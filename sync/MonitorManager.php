<?php
declare(strict_types=1);
/**
 * SyncMonitorManager - Cua ngõ tập trung cho monitor/working-area/DPI (PHASE 3).
 * Mong tren SyncWindowDiscovery (EnumDisplayMonitors/GetMonitorInfo/GetDpiForMonitor)
 * + SyncLayoutManager::workArea. KHONG goi WinAPI truc tiep, KHONG duplicate logic.
 * Mọi working area deu la physical pixel, ke ca toa do am (monitor phu ben trai).
 */
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/LayoutManager.php';

class SyncMonitorManager
{
    /** Tat ca monitor: [{id,handle,name,resolution{w,h},workArea{x,y,w,h},dpi{x,y},primary}]. */
    public static function list(): array
    {
        try {
            return SyncWindowDiscovery::monitors();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function primary(): ?array
    {
        foreach (self::list() as $m) {
            if (!empty($m['primary'])) return $m;
        }
        $all = self::list();
        return $all[0] ?? null;
    }

    public static function get(int $id): ?array
    {
        foreach (self::list() as $m) {
            if ((int)($m['id'] ?? 0) === $id) return $m;
        }
        return null;
    }

    /** Working area 1 monitor (mac dinh primary). Luon tru taskbar (WinAPI work rect). */
    public static function workArea(?int $monitorId = null): ?array
    {
        try {
            return SyncLayoutManager::workArea($monitorId);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Tat ca working areas dang [{monitorId,name,x,y,w,h,resW,resH,dpi,primary}], sap theo dien tich giam dan. */
    public static function allWorkAreas(): array
    {
        $out = [];
        foreach (self::list() as $m) {
            $wa = $m['workArea'] ?? null;
            if (!is_array($wa) || (int)($wa['w'] ?? 0) <= 0 || (int)($wa['h'] ?? 0) <= 0) continue;
            $res = $m['resolution'] ?? [];
            $dpi = $m['dpi'] ?? ['x' => 96, 'y' => 96];
            $out[] = [
                'monitorId' => (int)$m['id'],
                // Device name (\\\\.\\DISPLAY1) on dinh hon numeric id khi rut/cam monitor
                'name' => (string)($m['name'] ?? ''),
                'x' => (int)$wa['x'], 'y' => (int)$wa['y'],
                'w' => (int)$wa['w'], 'h' => (int)$wa['h'],
                'resW' => (int)($res['w'] ?? $wa['w']),
                'resH' => (int)($res['h'] ?? $wa['h']),
                'dpi' => (int)($dpi['x'] ?? 96),
                'primary' => !empty($m['primary']),
            ];
        }
        usort($out, fn($a, $b) => ($b['w'] * $b['h']) <=> ($a['w'] * $a['h']));
        return $out;
    }

    /**
     * Giai quyet monitor muc tieu tu settings layout.
     * $setting: 'primary'|'<id>'|'all'. $allowMulti: layout_multi ON.
     * Tra ve danh sach work areas (rong neu khong co monitor). Monitor chon mat
     * -> fallback Primary (spec muc 19). 'all' hoac multi ON -> nhieu areas de phan phoi.
     */
    public static function resolveTargets(string $setting, bool $allowMulti): array
    {
        $all = self::allWorkAreas();
        if (!$all) return [];
        $setting = strtolower(trim($setting));
        if ($setting === 'all' || $allowMulti) {
            // 'all' hoac multi: dung tat ca (da sort theo dien tich, monitor lon nhan nhieu)
            if ($setting === 'all') return $all;
            if ($allowMulti) return $all;
        }
        if ($setting !== 'primary' && ctype_digit($setting) && (int)$setting > 0) {
            foreach ($all as $a) {
                if ($a['monitorId'] === (int)$setting) return [$a];
            }
            // id mat -> fallback primary
        }
        foreach ($all as $a) {
            if (!empty($a['primary'])) return [$a];
        }
        return [$all[0]];
    }

    /** Chuoi mo ta ngan cho log: "Mon1 1920x1040@0,0 DPI144". */
    public static function describe(array $area): string
    {
        $dpi = isset($area['dpi']) ? ' DPI' . (int)$area['dpi'] : '';
        if ($dpi === '') {
            foreach (self::list() as $m) {
                if ((int)($m['id'] ?? 0) === (int)($area['monitorId'] ?? 0) && isset($m['dpi'])) {
                    $dpi = ' DPI' . (int)$m['dpi']['x'];
                    break;
                }
            }
        }
        return 'Mon' . (int)($area['monitorId'] ?? 0) . ' ' . (int)$area['w'] . 'x' . (int)$area['h']
            . '@' . (int)$area['x'] . ',' . (int)$area['y'] . $dpi;
    }

    /**
     * Giai quyet theo DANH SACH device names (stable identity, spec muc 13).
     * Tra ve [areas, missing[]]: ten khong map duoc monitor live -> missing
     * (caller hoi disconnect ask/auto). Rong het -> caller fallback primary.
     * Thu tu areas GIU NGUYEN thu tu names (quan trong cho sequential).
     */
    public static function resolveTargetsByNames(array $names): array
    {
        $all = self::allWorkAreas();
        $byName = [];
        foreach ($all as $a) {
            if ($a['name'] !== '') $byName[strtolower($a['name'])] = $a;
        }
        $areas = [];
        $missing = [];
        foreach ($names as $nm) {
            $nm = trim((string)$nm);
            if ($nm === '') continue;
            $k = strtolower($nm);
            if (isset($byName[$k])) $areas[] = $byName[$k];
            else $missing[] = $nm;
        }
        return [$areas, $missing];
    }
}
