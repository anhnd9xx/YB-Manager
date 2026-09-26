<?php
declare(strict_types=1);
/**
 * PermissionService - Allowlist + Role + Rate limit (§6, §7, §30, §32).
 * VIEWER: xem status/report. OPERATOR: chay job duoc phep. ADMIN: quan tri.
 * Unknown sender: KHONG chay command (§57).
 */
require_once __DIR__ . '/../config.php';

class PermissionService
{
    public const VIEWER = 'VIEWER';
    public const OPERATOR = 'OPERATOR';
    public const DEVELOPER = 'DEVELOPER';
    public const ADMIN = 'ADMIN';

    public const RATE_PER_MIN = 10;
    public const ROLES = ['VIEWER', 'OPERATOR', 'DEVELOPER', 'ADMIN'];

    /** @return array[] [{chat_id, user_id?, role}] */
    public static function allowed(): array
    {
        try {
            $j = json_decode((string)get_setting('notify_allowed_chats', ''), true);
            return is_array($j) ? array_values($j) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function saveAllowed(array $list): void
    {
        $clean = [];
        foreach ($list as $a) {
            if (!is_array($a) || trim((string)($a['chat_id'] ?? '')) === '') continue;
            $role = strtoupper(trim((string)($a['role'] ?? 'VIEWER')));
            if (!in_array($role, self::ROLES, true)) $role = self::VIEWER;
            $clean[] = ['chat_id' => trim((string)$a['chat_id']),
                'user_id' => trim((string)($a['user_id'] ?? '')),
                'role' => $role];
        }
        set_setting('notify_allowed_chats', json_encode(array_values($clean), JSON_UNESCAPED_UNICODE));
    }

    /** @return array{ok, role?, reason?} */
    public static function check(string $chatId, string $userId = ''): array
    {
        foreach (self::allowed() as $a) {
            if ((string)$a['chat_id'] !== $chatId) continue;
            if ($a['user_id'] !== '' && $userId !== '' && (string)$a['user_id'] !== $userId) continue;
            return ['ok' => true, 'role' => $a['role']];
        }
        return ['ok' => false, 'reason' => 'unauthorized'];
    }

    public static function roleRank(string $role): int
    {
        if ($role === self::ADMIN) return 3;
        if ($role === self::DEVELOPER || $role === self::OPERATOR) return 2;
        return 1;
    }

    public static function can(string $role, string $required): bool
    {
        if ($required === 'DEVELOPER') return self::canDev($role);
        return self::roleRank($role) >= self::roleRank($required);
    }

    /** Dev (plan/code): DEVELOPER hoac ADMIN. OPERATOR khong du. */
    public static function canDev(string $role): bool
    {
        return $role === self::DEVELOPER || $role === self::ADMIN;
    }

    /** Approve/apply/rollback: mac dinh ADMIN (configurable). */
    public static function canApprove(string $role): bool
    {
        if (get_setting('ai_require_admin_apply', '1') !== '1') return self::canDev($role);
        return $role === self::ADMIN;
    }

    /** Rate limit 10/phut/chat. @return array{ok, retry_after?} */
    public static function rateCheck(string $chatId): array
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_rate_' . md5($chatId) . '.json';
        $now = microtime(true);
        $ts = [];
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) $ts = array_values(array_filter($j, fn($t) => ($now - (float)$t) < 60));
        }
        if (count($ts) >= self::RATE_PER_MIN) {
            return ['ok' => false, 'retry_after' => 60];
        }
        $ts[] = $now;
        @file_put_contents($f, json_encode($ts));
        return ['ok' => true];
    }

    public static function defaultRole(): string
    {
        $r = strtoupper(trim((string)get_setting('notify_default_role', self::VIEWER)));
        return in_array($r, self::ROLES, true) ? $r : self::VIEWER;
    }
}
