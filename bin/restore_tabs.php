<?php
declare(strict_types=1);
/**
 * bin/restore_tabs.php - ACTIVATOR + VERIFIER sau prelaunch restore (fire-and-forget).
 * Dung: php -f bin/restore_tabs.php -- <profileId> [snapshotFile]
 * URLs DA inject vao Chrome command luc Popen -> o day KHONG navigate/create lan 2.
 * Chi: poll CDP + settle (250/500/1000ms, khong cho page load) -> VERIFY du
 *   so tab + dung thu tu (saved tab_index la source of truth) -> RECOVER chi
 *   URL thieu (khong duplicate) -> bringToFront active cu -> ghi tabverify file.
 * Chinh tien trinh 1-lan nay la session_restore guard (khong restore 2 lan).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$profileId = (int)($argv[1] ?? 0);
if ($profileId <= 0) exit(2);

// Guard chong autosave de len transient state (§4): lock ton tai = RESTORING,
// xoa khi SESSION_READY (verify xong) hoac luc thoat. Autosave bo qua lock tuoi <120s.
$guardFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_srestore_' . $profileId . '.lock';
@file_put_contents($guardFile, (string)getmypid());

$snap = null;
if (!empty($argv[2]) && is_file((string)$argv[2])) {
    $raw = (string)@file_get_contents((string)$argv[2]);
    @unlink((string)$argv[2]);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $j = json_decode(trim($raw), true);
    if (is_array($j)) $snap = $j;
}
if (!is_array($snap) || empty($snap['urls'])) { @unlink($guardFile); exit(0); }
$expected = array_values(array_filter(array_map(fn($u) => trim((string)$u), $snap['urls'])));
if (!$expected) { @unlink($guardFile); exit(0); }
$activeUrl = isset($snap['activeUrl']) ? trim((string)$snap['activeUrl']) : null;

/** Normalize URL de so khop active tab (host lower + bo / cuoi). */
function norm_tab_url(string $u): string
{
    $p = parse_url(strtolower(trim($u)));
    if (!is_array($p) || empty($p['host'])) return strtolower(rtrim(trim($u), '/'));
    $s = $p['host'] . ($p['path'] ?? '');
    if (!empty($p['query'])) $s .= '?' . $p['query'];
    return rtrim($s, '/');
}

/** Tab co the dem duoc: http(s) that (loai about:blank/chrome://). */
function countable_tab(array $t): bool
{
    $u = strtolower(trim((string)($t['url'] ?? '')));
    return str_starts_with($u, 'http://') || str_starts_with($u, 'https://');
}

function write_tabverify(int $profileId, int $expected, int $actual, string $state): void
{
    @file_put_contents(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tabverify_' . $profileId . '.json',
        json_encode(['expected' => $expected, 'actual' => $actual, 'state' => $state, 'ts' => microtime(true)], JSON_UNESCAPED_UNICODE));
}

