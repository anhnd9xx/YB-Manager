<?php
declare(strict_types=1);
/**
 * RuntimeReconciler - Startup/runtime reconciliation (khong tin disk blindly).
 * Rules:
 *  - Saved RUNNING (DB) + OS khong thay managed process -> STOPPED.
 *  - Saved STOPPED + OS thay managed process (ownership via user-data-dir) -> RUNNING (attach).
 *  - Runtime files may khac -> discard (MachineContext).
 * Goi throttled (khong moi request): profiles list da refresh tung row san.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/MachineContext.php';
require_once __DIR__ . '/ProfilePathResolver.php';
require_once __DIR__ . '/SyncLogger.php';

class RuntimeReconciler
{
    private static int $lastRun = 0;
    public const THROTTLE_SEC = 120;

    /**
     * @return array{profiles:int, running_actual:int, db_fixed:int, stale_runtime_cleared:int, machine_changed:bool}
     */
    public static function reconcileAll(bool $force = false): array
    {
        $now = time();
        if (!$force && ($now - self::$lastRun) < self::THROTTLE_SEC) {
            return ['profiles' => 0, 'running_actual' => 0, 'db_fixed' => 0,
                'stale_runtime_cleared' => 0, 'machine_changed' => false, 'throttled' => true];
        }
        self::$lastRun = $now;
        $out = ['profiles' => 0, 'running_actual' => 0, 'db_fixed' => 0,
            'stale_runtime_cleared' => 0, 'machine_changed' => false, 'throttled' => false];
        // 1) Runtime files may khac -> discard
        try {
            $rc = MachineContext::reconcileTempRuntime();
            $out['stale_runtime_cleared'] = (int)($rc['discarded'] ?? 0);
            $out['machine_changed'] = (int)($rc['discarded'] ?? 0) > 0;
        } catch (Throwable $e) {
        }
        // 2) DB status vs OS (1 WMI scan)
        try {
            $aliveMap = chrome_running_info();
        } catch (Throwable $e) {
            $aliveMap = [];
        }
        $norm = [];
        foreach ($aliveMap as $d => $v) {
            $norm[strtolower(trim((string)$d))] = true;
        }
        try {
            $rows = db()->query('SELECT id, status, user_data_dir FROM profiles')->fetchAll();
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out['profiles']++;
            $dir = strtolower(trim((string)($r['user_data_dir'] ?? '')));
            $alive = $dir !== '' && isset($norm[$dir]);
            // Neu stored dir stale (PC khac) nhung resolved dir alive -> attach theo resolved
            if (!$alive && $dir !== '') {
                try {
                    $pr = ProfilePathResolver::resolve($r);
                    if ($pr['migrated']) {
                        $dir = strtolower(trim($pr['path']));
                        $alive = isset($norm[$dir]);
                    }
                } catch (Throwable $e) {
                }
            }
            if ($alive) $out['running_actual']++;
            $want = $alive ? 'running' : 'stopped';
            if (strtolower((string)($r['status'] ?? '')) !== $want) {
                try {
                    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute([$want, $id]);
                    $out['db_fixed']++;
                } catch (Throwable $e) {
                }
            }
        }
        try {
            SyncLogger::info('reconcile', '[RECONCILE] profiles=' . $out['profiles']
                . ' actual_running=' . $out['running_actual'] . ' db_fixed=' . $out['db_fixed']
                . ' stale_cleared=' . $out['stale_runtime_cleared']);
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Portable deployment check (first startup PC-B + diagnostics).
     */
    public static function deployCheck(): array
    {
        $rec = self::reconcileAll(true);
        $out = ['machine' => MachineContext::describe(),
            'machine_changed' => $rec['machine_changed'],
            'profiles' => $rec['profiles'],
            'running_actual' => $rec['running_actual'],
            'db_fixed' => $rec['db_fixed'],
            'stale_runtime_cleared' => $rec['stale_runtime_cleared'],
            'sessions' => 0, 'chrome' => ['path' => '', 'ok' => false],
            'monitors' => ['count' => 0], 'ports' => ['running' => 0, 'distinct' => 0]];
        try {
            $out['sessions'] = (int)db()->query('SELECT COUNT(DISTINCT profile_id) FROM tab_sessions')->fetchColumn();
        } catch (Throwable $e) {
        }
        try {
            $cp = chrome_path();
            $out['chrome'] = ['path' => $cp, 'ok' => chrome_available()];
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/MonitorRegistry.php';
            $d = MonitorRegistry::diagnostics();
            $out['monitors'] = ['count' => (int)($d['monitor_count'] ?? 0),
                'method' => $d['enum_method'] ?? null];
        } catch (Throwable $e) {
        }
        try {
            $rows = db()->query("SELECT debug_port FROM profiles WHERE status='running' AND debug_port>0")->fetchAll(PDO::FETCH_COLUMN);
            $ports = array_map('intval', (array)$rows);
            $out['ports'] = ['running' => count($ports), 'distinct' => count(array_unique($ports))];
        } catch (Throwable $e) {
        }
        return $out;
    }
}
