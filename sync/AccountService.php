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
require_once __DIR__ . '/ChannelEvaluationManager.php';

class AccountService
{
    /**
     * Legacy wrapper (monitor worker + API cu): di qua staged pipeline de
     * TIMEOUT/CDP loi khong bao gio thanh UNAVAILABLE. Tra ve shape cu.
     * @return array{profileId:int,status:ok|failed|skipped,stage?:string,stability?:int,
     *               confidence?:int,reasons?:array,warnings?:array,message?:string}
     */
    public static function evaluateProfile(int $profileId): array
    {
        try {
            $r = ChannelEvaluationManager::evaluate_one($profileId);
        } catch (Throwable $e) {
            return ['profileId' => $profileId, 'status' => 'failed',
                    'message' => mb_substr($e->getMessage(), 0, 200)];
        }
        if (!empty($r['already_running'])) {
            return ['profileId' => $profileId, 'status' => 'skipped', 'message' => 'ALREADY_RUNNING'];
        }
        if (($r['status'] ?? '') === 'CANCELLED') {
            return ['profileId' => $profileId, 'status' => 'skipped', 'message' => 'cancelled'];
        }
        if (($r['attempt_status'] ?? '') !== 'SUCCESS') {
            // Precondition/tool loi: skipped (khong phai channel hong)
            return ['profileId' => $profileId, 'status' => 'skipped',
                    'message' => ($r['error_code'] ?? '') . ': ' . ($r['reason'] ?? ''),
                    'stage' => (string)(AccountRepository::load($profileId)['stage'] ?? 'NEW')];
        }
        $st = AccountRepository::load($profileId);
        return ['profileId' => $profileId, 'status' => 'ok', 'stage' => (string)($r['stage'] ?? $st['stage'] ?? 'NEW'),
                'stability' => (int)($r['stability'] ?? 0), 'confidence' => (int)($r['confidence'] ?? 0),
                'reasons' => [], 'warnings' => [],
                'managedDays' => $st ? (int)(floor((time() - strtotime((string)$st['imported_at'])) / 86400)) : 0];
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
