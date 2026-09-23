<?php
declare(strict_types=1);
/**
 * ReportScheduler - Bao cao dinh ky (daily/weekly) qua Telegram.
 * Chay trong notify worker moi tick (khong daemon rieng): den gio -> build + enqueue.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ReportManager.php';
require_once __DIR__ . '/NotificationQueue.php';
require_once __DIR__ . '/TelegramProvider.php';
require_once __DIR__ . '/SyncLogger.php';

class ReportScheduler
{
    /** @return string[] da gui gi (de worker log) */
    public static function tick(): array
    {
        $sent = [];
        try {
            if (get_setting('notify_telegram_enabled', '0') !== '1') return $sent;
            if (get_setting('notify_daily_enabled', '0') === '1') {
                $at = get_setting('notify_daily_time', '22:00');
                if (self::dueToday('notify_daily_last', $at)) {
                    $doc = ReportManager::buildDaily();
                    $nid = NotificationQueue::enqueueText($doc['title'], self::docBody($doc));
                    if ($nid) {
                        self::markDone('notify_daily_last');
                        $sent[] = 'daily:' . $nid;
                    }
                }
            }
            if (get_setting('notify_weekly_enabled', '0') === '1') {
                $day = (int)get_setting('notify_weekly_day', '1'); // 1=Mon
                $at = get_setting('notify_weekly_time', '08:00');
                if ((int)date('N') === $day && self::dueToday('notify_weekly_last', $at)) {
                    $doc = ReportManager::buildWeekly();
                    $nid = NotificationQueue::enqueueText($doc['title'], self::docBody($doc));
                    if ($nid) {
                        self::markDone('notify_weekly_last');
                        $sent[] = 'weekly:' . $nid;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        return $sent;
    }

    private static function dueToday(string $key, string $at): bool
    {
        $now = date('H:i');
        if ($now < substr($at, 0, 5)) return false;
        $last = get_setting($key, '');
        return $last !== date('Y-m-d');
    }

    private static function markDone(string $key): void
    {
        try {
            db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')
                ->execute([$key, date('Y-m-d')]);
        } catch (Throwable $e) {
        }
    }

    public static function docBody(array $doc): string
    {
        $text = TelegramProvider::formatDocument($doc);
        // enqueueText ghep title\nmessage; o day title da trong body -> tra body ngan
        $lines = explode("\n", $text, 2);
        return $lines[1] ?? '';
    }
}
