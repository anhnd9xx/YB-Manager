<?php
declare(strict_types=1);
/**
 * AccountDataCollector - Thu thap tin hieu tai khoan qua CDP (spec muc 12).
 * CHI DOC + dieu huong tab kiem tra rieng (background) cua chinh user:
 * - Khong bypass CAPTCHA/2FA/recovery (gap -> danh dau ACTION_REQUIRED, user tu xu ly).
 * - Khong like/view/sub/comment.
 * - Khong luu password/token/cookie (chi trang thai + diem).
 * Reuse: cdp_reachable, cdp_page_targets, cdp_ws_send/batch (config.php).
 */
require_once __DIR__ . '/../config.php';

class AccountDataCollector
{
    /**
     * @return array{status:ok|failed|skipped, signals:array, error?:string}
     * signals: login|session|youtube (ok|failed|unknown), channel (exists|none|unknown),
     *          channelName, challenge:bool, recovery:bool
     */
    public static function collect(int $profileId, int $timeoutSec = 25): array
    {
        $t0 = microtime(true);
        $deadline = $t0 + max(10, $timeoutSec);
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $p = $st->fetch();
            if (!$p) return self::res('skipped', [], 'no profile');
            $port = (int)($p['debug_port'] ?? 0);
            if (($p['status'] ?? '') !== 'running' || $port <= 0 || !cdp_reachable($port)) {
                return self::res('skipped', [], 'profile not running');
            }
            // Tab kiem tra background rieng (khong dung tab user dang xem)
            $tabId = self::openTab($port, 'https://www.youtube.com');
            if ($tabId === null) {
                return self::res('failed', self::blank(), 'cannot open check tab');
            }
            try {
                $yt = self::probeYoutube($port, $tabId, $deadline);
                if ($yt === null) {
                    return self::res('failed', self::blank(), 'youtube probe failed');
                }
                $ch = self::probeChannel($port, $tabId, $deadline);
                $signals = [
                    'login' => $yt['av'] ? 'ok' : 'failed',
                    'session' => 'ok', // CDP evaluate thanh cong = session dung duoc
                    'youtube' => $yt['loaded'] ? 'ok' : 'failed',
                    'channel' => $ch['state'],
                    'channelName' => $ch['name'],
                    'challenge' => $yt['ch'] || $ch['ch'],
                    'recovery' => $yt['rc'] || $ch['rc'],
                ];
                return self::res('success', $signals, null);
            } finally {
                self::closeTab($port, $tabId);
            }
        } catch (Throwable $e) {
            return self::res('failed', self::blank(), mb_substr($e->getMessage(), 0, 200));
        }
    }

    private static function res(string $status, array $signals, ?string $error): array
    {
        $r = ['status' => $status, 'signals' => $signals + self::blank()];
        if ($error !== null) $r['error'] = $error;
        return $r;
    }

    private static function blank(): array
    {
        return ['login' => 'unknown', 'session' => 'unknown', 'youtube' => 'unknown',
                'channel' => 'unknown', 'channelName' => null, 'challenge' => false, 'recovery' => false];
    }

    private static function openTab(int $port, string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://127.0.0.1:' . $port . '/json/new?' . urlencode($url), false, $ctx);
        $t = json_decode((string)$raw, true);
        return is_array($t) && !empty($t['id']) ? (string)$t['id'] : null;
    }

    private static function closeTab(int $port, string $tabId): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        @file_get_contents('http://127.0.0.1:' . $port . '/json/close/' . $tabId, false, $ctx);
    }

    private static function wsFor(int $port, string $tabId): ?string
    {
        foreach (cdp_page_targets($port) as $t) {
            if ((string)$t['id'] === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                return (string)$t['webSocketDebuggerUrl'];
            }
        }
        return null;
    }

    /** Evaluate JS tren tab, tra ve value da decode (array) hoac null. */
    private static function eval(int $port, string $tabId, string $js): ?array
    {
        $ws = self::wsFor($port, $tabId);
        if ($ws === null) return null;
        $v = cdp_ws_batch($port, $ws, [
            json_encode(['id' => 7, 'method' => 'Runtime.evaluate',
                'params' => ['expression' => $js, 'returnByValue' => true]]),
        ], 7);
        if (!is_string($v)) return null;
        $d = json_decode($v, true);
        return is_array($d) ? $d : null;
    }

    /** Cho tab load (title/url xuat hien), toi da $waitSec. */
    private static function waitLoad(int $port, string $tabId, float $deadline, int $waitSec = 8): void
    {
        $until = min($deadline, microtime(true) + $waitSec);
        while (microtime(true) < $until) {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)$t['id'] === $tabId && trim((string)($t['title'] ?? '')) !== '') return;
            }
            usleep(500000);
        }
    }

    private static function probeYoutube(int $port, string $tabId, float $deadline): ?array
    {
        self::waitLoad($port, $tabId, $deadline, 8);
        $d = self::eval($port, $tabId,
            "(()=>{try{const t=document.title||'',u=location.href;"
            . "return {t:t.slice(0,120),u:u.slice(0,200),"
            . "av:!!document.querySelector('button#avatar-btn'),"
            . "rs:document.readyState,"
            . "ch:/challenge|verify/i.test(u+' '+t),"
            . "rc:/recover/i.test(u)}}catch(e){return null}})()");
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
    private static function probeChannel(int $port, string $tabId, float $deadline): array
    {
        $out = ['state' => 'unknown', 'name' => null, 'ch' => false, 'rc' => false];
        $ws = self::wsFor($port, $tabId);
        if ($ws === null) return $out;
        cdp_ws_send($port, $ws, json_encode(['id' => 8, 'method' => 'Page.navigate',
            'params' => ['url' => 'https://www.youtube.com/@me']]));
        self::waitLoad($port, $tabId, $deadline, 8);
        usleep(1500000); // cho redirect @me hoan tat
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
        $looksChannel = (bool)preg_match('#youtube\.com/(@|channel/|c/)#i', $u)
            && !preg_match('#/signin|/signup#i', $u);
        $createMarkers = (bool)preg_match('/create.*channel|tạo kênh|create a channel|tạo kênh/i', $body);
        if ($looksChannel && !$createMarkers) {
            $out['state'] = 'exists';
            if (preg_match('#youtube\.com/(@[^/?#]+)#i', $u, $m)) $out['name'] = urldecode($m[1]);
            elseif (preg_match('#youtube\.com/(channel|c)/([^/?#]+)#i', $u, $m)) $out['name'] = $m[2];
        } elseif ($createMarkers) {
            $out['state'] = 'none';
        }
        return $out;
    }
}
