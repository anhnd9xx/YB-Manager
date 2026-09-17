<?php
declare(strict_types=1);
/**
 * bin/sync_engine.php - SyncEngine + EventDispatcher (module Synchronize).
 *
 * 2 che do:
 *   A) Session mode (Phase 6): php sync_engine.php --session=<id>
 *      Doc cau hinh + state tu DB, ho tro pause/resume, doi MAIN live,
 *      them/bot follower live, reconnect follower chet, stop policy.
 *   B) Legacy mode (Phase 3-5): --master=<id> --controlled=<ids> [--fps] ...
 *
 * Pipeline: CAPTURE -> NORMALIZE -> QUEUE (rieng tung target) -> DISPATCH -> INJECT.
 * Loi 1 target khong block target khac. Khong bao gio restart browser.
 */
require_once __DIR__ . '/../sync/SyncEvent.php';
require_once __DIR__ . '/../sync/CoordinateMapper.php';
require_once __DIR__ . '/../sync/EventQueue.php';
require_once __DIR__ . '/../sync/CdpConnection.php';
require_once __DIR__ . '/../sync/InputInjector.php';
require_once __DIR__ . '/../sync/Capture.php';
require_once __DIR__ . '/../sync/MouseManager.php';
require_once __DIR__ . '/../sync/KeyboardManager.php';
require_once __DIR__ . '/../sync/TextManager.php';
require_once __DIR__ . '/../sync/DebugCursor.php';
require_once __DIR__ . '/../sync/SyncSession.php';
require_once __DIR__ . '/../sync/MainWindowManager.php';
require_once __DIR__ . '/../sync/SyncLogger.php';
require_once __DIR__ . '/../config.php';

function eng_arg(array $argv, string $name, string $def = ''): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $name . '=')) return substr($a, strlen($name) + 3);
    }
    return $def;
}

function eng_profile(int $id): ?array
{
    $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ?: null;
}

function eng_first_ws(int $port): ?array
{
    foreach (cdp_page_targets($port) as $t) {
        return ['targetId' => (string)$t['id'], 'ws' => (string)$t['webSocketDebuggerUrl']];
    }
    return null;
}

function eng_arm_tab(SyncCdpConnection $conn, SyncCapture $cap, string $targetId, int $fps): bool
{
    $id0 = $conn->sendCommand('Runtime.enable');
    $conn->waitForId($id0, 2000);
    if (!$cap->arm($conn, $targetId, $fps)) return false;
    // Chay ngay trong document hien tai (khong can reload mat trang thai user)
    eng_ensure_script($conn, $fps);
    return true;
}

/**
 * Dam bao script dang chay trong document HIEN TAI cua tab (phong truong hop
 * addScriptToEvaluateOnNewDocument khong ap dung: data: URL, trang dac biet,
 * context mat sau crash). Idempotent (script co guard flag).
 */
function eng_ensure_script(SyncCdpConnection $conn, int $fps): void
{
    $id = $conn->sendCommand('Runtime.evaluate', [
        'expression' => 'window.__ytmSyncArmed === true ? "armed" : "no"',
        'returnByValue' => true]);
    $r = $conn->waitForId($id, 2000);
    if (($r['result']['result']['value'] ?? null) === 'armed') return;
    $id2 = $conn->sendCommand('Runtime.evaluate', ['expression' => SyncCapture::script($fps)]);
    $conn->waitForId($id2, 2000);
}

// ---------------- Cau hinh: session mode hoac legacy ----------------
$sessionId = (int)eng_arg($argv, 'session', '0');
$session = $sessionId > 0 ? SyncSession::load($sessionId) : null;

