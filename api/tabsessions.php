<?php
declare(strict_types=1);
/**
 * api/tabsessions.php - Tab Session Manager endpoints.
 * GET  ?action=tabs&id=      tabs SONG (cho panel, khong redraw lien tuc)
 * GET  ?action=get&id=       session da luu (current + last_good)
 * POST ?action=save {id}     snapshot ngay (CDP, timeout ngan)
 * POST ?action=restore {id}  khoi phuc ngay tren Chrome dang chay
 * POST ?action=clear {id}    xoa session
 */
require_once __DIR__ . '/../sync/TabSessionStore.php';
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
            $port = (int)($p['debug_port'] ?? 0);
            if (($p['status'] ?? '') !== 'running' || !cdp_reachable($port)) {
                json_out(['ok' => false, 'message' => 'Chrome chua chay'], 409);
            }
            $r = TabSessionStore::getRestorable($id);
            if (($r['status'] ?? 'none') === 'empty') {
                json_out(['ok' => false, 'message' => 'Session rong (khong co tab de khoi phuc)'], 404);
            }
            if (($r['status'] ?? 'none') !== 'session') {
                json_out(['ok' => false, 'message' => 'Khong co session de khoi phuc'], 404);
            }
            $sess = $r['session'];
            $t0 = microtime(true);
            // Tab blank hien tai? (restore dung tab dau, khong thua New Tab)
            $blankId = null;
            foreach (cdp_page_targets($port) as $t) {
                if (!TabSessionManager::is_restorable_url((string)($t['url'] ?? ''))) {
                    $blankId = (string)$t['id'];
                    break;
                }
            }
            $steps = TabSessionManager::restoreSteps($sess['tabs'], $blankId !== null);
            $opened = [];
            foreach ($steps as $s) {
                if ($s['action'] === 'navigate' && $blankId !== null) {
                    if (tabs_navigate($port, $blankId, $s['url'])) $opened[] = $blankId;
                } else {
                    $nid = tabs_open_new($port, $s['url']);
                    if ($nid !== null) $opened[] = $nid;
                }
                usleep(300000);
            }
            // Active lai dung tab da luu
            $ai = min((int)$sess['active_index'], count($opened) - 1);
            if ($ai >= 0 && isset($opened[$ai])) tabs_activate($port, $opened[$ai]);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            SyncLogger::info('tab_session', "[SESSION] #$id restored " . count($opened) . " tabs: {$ms}ms", $id);
            log_action($id, 'tab_restore', count($opened) . ' tabs');
            json_out(['ok' => true, 'count' => count($opened), 'ms' => $ms]);
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
