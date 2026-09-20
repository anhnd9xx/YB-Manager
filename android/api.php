<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

$FR = __DIR__ . '/frames';
if (!is_dir($FR)) mkdir($FR, 0777, true);
define('ANDROID_FRAMES', $FR);

function profile_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function strip_prefix(string $title, string $name): string
{
    $n = trim($name);
    if ($n !== '' && mb_strpos($title, $n) === 0) {
        $rest = mb_substr($title, mb_strlen($n));
        if (mb_strpos($rest, '|') === 0) $rest = ltrim($rest, '| ');
        return $rest;
    }
    return $title;
}

function process_alive(int $pid): bool
{
    if ($pid <= 0) return false;
    $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
    return is_string($out) && strpos($out, (string)$pid) !== false;
}

function frame_dir(int $id): string
{
    return ANDROID_FRAMES . '/ch' . $id;
}

function daemon_running(int $id): bool
{
    $f = frame_dir($id) . '/daemon.pid';
    if (!is_file($f)) return false;
    return process_alive((int)trim((string)file_get_contents($f)));
}

function spawn_daemon(int $id, int $port): bool
{
    if (daemon_running($id)) return true;
    @mkdir(frame_dir($id), 0777, true);
    $php = 'C:\xampp\php\php.exe';
    $script = __DIR__ . '\scd.php';
    $log = frame_dir($id) . '\daemon.log';
    $pf = frame_dir($id) . '\daemon.pid';
    $proc = proc_open([$php, $script, (string)$port, (string)$id], [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']
    ], $pipes, null, null, ['bypass_shell' => true, 'create_flags' => 0x08000000]);
    if (!is_resource($proc)) return false;
    $st = proc_get_status($proc);
    if (!empty($st['pid'])) file_put_contents($pf, (string)$st['pid']);
    fclose($pipes[0]);
    $dl = microtime(true) + 4;
    while (microtime(true) < $dl) {
        usleep(500000);
        if (daemon_running($id)) return true;
    }
    return false;
}

function target_ws(int $port, string $tabId): ?string
{
    foreach (cdp_page_targets($port) as $t) {
        if ($t['id'] === $tabId) return $t['webSocketDebuggerUrl'];
    }
    return null;
}

function cdp_cmd_once(?string $wsUrl, array $cmd): bool
{
    if ($wsUrl === null) return false;
    $u = parse_url($wsUrl);
    if (!$u) return false;
    $pp = (int)($u['port'] ?? 0);
    return cdp_ws_send($pp, $wsUrl, json_encode($cmd));
}

$act = $_GET['act'] ?? $_POST['act'] ?? '';

