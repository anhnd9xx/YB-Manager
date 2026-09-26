<?php
declare(strict_types=1);
/**
 * api/aidev.php - AI Dev Console: status/projects/jobs/review/apply/config.
 */
require_once __DIR__ . '/../sync/OpenCodeService.php';
require_once __DIR__ . '/../sync/OpenCodeGateway.php';
require_once __DIR__ . '/../sync/DevProjectRegistry.php';
require_once __DIR__ . '/../sync/DevJobManager.php';

try {
    DevJobManager::recoverStale();
} catch (Throwable $e) {
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';

try {
    switch ($action) {
        case 'status': {
            $st = OpenCodeService::status();
            $prim = DevProjectRegistry::primary();
            $active = DevJobManager::activeForProject();
            $pending = 0;
            foreach ($active as $j) {
                if (in_array($j['status'], ['REVIEW_READY'], true)) $pending++;
            }
            // Git workspace
            $git = ['clean' => null, 'branch' => null];
            if ($prim && is_dir((string)$prim['root_path'])) {
                $b = DevJobManager::git((string)$prim['root_path'], ['branch', '--show-current']);
                $git['branch'] = trim((string)($b['out'] ?? ''));
                $git['clean'] = !DevJobManager::isDirty((string)$prim['root_path']);
            }
            json_out(['ok' => true, 'data' => [
                'opencode' => $st,
                'project' => $prim ? ['id' => $prim['id'], 'name' => $prim['name'],
                    'root_path' => $prim['root_path']] : null,
                'active_jobs' => count($active),
                'pending_reviews' => $pending,
                'git' => $git,
                'config' => [
                    'autostart' => get_setting('ai_oc_autostart', '1') === '1',
                    'qa' => get_setting('ai_qa_enabled', '1') === '1',
                    'plan' => get_setting('ai_plan_enabled', '1') === '1',
                    'dev' => get_setting('ai_dev_enabled', '1') === '1',
                    'auto_tests' => get_setting('ai_auto_tests', '1') === '1',
                    'auto_apply' => get_setting('ai_auto_apply', '0') === '1',
                    'require_admin_apply' => get_setting('ai_require_admin_apply', '1') === '1',
                ]]]);
            break;
        }

        case 'oc_start': {
            json_out(OpenCodeService::start());
            break;
        }

        case 'oc_stop': {
            json_out(OpenCodeService::stop());
            break;
        }

        case 'oc_restart': {
            json_out(OpenCodeService::restart());
            break;
        }

        case 'config_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $allow = ['ai_oc_autostart', 'ai_oc_host', 'ai_oc_port', 'ai_qa_enabled',
                'ai_plan_enabled', 'ai_dev_enabled', 'ai_auto_tests', 'ai_auto_apply',
                'ai_require_admin_apply', 'ai_dev_max_duration_min', 'ai_dev_max_files_warn'];
            foreach ($allow as $k) {
                if (array_key_exists($k, $b)) {
                    $v = (string)$b[$k];
                    if (in_array($k, ['ai_oc_port', 'ai_dev_max_duration_min', 'ai_dev_max_files_warn'], true)) {
                        $v = (string)max(1, (int)$v);
                    }
                    set_setting($k, $v);
                }
            }
            json_out(['ok' => true, 'data' => ['saved' => true]]);
            break;
        }

        case 'projects': {
            json_out(['ok' => true, 'data' => DevProjectRegistry::list()]);
            break;
        }

        case 'jobs': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => DevJobManager::list(
                ['status' => $b['status'] ?? 'all'], (int)($b['limit'] ?? 30))]);
            break;
        }

        case 'job_detail': {
            $b = $method === 'GET' ? $_GET : json_body();
            $job = DevJobManager::get((string)($b['code'] ?? ''));
            if (!$job) json_out(['ok' => false, 'message' => 'Không thấy job'], 404);
            // Lam moi tien do (poll ngan) neu dang chay
            if (in_array($job['status'], ['CODING', 'TESTING', 'ANALYZING'], true)) {
                DevJobManager::pollProgress((string)$job['job_code']);
                $job = DevJobManager::get((string)$job['job_code']);
            }
            // Diff files (ngan)
            $files = [];
            try {
                if (!empty($job['worktree_path']) && is_dir((string)$job['worktree_path'])) {
                    $d = DevJobManager::git((string)$job['worktree_path'],
                        ['diff', '--name-status', (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
                    if (!empty($d['ok'])) {
                        foreach (explode("\n", trim((string)$d['out'])) as $line) {
                            $line = trim($line);
                            if ($line !== '') $files[] = mb_substr($line, 0, 200);
                            if (count($files) >= 100) break;
                        }
                    }
                }
            } catch (Throwable $e) {
            }
            $job['diff_files'] = $files;
            json_out(['ok' => true, 'data' => $job]);
            break;
        }

        case 'job_progress': {
            // UI poll tien do: hoi OpenCode session neu dang chay
            $b = $method === 'GET' ? $_GET : json_body();
            $job = DevJobManager::get((string)($b['code'] ?? ''));
            if (!$job) json_out(['ok' => false, 'message' => 'Không thấy job'], 404);
            if (in_array($job['status'], ['CODING', 'TESTING', 'ANALYZING'], true)) {
                DevJobManager::pollProgress((string)$job['job_code']);
                $job = DevJobManager::get((string)$job['job_code']);
            }
            require_once __DIR__ . '/../sync/AIDevConsole.php';
            json_out(['ok' => true, 'data' => ['job' => $job, 'progress_text' => AIDevConsole::progressText($job)]]);
            break;
        }

        case 'job_approve': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/AIDevConsole.php';
            $r = DevJobManager::approve((string)($b['code'] ?? ''), 'ui', !empty($b['confirmed2']));
            json_out($r['ok'] ? ['ok' => true] : ['ok' => false, 'message' => $r['message'] ?? $r['error'] ?? 'Lỗi',
                'data' => ['need' => $r['need'] ?? null]], $r['ok'] ? 200 : 400);
            break;
        }

        case 'job_reject': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = DevJobManager::reject((string)($b['code'] ?? ''), 'ui');
            json_out($r['ok'] ? ['ok' => true] : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'job_apply': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = DevJobManager::apply((string)($b['code'] ?? ''), 'ui');
            json_out($r['ok'] ? ['ok' => true, 'data' => ['commit' => $r['commit'] ?? '', 'snapshot' => $r['snapshot'] ?? '']]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'job_rollback': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = DevJobManager::rollback((string)($b['code'] ?? ''), 'ui', !empty($b['confirmed']));
            json_out($r['ok'] ? ['ok' => true, 'data' => ['commit' => $r['commit'] ?? '']]
                : ['ok' => false, 'message' => $r['message'] ?? $r['error'] ?? 'Lỗi',
                    'data' => ['need' => $r['need'] ?? null]], $r['ok'] ? 200 : 400);
            break;
        }

        case 'job_cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = DevJobManager::cancel((string)($b['code'] ?? ''), 'ui');
            json_out($r['ok'] ? ['ok' => true] : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'job_tests': {
            // Chay lai tests allowlisted
            $b = $method === 'GET' ? $_GET : json_body();
            $r = DevJobManager::runTests((string)($b['code'] ?? ''), 'ui');
            json_out($r['ok'] ? ['ok' => true, 'data' => ['report' => $r['report'] ?? null]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi',
                    'data' => ['report' => $r['report'] ?? null]], $r['ok'] ? 200 : 400);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action không hợp lệ'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'aidev exception', null, $e);
    json_out(['ok' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
