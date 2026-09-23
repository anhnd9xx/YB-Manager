<?php
declare(strict_types=1);
/**
 * TelegramSupervisor - Quan ly lifecycle inbound receiver (§4).
 * start/stop/restart/health per connection + watchdog chung (khong thread).
 * Auto-start khi app mo (ensure, throttle 60s) (§42). App shutdown KHONG
 * doi enabled (§44). Pairing/chat khong so huu worker (§24-§25).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class TelegramSupervisor
{
    public const WATCHDOG_STALE_SEC = 75; // LISTENING nhung khong completed qua 75s -> restart
    public const ENSURE_THROTTLE_SEC = 60;

    public static function stateFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_supervisor.json';
    }

    /** Throttle: khong ensure lien tuc moi request. */
    private static function throttled(): bool
    {
        $f = self::stateFile();
        if (!is_file($f)) return false;
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) && (microtime(true) - (float)($j['ensure_at'] ?? 0)) < self::ENSURE_THROTTLE_SEC;
    }

    private static function touchEnsure(): void
    {
        $f = self::stateFile();
        $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $j['ensure_at'] = microtime(true);
        @file_put_contents($f, json_encode($j));
    }

    /**
     * Dam bao receiver chay khi app mo (§42): primary CONNECTED+enabled
     * (hoac legacy connected) ma worker chet -> spawn. Watchdog stale -> restart.
     */
    public static function ensure(): void
    {
        if (self::throttled()) return;
        self::touchEnsure();
        try {
            require_once __DIR__ . '/TgBotStore.php';
            TgBotStore::ensureTables();
            $conns = TgBotStore::list();
            if ($conns) {
                foreach ($conns as $c) {
                    $id = (int)$c['id'];
                    if (!empty($c['is_primary']) || count($conns) === 1) {
                        self::ensureConnection($id, $c);
                    }
                }
                self::watchdog();
                return;
            }
        } catch (Throwable $e) {
        }
        // Legacy (chua migrate): token + inbound/setup active -> worker
        try {
            require_once __DIR__ . '/TelegramGateway.php';
            if (!TelegramGateway::shouldPoll()) return;
            require_once __DIR__ . '/TelegramPollingCtl.php';
            TelegramPollingCtl::ensureRunning();
        } catch (Throwable $e) {
        }
    }

    private static function ensureConnection(int $id, array $conn): void
    {
        $enabled = !isset($conn['enabled']) || (int)$conn['enabled'] !== 0;
        $auto = !isset($conn['auto_connect']) || (int)$conn['auto_connect'] !== 0;
        $status = (string)($conn['status'] ?? '');
        if (!$enabled || !$auto) return;
        if (!in_array($status, [TgBotStore::ST_CONNECTED, TgBotStore::ST_CONNECTING], true)) return;
        require_once __DIR__ . '/TelegramPollingCtl.php';
        if (!TelegramPollingCtl::alive()) {
            TelegramPollingCtl::ensureRunning();
            try {
                SyncLogger::info('telegram', '[SUPERVISOR] auto-start worker connection=' . $id);
            } catch (Throwable $e) {
            }
        }
    }

    /** Watchdog chung (§16): worker alive nhung stale -> restart (khong restart app). */
    public static function watchdog(): void
    {
        try {
            require_once __DIR__ . '/TelegramGateway.php';
            $gw = TelegramGateway::connectionState();
            if (($gw['state'] ?? '') === 'UNHEALTHY') {
                require_once __DIR__ . '/TelegramPollingCtl.php';
                $pid = TelegramPollingCtl::pid();
                if ($pid !== null) {
                    @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
                }
                usleep(500000);
                TelegramPollingCtl::ensureRunning();
                try {
                    require_once __DIR__ . '/TgBotStore.php';
                    $prim = TgBotStore::primary();
                    if ($prim) {
                        $rc = ((int)($prim['reconnect_count'] ?? 0)) + 1;
                        db()->prepare('UPDATE telegram_bot_connections SET reconnect_count=? WHERE id=?')
                            ->execute([$rc, (int)$prim['id']]);
                    }
                    SyncLogger::warn('telegram', '[WATCHDOG] stale worker restarted');
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    public static function start(int $connectionId): array
    {
        try {
            require_once __DIR__ . '/TgBotStore.php';
            $conn = TgBotStore::get($connectionId);
            if (!$conn) return ['ok' => false, 'error' => 'Không thấy Bot.'];
            db()->prepare('UPDATE telegram_bot_connections SET enabled=1 WHERE id=?')->execute([$connectionId]);
            $r = TgBotStore::connect($connectionId);
            return $r;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Lỗi khởi động.'];
        }
    }

    public static function stop(int $connectionId): array
    {
        try {
            require_once __DIR__ . '/TgBotStore.php';
            // enabled=false (manual disconnect). App shutdown KHONG goi ham nay (§44).
            db()->prepare('UPDATE telegram_bot_connections SET enabled=0 WHERE id=?')->execute([$connectionId]);
            return TgBotStore::disconnect($connectionId);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Lỗi dừng.'];
        }
    }

    public static function restart(int $connectionId): array
    {
        // Chi restart receiver (§35), khong restart Tool
        try {
            require_once __DIR__ . '/TelegramPollingCtl.php';
            $pid = TelegramPollingCtl::pid();
            if ($pid !== null) {
                @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
                usleep(800000);
            }
            $ok = TelegramPollingCtl::ensureRunning();
            try {
                SyncLogger::info('telegram', '[SUPERVISOR] manual restart connection=' . $connectionId . ' ok=' . (int)$ok);
            } catch (Throwable $e) {
            }
            return ['ok' => $ok];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Lỗi restart.'];
        }
    }

    /** @return array health tong hop (DB + worker + queue) */
    public static function health(?int $connectionId = null): array
    {
        $out = ['state' => 'STOPPED', 'health' => 'FAILED', 'worker_alive' => false,
            'queue_depth' => 0, 'uptime' => null, 'last_poll_at' => null,
            'last_poll_success_at' => null, 'last_inbound_at' => null,
            'offset' => 0, 'reconnect_count' => 0, 'duplicates' => 0, 'last_error' => null];
        try {
            require_once __DIR__ . '/TgBotStore.php';
            $conn = $connectionId ? TgBotStore::get($connectionId) : TgBotStore::primary();
            if ($conn) {
                $out['connection_status'] = $conn['status'] ?? null;
                $out['offset'] = (int)($conn['last_update_id'] ?? 0);
                $out['last_poll_at'] = $conn['last_poll_at'] ?? null;
                $out['last_poll_success_at'] = $conn['last_poll_success_at'] ?? null;
                $out['last_inbound_at'] = $conn['last_inbound_at'] ?? null;
                $out['reconnect_count'] = (int)($conn['reconnect_count'] ?? 0);
                $out['last_error'] = $conn['last_error_code'] ?? $conn['last_error'] ?? null;
            }
            require_once __DIR__ . '/TelegramGateway.php';
            $gw = TelegramGateway::connectionState();
            $out['state'] = $gw['state'] ?? 'STOPPED';
            require_once __DIR__ . '/TelegramPollingCtl.php';
            $out['worker_alive'] = TelegramPollingCtl::alive();
            if (!empty($gw['worker_started_at'])) {
                $up = max(0, time() - (int)$gw['worker_started_at']);
                $out['uptime'] = ($up >= 3600 ? intdiv($up, 3600) . 'h ' : '') . intdiv($up % 3600, 60) . 'm';
            }
            if (empty($out['offset'])) {
                require_once __DIR__ . '/TelegramOffset.php';
                $out['offset'] = TelegramOffset::get();
            }
            try {
                $out['queue_depth'] = (int)db()->query("SELECT COUNT(*) FROM tg_update_queue WHERE status='QUEUED'")->fetchColumn();
            } catch (Throwable $e) {
            }
            require_once __DIR__ . '/TelegramCounters.php';
            $c = TelegramCounters::all();
            $out['duplicates'] = (int)($c['in_dedup'] ?? 0) + (int)($c['ui_dedup'] ?? 0);
            $out['in_recv'] = (int)($c['in_recv'] ?? 0);
            $out['out_sent'] = (int)($c['out_sent'] ?? 0);
            // Health tong hop (§34): FAILED neu can can thiep; idle-hop-le thi HEALTHY
            $st = $out['state'];
            if (in_array($st, ['AUTH_ERROR', 'CONFLICT', 'WEBHOOK_CONFLICT'], true)) {
                $out['health'] = 'FAILED';
            } elseif (in_array($st, ['RECONNECTING', 'BACKOFF', 'UNHEALTHY'], true)) {
                $out['health'] = 'DEGRADED';
            } elseif ($st === 'LISTENING') {
                $out['health'] = 'HEALTHY';
            } elseif (!$out['worker_alive'] && TelegramGateway::shouldPoll()) {
                // Can poll nhung worker chet -> FAILED (supervisor se spawn lai)
                $out['health'] = 'FAILED';
            } else {
                // Idle hop le (chua den luc poll) -> HEALTHY
                $out['health'] = 'HEALTHY';
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
