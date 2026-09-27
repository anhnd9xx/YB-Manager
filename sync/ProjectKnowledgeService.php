<?php
declare(strict_types=1);
/**
 * ProjectKnowledgeService - Kien thuc co cau truc (khong phai raw chat).
 * Types: ARCHITECTURE_DECISION | PROJECT_RULE | KNOWN_BUG | BUG_FIX |
 * MODULE_DESCRIPTION | TECH_DEBT | TEST_REQUIREMENT | INVARIANT | LESSON_LEARNED.
 */
require_once __DIR__ . '/../config.php';

class ProjectKnowledgeService
{
    public const TYPES = ['ARCHITECTURE_DECISION', 'PROJECT_RULE', 'KNOWN_BUG', 'BUG_FIX',
        'MODULE_DESCRIPTION', 'TECH_DEBT', 'TEST_REQUIREMENT', 'INVARIANT', 'LESSON_LEARNED'];

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS project_knowledge (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL DEFAULT 0,
                ktype VARCHAR(30) NOT NULL DEFAULT 'LESSON_LEARNED',
                title VARCHAR(190) NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                source VARCHAR(40) NOT NULL DEFAULT '',
                source_id VARCHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_know_proj (project_id, ktype)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS known_issues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL DEFAULT 0,
                signature VARCHAR(120) NOT NULL DEFAULT '',
                symptoms VARCHAR(500) NOT NULL DEFAULT '',
                module VARCHAR(40) NOT NULL DEFAULT '',
                root_cause VARCHAR(500) NOT NULL DEFAULT '',
                fix_dev_job VARCHAR(20) NOT NULL DEFAULT '',
                regression_test VARCHAR(255) NOT NULL DEFAULT '',
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_issue_sig (project_id, signature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
        self::seedDefaults();
    }

    /** Seed invariants da biet (khong trung). */
    private static function seedDefaults(): void
    {
        static $seeded = false;
        if ($seeded) return;
        $seeded = true;
        $defs = [
            ['INVARIANT', 'Telegram single consumer', 'Telegram chỉ có đúng 1 getUpdates consumer (bin/telegram_polling.php). Pairing/Chat/Commands subscribe cùng worker, không tạo poller riêng.', 'seed', ''],
            ['INVARIANT', 'WindowPlacementManager duy nhất', 'Chỉ WindowPlacementManager được reposition Chrome. Auto Activity tuyệt đối không MoveWindow/SetWindowPos/focus.', 'seed', ''],
            ['PROJECT_RULE', 'Long ops qua JobManager', 'Mọi batch/long operation dùng JobManager với progress thật (completed/total), không dispatch-count.', 'seed', ''],
            ['PROJECT_RULE', 'Secrets không log', 'Bot token, proxy password, API key, cookie không log, không đưa thừa vào prompt.', 'seed', ''],
            ['PROJECT_RULE', 'EventBus cho modules', 'Modules giao tiếp qua EventBus; NotificationManager route theo rules.', 'seed', ''],
            ['LESSON_LEARNED', 'Không process.wait tuần tự trong Stop All', 'Stop All phải poll bounded, không chờ tuần tự từng profile.', 'seed', ''],
            ['KNOWN_BUG', 'Secondary monitor nhảy về primary', 'Chrome secondary monitor có thể nhảy về primary sau delayed placement callback — fix thuộc WindowPlacementManager.', 'seed', ''],
            ['ARCHITECTURE_DECISION', 'AI code trong worktree', 'OpenCode chỉ sửa trong git worktree cách ly; apply bằng merge sau APPROVE; rollback bằng revert.', 'seed', ''],
        ];
        try {
            foreach ($defs as [$t, $title, $body, $src, $sid]) {
                $st = db()->prepare('SELECT id FROM project_knowledge WHERE project_id=1 AND ktype=? AND title=? LIMIT 1');
                $st->execute([$t, $title]);
                if ($st->fetch()) continue;
                db()->prepare('INSERT INTO project_knowledge (project_id, ktype, title, body, source, source_id)
                    VALUES (1,?,?,?,?,?)')->execute([$t, $title, $body, $src, $sid]);
            }
        } catch (Throwable $e) {
        }
    }

    /** @return int|null id */
    public static function add(int $projectId, string $type, string $title, string $body,
        string $source = '', string $sourceId = ''): ?int
    {
        self::ensureTables();
        if (!in_array($type, self::TYPES, true)) $type = 'LESSON_LEARNED';
        try {
            db()->prepare('INSERT INTO project_knowledge (project_id, ktype, title, body, source, source_id)
                VALUES (?,?,?,?,?,?)')
                ->execute([$projectId, $type, mb_substr($title, 0, 190), $body, mb_substr($source, 0, 40), mb_substr($sourceId, 0, 64)]);
            return (int)db()->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array[] lien quan (match tu khoa don gian). */
    public static function relevant(int $projectId, string $query, int $limit = 8): array
    {
        self::ensureTables();
        try {
            $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($query)),
                fn($w) => mb_strlen($w) > 3));
            $words = array_slice(array_unique($words), 0, 8);
            if (!$words) {
                $st = db()->prepare("SELECT * FROM project_knowledge WHERE project_id=?
                    AND ktype IN ('INVARIANT','PROJECT_RULE') ORDER BY id LIMIT $limit");
                $st->execute([$projectId]);
                return $st->fetchAll();
            }
            $conds = [];
            $p = [$projectId];
            foreach ($words as $w) {
                $conds[] = '(LOWER(title) LIKE ? OR LOWER(body) LIKE ?)';
                $p[] = "%$w%";
                $p[] = "%$w%";
            }
            $st = db()->prepare('SELECT *, (ktype IN (\'INVARIANT\',\'PROJECT_RULE\')) AS prio
                FROM project_knowledge WHERE project_id=? AND (' . implode(' OR ', $conds) . ')
                ORDER BY prio DESC, id DESC LIMIT ' . max(1, min(20, $limit)));
            $st->execute($p);
            $rows = $st->fetchAll();
            if (count($rows) < 3) {
                // Luon kem invariants co ban
                $st2 = db()->prepare("SELECT * FROM project_knowledge WHERE project_id=?
                    AND ktype='INVARIANT' ORDER BY id LIMIT 3");
                $st2->execute([$projectId]);
                foreach ($st2->fetchAll() as $r) $rows[] = $r;
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function list(int $projectId, ?string $type = null, int $limit = 50): array
    {
        self::ensureTables();
        try {
            $w = 'project_id=?';
            $p = [$projectId];
            if ($type !== null && $type !== '') {
                $w .= ' AND ktype=?';
                $p[] = $type;
            }
            $st = db()->prepare("SELECT * FROM project_knowledge WHERE $w ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function delete(int $id): bool
    {
        self::ensureTables();
        try {
            $st = db()->prepare('DELETE FROM project_knowledge WHERE id=?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ================= Known issues =================

    public static function upsertIssue(int $projectId, string $signature, string $symptoms,
        string $module, string $rootCause, string $fixJob = '', string $regression = ''): void
    {
        self::ensureTables();
        try {
            db()->prepare('INSERT INTO known_issues (project_id, signature, symptoms, module,
                    root_cause, fix_dev_job, regression_test, resolved_at)
                VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE symptoms=VALUES(symptoms),
                    module=VALUES(module), root_cause=VALUES(root_cause), fix_dev_job=VALUES(fix_dev_job),
                    regression_test=VALUES(regression_test), resolved_at=NOW()')
                ->execute([$projectId, mb_substr($signature, 0, 120), mb_substr($symptoms, 0, 500),
                    mb_substr($module, 0, 40), mb_substr($rootCause, 0, 500),
                    mb_substr($fixJob, 0, 20), mb_substr($regression, 0, 255)]);
        } catch (Throwable $e) {
        }
    }

    /** @return array[] issues match symptoms */
    public static function matchIssues(int $projectId, string $text, int $limit = 5): array
    {
        self::ensureTables();
        try {
            $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($text)),
                fn($w) => mb_strlen($w) > 3));
            $words = array_slice(array_unique($words), 0, 8);
            if (!$words) return [];
            $conds = [];
            $p = [$projectId];
            foreach ($words as $w) {
                $conds[] = '(LOWER(signature) LIKE ? OR LOWER(symptoms) LIKE ? OR LOWER(root_cause) LIKE ?)';
                $p[] = "%$w%";
                $p[] = "%$w%";
                $p[] = "%$w%";
            }
            $st = db()->prepare('SELECT * FROM known_issues WHERE project_id=? AND ('
                . implode(' OR ', $conds) . ') ORDER BY resolved_at DESC LIMIT ' . max(1, min(10, $limit)));
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function listIssues(int $projectId, int $limit = 50): array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM known_issues WHERE project_id=? ORDER BY resolved_at DESC LIMIT ' . max(1, min(100, $limit)));
            $st->execute([$projectId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Hoc tu DevJob APPLIED: tao summary co cau truc (khong luu conversation).
     */
    public static function learnFromJob(array $job): void
    {
        try {
            self::ensureTables();
            $pid = (int)($job['project_id'] ?? 0);
            $code = (string)($job['job_code'] ?? '');
            if ($pid <= 0 || $code === '') return;
            $summary = "Problem: " . mb_substr((string)($job['request'] ?? ''), 0, 300) . "\n"
                . "Fix: " . mb_substr((string)($job['summary'] ?? ''), 0, 500) . "\n"
                . "Files: " . (int)($job['files_changed'] ?? 0)
                . " (+" . (int)($job['lines_added'] ?? 0) . "/-" . (int)($job['lines_removed'] ?? 0) . ")\n"
                . "Tests: " . (string)($job['test_status'] ?? '') . "\n"
                . "Risks: " . (string)($job['high_risk_flags'] ?? '');
            self::add($pid, 'BUG_FIX', "Fix từ $code", $summary, 'devjob', $code);
        } catch (Throwable $e) {
        }
    }
}
