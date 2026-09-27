<?php
declare(strict_types=1);
/**
 * DevJobPipeline - Trang thai may V2: REQUEST -> CONTEXT -> PLAN -> IMPACT ->
 * WORKTREE -> CODING -> STATIC -> TEST -> REVIEW -> FIX? -> VERIFY -> REVIEW_READY.
 * Tien tu dong (runner) hoac tung buoc (UI/API). Khong thay approval flow.
 */
require_once __DIR__ . '/../config.php';

class DevJobPipeline
{
    public const MAX_FIX_ITERS = 3;

    /**
     * Tien 1 buoc tuy state. Idempotent. @return array{ok, advanced?, state?, error?}
     */
    public static function advance(string $code): array
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $job = DevJobManager::get($code);
            if (!$job) return ['ok' => false, 'error' => 'Không thấy job'];
            $st = (string)$job['status'];
            // Throttle buoc ton tien (plan): khong spam session moi
            if (in_array($st, ['ANALYZING'], true)) {
                $last = (int)get_setting('ai_adv_at_' . $code, '0');
                if ((time() - $last) < 600) {
                    return ['ok' => true, 'advanced' => false, 'state' => $st, 'throttled' => true];
                }
                set_setting('ai_adv_at_' . $code, (string)time());
            }
            switch ($st) {
                case DevJobManager::ST_QUEUED:
                    return self::stepPrepare($job);
                case DevJobManager::ST_ANALYZING:
                    return self::stepPlan($job);
                case DevJobManager::ST_CODING:
                    return self::stepCoding($job);
                case DevJobManager::ST_TESTING:
                    return self::stepTest($job);
                default:
                    return ['ok' => true, 'advanced' => false, 'state' => $st];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    private static function stepPrepare(array $job): array
    {
        $code = (string)$job['job_code'];
        $r = DevJobManager::prepare($code, (string)($job['requested_by'] ?? ''));
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'prepare_fail'];
        return ['ok' => true, 'advanced' => true, 'state' => DevJobManager::ST_ANALYZING];
    }

    private static function stepPlan(array $job): array
    {
        $code = (string)$job['job_code'];
        require_once __DIR__ . '/DevPlanningEngine.php';
        $b = DevPlanningEngine::buildPlan((int)$job['project_id'], (string)$job['request'],
            !empty($job['plan_text']) ? ['full' => true] : []);
        if (empty($b['ok'])) {
            if (($b['error'] ?? '') === 'opencode_offline') {
                return ['ok' => false, 'error' => 'opencode_offline'];
            }
            return ['ok' => false, 'error' => $b['error'] ?? 'plan_fail'];
        }
        DevJobManager::setFields($code, [
            'planning_summary' => mb_substr((string)$b['plan'], 0, 4000),
            'impact_report' => json_encode($b['impact'] ?? [], JSON_UNESCAPED_UNICODE),
            'acceptance_criteria' => self::extractAcceptance((string)$b['plan']),
            'risk_level' => (string)($b['size'] ?? 'MEDIUM'),
            'expected_files' => json_encode(array_slice($b['impact']['files'] ?? [], 0, 30), JSON_UNESCAPED_UNICODE),
        ]);
        // Coding ngay voi package day du
        $plan = (string)$b['plan'];
        $st = DevJobManager::startCoding($code, (string)($job['requested_by'] ?? ''), $plan);
        if (empty($st['ok'])) return ['ok' => false, 'error' => $st['error'] ?? 'coding_fail'];
        return ['ok' => true, 'advanced' => true, 'state' => DevJobManager::ST_CODING];
    }

    private static function stepCoding(array $job): array
    {
        // Doi session idle roi chuyen TESTING (poll ngan, runner goi lai)
        $code = (string)$job['job_code'];
        require_once __DIR__ . '/OpenCodeGateway.php';
        $sid = (string)($job['opencode_session_id'] ?? '');
        if ($sid === '') return ['ok' => false, 'error' => 'no_session'];
        $w = OpenCodeGateway::waitIdle($sid, 60);
        if (empty($w['idle'])) return ['ok' => true, 'advanced' => false, 'state' => 'CODING'];
        DevJobManager::setFields($code, ['status' => DevJobManager::ST_TESTING]);
        return ['ok' => true, 'advanced' => true, 'state' => DevJobManager::ST_TESTING];
    }

    private static function stepTest(array $job): array
    {
        $code = (string)$job['job_code'];
        $r = DevJobManager::runTests($code, (string)($job['requested_by'] ?? ''));
        if (empty($r['ok'])) {
            // Self-fix loop (toi da 3)
            $iters = (int)($job['fix_iterations'] ?? 0);
            if ($iters < self::MAX_FIX_ITERS) {
                DevJobManager::setFields($code, ['fix_iterations' => $iters + 1, 'status' => DevJobManager::ST_CODING]);
                DevJobManager::followup($code,
                    'Tests FAIL: ' . mb_substr(json_encode($r['report'] ?? [], JSON_UNESCAPED_UNICODE), 0, 800)
                    . '. Hãy sửa cho pass rồi báo cáo lại.',
                    (string)($job['requested_by'] ?? ''));
                return ['ok' => true, 'advanced' => true, 'state' => 'CODING_FIX_' . ($iters + 1)];
            }
            return ['ok' => false, 'error' => 'tests_failed_max_iters'];
        }
        return self::stepReview(DevJobManager::get($code) ?: $job);
    }

