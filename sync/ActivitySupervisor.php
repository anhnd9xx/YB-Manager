<?php
declare(strict_types=1);
/**
 * ActivitySupervisor - Giam sat singleton worker Auto Activity (khong phai scheduler thu hai).
 *
 * Worker that su: bin/activity_scheduler.php (flock singleton, tick 60s).
 * Supervisor: start khi chet/stale, giu 1 worker duy nhat, tra ve health that
 * (PID song + heartbeat tuoi) cho UI/diagnostics.
 *
 * State: STOPPED | STARTING | RUNNING | STALE | ERROR
 * KHOI tao worker moi khi: khong PID, PID da chet, hoac heartbeat > 180s.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class ActivitySupervisor
{
    public const STALE_SEC = 180;
    public const ENSURE_THROTTLE_SEC = 60;

    public static function pidFile(): string
    {
        return __DIR__ . '/../bin/.activity_scheduler.pid';
    }

    public static function hbFile(): string
    {
        return __DIR__ . '/../bin/.activity_scheduler.hb';
    }

    public static function pid(): ?int
    {
        $f = self::pidFile();
        if (!is_file($f)) return null;
        $pid = (int)trim((string)@file_get_contents($f));
        return $pid > 0 ? $pid : null;
    }

    /** PID co phai tien trinh PHP dang song khong (Windows tasklist). */
    public static function alive(?int $pid = null): bool
    {
        $pid = $pid ?? self::pid();
        if ($pid === null || $pid <= 0) return false;
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        foreach ($out as $line) {
            if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                && stripos($m[1], 'php') !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array|null heartbeat JSON cua worker */
    public static function heartbeat(): ?array
    {
        $f = self::hbFile();
        if (!is_file($f)) return null;
        try {
            $j = json_decode((string)@file_get_contents($f), true);
            return is_array($j) ? $j : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function heartbeatAge(): ?int
    {
        $hb = self::heartbeat();
        if ($hb === null || empty($hb['heartbeat_at'])) return null;
        $ts = strtotime((string)$hb['heartbeat_at']);
        if ($ts === false) return null;
        return max(0, time() - $ts);
    }

    /**
     * Trang thai that: RUNNING (song + heartbeat tuoi) | STALE (song nhung
     * heartbeat gia / chet) | STOPPED. Khong tin PID file mot minh.
     * @return array{state,pid,heartbeat_age,started_at,last_tick,last_ran,last_due,last_error}
     */
    public static function state(): array
    {
        $pid = self::pid();
        $hb = self::heartbeat();
        $out = ['state' => 'STOPPED', 'pid' => $pid, 'heartbeat_age' => null,
            'started_at' => $hb['started_at'] ?? null,
            'last_tick' => $hb['tick'] ?? 0, 'last_ran' => $hb['last_ran'] ?? 0,
            'last_due' => $hb['last_due'] ?? 0, 'last_error' => $hb['last_error'] ?? null];
        if ($pid === null) return $out;
        if (!self::alive($pid)) {
            $out['state'] = 'STOPPED';
            return $out;
        }
        $age = self::heartbeatAge();
        $out['heartbeat_age'] = $age;
        if ($age !== null && $age > self::STALE_SEC) {
            $out['state'] = 'STALE';
            return $out;
        }
        $out['state'] = 'RUNNING';
        return $out;
    }

    /** Spawn worker detached (1 lan). Tra ve true neu len duoc. */
    public static function spawn(): bool
    {
        $php = php_cli_binary();
        if ($php === '') return false;
        $script = __DIR__ . '/../bin/activity_scheduler.php';
        $log = __DIR__ . '/../bin/activity_scheduler.log';
        if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
        // flock trong worker loai trung lap: 2 lenh spawn gan nhau van chi 1 song.
        $cmdline = 'start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1';
        pclose(popen($cmdline, 'r'));
        for ($i = 0; $i < 10; $i++) {
            usleep(500000);
            if (self::alive()) return true;
        }
        return false;
    }

    /**
     * Dam bao worker song (singleton): song+tuoi -> thoi; chet/stale -> spawn.
     * Throttle de khong spawn lien tuc khi MySQL chet han.
     * @return array{running, pid, spawned, state}
     */
    public static function ensure(bool $force = false): array
    {
        $st = self::state();
        if ($st['state'] === 'RUNNING' && !$force) {
            return ['running' => true, 'pid' => $st['pid'], 'spawned' => false, 'state' => 'RUNNING'];
        }
        try {
            $last = (int)get_setting('act_ensure_at', '0');
            if (!$force && (time() - $last) < self::ENSURE_THROTTLE_SEC) {
                return ['running' => false, 'pid' => $st['pid'], 'spawned' => false, 'state' => $st['state']];
            }
            set_setting('act_ensure_at', (string)time());
        } catch (Throwable $e) {
        }
        // Kill tien trinh stale (song nhung khong heartbeat) truoc khi spawn moi
        if ($st['state'] === 'STALE' && !empty($st['pid'])) {
            @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . (int)$st['pid'] . ' -Force -ErrorAction SilentlyContinue"');
            usleep(500000);
        }
        $ok = self::spawn();
        try {
            SyncLogger::info('activity', '[Supervisor] ensure state=' . $st['state'] . ' -> ' . ($ok ? 'RUNNING' : 'FAILED'));
        } catch (Throwable $e) {
        }
        $now = self::state();
        return ['running' => $ok, 'pid' => $now['pid'], 'spawned' => $ok, 'state' => $now['state']];
    }
}
