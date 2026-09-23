<?php
declare(strict_types=1);
/**
 * NotificationQueue - Outbox persist + worker claim (§12-§15, §44, §48).
 * - Khong gui truc tiep trong worker module (enqueue roi ve ngay).
 * - Restart-safe: PENDING tiep tuc (§14). Dedup event_id+provider (§15).
 * - Retry backoff 1s/3s/10s, toi da 3 lan (§13). Rate limit 1.2s/msg (§44).
 * - Delivery failure khong doi task thanh FAILED (§48).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class NotificationQueue
{
    public const ST_PENDING = 'PENDING';
    public const ST_SENDING = 'SENDING';
    public const ST_SENT = 'SENT';
    public const ST_FAILED = 'FAILED';
    public const ST_CANCELLED = 'CANCELLED';

    public const MAX_ATTEMPTS = 3;
    /** Backoff giay theo attempt (1-indexed): 1s, 3s, 10s */
    public const BACKOFF = [1 => 1, 2 => 3, 3 => 10];

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS notification_outbox (
                notification_id VARCHAR(64) PRIMARY KEY,
                event_id VARCHAR(64) NOT NULL,
                provider VARCHAR(20) NOT NULL DEFAULT 'telegram',
                status VARCHAR(15) NOT NULL DEFAULT 'PENDING',
                attempt_count INT NOT NULL DEFAULT 0,
                next_try_at DATETIME NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                UNIQUE KEY uq_ev_provider (event_id, provider),
                KEY idx_outbox_status (status, next_try_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** Enqueue (dedup event+provider). Tra ve notification_id hoac null. */
    public static function enqueue(array $ev, string $provider = 'telegram'): ?string
    {
        self::ensureTable();
        $nid = 'nt_' . date('YmdHis') . '_' . substr(md5(($ev['event_id'] ?? '') . $provider), 0, 8);
        try {
            db()->prepare('INSERT IGNORE INTO notification_outbox
                (notification_id, event_id, provider, status, attempt_count, next_try_at, message, created_at)
                VALUES (?,?,?,?,0,NOW(),?,NOW())')
                ->execute([$nid, $ev['event_id'] ?? '', $provider,
                    self::ST_PENDING, $ev['title'] . "\n" . ($ev['message'] ?? '')]);
            return $nid;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Enqueue message tuy y (manual send / report) voi event gia. */
    public static function enqueueText(string $title, string $message, string $provider = 'telegram'): ?string
    {
        self::ensureTable();
        $nid = 'nt_' . date('YmdHis') . '_' . substr(md5($title . microtime(true)), 0, 8);
        try {
            db()->prepare('INSERT INTO notification_outbox
                (notification_id, event_id, provider, status, attempt_count, next_try_at, message, created_at)
                VALUES (?,?,?,?,0,NOW(),?,NOW())')
                ->execute([$nid, 'manual_' . substr(md5($nid), 0, 8), $provider, self::ST_PENDING,
                    $title . "\n" . $message]);
            return $nid;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Claim toi da $limit PENDING den han (SENDING + attempt++).
     * @return array[] rows
     */
    public static function claim(int $limit = 10): array
    {
        self::ensureTable();
        $limit = max(1, min(25, $limit));
        $out = [];
        try {
            $rows = db()->query("SELECT * FROM notification_outbox WHERE status='"
                . self::ST_PENDING . "' AND (next_try_at IS NULL OR next_try_at <= NOW())
                ORDER BY created_at ASC LIMIT $limit")->fetchAll();
            foreach ($rows as $r) {
                $n = db()->prepare('UPDATE notification_outbox SET status=?, attempt_count=attempt_count+1
                    WHERE notification_id=? AND status=?');
                $n->execute([self::ST_SENDING, $r['notification_id'], self::ST_PENDING]);
                if ($n->rowCount() > 0) {
                    $r['attempt_count'] = (int)$r['attempt_count'] + 1;
                    $out[] = $r;
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function markSent(string $nid): void
    {
        try {
            db()->prepare('UPDATE notification_outbox SET status=?, sent_at=NOW(), last_error=NULL WHERE notification_id=?')
                ->execute([self::ST_SENT, $nid]);
        } catch (Throwable $e) {
        }
    }

    public static function markFail(string $nid, string $error, int $attempt): void
    {
        try {
            if ($attempt >= self::MAX_ATTEMPTS) {
                db()->prepare('UPDATE notification_outbox SET status=?, last_error=? WHERE notification_id=?')
                    ->execute([self::ST_FAILED, mb_substr($error, 0, 500), $nid]);
            } else {
                $backoff = self::BACKOFF[$attempt] ?? 10;
                db()->prepare('UPDATE notification_outbox SET status=?, last_error=?,
                        next_try_at=DATE_ADD(NOW(), INTERVAL ' . (int)$backoff . ' SECOND) WHERE notification_id=?')
                    ->execute([self::ST_PENDING, mb_substr($error, 0, 500), $nid]);
            }
        } catch (Throwable $e) {
        }
    }

    public static function cancel(string $nid): bool
    {
        try {
            $st = db()->prepare('UPDATE notification_outbox SET status=? WHERE notification_id=? AND status=?');
            $st->execute([self::ST_CANCELLED, $nid, self::ST_PENDING]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function history(int $limit = 100): array
    {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        try {
            return db()->query('SELECT n.*, e.module, e.event_type, e.severity, e.title AS ev_title
                FROM notification_outbox n LEFT JOIN app_events e ON e.event_id=n.event_id
                ORDER BY n.created_at DESC LIMIT ' . $limit)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Badge: failed + unsent (§28). */
    public static function pendingCounts(): array
    {
        self::ensureTable();
        $out = ['pending' => 0, 'failed' => 0];
        try {
            foreach (db()->query("SELECT status, COUNT(*) c FROM notification_outbox
                    WHERE status IN ('PENDING','FAILED') GROUP BY status")->fetchAll() as $r) {
                if ($r['status'] === self::ST_PENDING) $out['pending'] = (int)$r['c'];
                if ($r['status'] === self::ST_FAILED) $out['failed'] = (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
