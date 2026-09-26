<?php
declare(strict_types=1);
/**
 * OpenCodeServiceManager - Quan ly OpenCode headless server local-only.
 * States: STOPPED | STARTING | ONLINE | BUSY | RESTARTING | ERROR.
 * Server bind 127.0.0.1 + Basic auth (password tu secret settings).
 * YT Manager crash/khong anh huong: moi thao tac co try/catch + timeout.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class OpenCodeService
{
    public const ST_STOPPED = 'STOPPED';
    public const ST_STARTING = 'STARTING';
    public const ST_ONLINE = 'ONLINE';
    public const ST_BUSY = 'BUSY';
    public const ST_RESTARTING = 'RESTARTING';
    public const ST_ERROR = 'ERROR';

    public static function host(): string
    {
        return trim((string)get_setting('ai_oc_host', '127.0.0.1')) ?: '127.0.0.1';
    }

    public static function port(): int
    {
        $p = (int)get_setting('ai_oc_port', '4096');
        return ($p >= 1024 && $p <= 65535) ? $p : 4096;
    }

    public static function baseUrl(): string
    {
        return 'http://' . self::host() . ':' . self::port();
    }

    /** Mat khau server: sinh 1 lan, luu settings (khong log). */
    public static function password(): string
    {
        $pw = get_setting('ai_oc_password', '');
        if ($pw === '') {
            $pw = 'ytm-' . substr(md5(microtime(true) . mt_rand()), 0, 8)
                . substr(md5(mt_rand() . 'oc'), 0, 8);
            set_setting('ai_oc_password', $pw);
        }
        return $pw;
    }

    /** Duong dan binary opencode (exe truc tiep, tranh .ps1 policy). */
    public static function binary(): string
    {
        $cfg = trim((string)get_setting('ai_oc_binary', ''));
        if ($cfg !== '' && is_file($cfg)) return $cfg;
        $cands = [
            getenv('APPDATA') . '\npm\node_modules\opencode-ai\bin\opencode.exe',
            'C:\Users\asus\AppData\Roaming\npm\node_modules\opencode-ai\bin\opencode.exe',
        ];
        foreach ($cands as $c) {
            if ($c && is_file($c)) return $c;
        }
        return '';
    }

    public static function pidFile(): string
    {
        return __DIR__ . '/../bin/.opencode_server.pid';
    }

    public static function pid(): ?int
    {
        $f = self::pidFile();
        if (!is_file($f)) return null;
        $pid = (int)trim((string)@file_get_contents($f));
        if ($pid <= 0) return null;
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        foreach ($out as $line) {
            if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                && (stripos($m[1], 'opencode') !== false || stripos($m[1], 'node') !== false)) {
                return $pid;
            }
        }
        return null;
    }

    /** @return array{state, pid?, url?, detail?} */
    public static function status(): array
    {
        $pid = self::pid();
        if ($pid === null) return ['state' => self::ST_STOPPED, 'pid' => null, 'url' => self::baseUrl()];
        $h = self::health(4);
        if (!empty($h['ok'])) {
            $busy = self::busySessions();
            return ['state' => $busy > 0 ? self::ST_BUSY : self::ST_ONLINE,
                'pid' => $pid, 'url' => self::baseUrl(), 'busy_sessions' => $busy];
        }
        return ['state' => self::ST_ERROR, 'pid' => $pid, 'url' => self::baseUrl(),
            'detail' => 'Process sống nhưng API không phản hồi'];
    }

    /** So session dang busy ( polling /session/active ). */
    public static function busySessions(): int
    {
        try {
            require_once __DIR__ . '/OpenCodeGateway.php';
            $r = OpenCodeGateway::get('/api/session/active');
            if (!empty($r['ok']) && is_array($r['data'] ?? null)) return count($r['data']);
        } catch (Throwable $e) {
        }
        return 0;
    }

    /** @return array{ok} */
    public static function health(int $timeout = 4): array
    {
        try {
            require_once __DIR__ . '/OpenCodeGateway.php';
            $r = OpenCodeGateway::get('/api/health', $timeout);
            return ['ok' => !empty($r['ok']) && !empty($r['data']['healthy'])];
        } catch (Throwable $e) {
            return ['ok' => false];
        }
    }

    /** @return array{ok, pid?, message?} */
    public static function start(): array
    {
        if (get_setting('ai_oc_autostart', '1') !== '1' && func_num_args() === 0) {
            // start() tay tu UI/API van cho phep; chi autostart moi check flag.
        }
        if (self::pid() !== null) {
            $h = self::health(4);
            if (!empty($h['ok'])) return ['ok' => true, 'pid' => self::pid(), 'message' => 'Đang chạy'];
        }
        $bin = self::binary();
        if ($bin === '') {
            return ['ok' => false, 'message' => 'Chưa tìm thấy OpenCode (npm i -g opencode-ai)'];
        }
        try {
            $log = __DIR__ . '/../bin/opencode_server.log';
            if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
            $workdir = self::primaryRoot();
            $cmd = 'start "" /B "' . $bin . '" serve --port ' . self::port()
                . ' --hostname ' . self::host()
                . ' >> "' . $log . '" 2>&1';
            // Dat password env cho tien trinh con qua PowerShell (cmd /c khong set env inline an toan)
            $ps = '$env:OPENCODE_SERVER_PASSWORD=\'' . str_replace("'", "''", self::password()) . '\'; '
                . 'Start-Process -FilePath \'' . str_replace("'", "''", $bin) . '\' '
                . '-ArgumentList \'serve\',\'--port\',\'' . self::port() . '\',\'--hostname\',\'' . self::host() . '\' '
                . '-WindowStyle Hidden -WorkingDirectory \'' . str_replace("'", "''", $workdir) . '\' '
                . '-RedirectStandardOutput \'' . str_replace("'", "''", $log) . '\' '
                . '-RedirectStandardError \'' . str_replace("'", "''", $log) . '.err\' | Out-Null';
            pclose(popen('powershell -NoProfile -Command "' . $ps . '"', 'r'));
            // Doi process xuat hien (scan command line)
            $pid = null;
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                $pid = self::findServePid();
                if ($pid !== null) break;
            }
            if ($pid === null) return ['ok' => false, 'message' => 'Không spawn được server'];
            @file_put_contents(self::pidFile(), (string)$pid);
            // Doi API healthy
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                $h = self::health(3);
                if (!empty($h['ok'])) {
                    try {
                        SyncLogger::info('aidev', '[OpenCode] server ONLINE pid=' . $pid);
                    } catch (Throwable $e) {
                    }
                    return ['ok' => true, 'pid' => $pid];
                }
            }
            return ['ok' => false, 'message' => 'Server không phản hồi API'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Tim PID opencode serve bang CIM (khong phu thuoc wmic, khong nested quotes). */
    public static function findServePid(): ?int
    {
        try {
            $ps = 'Get-CimInstance Win32_Process | Where-Object { $_.Name -eq \'opencode.exe\' '
                . '-and $_.CommandLine -like \'*serve*\' } | '
                . 'Select-Object -ExpandProperty ProcessId';
            $out = shell_exec('powershell -NoProfile -Command "' . $ps . '" 2>NUL');
            if (is_string($out) && preg_match('/(\d+)/', $out, $m)) return (int)$m[1];
        } catch (Throwable $e) {
        }
        return null;
    }

    /** @return array{ok, message?} */
    public static function stop(): array
    {
        try {
            $pid = self::pid() ?? self::findServePid();
            if ($pid === null) {
                @unlink(self::pidFile());
                return ['ok' => true, 'message' => 'Đã dừng'];
            }
            @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
            usleep(800000);
            if (self::findServePid() === null) {
                @unlink(self::pidFile());
                return ['ok' => true];
            }
            return ['ok' => false, 'message' => 'Không dừng được process'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{ok, ...} */
    public static function restart(): array
    {
        self::stop();
        usleep(1000000);
        return self::start();
    }

    /** Dam bao chay (autostart ON): duoc goi tu API/UI. */
    public static function ensureRunning(): array
    {
        $st = self::status();
        if (in_array($st['state'], [self::ST_ONLINE, self::ST_BUSY], true)) return ['ok' => true, 'state' => $st['state']];
        if (get_setting('ai_oc_autostart', '1') !== '1') {
            return ['ok' => false, 'state' => $st['state'], 'message' => 'Auto Start đang OFF'];
        }
        return self::start();
    }

    /**
     * Spawn tien trinh PHP tach roi (AI answer async...). Tra pid hoac 0.
     * Dung Start-Process (khong start /B duoi Apache) nhu proxy_relay.
     */
    public static function spawnPhp(string $script, array $args = []): int
    {
        try {
            $php = php_cli_binary();
            if ($php === '') $php = 'C:/xampp/php/php.exe';
            $flat = [];
            foreach ($args as $k => $v) $flat[] = '--' . $k . '=' . $v;
            $argList = implode(',', array_map(fn($a) => '\'' . str_replace("'", "''", $a) . '\'', array_merge([$php, $script], $flat)));
            // Ghi text dai ra file tam thay vi args (tranh quoting hell)
            $ps = 'Start-Process -FilePath \'' . str_replace("'", "''", $php) . '\' '
                . '-ArgumentList ' . $argList . ' -WindowStyle Hidden | Out-Null';
            pclose(popen('powershell -NoProfile -Command "' . $ps . '"', 'r'));
            return 1;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function primaryRoot(): string
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::primary();
            if ($p && is_dir((string)$p['root_path'])) return (string)$p['root_path'];
        } catch (Throwable $e) {
        }
        return BASE_DIR;
    }
}
