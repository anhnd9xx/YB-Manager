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
            $sessCount = 0;
            try {
                $sessCount = (int)db()->query("SELECT COUNT(*) FROM ai_sessions WHERE status='ACTIVE'")->fetchColumn();
            } catch (Throwable $e) {
            }
            $brain = [];
            try {
                require_once __DIR__ . '/../sync/ProjectBrainService.php';
                $bst = ProjectBrainService::state(1);
                $brain = ['symbols' => (int)($bst['symbols_count'] ?? 0),
                    'files' => (int)($bst['files_indexed'] ?? 0),
                    'indexed' => !empty($bst['symbols_count'])];
            } catch (Throwable $e) {
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
                'active_sessions' => $sessCount,
                'brain' => $brain,
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
            try {
                $st = db()->prepare('SELECT session_id, model, status, tokens_input, tokens_output, cost, updated_at
                    FROM ai_sessions WHERE dev_job_id=? ORDER BY id DESC LIMIT 1');
                $st->execute([(int)$job['id']]);
                $job['ai_session'] = $st->fetch() ?: null;
            } catch (Throwable $e) {
            }
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

        case 'job_advance': {
            // Day pipeline 1 buoc (QUEUED/ANALYZING/CODING/TESTING)
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/DevJobPipeline.php';
            $r = DevJobPipeline::advance((string)($b['code'] ?? ''));
            json_out($r['ok'] ? ['ok' => true, 'data' => $r]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'brain_index': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/ProjectBrainService.php';
            $r = ProjectBrainService::index(1, !empty($b['full']));
            json_out(['ok' => true, 'data' => $r + ProjectBrainService::state(1)]);
            break;
        }

        case 'brain_search': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/ProjectBrainService.php';
            $q = trim((string)($b['q'] ?? ''));
            if ($q === '') json_out(['ok' => false, 'message' => 'Thiếu từ khóa'], 400);
            $hits = ProjectBrainService::search(1, $q, 20, ($b['module'] ?? null) ?: null);
            $rel = [];
            if ($hits) $rel = ProjectBrainService::related(1, (string)$hits[0]['symbol_name'], 10);
            json_out(['ok' => true, 'data' => ['hits' => $hits, 'related' => $rel]]);
            break;
        }

        case 'brain_modules': {
            require_once __DIR__ . '/../sync/ProjectBrainService.php';
            json_out(['ok' => true, 'data' => ['modules' => ProjectBrainService::moduleMap(1),
                'state' => ProjectBrainService::state(1), 'git' => ProjectBrainService::gitState(1)]]);
            break;
        }

        case 'diag_list': {
            require_once __DIR__ . '/../sync/AIDiagnosisEngine.php';
            json_out(['ok' => true, 'data' => AIDiagnosisEngine::list(1, 20)]);
            break;
        }

        case 'diag_get': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/AIDiagnosisEngine.php';
            $d = AIDiagnosisEngine::get((string)($b['code'] ?? ''));
            if (!$d) json_out(['ok' => false, 'message' => 'Không thấy'], 404);
            json_out(['ok' => true, 'data' => $d]);
            break;
        }

        case 'diag_create': {
            // Chan doan tu UI (async? chay sync toi da 5 phut)
            $b = $method === 'GET' ? $_GET : json_body();
            $problem = trim((string)($b['problem'] ?? ''));
            if ($problem === '') json_out(['ok' => false, 'message' => 'Thiếu mô tả'], 400);
            @set_time_limit(330);
            require_once __DIR__ . '/../sync/AIDiagnosisEngine.php';
            $r = AIDiagnosisEngine::diagnose(1, $problem, 'UI', 'ui');
            json_out($r['ok'] ? ['ok' => true, 'data' => $r['diag']]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi'], $r['ok'] ? 200 : 500);
            break;
        }

        case 'knowledge': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/ProjectKnowledgeService.php';
            json_out(['ok' => true, 'data' => ProjectKnowledgeService::list(1,
                ($b['type'] ?? null) ?: null, 50)]);
            break;
        }

        case 'knowledge_add': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/ProjectKnowledgeService.php';
            $id = ProjectKnowledgeService::add(1, (string)($b['type'] ?? 'LESSON_LEARNED'),
                (string)($b['title'] ?? ''), (string)($b['body'] ?? ''), 'ui', 'ui');
            json_out($id ? ['ok' => true, 'data' => ['id' => $id]]
                : ['ok' => false, 'message' => 'Không lưu được'], $id ? 200 : 500);
            break;
        }

        case 'knowledge_delete': {
            $b = $method === 'GET' ? $_GET : json_body();
            require_once __DIR__ . '/../sync/ProjectKnowledgeService.php';
            json_out(['ok' => ProjectKnowledgeService::delete((int)($b['id'] ?? 0))]);
            break;
        }

        case 'issues': {
            require_once __DIR__ . '/../sync/ProjectKnowledgeService.php';
            json_out(['ok' => true, 'data' => ProjectKnowledgeService::listIssues(1, 50)]);
            break;
        }

        case 'runner_status': {
            $f = __DIR__ . '/../bin/.ai_dev_runner.pid';
            $alive = false;
            $pid = is_file($f) ? (int)trim((string)@file_get_contents($f)) : 0;
            if ($pid > 0) {
                $out = [];
                @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
                foreach ($out as $line) {
                    if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                        && stripos($m[1], 'php') !== false) {
                        $alive = true;
                        break;
                    }
                }
            }
            json_out(['ok' => true, 'data' => ['running' => $alive, 'pid' => $alive ? $pid : null]]);
            break;
        }

        case 'runner_start': {
            $php = php_cli_binary();
            if ($php === '') json_out(['ok' => false, 'message' => 'Không có PHP CLI'], 500);
            pclose(popen('start "" /B "' . $php . '" -f "' . __DIR__ . '/../bin/ai_dev_runner.php'
                . '" >> "' . __DIR__ . '/../bin/ai_dev_runner.log" 2>&1', 'r'));
            json_out(['ok' => true, 'data' => ['started' => true]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action không hợp lệ'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'aidev exception', null, $e);
    json_out(['ok' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
