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

/**
 * Safe cancellation/pause (§51): 'ok' | 'cancelled'.
 * PAUSED -> cho (sleep) den khi resume (QUEUED->RUNNING lai) hoac cancel.
 */
function job_poll_control(string $jid): string
{
    try {
        for ($i = 0; $i < 20; $i++) {
            $j = JobManager::get($jid);
            $st = (string)($j['status'] ?? '');
            if ($st === 'CANCELLED') return 'cancelled';
            if ($st !== 'PAUSED') {
                if ($st === 'QUEUED') {
                    db()->prepare("UPDATE app_jobs SET status='RUNNING' WHERE job_id=? AND status='QUEUED'")
                        ->execute([$jid]);
                }
                return 'ok';
            }
            sleep(3);
        }
        return 'ok';
    } catch (Throwable $e) {
        return 'ok';
    }
}

function profile_name_map(array $ids): array
{
    $out = [];
    try {
        if (!$ids) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, name FROM profiles WHERE id IN ($in)");
        $st->execute(array_values($ids));
        foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = (string)$r['name'];
    } catch (Throwable $e) {
    }
    return $out;
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
                case 'PROXY':
                    run_proxy_job($job);
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
    $names = profile_name_map($ids);
    JobManager::initItems($jid, $ids, 'profile', fn($id) => $names[(int)$id] ?? ('#' . $id));
    $done = 0;
    $ok = 0;
    $fail = 0;
    $warn = 0;
    $cancelled = false;
    $deadline = microtime(true) + 1800; // toi da 30ph/job
    while (microtime(true) < $deadline) {
        if (job_poll_control($jid) === 'cancelled') {
            $cancelled = true;
            break;
        }
        $ch = ChannelEvaluationManager::evaluate_chunk($bid, 4);
        if (empty($ch['ok'])) break;
        foreach ((array)($ch['results'] ?? []) as $r) {
            if (!empty($r['already_running'])) continue;
            $done++;
            $pid = (int)($r['profileId'] ?? 0);
            $att = (string)($r['attempt_status'] ?? '');
            $key = 'profile:' . $pid;
            if ($att === 'SUCCESS') {
                $ok++;
                if ($pid > 0) JobManager::setItem($jid, $key, 'SUCCESS');
            } elseif ($att === 'PARTIAL') {
                $ok++;
                $warn++;
                if ($pid > 0) JobManager::setItem($jid, $key, 'WARNING');
            } elseif ($att === 'CANCELLED') {
                if ($pid > 0) JobManager::setItem($jid, $key, 'CANCELLED');
            } else {
                $fail++;
                if ($pid > 0) JobManager::setItem($jid, $key, 'FAILED', null,
                    mb_substr((string)($r['error_code'] ?? $r['message'] ?? 'failed'), 0, 200));
            }
        }
        JobManager::update($jid, ['progress_done' => $done, 'success_count' => $ok,
            'warning_count' => $warn, 'failed_count' => $fail]);
        if (!empty($ch['done'])) break;
        if (empty($ch['results'])) break;
    }
    job_ctx_clear($bid);
    if ($cancelled) {
        try {
            db()->prepare("UPDATE job_items SET status='CANCELLED' WHERE job_id=? AND status IN ('QUEUED','RUNNING')")
                ->execute([$jid]);
        } catch (Throwable $e) {
        }
        JobManager::finish($jid, JobManager::ST_CANCELLED, "Đã hủy. Hoàn tất: $ok/$done. Lỗi: $fail");
        echo date('H:i:s') . " CANCELLED $jid done=$done\n";
        return;
    }
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
    $jt = strtoupper((string)($job['job_type'] ?? ''));
    if ($jt === 'BROWSER_STOP') $action = 'stop';
    elseif ($jt === 'BROWSER_START') $action = 'start';
    else {
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
    }
    if ($action === 'stop') {
        $stp = ChromeBatchManager::stopBatch($ids, 'safe');
        job_ctx_set((string)($stp['batch_id'] ?? $jid), $jid, (string)($job['source'] ?? ''));
        JobManager::update($jid, ['progress_total' => count($ids)]);
        $deadline = microtime(true) + 600;
        $closed = 0;
        $cancelled = false;
        while (microtime(true) < $deadline) {
            if (job_poll_control($jid) === 'cancelled') {
                $cancelled = true;
                break;
            }
            $p = ChromeBatchManager::stopPoll((string)($stp['batch_id'] ?? ''));
            $closed = (int)($p['closed'] ?? 0);
            JobManager::update($jid, ['progress_done' => $closed]);
            if (!empty($p['done'])) break;
            usleep(500000);
        }
        job_ctx_clear((string)($stp['batch_id'] ?? $jid));
        if ($cancelled) {
            JobManager::finish($jid, JobManager::ST_CANCELLED, "Đã hủy. Đã đóng: $closed/" . count($ids));
            echo date('H:i:s') . " CANCELLED $jid closed=$closed\n";
            return;
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
        if (job_poll_control($jid) === 'cancelled') {
            JobManager::finish($jid, JobManager::ST_CANCELLED, 'Đã hủy khi đang mở kênh.');
            echo date('H:i:s') . " CANCELLED $jid\n";
            return;
        }
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
    $names = profile_name_map($ids);
    JobManager::initItems($jid, $ids, 'profile', fn($id) => $names[(int)$id] ?? ('#' . $id));
    foreach ($ids as $id) {
        if (job_poll_control($jid) === 'cancelled') {
            try {
                db()->prepare("UPDATE job_items SET status='CANCELLED' WHERE job_id=? AND status IN ('QUEUED','RUNNING')")
                    ->execute([$jid]);
            } catch (Throwable $e) {
            }
            JobManager::finish($jid, JobManager::ST_CANCELLED, "Đã hủy. Xong: $ok/$done. Lỗi: $fail");
            echo date('H:i:s') . " CANCELLED $jid done=$done\n";
            return;
        }
        JobManager::setItem($jid, 'profile:' . $id, 'RUNNING');
        try {
            $r = ActivityManager::runCycle($id);
            $bad = 0;
            foreach ((array)($r['tasks'] ?? []) as $t) {
                if (empty($t['ok']) && !in_array($t['result'] ?? '', ['REUSED', 'CHECKED'], true)) $bad++;
            }
            if ($bad > 0) {
                $fail++;
                JobManager::setItem($jid, 'profile:' . $id, 'FAILED', null, "$bad task lỗi");
            } else {
                $ok++;
                JobManager::setItem($jid, 'profile:' . $id, 'SUCCESS');
            }
        } catch (Throwable $e) {
            $fail++;
            JobManager::setItem($jid, 'profile:' . $id, 'FAILED', null, mb_substr($e->getMessage(), 0, 200));
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

/** PROXY_CHECK: kiem tra tung proxy, progress that, item moi proxy. */
function run_proxy_job(array $job): void
{
    $jid = (string)$job['job_id'];
    $t = json_decode((string)($job['targets'] ?? ''), true);
    $ids = is_array($t) ? array_values(array_filter(array_map('intval', $t))) : [];
    try {
        if (!$ids) {
            $ids = array_map(fn($r) => (int)$r['id'],
                db()->query('SELECT id FROM proxies ORDER BY id')->fetchAll());
        }
    } catch (Throwable $e) {
    }
    $names = [];
    try {
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT id, name FROM proxies WHERE id IN ($in)");
            $st->execute(array_values($ids));
            foreach ($st->fetchAll() as $r) $names[(int)$r['id']] = (string)$r['name'];
        }
    } catch (Throwable $e) {
    }
    JobManager::update($jid, ['progress_total' => count($ids)]);
    JobManager::initItems($jid, $ids, 'proxy', fn($id) => $names[(int)$id] ?? ('proxy#' . $id));
    $ok = 0;
    $fail = 0;
    $done = 0;
    try {
        $upd = db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?');
    } catch (Throwable $e) {
        JobManager::finish($jid, JobManager::ST_FAILED, null, 'db_error');
        return;
    }
    foreach ($ids as $id) {
        if (job_poll_control($jid) === 'cancelled') {
            try {
                db()->prepare("UPDATE job_items SET status='CANCELLED' WHERE job_id=? AND status IN ('QUEUED','RUNNING')")
                    ->execute([$jid]);
            } catch (Throwable $e) {
            }
            JobManager::finish($jid, JobManager::ST_CANCELLED, "Đã hủy. Alive: $ok/$done. Dead: $fail");
            echo date('H:i:s') . " CANCELLED $jid done=$done\n";
            return;
        }
        JobManager::setItem($jid, 'proxy:' . $id, 'RUNNING');
        try {
            $st = db()->prepare('SELECT * FROM proxies WHERE id=?');
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) {
                $fail++;
                JobManager::setItem($jid, 'proxy:' . $id, 'FAILED', null, 'not_found');
            } else {
                $alive = test_proxy($p);
                $upd->execute([$alive ? 'alive' : 'dead', $id]);
                if ($alive) {
                    $ok++;
                    JobManager::setItem($jid, 'proxy:' . $id, 'SUCCESS');
                } else {
                    $fail++;
                    JobManager::setItem($jid, 'proxy:' . $id, 'FAILED', null, 'dead');
                }
            }
        } catch (Throwable $e) {
            $fail++;
            JobManager::setItem($jid, 'proxy:' . $id, 'FAILED', null, mb_substr($e->getMessage(), 0, 200));
        }
        $done++;
        JobManager::update($jid, ['progress_done' => $done, 'success_count' => $ok, 'failed_count' => $fail]);
    }
    $summary = "Total: " . count($ids) . "\nAlive: $ok\nDead: $fail";
    if ($fail > 0 && $ok === 0) JobManager::finish($jid, JobManager::ST_FAILED, $summary);
    elseif ($fail > 0) JobManager::finish($jid, JobManager::ST_PARTIAL, $summary);
    else JobManager::finish($jid, JobManager::ST_SUCCESS, $summary);
    echo date('H:i:s') . " DONE $jid alive=$ok dead=$fail\n";
}
