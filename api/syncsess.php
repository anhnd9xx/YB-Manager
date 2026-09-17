<?php
declare(strict_types=1);
/**
 * api/syncsess.php - Quan ly SyncSession (module Synchronize, PHASE 6).
 * Actions (GET/POST JSON):
 *   get?id?            session + bang roles + engine alive
 *   create             {name, main, controlled[], config?}
 *   update_config      {id, config}
 *   start|stop|pause|resume|restart {id}
 *   set_main           {id, profileId}
 *   add_controlled|remove_controlled {id, profileId}
 *   reconnect          {id, profileId}
 *   delete             {id}
 */
require_once __DIR__ . '/../sync/SyncSession.php';
require_once __DIR__ . '/../sync/MainWindowManager.php';
require_once __DIR__ . '/../sync/ControlledWindowManager.php';
require_once __DIR__ . '/../sync/HealthMonitor.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$action = $_GET['action'] ?? 'get';

function sess_engine_pid(?int $sessionId): ?int
{
    if ($sessionId === null || $sessionId <= 0) return null;
    try {
        $st = db()->prepare('SELECT engine_pid FROM sync_sessions WHERE id=?');
        $st->execute([$sessionId]);
        $pid = (int)($st->fetchColumn() ?? 0);
        return $pid > 0 ? $pid : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Engine con song khong? Kiem tra PID trong DB + tasklist (nhanh, khong WMI/wmic). */
function sess_engine_alive(?int $sessionId): bool
{
    $pid = sess_engine_pid($sessionId);
    if ($pid === null) return false;
    $out = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
    foreach ($out as $line) {
        // CSV: "Image Name","PID",... — khop PID va image php.exe
        if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
            && stripos($m[1], 'php') !== false) {
            return true;
        }
    }
    return false;
}

function sess_spawn_engine(int $sessionId): bool
{
    $php = php_cli_binary();
    if ($php === '') return false;
    $script = __DIR__ . '/../bin/sync_engine.php';
    // Log ra file de debug + giam sat (spec Logging/Debug): xoay khi > 2MB
    $log = __DIR__ . '/../bin/sync_engine.log';
    if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
    // start /B: detach NGAY LAP TUC, khong treo nhu PowerShell Start-Process
    // duoi Apache (Start-Process treo >100s khong on dinh). Engine chi mo
    // outbound TCP (khong LISTEN) nen khong vuong van de socket cua start /B
    // (van de do chi anh huong relay phuc vu socket - van giu Start-Process).
    $cmdline = 'start "" /B "' . $php . '" -f "' . $script . '" -- --session=' . $sessionId
        . ' >> "' . $log . '" 2>&1';
    pclose(popen($cmdline, 'r'));
    for ($i = 0; $i < 12; $i++) {
        usleep(500000);
        if (sess_engine_alive($sessionId)) return true;
    }
    return false;
}

function sess_kill_engine(?int $pid, ?int $sessionId): void
{
    // Gop kill theo PID + quet cmdline vao 1 luot PowerShell duy nhat (API nhanh)
    $parts = [];
    if ($pid !== null && $pid > 0) {
        $parts[] = 'Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue';
    }
    $pat = $sessionId !== null ? '*sync_engine.php*--session=' . $sessionId . '*' : '*sync_engine.php*';
    $parts[] = 'Get-CimInstance Win32_Process -Filter "Name=\'php.exe\'"'
        . ' | Where-Object { $_.CommandLine -like ' . "'" . $pat . "'" . ' }'
        . ' | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }';
    @shell_exec('powershell -NoProfile -Command "' . implode('; ', $parts) . '"');
}

/** Bang roles cho UI: moi profile + role + trang thai live (gom ca minimized). */
function sess_roles_table(?SyncSession $s): array
{
    $wins = [];
    foreach (SyncWindowDiscovery::discover()['windows'] as $w) {
        if ($w->class !== 'Chrome_WidgetWin_1' || !$w->visible) continue;
        if ($w->rect === null || $w->rect['w'] <= 0 || $w->rect['h'] <= 0) continue;
        if ($w->profileId !== null && !isset($wins[$w->profileId])) $wins[$w->profileId] = $w;
    }
    $out = [];
    foreach (db()->query('SELECT id, name, status, debug_port, sync_role FROM profiles ORDER BY id') as $p) {
        $pid = (int)$p['id'];
        $role = (string)($p['sync_role'] ?? 'NONE');
        $w = $wins[$pid] ?? null;
        if ($role === 'NONE') $st = 'IDLE';
        elseif ($w === null) $st = ($p['status'] === 'running') ? 'CRASHED' : 'CLOSED';
        elseif ($s !== null && $s->state === SyncSession::RUNNING) $st = 'SYNCING';
        elseif ($s !== null && $s->state === SyncSession::PAUSED) $st = 'PAUSED';
        elseif ($s !== null && $s->state === SyncSession::STARTING) $st = 'STARTING';
        elseif ($s !== null && $s->state === SyncSession::STOPPING) $st = 'STOPPING';
        else $st = ($p['status'] === 'running') ? 'RUNNING' : 'STOPPED';
        $out[] = ['id' => $pid, 'name' => $p['name'], 'role' => $role, 'status' => $st,
                  'hwnd' => $w?->hwnd, 'rect' => $w?->rect, 'browser' => $p['status']];
    }
    return $out;
}

try {
    switch ($action) {
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            $s = $id > 0 ? SyncSession::load($id) : (SyncSession::latest() ?? SyncSession::getActive());
            if (!$s) json_out(['ok' => true, 'data' => ['session' => null, 'roles' => sess_roles_table(null), 'engine' => false]]);
            json_out(['ok' => true, 'data' => ['session' => $s->toArray(), 'roles' => sess_roles_table($s),
                'engine' => sess_engine_alive($s->id)]]);
            break;
        }

        case 'create': {
            $b = json_body();
            $s = SyncSession::create((string)($b['name'] ?? ''), (int)($b['main'] ?? 0),
                (array)($b['controlled'] ?? []), (array)($b['config'] ?? []));
            SyncLogger::info('SYNC_START', "Session #{$s->id} created (main #{$s->mainProfileId})", $s->mainProfileId);
            log_action($s->mainProfileId, 'sync_session_create', "Session #{$s->id}");
            json_out(['ok' => true, 'data' => $s->toArray()], 201);
            break;
        }

        case 'update_config': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? $_GET['id'] ?? 0));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            if (in_array($s->state, [SyncSession::STARTING, SyncSession::STOPPING], true)) {
                json_out(['ok' => false, 'message' => 'Khong doi config khi state=' . $s->state], 400);
            }
            $s->config = SyncSession::normalizeConfig((array)($b['config'] ?? []));
            $s->save();
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'start': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? ($_GET['id'] ?? 0)));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            $other = SyncSession::getActive();
            if ($other && $other->id !== $s->id) {
                json_out(['ok' => false, 'message' => 'Dang co session khac ACTIVE (stop truoc)'], 400);
            }
            if (!in_array($s->state, [SyncSession::IDLE, SyncSession::STOPPED, SyncSession::ERROR], true)) {
                json_out(['ok' => false, 'message' => 'State hien tai: ' . $s->state], 400);
            }
            // Validate MAIN + CONTROLLED (spec muc 30: buoc 1-5)
            try {
                if (SyncMainManager::liveWindow($s->mainProfileId) === null) {
                    json_out(['ok' => false, 'message' => "MAIN #{$s->mainProfileId} chua mo"], 400);
                }
                foreach ($s->controlledIds as $cid) {
                    SyncControlledManager::validate($cid, $s->config['inputMode'] ?? 'AUTO');
                }
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            $s->transition(SyncSession::STARTING);
            if (!sess_spawn_engine($s->id)) {
                try { $s->transition(SyncSession::ERROR, 'Khong spawn duoc engine'); } catch (Throwable $e) {}
                json_out(['ok' => false, 'message' => 'Khong spawn duoc engine'], 500);
            }
            SyncLogger::info('SYNC_START', "Session #{$s->id} STARTING", $s->mainProfileId);
            log_action($s->mainProfileId, 'sync_start', "Session #{$s->id}");
            json_out(['ok' => true, 'data' => SyncSession::load($s->id)->toArray()]);
            break;
        }

        case 'stop': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? ($_GET['id'] ?? 0)));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            if (!in_array($s->state, [SyncSession::RUNNING, SyncSession::PAUSED, SyncSession::STARTING, SyncSession::ERROR], true)) {
                // IDLE/STOPPED/STOPPING (khong nam trong mang) -> idempotent, bam Stop 2 lan khong loi
                json_out(['ok' => true, 'data' => $s->toArray(), 'message' => 'Session khong chay']);
            }
            try { $s->transition(SyncSession::STOPPING); } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            // Cho engine tu thoat (drain) toi da ~6s, roi kill backup. KHONG dong browser.
            for ($i = 0; $i < 12; $i++) {
                usleep(500000);
                $cur = SyncSession::load($s->id);
                if ($cur && $cur->state === SyncSession::STOPPED && !sess_engine_alive($s->id)) break;
            }
            sess_kill_engine($s->enginePid, $s->id);
            $cur = SyncSession::load($s->id);
            if ($cur && $cur->state !== SyncSession::STOPPED) {
                try { $cur->transition(SyncSession::STOPPED); } catch (Throwable $e) {}
                $cur->enginePid = null;
                $cur->save();
            }
            SyncLogger::info('SYNC_STOP', "Session #{$s->id} STOPPED (browser giu nguyen)");
            log_action(null, 'sync_stop', "Session #{$s->id}");
            json_out(['ok' => true, 'data' => SyncSession::load($s->id)->toArray()]);
            break;
        }

        case 'pause': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? $_GET['id'] ?? 0));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            try { $s->transition(SyncSession::PAUSED); } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'resume': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? $_GET['id'] ?? 0));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            try { $s->transition(SyncSession::RUNNING); } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'restart': {
            // stopSync -> cleanup -> reconnect -> startSync, khong restart browser
            $b = json_body();
            $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
            $_GET['id'] = $id;
            // goi lai noi bo: stop roi start (don gian, deterministic)
            $s = SyncSession::load($id);
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            if (in_array($s->state, [SyncSession::RUNNING, SyncSession::PAUSED, SyncSession::ERROR], true)) {
                try { $s->transition(SyncSession::STOPPING); } catch (Throwable $e) {}
                for ($i = 0; $i < 12; $i++) {
                    usleep(500000);
                    $cur = SyncSession::load($id);
                    if ($cur && $cur->state === SyncSession::STOPPED && !sess_engine_alive($id)) break;
                }
                sess_kill_engine($s->enginePid, $id);
                $s = SyncSession::load($id);
                if ($s->state !== SyncSession::STOPPED) {
                    try { $s->transition(SyncSession::STOPPED); } catch (Throwable $e) {}
                }
            }
            if (!in_array($s->state, [SyncSession::IDLE, SyncSession::STOPPED, SyncSession::ERROR], true)) {
                json_out(['ok' => false, 'message' => 'Khong restart duoc tu state=' . $s->state], 400);
            }
            try {
                if (SyncMainManager::liveWindow($s->mainProfileId) === null) {
                    json_out(['ok' => false, 'message' => "MAIN #{$s->mainProfileId} chua mo"], 400);
                }
                foreach ($s->controlledIds as $cid) SyncControlledManager::validate($cid, $s->config['inputMode'] ?? 'AUTO');
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            $s->transition(SyncSession::STARTING);
            if (!sess_spawn_engine($s->id)) {
                try { $s->transition(SyncSession::ERROR, 'Khong spawn duoc engine'); } catch (Throwable $e) {}
                json_out(['ok' => false, 'message' => 'Khong spawn duoc engine'], 500);
            }
            SyncLogger::info('SYNC_RESTART', "Session #{$s->id} restarted");
            json_out(['ok' => true, 'data' => SyncSession::load($s->id)->toArray()]);
            break;
        }

        case 'set_main': {
            $b = json_body();
            try {
                $s = SyncMainManager::setMain((int)($b['id'] ?? $_GET['id'] ?? 0), (int)($b['profileId'] ?? 0));
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'add_controlled': {
            $b = json_body();
            try {
                $s = SyncControlledManager::add((int)($b['id'] ?? $_GET['id'] ?? 0), (int)($b['profileId'] ?? 0));
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'remove_controlled': {
            $b = json_body();
            try {
                $s = SyncControlledManager::remove((int)($b['id'] ?? $_GET['id'] ?? 0), (int)($b['profileId'] ?? 0));
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'reconnect': {
            $b = json_body();
            try {
                $s = SyncControlledManager::reconnect((int)($b['id'] ?? $_GET['id'] ?? 0), (int)($b['profileId'] ?? 0));
            } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true, 'data' => $s->toArray()]);
            break;
        }

        case 'delete': {
            $b = json_body();
            $s = SyncSession::load((int)($b['id'] ?? $_GET['id'] ?? 0));
            if (!$s) json_out(['ok' => false, 'message' => 'Session khong ton tai'], 404);
            try { $s->delete(); } catch (RuntimeException $e) {
                json_out(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            json_out(['ok' => true]);
            break;
        }

        case 'health': {
            // Kiem tra suc khoe session + tung profile (?id=, bo qua = session active)
            $id = (int)($_GET['id'] ?? 0);
            json_out(['ok' => true, 'data' => SyncHealthMonitor::check($id > 0 ? $id : null)]);
            break;
        }

        case 'debug': {
            // Debug Mode (Phase 9): snapshot engine (last event, tung target) + master info
            $id = (int)($_GET['id'] ?? 0);
            $s = $id > 0 ? SyncSession::load($id) : SyncSession::getActive();
            $snap = null;
            if ($s) {
                $f = __DIR__ . '/../bin/sync_debug_' . $s->id . '.json';
                if (is_file($f)) {
                    $j = json_decode((string)@file_get_contents($f), true);
                    if (is_array($j)) $snap = $j;
                }
            }
            json_out(['ok' => true, 'data' => [
                'session' => $s?->toArray(),
                'engine' => $s ? sess_engine_alive($s->id) : false,
                'snapshot' => $snap,
                'snapshotAge' => ($snap && isset($snap['ts'])) ? (time() - (int)$snap['ts']) : null,
            ]]);
            break;
        }

        case 'logs': {
            // Tail sync/sync.log cho Debug Mode (?limit=, ?level=)
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $level = strtoupper(trim((string)($_GET['level'] ?? '')));
            $rows = [];
            $f = __DIR__ . '/../sync/sync.log';
            if (is_file($f)) {
                $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines)) {
                    foreach (array_slice($lines, -$limit) as $ln) {
                        $j = json_decode($ln, true);
                        if (!is_array($j)) continue;
                        if ($level !== '' && ($j['level'] ?? '') !== $level) continue;
                        $rows[] = $j;
                    }
                }
            }
            json_out(['ok' => true, 'data' => array_reverse($rows)]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'syncsess exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
