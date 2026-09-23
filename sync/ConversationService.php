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
        try {
            db()->prepare('INSERT INTO tg_messages (message_id, direction, source, chat_id, user_id,
                    text, command_id, job_id, status, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$opts['message_id'] ?? null, $direction, $opts['source'] ?? 'TELEGRAM',
                    $chatId, $opts['user_id'] ?? null, mb_substr($text, 0, 4000),
                    $opts['command_id'] ?? null, $opts['job_id'] ?? null, $opts['status'] ?? null]);
            db()->prepare('DELETE FROM tg_messages WHERE id NOT IN
                (SELECT id FROM (SELECT id FROM tg_messages ORDER BY id DESC LIMIT 2000) t)')->execute();
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
