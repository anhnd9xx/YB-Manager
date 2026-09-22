<?php
declare(strict_types=1);
/**
 * ChannelPresenceEvidenceCollector - Phan biet NO_CHANNEL vs TIMEOUT (§10-§11).
 *
 * Khong dung handle-empty hay 1 selector duy nhat. Thu nhieu signal doc lap
 * tu authenticated YouTube state, moi signal co name/value/confidence/source/ts.
 *
 * Tra ve enum: HAS_CHANNEL | NO_CHANNEL | UNKNOWN | TIMEOUT | CHECK_FAILED
 * (TIMEOUT khac NO_CHANNEL - TIMEOUT la loi ky thuat, giu auth).
 */
require_once __DIR__ . '/EvalStates.php';

class ChannelPresenceEvidenceCollector
{
    /**
     * @param array|null $dict dict tu YoutubeSignals::channelEvidenceJs() (null = transport/timeout)
     * @param bool $authOk auth SIGNED_IN ca 2 (bat buoc de ket luan HAS/NO)
     * @return array{presence:string, confidence:string, evidence:array[], access:string,
     *   channel_name:?string, restricted:bool, challenge:bool, recovery:bool}
     */
    public static function decide(?array $dict, bool $authOk): array
    {
        $ts = microtime(true);
        $mk = function (string $name, $value, string $conf) use ($ts): array {
            return ['name' => $name, 'value' => $value, 'confidence' => $conf,
                'source' => 'channel_dom', 'timestamp' => $ts];
        };
        $blank = [
            'presence' => 'CHECK_FAILED', 'confidence' => 'LOW', 'evidence' => [],
            'access' => 'NOT_CHECKED', 'channel_name' => null,
            'restricted' => false, 'challenge' => false, 'recovery' => false,
        ];
        if (!is_array($dict)) {
            // Khong thu duoc dict (CDP null) = transport/timeout, KHONG phai NO_CHANNEL
            $blank['presence'] = 'TIMEOUT';
            $blank['evidence'] = [$mk('channel_probe_no_response', true, 'HIGH')];
            return $blank;
        }
        $u = (string)($dict['u'] ?? '');
        $body = (string)($dict['body'] ?? '');
        $isPlaceholder = (bool)preg_match('~/(@me|me|mine|current)([/?#]|$)~i', $u);
        $identityPresent = (bool)preg_match('#youtube\.com/(@|channel/|c/)#i', $u)
            && !preg_match('#/signin|/signup#i', $u) && !$isPlaceholder;
        $createPresent = !empty($dict['create']);
        $restricted = (bool)($dict['restricted'] ?? false);
        $challenge = !empty($dict['ch']);
        $recovery = !empty($dict['rc']);
        // Trich handle that (loai placeholder @me/me/mine/current)
        $chName = null;
        if (preg_match('~youtube\.com/(@[^/?#]+)~i', $u, $m)) $chName = urldecode($m[1]);
        elseif (preg_match('~youtube\.com/(channel|c)/([^/?#]+)~i', $u, $m)) $chName = $m[2];
        if ($chName !== null && preg_match('/^(@me|me|mine|current)$/i', ltrim($chName, '@'))) {
            $chName = null;
            $identityPresent = false;
        }
        $ev = [
            $mk('authenticated_youtube_identity', $authOk, 'HIGH'),
            $mk('channel_identity_present', $identityPresent, 'HIGH'),
            $mk('create_channel_state_present', $createPresent, 'MEDIUM'),
            $mk('account_has_channel_entry', $identityPresent && !$createPresent, 'HIGH'),
            $mk('explicit_no_channel_state', $createPresent && !$identityPresent, 'HIGH'),
            $mk('channel_redirect_without_identity', $isPlaceholder, 'LOW'),
            $mk('channel_management_restricted', $restricted, 'MEDIUM'),
        ];
        // Chua login ma doi ket luan channel => NOT_CHECKED (precedence: auth truoc)
        if (!$authOk) {
            $blank['evidence'] = $ev;
            $blank['presence'] = 'NOT_CHECKED';
            $blank['challenge'] = $challenge;
            $blank['recovery'] = $recovery;
            $blank['restricted'] = $restricted;
            return $blank;
        }
        if ($identityPresent && !$createPresent) {
            return ['presence' => 'HAS_CHANNEL', 'confidence' => 'HIGH', 'evidence' => $ev,
                'access' => $restricted ? 'RESTRICTED' : 'ACCESSIBLE', 'channel_name' => $chName,
                'restricted' => $restricted, 'challenge' => $challenge, 'recovery' => $recovery];
        }
        if ($createPresent && !$identityPresent) {
            // Authenticated + explicit create marker = verified NO_CHANNEL (§26)
            return ['presence' => 'NO_CHANNEL', 'confidence' => 'HIGH', 'evidence' => $ev,
                'access' => 'NOT_APPLICABLE', 'channel_name' => null,
                'restricted' => $restricted, 'challenge' => $challenge, 'recovery' => $recovery];
        }
        if ($challenge || $recovery) {
            $blank['evidence'] = $ev;
            $blank['presence'] = 'UNKNOWN';
            $blank['challenge'] = $challenge;
            $blank['recovery'] = $recovery;
            $blank['restricted'] = $restricted;
            return $blank;
        }
        // Mo ho (redirect placeholder, DOM thieu, conflict nhe) => UNKNOWN, KHONG phai NO_CHANNEL
        $blank['evidence'] = $ev;
        $blank['presence'] = 'UNKNOWN';
        $blank['challenge'] = $challenge;
        $blank['recovery'] = $recovery;
        $blank['restricted'] = $restricted;
        return $blank;
    }

    /** Co nen retry channel stage? Chi TIMEOUT/page tam thoi, KHONG retry HAS/NO (§38). */
    public static function shouldRetry(array $dec): bool
    {
        return $dec['presence'] === 'TIMEOUT';
    }
}
