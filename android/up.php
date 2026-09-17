<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

$UPD = __DIR__ . '/_uploads';
if (!is_dir($UPD)) mkdir($UPD, 0777, true);

$id = (int)($_POST['id'] ?? 0);
$tab = (string)($_POST['tab'] ?? '');
if (!$id || empty($_FILES['file']['tmp_name'])) json_out(['error' => 'missing id/file'], 400);

$st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) json_out(['error' => 'no profile'], 404);
$port = (int)$p['debug_port'];

$name = $_FILES['file']['name'] ?? 'file';
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
$dest = $UPD . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '_' . $name;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) json_out(['error' => 'save failed'], 500);

$ws = null;
foreach (cdp_page_targets($port) as $t) {
    if ($t['id'] === $tab) { $ws = $t['webSocketDebuggerUrl']; break; }
}
if (!$ws) json_out(['error' => 'tab not found']);
$u = parse_url($ws);
$pp = (int)($u['port'] ?? 0);

function ws_rpc(int $port, string $wsUrl, array $cmds, int $wantId, int $timeout = 4): ?array
{
    $u = parse_url($wsUrl);
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
    if (!$fp) return null;
    $path = $u['path'] ?? '/';
    if (!empty($u['query'])) $path .= '?' . $u['query'];
    fwrite($fp, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $hdr = ''; $dl = microtime(true) + 2;
    while (strpos($hdr, "\r\n\r\n") === false && microtime(true) < $dl) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $hdr .= $c; }
    if (strpos($hdr, ' 101 ') === false) { fclose($fp); return null; }
    $send = function (string $payload) use ($fp): void {
        $len = strlen($payload);
        if ($len < 126) $h = chr(0x81) . chr(0x80 | $len);
        elseif ($len < 65536) $h = chr(0x81) . chr(0x80 | 126) . pack('n', $len);
        else $h = chr(0x81) . chr(0x80 | 127) . pack('J', $len);
        $mask = random_bytes(4); $b = '';
        for ($i = 0; $i < $len; $i++) $b .= $payload[$i] ^ $mask[$i % 4];
        fwrite($fp, $h . $mask . $b);
    };
    foreach ($cmds as $payload) { $send($payload); usleep(150000); }
    $buf = ''; $dl = microtime(true) + $timeout;
    while (microtime(true) < $dl) {
        $r = [$fp]; $w = null; $e = null;
        if (@stream_select($r, $w, $e, 0, 400000) === 1) {
            $d = fread($fp, 65536);
            if ($d === false || $d === '') break;
            $buf .= $d;
            $pos = strrpos($buf, '"id":' . $wantId);
            if ($pos !== false && strpos(substr($buf, $pos), '}') !== false) break;
        }
    }
    fclose($fp);
    $pos = strrpos($buf, '"id":' . $wantId);
    if ($pos === false) return null;
    return json_decode('{' . substr($buf, $pos), true);
}

$r = ws_rpc($pp, $ws, [
    json_encode(['id' => 1, 'method' => 'DOM.getDocument', 'params' => ['depth' => -1]]),
    json_encode(['id' => 2, 'method' => 'DOM.querySelector', 'params' => ['nodeId' => 1, 'selector' => 'input[type="file"]']]),
], 2);
$nodeId = $r['result']['result']['nodeId'] ?? null;
if (!$nodeId || $nodeId <= 0) json_out(['ok' => false, 'info' => 'khong co input file tren trang']);
$r2 = ws_rpc($pp, $ws, [
    json_encode(['id' => 3, 'method' => 'DOM.setFileInputFiles', 'params' => ['nodeId' => $nodeId, 'files' => [$dest]]]),
], 3);
json_out(['ok' => $r2 !== null && !isset($r2['error'])]);