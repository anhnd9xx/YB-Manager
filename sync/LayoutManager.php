<?php
declare(strict_types=1);
/**
 * SyncLayoutManager - Xep layout window cho module Synchronize (PHASE 2).
 * Grid (tile) / Uniform Size / Overlapped (cascade). Tinh tren WORKING AREA
 * cua monitor (tru taskbar), ton trong margin/gap, kep window trong man hinh.
 * Ap dung qua SyncWindowManager (moveresize) — loi 1 window khong lan (ghi nhan tung cai).
 */
require_once __DIR__ . '/WindowManager.php';
require_once __DIR__ . '/DpiManager.php';

class SyncLayoutManager
{
    /**
     * Tinh luoi grid cho $n cua so tren working area.
     * Tra ve ['rows'=>, 'cols'=>, 'cellW'=>, 'cellH'=>, 'rects'=>[[x,y,w,h]...]].
     */
    public static function computeGrid(int $n, array $wa, int $margin = 8, int $gapX = 8, int $gapY = 8): array
    {
        if ($n <= 0) return ['rows' => 0, 'cols' => 0, 'cellW' => 0, 'cellH' => 0, 'rects' => []];
        $W = max(100, (int)$wa['w']);
        $H = max(100, (int)$wa['h']);
        // So cot theo ty le khung hinh de o gan vuong nhat
        $cols = (int)max(1, round(sqrt($n * $W / $H)));
        $rows = (int)ceil($n / $cols);
        // Neu thua hang trong, giam cot cho gon
        while ($cols > 1 && ($rows - 1) * $cols >= $n) $cols--;
        $rows = (int)ceil($n / $cols);
        $cellW = (int)floor(($W - 2 * $margin - ($cols - 1) * $gapX) / $cols);
        $cellH = (int)floor(($H - 2 * $margin - ($rows - 1) * $gapY) / $rows);
        $cellW = max(200, $cellW);
        $cellH = max(150, $cellH);
        $rects = [];
        for ($i = 0; $i < $n; $i++) {
            $r = (int)floor($i / $cols);
            $c = $i % $cols;
            $rects[] = [
                'x' => (int)$wa['x'] + $margin + $c * ($cellW + $gapX),
                'y' => (int)$wa['y'] + $margin + $r * ($cellH + $gapY),
                'w' => $cellW, 'h' => $cellH,
            ];
        }
        return ['rows' => $rows, 'cols' => $cols, 'cellW' => $cellW, 'cellH' => $cellH, 'rects' => $rects];
    }

    /** Lay working area cua monitor (mac dinh monitor primary). */
    public static function workArea(?int $monitorId = null): ?array
    {
        $mons = SyncWindowDiscovery::monitors();
        if (!$mons) return null;
        $pick = null;
        if ($monitorId !== null) {
            foreach ($mons as $m) if ((int)$m['id'] === $monitorId) { $pick = $m; break; }
        }
        if ($pick === null) {
            foreach ($mons as $m) if (!empty($m['primary'])) { $pick = $m; break; }
            if ($pick === null) $pick = $mons[0];
        }
        $wa = $pick['workArea'] ?? null;
        if (!is_array($wa)) return null;
        return ['x' => (int)$wa['x'], 'y' => (int)$wa['y'], 'w' => (int)$wa['w'], 'h' => (int)$wa['h'],
                'monitorId' => (int)$pick['id'], 'monitorName' => (string)($pick['name'] ?? '')];
    }

    /**
     * Loc danh sach window muc tieu: uu tien hwnds chi dinh, roi profileIds, mac dinh
     * tat ca window trinh duyet cua profile duoc quan ly (sap xep theo profileId on dinh).
     * @return SyncWindowInfo[]
     */
    public static function targets(array $hwnds = [], array $profileIds = []): array
    {
        $all = SyncWindowManager::findBrowserWindows(true);
        if ($hwnds) {
            $set = array_flip(array_map('intval', $hwnds));
            $all = array_values(array_filter($all, fn($w) => isset($set[$w->hwnd])));
        } elseif ($profileIds) {
            $set = array_flip(array_map('intval', $profileIds));
            $all = array_values(array_filter($all, fn($w) => $w->profileId !== null && isset($set[$w->profileId])));
        }
        usort($all, fn($a, $b) => ($a->profileId ?? 999999) <=> ($b->profileId ?? 999999) ?: $a->hwnd <=> $b->hwnd);
        return $all;
    }

