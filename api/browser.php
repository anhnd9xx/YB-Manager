<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? 'open';
$profileId = (int)($_GET['id'] ?? 0);

try {
    $stmt = db()->prepare(
        'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                pr.username AS proxy_user, pr.password AS proxy_pass
         FROM profiles p
         LEFT JOIN proxies pr ON pr.id = p.proxy_id
         WHERE p.id = ?'
    );
    $stmt->execute([$profileId]);
    $p = $stmt->fetch();

    if (!$p) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);

    if (!file_exists(chrome_path())) {
        json_out(['ok' => false, 'message' => 'Khong tim thay Chrome: ' . chrome_path()], 500);
    }

    switch ($action) {
        case 'open':
            open_chrome($p);
            break;

        case 'open_studio':
            open_chrome($p, 'https://studio.youtube.com');
            break;

        case 'open_dashboard':
            open_chrome($p, 'https://studio.youtube.com/channel/' . $p['channel_handle']);
            break;

        case 'close':
            kill_chrome($p);
            break;

        case 'status':
            $running = is_chrome_running($p);
            db()->prepare('UPDATE profiles SET status=? WHERE id=?')
                ->execute([$running ? 'running' : 'stopped', $p['id']]);
            json_out(['ok' => true, 'running' => $running]);
            break;

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}

function get_debug_port(array $p): ?int
{
    static $used = [];
    $base = 9200;
    if (!empty($p['debug_port'])) {
        $port = (int)$p['debug_port'];
        if (!isset($used[$port])) {
            $used[$port] = true;
            return $port;
        }
    }
    for ($i = 0; $i < 100; $i++) {
        $port = $base + (int)$p['id'] + $i;
        if (isset($used[$port])) continue;
        if (cdp_reachable($port)) continue; // port dang bi instance khac chiem -> bo qua
        $used[$port] = true;
        db()->prepare('UPDATE profiles SET debug_port=? WHERE id=?')->execute([$port, (int)$p['id']]);
        log_action((int)$p['id'], 'assign_port', 'Debug port ' . $port);
        return $port;
    }
    return null;
}

function open_chrome(array $p, string $url = 'https://www.youtube.com'): void
{
    // Neu Chrome dang chay VA CDP con phan hoi VA config (proxy/user-agent) dung nhu DB
    // -> tra ve ngay, khong dong/restart (tranh mat tab khi user bam "Mo" lai).
    // Neu config da doi (gan proxy moi, doi UA...) -> restart de ap dung config moi.
    if (is_chrome_running($p)) {
        if (!empty($p['debug_port']) && cdp_reachable((int)$p['debug_port'])) {
            if (chrome_cmdline_matches_config($p)) {
                json_out(['ok' => true, 'message' => 'Kenh "' . $p['name'] . '" dang chay (port ' . $p['debug_port'] . ')', 'port' => (int)$p['debug_port']]);
                return;
            }
            // Config khac -> dong de relaunch voi proxy/ua moi
            kill_chrome_quiet($p);
        } else {
            // Instance cu chay sai port/khong CDP -> dong de dam bao instance moi chay dung debug port
            kill_chrome_quiet($p);
        }
    }

    // Chi mo khi proxy con song
    if (!empty($p['proxy_host'])) {
        $proxyTest = [
            'host'     => $p['proxy_host'],
            'port'     => (int)$p['proxy_port'],
            'protocol' => $p['proxy_protocol'] ?? 'http',
            'username' => $p['proxy_user'],
            'password' => $p['proxy_pass'],
        ];
        if (!test_proxy($proxyTest)) {
            db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
                ->execute(['dead', (int)$p['proxy_id']]);
            json_out([
                'ok'         => false,
                'proxy_dead' => true,
                'message'    => 'Proxy cua kenh da chet (' . $p['proxy_host'] . ':' . $p['proxy_port'] . '). '
                    . 'Gan proxy khac hoac gõ proxy de mo kenh.',
            ], 409);
        }
        db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
            ->execute(['alive', (int)$p['proxy_id']]);
    }

    // Bat buoc co debug port truoc khi launch
    $port = get_debug_port($p);
    if (!$port) {
        json_out(['ok' => false, 'message' => 'Khong the gan debug port'], 500);
    }

    // Fire-and-forget: dung popen + start /B de khong cho Chrome thoat
    launch_chrome($p, $url, $port);

    db()->prepare('UPDATE profiles SET status=?, last_opened=NOW(), debug_port=? WHERE id=?')
        ->execute(['running', $port, (int)$p['id']]);
    log_action((int)$p['id'], 'open', $url);
    json_out(['ok' => true, 'message' => 'Da mo Chrome cho profile: ' . $p['name'], 'port' => $port]);
}

function kill_chrome_quiet(array $p): void
{
    kill_chrome_processes($p);
    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $p['id']]);
}

function kill_chrome(array $p): void
{
    kill_chrome_processes($p);
    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $p['id']]);
    log_action((int)$p['id'], 'close', 'Dong chrome');
    json_out(['ok' => true, 'message' => 'Da dong Chrome cho profile: ' . $p['name']]);
}