$masterId = 0;
$controlledIds = [];
$cfg = SyncSession::defaultConfig();
if ($session !== null) {
    $masterId = $session->mainProfileId;
    $controlledIds = $session->controlledIds;
    $cfg = $session->config;
} else {
    $masterId = (int)eng_arg($argv, 'master', '0');
    $controlledIds = array_values(array_filter(array_map('intval', explode(',', eng_arg($argv, 'controlled', '')))));
    $cfg['fps'] = in_array((int)eng_arg($argv, 'fps', '60'), [30, 60, 120], true) ? (int)eng_arg($argv, 'fps', '60') : 60;
    $cfg['clickDelay'] = max(0, min(2000, (int)eng_arg($argv, 'click-delay', '20')));
    $tm = strtolower(eng_arg($argv, 'text-mode', 'off'));
    $cfg['textMode'] = in_array($tm, ['keyboard', 'direct'], true) ? $tm : 'off';
    if ($cfg['textMode'] !== 'off') {
        $tf = eng_arg($argv, 'text-file', '');
        if ($tf !== '' && is_file($tf)) {
            foreach (file($tf, FILE_IGNORE_NEW_LINES) as $ln) {
                $ln = rtrim($ln, "\r\n");
                if ($ln !== '') $cfg['textLines'][] = $ln;
            }
        } else {
            foreach (explode('|', eng_arg($argv, 'text-lines', eng_arg($argv, 'text', ''))) as $ln) {
                if ($ln !== '') $cfg['textLines'][] = $ln;
            }
        }
        $ts = strtolower(eng_arg($argv, 'text-strategy', 'identical'));
        $cfg['textStrategy'] = in_array($ts, ['identical', 'design', 'in_order', 'random'], true) ? $ts : 'identical';
        $cfg['typingMin'] = max(0, (int)eng_arg($argv, 'typing-delay-min', '50'));
        $cfg['typingMax'] = max($cfg['typingMin'], (int)eng_arg($argv, 'typing-delay-max', '120'));
    }
}

if ($masterId <= 0 || !$controlledIds) {
    fwrite(STDERR, "Usage: php sync_engine.php --session=<id> | --master=<id> --controlled=<ids>\n");
    exit(2);
}

// Ghi PID NGAY (API poll theo PID de xac nhan spawn, khong cho ket noi xong)
if ($session !== null) {
    $session->enginePid = getmypid();
    $session->save();
    // Thoat sach -> xoa PID cua minh (guard: khong xoa PID cua engine new hon)
    register_shutdown_function(function () use ($sessionId) {
        try {
            $s = SyncSession::load($sessionId);
            if ($s && (int)$s->enginePid === getmypid()) {
                $s->enginePid = null;
                $s->save();
            }
        } catch (Throwable $e) {}
    });
}
if (($cfg['inputMode'] ?? 'AUTO') === 'WINDOWS_API') {
    $msg = 'Windows API backend chua co (Phase 8); dung AUTO/CDP';
    if ($session !== null) {
        try { $session->transition(SyncSession::ERROR, $msg); } catch (Throwable $e) {}
    }
    fwrite(STDERR, $msg . "\n");
    exit(5);
}

function eng_master_alive(int $masterId): bool
{
    $m = eng_profile($masterId);
    if (!$m || $m['status'] !== 'running' || empty($m['debug_port'])) return false;
    return cdp_reachable((int)$m['debug_port']);
}

if (!eng_master_alive($masterId)) {
    $msg = "Master #$masterId chua chay (mo kenh truoc)";
    if ($session !== null) {
        try { $session->transition(SyncSession::ERROR, $msg); } catch (Throwable $e) {}
    }
    fwrite(STDERR, $msg . "\n");
    exit(3);
}

$fps = (int)($cfg['fps'] ?? 60);
$mouse = new SyncMouseManager((int)($cfg['clickDelay'] ?? 20), !empty($cfg['clickRandom']), (int)($cfg['clickVariation'] ?? 30));
$kbd = new SyncKeyboardManager();
$textMgr = new SyncTextManager($cfg['textMode'] ?? 'off', $cfg['textStrategy'] ?? 'identical',
    (int)($cfg['typingMin'] ?? 50), (int)($cfg['typingMax'] ?? 120));
$cap = new SyncCapture();
$seq = 0;

/** @var array<string,SyncCdpConnection> targetId -> conn (MASTER) */
$masterConns = [];
/** @var array<int,array> fid -> {conn,injector,queue,port,vw,vh,name,targetId,deadNotified,reconnectAt} */
$followers = [];

function eng_scan_master(int $mport, int $fps, SyncCapture $cap, array &$masterConns): void
{
    $seen = [];
    foreach (cdp_page_targets($mport) as $t) {
        $tid = (string)$t['id'];
        $seen[$tid] = true;
        if (isset($masterConns[$tid]) && $masterConns[$tid]->isConnected()) {
            // Tab cu: dam bao script van chay trong document hien tai
            eng_ensure_script($masterConns[$tid], $fps);
            continue;
        }
        $c = new SyncCdpConnection();
        if (!$c->connect((string)$t['webSocketDebuggerUrl'])) {
            echo date('H:i:s') . " master tab $tid: WS connect FAIL\n";
            continue;
        }
        if (!eng_arm_tab($c, $cap, $tid, $fps)) {
            echo date('H:i:s') . " master tab $tid: arm FAIL\n";
            $c->disconnect();
            $cap->forget($tid);
            continue;
        }
        $masterConns[$tid] = $c;
        echo date('H:i:s') . " master tab armed: $tid\n";
    }
    foreach (array_keys($masterConns) as $tid) {
        if (!isset($seen[$tid]) || !$masterConns[$tid]->isConnected()) {
            $masterConns[$tid]->disconnect();
            unset($masterConns[$tid]);
            $cap->forget($tid);
            echo date('H:i:s') . " master tab closed/dropped: $tid\n";
        }
    }
}

