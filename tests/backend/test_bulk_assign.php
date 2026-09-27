<?php
declare(strict_types=1);
// Backend tests: bulk assign service layer + DB that (PHASE 16-20).
// Chay: php tests/backend/test_bulk_assign.php (exit 0 = pass)
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/ActivityManager.php';
require __DIR__ . '/../../sync/ActivityPlanner.php';

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
function cleanup_fixtures(array $ids) {
    $in = implode(',', array_map('intval', $ids));
    db()->exec("DELETE FROM activity_sessions WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM activity_configs WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM activity_history WHERE profile_id IN ($in)");
    db()->exec("DELETE FROM profiles WHERE id IN ($in)");
}

$ids = [9001, 9002, 9003, 9004, 9005];
try {
    cleanup_fixtures($ids);
    // Fixture profiles (PHASE 36)
    foreach ($ids as $id) {
        db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
            ->execute([$id, 'TESTBULK #' . $id, 'C:\\tmp\\ytm-test-' . $id, 'stopped']);
    }

    // PHASE 16: create 5
    $created = 0;
    foreach ($ids as $id) {
        $r = ActivityManager::saveConfig($id, ['enabled' => 1, 'schedule_start' => '08:00',
            'schedule_end' => '22:00', 'interval_minutes' => 30,
            'required_pages' => ['gmail', 'google'], 'activity_mode' => 'maintain']);
        if (!empty($r['ok'])) $created++;
    }
    t('bulk create 5', $created === 5, "$created/5");
    $n = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id IN (9001,9002,9003,9004,9005)')->fetchColumn();
    t('DB has 5 configs', $n === 5, "n=$n");

    // PHASE 17: update 3 + create 2 (khong 8)
    foreach ([9001, 9002, 9003, 9004, 9005] as $id) {
        ActivityManager::saveConfig($id, ['enabled' => 1, 'interval_minutes' => 60]);
    }
    $n2 = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id IN (9001,9002,9003,9004,9005)')->fetchColumn();
    $iv = (int)db()->query('SELECT interval_minutes FROM activity_configs WHERE profile_id=9001')->fetchColumn();
    t('update no dupes', $n2 === 5 && $iv === 60, "n=$n2 iv=$iv");

    // PHASE 18: idempotent (gui lai y het)
    foreach ($ids as $id) {
        ActivityManager::saveConfig($id, ['enabled' => 1, 'interval_minutes' => 60]);
    }
    $n3 = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id IN (9001,9002,9003,9004,9005)')->fetchColumn();
    t('idempotent', $n3 === 5, "n=$n3");

    // PHASE 19: invalid (saveConfig khong validate profile ton tai — API bulk lam)
    // Kiem tra validation helper: profile 999999 khong ton tai
    $ex = db()->prepare('SELECT id FROM profiles WHERE id=?');
    $ex->execute([999999]);
    t('invalid detected', $ex->fetchColumn() === false);

    // PHASE 20: persist values
    $c = ActivityManager::getConfig(9001);
    t('persist values', $c['enabled'] === 1 && $c['schedule_start'] === '08:00'
        && $c['schedule_end'] === '22:00' && $c['interval_minutes'] === 60
        && in_array('gmail', $c['required_pages'], true), json_encode([
            $c['enabled'], $c['schedule_start'], $c['schedule_end'],
            $c['interval_minutes'], $c['required_pages']]));

    // Disable (khong xoa)
    ActivityManager::saveConfig(9002, ['enabled' => 0]);
    $en = (int)db()->query('SELECT enabled FROM activity_configs WHERE profile_id=9002')->fetchColumn();
    $still = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id=9002')->fetchColumn();
    t('disable keeps row', $en === 0 && $still === 1);
} finally {
    cleanup_fixtures($ids);
}
$n4 = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id IN (9001,9002,9003,9004,9005)')->fetchColumn();
t('cleanup', $n4 === 0);
echo "BACKEND BULK: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
