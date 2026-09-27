<?php
declare(strict_types=1);
/**
 * ArchitectureRuleEngine - Chan diff vi pham kien truc (§22).
 * Rules tu DEV_RULES + invariants: new poller, SetWindowPos ngoai WPM,
 * JobManager bypass, secrets trong diff, duplicate service.
 */
require_once __DIR__ . '/../config.php';

class ArchitectureRuleEngine
{
    /**
     * @param string[] $files ["STATUS path", ...] tu git diff --name-status
     * @param string $diffFull full diff (gioi han) de scan noi dung
     * @return array{violations: array[{rule, severity, file, detail}]}
     */
    public static function check(array $files, string $diffFull = ''): array
    {
        $viol = [];
        $names = [];
        foreach ($files as $f) {
            $parts = preg_split('/\s+/', trim($f), 2);
            $names[] = $parts[count($parts) - 1];
        }
        $blob = implode("\n", $names);
        // 1. New Telegram poller
        foreach ($names as $n) {
            $l = strtolower($n);
            if ((str_contains($l, 'poll') || str_contains($l, 'getupdates'))
                && !in_array(basename($l), ['telegram_polling.php', 'telegrampollingctl.php'], true)) {
                $viol[] = ['rule' => 'SINGLE_POLL_CONSUMER', 'severity' => 'BLOCK',
                    'file' => $n, 'detail' => 'Nghi tạo poller Telegram thứ hai'];
            }
        }
        if (preg_match('/getUpdates/i', $diffFull) && !preg_match('/telegram_polling\.php/i', $blob)) {
            // getUpdates chi hop le trong transport hien co
            $viol[] = ['rule' => 'SINGLE_POLL_CONSUMER', 'severity' => 'WARNING',
                'file' => '', 'detail' => 'Diff nhắc getUpdates ngoài transport hiện có — kiểm tra'];
        }
        // 2. SetWindowPos/MoveWindow ngoai WindowPlacementManager
        if (preg_match('/\b(SetWindowPos|MoveWindow|SetForegroundWindow)\b/', $diffFull)) {
            $outside = true;
            foreach ($names as $n) {
                if (stripos($n, 'WindowPlacementManager') !== false) {
                    $outside = false;
                    break;
                }
            }
            if ($outside) {
                $viol[] = ['rule' => 'WPM_ONLY', 'severity' => 'BLOCK',
                    'file' => '', 'detail' => 'Reposition/focus window ngoài WindowPlacementManager'];
            }
        }
        // 3. Long task bypass JobManager: batch loop moi ma khong dung JobManager
        if (preg_match('/foreach\s*\(\s*\$(profiles|ids|targets)\b/i', $diffFull)
            && stripos($diffFull, 'JobManager') === false
            && preg_match('/launch|startChrome|openProfile|evaluate_one/i', $diffFull)) {
            $viol[] = ['rule' => 'JOB_MANAGER', 'severity' => 'WARNING',
                'file' => '', 'detail' => 'Batch loop mới không qua JobManager — kiểm tra'];
        }
        // 4. Secrets trong diff
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            $red = ProjectContextService::redactSecrets($diffFull);
            if ($red !== $diffFull) {
                $viol[] = ['rule' => 'NO_SECRETS', 'severity' => 'BLOCK',
                    'file' => '', 'detail' => 'Diff chứa secret (token/password/key)'];
            }
        } catch (Throwable $e) {
        }
        // 5. Duplicate service: class moi trung ten class cu
        try {
            require_once __DIR__ . '/ProjectBrainService.php';
            if (preg_match_all('/^\+\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $diffFull, $m)) {
                foreach (array_unique($m[1]) as $cls) {
                    $ex = ProjectBrainService::search(1, $cls, 3);
                    foreach ($ex as $s) {
                        if (strcasecmp((string)$s['symbol_name'], $cls) === 0
                            && stripos(implode(',', $names), (string)$s['file_path']) === false) {
                            $viol[] = ['rule' => 'NO_DUPLICATE_SERVICE', 'severity' => 'WARNING',
                                'file' => '', 'detail' => "Class $cls đã tồn tại ở " . $s['file_path']];
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }
        // 6. sleep dai trong worker (handover thoi gian)
        if (preg_match('/sleep\s*\(\s*(\d+)\s*\)/', $diffFull, $m) && (int)$m[1] >= 60) {
            $viol[] = ['rule' => 'NO_LONG_SLEEP', 'severity' => 'WARNING',
                'file' => '', 'detail' => 'sleep(' . $m[1] . ') dài trong code mới — kiểm tra'];
        }
        return ['violations' => $viol];
    }

    /** Co BLOCK nao khong? */
    public static function hasBlock(array $check): bool
    {
        foreach ($check['violations'] ?? [] as $v) {
            if (($v['severity'] ?? '') === 'BLOCK') return true;
        }
        return false;
    }
}