if ($act === 'list') {
    $channels = [];
    foreach (db()->query('SELECT id, name, status, debug_port FROM profiles ORDER BY id') as $p) {
        $item = ['id' => (int)$p['id'], 'name' => $p['name'], 'status' => $p['status'], 'port' => (int)$p['debug_port'], 'tabs' => []];
        if ($p['status'] === 'running' && cdp_reachable((int)$p['debug_port'])) {
            $meta = ['w' => 0, 'h' => 0, 'ts' => 0, 'tab' => null];
            $mf = frame_dir((int)$p['id']) . '/meta.json';
            if (is_file($mf)) {
                $m = json_decode((string)file_get_contents($mf), true);
                if (is_array($m)) $meta = array_merge($meta, $m);
            }
            $item['meta'] = $meta;
            foreach (cdp_page_targets((int)$p['debug_port']) as $t) {
                $item['tabs'][] = ['id' => $t['id'], 'title' => strip_prefix($t['title'], $p['name']), 'url' => $t['url']];
            }
        }
        $channels[] = $item;
    }
    json_out(['channels' => $channels]);
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$tab = (string)($_POST['tab'] ?? '');
$p = profile_by_id($id);
if (!$p) json_out(['error' => 'no profile'], 404);
$port = (int)$p['debug_port'];

if ($act === 'stream') {
    $ok = spawn_daemon($id, $port);
    json_out(['ok' => $ok]);
}

if ($act === 'select') {
    if ($tab === '') json_out(['error' => 'no tab']);
    spawn_daemon($id, $port);
    file_put_contents(frame_dir($id) . '/ctrl.json', json_encode(['tab' => $tab, 'ts' => microtime(true)]));
    $ws = target_ws($port, $tab);
    if ($ws !== null) cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.bringToFront']);
    json_out(['ok' => true]);
}

function need_tab(string $tab): string
{
    global $port, $p;
    if ($tab === '') {
        $tl = cdp_page_targets($port);
        if (empty($tl)) json_out(['error' => 'no tabs'], 404);
        file_put_contents(frame_dir($p['id']) . '/ctrl.json', json_encode(['tab' => $tl[0]['id'], 'ts' => microtime(true)]));
        return $tl[0]['id'];
    }
    return $tab;
}

if ($act === 'nav') {
    $url = (string)($_POST['url'] ?? '');
    $ws = target_ws($port, need_tab($tab));
    $ok = $ws !== null && cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.navigate', 'params' => ['url' => $url]]);
    json_out(['ok' => $ok]);
}

if ($act === 'reload') {
    $ws = target_ws($port, need_tab($tab));
    $ok = $ws !== null && cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.reload']);
    json_out(['ok' => $ok]);
}

if ($act === 'back') {
    $ws = target_ws($port, need_tab($tab));
    json_out(['ok' => $ws !== null && cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.goBack'])]);
}

if ($act === 'forward') {
    $ws = target_ws($port, need_tab($tab));
    json_out(['ok' => $ws !== null && cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.goForward'])]);
}

if ($act === 'newtab') {
    $url = (string)($_POST['url'] ?? 'https://www.youtube.com');
    $r = cdp_http($port, 'PUT', '/json/new?' . urlencode($url), 3000);
    $t = $r !== null ? json_decode($r['body'], true) : null;
    $newId = is_array($t) && !empty($t['id']) ? $t['id'] : null;
    if ($newId !== null) {
        file_put_contents(frame_dir($id) . '/ctrl.json', json_encode(['tab' => $newId, 'ts' => microtime(true)]));
        $ws = target_ws($port, $newId);
        if ($ws !== null) cdp_cmd_once($ws, ['id' => 1, 'method' => 'Page.bringToFront']);
    }
    json_out(['ok' => $newId !== null, 'tab' => $newId]);
}

if ($act === 'closetab') {
    if ($tab === '') json_out(['error' => 'no tab']);
    cdp_http($port, 'GET', '/json/close/' . $tab, 1500);
    // Neu dong dung tab daemon dang theo -> xoa ctrl de daemon tu bam tab con lai
    $cf = frame_dir($id) . '/ctrl.json';
    $c = is_file($cf) ? json_decode((string)file_get_contents($cf), true) : null;
    if (is_array($c) && ($c['tab'] ?? '') === $tab) @unlink($cf);
    json_out(['ok' => true]);
}

if ($act === 'tap') {
    $x = (float)($_POST['x'] ?? 0); $y = (float)($_POST['y'] ?? 0);
    $type = (string)($_POST['type'] ?? '');
    $ws = target_ws($port, need_tab($tab));
    if ($ws === null) json_out(['error' => 'no target'], 404);
    $u = parse_url($ws);
    $pp = (int)($u['port'] ?? 0);
    if ($type === 'start') {
        cdp_ws_batch($pp, $ws, [json_encode(['id' => 1, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchStart', 'touchPoints' => [['x' => $x, 'y' => $y, 'id' => 1, 'radiusX' => 2, 'radiusY' => 2]]]])], 0);
    } elseif ($type === 'end') {
        cdp_ws_batch($pp, $ws, [json_encode(['id' => 1, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchEnd', 'touchPoints' => []]])], 0);
    } else {
        cdp_ws_batch($pp, $ws, [
            json_encode(['id' => 1, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchStart', 'touchPoints' => [['x' => $x, 'y' => $y, 'id' => 1, 'radiusX' => 2, 'radiusY' => 2]]]]),
            json_encode(['id' => 2, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchEnd', 'touchPoints' => []]]),
        ], 0);
    }
    json_out(['ok' => true]);
}

if ($act === 'scroll') {
    $dx = (float)($_POST['dx'] ?? 0); $dy = (float)($_POST['dy'] ?? 0);
    $x = (float)($_POST['x'] ?? 0); $y = (float)($_POST['y'] ?? 0);
    $ws = target_ws($port, need_tab($tab));
    $ok = $ws !== null && cdp_cmd_once($ws, ['id' => 1, 'method' => 'Input.dispatchMouseEvent', 'params' => ['type' => 'mouseWheel', 'x' => $x, 'y' => $y, 'deltaX' => $dx, 'deltaY' => $dy]]);
    json_out(['ok' => $ok]);
}

if ($act === 'keys') {
    $text = (string)($_POST['text'] ?? '');
    $enter = (bool)($_POST['enter'] ?? false);
    $ws = target_ws($port, need_tab($tab));
    if ($ws === null) json_out(['error' => 'no target'], 404);
    $u = parse_url($ws);
    $pp = (int)($u['port'] ?? 0);
    $cmds = [];
    if ($text !== '') $cmds[] = json_encode(['id' => 1, 'method' => 'Input.insertText', 'params' => ['text' => $text]]);
    if ($enter) {
        $cmds[] = json_encode(['id' => 2, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyDown', 'key' => 'Enter', 'code' => 'Enter', 'windowsVirtualKeyCode' => 13, 'nativeVirtualKeyCode' => 13]]);
        $cmds[] = json_encode(['id' => 3, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => 'Enter', 'code' => 'Enter', 'windowsVirtualKeyCode' => 13, 'nativeVirtualKeyCode' => 13]]);
    }
    if ($cmds === []) json_out(['error' => 'empty']);
    cdp_ws_batch($pp, $ws, $cmds, 0);
    json_out(['ok' => true]);
}

if ($act === 'mouse') {
    $kind = (string)($_POST['kind'] ?? 'click');
    $x = (float)($_POST['x'] ?? 0); $y = (float)($_POST['y'] ?? 0);
    $ws = target_ws($port, need_tab($tab));
    if ($ws === null) json_out(['error' => 'no target'], 404);
    $u = parse_url($ws);
    $pp = (int)($u['port'] ?? 0);
    $btn = $kind === 'right' ? 'right' : 'left';
    $count = $kind === 'dbl' ? 2 : 1;
    if ($kind === 'right' || $kind === 'dbl') {
        cdp_ws_batch($pp, $ws, [
            json_encode(['id' => 1, 'method' => 'Input.dispatchMouseEvent', 'params' => ['type' => 'mouseMoved', 'x' => $x, 'y' => $y, 'button' => 'none']]),
            json_encode(['id' => 2, 'method' => 'Input.dispatchMouseEvent', 'params' => ['type' => 'mousePressed', 'x' => $x, 'y' => $y, 'button' => $btn, 'buttons' => $btn === 'right' ? 2 : 1, 'clickCount' => $count]]),
            json_encode(['id' => 3, 'method' => 'Input.dispatchMouseEvent', 'params' => ['type' => 'mouseReleased', 'x' => $x, 'y' => $y, 'button' => $btn, 'buttons' => 0, 'clickCount' => $count]]),
        ], 0);
    } else {
        cdp_ws_batch($pp, $ws, [
            json_encode(['id' => 1, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchStart', 'touchPoints' => [['x' => $x, 'y' => $y, 'id' => 1, 'radiusX' => 2, 'radiusY' => 2]]]]),
            json_encode(['id' => 2, 'method' => 'Input.dispatchTouchEvent', 'params' => ['type' => 'touchEnd', 'touchPoints' => []]]),
        ], 0);
    }
    json_out(['ok' => true]);
}

if ($act === 'raw') {
    $key = (string)($_POST['key'] ?? '');
    $ws = target_ws($port, need_tab($tab));
    if ($ws === null) json_out(['error' => 'no target'], 404);
    $u = parse_url($ws);
    $pp = (int)($u['port'] ?? 0);
    $defs = [
        'backspace' => ['key' => 'Backspace', 'code' => 'Backspace', 'vk' => 8],
        'delete'    => ['key' => 'Delete', 'code' => 'Delete', 'vk' => 46],
        'enter'     => ['key' => 'Enter', 'code' => 'Enter', 'vk' => 13],
        'tab'       => ['key' => 'Tab', 'code' => 'Tab', 'vk' => 9],
        'escape'    => ['key' => 'Escape', 'code' => 'Escape', 'vk' => 27],
        'home'      => ['key' => 'Home', 'code' => 'Home', 'vk' => 36],
        'end'       => ['key' => 'End', 'code' => 'End', 'vk' => 35],
        'pageup'    => ['key' => 'PageUp', 'code' => 'PageUp', 'vk' => 33],
        'pagedown'  => ['key' => 'PageDown', 'code' => 'PageDown', 'vk' => 34],
        'up'        => ['key' => 'ArrowUp', 'code' => 'ArrowUp', 'vk' => 38],
        'down'      => ['key' => 'ArrowDown', 'code' => 'ArrowDown', 'vk' => 40],
        'left'      => ['key' => 'ArrowLeft', 'code' => 'ArrowLeft', 'vk' => 37],
        'right'     => ['key' => 'ArrowRight', 'code' => 'ArrowRight', 'vk' => 39],
        'f5'        => ['key' => 'F5', 'code' => 'F5', 'vk' => 116],
    ];
    $mod = 0;
    $sym = $key;
    if (preg_match('/^ctrl\+(.+)$/i', $key, $m)) {
        $mod = 2;
        $sym = strtolower($m[1]);
    }
    $d = $defs[$sym] ?? null;
    if ($d !== null) {
        $cmds = [];
        if ($mod & 2) $cmds[] = json_encode(['id' => 1, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => 'Control', 'code' => 'ControlLeft', 'modifiers' => 2, 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]);
        $cmds[] = json_encode(['id' => 2, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => $mod ? strtoupper($d['key']) : $d['key'], 'code' => $d['code'], 'modifiers' => $mod, 'windowsVirtualKeyCode' => $d['vk'], 'nativeVirtualKeyCode' => $d['vk']]]);
        if ($mod & 2) {
            $cmds[] = json_encode(['id' => 3, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'char', 'key' => strtolower($d['key']), 'code' => $d['code'], 'modifiers' => 2, 'windowsVirtualKeyCode' => $d['vk'], 'nativeVirtualKeyCode' => $d['vk']]]);
            $cmds[] = json_encode(['id' => 4, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => strtoupper($d['key']), 'code' => $d['code'], 'modifiers' => 2, 'windowsVirtualKeyCode' => $d['vk'], 'nativeVirtualKeyCode' => $d['vk']]]);
            $cmds[] = json_encode(['id' => 5, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => 'Control', 'code' => 'ControlLeft', 'modifiers' => 0, 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]);
        } else {
            $cmds[] = json_encode(['id' => 6, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => $d['key'], 'code' => $d['code'], 'modifiers' => 0, 'windowsVirtualKeyCode' => $d['vk'], 'nativeVirtualKeyCode' => $d['vk']]]);
        }
        cdp_ws_batch($pp, $ws, $cmds, 0);
        json_out(['ok' => true]);
    }
    if ($mod & 2 && preg_match('/^[a-z]$/i', $sym)) {
        $c = strtoupper($sym);
        $code = 'Key' . $c;
        $vk = ord($c);
        cdp_ws_batch($pp, $ws, [
            json_encode(['id' => 1, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => 'Control', 'code' => 'ControlLeft', 'modifiers' => 2, 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]),
            json_encode(['id' => 2, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => $c, 'code' => $code, 'modifiers' => 2, 'windowsVirtualKeyCode' => $vk, 'nativeVirtualKeyCode' => $vk]]),
            json_encode(['id' => 3, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'char', 'key' => strtolower($c), 'code' => $code, 'modifiers' => 2, 'windowsVirtualKeyCode' => $vk, 'nativeVirtualKeyCode' => $vk]]),
            json_encode(['id' => 4, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => $c, 'code' => $code, 'modifiers' => 2, 'windowsVirtualKeyCode' => $vk, 'nativeVirtualKeyCode' => $vk]]),
            json_encode(['id' => 5, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => 'Control', 'code' => 'ControlLeft', 'modifiers' => 0, 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]),
        ], 0);
        json_out(['ok' => true]);
    }
    json_out(['error' => 'key khong ho tro'], 400);
}

json_out(['error' => 'unknown act'], 400);