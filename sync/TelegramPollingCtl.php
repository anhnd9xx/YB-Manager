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
        if (self::alive()) return true;
        $php = php_cli_binary();
        if ($php === '') return false;
        $script = __DIR__ . '/../bin/telegram_polling.php';
        $log = __DIR__ . '/../bin/telegram_polling.log';
        if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
        pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1', 'r'));
        for ($i = 0; $i < 10; $i++) {
            usleep(500000);
            if (self::pid() !== null) return true;
        }
        return self::alive();
    }

    /**
     * Worker con song? Test flock tren lock file cua worker (nhanh, race-free,
     * khong phu thuoc WMI cham). Lay duoc lock => khong ai giu => dead.
     */
    public static function alive(): bool
    {
        $f = __DIR__ . '/../bin/.tg_polling.lock';
        $fh = @fopen($f, 'c');
        if (!$fh) {
            $pid = self::pid();
            return $pid !== null;
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
}
