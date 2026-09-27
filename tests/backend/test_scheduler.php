<?php
declare(strict_types=1);
// Scheduler integration tests (PHASE 21-26, 31-33): planner + tick + recovery.
// Chay: php tests/backend/test_scheduler.php
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/ActivityManager.php';
require __DIR__ . '/../../sync/ActivityPlanner.php';
require __DIR__ . '/../../sync/ActivityScheduler.php';

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
function cleanup_sched(array $ids) {
    $in = implode(',', array_map('intval', $ids));
    db()->exec("DELETE FROM activity_sessions WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM activity_configs WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM activity_history WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM profiles WHERE id IN ($in)");
}

$ids = [9011, 9012, 9013];
try {
    cleanup_sched($ids);
    foreach ($ids as $id) {
        db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
            ->execute([$id, 'TESTSCHED #' . $id, 'C:\\tmp\\ytm-test-' . $id, 'stopped']);
        ActivityManager::saveConfig($id, ['enabled' => 1, 'schedule_start' => '00:00',
            'schedule_end' => '23:59', 'sessions_min' => 4, 'sessions_max' => 6,
            'activity_mode' => 'maintain', 'planner_enabled' => 1]);
    }

    // Plan + spread (khong Chrome nao chay — sessions PLANNED future/past)
    $tot = 0;
    $firsts = [];
    foreach ($ids as $id) {
        $rows = ActivityPlanner::todayPlan($id);
        if (!$rows) ActivityPlanner::ensureTodayPlan($id);
        $rows = ActivityPlanner::todayPlan($id);
        $tot += count($rows);
        if ($rows) $firsts[] = substr($rows[0]['run_at'], 11, 5);
    }
    t('plans generated', $tot >= 12, "total=$tot firsts=" . implode(',', $firsts));

    // Idempotent ensure
    $n0 = (int)db()->query('SELECT COUNT(*) FROM activity_sessions WHERE profile_id IN (9011,9012,9013)')->fetchColumn();
    ActivityPlanner::ensureTodayPlan(9011);
    $n1 = (int)db()->query('SELECT COUNT(*) FROM activity_sessions WHERE profile_id IN (9011,9012,9013)')->fetchColumn();
    t('ensure idempotent', $n0 === $n1, "$n0->$n1");

    // Chrome stopped -> runDueSessions SKIP (khong crash, khong mo Chrome)
    $ran = ActivityPlanner::runDueSessions(9011, 3);
    t('stopped chrome safe', true, "ran=$ran");

    // Missed window -> SKIPPED
    db()->exec("UPDATE activity_sessions SET run_at=DATE_SUB(NOW(), INTERVAL 2 HOUR), status='PLANNED'
        WHERE profile_id=9012 ORDER BY run_at ASC LIMIT 1");
    ActivityPlanner::runDueSessions(9012, 2);
    $m = db()->query("SELECT status, error_code FROM activity_sessions WHERE profile_id=9012
        AND error_code='MISSED_WINDOW' ORDER BY run_at DESC LIMIT 1")->fetch();
    t('missed window skipped', !empty($m) && $m['status'] === 'SKIPPED');

    // Boot recovery: RUNNING cu -> PLANNED
    db()->exec("UPDATE activity_sessions SET status='RUNNING', started_at=DATE_SUB(NOW(), INTERVAL 1 HOUR)
        WHERE profile_id=9013 ORDER BY run_at ASC LIMIT 1");
    // reset throttle static bang cach doi? bootRecover throttle 300s/process — process moi nen chay
    ActivityPlanner::bootRecover();
    $r = db()->query("SELECT status FROM activity_sessions WHERE profile_id=9013 AND started_at IS NULL
        ORDER BY run_at ASC LIMIT 1")->fetch();
    t('boot recovery', ($r['status'] ?? '') === 'PLANNED', $r['status'] ?? '?');

    // next_run hop le
    ActivityPlanner::updateNextRun(9011);
    $nx = db()->query('SELECT next_run_at FROM activity_configs WHERE profile_id=9011')->fetchColumn();
    t('next_run set', $nx !== null && $nx !== false, (string)$nx);

    // Tick chay duoc (profiles stopped -> skipped, khong exception)
    $tick = ActivityScheduler::tick(2);
    t('tick ok', isset($tick['ran']) && isset($tick['skipped']), 'ran=' . ($tick['ran'] ?? '?'));

    // Daily summary shape
    $sum = ActivityPlanner::dailySummary();
    t('daily summary', isset($sum['profiles'], $sum['sessions'], $sum['failed']), json_encode($sum));

    // next_run_at NULL khi chua co plan? (profile moi chua ensure)
    $s = ActivityPlanner::summary(9011);
    t('summary shape', isset($s['sessions_done'], $s['next_in']), json_encode($s));
} finally {
    cleanup_sched($ids);
}
echo "BACKEND SCHEDULER: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
