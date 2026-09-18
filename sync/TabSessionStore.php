<?php
declare(strict_types=1);
/**
 * TabSessionStore - Persistence cho Tab Session (bang tab_sessions).
 * kind 'current' luu moi lan (fingerprint-guard o caller);
 * kind 'last_good' CHI khi snapshot good (>= 1 restorable URL).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TabSessionManager.php';

class TabSessionStore
{
    /**
     * Luu snapshot {tabs,active_index,fingerprint,good}.
     * - validate truoc: invalid (khong phai list) -> KHONG GHI GI ca (giu current+last_good).
     * - current: skip khi fingerprint giong (khong ghi disk khi khong doi).
     * - last_good: CHI khi good.
     * Tra ve ['savedCurrent'=>bool,'savedGood'=>bool].
     */
    public static function save(int $profileId, array $snap): array
    {
        $now = date('Y-m-d H:i:s');
        $v = TabSessionManager::validate_session($snap['tabs'] ?? null);
        if (!$v['valid']) return ['savedCurrent' => false, 'savedGood' => false];
        $snap['tabs'] = $v['tabs'];
        // Tat "nho tab active" -> active_index luon 0 (tinh lai fingerprint de skip-save van dung)
        try {
            $ra = get_setting('tab_remember_active', '1');
            if (!($ra === '1' || $ra === 'true')) {
                $snap['active_index'] = 0;
                $snap['fingerprint'] = TabSessionManager::fingerprint($snap['tabs'], 0);
            }
        } catch (Throwable $e) {
        }
        $tabsJson = json_encode($snap['tabs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $savedCurrent = false;
        $savedGood = false;
        try {
            // Log raw snapshot de phan biet "Chrome tra sai" vs "storage luu sai" (§10)
            $rawLines = [];
            foreach ($snap['tabs'] as $i => $t) {
                $rawLines[] = $i . ' ' . ($t['url'] ?? '');
            }
            require_once __DIR__ . '/SyncLogger.php';
            SyncLogger::debug('tab_snapshot', "[SESSION SNAPSHOT] #$profileId\n" . implode("\n", $rawLines), $profileId);
            // current: skip khi fingerprint giong (khong ghi disk khi khong doi)
            $cur = self::get($profileId, 'current');
            if ($cur === null || ($cur['fingerprint'] ?? null) !== $snap['fingerprint']) {
                db()->prepare(
                    'INSERT INTO tab_sessions (profile_id, kind, saved_at, active_index, tabs, fingerprint)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE saved_at=VALUES(saved_at), active_index=VALUES(active_index),
                     tabs=VALUES(tabs), fingerprint=VALUES(fingerprint)'
                )->execute([$profileId, 'current', $now, (int)$snap['active_index'], $tabsJson, $snap['fingerprint']]);
                $savedCurrent = true;
            }
            if (!empty($snap['good'])) {
                $lg = self::get($profileId, 'last_good');
                if ($lg === null || ($lg['fingerprint'] ?? null) !== $snap['fingerprint']) {
                    db()->prepare(
                        'INSERT INTO tab_sessions (profile_id, kind, saved_at, active_index, tabs, fingerprint)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE saved_at=VALUES(saved_at), active_index=VALUES(active_index),
                         tabs=VALUES(tabs), fingerprint=VALUES(fingerprint)'
                    )->execute([$profileId, 'last_good', $now, (int)$snap['active_index'], $tabsJson, $snap['fingerprint']]);
                    $savedGood = true;
                }
            }
        } catch (Throwable $e) {
        }
        return ['savedCurrent' => $savedCurrent, 'savedGood' => $savedGood];
    }

    /** Doc session (kind current|last_good). Tra ve null neu chua co. */
    public static function get(int $profileId, string $kind = 'current'): ?array
    {
        try {
            $st = db()->prepare('SELECT profile_id, kind, saved_at, active_index, tabs, fingerprint
                                 FROM tab_sessions WHERE profile_id=? AND kind=?');
            $st->execute([$profileId, $kind]);
            $r = $st->fetch();
            if (!$r) return null;
            $tabs = json_decode((string)($r['tabs'] ?? '[]'), true);
            return [
                'profile_id' => (int)$r['profile_id'], 'kind' => $r['kind'],
                'saved_at' => $r['saved_at'], 'active_index' => (int)$r['active_index'],
                'tabs' => is_array($tabs) ? $tabs : [], 'fingerprint' => $r['fingerprint'],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * URLs de inject vao Chrome command TRUOC launch (prelaunch restore).
     * Nhanh (1-2 SELECT, khong can Chrome chay). Tra ve null neu khong co
     * session tot. Thu tu = thu tu luu (khong sort).
     * @return array{urls:string[],activeUrl:?string}|null
     */
    public static function getUrlsForLaunch(int $profileId): ?array
    {
        $r = self::getRestorable($profileId);
        if (($r['status'] ?? 'none') !== 'session') return null;
        $sess = $r['session'];
        $urls = [];
        foreach ((array)($sess['tabs'] ?? []) as $t) {
            $u = trim((string)($t['url'] ?? ''));
            if (TabSessionManager::is_restorable_url($u)) $urls[] = $u;
        }
        if (!$urls) return null;
        $ai = min((int)$sess['active_index'], count($urls) - 1);
        return ['urls' => $urls, 'activeUrl' => $ai >= 0 ? $urls[$ai] : null];
    }
    /**
     * Session de restore, phan biet 3 trang thai (khong nham empty voi none):
     * - session: co tabs tot de mo (last_good uu tien, fallback current good).
     * - empty: current TON TAI nhung rong (user dong het tab chu dong) -> mo trang chu, KHONG mo last_good.
     * - none: chua co du lieu gi.
     * @return array{status:session|empty|none, session?:array}
     */
    public static function getRestorable(int $profileId): array
    {
        // current GOOD = tuoi nhat + tot -> uu tien; last_good la fallback khi current rong/hong
        $c = self::get($profileId, 'current');
        if ($c !== null && !empty($c['tabs'])) {
            $snap = TabSessionManager::buildSnapshot(
                array_map(fn($t) => ['id' => '', 'url' => $t['url'] ?? '', 'title' => $t['title'] ?? ''],
                    $c['tabs']), (int)$c['active_index']);
            if ($snap['good']) {
                $c['tabs'] = self::orderByIndex($c['tabs']);
                return ['status' => 'session', 'session' => $c];
            }
        }
        $g = self::get($profileId, 'last_good');
        if ($g !== null && !empty($g['tabs'])) {
            $g['tabs'] = self::orderByIndex($g['tabs']);
            return ['status' => 'session', 'session' => $g];
        }
        if ($c !== null || $g !== null) return ['status' => 'empty'];
        return ['status' => 'none'];
    }

    /** Sap tabs theo tab_index (du lieu cu khong co i van giu order). */
    private static function orderByIndex(array $tabs): array
    {
        $hasI = false;
        foreach ($tabs as $t) {
            if (is_array($t) && array_key_exists('i', $t)) {
                $hasI = true;
                break;
            }
        }
        if (!$hasI) return array_values($tabs);
        usort($tabs, fn($a, $b) => ((int)($a['i'] ?? 0)) <=> ((int)($b['i'] ?? 0)));
        return array_values($tabs);
    }

    public static function clear(int $profileId): void
    {
        try {
            db()->prepare('DELETE FROM tab_sessions WHERE profile_id=?')->execute([$profileId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Doc tabs SONG tu CDP (khong doc file session Chrome khi dang chay).
     * Tra ve ['tabs'=>[{id,url,title}], 'activeIndex'=>int] hoac null (CDP chet).
     * activeIndex: tab daemon screencast dang theo (meta.json) neu co, else tab cuoi.
     * QUAN TRONG: /json/list tra ve LIFO + lech theo activation (KHONG phai trai->phai)
     * -> dao lai de gan dung thu tu tao/tab-strip cho truong hop thuong (khong keo-tha).
     * Muon CHINH XAC tuyet doi (ke ca da keo-tha) dung trueOrderTabs().
     */
    public static function readLive(int $profileId, int $port): ?array
    {
        try {
            if ($port <= 0 || !cdp_reachable($port)) return null;
            $tabs = [];
            $raw = array_reverse(cdp_page_targets($port));
            foreach ($raw as $t) {
                $tabs[] = ['id' => (string)$t['id'], 'url' => (string)($t['url'] ?? ''),
                           'title' => (string)($t['title'] ?? '')];
            }
            $active = count($tabs) > 0 ? count($tabs) - 1 : 0;
            $mf = BASE_DIR . '/android/frames/ch' . $profileId . '/meta.json';
            if (is_file($mf)) {
                $m = json_decode((string)@file_get_contents($mf), true);
                $tracked = (string)($m['tab'] ?? '');
                if ($tracked !== '') {
                    foreach ($tabs as $i => $t) {
                        if ($t['id'] === $tracked) {
                            $active = $i;
                            break;
                        }
                    }
                }
            }
            return ['tabs' => $tabs, 'activeIndex' => $active];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Thu tu tab strip THAT (ke ca user da keo-tha): xoay active bang Ctrl+PgDn,
     * doc document.visibilityState de biet tab nao dang hien. Tra ve tabs sap
     * dung thu tu trai->phai, hoac null neu khong xac dinh duoc (window minimize,
     * qua 8 tabs, CDP loi, het budget) -> caller dung thu tu readLive.
     * Toi uu: fetch /json/list 1 LAN (endpoint nay cham khi browser ban),
     * evaluate thi nhanh; cache id->ws ca vong chay.
     * Co tac dong hien thi nhe (tab nhay) + cham (~0.5s/tab) nen CHI dung khi
     * user luu tay / pre-close, KHONG dung cho autosave dinh ky.
     * Luon tra active ve tab ban dau truoc khi return.
     * @param array{id:string,url:string,title:string}[] $tabs (tu readLive)
     * @param int $budgetMs Ngan sach toi da (0 = mac dinh 25s)
     */
    public static function trueOrderTabs(int $port, array $tabs, int $budgetMs = 0): ?array
    {
        try {
            if (count($tabs) <= 1 || count($tabs) > 8) return null;
            $deadline = microtime(true) + ($budgetMs > 0 ? min(25, $budgetMs / 1000) : 25);
            $byId = [];
            foreach ($tabs as $t) $byId[(string)$t['id']] = $t;
            // 1 list duy nhat -> map id=>ws (on dinh trong vong cycling)
            $wsMap = [];
            foreach (cdp_page_targets($port) as $t) {
                if (!empty($t['webSocketDebuggerUrl'])) $wsMap[(string)$t['id']] = (string)$t['webSocketDebuggerUrl'];
            }
            $visOf = function (string $ws) use ($port): ?string {
                $v = cdp_ws_batch($port, $ws, [json_encode(['id' => 71, 'method' => 'Runtime.evaluate',
                    'params' => ['expression' => 'document.visibilityState', 'returnByValue' => true]])], 71);
                return is_string($v) ? trim($v, '"') : null;
            };
            $activeOf = function () use (&$wsMap, $port, $byId, $visOf): ?string {
                foreach (array_keys($byId) as $id) {
                    if (!isset($wsMap[$id])) continue;
                    if ($visOf($wsMap[$id]) === 'visible') return $id;
                }
                return null;
            };
            $start = $activeOf();
            if ($start === null) return null;
            $order = [$start];
            while (count($order) < count($byId)) {
                if (microtime(true) > $deadline) return null;
                $ws = $wsMap[(string)end($order)] ?? null;
                if ($ws === null) return null;
                cdp_ws_batch($port, $ws, [
                    json_encode(['id' => 72, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => 'Control', 'code' => 'ControlLeft', 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]),
                    json_encode(['id' => 73, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'rawKeyDown', 'key' => 'PageDown', 'code' => 'PageDown', 'modifiers' => 2, 'windowsVirtualKeyCode' => 34, 'nativeVirtualKeyCode' => 34]]),
                    json_encode(['id' => 74, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => 'PageDown', 'code' => 'PageDown', 'modifiers' => 2, 'windowsVirtualKeyCode' => 34, 'nativeVirtualKeyCode' => 34]]),
                    json_encode(['id' => 75, 'method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => 'Control', 'code' => 'ControlLeft', 'windowsVirtualKeyCode' => 17, 'nativeVirtualKeyCode' => 17]]),
                ], 0);
                usleep(350000);
                $a = $activeOf();
                if ($a === null || $a === $start || in_array($a, $order, true)) break;
                $order[] = $a;
            }
            // Tra active ve tab ban dau
            if (isset($wsMap[$start])) {
                cdp_ws_send($port, $wsMap[$start], json_encode(['id' => 76, 'method' => 'Page.bringToFront']));
            }
            if (count($order) !== count($byId)) return null;
            $out = [];
            foreach ($order as $id) $out[] = $byId[$id];
            return $out;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Snapshot live day du: readLive (+true-order neu $precise) -> buildSnapshot.
     * $precise=true: luu tay / pre-close. false: autosave (nhanh, khong flicker).
     * $budgetMs: ngan sach cho true-order (pre-close 1200ms; qua gio -> fallback).
     */
    public static function snapshotLive(int $profileId, int $port, bool $precise, int $budgetMs = 0): ?array
    {
        $live = self::readLive($profileId, $port);
        if ($live === null) return null;
        if ($precise) {
            $ordered = self::trueOrderTabs($port, $live['tabs'], $budgetMs);
            if ($ordered !== null) {
                // trueOrder bat dau chu ky tu tab dang active + tra active ve do
                // -> ordered[0] chinh la active tab hien tai
                $live['tabs'] = $ordered;
                $live['activeIndex'] = 0;
            }
        }
        return TabSessionManager::buildSnapshot($live['tabs'], (int)$live['activeIndex']);
    }

    /** So tab restorable cua current (cho card UI). */
    public static function counts(array $profileIds): array
    {        $out = [];
        try {
            $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
            if (!$ids) return $out;
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT profile_id, saved_at, tabs FROM tab_sessions WHERE profile_id IN ($in) AND kind='current'");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $tabs = json_decode((string)($r['tabs'] ?? '[]'), true);
                $out[(int)$r['profile_id']] = ['count' => is_array($tabs) ? count($tabs) : 0, 'saved_at' => $r['saved_at']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
