<?php
declare(strict_types=1);
/**
 * bin/activity_scheduler.php - Daemon scheduler Auto Activity (1 tien trinh duy nhat).
 * Chay: php -f bin/activity_scheduler.php (flock singleton, tick 60s, toi da N profiles/tick).
 * Dung Task Scheduler / start tu API (giong account_monitor).
 *
 * Loop an toan long-running:
 * - db_ping() moi vong (MySQL restart -> tu reconnect, khong chet worker)
 * - heartbeat JSON moi vong (supervisor/UI biet song/chet that)
 * - try/catch quanh tick (1 profile loi khong kill worker)
 * - gc_collect_cycles dinh ky (chong leak nhe)
 */
require_once __DIR__ . '/../sync/ActivityScheduler.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.activity_scheduler.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.activity_scheduler.pid', (string)getmypid());

$startedAt = date('Y-m-d H:i:s');
$hbFile = __DIR__ . '/.activity_scheduler.hb';
$beats = 0;

function act_hb(string $hbFile, string $startedAt, int $tick, int $ran, int $due, ?string $err): void
{
    @file_put_contents($hbFile, json_encode([
        'pid' => getmypid(), 'started_at' => $startedAt,
        'heartbeat_at' => date('Y-m-d H:i:s'), 'tick' => $tick,
        'last_ran' => $ran, 'last_due' => $due, 'last_error' => $err,
    ], JSON_UNESCAPED_UNICODE));
}

try {
    SyncLogger::info('activity', '[Scheduler] started pid=' . getmypid());
} catch (Throwable $e) {
}
act_hb($hbFile, $startedAt, 0, 0, 0, null);
$ticks = 0;
while (true) {
    $ticks++;
    $ran = 0;
    $due = 0;
    $err = null;
    try {
        if (!db_ping()) {
            $err = 'DB_UNREACHABLE';
        } else {
            // Concurrency tu settings (§20), khong one thread/profile
            $r = ActivityScheduler::tick(ActivityScheduler::concurrency());
            $ran = (int)($r['ran'] ?? 0);
            $due = (int)($r['due'] ?? $ran);
            $msg = date('H:i:s') . ' tick ran=' . $ran . ' due=' . $due;
            if (!empty($r['results'])) {
                foreach ($r['results'] as $one) {
                    $msg .= ' #' . $one['id'] . '[' . implode(',', $one['tasks']) . ']';
                }
            }
            // Upload Manager tick (nhe, idempotent): slots + plan + publishes + alerts.
            // Khong scheduler moi — reuse daemon supervised nay.
            try {
                require_once __DIR__ . '/../sync/PublishScheduler.php';
                $up = PublishScheduler::tick();
                if ((int)($up['planned'] ?? 0) > 0 || (int)($up['published'] ?? 0) > 0) {
                    $msg .= ' upl[plan=' . $up['planned'] . ',pub=' . $up['published'] . ']';
                }
            } catch (Throwable $eUp) {
            }
            echo $msg . "\n";
            @fflush(STDOUT);
        }
    } catch (Throwable $e) {
        $err = mb_substr($e->getMessage(), 0, 150);
        echo date('H:i:s') . ' tick err: ' . $err . "\n";
        try {
            db_reconnect();
        } catch (Throwable $e2) {
        }
    }
    act_hb($hbFile, $startedAt, $ticks, $ran, $due, $err);
    if ($ticks % 30 === 0) {
        try {
            gc_collect_cycles();
        } catch (Throwable $e) {
        }
    }
    sleep(60);
}
