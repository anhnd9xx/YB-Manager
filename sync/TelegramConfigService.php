<?php
declare(strict_types=1);
/**
 * TelegramConfigService - Facade cau hinh Telegram DUY NHAT (§3, §15).
 * Tat ca component (Gateway/Provider/ChatTest/Notify) di qua service nay,
 * KHONG query settings/DB truc tiep. Single source of truth.
 */
require_once __DIR__ . '/TelegramConfig.php';

class TelegramConfigService
{
    public static function get_bot_token(): string
    {
        return TelegramConfig::token();
    }

    public static function get_bot_info(): array
    {
        return ['id' => TelegramConfig::get('bot_id', ''),
            'username' => TelegramConfig::get('bot_username', ''),
            'first_name' => TelegramConfig::get('bot_first_name', '')];
    }

    public static function get_primary_chat(): array
    {
        return TelegramConfig::primaryDestination() + ['source' => 'primary'];
    }

    public static function get_pending_chat(): ?array
    {
        $eff = TelegramConfig::effectiveTestChat();
        if ($eff !== null && $eff['source'] === 'candidate') return $eff;
        return null;
    }

    public static function get_effective_test_chat(): ?array
    {
        return TelegramConfig::effectiveTestChat();
    }

    public static function is_paired(): bool
    {
        return TelegramConfig::pairingStatus() === 'PAIRED';
    }

    public static function is_gateway_online(): bool
    {
        return TelegramConfig::transportStatus() === 'ONLINE';
    }

    public static function transport_status(): string
    {
        return TelegramConfig::transportStatus();
    }

    public static function pairing_status(): string
    {
        return TelegramConfig::pairingStatus();
    }

    public static function save_pairing(string $chatId, string $userId, string $username,
        string $displayName, string $confirmedBy = 'telegram'): void
    {
        TelegramConfig::set('primary_chat_id', $chatId);
        TelegramConfig::set('primary_user_id', $userId);
        TelegramConfig::set('primary_username', $username);
        TelegramConfig::set('primary_display_name', $displayName);
        TelegramConfig::set('primary_chat_type', 'private');
        TelegramConfig::set('role', 'ADMIN');
        TelegramConfig::set('paired_at', date('Y-m-d H:i:s'));
        TelegramConfig::set('enabled', '1');
        TelegramConfig::set('inbound_enabled', '1');
        TelegramConfig::set('outbound_enabled', '1');
        TelegramConfig::setStatus('CONNECTED');
        require_once __DIR__ . '/PermissionService.php';
        $list = PermissionService::allowed();
        $kept = [];
        foreach ($list as $a) {
            if (($a['role'] ?? '') === 'ADMIN' && (string)$a['chat_id'] !== $chatId) continue;
            $kept[] = $a;
        }
        $kept[] = ['chat_id' => $chatId, 'user_id' => $userId, 'role' => 'ADMIN'];
        PermissionService::saveAllowed($kept);
        // Link pairing -> destination cua primary connection (§45)
        try {
            require_once __DIR__ . '/TgBotStore.php';
            $conn = TgBotStore::primary();
            if ($conn) {
                TgBotStore::upsertDestination((int)$conn['id'], $chatId, [
                    'user_id' => $userId, 'username' => $username,
                    'display_name' => $displayName, 'chat_type' => 'private', 'role' => 'ADMIN']);
                TgBotStore::setStatus((int)$conn['id'], TgBotStore::ST_CONNECTED);
            }
        } catch (Throwable $e) {
        }
        try {
            SyncLogger::info('telegram', '[Pairing] paired chat=' . $chatId
                . ' user=' . $userId . ' via=' . $confirmedBy);
        } catch (Throwable $e) {
        }
    }
}
