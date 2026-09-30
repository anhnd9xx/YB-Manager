<?php
declare(strict_types=1);
/**
 * MonitorRegistry - SINGLE OWNER cho danh sach monitor runtime (portable multi-PC).
 *
 * - Khong persist HMONITOR/index: chi device_name (+ semantic SECONDARY) lam preference.
 * - refresh() truoc moi arrange: topology tuoi, khong dung cache cu/may cu.
 * - resolve(): EXACT > PROFILE_SETTING > CURRENT_WINDOW > PRIMARY > FIRST.
 *   Chi null khi registry THUC SU rong (luc do moi bao loi + diagnostics).
 * - resolveSaved() PURE (khong WinAPI) de unit test voi fake registry.
 */
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/SyncLogger.php';

class MonitorRegistry
{
    public const M_EXACT = 'EXACT';
    public const M_PROFILE = 'PROFILE_SETTING';
    public const M_CURRENT_WINDOW = 'CURRENT_WINDOW';
    public const M_PRIMARY_FALLBACK = 'PRIMARY_FALLBACK';
    public const M_AUTO_FALLBACK = 'AUTO_FALLBACK';
    public const M_SECONDARY = 'SECONDARY';

    /** @var array[]|null snapshot runtime */
    private static ?array $snap = null;
    private static float $snapAt = 0.0;
    private static ?array $lastMeta = null;

    public static function invalidate(): void
    {
        self::$snap = null;
        self::$snapAt = 0.0;
        try {
            SyncWindowDiscovery::clearCache();
        } catch (Throwable $e) {
        }
    }

    /**
     * Refresh topology (force). Tra ve snapshot.
     * @return array[] [{runtime_id,device_name,is_primary,bounds,work_area,dpi_x,dpi_y,scale,index,available}]
     */
    public static function refresh(): array
    {
        self::invalidate();
        return self::snapshot();
    }

    /** @return array[] snapshot (cache 5s, giong WPM topology cache) */
    public static function snapshot(): array
    {
        $now = microtime(true);
        if (self::$snap !== null && ($now - self::$snapAt) < 5) return self::$snap;
        $out = [];
        try {
            $disc = SyncWindowDiscovery::discover(false);
            self::$lastMeta = ['monitorEnumMethod' => $disc['monitorEnumMethod'] ?? null,
                'session' => $disc['session'] ?? null];
            $i = 0;
            foreach ((array)($disc['monitors'] ?? []) as $m) {
                $wa = $m['workArea'] ?? null;
                if (!is_array($wa) || (int)($wa['w'] ?? 0) <= 0 || (int)($wa['h'] ?? 0) <= 0) continue;
                $res = $m['resolution'] ?? ['w' => $wa['w'], 'h' => $wa['h']];
                $dpi = $m['dpi'] ?? ['x' => 96, 'y' => 96];
                $dx = max(1, (int)($dpi['x'] ?? 96));
                $i++;
                $out[] = [
                    'runtime_id' => $i,
                    'device_name' => (string)($m['name'] ?? ''),
                    'is_primary' => !empty($m['primary']),
                    'bounds' => ['x' => (int)($wa['x'] ?? 0) - 0, 'y' => (int)($wa['y'] ?? 0),
                        'w' => (int)($res['w'] ?? $wa['w']), 'h' => (int)($res['h'] ?? $wa['h'])],
                    'work_area' => ['x' => (int)$wa['x'], 'y' => (int)$wa['y'],
                        'w' => (int)$wa['w'], 'h' => (int)$wa['h']],
                    'dpi_x' => $dx,
                    'dpi_y' => max(1, (int)($dpi['y'] ?? 96)),
                    'scale' => round($dx / 96, 4),
                    'index' => $i,
                    'available' => true,
                ];
            }
        } catch (Throwable $e) {
            $out = [];
        }
        self::$snap = $out;
        self::$snapAt = $now;
        return $out;
    }

    public static function lastMeta(): ?array
    {
        return self::$lastMeta;
    }

    /** @return array[] */
    public static function getAll(): array
    {
        return self::snapshot();
    }

    public static function getPrimary(): ?array
    {
        foreach (self::snapshot() as $m) {
            if (!empty($m['is_primary'])) return $m;
        }
        $all = self::snapshot();
        return $all[0] ?? null;
    }

