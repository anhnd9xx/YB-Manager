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
                case 'UPLOAD':
                    run_upload_job($job);
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
    require_once __DIR__ . '/../sync/ActivityPlanner.php';
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
        // Planner mode: chay sessions due; legacy: runCycle cu
        $cfg = ActivityManager::getConfig($id);
        if (!empty($cfg['planner_enabled'])) {
            $before = ActivityPlanner::summary($id);
            $n = ActivityPlanner::runDueSessions($id, 3);
            $after = ActivityPlanner::summary($id);
            $newOk = max(0, $after['success'] - $before['success']);
            $newFail = max(0, $after['failed'] - $before['failed']);
            if ($n > 0 && $newFail === 0) {
                $ok++;
                JobManager::setItem($jid, 'profile:' . $id, 'SUCCESS');
            } elseif ($n > 0) {
                $fail++;
                JobManager::setItem($jid, 'profile:' . $id, 'FAILED', null, "$newFail session lỗi");
            } else {
                $ok++;
                JobManager::setItem($jid, 'profile:' . $id, 'SUCCESS');
            }
            $done++;
            JobManager::update($jid, ['progress_done' => $done, 'success_count' => $ok, 'failed_count' => $fail]);
            continue;
        }
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

/**
 * YOUTUBE_UPLOAD: preflight -> provider upload (resumable, progress) ->
 * metadata + schedule publish -> records. Retry transient (backoff), fatal
 * -> NEEDS_ATTENTION. Khong browser automation.
 */
