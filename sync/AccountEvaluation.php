<?php
declare(strict_types=1);
/**
 * AccountEvaluation - Engine THUAN TINH TOAN cho module Account Evaluation.
 * PURE: khong DB, khong CDP, khong log. Tach Data Collection khoi Evaluation
 * de unit test khong can mo Chrome that (spec muc 12).
 *
 * Dau vao:
 *   $signals: ['login'=>ok|failed|unknown, 'session'=>ok|failed|unknown,
 *              'youtube'=>ok|failed|unknown, 'channel'=>exists|none|unknown,
 *              'channelName'=>?string, 'challenge'=>bool, 'recovery'=>bool]
 *   $counters: ['success'=>int,'fail'=>int,'consecFails'=>int,
 *               'daysObserved'=>float,'hoursSinceSuccess'=>?float]
 *   $policy: tu SettingsService::accountDefaults/getAccountSettings
 * Dau ra: ['stability'=>0..100,'confidence'=>0..100,'stage'=>...,
 *          'reasons'=>[...],'warnings'=>[...]]
 */

class AccountStabilityCalculator
{
    /** Tri state -> 0..1 (unknown = 0.5: chua chac chan, khong dam cung khong tha). */
    public static function tri(string $v): float
    {
        return $v === 'ok' ? 1.0 : ($v === 'failed' ? 0.0 : 0.5);
    }

    /**
     * @param array{login:string,session:string,youtube:string} $signals
     * @param array{success:int,fail:int,consecFails:int,unavailFails:int} $counters
     * @param array{wLogin:float,wSession:float,wYoutube:float,wRate:float,wConsec:float} $w
     */
    public static function calc(array $signals, array $counters, array $w): int
    {
        $rate = 0.0;
        $total = (int)$counters['success'] + (int)$counters['fail'];
        if ($total > 0) $rate = (int)$counters['success'] / $total;
        elseif ((string)($signals['login'] ?? '') === 'ok') $rate = 1.0; // check dau tien ok
        $consec = max(0.0, 1.0 - (int)$counters['consecFails'] / max(1, (int)$counters['unavailFails']));
        $num = $w['wLogin'] * self::tri((string)($signals['login'] ?? 'unknown'))
             + $w['wSession'] * self::tri((string)($signals['session'] ?? 'unknown'))
             + $w['wYoutube'] * self::tri((string)($signals['youtube'] ?? 'unknown'))
             + $w['wRate'] * $rate
             + $w['wConsec'] * $consec;
        $den = max(0.0001, $w['wLogin'] + $w['wSession'] + $w['wYoutube'] + $w['wRate'] + $w['wConsec']);
        return (int)round(100 * max(0.0, min(1.0, $num / $den)));
    }
}

class AccountConfidenceCalculator
{
    /**
     * @param array{daysObserved:float,success:int,fail:int,hoursSinceSuccess:?float} $counters
     * @param array{minDays:int,minChecks:int,maxAgeH:int} $policy
     */
    public static function calc(array $counters, array $policy): int
    {
        $days = min(1.0, (float)$counters['daysObserved'] / max(1, (int)$policy['minDays']));
        $checks = min(1.0, (int)$counters['success'] / max(1, (int)$policy['minChecks']));
        $hss = $counters['hoursSinceSuccess'];
        if ($hss === null) {
            $recency = 0.0;
        } else {
            $recency = max(0.0, 1.0 - (float)$hss / max(1, (int)$policy['maxAgeH']));
        }
        $total = (int)$counters['success'] + (int)$counters['fail'];
        $consistency = $total > 0 ? (int)$counters['success'] / $total : 0.0;
        return (int)round(100 * (0.30 * $days + 0.30 * $checks + 0.20 * $recency + 0.20 * $consistency));
    }
}

