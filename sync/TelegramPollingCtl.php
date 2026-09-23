<?php
declare(strict_types=1);
/**
 * TelegramPollingCtl - Dam bao polling worker chay (giong acc_spawn_monitor).
 */
require_once __DIR__ . '/../config.php';

class TelegramPollingCtl
{
    public static function pid(): ?int
    {
        $f = __DIR__ . '/../bin/.tg_polling.pid';
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

    public static function ensureRunning(): bool
    {
        if (self::pid() !== null) return true;
        // Chong 409: quet process that, khong tin pid file stale (TOCTOU giua cac spawn)
        if (self::runningCount() > 0) return true;
        $php = php_cli_binary();
        if ($php === '') return false;
        $script = __DIR__ . '/../bin/telegram_polling.php';
        $log = __DIR__ . '/../bin/telegram_polling.log';
        if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
        // Re-check ngay truoc spawn (hep cua race)
        if (self::runningCount() > 0) return true;
        pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1', 'r'));
        for ($i = 0; $i < 10; $i++) {
            usleep(500000);
            if (self::pid() !== null) return true;
        }
        return self::runningCount() > 0;
    }

    /** Dem worker that dang chay (quet commandline, khong phu thuoc pid file). */
    public static function runningCount(): int
    {
        try {
            $out = [];
            @exec('powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like \'*telegram_polling.php*\' } | ForEach-Object { $_.ProcessId }"', $out);
            $n = 0;
            foreach ($out as $line) {
                if (ctype_digit(trim((string)$line))) $n++;
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
