<?php
declare(strict_types=1);
/**
 * AuthEvidenceCollector - Thu nhieu signal doc lap (§5).
 * Moi signal: [name, value, confidence, source, timestamp].
 * Cookie/avatar/handle chi la ONE weak/medium evidence (§6-§8).
 */
require_once __DIR__ . '/YoutubeSignals.php';
require_once __DIR__ . '/AccountDataCollector.php';

class AuthEvidenceCollector
{
    /**
     * Collect YOUTUBE evidence voi condition-based wait (§11-§12, §54):
     * poll moi 250ms den khi co strong evidence / page error / challenge
     * hoac het AUTH_SIGNAL_DEADLINE. Ket thuc som khi du (khong sleep dai).
     *
     * @return array{dict:?array, evidence:array[], waited_ms:int, settled:bool}
     */
    public static function collectYoutube(int $port, string $tabId, int $deadlineSec = 5): array
    {
        $t0 = microtime(true);
        $deadline = min(microtime(true) + max(2, $deadlineSec), microtime(true) + 8);
        $last = null;
        $waited = 0;
        do {
            $d = AccountDataCollector::eval($port, $tabId, YoutubeSignals::youtubeEvidenceJs());
            if (is_array($d)) {
                $last = $d;
                if (self::settled($d)) break;
            }
            usleep(250000);
            $waited = (int)round((microtime(true) - $t0) * 1000);
        } while (microtime(true) < $deadline);
        // Final read (dam bao co dict moi nhat)
        if ($last === null) {
            $d = AccountDataCollector::eval($port, $tabId, YoutubeSignals::youtubeEvidenceJs());
            if (is_array($d)) $last = $d;
        }
        $waited = (int)round((microtime(true) - $t0) * 1000);
        if (!is_array($last)) {
            return ['dict' => null, 'evidence' => [], 'waited_ms' => $waited, 'settled' => false];
        }
        require_once __DIR__ . '/AuthDecisionEngine.php';
        return ['dict' => $last, 'evidence' => AuthDecisionEngine::fromYoutubeDict($last),
            'waited_ms' => $waited, 'settled' => self::settled($last)];
    }

    /** Dieu kien dung som: strong auth / login / challenge / page error. */
    private static function settled(array $d): bool
    {
        if (!empty($d['ch']) || !empty($d['rc'])) return true;        // security
        if (!empty($d['pageError'])) return true;                     // page error
        if (!empty($d['av']) && (($d['rs'] ?? '') === 'complete' || trim((string)($d['t'] ?? '')) !== '')) return true;
        if (!empty($d['loginRedirect'])) return true;                 // explicit login
        if (!empty($d['signinCta']) && (($d['rs'] ?? '') === 'complete')) return true;
        return false;
    }

    /**
     * Collect GOOGLE evidence (navigate tab rieng den myaccount, roi back).
     * Chi goi khi youtube evidence chua du / conflict (tiet kiem navigate).
     */
    public static function collectGoogle(int $port, string $tabId, float $deadline): array
    {
        $t0 = microtime(true);
        $ws = self::wsFor($port, $tabId);
        if ($ws !== null) {
            cdp_ws_send($port, $ws, json_encode(['id' => 21, 'method' => 'Page.navigate',
                'params' => ['url' => 'https://myaccount.google.com/']]));
        }
        AccountDataCollector::waitLoad($port, $tabId, $deadline, 4);
        $d = AccountDataCollector::eval($port, $tabId, YoutubeSignals::googleEvidenceJs());
        $waited = (int)round((microtime(true) - $t0) * 1000);
        if (!is_array($d)) {
            return ['dict' => null, 'evidence' => [], 'waited_ms' => $waited, 'settled' => false];
        }
        require_once __DIR__ . '/AuthDecisionEngine.php';
        return ['dict' => $d, 'evidence' => AuthDecisionEngine::fromGoogleDict($d),
            'waited_ms' => $waited, 'settled' => true];
    }

    private static function wsFor(int $port, string $tabId): ?string
    {
        foreach (cdp_page_targets($port) as $t) {
            if ((string)($t['id'] ?? '') === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                return (string)$t['webSocketDebuggerUrl'];
            }
        }
        return null;
    }

    /** Tom tat evidence cho log (khong log cookie/token/email/password) (§47-§48). */
    public static function summary(array $evidence): string
    {
        $pos = 0;
        $neg = 0;
        foreach ($evidence as $e) {
            if (!is_array($e) || empty($e['value'])) continue;
            $n = (string)($e['name'] ?? '');
            if (in_array($n, ['account_avatar_detected', 'account_menu_detected',
                'authenticated_endpoint_signal', 'youtube_account_identity_detected'], true)) $pos++;
            if (in_array($n, ['login_page_detected', 'redirect_to_login',
                'sign_in_cta_detected'], true)) $neg++;
        }
        return "signals={$pos}/" . max(1, ($pos + $neg)) . ($neg > 0 ? " neg={$neg}" : '');
    }
}
