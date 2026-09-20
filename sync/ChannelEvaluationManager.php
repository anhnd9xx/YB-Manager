<?php
declare(strict_types=1);
/**
 * ChannelEvaluationManager - service batch danh gia kenh (spec "nang cap danh gia").
 *
 * Tach runtime Chrome (profiles.status) khoi evaluation_status:
 *   UNCHECKED|CHECKING|ACTIVE|LOGIN_REQUIRED|VERIFICATION_REQUIRED|UNAVAILABLE|ERROR
 * CHECKING la runtime (khong persist sau restart). Loi tool (CDP/timeout) KHONG
 * ghi de last_known_status: giu ket qua tot cuoi + last_error rieng.
 *
 * Flow: SELECT -> CREATE BATCH (mark CHECKING 1 UPDATE) -> CHUNK (<=4) ->
 *   RESULT PER PROFILE -> PERSIST -> UPDATE EXACT CARD (UI) -> HISTORY.
 * PHP sync/request: concurrency = chunk size (mac dinh 4), UI goi chunk tiep
 * ngay khi chunk truoc xong. Khong thread/profile.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AccountRepository.php';
require_once __DIR__ . '/AccountDataCollector.php';
require_once __DIR__ . '/AccountEvaluation.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/SyncLogger.php';

class ChannelEvaluationManager
{
    public const UNCHECKED = 'UNCHECKED';
    public const CHECKING = 'CHECKING';
    public const ACTIVE = 'ACTIVE';
    public const LOGIN_REQUIRED = 'LOGIN_REQUIRED';
    public const VERIFICATION_REQUIRED = 'VERIFICATION_REQUIRED';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const ERROR = 'ERROR';

    public const CONCURRENCY_DEFAULT = 4;
    /** Watchdog: CHECKING qua 60s -> ERROR Evaluation timeout. */
    public const WATCHDOG_SEC = 60;

    public const VN = [
        'UNCHECKED' => 'Chưa kiểm tra',
        'CHECKING' => 'Đang kiểm tra',
        'ACTIVE' => 'Hoạt động',
        'LOGIN_REQUIRED' => 'Cần đăng nhập',
        'VERIFICATION_REQUIRED' => 'Cần xác minh',
        'UNAVAILABLE' => 'Không truy cập được',
        'ERROR' => 'Lỗi kiểm tra',
    ];

    public static function hasEvalCols(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $r = db()->query("SHOW COLUMNS FROM account_states LIKE 'eval_status'")->fetch();
            $has = (bool)$r;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    private static function cancelFile(int $profileId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_evalcancel_' . $profileId . '.json';
    }

    public static function cancel_evaluation(int $profileId): void
    {
        @file_put_contents(self::cancelFile($profileId), json_encode(['cancel' => true, 'ts' => time()]));
    }

    private static function isCancelled(int $profileId): bool
    {
        $f = self::cancelFile($profileId);
        if (!is_file($f)) return false;
        if (time() - (int)@filemtime($f) > 300) {
            @unlink($f);
            return false;
        }
        return true;
    }

    private static function clearCancel(int $profileId): void
    {
        @unlink(self::cancelFile($profileId));
    }

    /** Stage hien tai cho panel live (file temp, UI poll). */
    private static function stageFile(int $profileId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_evalstage_' . $profileId . '.json';
    }

    private static function setStage(int $profileId, string $stage): void
    {
        @file_put_contents(self::stageFile($profileId), json_encode(['stage' => $stage, 'ts' => microtime(true)]));
    }

    public static function getStage(int $profileId): ?string
    {
        $f = self::stageFile($profileId);
        if (!is_file($f)) return null;
        if (microtime(true) - (float)((json_decode((string)@file_get_contents($f), true)['ts'] ?? 0)) > 120) return null;
        return (string)(json_decode((string)@file_get_contents($f), true)['stage'] ?? '');
    }

    /**
     * Map signals that -> evaluation status. CHI ACTIVE khi du dieu kien;
     * phan biet loi account vs loi tool (tool loi -> ERROR, giu last_known).
     * @return array{status,reason,loginUi,sessionUi,youtubeUi,channelUi}
     */
    public static function mapSignals(array $signals, ?string $toolError): array
    {
        if ($toolError !== null) {
            return ['status' => self::ERROR, 'reason' => $toolError,
                'loginUi' => 'CHECK_FAILED', 'sessionUi' => 'CHECK_FAILED',
                'youtubeUi' => 'CHECK_FAILED', 'channelUi' => 'NOT_CHECKED'];
        }
        $login = (string)($signals['login'] ?? 'unknown');
        $session = (string)($signals['session'] ?? 'unknown');
        $yt = (string)($signals['youtube'] ?? 'unknown');
        $ch = (string)($signals['channel'] ?? 'unknown');
        $challenge = !empty($signals['challenge']);
        $recovery = !empty($signals['recovery']);
        $yn = fn(bool $b, string $y, string $n) => $b ? $y : $n;
        if ($recovery) {
            return ['status' => self::VERIFICATION_REQUIRED, 'reason' => 'recovery_required',
                'loginUi' => $yn($login === 'ok', 'YES', 'NO'), 'sessionUi' => $yn($session === 'ok', 'VALID', 'INVALID'),
                'youtubeUi' => $yn($yt === 'ok', 'AVAILABLE', 'UNAVAILABLE'), 'channelUi' => 'NOT_CHECKED'];
        }
        if ($challenge) {
            return ['status' => self::VERIFICATION_REQUIRED, 'reason' => 'security_challenge',
                'loginUi' => $yn($login === 'ok', 'YES', 'NO'), 'sessionUi' => $yn($session === 'ok', 'VALID', 'INVALID'),
                'youtubeUi' => $yn($yt === 'ok', 'AVAILABLE', 'UNAVAILABLE'), 'channelUi' => 'NOT_CHECKED'];
        }
        if ($login === 'failed' || $session === 'failed') {
            return ['status' => self::LOGIN_REQUIRED, 'reason' => 'login_or_session_unusable',
                'loginUi' => 'NO', 'sessionUi' => 'INVALID',
                'youtubeUi' => $yn($yt === 'ok', 'AVAILABLE', 'UNAVAILABLE'),
                'channelUi' => $ch === 'exists' ? 'AVAILABLE' : ($ch === 'none' ? 'NO' : 'NOT_CHECKED')];
        }
        if ($yt === 'failed') {
            return ['status' => self::UNAVAILABLE, 'reason' => 'youtube_unreachable',
                'loginUi' => $yn($login === 'ok', 'YES', 'NO'), 'sessionUi' => $yn($session === 'ok', 'VALID', 'INVALID'),
                'youtubeUi' => 'UNAVAILABLE', 'channelUi' => 'NOT_CHECKED'];
        }
        if ($login === 'ok' && $session === 'ok' && $yt === 'ok') {
            return ['status' => self::ACTIVE, 'reason' => null,
                'loginUi' => 'YES', 'sessionUi' => 'VALID', 'youtubeUi' => 'AVAILABLE',
                'channelUi' => $ch === 'exists' ? 'AVAILABLE' : ($ch === 'none' ? 'NO' : 'AVAILABLE')];
        }
        return ['status' => self::UNAVAILABLE, 'reason' => 'signals_incomplete',
            'loginUi' => $login === 'ok' ? 'YES' : 'CHECK_FAILED',
            'sessionUi' => $session === 'ok' ? 'VALID' : 'CHECK_FAILED',
            'youtubeUi' => $yt === 'ok' ? 'AVAILABLE' : 'CHECK_FAILED',
            'channelUi' => 'NOT_CHECKED'];
    }

    /**
     * Danh gia 1 profile: stage co timeout, watchdog 60s, khong treo CHECKING.
     * Tra ve EvaluationResult day du (khong random, dua tren signal that).
     */
    public static function evaluate_one(int $profileId): array
    {
        $t0 = microtime(true);
        $prev = AccountRepository::ensure($profileId);
        $prevStatus = ($prev && self::hasEvalCols()) ? (string)($prev['eval_status'] ?? self::UNCHECKED) : self::UNCHECKED;
        if ($prevStatus === self::CHECKING) $prevStatus = self::UNCHECKED;
        $lastKnown = ($prev && self::hasEvalCols() && !empty($prev['last_known_status']))
            ? (string)$prev['last_known_status'] : ($prevStatus !== self::CHECKING ? $prevStatus : null);

        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        // Mark CHECKING ngay (UI da optimistic, day la persist cho poll khac)
        if (self::hasEvalCols()) {
            try {
                db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=NOW(), eval_stage=? WHERE profile_id=?')
                    ->execute([self::CHECKING, 'INITIALIZING', $profileId]);
            } catch (Throwable $e) {
            }
        }
        self::setStage($profileId, 'INITIALIZING');

        // Stage 1: Chrome chay? (khong phai loi account)
        self::setStage($profileId, 'CHECKING_SESSION');
        $prof = null;
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $prof = $st->fetch() ?: null;
        } catch (Throwable $e) {
        }
        if (!$prof) {
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0, 'profile_not_found');
        }
        $port = (int)($prof['debug_port'] ?? 0);
        if (($prof['status'] ?? '') !== 'running' || $port <= 0 || !cdp_reachable($port)) {
            // Chrome dung: account UNAVAILABLE (khong phai tool ERROR), giu last_known
            return self::finishAccount($profileId, $prev, $prevStatus, $lastKnown, $t0,
                self::UNAVAILABLE, 'chrome_not_running',
                ['login' => 'unknown', 'session' => 'unknown', 'youtube' => 'failed',
                 'channel' => 'unknown', 'channelName' => null, 'challenge' => false, 'recovery' => false]);
        }
        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        // Stage 2-4: collect (co deadline tong 45s; qua -> ERROR timeout, giu last_known)
        foreach (['CHECKING_LOGIN', 'CHECKING_PLATFORM', 'CHECKING_CHANNEL'] as $s) self::setStage($profileId, $s);
        self::setStage($profileId, 'CHECKING_PLATFORM');
        $col = AccountDataCollector::collect($profileId, 40);
        $elapsed = microtime(true) - $t0;
        if ($elapsed > self::WATCHDOG_SEC) {
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0, 'Evaluation timeout');
        }
        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        self::setStage($profileId, 'FINALIZING');
        if (($col['status'] ?? '') === 'skipped') {
            $err = (string)($col['error'] ?? 'skipped');
            if (str_contains($err, 'not running')) {
                return self::finishAccount($profileId, $prev, $prevStatus, $lastKnown, $t0,
                    self::UNAVAILABLE, 'chrome_not_running', $col['signals'] ?? []);
            }
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0, $err);
        }
        $toolErr = ($col['status'] ?? '') === 'success' ? null : (string)($col['error'] ?? 'collect_failed');
        $mapped = self::mapSignals((array)($col['signals'] ?? []), $toolErr);
        if ($mapped['status'] === self::ERROR) {
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0, (string)$mapped['reason']);
        }
        $res = self::finishAccount($profileId, $prev, $prevStatus, $lastKnown, $t0,
            (string)$mapped['status'], $mapped['reason'], (array)($col['signals'] ?? []));
        $res['loginUi'] = $mapped['loginUi'];
        $res['sessionUi'] = $mapped['sessionUi'];
        $res['youtubeUi'] = $mapped['youtubeUi'];
        $res['channelUi'] = $mapped['channelUi'];
        self::setStage($profileId, 'DONE');
        return $res;
    }

    /** Ket qua account that (ghi de eval_status + last_known neu thanh cong that). */
    private static function finishAccount(int $profileId, ?array $prev, string $prevStatus, ?string $lastKnown,
        float $t0, string $status, ?string $reason, array $signals): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $now = date('Y-m-d H:i:s');
        $isSuccessCheck = in_array($status, [self::ACTIVE, self::LOGIN_REQUIRED, self::VERIFICATION_REQUIRED, self::UNAVAILABLE], true);
        // Cap nhat scores cu (engine) NHUNG khong hard-code ACTIVE=100: dung engine that
        $stability = (int)($prev['stability'] ?? 0);
        $confidence = (int)($prev['confidence'] ?? 0);
        $stage = (string)($prev['stage'] ?? 'NEW');
        try {
            $policy = SyncSettingsService::getAccountPolicy();
            $st = AccountRepository::ensure($profileId);
            if ($st) {
                $outcome = $status === self::ACTIVE ? 'success' : 'failed';
                $daysObs = max(0.0, (time() - strtotime((string)$st['imported_at'])) / 86400);
                $counters = [
                    'success' => (int)$st['success_count'] + ($outcome === 'success' ? 1 : 0),
                    'fail' => (int)$st['fail_count'] + ($outcome === 'failed' ? 1 : 0),
                    'consecFails' => $outcome === 'success' ? 0 : (int)$st['consec_fails'] + 1,
                    'daysObserved' => $daysObs,
                    'hoursSinceSuccess' => $outcome === 'success' ? 0.0 : null,
                ];
                $eval = AccountEvaluationEngine::evaluate($signals + ['channel' => 'unknown'], $counters, $policy);
                $stability = (int)$eval['stability'];
                $confidence = (int)$eval['confidence'];
                $stage = (string)$eval['stage'];
                AccountRepository::saveResult($profileId, $outcome, $signals, $eval);
            }
        } catch (Throwable $e) {
        }
        if (self::hasEvalCols()) {
            try {
                db()->prepare('UPDATE account_states SET eval_status=?, last_known_status=?, last_successful_check_at=IF(? IN (\'ACTIVE\',\'LOGIN_REQUIRED\',\'VERIFICATION_REQUIRED\',\'UNAVAILABLE\'),?,last_successful_check_at), last_attempt_at=?, last_error=NULL, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                    ->execute([$status, $isSuccessCheck ? $status : $lastKnown, $status, $now, $now, $ms, 'DONE', $profileId]);
                db()->prepare('INSERT INTO account_history (profile_id, checked_at, stability, confidence, stage, reasons, warnings, eval_status, prev_status, duration_ms, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$profileId, $now, $stability, $confidence, $stage, json_encode([]), json_encode([]), $status, $prevStatus !== self::CHECKING ? $prevStatus : null, $ms, $reason]);
                // Giu <=100 records/profile
                db()->prepare('DELETE FROM account_history WHERE profile_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM account_history WHERE profile_id=? ORDER BY id DESC LIMIT 100) t)')
                    ->execute([$profileId, $profileId]);
            } catch (Throwable $e) {
            }
        }
        try {
            SyncLogger::info('evaluation', "[EVALUATION RESULT] profile=$profileId status=$status duration={$ms}ms" . ($reason ? " reason=$reason" : ''), $profileId);
            log_action($profileId, 'eval_result', "$status" . ($reason ? " ($reason)" : ''));
        } catch (Throwable $e) {
        }
        // Monitoring hooks: alert + state history (khong lam hong danh gia chinh)
        try {
            require_once __DIR__ . '/AlertManager.php';
            require_once __DIR__ . '/StateHistory.php';
            require_once __DIR__ . '/MonitoringService.php';
            $prefs = MonitoringService::getSettings($profileId);
            AlertManager::syncFromEval($profileId, $status, $reason, $prefs);
            $from = ($prevStatus !== self::CHECKING) ? $prevStatus : ($lastKnown ?? self::UNCHECKED);
            StateHistory::record($profileId, StateHistory::CAT_EVAL, $from, $status, $reason);
        } catch (Throwable $e) {
        }
        self::setStage($profileId, 'DONE');
        $row = AccountRepository::load($profileId);
        return ['profileId' => $profileId, 'status' => $status, 'checked_at' => $now,
            'login_state' => $signals['login'] ?? 'unknown', 'session_state' => $signals['session'] ?? 'unknown',
            'youtube_state' => $signals['youtube'] ?? 'unknown',
            'security_challenge' => !empty($signals['challenge']), 'channel_state' => $signals['channel'] ?? 'unknown',
            'success_count' => (int)($row['success_count'] ?? 0), 'fail_count' => (int)($row['fail_count'] ?? 0),
            'consecutive_errors' => (int)($row['consec_fails'] ?? 0),
            'reason' => $reason, 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $isSuccessCheck ? $status : $lastKnown,
            'stability' => $stability, 'confidence' => $confidence, 'stage' => $stage,
            'tool_error' => false];
    }

    /** Loi tool: eval_status=ERROR nhung GIU last_known + scores cu. */
    private static function finishToolError(int $profileId, string $prevStatus, ?string $lastKnown, float $t0, string $err): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $now = date('Y-m-d H:i:s');
        if (self::hasEvalCols()) {
            try {
                db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=?, last_error=?, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                    ->execute([self::ERROR, $now, mb_substr($err, 0, 500), $ms, 'DONE', $profileId]);
                $st = AccountRepository::load($profileId);
                db()->prepare('INSERT INTO account_history (profile_id, checked_at, stability, confidence, stage, reasons, warnings, eval_status, prev_status, duration_ms, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$profileId, $now, (int)($st['stability'] ?? 0), (int)($st['confidence'] ?? 0),
                        (string)($st['stage'] ?? 'NEW'), json_encode([]), json_encode([]), self::ERROR,
                        $prevStatus !== self::CHECKING ? $prevStatus : null, $ms, mb_substr($err, 0, 200)]);
            } catch (Throwable $e) {
            }
        }
        try {
            SyncLogger::error('evaluation', "[EVALUATION ERROR] profile=$profileId reason=$err", $profileId);
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/AlertManager.php';
            require_once __DIR__ . '/MonitoringService.php';
            $prefs = MonitoringService::getSettings($profileId);
            AlertManager::syncFromEval($profileId, self::ERROR, $err, $prefs);
        } catch (Throwable $e) {
        }
        self::setStage($profileId, 'DONE');
        return ['profileId' => $profileId, 'status' => self::ERROR, 'checked_at' => $now,
            'reason' => $err, 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $lastKnown, 'tool_error' => true,
            'loginUi' => 'CHECK_FAILED', 'sessionUi' => 'CHECK_FAILED',
            'youtubeUi' => 'CHECK_FAILED', 'channelUi' => 'NOT_CHECKED'];
    }

    private static function finishCancelled(int $profileId, string $prevStatus, ?string $lastKnown, float $t0): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (self::hasEvalCols()) {
            try {
                // Tra ve trang thai cu (khong de CHECKING treo)
                $back = $lastKnown ?? ($prevStatus !== self::CHECKING ? $prevStatus : self::UNCHECKED);
                db()->prepare('UPDATE account_states SET eval_status=?, eval_stage=? WHERE profile_id=?')
                    ->execute([$back, 'DONE', $profileId]);
            } catch (Throwable $e) {
            }
        }
        self::setStage($profileId, 'DONE');
        return ['profileId' => $profileId, 'status' => 'CANCELLED', 'checked_at' => date('Y-m-d H:i:s'),
            'reason' => 'cancelled', 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $lastKnown, 'tool_error' => false];
    }

    // ================= Batch =================

    public static function evaluate_many(array $profileIds, int $concurrency = self::CONCURRENCY_DEFAULT): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        if (!$ids) return ['ok' => false, 'message' => 'Chua chon kenh nao'];
        $concurrency = max(2, min(8, $concurrency));
        $batchId = 'ev_' . date('His') . '_' . substr(md5(json_encode($ids) . microtime(true)), 0, 6);
        // Mark CHECKING 1 UPDATE duy nhat (UI da optimistic; day persist cho poll)
        if (self::hasEvalCols()) {
            try {
                AccountRepository::ensureAll();
                $in = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("UPDATE account_states SET eval_status='CHECKING', last_attempt_at=NOW(), eval_stage='QUEUED' WHERE profile_id IN ($in)")
                    ->execute($ids);
            } catch (Throwable $e) {
            }
        }
        try {
            db()->prepare('INSERT INTO eval_batches (batch_id, ids, total, pending, running, completed, failed, cancelled, status) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$batchId, json_encode($ids), count($ids), count($ids), 0, 0, 0, 0, 'running']);
        } catch (Throwable $e) {
            // Bang chua migrate: van tra batch chay dang in-memory (chunk truc tiep)
        }
        try {
            SyncLogger::info('evaluation', "[EVALUATION] batch=$batchId profiles=" . count($ids));
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'batch_id' => $batchId, 'total' => count($ids), 'concurrency' => $concurrency];
    }

    /** Xu ly chunk tiep theo (<= $limit, mac dinh 4). Moi profile xong doc lap. */
    public static function evaluate_chunk(string $batchId, int $limit = self::CONCURRENCY_DEFAULT): array
    {
        $limit = max(1, min(8, $limit));
        $batch = self::loadBatch($batchId);
        if (!$batch) return ['ok' => false, 'message' => 'Batch khong ton tai'];
        if (($batch['status'] ?? '') !== 'running') {
            return ['ok' => true, 'batch' => $batch, 'results' => [], 'done' => true];
        }
        $ids = json_decode((string)($batch['ids'] ?? '[]'), true);
        if (!is_array($ids)) $ids = [];
        // Tim pending: nhung id chua co ket qua trong batch progress
        $doneIds = json_decode((string)($batch['done_ids'] ?? '[]'), true);
        if (!is_array($doneIds)) $doneIds = [];
        $doneSet = array_flip(array_map('intval', $doneIds));
        $todo = [];
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && !isset($doneSet[$pid])) $todo[] = $pid;
            if (count($todo) >= $limit) break;
        }
        // Cancel? -> pending con lai -> cancelled
        if (self::batchCancelled($batchId)) {
            foreach ($todo as $pid) self::cancel_evaluation($pid);
            self::saveBatch($batchId, ['status' => 'cancelled',
                'cancelled' => (int)($batch['cancelled'] ?? 0) + count($todo),
                'pending' => 0, 'running' => 0]);
            // Tra cac CHECKING con lai ve last_known
            self::revertChecking($ids, $doneIds);
            return ['ok' => true, 'batch' => self::loadBatch($batchId), 'results' => [], 'done' => true, 'cancelled' => true];
        }
        $results = [];
        foreach ($todo as $pid) {
            try {
                $r = self::evaluate_one($pid);
            } catch (Throwable $e) {
                $r = ['profileId' => $pid, 'status' => self::ERROR, 'reason' => mb_substr($e->getMessage(), 0, 200), 'tool_error' => true];
            }
            $results[] = $r;
            $doneIds[] = $pid;
        }
        $completed = (int)($batch['completed'] ?? 0);
        $failed = (int)($batch['failed'] ?? 0);
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'CANCELLED') continue;
            if (($r['status'] ?? '') === self::ERROR && !empty($r['tool_error'])) $failed++;
            else $completed++;
        }
        $pending = max(0, (int)($batch['total'] ?? count($ids)) - count($doneIds));
        $patch = ['completed' => $completed, 'failed' => $failed, 'pending' => $pending,
                  'running' => 0, 'done_ids' => json_encode(array_values($doneIds))];
        if ($pending <= 0) $patch['status'] = 'done';
        self::saveBatch($batchId, $patch);
        // Watchdog: CHECKING qua 60s (treo tu batch cu) -> ERROR
        self::watchdog();
        return ['ok' => true, 'batch' => self::loadBatch($batchId), 'results' => $results, 'done' => $pending <= 0];
    }

    public static function batch_state(string $batchId): ?array
    {
        return self::loadBatch($batchId);
    }

    public static function cancel_batch(string $batchId): bool
    {
        try {
            db()->prepare("UPDATE eval_batches SET status='cancelling' WHERE batch_id=? AND status='running'")->execute([$batchId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function batchCancelled(string $batchId): bool
    {
        try {
            $st = db()->prepare('SELECT status FROM eval_batches WHERE batch_id=?');
            $st->execute([$batchId]);
            return ($st->fetchColumn() ?: '') === 'cancelling';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function loadBatch(string $batchId): ?array
    {
        try {
            $st = db()->prepare('SELECT * FROM eval_batches WHERE batch_id=?');
            $st->execute([$batchId]);
            $r = $st->fetch();
            if (!$r) return null;
            // done_ids khong phai cot DB -> lay tu tien do completed (ghep tu history gan nhat? don gian: tru pending)
            // De chinh xac, luu done_ids vao cot ids? -> tach: giu nguyen ids, them file tien do.
            $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_evalbatch_' . $batchId . '.json';
            if (is_file($f)) {
                $j = json_decode((string)@file_get_contents($f), true);
                if (is_array($j) && isset($j['done_ids'])) $r['done_ids'] = json_encode($j['done_ids']);
            }
            return $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function saveBatch(string $batchId, array $patch): void
    {
        try {
            $sets = [];
            $params = [];
            foreach (['completed' => 'completed', 'failed' => 'failed', 'pending' => 'pending',
                      'running' => 'running', 'cancelled' => 'cancelled', 'status' => 'status'] as $k => $col) {
                if (array_key_exists($k, $patch)) {
                    $sets[] = "$col=?";
                    $params[] = $patch[$k];
                }
            }
            if ($sets) {
                $params[] = $batchId;
                db()->prepare('UPDATE eval_batches SET ' . implode(',', $sets) . ' WHERE batch_id=?')->execute($params);
            }
            if (array_key_exists('done_ids', $patch)) {
                @file_put_contents(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_evalbatch_' . $batchId . '.json',
                    json_encode(['done_ids' => json_decode((string)$patch['done_ids'], true) ?: []]));
            }
        } catch (Throwable $e) {
        }
    }

    /** Watchdog: CHECKING qua 60s -> ERROR Evaluation timeout (khong treo vinh vien). */
    public static function watchdog(): int
    {
        if (!self::hasEvalCols()) return 0;
        try {
            $st = db()->prepare("UPDATE account_states SET eval_status='ERROR', last_error='Evaluation timeout', eval_stage='DONE' WHERE eval_status='CHECKING' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $st->execute([self::WATCHDOG_SEC]);
            return (int)$st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function revertChecking(array $ids, array $doneIds): void
    {
        if (!self::hasEvalCols()) return;
        $doneSet = array_flip(array_map('intval', (array)$doneIds));
        $rest = [];
        foreach ($ids as $pid) {
            if (!isset($doneSet[(int)$pid])) $rest[] = (int)$pid;
        }
        if (!$rest) return;
        try {
            $in = implode(',', array_fill(0, count($rest), '?'));
            db()->prepare("UPDATE account_states SET eval_status=COALESCE(last_known_status,'UNCHECKED'), eval_stage='DONE' WHERE profile_id IN ($in) AND eval_status='CHECKING'")
                ->execute($rest);
        } catch (Throwable $e) {
        }
    }

    public static function get_evaluation(int $profileId): ?array
    {
        $row = AccountRepository::ensure($profileId);
        if (!$row) return null;
        if (self::hasEvalCols() && ($row['eval_status'] ?? '') === self::CHECKING) {
            // Watchdog don le: qua 60s -> ERROR ngay khi doc
            try {
                if (!empty($row['last_attempt_at']) && (time() - strtotime((string)$row['last_attempt_at'])) > self::WATCHDOG_SEC) {
                    db()->prepare("UPDATE account_states SET eval_status='ERROR', last_error='Evaluation timeout', eval_stage='DONE' WHERE profile_id=?")
                        ->execute([$profileId]);
                    $row = AccountRepository::load($profileId);
                }
            } catch (Throwable $e) {
            }
        }
        $row['eval_stage_live'] = self::getStage($profileId);
        return $row;
    }

    public static function get_history(int $profileId, int $limit = 100): array
    {
        return AccountRepository::history($profileId, max(1, min(100, $limit)));
    }
}
