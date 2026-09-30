<?php
declare(strict_types=1);
/**
 * ChannelQueueService - Hang cho video rieng tung kenh: assign, order,
 * atomic reserve (1 video 1 job), consume/release, lich su.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';
require_once __DIR__ . '/SyncLogger.php';

class ChannelQueueService
{
    public const ST_READY = 'READY';
    public const ST_RESERVED = 'RESERVED';
    public const ST_CONSUMED = 'CONSUMED';
    public const ST_SKIPPED = 'SKIPPED';

    /**
     * Gan video vao queue kenh (idempotent theo UNIQUE). $metaOverride: per-video metadata.
     * @return array{added:int, existed:int}
     */
    public static function assign(int $profileId, array $assetIds, ?array $metaOverride = null): array
    {
        UploadStore::ensureSchema();
        $added = 0;
        $existed = 0;
        try {
            $mx = db()->prepare('SELECT COALESCE(MAX(queue_position), -1) FROM channel_video_queue WHERE profile_id=?');
            $mx->execute([$profileId]);
            $pos = (int)$mx->fetchColumn();
            $ins = db()->prepare("INSERT IGNORE INTO channel_video_queue
                (profile_id, video_asset_id, queue_position, status, metadata_override)
                VALUES (?,?,?, 'READY', ?)");
            foreach (array_values(array_unique(array_filter(array_map('intval', $assetIds)))) as $aid) {
                // Chi nhan asset READY/ton tai
                $st = db()->prepare("SELECT id FROM video_assets WHERE id=? AND status IN ('NEW','READY')");
                $st->execute([$aid]);
                if (!$st->fetchColumn()) {
                    $existed++;
                    continue;
                }
                $pos++;
                $ins->execute([$profileId, $aid, $pos,
                    $metaOverride !== null ? json_encode($metaOverride, JSON_UNESCAPED_UNICODE) : null]);
                if ($ins->rowCount() > 0) {
                    $added++;
                    try {
                        db()->prepare("UPDATE video_assets SET status='ASSIGNED' WHERE id=? AND status='NEW'")->execute([$aid]);
                    } catch (Throwable $e) {
                    }
                } else {
                    $existed++;
                }
            }
            self::renumber($profileId);
        } catch (Throwable $e) {
        }
        return ['added' => $added, 'existed' => $existed];
    }

    /** Sap lai position lien tuc theo thu tu hien tai. */
    public static function renumber(int $profileId): void
    {
        try {
            $rows = db()->prepare('SELECT id FROM channel_video_queue WHERE profile_id=? AND status<>? ORDER BY queue_position ASC, id ASC');
            $rows->execute([$profileId, self::ST_CONSUMED]);
            $up = db()->prepare('UPDATE channel_video_queue SET queue_position=? WHERE id=?');
            $i = 0;
            foreach ($rows->fetchAll() as $r) {
                $up->execute([$i++, (int)$r['id']]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Dat lai thu tu manual: [queueId...] theo thu tu mong muon. */
    public static function reorder(int $profileId, array $queueIds): array
    {
        UploadStore::ensureSchema();
        try {
            $up = db()->prepare('UPDATE channel_video_queue SET queue_position=? WHERE id=? AND profile_id=?');
            $i = 0;
            foreach (array_map('intval', $queueIds) as $qid) {
                $up->execute([$i++, $qid, $profileId]);
            }
            self::renumber($profileId);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function remove(int $profileId, array $queueIds): int
    {
        UploadStore::ensureSchema();
        $n = 0;
        try {
            $del = db()->prepare("DELETE FROM channel_video_queue WHERE id=? AND profile_id=? AND status IN ('READY','SKIPPED')");
            foreach (array_map('intval', $queueIds) as $qid) {
                $del->execute([$qid, $profileId]);
                $n += $del->rowCount();
            }
            self::renumber($profileId);
        } catch (Throwable $e) {
        }
        return $n;
    }

    public static function skip(int $profileId, array $queueIds): int
    {
        $n = 0;
        try {
            $st = db()->prepare("UPDATE channel_video_queue SET status=? WHERE id=? AND profile_id=? AND status='READY'");
            foreach (array_map('intval', $queueIds) as $qid) {
                $st->execute([self::ST_SKIPPED, $qid, $profileId]);
                $n += $st->rowCount();
            }
        } catch (Throwable $e) {
        }
        return $n;
    }

    /**
     * Lay + reserve video tiep theo (ATOMIC: transaction + FOR UPDATE).
     * Thu tu theo queue_mode: MANUAL/FILENAME_ASC/CREATED_TIME/RANDOM.
     * @return array|null queue row + asset
     */
    public static function next(int $profileId, string $queueMode = 'MANUAL', string $jobId = ''): ?array
    {
        UploadStore::ensureSchema();
        $order = match ($queueMode) {
            'FILENAME_ASC' => 'a.filename ASC, a.id ASC',
            'CREATED_TIME' => 'a.created_at ASC, a.id ASC',
            'RANDOM' => 'RAND()',
            default => 'q.queue_position ASC, q.id ASC',
        };
        try {
            db()->beginTransaction();
            // Khong dequue video da PUBLISHED/SCHEDULED o kenh nay (policy mac dinh)
            $st = db()->prepare("SELECT q.*, a.source_path, a.filename, a.size_bytes, a.checksum
                FROM channel_video_queue q JOIN video_assets a ON a.id=q.video_asset_id
                WHERE q.profile_id=? AND q.status='READY' AND a.status NOT IN ('ARCHIVED')
                AND NOT EXISTS (SELECT 1 FROM video_uploads u WHERE u.profile_id=q.profile_id
                    AND u.video_asset_id=q.video_asset_id AND u.status IN ('SCHEDULED','PUBLISHED'))
                ORDER BY $order LIMIT 1 FOR UPDATE");
            $st->execute([$profileId]);
            $row = $st->fetch();
            if (!$row) {
                db()->rollBack();
                return null;
            }
            // File phai ton tai luc claim
            if (!is_file((string)$row['source_path'])) {
                db()->prepare("UPDATE video_assets SET status='FAILED', error_code='MISSING_FILE' WHERE id=?")
                    ->execute([(int)$row['video_asset_id']]);
                db()->prepare('UPDATE channel_video_queue SET status=? WHERE id=?')
                    ->execute([self::ST_SKIPPED, (int)$row['id']]);
                db()->commit();
                return null;
            }
            db()->prepare('UPDATE channel_video_queue SET status=?, reserved_at=NOW(), reserved_job=? WHERE id=? AND status=?')
                ->execute([self::ST_RESERVED, mb_substr($jobId, 0, 32), (int)$row['id'], self::ST_READY]);
            db()->commit();
            $row['status'] = self::ST_RESERVED;
            return $row;
        } catch (Throwable $e) {
            try {
                db()->rollBack();
            } catch (Throwable $e2) {
            }
            return null;
        }
    }

    public static function release(int $queueId): void
    {
        try {
            db()->prepare("UPDATE channel_video_queue SET status='READY', reserved_at=NULL, reserved_job=NULL
                WHERE id=? AND status=?")->execute([$queueId, self::ST_RESERVED]);
        } catch (Throwable $e) {
        }
    }

    public static function consume(int $queueId): void
    {
        try {
            db()->prepare('UPDATE channel_video_queue SET status=?, consumed_at=NOW() WHERE id=?')
                ->execute([self::ST_CONSUMED, $queueId]);
        } catch (Throwable $e) {
        }
    }

    /** @return array{ready,reserved,consumed,skipped,total} */
    public static function counts(int $profileId): array
    {
        UploadStore::ensureSchema();
        $out = ['ready' => 0, 'reserved' => 0, 'consumed' => 0, 'skipped' => 0, 'total' => 0];
        try {
            $st = db()->prepare('SELECT status, COUNT(*) c FROM channel_video_queue WHERE profile_id=? GROUP BY status');
            $st->execute([$profileId]);
            foreach ($st->fetchAll() as $r) {
                $k = strtolower((string)$r['status']);
                if (isset($out[$k])) $out[$k] = (int)$r['c'];
                $out['total'] += (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function list(int $profileId, int $page = 1, int $per = 20): array
    {
        UploadStore::ensureSchema();
        $page = max(1, $page);
        $per = max(5, min(100, $per));
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM channel_video_queue WHERE profile_id=?');
            $st->execute([$profileId]);
            $total = (int)$st->fetchColumn();
            $off = ($page - 1) * $per;
            $st = db()->prepare('SELECT q.*, a.filename, a.size_bytes, a.status AS asset_status
                FROM channel_video_queue q JOIN video_assets a ON a.id=q.video_asset_id
                WHERE q.profile_id=? ORDER BY q.queue_position ASC LIMIT ' . $per . ' OFFSET ' . $off);
            $st->execute([$profileId]);
            return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'per' => $per];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per' => $per];
        }
    }
}