function run_upload_job(array $job): void
{
    $jid = (string)$job['job_id'];
    require_once __DIR__ . '/../sync/UploadStore.php';
    require_once __DIR__ . '/../sync/UploadPlanner.php';
    require_once __DIR__ . '/../sync/UploadProvider.php';
    require_once __DIR__ . '/../sync/ChannelQueueService.php';
    require_once __DIR__ . '/../sync/EventBus.php';
    UploadStore::ensureSchema();
    try {
        $st = db()->prepare('SELECT u.*, a.source_path, a.filename, a.size_bytes, a.checksum,
                c.pre_upload_minutes, c.max_attempts, c.visibility, c.playlist_id,
                c.metadata_template_id, c.category_id, c.language
            FROM video_uploads u
            JOIN video_assets a ON a.id=u.video_asset_id
            JOIN channel_upload_configs c ON c.profile_id=u.profile_id
            WHERE u.job_id=? LIMIT 1');
        $st->execute([$jid]);
        $u = $st->fetch();
    } catch (Throwable $e) {
        $u = false;
    }
    if (!$u) {
        JobManager::finish($jid, JobManager::ST_FAILED, null, 'upload_not_found');
        return;
    }
    $uid = (int)$u['id'];
    $pid = (int)$u['profile_id'];
    if (job_poll_control($jid) === 'cancelled') {
        db()->prepare("UPDATE video_uploads SET status='CANCELLED' WHERE id=?")->execute([$uid]);
        if (!empty($u['queue_id'])) ChannelQueueService::release((int)$u['queue_id']);
        JobManager::finish($jid, JobManager::ST_CANCELLED, 'Đã hủy upload #' . $uid);
        return;
    }
    $setUp = function (string $status, int $pct = 0, int $bytes = 0) use ($uid, $jid) {
        try {
            db()->prepare('UPDATE video_uploads SET status=?, progress=?, bytes_uploaded=? WHERE id=?')
                ->execute([$status, $pct, $bytes, $uid]);
            JobManager::update($jid, ['progress_done' => $pct, 'progress_total' => 100]);
        } catch (Throwable $e) {
        }
    };
    // PREPARING + preflight
    $setUp('PREPARING');
    db()->prepare('UPDATE video_uploads SET started_at=COALESCE(started_at,NOW()), attempt=attempt+1 WHERE id=?')->execute([$uid]);
    $pre = UploadPlanner::preflight($pid, ['source_path' => $u['source_path'], 'checksum' => $u['checksum']]);
    if (!$pre['ok']) {
        UploadPlanner::handlePreflightFail($pid, (int)($u['queue_id'] ?? 0), (int)$u['video_asset_id'],
            $pre['errors'], 'AUTO_REPLACE');
        db()->prepare("UPDATE video_uploads SET status='FAILED', completed_at=NOW(), error_code=?, error_message=? WHERE id=?")
            ->execute(['PREFLIGHT', implode(',', $pre['errors']), $uid]);
        JobManager::finish($jid, JobManager::ST_FAILED, null, 'preflight: ' . implode(',', $pre['errors']));
        return;
    }
    // Metadata (template + per-video override)
    $tpl = null;
    try {
        if (!empty($u['metadata_template_id'])) {
            $t = db()->prepare('SELECT * FROM metadata_templates WHERE id=?');
            $t->execute([(int)$u['metadata_template_id']]);
            $tpl = $t->fetch() ?: null;
        }
        if (!$tpl) {
            $tpl = db()->query('SELECT * FROM metadata_templates ORDER BY id ASC LIMIT 1')->fetch() ?: [];
        }
    } catch (Throwable $e) {
        $tpl = [];
    }
    $ov = [];
    try {
        if (!empty($u['queue_id'])) {
            $q = db()->prepare('SELECT metadata_override FROM channel_video_queue WHERE id=?');
            $q->execute([(int)$u['queue_id']]);
            $ov = json_decode((string)($q->fetchColumn() ?? ''), true) ?: [];
        }
    } catch (Throwable $e) {
    }
    try {
        $profName = db()->prepare('SELECT name FROM profiles WHERE id=?');
        $profName->execute([$pid]);
        $chName = (string)($profName->fetchColumn() ?? ('#' . $pid));
    } catch (Throwable $e) {
        $chName = '#' . $pid;
    }
    $meta = UploadStore::renderMetadata((array)$tpl, ['filename' => pathinfo((string)$u['filename'], PATHINFO_FILENAME),
        'channel_name' => $chName, 'date' => date('Y-m-d'), 'index' => (string)$uid]);
    foreach (['title' => 'title', 'description' => 'description', 'tags' => 'tags'] as $k => $ok2) {
        if (!empty($ov[$ok2])) $meta[$k] = $ov[$ok2];
    }
    $meta['visibility'] = (string)$u['visibility'];
    $meta['playlist_id'] = (string)$u['playlist_id'];
    $meta['category_id'] = (string)$u['category_id'];
    $meta['language'] = (string)$u['language'];
    // UPLOADING (resumable, progress, cancel-aware)
    $setUp('UPLOADING', (int)$u['progress'], (int)$u['bytes_uploaded']);
    JobManager::update($jid, ['progress_total' => 100]);
    $prov = YouTubeApiProvider::make((string)$u['provider']);
    $cancelFlag = false;
    try {
        $r = $prov->uploadVideo($pid, (string)$u['source_path'], $meta,
            (string)($u['upload_session_ref'] ?? ''), (int)$u['bytes_uploaded'],
            function (int $sent, int $total) use ($uid, $jid, &$cancelFlag) {
                $pct = $total > 0 ? (int)floor($sent * 100 / $total) : 0;
                try {
                    db()->prepare('UPDATE video_uploads SET progress=?, bytes_uploaded=? WHERE id=?')
                        ->execute([$pct, $sent, $uid]);
                    JobManager::update($jid, ['progress_done' => $pct]);
                    $j = JobManager::get($jid);
                    if ($j && in_array((string)($j['status'] ?? ''), ['CANCELLED', 'PAUSED'], true)) $cancelFlag = true;
                } catch (Throwable $e) {
                }
            });
    } catch (Throwable $e) {
        $r = ['ok' => false, 'bytes_uploaded' => (int)$u['bytes_uploaded'], 'error' => mb_substr($e->getMessage(), 0, 200), 'error_code' => 'NETWORK_ERROR', 'fatal' => false];
    }
    if ($cancelFlag) {
        db()->prepare("UPDATE video_uploads SET status='CANCELLED' WHERE id=?")->execute([$uid]);
        if (!empty($u['queue_id'])) ChannelQueueService::release((int)$u['queue_id']);
        JobManager::finish($jid, JobManager::ST_CANCELLED, 'Đã hủy upload #' . $uid);
        return;
    }
    if (!empty($r['session_ref'])) {
        db()->prepare('UPDATE video_uploads SET upload_session_ref=? WHERE id=?')
            ->execute([mb_substr((string)$r['session_ref'], 0, 500), $uid]);
    }
    if (empty($r['ok'])) {
        $fatal = !empty($r['fatal']);
        $code = (string)($r['error_code'] ?? 'NETWORK_ERROR');
        if ($fatal) {
            db()->prepare("UPDATE video_uploads SET status='FAILED', completed_at=NOW(), error_code=?, error_message=? WHERE id=?")
                ->execute([$code, mb_substr((string)($r['error'] ?? ''), 0, 500), $uid]);
            if (!empty($u['queue_id'])) ChannelQueueService::release((int)$u['queue_id']);
            try {
                EventBus::emit(AppEvent::TASK_FAILED, AppEvent::MOD_UPLOAD, AppEvent::SEV_ERROR,
                    "Upload #$uid that bai (fatal)", "Profile #$pid: " . ($r['error'] ?? $code),
                    ['profile_id' => $pid, 'status' => 'FAILED']);
            } catch (Throwable $e) {
            }
            JobManager::finish($jid, JobManager::ST_FAILED, null, (string)($r['error'] ?? $code), $code);
            return;
        }
        // Transient: RETRYING + backoff
        $att = (int)$u['attempt'] + 1;
        db()->prepare('UPDATE video_uploads SET status=?, next_attempt_at=?, error_code=?, error_message=? WHERE id=?')
            ->execute(['RETRYING', UploadPlanner::backoffAt($att), $code, mb_substr((string)($r['error'] ?? ''), 0, 500), $uid]);
        JobManager::finish($jid, JobManager::ST_FAILED, 'Retry sau (attempt ' . $att . ')', (string)($r['error'] ?? $code), $code);
        return;
    }
    // PROCESSING -> SCHEDULING: metadata + publish slot
    $setUp('SCHEDULING', 100, (int)($r['bytes_uploaded'] ?? 0));
    $vid = (string)($r['provider_video_id'] ?? '');
    db()->prepare('UPDATE video_uploads SET provider_video_id=?, status=? WHERE id=?')
        ->execute([$vid, 'SCHEDULED', $uid]);
    try {
        $sch = db()->prepare('SELECT * FROM video_publish_schedules WHERE upload_id=? LIMIT 1');
        $sch->execute([$uid]);
        $slot = $sch->fetch();
    } catch (Throwable $e) {
        $slot = false;
    }
    if ($slot) {
        $sr = $prov->schedulePublish($pid, $vid, (string)$slot['publish_at'], $meta);
        db()->prepare('UPDATE video_publish_schedules SET status=?, provider_status=? WHERE id=?')
            ->execute([$sr['ok'] ? 'SCHEDULED' : 'RESERVED', $sr['ok'] ? 'SCHEDULED' : ('ERR:' . mb_substr((string)($sr['error'] ?? ''), 0, 60)), (int)$slot['id']]);
        if (empty($sr['ok'])) {
            try {
                EventBus::emit(AppEvent::WARNING, AppEvent::MOD_UPLOAD, AppEvent::SEV_WARNING,
                    "Upload #$uid xong nhung schedule loi", "Profile #$pid: " . ($sr['error'] ?? ''),
                    ['profile_id' => $pid, 'status' => 'WARNING']);
            } catch (Throwable $e) {
            }
        }
    }
    try {
        EventBus::emit(AppEvent::TASK_COMPLETED, AppEvent::MOD_UPLOAD, AppEvent::SEV_SUCCESS,
            "Upload #$uid hoan tat", "Profile #$pid: " . $u['filename'],
            ['profile_id' => $pid, 'status' => 'SUCCESS']);
    } catch (Throwable $e) {
    }
    JobManager::update($jid, ['progress_done' => 100, 'success_count' => 1]);
    JobManager::finish($jid, JobManager::ST_SUCCESS, 'Upload #' . $uid . ' -> SCHEDULED');
    echo date('H:i:s') . " DONE $jid upload=$uid\n";
}
