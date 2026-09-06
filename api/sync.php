<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            // Lay danh sach tab cua tat ca profile dang chay
            $profiles = db()->query(
                'SELECT id, name, status, debug_port, user_data_dir, last_opened FROM profiles ORDER BY id'
            )->fetchAll();
            $result = [];
            foreach ($profiles as $p) {
                $live = refresh_profile_status($p);
                $item = [
                    'id'        => (int)$p['id'],
                    'name'      => $p['name'],
                    'status'    => $live,
                    'port'      => $p['debug_port'] ? (int)$p['debug_port'] : null,
                    'tabs'      => [],
                    'tab_count' => 0,
                ];
                if ($live === 'running' && $p['debug_port']) {
                    $tabs = cdp_list_tabs((int)$p['debug_port']);
                    if ($tabs !== null) {
                        $pages = array_values(array_filter($tabs, fn($t) => ($t['type'] ?? '') === 'page'));
                        $item['tabs'] = array_map(function ($t) {
                            return [
                                'id'    => $t['id'],
                                'title' => $t['title'] ?? '',
                                'url'   => $t['url'] ?? '',
                            ];
                        }, $pages);
                        $item['tab_count'] = count($item['tabs']);
                    }
                    // Khi CDP khong phan hoi (mo bang tay khong port) thi van hien running, bo qua
                }
                $result[] = $item;
            }
            json_out(['ok' => true, 'data' => $result]);
            break;

        case 'open_url':
            // Mo 1 URL tren tat ca profile. Profile dang chay => tab moi qua CDP,
            // profile dang dung => launcher Chrome kem URL
            $b = json_body();
            $url = trim($b['url'] ?? '');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                json_out(['ok' => false, 'message' => 'URL khong hop le'], 400);
            }
            $profiles = db()->query(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.password AS proxy_pass
                 FROM profiles p
                 LEFT JOIN proxies pr ON pr.id = p.proxy_id
                 ORDER BY p.id'
            )->fetchAll();
            $opened = 0;
            $errors = [];
            $updateState = db()->prepare('UPDATE profiles SET status=? WHERE id=?');
            foreach ($profiles as $p) {
                $alive = ($p['status'] === 'running' && $p['debug_port'] && is_cdp_alive((int)$p['debug_port']));
                if (!$alive && $p['status'] === 'running') {
                    $updateState->execute(['stopped', (int)$p['id']]);
                    $p['status'] = 'stopped';
                }
                if ($alive) {
                    $ok = cdp_open_tab((int)$p['debug_port'], $url);
                    if ($ok) {
                        $opened++;
                        log_action((int)$p['id'], 'sync_open_url', $url);
                    } else {
                        $errors[] = $p['name'];
                    }
                } else {
                    // launch Chrome voi URL trong tab dau
                    try {
                        open_chrome_from_sync($p, $url);
                        $opened++;
                        log_action((int)$p['id'], 'sync_open_url', $url);
                    } catch (Throwable $e) {
                        $errors[] = $p['name'];
                    }
                }
            }
            json_out([
                'ok'        => true,
                'opened'    => $opened,
                'errors'    => $errors,
                'message'   => "Da dong bo URL cho $opened kenh" . ($errors ? ', loi: ' . implode(', ', $errors) : ''),
            ]);
            break;

        case 'open_tab':
            // Mo 1 tab moi trong 1 profile cu the (du chrome dang chay hay dung)
            $profileId = (int)($_GET['profile_id'] ?? 0);
            $b = json_body();
            $url = trim($b['url'] ?? '');
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                json_out(['ok' => false, 'message' => 'URL khong hop le'], 400);
            }
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

            $alive = ($p['status'] === 'running' && $p['debug_port'] && is_cdp_alive((int)$p['debug_port']));
            if (!$alive) {
                // cap nhat trang thai thuc te
                db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $profileId]);
            }

            if ($alive) {
                $ok = cdp_open_tab((int)$p['debug_port'], $url);
                if (!$ok) json_out(['ok' => false, 'message' => 'Khong the mo tab qua CDP'], 500);
                log_action($profileId, 'sync_open_tab', $url);
                json_out(['ok' => true, 'message' => 'Da mo tab moi']);
            }

            // Profile dung -> launch Chrome voi URL
            try {
                open_chrome_from_sync($p, $url);
                log_action($profileId, 'sync_open_tab', $url);
                json_out(['ok' => true, 'message' => 'Da mo Chrome voi tab moi']);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi: ' . $e->getMessage()], 500);
            }
            break;

        case 'open_studio':
            $profiles = db()->query(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.password AS proxy_pass
                 FROM profiles p
                 LEFT JOIN proxies pr ON pr.id = p.proxy_id
                 ORDER BY p.id'
            )->fetchAll();
            $opened = 0;
            $errors = [];
            $updateState = db()->prepare('UPDATE profiles SET status=? WHERE id=?');
            foreach ($profiles as $p) {
                $url = 'https://studio.youtube.com';
                $alive = ($p['status'] === 'running' && $p['debug_port'] && is_cdp_alive((int)$p['debug_port']));
                if (!$alive && $p['status'] === 'running') {
                    $updateState->execute(['stopped', (int)$p['id']]);
                    $p['status'] = 'stopped';
                }
                if ($alive) {
                    if (cdp_open_tab((int)$p['debug_port'], $url)) {
                        $opened++;
                        log_action((int)$p['id'], 'sync_open_studio', '');
                    } else {
                        $errors[] = $p['name'];
                    }
                } else {
                    try {
                        open_chrome_from_sync($p, $url);
                        $opened++;
                        log_action((int)$p['id'], 'sync_open_studio', '');
                    } catch (Throwable $e) {
                        $errors[] = $p['name'];
                    }
                }
            }
            sleep(1);
            json_out([
                'ok'      => true,
                'opened'  => $opened,
                'errors'  => $errors,
                'message' => "Da mo YouTube Studio cho $opened kenh" . ($errors ? ', loi: ' . implode(', ', $errors) : ''),
            ]);
            break;

        case 'close_tab':
            // Dong 1 tab (profile_id + tab_id tu CDP)
            $profileId = (int)($_GET['profile_id'] ?? 0);
            $tabId = trim($_GET['tab_id'] ?? '');
            $stmt = db()->prepare('SELECT id, name, debug_port, status FROM profiles WHERE id = ?');
            $stmt->execute([$profileId]);
            $p = $stmt->fetch();
            if (!$p || $p['status'] !== 'running' || !$p['debug_port']) {
                json_out(['ok' => false, 'message' => 'Profile khong dang chay'], 400);
            }
            $ok = cdp_close_tab((int)$p['debug_port'], $tabId);
            if ($ok) {
                log_action($profileId, 'sync_close_tab', $tabId);
            }
            json_out(['ok' => $ok, 'message' => $ok ? 'Da dong tab' : 'Khong the dong tab']);
            break;

        case 'close_all_tabs':
            // Dong tat ca tab cua 1 profile (giu cua so Chrome)
            $profileId = (int)($_GET['profile_id'] ?? 0);
            $stmt = db()->prepare('SELECT id, name, debug_port, status FROM profiles WHERE id = ?');
            $stmt->execute([$profileId]);
            $p = $stmt->fetch();
            if (!$p || $p['status'] !== 'running' || !$p['debug_port']) {
                json_out(['ok' => false, 'message' => 'Profile khong dang chay'], 400);
            }
            $tabs = cdp_list_tabs((int)$p['debug_port']) ?: [];
            $closed = 0;
            foreach ($tabs as $t) {
                if (($t['type'] ?? '') === 'page' && cdp_close_tab((int)$p['debug_port'], $t['id'])) {
                    $closed++;
                }
            }
            if ($closed > 0) {
                log_action($profileId, 'sync_close_all_tabs', "$closed tab");
            }
            json_out(['ok' => true, 'closed' => $closed, 'message' => "Da dong $closed tab"]);
            break;

        case 'refresh':
            // Reload tat ca tab cua profile qua CDP Page.reload (cau lon hon,
            // tam thoi dong va mo lai cung URL duoc xu ly o client) — o day
            // chi tra ve trang thai de client tu dong bo.
            json_out(['ok' => true, 'message' => 'Dung refresh tu bang dieu khien']);
            break;

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}

