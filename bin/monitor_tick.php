<?php
declare(strict_types=1);
/**
 * bin/monitor_tick.php - Scheduler 1-shot cho module Monitoring.
 * Dung: php -f bin/monitor_tick.php [--interval=60]
 * Chay bang Task Scheduler moi N phut (khong thread, khong daemon treo).
 * tick(): watchdog + chon <=4 profile theo priority + snapshot KPI.
 */
require_once __DIR__ . '/../sync/MonitoringService.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$interval = 60;
foreach ($argv as $a) {
    if (str_starts_with($a, '--interval=')) $interval = max(5, (int)substr($a, 11));
}
try {
    $done = MonitoringService::tick($interval, 4);
    $msg = '[Monitor] tick interval=' . $interval . 'm checked=' . count($done)
        . ($done ? ' (' . implode(',', $done) . ')' : '');
    SyncLogger::info('monitor_tick', $msg);
    echo date('H:i:s') . ' ' . $msg . "\n";
} catch (Throwable $e) {
    echo date('H:i:s') . ' tick err: ' . mb_substr($e->getMessage(), 0, 150) . "\n";
    exit(1);
}
