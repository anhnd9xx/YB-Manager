<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sync/SyncLogger.php';
require_once __DIR__ . '/../sync/WindowDiscovery.php';

$action = $_GET['action'] ?? 'open';
$profileId = (int)($_GET['id'] ?? 0);

// Batch actions khong can profile don (engine tu load theo ids)
if (str_starts_with($action, 'batch_')) {
    // chuyen thang xuong switch ben duoi voi $p = null
    $p = null;
} else {
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
    } catch (Throwable $e) {
        json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
    }
}

try {
    // $p da load o tren (batch: null)

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

        case 'batch_open_prepare':
        case 'batch_open_chunk':
        case 'batch_open_poll':
        case 'batch_open_cancel':
        case 'batch_close':
        case 'batch_close_poll': {
            // Batch lifecycle engine duy nhat (§1): start/stop all/selected chung code
            @set_time_limit(120);
            require_once __DIR__ . '/../sync/ChromeBatchManager.php';
            $b = json_body();
            if ($action === 'batch_open_prepare') {
                $ids = array_map('intval', (array)($b['ids'] ?? []));
                if (!$ids && !empty($_GET['ids'])) {
                    $ids = array_map('intval', explode(',', (string)$_GET['ids']));
                }
                if (!$ids) {
                    // mac dinh: tat ca profiles (Mo tat ca)
                    try {
                        $ids = array_map('intval', db()->query('SELECT id FROM profiles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
                    } catch (Throwable $e) {
                        $ids = [];
                    }
                }
                if (!$ids) json_out(['ok' => false, 'message' => 'Chua co kenh nao'], 400);
                json_out(['ok' => true, 'data' => ChromeBatchManager::startPrepare($ids)]);
                break;
            }
            if ($action === 'batch_open_chunk') {
                $bid = (string)($b['batch_id'] ?? ($_GET['batch_id'] ?? ''));
                if ($bid === '') json_out(['ok' => false, 'message' => 'Thieu batch_id'], 400);
                $limit = (int)($b['limit'] ?? ChromeBatchManager::MAX_PARALLEL_START);
                json_out(['ok' => true, 'data' => ChromeBatchManager::startChunk($bid, $limit)]);
                break;
            }
            if ($action === 'batch_open_poll') {
                $bid = (string)($_GET['batch_id'] ?? '');
                if ($bid === '') json_out(['ok' => false, 'message' => 'Thieu batch_id'], 400);
                json_out(['ok' => true, 'data' => ChromeBatchManager::startPoll($bid)]);
                break;
            }
            if ($action === 'batch_open_cancel') {
                $bid = (string)($b['batch_id'] ?? ($_GET['batch_id'] ?? ''));
                if ($bid === '') json_out(['ok' => false, 'message' => 'Thieu batch_id'], 400);
                json_out(['ok' => true, 'data' => ['cancelled' => ChromeBatchManager::startCancel($bid)]]);
                break;
            }
            if ($action === 'batch_close') {
                $ids = array_map('intval', (array)($b['ids'] ?? []));
                if (!$ids && !empty($_GET['ids'])) {
                    $ids = array_map('intval', explode(',', (string)$_GET['ids']));
                }
                if (!$ids) {
                    try {
                        $ids = array_map('intval', db()->query("SELECT id FROM profiles WHERE status='running' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
                    } catch (Throwable $e) {
                        $ids = [];
                    }
                }
                if (!$ids) json_out(['ok' => true, 'data' => ['batch_id' => null, 'total' => 0, 'dispatched' => 0, 'done' => true]]);
                // Huy start batch dang chay truoc (§39)
                if (!empty($b['cancel_open_batch'])) {
                    ChromeBatchManager::startCancel((string)$b['cancel_open_batch']);
                }
                $mode = (string)($b['mode'] ?? get_setting('close_mode', 'safe'));
                if (!in_array($mode, ['safe', 'fast'], true)) $mode = 'safe';
                json_out(['ok' => true, 'data' => ChromeBatchManager::stopBatch($ids, $mode)]);
                break;
            }
            // batch_close_poll
            $bid = (string)($_GET['batch_id'] ?? '');
            if ($bid === '') json_out(['ok' => false, 'message' => 'Thieu batch_id'], 400);
            json_out(['ok' => true, 'data' => ChromeBatchManager::stopPoll($bid)]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}

function open_chrome(array $p, ?string $url = null): void
{
    $t0 = microtime(true); // B7: do dispatch time (khong tinh render Chrome)
    // Trang mo mac dinh: lay tu setting "home_url" (mac dinh google.com).
    // Prelaunch restore (neu co session) do launch_chrome lo (inject URLs vao command).
    $explicitUrl = $url !== null;
    if ($url === null) {
        $url = get_setting('home_url', 'https://www.google.com/');
    }
    // Neu Chrome dang chay VA CDP con phan hoi VA config (proxy/user-agent) dung nhu DB
    // -> tra ve ngay, khong dong/restart (tranh mat tab khi user bam "Mo" lai).
    // Neu config da doi (gan proxy moi, doi UA...) -> restart de ap dung config moi.
    if (is_chrome_running($p)) {
        if (!empty($p['debug_port']) && cdp_reachable((int)$p['debug_port'])) {
            if (chrome_cmdline_matches_config($p)) {
                // Relay cua kenh dang chay co CHET (khong lang nghe) khong -> khoi dong lai ngay
                // (truoc day open tra "dang chay" ma khong sua relay chet -> kenh di qua proxy cut;
                //  extra: chi dung relay_listening nhanh, khong dung relay_healthy 12s de khoi treo open).
                $relay = expected_relay_port($p);
                if ($relay !== null && !relay_listening($relay)) {
                    if (start_proxy_relay($p) === null) {
                        json_out(['ok' => false, 'proxy_dead' => true, 'message' => 'Relay cua kenh da chet va khong the khoi dong lai (proxy chet?).'], 409);
                    }
                }
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

    // Chi mo khi proxy con song.
    // Proxy CO credential dung relay local -> kiem tra song/chet nam trong launch_chrome
    // (start_proxy_relay), khong test_proxy rieng de mo nhanh (1 lan noi len proxy la du).
    // Proxy KHONG credential (khong can relay) -> van test_proxy nhu cu.
    if (!empty($p['proxy_host']) && expected_relay_port($p) === null) {
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
            try {
                require_once __DIR__ . '/../sync/AlertManager.php';
                require_once __DIR__ . '/../sync/MonitoringService.php';
                require_once __DIR__ . '/../sync/StateHistory.php';
                AlertManager::syncProxy((int)$p['id'], true, MonitoringService::getSettings((int)$p['id']));
                StateHistory::record((int)$p['id'], StateHistory::CAT_PROXY, 'OK', 'ERROR', 'proxy dead');
            } catch (Throwable $e) {
            }
            json_out([
                'ok'         => false,
                'proxy_dead' => true,
                'message'    => 'Proxy cua kenh da chet (' . $p['proxy_host'] . ':' . $p['proxy_port'] . '). '
                    . 'Gan proxy khac hoac gõ proxy de mo kenh.',
            ], 409);
        }
        db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
            ->execute(['alive', (int)$p['proxy_id']]);
        try {
            require_once __DIR__ . '/../sync/AlertManager.php';
            AlertManager::syncProxy((int)$p['id'], false, ['alert_on_proxy_error' => 1]);
        } catch (Throwable $e) {
        }
    }

    // Bat buoc co debug port truoc khi launch (cap chung via config.php)
    $port = allocate_debug_port($p);
    if (!$port) {
        json_out(['ok' => false, 'message' => 'Khong the gan debug port'], 500);
    }

    // Fire-and-forget: dung popen + start /B de khong cho Chrome thoat.
    // URL chi dinh (Studio/Dashboard) -> skip session inject; mo mac dinh thi
    // launch_chrome tu inject session (prelaunch restore, khong blank).
    // LUU Y: dung $explicitUrl (chup TRUOC khi gan home_url), khong dung $url.
    try {
        launch_chrome($p, $url ?? get_setting('home_url', 'https://www.google.com/'), $port,
            $explicitUrl ? ['skipSessionInject' => true] : []);
    } catch (RuntimeException $e) {
        json_out(['ok' => false, 'proxy_dead' => true, 'message' => $e->getMessage()], 409);
    }

    db()->prepare('UPDATE profiles SET status=?, last_opened=NOW(), debug_port=? WHERE id=?')
        ->execute(['running', $port, (int)$p['id']]);
    // Account Evaluation: dam bao state + danh dau due neu bat "Evaluate on Profile Start"
    try {
        require_once __DIR__ . '/../sync/AccountRepository.php';
        require_once __DIR__ . '/../sync/SettingsService.php';
        AccountRepository::ensure((int)$p['id']);
        if (SyncSettingsService::getAccountPolicy()['evalOnStart']) {
            db()->prepare('UPDATE account_states SET last_checked_at=NULL WHERE profile_id=?')
                ->execute([(int)$p['id']]);
        }
    } catch (Throwable $e) {
        // khong de danh gia lam hong mo kenh
    }
    log_action((int)$p['id'], 'open', $url);
    try {
        require_once __DIR__ . '/../sync/StateHistory.php';
        StateHistory::record((int)$p['id'], StateHistory::CAT_RUNTIME, 'STOPPED', 'RUNNING', 'open');
    } catch (Throwable $e) {
    }
    json_out(['ok' => true, 'message' => 'Da mo Chrome cho profile: ' . $p['name'], 'port' => $port]);
}

function kill_chrome_quiet(array $p): void
{
    tab_snapshot_before_close($p);
    try {
        require_once __DIR__ . '/../sync/WindowPlacementManager.php';
        WindowPlacementManager::save_window_placement($p, (int)($p['debug_port'] ?? 0) > 0 ? placement_hwnd_for((int)$p['id']) : null);
    } catch (Throwable $e) {
    }
    kill_chrome_processes($p);
    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $p['id']]);
}

/**
 * Snapshot tab TRUOC KHI dong Chrome (CLOSING -> snapshot -> save).
 * Timeout ngan (~1.5s): fail thi bo qua, dung last_good lam fallback.
 * Khong bao gio block dong Chrome.
 */
function tab_snapshot_before_close(array $p): void
{
    try {
        $id = (int)($p['id'] ?? 0);
        $port = (int)($p['debug_port'] ?? 0);
        if ($id <= 0 || $port <= 0 || !cdp_reachable($port)) return;
        require_once __DIR__ . '/../sync/TabSessionStore.php';
        require_once __DIR__ . '/../sync/SyncLogger.php';
        $t0 = microtime(true);
        // Pre-close: true-order de giu dung vi tri ke ca da keo-tha tab.
        // Deadline 1200ms: qua gio dung last_good, khong block dong Chrome.
        $snap = TabSessionStore::snapshotLive($id, $port, true, 1200);
        if ($snap === null) return;
        TabSessionStore::save($id, $snap);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        SyncLogger::info('tab_session', "[SESSION] profile=$id port=$port snapshot "
            . count($snap['tabs']) . " tabs: {$ms}ms (pre-close)", $id);
    } catch (Throwable $e) {
        // snapshot fail -> khong block dong Chrome
    }
}

function placement_hwnd_for(int $profileId): ?int
{
    try {
        foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
            if ($w->profileId !== null && (int)$w->profileId === $profileId
                && $w->class === 'Chrome_WidgetWin_1' && $w->visible) {
                return $w->hwnd;
            }
        }
    } catch (Throwable $e) {
    }
    return null;
}

function kill_chrome(array $p): void
{
    $t0 = microtime(true);
    tab_snapshot_before_close($p);
    try {
        require_once __DIR__ . '/../sync/WindowPlacementManager.php';
        WindowPlacementManager::save_window_placement($p, placement_hwnd_for((int)$p['id']));
    } catch (Throwable $e) {
    }
    kill_chrome_processes($p);
    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $p['id']]);
    SyncLogger::info('browser_perf', '[PERF] close profile #' . (int)$p['id']
        . ' dispatch: ' . (int)round((microtime(true) - $t0) * 1000) . 'ms');
    log_action((int)$p['id'], 'close', 'Dong chrome');
    try {
        require_once __DIR__ . '/../sync/StateHistory.php';
        StateHistory::record((int)$p['id'], StateHistory::CAT_RUNTIME, 'RUNNING', 'STOPPED', 'close');
    } catch (Throwable $e) {
    }
    json_out(['ok' => true, 'message' => 'Da dong Chrome cho profile: ' . $p['name']]);
}