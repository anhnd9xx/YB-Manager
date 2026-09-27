<?php
declare(strict_types=1);
/**
 * TelegramMessageRouter - PIPELINE DUY NHAT cho moi Telegram update.
 * Worker (queue/dedup) -> Router (business routing) -> Orchestrator/Commands.
 * Plain text KHONG BAO GIO di CommandRouter. Trace [TG_ROUTE_*] de debug.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class TelegramMessageRouter
{
    public static function trace(int $uid, string $stage, string $detail = ''): void
    {
        try {
            SyncLogger::info('telegram', '[TG_ROUTE_' . $stage . '] update=' . $uid
                . ($detail !== '' ? ' ' . mb_substr($detail, 0, 200) : ''));
        } catch (Throwable $e) {
        }
    }

    /** Ghi quyet dinh route cuoi (diagnostics). */
    public static function recordDecision(int $uid, string $route, string $extra = ''): void
    {
        try {
            set_setting('tg_last_route', json_encode(['update_id' => $uid, 'route' => $route,
                'extra' => mb_substr($extra, 0, 120), 'at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
            if ($route === 'COMMAND_UNKNOWN') {
                set_setting('tg_unknown_count', (string)((int)get_setting('tg_unknown_count', '0') + 1));
            }
        } catch (Throwable $e) {
        }
    }

    public static function lastDecision(): array
    {
        try {
            $j = json_decode((string)get_setting('tg_last_route', ''), true);
            return is_array($j) ? $j : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Worker da xu ly: dedup, log, setup branch. Router chi business routing.
     * @return array{reply?} reply de worker gui (null = router tu gui hoac khong gui)
     */
    public static function route_message(string $chatId, string $userId, string $text,
        bool $isCmd, int $msgDate, int $uid, array $chk): array
    {
        self::trace($uid, 'RECEIVED', 'text="' . mb_substr($text, 0, 60) . '" cmd=' . ($isCmd ? '1' : '0'));
        self::trace($uid, 'STAGE', 'authorization=' . (!empty($chk['ok']) ? ($chk['role'] ?? '?') : 'DENIED'));
        require_once __DIR__ . '/AIControlOrchestrator.php';
        if ($isCmd) {
            // Stale command safety
            if ($msgDate > 0) {
                $maxAge = max(60, (int)get_setting('tg_command_max_age', '180'));
                if ((time() - $msgDate) > $maxAge) {
                    require_once __DIR__ . '/ConversationService.php';
                    ConversationService::log(ConversationService::OUT, $chatId,
                        '⌛ Lệnh đã hết hạn, vui lòng gửi lại.', ['type' => ConversationService::T_COMMAND]);
                    require_once __DIR__ . '/TelegramGateway.php';
                    TelegramGateway::sendMessage($chatId, '⌛ Lệnh đã hết hạn, vui lòng gửi lại.');
                    self::trace($uid, 'RESULT', 'COMMAND_EXPIRED');
                    self::recordDecision($uid, 'COMMAND_EXPIRED');
                    return ['reply' => null];
                }
            }
            self::trace($uid, 'STAGE', 'slash_command');
            $reply = AIControlOrchestrator::handle_command($text, $chatId, $userId);
            $t = (string)($reply['text'] ?? '');
            if (str_contains($t, 'Không có lệnh /')) {
                self::trace($uid, 'RESULT', 'COMMAND_UNKNOWN');
                self::recordDecision($uid, 'COMMAND_UNKNOWN', $text);
            } else {
                self::trace($uid, 'RESULT', 'COMMAND');
                self::recordDecision($uid, 'COMMAND');
            }
            return ['reply' => $reply];
        }
        if (!empty($chk['ok'])) {
            self::trace($uid, 'STAGE', 'message_router');
            $r = AIControlOrchestrator::handle_plain_text($chatId, $userId,
                (string)($chk['role'] ?? 'VIEWER'), $text);
            // Orchestrator da log intent ben trong; ghi lai route tom tat
            self::recordDecision($uid, 'AI_CONTROL');
            return ['reply' => $r['reply'] ?? null];
        }
        // Unauthorized plain (khong setup): audit + im lang (khong "Khong hieu lenh")
        try {
            require_once __DIR__ . '/CommandRouter.php';
            CommandRouter::route($text, 'TELEGRAM', $chatId, $userId);
        } catch (Throwable $e) {
        }
        self::trace($uid, 'RESULT', 'UNAUTHORIZED_SILENT');
        self::recordDecision($uid, 'UNAUTHORIZED_SILENT');
        return ['reply' => null];
    }

    /** @return bool true neu da xu ly */
    public static function route_callback(string $chatId, string $userId, string $role,
        string $data, int $uid): bool
    {
        self::trace($uid, 'RECEIVED', 'callback len=' . strlen($data));
        self::trace($uid, 'STAGE', 'callback_router');
        require_once __DIR__ . '/AIControlOrchestrator.php';
        if (AIControlOrchestrator::handle_callback($chatId, $userId, $role, $data)) {
            self::trace($uid, 'RESULT', 'CALLBACK_AI');
            self::recordDecision($uid, 'CALLBACK_AI');
            return true;
        }
        self::trace($uid, 'RESULT', 'CALLBACK_LEGACY');
        self::recordDecision($uid, 'CALLBACK_LEGACY');
        return false;
    }
}
