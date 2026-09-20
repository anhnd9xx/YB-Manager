<?php
declare(strict_types=1);
/**
 * HealthCheckPipeline - staged health check cho 1 profile.
 *
 * Pipeline: PRECHECK_BROWSER -> CHECK_CDP -> CHECK_PROXY_NETWORK -> CHECK_SESSION
 *   -> CHECK_LOGIN -> CHECK_YOUTUBE -> CHECK_CHANNEL -> CHECK_SECURITY -> FINALIZE.
 * Moi stage: PASS | FAIL | TIMEOUT | NOT_RUN | NOT_APPLICABLE (khong 'unknown').
 * Short-circuit: CDP fail -> cac stage sau NOT_RUN (khong cho 20-30s);
 *   proxy fail -> giu channel, khong ket luan YT/channel.
 * Timeout moi stage (2/3/4/3/4/5/5/3s) + absolute deadline 20s.
 * Retry 1 lan chi cho CDP_TIMEOUT/NETWORK_TIMEOUT/PAGE_TIMEOUT.
 * Inference chi khi co evidence; technical loi -> confidence LOW, giu last_known.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AccountDataCollector.php';

class HealthCheckPipeline
{
    public const DEADLINE_SEC = 20;

    public const TIMEOUTS = [
        'PRECHECK_BROWSER' => 2, 'CHECK_CDP' => 3, 'CHECK_PROXY_NETWORK' => 4,
        'CHECK_SESSION' => 3, 'CHECK_LOGIN' => 4, 'CHECK_YOUTUBE' => 5,
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
     *   confidence:HIGH|MEDIUM|LOW, infra?:string, timings:array}
     */
    public static function run(int $profileId, callable $onStage = null): array
    {
        $t0 = microtime(true);
        $deadline = $t0 + self::DEADLINE_SEC;
        $stages = [];
        $timings = [];
        $mark = function (array $s) use (&$stages, $onStage, $profileId) {
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

        // ---- PRECHECK_BROWSER (2s): profile + runtime ----
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
            return self::finish($stages, [], 'tool_error', 'EVALUATOR_ERROR', 'profile_not_found', null, null, 'LOW', null, $timings, $t0);
        }
        if (($prof['status'] ?? '') !== 'running') {
            $mark(self::stage('PRECHECK_BROWSER', 'FAIL', 'CHROME_NOT_RUNNING', 'Chrome chua chay', $timings['browser']));
            self::notRun($stages, array_slice(self::STAGES, 1, 7));
            return self::finish($stages, [], 'precondition', 'CHROME_NOT_RUNNING', 'Can mo Chrome de kiem tra', null, null, 'LOW', null, $timings, $t0);
        }
        $mark(self::stage('PRECHECK_BROWSER', 'PASS', null, null, $timings['browser']));

        // ---- CHECK_CDP (3s): port + reachable + khop process ----
        $t = microtime(true);
        $port = (int)($prof['debug_port'] ?? 0);
        $cdpFail = null;
        if ($port <= 0) $cdpFail = ['CHECK_CDP', 'FAIL', 'CDP_UNAVAILABLE', 'Thieu debug port'];
        elseif (!cdp_reachable($port)) $cdpFail = ['CHECK_CDP', 'TIMEOUT', 'CDP_TIMEOUT', 'CDP khong phan hoi'];
        else {
            $udir = (string)($prof['user_data_dir'] ?? '');
            if ($udir !== '') {
                $cmd = chrome_main_cmdline($udir);
                if (is_string($cmd) && strpos($cmd, 'remote-debugging-port=' . $port) === false) {
                    $cdpFail = ['CHECK_CDP', 'FAIL', 'PROFILE_MISMATCH', 'Debug port khong khop Chrome cua kenh'];
                }
            }
        }
        $timings['cdp'] = (int)round((microtime(true) - $t) * 1000);
        if ($cdpFail !== null) {
            $mark(self::stage($cdpFail[0], $cdpFail[1], $cdpFail[2], $cdpFail[3], $timings['cdp']));
            self::notRun($stages, array_slice(self::STAGES, 2, 6));
            $pre = $cdpFail[2] === 'PROFILE_MISMATCH' ? 'precondition' : 'tool_error';
            return self::finish($stages, [], $pre, $cdpFail[2], $cdpFail[3], null, null, 'LOW', null, $timings, $t0);
        }
        $mark(self::stage('CHECK_CDP', 'PASS', null, null, $timings['cdp']));

        // ---- CHECK_PROXY_NETWORK (4s): proxy kenh con di duoc khong ----
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
            // Proxy hong: STOP, giu channel (khong ket luan YT/channel)
            $mark(self::stage('CHECK_PROXY_NETWORK', $px['result'] === 'TIMEOUT' ? 'TIMEOUT' : 'FAIL',
                'PROXY_ERROR', $px['detail'] ?? 'proxy loi', $timings['proxy']));
            self::notRun($stages, array_slice(self::STAGES, 3, 5));
            return self::finish($stages, [], 'tool_error', 'PROXY_ERROR', $px['detail'] ?? 'Proxy khong ket noi duoc',
                null, null, 'LOW', 'PROXY_ERROR', $timings, $t0);
        }
        if ($over()) {
            self::notRun($stages, array_slice(self::STAGES, 3, 5));
            return self::finish($stages, [], 'tool_error', 'TIMEOUT', 'Evaluation timeout', null, null, 'LOW', null, $timings, $t0);
        }

        // ---- CHECK_SESSION (3s): mo tab + evaluate duoc ----
        $t = microtime(true);
        $tabId = AccountDataCollector::openCheckTab($port);
        if ($tabId === null && !$over()) {
            usleep(500000); // retry 1 lan cho transient
            if (!$over()) $tabId = AccountDataCollector::openCheckTab($port);
        }
        $targets = $tabId !== null ? cdp_page_targets($port) : [];
        $sessOk = false;
        if ($tabId !== null) {
            $v = AccountDataCollector::eval($port, $tabId, 'document.readyState', $targets);
            $sessOk = $v !== null;
            if (!$sessOk && !$over()) {
                usleep(500000);
                $v = AccountDataCollector::eval($port, $tabId, 'document.readyState', null);
                $sessOk = $v !== null;
            }
        }
        $timings['session'] = (int)round((microtime(true) - $t) * 1000);
        if (!$sessOk) {
            if ($tabId !== null) AccountDataCollector::closeTab($port, $tabId);
            $mark(self::stage('CHECK_SESSION', 'TIMEOUT', 'CDP_TIMEOUT', 'Khong mo/evaluate duoc tab kiem tra', $timings['session']));
            self::notRun($stages, array_slice(self::STAGES, 4, 4));
            return self::finish($stages, [], 'tool_error', 'CDP_TIMEOUT', 'Trinh duyet phan hoi qua thoi gian', null, null, 'LOW', null, $timings, $t0);
        }
        $mark(self::stage('CHECK_SESSION', 'PASS', null, null, $timings['session']));

        // ---- CHECK_LOGIN + CHECK_YOUTUBE (4s/5s): 1 probe youtube ----
        $t = microtime(true);
        AccountDataCollector::waitLoad($port, $tabId, $deadline, self::TIMEOUTS['CHECK_YOUTUBE']);
        $yt = AccountDataCollector::probeYoutube($port, $tabId, $deadline, 2, cdp_page_targets($port));
        if ($yt === null && !$over()) {
            usleep(500000); // retry 1 lan
            $yt = AccountDataCollector::probeYoutube($port, $tabId, $deadline, 2, null);
        }
        $timings['login'] = $timings['youtube'] = (int)round((microtime(true) - $t) * 1000);
        if ($yt === null) {
            AccountDataCollector::closeTab($port, $tabId);
            $mark(self::stage('CHECK_LOGIN', 'NOT_RUN'));
            $mark(self::stage('CHECK_YOUTUBE', 'TIMEOUT', 'PAGE_TIMEOUT', 'Trang phan hoi qua cham', $timings['youtube']));
            self::notRun($stages, ['CHECK_CHANNEL', 'CHECK_SECURITY']);
            return self::finish($stages, [], 'tool_error', 'PAGE_TIMEOUT', 'Trang phan hoi qua cham', null, null, 'LOW', null, $timings, $t0);
        }
        $pageOk = !empty($yt['loaded']);
        // Login: FAIL chi khi trang da load xong ma khong avatar (evidence ro)
        if (!empty($yt['av'])) {
            $mark(self::stage('CHECK_LOGIN', 'PASS', null, null, $timings['login']));
            $loginEv = 'ok';
        } elseif ($pageOk) {
            $mark(self::stage('CHECK_LOGIN', 'FAIL', 'LOGIN_REQUIRED', 'Can dang nhap', $timings['login']));
            $loginEv = 'failed';
        } else {
            $mark(self::stage('CHECK_LOGIN', 'TIMEOUT', 'NETWORK_TIMEOUT', 'Trang chua tai xong', $timings['login']));
            $loginEv = 'unknown';
        }
        if ($pageOk) {
            $mark(self::stage('CHECK_YOUTUBE', 'PASS', null, null, $timings['youtube']));
            $ytEv = 'ok';
        } elseif ($loginEv === 'unknown') {
            $mark(self::stage('CHECK_YOUTUBE', 'TIMEOUT', 'NETWORK_TIMEOUT', 'Trang chua tai xong', $timings['youtube']));
            $ytEv = 'unknown';
        } else {
            // Trang phan hoi nhung khong phai YouTube hoan chinh (evidence)
            $mark(self::stage('CHECK_YOUTUBE', 'FAIL', 'YOUTUBE_UNAVAILABLE', 'Khong truy cap duoc YouTube', $timings['youtube']));
            $ytEv = 'failed';
        }

        // ---- CHECK_CHANNEL (5s) ----
        $t = microtime(true);
        $ch = AccountDataCollector::probeChannel($port, $tabId, $deadline, self::TIMEOUTS['CHECK_CHANNEL']);
        $timings['channel'] = (int)round((microtime(true) - $t) * 1000);
        if (($ch['state'] ?? 'unknown') === 'unknown' && empty($ch['name'])) {
            $mark(self::stage('CHECK_CHANNEL', 'TIMEOUT', null, 'Khong xac dinh duoc kenh', $timings['channel']));
        } else {
            $mark(self::stage('CHECK_CHANNEL', 'PASS', null, null, $timings['channel']));
        }

        // ---- CHECK_SECURITY (3s): tu du lieu da thu (khong query them) ----
        $t = microtime(true);
        $challenge = !empty($yt['ch']) || !empty($ch['ch']);
        $recovery = !empty($yt['rc']) || !empty($ch['rc']);
        $restricted = !empty($ch['restricted']);
        if ($recovery || $challenge || $restricted) {
            $code = $recovery ? 'recovery_required' : ($restricted ? 'restricted' : 'security_challenge');
            $mark(self::stage('CHECK_SECURITY', 'FAIL', $code, 'Can xac minh', (int)round((microtime(true) - $t) * 1000)));
        } else {
            $mark(self::stage('CHECK_SECURITY', 'PASS', null, null, (int)round((microtime(true) - $t) * 1000)));
        }
        AccountDataCollector::closeTab($port, $tabId);

        // ---- FINALIZE: inference evidence-only + confidence ----
        $mark(self::stage('FINALIZE', 'PASS', null, null, 0));
        $signals = [
            'login' => $loginEv, 'session' => 'ok', 'youtube' => $ytEv,
            'channel' => $ch['state'] ?? 'unknown', 'channelName' => $ch['name'] ?? null,
            'challenge' => $challenge, 'recovery' => $recovery, 'restricted' => $restricted,
        ];
        if ($restricted) {
            return self::finish($stages, $signals, 'success', null, null, 'RESTRICTED', 'restricted', 'HIGH', null, $timings, $t0);
        }
        if ($recovery) {
            return self::finish($stages, $signals, 'success', null, null, 'VERIFICATION_REQUIRED', 'recovery_required', 'HIGH', null, $timings, $t0);
        }
        if ($challenge) {
            return self::finish($stages, $signals, 'success', null, null, 'VERIFICATION_REQUIRED', 'security_challenge', 'HIGH', null, $timings, $t0);
        }
        if ($loginEv === 'failed') {
            return self::finish($stages, $signals, 'success', null, null, 'LOGIN_REQUIRED', 'login_required', 'HIGH', null, $timings, $t0);
        }
        if ($ytEv === 'failed') {
            return self::finish($stages, $signals, 'success', null, null, 'CHANNEL_UNAVAILABLE', 'youtube_unavailable', 'MEDIUM', null, $timings, $t0);
        }
        if ($loginEv === 'unknown' || $ytEv === 'unknown') {
            // Khong du evidence -> technical, giu last_known
            return self::finish($stages, $signals, 'tool_error', 'NETWORK_TIMEOUT', 'Ket noi mang qua thoi gian', null, null, 'LOW', null, $timings, $t0);
        }
        $conf = (($ch['state'] ?? 'unknown') === 'unknown') ? 'MEDIUM' : 'HIGH';
        return self::finish($stages, $signals, 'success', null, null, 'ACTIVE', null, $conf, null, $timings, $t0);
    }

    private static function finish(array $stages, array $signals, string $outcome, ?string $code, ?string $error,
        ?string $channelStatus, ?string $reason, string $confidence, ?string $infra, array $timings, float $t0): array
    {
        $timings['total'] = (int)round((microtime(true) - $t0) * 1000);
        return ['stages' => $stages, 'signals' => $signals, 'outcome' => $outcome,
            'error_code' => $code, 'error' => $error, 'channel_status' => $channelStatus,
            'reason' => $reason, 'confidence' => $confidence, 'infra' => $infra, 'timings' => $timings];
    }
}
