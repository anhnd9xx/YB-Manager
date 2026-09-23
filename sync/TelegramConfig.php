<?php
declare(strict_types=1);
/**
 * TelegramConfig - Cau hinh Telegram tap trung, one-field setup (§26-§28).
 * Bot Token la secret: khong bao gio tra full qua API thuong, chi masked.
 * Migration: notify_chat_id cu -> primary_chat_id (khong bat pair lai).
 * Chat ID khong cho edit truc tiep o normal UI (§14).
 */
require_once __DIR__ . '/../config.php';

class TelegramConfig
{
    public static function get(string $key, string $default = ''): string
    {
        return get_setting('tg_' . $key, get_setting($key, $default));
    }

    public static function set(string $key, string $value): void
    {
        set_setting('tg_' . $key, $value);
    }

    /** @return array public view (token masked, chat masked) */
    public static function publicView(): array
    {
        $token = self::token();
        $chat = self::get('primary_chat_id', get_setting('notify_chat_id', ''));
        return [
            'connected' => self::isConnected(),
            'enabled' => self::get('enabled', '0') === '1',
            'inbound_enabled' => self::get('inbound_enabled', '0') === '1',
            'outbound_enabled' => self::get('outbound_enabled', '1') === '1',
            'has_token' => $token !== '',
            'token_masked' => self::maskToken($token),
            'bot_username' => self::get('bot_username', ''),
            'bot_first_name' => self::get('bot_first_name', ''),
            'bot_id' => self::get('bot_id', ''),
            'display_name' => self::get('primary_display_name', ''),
            'username' => self::get('primary_username', ''),
            'chat_type' => self::get('primary_chat_type', ''),
            'chat_masked' => self::maskChat($chat),
            'role' => self::get('role', 'ADMIN'),
            'paired_at' => self::get('paired_at', ''),
            'connection_status' => self::get('connection_status', ''),
            'last_error' => self::get('last_error', ''),
        ];
    }

    public static function token(): string
    {
        $t = get_setting('notify_bot_token', '');
        if ($t === '') $t = self::get('token', '');
        return trim($t);
    }

    public static function saveToken(string $token): void
    {
        set_setting('notify_bot_token', trim($token));
    }

    public static function clearToken(): void
    {
        set_setting('notify_bot_token', '');
        set_setting('tg_token', '');
    }

    public static function isConnected(): bool
    {
        return self::token() !== '' && self::primaryChatId() !== ''
            && self::get('enabled', '0') === '1';
    }

    public static function primaryChatId(): string
    {
        $c = self::get('primary_chat_id', '');
        if ($c === '') {
            // Migration §28: chat cu -> primary (khong pair lai)
            $c = trim((string)get_setting('notify_chat_id', ''));
            if ($c !== '') {
                self::set('primary_chat_id', $c);
            }
        }
        return $c;
    }

    public static function primaryUserId(): string
    {
        return self::get('primary_user_id', '');
    }

    /** @return array{chat_id, user_id, display_name} */
    public static function primaryDestination(): array
    {
        return ['chat_id' => self::primaryChatId(),
            'user_id' => self::primaryUserId(),
            'display_name' => self::get('primary_display_name', '')];
    }

    public static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        // 123456789:ABC...XYZ
        $pos = strpos($token, ':');
        if ($pos === false) return substr($token, 0, 3) . '••••••••';
        return substr($token, 0, min(9, $pos)) . ':ABC••••••••' . substr($token, -3);
    }

    public static function maskChat(string $chatId): string
    {
        $chatId = trim($chatId);
        if ($chatId === '') return '';
        $len = strlen($chatId);
        if ($len <= 4) return '••••';
        return '••••••' . substr($chatId, -4);
    }

    public static function setStatus(string $status, string $error = ''): void
    {
        self::set('connection_status', $status);
        self::set('last_error', $error);
    }
}
