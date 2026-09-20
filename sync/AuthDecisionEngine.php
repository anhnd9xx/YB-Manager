<?php
declare(strict_types=1);
/**
 * AuthDecisionEngine - Ket luan auth tu LIST Evidence (§9, §29, §63).
 *
 * Input:  list[Evidence{name,value,confidence,source,timestamp}]
 * Output: [status, confidence, reason, evidence_used]
 *
 * Nguyen tac:
 *  - Khong du bang chung => UNKNOWN (KHONG mac dinh SIGNED_OUT).
 *  - Cookie/avatar/handle mot minh KHONG du ket luan (§6-§8).
 *  - Conflicting signals => UNKNOWN + log AUTH_EVIDENCE_CONFLICT (§10).
 *  - Selector failure (thieu signal) => UNKNOWN, tru khi explicit logout/login (§51).
 */
require_once __DIR__ . '/EvalStates.php';

class AuthDecisionEngine
{
    /**
     * @param array[] $ev list evidence (moi cai: name/value/confidence/source/ts)
     * @param string $kind 'google'|'youtube' (de quy tac text phu hop)
     * @return array{status:string, confidence:string, reason:string, evidence_used:string[]}
     */
    public static function decide(array $ev, string $kind = 'youtube'): array
    {
        $by = [];
        foreach ($ev as $e) {
            if (!is_array($e) || !isset($e['name'])) continue;
            $by[(string)$e['name']] = $e;
        }
        $used = array_keys($by);
        $val = function (string $n) use ($by): bool {
            return !empty($by[$n]['value']);
        };
        $missing = function (string $n) use ($by): bool {
            return !array_key_exists($n, $by) || ($by[$n]['value'] === null);
        };

        // Page / transport loi => UNKNOWN (khong phai signed out) (§55)
        if ($val('page_error_detected')) {
            return self::out('UNKNOWN', 'LOW', 'page_error', $used);
        }
        if ($missing('ready_state') && $missing('account_avatar_detected')
            && $missing('sign_in_cta_detected') && $missing('login_page_detected')) {
            // DOM incomplete / khong thu duoc gi => UNKNOWN (§51)
            return self::out('UNKNOWN', 'LOW', 'dom_incomplete', $used);
        }
        // Security challenge => VERIFICATION_REQUIRED (khong gán SIGNED_OUT) (§28)
        if ($val('security_challenge_detected') || $val('recovery_detected')) {
            return self::out('VERIFICATION_REQUIRED', 'HIGH',
                $val('recovery_detected') ? 'recovery_required' : 'security_challenge', $used);
        }

        // Dem signal doc lap (khong tinh cookie/handle mot minh)
        $pos = 0; // authenticated signals
        if ($val('account_avatar_detected')) $pos++;
        if ($val('account_menu_detected')) $pos++;
        if ($val('authenticated_endpoint_signal')) $pos++;
        if ($val('youtube_account_identity_detected')) $pos++;
        $neg = 0; // signed-out signals
        if ($val('login_page_detected')) $neg += 2; // explicit login page rat manh
        if ($val('redirect_to_login')) $neg += 2;
        if ($val('sign_in_cta_detected')) $neg++;
        // Cookie/session chi la weak/medium, khong tu quyet (§6)
        $cookieHint = $val('session_cookie_signal');

        // Conflict: ca pos va neg manh => UNKNOWN + retry o caller (§10)
        if ($pos >= 2 && $neg >= 2) {
            try {
                require_once __DIR__ . '/SyncLogger.php';
                SyncLogger::warn('evaluation', '[AUTH_EVIDENCE_CONFLICT] kind=' . $kind
                    . ' pos=' . $pos . ' neg=' . $neg);
            } catch (Throwable $e) {
            }
            return self::out('UNKNOWN', 'LOW', 'auth_evidence_conflict', $used);
        }
        // Conflict nhe (1 pos + signin CTA don le): co the CTA rac/A-B => UNKNOWN
        if ($pos >= 1 && $neg >= 1 && $pos < 2 && $neg < 2) {
            return self::out('UNKNOWN', 'LOW', 'auth_evidence_conflict', $used);
        }

        // SIGNED_IN HIGH: authenticated signal + identity + khong login redirect (§9)
        if ($pos >= 2 && $neg === 0) {
            return self::out('SIGNED_IN', 'HIGH', 'authenticated_identity_confirmed', $used);
        }
        // SIGNED_IN MEDIUM: 1 strong + cookie ho tro, hoac 1 strong don le khong neg
        if ($pos >= 1 && $neg === 0) {
            $conf = ($cookieHint || $pos >= 1) ? 'MEDIUM' : 'LOW';
            // 1 avatar don le + khong neg => MEDIUM (khong HIGH vi can >=2)
            return self::out('SIGNED_IN', $cookieHint ? 'MEDIUM' : 'MEDIUM', 'single_identity_signal', $used);
        }
        // SIGNED_OUT HIGH: explicit login page / redirect + endpoint rejected + khong pos (§9)
        if ($neg >= 2 && $pos === 0) {
            return self::out('SIGNED_OUT', 'HIGH', 'explicit_login_state', $used);
        }
        if ($val('sign_in_cta_detected') && $pos === 0 && $val('authenticated_endpoint_rejected')) {
            return self::out('SIGNED_OUT', 'HIGH', 'explicit_signin_state', $used);
        }
        // CTA don le nhung khong pos va khong explicit page => MEDIUM SIGNED_OUT
        // (van yeu cau kem dieu kien page da load xong de tranh false logout)
        if ($val('sign_in_cta_detected') && $pos === 0 && $val('page_loaded')) {
            return self::out('SIGNED_OUT', 'MEDIUM', 'signin_cta_without_identity', $used);
        }
        // Con lai: khong du => UNKNOWN
        return self::out('UNKNOWN', 'LOW', 'insufficient_evidence', $used);
    }

