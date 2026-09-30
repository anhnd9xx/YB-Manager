<?php
declare(strict_types=1);
// YouTube Upload Manager V1 tests (simulated provider, file that, DB that).
// Chay: php tests/backend/test_upload_v1.php
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/UploadStore.php';
require __DIR__ . '/../../sync/VideoLibraryService.php';
require __DIR__ . '/../../sync/ChannelQueueService.php';
require __DIR__ . '/../../sync/UploadPlanner.php';
require __DIR__ . '/../../sync/UploadProvider.php';
require __DIR__ . '/../../sync/PublishScheduler.php';

$pass = 0;
$fail = 0;
function t($name, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FAIL $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    }
}

$tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_upl_test';
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/sub', 0777, true);
function wvid(string $p, int $kb, string $seed) {
    $fh = fopen($p, 'wb');
    $h = 0;
    for ($i = 0; $i < $kb; $i++) {
        $h = ($h * 31 + $i + ord($seed[$i % strlen($seed)])) % 1000003;
        fwrite($fh, str_repeat(chr($h % 256), 1024));
    }
    fclose($fh);
}
function likeTmp(string $tmp): string
{
    return str_replace('\\', '\\\\', $tmp) . '%';
}
$pid = 9061;
function cleanup_upl($tmp, $pid) {
    try {
        foreach (['video_publish_schedules', 'video_uploads', 'channel_video_queue'] as $tb) {
            db()->exec("DELETE FROM $tb WHERE profile_id=$pid");
        }
        db()->exec('DELETE FROM channel_upload_configs WHERE profile_id=' . $pid);
    } catch (Throwable $e) {
    }
    try { db()->exec('DELETE FROM profiles WHERE id=' . $pid); } catch (Throwable $e) {}
    $files = glob($tmp . '/*');
    foreach ((array)$files as $f) {
        if (is_file($f)) @unlink($f);
    }
    $sub = glob($tmp . '/sub/*');
    foreach ((array)$sub as $f) {
        if (is_file($f)) @unlink($f);
    }
    // Xoa assets test (theo path)
    try {
        $rows = db()->prepare("SELECT id, source_path FROM video_assets WHERE source_path LIKE ?");
        $rows->execute([likeTmp($tmp)]);
        foreach ($rows->fetchAll() as $r) {
            try { db()->prepare('DELETE FROM channel_video_queue WHERE video_asset_id=?')->execute([(int)$r['id']]); } catch (Throwable $e) {}
            try { db()->prepare('DELETE FROM video_assets WHERE id=?')->execute([(int)$r['id']]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}

try {
    cleanup_upl($tmp, $pid);
    db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
        ->execute([$pid, 'TESTUPL #9061', 'C:\\tmp\\ytm-test-upl-9061', 'stopped']);
    wvid($tmp . '/a.mp4', 64, 'aaa');
    wvid($tmp . '/copy_a.mp4', 64, 'aaa'); // cung content -> duplicate
    wvid($tmp . '/b.mov', 32, 'bbb');
    wvid($tmp . '/sub/c.webm', 16, 'ccc');
    file_put_contents($tmp . '/note.txt', 'not video');

    // ---- 1. Import + checksum dedupe
    $r1 = VideoLibraryService::importFile($tmp . '/a.mp4', 'MANUAL', false);
    $r2 = VideoLibraryService::importFile($tmp . '/copy_a.mp4', 'MANUAL', false);
    t('import_dedupe', !empty($r1['ok']) && !empty($r2['dedupe']) && (int)$r1['id'] === (int)$r2['id'],
        'id=' . ($r1['id'] ?? '?'));
    t('import_reject_format', VideoLibraryService::importFile($tmp . '/note.txt', 'MANUAL', false)['error'] === 'UNSUPPORTED_FORMAT');
    t('import_reject_missing', VideoLibraryService::importFile($tmp . '/nope.mp4', 'MANUAL', false)['error'] === 'FILE_MISSING');
    t('file_stable', VideoLibraryService::fileStable($tmp . '/a.mp4', 3) === true);
    t('file_stable_missing', VideoLibraryService::fileStable($tmp . '/nope.mp4', 1) === false);

    // ---- 2. Folder import (de quy)
    $f = VideoLibraryService::importFolder($tmp, true, true, null, false, false);
    t('folder_import', $f['scanned'] >= 4 && $f['imported'] >= 2 && $f['duplicate'] >= 1,
        json_encode($f));

    // ---- 3. CSV validation counts
    $csv = "file,title,channel\n" . $tmp . "/b.mov,T/sub,9061\n" . $tmp . "/ghost.mp4,X,9061\n"
        . $tmp . "/a.mp4,Y,999999\n" . $tmp . "/sub/c.webm,Z,9061";
    $c = VideoLibraryService::importCsv($csv, false, false);
    t('csv_counts', $c['rows'] === 4 && $c['missing_files'] === 1 && $c['invalid_channels'] === 1,
        json_encode($c));

    // ---- 4. Assign/order/counts
    $ids = [];
    try {
        $rows = db()->prepare('SELECT id FROM video_assets WHERE source_path LIKE ? ORDER BY id');
        $rows->execute([likeTmp($tmp)]);
        $ids = array_map(fn($r) => (int)$r['id'], $rows->fetchAll());
    } catch (Throwable $e) {
    }
    $a = ChannelQueueService::assign($pid, $ids);
    t('assign', $a['added'] === count($ids), 'added=' . $a['added'] . '/' . count($ids));
    $a2 = ChannelQueueService::assign($pid, $ids);
    t('assign_idempotent', $a2['added'] === 0 && $a2['existed'] === count($ids));
    $cnt = ChannelQueueService::counts($pid);
    t('queue_counts', $cnt['ready'] === count($ids) && $cnt['total'] === count($ids), json_encode($cnt));

    // ---- 5. Reserve atomic: 2 lan -> 1 thang, release -> lai duoc
    $n1 = ChannelQueueService::next($pid, 'MANUAL', 'job-1');
    $n2 = ChannelQueueService::next($pid, 'MANUAL', 'job-2');
    // n2 co the lay video KHAC (con READY) — dam bao khong trung video voi n1
    $sameVideo = ($n1 && $n2 && (int)$n1['video_asset_id'] === (int)$n2['video_asset_id']);
    t('reserve_no_double_pick', $n1 !== null && !$sameVideo,
        'n1=' . ($n1['video_asset_id'] ?? '?') . ' n2=' . ($n2['video_asset_id'] ?? 'none'));
    if ($n1) ChannelQueueService::release((int)$n1['id']);
    if ($n2) ChannelQueueService::release((int)$n2['id']);

    // ---- 6. No-dup: asset da SCHEDULED thi next() bo qua
    $all = ChannelQueueService::list($pid, 1, 50);
    $firstAid = (int)($all['rows'][0]['video_asset_id'] ?? 0);
    try {
        db()->prepare("INSERT INTO video_uploads (profile_id, video_asset_id, provider, status) VALUES (?,?,'SIMULATED','SCHEDULED')")
            ->execute([$pid, $firstAid]);
    } catch (Throwable $e) {
    }
    $picked = [];
    for ($i = 0; $i < 10; $i++) {
        $nx = ChannelQueueService::next($pid, 'MANUAL', 'job-x');
        if (!$nx) break;
        $picked[] = (int)$nx['video_asset_id'];
        ChannelQueueService::release((int)$nx['id']);
    }
    t('no_dup_scheduled', !in_array($firstAid, $picked, true), 'picked=' . implode(',', $picked));
    try {
        db()->prepare("DELETE FROM video_uploads WHERE profile_id=? AND video_asset_id=? AND status='SCHEDULED'")->execute([$pid, $firstAid]);
    } catch (Throwable $e) {
    }

    // ---- 7. Config bulk upsert + slots
    $b = UploadStore::bulkSave([$pid], ['upload_enabled' => 1, 'daily_enabled' => 1,
        'publish_slots' => ['19:30'], 'videos_per_day' => 1, 'pre_upload_minutes' => 180,
        'upload_provider' => 'SIMULATED']);
    t('bulk_config', $b['created'] === 1 && $b['updated'] === 0 && $b['failed'] === [], json_encode($b));
    $b2 = UploadStore::bulkSave([$pid], ['videos_per_day' => 1]);
    t('bulk_config_update', $b2['created'] === 0 && $b2['updated'] === 1);
    $s1 = UploadPlanner::ensureSlots($pid, 14);
    $s2 = UploadPlanner::ensureSlots($pid, 14);
    t('slots_idempotent', $s1 === 14 && $s2 === 0, "s1=$s1 s2=$s2");

    // ---- 8. Doi gio refresh slots (chi xoa PLANNED *tuong lai*; slot hom nay da qua thi giu)
    UploadStore::saveConfig($pid, array_merge(UploadStore::getConfig($pid), ['publish_slots' => ['20:00']]));
    $rc = UploadPlanner::refreshSlots($pid, 14);
    $has1930 = 0;
    $has2000 = 0;
    try {
        $has1930 = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules WHERE profile_id=$pid AND TIME(publish_at)='19:30:00' AND publish_at>NOW() AND status='PLANNED'")->fetchColumn();
        $has2000 = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules WHERE profile_id=$pid AND TIME(publish_at)='20:00:00' AND status='PLANNED'")->fetchColumn();
    } catch (Throwable $e) {
    }
    t('config_change_refresh_slots', $has1930 === 0 && $has2000 >= 13, "future1930=$has1930 2000=$has2000");

    // ---- 9. Metadata render
    $m = UploadStore::renderMetadata(['title_template' => '{filename} | {channel_name} {date} #{index}', 'description_template' => 'Hi', 'tags' => 'a, b', 'category_id' => '22', 'visibility' => 'SCHEDULED', 'playlist_id' => '', 'language' => 'vi'],
        ['filename' => 'video01', 'channel_name' => 'Kenh 5', 'date' => '2026-01-01', 'index' => '3']);
    t('metadata_render', $m['title'] === 'video01 | Kenh 5 2026-01-01 #3' && $m['tags'] === ['a', 'b'], $m['title']);

    // ---- 10. Simulated upload flow + resume data
    $prov = new SimulatedProvider();
    $prog = [];
    $up = $prov->uploadVideo($pid, $tmp . '/b.mov', [], '', 0, function ($s, $t) use (&$prog) {
        $prog[] = [$s, $t];
    });
    t('sim_upload_ok', !empty($up['ok']) && !empty($up['provider_video_id']) && end($prog)[0] === end($prog)[1]);
    $upFail = $prov->uploadVideo($pid, $tmp . '/b.mov', [], 'sess_FAIL_AT_50', 0, function () {
    });
    t('sim_fail_transient', empty($upFail['ok']) && ($upFail['error_code'] ?? '') === 'NETWORK_ERROR'
        && ($upFail['bytes_uploaded'] ?? 0) > 0 && empty($upFail['fatal']));
    // Resume tu offset giua file (deterministic, khong phu thuoc fail point)
    $prog2 = [];
    $re = $prov->uploadVideo($pid, $tmp . '/b.mov', [], '', 8192, function ($s, $t) use (&$prog2) {
        $prog2[] = $s;
    });
    t('sim_resume', !empty($re['ok']) && ($prog2[0] ?? 0) >= 8192, 'first_progress=' . ($prog2[0] ?? '?'));
    t('sim_schedule', $prov->schedulePublish($pid, 'sim_x', date('Y-m-d H:i:s'), [])['ok'] === true);

    // ---- 11. Settle publish (simulated, slot qua gio -> PUBLISHED + consume)
    try {
        $aid2 = $ids[0];
        db()->prepare("INSERT INTO video_uploads (profile_id, video_asset_id, provider, status, provider_video_id) VALUES (?,?,'SIMULATED','SCHEDULED','sim_t')")->execute([$pid, $aid2]);
    } catch (Throwable $e) {
    }
    try {
        $uid = (int)db()->query("SELECT id FROM video_uploads WHERE profile_id=$pid AND provider_video_id='sim_t' ORDER BY id DESC LIMIT 1")->fetchColumn();
        db()->prepare("INSERT INTO video_publish_schedules (profile_id, video_asset_id, upload_id, publish_at, status) VALUES (?,?,?,DATE_SUB(NOW(), INTERVAL 5 MINUTE),'SCHEDULED')")
            ->execute([$pid, $aid2, $uid]);
        $n = PublishScheduler::settleDuePublishes();
        $stt = db()->query("SELECT status FROM video_publish_schedules WHERE upload_id=$uid")->fetchColumn();
        t('settle_publish', $n >= 1 && $stt === 'PUBLISHED', "n=$n status=$stt");
    } catch (Throwable $e) {
        t('settle_publish', false, $e->getMessage());
    }

    // ---- 12. Preflight: missing file + source changed
    $pf1 = UploadPlanner::preflight($pid, ['source_path' => $tmp . '/ghost.mp4', 'checksum' => 'x']);
    t('preflight_missing', in_array('FILE_MISSING', $pf1['errors'], true));
    file_put_contents($tmp . '/chg.mp4', 'v1-content-padding-1234567890');
    $ri = VideoLibraryService::importFile($tmp . '/chg.mp4', 'MANUAL', false);
    file_put_contents($tmp . '/chg.mp4', 'v2-DIFFERENT-content-padding-xyz');
    $pf2 = UploadPlanner::preflight($pid, ['source_path' => $tmp . '/chg.mp4', 'checksum' => 'deadbeef']);
    t('preflight_changed', in_array('SOURCE_CHANGED', $pf2['errors'], true));

    // ---- 13. KPIs + health shape
    $k = PublishScheduler::kpis();
    t('kpis_shape', isset($k['channels'], $k['ready'], $k['uploading'], $k['scheduled'], $k['published_today'], $k['failed'], $k['low']));
} finally {
    cleanup_upl($tmp, $pid);
}

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
