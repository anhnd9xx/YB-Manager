<?php
declare(strict_types=1);
/**
 * AccountDataCollector - Thu thap tin hieu tai khoan qua CDP.
 * CHI DOC + dieu huong tab kiem tra rieng (background) cua chinh user:
 * - Khong bypass CAPTCHA/2FA/recovery (gap -> danh dau, user tu xu ly).
 * - Khong like/view/sub/comment. Khong luu password/token/cookie.
 *
 * Hardening:
 * - precheck() rieng voi error_code ro rang (CHROME_NOT_RUNNING,
 *   DEBUG_PORT_UNAVAILABLE, CDP_CONNECT_FAILED, PROFILE_MISMATCH).
 * - 1 snapshot /json/list dau batch, reuse trong ca run (khong query tung eval).
 * - Moi stage co timeout (poll 250ms, khong fixed sleep dai).
 */
require_once __DIR__ . '/../config.php';

class AccountDataCollector
{
    // Error codes (khong dung chung "ERROR")
    public const E_CHROME_NOT_RUNNING = 'CHROME_NOT_RUNNING';
    public const E_DEBUG_PORT = 'DEBUG_PORT_UNAVAILABLE';
    public const E_CDP_CONNECT = 'CDP_CONNECT_FAILED';
    public const E_CDP_TIMEOUT = 'CDP_TIMEOUT';
    public const E_PAGE_TIMEOUT = 'PAGE_TIMEOUT';
    public const E_PROFILE_MISMATCH = 'PROFILE_MISMATCH';
    public const E_INTERNAL = 'EVALUATOR_INTERNAL_ERROR';

    /** Loi tam thoi duoc retry 1 lan (delay 500ms). */
    public static function isTransient(string $code): bool
    {
        return in_array($code, [self::E_CDP_CONNECT, self::E_CDP_TIMEOUT, 'NETWORK_TIMEOUT'], true);
    }