// ================= CDP helpers (HTTP endpoints, khong can WebSocket) =================

function cdp_base(?int $port): string
{
    return 'http://127.0.0.1:' . $port;
}

function cdp_http(string $url, string $method = 'GET', int $timeout = 3)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    return $body;
}

function cdp_list_tabs(int $port): ?array
{
    $body = cdp_http(cdp_base($port) . '/json/list', 'GET', 2);
    if ($body === null) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function is_cdp_alive(int $port): bool
{
    return cdp_list_tabs($port) !== null;
}

function cdp_open_tab(int $port, string $url): bool
{
    // Chrome CDP: PUT /json/new?{URL} de mo tab moi
    $full = cdp_base($port) . '/json/new?' . rawurlencode($url);
    $body = cdp_http($full, 'PUT', 5);
    return $body !== null;
}

function cdp_close_tab(int $port, string $tabId): bool
{
    $full = cdp_base($port) . '/json/close/' . $tabId;
    $body = cdp_http($full, 'PUT', 3);
    return $body !== null;
}

// Launch Chrome dung logic chung (config.php: build_chrome_command + launch_chrome)
function open_chrome_from_sync(array $p, string $url): void
{
    if (!file_exists(chrome_path())) {
        throw new RuntimeException('Khong tim thay Chrome');
    }
    // Dong instance cu dang chay sai port/khong CDP truoc khi launch,
    // tranh Chrome "nhet" tab vao cua so cu va bo qua debug port moi.
    if (is_chrome_running($p)) {
        kill_chrome_processes($p);
    }
    if (!empty($p['debug_port'])) {
        $port = (int)$p['debug_port'];
    } else {
        $port = 9200 + (int)$p['id'];
        if (cdp_reachable($port)) {
            // Tìm port khác nếu 9200+id đã bị chiếm bởi instance khác
            for ($i = 1; $i < 100; $i++) {
                $alt = $port + $i;
                if (!cdp_reachable($alt)) { $port = $alt; break; }
            }
        }
        db()->prepare('UPDATE profiles SET debug_port=? WHERE id=?')->execute([$port, (int)$p['id']]);
    }

    launch_chrome($p, $url, $port);

    db()->prepare('UPDATE profiles SET status=?, last_opened=NOW(), debug_port=? WHERE id=?')
        ->execute(['running', $port, (int)$p['id']]);
}