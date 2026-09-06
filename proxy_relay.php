<?php
/**
 * proxy_relay.php - Local forward proxy (127.0.0.1) nhanh, multiplexed (event loop).
 * Duyet nguoc qua proxy that co credential (them Proxy-Authorization) de fix loi Chrome:
 * --proxy-server=http://user:pass@host khong gui Authorization cho subresource/CONNECT.
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
    stream_set_timeout($fp, 5);
    $target = ($uri !== '' && strpos($uri, '://') !== false) ? parse_url($uri, PHP_URL_HOST) : '';
    if ($target === '' || $target === null) $target = 'www.gstatic.com';
    $body = $uri !== '' ? $uri : "http://$target/generate_204";
    fwrite($fp, "CONNECT $target:443 HTTP/1.1\r\nHost: $target:443\r\n\r\n");
    $buf = '';
    $dl = microtime(true) + 5;
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
function addConn($c, $dead = 40): int
{
    global $conns, $c2id, $nextId;
    stream_set_blocking($c, false);
    stream_set_timeout($c, 30, 0);
    $id = $nextId++;
    $conns[$id] = ['c' => $c, 'u' => null, 'rbuf' => '', 'ubuf' => '', 'phase' => PH_REQ, 'dead' => microtime(true) + $dead, 'type' => ''];
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
        $rd[] = $ctx['c'];
        if ($ctx['u'] !== null) { $rd[] = $ctx['u']; }
        if ($ctx['phase'] === PH_UP && $ctx['u'] !== null) { $wr[] = $ctx['u']; }
    }
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
        if ($now > $ctx['dead']) { dropConn($id); continue; }

        if ($ctx['phase'] === PH_REQ) {
            if (!in_array($ctx['c'], $rd, true)) { unset($ctx); continue; }
            $d = fread($ctx['c'], 65536);
            if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
            $ctx['rbuf'] .= $d;
            if (strlen($ctx['rbuf']) > 1024 * 1024) { dropConn($id); unset($ctx); continue; }
            if (strpos($ctx['rbuf'], "\r\n\r\n") === false) { unset($ctx); continue; }
            // parse
            $idx = strpos($ctx['rbuf'], "\r\n\r\n");
            $head = substr($ctx['rbuf'], 0, $idx);
            $ctx['ubuf'] = substr($ctx['rbuf'], $idx + 4);
            $lines = explode("\r\n", $head);
            $first = array_shift($lines);
            $parts = array_pad(explode(' ', $first, 3), 3, '');
            $method = $parts[0];
            $target = $parts[1];
            $isConnect = strtoupper($method) === 'CONNECT';
            if (!openUp($ctx)) { @fwrite($ctx['c'], "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n"); dropConn($id); unset($ctx); continue; }
            if ($isConnect) {
                $ctx['type'] = 'CONNECT';
                $ctx['ct'] = "CONNECT $target HTTP/1.1\r\nHost: $target\r\n" . $GLOBALS['authLine'] . "\r\n";
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
                $ctx['reqOut'] = $out;
            }
            $ctx['phase'] = PH_UP;
            unset($ctx);
            continue;
        }

        if ($ctx['phase'] === PH_UP) {
            if ($ctx['u'] !== null && in_array($ctx['u'], $wr, true)) {
                // outbound connect hoan tat (writable)
                $send = $ctx['type'] === 'CONNECT' ? $ctx['ct'] : ($ctx['reqOut'] . $ctx['ubuf']);
                if (@fwrite($ctx['u'], $send) === false || strlen($send) === 0) { dropConn($id); unset($ctx); continue; }
                if ($ctx['type'] === 'CONNECT') {
                    // gui ca du lieu client band len (sau CONNECT head)
                    if ($ctx['ubuf'] !== '') @fwrite($ctx['u'], $ctx['ubuf']);
                    $ctx['phase'] = PH_CW;
                    $ctx['ubuf'] = '';
                } else {
                    $ctx['phase'] = PH_PUMP;
                }
                unset($ctx);
                continue;
            }
            unset($ctx);
            continue;
        }

        if ($ctx['phase'] === PH_CW) {
            if ($ctx['u'] !== null && in_array($ctx['u'], $rd, true)) {
                $d = fread($ctx['u'], 8192);
                if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
                $ctx['ubuf'] .= $d;
                if (strpos($ctx['ubuf'], "\r\n\r\n") === false) {
                    if (strlen($ctx['ubuf']) > 65536) { dropConn($id); unset($ctx); continue; }
                    unset($ctx);
                    continue;
                }
                if (stripos($ctx['ubuf'], ' 200 ') === false) {
                    @fwrite($ctx['c'], "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                    dropConn($id); unset($ctx);
                    continue;
                }
                $i = strpos($ctx['ubuf'], "\r\n\r\n") + 4;
                $left = substr($ctx['ubuf'], $i);
                @fwrite($ctx['c'], "HTTP/1.1 200 Connection Established\r\n\r\n");
                if ($left !== '') {
                    if (@fwrite($ctx['c'], $left) === false) { dropConn($id); unset($ctx); continue; }
                }
                $ctx['ubuf'] = '';
                $ctx['phase'] = PH_PUMP;
                unset($ctx);
                continue;
            }
            unset($ctx);
            continue;
        }

        // PH_PUMP
        $any = false;
        if (in_array($ctx['c'], $rd, true)) {
            $d = fread($ctx['c'], 65536);
            if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
            if ($ctx['u'] !== null) { if (@fwrite($ctx['u'], $d) === false) { dropConn($id); unset($ctx); continue; } }
            $any = true;
        }
        if ($ctx['u'] !== null && in_array($ctx['u'], $rd, true)) {
            $d = fread($ctx['u'], 65536);
            if ($d === false || $d === '') { dropConn($id); unset($ctx); continue; }
            if (@fwrite($ctx['c'], $d) === false) { dropConn($id); unset($ctx); continue; }
            $any = true;
        }
        unset($ctx);
    }
}