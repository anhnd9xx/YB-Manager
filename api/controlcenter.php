<?php
declare(strict_types=1);
/**
 * api/controlcenter.php - Trung tam dieu hanh: summary/jobs/alerts/health/schedules.
 * Chi observe/aggregate + dispatch qua service hien co (khong duplicate logic).
 */
require_once __DIR__ . '/../sync/JobManager.php';
require_once __DIR__ . '/../sync/AlertCenter.php';
require_once __DIR__ . '/../sync/SystemHealthService.php';
require_once __DIR__ . '/../sync/SchedulerService.php';
require_once __DIR__ . '/../sync/ResourceMonitor.php';

// Tick nen: recovery job cu + scheduler fire (tu throttle trong ham).
try {
    JobManager::recoverStale();
} catch (Throwable $e) {
}
try {
    SchedulerService::tick();
} catch (Throwable $e) {
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'summary';

try {
    switch ($action) {
        case 'summary': {
            JobManager::ensureSchema();
            $chTotal = 0;
            $chRunning = 0;
            try {
                $chTotal = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
                $chRunning = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
            } catch (Throwable $e) {
            }
            $pxTotal = 0;
            $pxHealthy = 0;
            try {
                $pxTotal = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
                $pxHealthy = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='alive'")->fetchColumn();
            } catch (Throwable $e) {
            }
            $jobsActive = JobManager::active(5);
            $nActive = 0;
            try {
                $nActive = (int)db()->query("SELECT COUNT(*) FROM app_jobs WHERE status IN ('QUEUED','RUNNING','PAUSED')")->fetchColumn();
            } catch (Throwable $e) {
            }
            $tg = ['state' => 'STOPPED', 'listening' => false];
            try {
                require_once __DIR__ . '/../sync/TelegramGateway.php';
                $tg = TelegramGateway::connectionState();
            } catch (Throwable $e) {
            }
            $checks = SystemHealthService::check();
            $alerts = AlertCenter::list('OPEN', 5);
            // Gop channel alerts OPEN (read-only) vao top canh bao
            try {
                require_once __DIR__ . '/../sync/AlertManager.php';
                $chAlerts = AlertManager::list('OPEN', null, 5);
                foreach ($chAlerts as $a) {
                    $alerts[] = ['id' => 'ch' . $a['id'], 'module' => 'CHANNEL',
                        'type' => $a['type'], 'severity' => $a['severity'],
                        'title' => 'Kênh #' . $a['profile_id'] . ': ' . $a['type'],
                        'message' => $a['message'], 'profile_id' => $a['profile_id'],
                        'status' => 'OPEN', 'last_seen_at' => $a['last_seen'],
                        'occurrence_count' => $a['seen_count'] ?? 1, 'channel' => true];
                }
            } catch (Throwable $e) {
            }
            $activity = [];
            try {
                $activity = db()->query('SELECT event_type, module, severity, title, created_at FROM app_events
                    ORDER BY created_at DESC LIMIT 8')->fetchAll();
            } catch (Throwable $e) {
            }
            // Auto Activity widget (§61): running/waiting/today/errors
            $actWidget = ['running' => 0, 'waiting' => 0, 'today' => '0/0', 'errors' => 0, 'scheduler' => false];
            try {
                require_once __DIR__ . '/../sync/ActivityScheduler.php';
                $ast = ActivityScheduler::status();
                $actWidget['running'] = $ast['running'] ?? 0;
                $actWidget['waiting'] = $ast['waiting'] ?? 0;
                $actWidget['errors'] = $ast['errors'] ?? 0;
                $td = db()->query("SELECT SUM(status IN ('DONE','FAILED','SKIPPED')) d, COUNT(*) t
                    FROM activity_sessions WHERE plan_date=CURDATE()")->fetch();
                if ($td) $actWidget['today'] = ((int)($td['d'] ?? 0)) . '/' . ((int)($td['t'] ?? 0));
                $pf = __DIR__ . '/../bin/.activity_scheduler.pid';
                if (is_file($pf)) {
                    $pid = (int)trim((string)@file_get_contents($pf));
                    $actWidget['scheduler'] = $pid > 0;
                }
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'data' => [
                'kpi' => ['channels_total' => $chTotal, 'chrome_running' => $chRunning,
                    'proxy_healthy' => $pxHealthy, 'proxy_total' => $pxTotal,
                    'jobs_active' => $nActive,
                    'telegram' => ['listening' => !empty($tg['listening']), 'state' => $tg['state'] ?? 'STOPPED']],
                'overall' => SystemHealthService::overall($checks),
                'jobs_active_list' => $jobsActive,
                'alerts' => $alerts,
                'alert_counts' => AlertCenter::counts(),
                'upcoming' => SchedulerService::upcoming(5),
                'resources' => ResourceMonitor::snapshot(),
                'activity' => $activity,
                'auto_activity' => $actWidget,
            ]]);
            break;
        }

        case 'jobs': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => JobManager::history([
                'module' => $b['module'] ?? 'all', 'status' => $b['status'] ?? 'all',
                'source' => $b['source'] ?? 'all', 'date' => $b['date'] ?? '',
            ], (int)($b['limit'] ?? 50), (int)($b['offset'] ?? 0))]);
            break;
        }

        case 'job_detail': {
            $b = $method === 'GET' ? $_GET : json_body();
            $d = JobManager::detail((string)($b['job_id'] ?? ''));
            if (!$d) json_out(['ok' => false, 'message' => 'Không thấy Job'], 404);
            json_out(['ok' => true, 'data' => $d]);
            break;
        }

        case 'job_cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => JobManager::cancel((string)($b['job_id'] ?? ''))]);
            break;
        }

        case 'job_pause': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => JobManager::pause((string)($b['job_id'] ?? ''))]);
            break;
        }

        case 'job_resume': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => JobManager::resume((string)($b['job_id'] ?? ''))]);
            break;
        }

        case 'job_retry': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = JobManager::retryFailed((string)($b['job_id'] ?? ''), 'UI',
                ['created_by' => 'control-center']);
            if (!$r) json_out(['ok' => false, 'message' => 'Không có gì để thử lại'], 400);
            cc_ensure_job_worker();
            json_out(['ok' => true, 'data' => ['job_id' => $r['job_id']]]);
            break;
        }

        case 'job_create': {
            // Quick actions / adapters: tao job that qua JobManager + dam bao worker.
            $b = $method === 'GET' ? $_GET : json_body();
            $kind = strtoupper((string)($b['kind'] ?? ''));
            $map = ['EVALUATION' => ['EVALUATION', 'EVALUATION'], 'BROWSER_START' => ['BROWSER', 'BROWSER_START'],
                'BROWSER_STOP' => ['BROWSER', 'BROWSER_STOP'], 'AUTO_ACTIVITY' => ['AUTO_ACTIVITY', 'AUTO_ACTIVITY'],
                'PROXY_CHECK' => ['PROXY', 'PROXY_CHECK']];
            if (!isset($map[$kind])) json_out(['ok' => false, 'message' => 'Kind không hỗ trợ'], 400);
            [$module, $jobType] = $map[$kind];
            $targets = cc_resolve_targets($kind, $b['scope'] ?? 'all', $b['ids'] ?? []);
            if (!$targets && $kind !== 'PROXY_CHECK') {
                json_out(['ok' => false, 'message' => 'Không có target nào'], 400);
            }
            $names = ['EVALUATION' => 'Đánh giá kênh', 'BROWSER_START' => 'Mở kênh',
                'BROWSER_STOP' => 'Đóng kênh', 'AUTO_ACTIVITY' => 'Auto activity',
                'PROXY_CHECK' => 'Kiểm tra proxy'];
            $job = JobManager::create($module, ($names[$kind] ?? $kind) . ' (' . count($targets) . ')',
                $targets, 'UI', ['job_type' => $jobType, 'created_by' => 'control-center',
                    'resumable' => in_array($kind, ['EVALUATION', 'PROXY_CHECK'], true) ? 1 : 0]);
            cc_ensure_job_worker();
            json_out(['ok' => true, 'data' => ['job_id' => $job['job_id']]]);
            break;
        }

        case 'alerts': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => [
                'list' => AlertCenter::list($b['status'] ?? 'OPEN', (int)($b['limit'] ?? 50),
                    (int)($b['offset'] ?? 0), $b['severity'] ?? null),
                'counts' => AlertCenter::counts()]]);
            break;
        }

        case 'alert_ack': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => AlertCenter::ack((int)($b['id'] ?? 0))]);
            break;
        }

        case 'alert_resolve': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => AlertCenter::resolveId((int)($b['id'] ?? 0))]);
            break;
        }

        case 'health': {
            $b = $method === 'GET' ? $_GET : json_body();
            $only = (string)($b['only'] ?? '');
            $checks = SystemHealthService::check($only !== '' ? $only : null);
            json_out(['ok' => true, 'data' => ['checks' => array_values($checks),
                'overall' => SystemHealthService::overall($checks)]]);
            break;
        }

        case 'health_recover': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = SystemHealthService::recover((string)($b['name'] ?? ''));
            json_out($r['ok'] ? ['ok' => true, 'data' => ['recovered' => true]]
                : ['ok' => false, 'message' => $r['message'] ?? 'Lỗi']);
            break;
        }

        case 'schedules': {
            json_out(['ok' => true, 'data' => ['list' => SchedulerService::list(),
                'upcoming' => SchedulerService::upcoming(10)]]);
            break;
        }

        case 'schedule_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = SchedulerService::save(isset($b['id']) ? (int)$b['id'] : null, $b);
            if (!$r) json_out(['ok' => false, 'message' => 'Không lưu được lịch'], 500);
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        case 'schedule_delete': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => SchedulerService::delete((int)($b['id'] ?? 0))]);
            break;
        }

        case 'schedule_toggle': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => SchedulerService::toggle((int)($b['id'] ?? 0), !empty($b['enabled']))]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action không hợp lệ'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'controlcenter exception', null, $e);
    json_out(['ok' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}

/** @return int[] */
function cc_resolve_targets(string $kind, $scope, $ids): array
{
    try {
        if (is_array($ids) && $ids) {
            return array_values(array_filter(array_map('intval', $ids)));
        }
        if ($kind === 'PROXY_CHECK') {
            return array_map(fn($r) => (int)$r['id'],
                db()->query('SELECT id FROM proxies ORDER BY id')->fetchAll());
        }
        $scope = (string)$scope;
        if ($scope === 'running') {
            return array_map(fn($r) => (int)$r['id'],
                db()->query("SELECT id FROM profiles WHERE status='running' ORDER BY id")->fetchAll());
        }
        if ($scope === 'stopped') {
            return array_map(fn($r) => (int)$r['id'],
                db()->query("SELECT id FROM profiles WHERE status<>'running' ORDER BY id")->fetchAll());
        }
        return array_map(fn($r) => (int)$r['id'],
            db()->query('SELECT id FROM profiles ORDER BY id')->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/** Dam bao job worker chay sau khi tao job (giong tgp_spawn). */
function cc_ensure_job_worker(): void
{
    try {
        $f = __DIR__ . '/../bin/.job_worker.pid';
        if (is_file($f)) {
            $pid = (int)trim((string)@file_get_contents($f));
            if ($pid > 0) {
                $out = [];
                @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
                foreach ($out as $line) {
                    if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                        && stripos($m[1], 'php') !== false) {
                        return;
                    }
                }
            }
        }
        $php = php_cli_binary();
        if ($php === '') return;
        pclose(popen('start "" /B "' . $php . '" -f "' . __DIR__ . '/../bin/job_worker.php'
            . '" >> "' . __DIR__ . '/../bin/job_worker.log" 2>&1', 'r'));
    } catch (Throwable $e) {
    }
}
