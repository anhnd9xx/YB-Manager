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
    public const RESTRICTED = 'RESTRICTED';
    public const ERROR = 'ERROR';

    // Attempt status (ket qua LAN CHECK, khac channel status)
    public const ATT_SUCCESS = 'SUCCESS';
    public const ATT_FAILED = 'FAILED';
    public const ATT_TIMEOUT = 'TIMEOUT';
    public const ATT_CANCELLED = 'CANCELLED';

    public const CONCURRENCY_DEFAULT = 4;
    /** Watchdog: EVALUATION_MAX_DURATION 30s, khong giu CHECKING mai mai. */
    public const WATCHDOG_SEC = 30;

    public const VN = [
        'UNCHECKED' => 'Chưa kiểm tra',
        'CHECKING' => 'Đang kiểm tra',
        'ACTIVE' => 'Hoạt động',
        'LOGIN_REQUIRED' => 'Cần đăng nhập',
        'VERIFICATION_REQUIRED' => 'Cần xác minh',
        'UNAVAILABLE' => 'Không truy cập được',
        'RESTRICTED' => 'Bị hạn chế',
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
     * Danh gia 1 profile: profile lock, run_state rieng, precheck ma loi,
     * autostart tuy chon, retry transient 1 lan, deadline 30s.
     * Tra ve EvaluationResult day du (khong random, dua tren signal that).
     * Neu dang co evaluation khac -> ALREADY_RUNNING (khong duplicate).
     */
    public static function evaluate_one(int $profileId, array $opts = []): array
    {
        $t0 = microtime(true);
        $prev = AccountRepository::ensure($profileId);
        if (!$prev && $profileId > 0) {
            // ensure that bai (DB) -> van tra loi co cau truc
            return self::bareResult($profileId, self::ERROR, 'FAILED', AccountDataCollector::E_INTERNAL,
                'Khong doc duoc state', $t0, self::UNCHECKED, null);
        }
        $prevStatus = self::hasEvalCols() ? (string)($prev['eval_status'] ?? self::UNCHECKED) : self::UNCHECKED;
        $freshChecking = false;
        if ($prevStatus === self::CHECKING && self::hasEvalCols()) {
            // Profile lock: CHECKING tuoi (<30s) = dang co evaluation khac
            try {
                $la = !empty($prev['last_attempt_at']) ? (time() - strtotime((string)$prev['last_attempt_at'])) : 9999;
                if ($la < self::WATCHDOG_SEC) $freshChecking = true;
            } catch (Throwable $e) {
            }
        }
        if ($freshChecking && empty($opts['force'])) {
            try {
                SyncLogger::info('evaluation', "[EVAL ALREADY_RUNNING] profile=$profileId", $profileId);
            } catch (Throwable $e) {
            }
            return ['profileId' => $profileId, 'status' => $prevStatus, 'already_running' => true,
                'checked_at' => date('Y-m-d H:i:s'), 'reason' => 'ALREADY_RUNNING',
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000)];
        }
        if ($prevStatus === self::CHECKING) $prevStatus = self::UNCHECKED;
        $lastKnown = (self::hasEvalCols() && !empty($prev['last_known_status']))
            ? (string)$prev['last_known_status'] : ($prevStatus !== self::CHECKING ? $prevStatus : null);

        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        // Mark CHECKING ngay (UI da optimistic, day la persist + lock cho poll khac)
        if (self::hasEvalCols()) {
            try {
                db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=NOW(), eval_stage=? WHERE profile_id=?')
                    ->execute([self::CHECKING, 'QUEUED', $profileId]);
            } catch (Throwable $e) {
            }
        }
        self::setStage($profileId, 'QUEUED');
        $policy = SyncSettingsService::getAccountPolicy();

        try {
            SyncLogger::info('evaluation', "[EVAL START] profile=$profileId", $profileId);
        } catch (Throwable $e) {
        }
        // PRECHECK (ma loi rieng, khong gom "evaluation failed")
        self::setStage($profileId, 'PRECHECK');
        $t = microtime(true);
        $pre = AccountDataCollector::precheck($profileId);
        $perf = ['precheck' => (int)round((microtime(true) - $t) * 1000)];
        try {
            if (!empty($pre['ok'])) {
                SyncLogger::info('evaluation', "[EVAL PRECHECK] profile=$profileId chrome=RUNNING debug_port=" . ($pre['port'] ?? '?') . ' cdp=OK', $profileId);
            }
        } catch (Throwable $e) {
        }
        $autoStarted = false;
        if (!$pre['ok']) {
            $code = (string)($pre['code'] ?? AccountDataCollector::E_INTERNAL);
            // Chrome dung + autoStart bat -> tu mo (poll ready, khong sleep mu)
            if ($code === AccountDataCollector::E_CHROME_NOT_RUNNING && !empty($policy['autoStart'])) {
                self::setStage($profileId, 'CONNECTING');
                $st = self::autoStartChrome($profileId, $policy);
                $perf['autostart'] = $st['ms'] ?? 0;
                if (!$st['ok']) {
                    return self::finishPrecondition($profileId, $prevStatus, $lastKnown, $t0, $st['code'], $st['message'], $perf);
                }
                $autoStarted = !empty($st['was_stopped']);
                $pre = AccountDataCollector::precheck($profileId);
                if (!$pre['ok']) {
                    return self::finishPrecondition($profileId, $prevStatus, $lastKnown, $t0,
                        (string)($pre['code'] ?? $code), (string)($pre['message'] ?? ''), $perf, $autoStarted);
                }
            } else {
                // REQUIRE_RUNNING (default): dieu kien thieu, KHONG ket luan channel hong,
                // KHONG cham counters/scores
                return self::finishPrecondition($profileId, $prevStatus, $lastKnown, $t0, $code,
                    (string)($pre['message'] ?? ''), $perf);
            }
        }
        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        // CONNECTING + CHECK (deadline tong 30s; retry transient 1 lan, delay 500ms)
        self::setStage($profileId, 'CONNECTING');
        self::setStage($profileId, 'CHECKING_SESSION');
        $col = AccountDataCollector::collect($profileId, 25, ['session' => 5, 'platform' => 8]);
        $code = (string)($col['error_code'] ?? '');
        if (($col['status'] ?? '') !== 'success' && AccountDataCollector::isTransient($code)) {
            usleep(500000); // retry delay 300-800ms theo spec (1 lan)
            if (microtime(true) - $t0 < self::WATCHDOG_SEC) {
                $col = AccountDataCollector::collect($profileId, 25, ['session' => 5, 'platform' => 8]);
            }
        }
        $perf = array_merge($perf, (array)($col['timings'] ?? []));
        if (microtime(true) - $t0 > self::WATCHDOG_SEC) {
            if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0,
                'Evaluation timeout', 'TIMEOUT', $perf);
        }
        if (self::isCancelled($profileId)) {
            self::clearCancel($profileId);
            if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
            return self::finishCancelled($profileId, $prevStatus, $lastKnown, $t0);
        }
        self::setStage($profileId, 'CHECKING_LOGIN');
        self::setStage($profileId, 'CHECKING_PLATFORM');
        self::setStage($profileId, 'CHECKING_CHANNEL');
        self::setStage($profileId, 'FINALIZING');
        if (($col['status'] ?? '') === 'skipped') {
            $code = (string)($col['error_code'] ?? AccountDataCollector::E_INTERNAL);
            if (in_array($code, [AccountDataCollector::E_CHROME_NOT_RUNNING, AccountDataCollector::E_DEBUG_PORT,
                    AccountDataCollector::E_PROFILE_MISMATCH], true)) {
                if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
                return self::finishPrecondition($profileId, $prevStatus, $lastKnown, $t0, $code,
                    (string)($col['error'] ?? ''), $perf, $autoStarted);
            }
            if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0,
                (string)($col['error'] ?? 'collect_failed'), $code, $perf);
        }
        $toolErr = ($col['status'] ?? '') === 'success' ? null : (string)($col['error'] ?? 'collect_failed');
        $toolCode = ($col['status'] ?? '') === 'success' ? null : (string)($col['error_code'] ?? AccountDataCollector::E_INTERNAL);
        $mapped = self::mapSignals((array)($col['signals'] ?? []), $toolErr);
        if ($mapped['status'] === self::ERROR) {
            if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
            return self::finishToolError($profileId, $prevStatus, $lastKnown, $t0,
                (string)$mapped['reason'], $toolCode, $perf);
        }
        $res = self::finishAccount($profileId, $prev, $prevStatus, $lastKnown, $t0,
            (string)$mapped['status'], $mapped['reason'], (array)($col['signals'] ?? []), $perf);
        $res['loginUi'] = $mapped['loginUi'];
        $res['sessionUi'] = $mapped['sessionUi'];
        $res['youtubeUi'] = $mapped['youtubeUi'];
        $res['channelUi'] = $mapped['channelUi'];
        if ($autoStarted && !empty($policy['closeAfter'])) self::closeChromeQuiet($profileId);
        self::setStage($profileId, 'SUCCESS');
        $res['run_state'] = 'SUCCESS';
        return $res;
    }

    /** Tu mo Chrome cho evaluation (poll CDP ready 500ms toi da 10s). */
    private static function autoStartChrome(int $profileId, array $policy): array
    {
        $t0 = microtime(true);
        try {
            $st = db()->prepare(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.password AS proxy_pass
                 FROM profiles p LEFT JOIN proxies pr ON pr.id = p.proxy_id WHERE p.id=?');
            $st->execute([$profileId]);
            $p = $st->fetch();
            if (!$p) return ['ok' => false, 'code' => AccountDataCollector::E_INTERNAL, 'message' => 'profile_not_found', 'ms' => 0];
            if (!file_exists(chrome_path())) {
                return ['ok' => false, 'code' => 'CHROME_START_TIMEOUT', 'message' => 'Khong tim thay Chrome', 'ms' => 0];
            }
            $port = allocate_debug_port($p);
            if (!$port) {
                return ['ok' => false, 'code' => AccountDataCollector::E_DEBUG_PORT, 'message' => 'Khong gan duoc debug port', 'ms' => 0];
            }
            try {
                launch_chrome($p, get_setting('home_url', 'https://www.google.com/'), $port, ['skipSessionInject' => true]);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'code' => 'CHROME_START_TIMEOUT', 'message' => mb_substr($e->getMessage(), 0, 200), 'ms' => 0];
            }
            db()->prepare('UPDATE profiles SET status=?, last_opened=NOW(), debug_port=? WHERE id=?')
                ->execute(['running', $port, $profileId]);
            // Poll CDP ready (khong sleep mu): 500ms toi da 10s
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                if (cdp_reachable($port) && cdp_page_targets($port)) break;
                usleep(500000);
            }
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if (!cdp_reachable($port)) {
                return ['ok' => false, 'code' => 'CHROME_START_TIMEOUT', 'message' => 'Chrome mo nhung CDP khong ready', 'ms' => $ms];
            }
            try {
                SyncLogger::info('evaluation', "[EVAL AUTOSTART] profile=$profileId port=$port ms=$ms", $profileId);
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'ms' => $ms, 'was_stopped' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => AccountDataCollector::E_INTERNAL,
                    'message' => mb_substr($e->getMessage(), 0, 200),
                    'ms' => (int)round((microtime(true) - $t0) * 1000)];
        }
    }

    private static function closeChromeQuiet(int $profileId): void
    {
        try {
            $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $p = $st->fetch();
            if ($p) {
                kill_chrome_processes($p);
                db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $profileId]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Ket qua bare khi khong doc duoc state (cau truc dong nhat). */
    private static function bareResult(int $profileId, string $status, string $attempt, ?string $code,
        string $msg, float $t0, string $prevStatus, ?string $lastKnown): array
    {
        return ['profileId' => $profileId, 'status' => $status, 'attempt_status' => $attempt,
            'error_code' => $code, 'checked_at' => date('Y-m-d H:i:s'),
            'reason' => $msg, 'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'prev_status' => $prevStatus, 'last_known_status' => $lastKnown, 'tool_error' => $attempt !== self::ATT_SUCCESS,
            'loginUi' => 'NOT_CHECKED', 'sessionUi' => 'NOT_CHECKED',
            'youtubeUi' => 'NOT_CHECKED', 'channelUi' => 'NOT_CHECKED', 'run_state' => 'FAILED'];
    }

    /**
     * Dieu kien thieu (CHROME_NOT_RUNNING/DEBUG_PORT/PROFILE_MISMATCH/START_TIMEOUT):
     * attempt FAILED, GIU channel status + last_known, KHONG cham counters/scores.
     */
    private static function finishPrecondition(int $profileId, string $prevStatus, ?string $lastKnown,
        float $t0, string $code, string $message, array $perf = [], bool $autoStarted = false): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $now = date('Y-m-d H:i:s');
        $back = $lastKnown ?? ($prevStatus !== self::CHECKING ? $prevStatus : self::UNCHECKED);
        if (self::hasEvalCols()) {
            try {
                $hasAttempt = (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch();
                if ($hasAttempt) {
                    db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=?, last_attempt_status=?, last_error_code=?, last_error_message=?, last_error=?, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                        ->execute([$back, $now, self::ATT_FAILED, $code, mb_substr($message, 0, 500), mb_substr($message, 0, 500), $ms, 'FAILED', $profileId]);
                } else {
                    db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=?, last_error=?, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                        ->execute([$back, $now, mb_substr($message, 0, 500), $ms, 'FAILED', $profileId]);
                }
            } catch (Throwable $e) {
            }
        }
        try {
            SyncLogger::warn('evaluation', "[EVAL PRECHECK] profile=$profileId code=$code", $profileId);
            log_action($profileId, 'eval_precheck', $code);
        } catch (Throwable $e) {
        }
        self::setStage($profileId, 'FAILED');
        return ['profileId' => $profileId, 'status' => $back, 'attempt_status' => self::ATT_FAILED,
            'error_code' => $code, 'checked_at' => $now,
            'login_state' => 'unknown', 'session_state' => 'unknown', 'youtube_state' => 'unknown',
            'security_challenge' => false, 'channel_state' => 'unknown',
            'reason' => $message ?: $code, 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $lastKnown, 'tool_error' => false, 'precondition' => true,
            'auto_started' => $autoStarted, 'perf' => $perf, 'run_state' => 'FAILED',
            'loginUi' => 'NOT_CHECKED', 'sessionUi' => 'NOT_CHECKED',
            'youtubeUi' => 'NOT_CHECKED', 'channelUi' => 'NOT_CHECKED'];
    }

    /** Ket qua account that (ghi de eval_status + last_known neu thanh cong that). */
    private static function finishAccount(int $profileId, ?array $prev, string $prevStatus, ?string $lastKnown,
        float $t0, string $status, ?string $reason, array $signals, array $perf = []): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $now = date('Y-m-d H:i:s');
        $isSuccessCheck = in_array($status, [self::ACTIVE, self::LOGIN_REQUIRED, self::VERIFICATION_REQUIRED, self::UNAVAILABLE, self::RESTRICTED], true);
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
                $hasAttempt = (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch();
                $attCols = $hasAttempt ? ', last_attempt_status=?, last_error_code=NULL, last_error_message=NULL' : '';
                $attParams = $hasAttempt ? [self::ATT_SUCCESS, null, null] : [];
                db()->prepare('UPDATE account_states SET eval_status=?, last_known_status=?, last_successful_check_at=IF(? IN (\'ACTIVE\',\'LOGIN_REQUIRED\',\'VERIFICATION_REQUIRED\',\'UNAVAILABLE\',\'RESTRICTED\'),?,last_successful_check_at), last_attempt_at=?, last_error=NULL, last_duration_ms=?, eval_stage=?' . $attCols . ' WHERE profile_id=?')
                    ->execute(array_merge([$status, $isSuccessCheck ? $status : $lastKnown, $status, $now, $now, $ms, 'SUCCESS'], $attParams, [$profileId]));
                db()->prepare('INSERT INTO account_history (profile_id, checked_at, stability, confidence, stage, reasons, warnings, eval_status, prev_status, duration_ms, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$profileId, $now, $stability, $confidence, $stage, json_encode([]), json_encode([]), $status, $prevStatus !== self::CHECKING ? $prevStatus : null, $ms, $reason]);
                // Giu <=100 records/profile
                db()->prepare('DELETE FROM account_history WHERE profile_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM account_history WHERE profile_id=? ORDER BY id DESC LIMIT 100) t)')
                    ->execute([$profileId, $profileId]);
            } catch (Throwable $e) {
            }
        }
        try {
            SyncLogger::info('evaluation', "[EVAL RESULT] profile=$profileId channel_status=$status duration={$ms}ms" . ($reason ? " reason=$reason" : ''), $profileId);
            $pp = [];
            foreach (['precheck', 'connect', 'session', 'platform'] as $k) {
                if (isset($perf[$k])) $pp[] = "$k={$perf[$k]}ms";
            }
            if ($pp) SyncLogger::info('evaluation', "[EVAL PERF] profile=$profileId " . implode(' ', $pp) . " total={$ms}ms", $profileId);
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
        self::setStage($profileId, 'SUCCESS');
        $row = AccountRepository::load($profileId);
        return ['profileId' => $profileId, 'status' => $status, 'attempt_status' => self::ATT_SUCCESS,
            'error_code' => null, 'checked_at' => $now,
            'login_state' => $signals['login'] ?? 'unknown', 'session_state' => $signals['session'] ?? 'unknown',
            'youtube_state' => $signals['youtube'] ?? 'unknown',
            'security_challenge' => !empty($signals['challenge']), 'channel_state' => $signals['channel'] ?? 'unknown',
            'success_count' => (int)($row['success_count'] ?? 0), 'fail_count' => (int)($row['fail_count'] ?? 0),
            'consecutive_errors' => (int)($row['consec_fails'] ?? 0),
            'reason' => $reason, 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $isSuccessCheck ? $status : $lastKnown,
            'stability' => $stability, 'confidence' => $confidence, 'stage' => $stage,
            'tool_error' => false, 'perf' => $perf, 'run_state' => 'SUCCESS'];
    }

    /** Loi tool: GIU channel status + last_known + scores cu, chi ghi attempt. */
    private static function finishToolError(int $profileId, string $prevStatus, ?string $lastKnown, float $t0,
        string $err, ?string $code = null, array $perf = []): array
    {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $now = date('Y-m-d H:i:s');
        $code = $code ?? AccountDataCollector::E_INTERNAL;
        $attempt = $code === 'TIMEOUT' ? self::ATT_TIMEOUT : self::ATT_FAILED;
        $back = $lastKnown ?? ($prevStatus !== self::CHECKING ? $prevStatus : self::UNCHECKED);
        if (self::hasEvalCols()) {
            try {
                $hasAttempt = (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch();
                if ($hasAttempt) {
                    db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=?, last_attempt_status=?, last_error_code=?, last_error_message=?, last_error=?, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                        ->execute([$back, $now, $attempt, $code, mb_substr($err, 0, 500), mb_substr($err, 0, 500), $ms, 'FAILED', $profileId]);
                } else {
                    db()->prepare('UPDATE account_states SET eval_status=?, last_attempt_at=?, last_error=?, last_duration_ms=?, eval_stage=? WHERE profile_id=?')
                        ->execute([$back, $now, mb_substr($err, 0, 500), $ms, 'FAILED', $profileId]);
                }
                $st = AccountRepository::load($profileId);
                db()->prepare('INSERT INTO account_history (profile_id, checked_at, stability, confidence, stage, reasons, warnings, eval_status, prev_status, duration_ms, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$profileId, $now, (int)($st['stability'] ?? 0), (int)($st['confidence'] ?? 0),
                        (string)($st['stage'] ?? 'NEW'), json_encode([]), json_encode([]), $back,
                        $prevStatus !== self::CHECKING ? $prevStatus : null, $ms, mb_substr($code . ': ' . $err, 0, 200)]);
            } catch (Throwable $e) {
            }
        }
        try {
            SyncLogger::error('evaluation', "[EVAL FAILED] profile=$profileId error=$code previous_status=" . ($lastKnown ?? $prevStatus), $profileId);
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/AlertManager.php';
            require_once __DIR__ . '/MonitoringService.php';
            $prefs = MonitoringService::getSettings($profileId);
            AlertManager::syncFromEval($profileId, self::ERROR, $code . ': ' . $err, $prefs);
        } catch (Throwable $e) {
        }
        self::setStage($profileId, 'FAILED');
        return ['profileId' => $profileId, 'status' => $back, 'attempt_status' => $attempt,
            'error_code' => $code, 'checked_at' => $now,
            'reason' => $err, 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $lastKnown, 'tool_error' => true,
            'perf' => $perf, 'run_state' => 'FAILED',
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
                $hasAttempt = (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch();
                if ($hasAttempt) {
                    db()->prepare('UPDATE account_states SET eval_status=?, eval_stage=?, last_attempt_status=?, last_attempt_at=? WHERE profile_id=?')
                        ->execute([$back, 'CANCELLED', self::ATT_CANCELLED, date('Y-m-d H:i:s'), $profileId]);
                } else {
                    db()->prepare('UPDATE account_states SET eval_status=?, eval_stage=? WHERE profile_id=?')
                        ->execute([$back, 'CANCELLED', $profileId]);
                }
            } catch (Throwable $e) {
            }
        }
        self::setStage($profileId, 'CANCELLED');
        return ['profileId' => $profileId, 'status' => 'CANCELLED', 'attempt_status' => self::ATT_CANCELLED,
            'error_code' => null, 'checked_at' => date('Y-m-d H:i:s'),
            'reason' => 'cancelled', 'duration_ms' => $ms, 'prev_status' => $prevStatus,
            'last_known_status' => $lastKnown, 'tool_error' => false, 'run_state' => 'CANCELLED'];
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
            SyncLogger::info('evaluation', "[EVAL BATCH] id=$batchId total=" . count($ids) . " concurrency=$concurrency");
            foreach ($ids as $pid) SyncLogger::debug('evaluation', "[EVAL QUEUED] profile=$pid batch=$batchId", $pid);
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
        if (($batch['status'] ?? '') === 'done' || ($batch['status'] ?? '') === 'cancelled') {
            return ['ok' => true, 'batch' => $batch, 'results' => [], 'done' => true];
        }
        // 'cancelling' roi xuong nhanh cancel ben duoi (khong early-return)
        // Concurrency theo policy (2/4/6/8), mac dinh 4
        try {
            $policy = SyncSettingsService::getAccountPolicy();
            $limit = (int)($policy['concurrency'] ?? $limit);
            if (!in_array($limit, [2, 4, 6, 8], true)) $limit = self::CONCURRENCY_DEFAULT;
        } catch (Throwable $e) {
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
        // Cancel? -> TAT CA pending con lai -> cancelled ngay (khong kill running)
        if (self::batchCancelled($batchId)) {
            $remaining = [];
            foreach ($ids as $pid) {
                $pid = (int)$pid;
                if ($pid > 0 && !isset($doneSet[$pid])) $remaining[] = $pid;
            }
            foreach ($remaining as $pid) self::cancel_evaluation($pid);
            self::saveBatch($batchId, ['status' => 'cancelled',
                'cancelled' => (int)($batch['cancelled'] ?? 0) + count($remaining),
                'pending' => 0, 'running' => 0,
                'done_ids' => json_encode(array_values(array_merge($doneIds, $remaining)))]);
            // Tra cac CHECKING con lai ve last_known
            self::revertChecking($ids, $doneIds);
            try {
                SyncLogger::info('evaluation', "[EVAL BATCH] id=$batchId cancelled pending=" . count($remaining));
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'batch' => self::loadBatch($batchId), 'results' => [], 'done' => true, 'cancelled' => true];
        }
        $results = [];
        foreach ($todo as $pid) {
            // Chunk xu ly tuan tu trong 1 request = chu so huu lock -> force bypass
            // ALREADY_RUNNING (lock chi chan request ngoai: double-click, tab khac)
            if (self::batchCancelled($batchId)) {
                // Huy giua chunk: cancel nhe, running hien tai xong stage an toan
                self::cancel_evaluation($pid);
                $r = self::evaluate_one($pid); // se tra CANCELLED nhanh
            } else {
                try {
                    $r = self::evaluate_one($pid, ['force' => true]);
                } catch (Throwable $e) {
                    $r = self::bareResult($pid, self::UNCHECKED, self::ATT_FAILED,
                        AccountDataCollector::E_INTERNAL, mb_substr($e->getMessage(), 0, 200),
                        microtime(true), self::UNCHECKED, null);
                }
            }
            $results[] = $r;
            $doneIds[] = $pid;
        }
        $completed = (int)($batch['completed'] ?? 0);
        $failed = (int)($batch['failed'] ?? 0);
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'CANCELLED' || !empty($r['already_running'])) continue;
            if (($r['attempt_status'] ?? '') === self::ATT_SUCCESS) $completed++;
            else $failed++;
        }
        $pending = max(0, (int)($batch['total'] ?? count($ids)) - count($doneIds));
        $patch = ['completed' => $completed, 'failed' => $failed, 'pending' => $pending,
                  'running' => 0, 'done_ids' => json_encode(array_values($doneIds))];
        if ($pending <= 0) {
            $patch['status'] = 'done';
            try {
                SyncLogger::info('evaluation', "[EVAL BATCH] id=$batchId done total=" . count($ids) . " ok=$completed fail=$failed");
            } catch (Throwable $e) {
            }
        }
        self::saveBatch($batchId, $patch);
        // Watchdog: CHECKING qua 30s (treo tu batch cu/restart) -> revert last_known
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

    /**
     * Watchdog: CHECKING qua 30s (stuck/restart) -> revert last_known/UNCHECKED
     * + attempt TIMEOUT. KHONG persist CHECKING/QUEUED sau restart.
     */
    public static function watchdog(): int
    {
        if (!self::hasEvalCols()) return 0;
        try {
            $hasAttempt = (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch();
            if ($hasAttempt) {
                $st = db()->prepare("UPDATE account_states SET eval_status=COALESCE(last_known_status,'UNCHECKED'), last_attempt_status=?, last_error_code='TIMEOUT', last_error_message='Evaluation timeout', last_error='Evaluation timeout', eval_stage='FAILED' WHERE eval_status='CHECKING' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
                $st->execute([self::ATT_TIMEOUT, self::WATCHDOG_SEC]);
            } else {
                $st = db()->prepare("UPDATE account_states SET eval_status=COALESCE(last_known_status,'UNCHECKED'), last_error='Evaluation timeout', eval_stage='FAILED' WHERE eval_status='CHECKING' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
                $st->execute([self::WATCHDOG_SEC]);
            }
            $n = (int)$st->rowCount();
            if ($n > 0) {
                try {
                    SyncLogger::warn('evaluation', "[EVAL WATCHDOG] reverted $n stuck CHECKING");
                } catch (Throwable $e) {
                }
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Priority: dua profile QUEUED trong batch len dau queue (khong duplicate).
     * Tra ve true neu tim thay va dua len.
     */
    public static function prioritize(string $batchId, int $profileId): bool
    {
        $batch = self::loadBatch($batchId);
        if (!$batch || ($batch['status'] ?? '') !== 'running') return false;
        $ids = json_decode((string)($batch['ids'] ?? '[]'), true);
        if (!is_array($ids)) return false;
        $doneIds = json_decode((string)($batch['done_ids'] ?? '[]'), true);
        if (!is_array($doneIds)) $doneIds = [];
        $doneSet = array_flip(array_map('intval', $doneIds));
        if (isset($doneSet[$profileId])) return false; // da xong
        $pos = array_search($profileId, array_map('intval', $ids), true);
        if ($pos === false) return false;
        // Dua len truoc pending chua xong dau tien
        $new = [];
        $new[] = $profileId;
        foreach ($ids as $pid) {
            if ((int)$pid !== $profileId) $new[] = (int)$pid;
        }
        try {
            db()->prepare('UPDATE eval_batches SET ids=? WHERE batch_id=?')->execute([json_encode(array_values($new)), $batchId]);
            try {
                SyncLogger::info('evaluation', "[EVAL PRIORITY] batch=$batchId profile=$profileId moved to front", $profileId);
            } catch (Throwable $e) {
            }
            return true;
        } catch (Throwable $e) {
            return false;
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
