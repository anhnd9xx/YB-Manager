<?php
declare(strict_types=1);
/**
 * bin/apply_window.php - Ap dung Global Window Settings cho 1 profile sau Launch.
 * Chay DETACHED (fire-and-forget) tu launch_chrome, khong block web request.
 * Dung: php -f bin/apply_window.php -- <profileId> [snapshotFile]
 *   snapshotFile: duong dan file JSON cua SyncSettingsService::snapshot() ghi TAI THOI DIEM
 *   launch (snapshot file chong race khi user Save giua launch; truyen file thay vi argv
 *   de tranh vo quote/base64 tren command-line Windows). Thieu file -> doc DB hien tai.
 *
 * Flow: poll HWND 100ms/timeout 10s -> validate -> tinh rect theo position/monitor/gap
 *  (W/H la physical pixel cho SetWindowPos; work area Win32 cung physical -> nhat quan
 *  multi-DPI, khong gia dinh DPI dong nhat) -> moveResize -> log.
 * Exit: 0 ok/skip, 1 timeout HWND, 2 loi validate/monitor. Khong bao gio throw ra ngoai.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sync/SettingsService.php';
require_once __DIR__ . '/../sync/WindowManager.php';
require_once __DIR__ . '/../sync/LayoutManager.php';
require_once __DIR__ . '/../sync/WindowPlacementManager.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

function aw_log(string $msg, ?int $pid = null): void
{
    SyncLogger::info('window_apply', $msg, $pid);
    echo date('H:i:s') . ' ' . $msg . "\n";
}

$profileId = (int)($argv[1] ?? 0);
$snap = [];
// Snapshot file (launch ghi truoc khi spawn; doc + xoa ngay de khong rac temp)
if (!empty($argv[2]) && is_file((string)$argv[2])) {
    $raw = (string)@file_get_contents((string)$argv[2]);
    @unlink((string)$argv[2]);
    // Strip BOM UTF-8 (file ghi tu PowerShell/cmd co the kem BOM lam hong json_decode)
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $j = json_decode(trim($raw), true);
    if (is_array($j)) $snap = $j;
}
if (!$snap) {
    // Fallback: snapshot hien tai (khi chay tay khong kem snapshot)
    try {
        $snap = SyncSettingsService::snapshot();
    } catch (Throwable $e) {
        $snap = [];
    }
}
$snap = SyncSettingsService::normalize($snap);
if ($profileId <= 0) {
    aw_log('[Window] profileId khong hop le, bo qua');
    exit(2);
}
aw_log("[Window] Target size: {$snap['width']}x{$snap['height']} (profile #$profileId)", $profileId);

// Placement dich tu launch (uu tien so 1): Chrome da co --window-position truoc Popen,
// day chi la guard verify/correct neu Chrome tu restore sai monitor.
$placeRect = $snap['placement_rect'] ?? null;
$placeMonName = (string)($snap['placement_monitor'] ?? '');
if (is_array($placeRect) && isset($placeRect['x'], $placeRect['y'], $placeRect['w'], $placeRect['h'])) {
    $placeRect = ['x' => (int)$placeRect['x'], 'y' => (int)$placeRect['y'],
                  'w' => (int)$placeRect['w'], 'h' => (int)$placeRect['h']];
} else {
    $placeRect = null;
}
// Version dau: fixed OFF + auto + khong placement -> giu hanh vi cu (khong cham window)
if (!$snap['fixed'] && $snap['position'] === 'auto' && $placeRect === null) {
    aw_log('[Window] Fixed OFF + Auto: khong ep kich thuoc/vi tri', $profileId);
    WindowPlacementManager::clearGuard($profileId);
    exit(0);
}

// ---- Wait for HWND: finder gon + backoff (100ms x10, 250ms x12, 500ms x12 ~ 10s).
// Moi poll chi 1 EnumWindows + 1 CIM (khong lay monitor/DPI nhu discovery day du)
// nen re hon nhieu khi Start All mo nhieu Chrome song song.
aw_log('[Window] Waiting for HWND', $profileId);
$win = null;
try {
    $st = db()->prepare('SELECT user_data_dir FROM profiles WHERE id=?');
    $st->execute([$profileId]);
    $udir = (string)($st->fetchColumn() ?? '');
} catch (Throwable $e) {
    $udir = '';
}
if ($udir === '') {
    SyncLogger::warn('window_apply', '[Window] Khong tim thay profile', $profileId);
    exit(2);
}
$findScript = __DIR__ . '/../sync/win32_find_profile.ps1';
$waits = array_merge(array_fill(0, 10, 100000), array_fill(0, 12, 250000), array_fill(0, 12, 500000));
foreach ($waits as $waitUs) {
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $findScript . '"'
        . ' -UserDataDir "' . str_replace('"', '', $udir) . '"';
    $json = @shell_exec($cmd);
    $r = is_string($json) ? json_decode(trim($json), true) : null;
    if (is_array($r) && !empty($r['ok']) && (int)($r['hwnd'] ?? 0) > 0) {
        $win = ['hwnd' => (int)$r['hwnd'], 'pid' => (int)($r['pid'] ?? 0), 'rect' => null];
        break;
    }
    usleep($waitUs);
}
if ($win === null) {
    $msg = "[Window] HWND khong xuat hien sau 10s (profile #$profileId)";
    SyncLogger::warn('window_apply', $msg, $profileId);
    echo date('H:i:s') . " $msg\n";
    WindowPlacementManager::clearGuard($profileId);
    exit(1);
}
aw_log('[Window] HWND found: ' . $win['hwnd'], $profileId);
WindowPlacementManager::updateGuardHwnd($profileId, (int)$win['hwnd']);

// Rect hien tai: 1 lan discovery duy nhat (khong poll) de tinh vi tri giu/clamp
$curRect = null;
try {
    $found = SyncWindowManager::findWindow((int)$win['hwnd']);
    if ($found !== null) $curRect = $found->rect;
} catch (Throwable $e) {
}

// ---- Resolve monitor: placement dich uu tien; fallback Primary neu bi thao ----
$placeMon = $placeMonName !== '' ? WindowPlacementManager::findByDevice($placeMonName) : null;
if ($placeMonName !== '' && $placeMon === null) {
    SyncLogger::warn('window_apply', "[Window] Placement monitor $placeMonName mat -> fallback", $profileId);
    WindowPlacementManager::refreshMonitors();
    $placeMon = $placeMonName !== '' ? WindowPlacementManager::findByDevice($placeMonName) : null;
}
$monId = $snap['monitor'] === 'primary' ? null : (int)$snap['monitor'];
$wa = null;
if ($placeMon !== null) {
    $wa = ['x' => $placeMon['work_left'], 'y' => $placeMon['work_top'],
            'w' => $placeMon['work_width'], 'h' => $placeMon['work_height'],
            'monitorId' => $placeMon['id'], 'monitorName' => $placeMon['device_name']];
} else {
    $wa = SyncLayoutManager::workArea($monId);
    if ($wa === null) {
        $wa = SyncLayoutManager::workArea(null);
    }
}
if ($wa === null) {
    $msg = '[Window] Khong lay duoc working area monitor, bo qua';
    SyncLogger::warn('window_apply', $msg, $profileId);
    echo date('H:i:s') . " $msg\n";
    exit(2);
}
if ($monId !== null && (int)$wa['monitorId'] !== $monId) {
    SyncLogger::warn('window_apply', "[Window] Monitor $monId khong ton tai -> fallback Primary", $profileId);
}
aw_log("[Window] Monitor: {$wa['monitorName']} (id {$wa['monitorId']})", $profileId);

// ---- Tinh rect dich (placement uu tien tuyet doi) ----
if ($placeRect !== null) {
    // Giu am hop le, chi kep trong work area CHINH monitor dich
    $W = max(200, min($placeRect['w'], $wa['w']));
    $H = max(150, min($placeRect['h'], $wa['h']));
    $x = max($wa['x'], min($placeRect['x'], $wa['x'] + $wa['w'] - $W));
    $y = max($wa['y'], min($placeRect['y'], $wa['y'] + $wa['h'] - $H));
    $snap['position'] = '__placement__';
} else {
$W = $snap['fixed'] ? $snap['width'] : (int)($curRect['w'] ?? $snap['width']);
$H = $snap['fixed'] ? $snap['height'] : (int)($curRect['h'] ?? $snap['height']);
$W = min($W, $wa['w']);
$H = min($H, $wa['h']);
$gap = $snap['gap'];
$x = (int)($curRect['x'] ?? $wa['x']);
$y = (int)($curRect['y'] ?? $wa['y']);

switch ($snap['position']) {
    case 'custom':
        $x = $snap['x'];
        $y = $snap['y'];
        break;
    case 'cascade': {
        $step = 30;
        $k = ($profileId - 1) % 15;
        $x = $wa['x'] + $gap + $k * $step;
        $y = $wa['y'] + $gap + $k * $step;
        break;
    }
    case 'grid': {
        // O grid cua window moi dua tren so window dang chay (khong doi window khac)
        $all = SyncWindowManager::findBrowserWindows(true);
        usort($all, fn($a, $b) => ($a->profileId ?? 999999) <=> ($b->profileId ?? 999999));
        $n = max(1, count($all));
        $idx = 0;
        foreach ($all as $i => $w) {
            if ($w->hwnd === (int)$win['hwnd']) {
                $idx = $i;
                break;
            }
        }
        $g = SyncLayoutManager::computeGrid($n, $wa, $gap, $gap, $gap);
        if (!empty($g['rects'][$idx])) {
            $x = $g['rects'][$idx]['x'];
            $y = $g['rects'][$idx]['y'];
            // Grid dinh ca kich thuoc o (giong Tile); document trong UI/log
            $W = min($g['rects'][$idx]['w'], $wa['w']);
            $H = min($g['rects'][$idx]['h'], $wa['h']);
        }
        break;
    }
    default: // auto: giu vi tri hien tai, kep trong man hinh
        break;
}
// Kep trong working area CHINH monitor dich (giu am: wa.x co the -1920)
$x = max($wa['x'], min($x, $wa['x'] + $wa['w'] - $W));
$y = max($wa['y'], min($y, $wa['y'] + $wa['h'] - $H));
} // end non-placement branch

aw_log("[Window] Applying global window settings: {$W}x{$H} @ ($x,$y) mode={$snap['position']}", $profileId);
try {
    $profRow = WindowPlacementManager::profileRow($profileId);
} catch (Throwable $e) {
    $profRow = ['id' => $profileId];
}
$r = SyncWindowManager::moveResize((int)$win['hwnd'], $x, $y, $W, $H);
if ($r['ok']) {
    $rc = $r['rect'] ?? null;
    $got = is_array($rc) ? " -> thuc te {$rc['w']}x{$rc['h']} @ ({$rc['x']},{$rc['y']})" : '';
    aw_log('[Window] Window configuration applied' . $got, $profileId);
    // Maximized (§54): restore state da luu sau khi dat normal rect
    $wantMax = false;
    try {
        $wantMax = WindowPlacementManager::resolve_startup_state($profRow ?? ['id' => $profileId]) === 'maximized';
    } catch (Throwable $e) {
    }
    if ($wantMax) {
        $mr = SyncWindowManager::maximizeWindow((int)$win['hwnd']);
        aw_log('[Window] Restored maximized state: ' . (!empty($mr['ok']) ? 'ok' : 'fail'), $profileId);
    }
    // Placement guard chung: verify 100/350/800/1500ms trong ~1600ms (Chrome hay tu restore ve primary)
    $tMon = $placeMon ?? WindowPlacementManager::monitorForRect($x, $y, $W, $H) ?? WindowPlacementManager::primary();
    $tRect = ['x' => $x, 'y' => $y, 'w' => $W, 'h' => $H];
    if ($wantMax && $tMon !== null) {
        // Maximized: target la work area monitor dich (khong phai normal rect)
        $tRect = ['x' => (int)$tMon['work_left'], 'y' => (int)$tMon['work_top'],
                  'w' => (int)$tMon['work_width'], 'h' => (int)$tMon['work_height']];
    }
    SyncLogger::info('placement', '[HWND] profile=#' . $profileId . ' hwnd=' . (int)$win['hwnd'], $profileId);
    $checks = [100, 350, 800, 1500];
    $t0 = microtime(true);
    $prev = 0;
    foreach ($checks as $ms) {
        usleep(max(0, ($ms - $prev) * 1000));
        $prev = $ms;
        $v = WindowPlacementManager::verify_window_placement($profRow ?? ['id' => $profileId], (int)$win['hwnd'], $tRect, $tMon ?? []);
        if (!$v['ok']) {
            if ($wantMax) {
                SyncWindowManager::maximizeWindow((int)$win['hwnd']);
            } else {
                WindowPlacementManager::correct_window_placement($profRow ?? ['id' => $profileId], (int)$win['hwnd'], $tRect, $tMon ?? []);
            }
            SyncLogger::info('placement', '[PLACEMENT CORRECT] profile=#' . $profileId
                . ' actual=' . $v['actual'] . ' target=' . ($tMon['device_name'] ?? ''), $profileId);
        } else {
            SyncLogger::debug('placement', '[PLACEMENT VERIFY] profile=#' . $profileId
                . ' expected=' . $v['expected'] . ' actual=' . $v['actual'] . ' result=OK', $profileId);
        }
        if ((microtime(true) - $t0) * 1000 >= WindowPlacementManager::GUARD_DURATION_MS) break;
    }
    SyncLogger::info('placement', '[PLACEMENT STABLE] profile=#' . $profileId, $profileId);
    WindowPlacementManager::clearGuard($profileId); // mo khoa cho AutoArrange (§12)
    exit(0);
}
SyncLogger::warn('window_apply', '[Window] Apply that bai: ' . ($r['error'] ?? 'unknown'), $profileId);
WindowPlacementManager::clearGuard($profileId);
exit(2);
