<?php
declare(strict_types=1);
/**
 * AIDiagnosisEngine - Chuan doan co cau truc: runtime + logs + source +
 * known bugs -> OpenCode -> PROBLEM/EVIDENCE/CAUSE/CONFIDENCE/FIX/TEST.
 * Luu DIAG-xxx de "fix loi do" resolve duoc.
 */
require_once __DIR__ . '/../config.php';

class AIDiagnosisEngine
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS ai_diagnoses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                diag_code VARCHAR(20) NOT NULL DEFAULT '',
                project_id INT NOT NULL DEFAULT 0,
                problem TEXT NOT NULL,
                runtime_snapshot TEXT NULL,
                evidence TEXT NULL,
                root_cause VARCHAR(1000) NOT NULL DEFAULT '',
                confidence VARCHAR(10) NOT NULL DEFAULT 'LOW',
                affected TEXT NULL,
                recommended_fix TEXT NULL,
                test_plan TEXT NULL,
                dev_job_code VARCHAR(20) NOT NULL DEFAULT '',
                source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
                requested_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_diag_code (diag_code),
                KEY idx_diag_proj (project_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /**
     * @return array{ok, diag?, error?} diag co code DIAG-xxx
     */
    public static function diagnose(int $projectId, string $problem, string $source = 'TELEGRAM',
        string $requestedBy = '', array $entities = []): array
    {
        self::ensureTable();
        try {
            require_once __DIR__ . '/SmartContextBuilder.php';
            require_once __DIR__ . '/RuntimeContextService.php';
            require_once __DIR__ . '/ProjectKnowledgeService.php';
            require_once __DIR__ . '/OpenCodeService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            // 1. Runtime evidence
            $snap = RuntimeContextService::snapshot($projectId);
            $rtText = RuntimeContextService::toText($snap);
            // 2. Known issues match truoc (regression uu tien)
            $issues = ProjectKnowledgeService::matchIssues($projectId, $problem, 3);
            $issueText = '';
            foreach ($issues as $is) {
                $issueText .= "KNOWN [" . $is['signature'] . '] ' . $is['root_cause']
                    . ' (fix: ' . $is['fix_dev_job'] . ")\n";
            }
            // 3. Smart context (symbols + rules + logs + history)
            $cx = SmartContextBuilder::build($projectId, $problem, 'HYBRID_DIAGNOSIS',
                ['runtime' => true, 'logs' => true, 'history' => true]);
            if (empty($cx['ok'])) return ['ok' => false, 'error' => $cx['error'] ?? 'context_error'];
            // 4. OpenCode analysis ( structured output yeu cau )
            $ens = OpenCodeService::ensureRunning();
            if (empty($ens['ok'])) return ['ok' => false, 'error' => 'opencode_offline'];
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get($projectId);
            $prompt = "Chẩn đoán sự cố (CHỈ ĐỌC, không sửa file).\nVẤN ĐỀ: $problem\n"
                . "RUNTIME:\n$rtText\n"
                . ($issueText !== '' ? "KNOWN ISSUES (ưu tiên kiểm tra regression):\n$issueText\n" : '')
                . "CONTEXT:\n" . $cx['context'] . "\n"
                . "Trả lời ĐÚNG format:\nPROBLEM: ...\nEVIDENCE: ...\nLIKELY ROOT CAUSE: ...\n"
                . "CONFIDENCE: HIGH|MEDIUM|LOW\nAFFECTED: ...\nRECOMMENDED FIX: ...\nTEST PLAN: ...";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Diagnosis',
                'directory' => $p ? (string)$p['root_path'] : '', 'timeout' => 300]);
            if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'oc_error'];
            $text = trim((string)$r['text']);
            $parsed = self::parseDiagnosis($text);
            // 5. Luu DIAG
            db()->prepare('INSERT INTO ai_diagnoses (project_id, problem, runtime_snapshot, evidence,
                    root_cause, confidence, affected, recommended_fix, test_plan, source, requested_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$projectId, mb_substr($problem, 0, 2000), mb_substr($rtText, 0, 2000),
                    mb_substr($parsed['evidence'], 0, 2000), mb_substr($parsed['cause'], 0, 1000),
                    $parsed['confidence'], mb_substr($parsed['affected'], 0, 1000),
                    mb_substr($parsed['fix'], 0, 2000), mb_substr($parsed['test'], 0, 1000),
                    $source, mb_substr($requestedBy, 0, 64)]);
            $id = (int)db()->lastInsertId();
            $code = 'DIAG-' . $id;
            db()->prepare('UPDATE ai_diagnoses SET diag_code=? WHERE id=?')->execute([$code, $id]);
            try {
                require_once __DIR__ . '/SyncLogger.php';
                SyncLogger::info('aidev', "[DIAG] $code conf=" . $parsed['confidence']);
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'diag' => array_merge(['diag_code' => $code], $parsed),
                'trace' => $cx['trace'] ?? [], 'session_id' => $r['session_id'] ?? ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Parse output co cau truc; confidence thap mac dinh. */
    public static function parseDiagnosis(string $text): array
    {
        $out = ['problem' => '', 'evidence' => '', 'cause' => '', 'confidence' => 'LOW',
            'affected' => '', 'fix' => '', 'test' => '', 'raw' => mb_substr($text, 0, 3000)];
        $map = ['PROBLEM' => 'problem', 'EVIDENCE' => 'evidence', 'LIKELY ROOT CAUSE' => 'cause',
            'ROOT CAUSE' => 'cause', 'AFFECTED' => 'affected', 'RECOMMENDED FIX' => 'fix',
            'TEST PLAN' => 'test'];
        $lines = explode("\n", $text);
        $cur = '';
        foreach ($lines as $line) {
            $t = trim($line);
            $hit = false;
            foreach ($map as $k => $f) {
                if (stripos($t, $k . ':') === 0) {
                    $cur = $f;
                    $out[$f] .= ' ' . trim(substr($t, strlen($k) + 1));
                    $hit = true;
                    break;
                }
            }
            if (!$hit && $cur !== '' && $t !== '') $out[$cur] .= ' ' . $t;
        }
        if (preg_match('/CONFIDENCE:\s*(HIGH|MEDIUM|LOW)/i', $text, $m)) {
            $out['confidence'] = strtoupper($m[1]);
        }
        foreach ($out as $k => $v) {
            if (is_string($v)) $out[$k] = trim(mb_substr($v, 0, 1500));
        }
        return $out;
    }

    public static function get(string $code): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare('SELECT * FROM ai_diagnoses WHERE diag_code=?');
            $st->execute([strtoupper(trim($code))]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function list(int $projectId, int $limit = 20): array
    {
        self::ensureTable();
        try {
            $st = db()->prepare('SELECT diag_code, problem, root_cause, confidence, dev_job_code, created_at
                FROM ai_diagnoses WHERE project_id=? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
            $st->execute([$projectId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function linkJob(string $code, string $jobCode): void
    {
        try {
            self::ensureTable();
            db()->prepare('UPDATE ai_diagnoses SET dev_job_code=? WHERE diag_code=?')->execute([$jobCode, $code]);
        } catch (Throwable $e) {
        }
    }
}