class AccountReadinessEvaluator
{
    public const NEW = 'NEW';
    public const OBSERVING = 'OBSERVING';
    public const STABLE = 'STABLE';
    public const READY = 'READY_FOR_CHANNEL';
    public const CHANNEL = 'CHANNEL_EXISTS';
    public const REVIEW = 'REVIEW_REQUIRED';
    public const ACTION = 'ACTION_REQUIRED';
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Cay quyet dinh theo spec muc 6. Thu tu uu tien co dinh.
     * @param array{channel:string,challenge:bool,recovery:bool,login:string,session:string} $signals
     * @param array{daysObserved:float,success:int,consecFails:int} $counters
     * @param array{minDays:int,minChecks:int,readyStability:int,readyConfidence:int,reviewThreshold:int,unavailFails:int} $policy
     * @param array{stability:int,confidence:int} $scores
     * @return array{stage:string,reasons:string[],warnings:string[]}
     */
    public static function evaluate(array $signals, array $counters, array $policy, array $scores): array
    {
        $reasons = [];
        $warnings = [];
        $ch = ($signals['channel'] ?? 'unknown');
        if ($ch === 'exists') {
            $reasons[] = 'channel_exists';
            return ['stage' => self::CHANNEL, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if (!empty($signals['recovery'])) {
            $reasons[] = 'recovery_required';
            return ['stage' => self::ACTION, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if (!empty($signals['challenge'])) {
            $reasons[] = 'security_challenge';
            return ['stage' => self::ACTION, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if (($signals['login'] ?? 'unknown') === 'failed' || ($signals['session'] ?? 'unknown') === 'failed') {
            $reasons[] = 'login_or_session_unusable';
            return ['stage' => self::ACTION, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if ((int)$counters['consecFails'] >= max(1, (int)$policy['unavailFails'])) {
            $reasons[] = 'too_many_consecutive_failures';
            return ['stage' => self::UNAVAILABLE, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        $days = (float)$counters['daysObserved'];
        $succ = (int)$counters['success'];
        if ($days < (int)$policy['minDays'] || $succ < (int)$policy['minChecks']) {
            $reasons[] = 'insufficient_history';
            if ($ch === 'unknown') $warnings[] = 'channel_unknown';
            return ['stage' => self::OBSERVING, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if ((int)$scores['stability'] < (int)$policy['reviewThreshold']) {
            $reasons[] = 'stability_below_review';
            return ['stage' => self::REVIEW, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if ((int)$scores['confidence'] < (int)$policy['readyConfidence']) {
            $reasons[] = 'confidence_below_ready';
            return ['stage' => self::STABLE, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        if ((int)$scores['stability'] >= (int)$policy['readyStability']) {
            $reasons[] = 'meets_ready_policy';
            if ($ch === 'unknown') $warnings[] = 'channel_unknown';
            return ['stage' => self::READY, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        $reasons[] = 'stable_but_below_ready';
        return ['stage' => self::STABLE, 'reasons' => $reasons, 'warnings' => $warnings];
    }
}

class AccountEvaluationEngine
{
    /**
     * Chay full pipeline: stability -> confidence -> readiness.
     * Trong so mac dinh (tong 100): login 25 / session 15 / youtube 25 /
     * success-rate 25 / consec-fails 10. login+session OK cho san toi da 40 diem
     * nen REVIEW (<50) van dat duoc khi YouTube + lich su te (khong chet code).
     * @param array $signals (nhu ReadinessEvaluator)
     * @param array $counters ['success','fail','consecFails','daysObserved','hoursSinceSuccess']
     * @param array $policy full policy (weights + thresholds)
     */
    public static function evaluate(array $signals, array $counters, array $policy): array
    {
        $w = [
            'wLogin' => (float)($policy['wLogin'] ?? 25),
            'wSession' => (float)($policy['wSession'] ?? 15),
            'wYoutube' => (float)($policy['wYoutube'] ?? 25),
            'wRate' => (float)($policy['wRate'] ?? 25),
            'wConsec' => (float)($policy['wConsec'] ?? 10),
        ];
        $cc = $counters + ['unavailFails' => (int)($policy['unavailFails'] ?? 20)];
        $stability = AccountStabilityCalculator::calc($signals, $cc, $w);
        $confidence = AccountConfidenceCalculator::calc($counters, $policy);
        $r = AccountReadinessEvaluator::evaluate($signals, $counters, $policy,
            ['stability' => $stability, 'confidence' => $confidence]);
        $r['stability'] = $stability;
        $r['confidence'] = $confidence;
        return $r;
    }
}
