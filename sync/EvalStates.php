<?php
declare(strict_types=1);
/**
 * EvalStates - STATE MODEL MOI cho Channel Evaluation Engine (§2, §3, §32-§33).
 *
 * Khong dung mot field `status` chung chung. Moi truc co enum rieng,
 * khong boolean (UNKNOWN / NOT_CHECKED / CHECK_FAILED bieu dien duoc).
 *
 * browser_status:          UNKNOWN|READY|NOT_RUNNING|CDP_ERROR|TIMEOUT
 * google_auth_status:      UNKNOWN|CHECKING|SIGNED_IN|SIGNED_OUT|VERIFICATION_REQUIRED|CHECK_FAILED
 * youtube_auth_status:     UNKNOWN|CHECKING|SIGNED_IN|SIGNED_OUT|CHECK_FAILED
 * channel_presence:        UNKNOWN|NOT_CHECKED|CHECKING|HAS_CHANNEL|NO_CHANNEL|CHECK_FAILED
 * channel_access_status:   UNKNOWN|NOT_CHECKED|ACCESSIBLE|UNAVAILABLE|RESTRICTED|CHECK_FAILED
 * evaluation_attempt:      QUEUED|RUNNING|SUCCESS|PARTIAL|FAILED|TIMEOUT|CANCELLED
 * confidence:              HIGH|MEDIUM|LOW
 * security_status:         OK|CHALLENGE|RECOVERY|RESTRICTED|UNKNOWN
 * infra_status:            OK|PROXY_ERROR|NETWORK_ERROR|BROWSER_ERROR|UNKNOWN
 */

class EvalStates
{
    public const BROWSER = ['UNKNOWN', 'READY', 'NOT_RUNNING', 'CDP_ERROR', 'TIMEOUT'];
    public const GOOGLE_AUTH = ['UNKNOWN', 'CHECKING', 'SIGNED_IN', 'SIGNED_OUT', 'VERIFICATION_REQUIRED', 'CHECK_FAILED'];
    public const YOUTUBE_AUTH = ['UNKNOWN', 'CHECKING', 'SIGNED_IN', 'SIGNED_OUT', 'CHECK_FAILED'];
    public const PRESENCE = ['UNKNOWN', 'NOT_CHECKED', 'CHECKING', 'HAS_CHANNEL', 'NO_CHANNEL', 'CHECK_FAILED'];
    public const ACCESS = ['UNKNOWN', 'NOT_CHECKED', 'ACCESSIBLE', 'UNAVAILABLE', 'RESTRICTED', 'CHECK_FAILED'];
    public const ATTEMPT = ['QUEUED', 'RUNNING', 'SUCCESS', 'PARTIAL', 'FAILED', 'TIMEOUT', 'CANCELLED'];
    public const CONF = ['HIGH', 'MEDIUM', 'LOW'];
    public const SECURITY = ['OK', 'CHALLENGE', 'RECOVERY', 'RESTRICTED', 'UNKNOWN'];
    public const INFRA = ['OK', 'PROXY_ERROR', 'NETWORK_ERROR', 'BROWSER_ERROR', 'UNKNOWN'];

    /** TTL / deadline tap trung (§19, §36, §54). */
    public const AUTH_CACHE_TTL = 30;          // giay, cache theo profile
    public const AUTH_SIGNAL_DEADLINE = 5;     // giay, cho identity settle (ket thuc som khi strong evidence)
    public const AUTH_VERIFICATION_TTL = 86400; // giay (24h): qua han -> stale, can kiem tra lai

    public static function valid(string $v, array $set): bool
    {
        return in_array($v, $set, true);
    }

