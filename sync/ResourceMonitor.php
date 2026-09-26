<?php
declare(strict_types=1);
/**
 * ResourceMonitor - CPU/RAM/Chrome processes (Windows). Khong fake data:
 * khong lay duoc -> null, UI hien "—". Cache 5s tranh poll day.
 */
require_once __DIR__ . '/../config.php';

class ResourceMonitor
{
    private const CACHE_SEC = 5;

    /** @return array{cpu_percent,mem_used_gb,mem_total_gb,mem_percent,chrome_processes,managed_running} */
    public static function snapshot(): array
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_resources.json';
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j) && (time() - (int)($j['ts'] ?? 0)) < self::CACHE_SEC && isset($j['data'])) {
                return $j['data'];
            }
        }
        $data = ['cpu_percent' => null, 'mem_used_gb' => null, 'mem_total_gb' => null,
            'mem_percent' => null, 'chrome_processes' => null, 'managed_running' => null];
        // CIM truc tiep (wmic da bi loai bo; Start-Job khong chay trong PHP context).
        // CIM thuong <2s; cache 5s + try/catch nen khong treo request.
        try {
            $ps = '$c = Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average; '
                . '$o = Get-CimInstance Win32_OperatingSystem; '
                . '@($c.Average, $o.TotalVisibleMemorySize, $o.FreePhysicalMemory) -join \'|\'';
            $out = shell_exec('powershell -NoProfile -Command "' . $ps . '" 2>NUL');
            if (is_string($out) && preg_match('/([\d.]+)\|(\d+)\|(\d+)/', $out, $m)) {
                $data['cpu_percent'] = (int)round((float)$m[1]);
                $tot = (int)$m[2];
                $free = (int)$m[3];
                if ($tot > 0) {
                    $data['mem_total_gb'] = round($tot / 1024 / 1024, 1);
                    $data['mem_used_gb'] = round(($tot - $free) / 1024 / 1024, 1);
                    $data['mem_percent'] = (int)round(($tot - $free) * 100 / $tot);
                }
            }
        } catch (Throwable $e) {
        }
        try {
            $out = [];
            @exec('tasklist /FI "IMAGENAME eq chrome.exe" /FO CSV /NH 2>NUL', $out);
            $n = 0;
            foreach ($out as $line) {
                if (stripos(trim($line), '"chrome.exe"') === 0) $n++;
            }
            $data['chrome_processes'] = $n;
        } catch (Throwable $e) {
        }
        try {
            $data['managed_running'] = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
        } catch (Throwable $e) {
        }
        @file_put_contents($f, json_encode(['ts' => time(), 'data' => $data]));
        return $data;
    }
}