    /**
     * Xep grid (tile): chia deu working area, dua moi window vao 1 o.
     * Tra ve ['ok','monitor'=>, 'rows','cols','results'=>[{hwnd,profileId,ok,rect?,error?}]].
     */
    public static function tile(array $hwnds = [], array $profileIds = [], ?int $monitorId = null, int $margin = 8, int $gapX = 8, int $gapY = 8): array
    {
        $wins = self::targets($hwnds, $profileIds);
        if (!$wins) return ['ok' => false, 'message' => 'Khong co window nao de xep'];
        $wa = self::workArea($monitorId);
        if ($wa === null) return ['ok' => false, 'message' => 'Khong lay duoc working area monitor'];
        $g = self::computeGrid(count($wins), $wa, $margin, $gapX, $gapY);
        $results = [];
        foreach ($wins as $i => $w) {
            $rc = $g['rects'][$i];
            $r = SyncWindowManager::moveResize($w->hwnd, $rc['x'], $rc['y'], $rc['w'], $rc['h']);
            $results[] = ['hwnd' => $w->hwnd, 'profileId' => $w->profileId, 'profileName' => $w->profileName,
                          'ok' => $r['ok'], 'rect' => $r['rect'] ?? null, 'error' => $r['error'] ?? null];
            if (!$r['ok']) SyncLogger::warn('layout_tile', "Tile hwnd={$w->hwnd} that bai: " . ($r['error'] ?? ''), $w->profileId);
        }
        SyncLogger::info('layout_tile', count($wins) . ' window -> grid ' . $g['rows'] . 'x' . $g['cols'] . ' monitor ' . $wa['monitorId']);
        return ['ok' => true, 'monitor' => $wa, 'rows' => $g['rows'], 'cols' => $g['cols'], 'results' => $results];
    }

    /**
     * Uniform Size: dua moi window ve cung kich thuoc WxH (giu vi tri, kep trong work area
     * cua monitor chua window do; ton trong DPI: W,H hieu la physical pixel).
     */
    public static function uniformSize(int $w, int $h, array $hwnds = [], array $profileIds = []): array
    {
        if ($w < 200 || $h < 150) return ['ok' => false, 'message' => 'Kich thuoc toi thieu 200x150'];
        if ($w > 7680 || $h > 4320) return ['ok' => false, 'message' => 'Kich thuoc qua lon'];
        $wins = self::targets($hwnds, $profileIds);
        if (!$wins) return ['ok' => false, 'message' => 'Khong co window nao de resize'];
        $results = [];
        foreach ($wins as $wd) {
            // Kep vi tri hien tai vao work area monitor cua window (khong day ra ngoai man hinh)
            $x = $wd->rect['x'] ?? 0;
            $y = $wd->rect['y'] ?? 0;
            $wa = self::workArea($wd->monitorId);
            if ($wa !== null) {
                $x = max($wa['x'], min($x, $wa['x'] + $wa['w'] - min($w, $wa['w'])));
                $y = max($wa['y'], min($y, $wa['y'] + $wa['h'] - min($h, $wa['h'])));
            }
            $r = SyncWindowManager::moveResize($wd->hwnd, $x, $y, $w, $h);
            $results[] = ['hwnd' => $wd->hwnd, 'profileId' => $wd->profileId, 'profileName' => $wd->profileName,
                          'ok' => $r['ok'], 'rect' => $r['rect'] ?? null, 'error' => $r['error'] ?? null];
            if (!$r['ok']) SyncLogger::warn('layout_uniform', "Uniform hwnd={$wd->hwnd} that bai", $wd->profileId);
        }
        SyncLogger::info('layout_uniform', count($wins) . " window -> {$w}x{$h}");
        return ['ok' => true, 'results' => $results];
    }

    /**
     * Overlapped (cascade): xep chong cac window tu 1 goc work area, lech nhau $offset px,
     * cung kich thuoc (mac dinh lay kich thuoc window dau tien, toi thieu 800x600).
     */
    public static function overlap(array $hwnds = [], array $profileIds = [], ?int $monitorId = null, int $offset = 28, int $w = 0, int $h = 0): array
    {
        $wins = self::targets($hwnds, $profileIds);
        if (!$wins) return ['ok' => false, 'message' => 'Khong co window nao de xep'];
        $wa = self::workArea($monitorId);
        if ($wa === null) return ['ok' => false, 'message' => 'Khong lay duoc working area monitor'];
        if ($w <= 0 || $h <= 0) {
            $r0 = $wins[0]->rect;
            $w = max(800, (int)($r0['w'] ?? 800));
            $h = max(600, (int)($r0['h'] ?? 600));
        }
        $w = min($w, $wa['w']);
        $h = min($h, $wa['h']);
        $offset = max(0, min(200, $offset));
        $results = [];
        foreach ($wins as $i => $wd) {
            // Kep trong work area ke ca khi cascade nhieu tang
            $maxDx = max(0, $wa['w'] - $w);
            $maxDy = max(0, $wa['h'] - $h);
            $dx = $offset > 0 ? (($i * $offset) % (max(1, $maxDx + 1))) : 0;
            $dy = $offset > 0 ? (($i * $offset) % (max(1, $maxDy + 1))) : 0;
            $r = SyncWindowManager::moveResize($wd->hwnd, $wa['x'] + $dx, $wa['y'] + $dy, $w, $h);
            $results[] = ['hwnd' => $wd->hwnd, 'profileId' => $wd->profileId, 'profileName' => $wd->profileName,
                          'ok' => $r['ok'], 'rect' => $r['rect'] ?? null, 'error' => $r['error'] ?? null];
            if (!$r['ok']) SyncLogger::warn('layout_overlap', "Overlap hwnd={$wd->hwnd} that bai", $wd->profileId);
        }
        SyncLogger::info('layout_overlap', count($wins) . " window cascade offset=$offset monitor " . $wa['monitorId']);
        return ['ok' => true, 'monitor' => $wa, 'results' => $results];
    }
}
