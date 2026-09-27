<?php
declare(strict_types=1);
/**
 * DevPlanningEngine - Plan co cau truc + phan loai co nho + impact analysis.
 * TRIVIAL/SMALL: light plan. MEDIUM/LARGE: full plan. HIGH_RISK: + approval.
 */
require_once __DIR__ . '/../config.php';

class DevPlanningEngine
{
    /** @return TRIVIAL|SMALL|MEDIUM|LARGE|HIGH_RISK */
    public static function classify(string $request, array $impact = []): string
    {
        $low = mb_strtolower($request);
        if (!empty($impact['db_migration']) || !empty($impact['high_risk'])) return 'HIGH_RISK';
        if (preg_match('/\b(auth|password|login|token|secret|webhook|migration|startup|install|delete)\b/i', $request)) {
            return 'HIGH_RISK';
        }
        $words = count(preg_split('/\s+/u', trim($request)));
        if ($words <= 8 && !empty($impact) && (int)($impact['files'] ?? 99) <= 2) return 'SMALL';
        if ($words <= 5) return 'TRIVIAL';
        if (!empty($impact) && (int)($impact['files'] ?? 0) > 10) return 'LARGE';
        return 'MEDIUM';
    }

    /**
     * Impact analysis tu brain graph: symbols trong request -> callers/downstream.
     * @return array{files, symbols, downstream, db, api, ui, tests, high_risk, db_migration}
     */
    public static function impact(int $projectId, string $request): array
    {
        $out = ['files' => [], 'symbols' => [], 'downstream' => [], 'db' => [],
            'api' => [], 'ui' => [], 'tests' => [], 'high_risk' => [], 'db_migration' => false];
        try {
            require_once __DIR__ . '/SmartContextBuilder.php';
            require_once __DIR__ . '/ProjectBrainService.php';
            $terms = SmartContextBuilder::extractTerms($request);
            $syms = [];
            foreach (array_slice($terms, 0, 4) as $t) {
                foreach (ProjectBrainService::search($projectId, $t, 4) as $s) {
                    $syms[$s['symbol_name']] = $s;
                }
            }
            $files = [];
            foreach (array_slice($syms, 0, 6) as $name => $s) {
                $files[$s['file_path']] = true;
                $out['symbols'][] = $name;
                $rel = ProjectBrainService::related($projectId, $name, 8);
                foreach ($rel['callers'] as $c) $out['downstream'][] = $c;
                if (stripos($s['file_path'], 'api/') === 0) $out['api'][] = $s['file_path'];
                if (stripos($s['file_path'], 'assets/') === 0) $out['ui'][] = $s['file_path'];
            }
            $out['files'] = array_keys($files);
            $out['downstream'] = array_values(array_unique($out['downstream']));
            // DB impact: table names xuat hien trong request/files
            try {
                $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
                foreach ($tables as $t) {
                    $tn = (string)$t[0];
                    if (stripos($request, $tn) !== false) $out['db'][] = $tn;
                }
            } catch (Throwable $e) {
            }
            if (preg_match('/\bmigration\b/i', $request)) $out['db_migration'] = true;
            // High-risk patterns
            foreach (['auth', 'token', 'secret', 'password', 'webhook', 'startup', 'install', 'delete'] as $kw) {
                if (stripos($request, $kw) !== false) $out['high_risk'][] = $kw;
            }
            // Test impact: file test lien quan (ten chua symbol/module)
            $out['tests'] = self::suggestTests($out['files'], $out['symbols']);
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** @return string[] goi y test */
    public static function suggestTests(array $files, array $symbols): array
    {
        $out = [];
        $blob = implode(' ', array_merge($files, $symbols));
        $map = ['Telegram' => 'Telegram reconnect/pairing/chat receive', 'Chrome' => 'Browser start/stop',
            'Evaluation' => 'Evaluation batch', 'Activity' => 'Activity planner', 'Proxy' => 'Proxy check',
            'Job' => 'Job Center', 'Notification' => 'Notify rules', 'Health' => 'Health probes',
            'Scheduler' => 'Scheduler tick', 'AIDev' => 'AI Dev intents', 'Sync' => 'Sync session'];
        foreach ($map as $k => $v) {
            if (stripos($blob, $k) !== false) $out[] = $v;
        }
        return $out;
    }

    /**
     * Build plan structure (dua OpenCode, co impact + size).
     * @return array{ok, plan?, size?, impact?, error?}
     */
    public static function buildPlan(int $projectId, string $request, array $opts = []): array
    {
        try {
            require_once __DIR__ . '/SmartContextBuilder.php';
            require_once __DIR__ . '/OpenCodeService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            $impact = self::impact($projectId, $request);
            $size = self::classify($request, array_merge($impact, ['files' => count($impact['files'])]));
            $cx = SmartContextBuilder::build($projectId, $request, 'PLAN_REQUEST',
                ['history' => true]);
            if (empty($cx['ok'])) return ['ok' => false, 'error' => $cx['error'] ?? 'context_error'];
            $p = DevProjectRegistry::get($projectId);
            $light = in_array($size, ['TRIVIAL', 'SMALL'], true) && empty($opts['full']);
            $prompt = $light
                ? "Lập LIGHT PLAN (KHÔNG CODE, không sửa file) cho yêu cầu nhỏ:\nYÊU CẦU: $request\n"
                . "CONTEXT:\n" . $cx['context'] . "\nTrả lời gọn: GOAL | FILES | STEPS (1-5) | TEST."
                : "Lập PHƯƠNG ÁN (KHÔNG CODE, không sửa file):\nYÊU CẦU: $request\n"
                . "IMPACT SƠ BỘ: files=" . implode(',', array_slice($impact['files'], 0, 10))
                . " downstream=" . implode(',', array_slice($impact['downstream'], 0, 8)) . "\n"
                . "CONTEXT:\n" . $cx['context'] . "\nTrả lời ĐÚNG format:\nGOAL: ...\nCURRENT ARCHITECTURE: ...\n"
                . "PROPOSED CHANGE: ...\nFILES EXPECTED: ...\nDATABASE CHANGES: ...\nAPI CHANGES: ...\nUI CHANGES: ...\n"
                . "EVENT CHANGES: ...\nRISK: ...\nBACKWARD COMPATIBILITY: ...\nTEST PLAN: ...\nACCEPTANCE CRITERIA: ...";
            $ens = OpenCodeService::ensureRunning();
            if (empty($ens['ok'])) return ['ok' => false, 'error' => 'opencode_offline', 'size' => $size, 'impact' => $impact];
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Plan ' . $size,
                'directory' => $p ? (string)$p['root_path'] : '', 'timeout' => 600]);
            if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'oc_error', 'size' => $size, 'impact' => $impact];
            return ['ok' => true, 'plan' => trim((string)$r['text']), 'size' => $size,
                'impact' => $impact, 'session_id' => $r['session_id'] ?? '',
                'trace' => $cx['trace'] ?? []];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }
}
