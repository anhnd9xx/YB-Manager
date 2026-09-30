<?php
// E2E upload V1 qua API that (SIMULATED provider + job_worker that).
// profile 9071, slot gan (lead 180 -> plan ngay), veriy SCHEDULED.
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/UploadStore.php';
require __DIR__ . '/../../sync/VideoLibraryService.php';
require __DIR__ . '/../../sync/ChannelQueueService.php';
require __DIR__ . '/../../sync/UploadPlanner.php';

$pid = 9071;
$tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_upl_e2e';
@mkdir($tmp, 0777, true);
function e2e_cleanup($tmp, $pid) {
    try {
        foreach (['video_publish_schedules', 'video_uploads', 'channel_video_queue'] as $tb) {
            db()->exec("DELETE FROM $tb WHERE profile_id=$pid");
        }
        db()->exec("DELETE FROM channel_upload_configs WHERE profile_id=$pid");
        db()->exec("DELETE FROM app_jobs WHERE module='UPLOAD'");
    } catch (Throwable $e) {}
    try { db()->exec('DELETE FROM profiles WHERE id=' . $pid); } catch (Throwable $e) {}
    foreach (glob($tmp . '/*') ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    try {
        $rows = db()->prepare('SELECT id FROM video_assets WHERE source_path LIKE ?');
        $rows->execute([str_replace('\\', '\\\\', $tmp) . '%']);
        foreach ($rows->fetchAll() as $r) {
            try { db()->prepare('DELETE FROM video_assets WHERE id=?')->execute([(int)$r['id']]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
$step = ($argv[1] ?? 'setup');
if ($step === 'setup') {
    e2e_cleanup($tmp, $pid);
    db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
        ->execute([$pid, 'TESTE2E #9071', 'C:\\tmp\\ytm-e2e-9071', 'stopped']);
    $fh = fopen($tmp . '/e2e.mp4', 'wb');
    for ($i = 0; $i < 48; $i++) fwrite($fh, str_repeat(chr(($i * 7) % 256), 1024));
    fclose($fh);
    $r = VideoLibraryService::importFile($tmp . '/e2e.mp4', 'MANUAL', false);
    echo 'import=' . json_encode($r) . PHP_EOL;
    $a = ChannelQueueService::assign($pid, [(int)$r['id']]);
    echo 'assign=' . json_encode($a) . PHP_EOL;
    $slot = date('H:i', time() + 600);
    $c = UploadStore::saveConfig($pid, UploadStore::getConfig($pid) + []);
    $c2 = UploadStore::saveConfig($pid, ['upload_enabled' => 1, 'daily_enabled' => 1,
        'publish_slots' => [$slot], 'videos_per_day' => 1, 'pre_upload_minutes' => 180,
        'upload_provider' => 'SIMULATED', 'weekdays' => (string)date('N')]);
    echo 'config=' . json_encode($c2) . ' slot=' . $slot . PHP_EOL;
    UploadPlanner::refreshSlots($pid, 2);
    $pl = UploadPlanner::planDueUploads();
    echo 'plan=' . json_encode($pl) . PHP_EOL;
    JobManager::ensureWorker();
    echo 'worker_alive=' . var_export(JobManager::workerAlive(), true) . PHP_EOL;
    exit(0);
}
if ($step === 'verify') {
    $u = db()->query("SELECT id, status, progress, provider_video_id, error_code FROM video_uploads WHERE profile_id=$pid ORDER BY id DESC LIMIT 1")->fetch();
    echo 'upload=' . json_encode($u) . PHP_EOL;
    $s = db()->query("SELECT publish_at, status FROM video_publish_schedules WHERE profile_id=$pid ORDER BY publish_at DESC LIMIT 1")->fetch();
    echo 'slot=' . json_encode($s) . PHP_EOL;
    $j = db()->query("SELECT job_id, status FROM app_jobs WHERE module='UPLOAD' ORDER BY created_at DESC LIMIT 1")->fetch();
    echo 'job=' . json_encode($j) . PHP_EOL;
    e2e_cleanup($tmp, $pid);
    exit(0);
}
