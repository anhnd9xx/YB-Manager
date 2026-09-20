<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$port = isset($argv[1]) ? (int)$argv[1] : 0;
$pid  = isset($argv[2]) ? trim($argv[2]) : '';
if ($port <= 0 || $pid === '' || !preg_match('/^\d+$/', $pid)) die("usage: scd.php <debug_port> <profile_id>\n");

$key  = 'ch' . $pid;
$dir  = __DIR__ . '/frames/' . $key;
if (!is_dir($dir)) mkdir($dir, 0777, true);
file_put_contents($dir . '/daemon.pid', (string)getmypid());

$FRAME = $dir . '/frame.jpg';
$TMP   = $dir . '/frame.tmp';
$META  = $dir . '/meta.json';
$CTRL  = $dir . '/ctrl.json';

function tabs(int $port): array
{
    // Raw socket thay file_get_contents (wrapper ton 2x timeout moi call DevTools)
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
    $json = false;
    if ($fp) {
        stream_set_blocking($fp, false);
        fwrite($fp, "GET /json/list HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n");
        $buf = '';
        $dl = microtime(true) + 1.5;
        while (microtime(true) < $dl) {
            $r = [$fp];
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) !== 1) continue;
            $c = @fread($fp, 65536);
            if ($c === false || $c === '') break;
            $buf .= $c;
            if (($p = strpos($buf, "\r\n\r\n")) !== false) {
                if (preg_match('#Content-Length:\s*(\d+)#i', substr($buf, 0, $p), $m)
                    && strlen($buf) >= $p + 4 + (int)$m[1]) break;
            }
        }
        fclose($fp);
        if (($p = strpos($buf, "\r\n\r\n")) !== false) $json = substr($buf, $p + 4);
    }
    if ($json === false) return [];
    $arr = json_decode($json, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        if (($t['type'] ?? '') === 'page' && !empty($t['id']) && !empty($t['webSocketDebuggerUrl'])) {
            $out[] = ['id' => $t['id'], 'ws' => $t['webSocketDebuggerUrl']];
        }
    }
    return $out;
}

