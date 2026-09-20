<?php
declare(strict_types=1);
/**
 * BrowserProbe - xac dinh browser READY theo nhieu lop, KHONG dung page load.
 *   A) PROCESS: Chrome con khong (chi khi port dong - xac dinh NOT_RUNNING)
 *   B) TCP 127.0.0.1:port (500ms)
 *   C) GET /json/version (1000ms) - raw socket, loopback truc tiep, NO PROXY
 *   D) WS handshake browser URL (1500ms)
 *   E) Browser.getVersion tren WS do (1500ms) -> BROWSER_READY
 * Moi profile 1 probe doc lap (khong global lock). Retry 2 lan cho loi tam
 * thoi (backoff 150/400ms), khong retry mismatch/not-found.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class BrowserProbe
{
    public const PORT_TIMEOUT_MS = 500;
    public const HTTP_TIMEOUT_MS = 1000;
    public const WS_TIMEOUT_MS = 1500;
    public const CMD_TIMEOUT_MS = 1500;
    public const MAX_RETRIES = 2;

    public static function transient(string $code): bool
    {
        return in_array($code, ['DEBUG_PORT_NOT_LISTENING', 'DEVTOOLS_HTTP_UNAVAILABLE',
            'CDP_WEBSOCKET_FAILED', 'CDP_COMMAND_TIMEOUT'], true);
    }

    /**
     * @return array{ready:bool, code?:string, message?:string, port:int,
     *   process_alive?:bool, ws_url?:string, timings:array, attempts:int}
     */
    public static function probe(int $profileId, int $port, string $userDataDir = ''): array
    {
        $t = []; // per-layer ms
        $attempts = 0;
        $last = null;
        foreach ([0, 150, 400] as $backoff) {
            if ($backoff > 0) usleep($backoff * 1000);
            $attempts++;
            $last = self::once($port, $userDataDir, $t);
            if ($last['ready'] || !self::transient((string)($last['code'] ?? ''))) break;
            if ($attempts > self::MAX_RETRIES) break;
        }
        $total = array_sum($t);
        try {
            $line = "[BROWSER PROBE] profile=$profileId port=$port"
                . ' tcp=' . ($t['tcp'] ?? '?') . 'ms'
                . ' version=' . ($t['http'] ?? '?') . 'ms'
                . ' ws=' . ($t['ws'] ?? '?') . 'ms'
                . ' getVersion=' . ($t['cmd'] ?? '?') . 'ms'
                . " total={$total}ms attempts=$attempts"
                . ($last['ready'] ? ' READY' : ' FAIL(' . ($last['code'] ?? '?') . ')');
            if ($last['ready']) SyncLogger::debug('browser_probe', $line, $profileId);
            else SyncLogger::warn('browser_probe', $line, $profileId);
        } catch (Throwable $e) {
        }
        $last['timings'] = $t;
        $last['attempts'] = $attempts;
        return $last;
    }

    private static function once(int $port, string $userDataDir, array &$t): array
    {
        // B) TCP (500ms)
        $t0 = microtime(true);
        $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, self::PORT_TIMEOUT_MS / 1000);
        $t['tcp'] = (int)round((microtime(true) - $t0) * 1000);
        if (!$fp) {
            // Port dong: phan biet NOT_RUNNING vs NOT_LISTENING (process check 1 lan)
            $alive = $userDataDir !== '' ? chrome_processes_alive($userDataDir) : null;
            if ($alive === false) {
                return ['ready' => false, 'code' => 'BROWSER_NOT_RUNNING', 'message' => 'Chrome khong chay', 'port' => $port, 'process_alive' => false];
            }
            return ['ready' => false, 'code' => 'DEBUG_PORT_NOT_LISTENING', 'message' => 'Cong kiem tra chua san sang', 'port' => $port, 'process_alive' => $alive];
        }
        fclose($fp);
        // C) /json/version (1000ms, raw socket - khong qua proxy)
        $t0 = microtime(true);
        $ver = cdp_http($port, 'GET', '/json/version', self::HTTP_TIMEOUT_MS);
        $t['http'] = (int)round((microtime(true) - $t0) * 1000);
        if ($ver === null || $ver['code'] < 200 || $ver['code'] >= 300) {
            return ['ready' => false, 'code' => 'DEVTOOLS_HTTP_UNAVAILABLE', 'message' => 'DevTools HTTP khong phan hoi', 'port' => $port, 'process_alive' => true];
        }
        $j = json_decode($ver['body'], true);
        $ws = is_array($j) ? (string)($j['webSocketDebuggerUrl'] ?? '') : '';
        if ($ws === '') {
            return ['ready' => false, 'code' => 'DEVTOOLS_HTTP_UNAVAILABLE', 'message' => 'Thieu websocket url', 'port' => $port, 'process_alive' => true];
        }
        // Port ownership: browser id phai on dinh? (verify nhe: version phai co Browser ten)
        // D) WS handshake (1500ms)
        $t0 = microtime(true);
        $u = parse_url($ws);
        $wp = (int)($u['port'] ?? $port);
        $path = ($u['path'] ?? '/') . (!empty($u['query']) ? '?' . $u['query'] : '');
        $conn = @stream_socket_client('tcp://127.0.0.1:' . $wp, $errno, $errstr, self::WS_TIMEOUT_MS / 1000);
        if (!$conn) {
            $t['ws'] = (int)round((microtime(true) - $t0) * 1000);
            return ['ready' => false, 'code' => 'CDP_WEBSOCKET_FAILED', 'message' => 'Khong mo duoc WebSocket', 'port' => $port, 'process_alive' => true];
        }
        stream_set_blocking($conn, false);
        $key = base64_encode(random_bytes(16));
        @fwrite($conn, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$wp\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $hdr = '';
        $dl = microtime(true) + self::WS_TIMEOUT_MS / 1000;
        while (strpos($hdr, "\r\n\r\n") === false && microtime(true) < $dl) {
            $r = [$conn];
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) !== 1) continue;
            $c = @fread($conn, 4096);
            if ($c === false || $c === '') break;
            $hdr .= $c;
        }
        if (strpos($hdr, ' 101 ') === false) {
            fclose($conn);
            $t['ws'] = (int)round((microtime(true) - $t0) * 1000);
            return ['ready' => false, 'code' => 'CDP_WEBSOCKET_FAILED', 'message' => 'WebSocket handshake that bai', 'port' => $port, 'process_alive' => true];
        }
        $t['ws'] = (int)round((microtime(true) - $t0) * 1000);
        // E) Browser.getVersion (1500ms) - nhe, khong page load
        $t0 = microtime(true);
        $payload = json_encode(['id' => 1, 'method' => 'Browser.getVersion']);
        $len = strlen($payload);
        $h = chr(0x81) . chr(0x80 | $len);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
        @fwrite($conn, $h . $mask . $masked);
        $buf = '';
        $got = false;
        $dl = microtime(true) + self::CMD_TIMEOUT_MS / 1000;
        while (microtime(true) < $dl) {
            $r = [$conn];
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) !== 1) continue;
            $c = @fread($conn, 65536);
            if ($c === false || $c === '') break;
            $buf .= $c;
            if (strpos($buf, '"id":1') !== false || strpos($buf, '"id": 1') !== false) {
                $got = true;
                break;
            }
        }
        fclose($conn);
        $t['cmd'] = (int)round((microtime(true) - $t0) * 1000);
        if (!$got) {
            return ['ready' => false, 'code' => 'CDP_COMMAND_TIMEOUT', 'message' => 'Trinh duyet phan hoi cham', 'port' => $port, 'process_alive' => true];
        }
        return ['ready' => true, 'port' => $port, 'process_alive' => true, 'ws_url' => $ws];
    }
}
