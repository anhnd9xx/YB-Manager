<?php
declare(strict_types=1);
// Auto Activity V3 tests: upsert/version, bulk, 24/24, random, selector, cap, restart.
// Chay: php tests/backend/test_activity_v3.php
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

$ids = [9031, 9032, 9033, 9034, 9035, 9036, 9037, 9038, 9039, 9040, 9041, 9042, 9043, 9044, 9045, 9050];
function cleanup_v3(array $ids) {
    $in = implode(',', array_map('intval', $ids));
    try { db()->exec("DELETE FROM activity_sessions WHERE profile_id IN ($in)"); } catch (Throwable $e) {}
    try { db()->exec("DELETE FROM activity_history WHERE profile_id IN ($in)"); } catch (Throwable $e) {}
    try { db()->exec("DELETE FROM activity_configs WHERE profile_id IN ($in)"); } catch (Throwable $e) {}
    try { db()->exec("DELETE FROM profiles WHERE id IN ($in)"); } catch (Throwable $e) {}
    foreach ($ids as $id) {
        @unlink(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_activity_' . $id . '.json');
    }
}

try {
    cleanup_v3($ids);
    foreach ($ids as $id) {
        db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
            ->execute([$id, 'TESTV3 #' . $id, 'C:\\tmp\\ytm-test-v3-' . $id, 'stopped']);
    }

    // ---- 1. UPSERT same row: khong duplicate, version++, field moi co hieu luc
    $r1 = ActivityManager::saveConfig(9031, ['enabled' => 1, 'schedule_mode' => 'WINDOW',
        'interval_minutes' => 30, 'activity_mode' => 'maintain', 'planner_enabled' => 0]);
    $v1 = (int)(ActivityManager::getConfig(9031)['config_version'] ?? 0);
    $r2 = ActivityManager::saveConfig(9031, ['enabled' => 1, 'schedule_mode' => 'ALWAYS',
        'interval_mode' => 'RANDOM_RANGE', 'random_min' => 45, 'random_max' => 120,
        'activity_mode' => 'full', 'planner_enabled' => 0]);
    $c2 = ActivityManager::getConfig(9031);
    $nRows = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id=9031')->fetchColumn();
    t('upsert_same_row', $r1['ok'] && $r2['ok'] && $nRows === 1, "rows=$nRows v{$v1}->v" . ($c2['config_version'] ?? '?'));
    t('upsert_version_bump', (int)($c2['config_version'] ?? 0) === $v1 + 1);
    t('upsert_new_fields', ($c2['schedule_mode'] ?? '') === 'ALWAYS'
        && ($c2['activity_mode'] ?? '') === 'full' && (int)($c2['random_min'] ?? 0) === 45);

    // ---- 2. BULK 10 existing -> created=0 updated=10, khong duplicate
    $ten = [9032, 9033, 9034, 9035, 9036, 9037, 9038, 9039, 9040, 9041];
    foreach ($ten as $id) ActivityManager::saveConfig($id, ['enabled' => 1, 'planner_enabled' => 0]);
    $b = ActivityManager::bulkSave($ten, ['enabled' => 1, 'schedule_mode' => 'ALWAYS',
        'interval_minutes' => 60, 'planner_enabled' => 0]);
    $nRows10 = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE profile_id IN (9032,9033,9034,9035,9036,9037,9038,9039,9040,9041)')->fetchColumn();
    t('bulk_10_existing', $b['created'] === 0 && $b['updated'] === 10 && $b['failed'] === 0 && $nRows10 === 10,
        "created={$b['created']} updated={$b['updated']} failed={$b['failed']} rows=$nRows10");
    t('bulk_versions', count($b['config_versions'] ?? []) === 10 && min($b['config_versions']) >= 2,
        'min_v=' . min($b['config_versions'] ?: [0]));

    // ---- 3. MIXED: 6 existing + 4 new
    $mix = [9036, 9037, 9038, 9039, 9040, 9041, 9042, 9043, 9044, 9045];
    $m = ActivityManager::bulkSave($mix, ['enabled' => 1, 'planner_enabled' => 0]);
    t('bulk_mixed_6_4', $m['updated'] === 6 && $m['created'] === 4 && $m['failed'] === 0,
        "updated={$m['updated']} created={$m['created']}");

    // ---- 4. Single save recalc next_run ngay (legacy mode)
    ActivityManager::saveConfig(9050, ['enabled' => 1, 'planner_enabled' => 0,
        'interval_mode' => 'FIXED', 'interval_minutes' => 120, 'schedule_mode' => 'ALWAYS']);
    $nx1 = db()->query('SELECT next_run_at FROM activity_configs WHERE profile_id=9050')->fetchColumn();
    ActivityManager::saveConfig(9050, ['enabled' => 1, 'planner_enabled' => 0,
        'interval_mode' => 'FIXED', 'interval_minutes' => 30, 'schedule_mode' => 'ALWAYS']);
    $nx2 = db()->query('SELECT next_run_at FROM activity_configs WHERE profile_id=9050')->fetchColumn();
    t('save_recalc_next_run', !empty($nx1) && !empty($nx2) && strtotime((string)$nx2) < strtotime((string)$nx1),
        "120m->$nx1 30m->$nx2");

    // ---- 5. 24/24: inHours true moi gio; WINDOW 23:00 false + clamp sang hom sau
    $always = ['schedule_mode' => 'ALWAYS'];
    $okAll = true;
    foreach (['01:00', '06:00', '15:00', '23:30'] as $hm) {
        $ts = strtotime(date('Y-m-d') . ' ' . $hm);
        if (!ActivityScheduler::inHours($always, $ts)) $okAll = false;
    }
    t('always_24h', $okAll);
    $win = ['schedule_mode' => 'WINDOW', 'schedule_start' => '08:00', 'schedule_end' => '22:00',
        'active_days' => '1,2,3,4,5,6,7'];
    $late = strtotime(date('Y-m-d') . ' 23:00');
    t('window_off_hours', !ActivityScheduler::inHours($win, $late));
    $cl = ActivityScheduler::clampWindow($win, $late, $late);
    t('window_clamp_next_day', date('H:i', strtotime($cl)) === '08:00' && strtotime($cl) > $late, $cl);

    // ---- 6. Random interval bounds (200 samples) + calculateNextRun
    $inRange = true;
    for ($i = 0; $i < 200; $i++) {
        $v = ActivityManager::next_interval(45, 120);
        if ($v < 45 || $v > 120) {
            $inRange = false;
            break;
        }
    }
    t('random_interval_bounds', $inRange);
    $cfgR = ['interval_mode' => 'RANDOM_RANGE', 'random_min' => 45, 'random_max' => 120,
        'schedule_mode' => 'ALWAYS', 'schedule_start' => '00:00', 'schedule_end' => '23:59',
        'active_days' => '1,2,3,4,5,6,7'];
    $nr = ActivityScheduler::calculateNextRun($cfgR);
    $dMin = (strtotime($nr) - time()) / 60;
    t('random_next_run', $dMin >= 44 && $dMin <= 121, $nr . " (+${dMin}m)");

    // ---- 7. pickCustomUrls: source + cooldown + disabled behavior + ORDER rotation
    $cfgP = ['profile_id' => 9031, 'tab_source_mode' => 'BOTH', 'tab_selection_mode' => 'RANDOM',
        'url_cooldown_minutes' => 60, 'required_pages' => ['gmail'],
        'custom_tabs' => [
            ['label' => 'a', 'url' => 'https://a.example.com/', 'domain' => 'a.example.com', 'behavior' => 'BOTH'],
            ['label' => 'b', 'url' => 'https://b.example.com/', 'domain' => 'b.example.com', 'behavior' => 'BOTH'],
            ['label' => 'm', 'url' => 'https://m.example.com/', 'domain' => 'm.example.com', 'behavior' => 'MAINTAIN'],
        ]];
    $picks = ActivityPlanner::pickCustomUrls($cfgP, [], 5);
    $urls = array_column($picks, 'url');
    $hasM = false;
    foreach ($urls as $u) {
        if (str_contains((string)$u, 'm.example.com')) $hasM = true;
    }
    t('selector_pool_only', count($picks) === 3 && !$hasM, implode(',', array_map(fn($u) => parse_url($u, PHP_URL_HOST), $urls)));
    // Cooldown: a.example.com vua dung -> bi loai
    db()->prepare("INSERT INTO activity_history (profile_id, task_type, domain, result) VALUES (?,?,?,?)")
        ->execute([9031, 'OPEN_CUSTOM_TAB', 'a.example.com', 'OPENED']);
    $picks2 = ActivityPlanner::pickCustomUrls($cfgP, [], 5);
    $hosts2 = array_map(fn($u) => parse_url($u, PHP_URL_HOST), array_column($picks2, 'url'));
    t('selector_cooldown', !in_array('a.example.com', $hosts2, true), implode(',', $hosts2));
    // ORDER rotation
    $cfgO = $cfgP;
    $cfgO['tab_selection_mode'] = 'ORDER';
    $o1 = ActivityPlanner::pickCustomUrls($cfgO, [], 1);
    ActivityPlanner::bumpCustomIdx(9031, 1);
    $o2 = ActivityPlanner::pickCustomUrls($cfgO, [], 1);
    t('selector_order_rotation', ($o1[0]['url'] ?? '') !== ($o2[0]['url'] ?? ''), ($o1[0]['url'] ?? '?') . ' -> ' . ($o2[0]['url'] ?? '?'));

    // ---- 8. select_tabs: count bounds + dedupe + chi tu pool
    $cands = [['url' => 'https://x.example.com/', 'weight' => 3], ['url' => 'https://y.example.com/']];
    $okSel = true;
    for ($i = 0; $i < 50; $i++) {
        $s = ActivityManager::select_tabs($cands, 1, 2);
        if (count($s) < 1 || count($s) > 2) {
            $okSel = false;
            break;
        }
        foreach ($s as $one) {
            if (!in_array($one['url'], ['https://x.example.com/', 'https://y.example.com/'], true)) {
                $okSel = false;
                break 2;
            }
        }
    }
    t('select_tabs_bounds', $okSel);

    // ---- 9. Circuit breaker: 5 transient -> SUSPENDED; success reset; BLOCKED -> ERROR
    ActivityManager::saveConfig(9042, ['enabled' => 1, 'planner_enabled' => 0]);
    for ($i = 0; $i < 5; $i++) ActivityManager::noteResult(9042, false, 'NETWORK_ERROR');
    $cb = ActivityManager::getConfig(9042);
    t('circuit_suspend', ($cb['runtime_state'] ?? '') === 'SUSPENDED' && (int)($cb['fail_streak'] ?? 0) >= 5,
        ($cb['runtime_state'] ?? '?') . ' streak=' . ($cb['fail_streak'] ?? '?'));
    ActivityManager::noteResult(9042, true);
    $cb2 = ActivityManager::getConfig(9042);
    t('circuit_reset_on_success', (int)($cb2['fail_streak'] ?? -1) === 0);
    ActivityManager::noteResult(9042, false, 'BLOCKED');
    $cb3 = ActivityManager::getConfig(9042);
    t('blocked_no_retry', ($cb3['runtime_state'] ?? '') === 'ERROR', ($cb3['runtime_state'] ?? '?'));
    $st = ActivityScheduler::status();
    t('status_suspended_count', ($st['suspended'] ?? 0) >= 1, json_encode($st));

    // ---- 10. Restart persist: config + version + custom list van con
    ActivityManager::saveConfig(9043, ['enabled' => 1, 'schedule_mode' => 'ALWAYS',
        'planner_enabled' => 0,
        'custom_tabs' => [['label' => 'k', 'url' => 'https://k.example.com/', 'domain' => 'k.example.com', 'behavior' => 'BOTH']]]);
    $re = ActivityManager::getConfig(9043);
    t('restart_persist', !empty($re['enabled']) && ($re['schedule_mode'] ?? '') === 'ALWAYS'
        && count($re['custom_tabs'] ?? []) === 1 && (int)($re['config_version'] ?? 0) >= 1,
        'v' . ($re['config_version'] ?? '?'));
} finally {
    cleanup_v3($ids);
}

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
