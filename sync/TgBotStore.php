<?php
declare(strict_types=1);
/**
 * TgBotStore - telegram_bot_connections + telegram_destinations (§21, §34-§37).
 * Multi-bot ready (DB), UI primary don gian. Token ENCRYPT at rest (DPAPI).
 * Frontend khong bao gio nhan raw token (§23, §43).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TgSecret.php';
require_once __DIR__ . '/SyncLogger.php';

class TgBotStore
{
    public const ST_DISCONNECTED = 'DISCONNECTED';
    public const ST_CONNECTING = 'CONNECTING';
    public const ST_CONNECTED = 'CONNECTED';
    public const ST_INVALID_TOKEN = 'INVALID_TOKEN';
    public const ST_ERROR = 'ERROR';
    public const ST_ARCHIVED = 'ARCHIVED';

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS telegram_bot_connections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL DEFAULT '',
                bot_id VARCHAR(64) NOT NULL DEFAULT '',
                bot_username VARCHAR(100) NOT NULL DEFAULT '',
                bot_first_name VARCHAR(120) NOT NULL DEFAULT '',
                token_encrypted TEXT NULL,
                token_preview VARCHAR(40) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'DISCONNECTED',
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                inbound_enabled TINYINT(1) NOT NULL DEFAULT 1,
                outbound_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                last_connected_at DATETIME NULL,
                last_disconnected_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                deleted_at DATETIME NULL,
                KEY idx_bot_primary (is_primary, deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS telegram_destinations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                connection_id INT NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(64) NULL,
                username VARCHAR(100) NULL,
                display_name VARCHAR(190) NULL,
                chat_type VARCHAR(20) NOT NULL DEFAULT 'private',
                role VARCHAR(15) NOT NULL DEFAULT 'ADMIN',
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                paired_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                UNIQUE KEY uq_dest_conn_chat (connection_id, chat_id),
                KEY idx_dest_primary (is_primary, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Health fields (§29): enabled/auto_connect/runtime/poll/inbound/error/reconnect
            $cols = [];
            foreach (db()->query('SHOW COLUMNS FROM telegram_bot_connections')->fetchAll() as $r) {
                $cols[(string)$r['Field']] = true;
            }
            $add = [
                'enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'auto_connect' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'credential_status' => "VARCHAR(20) NOT NULL DEFAULT 'VALID'",
                'primary_chat_id' => 'VARCHAR(64) NULL',
                'primary_user_id' => 'VARCHAR(64) NULL',
                'primary_username' => 'VARCHAR(100) NULL',
                'primary_display_name' => 'VARCHAR(190) NULL',
                'paired_at' => 'DATETIME NULL',
                'runtime_state' => 'VARCHAR(20) NULL',
                'last_update_id' => 'BIGINT NULL',
                'last_poll_at' => 'DATETIME NULL',
                'last_poll_success_at' => 'DATETIME NULL',
                'last_inbound_at' => 'DATETIME NULL',
                'last_outbound_at' => 'DATETIME NULL',
                'last_error_code' => 'VARCHAR(40) NULL',
                'last_error_at' => 'DATETIME NULL',
                'reconnect_count' => 'INT NOT NULL DEFAULT 0',
            ];
            foreach ($add as $col => $def) {
                if (empty($cols[$col])) {
                    try {
                        db()->exec("ALTER TABLE telegram_bot_connections ADD COLUMN $col $def");
                    } catch (Throwable $e) {
                    }
                }
            }
            // Raw update queue (§10-§12): bounded, dispatcher rieng error boundary
            db()->exec("CREATE TABLE IF NOT EXISTS tg_update_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                connection_id INT NOT NULL DEFAULT 0,
                update_id BIGINT NOT NULL,
                update_json MEDIUMTEXT NOT NULL,
                status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_conn_update (connection_id, update_id),
                KEY idx_queue_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    public static function preview(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        $pos = strpos($token, ':');
        if ($pos === false) return substr($token, 0, 3) . '••••••';
        return substr($token, 0, min(6, $pos)) . '••••••' . substr($token, -3);
    }

    /** @return array[] connections (khong raw token) */
    public static function list(bool $includeArchived = false): array
    {
        self::ensureTables();
        try {
            $w = $includeArchived ? '' : "WHERE deleted_at IS NULL";
            $rows = db()->query('SELECT id, name, bot_id, bot_username, bot_first_name,
                    token_preview, (token_encrypted IS NOT NULL AND token_encrypted<>\'\') AS has_token,
                    status, credential_status, is_primary, enabled, auto_connect,
                    inbound_enabled, outbound_enabled,
                    primary_chat_id, primary_user_id, primary_username, primary_display_name, paired_at,
                    created_at, updated_at, last_connected_at, last_disconnected_at,
                    last_poll_at, last_poll_success_at, last_inbound_at, last_outbound_at,
                    last_error, last_error_code, last_error_at, reconnect_count, deleted_at
                FROM telegram_bot_connections ' . $w . ' ORDER BY is_primary DESC, id ASC')->fetchAll();
            foreach ($rows as &$r) {
                $r['destinations'] = self::destinations((int)$r['id']);
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function get(int $id): ?array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM telegram_bot_connections WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function primary(): ?array
    {
        self::ensureTables();
        try {
            $r = db()->query("SELECT * FROM telegram_bot_connections
                WHERE is_primary=1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch();
            if ($r) return $r;
            $r = db()->query("SELECT * FROM telegram_bot_connections
                WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Giai ma token runtime (khong log, khong tra frontend). */
    public static function runtimeToken(?array $conn = null): string
    {
        try {
            $conn = $conn ?? self::primary();
            if (!$conn || empty($conn['token_encrypted'])) return '';
            $t = TgSecret::unprotect((string)$conn['token_encrypted']);
            return $t ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Luu token tu setup flow (one-field): validate getMe truoc, encrypt,
     * tao/merge connection (enabled + auto_connect ON), status CONNECTING.
     * KHONG plaintext settings (§3). Transaction: all-or-nothing (§30).
     * @return array{ok, error?, connection_id?, bot?, created?}
     */
    public static function storeSetupToken(string $token, string $name = 'Telegram Bot'): array
    {
        self::ensureTables();
        $token = trim($token);
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            return ['ok' => false, 'error' => 'Bot Token không đúng định dạng.'];
        }
        require_once __DIR__ . '/TelegramProvider.php';
        $me = TelegramProvider::getMeVia($token);
        if (empty($me['ok'])) {
            return ['ok' => false, 'error' => 'Bot Token không hợp lệ hoặc không thể kết nối Telegram.'];
        }
        $enc = TgSecret::protect($token);
        if ($enc === null) {
            return ['ok' => false, 'error' => 'Không mã hóa được token (DPAPI).'];
        }
        $botId = (string)($me['bot']['id'] ?? '');
        try {
            db()->beginTransaction();
            $st = db()->prepare('SELECT id FROM telegram_bot_connections WHERE bot_id=? AND deleted_at IS NULL LIMIT 1');
            $st->execute([$botId]);
            $ex = $st->fetchColumn();
            if ($ex) {
                db()->prepare('UPDATE telegram_bot_connections SET token_encrypted=?, token_preview=?,
                        bot_username=?, bot_first_name=?, credential_status=?, status=?,
                        enabled=1, auto_connect=1, updated_at=NOW() WHERE id=?')
                    ->execute([$enc, self::preview($token),
                        (string)($me['bot']['username'] ?? ''), (string)($me['bot']['first_name'] ?? ''),
                        'VALID', self::ST_CONNECTING, (int)$ex]);
                $cid = (int)$ex;
                $created = false;
            } else {
                $has = (int)db()->query('SELECT COUNT(*) FROM telegram_bot_connections WHERE deleted_at IS NULL')->fetchColumn();
                db()->prepare('INSERT INTO telegram_bot_connections (name, bot_id, bot_username, bot_first_name,
                        token_encrypted, token_preview, credential_status, status, is_primary,
                        enabled, auto_connect, inbound_enabled, outbound_enabled, created_at)
                    VALUES (?,?,?,?,?,?,?,?,1,1,1,1,1,NOW())')
                    ->execute([$name !== '' ? mb_substr($name, 0, 120) : 'Telegram Bot',
                        $botId, (string)($me['bot']['username'] ?? ''), (string)($me['bot']['first_name'] ?? ''),
                        $enc, self::preview($token), 'VALID', self::ST_CONNECTING, $has === 0 ? 1 : 0]);
                $cid = (int)db()->lastInsertId();
                $created = true;
                if ($has === 0) {
                    db()->prepare('UPDATE telegram_bot_connections SET is_primary=0 WHERE id<>?')->execute([$cid]);
                }
            }
            db()->commit();
            return ['ok' => true, 'connection_id' => $cid, 'bot' => $me['bot'], 'created' => $created];
        } catch (Throwable $e) {
            try {
                db()->rollBack();
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'error' => 'Không lưu được token.'];
        }
    }

    /**
     * Them bot: validate getMe truoc, chi luu khi valid (§24-§25).
     * @return array{ok, error?, connection?, bot?, different_bot?}
     */
    public static function add(string $name, string $token): array
    {
        self::ensureTables();
        $token = trim($token);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Telegram Bot';
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            return ['ok' => false, 'error' => 'Token không đúng định dạng.'];
        }
        require_once __DIR__ . '/TelegramProvider.php';
        $me = TelegramProvider::getMeVia($token);
        if (empty($me['ok'])) {
            return ['ok' => false, 'error' => 'Token không hợp lệ hoặc không kết nối được Telegram.'];
        }
        $enc = TgSecret::protect($token);
        if ($enc === null) {
            return ['ok' => false, 'error' => 'Không mã hóa được token (DPAPI).'];
        }
        try {
            $has = (int)db()->query('SELECT COUNT(*) FROM telegram_bot_connections WHERE deleted_at IS NULL')->fetchColumn();
            $st = db()->prepare('INSERT INTO telegram_bot_connections (name, bot_id, bot_username, bot_first_name,
                    token_encrypted, token_preview, credential_status, status, is_primary, inbound_enabled, outbound_enabled, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,1,1,NOW())');
            $st->execute([$name, (string)($me['bot']['id'] ?? ''), (string)($me['bot']['username'] ?? ''),
                (string)($me['bot']['first_name'] ?? ''), $enc, self::preview($token),
                'VALID', self::ST_DISCONNECTED, $has === 0 ? 1 : 0]);
            $id = (int)db()->lastInsertId();
            if ($has === 0) {
                db()->prepare('UPDATE telegram_bot_connections SET is_primary=0 WHERE id<>?')->execute([$id]);
            }
            return ['ok' => true, 'connection' => self::get($id), 'bot' => $me['bot']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Không lưu được Bot.'];
        }
    }

    /**
     * Thay token: validate truoc; invalid -> GIU cu (§26). Bot khac -> can confirm.
     * @return array{ok, error?, need_confirm?, old?, new?, connection?}
     */
    public static function replaceToken(int $id, string $token, bool $confirmed = false): array
    {
        self::ensureTables();
        $conn = self::get($id);
        if (!$conn || !empty($conn['deleted_at'])) return ['ok' => false, 'error' => 'Không thấy Bot.'];
        $token = trim($token);
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            return ['ok' => false, 'error' => 'Token không đúng định dạng.'];
        }
        require_once __DIR__ . '/TelegramProvider.php';
        $me = TelegramProvider::getMeVia($token);
        if (empty($me['ok'])) {
            return ['ok' => false, 'error' => 'Token mới không hợp lệ. Giữ nguyên token cũ.'];
        }
        $newBotId = (string)($me['bot']['id'] ?? '');
        if ($newBotId !== '' && (string)($conn['bot_id'] ?? '') !== '' && $newBotId !== (string)$conn['bot_id']) {
            if (!$confirmed) {
                return ['ok' => false, 'error' => 'different_bot', 'need_confirm' => true,
                    'old' => ['username' => $conn['bot_username']], 'new' => $me['bot']];
            }
        }
        $enc = TgSecret::protect($token);
        if ($enc === null) return ['ok' => false, 'error' => 'Không mã hóa được token (DPAPI).'];
        try {
            db()->prepare('UPDATE telegram_bot_connections SET token_encrypted=?, token_preview=?,
                    bot_id=?, bot_username=?, bot_first_name=?, credential_status=?, status=?, last_error=NULL, updated_at=NOW() WHERE id=?')
                ->execute([$enc, self::preview($token), $newBotId,
                    (string)($me['bot']['username'] ?? ''), (string)($me['bot']['first_name'] ?? ''),
                    'VALID', self::ST_DISCONNECTED, $id]);
            return ['ok' => true, 'connection' => self::get($id)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Không lưu được token.'];
        }
    }

    public static function rename(int $id, string $name): bool
    {
        try {
            self::ensureTables();
            $st = db()->prepare('UPDATE telegram_bot_connections SET name=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL');
            $st->execute([mb_substr(trim($name) !== '' ? trim($name) : 'Telegram Bot', 0, 120), $id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Dat primary (chi 1). */
    public static function setPrimary(int $id): bool
    {
        try {
            self::ensureTables();
            db()->prepare('UPDATE telegram_bot_connections SET is_primary=0 WHERE deleted_at IS NULL')->execute();
            $st = db()->prepare('UPDATE telegram_bot_connections SET is_primary=1 WHERE id=? AND deleted_at IS NULL');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Xoa bot (§31-§33): stop, xoa secret, ARCHIVED (giu history mac dinh).
     * $wipeHistory=true: xoa ca tg_messages lien quan (rieng, default OFF).
     */
    public static function remove(int $id, bool $wipeHistory = false): array
    {
        self::ensureTables();
        $conn = self::get($id);
        if (!$conn) return ['ok' => false, 'error' => 'Không thấy Bot.'];
        try {
            require_once __DIR__ . '/TelegramPollingCtl.php';
            // Stop worker neu dang chay bot nay (singleton hien tai)
            self::setStatus($id, self::ST_DISCONNECTED, 'removed');
            db()->prepare('UPDATE telegram_bot_connections SET token_encrypted=NULL,
                    status=?, deleted_at=NOW(), is_primary=0 WHERE id=?')
                ->execute([self::ST_ARCHIVED, $id]);
            // Chuyen primary cho bot khac neu con
            try {
                $nx = db()->query('SELECT id FROM telegram_bot_connections WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
                if ($nx) self::setPrimary((int)$nx);
            } catch (Throwable $e) {
            }
            if ($wipeHistory) {
                db()->prepare('DELETE FROM tg_messages WHERE connection_id=?')->execute([$id]);
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Không xóa được Bot.'];
        }
    }

    public static function setStatus(int $id, string $status, ?string $error = null): void
    {
        try {
            self::ensureTables();
            if ($error !== null) {
                db()->prepare('UPDATE telegram_bot_connections SET status=?, last_error=? WHERE id=?')
                    ->execute([$status, mb_substr($error, 0, 500), $id]);
            } else {
                db()->prepare('UPDATE telegram_bot_connections SET status=? WHERE id=?')->execute([$status, $id]);
            }
            if ($status === self::ST_CONNECTED) {
                db()->prepare('UPDATE telegram_bot_connections SET last_connected_at=NOW() WHERE id=?')->execute([$id]);
            }
            if ($status === self::ST_DISCONNECTED) {
                db()->prepare('UPDATE telegram_bot_connections SET last_disconnected_at=NOW() WHERE id=?')->execute([$id]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Credential status (§1, §20): chi 401 moi INVALID. */
    public static function setCredential(int $id, string $status): void
    {
        if (!in_array($status, ['VALID', 'INVALID'], true)) return;
        try {
            self::ensureTables();
            db()->prepare('UPDATE telegram_bot_connections SET credential_status=? WHERE id=?')
                ->execute([$status, $id]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Connect (§28): decrypt -> getMe -> polling -> CONNECTED. Khong can nhap lai token.
     * getMe fail (401/invalid): credential INVALID, giu row (§20, §48).
     * @return array{ok, error?}
     */
    public static function connect(int $id): array
    {
        self::ensureTables();
        $conn = self::get($id);
        if (!$conn || !empty($conn['deleted_at'])) return ['ok' => false, 'error' => 'Không thấy Bot.'];
        self::setStatus($id, self::ST_CONNECTING);
        $token = self::runtimeToken($conn);
        if ($token === '') {
            self::setStatus($id, self::ST_ERROR, 'Không giải mã được token.');
            return ['ok' => false, 'error' => 'Không giải mã được token.'];
        }
        require_once __DIR__ . '/TelegramProvider.php';
        $me = TelegramProvider::getMeRaw($token);
        if (empty($me['ok'])) {
            if (!empty($me['unauthorized'])) {
                // Chi 401 moi INVALID (§20). Mang -> ERROR, giu credential VALID.
                self::setCredential($id, 'INVALID');
                self::setStatus($id, self::ST_INVALID_TOKEN, 'Token không hợp lệ.');
                return ['ok' => false, 'error' => 'Token không hợp lệ (unauthorized).'];
            }
            self::setStatus($id, self::ST_ERROR, $me['error'] ?? 'Lỗi kết nối.');
            return ['ok' => false, 'error' => $me['error'] ?? 'Không kết nối được Telegram.'];
        }
        self::setCredential($id, 'VALID');
        try {
            db()->prepare('UPDATE telegram_bot_connections SET bot_id=?, bot_username=?, bot_first_name=?,
                    last_error=NULL, updated_at=NOW() WHERE id=?')
                ->execute([(string)($me['bot']['id'] ?? ''), (string)($me['bot']['username'] ?? ''),
                    (string)($me['bot']['first_name'] ?? ''), $id]);
        } catch (Throwable $e) {
        }
        self::setStatus($id, self::ST_CONNECTED);
        // Sync bot info ve legacy keys cho hien thi (khong token)
        try {
            require_once __DIR__ . '/TelegramConfig.php';
            TelegramConfig::set('bot_id', (string)($me['bot']['id'] ?? ''));
            TelegramConfig::set('bot_username', (string)($me['bot']['username'] ?? ''));
            TelegramConfig::set('bot_first_name', (string)($me['bot']['first_name'] ?? ''));
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/TelegramPollingCtl.php';
            TelegramPollingCtl::ensureRunning();
        } catch (Throwable $e) {
        }
        return ['ok' => true];
    }

    /**
     * Disconnect (§29-§30): stop polling (neu khong con bot CONNECTED), GIU
     * token/recipient/pairing/history/rules. "Ngat ket noi", khong revoke.
     */
    public static function disconnect(int $id): array
    {
        self::ensureTables();
        $conn = self::get($id);
        if (!$conn) return ['ok' => false, 'error' => 'Không thấy Bot.'];
        self::setStatus($id, self::ST_DISCONNECTED);
        try {
            $n = (int)db()->query("SELECT COUNT(*) FROM telegram_bot_connections
                WHERE status='CONNECTED' AND deleted_at IS NULL")->fetchColumn();
            if ($n === 0) {
                $self = getmypid();
                $ps = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \\"Name=\'php.exe\'\\"'
                    . ' | Where-Object { $_.CommandLine -like \'*telegram_polling.php*\' } '
                    . '| Where-Object { $_.ProcessId -ne ' . $self . ' } '
                    . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
                @shell_exec($ps);
            }
        } catch (Throwable $e) {
        }
        return ['ok' => true];
    }

    // ================= Destinations (§34) =================

    /** @return array[] */
    public static function destinations(int $connectionId): array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM telegram_destinations WHERE connection_id=? AND is_active=1 ORDER BY is_primary DESC, id');
            $st->execute([$connectionId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Upsert destination theo (connection, chat). Tra ve id. */
    public static function upsertDestination(int $connectionId, string $chatId, array $info = []): int
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT id FROM telegram_destinations WHERE connection_id=? AND chat_id=?');
            $st->execute([$connectionId, $chatId]);
            $ex = $st->fetchColumn();
            if ($ex) {
                db()->prepare('UPDATE telegram_destinations SET user_id=?, username=?, display_name=?,
                        chat_type=?, role=?, is_active=1, last_seen_at=NOW() WHERE id=?')
                    ->execute([$info['user_id'] ?? null, $info['username'] ?? null,
                        isset($info['display_name']) ? mb_substr((string)$info['display_name'], 0, 190) : null,
                        $info['chat_type'] ?? 'private', $info['role'] ?? 'ADMIN', (int)$ex]);
                return (int)$ex;
            }
            $cnt = self::countDest($connectionId);
            db()->prepare('INSERT INTO telegram_destinations (connection_id, chat_id, user_id, username,
                    display_name, chat_type, role, is_primary, is_active, paired_at, last_seen_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$connectionId, $chatId, $info['user_id'] ?? null, $info['username'] ?? null,
                    isset($info['display_name']) ? mb_substr((string)$info['display_name'], 0, 190) : null,
                    $info['chat_type'] ?? 'private', $info['role'] ?? 'ADMIN',
                    $cnt === 0 ? 1 : 0, 1, date('Y-m-d H:i:s')]);
            return (int)db()->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function countDest(int $connectionId): int
    {
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM telegram_destinations WHERE connection_id=?');
            $st->execute([$connectionId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function primaryDestination(int $connectionId): ?array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM telegram_destinations
                WHERE connection_id=? AND is_active=1 ORDER BY is_primary DESC, id LIMIT 1');
            $st->execute([$connectionId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ================= Migration (§44) =================

    /**
     * Migrate legacy token/settings -> connections+destinations. Idempotent.
     * Ca truong hop legacy token XUAT HIEN LAI sau migrate (preview khac) (§44).
     * @return array{ok, migrated, connection_id?}
     */
    public static function migrateLegacy(): array
    {
        self::ensureTables();
        try {
            require_once __DIR__ . '/TelegramConfig.php';
            $token = TelegramConfig::token();
            if ($token === '') return ['ok' => true, 'migrated' => false];
            $n = (int)db()->query('SELECT COUNT(*) FROM telegram_bot_connections')->fetchColumn();
            if ($n > 0) {
                // Legacy token xuat hien lai (user nhap theo flow cu)? So preview:
                // khac -> encrypt vao primary (validate truoc), giong -> chi xoa plaintext thua.
                $prim = self::primary();
                if ($prim && self::preview($token) !== (string)($prim['token_preview'] ?? '')) {
                    require_once __DIR__ . '/TelegramProvider.php';
                    $me = TelegramProvider::getMeVia($token);
                    if (!empty($me['ok'])) {
                        $enc = TgSecret::protect($token);
                        if ($enc !== null) {
                            db()->prepare('UPDATE telegram_bot_connections SET token_encrypted=?,
                                    token_preview=?, bot_id=?, bot_username=?, bot_first_name=?,
                                    credential_status=?, updated_at=NOW() WHERE id=?')
                                ->execute([$enc, self::preview($token),
                                    (string)($me['bot']['id'] ?? ''), (string)($me['bot']['username'] ?? ''),
                                    (string)($me['bot']['first_name'] ?? ''), 'VALID', (int)$prim['id']]);
                            foreach (['notify_bot_token', 'tg_token'] as $k) {
                                db()->prepare('DELETE FROM settings WHERE skey=?')->execute([$k]);
                            }
                            return ['ok' => true, 'migrated' => true, 'connection_id' => (int)$prim['id']];
                        }
                    }
                }
                return ['ok' => true, 'migrated' => false];
            }
            // Validate neu co mang (offline -> migrate muted voi ERROR status)
            require_once __DIR__ . '/TelegramProvider.php';
            $me = TelegramProvider::getMeVia($token);
            $bot = is_array($me['bot'] ?? null) ? $me['bot'] : [
                'id' => TelegramConfig::get('bot_id', ''),
                'username' => TelegramConfig::get('bot_username', ''),
                'first_name' => TelegramConfig::get('bot_first_name', '')];
            $enc = TgSecret::protect($token);
            if ($enc === null) return ['ok' => false, 'error' => 'DPAPI unavailable'];
            // Giu ket noi hien tai: neu truoc day inbound bat hoac worker dang chay -> CONNECTED
            $wasLive = get_setting('notify_inbound_enabled', '0') === '1';
            if (!$wasLive) {
                try {
                    require_once __DIR__ . '/TelegramPollingCtl.php';
                    $wasLive = TelegramPollingCtl::pid() !== null;
                } catch (Throwable $e) {
                }
            }
            $status = empty($me['ok']) ? self::ST_ERROR
                : ($wasLive ? self::ST_CONNECTED : self::ST_DISCONNECTED);
            db()->prepare('INSERT INTO telegram_bot_connections (name, bot_id, bot_username, bot_first_name,
                    token_encrypted, token_preview, credential_status, status, is_primary, inbound_enabled, outbound_enabled, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,1,1,NOW())')
                ->execute(['Telegram Bot', (string)($bot['id'] ?? ''), (string)($bot['username'] ?? ''),
                    (string)($bot['first_name'] ?? ''), $enc, self::preview($token),
                    empty($me['ok']) ? 'INVALID' : 'VALID', $status, 1]);
            $cid = (int)db()->lastInsertId();
            // Destination tu pairing hien tai (§45)
            $chatId = TelegramConfig::primaryChatId();
            if ($chatId !== '') {
                self::upsertDestination($cid, $chatId, [
                    'user_id' => TelegramConfig::primaryUserId(),
                    'username' => TelegramConfig::get('primary_username', ''),
                    'display_name' => TelegramConfig::get('primary_display_name', ''),
                    'chat_type' => TelegramConfig::get('primary_chat_type', 'private'),
                    'role' => TelegramConfig::get('role', 'ADMIN')]);
            }
            // Verify + xoa plaintext legacy (§44 buoc 7-8)
            $check = self::get($cid);
            if ($check && !empty($check['token_encrypted'])) {
                foreach (['notify_bot_token', 'tg_token'] as $k) {
                    db()->prepare('DELETE FROM settings WHERE skey=?')->execute([$k]);
                }
                try {
                    SyncLogger::info('telegram', '[Migrate] legacy token -> connection #' . $cid);
                } catch (Throwable $e) {
                }
                return ['ok' => true, 'migrated' => true, 'connection_id' => $cid];
            }
            return ['ok' => false, 'error' => 'verify_failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
