<?php
declare(strict_types=1);
/**
 * SyncWindowManager - Dieu khien window theo HWND (PHASE 1).
 * findBrowserWindows / findWindow / getWindowRect / getClientRect /
 * moveWindow / resizeWindow / restoreWindow / minimizeWindow /
 * showWindow / hideWindow / bringToFront.
 * Moi lenh validate IsWindow trong script PS; loi 1 target khong lan sang target khac
 * (tra ve ['ok'=>false,'error'=>...] thay vi nem exception).
 */
require_once __DIR__ . '/WindowDiscovery.php';

class SyncWindowManager
{
    public static function findBrowserWindows(bool $managedOnly = true): array
    {
        return SyncWindowDiscovery::findBrowserWindows($managedOnly);
    }

    public static function findWindow(int $hwnd): ?SyncWindowInfo
    {
        return SyncWindowDiscovery::findWindow($hwnd);
    }

    /** @return array{x:int,y:int,w:int,h:int}|null */
    public static function getWindowRect(int $hwnd): ?array
    {
        $w = self::findWindow($hwnd);
        return $w?->rect;
    }

    /** @return array{x:int,y:int,w:int,h:int}|null (goc SCREEN) */
    public static function getClientRect(int $hwnd): ?array
    {
        $w = self::findWindow($hwnd);
        return $w?->clientRect;
    }

    private static function control(int $hwnd, string $action, int $x = 0, int $y = 0, int $w = 0, int $h = 0): array
    {
        if ($hwnd <= 0) return ['ok' => false, 'error' => 'HWND khong hop le'];
        try {
            $script = __DIR__ . '/win32_control.ps1';
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"'
                . ' -Hwnd ' . $hwnd . ' -Action ' . escapeshellarg($action)
                . " -PosX $x -PosY $y -Width $w -Height $h";
            $json = @shell_exec($cmd);
            $r = is_string($json) ? json_decode(trim($json), true) : null;
            if (!is_array($r) || !array_key_exists('ok', $r)) {
                SyncLogger::warn('window_control', "Control '$action' hwnd=$hwnd khong tra ve JSON");
                return ['ok' => false, 'error' => 'Control script khong phan hoi'];
            }
            if (!$r['ok']) {
                SyncLogger::warn('window_control', "Control '$action' hwnd=$hwnd that bai: " . ($r['error'] ?? ''));
            }
            return ['ok' => (bool)$r['ok'], 'error' => $r['error'] ?? null, 'rect' => $r['rect'] ?? null];
        } catch (Throwable $e) {
            SyncLogger::error('window_control', "Exception control '$action' hwnd=$hwnd", null, $e);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    public static function moveWindow(int $hwnd, int $x, int $y): array
    {
        return self::control($hwnd, 'move', $x, $y);
    }

    public static function resizeWindow(int $hwnd, int $w, int $h): array
    {
        if ($w <= 0 || $h <= 0) return ['ok' => false, 'error' => 'Kich thuoc phai > 0'];
        return self::control($hwnd, 'resize', 0, 0, $w, $h);
    }

    public static function moveResize(int $hwnd, int $x, int $y, int $w, int $h): array
    {
        if ($w <= 0 || $h <= 0) return ['ok' => false, 'error' => 'Kich thuoc phai > 0'];
        return self::control($hwnd, 'moveresize', $x, $y, $w, $h);
    }

    public static function restoreWindow(int $hwnd): array
    {
        return self::control($hwnd, 'restore');
    }

    public static function minimizeWindow(int $hwnd): array
    {
        return self::control($hwnd, 'minimize');
    }

    public static function showWindow(int $hwnd): array
    {
        return self::control($hwnd, 'show');
    }

    public static function hideWindow(int $hwnd): array
    {
        return self::control($hwnd, 'hide');
    }

    public static function bringToFront(int $hwnd): array
    {
        return self::control($hwnd, 'front');
    }
}
