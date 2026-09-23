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

    private static function control(int $hwnd, string $action, int $x = 0, int $y = 0, int $w = 0, int $h = 0, array $trace = []): array
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
            $res = ['ok' => (bool)$r['ok'], 'error' => $r['error'] ?? null, 'rect' => $r['rect'] ?? null];
            self::traceMove($hwnd, $action, $x, $y, $w, $h, $res, $trace);
            return $res;
        } catch (Throwable $e) {
            SyncLogger::error('window_control', "Exception control '$action' hwnd=$hwnd", null, $e);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    public static function moveWindow(int $hwnd, int $x, int $y, array $trace = []): array
    {
        return self::control($hwnd, 'move', $x, $y, 0, 0, $trace);
    }

    public static function resizeWindow(int $hwnd, int $w, int $h, array $trace = []): array
    {
        if ($w <= 0 || $h <= 0) return ['ok' => false, 'error' => 'Kich thuoc phai > 0'];
        return self::control($hwnd, 'resize', 0, 0, $w, $h, $trace);
    }

    public static function moveResize(int $hwnd, int $x, int $y, int $w, int $h, array $trace = []): array
    {
        if ($w <= 0 || $h <= 0) return ['ok' => false, 'error' => 'Kich thuoc phai > 0'];
        return self::control($hwnd, 'moveresize', $x, $y, $w, $h, $trace);
    }

    public static function restoreWindow(int $hwnd): array
    {
        return self::control($hwnd, 'restore');
    }

    public static function maximizeWindow(int $hwnd, array $trace = []): array
    {
        return self::control($hwnd, 'maximize', 0, 0, 0, 0, $trace);
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

    /**
     * Apply NHIEU rect trong 1 batch duy nhat (B5-B6): 1 process powershell,
     * DeferWindowPos + NOACTIVATE mac dinh (khong cuop focus). Loi 1 window khong lan.
     * @param array $rects [{hwnd,x,y,w,h}, ...]
     * @param bool $noActivate true = khong kich hoat window (mac dinh)
     * @return array hwnd(string) => ['ok'=>bool,'error'=>?string,'rect'=>?array]
     */
    public static function applyLayoutBatch(array $rects, bool $noActivate = true, array $trace = []): array
    {
        $out = [];
        $items = [];
        foreach ($rects as $r) {
            $hwnd = (int)($r['hwnd'] ?? 0);
            if ($hwnd <= 0) continue;
            $items[] = ['hwnd' => $hwnd, 'x' => (int)($r['x'] ?? 0), 'y' => (int)($r['y'] ?? 0),
                        'w' => (int)($r['w'] ?? 0), 'h' => (int)($r['h'] ?? 0)];
            $out[(string)$hwnd] = ['ok' => false, 'error' => 'Chua apply', 'rect' => null];
        }
        if (!$items) return $out;
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_layout_' . getmypid() . '.json';
        try {
            if (@file_put_contents($tmp, json_encode($items)) === false) {
                foreach ($out as $k => &$v) $v['error'] = 'Khong ghi duoc input batch';
                unset($v);
                return $out;
            }
            $script = __DIR__ . '/win32_apply_layout.ps1';
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"'
                . ' -InputFile "' . $tmp . '"'
                . ($noActivate ? '' : ' -AllowFocus');
            $json = @shell_exec($cmd);
            $r = is_string($json) ? json_decode(trim($json), true) : null;
            if (!is_array($r) || !isset($r['results']) || !is_array($r['results'])) {
                SyncLogger::warn('window_batch', 'Batch script khong tra ve JSON');
                foreach ($out as $k => &$v) $v['error'] = 'Batch script khong phan hoi';
                unset($v);
                return $out;
            }
            foreach ($r['results'] as $one) {
                $k = (string)(int)($one['hwnd'] ?? 0);
                if (!isset($out[$k])) continue;
                $out[$k] = ['ok' => !empty($one['ok']), 'error' => $one['error'] ?? null,
                            'rect' => $one['rect'] ?? null];
                if (empty($one['ok'])) {
                    SyncLogger::warn('window_batch', "Batch hwnd=$k that bai: " . ($one['error'] ?? ''));
                } else {
                    // Trace batch move (tim target rect da gui)
                    foreach ($items as $it) {
                        if ((string)$it['hwnd'] === $k) {
                            self::traceMove((int)$k, 'batch', $it['x'], $it['y'], $it['w'], $it['h'],
                                $out[$k], $trace);
                            break;
                        }
                    }
                }
            }
            return $out;
        } catch (Throwable $e) {
            SyncLogger::error('window_batch', 'Exception batch apply', null, $e);
            foreach ($out as $k => &$v) $v['error'] = mb_substr($e->getMessage(), 0, 200);
            unset($v);
            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Window move trace tam thoi (§2): MOI lenh move/resize log de tim thu pham.
     * Format: [WINDOW MOVE] t= profile= hwnd= caller= reason= OLDmon -> NEWmon rects.
     * $trace: ['profileId'=>int, 'reason'=>string, 'batch_id'=>?string]
     */
    public static function traceMove(int $hwnd, string $action, int $x, int $y, int $w, int $h,
        array $res, array $trace = []): void
    {
        try {
            if (!in_array($action, ['move', 'resize', 'moveresize', 'maximize', 'batch'], true)) return;
            if (empty($res['ok'])) return;
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            $caller = 'unknown';
            foreach ($bt as $f) {
                $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
                if ($fn !== '' && stripos($fn, 'traceMove') === false && stripos($fn, 'control') === false) {
                    $caller = $fn;
                    break;
                }
            }
            $pid = (int)($trace['profileId'] ?? 0);
            $oldMon = '?';
            $oldRect = '?';
            try {
                $win = self::findWindow($hwnd);
                if ($win !== null) {
                    if ($pid <= 0 && $win->profileId !== null) $pid = (int)$win->profileId;
                    $r = $win->rect;
                    // findWindow doc cache truoc khi move -> day la rect CU (gan dung)
                    if (is_array($r)) $oldRect = $r['x'] . ',' . $r['y'] . ',' . $r['w'] . 'x' . $r['h'];
                    require_once __DIR__ . '/WindowPlacementManager.php';
                    $m = WindowPlacementManager::monitorForHwnd($hwnd);
                    if ($m !== null) $oldMon = (string)($m['device_name'] ?? '?');
                }
            } catch (Throwable $e) {
            }
            $newRect = is_array($res['rect'] ?? null)
                ? $res['rect']['x'] . ',' . $res['rect']['y'] . ',' . $res['rect']['w'] . 'x' . $res['rect']['h']
                : "$x,$y,{$w}x{$h}";
            $newMon = '?';
            try {
                require_once __DIR__ . '/WindowPlacementManager.php';
                $nr = is_array($res['rect'] ?? null) ? $res['rect'] : ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
                $nm = WindowPlacementManager::monitorForRect((int)$nr['x'], (int)$nr['y'], max(1, (int)$nr['w']), max(1, (int)$nr['h']));
                if ($nm !== null) $newMon = (string)($nm['device_name'] ?? '?');
            } catch (Throwable $e) {
            }
            $life = '';
            try {
                require_once __DIR__ . '/ChromeBatchManager.php';
                $l = $pid > 0 ? ChromeBatchManager::lifeGet($pid) : null;
                if ($l !== null) $life = ' life=' . ($l['state'] ?? '?');
            } catch (Throwable $e) {
            }
            SyncLogger::info('window_move', '[WINDOW MOVE] t=' . date('H:i:s.v')
                . ' profile=' . $pid . ' hwnd=' . $hwnd . ' caller=' . $caller
                . ' reason=' . (string)($trace['reason'] ?? $action)
                . ' ' . $oldMon . ' -> ' . $newMon
                . ' old=(' . $oldRect . ') new=(' . $newRect . ')' . $life
                . (!empty($trace['batch_id']) ? ' batch=' . $trace['batch_id'] : ''), $pid > 0 ? $pid : null);
        } catch (Throwable $e) {
        }
    }
}
