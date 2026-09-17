<?php
declare(strict_types=1);
/**
 * AccountService - Dieu phoi 1 lan danh gia: ensure state -> collect signals
 * -> engine -> save + history + log. Dung chung cho API, monitor worker.
 * Loi 1 profile khong lan sang profile khac (tra ve result tung cai).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AccountRepository.php';
require_once __DIR__ . '/AccountDataCollector.php';
require_once __DIR__ . '/AccountEvaluation.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/SyncLogger.php';

class AccountService
{
    /**
     * @return array{profileId:int,status:ok|failed|skipped,stage?:string,stability?:int,
     *               confidence?:int,reasons?:array,warnings?:array,message?:string}
     */
    public static function evaluateProfile(int $profileId): array
    {
        $st = AccountRepository::ensure($profileId);
        if (!$st) return ['profileId' => $profileId, 'status' => 'failed', 'message' => 'Khong co state'];
        $policy = SyncSettingsService::getAccountPolicy();
        $col = AccountDataCollector::collect($profileId);
        if ($col['status'] === 'skipped') {
            return ['profileId' => $profileId, 'status' => 'skipped',
                    'message' => $col['error'] ?? 'skipped', 'stage' => (string)$st['stage']];
        }
        $outcome = $col['status'] === 'success' ? 'success' : 'failed';
        $daysObs = max(0.0, (time() - strtotime((string)$st['imported_at'])) / 86400);
        $hss = $st['last_success_at'] !== null && $outcome === 'success'
            ? 0.0
            : ($st['last_success_at'] !== null
                ? max(0.0, (time() - strtotime((string)$st['last_success_at'])) / 3600) : null);
        // counters SAU check nay (engine danh gia trang thai moi)
        $counters = [
            'success' => (int)$st['success_count'] + ($outcome === 'success' ? 1 : 0),
            'fail' => (int)$st['fail_count'] + ($outcome === 'failed' ? 1 : 0),
            'consecFails' => $outcome === 'success' ? 0 : (int)$st['consec_fails'] + 1,
            'daysObserved' => $daysObs,
            'hoursSinceSuccess' => $outcome === 'success' ? 0.0 : $hss,
        ];
        $eval = AccountEvaluationEngine::evaluate($col['signals'], $counters, $policy);
        $row = AccountRepository::saveResult($profileId, $outcome, $col['signals'], $eval);
        if ($outcome === 'success') {
            SyncLogger::info('acc_check', "[Acc] #$profileId {$eval['stage']} stab={$eval['stability']} conf={$eval['confidence']}", $profileId);
        } else {
            SyncLogger::warn('acc_check', "[Acc] #$profileId check failed (" . ($col['error'] ?? '') . ")", $profileId);
        }
        log_action($profileId, 'acc_evaluate', $eval['stage'] . " s={$eval['stability']} c={$eval['confidence']}");
        return ['profileId' => $profileId, 'status' => $outcome, 'stage' => $eval['stage'],
                'stability' => $eval['stability'], 'confidence' => $eval['confidence'],
                'reasons' => $eval['reasons'], 'warnings' => $eval['warnings'],
                'managedDays' => $row ? (int)($row['managed_days'] ?? 0) : 0]
            + ($outcome === 'failed' && isset($col['error']) ? ['message' => $col['error']] : []);
    }

    /**
     * Danh gia nhieu profile tuan tu (1 loi khong fail batch). Gioi han batch
     * de HTTP khong treo: tra ve processed + remaining de UI goi tiep (khong freeze).
     * @return array{results:array,processed:int,remaining:int}
     */
    public static function evaluateBatch(array $profileIds, int $maxBatch = 10): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        $maxBatch = max(1, min(100, $maxBatch));
        $todo = array_slice($ids, 0, $maxBatch);
        $results = [];
        foreach ($todo as $pid) {
            try {
                $results[] = self::evaluateProfile($pid);
            } catch (Throwable $e) {
                $results[] = ['profileId' => $pid, 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        return ['results' => $results, 'processed' => count($todo), 'remaining' => max(0, count($ids) - count($todo))];
    }
}
