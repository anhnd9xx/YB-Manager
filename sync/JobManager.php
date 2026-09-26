<?php
declare(strict_types=1);
/**
 * JobManager - Generic jobs cho moi module (§16, §38, §41-§43).
 * Job: job_id/module/command_id/targets/status/progress/result + source.
 * Worker (bin/job_worker.php) chay QUEUED tuan tu; TeeTien do; xong emit
 * JOB_COMPLETED/JOB_FAILED (EventBus). Telegram khong cho job chay xong (§17).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class JobManager
{
    public const ST_QUEUED = 'QUEUED';
    public const ST_RUNNING = 'RUNNING';
    public const ST_PAUSED = 'PAUSED';
    public const ST_SUCCESS = 'SUCCESS';
    public const ST_PARTIAL = 'PARTIAL';
    public const ST_FAILED = 'FAILED';
    public const ST_CANCELLED = 'CANCELLED';
    public const ST_INTERRUPTED = 'INTERRUPTED';

    public const ACTIVE_STATUSES = ['QUEUED', 'RUNNING', 'PAUSED'];
    public const DONE_STATUSES = ['SUCCESS', 'PARTIAL', 'FAILED', 'CANCELLED', 'INTERRUPTED'];

    private static int $seq = 0;

    /** Dam bao schema Control Center (chay lai migration neu DB cu). */
    public static function ensureSchema(): void
    {
        self::ensureTable();
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = [];
            foreach (db()->query('SHOW COLUMNS FROM app_jobs')->fetchAll() as $r) {
                $cols[(string)$r['Field']] = true;
            }
            $add = [
                'job_type' => "VARCHAR(40) NOT NULL DEFAULT ''",
                'priority' => 'TINYINT NOT NULL DEFAULT 0',
                'cancelled_count' => 'INT NOT NULL DEFAULT 0',
                'progress_percent' => 'TINYINT NOT NULL DEFAULT 0',
                'queued_at' => 'DATETIME NULL',
                'completed_at' => 'DATETIME NULL',
                'created_by' => "VARCHAR(64) NOT NULL DEFAULT ''",
                'resumable' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'notify_on_complete' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'notify_on_failure' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'error_code' => 'VARCHAR(40) NULL',
            ];
            foreach ($add as $col => $def) {
                if (empty($cols[$col])) {
                    try {
                        db()->exec("ALTER TABLE app_jobs ADD COLUMN $col $def");
                    } catch (Throwable $e) {
                    }
                }
            }
            db()->exec("CREATE TABLE IF NOT EXISTS job_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(32) NOT NULL,
                item_key VARCHAR(64) NOT NULL DEFAULT '',
                target_type VARCHAR(20) NOT NULL DEFAULT 'profile',
                target_id VARCHAR(64) NOT NULL DEFAULT '',
                target_name VARCHAR(190) NOT NULL DEFAULT '',
                status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
                attempt_count INT NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                result TEXT NULL,
                error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_job_item (job_id, item_key),
                KEY idx_job_items_job (job_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS app_jobs (
                job_id VARCHAR(32) PRIMARY KEY,
                module VARCHAR(30) NOT NULL,
                command_id VARCHAR(64) NULL,
                name VARCHAR(190) NOT NULL DEFAULT '',
                targets TEXT NULL,
                status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
                progress_done INT NOT NULL DEFAULT 0,
                progress_total INT NOT NULL DEFAULT 0,
                success_count INT NOT NULL DEFAULT 0,
                warning_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
                chat_id VARCHAR(64) NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                result TEXT NULL,
                error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array job row */
    public static function create(string $module, string $name, array $targets = [],
        string $source = 'TELEGRAM', array $opts = []): array
    {
        self::ensureSchema();
        $prefix = ['EVALUATION' => 'EVA', 'BROWSER' => 'BRW', 'AUTO_ACTIVITY' => 'ACT',
            'CHANNEL' => 'CHN', 'PROXY' => 'PRX', 'SYSTEM' => 'SYS', 'REPORT' => 'RPT'];
        $pre = $prefix[strtoupper($module)] ?? 'JOB';
        self::$seq++;
        $jobId = $pre . '-' . strtoupper(substr(md5(microtime(true) . self::$seq . mt_rand()), 0, 4))
            . '-' . date('His');
        $bigBatch = count($targets) >= 3;
        $job = ['job_id' => $jobId, 'module' => strtoupper($module), 'name' => mb_substr($name, 0, 190),
            'targets' => json_encode(array_values($targets), JSON_UNESCAPED_UNICODE),
            'status' => self::ST_QUEUED, 'command_id' => $opts['command_id'] ?? null,
            'source' => $source, 'chat_id' => $opts['chat_id'] ?? null,
            'progress_total' => count($targets)];
        try {
            db()->prepare('INSERT INTO app_jobs (job_id, module, job_type, command_id, name, targets, status,
                    progress_total, source, chat_id, created_at, queued_at, created_by, resumable,
                    notify_on_complete, notify_on_failure, priority)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,?,?)')
                ->execute([$job['job_id'], $job['module'], mb_substr((string)($opts['job_type'] ?? ''), 0, 40),
                    $job['command_id'], $job['name'],
                    $job['targets'], $job['status'], $job['progress_total'], $job['source'], $job['chat_id'],
                    mb_substr((string)($opts['created_by'] ?? ''), 0, 64),
                    !empty($opts['resumable']) ? 1 : 0,
                    array_key_exists('notify_on_complete', $opts) ? (!empty($opts['notify_on_complete']) ? 1 : 0) : ($bigBatch ? 1 : 0),
                    array_key_exists('notify_on_failure', $opts) ? (!empty($opts['notify_on_failure']) ? 1 : 0) : 1,
                    (int)($opts['priority'] ?? 0)]);
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/EventBus.php';
            EventBus::emit(AppEvent::TASK_STARTED, $job['module'], AppEvent::SEV_INFO,
                'Job đã tạo: ' . $jobId, $name,
                ['status' => 'QUEUED', 'data' => ['job_id' => $jobId]]);
        } catch (Throwable $e) {
        }
        return $job;
    }

    public static function get(string $jobId): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare('SELECT * FROM app_jobs WHERE job_id=?');
            $st->execute([$jobId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array[] running/queued/paused (moi nhat truoc) */
    public static function active(int $limit = 20): array
    {
        self::ensureSchema();
        try {
            $limit = max(1, min(50, $limit));
            return db()->query("SELECT * FROM app_jobs WHERE status IN ('QUEUED','RUNNING','PAUSED')
                ORDER BY created_at DESC LIMIT $limit")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Duplicate lock (§33): job cung command+targets dang chay? */
    public static function duplicate(string $commandId): ?array
    {
        self::ensureSchema();
        try {
            $st = db()->prepare("SELECT * FROM app_jobs WHERE command_id=? AND status IN ('QUEUED','RUNNING','PAUSED')
                ORDER BY created_at DESC LIMIT 1");
            $st->execute([$commandId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function update(string $jobId, array $patch): void
    {
        $allow = ['status', 'progress_done', 'progress_total', 'progress_percent',
            'success_count', 'warning_count', 'failed_count', 'cancelled_count',
            'result', 'error', 'error_code', 'started_at', 'finished_at', 'completed_at'];
        $sets = [];
        $params = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$k=?";
                $params[] = $patch[$k];
            }
        }
        if (!$sets) return;
        try {
            self::ensureSchema();
            $params[] = $jobId;
            db()->prepare('UPDATE app_jobs SET ' . implode(',', $sets) . ' WHERE job_id=?')->execute($params);
            // progress_percent suy tu done/total khi caller khong tu tinh
            if (array_key_exists('progress_done', $patch) || array_key_exists('progress_total', $patch)) {
                try {
                    db()->prepare("UPDATE app_jobs SET progress_percent=
                        CASE WHEN progress_total>0 THEN LEAST(100, FLOOR(progress_done*100/progress_total)) ELSE 0 END
                        WHERE job_id=?")->execute([$jobId]);
                } catch (Throwable $e2) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    /** Claim 1 QUEUED job (worker). Uu tien priority cao truoc. */
    public static function claim(): ?array
    {
        self::ensureSchema();
        try {
            $r = db()->query("SELECT * FROM app_jobs WHERE status='QUEUED' ORDER BY priority DESC, created_at ASC LIMIT 1")->fetch();
            if (!$r) return null;
            $st = db()->prepare("UPDATE app_jobs SET status='RUNNING', started_at=NOW()
                WHERE job_id=? AND status='QUEUED'");
            $st->execute([$r['job_id']]);
            if ($st->rowCount() === 0) return null;
            $r['status'] = self::ST_RUNNING;
            return $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Hoan tat job + emit JOB_COMPLETED/JOB_FAILED (§18, §38). Ton trong notify flags. */
    public static function finish(string $jobId, string $status, ?string $result = null, ?string $error = null, ?string $errorCode = null): void
    {
        self::update($jobId, ['status' => $status, 'finished_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
            'result' => $result, 'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 40) : null]);
        try {
            $job = self::get($jobId);
            $ok = in_array($status, [self::ST_SUCCESS, self::ST_PARTIAL], true);
            $wantNotify = $ok ? ((int)($job['notify_on_complete'] ?? 1) === 1)
                : ((int)($job['notify_on_failure'] ?? 1) === 1);
            if (!$wantNotify) return;
            require_once __DIR__ . '/EventBus.php';
            EventBus::emit($ok ? AppEvent::JOB_COMPLETED : AppEvent::JOB_FAILED,
                $job['module'] ?? 'SYSTEM',
                $ok ? AppEvent::SEV_SUCCESS : AppEvent::SEV_ERROR,
                ($ok ? '✅ Job ' : '❌ Job ') . $jobId . ' ' . ($ok ? 'hoàn tất' : 'thất bại'),
                ($job['name'] ?? '') . ($result ? "\n$result" : '') . ($error ? "\nLỗi: $error" : ''),
                ['status' => $status,
                    'data' => ['job_id' => $jobId, 'command_id' => $job['command_id'] ?? null,
                        'job_source' => $job['source'] ?? '', 'chat_id' => $job['chat_id'] ?? null]]);
            // Chat timeline: job card (§S)
            if (!empty($job['chat_id'])) {
                require_once __DIR__ . '/ConversationService.php';
                ConversationService::log(ConversationService::OUT, (string)$job['chat_id'],
                    'JOB ' . $jobId . ' · ' . ($job['name'] ?? '') . ' · ' . $status,
                    ['job_id' => $jobId, 'status' => $ok ? 'SENT' : 'FAILED',
                        'type' => ConversationService::T_JOB]);
            }
        } catch (Throwable $e) {
        }
    }

    public static function cancel(string $jobId): bool
    {
        try {
            self::ensureSchema();
            $st = db()->prepare("UPDATE app_jobs SET status='CANCELLED', finished_at=NOW(), completed_at=NOW()
                WHERE job_id=? AND status IN ('QUEUED','RUNNING','PAUSED')");
            $st->execute([$jobId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function pause(string $jobId): bool
    {
        try {
            self::ensureSchema();
            $st = db()->prepare("UPDATE app_jobs SET status='PAUSED' WHERE job_id=? AND status IN ('QUEUED','RUNNING')");
            $st->execute([$jobId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function resume(string $jobId): bool
    {
        try {
            self::ensureSchema();
            $st = db()->prepare("UPDATE app_jobs SET status='QUEUED' WHERE job_id=? AND status='PAUSED'");
            $st->execute([$jobId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ================= Job items (item-level results §12) =================

    /** Khoi tao items tu targets (idempotent). */
    public static function initItems(string $jobId, array $targets, string $targetType = 'profile',
        ?callable $nameOf = null): void
    {
        try {
            self::ensureSchema();
            $st = db()->prepare('INSERT IGNORE INTO job_items (job_id, item_key, target_type, target_id, target_name)
                VALUES (?,?,?,?,?)');
            foreach (array_values($targets) as $t) {
                $tid = (string)$t;
                $nm = '';
                if ($nameOf !== null) {
                    try {
                        $nm = (string)$nameOf($t);
                    } catch (Throwable $e) {
                    }
                }
                $st->execute([$jobId, $targetType . ':' . $tid, $targetType, $tid, mb_substr($nm, 0, 190)]);
            }
        } catch (Throwable $e) {
        }
    }

    public static function setItem(string $jobId, string $itemKey, string $status,
        ?string $result = null, ?string $error = null): void
    {
        if (!in_array($status, ['QUEUED', 'RUNNING', 'SUCCESS', 'WARNING', 'FAILED', 'CANCELLED', 'SKIPPED'], true)) return;
        try {
            self::ensureSchema();
            $now = date('Y-m-d H:i:s');
            if ($status === 'RUNNING') {
                db()->prepare("UPDATE job_items SET status='RUNNING', attempt_count=attempt_count+1,
                        started_at=COALESCE(started_at,?) WHERE job_id=? AND item_key=?")
                    ->execute([$now, $jobId, $itemKey]);
            } else {
                db()->prepare('UPDATE job_items SET status=?, completed_at=?, result=?, error=? WHERE job_id=? AND item_key=?')
                    ->execute([$status, $now, $result, $error !== null ? mb_substr($error, 0, 500) : null, $jobId, $itemKey]);
            }
        } catch (Throwable $e) {
        }
    }

    /** @return array[] items (limit) */
    public static function items(string $jobId, int $limit = 200): array
    {
        try {
            self::ensureSchema();
            $limit = max(1, min(500, $limit));
            $st = db()->prepare("SELECT * FROM job_items WHERE job_id=? ORDER BY id ASC LIMIT $limit");
            $st->execute([$jobId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return string[] item_key failed */
    public static function failedItems(string $jobId): array
    {
        try {
            self::ensureSchema();
            $st = db()->prepare("SELECT item_key, target_id FROM job_items WHERE job_id=? AND status='FAILED' ORDER BY id");
            $st->execute([$jobId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Retry failed: tao job moi CHI voi failed items (§13, §52).
     * Fallback: neu khong co items, dung targets goc (ghi chu trong ten).
     */
    public static function retryFailed(string $jobId, string $source = 'UI', array $opts = []): ?array
    {
        $job = self::get($jobId);
        if (!$job) return null;
        $failed = self::failedItems($jobId);
        $targets = [];
        foreach ($failed as $f) $targets[] = (string)($f['target_id'] ?? '');
        $targets = array_values(array_filter($targets));
        $suffix = '';
        if (!$targets) {
            $t = json_decode((string)($job['targets'] ?? ''), true);
            $targets = is_array($t) ? array_values($t) : [];
            $suffix = ' (full)';
        }
        if (!$targets) return null;
        return self::create((string)$job['module'], (string)($job['name'] ?? '') . ' — retry' . $suffix,
            $targets, $source, [
                'job_type' => (string)($job['job_type'] ?? ''),
                'command_id' => $job['command_id'] ?? null,
                'chat_id' => $job['chat_id'] ?? null,
                'created_by' => $opts['created_by'] ?? ($job['created_by'] ?? ''),
                'resumable' => $job['resumable'] ?? 0,
            ]);
    }

    /** @return array{job, items, failed} chi tiet cho drawer */
    public static function detail(string $jobId): ?array
    {
        $job = self::get($jobId);
        if (!$job) return null;
        return ['job' => $job, 'items' => self::items($jobId), 'failed' => self::failedItems($jobId)];
    }

    /** Lich su co filter (module/status/source/date). */
    public static function history(array $f = [], int $limit = 50, int $offset = 0): array
    {
        try {
            self::ensureSchema();
            $limit = max(1, min(200, $limit));
            $offset = max(0, $offset);
            $w = [];
            $p = [];
            if (!empty($f['module']) && $f['module'] !== 'all') {
                $w[] = 'module=?';
                $p[] = $f['module'];
            }
            if (!empty($f['status']) && $f['status'] !== 'all') {
                if ($f['status'] === 'ACTIVE') $w[] = "status IN ('QUEUED','RUNNING','PAUSED')";
                else {
                    $w[] = 'status=?';
                    $p[] = $f['status'];
                }
            }
            if (!empty($f['source']) && $f['source'] !== 'all') {
                $w[] = 'source=?';
                $p[] = $f['source'];
            }
            if (!empty($f['date'])) {
                $w[] = 'DATE(created_at)=?';
                $p[] = $f['date'];
            }
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = db()->prepare("SELECT * FROM app_jobs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * App restart recovery (§39-§41): RUNNING cu -> INTERRUPTED (khong RUNNING gia).
     * Goi throttled tu API (khong moi request).
     */
    public static function recoverStale(): int
    {
        static $last = 0;
        if ((time() - $last) < 60) return 0;
        $last = time();
        try {
            self::ensureSchema();
            $st = db()->prepare("UPDATE app_jobs SET status='INTERRUPTED', finished_at=NOW(), completed_at=NOW(),
                    error_code='APP_RESTARTED', error='App restarted giua chung'
                WHERE status='RUNNING' AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))");
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
