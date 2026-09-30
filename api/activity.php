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
require_once __DIR__ . '/../sync/ActivitySupervisor.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'config';

// Boot ensure (§47, DoD #10): co config enabled ma worker chet/stale -> tu spawn.
// Ap dung cho moi action thay doi/doc lich (bulk/save/run/status/config/plan),
// khong chi 3 action cu — luu bulk khong bao gio khoi dong worker la bug cu.
if (in_array($action, ['status', 'config', 'plan', 'bulk', 'save', 'run', 'run_now', 'diagnose'], true)) {
    try {
        $n = 0;
        try {
            $n = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE enabled=1')->fetchColumn();
        } catch (Throwable $e) {
        }
        if ($n > 0) ActivitySupervisor::ensure();
    } catch (Throwable $e) {
    }
}

function act_pid_file(): string
{
    return ActivitySupervisor::pidFile();
}

function act_monitor_pid(): ?int
{
    $pid = ActivitySupervisor::pid();
    return ActivitySupervisor::alive($pid) ? $pid : null;
}

function act_spawn(): bool
{
    return ActivitySupervisor::spawn();
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
            $cfg = ActivityManager::getConfig($id);
            json_out(['ok' => $r['ok'], 'data' => $cfg, 'warnings' => $r['errors'],
                'config_version' => (int)($cfg['config_version'] ?? 1)]);
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
            try {
                SyncLogger::info('activity', '[7 BACKEND_RECEIVED] keys=[' . implode(',', array_keys($b)) . ']'
                    . ' profile_ids=[' . implode(',', array_slice($ids, 0, 20)) . '] count=' . count($ids));
            } catch (Throwable $e) {
            }
            if (!$ids) json_out(['ok' => false, 'message' => 'Chua chon kenh nao'], 400);
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $out = ActivityManager::bulkSave($ids, $patch);
            // Len lich ngay cho kenh duoc bat (UI hien "Tiep theo" lap tuc §29)
            foreach ($out['results']['ok'] as $pid) {
                try {
                    $cfg = ActivityManager::getConfig((int)$pid);
                    if (!empty($cfg['enabled'])) {
                        require_once __DIR__ . '/../sync/ActivityPlanner.php';
                        ActivityPlanner::ensureTodayPlan((int)$pid);
                        ActivityPlanner::updateNextRun((int)$pid);
                    } else {
                        // Tat: xoa next_run cu (§39, khong xoa config)
                        db()->prepare('UPDATE activity_configs SET next_run_at=NULL WHERE profile_id=?')
                            ->execute([(int)$pid]);
                    }
                } catch (Throwable $e) {
                }
            }
            json_out(['ok' => true, 'data' => [
                'requested' => $out['requested'], 'assigned' => $out['assigned'],
                'updated' => $out['updated'], 'created' => $out['created'],
                'skipped' => 0, 'failed' => $out['failed'],
                'total' => $out['requested'], 'results' => $out['results'],
                'config_versions' => $out['config_versions']]]);
            break;
        }

        case 'bulk_existing': {
            // Dem kenh da co lich (modal warning §37)
            $b = $method === 'GET' ? $_GET : json_body();
            $raw = (string)($b['ids'] ?? '');
            $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
            $n = 0;
            try {
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $st = db()->prepare("SELECT COUNT(*) FROM activity_configs WHERE profile_id IN ($in)");
                    $st->execute($ids);
                    $n = (int)$st->fetchColumn();
                }
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'data' => ['existing' => $n, 'total' => count($ids)]]);
            break;
        }

        case 'status': {
            json_out(['ok' => true, 'data' => ActivityScheduler::status()
                + ['scheduler_running' => act_monitor_pid() !== null]]);
            break;
        }

        case 'monitor_status': {
            json_out(['ok' => true, 'data' => ActivitySupervisor::state()
                + ['running' => ActivitySupervisor::state()['state'] === 'RUNNING']]);
            break;
        }

        case 'monitor_start': {
            $r = ActivitySupervisor::ensure(true);
            SyncLogger::info('activity', '[Scheduler] started via API');
            json_out(['ok' => $r['running'], 'data' => $r]);
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

        case 'websites_table': {
            // Bang co paging/filter/search (§40, §67-68, §73-74)
            $b = $method === 'GET' ? $_GET : json_body();
            $page = max(1, (int)($b['page'] ?? 1));
            $per = max(5, min(100, (int)($b['per'] ?? 20)));
            $q = trim((string)($b['q'] ?? ''));
            $cat = (string)($b['category'] ?? 'all');
            $en = (string)($b['enabled'] ?? 'all');
            try {
                ActivityManager::ensureTables();
                $w = [];
                $p = [];
                if ($cat !== 'all' && $cat !== '') {
                    $w[] = 'category=?';
                    $p[] = $cat;
                }
                if ($en === '1') $w[] = 'enabled=1';
                elseif ($en === '0') $w[] = 'enabled=0';
                if ($q !== '') {
                    $w[] = '(name LIKE ? OR domain LIKE ? OR url LIKE ?)';
                    $p[] = "%$q%";
                    $p[] = "%$q%";
                    $p[] = "%$q%";
                }
                $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
                $total = 0;
                try {
                    $st = db()->prepare("SELECT COUNT(*) FROM activity_websites $where");
                    $st->execute($p);
                    $total = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                }
                $off = ($page - 1) * $per;
                $st = db()->prepare("SELECT *, (usage_today=CURDATE()) AS used_today_flag
                    FROM activity_websites $where ORDER BY id DESC LIMIT $per OFFSET $off");
                $st->execute($p);
                json_out(['ok' => true, 'data' => ['rows' => $st->fetchAll(), 'total' => $total,
                    'page' => $page, 'per' => $per]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi tải'], 500);
            }
            break;
        }

        case 'websites_bulk': {
            // Enable/disable/category/weight/delete hang loat (§72)
            $b = $method === 'GET' ? $_GET : json_body();
            $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
            if (!$ids) json_out(['ok' => false, 'message' => 'Chưa chọn'], 400);
            $op = (string)($b['op'] ?? '');
            $in = implode(',', array_fill(0, count($ids), '?'));
            try {
                ActivityManager::ensureTables();
                if ($op === 'enable') db()->prepare("UPDATE activity_websites SET enabled=1 WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'disable') db()->prepare("UPDATE activity_websites SET enabled=0 WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'delete') db()->prepare("DELETE FROM activity_websites WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'category') {
                    $cat = strtoupper((string)($b['category'] ?? 'CUSTOM'));
                    if (!in_array($cat, ActivityManager::WEB_CATEGORIES, true)) json_out(['ok' => false, 'message' => 'Category sai'], 400);
                    db()->prepare("UPDATE activity_websites SET category=? WHERE id IN ($in)")->execute(array_merge([$cat], $ids));
                } elseif ($op === 'weight') {
                    $w = max(1, min(10, (int)($b['weight'] ?? 1)));
                    db()->prepare("UPDATE activity_websites SET weight=? WHERE id IN ($in)")->execute(array_merge([$w], $ids));
                } else json_out(['ok' => false, 'message' => 'Op không hỗ trợ'], 400);
                json_out(['ok' => true, 'data' => ['done' => true]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi'], 500);
            }
            break;
        }

        case 'websites_export': {
            // Export TXT/CSV (§38)
            $b = $method === 'GET' ? $_GET : json_body();
            $fmt = strtolower((string)($b['format'] ?? 'txt'));
            $rows = ActivityManager::webList(null, false);
            if ($fmt === 'csv') {
                $out = "name,domain,url,category,enabled,weight\n";
                foreach ($rows as $r) {
                    $out .= '"' . str_replace('"', '""', (string)$r['name']) . '",'
                        . (string)$r['domain'] . ',"' . str_replace('"', '""', (string)$r['url']) . '",'
                        . (string)$r['category'] . ',' . (int)$r['enabled'] . ',' . (int)$r['weight'] . "\n";
                }
            } else {
                $lines = [];
                foreach ($rows as $r) $lines[] = $r['name'] . ' | ' . $r['url'];
                $out = implode("\n", $lines);
            }
            json_out(['ok' => true, 'data' => ['text' => $out, 'count' => count($rows)]]);
            break;
        }

        case 'website_test': {
            // Test reachable co ban (§16): khong login, khong session user
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            try {
                ActivityManager::ensureTables();
                $st = db()->prepare('SELECT * FROM activity_websites WHERE id=?');
                $st->execute([$id]);
                $w = $st->fetch();
                if (!$w) json_out(['ok' => false, 'message' => 'Không thấy'], 404);
                $ch = curl_init((string)$w['url']);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true,
                    CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 YTM-Checker']);
                curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errno = curl_errno($ch);
                curl_close($ch);
                $status = ($errno === 0 && $http >= 200 && $http < 400) ? 'OK'
                    : (($errno !== 0) ? 'TIMEOUT' : 'FAIL');
                json_out(['ok' => true, 'data' => ['status' => $status, 'http' => $http]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi test'], 500);
            }
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

        case 'searchpool_table': {
            // Bang query co paging/filter (§40, §67, §73)
            $b = $method === 'GET' ? $_GET : json_body();
            $page = max(1, (int)($b['page'] ?? 1));
            $per = max(5, min(100, (int)($b['per'] ?? 20)));
            $q = trim((string)($b['q'] ?? ''));
            $cat = (string)($b['category'] ?? 'all');
            $en = (string)($b['enabled'] ?? 'all');
            $used = (string)($b['used'] ?? 'all'); // today|never|all
            try {
                ActivityManager::ensureTables();
                $w = [];
                $p = [];
                if ($cat !== 'all' && $cat !== '') {
                    $w[] = 'category=?';
                    $p[] = $cat;
                }
                if ($en === '1') $w[] = 'enabled=1';
                elseif ($en === '0') $w[] = 'enabled=0';
                if ($used === 'today') $w[] = 'use_today=CURDATE()';
                elseif ($used === 'never') $w[] = 'use_count=0';
                if ($q !== '') {
                    $w[] = 'query LIKE ?';
                    $p[] = "%$q%";
                }
                $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
                $total = 0;
                try {
                    $st = db()->prepare("SELECT COUNT(*) FROM activity_search_pool $where");
                    $st->execute($p);
                    $total = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                }
                $off = ($page - 1) * $per;
                $st = db()->prepare("SELECT * FROM activity_search_pool $where
                    ORDER BY id DESC LIMIT $per OFFSET $off");
                $st->execute($p);
                json_out(['ok' => true, 'data' => ['rows' => $st->fetchAll(), 'total' => $total,
                    'page' => $page, 'per' => $per]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi tải'], 500);
            }
            break;
        }

        case 'searchpool_bulk': {
            $b = $method === 'GET' ? $_GET : json_body();
            $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
            if (!$ids) json_out(['ok' => false, 'message' => 'Chưa chọn'], 400);
            $op = (string)($b['op'] ?? '');
            $in = implode(',', array_fill(0, count($ids), '?'));
            try {
                ActivityManager::ensureTables();
                if ($op === 'enable') db()->prepare("UPDATE activity_search_pool SET enabled=1 WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'disable') db()->prepare("UPDATE activity_search_pool SET enabled=0 WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'delete') db()->prepare("DELETE FROM activity_search_pool WHERE id IN ($in)")->execute($ids);
                elseif ($op === 'category') {
                    $cat = strtoupper((string)($b['category'] ?? 'CUSTOM'));
                    if (!in_array($cat, ActivityManager::WEB_CATEGORIES, true)) json_out(['ok' => false, 'message' => 'Category sai'], 400);
                    db()->prepare("UPDATE activity_search_pool SET category=? WHERE id IN ($in)")->execute(array_merge([$cat], $ids));
                } elseif ($op === 'weight') {
                    $w = max(1, min(10, (int)($b['weight'] ?? 1)));
                    db()->prepare("UPDATE activity_search_pool SET weight=? WHERE id IN ($in)")->execute(array_merge([$w], $ids));
                } else json_out(['ok' => false, 'message' => 'Op không hỗ trợ'], 400);
                json_out(['ok' => true, 'data' => ['done' => true]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi'], 500);
            }
            break;
        }

        case 'searchpool_export': {
            $b = $method === 'GET' ? $_GET : json_body();
            $fmt = strtolower((string)($b['format'] ?? 'txt'));
            $rows = ActivityManager::searchList(false);
            if ($fmt === 'csv') {
                $out = "query,category,enabled,weight\n";
                foreach ($rows as $r) {
                    $out .= '"' . str_replace('"', '""', (string)$r['query']) . '",'
                        . (string)$r['category'] . ',' . (int)$r['enabled'] . ',' . (int)$r['weight'] . "\n";
                }
            } else {
                $lines = [];
                foreach ($rows as $r) $lines[] = $r['query'];
                $out = implode("\n", $lines);
            }
            json_out(['ok' => true, 'data' => ['text' => $out, 'count' => count($rows)]]);
            break;
        }

        case 'pools_stats': {
            // Stats pool (§55-57) + daily stats (§55)
            try {
                ActivityManager::ensureTables();
                $sq = db()->query('SELECT COUNT(*) t, SUM(enabled=1) e,
                        SUM(use_today=CURDATE()) u, COUNT(DISTINCT CASE WHEN use_today=CURDATE() THEN query END) uq
                    FROM activity_search_pool')->fetch();
                $wb = db()->query('SELECT COUNT(*) t, SUM(enabled=1) e,
                        SUM(usage_today=CURDATE()) u, COUNT(DISTINCT CASE WHEN usage_today=CURDATE() THEN domain END) uq
                    FROM activity_websites')->fetch();
                $dy = db()->query("SELECT
                        SUM(task_type='OPEN_SEARCH') s,
                        SUM(task_type='OPEN_SEARCH' AND result='SUCCESS') s_ok,
                        SUM(task_type='OPEN_SEARCH' AND result='BLOCKED') s_block,
                        SUM(task_type='SEARCH_VISIT') v,
                        SUM(task_type='SEARCH_VISIT' AND result='SUCCESS') v_ok,
                        SUM(task_type='SEARCH_VISIT' AND result='VISIT_FAILED') v_fail
                    FROM activity_history WHERE created_at>=CURDATE()")->fetch();
                json_out(['ok' => true, 'data' => ['search_pool' => $sq ?: [], 'web_pool' => $wb ?: [],
                    'daily' => $dy ?: []]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Lỗi'], 500);
            }
            break;
        }

        case 'search_test': {
            // Chay thu 1 profile: query select -> search -> filter -> visit (§75-76).
            // Khong thay daily counters (test_mode).
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            @set_time_limit(120);
            require_once __DIR__ . '/../sync/ActivityContentSelector.php';
            // Test mode: bo qua cooldown nhung KHONG doi counters
            $q = ActivityContentSelector::select_query($id, 120, false, true);
            if (!$q) json_out(['ok' => false, 'message' => 'Pool trống hoặc cooldown'], 400);
            $cfg = ActivityManager::getConfig($id);
            $r = ActivityManager::runTask($id, ['type' => ActivityManager::T_SEARCH_VISIT,
                'query' => (string)$q['query'], 'depth' => (int)$cfg['max_result_depth'], 'test_mode' => true]);
            $d = is_array($r['detail'] ?? null) ? $r['detail'] : [];
            json_out(['ok' => !empty($r['ok']), 'data' => [
                'query' => (string)$q['query'],
                'search' => !empty($r['ok']) || ($r['result'] ?? '') === 'SUCCESS' ? 'PASS' : 'FAIL',
                'organic' => $d['organic'] ?? 0,
                'approved' => $d['approved'] ?? 0,
                'selected' => $d['selected'] ?? null,
                'visit' => $d['visit'] ?? 'SKIPPED',
                'reason' => $d['reason'] ?? ($r['error'] ?? ''),
                'ms' => $r['ms'] ?? 0]]);
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
            if ($n === 0) {
                // Van khong co gi due (VD ngay nghi) -> chay legacy cycle 1 lan,
                // cung executor (khong executor rieng), bao ket qua truc tiep
                $r = ActivityManager::runCycle($id);
                json_out(['ok' => true, 'data' => ['ran' => 1, 'fallback' => 'cycle',
                    'tasks' => $r['tasks'], 'summary' => ActivityPlanner::summary($id)]]);
            }
            json_out(['ok' => true, 'data' => ['ran' => $n, 'summary' => ActivityPlanner::summary($id)]]);
            break;
        }

        case 'run_now': {
            // Chay thu ngay: bypass schedule timing, dung cung config + tab executor.
            // Tra ve diagnostics chi tiet (chrome/cdp/mode/tung tab/result).
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            @set_time_limit(180);
            ActivityManager::ensureTables();
            $cfg = ActivityManager::getConfig($id);
            try {
                $pst = db()->prepare('SELECT status, debug_port, user_data_dir FROM profiles WHERE id=?');
                $pst->execute([$id]);
                $prof = $pst->fetch() ?: [];
            } catch (Throwable $e) {
                $prof = [];
            }
            $chromeRunning = (($prof['status'] ?? '') === 'running');
            if (!$chromeRunning) {
                try {
                    $dir = strtolower(trim((string)($prof['user_data_dir'] ?? '')));
                    $chromeRunning = $dir !== '' && isset(chrome_running_info()[$dir]);
                } catch (Throwable $e) {
                }
            }
            $port = (int)($prof['debug_port'] ?? 0);
            $cdpReady = $port > 0 && cdp_reachable($port);
            if (empty($cfg['enabled'])) {
                json_out(['ok' => false, 'message' => 'Auto Activity dang TAT (bat len truoc khi chay thu)'], 400);
            }
            if (!$chromeRunning || !$cdpReady) {
                json_out(['ok' => false, 'message' => 'Chrome chua chay hoac CDP chua san sang (mo kenh truoc)',
                    'data' => ['chrome_running' => $chromeRunning, 'cdp_ready' => $cdpReady]], 409);
            }
            $t0 = microtime(true);
            $r = ActivityManager::runCycle($id);
            $tabs = [];
            foreach ((array)($r['tasks'] ?? []) as $tk) {
                $res = (string)($tk['result'] ?? '');
                $tabs[] = ['label' => (string)($tk['label'] ?? '?'),
                    'result' => in_array($res, ['OPENED'], true) ? 'CREATED'
                        : (in_array($res, ['REUSED', 'CHECKED', 'SUCCESS'], true) ? 'ALREADY_PRESENT' : $res)];
            }
            $okCount = 0;
            foreach ((array)($r['tasks'] ?? []) as $tk) {
                if (!empty($tk['ok']) || in_array(($tk['result'] ?? ''), ['REUSED', 'CHECKED', 'SUCCESS'], true)) $okCount++;
            }
            json_out(['ok' => true, 'data' => [
                'profile_id' => $id,
                'chrome_running' => true, 'cdp_ready' => true,
                'mode' => (string)($cfg['activity_mode'] ?? 'maintain'),
                'required' => count(ActivityManager::requiredUrls($cfg)),
                'tabs' => $tabs,
                'result' => $okCount > 0 ? 'SUCCESS' : 'FAILED',
                'ms' => (int)round((microtime(true) - $t0) * 1000),
                'config_version' => (int)($cfg['config_version'] ?? 1),
                'next_run_at' => (string)($cfg['next_run_at'] ?? ''),
            ]]);
            break;
        }

        case 'diagnose': {
            // Diagnostics 1 profile: config vs scheduler vs runtime (khong can mo UI khac)
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            ActivityManager::ensureTables();
            $cfg = ActivityManager::getConfig($id);
            try {
                $pst = db()->prepare('SELECT status, debug_port, user_data_dir FROM profiles WHERE id=?');
                $pst->execute([$id]);
                $prof = $pst->fetch() ?: [];
            } catch (Throwable $e) {
                $prof = [];
            }
            $chromeRunning = (($prof['status'] ?? '') === 'running');
            if (!$chromeRunning) {
                try {
                    $dir = strtolower(trim((string)($prof['user_data_dir'] ?? '')));
                    $chromeRunning = $dir !== '' && isset(chrome_running_info()[$dir]);
                } catch (Throwable $e) {
                }
            }
            $port = (int)($prof['debug_port'] ?? 0);
            $last = null;
            try {
                $hst = db()->prepare('SELECT task_type, domain, result, error_code, created_at
                    FROM activity_history WHERE profile_id=? ORDER BY id DESC LIMIT 1');
                $hst->execute([$id]);
                $last = $hst->fetch() ?: null;
            } catch (Throwable $e) {
            }
            $schedPid = act_monitor_pid();
            $wst = ActivitySupervisor::state();
            $waitingReason = null;
            if (empty($cfg['enabled'])) $waitingReason = 'DISABLED';
            elseif (!empty($cfg['pause_until']) && strtotime((string)$cfg['pause_until']) > time()) $waitingReason = 'PAUSED';
            elseif (in_array(strtoupper((string)($cfg['runtime_state'] ?? '')), ['SUSPENDED', 'SUSPENDED_ERROR', 'ERROR'], true)) $waitingReason = strtoupper((string)$cfg['runtime_state']);
            elseif ($schedPid === null) $waitingReason = 'SCHEDULER_NOT_RUNNING';
            elseif (ActivityScheduler::inHours($cfg) === false) $waitingReason = 'OFF_HOURS';
            elseif (!$chromeRunning && empty($cfg['auto_start_profile'])) $waitingReason = 'PROFILE_NOT_RUNNING';
            json_out(['ok' => true, 'data' => [
                'profile_id' => $id,
                'config_version' => (int)($cfg['config_version'] ?? 1),
                'desired_enabled' => !empty($cfg['enabled']),
                'scheduler_running' => $schedPid !== null,
                'scheduler_pid' => $schedPid,
                'worker' => $wst,
                'config_loaded' => true,
                'schedule_mode' => (string)($cfg['schedule_mode'] ?? 'WINDOW'),
                'interval' => ((int)($cfg['interval_minutes'] ?? 0)) . 'm ' . (string)($cfg['interval_mode'] ?? 'FIXED'),
                'last_run_at' => $cfg['last_run_at'] ?? null,
                'next_run_at' => $cfg['next_run_at'] ?? null,
                'runtime_state' => (string)($cfg['runtime_state'] ?? 'WAITING'),
                'fail_streak' => (int)($cfg['fail_streak'] ?? 0),
                'last_result' => $last,
                'chrome_running' => $chromeRunning,
                'cdp_ready' => $port > 0 && cdp_reachable($port),
                'waiting_reason' => $waitingReason,
            ]]);
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

        case 'worker': {
            // Diagnostics global: supervisor/worker PID, heartbeat, tick, configs, due
            $st = ActivitySupervisor::state();
            $cfg = ['enabled' => 0, 'due' => 0, 'next' => null];
            try {
                $cfg['enabled'] = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE enabled=1')->fetchColumn();
                $dueRows = db()->query("SELECT profile_id FROM activity_configs WHERE enabled=1
                    AND (next_run_at IS NULL OR next_run_at<=NOW())")->fetchAll();
                $cfg['due'] = count($dueRows);
                $cfg['next'] = db()->query("SELECT MIN(next_run_at) FROM activity_configs
                    WHERE enabled=1 AND next_run_at IS NOT NULL")->fetchColumn() ?: null;
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'data' => ['supervisor' => $st, 'configs' => $cfg]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'activity exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
