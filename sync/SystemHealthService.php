<?php
declare(strict_types=1);
/**
 * SystemHealthService - Health probes dang ky + aggregate + auto-recovery co policy.
 * Subsystem khac chi observe/aggregate; recover() di qua service hien co.
 * Status: HEALTHY | DEGRADED | UNHEALTHY | UNKNOWN.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class SystemHealthService
{
    public const HEALTHY = 'HEALTHY';
    public const DEGRADED = 'DEGRADED';
    public const UNHEALTHY = 'UNHEALTHY';
    public const UNKNOWN = 'UNKNOWN';

    /** @var array<string,array{label:string,probe:callable,recover:?callable}> */
    private static array $registry = [];
    private static bool $booted = false;

    public static function register(string $name, string $label, callable $probe, ?callable $recover = null): void
    {
        self::$registry[$name] = ['label' => $label, 'probe' => $probe, 'recover' => $recover];
    }

    private static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;
        self::register('database', 'Database', [self::class, 'probeDatabase']);
        self::register('telegram', 'Telegram Receiver', [self::class, 'probeTelegram'],
            [self::class, 'recoverTelegram']);
        self::register('job_worker', 'Job Workers', [self::class, 'probeJobWorker'],
            [self::class, 'recoverJobWorker']);
        self::register('scheduler', 'Scheduler', [self::class, 'probeScheduler']);
        self::register('proxy', 'Proxy Service', [self::class, 'probeProxy']);
        self::register('eventbus', 'Event Bus', [self::class, 'probeEventBus']);
        self::register('chrome', 'Chrome Manager', [self::class, 'probeChrome']);
        self::register('opencode', 'OpenCode Service', [self::class, 'probeOpenCode'],
            [self::class, 'recoverOpenCode']);
        self::register('activity', 'Auto Activity', [self::class, 'probeActivity'],
            [self::class, 'recoverActivity']);
    }

    /** @return array<string,array> name => probe result */
    public static function check(?string $only = null): array
    {
        self::boot();
        $out = [];
        foreach (self::$registry as $name => $r) {
            if ($only !== null && $only !== $name) continue;
            try {
                $res = ($r['probe'])();
                if (!is_array($res)) $res = ['status' => self::UNKNOWN];
            } catch (Throwable $e) {
                $res = ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
            }
            $out[$name] = ['name' => $name, 'label' => $r['label'],
                'status' => $res['status'] ?? self::UNKNOWN,
                'detail' => $res['detail'] ?? null,
                'metrics' => $res['metrics'] ?? [],
                'recoverable' => $r['recover'] !== null,
                'checked_at' => date('Y-m-d H:i:s')];
        }
        return $out;
    }

    /** Trang thai tong hop he thong (bo qua loi channel binh thuong). */
    public static function overall(array $checks = []): array
    {
        if (!$checks) $checks = self::check();
        $crit = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if (($c['status'] ?? '') === self::UNHEALTHY) $crit++;
            elseif (($c['status'] ?? '') === self::DEGRADED) $warn++;
        }
        if ($crit > 0) return ['level' => 'critical', 'label' => 'Cần chú ý', 'unhealthy' => $crit, 'degraded' => $warn];
        if ($warn > 0) return ['level' => 'warning', 'label' => "Có $warn cảnh báo", 'unhealthy' => 0, 'degraded' => $warn];
        return ['level' => 'ok', 'label' => 'Hệ thống ổn định', 'unhealthy' => 0, 'degraded' => 0];
    }

    // ---------------- Probes ----------------

    public static function probeDatabase(): array
    {
        try {
            if (!db_ping()) return ['status' => self::UNHEALTHY, 'detail' => 'Không kết nối được MySQL'];
            return ['status' => self::HEALTHY, 'detail' => 'MySQL phản hồi'];
        } catch (Throwable $e) {
            return ['status' => self::UNHEALTHY, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeTelegram(): array
    {
        try {
            require_once __DIR__ . '/TelegramSupervisor.php';
            $h = TelegramSupervisor::health();
            $st = (string)($h['state'] ?? 'STOPPED');
            $alive = !empty($h['worker_alive']);
            $metrics = ['worker_alive' => $alive, 'state' => $st,
                'uptime' => $h['uptime'] ?? null,
                'last_poll_success_at' => $h['last_poll_success_at'] ?? null,
                'last_inbound_at' => $h['last_inbound_at'] ?? null,
                'reconnect_count' => $h['reconnect_count'] ?? 0,
                'worker_restarts' => $h['worker_restarts'] ?? 0,
                'queue_depth' => $h['queue_depth'] ?? 0];
            if (in_array($st, ['AUTH_ERROR', 'CONFLICT'], true)) {
                return ['status' => self::UNHEALTHY, 'detail' => 'Token/xung đột: ' . $st, 'metrics' => $metrics];
            }
            if (!$alive) {
                require_once __DIR__ . '/TelegramGateway.php';
                if (TelegramGateway::shouldPoll()) {
                    return ['status' => self::UNHEALTHY, 'detail' => 'Cần poll nhưng worker chết', 'metrics' => $metrics];
                }
                return ['status' => self::HEALTHY, 'detail' => 'Idle hợp lệ (chưa kết nối)', 'metrics' => $metrics];
            }
            if (in_array($st, ['RECONNECTING', 'UNHEALTHY', 'ERROR'], true)) {
                return ['status' => self::DEGRADED, 'detail' => 'Đang khôi phục: ' . $st, 'metrics' => $metrics];
            }
            return ['status' => self::HEALTHY, 'detail' => 'Đang lắng nghe', 'metrics' => $metrics];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeJobWorker(): array
    {
        try {
            $alive = self::procAlive(__DIR__ . '/../bin/.job_worker.pid');
            $queued = 0;
            $running = 0;
            try {
                require_once __DIR__ . '/JobManager.php';
                JobManager::ensureSchema();
                $queued = (int)db()->query("SELECT COUNT(*) FROM app_jobs WHERE status='QUEUED'")->fetchColumn();
                $running = (int)db()->query("SELECT COUNT(*) FROM app_jobs WHERE status IN ('RUNNING','PAUSED')")->fetchColumn();
            } catch (Throwable $e) {
            }
            $metrics = ['alive' => $alive, 'queued' => $queued, 'running' => $running];
            if (!$alive && ($queued > 0 || $running > 0)) {
                return ['status' => self::UNHEALTHY, 'detail' => "Worker chết, còn $queued queued / $running running", 'metrics' => $metrics];
            }
            if (!$alive) return ['status' => self::DEGRADED, 'detail' => 'Worker chưa chạy (không có job chờ)', 'metrics' => $metrics];
            return ['status' => self::HEALTHY, 'detail' => "Worker sống · $running running / $queued queued", 'metrics' => $metrics];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeScheduler(): array
    {
        try {
            require_once __DIR__ . '/SchedulerService.php';
            $up = SchedulerService::upcoming(5);
            $n = SchedulerService::countEnabled();
            return ['status' => self::HEALTHY, 'detail' => "$n lịch đang bật · " . count($up) . ' lượt sắp tới',
                'metrics' => ['enabled' => $n, 'upcoming' => count($up)]];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeProxy(): array
    {
        try {
            $total = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
            $dead = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn();
            $metrics = ['total' => $total, 'dead' => $dead, 'healthy' => $total - $dead];
            if ($total === 0) return ['status' => self::UNKNOWN, 'detail' => 'Chưa có proxy', 'metrics' => $metrics];
            if ($dead >= $total) return ['status' => self::UNHEALTHY, 'detail' => "Tất cả $total proxy lỗi", 'metrics' => $metrics];
            if ($dead > 0) return ['status' => self::DEGRADED, 'detail' => "$dead/$total proxy lỗi", 'metrics' => $metrics];
            return ['status' => self::HEALTHY, 'detail' => "$total/$total khỏe", 'metrics' => $metrics];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeEventBus(): array
    {
        try {
            $last = null;
            try {
                $last = db()->query('SELECT MAX(created_at) FROM app_events')->fetchColumn();
            } catch (Throwable $e) {
            }
            $age = $last ? (time() - strtotime((string)$last)) : null;
            return ['status' => self::HEALTHY, 'detail' => $last ? "Event mới nhất $last" : 'Chưa có event',
                'metrics' => ['last_event_at' => $last, 'last_event_age_sec' => $age]];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeChrome(): array
    {
        try {
            $total = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
            $running = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
            return ['status' => self::HEALTHY, 'detail' => "$running/$total đang chạy",
                'metrics' => ['total' => $total, 'running' => $running]];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function probeOpenCode(): array
    {
        try {
            require_once __DIR__ . '/OpenCodeService.php';
            $st = OpenCodeService::status();
            $state = (string)($st['state'] ?? 'STOPPED');
            $metrics = ['state' => $state, 'pid' => $st['pid'] ?? null,
                'busy_sessions' => $st['busy_sessions'] ?? 0, 'url' => $st['url'] ?? ''];
            if (in_array($state, ['ONLINE', 'BUSY'], true)) {
                return ['status' => self::HEALTHY, 'detail' => "OpenCode $state", 'metrics' => $metrics];
            }
            if ($state === 'ERROR') {
                return ['status' => self::UNHEALTHY, 'detail' => 'Process sống, API chết', 'metrics' => $metrics];
            }
            return ['status' => self::DEGRADED, 'detail' => 'OpenCode chưa chạy (Q&A/Dev Jobs dùng CLI/autostart)', 'metrics' => $metrics];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function recoverOpenCode(): bool
    {
        require_once __DIR__ . '/OpenCodeService.php';
        $r = OpenCodeService::restart();
        return !empty($r['ok']);
    }

    public static function probeActivity(): array
    {
        try {
            require_once __DIR__ . '/ActivityScheduler.php';
            $st = ActivityScheduler::status();
            $alive = self::procAlive(__DIR__ . '/../bin/.activity_scheduler.pid');
            $metrics = ['scheduler_alive' => $alive, 'enabled' => $st['enabled'] ?? 0,
                'running' => $st['running'] ?? 0, 'waiting' => $st['waiting'] ?? 0,
                'errors' => $st['errors'] ?? 0];
            if (empty($st['enabled'])) {
                return ['status' => self::HEALTHY, 'detail' => 'Chưa bật kênh nào', 'metrics' => $metrics];
            }
            if (!$alive) {
                return ['status' => self::DEGRADED, 'detail' => 'Scheduler chưa chạy (bật ở Auto Activity)', 'metrics' => $metrics];
            }
            if (($st['errors'] ?? 0) > 0) {
                return ['status' => self::DEGRADED, 'detail' => ($st['errors'] ?? 0) . ' kênh lỗi 24h', 'metrics' => $metrics];
            }
            return ['status' => self::HEALTHY,
                'detail' => ($st['enabled'] ?? 0) . ' kênh · ' . ($st['running'] ?? 0) . ' đang chạy',
                'metrics' => $metrics];
        } catch (Throwable $e) {
            return ['status' => self::UNKNOWN, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function recoverActivity(): bool
    {
        // Start scheduler daemon (giong monitor_start)
        try {
            if (self::procAlive(__DIR__ . '/../bin/.activity_scheduler.pid')) return true;
            $php = php_cli_binary();
            if ($php === '') return false;
            $script = __DIR__ . '/../bin/activity_scheduler.php';
            pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "'
                . __DIR__ . '/../bin/activity_scheduler.log" 2>&1', 'r'));
            for ($i = 0; $i < 10; $i++) {
                usleep(500000);
                if (self::procAlive(__DIR__ . '/../bin/.activity_scheduler.pid')) return true;
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---------------- Recovery (co policy, khong loop vo han) ----------------

    private static function policy(string $name): array
    {
        return ['enabled' => get_setting('cc_recover_' . $name, '1') === '1',
            'max_attempts' => max(1, (int)get_setting('cc_recover_' . $name . '_max', '3')),
            'cooldown_sec' => max(30, (int)get_setting('cc_recover_' . $name . '_cooldown', '300'))];
    }

    private static function attempts(string $name): array
    {
        $j = json_decode(get_setting('cc_recover_state_' . $name, ''), true);
        return is_array($j) ? $j : ['count' => 0, 'first_at' => 0, 'last_at' => 0];
    }

    private static function recordAttempt(string $name): void
    {
        $a = self::attempts($name);
        $now = time();
        if ($now - (int)($a['first_at'] ?? 0) > 3600) $a = ['count' => 0, 'first_at' => $now, 'last_at' => 0];
        $a['count'] = (int)($a['count'] ?? 0) + 1;
        $a['last_at'] = $now;
        if (empty($a['first_at'])) $a['first_at'] = $now;
        set_setting('cc_recover_state_' . $name, json_encode($a));
    }

    /** @return array{ok, message} */
    public static function recover(string $name): array
    {
        self::boot();
        $r = self::$registry[$name] ?? null;
        if (!$r || $r['recover'] === null) return ['ok' => false, 'message' => 'Subsystem không hỗ trợ recover'];
        $pol = self::policy($name);
        if (!$pol['enabled']) return ['ok' => false, 'message' => 'Auto-recovery đang tắt'];
        $a = self::attempts($name);
        if ((int)($a['count'] ?? 0) >= $pol['max_attempts'] && (time() - (int)($a['first_at'] ?? 0)) < 3600) {
            return ['ok' => false, 'message' => 'Vượt số lần recover cho phép trong 1 giờ'];
        }
        if ((time() - (int)($a['last_at'] ?? 0)) < $pol['cooldown_sec']) {
            return ['ok' => false, 'message' => 'Đang cooldown, thử lại sau'];
        }
        try {
            $ok = (bool)($r['recover'])();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 150)];
        }
        if ($ok) {
            self::recordAttempt($name);
            try {
                SyncLogger::warn('health', "[RECOVER] $name recovered");
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'message' => 'Đã recover'];
        }
        return ['ok' => false, 'message' => 'Recover thất bại'];
    }

    public static function recoverTelegram(): bool
    {
        require_once __DIR__ . '/TelegramSupervisor.php';
        $prim = null;
        try {
            require_once __DIR__ . '/TgBotStore.php';
            $prim = TgBotStore::primary();
        } catch (Throwable $e) {
        }
        $r = TelegramSupervisor::restart($prim ? (int)$prim['id'] : 0);
        return !empty($r['ok']);
    }

    public static function recoverJobWorker(): bool
    {
        if (self::procAlive(__DIR__ . '/../bin/.job_worker.pid')) return true;
        $php = php_cli_binary();
        if ($php === '') return false;
        $script = __DIR__ . '/../bin/job_worker.php';
        $log = __DIR__ . '/../bin/job_worker.log';
        if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
        pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1', 'r'));
        for ($i = 0; $i < 10; $i++) {
            usleep(500000);
            if (self::procAlive(__DIR__ . '/../bin/.job_worker.pid')) return true;
        }
        return false;
    }

    private static function procAlive(string $pidFile): bool
    {
        if (!is_file($pidFile)) return false;
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid <= 0) return false;
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        foreach ($out as $line) {
            if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                && stripos($m[1], 'php') !== false) {
                return true;
            }
        }
        return false;
    }
}