    /**
     * PRECHECK: profile + runtime + port + CDP + khop debug port voi process.
     * @return array{ok:bool, profile?:array, port?:int, code?:string, message?:string, ms:int}
     */
    public static function precheck(int $profileId): array
    {
        $t0 = microtime(true);
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port, user_data_dir FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $p = $st->fetch();
            if (!$p) {
                return ['ok' => false, 'code' => self::E_INTERNAL, 'message' => 'profile_not_found',
                        'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            $port = (int)($p['debug_port'] ?? 0);
            if (($p['status'] ?? '') !== 'running') {
                return ['ok' => false, 'code' => self::E_CHROME_NOT_RUNNING, 'message' => 'Chrome chua chay (can mo Chrome de kiem tra)',
                        'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            if ($port <= 0) {
                return ['ok' => false, 'code' => self::E_DEBUG_PORT, 'message' => 'Thieu debug port',
                        'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            if (!cdp_reachable($port)) {
                return ['ok' => false, 'code' => self::E_CDP_CONNECT, 'message' => 'Khong ket noi duoc CDP',
                        'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            // Khop debug port voi process Chrome that cua profile (chong nham port)
            $udir = (string)($p['user_data_dir'] ?? '');
            if ($udir !== '') {
                $cmd = chrome_main_cmdline($udir);
                if (is_string($cmd) && strpos($cmd, 'remote-debugging-port=' . $port) === false) {
                    return ['ok' => false, 'code' => self::E_PROFILE_MISMATCH,
                            'message' => 'Debug port khong khop Chrome cua kenh',
                            'ms' => (int)round((microtime(true) - $t0) * 1000)];
                }
            }
            return ['ok' => true, 'profile' => $p, 'port' => $port,
                    'ms' => (int)round((microtime(true) - $t0) * 1000)];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => self::E_INTERNAL, 'message' => mb_substr($e->getMessage(), 0, 200),
                    'ms' => (int)round((microtime(true) - $t0) * 1000)];
        }
    }

    /**
     * @return array{status:success|failed|skipped, signals:array, error?:string, error_code?:string, timings?:array}
     * signals: login|session|youtube (ok|failed|unknown), channel (exists|none|unknown),
     *          channelName, challenge:bool, recovery:bool
     * $timeouts: ['session'=>5,'platform'=>8] giay.
     */
    public static function collect(int $profileId, int $timeoutSec = 25, array $timeouts = []): array
    {
        $tAll = microtime(true);
        $timings = [];
        $t = microtime(true);
        $pre = self::precheck($profileId);
        $timings['precheck'] = (int)round((microtime(true) - $t) * 1000);
        if (!$pre['ok']) {
            $code = (string)($pre['code'] ?? self::E_INTERNAL);
            // Precheck fail = dieu kien, khong phai ket luan account hong
            return self::res('skipped', [], (string)($pre['message'] ?? ''), $code, $timings);
        }
        $port = (int)$pre['port'];
        $deadline = $tAll + max(10, $timeoutSec);
        $tSession = (int)($timeouts['session'] ?? 5);
        $tPlatform = (int)($timeouts['platform'] ?? 8);
        try {
            // Tab kiem tra background rieng (khong dung tab user dang xem)
            $t = microtime(true);
            $tabId = self::openTab($port, 'https://www.youtube.com');
            $timings['connect'] = (int)round((microtime(true) - $t) * 1000);
            if ($tabId === null) {
                return self::res('failed', self::blank(), 'cannot open check tab', self::E_CDP_TIMEOUT, $timings);
            }
            try {
                // 1 snapshot targets, reuse ca run (khong /json/list tung eval)
                $targets = cdp_page_targets($port);
                $t = microtime(true);
                $yt = self::probeYoutube($port, $tabId, $deadline, $tSession, $targets);
                $timings['session'] = (int)round((microtime(true) - $t) * 1000);
                if ($yt === null) {
                    return self::res('failed', self::blank(), 'youtube probe failed', self::E_PAGE_TIMEOUT, $timings);
                }
                $t = microtime(true);
                $ch = self::probeChannel($port, $tabId, $deadline, $tPlatform);
                $timings['platform'] = (int)round((microtime(true) - $t) * 1000);
                $signals = [
                    // KHONG boolean: trang chua load xong => unknown (khong suy logout) (§30-§31, §51)
                    'login' => $yt['av'] ? 'ok' : ($yt['loaded'] ? 'failed' : 'unknown'),
                    'session' => 'ok', // CDP evaluate thanh cong = session dung duoc
                    'youtube' => $yt['loaded'] ? 'ok' : 'unknown',
                    'channel' => $ch['state'],
                    'channelName' => $ch['name'],
                    'challenge' => $yt['ch'] || $ch['ch'],
                    'recovery' => $yt['rc'] || $ch['rc'],
                ];
                $timings['total'] = (int)round((microtime(true) - $tAll) * 1000);
                return self::res('success', $signals, null, null, $timings);
            } finally {
                self::closeTab($port, $tabId);
            }
        } catch (Throwable $e) {
            $timings['total'] = (int)round((microtime(true) - $tAll) * 1000);
            return self::res('failed', self::blank(), mb_substr($e->getMessage(), 0, 200), self::E_INTERNAL, $timings);
        }
    }

    private static function res(string $status, array $signals, ?string $error, ?string $code, array $timings): array
    {
        $r = ['status' => $status, 'signals' => $signals + self::blank(), 'timings' => $timings];
        if ($error !== null) $r['error'] = $error;
        if ($code !== null) $r['error_code'] = $code;
        return $r;
    }

    private static function blank(): array
    {
        return ['login' => 'unknown', 'session' => 'unknown', 'youtube' => 'unknown',
                'channel' => 'unknown', 'channelName' => null, 'challenge' => false, 'recovery' => false];
    }

    private static function openTab(int $port, string $url, int $timeoutSec = 3): ?string
    {
        // PUT: Chrome moi tra 405 cho GET /json/new (raw socket, ~ms)
        $r = cdp_http($port, 'PUT', '/json/new?' . urlencode($url), max(1000, $timeoutSec * 1000));
        if ($r === null) return null;
        $t = json_decode($r['body'], true);
        return is_array($t) && !empty($t['id']) ? (string)$t['id'] : null;
    }

    public static function openCheckTab(int $port, string $url = 'https://www.youtube.com'): ?string
    {
        return self::openTab($port, $url, 2);
    }

    /**
     * CHECK_PROXY_NETWORK (4s): proxy kenh con di duoc khong.
     * Khong proxy -> NOT_APPLICABLE. Auth sai/timeout/conn -> PROXY_ERROR.
     * @return array{result:PASS|TIMEOUT|FAIL|NOT_APPLICABLE, code?:string, ms:int}
     */
    public static function checkProxyNetwork(?array $proxyCfg, int $timeoutSec = 4): array
    {
        $t0 = microtime(true);
        $ms = fn() => (int)round((microtime(true) - $t0) * 1000);
        if (empty($proxyCfg) || empty($proxyCfg['host'])) {
            return ['result' => 'NOT_APPLICABLE', 'ms' => $ms()];
        }
        $ch = curl_init('http://www.google.com/generate_204');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_NOBODY => true,
        ]);
        proxy_curl_apply($ch, [
            'host' => $proxyCfg['host'], 'port' => (int)($proxyCfg['port'] ?? 0),
            'protocol' => $proxyCfg['protocol'] ?? 'http',
            'username' => $proxyCfg['username'] ?? null, 'password' => $proxyCfg['password'] ?? null,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno === 0 && $code >= 200 && $code < 400) {
            return ['result' => 'PASS', 'ms' => $ms()];
        }
        if ($errno === 28) return ['result' => 'TIMEOUT', 'code' => 'PROXY_ERROR', 'detail' => 'proxy timeout', 'ms' => $ms()];
        if ($errno === 67) return ['result' => 'FAIL', 'code' => 'PROXY_ERROR', 'detail' => 'proxy auth failed', 'ms' => $ms()];
        return ['result' => 'FAIL', 'code' => 'PROXY_ERROR', 'detail' => 'proxy unreachable', 'ms' => $ms()];
    }

    public static function closeTab(int $port, string $tabId): void
    {
        cdp_http($port, 'GET', '/json/close/' . $tabId, 1500);
    }

    /** Tim WS tu snapshot co san; thieu thi fetch lai 1 lan (khong moi eval). */
    private static function wsFor(int $port, string $tabId, ?array $cached = null): ?string
    {
        $list = $cached ?? cdp_page_targets($port);
        foreach ($list as $t) {
            if ((string)($t['id'] ?? '') === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                return (string)$t['webSocketDebuggerUrl'];
            }
        }
        if ($cached !== null) {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)($t['id'] ?? '') === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                    return (string)$t['webSocketDebuggerUrl'];
                }
            }
        }
        return null;
    }

