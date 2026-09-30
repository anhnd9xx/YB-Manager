<?php
declare(strict_types=1);
// Supervisor test (harness-safe: khong kill trong cung process).
// Chay: php tests/backend/test_activity_supervisor.php
// Buoc kill/recovery chay rieng (CHEAT=kill) sau buoc ensure.
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/ActivitySupervisor.php';

$pass = 0;
$fail = 0;
function t($name, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FAIL $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    }
}

$mode = ($argv[1] ?? 'ensure');
if ($mode === 'kill') {
    // Buoc 2 (chay rieng): kill worker hien tai (mo phong crash)
    $pid = ActivitySupervisor::pid();
    if ($pid) {
        @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . (int)$pid . ' -Force -ErrorAction SilentlyContinue"');
        echo "KILLED $pid" . PHP_EOL;
    } else {
        echo "NO_WORKER" . PHP_EOL;
    }
    exit(0);
}

// Buoc 1: ensure (khoi dong neu can) + singleton + heartbeat
$r1 = ActivitySupervisor::ensure();
t('ensure_running', !empty($r1['running']) && !empty($r1['pid']), 'pid=' . ($r1['pid'] ?? '?'));
sleep(2);
$st = ActivitySupervisor::state();
t('heartbeat_fresh', $st['state'] === 'RUNNING' && ($st['heartbeat_age'] ?? 999) <= 60,
    'state=' . $st['state'] . ' age=' . ($st['heartbeat_age'] ?? '?'));
$r2 = ActivitySupervisor::ensure();
t('singleton_same_pid', empty($r2['spawned']) && (int)($r2['pid'] ?? 0) === (int)($r1['pid'] ?? -1),
    'pid=' . ($r2['pid'] ?? '?'));
t('heartbeat_fields', isset($st['started_at'], $st['last_tick']),
    'tick=' . ($st['last_tick'] ?? '?'));

echo "WORKER_PID=" . (ActivitySupervisor::pid() ?? 'none') . PHP_EOL;
echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
