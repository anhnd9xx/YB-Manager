<?php
declare(strict_types=1);
/**
 * bin/job_worker.php - Thuc thi jobs (1 tien trinh, tuan tu).
 * Claim QUEUED -> chay executor theo module -> progress -> finish (emit JOB_*).
 * Telegram khong cho job chay xong: ack ngay luc tao (§17).
 */
require_once __DIR__ . '/../sync/JobManager.php';
require_once __DIR__ . '/../sync/CommandRouter.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.job_worker.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.job_worker.pid', (string)getmypid());

function job_ctx_set(string $batchId, string $jobId, string $source): void
{
    @file_put_contents(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
        . 'ytm_jobctx_' . preg_replace('/[^A-Za-z0-9_-]/', '', $batchId) . '.json',
        json_encode(['job_id' => $jobId, 'source' => $source]));
}
function job_ctx_clear(string $batchId): void
{
    @unlink(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
        . 'ytm_jobctx_' . preg_replace('/[^A-Za-z0-9_-]/', '', $batchId) . '.json');
}

try {
    SyncLogger::info('job', '[Worker] started pid=' . getmypid());
} catch (Throwable $e) {
}

while (true) {
    try {
        $job = JobManager::claim();
        if (!$job) {
            sleep(3);
            continue;
        }
        $jid = (string)$job['job_id'];
        echo date('H:i:s') . " RUN $jid {$job['module']} {$job['name']}\n";
        try {
            switch ($job['module']) {
                case 'EVALUATION':
                    run_eval_job($job);
                    break;
                case 'BROWSER':
                    run_browser_job($job);
                    break;
                case 'AUTO_ACTIVITY':
                    run_activity_job($job);
                    break;
                default:
                    JobManager::finish($jid, JobManager::ST_FAILED, null, 'module_khong_ho_tro');
            }
        } catch (Throwable $e) {
            JobManager::finish($jid, JobManager::ST_FAILED, null, mb_substr($e->getMessage(), 0, 200));
        }
        @fflush(STDOUT);
    } catch (Throwable $e) {
        echo date('H:i:s') . ' worker err: ' . mb_substr($e->getMessage(), 0, 150) . "\n";
        sleep(3);
    }
}

function job_targets(array $job): array
{
    $t = json_decode((string)($job['targets'] ?? ''), true);
    return is_array($t) ? array_values(array_filter(array_map('intval', $t))) : [];
}

function run_eval_job(array $job): void
{
    $jid = (string)$job['job_id'];
    require_once __DIR__ . '/../sync/ChannelEvaluationManager.php';
    $ids = job_targets($job);
    $st = ChannelEvaluationManager::evaluate_many($ids, 4);
    if (empty($st['ok'])) {
        JobManager::finish($jid, JobManager::ST_FAILED, null, $st['message'] ?? 'batch_error');
        return;
    }
    $bid = (string)$st['batch_id'];
    job_ctx_set($bid, $jid, (string)($job['source'] ?? ''));
    JobManager::update($jid, ['progress_total' => count($ids)]);
    $done = 0;
    $ok = 0;
    $fail = 0;
    $deadline = microtime(true) + 1800; // toi da 30ph/job
    while (microtime(true) < $deadline) {
        $ch = ChannelEvaluationManager::evaluate_chunk($bid, 4);
        if (empty($ch['ok'])) break;
        foreach ((array)($ch['results'] ?? []) as $r) {
            if (!empty($r['already_running'])) continue;
            $done++;
            if (($r['attempt_status'] ?? '') === 'SUCCESS' || ($r['attempt_status'] ?? '') === 'PARTIAL') $ok++;
            else $fail++;
        }
        JobManager::update($jid, ['progress_done' => $done, 'success_count' => $ok, 'failed_count' => $fail]);
        if (!empty($ch['done'])) break;
        if (empty($ch['results'])) break;
    }
    job_ctx_clear($bid);
    $summary = "Tổng: " . count($ids) . "\nHoàn tất: $ok\nLỗi: $fail";
    if ($fail > 0 && $ok === 0) JobManager::finish($jid, JobManager::ST_FAILED, $summary);
    elseif ($fail > 0) JobManager::finish($jid, JobManager::ST_PARTIAL, $summary);
    else JobManager::finish($jid, JobManager::ST_SUCCESS, $summary);
    echo date('H:i:s') . " DONE $jid ok=$ok fail=$fail\n";
}

