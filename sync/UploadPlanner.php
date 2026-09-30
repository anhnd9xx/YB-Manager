<?php
declare(strict_types=1);
/**
 * UploadPlanner - Sinh publish slots (rolling 14 ngay), chon video + tao upload jobs.
 * Tach UPLOAD (som, theo lead time) va PUBLISH (dung gio). Sequential V1:
 * chi tao job moi khi khong co upload job active (per-channel lock + global).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';
require_once __DIR__ . '/ChannelQueueService.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/SyncLogger.php';

class UploadPlanner
{
    public const HORIZON_DAYS = 14;

    /**
     * Sinh slots cho kenh (idempotent nho UNIQUE profile+publish_at).
     * @return int so slot moi
     */
    public static function ensureSlots(int $profileId, int $horizonDays = self::HORIZON_DAYS): int
    {
        UploadStore::ensureSchema();
        $cfg = UploadStore::getConfig($profileId);
        if (empty($cfg['upload_enabled']) || empty($cfg['daily_enabled'])) return 0;
        if (!empty($cfg['paused_until']) && strtotime((string)$cfg['paused_until']) > time()) return 0;
        $slots = (array)($cfg['publish_slots'] ?? []);
        $vpd = max(1, (int)$cfg['videos_per_day']);
        $need = array_slice($slots, 0, $vpd);
        if (!$need) return 0;
        $wdays = array_map('intval', explode(',', (string)$cfg['weekdays']));
        $tz = (string)$cfg['timezone'];
        $made = 0;
        try {
            $ins = db()->prepare("INSERT IGNORE INTO video_publish_schedules
                (profile_id, publish_at, timezone, status) VALUES (?,?,?,'PLANNED')");
            $today = new DateTime('today', new DateTimeZone($tz));
            for ($d = 0; $d < $horizonDays; $d++) {
                $day = (clone $today)->modify("+$d days");
                if (!in_array((int)$day->format('N'), $wdays, true)) continue;
                foreach ($need as $t) {
                    $at = $day->format('Y-m-d') . ' ' . $t . ':00';
                    $ins->execute([$profileId, $at, $tz]);
                    $made += $ins->rowCount();
                }
            }
        } catch (Throwable $e) {
        }
        return $made;
    }

    /**
     * Doi config (vd 19:30 -> 20:00): xoa PLANNED tuong lai chua gan upload
     * roi sinh lai. Slot da gan upload/provider thi giu (bao action required).
     * @return int slots moi
     */
    public static function refreshSlots(int $profileId, int $horizonDays = self::HORIZON_DAYS): int
    {
        UploadStore::ensureSchema();
        try {
            db()->prepare("DELETE FROM video_publish_schedules WHERE profile_id=?
                AND status='PLANNED' AND publish_at>NOW() AND upload_id IS NULL")->execute([$profileId]);
        } catch (Throwable $e) {
        }
        return self::ensureSlots($profileId, $horizonDays);
    }
    public static function uploadBusy(?int $profileId = null): bool
    {
        try {
            UploadStore::ensureSchema();
            if ($profileId !== null) {
                $st = db()->prepare("SELECT COUNT(*) FROM video_uploads WHERE profile_id=?
                    AND status IN ('QUEUED','PREPARING','UPLOADING','SCHEDULING','RETRYING')");
                $st->execute([$profileId]);
                if ((int)$st->fetchColumn() > 0) return true;
            }
            $n = (int)db()->query("SELECT COUNT(*) FROM video_uploads
                WHERE status IN ('QUEUED','PREPARING','UPLOADING','SCHEDULING','RETRYING')")->fetchColumn();
            return $n > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Preflight truoc upload. @return array{ok, errors[]}
     */
    public static function preflight(int $profileId, array $asset): array
    {
        $errors = [];
        $path = (string)($asset['source_path'] ?? '');
        if (!is_file($path)) $errors[] = 'FILE_MISSING';
        elseif (!is_readable($path)) $errors[] = 'FILE_UNREADABLE';
        if (!empty($asset['checksum'])) {
            require_once __DIR__ . '/VideoLibraryService.php';
            $now = VideoLibraryService::checksum($path);
            if ($now !== null && $now !== (string)$asset['checksum']) $errors[] = 'SOURCE_CHANGED';
        }
        $cfg = UploadStore::getConfig($profileId);
        if (strtoupper((string)$cfg['upload_provider']) === 'YOUTUBE_API') {
            $cred = UploadStore::getCredential($profileId);
            if (!in_array($cur = (string)$cred['auth_status'], ['VALID'], true)) {
                $errors[] = 'AUTH_' . $cur;
            }
        }
        return ['ok' => !$errors, 'errors' => $errors];
    }

    /**
     * Plan uploads due: slot sap toi trong lead window ma chua co video/upload
     * -> reserve queue video + tao JobManager UPLOAD job (1 lan, idempotent).
     * @return array{planned:int, jobs:string[]}
     */
    public static function planDueUploads(): array
    {
        UploadStore::ensureSchema();
        $out = ['planned' => 0, 'jobs' => []];
        if (self::uploadBusy()) return $out; // sequential V1: 1 upload tai 1 thoi diem
        try {
            $cfgs = db()->query('SELECT profile_id, pre_upload_minutes FROM channel_upload_configs
                WHERE upload_enabled=1 AND daily_enabled=1')->fetchAll();
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($cfgs as $c) {
            $pid = (int)$c['profile_id'];
            try {
                if (!empty(UploadStore::getConfig($pid)['paused_until'])
                    && strtotime((string)UploadStore::getConfig($pid)['paused_until']) > time()) {
                    continue;
                }
                if (self::uploadBusy($pid)) continue;
                $lead = max(15, (int)$c['pre_upload_minutes']);
                // Slot gan nhat trong lead window chua gan video/upload
                $st = db()->prepare("SELECT * FROM video_publish_schedules WHERE profile_id=?
                    AND status='PLANNED' AND publish_at > NOW()
                    AND publish_at <= DATE_ADD(NOW(), INTERVAL $lead MINUTE)
                    ORDER BY publish_at ASC LIMIT 1");
                $st->execute([$pid]);
                $slot = $st->fetch();
                if (!$slot) continue;
                // Slot da gan upload?
                if (!empty($slot['upload_id'])) continue;
                $cfg = UploadStore::getConfig($pid);
                $item = ChannelQueueService::next($pid, (string)$cfg['queue_mode'], 'plan');
                if (!$item) continue; // het queue -> alert o tick, khong fail slot
                // Tao upload record + job (atomic claim da xong trong next())
                $asset = ['source_path' => $item['source_path'], 'checksum' => $item['checksum']];
                $pre = self::preflight($pid, $asset);
                if (!$pre['ok']) {
                    // Fatal file/auth: mark + replacement theo policy
                    self::handlePreflightFail($pid, (int)$item['id'], (int)$item['video_asset_id'], $pre['errors'], (string)$cfg['replacement_policy']);
                    continue;
                }
                require_once __DIR__ . '/JobManager.php';
                db()->prepare("INSERT INTO video_uploads (profile_id, video_asset_id, queue_id, provider, status, file_checksum)
                    VALUES (?,?,?,?,'QUEUED',?)")
                    ->execute([$pid, (int)$item['video_asset_id'], (int)$item['id'],
                        strtoupper((string)$cfg['upload_provider']), (string)$item['checksum']]);
                $uid = (int)db()->lastInsertId();
                $job = JobManager::create('UPLOAD', 'Upload #' . $uid . ' ch=' . $pid, [$pid], 'SCHEDULER',
                    ['job_type' => 'YOUTUBE_UPLOAD', 'created_by' => 'planner',
                        'notify_on_complete' => 0, 'notify_on_failure' => 1, 'resumable' => 1,
                        'command_id' => 'upl-' . $uid]);
                db()->prepare('UPDATE video_uploads SET job_id=? WHERE id=?')->execute([$job['job_id'], $uid]);
                // Gan slot <-> upload (reservation publish slot)
                db()->prepare('UPDATE video_publish_schedules SET video_asset_id=?, upload_id=?, status=? WHERE id=?')
                    ->execute([(int)$item['video_asset_id'], $uid, 'RESERVED', (int)$slot['id']]);
                // job_items de progress
                try {
                    db()->prepare('INSERT IGNORE INTO job_items (job_id, item_key, target_type, target_id, target_name, status)
                        VALUES (?,?,?,?,?,?)')
                        ->execute([$job['job_id'], 'upload-' . $uid, 'upload', (string)$uid,
                            mb_substr((string)$item['filename'], 0, 190), 'QUEUED']);
                } catch (Throwable $e) {
                }
                $out['planned']++;
                $out['jobs'][] = $job['job_id'];
                JobManager::ensureWorker();
                try {
                    SyncLogger::info('upload', '[PLAN] upload=' . $uid . ' ch=' . $pid . ' slot=' . $slot['publish_at'] . ' job=' . $job['job_id']);
                } catch (Throwable $e) {
                }
                return $out; // sequential: 1 job/lan tick
            } catch (Throwable $e) {
                continue;
            }
        }
        return $out;
    }

    /** Backoff retry: 1m, 5m, 15m, 30m theo attempt. */
    public static function backoffAt(int $attempt): string
    {
        $mins = [1 => 1, 2 => 5, 3 => 15];
        $m = $mins[$attempt] ?? 30;
        return date('Y-m-d H:i:s', time() + $m * 60);
    }

    /**
     * Retry engine: RETRYING toi han -> job moi (attempt+1). Restart reconcile:
     * UPLOADING/PREPARING/SCHEDULING mo coi (>30p khong tien trien) -> RETRYING
     * (resume qua session_ref neu provider ho tro). Khong bao gio duplicate published.
     * @return int so job tao
     */
    public static function retryDue(): int
    {
        UploadStore::ensureSchema();
        $n = 0;
        try {
            // Reconcile uploads mo coi (restart giua chung)
            db()->prepare("UPDATE video_uploads SET status='RETRYING', next_attempt_at=NOW()
                WHERE status IN ('PREPARING','UPLOADING','SCHEDULING')
                AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))")->execute();
            if (self::uploadBusy()) return 0;
            $rows = db()->query("SELECT u.*, c.max_attempts FROM video_uploads u
                JOIN channel_upload_configs c ON c.profile_id=u.profile_id
                WHERE u.status='RETRYING' AND (u.next_attempt_at IS NULL OR u.next_attempt_at<=NOW())
                ORDER BY u.next_attempt_at ASC LIMIT 3")->fetchAll();
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            $pid = (int)$u['profile_id'];
            try {
                if (self::uploadBusy($pid)) continue;
                if ((int)$u['attempt'] >= max(1, (int)$u['max_attempts'])) {
                    db()->prepare("UPDATE video_uploads SET status='FAILED', completed_at=NOW(),
                        error_code='MAX_ATTEMPTS', error_message='needs attention' WHERE id=?")->execute([$uid]);
                    if (!empty($u['queue_id'])) ChannelQueueService::release((int)$u['queue_id']);
                    require_once __DIR__ . '/EventBus.php';
                    EventBus::emit(AppEvent::TASK_FAILED, AppEvent::MOD_UPLOAD, AppEvent::SEV_ERROR,
                        "Upload #$uid that bai (het retry)", "Profile #$pid",
                        ['profile_id' => $pid, 'status' => 'FAILED']);
                    continue;
                }
                require_once __DIR__ . '/JobManager.php';
                db()->prepare('UPDATE video_uploads SET status=?, attempt=attempt+1 WHERE id=?')
                    ->execute(['QUEUED', $uid]);
                $job = JobManager::create('UPLOAD', 'Retry upload #' . $uid . ' ch=' . $pid, [$pid], 'SCHEDULER',
                    ['job_type' => 'YOUTUBE_UPLOAD', 'created_by' => 'retry',
                        'notify_on_complete' => 0, 'notify_on_failure' => 1, 'resumable' => 1,
                        'command_id' => 'upl-' . $uid . '-' . ((int)$u['attempt'] + 1)]);
                db()->prepare('UPDATE video_uploads SET job_id=? WHERE id=?')->execute([$job['job_id'], $uid]);
                JobManager::ensureWorker();
                $n++;
                return $n; // sequential: 1/lan
            } catch (Throwable $e) {
            }
        }
        return $n;
    }

    /** Xu ly preflight fail: mark + replacement (AUTO_REPLACE tiep tuc vong sau). */
    public static function handlePreflightFail(int $profileId, int $queueId, int $assetId, array $errors, string $policy): void
    {
        $fatalAuth = false;
        foreach ($errors as $e) {
            if (str_starts_with($e, 'AUTH_')) $fatalAuth = true;
        }
        try {
            if ($fatalAuth) {
                // Auth invalid: KHONG consume queue (khong dequeue), chi alert
                ChannelQueueService::release($queueId);
                UploadStore::setAuthStatus($profileId, 'REAUTH_REQUIRED', implode(',', $errors));
                require_once __DIR__ . '/EventBus.php';
                EventBus::emit(AppEvent::WARNING, AppEvent::MOD_UPLOAD, AppEvent::SEV_WARNING,
                    'Kênh cần xác thực lại YouTube', "Profile #$profileId: " . implode(',', $errors),
                    ['profile_id' => $profileId, 'status' => 'WARNING']);
                return;
            }
            if ($policy === 'SKIP_SLOT') {
                ChannelQueueService::skip($profileId, [$queueId]);
            } else {
                // AUTO_REPLACE/ASK: tra video ve READY (lan sau pick video khac nho cooldown tu nhien),
                // mark asset loi de khong pick lai lien tuc
                ChannelQueueService::release($queueId);
                db()->prepare("UPDATE video_assets SET status='FAILED', error_code=? WHERE id=?")
                    ->execute([implode(',', $errors), $assetId]);
                db()->prepare('UPDATE channel_video_queue SET status=? WHERE video_asset_id=? AND status=?')
                    ->execute([ChannelQueueService::ST_SKIPPED, $assetId, ChannelQueueService::ST_READY]);
            }
        } catch (Throwable $e) {
        }
    }
}
