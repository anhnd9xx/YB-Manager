<?php
declare(strict_types=1);
/**
 * AICodeReviewer - Review doc lap DIFF (khong dua vao loi coder) (§16).
 * Nhan: request, plan, diff, rules, test results -> PASS|PASS_WITH_WARNINGS|
 * CHANGES_REQUIRED|BLOCK + issues[{severity,file,symbol,description,fix}].
 */
require_once __DIR__ . '/../config.php';

class AICodeReviewer
{
    /**
     * @return array{ok, verdict?, issues?, raw?, error?}
     */
    public static function review(int $projectId, string $request, string $plan,
        string $diff, string $testReport, int $timeoutSec = 600): array
    {
        try {
            require_once __DIR__ . '/SmartContextBuilder.php';
            require_once __DIR__ . '/ArchitectureRuleEngine.php';
            require_once __DIR__ . '/OpenCodeService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            // Static checks truoc (BLOCK/WARNING kieu kien truc)
            $files = [];
            foreach (explode("\n", $diff) as $line) {
                if (preg_match('/^[AMD]\s+(.+)$/', trim($line), $m)) $files[] = trim($line);
            }
            $static = ArchitectureRuleEngine::check($files, $diff);
            $staticText = '';
            foreach ($static['violations'] ?? [] as $v) {
                $staticText .= '[' . $v['severity'] . '] ' . $v['rule'] . ': ' . $v['detail'] . "\n";
            }
            $cx = SmartContextBuilder::build($projectId, $request, 'CODE_REVIEW', []);
            $rules = ProjectKnowledgeService::relevant($projectId, $request . ' review rules', 5);
            $ruleText = '';
            foreach ($rules as $k) {
                $ruleText .= '[' . $k['ktype'] . '] ' . $k['title'] . ': ' . mb_substr((string)$k['body'], 0, 160) . "\n";
            }
            $p = DevProjectRegistry::get($projectId);
            $prompt = "Bạn là REVIEWER độc lập. Chỉ review DIFF dưới đây (đừng tin lời coder).\n"
                . "YÊU CẦU GỐC: " . mb_substr($request, 0, 800) . "\n"
                . "PLAN: " . mb_substr($plan, 0, 1500) . "\n"
                . "STATIC CHECKS (đã chạy):\n" . ($staticText !== '' ? $staticText : '(sạch)') . "\n"
                . "RULES:\n" . $ruleText . "\n"
                . "TEST RESULTS:\n" . mb_substr($testReport, 0, 800) . "\n"
                . "CONTEXT:\n" . ($cx['context'] ?? '') . "\n"
                . "DIFF:\n" . mb_substr($diff, 0, 12000) . "\n"
                . "Kiểm tra: correctness, regression, concurrency, async lifecycle, leaks, errors, "
                . "persistence, idempotency, security, architecture, duplicate service, test quality.\n"
                . "Trả lời ĐÚNG format:\nVERDICT: PASS|PASS_WITH_WARNINGS|CHANGES_REQUIRED|BLOCK\n"
                . "ISSUES:\n- [CRITICAL|MAJOR|MINOR] file: symbol — mô tả => fix: ...\n(mỗi dòng 1 issue, hoặc 'none')";
            $ens = OpenCodeService::ensureRunning();
            if (empty($ens['ok'])) return ['ok' => false, 'error' => 'opencode_offline'];
            // Session reviewer RIENG (doc lap coder)
            $c = OpenCodeGateway::createSession('Review', $p ? (string)$p['root_path'] : '');
            if (empty($c['ok'])) return ['ok' => false, 'error' => $c['error'] ?? 'oc_error'];
            $sid = (string)($c['session']['id'] ?? '');
            $pr = OpenCodeGateway::prompt($sid, $prompt);
            if (empty($pr['ok'])) return ['ok' => false, 'error' => $pr['error'] ?? 'oc_error'];
            $w = OpenCodeGateway::waitIdle($sid, $timeoutSec);
            if (empty($w['idle'])) return ['ok' => false, 'error' => 'oc_timeout', 'session_id' => $sid];
            $a = OpenCodeGateway::lastAssistantText($sid);
            return array_merge(['ok' => true, 'session_id' => $sid],
                self::parseReview(trim((string)$a['text'])));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{verdict, issues[], raw} */
    public static function parseReview(string $text): array
    {
        $verdict = 'CHANGES_REQUIRED';
        if (preg_match('/VERDICT:\s*(PASS_WITH_WARNINGS|CHANGES_REQUIRED|BLOCK|PASS)/i', $text, $m)) {
            $verdict = strtoupper($m[1]);
        }
        $issues = [];
        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if (preg_match('/^-\s*\[(CRITICAL|MAJOR|MINOR)\]\s*(.+)$/i', $t, $m)) {
                if (strcasecmp(trim($m[2]), 'none') === 0) continue;
                $issues[] = ['severity' => strtoupper($m[1]), 'text' => mb_substr(trim($m[2]), 0, 300)];
            }
        }
        return ['verdict' => $verdict, 'issues' => $issues, 'raw' => mb_substr($text, 0, 3000)];
    }
}
