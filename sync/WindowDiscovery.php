<?php
declare(strict_types=1);
/**
 * SyncWindowDiscovery - Tim window Chrome thuoc cac profile duoc quan ly (PHASE 1).
 * Chay sync/win32_discover.ps1 1 lan (gom process + window + monitor), join voi
 * bang profiles theo user-data-dir (lowercase, giong chrome_running_info).
 */
require_once __DIR__ . '/WindowInfo.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncWindowDiscovery
{
    /** @var array|null cache theo request */
    private static ?array $cache = null;

    /** Chuan hoa duong dan profile de so khop (lowercase, / -> \, bo /\ " cuoi). */
    public static function normDir(string $d): string
    {
        return rtrim(strtolower(str_replace('/', '\\', trim($d, " \t\n\r\0\x0B\""))), '\\');
    }

    /** Chay script discovery, tra ve ['processes'=>[], 'windows'=>[SyncWindowInfo], 'monitors'=>[], 'foregroundHwnd'=>int]. */
    public static function discover(bool $useCache = true): array
    {
        if ($useCache && self::$cache !== null) return self::$cache;
        $out = ['processes' => [], 'windows' => [], 'monitors' => [], 'foregroundHwnd' => 0];
        try {
            $script = __DIR__ . '/win32_discover.ps1';
            if (!is_file($script)) {
                SyncLogger::error('discovery', 'Thieu file win32_discover.ps1');
                return $out;
            }
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"';
            $json = @shell_exec($cmd);
            if (!is_string($json) || trim($json) === '') {
                SyncLogger::error('discovery', 'Script discovery khong tra ve du lieu');
                return $out;
            }
            // Bo BOM UTF-8 neu co; fallback chuyen tu codepage console neu JSON vo
            $raw = trim($json);
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            $data = json_decode($raw, true);
            if (!is_array($data) && function_exists('mb_convert_encoding')) {
                $data = json_decode(mb_convert_encoding($raw, 'UTF-8', 'UTF-8, CP936, Windows-1252'), true);
            }
            if (!is_array($data)) {
                SyncLogger::error('discovery', 'JSON discovery khong hop le');
                return $out;
            }
            $out['processes'] = $data['processes'] ?? [];
            $out['monitors'] = $data['monitors'] ?? [];
            $out['foregroundHwnd'] = (int)($data['foregroundHwnd'] ?? 0);

            // Map user-data-dir -> profile. CHUAN HOA 2 phia (lowercase + bo /\ cuoi)
            // vi Chrome quote arg kieu --user-data-dir="C:\...\K__nh_1\" (du backslash).
            $byDir = [];
            try {
                foreach (db()->query('SELECT id, name, user_data_dir, status FROM profiles') as $r) {
                    $byDir[self::normDir((string)$r['user_data_dir'])] = $r;
                }
            } catch (Throwable $e) {
                SyncLogger::warn('discovery', 'Khong doc duoc bang profiles: ' . $e->getMessage());
            }
            // Map pid -> user-data-dir tu process list
            $dirByPid = [];
            foreach ($out['processes'] as $pr) {
                if (!empty($pr['userDataDir'])) $dirByPid[(string)$pr['pid']] = (string)$pr['userDataDir'];
            }
            foreach ($data['windows'] ?? [] as $w) {
                $info = SyncWindowInfo::fromArray(is_array($w) ? $w : []);
                $dir = $dirByPid[(string)$info->pid] ?? '';
                $info->userDataDir = $dir;
                $norm = self::normDir($dir);
                if ($norm !== '' && isset($byDir[$norm])) {
                    $info->profileId = (int)$byDir[$norm]['id'];
                    $info->profileName = (string)$byDir[$norm]['name'];
                }
                $out['windows'][] = $info;
            }
        } catch (Throwable $e) {
            SyncLogger::error('discovery', 'Exception khi discovery', null, $e);
        }
        self::$cache = $out;
        return $out;
    }

    /** Chi cac window trinh duyet chinh (main) cua profile duoc quan ly. */
    public static function findBrowserWindows(bool $managedOnly = true): array
    {
        $out = [];
        foreach (self::discover()['windows'] as $w) {
            if (!$w->isBrowserMain()) continue;
            if ($managedOnly && $w->profileId === null) continue;
            $out[] = $w;
        }
        // Moi PID giu cua so co dien tich lon nhat (main window that)
        $best = [];
        foreach ($out as $w) {
            $k = (string)$w->pid;
            if (!isset($best[$k]) || $w->area() > $best[$k]->area()) $best[$k] = $w;
        }
        return array_values($best);
    }

    /** Tim window theo HWND (tra ve null neu khong thay / khong phai Chrome quan ly). */
    public static function findWindow(int $hwnd): ?SyncWindowInfo
    {
        foreach (self::discover()['windows'] as $w) {
            if ($w->hwnd === $hwnd) return $w;
        }
        return null;
    }

    /** Danh sach monitor (id, name, resolution, workArea, dpi, primary). */
    public static function monitors(): array
    {
        return self::discover()['monitors'];
    }
}
