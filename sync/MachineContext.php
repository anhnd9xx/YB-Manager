<?php
declare(strict_types=1);
/**
 * MachineContext - Dinh danh may + session runtime (portability lifecycle).
 * Runtime state (PID/HWND/life/guard/batch) chi tin khi machine khop.
 * Config/SessionSnapshot khong phu thuoc machine.
 */
class MachineContext
{
    private static ?string $id = null;
    private static ?int $session = null;

    /** ID on dinh per-machine: MachineGuid registry, fallback hostname+user. */
    public static function id(): string
    {
        if (self::$id !== null) return self::$id;
        try {
            $out = [];
            @exec('reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid 2>NUL', $out);
            foreach ($out as $line) {
                if (preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-fA-F-]{10,})/', $line, $m)) {
                    self::$id = 'mg-' . strtolower($m[1]);
                    return self::$id;
                }
            }
        } catch (Throwable $e) {
        }
        try {
            $h = gethostname() ?: 'unknown-host';
            $u = get_current_user() ?: 'unknown-user';
            self::$id = 'hu-' . substr(md5(strtolower($h) . '|' . strtolower((string)$u)), 0, 16);
        } catch (Throwable $e) {
            self::$id = 'hu-fallback';
        }
        return self::$id;
    }

    /** Windows session id cua process hien tai (Service Session 0 detection). */
    public static function sessionId(): int
    {
        if (self::$session !== null) return self::$session;
        try {
            $out = trim((string)@shell_exec(
                'powershell -NoProfile -Command "[System.Diagnostics.Process]::GetCurrentProcess().SessionId"'));
            $sid = (int)trim($out);
            // shell_exec co the kem output thua -> lay so cuoi
            if ((string)$sid !== trim($out) && preg_match('/(\d+)\s*$/', $out, $m)) $sid = (int)$m[1];
            self::$session = $sid;
        } catch (Throwable $e) {
            self::$session = -1;
        }
        return self::$session;
    }

    public static function describe(): array
    {
        try {
            $host = gethostname() ?: '';
        } catch (Throwable $e) {
            $host = '';
        }
        return ['machine_id' => self::id(), 'hostname' => $host,
            'session_id' => self::sessionId()];
    }

    /** Record runtime co phai cua may hien tai khong. */
    public static function isCurrent($rec): bool
    {
        if (!is_array($rec)) return false;
        if (!isset($rec['machine']) || $rec['machine'] === '') return false;
        return hash_equals((string)$rec['machine'], self::id());
    }

    /**
     * Startup cleanup: xoa runtime files khong phai cua may hien tai
     * (copy %TEMP% tu may khac) + bao cao. Khong dong vao config/sessions.
     * @return array{scanned, discarded}
     */
    public static function reconcileTempRuntime(): array
    {
        $out = ['scanned' => 0, 'discarded' => 0];
        try {
            $dir = rtrim(sys_get_temp_dir(), '/\\');
            $files = glob($dir . DIRECTORY_SEPARATOR . 'ytm_{life,guard}_*.json') ?: [];
            $bf = glob($dir . DIRECTORY_SEPARATOR . 'ytm_batch_*_*.json') ?: [];
            $files = array_merge($files, $bf);
            $sf = $dir . DIRECTORY_SEPARATOR . 'ytm_batch_stop_active.json';
            if (is_file($sf)) $files[] = $sf;
            foreach ($files as $f) {
                if (!is_file($f)) continue;
                $out['scanned']++;
                try {
                    $j = json_decode((string)@file_get_contents($f), true);
                } catch (Throwable $e) {
                    $j = null;
                }
                if (is_array($j) && isset($j['machine']) && !self::isCurrent($j)) {
                    @unlink($f);
                    $out['discarded']++;
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
