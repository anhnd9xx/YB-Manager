<?php
declare(strict_types=1);
/**
 * DevJobManager - Vong doi AI Dev Job: QUEUED -> PREPARING -> ANALYZING ->
 * CODING -> TESTING -> REVIEW_READY -> APPROVED -> APPLYING -> APPLIED
 * (hoac REJECTED/FAILED/CANCELLED). Lam viec CHI trong git worktree cach ly.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class DevJobManager
{
    public const ST_QUEUED = 'QUEUED';
    public const ST_PREPARING = 'PREPARING';
    public const ST_ANALYZING = 'ANALYZING';
    public const ST_CODING = 'CODING';
    public const ST_TESTING = 'TESTING';
    public const ST_REVIEW_READY = 'REVIEW_READY';
    public const ST_APPROVED = 'APPROVED';
    public const ST_APPLYING = 'APPLYING';
    public const ST_APPLIED = 'APPLIED';
    public const ST_REJECTED = 'REJECTED';
    public const ST_FAILED = 'FAILED';
    public const ST_CANCELLED = 'CANCELLED';
    public const ST_INTERRUPTED = 'INTERRUPTED';

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            DevProjectRegistry::ensureTable();
            db()->exec("CREATE TABLE IF NOT EXISTS dev_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_code VARCHAR(20) NOT NULL DEFAULT '',
                project_id INT NOT NULL DEFAULT 0,
                request TEXT NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
                requested_by VARCHAR(64) NOT NULL DEFAULT '',
                status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
                opencode_session_id VARCHAR(64) NULL,
                base_branch VARCHAR(80) NOT NULL DEFAULT '',
                base_commit VARCHAR(80) NOT NULL DEFAULT '',
                work_branch VARCHAR(120) NOT NULL DEFAULT '',
                worktree_path VARCHAR(500) NOT NULL DEFAULT '',
                plan_text MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                summary TEXT NULL,
                files_changed INT NOT NULL DEFAULT 0,
                lines_added INT NOT NULL DEFAULT 0,
                lines_removed INT NOT NULL DEFAULT 0,
                test_status VARCHAR(20) NOT NULL DEFAULT '',
                test_report TEXT NULL,
                review_status VARCHAR(20) NOT NULL DEFAULT '',
                reviewed_by VARCHAR(64) NOT NULL DEFAULT '',
                apply_status VARCHAR(20) NOT NULL DEFAULT '',
                applied_commit VARCHAR(80) NOT NULL DEFAULT '',
                snapshot_id VARCHAR(64) NOT NULL DEFAULT '',
                has_db_migration TINYINT(1) NOT NULL DEFAULT 0,
                has_dependency_change TINYINT(1) NOT NULL DEFAULT 0,
                high_risk_flags VARCHAR(255) NOT NULL DEFAULT '',
                error VARCHAR(500) NULL,
                UNIQUE KEY uq_dev_job_code (job_code),
                KEY idx_dev_jobs_status (status, created_at),
                KEY idx_dev_jobs_session (opencode_session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS ai_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(64) NOT NULL DEFAULT '',
                project_id INT NOT NULL DEFAULT 0,
                dev_job_id INT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'QA',
                title VARCHAR(190) NOT NULL DEFAULT '',
                model VARCHAR(80) NOT NULL DEFAULT '',
                status VARCHAR(15) NOT NULL DEFAULT 'ACTIVE',
                tokens_input INT NOT NULL DEFAULT 0,
                tokens_output INT NOT NULL DEFAULT 0,
                cost DOUBLE NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_ai_session (session_id),
                KEY idx_ai_sessions_job (dev_job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    // ================= CRUD =================

    public static function create(int $projectId, string $request, string $source = 'TELEGRAM',
        string $requestedBy = '', array $opts = []): ?array
    {
        self::ensureTables();
        try {
            db()->prepare('INSERT INTO dev_jobs (project_id, request, source, requested_by, status, plan_text)
                VALUES (?,?,?,?,?,?)')
                ->execute([$projectId, $request, $source, mb_substr($requestedBy, 0, 64),
                    self::ST_QUEUED, $opts['plan_text'] ?? null]);
            $id = (int)db()->lastInsertId();
            $code = 'DEV-' . $id;
            db()->prepare('UPDATE dev_jobs SET job_code=? WHERE id=?')->execute([$code, $id]);
            self::audit('CREATE', $code, $requestedBy, mb_substr($request, 0, 200));
            $st = db()->prepare('SELECT * FROM dev_jobs WHERE id=?');
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function get(string $code): ?array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM dev_jobs WHERE job_code=?');
            $st->execute([strtoupper(trim($code))]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function list(array $f = [], int $limit = 30): array
    {
        self::ensureTables();
        try {
            $w = [];
            $p = [];
            if (!empty($f['status']) && $f['status'] !== 'all') {
                $w[] = 'status=?';
                $p[] = $f['status'];
            }
            if (!empty($f['project_id'])) {
                $w[] = 'project_id=?';
                $p[] = (int)$f['project_id'];
            }
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $limit = max(1, min(100, $limit));
            $st = db()->prepare("SELECT * FROM dev_jobs $where ORDER BY id DESC LIMIT $limit");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function activeForProject(?int $projectId = null): array
    {
        self::ensureTables();
        try {
            $w = "status IN ('QUEUED','PREPARING','ANALYZING','CODING','TESTING','REVIEW_READY','APPROVED','APPLYING')";
            $p = [];
            if ($projectId) {
                $w .= ' AND project_id=?';
                $p[] = $projectId;
            }
            $st = db()->prepare("SELECT * FROM dev_jobs WHERE $w ORDER BY id DESC LIMIT 10");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function set(string $code, array $patch): void
    {
        $allow = ['status', 'opencode_session_id', 'base_branch', 'base_commit', 'work_branch',
            'worktree_path', 'plan_text', 'started_at', 'completed_at', 'summary', 'files_changed',
            'lines_added', 'lines_removed', 'test_status', 'test_report', 'review_status', 'reviewed_by',
            'apply_status', 'applied_commit', 'snapshot_id', 'has_db_migration', 'has_dependency_change',
            'high_risk_flags', 'error'];
        $sets = [];
        $params = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$k=?";
                $params[] = $patch[$k];
            }
        }
        if (!$sets) return;
        try {
            $params[] = $code;
            db()->prepare('UPDATE dev_jobs SET ' . implode(',', $sets) . ' WHERE job_code=?')->execute($params);
        } catch (Throwable $e) {
        }
    }

    public static function audit(string $action, string $code, string $by, string $detail = ''): void
    {
        try {
            SyncLogger::info('aidev', "[DEV $action] $code by=$by " . mb_substr($detail, 0, 300));
        } catch (Throwable $e) {
        }
    }

    // ================= Git helpers (khong shell injection) =================

    /** @return array{ok, out?, error?} */
    public static function git(string $root, array $args, int $timeoutSec = 60): array
    {
        $git = trim((string)get_setting('ai_git_binary', 'git'));
        if ($git === '') $git = 'git';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // --no-pager + -C root, args rieng le (proc_open khong qua shell)
        $cmd = array_merge([$git, '--no-pager', '-C', $root], $args);
        // Windows: proc_open van qua shell cho string; dung array thi bypassPowerShell? PHP Windows
        // khong ho tro array bypass — build string voi escapeshellarg tung phan.
        $str = implode(' ', array_map(fn($a) => escapeshellarg((string)$a), $cmd));
        try {
            $proc = proc_open($str, $descriptors, $pipes, $root);
            if (!is_resource($proc)) return ['ok' => false, 'error' => 'spawn_fail'];
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $out = '';
            $err = '';
            $t0 = microtime(true);
            while ((microtime(true) - $t0) < $timeoutSec) {
                $o = stream_get_contents($pipes[1]);
                $e = stream_get_contents($pipes[2]);
                if (is_string($o)) $out .= $o;
                if (is_string($e)) $err .= $e;
                $st = proc_get_status($proc);
                if (empty($st['running'])) {
                    $code = (int)$st['exitcode'];
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                    if ($code === 0) return ['ok' => true, 'out' => $out];
                    return ['ok' => false, 'error' => trim($err) !== '' ? mb_substr(trim($err), 0, 300) : ('exit_' . $code), 'out' => $out];
                }
                usleep(200000);
            }
            proc_terminate($proc);
            proc_close($proc);
            return ['ok' => false, 'error' => 'timeout'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function isDirty(string $root): bool
    {
        // Chi tracked modifications (staged/unstaged). Bo qua untracked (pid/lock,
        // .worktrees cua job khac) — khong thi job nao cung thay "dirty".
        $r = self::git($root, ['status', '--porcelain', '--untracked-files=no']);
        return !empty($r['ok']) && trim((string)($r['out'] ?? '')) !== '';
    }

    public static function headCommit(string $root): string
    {
        $r = self::git($root, ['rev-parse', 'HEAD']);
        return !empty($r['ok']) ? trim((string)($r['out'] ?? '')) : '';
    }

    // ================= Lifecycle =================

    /**
     * PREPARING: snapshot + branch + worktree.
     * @return array{ok, error?, need?} need=DIRTY khi main tree ban.
     */
    public static function prepare(string $code, string $by = ''): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get((int)$job['project_id']);
            if (!$p || empty($p['enabled'])) return ['ok' => false, 'error' => 'Project không khả dụng'];
            $v = DevProjectRegistry::validateRoot((string)$p['root_path']);
            if (empty($v['ok'])) return ['ok' => false, 'error' => $v['error'] ?? 'Root không hợp lệ'];
            $root = (string)$v['canonical'];
            $baseBranch = (string)($p['default_branch'] ?? 'master');
            self::set($code, ['status' => self::ST_PREPARING, 'base_branch' => $baseBranch,
                'base_commit' => self::headCommit($root), 'started_at' => date('Y-m-d H:i:s')]);
            // Main tree dirty? Khong silently overwrite (§17)
            if (self::isDirty($root)) {
                self::set($code, ['status' => self::ST_QUEUED,
                    'error' => 'Project đang có thay đổi chưa commit']);
                return ['ok' => false, 'error' => 'dirty',
                    'need' => 'DIRTY',
                    'message' => 'Project đang có thay đổi chưa commit. Commit hoặc stash trước khi AI code.'];
            }
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(mb_substr((string)$job['request'], 0, 40)));
            $slug = trim($slug ?: 'task', '-');
            $branch = 'ai/' . strtolower($code) . '-' . $slug;
            $wt = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.worktrees'
                . DIRECTORY_SEPARATOR . strtolower($code);
            // Don worktree cu (neu retry)
            self::git($root, ['worktree', 'remove', '--force', $wt]);
            self::git($root, ['branch', '-D', $branch]);
            $r1 = self::git($root, ['fetch', '--quiet']);
            $base = $baseBranch;
            // Tao branch tu origin/base neu co, khong thi HEAD
            self::git($root, ['branch', $branch, $base]);
            $r2 = self::git($root, ['worktree', 'add', $wt, $branch]);
            if (empty($r2['ok'])) {
                self::set($code, ['status' => self::ST_FAILED, 'error' => 'Không tạo được worktree']);
                return ['ok' => false, 'error' => $r2['error'] ?? 'worktree_fail'];
            }
            self::set($code, ['status' => self::ST_ANALYZING, 'work_branch' => $branch,
                'worktree_path' => $wt, 'base_commit' => self::headCommit($root)]);
            self::audit('PREPARE', $code, $by, "branch=$branch");
            return ['ok' => true, 'branch' => $branch, 'worktree' => $wt];
        } catch (Throwable $e) {
            self::set($code, ['status' => self::ST_FAILED, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /**
     * Bat dau coding: tao OpenCode session trong worktree + prompt (DEV_RULES, constraints).
     */
    public static function startCoding(string $code, string $by = '', string $plan = ''): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        if (empty($job['worktree_path']) || !is_dir((string)$job['worktree_path'])) {
            $p = self::prepare($code, $by);
            if (empty($p['ok'])) return $p;
            $job = self::get($code);
        }
        try {
            require_once __DIR__ . '/OpenCodeService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            $ens = OpenCodeService::ensureRunning();
            if (empty($ens['ok'])) return ['ok' => false, 'error' => 'opencode_offline'];
            require_once __DIR__ . '/ProjectContextService.php';
            $cx = ProjectContextService::build((int)$job['project_id'], 4000);
            $rules = self::devRulesText((int)$job['project_id']);
            $prompt = "Bạn là dev agent cho project YT Manager.\n"
                . "YÊU CẦU: " . $job['request'] . "\n"
                . ($plan !== '' ? "PHƯƠNG ÁN ĐÃ DUYỆT:\n$plan\n" : "")
                . "CONTEXT:\n" . ($cx['context'] ?? '') . "\n"
                . "RULES BẮT BUỘC:\n$rules\n"
                . "RÀNG BUỘC: chỉ sửa file trong worktree này; không chạy lệnh destructive/migration/dependency install "
                . "nếu chưa được yêu cầu rõ; sau khi code xong chạy test allowlisted và báo cáo structured "
                . "(tests passed/failed, lint, files changed). Không commit.";
            $c = OpenCodeGateway::createSession($code . ': ' . mb_substr((string)$job['request'], 0, 60),
                (string)$job['worktree_path']);
            if (empty($c['ok'])) return ['ok' => false, 'error' => $c['error'] ?? 'oc_error'];
            $sid = (string)($c['session']['id'] ?? '');
            self::set($code, ['status' => self::ST_CODING, 'opencode_session_id' => $sid]);
            self::saveSession($sid, (int)$job['project_id'], (int)$job['id'], 'DEV',
                $code, (string)($c['session']['model']['id'] ?? ''));
            $pr = OpenCodeGateway::prompt($sid, $prompt);
            if (empty($pr['ok'])) {
                self::set($code, ['status' => self::ST_FAILED, 'error' => 'Không gửi được prompt']);
                return ['ok' => false, 'error' => $pr['error'] ?? 'oc_error'];
            }
            self::audit('CODING', $code, $by, "session=$sid");
            return ['ok' => true, 'session_id' => $sid];
        } catch (Throwable $e) {
            self::set($code, ['status' => self::ST_FAILED, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function devRulesText(int $projectId): string
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get($projectId);
            $root = $p ? (string)$p['root_path'] : BASE_DIR;
            $f = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'DEV_RULES.md';
            if (is_file($f)) return (string)@file_get_contents($f);
        } catch (Throwable $e) {
        }
        return "- Không one-thread-per-profile; không global Chrome state.\n"
            . "- Telegram chỉ 1 polling consumer; window positioning chỉ qua WindowPlacementManager.\n"
            . "- Modules emit EventBus events; long operations dùng JobManager; secrets không log.\n"
            . "- PHP: php -l trước khi xong; không sửa file ngoài worktree.";
    }

    /** Ghi mapping session. */
    public static function saveSession(string $sid, int $projectId, ?int $devJobId,
        string $purpose, string $title, string $model = ''): void
    {
        self::ensureTables();
        try {
            db()->prepare('INSERT INTO ai_sessions (session_id, project_id, dev_job_id, purpose, title, model, status)
                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE updated_at=NOW(), model=VALUES(model)')
                ->execute([$sid, $projectId, $devJobId, $purpose, mb_substr($title, 0, 190), mb_substr($model, 0, 80), 'ACTIVE']);
        } catch (Throwable $e) {
        }
    }

    /**
     * Poll tien do: doi session idle (ngan) roi doc assistant cuoi, chuyen stage.
     * Khong spam: chi goi khi user hoi / UI poll.
     */
    public static function pollProgress(string $code): array
    {
        $job = self::get($code);
        if (!$job || empty($job['opencode_session_id'])) return ['ok' => false];
        try {
            require_once __DIR__ . '/OpenCodeGateway.php';
            $sid = (string)$job['opencode_session_id'];
            OpenCodeGateway::waitIdle($sid, 20);
            $a = OpenCodeGateway::lastAssistantText($sid);
            if ($a['text'] !== '') {
                self::set($code, ['summary' => mb_substr($a['text'], 0, 2000)]);
                try {
                    db()->prepare('UPDATE ai_sessions SET tokens_input=?, tokens_output=?, cost=?, updated_at=NOW() WHERE session_id=?')
                        ->execute([(int)($a['tokens']['input'] ?? 0), (int)($a['tokens']['output'] ?? 0),
                            (float)($a['cost'] ?? 0), $sid]);
                } catch (Throwable $e) {
                }
            }
            // Timeout guard
            $maxMin = max(5, (int)get_setting('ai_dev_max_duration_min', '60'));
            if (!empty($job['started_at']) && (time() - strtotime((string)$job['started_at'])) > $maxMin * 60
                && in_array($job['status'], [self::ST_CODING, self::ST_TESTING, self::ST_ANALYZING], true)) {
                self::set($code, ['status' => self::ST_FAILED, 'error' => 'Quá thời gian cho phép']);
                return ['ok' => true, 'timeout' => true];
            }
            return ['ok' => true, 'text' => mb_substr($a['text'], 0, 500)];
        } catch (Throwable $e) {
            return ['ok' => false];
        }
    }

    /**
     * Chay tests allowlisted (§29-§30). Hien tai: php_lint tren changed files.
     * @return array{ok, report}
     */
    public static function runTests(string $code, string $by = ''): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        self::set($code, ['status' => self::ST_TESTING]);
        $wt = (string)$job['worktree_path'];
        $report = ['lint' => 'SKIP', 'tests' => 'SKIP', 'passed' => 0, 'failed' => 0, 'details' => []];
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get((int)$job['project_id']);
            $allowed = [];
            if ($p) {
                $allowed = array_merge(
                    json_decode((string)($p['test_commands'] ?? ''), true) ?: [],
                    json_decode((string)($p['lint_commands'] ?? ''), true) ?: []);
            }
            $allowLint = false;
            foreach ($allowed as $c) {
                if (($c['key'] ?? '') === 'php_lint') {
                    $allowLint = true;
                    break;
                }
            }
            if (!$allowLint) {
                $report['lint'] = 'DENIED';
                $report['details'][] = 'php_lint chưa allowlisted cho project';
                self::set($code, ['test_status' => 'FAILED', 'test_report' => json_encode($report, JSON_UNESCAPED_UNICODE)]);
                return ['ok' => false, 'error' => 'Test command chưa được duyệt', 'report' => $report];
            }
            // Changed files trong worktree
            $diff = self::git($wt, ['diff', '--name-only', (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
            $files = array_values(array_filter(array_map('trim', explode("\n", (string)($diff['out'] ?? '')))));
            $phpFiles = array_values(array_filter($files, fn($f) => str_ends_with(strtolower($f), '.php')));
            $pass = 0;
            $fail = 0;
            $failList = [];
            foreach ($phpFiles as $f) {
                $full = rtrim($wt, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
                if (!is_file($full)) continue; // file xoa
                $lr = self::phpLint($full);
                if ($lr) {
                    $pass++;
                } else {
                    $fail++;
                    $failList[] = $f;
                }
            }
            $report['lint'] = $fail > 0 ? 'FAIL' : 'PASS';
            $report['passed'] = $pass;
            $report['failed'] = $fail;
            $report['details'] = $failList;
            // Structured test report tu AI (neu session co bao cao): giu rieng, khong tin mu quang
            $report['ai_reported'] = '(xem summary)';
            $ok = $fail === 0;
            self::set($code, ['test_status' => $ok ? 'PASS' : 'FAIL',
                'test_report' => json_encode($report, JSON_UNESCAPED_UNICODE)]);
            if ($ok) self::finishReview($code, $by);
            else {
                self::set($code, ['error' => 'Lint fail: ' . implode(',', array_slice($failList, 0, 5))]);
                self::notifyMilestone($code, 'TEST_FAILED', 0, 0, 0,
                    'Lint: ' . count($failList) . ' file lỗi');
            }
            return ['ok' => $ok, 'report' => $report];
        } catch (Throwable $e) {
            self::set($code, ['test_status' => 'ERROR', 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    private static function phpLint(string $file): bool
    {
        try {
            $php = php_cli_binary();
            if ($php === '') $php = 'php';
            $out = [];
            $ret = 0;
            @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $ret);
            return $ret === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Stage + commit tren work branch (Tool commit thay AI). Bat buoc truoc
     * diff/merge vi file AI tao moi la untracked (diff/merge bo qua).
     */
    public static function stageWorktree(array $job): array
    {
        $wt = (string)($job['worktree_path'] ?? '');
        if ($wt === '' || !is_dir($wt)) return ['ok' => false, 'error' => 'no_worktree'];
        self::git($wt, ['add', '-A']);
        $st = self::git($wt, ['status', '--porcelain']);
        if (trim((string)($st['out'] ?? '')) === '') return ['ok' => true, 'empty' => true];
        $c = self::git($wt, ['-c', 'user.email=ytm-ai@local', '-c', 'user.name=YT-AI',
            'commit', '-m', 'AI ' . $job['job_code'] . ': ' . mb_substr((string)$job['request'], 0, 80)]);
        if (empty($c['ok'])) return ['ok' => false, 'error' => $c['error'] ?? 'commit_fail'];
        return ['ok' => true];
    }

    /**
     * Tong hop diff -> REVIEW_READY (§23-§24, §26, §53-§54).
     */
    public static function finishReview(string $code, string $by = ''): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false];
        $wt = (string)$job['worktree_path'];
        self::stageWorktree($job); // de diff thay ca file moi
        $files = 0;
        $add = 0;
        $del = 0;
        $names = [];
        try {
            $r = self::git($wt, ['diff', '--numstat', (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
            if (!empty($r['ok'])) {
                foreach (explode("\n", trim((string)$r['out'])) as $line) {
                    $parts = preg_split('/\s+/', trim($line), 3);
                    if (count($parts) < 3) continue;
                    $files++;
                    $add += (int)$parts[0];
                    $del += (int)$parts[1];
                    $names[] = $parts[2];
                }
            }
        } catch (Throwable $e) {
        }
        $hasMig = false;
        $hasDep = false;
        $risks = [];
        foreach ($names as $n) {
            $l = strtolower($n);
            if (str_contains($l, 'migration') && (str_ends_with($l, '.sql') || str_ends_with($l, '.php'))) $hasMig = true;
            if (in_array(basename($l), ['composer.json', 'package.json', 'requirements.txt', 'pyproject.toml'], true)) $hasDep = true;
            if (preg_match('/(auth|permission|telegram|secret|login|password|startup|install)/', $l)) $risks[] = $n;
        }
        // File xoa?
        try {
            $d = self::git($wt, ['diff', '--diff-filter=D', '--name-only',
                (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
            $deleted = array_values(array_filter(array_map('trim', explode("\n", (string)($d['out'] ?? '')))));
            if ($deleted) $risks[] = 'deleted:' . implode(',', array_slice($deleted, 0, 5));
        } catch (Throwable $e) {
        }
        $maxFiles = max(1, (int)get_setting('ai_dev_max_files_warn', '20'));
        self::set($code, ['status' => self::ST_REVIEW_READY, 'review_status' => 'PENDING',
            'completed_at' => date('Y-m-d H:i:s'),
            'files_changed' => $files, 'lines_added' => $add, 'lines_removed' => $del,
            'has_db_migration' => $hasMig ? 1 : 0, 'has_dependency_change' => $hasDep ? 1 : 0,
            'high_risk_flags' => mb_substr(implode(';', $risks), 0, 255)
                . ($files > $maxFiles ? ';LARGE_DIFF' : '')]);
        self::audit('REVIEW_READY', $code, $by, "files=$files +$add -$del");
        self::notifyMilestone($code, 'REVIEW_READY', $files, $add, $del);
        return ['ok' => true, 'files' => $names];
    }

    /** Bao milestone gon ve Telegram requester (§21-23), khong spam tokens. */
    private static function notifyMilestone(string $code, string $kind,
        int $files = 0, int $add = 0, int $del = 0, string $extra = ''): void
    {
        try {
            $chatId = (string)get_setting('ai_dev_chat_' . $code, '');
            if ($chatId === '') return;
            require_once __DIR__ . '/AIDevConsole.php';
            if ($kind === 'REVIEW_READY') {
                $txt = "✅ CODE HOÀN TẤT\nJob: $code\nFiles changed: $files\nLines: +$add -$del\n"
                    . "Status: Chờ duyệt";
                if ($extra !== '') $txt .= "\nTests: $extra";
                AIDevConsole::sendWithButtons($chatId, $txt,
                    [['Duyệt', 'aidev:tgapprove:' . $code], ['Từ chối', 'aidev:tgject:' . $code],
                        ['Xem tiến độ', 'dev:status:' . $code]]);
            } elseif ($kind === 'TEST_FAILED') {
                AIDevConsole::sendWithButtons($chatId,
                    "❌ $code TEST FAILED\n$extra\nKhông apply.",
                    [['Cho AI sửa', 'aidev:tgfix:' . $code], ['Dừng', 'aidev:cancel:']]);
            } elseif ($kind === 'APPLIED') {
                AIDevConsole::reply($chatId, "✅ $code Applied\nCommit: $extra");
            }
        } catch (Throwable $e) {
        }
    }

    public static function approve(string $code, string $by, bool $confirmedHighRisk = false): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        if (($job['status'] ?? '') !== self::ST_REVIEW_READY) {
            return ['ok' => false, 'error' => 'Job chưa sẵn sàng duyệt'];
        }
        $risky = !empty($job['has_db_migration']) || !empty($job['has_dependency_change'])
            || trim((string)($job['high_risk_flags'] ?? '')) !== '';
        if ($risky && !$confirmedHighRisk) {
            return ['ok' => false, 'error' => 'high_risk', 'need' => 'CONFIRM2',
                'message' => 'Thay đổi rủi ro cao (DB/auth/xóa file/deps). Xác nhận lần 2 để duyệt.'];
        }
        self::set($code, ['status' => self::ST_APPROVED, 'review_status' => 'APPROVED', 'reviewed_by' => mb_substr($by, 0, 64)]);
        self::audit('APPROVE', $code, $by, '');
        return ['ok' => true];
    }

    public static function reject(string $code, string $by): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        self::set($code, ['status' => self::ST_REJECTED, 'review_status' => 'REJECTED',
            'reviewed_by' => mb_substr($by, 0, 64), 'completed_at' => date('Y-m-d H:i:s')]);
        self::cleanupWorktree($job);
        self::audit('REJECT', $code, $by, '');
        return ['ok' => true];
    }

    /**
     * APPLY: merge --no-ff work_branch vao base (trong main repo) + snapshot tag.
     */
    public static function apply(string $code, string $by): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        if (($job['status'] ?? '') !== self::ST_APPROVED) {
            return ['ok' => false, 'error' => 'Job chưa được duyệt'];
        }
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get((int)$job['project_id']);
            $v = DevProjectRegistry::validateRoot((string)$p['root_path']);
            if (empty($v['ok'])) return ['ok' => false, 'error' => 'Root không hợp lệ'];
            $root = (string)$v['canonical'];
            if (self::isDirty($root)) {
                return ['ok' => false, 'error' => 'Main tree đang dirty, không apply'];
            }
            self::set($code, ['status' => self::ST_APPLYING]);
            // Snapshot tag truoc apply (§74)
            $snap = 'pre-' . strtolower($code) . '-' . date('His');
            self::git($root, ['tag', $snap]);
            self::set($code, ['snapshot_id' => $snap]);
            // Stage+commit tren work branch (file moi la untracked) roi merge
            $st = self::stageWorktree($job);
            if (empty($st['ok'])) {
                self::set($code, ['status' => self::ST_APPROVED, 'error' => 'Không stage được worktree']);
                return ['ok' => false, 'error' => 'Không stage được worktree'];
            }
            // Merge
            $r = self::git($root, ['merge', '--no-ff', '-m', 'AI ' . $code . ': ' . mb_substr((string)$job['request'], 0, 80),
                (string)$job['work_branch']], 120);
            if (empty($r['ok'])) {
                self::git($root, ['merge', '--abort']);
                self::set($code, ['status' => self::ST_APPROVED, 'error' => 'Merge conflict, đã abort']);
                return ['ok' => false, 'error' => 'Merge conflict — đã abort, giữ nguyên main'];
            }
            $commit = self::headCommit($root);
            self::set($code, ['status' => self::ST_APPLIED, 'apply_status' => 'APPLIED',
                'applied_commit' => $commit, 'completed_at' => date('Y-m-d H:i:s')]);
            self::cleanupWorktree($job, true);
            self::audit('APPLY', $code, $by, "commit=$commit snapshot=$snap");
            self::notifyMilestone($code, 'APPLIED', 0, 0, 0, $commit);
            return ['ok' => true, 'commit' => $commit, 'snapshot' => $snap];
        } catch (Throwable $e) {
            self::set($code, ['status' => self::ST_APPROVED, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /**
     * Rollback: revert commit applied (§28). Migration nguy hiem: canh bao, khong tu rollback DB.
     */
    public static function rollback(string $code, string $by, bool $confirmed = false): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        if (($job['status'] ?? '') !== self::ST_APPLIED || trim((string)$job['applied_commit']) === '') {
            return ['ok' => false, 'error' => 'Job chưa applied'];
        }
        if (!empty($job['has_db_migration']) && !$confirmed) {
            return ['ok' => false, 'error' => 'db_migration', 'need' => 'CONFIRM',
                'message' => 'Job có DB migration — rollback code KHÔNG rollback DB. Xác nhận để tiếp tục.'];
        }
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get((int)$job['project_id']);
            $v = DevProjectRegistry::validateRoot((string)$p['root_path']);
            if (empty($v['ok'])) return ['ok' => false, 'error' => 'Root không hợp lệ'];
            $root = (string)$v['canonical'];
            if (self::isDirty($root)) return ['ok' => false, 'error' => 'Main tree dirty'];
            // Revert merge commit can -m 1 (mainline); revert thuong khong can.
            // Dung rev-list (khong dung --format=%P vi % bi cmd.exe expand).
            $isMerge = false;
            try {
                $p = self::git($root, ['rev-list', '--parents', '-n', '1', (string)$job['applied_commit']]);
                $isMerge = !empty($p['ok']) && count(preg_split('/\s+/', trim((string)$p['out']))) > 2;
            } catch (Throwable $e) {
            }
            $args = ['revert', '--no-commit'];
            if ($isMerge) $args[] = '-m';
            if ($isMerge) $args[] = '1';
            $args[] = (string)$job['applied_commit'];
            $r = self::git($root, $args, 120);
            if (empty($r['ok'])) {
                self::git($root, ['revert', '--abort']);
                return ['ok' => false, 'error' => 'Revert conflict — đã abort'];
            }
            $r2 = self::git($root, ['commit', '-m', 'Revert AI ' . $code]);
            if (empty($r2['ok'])) {
                self::git($root, ['revert', '--abort']);
                return ['ok' => false, 'error' => 'Không commit được revert'];
            }
            self::set($code, ['apply_status' => 'ROLLED_BACK']);
            self::audit('ROLLBACK', $code, $by, (string)$job['applied_commit']);
            return ['ok' => true, 'commit' => self::headCommit($root)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Huy job: interrupt session + cleanup worktree. */
    public static function cancel(string $code, string $by): array
    {
        $job = self::get($code);
        if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
        try {
            if (!empty($job['opencode_session_id'])) {
                require_once __DIR__ . '/OpenCodeGateway.php';
                OpenCodeGateway::interrupt((string)$job['opencode_session_id']);
            }
        } catch (Throwable $e) {
        }
        self::set($code, ['status' => self::ST_CANCELLED, 'completed_at' => date('Y-m-d H:i:s')]);
        self::cleanupWorktree($job);
        self::audit('CANCEL', $code, $by, '');
        return ['ok' => true];
    }

    /** DEV_FOLLOWUP: tiep tuc cung session trong worktree. */
    public static function followup(string $code, string $prompt, string $by = ''): array
    {
        $job = self::get($code);
        if (!$job || empty($job['opencode_session_id'])) {
            return ['ok' => false, 'error' => 'Không có session để tiếp tục'];
        }
        if (!in_array($job['status'] ?? '', [self::ST_CODING, self::ST_TESTING, self::ST_REVIEW_READY, self::ST_FAILED], true)) {
            return ['ok' => false, 'error' => 'Job không ở trạng thái tiếp tục được'];
        }
        try {
            require_once __DIR__ . '/OpenCodeGateway.php';
            if (($job['status'] ?? '') === self::ST_REVIEW_READY) {
                self::set($code, ['status' => self::ST_CODING, 'review_status' => 'CHANGES_REQUESTED']);
            }
            $r = OpenCodeGateway::prompt((string)$job['opencode_session_id'], $prompt);
            if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'oc_error'];
            self::audit('FOLLOWUP', $code, $by, mb_substr($prompt, 0, 150));
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Don worktree + branch (giuu lai sau APPLY? mac dinh don de gon). */
    public static function cleanupWorktree(array $job, bool $afterApply = false): void
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get((int)$job['project_id']);
            if (!$p) return;
            $root = realpath((string)$p['root_path']);
            if ($root === false) return;
            $wt = (string)($job['worktree_path'] ?? '');
            // Chi xoa neu nam trong .worktrees cua project (an toan)
            $safe = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.worktrees' . DIRECTORY_SEPARATOR;
            if ($wt !== '' && str_starts_with(strtolower($wt), strtolower($safe))) {
                self::git($root, ['worktree', 'remove', '--force', $wt]);
            }
            if (!empty($job['work_branch']) && !$afterApply) {
                // Sau apply giu branch lich su? Don luon cho gon (commit da merge).
                self::git($root, ['branch', '-D', (string)$job['work_branch']]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Active dev context theo chat (settings). */
    public static function activeForChat(string $chatId): string
    {
        return (string)get_setting('ai_dev_active_' . md5($chatId), '');
    }

    public static function setActiveForChat(string $chatId, string $code): void
    {
        set_setting('ai_dev_active_' . md5($chatId), $code);
    }

    public static function clearActiveForChat(string $chatId): void
    {
        set_setting('ai_dev_active_' . md5($chatId), '');
    }

    /** Recovery sau restart: session chet -> INTERRUPTED (§67). */
    public static function recoverStale(): int
    {
        static $last = 0;
        if ((time() - $last) < 120) return 0;
        $last = time();
        $n = 0;
        try {
            self::ensureTables();
            $rows = db()->query("SELECT * FROM dev_jobs WHERE status IN ('CODING','TESTING','ANALYZING','PREPARING','APPLYING')")->fetchAll();
            require_once __DIR__ . '/OpenCodeGateway.php';
            foreach ($rows as $j) {
                $sid = (string)($j['opencode_session_id'] ?? '');
                $alive = $sid !== '' && OpenCodeGateway::getSession($sid) !== null;
                $old = !empty($j['started_at']) && (time() - strtotime((string)$j['started_at'])) > 3600;
                if ((!$alive && $old) || $old && $sid === '') {
                    self::set((string)$j['job_code'], ['status' => self::ST_INTERRUPTED,
                        'error' => 'App/OpenCode restart giữa chừng']);
                    $n++;
                }
            }
        } catch (Throwable $e) {
        }
        return $n;
    }
}
