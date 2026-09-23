<?php
declare(strict_types=1);
/**
 * api/activity.php - Auto Activity endpoints.
 * GET  ?action=config&id=            (config 1 profile)
 * POST ?action=save {id, ...fields}  (luu config)
 * POST ?action=run {id, task?}       (chay ngay: full cycle hoac 1 task)
 * POST ?action=pause {id, mode}      (1h|today|off)
 * GET  ?action=history&id=&filter=&limit=
 * POST ?action=bulk {ids[], patch}   (gan cau hinh hang loat)
 * GET  ?action=status                (monitoring: enabled/running/waiting/errors)
 * GET/POST ?action=monitor_start|monitor_stop|monitor_status
 */
require_once __DIR__ . '/../sync/ActivityManager.php';
require_once __DIR__ . '/../sync/ActivityScheduler.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'config';

function act_pid_file(): string
{
    return __DIR__ . '/../bin/.activity_scheduler.pid';
}

function act_monitor_pid(): ?int
{
    $f = act_pid_file();
    if (!is_file($f)) return null;
    $pid = (int)trim((string)@file_get_contents($f));
    if ($pid <= 0) return null;
    $out = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
    foreach ($out as $line) {
        if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
            && stripos($m[1], 'php') !== false) {
            return $pid;
        }
    }
    return null;
}

function act_spawn(): bool
{
    $php = php_cli_binary();
    if ($php === '') return false;
    $script = __DIR__ . '/../bin/activity_scheduler.php';
    $log = __DIR__ . '/../bin/activity_scheduler.log';
    if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
    $cmdline = 'start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1';
    pclose(popen($cmdline, 'r'));
    for ($i = 0; $i < 10; $i++) {
        usleep(500000);
        if (act_monitor_pid() !== null) return true;
    }
    return false;
}

try {
    switch ($action) {
        case 'config': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $cfg = ActivityManager::getConfig($id);
            $st = ActivityManager::stateGet($id);
            $cfg['running_task'] = $st['running_task'] ?? null;
            $cfg['scheduler_running'] = act_monitor_pid() !== null;
            json_out(['ok' => true, 'data' => $cfg]);
            break;
        }

        case 'save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $r = ActivityManager::saveConfig($id, $b);
            json_out(['ok' => $r['ok'], 'data' => ActivityManager::getConfig($id), 'warnings' => $r['errors']]);
            break;
        }

        case 'run': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            @set_time_limit(120);
            if (!empty($b['task']) && is_array($b['task'])) {
                $r = ActivityManager::runTask($id, $b['task']);
                json_out(['ok' => !empty($r['ok']), 'data' => $r]);
            }
            $r = ActivityManager::runCycle($id);
            $ok = true;
            foreach ($r['tasks'] as $t) {
                if (empty($t['ok']) && ($t['result'] ?? '') !== ActivityManager::R_REUSED) {
                    $ok = $ok && in_array($t['result'] ?? '', [ActivityManager::R_REUSED, ActivityManager::R_CHECKED], true);
                }
            }
            json_out(['ok' => $ok, 'data' => $r]);
            break;
        }

        case 'pause': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $mode = (string)($b['mode'] ?? 'off');
            $until = null;
            if ($mode === '1h') $until = date('Y-m-d H:i:s', time() + 3600);
            elseif ($mode === 'today') $until = date('Y-m-d 23:59:59');
            $cfg = ActivityManager::getConfig($id);
            $cfg['pause_until'] = $until;
            $r = ActivityManager::saveConfig($id, $cfg);
            json_out(['ok' => $r['ok'], 'data' => ['pause_until' => $until]]);
            break;
        }

        case 'history': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $filter = (string)($_GET['filter'] ?? 'all');
            if (!in_array($filter, ['all', 'site', 'search', 'error'], true)) $filter = 'all';
            json_out(['ok' => true, 'data' => ActivityManager::history($id, $filter, (int)($_GET['limit'] ?? 100))]);
            break;
        }

        case 'bulk': {
            $b = $method === 'GET' ? $_GET : json_body();
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])))));
            if (!$ids) json_out(['ok' => false, 'message' => 'Chua chon kenh nao'], 400);
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $done = 0;
            $errors = [];
            foreach ($ids as $pid) {
                $cur = ActivityManager::getConfig($pid);
                $merged = array_merge($cur, $patch);
                // required_pages/search_queries tu patch thay the han (khong merge)
                $r = ActivityManager::saveConfig($pid, $merged);
                if ($r['ok']) $done++;
                else $errors[] = $pid;
            }
            json_out(['ok' => true, 'data' => ['updated' => $done, 'total' => count($ids), 'errors' => $errors]]);
            break;
        }

        case 'status': {
            json_out(['ok' => true, 'data' => ActivityScheduler::status()
                + ['scheduler_running' => act_monitor_pid() !== null]]);
            break;
        }

        case 'monitor_status': {
            $pid = act_monitor_pid();
            json_out(['ok' => true, 'data' => ['running' => $pid !== null, 'pid' => $pid]]);
            break;
        }

        case 'monitor_start': {
            $pid = act_monitor_pid();
            if ($pid !== null) json_out(['ok' => true, 'data' => ['running' => true, 'pid' => $pid]]);
            if (!act_spawn()) json_out(['ok' => false, 'message' => 'Khong spawn duoc scheduler'], 500);
            SyncLogger::info('activity', '[Scheduler] started via API');
            json_out(['ok' => true, 'data' => ['running' => true, 'pid' => act_monitor_pid()]]);
            break;
        }

        case 'monitor_stop': {
            $pid = act_monitor_pid();
            if ($pid !== null) {
                @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
            }
            SyncLogger::info('activity', '[Scheduler] stopped via API');
            json_out(['ok' => true, 'data' => ['running' => false]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'activity exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
