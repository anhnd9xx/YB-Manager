<?php
declare(strict_types=1);
/**
 * PairingService - Ghep noi chat (§51-§52). UI tao ma 6 so, TTL 5 phut,
 * single-use. /pair <ma> -> add allowed chat voi default role.
 */
require_once __DIR__ . '/../config.php';

class PairingService
{
    public const TTL_SEC = 300;

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS tg_pairing (
                code VARCHAR(10) PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array{code, expires_at} */
    public static function generate(): array
    {
        self::ensureTable();
        $code = (string)mt_rand(100000, 999999);
        $exp = date('Y-m-d H:i:s', time() + self::TTL_SEC);
        try {
            db()->prepare('INSERT INTO tg_pairing (code, expires_at, used) VALUES (?,?,0)
                ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at), used=0')->execute([$code, $exp]);
        } catch (Throwable $e) {
        }
        return ['code' => $code, 'expires_at' => $exp];
    }

    /** @return array{ok, error?} */
    public static function redeem(string $code, string $chatId, string $userId): array
    {
        self::ensureTable();
        $code = trim($code);
        try {
            $st = db()->prepare("SELECT * FROM tg_pairing WHERE code=? AND used=0");
            $st->execute([$code]);
            $r = $st->fetch();
            if (!$r) return ['ok' => false, 'error' => 'invalid_code'];
            if (strtotime((string)$r['expires_at']) < time()) {
                return ['ok' => false, 'error' => 'expired'];
            }
            db()->prepare('UPDATE tg_pairing SET used=1 WHERE code=?')->execute([$code]);
            require_once __DIR__ . '/PermissionService.php';
            $list = PermissionService::allowed();
            foreach ($list as $a) {
                if ((string)$a['chat_id'] === $chatId) {
                    return ['ok' => true, 'role' => $a['role']]; // da co
                }
            }
            $list[] = ['chat_id' => $chatId, 'user_id' => $userId, 'role' => PermissionService::defaultRole()];
            PermissionService::saveAllowed($list);
            return ['ok' => true, 'role' => PermissionService::defaultRole()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }
}
