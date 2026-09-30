<?php
declare(strict_types=1);
// Window Layout Engine V2 tests — PURE (khong can Chrome/monitor that).
// Chay: php tests/backend/test_window_layout_engine.php
require __DIR__ . '/../../sync/SmartLayoutEngine.php';
require __DIR__ . '/../../sync/MultiMonitorLayoutEngine.php';
require __DIR__ . '/../../sync/WindowLayoutManager.php';

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

function baseLayout(array $over = []): array {
    return array_merge([
        'mode' => 'smart_auto', 'sizeMode' => 'auto_fit', 'monitor' => 'all',
        'multi' => true, 'gapX' => 5, 'gapY' => 5, 'minW' => 500, 'minH' => 400,
        'respectTaskbar' => true, 'fallback' => 'auto', 'compactX' => 150,
        'compactY' => 40, 'distribution' => 'smart', 'sizeBalance' => 'similar',
        'forceCols' => 0,
    ], $over);
}
function baseWin(): array {
    return ['width' => 1280, 'height' => 720];
}
function area(int $id, int $x, int $y, int $w, int $h, string $name = ''): array {
    return ['monitorId' => $id, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
        'name' => $name !== '' ? $name : ('MON' . $id)];
}
function withinArea(array $s, array $a): bool {
    return $s['x'] >= $a['x'] && $s['y'] >= $a['y']
        && $s['x'] + $s['w'] <= $a['x'] + $a['w'] + 1
        && $s['y'] + $s['h'] <= $a['y'] + $a['h'] + 1
        && $s['w'] > 0 && $s['h'] > 0;
}
function noOverlap(array $slots): bool {
    for ($i = 0; $i < count($slots); $i++) {
        for ($j = $i + 1; $j < count($slots); $j++) {
            $a = $slots[$i];
            $b = $slots[$j];
            if (max($a['x'], $b['x']) < min($a['x'] + $a['w'], $b['x'] + $b['w'])
                && max($a['y'], $b['y']) < min($a['y'] + $a['h'], $b['y'] + $b['h'])) {
                return false;
            }
        }
    }
    return true;
}

// Nguong thuc te khi user chon Co nho + Day (drawer): grid vua nhieu window hon.
function realisticLayout(array $over = []): array {
    return baseLayout(array_merge(['minW' => 400, 'minH' => 300, 'gapX' => 6, 'gapY' => 6], $over));
}

$mon1 = [area(1, 0, 0, 1920, 1040, 'M1')];

// ---- 10 windows 1 monitor: smart grid, trong bounds, khong overlap
$p = SmartLayoutEngine::plan(10, $mon1, realisticLayout(), baseWin());
t('10win_ok', $p['ok'] && count($p['slots']) === 10, "cols={$p['cols']} rows={$p['rows']} cell={$p['cellW']}x{$p['cellH']}");
$allIn = true;
foreach ($p['slots'] as $s) $allIn = $allIn && withinArea($s, $mon1[0]);
t('10win_bounds', $allIn);
t('10win_no_overlap', noOverlap($p['slots']));

// ---- Minimum mac dinh bao thu (500x400): 10 windows khong grid vua ->
// fallback compact (chu dich overlap) thay vi rect vo dung. Ghi nhan hanh vi.
$p = SmartLayoutEngine::plan(10, $mon1, baseLayout(), baseWin());
t('10win_strict_min_fallback', $p['ok'] && ($p['fallbackUsed'] ?? '') === 'compact',
    'fb=' . ($p['fallbackUsed'] ?? '-'));

// ---- 20 / 32 / 50 windows (nguong thuc te)
foreach ([20, 32, 50] as $n) {
    $p = SmartLayoutEngine::plan($n, $mon1, realisticLayout(), baseWin());
    $in = true;
    foreach ($p['slots'] as $s) $in = $in && withinArea($s, $mon1[0]);
    t("{$n}win_ok", $p['ok'] && count($p['slots']) === $n,
        "cols={$p['cols']} rows={$p['rows']} cell={$p['cellW']}x{$p['cellH']} fb=" . ($p['fallbackUsed'] ?? '-'));
    t("{$n}win_bounds_positive", $in);
}

// ---- Negative coordinates: monitor trai primary (x<0) giu nguyen, khong clamp ve 0
$neg = [area(2, -1920, 0, 1920, 1040, 'LEFT')];
$p = SmartLayoutEngine::plan(8, $neg, baseLayout(), baseWin());
$in = true;
$hasNeg = false;
foreach ($p['slots'] as $s) {
    $in = $in && withinArea($s, $neg[0]);
    if ($s['x'] < 0) $hasNeg = true;
}
t('neg_coords_ok', $p['ok'] && count($p['slots']) === 8 && $in && $hasNeg,
    'slots_in_negative_area, no_clamp_to_primary');

// ---- Multi-monitor: 20 windows 2 monitors (profile affinity style per-area smart)
$two = [area(1, 0, 0, 1920, 1040, 'M1'), area(2, 1920, 0, 1920, 1040, 'M2')];
$p = SmartLayoutEngine::plan(20, $two, realisticLayout(), baseWin());
$c1 = 0;
$c2 = 0;
foreach ($p['slots'] as $s) {
    if ((int)$s['monitorId'] === 1) $c1++;
    elseif ((int)$s['monitorId'] === 2) $c2++;
}
t('20win_2mon_distributed', $p['ok'] && $c1 + $c2 === 20 && $c1 > 0 && $c2 > 0, "M1=$c1 M2=$c2");

