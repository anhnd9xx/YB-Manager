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
    public const ST_SUCCESS = 'SUCCESS';
    public const ST_PARTIAL = 'PARTIAL';
    public const ST_FAILED = 'FAILED';
    public const ST_CANCELLED = 'CANCELLED';

    private static int $seq = 0;

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
        self::ensureTable();
        $prefix = ['EVALUATION' => 'EVA', 'BROWSER' => 'BRW', 'AUTO_ACTIVITY' => 'ACT',
            'CHANNEL' => 'CHN', 'PROXY' => 'PRX', 'SYSTEM' => 'SYS'];
        $pre = $prefix[strtoupper($module)] ?? 'JOB';
        self::$seq++;
        $jobId = $pre . '-' . strtoupper(substr(md5(microtime(true) . self::$seq . mt_rand()), 0, 4))
            . '-' . date('His');
        $job = ['job_id' => $jobId, 'module' => strtoupper($module), 'name' => mb_substr($name, 0, 190),
            'targets' => json_encode(array_values($targets), JSON_UNESCAPED_UNICODE),
            'status' => self::ST_QUEUED, 'command_id' => $opts['command_id'] ?? null,
            'source' => $source, 'chat_id' => $opts['chat_id'] ?? null,
            'progress_total' => count($targets)];
        try {
            db()->prepare('INSERT INTO app_jobs (job_id, module, command_id, name, targets, status,
                    progress_total, source, chat_id, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$job['job_id'], $job['module'], $job['command_id'], $job['name'],
                    $job['targets'], $job['status'], $job['progress_total'], $job['source'], $job['chat_id']]);
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

    /** @return array[] running/queued (moi nhat truoc) */
    public static function active(int $limit = 20): array
    {
        self::ensureTable();
        try {
            $limit = max(1, min(50, $limit));
            return db()->query("SELECT * FROM app_jobs WHERE status IN ('QUEUED','RUNNING')
                ORDER BY created_at DESC LIMIT $limit")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Duplicate lock (§33): job cung command+targets dang chay? */
    public static function duplicate(string $commandId): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare("SELECT * FROM app_jobs WHERE command_id=? AND status IN ('QUEUED','RUNNING')
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
        $allow = ['status', 'progress_done', 'progress_total', 'success_count',
            'warning_count', 'failed_count', 'result', 'error', 'started_at', 'finished_at'];
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
            self::ensureTable();
            $params[] = $jobId;
            db()->prepare('UPDATE app_jobs SET ' . implode(',', $sets) . ' WHERE job_id=?')->execute($params);
        } catch (Throwable $e) {
        }
    }

    /** Claim 1 QUEUED job (worker). */
    public static function claim(): ?array
    {
        self::ensureTable();
        try {
            $r = db()->query("SELECT * FROM app_jobs WHERE status='QUEUED' ORDER BY created_at ASC LIMIT 1")->fetch();
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

    /** Hoan tat job + emit JOB_COMPLETED/JOB_FAILED (§18, §38). */
    public static function finish(string $jobId, string $status, ?string $result = null, ?string $error = null): void
    {
        self::update($jobId, ['status' => $status, 'finished_at' => date('Y-m-d H:i:s'),
            'result' => $result, 'error' => $error !== null ? mb_substr($error, 0, 500) : null]);
        try {
            $job = self::get($jobId);
            require_once __DIR__ . '/EventBus.php';
            $ok = in_array($status, [self::ST_SUCCESS, self::ST_PARTIAL], true);
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
            self::ensureTable();
            $st = db()->prepare("UPDATE app_jobs SET status='CANCELLED', finished_at=NOW()
                WHERE job_id=? AND status IN ('QUEUED','RUNNING')");
            $st->execute([$jobId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