    private static function out(string $status, string $conf, string $reason, array $used): array
    {
        $valid = $status === 'VERIFICATION_REQUIRED'
            ? EvalStates::GOOGLE_AUTH
            : array_merge(EvalStates::GOOGLE_AUTH, EvalStates::YOUTUBE_AUTH);
        if (!in_array($status, $valid, true)) $status = 'UNKNOWN';
        if (!in_array($conf, EvalStates::CONF, true)) $conf = 'LOW';
        return ['status' => $status, 'confidence' => $conf, 'reason' => $reason, 'evidence_used' => array_values($used)];
    }

    /**
     * Xay Evidence[] tu dict JS tho cua YoutubeSignals (de collector goi).
     * Moi evidence: [name, value(bool|string|null), confidence(HIGH|MED|LOW), source, ts].
     */
    public static function fromYoutubeDict(array $d, string $source = 'youtube_dom'): array
    {
        $ts = microtime(true);
        $mk = function (string $name, $value, string $conf) use ($source, $ts): array {
            return ['name' => $name, 'value' => $value, 'confidence' => $conf, 'source' => $source, 'timestamp' => $ts];
        };
        $loaded = (($d['rs'] ?? '') === 'complete') || trim((string)($d['t'] ?? '')) !== '';
        return [
            $mk('account_avatar_detected', !empty($d['av']), 'MEDIUM'),
            $mk('account_menu_detected', !empty($d['accountMenu']), 'MEDIUM'),
            $mk('authenticated_endpoint_signal', !empty($d['av']) && empty($d['loginRedirect']), 'MEDIUM'),
            $mk('youtube_account_identity_detected', !empty($d['av']) || !empty($d['identityName']), 'MEDIUM'),
            $mk('sign_in_cta_detected', !empty($d['signinCta']), 'MEDIUM'),
            $mk('login_page_detected', !empty($d['loginPage']), 'HIGH'),
            $mk('redirect_to_login', !empty($d['loginRedirect']), 'HIGH'),
            $mk('authenticated_endpoint_rejected', !empty($d['loginRedirect']) && empty($d['av']), 'MEDIUM'),
            $mk('security_challenge_detected', !empty($d['ch']), 'HIGH'),
            $mk('recovery_detected', !empty($d['rc']), 'HIGH'),
            $mk('page_error_detected', !empty($d['pageError']), 'HIGH'),
            $mk('page_loaded', $loaded, 'LOW'),
            // Cookie/handle chi weak: luon false o DOM-only (collector bo sung neu co)
            $mk('session_cookie_signal', false, 'LOW'),
            $mk('ready_state', (string)($d['rs'] ?? ''), 'LOW'),
        ];
    }

    public static function fromGoogleDict(array $d, string $source = 'google_dom'): array
    {
        $ts = microtime(true);
        $mk = function (string $name, $value, string $conf) use ($source, $ts): array {
            return ['name' => $name, 'value' => $value, 'confidence' => $conf, 'source' => $source, 'timestamp' => $ts];
        };
        $loaded = (($d['rs'] ?? '') === 'complete') || trim((string)($d['t'] ?? '')) !== '';
        return [
            $mk('account_avatar_detected', !empty($d['av']) || !empty($d['email']), 'MEDIUM'),
            $mk('account_menu_detected', !empty($d['av']), 'LOW'),
            $mk('authenticated_endpoint_signal', (!empty($d['av']) || !empty($d['email'])) && empty($d['loginRedirect']), 'MEDIUM'),
            $mk('youtube_account_identity_detected', !empty($d['email']), 'MEDIUM'),
            $mk('sign_in_cta_detected', !empty($d['signinCta']) || !empty($d['signinForm']), 'MEDIUM'),
            $mk('login_page_detected', !empty($d['loginPage']), 'HIGH'),
            $mk('redirect_to_login', !empty($d['loginRedirect']), 'HIGH'),
            $mk('authenticated_endpoint_rejected', !empty($d['loginRedirect']) && empty($d['av']) && empty($d['email']), 'MEDIUM'),
            $mk('security_challenge_detected', !empty($d['ch']), 'HIGH'),
            $mk('recovery_detected', !empty($d['rc']), 'HIGH'),
            $mk('page_error_detected', !empty($d['pageError']), 'HIGH'),
            $mk('page_loaded', $loaded, 'LOW'),
            $mk('session_cookie_signal', false, 'LOW'),
            $mk('ready_state', (string)($d['rs'] ?? ''), 'LOW'),
        ];
    }
}
