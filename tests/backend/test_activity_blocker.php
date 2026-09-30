<?php
declare(strict_types=1);
// Blocker test: 1-minute FIXED MAINTAIN 24/24 phai dispatch trong ~1 phut.
// Dung profile id 70 nhu report (fixture, tu don).
// Chay: php tests/backend/test_activity_blocker.php
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/ActivityManager.php';
require __DIR__ . '/../../sync/ActivityScheduler.php';
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

$id = 70;
function cleanup_b70() {
    try { db()->exec('DELETE FROM activity_sessions WHERE profile_id=70'); } catch (Throwable $e) {}
    try { db()->exec('DELETE FROM activity_history WHERE profile_id=70'); } catch (Throwable $e) {}
    try { db()->exec('DELETE FROM activity_configs WHERE profile_id=70'); } catch (Throwable $e) {}
    try { db()->exec('DELETE FROM profiles WHERE id=70'); } catch (Throwable $e) {}
    @unlink(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_activity_70.json');
}

try {
    cleanup_b70();
    db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
        ->execute([$id, 'BLOCKER #70', 'C:\\tmp\\ytm-blocker-70', 'stopped']);

    // ---- 1. Save dung config report: cycle 1 khong bi clamp, mode canonical
    $cur = ActivityManager::getConfig($id);
    $r = ActivityManager::saveConfig($id, array_merge($cur, [
        'enabled' => 1, 'schedule_mode' => 'ALWAYS',
        'interval_minutes' => 1, 'interval_mode' => 'FIXED',
        'activity_mode' => 'maintain',
        'required_pages' => ['gmail', 'google', 'youtube', 'drive', 'calendar'],
    ]));
    $c = ActivityManager::getConfig($id);
    t('db_cycle_1_not_clamped', $r['ok'] && (int)($c['interval_minutes'] ?? 0) === 1,
        'stored=' . ($c['interval_minutes'] ?? '?'));
    t('db_schedule_canonical', ($c['schedule_mode'] ?? '') === 'ALWAYS');
    t('db_required_5', count(ActivityManager::requiredUrls($c)) === 5,
        implode(',', array_map(fn($u) => parse_url($u, PHP_URL_HOST), ActivityManager::requiredUrls($c))));

    // ---- 2. Ngay sau Save: next_run ~ +1m (khong phai session xa / null)
    $nx = (string)($c['next_run_at'] ?? '');
    $dMin = $nx !== '' ? (strtotime($nx) - time()) / 60 : 9999;
    t('next_run_1min_after_save', $nx !== '' && $dMin >= 0 && $dMin <= 2, "$nx (+{$dMin}m)");

    // ---- 3. Update 30 -> 1: cung row, version++, next_run co lai
    ActivityManager::saveConfig($id, array_merge(ActivityManager::getConfig($id),
        ['interval_minutes' => 30]));
    $nx30 = (string)(ActivityManager::getConfig($id)['next_run_at'] ?? '');
    $v30 = (int)(ActivityManager::getConfig($id)['config_version'] ?? 0);
    ActivityManager::saveConfig($id, array_merge(ActivityManager::getConfig($id),
        ['interval_minutes' => 1]));
    $c1 = ActivityManager::getConfig($id);
    $nx1 = (string)($c1['next_run_at'] ?? '');
    t('update_same_row_recalc', (int)($c1['config_version'] ?? 0) === $v30 + 1
        && strtotime($nx1) < strtotime($nx30), "30m->$nx30 1m->$nx1");

    // ---- 4. ALWAYS active 00:01/06:00/12:00/23:59
    $okAll = true;
    foreach (['00:01', '06:00', '12:00', '23:59'] as $hm) {
        if (!ActivityScheduler::inHours(['schedule_mode' => 'ALWAYS'], strtotime(date('Y-m-d') . ' ' . $hm))) $okAll = false;
    }
    t('always_4_times', $okAll);

    // ---- 5. Due sau 70s voi cycle 1; cycle 30 thi chua due
    db()->prepare('UPDATE activity_configs SET last_run_at=DATE_SUB(NOW(), INTERVAL 70 SECOND) WHERE profile_id=70')->execute();
    $cd = ActivityManager::getConfig($id);
    t('due_after_70s_cycle1', ActivityScheduler::due($cd) === true);
    db()->prepare('UPDATE activity_configs SET interval_minutes=30 WHERE profile_id=70')->execute();
    t('not_due_cycle30', ActivityScheduler::due(ActivityManager::getConfig($id)) === false);
    db()->prepare('UPDATE activity_configs SET interval_minutes=1 WHERE profile_id=70')->execute();

    // ---- 6. Session planner xa + cycle 1 -> next_run van ~1m (min)
    ActivityPlanner::ensureTodayPlan($id);
    ActivityPlanner::updateNextRun($id);
    $nxMin = (string)(ActivityManager::getConfig($id)['next_run_at'] ?? '');
    $dMin2 = $nxMin !== '' ? (strtotime($nxMin) - time()) / 60 : 9999;
    t('next_run_min_planner_legacy', $nxMin !== '' && $dMin2 <= 2, "$nxMin (+{$dMin2}m)");

    // ---- 7. Tab matching canonical domains (§20)
    $pairs = [['https://mail.google.com/mail/u/0/#inbox', 'mail.google.com'],
        ['https://www.google.com/', 'https://www.google.com/'],
        ['https://www.youtube.com/watch?v=x', 'youtube.com'],
        ['https://drive.google.com/drive/my-drive', 'drive.google.com'],
        ['https://calendar.google.com/calendar', 'calendar.google.com']];
    $okM = true;
    foreach ($pairs as [$tab, $pat]) {
        if (!ActivityManager::domainMatch($tab, $pat)) $okM = false;
    }
    t('tab_matching_canonical', $okM);
} finally {
    cleanup_b70();
}

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