    public static function getByDeviceName(string $device): ?array
    {
        $device = trim($device);
        if ($device === '') return null;
        foreach (self::snapshot() as $m) {
            if (strcasecmp((string)$m['device_name'], $device) === 0) return $m;
        }
        return null;
    }

    public static function getByIndex(int $idx): ?array
    {
        foreach (self::snapshot() as $m) {
            if ((int)$m['index'] === $idx) return $m;
        }
        return null;
    }

    /** Monitor lon nhat khong phai primary (portable SECONDARY). */
    public static function getSecondary(): ?array
    {
        $best = null;
        foreach (self::snapshot() as $m) {
            if (!empty($m['is_primary'])) continue;
            if ($best === null || ($m['work_area']['w'] * $m['work_area']['h'])
                > ($best['work_area']['w'] * $best['work_area']['h'])) {
                $best = $m;
            }
        }
        return $best ?? self::getPrimary();
    }

    /** Monitor chua HWND (qua discovery monitorId -> device). */
    public static function getForHwnd(int $hwnd): ?array
    {
        try {
            $w = SyncWindowDiscovery::findWindow($hwnd);
        } catch (Throwable $e) {
            $w = null;
        }
        if ($w === null) return null;
        // Match theo id runtime tu discovery (map sang device qua snapshot hien tai)
        try {
            foreach (SyncWindowDiscovery::discover()['monitors'] as $m) {
                if ((int)($m['id'] ?? 0) === (int)($w->monitorId ?? -1) && !empty($m['name'])) {
                    $hit = self::getByDeviceName((string)$m['name']);
                    if ($hit !== null) return $hit;
                }
            }
        } catch (Throwable $e) {
        }
        // Fallback: center-in theo rect (nearest, khong exact-match)
        if ($w->rect !== null) {
            return self::getForPoint(
                (int)$w->rect['x'] + (int)((int)$w->rect['w'] / 2),
                (int)$w->rect['y'] + (int)((int)$w->rect['h'] / 2));
        }
        return null;
    }

    /** Monitor chua diem (center-in, roi nearest) — khong bao gio throw. */
    public static function getForPoint(int $x, int $y): ?array
    {
        return self::matchPoint($x, $y, self::snapshot());
    }

