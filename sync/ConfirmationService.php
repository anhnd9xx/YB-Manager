<?php
declare(strict_types=1);
/**
 * ConfirmationService - Xac nhan hanh dong rui ro (§21-§22).
 * confirmation_id ngan; callback confirm:<id>/cancel:<id> (khong encode raw command).
 * Expire 60s. Sweeper xoa het han.
 */
require_once __DIR__ . '/../config.php';

class ConfirmationService
{
    public const TTL_SEC = 60;

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS tg_confirmations (
                confirmation_id VARCHAR(32) PRIMARY KEY,
                command_id VARCHAR(64) NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(64) NULL,
                summary VARCHAR(500) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                status VARCHAR(15) NOT NULL DEFAULT 'PENDING',
                KEY idx_conf_cmd (command_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array{id, expires_at} */
    public static function create(string $commandId, string $chatId, string $userId, string $summary): array
    {
        self::ensureTable();
        $id = 'cf_' . substr(md5($commandId . microtime(true)), 0, 12);
        $exp = date('Y-m-d H:i:s', time() + self::TTL_SEC);
        try {
            db()->prepare('INSERT INTO tg_confirmations (confirmation_id, command_id, chat_id, user_id, summary, expires_at, status)
                VALUES (?,?,?,?,?,?,\'PENDING\')')
                ->execute([$id, $commandId, $chatId, $userId, mb_substr($summary, 0, 500), $exp]);
        } catch (Throwable $e) {
        }
        return ['id' => $id, 'expires_at' => $exp];
    }

    /** @return array|null row neu PENDING + con han */
    public static function get(string $id): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare("SELECT * FROM tg_confirmations WHERE confirmation_id=? AND status='PENDING'");
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) return null;
            if (strtotime((string)$r['expires_at']) < time()) {
                self::settle($id, 'EXPIRED');
                return null;
            }
            return $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function settle(string $id, string $status): void
    {
        try {
            self::ensureTable();
            db()->prepare("UPDATE tg_confirmations SET status=? WHERE confirmation_id=? AND status='PENDING'")
                ->execute([$status, $id]);
        } catch (Throwable $e) {
        }
    }

    public static function sweep(): int
    {
        try {
            self::ensureTable();
            $st = db()->prepare("UPDATE tg_confirmations SET status='EXPIRED'
                WHERE status='PENDING' AND expires_at < NOW()");
            $st->execute();
            return (int)$st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
