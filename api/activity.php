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
            // Template: bung preset thanh gia tri cu the (§43-45)
            if (!empty($patch['template']) && in_array(strtoupper((string)$patch['template']), ['LIGHT', 'NORMAL', 'HIGH'], true)) {
                $patch = array_merge($patch, ActivityManager::applyTemplate((string)$patch['template']));
            }
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

        case 'concurrency': {
            // Global concurrency §20 (2/4/6/8)
            $b = $method === 'GET' ? $_GET : json_body();
            if (array_key_exists('value', $b)) {
                $v = (int)$b['value'];
                if (!in_array($v, ActivityScheduler::CONCURRENCY_OPTS, true)) {
                    json_out(['ok' => false, 'message' => 'Chỉ hỗ trợ 2/4/6/8'], 400);
                }
                set_setting('act_concurrency', (string)$v);
            }
            json_out(['ok' => true, 'data' => ['value' => ActivityScheduler::concurrency()]]);
            break;
        }

        case 'websites': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => ActivityManager::webList(
                $b['category'] ?? null, !empty($b['enabled_only']))]);
            break;
        }

        case 'website_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = ActivityManager::webSave(isset($b['id']) ? (int)$b['id'] : null, $b);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['id' => $r['id']]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'website_delete': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => ActivityManager::webDelete((int)($b['id'] ?? 0))]);
            break;
        }

        case 'website_import': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => ActivityManager::webImport(
                (string)($b['text'] ?? ''), (string)($b['category'] ?? 'CUSTOM'))]);
            break;
        }

        case 'searchpool': {
            json_out(['ok' => true, 'data' => ActivityManager::searchList(false)]);
            break;
        }

        case 'search_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = ActivityManager::searchSave(isset($b['id']) ? (int)$b['id'] : null, $b);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['id' => $r['id']]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'search_delete': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => ActivityManager::searchDelete((int)($b['id'] ?? 0))]);
            break;
        }

        case 'search_import': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => ActivityManager::searchImport(
                (string)($b['text'] ?? ''), (string)($b['category'] ?? 'CUSTOM'))]);
            break;
        }

        case 'plan': {
            // Today's Plan + summary 1 profile (§17, §41)
            require_once __DIR__ . '/../sync/ActivityPlanner.php';
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            ActivityPlanner::ensureTodayPlan($id);
            json_out(['ok' => true, 'data' => [
                'sessions' => ActivityPlanner::todayPlan($id),
                'summary' => ActivityPlanner::summary($id)]]);
            break;
        }

        case 'regenerate': {
            require_once __DIR__ . '/../sync/ActivityPlanner.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $r = ActivityPlanner::regenerate($id);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['sessions' => $r['sessions']]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 500);
            break;
        }

        case 'run_session': {
            // Chay ngay session due (toi da 1) — nut "Chay ngay" §47
            require_once __DIR__ . '/../sync/ActivityPlanner.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            @set_time_limit(180);
            $n = ActivityPlanner::runDueSessions($id, 1);
            if ($n === 0) {
                // Khong co session due (plan future) -> chay 1 session PLANNED som nhat ngay
                $rows = ActivityPlanner::todayPlan($id);
                $next = null;
                foreach ($rows as $s) {
                    if (($s['status'] ?? '') === 'PLANNED') {
                        $next = $s;
                        break;
                    }
                }
                if ($next) {
                    try {
                        db()->prepare('UPDATE activity_sessions SET run_at=NOW() WHERE id=?')
                            ->execute([(int)$next['id']]);
                    } catch (Throwable $e) {
                    }
                    $n = ActivityPlanner::runDueSessions($id, 1);
                }
            }
            json_out(['ok' => true, 'data' => ['ran' => $n, 'summary' => ActivityPlanner::summary($id)]]);
            break;
        }

        case 'templates': {
            json_out(['ok' => true, 'data' => ActivityManager::TEMPLATES]);
            break;
        }

        case 'daily_summary': {
            // Bao cao ngay hom nay (UI + Telegram dung chung) §59
            require_once __DIR__ . '/../sync/ActivityPlanner.php';
            json_out(['ok' => true, 'data' => ActivityPlanner::dailySummary()]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'activity exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
