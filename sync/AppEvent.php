<?php
declare(strict_types=1);
/**
 * AppEvent - Event model chung cho Notification architecture (§2-§5).
 * Generic: khong event rieng cung cho tung module. Module tuong lai tu dang ky.
 */
require_once __DIR__ . '/../config.php';

class AppEvent
{
    // Event types (§3)
    public const TASK_STARTED = 'TASK_STARTED';
    public const TASK_COMPLETED = 'TASK_COMPLETED';
    public const TASK_FAILED = 'TASK_FAILED';
    public const BATCH_STARTED = 'BATCH_STARTED';
    public const BATCH_PROGRESS = 'BATCH_PROGRESS';
    public const BATCH_COMPLETED = 'BATCH_COMPLETED';
    public const BATCH_FAILED = 'BATCH_FAILED';
    public const STATUS_CHANGED = 'STATUS_CHANGED';
    public const WARNING = 'WARNING';
    public const CRITICAL_ALERT = 'CRITICAL_ALERT';
    public const DAILY_SUMMARY = 'DAILY_SUMMARY';
    public const WEEKLY_SUMMARY = 'WEEKLY_SUMMARY';
    // Job generic (§41-§42)
    public const JOB_COMPLETED = 'JOB_COMPLETED';
    public const JOB_FAILED = 'JOB_FAILED';

    // Severity (§4)
    public const SEV_DEBUG = 'DEBUG';
    public const SEV_INFO = 'INFO';
    public const SEV_SUCCESS = 'SUCCESS';
    public const SEV_WARNING = 'WARNING';
    public const SEV_ERROR = 'ERROR';
    public const SEV_CRITICAL = 'CRITICAL';

    // Modules (§5)
    public const MOD_CHANNEL = 'CHANNEL';
    public const MOD_BROWSER = 'BROWSER';
    public const MOD_TAB_SESSION = 'TAB_SESSION';
    public const MOD_EVALUATION = 'EVALUATION';
    public const MOD_PROXY = 'PROXY';
    public const MOD_AUTO_ACTIVITY = 'AUTO_ACTIVITY';
    public const MOD_SYNCHRONIZE = 'SYNCHRONIZE';
    public const MOD_MONITORING = 'MONITORING';
    public const MOD_SYSTEM = 'SYSTEM';

    public static function types(): array
    {
        return [self::TASK_STARTED, self::TASK_COMPLETED, self::TASK_FAILED,
            self::BATCH_STARTED, self::BATCH_PROGRESS, self::BATCH_COMPLETED, self::BATCH_FAILED,
            self::STATUS_CHANGED, self::WARNING, self::CRITICAL_ALERT,
            self::DAILY_SUMMARY, self::WEEKLY_SUMMARY, self::JOB_COMPLETED, self::JOB_FAILED];
    }

    public static function severities(): array
    {
        return [self::SEV_DEBUG, self::SEV_INFO, self::SEV_SUCCESS,
            self::SEV_WARNING, self::SEV_ERROR, self::SEV_CRITICAL];
    }

    public static function newId(): string
    {
        return 'ev_' . date('YmdHis') . '_' . substr(md5(microtime(true) . mt_rand()), 0, 8);
    }

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS app_events (
                event_id VARCHAR(64) PRIMARY KEY,
                event_type VARCHAR(30) NOT NULL,
                module VARCHAR(30) NOT NULL,
                severity VARCHAR(15) NOT NULL DEFAULT 'INFO',
                status VARCHAR(20) NULL,
                profile_id INT NULL,
                batch_id VARCHAR(64) NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                message TEXT NULL,
                data TEXT NULL,
                reportable TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ev_mod (module, created_at),
                KEY idx_ev_type (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array event row (da persist) */
    public static function create(string $type, string $module, string $severity,
        string $title, string $message = '', array $opts = []): array
    {
        self::ensureTable();
        if (!in_array($type, self::types(), true)) $type = self::TASK_COMPLETED;
        if (!in_array($severity, self::severities(), true)) $severity = self::SEV_INFO;
        $ev = [
            'event_id' => self::newId(),
            'event_type' => $type,
            'module' => strtoupper(trim($module)) ?: self::MOD_SYSTEM,
            'severity' => $severity,
            'status' => $opts['status'] ?? null,
            'profile_id' => isset($opts['profile_id']) ? (int)$opts['profile_id'] : null,
            'batch_id' => $opts['batch_id'] ?? null,
            'title' => mb_substr($title, 0, 255),
            'message' => $message,
            'data' => isset($opts['data']) ? json_encode($opts['data'], JSON_UNESCAPED_UNICODE) : null,
            'reportable' => empty($opts['reportable']) && ($opts['reportable'] ?? true) !== true ? 0 : 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        try {
            db()->prepare('INSERT INTO app_events (event_id, event_type, module, severity, status,
                    profile_id, batch_id, title, message, data, reportable, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ev['event_id'], $ev['event_type'], $ev['module'], $ev['severity'],
                    $ev['status'], $ev['profile_id'], $ev['batch_id'], $ev['title'],
                    $ev['message'], $ev['data'], $ev['reportable'], $ev['created_at']]);
        } catch (Throwable $e) {
        }
        return $ev;
    }
}
