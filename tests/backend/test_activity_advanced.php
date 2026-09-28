<?php
declare(strict_types=1);
// Advanced scheduling tests: custom cycle, random range, window clamp,
// import dedupe, tab selector, cooldown, bulk persist.
// Chay: php tests/backend/test_activity_advanced.php
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
// Seeded random deterministic
$seq = [30, 90, 45, 60, 35, 80, 50, 55, 40, 70, 33, 88];
ActivityManager::$randFn = function ($a, $b) use (&$seq) {
    static $i = 0;
    $v = $seq[$i % count($seq)];
    $i++;
    return max($a, min($b, $v));
};
$pid = 9031;
try {
    db()->exec('DELETE FROM activity_sessions WHERE profile_id=' . $pid);
    db()->exec('DELETE FROM activity_configs WHERE profile_id=' . $pid);
    db()->exec('DELETE FROM activity_history WHERE profile_id=' . $pid);
    db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
        ->execute([$pid, 'TESTADV', 'C:\\tmp\\ytm-test-adv', 'stopped']);

    // TEST custom cycle 45 + restart persist (doc lai tu DB)
    ActivityManager::saveConfig($pid, ['enabled' => 0, 'interval_minutes' => 45]);
    $c = ActivityManager::getConfig($pid);
    t('custom cycle 45 persist', $c['interval_minutes'] === 45, $c['interval_minutes']);
    // 2 gio -> 120
    ActivityManager::saveConfig($pid, ['interval_minutes' => 120]);
    t('cycle 120 persist', ActivityManager::getConfig($pid)['interval_minutes'] === 120);

    // TEST random 100x trong [30,90]
    $bad = 0;
    for ($i = 0; $i < 100; $i++) {
        $v = ActivityManager::next_interval(30, 90);
        if ($v < 30 || $v > 90) $bad++;
    }
    t('random 100x bounds', $bad === 0);

    // TEST window clamp
    $cfg = ActivityManager::getConfig($pid);
    $cfg['schedule_start'] = '08:00';
    $cfg['schedule_end'] = '22:00';
    $base = strtotime(date('Y-m-d') . ' 21:00');
    $next = ActivityScheduler::clampWindow($cfg, strtotime(date('Y-m-d') . ' 22:31'), $base);
    t('window next day', date('H:i', strtotime($next)) === '08:00'
        && date('Y-m-d', strtotime($next)) !== date('Y-m-d', $base), $next);
    $cfgF = $cfg;
    $cfgF['interval_mode'] = 'FIXED';
    $cfgF['interval_minutes'] = 30;
    $cfgF['last_run_at'] = date('Y-m-d H:i:s', $base);
    t('fixed slot', ActivityScheduler::calculateNextRun($cfgF, $base) === date('Y-m-d H:i:s', $base + 1800));
    $cfgR = $cfg;
    $cfgR['interval_mode'] = 'RANDOM_RANGE';
    $cfgR['random_min'] = 30;
    $cfgR['random_max'] = 90;
    $nR = ActivityScheduler::calculateNextRun($cfgR, $base);
    $gap = (strtotime($nR) - $base) / 60;
    t('random next in range', $gap >= 30 && $gap <= 90, "$gap m");

    // TEST import dedupe + reject scheme
    $tabs = ActivityManager::cleanCustomTabs("https://example.com\nhttps://example.com/\nhttps://docs.example.com\njavascript:alert(1)\nfile:///x\n\nnotaurl");
    t('import dedupe', count($tabs) === 2, count($tabs));
    t('import domain', ($tabs[0]['domain'] ?? '') === 'example.com');

    // TEST select_tabs 1-2
    $cands = [['url' => 'https://a.com/', 'weight' => 1], ['url' => 'https://b.com/', 'weight' => 3],
        ['url' => 'https://c.com/', 'weight' => 1], ['url' => 'https://d.com/', 'weight' => 1]];
    $okN = true;
    for ($i = 0; $i < 30; $i++) {
        $p = ActivityManager::select_tabs($cands, 1, 2);
        if (count($p) < 1 || count($p) > 2) {
            $okN = false;
            break;
        }
    }
    t('select_tabs 1-2', $okN);

    // TEST cooldown: ghi history roi check
    ActivityManager::record($pid, 'OPEN_CUSTOM_TAB', 'cool.com', 'OPENED', 100);
    t('cooldown active', ActivityManager::domainCoolingDown($pid, 'cool.com', 60));
    t('cooldown other', !ActivityManager::domainCoolingDown($pid, 'other.com', 60));
    t('cooldown zero off', !ActivityManager::domainCoolingDown($pid, 'cool.com', 0));

    // TEST bulk persist day du fields moi
    ActivityManager::saveConfig($pid, ['enabled' => 1, 'interval_mode' => 'RANDOM_RANGE',
        'random_min' => 30, 'random_max' => 90, 'scheduled_tabs_enabled' => 1,
        'tab_source_mode' => 'CUSTOM', 'tab_selection_mode' => 'RANDOM',
        'tabs_min' => 1, 'tabs_max' => 2, 'max_automation_tabs' => 2,
        'url_cooldown_minutes' => 60,
        'custom_tabs' => [['url' => 'https://bulk1.com/', 'behavior' => 'BOTH']]]);
    $c2 = ActivityManager::getConfig($pid);
    t('bulk new fields', $c2['interval_mode'] === 'RANDOM_RANGE' && $c2['random_max'] === 90
        && $c2['scheduled_tabs_enabled'] === 1 && count($c2['custom_tabs']) === 1
        && ($c2['custom_tabs'][0]['behavior'] ?? '') === 'BOTH');
    // KEEP semantics: save khong gui custom_tabs -> giu nguyen
    ActivityManager::saveConfig($pid, ['enabled' => 1]);
    t('keep custom tabs', count(ActivityManager::getConfig($pid)['custom_tabs']) === 1);
    // ADD semantics
    ActivityManager::saveConfig($pid, ['custom_tabs' => [['url' => 'https://bulk2.com/']],
        'custom_list_mode' => 'add']);
    t('add merge', count(ActivityManager::getConfig($pid)['custom_tabs']) === 2);
    // REPLACE semantics
    ActivityManager::saveConfig($pid, ['custom_tabs' => [['url' => 'https://bulk3.com/']],
        'custom_list_mode' => 'replace']);
    $c3 = ActivityManager::getConfig($pid);
    t('replace', count($c3['custom_tabs']) === 1 && ($c3['custom_tabs'][0]['domain'] ?? '') === 'bulk3.com');

    // TEST restart: doc lai van du
    $c4 = ActivityManager::getConfig($pid);
    t('restart persist', $c4['interval_mode'] === 'RANDOM_RANGE' && $c4['max_automation_tabs'] === 2);
} finally {
    db()->exec('DELETE FROM activity_sessions WHERE profile_id=' . $pid);
    db()->exec('DELETE FROM activity_configs WHERE profile_id=' . $pid);
    db()->exec('DELETE FROM activity_history WHERE profile_id=' . $pid);
    db()->exec('DELETE FROM profiles WHERE id=' . $pid);
}
echo "ADVANCED: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