/**
 * Theo doi thay doi window (moved/resized/minimized/restored/closed/focus) cho
 * cac profile trong session. Chay ~10s/lan (khong polling nhanh). Ghi SyncLogger.
 * $state: map profileId -> ['hwnd','rect','minimized','focused'] giu giua cac lan.
 */
function eng_watch_windows(array &$state): void
{
    try {
        require_once __DIR__ . '/../sync/WindowDiscovery.php';
    } catch (Throwable $e) {
        return;
    }
    $disc = SyncWindowDiscovery::discover(false);
    $fg = (int)($disc['foregroundHwnd'] ?? 0);
    $byProfile = [];
    foreach ($disc['windows'] as $w) {
        if ($w->profileId === null) continue;
        // Gom ca minimized (khong dung isBrowserMain: no loc minimized -> minimize bao nham CLOSED)
        if ($w->class !== 'Chrome_WidgetWin_1' || !$w->visible) continue;
        if ($w->rect === null || $w->rect['w'] <= 0 || $w->rect['h'] <= 0) continue;
        $pid = (int)$w->profileId;
        $area = $w->rect['w'] * $w->rect['h'];
        if (!isset($byProfile[$pid]) || $area > $byProfile[$pid]['area']) {
            $byProfile[$pid] = ['info' => $w, 'area' => $area];
        }
    }
    $wins = [];
    foreach ($byProfile as $pid => $row) $wins[$pid] = $row['info'];
    $byProfile = $wins;
    foreach ($byProfile as $pid => $w) {
        $prev = $state[$pid] ?? null;
        $cur = ['hwnd' => $w->hwnd, 'rect' => $w->rect,
                'minimized' => $w->minimized, 'focused' => $fg > 0 && $w->hwnd === $fg];
        if ($prev === null) {
            $state[$pid] = $cur;
            continue;
        }
        if ($prev['hwnd'] !== $cur['hwnd']) {
            SyncLogger::info('WINDOW_RECONNECTED', "Kenh #$pid doi HWND {$prev['hwnd']}->{$cur['hwnd']} (co the mo lai)", $pid);
        } elseif ($cur['minimized'] && !$prev['minimized']) {
            SyncLogger::info('WINDOW_MINIMIZED', "Kenh #$pid minimized", $pid);
        } elseif (!$cur['minimized'] && $prev['minimized']) {
            SyncLogger::info('WINDOW_RESTORED', "Kenh #$pid restored", $pid);
        } else {
            $pr = $prev['rect'] ?? [];
            $cr = $cur['rect'] ?? [];
            if (($pr['x'] ?? null) !== ($cr['x'] ?? null) || ($pr['y'] ?? null) !== ($cr['y'] ?? null)) {
                SyncLogger::info('WINDOW_MOVED', "Kenh #$pid moved -> ({$cr['x']},{$cr['y']})", $pid);
            }
            if (($pr['w'] ?? null) !== ($cr['w'] ?? null) || ($pr['h'] ?? null) !== ($cr['h'] ?? null)) {
                SyncLogger::info('WINDOW_RESIZED', "Kenh #$pid resized -> {$cr['w']}x{$cr['h']}", $pid);
            }
        }
        if ($cur['focused'] && !$prev['focused']) {
            SyncLogger::debug('WINDOW_FOCUS', "Kenh #$pid focus", $pid);
        }
        $state[$pid] = $cur;
    }
    // Profile mat khoi discovery = window closed/crash
    foreach (array_keys($state) as $pid) {
        if (!isset($byProfile[$pid])) {
            SyncLogger::warn('WINDOW_CLOSED', "Kenh #$pid mat window (dong/crash?)", $pid);
            unset($state[$pid]);
        }
    }
}

