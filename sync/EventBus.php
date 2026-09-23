<?php
declare(strict_types=1);
/**
 * EventBus - Module chi emit event, KHONG biet Telegram ton tai (§6).
 * Subscriber duy nhat hien tai: NotificationManager (goi ngay trong-process,
 * chi route/enqueue — khong block: send that di qua worker).
 */
require_once __DIR__ . '/AppEvent.php';
require_once __DIR__ . '/SyncLogger.php';

class EventBus
{
    /** @var callable[] */
    private static array $subscribers = [];
    private static bool $booted = false;

    public static function subscribe(callable $fn): void
    {
        self::$subscribers[] = $fn;
    }

    private static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;
        try {
            require_once __DIR__ . '/NotificationManager.php';
            self::subscribe([NotificationManager::class, 'onEvent']);
        } catch (Throwable $e) {
        }
    }

    /**
     * Emit + persist + dispatch subscribers. Khong bao gio nem exception
     * (delivery failure la state rieng, task da xong la xong §48).
     */
    public static function emit(string $type, string $module, string $severity,
        string $title, string $message = '', array $opts = []): array
    {
        self::boot();
        try {
            $ev = AppEvent::create($type, $module, $severity, $title, $message, $opts);
        } catch (Throwable $e) {
            return [];
        }
        foreach (self::$subscribers as $fn) {
            try {
                $fn($ev);
            } catch (Throwable $e) {
                try {
                    SyncLogger::warn('eventbus', 'Subscriber loi: ' . mb_substr($e->getMessage(), 0, 150));
                } catch (Throwable $e2) {
                }
            }
        }
        return $ev;
    }
}
