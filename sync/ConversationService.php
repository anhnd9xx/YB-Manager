<?php
declare(strict_types=1);
/**
 * ConversationService - Lich su chat 2 chieu + metrics (§24, §48).
 * Persist message INBOUND/OUTBOUND (khong secret). UI timeline + polling worker doc.
 */
require_once __DIR__ . '/../config.php';

class ConversationService
{
    public const IN = 'INBOUND';
    public const OUT = 'OUTBOUND';

    // Message types (§H)
    public const T_TEXT = 'TEXT';
    public const T_COMMAND = 'COMMAND';
    public const T_SYSTEM = 'SYSTEM';
    public const T_JOB = 'JOB';
    public const T_ALERT = 'ALERT';
    public const T_REPORT = 'REPORT';

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS tg_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id VARCHAR(64) NULL,
                direction VARCHAR(10) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
                chat_id VARCHAR(64) NOT NULL DEFAULT '',
                user_id VARCHAR(64) NULL,
                text TEXT NULL,
                command_id VARCHAR(64) NULL,
                job_id VARCHAR(32) NULL,
                status VARCHAR(20) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_tg_chat (chat_id, id),
                KEY idx_tg_cmd (command_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Migration: type/status/sent_at/error (§H-§I)
            $cols = [];
            foreach (db()->query('SHOW COLUMNS FROM tg_messages')->fetchAll() as $r) {
                $cols[(string)$r['Field']] = true;
            }
            $add = [
                'msg_type' => "VARCHAR(15) NOT NULL DEFAULT 'TEXT'",
                'telegram_message_id' => 'VARCHAR(64) NULL',
                'sent_at' => 'DATETIME NULL',
                'error' => 'VARCHAR(500) NULL',
                // Idempotency (§4-§6): client id duy nhat + update id + composite inbound
                'client_message_id' => 'VARCHAR(64) NULL',
                'telegram_update_id' => 'BIGINT NULL',
                'connection_id' => 'INT NULL',
                'destination_id' => 'INT NULL',
            ];
            foreach ($add as $col => $def) {
                if (empty($cols[$col])) {
                    try {
                        db()->exec("ALTER TABLE tg_messages ADD COLUMN $col $def");
                    } catch (Throwable $e) {
                    }
                }
            }
            // Unique constraints (idempotent)
            foreach ([
                'uq_client_msg' => 'UNIQUE KEY uq_client_msg (client_message_id)',
                'uq_inbound_tg' => 'UNIQUE KEY uq_inbound_tg (chat_id, telegram_message_id, direction)',
            ] as $name => $ddl) {
                try {
                    $exists = db()->query("SHOW INDEX FROM tg_messages WHERE Key_name='$name'")->fetch();
                    if (!$exists) {
                        try {
                            db()->exec("ALTER TABLE tg_messages ADD $ddl");
                        } catch (Throwable $e) {
                        }
                    }
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    public static function log(string $direction, string $chatId, string $text,
        array $opts = []): void
    {
        self::ensureTable();
        // Khong luu secret: cat bot token neu lot vao text
        $token = trim((string)get_setting('notify_bot_token', ''));
        if ($token !== '' && str_contains($text, $token)) {
            $text = str_replace($token, '[REDACTED]', $text);
        }
        // Upsert idempotency (§7): client_id hoac inbound (chat + tg_msg_id) da co -> khong insert
        try {
            $cid = isset($opts['client_message_id']) ? trim((string)$opts['client_message_id']) : '';
            if ($cid !== '') {
                $st = db()->prepare('SELECT id FROM tg_messages WHERE client_message_id=? LIMIT 1');
                $st->execute([$cid]);
                if ($st->fetch()) {
                    require_once __DIR__ . '/TelegramCounters.php';
                    TelegramCounters::bump('ui_dedup');
                    return;
                }
            }
            $tgmid = isset($opts['telegram_message_id']) ? trim((string)$opts['telegram_message_id']) : '';
            if ($direction === 'INBOUND' && $tgmid !== '') {
                $st = db()->prepare("SELECT id FROM tg_messages WHERE chat_id=? AND telegram_message_id=? AND direction='INBOUND' LIMIT 1");
                $st->execute([$chatId, $tgmid]);
                if ($st->fetch()) {
                    require_once __DIR__ . '/TelegramCounters.php';
                    TelegramCounters::bump('in_dedup');
                    return;
                }
            }
        } catch (Throwable $e) {
        }
        try {
            db()->prepare('INSERT INTO tg_messages (message_id, direction, source, chat_id, user_id,
                    text, command_id, job_id, status, msg_type, telegram_message_id, sent_at, error,
                    client_message_id, telegram_update_id, connection_id, destination_id, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$opts['message_id'] ?? null, $direction, $opts['source'] ?? 'TELEGRAM',
                    $chatId, $opts['user_id'] ?? null, mb_substr($text, 0, 4000),
                    $opts['command_id'] ?? null, $opts['job_id'] ?? null, $opts['status'] ?? null,
                    $opts['type'] ?? self::T_TEXT, $opts['telegram_message_id'] ?? null,
                    $opts['sent_at'] ?? null, isset($opts['error']) ? mb_substr((string)$opts['error'], 0, 500) : null,
                    $opts['client_message_id'] ?? null,
                    isset($opts['telegram_update_id']) ? (int)$opts['telegram_update_id'] : null,
                    isset($opts['connection_id']) ? (int)$opts['connection_id'] : null,
                    isset($opts['destination_id']) ? (int)$opts['destination_id'] : null]);
            require_once __DIR__ . '/TelegramCounters.php';
            TelegramCounters::bump($direction === 'INBOUND' ? 'in_recv' : 'out_req');
            db()->prepare('DELETE FROM tg_messages WHERE id NOT IN
                (SELECT id FROM (SELECT id FROM tg_messages ORDER BY id DESC LIMIT 2000) t)')->execute();
            // Retention (§AP, default 30d)
            $days = max(1, min(3650, (int)get_setting('tg_retention_days', '30')));
            db()->prepare('DELETE FROM tg_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)')->execute();
        } catch (Throwable $e) {
        }
    }

    /** Cap nhat outbound status (QUEUED/SENDING/SENT/FAILED) (§I). @return row id? */
    public static function logOutbound(string $chatId, string $text, array $opts = []): int
    {
        self::ensureTable();
        $token = trim((string)get_setting('notify_bot_token', ''));
        if ($token !== '' && str_contains($text, $token)) {
            $text = str_replace($token, '[REDACTED]', $text);
        }
        // Upsert theo client_message_id: da co -> tra ve id cu (khong insert moi) (§7)
        try {
            $cid = isset($opts['client_message_id']) ? trim((string)$opts['client_message_id']) : '';
            if ($cid !== '') {
                $st = db()->prepare('SELECT id FROM tg_messages WHERE client_message_id=? LIMIT 1');
                $st->execute([$cid]);
                $ex = $st->fetchColumn();
                if ($ex) {
                    require_once __DIR__ . '/TelegramCounters.php';
                    TelegramCounters::bump('ui_dedup');
                    return (int)$ex;
                }
            }
        } catch (Throwable $e) {
        }
        try {
            db()->prepare('INSERT INTO tg_messages (direction, source, chat_id, user_id, text,
                    command_id, job_id, status, msg_type, client_message_id, connection_id, destination_id, created_at)
                VALUES (\'OUTBOUND\',?,?,?,?,?,?,?,\'TEXT\',?,?,?,NOW())')
                ->execute([$opts['source'] ?? 'UI', $chatId, $opts['user_id'] ?? null,
                    mb_substr($text, 0, 4000), $opts['command_id'] ?? null, $opts['job_id'] ?? null,
                    $opts['status'] ?? 'QUEUED', $opts['client_message_id'] ?? null,
                    isset($opts['connection_id']) ? (int)$opts['connection_id'] : null,
                    isset($opts['destination_id']) ? (int)$opts['destination_id'] : null]);
            $id = (int)db()->lastInsertId();
            try {
                require_once __DIR__ . '/TelegramCounters.php';
                TelegramCounters::bump('out_req');
            } catch (Throwable $e) {
            }
            return $id;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function setStatus(int $id, string $status, ?string $error = null, ?string $tgMsgId = null): void
    {
        try {
            self::ensureTable();
            $sets = 'status=?';
            $params = [$status];
            if ($status === 'SENT') {
                $sets .= ', sent_at=NOW()';
            }
            if ($error !== null) {
                $sets .= ', error=?';
                $params[] = mb_substr($error, 0, 500);
            }
            if ($tgMsgId !== null) {
                $sets .= ', telegram_message_id=?';
                $params[] = $tgMsgId;
            }
            $params[] = $id;
            db()->prepare("UPDATE tg_messages SET $sets WHERE id=?")->execute($params);
        } catch (Throwable $e) {
        }
    }

    public static function recent(int $limit = 100): array
    {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        try {
            return db()->query('SELECT * FROM tg_messages ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Last message time theo direction (connection card). */
    public static function lastAt(string $direction): ?string
    {
        self::ensureTable();
        try {
            $st = db()->prepare("SELECT created_at FROM tg_messages WHERE direction=? ORDER BY id DESC LIMIT 1");
            $st->execute([$direction]);
            $r = $st->fetchColumn();
            return $r ? (string)$r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Pagination chat (§AO): 50 moi nhat + older theo cursor. */    public static function page(int $limit = 50, int $beforeId = 0, string $type = 'all'): array
    {
        self::ensureTable();
        $limit = max(1, min(100, $limit));
        $w = '';
        $params = [];
        if ($beforeId > 0) {
            $w .= ' AND id < ?';
            $params[] = $beforeId;
        }
        if (in_array($type, ['TEXT', 'COMMAND', 'JOB', 'ALERT', 'REPORT', 'SYSTEM'], true)) {
            if ($type === 'TEXT') {
                // Chat filter: TEXT + COMMAND? Spec filter: Chat/Command rieng -> Chat = TEXT
                $w .= " AND msg_type='TEXT'";
            } else {
                $w .= ' AND msg_type=?';
                $params[] = $type;
            }
        }
        try {
            $st = db()->prepare('SELECT * FROM tg_messages WHERE 1=1' . $w . ' ORDER BY id DESC LIMIT ' . $limit);
            $st->execute($params);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function metrics(): array
    {
        self::ensureTable();
        $out = ['authorized' => 0, 'commands_today' => 0, 'running_jobs' => 0, 'bot' => 'Offline'];
        try {
            $chats = get_setting('notify_allowed_chats', '');
            $j = json_decode($chats, true);
            $out['authorized'] = is_array($j) ? count($j) : 0;
            $out['commands_today'] = (int)db()->query("SELECT COUNT(*) FROM app_commands
                WHERE DATE(requested_at)=CURDATE()")->fetchColumn();
            $out['running_jobs'] = (int)db()->query("SELECT COUNT(*) FROM app_jobs
                WHERE status IN ('QUEUED','RUNNING')")->fetchColumn();
            require_once __DIR__ . '/TelegramGateway.php';
            $gw = TelegramGateway::connectionState();
            $cfg = TelegramProvider::configured();
            $out['bot'] = !$cfg['ok'] ? 'Chưa cấu hình' : ($gw['listening'] ? 'Online' : 'Offline');
            $out['polling'] = $gw;
            require_once __DIR__ . '/TelegramGateway.php';
            $out['inbound'] = TelegramGateway::inboundEnabled() ? 'Bật' : 'Tắt';
        } catch (Throwable $e) {
        }
        return $out;
    }
}
