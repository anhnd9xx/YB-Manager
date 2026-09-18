<?php
declare(strict_types=1);
/**
 * bin/tab_autosave.php - Autosave tab sessions nhe (spec muc 11/17).
 * 1 tien trinh duy nhat (file lock), moi tick lay 1 BATCH round-robin
 * (mac dinh 10 profile) chu khong quet 50 Chrome cung luc.
 * Fingerprint-guard: tab khong doi -> skip save (khong ghi disk thua).
 * Loi 1 profile -> skip + tiep (khong fail batch).
 */
require __DIR__ . '/../sync/TabSessionStore.php';
require __DIR__ . '/../sync/SettingsService.php';
require __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.tab_autosave.lock', 'c');
if (!$lock) exit(0);
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
fwrite($lock, (string)getmypid());
fflush($lock);

fwrite(STDOUT, "tab_autosave started pid=" . getmypid() . "\n");
fflush(STDOUT);

$tick = 0;
while (true) {
    try {
        $policy = SyncSettingsService::getAccountPolicy();
        // Interval autosave rieng (giay): lay tu settings tab_autosave_interval, default 30s
        $autosaveSec = 30;
        try {
            $autosaveSec = max(10, min(3600, (int)get_setting('tab_autosave_interval', '30')));
        } catch (Throwable $e) {
        }
        // Danh sach running (gioi han 200, batch 10/tick).
        // Startup grace (§5): bo qua profile vua mo <60s (dang restore/chua on dinh).
        $ids = [];
        $graceCut = date('Y-m-d H:i:s', time() - 60);
        foreach (db()->query("SELECT id, last_opened FROM profiles WHERE status='running' ORDER BY id LIMIT 200") as $r) {
            $pid = (int)$r['id'];
            // Guard §4: restore dang chay (lock tuoi) -> SKIP
            $gf = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_srestore_' . $pid . '.lock';
            if (is_file($gf) && (time() - (int)@filemtime($gf)) < 120) continue;
            if (!empty($r['last_opened']) && (string)$r['last_opened'] >= $graceCut) continue;
            $ids[] = $pid;
        }
        $batch = TabSessionManager::autosaveBatch($ids, 10, $tick);
        $tick++;
        foreach ($batch as $pid) {
            try {
                $st = db()->prepare('SELECT debug_port FROM profiles WHERE id=? AND status=\'running\'');
                $st->execute([$pid]);
                $port = (int)($st->fetchColumn() ?? 0);
                if ($port <= 0) continue;
                $live = TabSessionStore::readLive($pid, $port);
                if ($live === null) continue;
                $snap = TabSessionManager::buildSnapshot($live['tabs'], (int)$live['activeIndex']);
                $r = TabSessionStore::save($pid, $snap);
                if ($r['savedCurrent'] || $r['savedGood']) {
                    fwrite(STDOUT, date('H:i:s') . " #$pid autosaved " . count($snap['tabs']) . " tabs\n");
                    fflush(STDOUT);
                }
            } catch (Throwable $e) {
                continue; // loi 1 profile -> skip
            }
            usleep(200000);
        }
        sleep($autosaveSec);
    } catch (Throwable $e) {
        fwrite(STDOUT, date('H:i:s') . " autosave err: " . mb_substr($e->getMessage(), 0, 150) . "\n");
        fflush(STDOUT);
        sleep(30);
    }
}