function connect(string $wsUrl)
{
    $u = parse_url($wsUrl);
    if (!$u) return null;
    $host = 'tcp://127.0.0.1:' . (int)($u['port'] ?? 0);
    $fp = @stream_socket_client($host, $errno, $errstr, 3);
    if (!$fp) return null;
    $path = $u['path'] ?? '/';
    if (!empty($u['query'])) $path .= '?' . $u['query'];
    fwrite($fp, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:" . $u['port'] . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $hdr = '';
    $dl = microtime(true) + 3;
    while (strpos($hdr, "\r\n\r\n") === false && microtime(true) < $dl) {
        $c = fread($fp, 8192);
        if ($c === false || $c === '') break;
        $hdr .= $c;
    }
    if (strpos($hdr, ' 101 ') === false) { fclose($fp); return null; }
    return $fp;
}

function ws_send($fp, string $json): void
{
    $len = strlen($json);
    if ($len < 126) $h = chr(0x81) . chr(0x80 | $len);
    elseif ($len < 65536) $h = chr(0x81) . chr(0x80 | 126) . pack('n', $len);
    else $h = chr(0x81) . chr(0x80 | 127) . pack('J', $len);
    $mask = random_bytes(4);
    $body = '';
    for ($i = 0; $i < $len; $i++) $body .= $json[$i] ^ $mask[$i % 4];
    @fwrite($fp, $h . $mask . $body);
}

function pull($fp, string &$in, array &$msgs): void
{
    while (strlen($in) >= 2) {
        $b0 = ord($in[0]); $b1 = ord($in[1]);
        $op = $b0 & 0x0f; $len = $b1 & 0x7f; $off = 2;
        if ($len === 126) {
            if (strlen($in) < 4) return;
            $len = unpack('n', substr($in, 2, 2))[1]; $off = 4;
        } elseif ($len === 127) {
            if (strlen($in) < 10) return;
            $n = unpack('J', substr($in, 2, 8))[1];
            $len = (int)$n; $off = 10;
        }
        if (strlen($in) < $off + $len) return;
        $payload = substr($in, $off, $len);
        $in = substr($in, $off + $len);
        if ($op === 0x8) { $msgs[] = '__CLOSE__'; return; }
        if ($op === 0x9) { ws_send($fp, $payload); continue; }
        if (($op & 0x3) === 0x1 || $op === 0x0) $msgs[] = $payload;
    }
}

function ctrl(): array
{
    global $CTRL;
    if (!is_file($CTRL)) return [];
    $a = json_decode((string)@file_get_contents($CTRL), true);
    return is_array($a) ? $a : [];
}

$in = ''; $fp = null; $curTab = null; $desired = null; $view = null; $err = null;
$lastWrite = 0;

while (true) {
    $c = ctrl();
    $d = isset($c['tab']) && $c['tab'] !== '' ? (string)$c['tab'] : null;

    if ($fp === null || ($d !== null && $d !== $desired)) {
        ws_close($fp); $in = '';
        $tl = tabs($port);
        $t = null;
        foreach ($tl as $x) { if ($d !== null && $x['id'] === $d) { $t = $x; break; } }
        if ($t === null && !empty($tl)) {
            $t = $tl[0];
            if ($d !== null) file_put_contents($CTRL, json_encode(['tab' => $t['id'], 'ts' => microtime(true)]));
        }
        if ($t === null) {
            file_put_contents($META, json_encode(['error' => 'no tab', 'ts' => microtime(true)]));
            usleep(800000);
            continue;
        }
        $fp = connect($t['ws']);
        if (!$fp) { usleep(1500000); continue; }
        ws_send($fp, json_encode(['id' => 1, 'method' => 'Page.enable']));
        ws_send($fp, json_encode(['id' => 2, 'method' => 'Runtime.evaluate', 'params' => ['expression' => 'JSON.stringify({w:innerWidth,h:innerHeight,dpr:devicePixelRatio})', 'returnByValue' => true]]));
        ws_send($fp, json_encode(['id' => 3, 'method' => 'Page.startScreencast', 'params' => ['format' => 'jpeg', 'quality' => 72, 'maxWidth' => 1280, 'maxHeight' => 900, 'everyNthFrame' => 1]]));
        ws_send($fp, json_encode(['id' => 4, 'method' => 'Browser.getWindowForTarget']));
        $curTab = $t['id']; $desired = $d; $view = null; $err = null;
        $lastWrite = 0; $winId = null; $visFixAt = 0;
        file_put_contents($META, json_encode(['w' => 0, 'h' => 0, 'ts' => microtime(true), 'tab' => $curTab, 'state' => 'attached']));
    }

    if ($fp === null) { usleep(500000); continue; }

    $dl = microtime(true) + 1.0;
    while (microtime(true) < $dl) {
        $r = [$fp]; $w = null; $e = null;
        if (@stream_select($r, $w, $e, 0, 150000) !== 1) break;
        $chunk = @fread($fp, 65536);
        if ($chunk === false || $chunk === '') { ws_close($fp); $fp = null; $curTab = null; break; }
        $in .= $chunk;
        $msgs = [];
        pull($fp, $in, $msgs);
        foreach ($msgs as $msg) {
            if ($msg === '__CLOSE__') { ws_close($fp); $fp = null; $curTab = null; break 2; }
            $j = json_decode($msg, true);
            if (!is_array($j)) continue;
            if (($j['id'] ?? 0) === 2 && isset($j['result']['result']['value'])) {
                $v = json_decode($j['result']['result']['value'], true);
                if (is_array($v)) {
                    $view = $v;
                    file_put_contents($META, json_encode(['w' => $view['w'] ?? 0, 'h' => $view['h'] ?? 0, 'ts' => microtime(true), 'tab' => $curTab, 'dpr' => $view['dpr'] ?? 1, 'state' => 'attached']));
                }
            } elseif (($j['id'] ?? 0) === 4 && isset($j['result']['windowId'])) {
                $winId = $j['result']['windowId'];
                ws_send($fp, json_encode(['id' => 5, 'method' => 'Browser.setWindowBounds', 'params' => ['windowId' => $winId, 'bounds' => ['windowState' => 'normal', 'width' => 1280, 'height' => 900]]]));
            } elseif (($j['method'] ?? '') === 'Page.screencastVisibilityChanged') {
                $vis = $j['params']['visible'] ?? true;
                if (!$vis && microtime(true) > $visFixAt) {
                    $visFixAt = microtime(true) + 5;
                    ws_send($fp, json_encode(['id' => 4, 'method' => 'Browser.getWindowForTarget']));
                }
            } elseif (($j['method'] ?? '') === 'Page.screencastFrame') {
                $p = $j['params'] ?? [];
                $data = $p['data'] ?? '';
                if ($data !== '' && !$err) {
                    file_put_contents($TMP, base64_decode($data));
                    @rename($TMP, $FRAME);
                    $lastWrite = microtime(true);
                    file_put_contents($META, json_encode(array_merge($view ?: [], ['w' => $view['w'] ?? 0, 'h' => $view['h'] ?? 0, 'ts' => $lastWrite, 'tab' => $curTab, 'dpr' => $view['dpr'] ?? 1, 'state' => 'streaming'])));
                }
                ws_send($fp, json_encode(['id' => 9, 'method' => 'Page.screencastFrameAck', 'params' => ['sessionId' => $p['sessionId'] ?? null]]));
            } elseif (isset($j['error'])) {
                $err = json_encode($j['error']);
                if (strpos($err, 'screencast') !== false || isset($j['id'])) {
                    if (($j['id'] ?? 0) === 3) { ws_close($fp); $fp = null; usleep(1200000); break 2; }
                }
            }
        }
        if ($fp === null) break;
    }
    if ($fp !== null && $view === null && $lastWrite === 0 && microtime(true) - 0 > 0) {
        static $t0 = 0; if ($t0 === 0) $t0 = microtime(true);
        if (microtime(true) - $t0 > 6) { ws_send($fp, json_encode(['id' => 13, 'method' => 'Page.startScreencast', 'params' => ['format' => 'jpeg', 'quality' => 72, 'maxWidth' => 1280, 'maxHeight' => 900, 'everyNthFrame' => 1]])); $t0 = microtime(true); }
    }
    if ($fp !== null && $lastWrite > 0 && microtime(true) - $lastWrite > 15) {
        ws_send($fp, json_encode(['id' => 15, 'method' => 'Page.startScreencast', 'params' => ['format' => 'jpeg', 'quality' => 72, 'maxWidth' => 1280, 'maxHeight' => 900, 'everyNthFrame' => 1]]));
    }
    usleep(80000);
}

function ws_close($fp): void { if (is_resource($fp)) @fclose($fp); }