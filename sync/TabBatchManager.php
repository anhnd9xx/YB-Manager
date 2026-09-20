<?php
declare(strict_types=1);
/**
 * TabBatchManager - thao tac NHIEU tab trong 1 lan goi duy nhat.
 *
 *   open_tabs(profileId, urls, preserve_order=true)
 *   close_tabs(profileId, targetIds)
 *   close_all_tabs(profileId, keep_one=false)
 *   restore_tabs(profileId, session)
 *   cancel_batch(profileId) / get_batch_state(profileId)
 *
 * Nguyen tac:
 *  - UI goi 1 lan, KHONG loop for url -> open_tab.
 *  - 1 CdpConnectionManager / batch (browser-level WS), KHONG reconnect tung tab.
 *  - 1 snapshot Target.getTargets duy nhat, KHONG /json/list tung tab.
 *  - ORDERED FAST CREATE: gui Target.createTarget tuan tu, await ACK ngan (2s),
 *    KHONG doi page load, KHONG sleep giua cac tab -> giu thu tu visual.
 *  - preserve_order=false: van tuan tu (don gian, deterministic); concurrency
 *    cau hinh chi gioi han kich thuoc chunk close (khong thread/tab).
 *  - Dong: fire Target.closeTarget lien tiep tren cung socket, batched.
 *  - Chi activate 1 lan cuoi batch (NONE|FIRST|LAST), khong activate tung tab,
 *    khong SetForegroundWindow.
 *  - Save session 1 LAN cuoi batch. Autosave bi chan giua batch (flag file).
 *  - Cancel: file flag -> dung phan con lai; close_all giua open se dong so da tao.
 *  - Moi command timeout 2s; 1 tab loi -> mark ERROR, batch van tiep tuc.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CdpConnectionManager.php';
require_once __DIR__ . '/TabSessionManager.php';
require_once __DIR__ . '/TabSessionStore.php';
require_once __DIR__ . '/SyncLogger.php';

class TabBatchManager
{
    public const TAB_COMMAND_TIMEOUT_MS = 2000;
    /** So close dispatch toi da moi chunk (khong thread, chi gioi han batch size). */
    public const TAB_CLOSE_CONCURRENCY = 8;

    public static function batchFile(int $profileId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tabbatch_' . $profileId . '.json';
    }

    public static function isBatchActive(int $profileId): bool
    {
        $f = self::batchFile($profileId);
        if (!is_file($f)) return false;
        // Stale > 5 phut (API chet giua chung) -> tu xoa
        if (time() - (int)@filemtime($f) > 300) {
            @unlink($f);
            return false;
        }
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) && ($j['status'] ?? '') === 'running';
    }

    public static function get_batch_state(int $profileId): array
    {
        $f = self::batchFile($profileId);
        if (!is_file($f)) return ['active' => false];
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) return ['active' => false];
        $j['active'] = ($j['status'] ?? '') === 'running';
        return $j;
    }

    public static function cancel_batch(int $profileId): bool
    {
        $f = self::batchFile($profileId);
        if (!is_file($f)) return false;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) {
            @unlink($f);
            return true;
        }
        $j['cancel'] = true;
        @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE));
        return true;
    }

    private static function batchCancelled(int $profileId): bool
    {
        $j = self::get_batch_state($profileId);
        return !empty($j['cancel']);
    }

    private static function beginBatch(int $profileId, string $type, int $total, array $extra = []): ?string
    {
        if (self::isBatchActive($profileId)) return null; // chong double-click
        $batchId = 'tb_' . date('His') . '_' . substr(md5($profileId . microtime(true)), 0, 6);
        @file_put_contents(self::batchFile($profileId), json_encode(array_merge([
            'batch_id' => $batchId, 'profile_id' => $profileId, 'type' => $type,
            'status' => 'running', 'total' => $total, 'completed' => 0,
            'failed' => 0, 'cancelled' => 0, 'cancel' => false, 't0' => microtime(true),
        ], $extra), JSON_UNESCAPED_UNICODE));
        return $batchId;
    }

    private static function progress(int $profileId, int $completed, int $failed = 0, int $cancelled = 0): void
    {
        $f = self::batchFile($profileId);
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) return;
        $j['completed'] = $completed;
        $j['failed'] = $failed;
        $j['cancelled'] = $cancelled;
        @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE));
    }

    private static function endBatch(int $profileId, array $patch = []): array
    {
        $f = self::batchFile($profileId);
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) $j = [];
        $j = array_merge($j, ['status' => 'done', 't1' => microtime(true)], $patch);
        if (isset($j['t0'])) $j['ms'] = (int)round(((float)$j['t1'] - (float)$j['t0']) * 1000);
        @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE));
        return $j;
    }

    private static function profilePort(int $profileId): ?array
    {
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $p = $st->fetch();
            if (!$p) return null;
            $port = (int)($p['debug_port'] ?? 0);
            if (($p['status'] ?? '') !== 'running' || !cdp_reachable($port)) return null;
            return [$p, $port];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function pageTargets(array $infos): array
    {
        $out = [];
        foreach ($infos as $t) {
            if (!is_array($t)) continue;
            if (($t['type'] ?? '') !== 'page') continue;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Mo nhieu tab. $opts: preserve_order(true), activate(NONE|FIRST|LAST),
     *   avoid_duplicate_tabs(false), blankTargetId (dung tab blank san cho URL dau).
     */
    public static function open_tabs(int $profileId, array $urls, array $opts = []): array
    {
        $t0 = microtime(true);
        $preserve = !array_key_exists('preserve_order', $opts) || !empty($opts['preserve_order']);
        $activate = strtoupper((string)($opts['activate'] ?? 'LAST'));
        if (!in_array($activate, ['NONE', 'FIRST', 'LAST'], true)) $activate = 'LAST';
        $dedup = !empty($opts['avoid_duplicate_tabs']);

        $clean = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u !== '' && TabSessionManager::is_restorable_url($u)) $clean[] = $u;
        }
        if (!$clean) return ['ok' => false, 'message' => 'Khong co URL hop le'];
        // $preserve chi anh huong thu tu gui (luon tuan tu de deterministic);
        // khong parallel that -> khong dao visual. Giu tham so de tuong thich spec.
        unset($preserve);

        $pp = self::profilePort($profileId);
        if ($pp === null) return ['ok' => false, 'message' => 'Chrome chua chay'];
        [$prof, $port] = $pp;

        $batchId = self::beginBatch($profileId, 'OPEN', count($clean));
        if ($batchId === null) return ['ok' => false, 'message' => 'Dang co batch khac chay', 'duplicate' => true];

        $mgr = CdpConnectionManager::forPort($port);
        if ($mgr === null || !$mgr->isOk()) {
            self::endBatch($profileId, ['error' => 'Khong ket noi CDP']);
            return ['ok' => false, 'message' => 'Khong ket noi CDP'];
        }
        // 1 snapshot duy nhat cho dedup (khong query tung URL)
        $existing = [];
        if ($dedup) {
            $infos = $mgr->getTargets() ?? [];
            foreach (self::pageTargets($infos) as $t) {
                $u = trim((string)($t['url'] ?? ''));
                if ($u !== '') $existing[$u] = true;
            }
        }
        $blankId = (string)($opts['blankTargetId'] ?? '');
        $created = [];
        $failed = 0;
        $cancelled = 0;
        $tCreate = microtime(true);
        foreach ($clean as $i => $url) {
            if (self::batchCancelled($profileId)) {
                $cancelled = count($clean) - $i;
                break;
            }
            if ($dedup && isset($existing[$url])) continue;
            $tid = $mgr->createTarget($url); // ACK ngan, khong doi load, khong sleep
            if ($tid !== null) {
                $created[] = $tid;
                if ($dedup) $existing[$url] = true;
            } else {
                $failed++;
            }
            self::progress($profileId, count($created), $failed);
        }
        $msCreate = (int)round((microtime(true) - $tCreate) * 1000);
        // Activate 1 LAN cuoi batch (khong activate tung tab -> khong nhap nhay)
        if ($activate !== 'NONE' && $created) {
            $target = $activate === 'FIRST' ? $created[0] : $created[count($created) - 1];
            $mgr->activateTarget($target);
        }
        // Don tab blank thua (neu co): 1 close tren cung socket, khong thua New Tab
        if ($blankId !== '' && $created) {
            $mgr->closeTarget($blankId);
        }
        $mgr->disconnect();
        // Save session 1 LAN cuoi (khong save tung tab)
        try {
            $snap = TabSessionStore::snapshotLive($profileId, $port, false);
            if ($snap !== null) TabSessionStore::save($profileId, $snap);
        } catch (Throwable $e) {
        }
        $msTotal = (int)round((microtime(true) - $t0) * 1000);
        SyncLogger::info('tab_batch', "[TAB BATCH] profile=#$profileId open=" . count($created)
            . ' fail=' . $failed . ' cancelled=' . $cancelled, $profileId);
        SyncLogger::info('tab_batch_perf', "[PERF] create commands={$msCreate}ms total={$msTotal}ms targets=" . count($created), $profileId);
        try {
            log_action($profileId, 'tab_batch_open', count($created) . ' tabs');
        } catch (Throwable $e) {
        }
        $st = self::endBatch($profileId, ['created' => $created, 'failedCount' => $failed, 'cancelledCount' => $cancelled]);
        return ['ok' => true, 'batch_id' => $batchId, 'created' => $created,
                'count' => count($created), 'failed' => $failed, 'cancelled' => $cancelled,
                'ms' => $msTotal, 'msCreate' => $msCreate, 'state' => $st];
    }

    /** Dong nhieu tab theo targetIds (1 snapshot, fire lien tiep, 1 save cuoi). */
    public static function close_tabs(int $profileId, array $targetIds): array
    {
        $t0 = microtime(true);
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $targetIds))));
        if (!$ids) return ['ok' => false, 'message' => 'Chua chon tab nao'];
        $pp = self::profilePort($profileId);
        if ($pp === null) return ['ok' => false, 'message' => 'Chrome chua chay'];
        [$prof, $port] = $pp;
        $batchId = self::beginBatch($profileId, 'CLOSE', count($ids));
        if ($batchId === null) return ['ok' => false, 'message' => 'Dang co batch khac chay', 'duplicate' => true];

        $mgr = CdpConnectionManager::forPort($port);
        if ($mgr === null || !$mgr->isOk()) {
            self::endBatch($profileId, ['error' => 'Khong ket noi CDP']);
            return ['ok' => false, 'message' => 'Khong ket noi CDP'];
        }
        $tDisp = microtime(true);
        $closed = 0;
        $failed = 0;
        // Bounded chunks (8) nhung tren cung socket tuan tu nhanh (khong thread)
        foreach (array_chunk($ids, self::TAB_CLOSE_CONCURRENCY) as $chunk) {
            if (self::batchCancelled($profileId)) break;
            foreach ($chunk as $tid) {
                if ($mgr->closeTarget($tid)) $closed++;
                else $failed++;
            }
            self::progress($profileId, $closed, $failed);
        }
        $msDisp = (int)round((microtime(true) - $tDisp) * 1000);
        $mgr->disconnect();
        try {
            $snap = TabSessionStore::snapshotLive($profileId, $port, false);
            if ($snap !== null) TabSessionStore::save($profileId, $snap);
        } catch (Throwable $e) {
        }
        $msTotal = (int)round((microtime(true) - $t0) * 1000);
        SyncLogger::info('tab_batch', "[TAB BATCH] profile=#$profileId close=$closed fail=$failed", $profileId);
        SyncLogger::info('tab_batch_perf', "[PERF] dispatch close " . count($ids) . "={$msDisp}ms total={$msTotal}ms", $profileId);
        try {
            log_action($profileId, 'tab_batch_close', $closed . ' tabs');
        } catch (Throwable $e) {
        }
        $st = self::endBatch($profileId, ['closedCount' => $closed, 'failedCount' => $failed]);
        return ['ok' => true, 'batch_id' => $batchId, 'closed' => $closed,
                'failed' => $failed, 'ms' => $msTotal, 'msDispatch' => $msDisp, 'state' => $st];
    }

    public static function close_all_tabs(int $profileId, bool $keepOne = false): array
    {
        $pp = self::profilePort($profileId);
        if ($pp === null) return ['ok' => false, 'message' => 'Chrome chua chay'];
        [$prof, $port] = $pp;
        $mgr = CdpConnectionManager::forPort($port);
        if ($mgr === null || !$mgr->isOk()) return ['ok' => false, 'message' => 'Khong ket noi CDP'];
        $infos = $mgr->getTargets() ?? [];
        $mgr->disconnect();
        $pages = self::pageTargets($infos);
        $ids = array_map(fn($t) => (string)($t['targetId'] ?? $t['id'] ?? ''), $pages);
        $ids = array_values(array_filter($ids));
        if ($keepOne && count($ids) > 1) {
            // Giu tab active hien tai (cuoi danh sach sau khi dao) hoac tab dau
            array_shift($ids);
        }
        if (!$ids) return ['ok' => true, 'closed' => 0, 'count' => 0];
        return self::close_tabs($profileId, $ids);
    }

    /** Restore session (ordered, active 1 lan). Dung cho Chrome DANG chay. */
    public static function restore_tabs(int $profileId): array
    {
        $r = TabSessionStore::getRestorable($profileId);
        if (($r['status'] ?? 'none') === 'empty') return ['ok' => false, 'message' => 'Session rong (khong co tab de khoi phuc)'];
        if (($r['status'] ?? 'none') !== 'session') return ['ok' => false, 'message' => 'Khong co session de khoi phuc'];
        $sess = $r['session'];
        $pp = self::profilePort($profileId);
        if ($pp === null) return ['ok' => false, 'message' => 'Chrome chua chay'];
        [$prof, $port] = $pp;
        // Tim tab blank hien tai bang 1 snapshot (khong list lap)
        $mgr0 = CdpConnectionManager::forPort($port);
        $blankId = '';
        if ($mgr0 !== null && $mgr0->isOk()) {
            foreach (self::pageTargets($mgr0->getTargets() ?? []) as $t) {
                $u = (string)($t['url'] ?? '');
                if (!TabSessionManager::is_restorable_url($u)) {
                    $blankId = (string)($t['targetId'] ?? '');
                    break;
                }
            }
            $mgr0->disconnect();
        }
        $urls = [];
        foreach ((array)($sess['tabs'] ?? []) as $t) {
            $u = trim((string)($t['url'] ?? ''));
            if (TabSessionManager::is_restorable_url($u)) $urls[] = $u;
        }
        if (!$urls) return ['ok' => false, 'message' => 'Session rong'];
        // Mo batch ordered; active = tab active cu (map sang FIRST/LAST gan nhat)
        $res = self::open_tabs($profileId, $urls, ['preserve_order' => true, 'activate' => 'NONE', 'blankTargetId' => $blankId]);
        if (empty($res['ok'])) return $res;
        // Activate dung tab active cu 1 LAN (khong tung tab)
        try {
            $ai = min((int)$sess['active_index'], count($res['created'] ?? []) - 1);
            $tid = $ai >= 0 ? (string)(($res['created'] ?? [])[$ai] ?? '') : '';
            if ($tid !== '') {
                $m2 = CdpConnectionManager::forPort($port);
                if ($m2 !== null && $m2->isOk()) {
                    $m2->activateTarget($tid);
                    $m2->disconnect();
                }
            }
        } catch (Throwable $e) {
        }
        try {
            log_action($profileId, 'tab_restore', count($res['created'] ?? []) . ' tabs');
        } catch (Throwable $e) {
        }
        return $res;
    }
}