// ---- MultiMonitor engine: equal 20/2, manual counts, capacity
$p = MultiMonitorLayoutEngine::plan(20, $two, baseLayout(['distribution' => 'equal']), baseWin());
$bc = [];
foreach (($p['breakdown'] ?? []) as $b) $bc[] = $b['count'];
t('equal_dist', $p['ok'] && array_sum($bc) === 20 && $bc === [10, 10], implode(',', $bc));
$p = MultiMonitorLayoutEngine::plan(10, $two, baseLayout(), baseWin(), [6, 4]);
$bc = [];
foreach (($p['breakdown'] ?? []) as $b) $bc[] = $b['count'];
t('manual_counts', $p['ok'] && $bc === [6, 4], implode(',', $bc));
$p = MultiMonitorLayoutEngine::plan(10, array_merge($two, [area(3, 0, 1040, 1920, 1040, 'M3')]),
    baseLayout(), baseWin(), [6, 4, 0]);
t('manual_counts_with_zero', $p['ok'] && count($p['slots']) === 10);
t('capacity', MultiMonitorLayoutEngine::capacity($two[0], 5, 5, 500, 400) === 3 * 2, (string)MultiMonitorLayoutEngine::capacity($two[0], 5, 5, 500, 400));

// ---- slotsPerMonitor ("8 o/man"): 24/3 -> 8+8+8; 32/3 -> overflow con tong 32
t('spm_24_3x8', SyncWindowLayoutManager::slotsPerMonitorCounts(24, 3, 8) === [8, 8, 8],
    implode(',', SyncWindowLayoutManager::slotsPerMonitorCounts(24, 3, 8) ?? []));
$c = SyncWindowLayoutManager::slotsPerMonitorCounts(32, 3, 8);
t('spm_32_overflow', $c !== null && array_sum($c) === 32 && min($c) >= 8, implode(',', $c ?? []));
$c = SyncWindowLayoutManager::slotsPerMonitorCounts(10, 3, 8);
t('spm_10_partial', $c !== null && array_sum($c) === 10 && $c === [8, 2, 0], implode(',', $c ?? []));
t('spm_invalid', SyncWindowLayoutManager::slotsPerMonitorCounts(0, 3, 8) === null);

// ---- Manual modes: grid forceCols, horizontal, vertical, cascade, compact
$p = SmartLayoutEngine::plan(12, $mon1, baseLayout(['mode' => 'grid', 'forceCols' => 4]), baseWin());
t('grid_forcecols', $p['ok'] && $p['cols'] === 4 && count($p['slots']) === 12);
$p = SmartLayoutEngine::plan(6, $mon1, baseLayout(['mode' => 'horizontal']), baseWin());
t('horizontal_ok', $p['ok'] && $p['rows'] === 1 && count($p['slots']) === 6);
$p = SmartLayoutEngine::plan(6, $mon1, baseLayout(['mode' => 'vertical']), baseWin());
t('vertical_ok', $p['ok'] && $p['cols'] === 1 && count($p['slots']) === 6);
foreach (['cascade', 'compact'] as $m) {
    $p = SmartLayoutEngine::plan(10, $mon1, baseLayout(['mode' => $m]), baseWin());
    $in = true;
    foreach ($p['slots'] as $s) $in = $in && withinArea($s, $mon1[0]);
    t("{$m}_wrapped_in_bounds", $p['ok'] && $in);
}

// ---- keep_size mode
$p = SmartLayoutEngine::plan(4, $mon1, baseLayout(['sizeMode' => 'keep_size']), baseWin());
t('keep_size', $p['ok'] && count($p['slots']) === 4);

// ---- planSummary: noOverlap true grid, perMonitor counts
$p = SmartLayoutEngine::plan(10, $mon1, realisticLayout(), baseWin());
$sum = SyncWindowLayoutManager::planSummary($p['slots'], realisticLayout());
t('summary_no_overlap', ($sum['noOverlap'] ?? false) === true);
t('summary_permon', ($sum['perMonitor'][1] ?? 0) === 10);

// ---- buildLayoutPlan envelope: immutable fields
$live = [
    ['profileId' => 1, 'profileName' => 'A', 'hwnd' => 101, 'pid' => 1001],
    ['profileId' => 2, 'profileName' => 'B', 'hwnd' => 102, 'pid' => 1002],
];
$lp = SyncWindowLayoutManager::buildLayoutPlan('lay_test', 7, $live, $p['slots'],
    [1 => ['device_name' => 'M1', 'name' => 'M1']], 'smart_auto');
t('plan_envelope', $lp['layout_id'] === 'lay_test' && $lp['generation'] === 7
    && count($lp['window_items']) === 2
    && $lp['window_items'][0]['profile_id'] === 1
    && $lp['window_items'][0]['target_state'] === 'normal'
    && $lp['window_items'][1]['z_order_index'] === 1);

// ---- generation monotonic + layout lock ownership
$g1 = WindowPlacementManager::nextLayoutGeneration();
$g2 = WindowPlacementManager::nextLayoutGeneration();
t('generation_monotonic', $g2 === $g1 + 1, "$g1->$g2");
$n = WindowPlacementManager::claimLayoutLock(
    [99111 => ['hwnd' => 1, 'rect' => ['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100], 'monitor' => 'M1']],
    'lay_test', $g2);
t('layout_lock_claim', $n === 1 && WindowPlacementManager::layoutLocked(99111));
@unlink(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_guard_99111.json');

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
