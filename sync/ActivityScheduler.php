<?php
declare(strict_types=1);
/**
 * ActivityScheduler - 1 scheduler chung cho toan he thong (§9, §34).
 *
 * Khong timer/profile, khong thread/profile. Moi tick xu ly toi da
 * ACTIVITY_CONCURRENCY profiles (bounded). Moi profile toi da 1 active task.
 *
 * Priority (§10): Lifecycle > Evaluation > Activity.
 *   - STARTING/CLOSING (life busy) -> WAIT
 *   - EVALUATING (eval stage live) -> WAIT
 *   - RESTORING (session lock) -> WAIT
 * Chrome STOPPED: khong tu mo tru auto_start_profile=ON (default OFF) (§11-§12).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ActivityManager.php';
require_once __DIR__ . '/ChromeBatchManager.php';
require_once __DIR__ . '/SyncLogger.php';

class ActivityScheduler
{
    public const CONCURRENCY = 4;
    public const MAX_ACTIVE_TASK = 1;

    /** Eval dang chay? (stage file tuoi + chua DONE). */
    public static function evalRunning(int $profileId): bool
    {
        try {
            require_once __DIR__ . '/ChannelEvaluationManager.php';
            $st = ChannelEvaluationManager::getStage($profileId);
            if ($st === null || $st === '') return false;
            return !in_array($st, ['SUCCESS', 'FAILED', 'CANCELLED', 'DONE'], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function restoring(int $profileId): bool
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_srestore_' . $profileId . '.lock';
        return is_file($f) && (time() - (int)@filemtime($f)) < 120;
    }

    public static function inHours(array $cfg, ?int $now = null): bool
    {
        $now = $now ?? time();
        $cur = date('H:i', $now);
        $ss = substr((string)($cfg['schedule_start'] ?? '08:00'), 0, 5);
        $se = substr((string)($cfg['schedule_end'] ?? '22:00'), 0, 5);
        if ($ss === $se) return true; // 24h
        if ($ss < $se) return $cur >= $ss && $cur < $se;
        return $cur >= $ss || $cur < $se; // qua dem
    }

    public static function paused(array $cfg, ?int $now = null): bool
    {
        $now = $now ?? time();
        return !empty($cfg['pause_until']) && strtotime((string)$cfg['pause_until']) > $now;
    }

    public static function due(array $cfg, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (empty($cfg['last_run_at'])) return true;
        $iv = max(15, (int)($cfg['interval_minutes'] ?? 30)) * 60;
        return (strtotime((string)$cfg['last_run_at']) + $iv) <= $now;
    }

    /**
     * 1 tick: chon toi da $max profiles due -> runCycle tuan tu (bounded).
     * @return array{ran:int, skipped:array, results:array}
     */
    public static function tick(int $max = self::CONCURRENCY): array
    {
        ActivityManager::ensureTables();
        $max = max(1, min(8, $max));
        $ran = 0;
        $skipped = [];
        $results = [];
        try {
            $rows = db()->query('SELECT c.*, p.status AS chrome_status
                FROM activity_configs c JOIN profiles p ON p.id=c.profile_id
                WHERE c.enabled=1 ORDER BY c.last_run_at IS NULL DESC, c.last_run_at ASC')->fetchAll();
        } catch (Throwable $e) {
            return ['ran' => 0, 'skipped' => ['db_error'], 'results' => []];
        }
        foreach ($rows as $r) {
            if ($ran >= $max) break;
            $id = (int)$r['profile_id'];
            $cfg = ActivityManager::getConfig($id);
            if (empty($cfg['enabled'])) continue;
            if (self::paused($cfg)) {
                $skipped[] = ['id' => $id, 'reason' => 'paused'];
                continue;
            }
            if (!self::inHours($cfg)) {
                $skipped[] = ['id' => $id, 'reason' => 'off_hours'];
                continue;
            }
            if (!self::due($cfg)) continue; // chua toi chu ky -> bo qua im lang
            // Priority: lifecycle > evaluation > activity (§10)
            if (ChromeBatchManager::isBusy($id)) {
                $skipped[] = ['id' => $id, 'reason' => 'lifecycle_busy'];
                continue;
            }
            if (self::evalRunning($id)) {
                $skipped[] = ['id' => $id, 'reason' => 'evaluating'];
                continue;
            }
            if (self::restoring($id)) {
                $skipped[] = ['id' => $id, 'reason' => 'restoring'];
                continue;
            }
            $running = (($r['chrome_status'] ?? '') === 'running') || self::chromeAlive($id);
            if (!$running) {
                if (empty($cfg['auto_start_profile'])) {
                    $skipped[] = ['id' => $id, 'reason' => 'stopped'];
                    continue;
                }
                // Auto-start: qua batch engine, bounded MAX_PARALLEL_START (§12)
                if (!self::autoStart($id)) {
                    $skipped[] = ['id' => $id, 'reason' => 'autostart_failed'];
                    continue;
                }
            }
            $t0 = microtime(true);
            try {
                $res = ActivityManager::runCycle($id);
                $ran++;
                $results[] = ['id' => $id, 'ms' => (int)round((microtime(true) - $t0) * 1000),
                    'tasks' => array_map(fn($t) => ($t['result'] ?? '?'), $res['tasks'] ?? [])];
            } catch (Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => 'exception'];
            }
        }
        try {
            SyncLogger::info('activity', '[SCHEDULER] tick ran=' . $ran . ' skipped=' . count($skipped));
        } catch (Throwable $e) {
        }
        // Event: AUTO ACTIVITY tick summary (§20) — 1 msg/tick, that qua rules (mac dinh DIGEST)
        if ($ran > 0) {
            try {
                require_once __DIR__ . '/EventBus.php';
                $ok = 0;
                $warn = 0;
                $fail = 0;
                $tasks = 0;
                foreach ($results as $one) {
                    foreach ((array)($one['tasks'] ?? []) as $t) {
                        $tasks++;
                        if (in_array($t, ['OPENED', 'REUSED', 'SUCCESS', 'CHECKED', 'CLOSED'], true)) $ok++;
                        elseif (in_array($t, ['BLOCKED', 'MISSING'], true)) $warn++;
                        else $fail++;
                    }
                }
                EventBus::emit(AppEvent::BATCH_COMPLETED, AppEvent::MOD_AUTO_ACTIVITY, AppEvent::SEV_SUCCESS,
                    'AUTO ACTIVITY HOÀN TẤT',
                    "Profiles: $ran\nSuccess: $ok\nWarning: $warn\nFailed: $fail\nTasks completed: $tasks",
                    ['status' => 'SUCCESS',
                        'data' => ['profiles' => $ran, 'success' => $ok, 'warning' => $warn,
                            'failed' => $fail, 'tasks' => $tasks]]);
            } catch (Throwable $e) {
            }
        }
        return ['ran' => $ran, 'skipped' => $skipped, 'results' => $results];
    }

    private static function chromeAlive(int $id): bool
    {
        try {
            $st = db()->prepare('SELECT user_data_dir FROM profiles WHERE id=?');
            $st->execute([$id]);
            $dir = strtolower(trim((string)($st->fetchColumn() ?? '')));
            if ($dir === '') return false;
            $map = chrome_running_info();
            return isset($map[$dir]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Auto-start 1 profile qua batch engine + doi CDP (toi da ~30s). */
    private static function autoStart(int $id): bool
    {
        try {
            $prep = ChromeBatchManager::startPrepare([$id]);
            if (empty($prep['queued'])) return false;
            $ch = ChromeBatchManager::startChunk($prep['batch_id'], 1);
            if (empty($ch['launched'])) {
                ChromeBatchManager::startCancel($prep['batch_id']);
                return false;
            }
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline) {
                $poll = ChromeBatchManager::startPoll($prep['batch_id']);
                if (!empty($poll['done'])) break;
                usleep(1000000);
            }
            // CDP ready?
            $st = db()->prepare('SELECT debug_port FROM profiles WHERE id=?');
            $st->execute([$id]);
            $port = (int)($st->fetchColumn() ?? 0);
            return $port > 0 && cdp_reachable($port);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Trang thai tong hop cho monitoring (§31). */
    public static function status(): array
    {
        ActivityManager::ensureTables();
        $enabled = 0;
        $running = 0;
        $paused = 0;
        try {
            $rows = db()->query('SELECT profile_id, pause_until FROM activity_configs WHERE enabled=1')->fetchAll();
            $enabled = count($rows);
            foreach ($rows as $r) {
                $id = (int)$r['profile_id'];
                if (!empty($r['pause_until']) && strtotime((string)$r['pause_until']) > time()) {
                    $paused++;
                    continue;
                }
                $st = ActivityManager::stateGet($id);
                if (!empty($st['running_task']) && (microtime(true) - (float)($st['running_ts'] ?? 0)) < 300) {
                    $running++;
                }
            }
            $errProfiles = (int)db()->query("SELECT COUNT(DISTINCT profile_id) FROM activity_history
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND (error_code IS NOT NULL OR result IN ('BLOCKED','MISSING'))")->fetchColumn();
        } catch (Throwable $e) {
            $errProfiles = 0;
        }
        return ['enabled' => $enabled, 'running' => $running,
            'waiting' => max(0, $enabled - $running - $paused),
            'paused' => $paused, 'errors' => $errProfiles ?? 0];
    }
}