    /**
     * DECISION TABLE BAT BUOC (§29). Khong nested if lung tung o caller.
     * Input: ket qua cac stage (da phan loai). Output: channel_status cu
     * (ACTIVE|LOGIN_REQUIRED|VERIFICATION_REQUIRED|CHANNEL_UNAVAILABLE|RESTRICTED|ERROR|UNCHECKED)
     * + attempt + ly do. Nguyen tac:
     *  1. Khong du bang chung => UNKNOWN (khong mac dinh SIGNED_OUT).
     *  2. Technical error => CHECK_FAILED/UNKNOWN, KHONG phai logged out.
     *  3. Chi strong evidence moi SIGNED_IN/SIGNED_OUT/HAS_CHANNEL/NO_CHANNEL.
     *
     * @param array{browser:string, google:string, youtube:string, presence:string,
     *   access:string, security:string, infra:string} $s
     * @return array{channel_status:string, attempt:string, reason:string}
     */
    public static function decide(array $s): array
    {
        $b = (string)($s['browser'] ?? 'UNKNOWN');
        $g = (string)($s['google'] ?? 'UNKNOWN');
        $y = (string)($s['youtube'] ?? 'UNKNOWN');
        $sec = (string)($s['security'] ?? 'UNKNOWN');
        $infra = (string)($s['infra'] ?? 'UNKNOWN');

        // CASE: infra loi (proxy/network/browser) => ERROR + attempt FAILED, giu last-known o caller
        if (in_array($infra, ['PROXY_ERROR', 'NETWORK_ERROR', 'BROWSER_ERROR'], true)) {
            return ['channel_status' => 'ERROR', 'attempt' => 'FAILED', 'reason' => 'infra_' . strtolower($infra)];
        }
        // CASE: browser technical => ERROR, auth UNKNOWN, channel NOT_CHECKED
        if (in_array($b, ['NOT_RUNNING', 'CDP_ERROR', 'TIMEOUT', 'UNKNOWN'], true) && $b !== 'READY') {
            // NOT_RUNNING la precondition (can mo Chrome), con lai la tool error
            $att = $b === 'TIMEOUT' ? 'TIMEOUT' : 'FAILED';
            return ['channel_status' => 'ERROR', 'attempt' => $att, 'reason' => 'browser_' . strtolower($b)];
        }
        // CASE: security challenge => VERIFICATION_REQUIRED, channel NOT_CHECKED
        if (in_array($sec, ['CHALLENGE', 'RECOVERY'], true)
            || $g === 'VERIFICATION_REQUIRED') {
            return ['channel_status' => 'VERIFICATION_REQUIRED', 'attempt' => 'SUCCESS',
                'reason' => $sec === 'RECOVERY' ? 'recovery_required' : 'security_challenge'];
        }
        if ($sec === 'RESTRICTED') {
            return ['channel_status' => 'RESTRICTED', 'attempt' => 'SUCCESS', 'reason' => 'restricted'];
        }
        // CASE: explicit signed out (google HOAC youtube) => LOGIN_REQUIRED, channel NOT_CHECKED
        if ($g === 'SIGNED_OUT' || $y === 'SIGNED_OUT') {
            return ['channel_status' => 'LOGIN_REQUIRED', 'attempt' => 'SUCCESS', 'reason' => 'login_required'];
        }
        // CASE: auth chua xac dinh / check failed => ERROR (technical), KHONG suy logged out
        if ($g !== 'SIGNED_IN' || $y !== 'SIGNED_IN') {
            return ['channel_status' => 'ERROR', 'attempt' => 'FAILED', 'reason' => 'auth_unknown'];
        }
        // Tu day: google SIGNED_IN + youtube SIGNED_IN
        $p = (string)($s['presence'] ?? 'NOT_CHECKED');
        $a = (string)($s['access'] ?? 'NOT_CHECKED');
        if ($p === 'HAS_CHANNEL') {
            if ($a === 'RESTRICTED') {
                return ['channel_status' => 'RESTRICTED', 'attempt' => 'SUCCESS', 'reason' => 'restricted'];
            }
            if ($a === 'UNAVAILABLE') {
                return ['channel_status' => 'CHANNEL_UNAVAILABLE', 'attempt' => 'SUCCESS', 'reason' => 'channel_unavailable'];
            }
            // ACCESSIBLE hoac chua check access xong van la ACTIVE (presence da strong)
            return ['channel_status' => 'ACTIVE', 'attempt' => 'SUCCESS', 'reason' => ''];
        }
        if ($p === 'NO_CHANNEL') {
            // Da login nhung verified khong co channel: van la ACTIVE o channel_status cu?
            // Giua backward-compat: dung ACTIVE + presence=NO_CHANNEL (UI hien "Chua co kenh").
            // Khong dung CHANNEL_UNAVAILABLE (do la loi ky thuat/truy cap).
            return ['channel_status' => 'ACTIVE', 'attempt' => 'SUCCESS', 'reason' => 'no_channel'];
        }
        // Signed in + channel check technical => ERROR (giu last-known), KHONG phai UNAVAILABLE
        return ['channel_status' => 'ERROR', 'attempt' => 'FAILED', 'reason' => 'channel_unknown'];
    }

    /** Chi HIGH/MEDIUM moi duoc overwrite last-known (§33). */
    public static function mayOverwrite(string $conf): bool
    {
        return $conf === 'HIGH' || $conf === 'MEDIUM';
    }
}
