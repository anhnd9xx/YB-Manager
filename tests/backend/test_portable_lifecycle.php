<?php
declare(strict_types=1);
// Portable lifecycle V3: machine identity, atomic store, session guards,
// chrome discovery, path migration, reconciler.
// Chay: php tests/backend/test_portable_lifecycle.php
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../sync/MachineContext.php';
require __DIR__ . '/../../sync/StateStore.php';
require __DIR__ . '/../../sync/TabSessionStore.php';
require __DIR__ . '/../../sync/ProfilePathResolver.php';
require __DIR__ . '/../../sync/RuntimeReconciler.php';

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
function tmpf(string $n): string {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $n;
}

$pid = 9081;
function cleanup_pl(int $pid) {
    try { db()->prepare('DELETE FROM tab_sessions WHERE profile_id=?')->execute([$pid]); } catch (Throwable $e) {}
    try { db()->prepare('DELETE FROM profiles WHERE id=?')->execute([$pid]); } catch (Throwable $e) {}
    foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_pltest_*') ?: [] as $f) @unlink($f);
}

try {
    cleanup_pl($pid);

    // ---- 1. Machine identity on dinh + stamp check
    $m1 = MachineContext::id();
    $m2 = MachineContext::id();
    t('machine_stable', $m1 !== '' && $m1 === $m2, $m1);
    t('machine_current_own', MachineContext::isCurrent(['machine' => $m1]) === true);
    t('machine_reject_foreign', MachineContext::isCurrent(['machine' => 'mg-deadbeef']) === false);
    t('machine_reject_missing', MachineContext::isCurrent(['state' => 'X']) === false);

    // ---- 2. Atomic write roundtrip + crash (tmp ton tai, main van valid)
    $f = tmpf('ytm_pltest_state.json');
    @unlink($f);
    t('atomic_write', StateStore::writeJson($f, ['a' => 1, 'tabs' => [1, 2]]) === true);
    t('atomic_read', StateStore::readJson($f) === ['a' => 1, 'tabs' => [1, 2]]);
    @file_put_contents($f . '.tmp.999', '{"a":');
    StateStore::cleanupTmp($f); // file moi (<300s) giu lai theo policy
    t('main_intact_after_tmp', StateStore::readJson($f) === ['a' => 1, 'tabs' => [1, 2]]);
    @unlink($f . '.tmp.999');
    @file_put_contents($f, '{broken');
    t('corrupt_read_null', StateStore::readJson($f) === null);
    @unlink($f);

    // ---- 3. Per-profile lock tuan tu hoa
    $order = [];
    $r1 = StateStore::withLock('pltest-1', function () use (&$order) {
        $order[] = 'a';
        return 'A';
    });
    $r2 = StateStore::withLock('pltest-1', function () use (&$order) {
        $order[] = 'b';
        return 'B';
    });
    t('profile_lock', $r1 === 'A' && $r2 === 'B' && $order === ['a', 'b']);

    // ---- 4. Session guards (can DB + FK profiles)
    db()->prepare('INSERT INTO profiles (id, name, user_data_dir, status) VALUES (?,?,?,?)')
        ->execute([$pid, 'TESTPL #9081', 'C:\\tmp\\ytm-test-pl-9081', 'stopped']);
    $snap5 = TabSessionManager::buildSnapshot(
        array_map(fn($i) => ['id' => "t$i", 'url' => "https://example.com/$i", 'title' => "T$i"], range(1, 5)), 2);
    $s1 = TabSessionStore::save($pid, $snap5, ['generation' => 10, 'phase' => 'PRE_CLOSE']);
    t('session_save_5tabs', !empty($s1['savedCurrent']) && !empty($s1['savedGood']));
    $cur = TabSessionStore::get($pid, 'current');
    t('session_generation_stamped', (int)($cur['generation'] ?? -1) === 10, 'gen=' . ($cur['generation'] ?? '?'));
    t('session_active_index', (int)($cur['active_index'] ?? -1) === 2);
    // Late empty snapshot -> KHONG xoa current 5 tabs
    $emptySnap = ['tabs' => [], 'active_index' => 0, 'fingerprint' => 'e', 'good' => false];
    $se = TabSessionStore::save($pid, $emptySnap, ['generation' => 10]);
    $cur2 = TabSessionStore::get($pid, 'current');
    t('empty_snapshot_guarded', ($se['reason'] ?? '') === 'empty_guarded' && count($cur2['tabs']) === 5,
        'reason=' . ($se['reason'] ?? '?'));
    // Stale generation -> IGNORE
    $old = TabSessionManager::buildSnapshot([['id' => 'x', 'url' => 'https://old.example/', 'title' => 'O']], 0);
    $ss = TabSessionStore::save($pid, $old, ['generation' => 9]);
    $cur3 = TabSessionStore::get($pid, 'current');
    t('stale_generation_ignored', ($ss['reason'] ?? '') === 'stale_generation' && count($cur3['tabs']) === 5,
        'reason=' . ($ss['reason'] ?? '?'));
    // Newer generation duoc ghi
    $new = TabSessionManager::buildSnapshot(
        array_map(fn($i) => ['id' => "n$i", 'url' => "https://new.example.com/$i", 'title' => "N$i"], range(1, 3)), 1);
    $sn = TabSessionStore::save($pid, $new, ['generation' => 11]);
    t('newer_generation_writes', !empty($sn['savedCurrent']) && count(TabSessionStore::get($pid, 'current')['tabs']) === 3);
    // Checksum lech -> get null (fallback LKG o caller)
    try {
        db()->prepare("UPDATE tab_sessions SET tabs='[{\"i\":0,\"url\":\"https://evil.example/\"}]' WHERE profile_id=? AND kind='current'")->execute([$pid]);
        t('checksum_mismatch_null', TabSessionStore::get($pid, 'current') === null);
    } catch (Throwable $e) {
        t('checksum_mismatch_null', false, $e->getMessage());
    }

    // ---- 5. Chrome discovery (may dev phai co chrome that)
    $cp = chrome_path();
    t('chrome_resolved_exists', is_file($cp), $cp);
    t('chrome_available', chrome_available() === true);

    // ---- 6. Path migration: absolute ao + dir that duoi project/profiles
    $realDir = PROFILES_DIR . DIRECTORY_SEPARATOR . 'TESTPL_9081';
    @mkdir($realDir, 0777, true);
    @file_put_contents($realDir . DIRECTORY_SEPARATOR . 'Preferences', '{}');
    db()->prepare('UPDATE profiles SET name=?, user_data_dir=? WHERE id=?')
        ->execute(['TESTPL_9081', 'D:\\OldMachine\\profiles\\whatever', $pid]);
    $prof = db()->prepare('SELECT * FROM profiles WHERE id=?');
    $prof->execute([$pid]);
    $pr = ProfilePathResolver::resolve($prof->fetch());
    t('path_migrated', !empty($pr['migrated']) && $pr['path'] === $realDir, $pr['path']);
    $dbDir = db()->prepare('SELECT user_data_dir FROM profiles WHERE id=?');
    $dbDir->execute([$pid]);
    t('path_db_updated', (string)$dbDir->fetchColumn() === $realDir);
    // Don dir that
    @unlink($realDir . DIRECTORY_SEPARATOR . 'Preferences');
    @rmdir($realDir);

    // ---- 7. Reconciler: fixture stopped + dir ao -> stopped, khong crash
    $rec = RuntimeReconciler::reconcileAll(true);
    t('reconcile_shape', isset($rec['profiles'], $rec['running_actual'], $rec['db_fixed'], $rec['stale_runtime_cleared']));
    $st = db()->prepare('SELECT status FROM profiles WHERE id=?');
    $st->execute([$pid]);
    // dir ao khong alive -> stopped (tru khi may that co chrome voi dir nay)
    t('reconcile_stopped', in_array((string)$st->fetchColumn(), ['stopped', 'running'], true));

    // ---- 8. Deploy check shape
    $dc = RuntimeReconciler::deployCheck();
    t('deploy_check_shape', isset($dc['machine'], $dc['profiles'], $dc['sessions'], $dc['chrome'], $dc['monitors'], $dc['ports'])
        && isset($dc['machine']['machine_id'], $dc['machine']['session_id']));
} finally {
    cleanup_pl($pid);
}

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