    /**
     * PURE: match diem tren fake areas (unit test). Khong clamp am (>=0):
     * monitor trai primary (x<0) van match dung.
     * @param array[] $areas [{device_name,work_area:{x,y,w,h}}]
     */
    public static function matchPoint(int $x, int $y, array $areas): ?array
    {
        foreach ($areas as $m) {
            $wa = $m['work_area'];
            if ($x >= $wa['x'] && $x < $wa['x'] + $wa['w']
                && $y >= $wa['y'] && $y < $wa['y'] + $wa['h']) {
                return $m;
            }
        }
        $best = null;
        $bestD = PHP_INT_MAX;
        foreach ($areas as $m) {
            $wa = $m['work_area'];
            $nx = max($wa['x'], min($x, $wa['x'] + $wa['w'] - 1));
            $ny = max($wa['y'], min($y, $wa['y'] + $wa['h'] - 1));
            $d = ($nx - $x) * ($nx - $x) + ($ny - $y) * ($ny - $y);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $m;
            }
        }
        return $best;
    }

    /**
     * Resolve 1 preference theo fallback chain (khong fatal khi stale).
     * @param string $explicit device user chon o modal ('' = khong)
     * @param string $mode PROFILE_SETTING|... ('profile' = dung $savedName)
     * @param string $savedName preferred device da luu ('' = khong)
     * @param int $hwnd window hien tai (0 = khong)
     * @param array[]|null $areas override registry (unit test); null = live snapshot
     * @return array{monitor: ?array, method: string, remapped: bool}
     */
    public static function resolve(string $explicit, string $mode, string $savedName, int $hwnd = 0, ?array $areas = null): array
    {
        $live = $areas === null;
        $all = $live ? self::snapshot() : $areas;
        if (!$all) return ['monitor' => null, 'method' => 'EMPTY_REGISTRY', 'remapped' => false];
        $byDevice = function (string $d) use ($all): ?array {
            $d = trim($d);
            if ($d === '') return null;
            foreach ($all as $m) {
                if (strcasecmp((string)($m['device_name'] ?? ''), $d) === 0) return $m;
            }
            return null;
        };
        if ($explicit !== '' && ($m = $byDevice($explicit)) !== null) {
            return ['monitor' => $m, 'method' => self::M_EXACT, 'remapped' => false];
        }
        if (strtoupper($mode) === 'SECONDARY') {
            $sec = null;
            foreach ($all as $m) {
                if (!empty($m['is_primary'])) continue;
                if ($sec === null || ($m['work_area']['w'] * $m['work_area']['h'])
                    > ($sec['work_area']['w'] * $sec['work_area']['h'])) {
                    $sec = $m;
                }
            }
            if ($sec !== null) {
                return ['monitor' => $sec, 'method' => self::M_SECONDARY,
                    'remapped' => $savedName !== '' && strcasecmp($sec['device_name'], $savedName) !== 0];
            }
        }
        if ($savedName !== '' && ($m = $byDevice($savedName)) !== null) {
            return ['monitor' => $m, 'method' => self::M_PROFILE, 'remapped' => false];
        }
        if ($hwnd > 0 && $live && ($m = self::getForHwnd($hwnd)) !== null) {
            return ['monitor' => $m, 'method' => self::M_CURRENT_WINDOW, 'remapped' => true];
        }
        foreach ($all as $m) {
            if (!empty($m['is_primary'])) {
                return ['monitor' => $m, 'method' => self::M_PRIMARY_FALLBACK, 'remapped' => true];
            }
        }
        return ['monitor' => $all[0], 'method' => self::M_AUTO_FALLBACK, 'remapped' => true];
    }

    /**
     * PURE: resolve tren fake areas (unit test, khong WinAPI).
     * @param array[] $areas [{device_name,is_primary,work_area:{x,y,w,h}}]
     */
    public static function resolveSaved(string $savedName, array $areas, ?string $currentName = null): array
    {
        if (!$areas) return ['monitor' => null, 'method' => 'EMPTY_REGISTRY', 'remapped' => false];
        foreach ($areas as $a) {
            if ($savedName !== '' && strcasecmp((string)($a['device_name'] ?? ''), $savedName) === 0) {
                return ['monitor' => $a, 'method' => self::M_PROFILE, 'remapped' => false];
            }
        }
        if ($currentName !== null && $currentName !== '') {
            foreach ($areas as $a) {
                if (strcasecmp((string)($a['device_name'] ?? ''), $currentName) === 0) {
                    return ['monitor' => $a, 'method' => self::M_CURRENT_WINDOW, 'remapped' => true];
                }
            }
        }
        foreach ($areas as $a) {
            if (!empty($a['is_primary'])) {
                return ['monitor' => $a, 'method' => self::M_PRIMARY_FALLBACK, 'remapped' => true];
            }
        }
        return ['monitor' => $areas[0], 'method' => self::M_AUTO_FALLBACK, 'remapped' => true];
    }

    /** Diagnostics day du cho endpoint/UI (khong bao gio throw). */
    public static function diagnostics(): array
    {
        $all = self::snapshot();
        $meta = self::$lastMeta ?? SyncWindowDiscovery::lastMeta();
        $primary = null;
        foreach ($all as $m) {
            if (!empty($m['is_primary'])) {
                $primary = $m['device_name'];
                break;
            }
        }
        return ['monitor_count' => count($all),
            'enum_method' => $meta['monitorEnumMethod'] ?? null,
            'session' => $meta['session'] ?? null,
            'primary' => $primary,
            'monitors' => array_map(fn($m) => [
                'device' => $m['device_name'], 'primary' => !empty($m['is_primary']),
                'work' => $m['work_area']['x'] . ',' . $m['work_area']['y'] . ' '
                    . $m['work_area']['w'] . 'x' . $m['work_area']['h'],
                'bounds' => $m['bounds']['w'] . 'x' . $m['bounds']['h'],
                'dpi' => $m['dpi_x'], 'scale' => $m['scale'],
            ], $all)];
    }
}
