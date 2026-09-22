<?php
declare(strict_types=1);
/**
 * HealthCheckPipeline - STATE + EVIDENCE ENGINE (rebuild, khong patch if/else cu).
 *
 * Pipeline: PRECHECK_BROWSER -> CHECK_CDP -> CHECK_PROXY_NETWORK -> CHECK_SESSION
 *   (dedicated evaluation target) -> CHECK_LOGIN (GOOGLE AUTH) ->
 *   CHECK_YOUTUBE (YOUTUBE AUTH) -> CHECK_CHANNEL (PRESENCE+ACCESS) ->
 *   CHECK_SECURITY -> FINALIZE (decision table).
 *
 * Nguyen tac:
 *  1. Khong du bang chung => UNKNOWN (khong mac dinh SIGNED_OUT).
 *  2. Technical error => CHECK_FAILED/UNKNOWN, KHONG phai logged out.
 *  3. Chi strong evidence moi SIGNED_IN/SIGNED_OUT/HAS_CHANNEL/NO_CHANNEL.
 *
 * Moi result co: evaluation_id, profile_id, started_at, completed_at, version.
 * Moi stage: PASS | FAIL | TIMEOUT | NOT_RUN | NOT_APPLICABLE.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AccountDataCollector.php';
require_once __DIR__ . '/BrowserProbe.php';
require_once __DIR__ . '/EvalStates.php';
require_once __DIR__ . '/YoutubeSignals.php';
require_once __DIR__ . '/AuthEvidenceCollector.php';
require_once __DIR__ . '/AuthDecisionEngine.php';
require_once __DIR__ . '/ChannelPresenceEvidenceCollector.php';
require_once __DIR__ . '/EvaluationTarget.php';

class HealthCheckPipeline
{
    public const DEADLINE_SEC = 20;
    public const VERSION = 2;

    public const TIMEOUTS = [
        'PRECHECK_BROWSER' => 2, 'CHECK_CDP' => 3, 'CHECK_PROXY_NETWORK' => 4,
        'CHECK_SESSION' => 3, 'CHECK_LOGIN' => 5, 'CHECK_YOUTUBE' => 5,
        'CHECK_CHANNEL' => 5, 'CHECK_SECURITY' => 3,
    ];

    public const STAGES = [
        'PRECHECK_BROWSER', 'CHECK_CDP', 'CHECK_PROXY_NETWORK', 'CHECK_SESSION',
        'CHECK_LOGIN', 'CHECK_YOUTUBE', 'CHECK_CHANNEL', 'CHECK_SECURITY', 'FINALIZE',
    ];

    private static function stage(string $name, string $result, ?string $code = null, ?string $detail = null, int $ms = 0): array
    {
        return ['stage' => $name, 'result' => $result, 'code' => $code, 'detail' => $detail, 'ms' => $ms];
    }

    private static function notRun(array &$stages, array $names): void
    {
        foreach ($names as $n) $stages[$n] = self::stage($n, 'NOT_RUN');
    }

    /**
     * @return array{stages:array, signals:array, outcome:success|precondition|tool_error,
     *   error_code?:string, error?:string, channel_status?:string, reason?:string,
     *   confidence:HIGH|MEDIUM|LOW, infra?:string, timings:array,
     *   evaluation_id:string, started_at:string, browser_status:string,
     *   google:{status,confidence,reason}, youtube:{status,confidence,reason},
     *   presence:string, presence_confidence:string, access:string, security:string,
     *   evidence_summary:string}
     */
    public static function run(int $profileId, callable $onStage = null): array
    {
        $evaluationId = 'ev_' . date('YmdHis') . '_' . $profileId . '_' . substr(md5(microtime(true) . $profileId), 0, 6);
        $startedAt = date('Y-m-d H:i:s');
        $t0 = microtime(true);
        $deadline = $t0 + self::DEADLINE_SEC;
        $stages = [];
        $timings = [];
        $mark = function (array $s) use (&$stages, $onStage) {
            $stages[$s['stage']] = $s;
            if ($onStage) {
                try {
                    $onStage($s);
                } catch (Throwable $e) {
                }
            }
        };
        $over = function () use ($deadline): bool {
            return microtime(true) > $deadline;
        };
        $meta = ['evaluation_id' => $evaluationId, 'started_at' => $startedAt, 'version' => self::VERSION];

        // ---- PRECHECK_BROWSER ----
        $t = microtime(true);
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port, user_data_dir, proxy_id FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $prof = $st->fetch() ?: null;
        } catch (Throwable $e) {
            $prof = null;
        }
        $timings['browser'] = (int)round((microtime(true) - $t) * 1000);
        if (!$prof) {
            $mark(self::stage('PRECHECK_BROWSER', 'FAIL', 'EVALUATOR_ERROR', 'profile_not_found', $timings['browser']));
            self::notRun($stages, array_slice(self::STAGES, 1, 7));
            return self::finish($stages, [], 'tool_error', 'EVALUATOR_ERROR', 'profile_not_found', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'UNKNOWN', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        if (($prof['status'] ?? '') !== 'running') {
            $mark(self::stage('PRECHECK_BROWSER', 'FAIL', 'CHROME_NOT_RUNNING', 'Chrome chua chay', $timings['browser']));
            self::notRun($stages, array_slice(self::STAGES, 1, 7));
            return self::finish($stages, [], 'precondition', 'CHROME_NOT_RUNNING', 'Can mo Chrome de kiem tra', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'NOT_RUNNING', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        $mark(self::stage('PRECHECK_BROWSER', 'PASS', null, null, $timings['browser']));
        $port = (int)($prof['debug_port'] ?? 0);
        if ($port <= 0) {
            $mark(self::stage('CHECK_CDP', 'FAIL', 'CDP_UNAVAILABLE', 'Thieu debug port', 0));
            self::notRun($stages, array_slice(self::STAGES, 2, 6));
            return self::finish($stages, [], 'precondition', 'CDP_UNAVAILABLE', 'Thieu debug port', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'CDP_ERROR', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }

        // ---- CHECK_CDP ----
        $probe = BrowserProbe::probe($profileId, $port, (string)($prof['user_data_dir'] ?? ''));
        $timings['cdp'] = array_sum($probe['timings'] ?? []);
        $timings['probe'] = $probe['timings'] ?? [];
        if (empty($probe['ready'])) {
            $code = (string)($probe['code'] ?? 'CDP_CONNECT_TIMEOUT');
            if ($code === 'BROWSER_NOT_RUNNING') {
                $mark(self::stage('CHECK_CDP', 'FAIL', $code, $probe['message'] ?? '', $timings['cdp']));
                self::notRun($stages, array_slice(self::STAGES, 2, 6));
                return self::finish($stages, [], 'precondition', $code, $probe['message'] ?? '', null, null, 'LOW', null, $timings, $t0, $meta
                    + ['browser_status' => 'NOT_RUNNING', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                        'security' => 'UNKNOWN', 'evidence_summary' => '']);
            }
            if (in_array($code, ['DEBUG_PORT_NOT_LISTENING', 'DEVTOOLS_HTTP_UNAVAILABLE', 'CDP_WEBSOCKET_FAILED', 'CDP_COMMAND_TIMEOUT'], true)) {
                $res = $code === 'DEBUG_PORT_NOT_LISTENING' ? 'FAIL' : 'TIMEOUT';
                $mark(self::stage('CHECK_CDP', $res, $code, $probe['message'] ?? '', $timings['cdp']));
                self::notRun($stages, array_slice(self::STAGES, 2, 6));
                $bs = $code === 'CDP_COMMAND_TIMEOUT' ? 'TIMEOUT' : 'CDP_ERROR';
                return self::finish($stages, [], 'tool_error', $code, $probe['message'] ?? '', null, null, 'LOW', null, $timings, $t0, $meta
                    + ['browser_status' => $bs, 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                        'security' => 'UNKNOWN', 'evidence_summary' => '']);
            }
            $mark(self::stage('CHECK_CDP', 'FAIL', $code, $probe['message'] ?? '', $timings['cdp']));
            self::notRun($stages, array_slice(self::STAGES, 2, 6));
            return self::finish($stages, [], 'precondition', $code, $probe['message'] ?? '', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'CDP_ERROR', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        // Ownership: port phai thuoc Chrome cua profile (§16-§17, key = profile_id + port)
        $udir = (string)($prof['user_data_dir'] ?? '');
        if ($udir !== '') {
            $cmd = chrome_main_cmdline($udir);
            if (is_string($cmd) && strpos($cmd, 'remote-debugging-port=' . $port) === false) {
                $mark(self::stage('CHECK_CDP', 'FAIL', 'PROFILE_MISMATCH', 'Debug port khong khop Chrome cua kenh', $timings['cdp']));
                self::notRun($stages, array_slice(self::STAGES, 2, 6));
                return self::finish($stages, [], 'precondition', 'PROFILE_MISMATCH', 'Debug port khong khop Chrome cua kenh', null, null, 'LOW', null, $timings, $t0, $meta
                    + ['browser_status' => 'CDP_ERROR', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                        'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                        'security' => 'UNKNOWN', 'evidence_summary' => '']);
            }
        }
        $pt = $probe['timings'] ?? [];
        $detail = 'port ' . ($pt['tcp'] ?? '?') . 'ms / http ' . ($pt['http'] ?? '?') . 'ms / ws '
            . ($pt['ws'] ?? '?') . 'ms / cmd ' . ($pt['cmd'] ?? '?') . 'ms';
        $mark(self::stage('CHECK_CDP', 'PASS', null, $detail, $timings['cdp']));

        // ---- CHECK_PROXY_NETWORK ----
        $t = microtime(true);
        $proxyCfg = null;
        try {
            if (!empty($prof['proxy_id'])) {
                $ps = db()->prepare('SELECT host, port, protocol, username, password FROM proxies WHERE id=?');
                $ps->execute([(int)$prof['proxy_id']]);
                $proxyCfg = $ps->fetch() ?: null;
            }
        } catch (Throwable $e) {
        }
        $px = AccountDataCollector::checkProxyNetwork($proxyCfg, self::TIMEOUTS['CHECK_PROXY_NETWORK']);
        $timings['proxy'] = (int)round((microtime(true) - $t) * 1000);
        if ($px['result'] === 'NOT_APPLICABLE') {
            $mark(self::stage('CHECK_PROXY_NETWORK', 'NOT_APPLICABLE', null, null, $timings['proxy']));
        } elseif ($px['result'] === 'PASS') {
            $mark(self::stage('CHECK_PROXY_NETWORK', 'PASS', null, null, $timings['proxy']));
        } else {
            $mark(self::stage('CHECK_PROXY_NETWORK', $px['result'] === 'TIMEOUT' ? 'TIMEOUT' : 'FAIL',
                'PROXY_ERROR', $px['detail'] ?? 'proxy loi', $timings['proxy']));
            self::notRun($stages, array_slice(self::STAGES, 3, 5));
            return self::finish($stages, [], 'tool_error', 'PROXY_ERROR', $px['detail'] ?? 'Proxy khong ket noi duoc',
                null, null, 'LOW', 'PROXY_ERROR', $timings, $t0, $meta
                + ['browser_status' => 'READY', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        if ($over()) {
            self::notRun($stages, array_slice(self::STAGES, 3, 5));
            return self::finish($stages, [], 'tool_error', 'TIMEOUT', 'Evaluation timeout', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'TIMEOUT', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }

        // ---- CHECK_SESSION: dedicated evaluation target (§13-§15) ----
        $t = microtime(true);
        $acq = EvaluationTarget::acquire($port, $profileId, 'https://www.youtube.com/');
        $tabId = $acq['tabId'];
        if ($tabId === null && !$over()) {
            usleep(400000);
            if (!$over()) {
                $acq = EvaluationTarget::acquire($port, $profileId, 'https://www.youtube.com/');
                $tabId = $acq['tabId'];
            }
        }
        $sessOk = false;
        if ($tabId !== null) {
            $sessOk = AccountDataCollector::waitContext($port, $tabId, 2500);
        }
        $timings['session'] = (int)round((microtime(true) - $t) * 1000);
        if (!$sessOk) {
            if ($tabId !== null) EvaluationTarget::release($port, $tabId, true);
            $mark(self::stage('CHECK_SESSION', 'TIMEOUT', 'CDP_TIMEOUT', 'Khong mo/evaluate duoc tab kiem tra', $timings['session']));
            self::notRun($stages, array_slice(self::STAGES, 4, 4));
            return self::finish($stages, [], 'tool_error', 'CDP_TIMEOUT', 'Trinh duyet phan hoi qua thoi gian', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'TIMEOUT', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'not_checked'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        $mark(self::stage('CHECK_SESSION', 'PASS', null, $acq['reused'] ? 'reused eval tab' : 'new eval tab', $timings['session']));

        // ---- CHECK_LOGIN (GOOGLE AUTH) + CHECK_YOUTUBE (YOUTUBE AUTH) ----
        // Thu youtube evidence truoc (1 navigate da co tu acquire).
        $t = microtime(true);
        AccountDataCollector::waitLoad($port, $tabId, $deadline, 3);
        $ytCol = AuthEvidenceCollector::collectYoutube($port, $tabId, EvalStates::AUTH_SIGNAL_DEADLINE);
        $ytDict = $ytCol['dict'];
        if ($ytDict === null && !$over()) {
            usleep(500000);
            $ytCol = AuthEvidenceCollector::collectYoutube($port, $tabId, 2);
            $ytDict = $ytCol['dict'];
        }
        $timings['login'] = $timings['youtube'] = (int)round((microtime(true) - $t) * 1000);
        if ($ytDict === null) {
            EvaluationTarget::release($port, $tabId, true);
            $mark(self::stage('CHECK_LOGIN', 'NOT_RUN'));
            $mark(self::stage('CHECK_YOUTUBE', 'TIMEOUT', 'PAGE_TIMEOUT', 'Trang phan hoi qua cham', $timings['youtube']));
            self::notRun($stages, ['CHECK_CHANNEL', 'CHECK_SECURITY']);
            return self::finish($stages, [], 'tool_error', 'PAGE_TIMEOUT', 'Trang phan hoi qua cham', null, null, 'LOW', null, $timings, $t0, $meta
                + ['browser_status' => 'READY', 'google' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'page_timeout'],
                    'youtube' => ['status' => 'UNKNOWN', 'confidence' => 'LOW', 'reason' => 'page_timeout'],
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => 'UNKNOWN', 'evidence_summary' => '']);
        }
        $ytEv = $ytCol['evidence'];
        // Page-level transport error => UNKNOWN ca 2 (§55), KHONG suy logout
        $pageError = !empty($ytDict['pageError']);

        // GOOGLE AUTH: mac dinh dung chung youtube evidence (cung session);
        // chi navigate myaccount khi youtube UNKNOWN/conflict (tiet kiem 1 navigate).
        $gDec = AuthDecisionEngine::decide($ytEv, 'google');
        // Neu youtube evidence chua du ma page khong loi => thu google page doc lap
        $needGooglePage = ($gDec['status'] === 'UNKNOWN' && !$pageError && !$over());
        $gColExtra = null;
        if ($needGooglePage) {
            $gColExtra = AuthEvidenceCollector::collectGoogle($port, $tabId, $deadline);
            if (is_array($gColExtra['dict'])) {
                // Hop nhat: google page manh hon cho google decision
                $gDec2 = AuthDecisionEngine::decide($gColExtra['evidence'], 'google');
                // Chi nhan neu manh hon (HIGH/MEDIUM thang LOW)
                if (self::confRank($gDec2['confidence']) > self::confRank($gDec['confidence'])
                    || $gDec2['status'] !== 'UNKNOWN') {
                    // Giu conflict: neu 2 page mau thuan => UNKNOWN (§10)
                    if (($gDec['status'] === 'SIGNED_IN' && $gDec2['status'] === 'SIGNED_OUT')
                        || ($gDec['status'] === 'SIGNED_OUT' && $gDec2['status'] === 'SIGNED_IN')) {
                        $gDec = ['status' => 'UNKNOWN', 'confidence' => 'LOW',
                            'reason' => 'auth_evidence_conflict', 'evidence_used' => array_merge($gDec['evidence_used'], $gDec2['evidence_used'])];
                    } else {
                        $gDec = $gDec2;
                    }
                }
                // Ve lai youtube de check channel (navigate back)
                EvaluationTarget::navigate($port, $tabId, 'https://www.youtube.com/');
                AccountDataCollector::waitLoad($port, $tabId, $deadline, 3);
                $ytCol2 = AuthEvidenceCollector::collectYoutube($port, $tabId, 3);
                if (is_array($ytCol2['dict'])) {
                    $ytDict = $ytCol2['dict'];
                    $ytEv = $ytCol2['evidence'];
                }
            }
        }
        $yDec = AuthDecisionEngine::decide($ytEv, 'youtube');

        // Map decision -> stage (CHECK_LOGIN = google, CHECK_YOUTUBE = youtube)
        // Selector missing / UNKNOWN => TIMEOUT|FAIL? Quy uoc: UNKNOWN => TIMEOUT (technical),
        // SIGNED_OUT => FAIL(LOGIN_REQUIRED), SIGNED_IN => PASS, VERIFICATION => FAIL(challenge).
        self::markAuthStage($mark, 'CHECK_LOGIN', $gDec, $timings['login']);
        self::markAuthStage($mark, 'CHECK_YOUTUBE', $yDec, $timings['youtube']);

        $evSummary = '[YT ' . AuthEvidenceCollector::summary($ytEv) . ']';
        try {
            require_once __DIR__ . '/SyncLogger.php';
            SyncLogger::info('evaluation', '[EVAL AUTH] profile=' . $profileId
                . ' google=' . $gDec['status'] . '/' . $gDec['confidence']
                . ' youtube=' . $yDec['status'] . '/' . $yDec['confidence']
                . ' ' . $evSummary, $profileId);
        } catch (Throwable $e) {
        }

        // ---- AUTH gate: chi SIGNED_IN ca 2 moi duoc check channel (§25) ----
        $authOk = ($gDec['status'] === 'SIGNED_IN' && $yDec['status'] === 'SIGNED_IN');
        $challenge = ($gDec['status'] === 'VERIFICATION_REQUIRED' || $yDec['status'] === 'VERIFICATION_REQUIRED')
            || !empty($ytDict['ch']);
        $recovery = !empty($ytDict['rc']);
        $security = 'OK';
        if ($recovery) $security = 'RECOVERY';
        elseif ($challenge) $security = 'CHALLENGE';

        if (!$authOk || $challenge || $recovery || $pageError) {
            $mark(self::stage('CHECK_CHANNEL', 'NOT_RUN', null, 'Doi LOGIN xac nhan', 0));
            $mark(self::stage('CHECK_SECURITY', $security === 'OK' ? 'PASS' : 'FAIL',
                $security === 'OK' ? null : strtolower($security), $security === 'OK' ? null : 'Can xac minh', 0));
            EvaluationTarget::release($port, $tabId, false); // giu tab reuse
            $timings['channel'] = 0;
            $mark(self::stage('FINALIZE', 'PASS', null, null, 0));
            // Decision table cho cac case chua login (§29)
            if ($pageError) {
                $chStatus = null;
                $outcome = 'tool_error';
                $code = 'PAGE_TIMEOUT';
                $err = 'Trang gap loi mang';
                $conf = 'LOW';
            } else {
                $dt = EvalStates::decide(['browser' => 'READY',
                    'google' => $gDec['status'] === 'VERIFICATION_REQUIRED' ? 'VERIFICATION_REQUIRED'
                        : ($gDec['status'] === 'SIGNED_OUT' ? 'SIGNED_OUT'
                        : ($gDec['status'] === 'SIGNED_IN' ? 'SIGNED_IN' : 'UNKNOWN')),
                    'youtube' => $yDec['status'] === 'SIGNED_OUT' ? 'SIGNED_OUT'
                        : ($yDec['status'] === 'SIGNED_IN' ? 'SIGNED_IN' : 'UNKNOWN'),
                    'presence' => 'NOT_CHECKED', 'access' => 'NOT_CHECKED',
                    'security' => $security, 'infra' => 'OK']);
                $chStatus = $dt['channel_status'];
                if ($chStatus === 'ERROR') {
                    $outcome = 'tool_error';
                    $code = 'NETWORK_TIMEOUT';
                    $err = 'Chua xac dinh duoc dang nhap';
                    $conf = 'LOW';
                } else {
                    $outcome = 'success';
                    $code = null;
                    $err = null;
                    $conf = min($gDec['confidence'], $yDec['confidence']) === 'HIGH' ? 'HIGH' : 'MEDIUM';
                    if ($chStatus === 'LOGIN_REQUIRED') $err = 'login_required';
                    elseif ($chStatus === 'VERIFICATION_REQUIRED') $err = $recovery ? 'recovery_required' : 'security_challenge';
                }
            }
            // Legacy signals (tuong thich ChannelEvaluationManager::finishAccount)
            $loginEv = $yDec['status'] === 'SIGNED_IN' ? 'ok'
                : ($yDec['status'] === 'SIGNED_OUT' || $gDec['status'] === 'SIGNED_OUT' ? 'failed' : 'unknown');
            $ytEvSig = $loginEv;
            $authLegacy = $yDec['status'] === 'SIGNED_IN' && $gDec['status'] === 'SIGNED_IN' ? 'LOGGED_IN'
                : ($challenge || $recovery ? 'VERIFICATION_REQUIRED'
                : (($yDec['status'] === 'SIGNED_OUT' || $gDec['status'] === 'SIGNED_OUT') ? 'LOGIN_REQUIRED' : 'UNKNOWN'));
            $signals = ['login' => $loginEv, 'session' => $pageError ? 'unknown' : 'ok', 'youtube' => $ytEvSig,
                'channel' => 'unknown', 'channelName' => null,
                'challenge' => $challenge, 'recovery' => $recovery, 'restricted' => false,
                'auth' => $authLegacy,
                'presence' => 'NOT_CHECKED', 'presence_evidence' => null,
            ];
            $youtubeStatus = $authLegacy === 'LOGGED_IN' ? 'ACCESSIBLE'
                : ($authLegacy === 'LOGIN_REQUIRED' ? 'LOGIN_REQUIRED'
                : ($pageError ? 'UNKNOWN' : 'NOT_CHECKED'));
            $summaryAuth = $authLegacy === 'LOGGED_IN' ? 'SIGNED_IN'
                : ($authLegacy === 'LOGIN_REQUIRED' ? 'SIGNED_OUT'
                : ($authLegacy === 'VERIFICATION_REQUIRED' ? 'VERIFICATION_REQUIRED' : 'UNKNOWN'));
            $summary = EvalStates::buildSummary(['auth' => $summaryAuth, 'youtube' => $youtubeStatus,
                'presence' => 'NOT_CHECKED', 'access' => 'NOT_CHECKED', 'security' => $security,
                'attempt' => $outcome === 'success' ? 'SUCCESS' : 'FAILED']);
            return self::finish($stages, $signals, $outcome, $code, $err, $chStatus, $err, $conf, null, $timings, $t0, $meta
                + ['browser_status' => 'READY', 'google' => $gDec, 'youtube' => $yDec,
                    'youtube_status' => $youtubeStatus,
                    'presence' => 'NOT_CHECKED', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => $security, 'evidence_summary' => $evSummary,
                    'account_channel_state' => $summary['account_channel_state'], 'readiness' => $summary['readiness']]);
        }

        // ---- CHECK_CHANNEL (PRESENCE + ACCESS): chi khi auth SIGNED_IN (§25-§27) ----
        // Deadline 3-6s (§35). Retry RIENG channel toi da 1 lan neu transient (§36-§38),
        // reuse evidence login da pass (khong chay lai Browser/CDP/Login) (§37).
        $t = microtime(true);
        $chDict = self::probeChannelOnce($port, $tabId, $deadline);
        $chDec = ChannelPresenceEvidenceCollector::decide($chDict, true);
        if (ChannelPresenceEvidenceCollector::shouldRetry($chDec) && !$over()) {
            usleep(500000); // 1 retry cho transient
            if (!$over()) {
                $chDict = self::probeChannelOnce($port, $tabId, $deadline);
                $chDec = ChannelPresenceEvidenceCollector::decide($chDict, true);
            }
        }
        $timings['channel'] = (int)round((microtime(true) - $t) * 1000);
        $presence = $chDec['presence'];
        $presenceConf = $chDec['confidence'];
        $presenceEv = $presence === 'HAS_CHANNEL' ? 'youtube_channel_identity_confirmed'
            : ($presence === 'NO_CHANNEL' ? 'authenticated_account_without_channel' : null);
        $access = $chDec['access'];
        $chName = $chDec['channel_name'];
        $restricted = $chDec['restricted'];
        $challenge = $challenge || $chDec['challenge'];
        $recovery = $recovery || $chDec['recovery'];
        if ($recovery) $security = 'RECOVERY';
        elseif ($chDec['challenge']) $security = 'CHALLENGE';
        if ($restricted) $security = 'RESTRICTED';
        // Stage result: NO_CHANNEL la PASS value (khong phai FAIL) (§45)
        if ($presence === 'HAS_CHANNEL' || $presence === 'NO_CHANNEL') {
            $mark(self::stage('CHECK_CHANNEL', 'PASS', null,
                $presence === 'NO_CHANNEL' ? 'no_channel' : null, $timings['channel']));
        } elseif ($presence === 'TIMEOUT') {
            $mark(self::stage('CHECK_CHANNEL', 'TIMEOUT', 'CHANNEL_TIMEOUT', 'Kiem tra kenh qua thoi gian', $timings['channel']));
        } else {
            $mark(self::stage('CHECK_CHANNEL', 'TIMEOUT', null, 'Khong xac dinh duoc kenh', $timings['channel']));
        }

        // ---- CHECK_SECURITY ----
        $t = microtime(true);
        if ($security !== 'OK') {
            $code = $security === 'RECOVERY' ? 'recovery_required' : ($security === 'RESTRICTED' ? 'restricted' : 'security_challenge');
            $mark(self::stage('CHECK_SECURITY', 'FAIL', $code, 'Can xac minh', (int)round((microtime(true) - $t) * 1000)));
        } else {
            $mark(self::stage('CHECK_SECURITY', 'PASS', null, null, (int)round((microtime(true) - $t) * 1000)));
        }
        EvaluationTarget::release($port, $tabId, false);

        // ---- FINALIZE: decision table (§29) + precedence auth (§7) ----
        $mark(self::stage('FINALIZE', 'PASS', null, null, 0));
        $dt = EvalStates::decide(['browser' => 'READY', 'google' => 'SIGNED_IN', 'youtube' => 'SIGNED_IN',
            'presence' => $presence, 'access' => $access, 'security' => $security, 'infra' => 'OK']);
        $chStatus = $dt['channel_status'];
        $attempt = $dt['attempt']; // SUCCESS | PARTIAL
        $youtubeStatus = EvalStates::youtubeStatus('SIGNED_IN');
        if ($attempt === 'PARTIAL' || $chStatus === 'ERROR') {
            // Auth + YouTube da verify, channel technical (TIMEOUT/UNKNOWN/CHECK_FAILED):
            // PARTIAL - giu SIGNED_IN + ACCESSIBLE, presence UNKNOWN (§8, §21).
            // TUYET DOI KHONG revert ve NEED_LOGIN/LOGIN_REQUIRED (§7).
            $signals = ['login' => 'ok', 'session' => 'ok', 'youtube' => 'ok',
                'channel' => 'unknown', 'channelName' => null,
                'challenge' => $challenge, 'recovery' => $recovery, 'restricted' => $restricted,
                'auth' => 'LOGGED_IN', 'presence' => $presence === 'TIMEOUT' ? 'UNKNOWN' : $presence,
                'presence_evidence' => null];
            $summary = EvalStates::buildSummary(['auth' => 'SIGNED_IN', 'youtube' => $youtubeStatus,
                'presence' => 'UNKNOWN', 'access' => 'NOT_CHECKED', 'security' => $security, 'attempt' => 'PARTIAL']);
            return self::finish($stages, $signals, 'partial', 'CHANNEL_TIMEOUT', 'Kiem tra kenh qua thoi gian',
                'ACTIVE', 'channel_timeout', 'MEDIUM', null, $timings, $t0, $meta
                + ['browser_status' => 'READY', 'google' => $gDec, 'youtube' => $yDec,
                    'youtube_status' => $youtubeStatus,
                    'presence' => 'UNKNOWN', 'presence_confidence' => 'LOW', 'access' => 'NOT_CHECKED',
                    'security' => $security, 'evidence_summary' => $evSummary,
                    'account_channel_state' => $summary['account_channel_state'], 'readiness' => $summary['readiness']]);
        }
        $conf = $presenceConf === 'HIGH' && $gDec['confidence'] === 'HIGH' && $yDec['confidence'] === 'HIGH'
            ? 'HIGH' : 'MEDIUM';
        $signals = ['login' => 'ok', 'session' => 'ok', 'youtube' => 'ok',
            'channel' => $presence === 'HAS_CHANNEL' ? 'exists' : ($presence === 'NO_CHANNEL' ? 'none' : 'unknown'),
            'channelName' => $chName,
            'challenge' => $challenge, 'recovery' => $recovery, 'restricted' => $restricted,
            'auth' => 'LOGGED_IN',
            'presence' => $presence, 'presence_evidence' => $presenceEv];
        $reason = null;
        if ($chStatus === 'ACTIVE' && $presence === 'NO_CHANNEL') $reason = 'no_channel';
        elseif ($chStatus === 'RESTRICTED') $reason = 'restricted';
        elseif ($chStatus === 'VERIFICATION_REQUIRED') $reason = $recovery ? 'recovery_required' : 'security_challenge';
        $youtubeStatus = EvalStates::youtubeStatus('SIGNED_IN');
        $summary = EvalStates::buildSummary(['auth' => 'SIGNED_IN', 'youtube' => $youtubeStatus,
            'presence' => $presence, 'access' => $access, 'security' => $security, 'attempt' => 'SUCCESS']);
        return self::finish($stages, $signals, 'success', null, null, $chStatus, $reason, $conf, null, $timings, $t0, $meta
            + ['browser_status' => 'READY', 'google' => $gDec, 'youtube' => $yDec,
                'youtube_status' => $youtubeStatus,
                'presence' => $presence, 'presence_confidence' => $presenceConf, 'access' => $access,
                'security' => $security, 'evidence_summary' => $evSummary,
                'account_channel_state' => $summary['account_channel_state'], 'readiness' => $summary['readiness']]);
    }

    /**
     * 1 lan probe channel: navigate @me (dedicated target) + doi redirect + eval.
     * Tra ve dict hoac null (transport/timeout). Deadline cap ~6s.
     */
    private static function probeChannelOnce(int $port, string $tabId, float $deadline): ?array
    {
        EvaluationTarget::navigate($port, $tabId, 'https://www.youtube.com/@me');
        AccountDataCollector::waitLoad($port, $tabId, $deadline, 4);
        AccountDataCollector::waitUrlChange($port, $tabId, '/@me', $deadline, 2);
        $d = AccountDataCollector::eval($port, $tabId, YoutubeSignals::channelEvidenceJs());
        return is_array($d) ? $d : null;
    }

    private static function confRank(string $c): int
    {
        return $c === 'HIGH' ? 3 : ($c === 'MEDIUM' ? 2 : 1);
    }

    private static function markAuthStage(callable $mark, string $stage, array $dec, int $ms): void
    {
        $st = $dec['status'] ?? 'UNKNOWN';
        if ($st === 'SIGNED_IN') {
            $mark(self::stage($stage, 'PASS', null, $dec['reason'] ?? null, $ms));
        } elseif ($st === 'SIGNED_OUT') {
            $mark(self::stage($stage, 'FAIL', 'LOGIN_REQUIRED', 'Can dang nhap', $ms));
        } elseif ($st === 'VERIFICATION_REQUIRED') {
            $mark(self::stage($stage, 'FAIL', 'security_challenge', 'Can xac minh', $ms));
        } else {
            $mark(self::stage($stage, 'TIMEOUT', 'NETWORK_TIMEOUT', 'Chua xac dinh (' . ($dec['reason'] ?? '?') . ')', $ms));
        }
    }

    private static function finish(array $stages, array $signals, string $outcome, ?string $code, ?string $error,
        ?string $channelStatus, ?string $reason, string $confidence, ?string $infra, array $timings, float $t0, array $meta = []): array
    {
        $timings['total'] = (int)round((microtime(true) - $t0) * 1000);
        return array_merge(['stages' => $stages, 'signals' => $signals, 'outcome' => $outcome,
            'error_code' => $code, 'error' => $error, 'channel_status' => $channelStatus,
            'reason' => $reason, 'confidence' => $confidence, 'infra' => $infra, 'timings' => $timings,
            'completed_at' => date('Y-m-d H:i:s')], $meta);
    }
}
