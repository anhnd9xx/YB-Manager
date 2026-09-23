<?php
declare(strict_types=1);
/**
 * bin/notify_worker.php - Telegram worker (1 tien trinh duy nhat).
 * Claim PENDING -> send (rate limit 1.2s) -> SENT/FAILED(+backoff 1/3/10s, max 3).
 * Restart-safe: PENDING/SENDING treo (>10ph) duoc claim lai.
 * + ReportScheduler.tick() moi vong (bao cao dinh ky).
 */
require_once __DIR__ . '/../sync/NotificationQueue.php';
require_once __DIR__ . '/../sync/TelegramProvider.php';
require_once __DIR__ . '/../sync/ReportScheduler.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.notify_worker.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.notify_worker.pid', (string)getmypid());

try {
    SyncLogger::info('notify', '[Worker] started pid=' . getmypid());
} catch (Throwable $e) {
}
// SENDING treo (>10ph, worker cu chet giua chung) -> ve PENDING
try {
    db()->prepare("UPDATE notification_outbox SET status='PENDING', next_try_at=NOW()
        WHERE status='SENDING' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->execute();
} catch (Throwable $e) {
}

while (true) {
    try {
        // Bao cao dinh ky truoc (re nhe, 1 lan/ngay-tuan nho dueToday)
        $rep = ReportScheduler::tick();
        foreach ($rep as $s) {
            echo date('H:i:s') . " scheduled $s\n";
        }
        $items = NotificationQueue::claim(10);
        if (!$items) {
            sleep(5);
            continue;
        }
        foreach ($items as $it) {
            $nid = (string)$it['notification_id'];
            $attempt = (int)$it['attempt_count'];
            $r = TelegramProvider::sendMessage((string)($it['message'] ?? ''));
            if (!empty($r['ok'])) {
                NotificationQueue::markSent($nid);
                echo date('H:i:s') . " SENT $nid\n";
            } else {
                NotificationQueue::markFail($nid, (string)($r['error'] ?? 'send_failed'), $attempt);
                echo date('H:i:s') . " FAIL $nid (" . ($r['error'] ?? '?') . ") attempt=$attempt\n";
            }
            usleep(1200000); // rate limit 1.2s/msg (§44)
        }
        @fflush(STDOUT);
    } catch (Throwable $e) {
        echo date('H:i:s') . ' worker err: ' . mb_substr($e->getMessage(), 0, 150) . "\n";
        sleep(5);
    }
}
