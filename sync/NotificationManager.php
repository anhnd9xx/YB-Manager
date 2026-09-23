<?php
declare(strict_types=1);
/**
 * NotificationManager - Subscribe EventBus, quyet dinh thong bao (§7).
 * - Event co can thong bao? (rules: module/event/severity/mode)
 * - Gui ngay (IMMEDIATE) hay gom digest (DIGEST) hay tat (OFF)
 * - Aggregation: batch gom 1 summary, khong 1 msg/profile (§17)
 * - Dedup: event_id + provider chi gui 1 lan (§15)
 * - Khong gui credentials (§47), khong spam mac dinh (§16)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';
require_once __DIR__ . '/NotificationQueue.php';

class NotificationManager
{
    public const MODE_OFF = 'OFF';
    public const MODE_IMMEDIATE = 'IMMEDIATE';
    public const MODE_DIGEST = 'DIGEST';

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS notification_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(30) NOT NULL DEFAULT '*',
                event_type VARCHAR(30) NOT NULL DEFAULT '*',
                severity VARCHAR(15) NOT NULL DEFAULT '*',
                mode VARCHAR(15) NOT NULL DEFAULT 'IMMEDIATE',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uq_rule (module, event_type, severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
        self::seedDefaults();
    }

    /** Default rules (§16): TASK_FAILED, BATCH_COMPLETED/FAILED, CRITICAL on. */
    private static function seedDefaults(): void
    {
        $defs = [
            // module, event, severity, mode, enabled
            ['EVALUATION', 'BATCH_COMPLETED', 'SUCCESS', self::MODE_IMMEDIATE, 1],
            ['EVALUATION', 'BATCH_FAILED', '*', self::MODE_IMMEDIATE, 1],
            ['EVALUATION', 'CRITICAL_ALERT', '*', self::MODE_IMMEDIATE, 1],
            ['EVALUATION', 'WARNING', '*', self::MODE_IMMEDIATE, 1],
            ['BROWSER', 'BATCH_COMPLETED', 'SUCCESS', self::MODE_IMMEDIATE, 1],
            ['BROWSER', 'BATCH_FAILED', '*', self::MODE_IMMEDIATE, 1],
            ['AUTO_ACTIVITY', 'TASK_FAILED', '*', self::MODE_IMMEDIATE, 1],
            ['AUTO_ACTIVITY', 'BATCH_COMPLETED', '*', self::MODE_DIGEST, 1],
            ['AUTO_ACTIVITY', 'CRITICAL_ALERT', '*', self::MODE_IMMEDIATE, 1],
            ['PROXY', 'STATUS_CHANGED', 'WARNING', self::MODE_IMMEDIATE, 1],
            ['PROXY', 'CRITICAL_ALERT', '*', self::MODE_IMMEDIATE, 1],
            ['*', 'TASK_FAILED', '*', self::MODE_IMMEDIATE, 1],
            ['*', 'BATCH_FAILED', '*', self::MODE_IMMEDIATE, 1],
            ['*', 'CRITICAL_ALERT', '*', self::MODE_IMMEDIATE, 1],
            ['*', 'TASK_COMPLETED', 'SUCCESS', self::MODE_OFF, 1],
            ['*', 'BATCH_PROGRESS', '*', self::MODE_OFF, 1],
        ];
        try {
            foreach ($defs as $d) {
                db()->prepare('INSERT IGNORE INTO notification_rules (module, event_type, severity, mode, enabled)
                    VALUES (?,?,?,?,?)')->execute($d);
            }
        } catch (Throwable $e) {
        }
    }

    public static function rules(): array
    {
        self::ensureTable();
        try {
            return db()->query('SELECT * FROM notification_rules ORDER BY module, event_type, severity')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function saveRule(int $id, string $mode, int $enabled): bool
    {
        self::ensureTable();
        if (!in_array($mode, [self::MODE_OFF, self::MODE_IMMEDIATE, self::MODE_DIGEST], true)) return false;
        try {
            db()->prepare('UPDATE notification_rules SET mode=?, enabled=? WHERE id=?')
                ->execute([$mode, $enabled ? 1 : 0, $id]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Tim rule cu the nhat (module/event/severity cu the > wildcard). */
    public static function matchRule(array $ev): ?array
    {
        $rules = self::rules();
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            if (empty($r['enabled'])) continue;
            $sm = ($r['module'] === '*' || $r['module'] === $ev['module']) ? ($r['module'] === '*' ? 0 : 2) : -1;
            if ($sm < 0) continue;
            $se = ($r['event_type'] === '*' || $r['event_type'] === $ev['event_type']) ? ($r['event_type'] === '*' ? 0 : 2) : -1;
            if ($se < 0) continue;
            $ss = ($r['severity'] === '*' || $r['severity'] === $ev['severity']) ? ($r['severity'] === '*' ? 0 : 2) : -1;
            if ($ss < 0) continue;
            $score = $sm + $se + $ss;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        return $best;
    }

    /** Subscriber cua EventBus. */
    public static function onEvent(array $ev): void
    {
        try {
            if (!self::telegramEnabled()) return; // tat la tat het (van luu event)
            $rule = self::matchRule($ev);
            if ($rule === null || ($rule['mode'] ?? self::MODE_OFF) === self::MODE_OFF) return;
            $mode = (string)$rule['mode'];
            // OFF-hours: chi CRITICAL gui ngay, con lai doi digest (§45)
            if ($mode === self::MODE_IMMEDIATE && self::inQuietHours()
                && ($ev['severity'] ?? '') !== AppEvent::SEV_CRITICAL) {
                $mode = self::MODE_DIGEST;
            }
            if ($mode === self::MODE_DIGEST) {
                // Digest: danh dau de report dinh ky gom (khong enqueue telegram)
                return;
            }
            // Aggregation: BATCH_PROGRESS khong bao gio gui le (§17)
            if (($ev['event_type'] ?? '') === AppEvent::BATCH_PROGRESS) return;
            // Correlation §39: batch chay tu Telegram job -> job completion da bao,
            // khong gui them generic batch notification
            if (($ev['event_type'] ?? '') === AppEvent::BATCH_COMPLETED && !empty($ev['batch_id'])) {
                $cf = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
                    . 'ytm_jobctx_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$ev['batch_id']) . '.json';
                if (is_file($cf)) {
                    $cj = json_decode((string)@file_get_contents($cf), true);
                    if (is_array($cj) && ($cj['source'] ?? '') === 'TELEGRAM') return;
                }
            }
            NotificationQueue::enqueue($ev, 'telegram');
        } catch (Throwable $e) {
        }
    }

    public static function telegramEnabled(): bool
    {
        try {
            return get_setting('notify_telegram_enabled', '0') === '1'
                && trim(get_setting('notify_bot_token', '')) !== ''
                && trim(get_setting('notify_chat_id', '')) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function inQuietHours(): bool
    {
        try {
            $s = get_setting('notify_quiet_start', '');
            $e = get_setting('notify_quiet_end', '');
            if ($s === '' || $e === '') return false;
            $cur = date('H:i');
            if ($s < $e) return $cur >= $s && $cur < $e;
            return $cur >= $s || $cur < $e;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Mask token cho UI/log (§10): 123456:ABC...XYZ */
    public static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        $parts = explode(':', $token, 2);
        $head = $parts[0];
        $tail = isset($parts[1]) ? substr($parts[1], -3) : '';
        return substr($head, 0, 6) . '...:' . '...' . $tail;
    }
}
