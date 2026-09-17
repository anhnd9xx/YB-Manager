<?php
/**
 * proxy_relay.php - Local forward proxy (127.0.0.1) nhanh, multiplexed (event loop).
 * Duyet nguoc qua proxy that co credential (them Proxy-Authorization) de fix loi Chrome:
 * --proxy-server=http://user:pass@host khong gui Authorization cho subresource/CONNECT.
 *
 * FIX (2024-06): Ghi co bo-dem 2 chieu - fwrite() non-blocking co the ghi IT HON du lieu
 * gui vao; truoc day cuong bo- qua phan thua (bi rot mat -> TLS/HTTP hong, kenh vua cham
 * vua dut). Gio ghi du (pending buffer), cho den khi socket writable moi flush tiep.
 *
 * Su dung: php -f proxy_relay.php <listen_port> <upstream_host> <upstream_port> [user] [pass]
 * Khi CLA=__HEALTH__ kiem tra 1 vong <port> co phuc vu duoc khong (dung cho app check).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$__HEALTH_CHECK__ = (($argv[1] ?? '') === '__HEALTH__');
if ($__HEALTH_CHECK__) {
    $port = (int)($argv[2] ?? 0);
    $uri = (string)($argv[3] ?? '');
    $fp = @stream_socket_client("tcp://127.0.0.1:$port", $ec, $es, 3);
    if (!$fp) { fwrite(STDOUT, "DOWN no-listen\n"); exit(2); }
    stream_set_timeout($fp, 15);
    $target = ($uri !== '' && strpos($uri, '://') !== false) ? parse_url($uri, PHP_URL_HOST) : '';
    if ($target === '' || $target === null) $target = 'www.gstatic.com';
    fwrite($fp, "CONNECT $target:443 HTTP/1.1\r\nHost: $target:443\r\n\r\n");
    $buf = '';
    $dl = microtime(true) + 15;
    while (strpos($buf, "\r\n") === false && microtime(true) < $dl) {
        $c = fread($fp, 4096);
        if ($c === '' || $c === false) break;
        $buf .= $c;
    }
    fclose($fp);
    if (stripos($buf, ' 200') !== false) { fwrite(STDOUT, "OK\n"); exit(0); }
    fwrite(STDOUT, "DOWN " . trim($buf) . "\n");
    exit(3);
}

$listen = (int)($argv[1] ?? 0);
$uh = (string)($argv[2] ?? '');
$up = (int)($argv[3] ?? 0);
$user = (string)($argv[4] ?? '');
$pass = (string)($argv[5] ?? '');
if ($listen < 1 || $uh === '' || $up < 1) exit("usage: proxy_relay.php <listen> <host> <port> [user] [pass]\n");

$authLine = $user !== '' ? 'Proxy-Authorization: Basic ' . base64_encode($user . ':' . $pass) . "\r\n" : '';

$server = @stream_socket_server("tcp://127.0.0.1:$listen", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "relay: cannot listen 127.0.0.1:$listen -> $errstr\n");
    exit(1);
}
stream_set_blocking($server, false);

const PH_REQ = 1;   // doc request head tu client
const PH_UP = 2;    // dang ket noi upstream (async)
const PH_CW = 3;    // cho CONNECT response tu upstream
const PH_PUMP = 4;  // chuyen tiep du lieu 2 chieu

$conns = [];
$c2id = [];
function addConn($c, $dead = 300): int
{
    global $conns, $c2id, $nextId;
    stream_set_blocking($c, false);
    stream_set_timeout($c, 30, 0);
    $id = $nextId++;
    $conns[$id] = ['c' => $c, 'u' => null, 'rbuf' => '', 'ubuf' => '', 'wb_c' => '', 'wb_u' => '',
        'phase' => PH_REQ, 'type' => '', 'reqOut' => '', 'dead' => microtime(true) + $dead,
        'close_c' => false, 'close_u' => false];
    $c2id[(int)$c] = $id;
    return $id;
}
$nextId = 1;

function dropConn(int $id): void
{
    global $conns, $c2id;
    if (!isset($conns[$id])) return;
    $ctx = $conns[$id];
    @fclose($ctx['c']);
    if ($ctx['u'] !== null) @fclose($ctx['u']);
    unset($c2id[(int)$ctx['c']]);
    unset($conns[$id]);
}

function openUp(array &$ctx): bool
{
    $u = @stream_socket_client("tcp://" . $GLOBALS['uh'] . ":" . $GLOBALS['up'], $ec, $es, 10, STREAM_CLIENT_ASYNC_CONNECT);
    if (!$u) return false;
    stream_set_blocking($u, false);
    stream_set_timeout($u, 30, 0);
    $ctx['u'] = $u;
    return true;
}

while (true) {
    $rd = [$server];
    $wr = [];
    foreach ($conns as $ctx) {
        if (!$ctx['close_c']) $rd[] = $ctx['c'];
        if ($ctx['u'] !== null && !$ctx['close_u']) $rd[] = $ctx['u'];
        if ($ctx['wb_c'] !== '') $wr[] = $ctx['c'];
        if ($ctx['u'] !== null && $ctx['wb_u'] !== '') $wr[] = $ctx['u'];
    }
    unset($ctx);
    $n = @stream_select($rd, $wr, $ex, 1);
    if ($n === false) { usleep(50000); continue; }

    if ($n > 0 && in_array($server, $rd, true)) {
        $c = @stream_socket_accept($server, -1);
        if ($c) { addConn($c); }
        if ($n === 1 && count($rd) === 1) continue;
    }

    $ids = array_keys($conns);
    foreach ($ids as $id) {
        if (!isset($conns[$id])) continue;
        $ctx =& $conns[$id];
        $now = microtime(true);
        if ($now > $ctx['dead']) { dropConn($id); unset($ctx); continue; }

        $cR = !$ctx['close_c'] && in_array($ctx['c'], $rd, true);
        $cW = in_array($ctx['c'], $wr, true);
        $uR = $ctx['u'] !== null && !$ctx['close_u'] && in_array($ctx['u'], $rd, true);
        $uW = $ctx['u'] !== null && in_array($ctx['u'], $wr, true);

        if ($ctx['phase'] === PH_REQ) {
            if (!$cR) { unset($ctx); continue; }
            $d = @fread($ctx['c'], 65536);
            if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
            $ctx['rbuf'] .= $d;
            $ctx['dead'] = microtime(true) + 300;
            if (strlen($ctx['rbuf']) > 1024 * 1024) { dropConn($id); unset($ctx); continue; }
            if (strpos($ctx['rbuf'], "\r\n\r\n") === false) { unset($ctx); continue; }
            // parse
            $idx = strpos($ctx['rbuf'], "\r\n\r\n");
            $head = substr($ctx['rbuf'], 0, $idx);
            $ctx['rbuf'] = substr($ctx['rbuf'], $idx + 4); // body du thua theo sau head
            $lines = explode("\r\n", $head);
            $first = array_shift($lines);
            $parts = array_pad(explode(' ', $first, 3), 3, '');
            $method = $parts[0];
            $target = $parts[1];
            $isConnect = strtoupper($method) === 'CONNECT';
            if (!openUp($ctx)) {
                $ctx['wb_c'] = "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n";
                $ctx['close_u'] = true;
                $ctx['phase'] = PH_PUMP;
                unset($ctx); continue;
            }
            if ($isConnect) {
                $ctx['type'] = 'CONNECT';
                $ctx['wb_u'] = "CONNECT $target HTTP/1.1\r\nHost: $target\r\n" . $GLOBALS['authLine'] . "\r\n";
            } else {
                $ctx['type'] = 'HTTP';
                $ver = $parts[2] !== '' ? $parts[2] : 'HTTP/1.1';
                $out = "$method $target $ver\r\n";
                foreach ($lines as $l) {
                    if ($l === '' || strncasecmp($l, 'Proxy-Connection:', 17) === 0) continue;
                    $out .= $l . "\r\n";
                }
                if ($GLOBALS['authLine'] !== '') $out .= $GLOBALS['authLine'];
                $out .= "Connection: close\r\n\r\n";
                $ctx['wb_u'] = $out . $ctx['rbuf'];
                $ctx['rbuf'] = '';
            }
            $ctx['phase'] = PH_UP;
            unset($ctx);
            continue;
        }

        if ($ctx['phase'] === PH_UP) {
            if ($uW && $ctx['wb_u'] !== '') {
                $w = @fwrite($ctx['u'], $ctx['wb_u']);
                if ($w === false) { dropConn($id); unset($ctx); continue; }
                if ($w > 0) $ctx['dead'] = microtime(true) + 300;
                $ctx['wb_u'] = substr($ctx['wb_u'], $w);
            }
            if ($ctx['wb_u'] === '') {
                if ($ctx['type'] === 'CONNECT') $ctx['phase'] = PH_CW;
                else $ctx['phase'] = PH_PUMP;
            }
            unset($ctx);
            continue;
        }

        if ($ctx['phase'] === PH_CW) {
            if ($uR) {
                $d = @fread($ctx['u'], 8192);
                if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
                $ctx['ubuf'] .= $d;
                $ctx['dead'] = microtime(true) + 300;
                if (strlen($ctx['ubuf']) > 65536) { dropConn($id); unset($ctx); continue; }
                if (strpos($ctx['ubuf'], "\r\n\r\n") === false) { unset($ctx); continue; }
                if (stripos($ctx['ubuf'], ' 200 ') === false) {
                    $ctx['wb_c'] = "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n";
                    $ctx['close_u'] = true;
                    $ctx['phase'] = PH_PUMP;
                    unset($ctx);
                    continue;
                }
                $i = strpos($ctx['ubuf'], "\r\n\r\n") + 4;
                $left = substr($ctx['ubuf'], $i);
                $ctx['ubuf'] = '';
                $ctx['wb_c'] = "HTTP/1.1 200 Connection Established\r\n\r\n" . $left;
                $ctx['phase'] = PH_PUMP;
            }
            unset($ctx);
            continue;
        }

        // PH_PUMP — flush data ra ngoai TRUOC khi xu ly EOF cung luc
        // (fix: upstream gui EOF ngay khi het du lieu, neu dropConn ngay se mat phan
        //  du lieu con trong wb_c/kieu "transfer closed with N bytes remaining").
        if ($cW && $ctx['wb_c'] !== '') {
            $w = @fwrite($ctx['c'], $ctx['wb_c']);
            if ($w === false) { dropConn($id); unset($ctx); continue; }
            if ($w > 0) $ctx['dead'] = microtime(true) + 300;
            $ctx['wb_c'] = substr($ctx['wb_c'], $w);
        }
        if ($uW && $ctx['wb_u'] !== '') {
            $w = @fwrite($ctx['u'], $ctx['wb_u']);
            if ($w === false) { dropConn($id); unset($ctx); continue; }
            if ($w > 0) $ctx['dead'] = microtime(true) + 300;
            $ctx['wb_u'] = substr($ctx['wb_u'], $w);
        }
        // doc tu client -> chuyen lên upstream
        if ($cR && $ctx['u'] !== null && !$ctx['close_u']) {
            $d = @fread($ctx['c'], 65536);
            if ($d === false || $d === '') { $ctx['close_c'] = true; }
            else {
                $ctx['wb_u'] .= $d;
                $ctx['dead'] = microtime(true) + 300;
            }
        }
        // doc tu upstream -> chuyen xuong client
        if ($uR) {
            $d = @fread($ctx['u'], 65536);
            if ($d === false || $d === '') { $ctx['close_u'] = true; }
            else {
                $ctx['wb_c'] .= $d;
                $ctx['dead'] = microtime(true) + 300;
            }
        }
        // kap neu wb qua lon
        if (strlen($ctx['wb_c']) > 2 * 1024 * 1024 || strlen($ctx['wb_u']) > 2 * 1024 * 1024) {
            dropConn($id); unset($ctx); continue;
        }
        // check dong: chi dong khi DA flush het wb sang huong con lai
        $allWbFlushed = ($ctx['wb_c'] === '') && ($ctx['wb_u'] === '');
        if ($ctx['close_c'] && $allWbFlushed) { dropConn($id); unset($ctx); continue; }
        if ($ctx['close_u'] && $ctx['wb_c'] === '' && $ctx['wb_u'] === '') {
            if ($ctx['phase'] === PH_CW) { dropConn($id); unset($ctx); continue; }
            // upstream het du lieu va da flush het -> dong (connection close ve phia proxy)
            @fclose($ctx['u']); $ctx['u'] = null; $ctx['close_u'] = true;
            dropConn($id); unset($ctx); continue;
        }
        unset($ctx);
    }
}