try {
    // Doi CDP ready + du targets (poll 100ms, toi da 15s)
    $port = 0;
    $targets = [];
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        try {
            $st = db()->prepare('SELECT debug_port FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $port = (int)($st->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            $port = 0;
        }
        if ($port > 0 && cdp_reachable($port)) {
            $targets = cdp_page_targets($port);
            if (count($targets) >= count($expected)) break;
        } else {
            $port = 0;
        }
        usleep(100000);
    }
    // Settle window: Chrome vua start co the chua tao het target frame dau (§18)
    $allPages = [];
    if ($port > 0) {
        foreach ([250000, 500000, 1000000] as $waitUs) {
            $allPages = array_values(array_filter(cdp_page_targets($port), 'countable_tab'));
            if (count($allPages) >= count($expected)) break;
            usleep($waitUs);
            $allPages = array_values(array_filter(cdp_page_targets($port), 'countable_tab'));
            if (count($allPages) >= count($expected)) break;
        }
    }
    $n = count($allPages);
    // VERIFY: saved tab_index la source of truth (khong dung CDP target order) (§16).
    // /json/list order khong dam bao = creation order -> order chi la WARNING
    // (launch command giu dung thu tu A|B|C|D); set-match moi quyet dinh OK.
    $haveSet = [];
    foreach ($allPages as $t) $haveSet[norm_tab_url((string)($t['url'] ?? ''))] = true;
    $setOk = true;
    foreach ($expected as $eu) {
        if (!isset($haveSet[norm_tab_url($eu)])) {
            $setOk = false;
            break;
        }
    }
    $orderOk = $setOk;
    if ($setOk && $n === count($expected) && $n > 0) {
        foreach ($expected as $i => $eu) {
            if (!isset($allPages[$i]) || norm_tab_url((string)($allPages[$i]['url'] ?? '')) !== norm_tab_url($eu)) {
                $orderOk = false;
                break;
            }
        }
        if (!$orderOk) {
            SyncLogger::info('tab_session', '[TAB ORDER] profile=' . $profileId
                . ' count OK nhung /json/list order khac saved order (warning only)', $profileId);
        }
    }
    if (!$setOk) {
        SyncLogger::warn('tab_session', '[TAB VERIFY] profile=' . $profileId
            . ' expected=' . count($expected) . ' actual=' . $n, $profileId);
    }
    echo date('H:i:s') . " [CDP] #$profileId found $n targets (expected " . count($expected) . ")"
        . ($orderOk ? ' OK' : ' ORDER/MISSING') . "\n";
    // RECOVER missing (§19): chi create URL thieu, khong duplicate, khong launch lai
    if ($n < count($expected) && $port > 0) {
        $have = [];
        foreach ($allPages as $t) $have[norm_tab_url((string)($t['url'] ?? ''))] = true;
        $missing = [];
        foreach ($expected as $eu) {
            if (!isset($have[norm_tab_url($eu)])) $missing[] = $eu;
        }
        $recovered = 0;
        foreach (array_slice($missing, 0, 8) as $mu) {
            $r = cdp_http($port, 'PUT', '/json/new?' . urlencode($mu), 2000);
            if ($r !== null) $recovered++;
            usleep(150000);
        }
        SyncLogger::info('tab_session', '[TAB RECOVER] profile=' . $profileId
            . ' missing=' . count($missing) . ' recovered=' . $recovered, $profileId);
        echo date('H:i:s') . " [RECOVER] #$profileId missing " . count($missing) . " recovered $recovered\n";
        // Verify lai 1 lan (1s)
        usleep(1000000);
        $allPages = array_values(array_filter(cdp_page_targets($port), 'countable_tab'));
        $n = count($allPages);
    }
    // Luu actual session khi complete de UI count trung thuc (§21);
    // thieu tab -> GIU last_good (khong ghi de mat URL cho lan mo sau) (§64)
    $finalState = ($setOk && $n === count($expected)) ? 'OK' : 'INCOMPLETE';
    if ($finalState === 'OK') {
        try {
            require_once __DIR__ . '/../sync/TabSessionStore.php';
            require_once __DIR__ . '/../sync/TabSessionManager.php';
            $live = TabSessionStore::readLive($profileId, $port);
            if ($live !== null) {
                TabSessionStore::save($profileId, TabSessionManager::buildSnapshot($live['tabs'], (int)$live['activeIndex']));
            }
        } catch (Throwable $e) {
        }
    }
    write_tabverify($profileId, count($expected), $n, $finalState);
    SyncLogger::info('tab_session', '[TAB VERIFY] profile=' . $profileId
        . ' expected=' . count($expected) . ' actual=' . $n . ' ' . $finalState, $profileId);
    // Active dung tab cu: KHONG navigate/reload, chi bringToFront
    if ($activeUrl !== null && $activeUrl !== '' && $port > 0) {
        $want = norm_tab_url($activeUrl);
        foreach ($allPages as $t) {
            if (norm_tab_url((string)($t['url'] ?? '')) === $want && !empty($t['webSocketDebuggerUrl'])) {
                cdp_ws_send($port, (string)$t['webSocketDebuggerUrl'],
                    json_encode(['id' => 10, 'method' => 'Page.bringToFront']));
                SyncLogger::info('tab_session', "[SESSION] #$profileId active tab restored", $profileId);
                break;
            }
        }
    }
    // SESSION_READY: xong -> xoa guard de autosave chay lai
    @unlink($guardFile);
} catch (Throwable $e) {
    // activator loi khong anh huong Chrome (tabs da mo san tu launch)
    @unlink($guardFile);
}
exit(0);