/** Ket noi 1 follower (tra ve record hoac null). */
function eng_connect_follower(int $fid, SyncMouseManager $mouse, SyncKeyboardManager $kbd): ?array
{
    $p = eng_profile($fid);
    if (!$p || $p['status'] !== 'running' || empty($p['debug_port'])) return null;
    if (!cdp_reachable((int)$p['debug_port'])) return null;
    $fw = eng_first_ws((int)$p['debug_port']);
    if ($fw === null) return null;
    $c = new SyncCdpConnection();
    if (!$c->connect($fw['ws'])) return null;
    $mouse->resetTarget((string)$fid);
    $kbd->resetTarget((string)$fid);
    return ['conn' => $c, 'injector' => new SyncCdpInjector($c), 'queue' => new SyncEventQueue(),
            'port' => (int)$p['debug_port'], 'vw' => 1280.0, 'vh' => 800.0,
            'name' => (string)$p['name'], 'targetId' => $fw['targetId'],
            'deadNotified' => false, 'reconnectAt' => 0, 'failCount' => 0];
}

/** Ghi heartbeat de HealthMonitor doc (10s/lan). */
function eng_heartbeat(?int $sessionId, array $stats, array $followers): void
{
    if ($sessionId === null || $sessionId <= 0) return;
    $qs = [];
    foreach ($followers as $fid => $f) $qs[$fid] = $f['queue']->size();
    @file_put_contents(__DIR__ . '/sync_heartbeat_' . $sessionId . '.json', json_encode([
        'pid' => getmypid(), 'ts' => time(), 'captured' => $stats['captured'],
        'dispatched' => $stats['dispatched'], 'dropped' => $stats['dropped'],
        'failed' => $stats['failed'], 'queues' => $qs,
    ]));
}

/** Ghi snapshot debug cho UI Debug Mode (2s/lan): last event + tung target. */
function eng_debug_snapshot(?int $sessionId, int $masterId, array $stats, array $followers, array $ring): void
{
    if ($sessionId === null || $sessionId <= 0) return;
    $tg = [];
    foreach ($followers as $fid => $f) {
        $tg[$fid] = [
            'name' => $f['name'], 'queue' => $f['queue']->size(),
            'conn' => $f['injector'] !== null && $f['conn']->isConnected(),
            'x' => isset($f['lastX']) ? round((float)$f['lastX'], 1) : null,
            'y' => isset($f['lastY']) ? round((float)$f['lastY'], 1) : null,
            'vw' => round((float)$f['vw'], 0), 'vh' => round((float)$f['vh'], 0),
        ];
    }
    @file_put_contents(__DIR__ . '/sync_debug_' . $sessionId . '.json', json_encode([
        'ts' => time(), 'pid' => getmypid(), 'master' => $masterId,
        'captured' => $stats['captured'], 'dispatched' => $stats['dispatched'],
        'dropped' => $stats['dropped'], 'failed' => $stats['failed'],
        'lastEvent' => end($ring) ?: null, 'recent' => array_slice($ring, -20),
        'targets' => $tg,
    ], JSON_UNESCAPED_UNICODE));
}

function eng_clear_debug(?int $sessionId): void
{
    if ($sessionId === null || $sessionId <= 0) return;
    @unlink(__DIR__ . '/sync_debug_' . $sessionId . '.json');
}

function eng_clear_heartbeat(?int $sessionId): void
{
    if ($sessionId === null || $sessionId <= 0) return;
    @unlink(__DIR__ . '/sync_heartbeat_' . $sessionId . '.json');
}

foreach ($controlledIds as $fid) {
    $f = eng_connect_follower($fid, $mouse, $kbd);
    if ($f === null) {
        echo date('H:i:s') . " follower #$fid chua san sang -> se thu lai (khong block)\n";
        // Giu slot trong de reconnect tu dong (khong mat cau hinh)
        $p = eng_profile($fid);
        $followers[$fid] = ['conn' => new SyncCdpConnection(), 'injector' => null, 'queue' => new SyncEventQueue(),
            'port' => 0, 'vw' => 1280.0, 'vh' => 800.0, 'name' => $p ? (string)$p['name'] : "#$fid",
            'targetId' => '', 'deadNotified' => false, 'reconnectAt' => microtime(true) + 5, 'failCount' => 0];
        continue;
    }
    $followers[$fid] = $f;
    echo date('H:i:s') . " follower connected: #$fid {$f['name']}\n";
}
if (!$followers) {
    fwrite(STDERR, "Khong co follower nao trong session\n");
    exit(4);
}

$mport = (int)(eng_profile($masterId)['debug_port'] ?? 0);
eng_scan_master($mport, $fps, $cap, $masterConns);
if ($session !== null) {
    // PID da ghi luc khoi dong; o day chi chuyen state
    try {
        $rs = SyncSession::load($session->id);
        if ($rs && $rs->state === SyncSession::STARTING) {
            $session = $rs;
            $session->transition(SyncSession::RUNNING);
        } elseif ($rs) {
            $session = $rs;
            $session->save();
        }
    } catch (Throwable $e) {
        if ($session !== null) $session->save();
    }
}
SyncLogger::info('SYNC_START', "master=#$masterId followers=" . count($followers) . " fps=$fps" . ($session ? " session #{$session->id}" : ' legacy'), $masterId);
echo date('H:i:s') . " SYNC RUNNING master=#$masterId followers=" . count($followers) . " fps=$fps\n";