function run_browser_job(array $job): void
{
    $jid = (string)$job['job_id'];
    require_once __DIR__ . '/../sync/ChromeBatchManager.php';
    $ids = job_targets($job);
    $action = 'start';
    try {
        $cmd = null;
        if (!empty($job['command_id'])) {
            $st = db()->prepare('SELECT command_name FROM app_commands WHERE command_id=?');
            $st->execute([$job['command_id']]);
            $cmd = $st->fetchColumn();
        }
        if (is_string($cmd) && str_contains($cmd, 'stop')) $action = 'stop';
    } catch (Throwable $e) {
    }
    if ($action === 'stop') {
        $stp = ChromeBatchManager::stopBatch($ids, 'safe');
        job_ctx_set((string)($stp['batch_id'] ?? $jid), $jid, (string)($job['source'] ?? ''));
        JobManager::update($jid, ['progress_total' => count($ids)]);
        $deadline = microtime(true) + 600;
        $closed = 0;
        while (microtime(true) < $deadline) {
            $p = ChromeBatchManager::stopPoll((string)($stp['batch_id'] ?? ''));
            $closed = (int)($p['closed'] ?? 0);
            JobManager::update($jid, ['progress_done' => $closed]);
            if (!empty($p['done'])) break;
            usleep(500000);
        }
        job_ctx_clear((string)($stp['batch_id'] ?? $jid));
        $summary = "Total: " . count($ids) . "\nGraceful: $closed\nForced fallback: " . max(0, count($ids) - $closed);
        if ($closed >= count($ids)) JobManager::finish($jid, JobManager::ST_SUCCESS, $summary);
        elseif ($closed > 0) JobManager::finish($jid, JobManager::ST_PARTIAL, $summary);
        else JobManager::finish($jid, JobManager::ST_FAILED, $summary);
        echo date('H:i:s') . " DONE $jid closed=$closed\n";
        return;
    }
    // start
    $prep = ChromeBatchManager::startPrepare($ids);
    if (empty($prep['queued'])) {
        JobManager::finish($jid, JobManager::ST_SUCCESS, 'Không có kênh nào cần mở (đang chạy/bận).');
        return;
    }
    $bid = (string)$prep['batch_id'];
    job_ctx_set($bid, $jid, (string)($job['source'] ?? ''));
    JobManager::update($jid, ['progress_total' => (int)($prep['queued'] ?? 0)]);
    do {
        $ch = ChromeBatchManager::startChunk($bid, 5);
    } while (empty($ch['done']));
    $deadline = microtime(true) + 600;
    while (microtime(true) < $deadline) {
        $poll = ChromeBatchManager::startPoll($bid);
        $c = $poll['counts'] ?? [];
        JobManager::update($jid, ['progress_done' => (int)($c['running'] ?? 0)]);
        if (!empty($poll['done'])) break;
        sleep(2);
    }
    job_ctx_clear($bid);
    $fin = JobManager::get($jid);
    $n = (int)($fin['progress_done'] ?? 0);
    $summary = "Total: " . count($ids) . "\nOpened: $n";
    JobManager::finish($jid, $n > 0 ? JobManager::ST_SUCCESS : JobManager::ST_FAILED, $summary);
    echo date('H:i:s') . " DONE $jid opened=$n\n";
}

function run_activity_job(array $job): void
{
    $jid = (string)$job['job_id'];
    require_once __DIR__ . '/../sync/ActivityManager.php';
    $ids = job_targets($job);
    $ok = 0;
    $fail = 0;
    $done = 0;
    JobManager::update($jid, ['progress_total' => count($ids)]);
    foreach ($ids as $id) {
        try {
            $r = ActivityManager::runCycle($id);
            $bad = 0;
            foreach ((array)($r['tasks'] ?? []) as $t) {
                if (empty($t['ok']) && !in_array($t['result'] ?? '', ['REUSED', 'CHECKED'], true)) $bad++;
            }
            if ($bad > 0) $fail++;
            else $ok++;
        } catch (Throwable $e) {
            $fail++;
        }
        $done++;
        JobManager::update($jid, ['progress_done' => $done, 'success_count' => $ok, 'failed_count' => $fail]);
    }
    $summary = "Profiles: " . count($ids) . "\nSuccess: $ok\nFailed: $fail";
    if ($fail > 0 && $ok === 0) JobManager::finish($jid, JobManager::ST_FAILED, $summary);
    elseif ($fail > 0) JobManager::finish($jid, JobManager::ST_PARTIAL, $summary);
    else JobManager::finish($jid, JobManager::ST_SUCCESS, $summary);
    echo date('H:i:s') . " DONE $jid ok=$ok fail=$fail\n";
}
