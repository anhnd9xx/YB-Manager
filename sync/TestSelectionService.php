<?php
declare(strict_types=1);
/**
 * TestSelectionService - Chon tests theo symbols/files doi (§15).
 * Levels: 1 lint/syntax | 2 targeted | 3 module | 4 integration | 5 full.
 */
require_once __DIR__ . '/../config.php';

class TestSelectionService
{
    /**
     * @param string[] $files changelist (name-status paths)
     * @return array{level:int, commands:array[], rationale:string}
     */
    public static function select(array $files, string $risk = ''): array
    {
        $names = [];
        foreach ($files as $f) {
            $parts = preg_split('/\s+/', trim($f), 2);
            $names[] = strtolower($parts[count($parts) - 1]);
        }
        $blob = implode(' ', $names);
        $hasPhp = (bool)preg_match('/\.php$/', $blob);
        $level = 1;
        $commands = [];
        if ($hasPhp) $commands[] = ['key' => 'php_lint', 'label' => 'PHP lint changed files', 'level' => 1];
        // Module mapping -> targeted checks (hien tai: selftest/endpoint probes)
        $map = [
            'telegram' => ['key' => 'tg_diag', 'label' => 'Telegram diagnostics + Chat Test', 'level' => 3],
            'activity' => ['key' => 'act_plan', 'label' => 'Activity planner dry-run', 'level' => 3],
            'job' => ['key' => 'job_history', 'label' => 'Job history + worker alive', 'level' => 3],
            'evaluat' => ['key' => 'eval_stage', 'label' => 'Evaluation stage check', 'level' => 3],
            'proxy' => ['key' => 'proxy_check', 'label' => 'Proxy check job', 'level' => 3],
            'aidev' => ['key' => 'aidev_intents', 'label' => 'AI intents + permissions', 'level' => 2],
            'health' => ['key' => 'health_api', 'label' => 'Health API', 'level' => 2],
        ];
        foreach ($map as $k => $c) {
            if (str_contains($blob, $k)) {
                $commands[] = $c;
                $level = max($level, $c['level']);
            }
        }
        if ($risk === 'HIGH_RISK' || count($names) > 15) {
            $level = 5;
            $commands[] = ['key' => 'full_regression', 'label' => 'Full regression checklist', 'level' => 5];
        }
        if (count($commands) === 1) {
            $commands[] = ['key' => 'api_smoke', 'label' => 'API smoke (page 200)', 'level' => 2];
            $level = max($level, 2);
        }
        return ['level' => $level, 'commands' => $commands,
            'rationale' => count($names) . ' files changed, risk=' . ($risk ?: 'normal')];
    }
}
