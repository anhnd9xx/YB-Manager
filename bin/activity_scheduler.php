<?php
declare(strict_types=1);
/**
 * bin/activity_scheduler.php - Daemon scheduler Auto Activity (1 tien trinh duy nhat).
 * Chay: php -f bin/activity_scheduler.php (flock singleton, tick 60s, toi da 4 profiles/tick).
 * Dung Task Scheduler / start tu API (giong account_monitor).
 */
require_once __DIR__ . '/../sync/ActivityScheduler.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.activity_scheduler.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.activity_scheduler.pid', (string)getmypid());

try {
    SyncLogger::info('activity', '[Scheduler] started pid=' . getmypid());
} catch (Throwable $e) {
}
while (true) {
    try {
        // Concurrency tu settings (§20), khong one thread/profile
        $r = ActivityScheduler::tick(ActivityScheduler::concurrency());
        $msg = date('H:i:s') . ' tick ran=' . $r['ran'];
        if (!empty($r['results'])) {
            foreach ($r['results'] as $one) {
                $msg .= ' #' . $one['id'] . '[' . implode(',', $one['tasks']) . ']';
            }
        }
        echo $msg . "\n";
        @fflush(STDOUT);
    } catch (Throwable $e) {
        echo date('H:i:s') . ' tick err: ' . mb_substr($e->getMessage(), 0, 150) . "\n";
    }
    sleep(60);
}
