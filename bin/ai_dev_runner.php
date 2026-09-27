<?php
declare(strict_types=1);
/**
 * bin/ai_dev_runner.php - Tien pipeline DevJob tu dong (singleton, 30s).
 * Chi tien jobs QUEUED/ANALYZING/CODING/TESTING. Approval/apply manual.
 */
require_once __DIR__ . '/../sync/DevJobPipeline.php';
require_once __DIR__ . '/../sync/DevJobManager.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.ai_dev_runner.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.ai_dev_runner.pid', (string)getmypid());

try {
    SyncLogger::info('aidev', '[RUNNER] started pid=' . getmypid());
} catch (Throwable $e) {
}

while (true) {
    try {
        DevJobManager::ensureTables();
        $rows = db()->query("SELECT job_code FROM dev_jobs WHERE status IN
            ('QUEUED','ANALYZING','CODING','TESTING') ORDER BY id ASC LIMIT 3")->fetchAll();
        foreach ($rows as $r) {
            $code = (string)$r['job_code'];
            try {
                $res = DevJobPipeline::advance($code);
                echo date('H:i:s') . " $code " . json_encode($res) . "\n";
            } catch (Throwable $e) {
                echo date('H:i:s') . " $code err=" . mb_substr($e->getMessage(), 0, 120) . "\n";
            }
            @fflush(STDOUT);
        }
    } catch (Throwable $e) {
        echo date('H:i:s') . ' runner err: ' . mb_substr($e->getMessage(), 0, 120) . "\n";
    }
    sleep(30);
}
