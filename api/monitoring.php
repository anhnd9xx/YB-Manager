<?php
declare(strict_types=1);
/**
 * api/monitoring.php - module "Thong ke & Theo doi".
 * GET  summary?watch=1 | distribution?by= | channels? + filters + page/per
 * GET  alerts?status=&severity= | trends?days=&metric= | history? + filters
 * POST alert_resolve {id} | watch {id,on} | settings {id,...} | recheck {id}
 */
require_once __DIR__ . '/../sync/MonitoringService.php';
require_once __DIR__ . '/../sync/AlertManager.php';
require_once __DIR__ . '/../sync/StateHistory.php';
require_once __DIR__ . '/../sync/ChannelEvaluationManager.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'summary';

try {
    switch ($action) {
        case 'summary': {
            $watch = !empty($_GET['watch']);
            json_out(['ok' => true, 'data' => [
                'summary' => MonitoringService::get_summary($watch),
                'distribution' => MonitoringService::get_status_distribution($watch),
            ]]);
            break;
        }

        case 'distribution': {
            $by = (string)($_GET['by'] ?? 'stage');
            json_out(['ok' => true, 'data' => MonitoringService::get_distribution($by)]);
            break;
        }

        case 'channels': {
            $f = [
                'search' => (string)($_GET['search'] ?? ''),
                'eval' => (string)($_GET['eval'] ?? ''),
                'chrome' => (string)($_GET['chrome'] ?? ''),
                'stage' => (string)($_GET['stage'] ?? ''),
                'platform' => (string)($_GET['platform'] ?? ''),
                'proxy' => (string)($_GET['proxy'] ?? ''),
                'monitoring' => (string)($_GET['monitoring'] ?? ''),
                'alert' => !empty($_GET['alert']),
                'watch' => !empty($_GET['watch']),
            ];
            json_out(['ok' => true, 'data' => MonitoringService::get_channel_rows(
                $f, (int)($_GET['page'] ?? 1), (int)($_GET['per'] ?? 20))]);
            break;
        }

        case 'alerts': {
            json_out(['ok' => true, 'data' => [
                'alerts' => AlertManager::list(
                    (string)($_GET['status'] ?? 'OPEN'),
                    (string)($_GET['severity'] ?? ''),
                    (int)($_GET['limit'] ?? 100), (int)($_GET['offset'] ?? 0)),
                'counts' => AlertManager::counts(),
            ]]);
            break;
        }

        case 'alert_resolve': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            AlertManager::resolveId($id);
            json_out(['ok' => true]);
            break;
        }

        case 'trends': {
            json_out(['ok' => true, 'data' => MonitoringService::get_trends(
                (int)($_GET['days'] ?? 7), (string)($_GET['metric'] ?? 'active'))]);
            break;
        }

        case 'history': {
            json_out(['ok' => true, 'data' => StateHistory::timeline(
                isset($_GET['profile_id']) ? (int)$_GET['profile_id'] : null,
                (string)($_GET['category'] ?? ''),
                (string)($_GET['from'] ?? ''),
                (int)($_GET['limit'] ?? 100), (int)($_GET['offset'] ?? 0))]);
            break;
        }

        case 'recent': {
            json_out(['ok' => true, 'data' => MonitoringService::get_recent_changes(
                (int)($_GET['limit'] ?? 20))]);
            break;
        }

        case 'watch': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $cur = MonitoringService::getSettings($id);
            $ok = MonitoringService::saveSettings($id, ['watchlist' => array_key_exists('on', $b) ? $b['on'] : !$cur['watchlist']]);
            json_out(['ok' => $ok, 'watchlist' => MonitoringService::getSettings($id)['watchlist']]);
            break;
        }

        case 'settings': {
            if ($method === 'GET') {
                $id = (int)($_GET['id'] ?? 0);
                json_out(['ok' => true, 'data' => MonitoringService::getSettings($id)]);
                break;
            }
            $b = json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => MonitoringService::saveSettings($id, $b)]);
            break;
        }

        case 'recheck': {
            // High priority queue: kiem tra ngay 1 kenh (tra ket qua de UI patch realtime)
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $r = ChannelEvaluationManager::evaluate_one($id);
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
