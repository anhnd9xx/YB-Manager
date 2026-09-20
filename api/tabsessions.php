<?php
declare(strict_types=1);
/**
 * api/tabsessions.php - Tab Session + Tab Batch endpoints.
 * GET  ?action=tabs&id=      tabs SONG (cho panel, khong redraw lien tuc)
 * GET  ?action=get&id=       session da luu (current + last_good)
 * POST ?action=save {id}     snapshot ngay (CDP, timeout ngan)
 * POST ?action=restore {id}  khoi phuc batch (1 WS, ordered, khong sleep tung tab)
 * POST ?action=clear {id}    xoa session
 * POST ?action=open_batch {id, urls[], preserve_order, activate, avoid_duplicate_tabs}
 * POST ?action=close_batch {id, targetIds[]}
 * POST ?action=close_all {id, keep_one}
 * GET  ?action=batch_state&id=   tien do batch (UI poll, khong refresh full)
 * POST ?action=batch_cancel {id}
 */
require_once __DIR__ . '/../sync/TabSessionStore.php';
require_once __DIR__ . '/../sync/TabBatchManager.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'tabs';

function tabs_profile(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Mo tab moi bang CDP /json/new (PUT - Chrome moi tra 405 cho GET). Tra ve target id hoac null. */
function tabs_open_new(int $port, string $url): ?string
{
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents('http://127.0.0.1:' . $port . '/json/new?' . urlencode($url), false, $ctx);
    $t = json_decode((string)$raw, true);
    return is_array($t) && !empty($t['id']) ? (string)$t['id'] : null;
}

/** Navigate tab co san toi URL (dung tab blank dau tien khi restore). */
function tabs_navigate(int $port, string $tabId, string $url): bool
{
    foreach (cdp_page_targets($port) as $t) {
        if ((string)$t['id'] === $tabId && !empty($t['webSocketDebuggerUrl'])) {
            return cdp_ws_send($port, (string)$t['webSocketDebuggerUrl'],
                json_encode(['id' => 9, 'method' => 'Page.navigate', 'params' => ['url' => $url]]));
        }
    }
    return false;
}

/** Activate 1 tab (bringToFront) de giu active_index sau restore. */
function tabs_activate(int $port, string $tabId): void
{
    foreach (cdp_page_targets($port) as $t) {
        if ((string)$t['id'] === $tabId && !empty($t['webSocketDebuggerUrl'])) {
            cdp_ws_send($port, (string)$t['webSocketDebuggerUrl'],
                json_encode(['id' => 10, 'method' => 'Page.bringToFront']));
            break;
        }
    }
}

try {
    switch ($action) {
        case 'tabs': {
            $id = (int)($_GET['id'] ?? 0);
            $p = tabs_profile($id);
            if (!$p) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            if (($p['status'] ?? '') !== 'running') {
                json_out(['ok' => true, 'data' => ['running' => false, 'tabs' => []]]);
            }
            $live = TabSessionStore::readLive($id, (int)$p['debug_port']);
            if ($live === null) json_out(['ok' => true, 'data' => ['running' => true, 'tabs' => [], 'stale' => true]]);
            $snap = TabSessionManager::buildSnapshot($live['tabs'], (int)$live['activeIndex']);
            $host = fn($u) => parse_url($u, PHP_URL_HOST) ?: $u;
            $tabs = array_map(fn($t) => ['url' => $t['url'], 'title' => $t['title'] !== '' ? $t['title'] : $host($t['url']),
                'host' => $host($t['url'])], $snap['tabs']);
            json_out(['ok' => true, 'data' => ['running' => true, 'tabs' => $tabs, 'active' => $snap['active_index']]]);
            break;
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => true, 'data' => [
                'current' => TabSessionStore::get($id, 'current'),
                'last_good' => TabSessionStore::get($id, 'last_good'),
            ]]);
            break;
        }

        case 'save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            $p = tabs_profile($id);
            if (!$p) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            $t0 = microtime(true);
            // Luu tay: dung true-order (chinh xac ke ca da keo-tha tab)
            $snap = TabSessionStore::snapshotLive($id, (int)($p['debug_port'] ?? 0), true);
            if ($snap === null) json_out(['ok' => false, 'message' => 'Chrome chua san sang (CDP)'], 409);
            $r = TabSessionStore::save($id, $snap);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            SyncLogger::info('tab_session', "[SESSION] profile=$id port=" . (int)($p['debug_port'] ?? 0)
                . ' snapshot ' . count($snap['tabs']) . " tabs: {$ms}ms", $id);
            if ($r['savedCurrent'] || $r['savedGood']) {
                SyncLogger::info('tab_session', "[SESSION] #$id saved", $id);
                log_action($id, 'tab_save', count($snap['tabs']) . ' tabs');
            }
            json_out(['ok' => true, 'count' => count($snap['tabs']), 'ms' => $ms, 'saved' => $r]);
            break;
        }

        case 'restore': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            $p = tabs_profile($id);
            if (!$p) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            // Batch restore: 1 WS browser-level, ordered fast create, khong sleep tung tab
            $r = TabBatchManager::restore_tabs($id);
            if (empty($r['ok'])) {
                $code = ($r['message'] ?? '') === 'Chrome chua chay' ? 409 : 400;
                if (isset($r['duplicate'])) $code = 409;
                json_out(['ok' => false, 'message' => $r['message'] ?? 'Loi'], $code);
            }
            json_out(['ok' => true, 'count' => $r['count'] ?? 0, 'ms' => $r['ms'] ?? 0,
                      'failed' => $r['failed'] ?? 0, 'batch_id' => $r['batch_id'] ?? null]);
            break;
        }

        case 'open_batch': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $rawUrls = $b['urls'] ?? $b['list'] ?? [];
            if (is_string($rawUrls)) $rawUrls = preg_split('/\r?\n/', $rawUrls);
            $r = TabBatchManager::open_tabs($id, (array)$rawUrls, [
                'preserve_order' => !array_key_exists('preserve_order', $b) || !empty($b['preserve_order']),
                'activate' => (string)($b['activate'] ?? 'LAST'),
                'avoid_duplicate_tabs' => !empty($b['avoid_duplicate_tabs']),
            ]);
            if (empty($r['ok'])) {
                $code = isset($r['duplicate']) ? 409 : ((($r['message'] ?? '') === 'Chrome chua chay') ? 409 : 400);
                json_out(['ok' => false, 'message' => $r['message'] ?? 'Loi'], $code);
            }
            json_out(['ok' => true, 'count' => $r['count'] ?? 0, 'failed' => $r['failed'] ?? 0,
                      'cancelled' => $r['cancelled'] ?? 0, 'ms' => $r['ms'] ?? 0,
                      'batch_id' => $r['batch_id'] ?? null, 'created' => $r['created'] ?? []]);
            break;
        }

        case 'close_batch': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $ids = (array)($b['targetIds'] ?? $b['ids'] ?? []);
            $r = TabBatchManager::close_tabs($id, $ids);
            if (empty($r['ok'])) {
                $code = isset($r['duplicate']) ? 409 : 400;
                json_out(['ok' => false, 'message' => $r['message'] ?? 'Loi'], $code);
            }
            json_out(['ok' => true, 'closed' => $r['closed'] ?? 0, 'failed' => $r['failed'] ?? 0,
                      'ms' => $r['ms'] ?? 0, 'batch_id' => $r['batch_id'] ?? null]);
            break;
        }

        case 'close_all': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $r = TabBatchManager::close_all_tabs($id, !empty($b['keep_one']));
            if (empty($r['ok'])) json_out(['ok' => false, 'message' => $r['message'] ?? 'Loi'], 400);
            json_out(['ok' => true, 'closed' => $r['closed'] ?? 0, 'ms' => $r['ms'] ?? 0]);
            break;
        }

        case 'batch_state': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => true, 'data' => TabBatchManager::get_batch_state($id)]);
            break;
        }

        case 'batch_cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => true, 'cancelled' => TabBatchManager::cancel_batch($id)]);
            break;
        }

        case 'clear': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            TabSessionStore::clear($id);
            log_action($id, 'tab_clear', 'Xoa session');
            json_out(['ok' => true]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'tabsessions exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