// Scripted text entry luc khoi dong (Phase 5), neu textEnabled + textMode + co lines
if (!empty($cfg['text']) && ($cfg['textMode'] ?? 'off') !== 'off' && !empty($cfg['textLines'])) {
    $plan = $textMgr->assign(array_keys($followers), $cfg['textLines']);
    foreach ($plan as $fid => $txt) {
        if ($txt === '' || !isset($followers[(int)$fid])) continue;
        $e = new SyncEvent();
        $e->timestamp = microtime(true);
        $e->sourceProfileId = $masterId;
        $e->type = SyncEvent::TEXT_INPUT;
        $e->text = $txt;
        $e->sequenceNumber = ++$seq;
        $e->id = $seq;
        $followers[(int)$fid]['queue']->push($e);
        echo date('H:i:s') . " queued TEXT_INPUT follower #$fid (" . mb_strlen($txt) . " chars)\n";
    }
}

$stats = ['captured' => 0, 'dispatched' => 0, 'dropped' => 0, 'failed' => 0];
$ring = []; // 20 event gan nhat cho inspector
$cursorAt = [];   // fid -> microtime lan cuoi ve cursor
$cursorMade = []; // fid -> true neu da tao dot
$start = microtime(true);
$lastScan = 0;
$lastStat = 0;
$lastVw = 0;
$lastHealth = 0;
$lastDebug = 0;
$winState = [];
$lastSessPoll = 0;
$masterDeadSince = 0;
$wasPaused = false;
$warnedOverflow = [];

