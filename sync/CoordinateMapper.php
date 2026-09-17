<?php
declare(strict_types=1);
/**
 * SyncCoordinateMapper - Anh xa toa do MAIN -> CONTROLLED (spec muc 7).
 * NGUYEN TAC BAT BUOC: khong bao gio gui nguyen screen coordinate cua MAIN.
 * Pipeline: MAIN SCREEN -> MAIN CLIENT -> NORMALIZED (0..1) -> TARGET CLIENT -> TARGET SCREEN.
 *
 * 2 duong di:
 *  a) Viewport path (CDP, dung cho inject page): client CSS px + viewport -> normalized.
 *     Mien nhiem DPI (CSS px doc lap DPI) -> chinh xac tuyet doi giua cac cua so
 *     khac kich thuoc/DPI/monitor.
 *  b) Screen path (Win32, dung cho backend Windows API + debug cursor):
 *     tru client origin, chia client size, nhan target size, cong target origin.
 */
class SyncCoordinateMapper
{
    /** Viewport client (x,y,vw,vh) -> normalized [0..1] (clamp bien). */
    public static function normalizeViewport(float $x, float $y, float $vw, float $vh): array
    {
        $vw = max(1.0, $vw);
        $vh = max(1.0, $vh);
        return [
            'x' => min(1.0, max(0.0, $x / $vw)),
            'y' => min(1.0, max(0.0, $y / $vh)),
        ];
    }

    /** Normalized -> viewport CSS px cua target (can vw/vh hien tai cua target). */
    public static function toViewport(float $nx, float $ny, float $vw, float $vh): array
    {
        return ['x' => $nx * max(1.0, $vw), 'y' => $ny * max(1.0, $vh)];
    }

    /**
     * Screen path day du: mainScreen -> targetScreen.
     * $mainClient/$targetClient: ['x','y','w','h'] goc SCREEN (lay tu WindowManager::getClientRect).
     */
    public static function mapScreen(float $sx, float $sy, array $mainClient, array $targetClient): array
    {
        $mw = max(1, (int)$mainClient['w']);
        $mh = max(1, (int)$mainClient['h']);
        $nx = ($sx - (float)$mainClient['x']) / $mw;
        $ny = ($sy - (float)$mainClient['y']) / $mh;
        $nx = min(1.0, max(0.0, $nx));
        $ny = min(1.0, max(0.0, $ny));
        return [
            'x' => (float)$targetClient['x'] + $nx * max(1, (int)$targetClient['w']),
            'y' => (float)$targetClient['y'] + $ny * max(1, (int)$targetClient['h']),
            'nx' => $nx, 'ny' => $ny,
        ];
    }

    /**
     * Wheel delta theo deltaMode cua JS (0=pixel, 1=line, 2=page) -> pixel cho CDP.
     * CDP Input.dispatchMouseEvent muon delta pixel.
     */
    public static function wheelToPixels(float $dx, float $dy, int $mode): array
    {
        if ($mode === 1) { $dx *= 16.0; $dy *= 16.0; }       // 1 line ~= 16px
        elseif ($mode === 2) { $dx *= 800.0; $dy *= 800.0; }  // 1 page ~= viewport
        return ['dx' => $dx, 'dy' => $dy];
    }
}