    /** Review doc lap + scope guard + verify -> REVIEW_READY hoac fix loop. */
    private static function stepReview(array $job): array
    {
        $code = (string)$job['job_code'];
        require_once __DIR__ . '/AICodeReviewer.php';
        require_once __DIR__ . '/ArchitectureRuleEngine.php';
        require_once __DIR__ . '/TestSelectionService.php';
        // Scope guard: diff files vs expected
        $scopeNote = self::scopeCheck($job);
        $diff = self::worktreeDiff($job);
        $rev = AICodeReviewer::review((int)$job['project_id'], (string)$job['request'],
            (string)($job['planning_summary'] ?? $job['plan_text'] ?? ''),
            $diff, (string)($job['test_report'] ?? ''));
        if (empty($rev['ok'])) return ['ok' => false, 'error' => $rev['error'] ?? 'review_fail'];
        $verdict = (string)($rev['verdict'] ?? 'CHANGES_REQUIRED');
        DevJobManager::setFields($code, ['review_result' => json_encode([
            'verdict' => $verdict, 'issues' => $rev['issues'] ?? [], 'scope' => $scopeNote,
            'session' => $rev['session_id'] ?? ''], JSON_UNESCAPED_UNICODE)]);
        if ($verdict === 'BLOCK') {
            DevJobManager::setFields($code, ['status' => DevJobManager::ST_FAILED,
                'error' => 'Review BLOCK: ' . mb_substr(json_encode($rev['issues'] ?? []), 0, 200)]);
            return ['ok' => false, 'error' => 'review_blocked'];
        }
        if ($verdict === 'CHANGES_REQUIRED') {
            $iters = (int)($job['fix_iterations'] ?? 0);
            if ($iters < self::MAX_FIX_ITERS) {
                DevJobManager::setFields($code, ['fix_iterations' => $iters + 1, 'status' => DevJobManager::ST_CODING]);
                $fix = 'Review yêu cầu sửa:' . "\n";
                foreach (array_slice($rev['issues'] ?? [], 0, 10) as $is) {
                    $fix .= '- [' . ($is['severity'] ?? '') . '] ' . ($is['text'] ?? '') . "\n";
                }
                DevJobManager::followup($code, mb_substr($fix, 0, 2000), (string)($job['requested_by'] ?? ''));
                return ['ok' => true, 'advanced' => true, 'state' => 'CODING_FIX_' . ($iters + 1)];
            }
            DevJobManager::setFields($code, ['status' => DevJobManager::ST_FAILED,
                'error' => 'Review changes-required quá số lần']);
            return ['ok' => false, 'error' => 'review_failed_max_iters'];
        }
        // PASS / PASS_WITH_WARNINGS -> verify acceptance -> REVIEW_READY
        $verify = self::verifyAcceptance($job);
        DevJobManager::setFields($code, ['verification_result' => $verify]);
        DevJobManager::finishReview($code, (string)($job['requested_by'] ?? ''));
        return ['ok' => true, 'advanced' => true, 'state' => DevJobManager::ST_REVIEW_READY];
    }

    /** Scope guard: files ngoai expected (tru tests/types/migration/imports co giai trinh). */
    private static function scopeCheck(array $job): string
    {
        try {
            $expected = json_decode((string)($job['expected_files'] ?? ''), true);
            if (!is_array($expected)) $expected = [];
            $diff = self::worktreeDiff($job, true);
            $changed = [];
            foreach (explode("\n", $diff) as $line) {
                if (preg_match('/^[AMD]\s+(.+)$/', trim($line), $m)) $changed[] = trim($m[1]);
            }
            $extra = [];
            foreach ($changed as $f) {
                $ok = false;
                foreach ($expected as $e) {
                    if ($f === $e || str_contains($f, (string)$e) || str_contains((string)$e, $f)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok && preg_match('/(test|spec|types?\.php|migration|\.sql|import)/i', $f)) $ok = true;
                if (!$ok) $extra[] = $f;
            }
            if ($extra) return 'SCOPE_EXPANSION: ' . implode(',', array_slice($extra, 0, 10));
            return 'scope ok (' . count($changed) . ' files)';
        } catch (Throwable $e) {
            return 'scope check error';
        }
    }

    /** @return string name-status diff (khong full content neu $namesOnly) */
    private static function worktreeDiff(array $job, bool $namesOnly = true): string
    {
        try {
            $wt = (string)($job['worktree_path'] ?? '');
            if ($wt === '' || !is_dir($wt)) return '';
            DevJobManager::stageWorktree($job);
            $r = DevJobManager::git($wt, $namesOnly
                ? ['diff', '--name-status', (string)$job['base_branch'] . '...' . (string)$job['work_branch']]
                : ['diff', (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
            $out = (string)($r['out'] ?? '');
            return $namesOnly ? $out : mb_substr($out, 0, 12000);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Verify acceptance criteria don gian: checklist vs tests/review. */
    private static function verifyAcceptance(array $job): string
    {
        $lines = [];
        foreach (explode("\n", (string)($job['acceptance_criteria'] ?? '')) as $line) {
            $t = trim($line, " \t-•*");
            if ($t !== '') $lines[] = $t;
        }
        if (!$lines) return 'NO_CRITERIA';
        $tested = ($job['test_status'] ?? '') === 'PASS';
        $out = [];
        $unverified = 0;
        foreach (array_slice($lines, 0, 15) as $c) {
            $ok = $tested && !preg_match('/sleep|wake|manual|thủ công/i', $c);
            if (!$ok) $unverified++;
            $out[] = ($ok ? '[✓] ' : '[✕] ') . mb_substr($c, 0, 120);
        }
        $out[] = $unverified === 0 ? 'Status: VERIFIED' : 'Status: PARTIALLY VERIFIED';
        return implode("\n", $out);
    }

    private static function extractAcceptance(string $plan): string
    {
        if (preg_match('/ACCEPTANCE CRITERIA:\s*(.+?)(\n[A-Z][A-Z ]+:\s*|\z)/s', $plan, $m)) {
            return trim(mb_substr($m[1], 0, 1500));
        }
        return '';
    }
}
