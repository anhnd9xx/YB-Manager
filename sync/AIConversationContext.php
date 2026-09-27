<?php
declare(strict_types=1);
/**
 * AIConversationContext - Context hoi thoai per Telegram destination/user.
 * Luu settings (persist restart). Thay the ad-hoc keys roi rac (van fallback).
 */
require_once __DIR__ . '/../config.php';

class AIConversationContext
{
    public static function key(string $chatId, string $userId = ''): string
    {
        return 'ai_conv_' . md5($chatId . '|' . $userId);
    }

    /** @return array context day du (defaults) */
    public static function get(string $chatId, string $userId = ''): array
    {
        $def = ['destination_id' => $chatId, 'active_project_id' => 1,
            'active_dev_job_id' => '', 'last_job_id' => '', 'last_diagnosis_id' => '',
            'last_entity_type' => '', 'last_entity_id' => '', 'last_intent' => '',
            'summary' => '', 'updated_at' => ''];
        try {
            $j = json_decode((string)get_setting(self::key($chatId, $userId), ''), true);
            if (is_array($j)) return array_merge($def, $j);
        } catch (Throwable $e) {
        }
        // Fallback keys cu (1 lan)
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $oldJob = DevJobManager::activeForChat($chatId);
            if ($oldJob !== '') $def['active_dev_job_id'] = $oldJob;
            $oldDiag = (string)get_setting('ai_last_diag_' . md5($chatId), '');
            if ($oldDiag !== '') $def['last_diagnosis_id'] = $oldDiag;
        } catch (Throwable $e) {
        }
        return $def;
    }

    public static function patch(string $chatId, string $userId, array $patch): array
    {
        $c = self::get($chatId, $userId);
        foreach ($patch as $k => $v) {
            if (array_key_exists($k, $c)) $c[$k] = $v;
        }
        $c['updated_at'] = date('Y-m-d H:i:s');
        set_setting(self::key($chatId, $userId), json_encode($c, JSON_UNESCAPED_UNICODE));
        return $c;
    }

    /** Ghi nhan intent+entity sau moi luot (de "kiem tra lai di" hieu context). */
    public static function touch(string $chatId, string $userId, string $intent, array $entity = []): array
    {
        $p = ['last_intent' => $intent];
        if (!empty($entity['type']) && ($entity['type'] ?? '') !== 'NONE') {
            $p['last_entity_type'] = (string)$entity['type'];
            $p['last_entity_id'] = (string)($entity['id'] ?? $entity['name'] ?? $entity['query'] ?? '');
        }
        return self::patch($chatId, $userId, $p);
    }

    public static function clear(string $chatId, string $userId = ''): void
    {
        set_setting(self::key($chatId, $userId), '');
        try {
            require_once __DIR__ . '/DevJobManager.php';
            DevJobManager::clearActiveForChat($chatId);
        } catch (Throwable $e) {
        }
    }

    /** Noi lai active dev job cho DevJobManager (tuong thich nguoc). */
    public static function syncDevJob(string $chatId, string $code): void
    {
        self::patch($chatId, '', ['active_dev_job_id' => $code]);
        try {
            require_once __DIR__ . '/DevJobManager.php';
            DevJobManager::setActiveForChat($chatId, $code);
        } catch (Throwable $e) {
        }
    }
}
