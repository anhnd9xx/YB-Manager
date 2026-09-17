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

// Version dau: fixed OFF + auto -> giu hanh vi cu (khong cham window)
if (!$snap['fixed'] && $snap['position'] === 'auto') {
    aw_log('[Window] Fixed OFF + Auto: khong ep kich thuoc/vi tri', $profileId);
    exit(0);
}

// ---- Wait for HWND: poll 100ms, timeout 10s (khong sleep mu 2s) ----
aw_log('[Window] Waiting for HWND', $profileId);
$win = null;
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
        if ($w->profileId === $profileId && $w->class === 'Chrome_WidgetWin_1' && $w->visible
            && $w->rect !== null && $w->rect['w'] > 0 && $w->rect['h'] > 0
            && ($win === null || $w->area() > $win->area())) {
            $win = $w;
        }
    }
    if ($win !== null && !$win->minimized) break;
    $win = ($win !== null && !$win->minimized) ? $win : null;
    usleep(100000);
}
if ($win === null) {
    $msg = "[Window] HWND khong xuat hien sau 10s (profile #$profileId)";
    SyncLogger::warn('window_apply', $msg, $profileId);
    echo date('H:i:s') . " $msg\n";
    exit(1);
}
aw_log('[Window] HWND found: ' . $win->hwnd, $profileId);

// ---- Resolve monitor (fallback Primary neu monitor chon bi thao) ----
$monId = $snap['monitor'] === 'primary' ? null : (int)$snap['monitor'];
$wa = SyncLayoutManager::workArea($monId);
if ($wa === null) {
    $wa = SyncLayoutManager::workArea(null);
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

// ---- Tinh rect dich ----
$W = $snap['fixed'] ? $snap['width'] : (int)($win->rect['w'] ?? $snap['width']);
$H = $snap['fixed'] ? $snap['height'] : (int)($win->rect['h'] ?? $snap['height']);
$W = min($W, $wa['w']);
$H = min($H, $wa['h']);
$gap = $snap['gap'];
$x = (int)($win->rect['x'] ?? $wa['x']);
$y = (int)($win->rect['y'] ?? $wa['y']);

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
            if ($w->hwnd === $win->hwnd) {
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
// Kep trong working area: khong bao gio mo ngoai vung nhin thay
$x = max($wa['x'], min($x, $wa['x'] + $wa['w'] - $W));
$y = max($wa['y'], min($y, $wa['y'] + $wa['h'] - $H));

aw_log("[Window] Applying global window settings: {$W}x{$H} @ ($x,$y) mode={$snap['position']}", $profileId);
$r = SyncWindowManager::moveResize($win->hwnd, $x, $y, $W, $H);
if ($r['ok']) {
    $rc = $r['rect'] ?? null;
    $got = is_array($rc) ? " -> thuc te {$rc['w']}x{$rc['h']} @ ({$rc['x']},{$rc['y']})" : '';
    aw_log('[Window] Window configuration applied' . $got, $profileId);
    exit(0);
}
SyncLogger::warn('window_apply', '[Window] Apply that bai: ' . ($r['error'] ?? 'unknown'), $profileId);
exit(2);