while (true) {
    try {
        $now = microtime(true);

        // ---- Session mode: nap state/cau hinh moi tu DB (pause/resume/stop/doi main) ----
        $paused = false;
        if ($session !== null && $now - $lastSessPoll > 2) {
            $lastSessPoll = $now;
            $fresh = SyncSession::load($session->id);
            if ($fresh === null) break; // session bi xoa -> thoat sach
            $session = $fresh;
            $cfg = $session->config;

            // Session bi API danh ERROR (vidu spawn fail) -> thoat sach, cho start lai
            if ($session->state === SyncSession::ERROR) {
                echo date('H:i:s') . " session ERROR -> exit\n";
                break;
            }

            if (in_array($session->state, [SyncSession::STOPPING, SyncSession::STOPPED], true)) {
                // Stop policy: drain (toi da 300 event / 3s) hoac cancel
                if (($cfg['stopPolicy'] ?? 'drain') === 'drain') {
                    $dl = microtime(true) + 3;
                    foreach ($followers as $fid => &$f) {
                        $n = 0;
                        while (!$f['queue']->isEmpty() && $n++ < 300 && microtime(true) < $dl) {
                            $e = $f['queue']->shift();
                            if ($e === null) break;
                            if ($f['injector'] !== null) {
                                if ($e->type === SyncEvent::KEY_DOWN || $e->type === SyncEvent::KEY_UP) $kbd->inject($f['injector'], (string)$fid, $e);
                                elseif ($e->type === SyncEvent::TEXT_INPUT) $textMgr->enterText($f['injector'], $e->text);
                                else $mouse->inject($f['injector'], (string)$fid, $e, $f['vw'], $f['vh']);
                            }
                        }
                    }
                    unset($f);
                }
                try { $session->transition(SyncSession::STOPPED); } catch (Throwable $e) {}
                SyncLogger::info('SYNC_STOP', "session #{$session->id} policy=" . ($cfg['stopPolicy'] ?? 'drain'));
                break;
            }
            if ($session->state === SyncSession::PAUSED && !$wasPaused) {
                // Vao pause: xoa queue (tranh inject su kien cu khi resume) + reset nut/phim
                foreach ($followers as $fid => $f) {
                    while ($f['queue']->shift() !== null) {}
                    $mouse->resetTarget((string)$fid);
                    $kbd->resetTarget((string)$fid);
                }
                $wasPaused = true;
                echo date('H:i:s') . " PAUSED (queues cleared)\n";
            } elseif ($session->state === SyncSession::RUNNING && $wasPaused) {
                $wasPaused = false;
                echo date('H:i:s') . " RESUMED\n";
            }
            $paused = ($session->state === SyncSession::PAUSED);

            // Doi MAIN live: drop master cu, ket noi + arm master moi (khong restart browser)
            if ($session->mainProfileId !== $masterId) {
                foreach ($masterConns as $mc) $mc->disconnect();
                $masterConns = [];
                $cap->reset();
                $oldMaster = $masterId;
                $masterId = $session->mainProfileId;
                $mp = eng_profile($masterId);
                $mport = $mp ? (int)($mp['debug_port'] ?? 0) : 0;
                $masterDeadSince = 0;
                SyncLogger::info('MAIN_CHANGED', "MAIN #$oldMaster -> #$masterId (session #{$session->id})", $masterId);
                echo date('H:i:s') . " MAIN_CHANGED #$oldMaster -> #$masterId\n";
            }
            // Follower them/bot live
            $want = [];
            foreach ($session->controlledIds as $cid) $want[$cid] = true;
            foreach (array_keys($followers) as $fid) {
                if (!isset($want[$fid])) {
                    $followers[$fid]['conn']->disconnect();
                    unset($followers[$fid]);
                    $mouse->resetTarget((string)$fid);
                    $kbd->resetTarget((string)$fid);
                    SyncLogger::info('PROFILE_REMOVED', "CONTROLLED #$fid (session #{$session->id})", $fid);
                    echo date('H:i:s') . " follower removed: #$fid\n";
                }
            }
            foreach (array_keys($want) as $cid) {
                if (!isset($followers[$cid])) {
                    $p = eng_profile($cid);
                    $followers[$cid] = ['conn' => new SyncCdpConnection(), 'injector' => null, 'queue' => new SyncEventQueue(),
                        'port' => 0, 'vw' => 1280.0, 'vh' => 800.0, 'name' => $p ? (string)$p['name'] : "#$cid",
                        'targetId' => '', 'deadNotified' => false, 'reconnectAt' => $now, 'failCount' => 0];
                    SyncLogger::info('PROFILE_ADDED', "CONTROLLED #$cid (session #{$session->id})", $cid);
                    echo date('H:i:s') . " follower added: #$cid\n";
                }
            }
            // Reconnect gap theo yeu cau UI (nut Reconnect)
            $rec = $cfg['reconnectIds'] ?? [];
            if ($rec) {
                foreach ($rec as $rid) {
                    $rid = (int)$rid;
                    if (isset($followers[$rid])) {
                        $followers[$rid]['conn']->disconnect();
                        $followers[$rid]['injector'] = null;
                        while ($followers[$rid]['queue']->shift() !== null) {}
                        $mouse->resetTarget((string)$rid);
                        $kbd->resetTarget((string)$rid);
                        $followers[$rid]['reconnectAt'] = $now;
                        $followers[$rid]['deadNotified'] = false;
                        SyncLogger::info('WINDOW_RECONNECTED', "Reconnect #$rid theo yeu cau (session #{$session->id})", $rid);
                        echo date('H:i:s') . " reconnect requested: #$rid\n";
                    }
                }
                $session->config['reconnectIds'] = [];
                $session->save();
                $cfg = $session->config;
            }
        }

        // ---- Master chet giua chung -> session ERROR + thoat (khong crash engine dot ngot) ----
        if (!eng_master_alive($masterId)) {
            if ($masterDeadSince <= 0) {
                $masterDeadSince = $now;
                echo date('H:i:s') . " WARN master #$masterId mat tin hieu (cho 12s truoc khi ERROR)\n";
            } elseif ($now - $masterDeadSince > 12) {
                $msg = "Master #$masterId disconnected qua 12s";
                if ($session !== null) {
                    try { $session->transition(SyncSession::ERROR, $msg); } catch (Throwable $e) {}
                }
                SyncLogger::error('WINDOW_DISCONNECTED', $msg . ($session ? " (session #{$session->id})" : ''), $masterId);
                break;
            }
        } else {
            $masterDeadSince = 0;
        }

        // ---- Reconnect follower chet (backoff mu, khong bao gio restart browser) ----
        foreach ($followers as $fid => &$f) {
            $alive = $f['injector'] !== null && $f['conn']->isConnected();
            if (!$alive && $now >= ($f['reconnectAt'] ?? 0)) {
                $nf = eng_connect_follower($fid, $mouse, $kbd);
                if ($nf !== null) {
                    while ($f['queue']->shift() !== null) {} // bo event cu, bat dau sach
                    $f = array_merge($f, $nf);
                    $f['deadNotified'] = false;
                    $f['failCount'] = 0;
                    SyncLogger::info('WINDOW_RECONNECTED', "Follower #$fid ket noi lai", $fid);
                    echo date('H:i:s') . " follower reconnected: #$fid\n";
                } else {
                    $f['failCount'] = (int)($f['failCount'] ?? 0) + 1;
                    $f['reconnectAt'] = $now + min(60, 5 * (2 ** min(3, $f['failCount'] - 1))); // 5,10,20,40,60s
                    if (!$f['deadNotified']) {
                        $f['deadNotified'] = true;
                        SyncLogger::warn('WINDOW_DISCONNECTED', "Follower #$fid mat ket noi (tu reconnect)", $fid);
                        echo date('H:i:s') . " follower #$fid down, retry sau\n";
                    }
                }
            }
        }
        unset($f);

        // ---- 1) CAPTURE (bo qua khi PAUSED: doc-xa de khong tran socket) ----
        $allow = ['move' => (bool)($cfg['mouseMove'] ?? true), 'click' => (bool)($cfg['mouseClick'] ?? true),
                  'wheel' => (bool)($cfg['mouseWheel'] ?? true), 'key' => (bool)($cfg['keyboard'] ?? true)];
        foreach ($masterConns as $tid => $mc) {
            foreach ($mc->readMessages(20) as $text) {
                if ($paused) continue; // drain-only
                $j = json_decode($text, true);
                if (!is_array($j)) continue;
                if (($j['method'] ?? '') !== 'Runtime.bindingCalled') continue;
                $pr = $j['params'] ?? [];
                if (($pr['name'] ?? '') !== SyncCapture::BINDING) continue;
                $d = json_decode((string)($pr['payload'] ?? ''), true);
                if (!is_array($d)) continue;
                $t = (string)($d['t'] ?? '');
                if ($t === 'MOUSE_MOVE' && !$allow['move']) continue;
                if (($t === 'MOUSE_LEFT_DOWN' || $t === 'MOUSE_LEFT_UP' || $t === 'MOUSE_RIGHT_DOWN' || $t === 'MOUSE_RIGHT_UP' || $t === 'MOUSE_MIDDLE_DOWN' || $t === 'MOUSE_MIDDLE_UP' || $t === 'MOUSE_XBUTTON_DOWN' || $t === 'MOUSE_XBUTTON_UP') && !$allow['click']) continue;
                if ($t === 'MOUSE_WHEEL' && !$allow['wheel']) continue;
                if (($t === 'KEY_DOWN' || $t === 'KEY_UP') && !$allow['key']) continue;
                $e = SyncEvent::fromCapture($masterId, $d);
                if ($e === null) continue;
                $n = SyncCoordinateMapper::normalizeViewport($e->clientX, $e->clientY, $e->viewportW, $e->viewportH);
                $e->normalizedX = $n['x'];
                $e->normalizedY = $n['y'];
                $e->sequenceNumber = ++$seq;
                $e->id = $seq;
                $stats['captured']++;
                $ring[] = ['seq' => $seq, 'type' => $e->type,
                           'nx' => round($e->normalizedX, 4), 'ny' => round($e->normalizedY, 4),
                           'ts' => time()];
                if (count($ring) > 20) array_shift($ring);
                foreach ($followers as $fid => &$f) {
                    if ($f['injector'] === null || !$f['conn']->isConnected()) continue;
                    if (!$f['queue']->push($e)) $stats['dropped']++;
                }
                unset($f);
            }
        }

        // ---- 2) DISPATCH + INJECT (dung khi PAUSED) ----
        if (!$paused) {
            foreach ($followers as $fid => &$f) {
                if ($f['injector'] === null) continue;
                $f['conn']->readMessages(0);
                if (!$f['conn']->isConnected()) continue;
                $guard = 0;
                while (!$f['queue']->isEmpty() && $guard++ < 200) {
                    $e = $f['queue']->shift();
                    if ($e === null) break;
                    if ($e->type === SyncEvent::KEY_DOWN || $e->type === SyncEvent::KEY_UP) {
                        $r = $kbd->inject($f['injector'], (string)$fid, $e);
                    } elseif ($e->type === SyncEvent::TEXT_INPUT) {
                        $n = $textMgr->enterText($f['injector'], $e->text);
                        $r = $n > 0 ? 'ok' : 'failed';
                    } else {
                        $r = $mouse->inject($f['injector'], (string)$fid, $e, $f['vw'], $f['vh']);
                        if ($r === 'ok' && ($e->type === SyncEvent::MOUSE_MOVE || $e->type === SyncEvent::MOUSE_WHEEL)) {
                            $pt = SyncCoordinateMapper::toViewport($e->normalizedX, $e->normalizedY, $f['vw'], $f['vh']);
                            $f['lastX'] = $pt['x'];
                            $f['lastY'] = $pt['y'];
                            // Debug cursor (Phase 9): cham do theo chuot, throttle 100ms
                            if (!empty($cfg['showCursor']) && $now - (float)($cursorAt[$fid] ?? 0) > 0.1) {
                                $cursorAt[$fid] = $now;
                                if (empty($cursorMade[$fid])) {
                                    SyncDebugCursor::ensure($f['conn']);
                                    $cursorMade[$fid] = true;
                                }
                                SyncDebugCursor::move($f['conn'], (float)$pt['x'], (float)$pt['y']);
                            }
                        }
                    }
                    if ($r === 'ok') $stats['dispatched']++;
                    elseif ($r === 'dropped') $stats['dropped']++;
                    else $stats['failed']++;
                }
                $ov = $f['queue']->stats()['overflow'];
                if ($ov > 0 && (($warnedOverflow[$fid] ?? -1) !== $ov)) {
                    $warnedOverflow[$fid] = $ov;
                    SyncLogger::warn('QUEUE_OVERFLOW', "Follower #$fid overflow count=$ov", $fid);
                    echo date('H:i:s') . " WARN follower #$fid QUEUE_OVERFLOW count=$ov\n";
                }
            }
            unset($f);
        }

        if ($now - $lastScan > 3) {
            $lastScan = $now;
            eng_scan_master($mport, $fps, $cap, $masterConns);
        }
        if ($now - $lastVw > 5) {
            $lastVw = $now;
            foreach ($followers as $fid => &$f) {
                if ($f['injector'] === null || !$f['conn']->isConnected()) continue;
                $id = $f['conn']->sendCommand('Runtime.evaluate', [
                    'expression' => '({w:window.innerWidth,h:window.innerHeight})', 'returnByValue' => true]);
                $r = $f['conn']->waitForId($id, 1500);
                $v = $r['result']['result']['value'] ?? null;
                if (is_array($v)) {
                    if (!empty($v['w'])) $f['vw'] = max(1.0, (float)$v['w']);
                    if (!empty($v['h'])) $f['vh'] = max(1.0, (float)$v['h']);
                }
            }
            unset($f);
            // Health 10s: heartbeat + drop conn CDP-chet + theo doi window moved/resized/focus
            if ($now - $lastHealth > 10) {
                $lastHealth = $now;
                eng_heartbeat($sessionId > 0 ? $sessionId : null, $stats, $followers);
                foreach ($followers as $fid => &$f) {
                    // Stale-drop: socket song nhung CDP chet -> drop de reconnect
                    if ($f['injector'] !== null && $f['conn']->isConnected() && $f['port'] > 0 && !cdp_reachable($f['port'])) {
                        $f['conn']->disconnect();
                        $f['injector'] = null;
                        $f['reconnectAt'] = $now;
                        SyncLogger::warn('WINDOW_DISCONNECTED', "Follower #$fid CDP chet (stale-drop)", $fid);
                    }
                }
                unset($f);
                eng_watch_windows($winState);
            }
        }
        if ($now - $lastStat > 10) {
            $lastStat = $now;
            $qs = [];
            foreach ($followers as $fid => $f) $qs[] = "#$fid:" . $f['queue']->size();
            echo date('H:i:s') . ' stat captured=' . $stats['captured'] . ' dispatched=' . $stats['dispatched']
                . ' dropped=' . $stats['dropped'] . ' failed=' . $stats['failed']
                . ' queues=[' . implode(',', $qs) . "]\n";
        }
        if ($now - $lastDebug > 2) {
            $lastDebug = $now;
            eng_debug_snapshot($sessionId > 0 ? $sessionId : null, $masterId, $stats, $followers, $ring);
        }
        usleep(5000);
    } catch (Throwable $e) {
        echo date('H:i:s') . ' engine err: ' . mb_substr($e->getMessage(), 0, 200) . "\n";
        usleep(200000);
    }
}

foreach ($masterConns as $mc) $mc->disconnect();
foreach ($followers as $f) {
    if (isset($f['conn']) && $f['conn'] instanceof SyncCdpConnection) $f['conn']->disconnect();
}
eng_clear_heartbeat($sessionId > 0 ? $sessionId : null);
eng_clear_debug($sessionId > 0 ? $sessionId : null);
echo date('H:i:s') . ' SYNC STOPPED captured=' . $stats['captured'] . ' dispatched=' . $stats['dispatched']
    . ' dropped=' . $stats['dropped'] . ' failed=' . $stats['failed'] . "\n";
