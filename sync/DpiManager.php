<?php
declare(strict_types=1);
/**
 * SyncDpiManager - Quan ly DPI cho module Synchronize (PHASE 2, spec muc 8).
 * Windows ho tro 100/125/150/175/200%; MAIN va CONTROLLED co the khac DPI,
 * khac monitor. Khong bao gio gia dinh DPI dong nhat.
 * Du lieu DPI lay tu discovery (GetDpiForWindow / GetDpiForMonitor), khong goi PS them.
 */
require_once __DIR__ . '/WindowDiscovery.php';

class SyncDpiManager
{
    /** DPI cua 1 window (theo HWND). null neu khong tim thay window. */
    public static function getWindowDpi(int $hwnd): ?int
    {
        $w = SyncWindowDiscovery::findWindow($hwnd);
        return $w === null ? null : $w->dpi;
    }

    /** Ty le scale cua 1 window (dpi/96). null neu khong tim thay. */
    public static function getWindowScale(int $hwnd): ?float
    {
        $w = SyncWindowDiscovery::findWindow($hwnd);
        return $w === null ? null : $w->dpiScale;
    }

    /** DPI that su cua 1 monitor (theo id tu discovery). null neu khong co. */
    public static function getMonitorDpi(int $monitorId): ?array
    {
        foreach (SyncWindowDiscovery::monitors() as $m) {
            if ((int)$m['id'] === $monitorId && isset($m['dpi'])) {
                return ['x' => (int)$m['dpi']['x'], 'y' => (int)$m['dpi']['y']];
            }
        }
        return null;
    }

    /** Diem logic (96-DPI) -> physical pixel theo DPI dich. */
    public static function scalePoint(int $x, int $y, int $dpi): array
    {
        $s = $dpi / 96;
        return ['x' => (int)round($x * $s), 'y' => (int)round($y * $s)];
    }

    /** Diem physical pixel -> logic (96-DPI) theo DPI nguon. */
    public static function unscalePoint(int $x, int $y, int $dpi): array
    {
        $s = $dpi / 96;
        if ($s <= 0) $s = 1.0;
        return ['x' => (int)round($x / $s), 'y' => (int)round($y / $s)];
    }

    /** Kich thuoc logic -> physical (lam tron len de khong vo layout). */
    public static function scaleSize(int $w, int $h, int $dpi): array
    {
        $s = $dpi / 96;
        return ['w' => (int)ceil($w * $s), 'h' => (int)ceil($h * $s)];
    }
}
