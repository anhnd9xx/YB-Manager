<?php
declare(strict_types=1);
/**
 * TabAutosaveCtl - Dam bao daemon tab_autosave chay khi setting bat.
 * Giong TelegramPollingCtl: test flock tren lock file (nhanh, race-free).
 */
require_once __DIR__ . '/../config.php';

class TabAutosaveCtl
{
    public const ENSURE_THROTTLE_SEC = 60;

    public static function pid(): ?int
    {
        $f = __DIR__ . '/../bin/.tab_autosave.pid';
        if (!is_file($f)) return null;
        $pid = (int)trim((string)@file_get_contents($f));
        if ($pid <= 0) return null;
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        foreach ($out as $line) {
            if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                && stripos($m[1], 'php') !== false) {
                return $pid;
            }
        }
        return null;
    }

    public static function alive(): bool
    {
        $f = __DIR__ . '/../bin/.tab_autosave.lock';
        $fh = @fopen($f, 'c');
        if (!$fh) {
            return self::pid() !== null;
        }
        $got = @flock($fh, LOCK_EX | LOCK_NB);
        if ($got) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return false;
        }
        @fclose($fh);
        return true;
    }

    /** Dam bao daemon chay neu setting tab_autosave=1 (throttle 60s). */
    public static function ensure(): bool
    {
        try {
            if (get_setting('tab_autosave', '1') !== '1') return false;
        } catch (Throwable $e) {
            return false;
        }
        try {
            $sf = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_autosave_ensure.json';
            if (is_file($sf) && (microtime(true) - (float)(json_decode((string)@file_get_contents($sf), true)['at'] ?? 0)) < self::ENSURE_THROTTLE_SEC) {
                return self::alive();
            }
            @file_put_contents($sf, json_encode(['at' => microtime(true)]));
        } catch (Throwable $e) {
        }
        if (self::alive()) return true;
        try {
            $php = php_cli_binary();
            if ($php === '') return false;
            $script = __DIR__ . '/../bin/tab_autosave.php';
            $log = __DIR__ . '/../bin/tab_autosave.log';
            if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
            pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1', 'r'));
            for ($i = 0; $i < 6; $i++) {
                usleep(500000);
                if (self::alive()) return true;
            }
        } catch (Throwable $e) {
        }
        return self::alive();
    }
}
