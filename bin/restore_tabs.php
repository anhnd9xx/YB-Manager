<?php
declare(strict_types=1);
/**
 * bin/restore_tabs.php - ACTIVATOR sau prelaunch restore (fire-and-forget).
 * Dung: php -f bin/restore_tabs.php -- <profileId> [snapshotFile]
 * URLs DA inject vao Chrome command luc Popen -> o day KHONG navigate/create.
 * Chi: poll CDP (100ms, toi da 15s) -> dem targets so voi expected (lech -> WARNING)
 *   -> bringToFront dung tab active cu -> thoat.
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
    // Dem that de log WARNING neu lech (spec muc 16)
    $allPages = [];
    if ($port > 0) {
        foreach (cdp_page_targets($port) as $t) $allPages[] = $t;
    }
    $n = count($allPages);
    if ($n !== count($expected)) {
        SyncLogger::warn('tab_session', '[SESSION WARNING] Expected ' . count($expected)
            . ' tabs, found ' . $n . " (#$profileId)", $profileId);
    }
    echo date('H:i:s') . " [CDP] #$profileId found $n targets (expected " . count($expected) . ")\n";
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
