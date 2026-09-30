<?php
declare(strict_types=1);
// ChromeBatchManager engine regression tests (START verified count + STOP one-click).
// Khong mo Chrome that: dung fixture profiles + batch files gia lap.
// Chay: php tests/backend/test_chrome_batch_engine.php
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/ChromeBatchManager.php';

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

$tmp = rtrim(sys_get_temp_dir(), '/\\');
$ids = [9021, 9022];
function cleanup_engine(array $ids, string $tmp, array $batches) {
    $in = implode(',', array_map('intval', $ids));
    try { db()->exec("DELETE FROM profiles WHERE id IN ($in)"); } catch (Throwable $e) {}
    foreach ($ids as $id) {
        @unlink($tmp . DIRECTORY_SEPARATOR . 'ytm_life_' . $id . '.json');
        @unlink($tmp . DIRECTORY_SEPARATOR . 'ytm_gen_' . $id . '.json');
        @unlink($tmp . DIRECTORY_SEPARATOR . 'ytm_guard_' . $id . '.json');
    }
    foreach ($batches as $f) @unlink($f);
}

$batches = [];
try {
    cleanup_engine($ids, $tmp, []);
    foreach ($ids as $id) {
        db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status, debug_port) VALUES (?,?,?,?,?)')
            ->execute([$id, 'TESTENG #' . $id, 'C:\\tmp\\ytm-eng-fake-' . $id, 'stopped', 0]);
    }

    // ---- TEST 1: Popen/dispatched != RUNNING (STARTING, chua verify -> running=0, done=false)
    $bid1 = 'teng1_' . substr(md5((string)microtime(true)), 0, 6);
    $f1 = $tmp . DIRECTORY_SEPARATOR . 'ytm_batch_open_' . $bid1 . '.json';
    $batches[] = $f1;
    @file_put_contents($f1, json_encode([
        'batch_id' => $bid1, 'ids' => $ids,
        'plans' => [], 'states' => ['9021' => 'STARTING'],
        'launched_at' => ['9021' => microtime(true)],
        't0' => microtime(true), 'status' => 'running',
    ], JSON_UNESCAPED_UNICODE));
    $r1 = ChromeBatchManager::startPoll($bid1);
    t('popen_is_not_running', ($r1['counts']['running'] ?? -1) === 0 && ($r1['done'] ?? true) === false,
        'running=' . ($r1['counts']['running'] ?? '?') . ' done=' . var_export($r1['done'] ?? null, true));

    // ---- TEST 2: process exit during startup -> START_FAILED, never RUNNING, DB stopped
    $bid2 = 'teng2_' . substr(md5((string)microtime(true) . 'x'), 0, 6);
    $f2 = $tmp . DIRECTORY_SEPARATOR . 'ytm_batch_open_' . $bid2 . '.json';
    $batches[] = $f2;
    @file_put_contents($f2, json_encode([
        'batch_id' => $bid2, 'ids' => [9022],
        'plans' => [], 'states' => ['9022' => 'STARTING'],
        'launched_at' => ['9022' => microtime(true) - 120],
        't0' => microtime(true) - 120, 'status' => 'running',
    ], JSON_UNESCAPED_UNICODE));
    $r2 = ChromeBatchManager::startPoll($bid2);
    $st2 = null;
    try {
        $s = db()->prepare('SELECT status FROM profiles WHERE id=?');
        $s->execute([9022]);
        $st2 = $s->fetchColumn();
    } catch (Throwable $e) {}
    $err2 = '';
    try {
        $j = json_decode((string)@file_get_contents($f2), true);
        $err2 = (string)(($j['errors'] ?? [])['9022'] ?? '');
    } catch (Throwable $e) {}
    t('process_exit_is_failed_not_running', ($r2['counts']['running'] ?? -1) === 0
        && ($r2['counts']['errors'] ?? 0) >= 1 && $st2 === 'stopped' && $err2 !== '',
        "running=" . ($r2['counts']['running'] ?? '?') . " errors=" . ($r2['counts']['errors'] ?? '?')
        . " db=$st2 reason=$err2");

    // ---- TEST 3: STOP one-click — fake CLOSING, process khong ton tai -> done ngay 1 poll
    $bid3 = 'teng3_' . substr(md5((string)microtime(true) . 'y'), 0, 6);
    $f3 = $tmp . DIRECTORY_SEPARATOR . 'ytm_batch_close_' . $bid3 . '.json';
    $batches[] = $f3;
    @file_put_contents($f3, json_encode([
        'batch_id' => $bid3, 'ids' => [9021],
        'states' => ['9021' => 'CLOSING'], 't0' => microtime(true) - 10,
        'grace_ms' => 2500, 'mode' => 'safe', 'status' => 'closing',
        'forced_total' => 0,
    ], JSON_UNESCAPED_UNICODE));
    $r3 = ChromeBatchManager::stopPoll($bid3);
    t('stop_one_click_done', ($r3['closed'] ?? -1) === 1 && ($r3['done'] ?? false) === true,
        'closed=' . ($r3['closed'] ?? '?') . ' done=' . var_export($r3['done'] ?? null, true));

    // ---- TEST 4: normDir (DB vs WMI path forms)
    t('normdir', ChromeBatchManager::normDir('C:/X/Profiles\\A\\') === ChromeBatchManager::normDir('c:\\x\\profiles\\a'),
        ChromeBatchManager::normDir('C:/X/Profiles\\A\\'));

    // ---- TEST 5: stop progress semantics — closed dem theo actual exit (done=true nghia la het)
    t('stop_progress_is_actual_exit', isset($r3['closed'], $r3['total']) && $r3['total'] === 1,
        'closed=' . ($r3['closed'] ?? '?') . '/total=' . ($r3['total'] ?? '?'));

    // ---- TEST 6: multi-error batch hoan tat (khong ket o done=false)
    $bid6 = 'teng6_' . substr(md5((string)microtime(true) . 'z'), 0, 6);
    $f6 = $tmp . DIRECTORY_SEPARATOR . 'ytm_batch_open_' . $bid6 . '.json';
    $batches[] = $f6;
    @file_put_contents($f6, json_encode([
        'batch_id' => $bid6, 'ids' => $ids,
        'plans' => [], 'states' => ['9021' => 'ERROR', '9022' => 'STARTING'],
        'errors' => ['9021' => 'WINDOW_TIMEOUT'],
        'launched_at' => ['9022' => microtime(true) - 120],
        't0' => microtime(true) - 120, 'status' => 'running',
    ], JSON_UNESCAPED_UNICODE));
    $r6 = ChromeBatchManager::startPoll($bid6);
    t('multi_error_completes', ($r6['done'] ?? false) === true
        && ($r6['counts']['running'] ?? -1) === 0 && ($r6['counts']['errors'] ?? 0) === 2,
        'done=' . var_export($r6['done'] ?? null, true) . ' errors=' . ($r6['counts']['errors'] ?? '?'));
} finally {
    cleanup_engine($ids, $tmp, $batches);
}

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