    /** Evaluate JS tren tab, tra ve value da decode (array) hoac null. */
    public static function eval(int $port, string $tabId, string $js, ?array $cached = null): ?array
    {
        $r = self::evalRaw($port, $tabId, $js, $cached);
        if (!$r['ok']) return null;
        return $r['value'];
    }

    /**
     * Evaluate phan biet loi CDP (vd tab moi chua co execution context)
     * voi mat ket noi. Tra ve ['ok'=>bool,'value'=>?array,'error'=>?string].
     */
    public static function evalRaw(int $port, string $tabId, string $js, ?array $cached = null): array
    {
        $ws = self::wsFor($port, $tabId, $cached);
        if ($ws === null) return ['ok' => false, 'value' => null, 'error' => 'no-target'];
        $v = cdp_ws_batch($port, $ws, [
            json_encode(['id' => 7, 'method' => 'Runtime.evaluate',
                'params' => ['expression' => $js, 'returnByValue' => true]]),
        ], 7);
        if (!is_string($v)) return ['ok' => false, 'value' => null, 'error' => 'no-response'];
        if (str_starts_with($v, 'ERR:')) return ['ok' => false, 'value' => null, 'error' => $v];
        // cdp_ws_batch tra RAW value: object -> JSON string; scalar -> tran (string)
        // hoac bare ('complete' khong quotes). Ca 3 deu la ket qua hop le.
        $d = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['ok' => true, 'value' => is_array($d) ? $d : ['value' => $d], 'error' => null];
        }
        return ['ok' => true, 'value' => ['value' => $v], 'error' => null];
    }

    /**
     * Doi execution context san sang (tab moi can vai tram ms).
     * Poll nhe 200ms toi da $budgetMs (mac dinh 2000ms), khong sleep mu.
     */
    public static function waitContext(int $port, string $tabId, int $budgetMs = 2000): bool
    {
        $deadline = microtime(true) + max(200, $budgetMs) / 1000;
        do {
            // readyState tra string -> evalRaw ok:true; chua context -> error chua 'context'
            $r = self::evalRaw($port, $tabId, 'document.readyState', null);
            if ($r['ok']) return true;
            // Chi doi khi context chua co; loi khac (mat target) -> dung ngay
            if (!str_contains((string)($r['error'] ?? ''), 'context')) return false;
            usleep(200000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** Cho tab load (title xuat hien), poll 250ms toi da $waitSec (khong sleep mu). */
    public static function waitLoad(int $port, string $tabId, float $deadline, int $waitSec = 8): void
    {
        $until = min($deadline, microtime(true) + $waitSec);
        while (microtime(true) < $until) {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)($t['id'] ?? '') === $tabId && trim((string)($t['title'] ?? '')) !== '') return;
            }
            usleep(250000);
        }
    }

    /** Cho URL doi (redirect @me), poll 250ms thay vi sleep cung 1.5s. */
    public static function waitUrlChange(int $port, string $tabId, string $fromPart, float $deadline, int $waitSec = 4): void
    {
        $until = min($deadline, microtime(true) + $waitSec);
        while (microtime(true) < $until) {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)($t['id'] ?? '') !== $tabId) continue;
                $u = (string)($t['url'] ?? '');
                if ($u !== '' && stripos($u, $fromPart) === false) return;
            }
            usleep(250000);
        }
    }

    public static function probeYoutube(int $port, string $tabId, float $deadline, int $waitSec, array $targets): ?array
    {
        self::waitLoad($port, $tabId, $deadline, $waitSec);
        $d = self::eval($port, $tabId,
            "(()=>{try{const t=document.title||'',u=location.href;"
            . "return {t:t.slice(0,120),u:u.slice(0,200),"
            . "av:!!document.querySelector('button#avatar-btn'),"
            . "rs:document.readyState,"
            . "ch:/challenge|verify/i.test(u+' '+t),"
            . "rc:/recover/i.test(u)}}catch(e){return null}})()", $targets);
        if (!is_array($d)) return null;
        return [
            'av' => !empty($d['av']),
            'loaded' => ($d['rs'] ?? '') === 'complete' || trim((string)($d['t'] ?? '')) !== '',
            'ch' => !empty($d['ch']),
            'rc' => !empty($d['rc']),
        ];
    }

    /**
     * Kiem tra channel qua https://www.youtube.com/@me (redirect ve channel neu co)
     * + doi chieu markers tao-kenh. Mo ho -> 'unknown' (khong doan mo).
     */
    public static function probeChannel(int $port, string $tabId, float $deadline, int $waitSec): array
    {
        $out = ['state' => 'unknown', 'name' => null, 'ch' => false, 'rc' => false, 'restricted' => false];
        $ws = self::wsFor($port, $tabId);
        if ($ws === null) return $out;
        cdp_ws_send($port, $ws, json_encode(['id' => 8, 'method' => 'Page.navigate',
            'params' => ['url' => 'https://www.youtube.com/@me']]));
        self::waitLoad($port, $tabId, $deadline, $waitSec);
        self::waitUrlChange($port, $tabId, '/@me', $deadline, 2);
        $d = self::eval($port, $tabId,
            "(()=>{try{const u=location.href,t=document.title||'';"
            . "const body=(document.body?document.body.innerText.slice(0,3000):'');"
            . "return {u:u.slice(0,220),t:t.slice(0,120),body:body,"
            . "ch:/challenge|verify/i.test(u+' '+t),"
            . "rc:/recover/i.test(u)}}catch(e){return null}})()");
        if (!is_array($d)) return $out;
        $out['ch'] = !empty($d['ch']);
        $out['rc'] = !empty($d['rc']);
        $u = (string)($d['u'] ?? '');
        $body = (string)($d['body'] ?? '');
        $out['restricted'] = (bool)preg_match('/restricted|bị hạn chế|account.*suspend|kênh.*vi phạm/i', $u . ' ' . mb_substr($body, 0, 1000));
        // Placeholder @me/me/mine/current KHONG chung minh co channel
        $isPlaceholder = (bool)preg_match('#/(@me|me|mine|current)([/?#]|$)#i', $u);
        $looksChannel = (bool)preg_match('#youtube\.com/(@|channel/|c/)#i', $u)
            && !preg_match('#/signin|/signup#i', $u) && !$isPlaceholder;
        $createMarkers = (bool)preg_match('/create.*channel|tạo kênh|create a channel|tạo kênh/i', $body);
        if ($looksChannel && !$createMarkers) {
            $out['state'] = 'exists';
            if (preg_match('~youtube\.com/(@[^/?#]+)~i', $u, $m)) $out['name'] = urldecode($m[1]);
            elseif (preg_match('~youtube\.com/(channel|c)/([^/?#]+)~i', $u, $m)) $out['name'] = $m[2];
            // Ten placeholder (@me/...) -> khong phai evidence, huy ket luan
            if ($out['name'] !== null && preg_match('/^(@me|me|mine|current)$/i', ltrim($out['name'], '@'))) {
                $out['state'] = 'unknown';
                $out['name'] = null;
            }
        } elseif ($createMarkers) {
            $out['state'] = 'none';
        }
        return $out;
    }
}
