<?php
declare(strict_types=1);
/**
 * relay_watchdog.php - Kiem tra luon trang thai relay cua cac kenh dang chay.
 * Neu relay chet (khong healthy) -> start_proxy_relay lai de khoi phuc ngay,
 * khong can phai mo lai kenh (fix: browser.php open khong restart relay khi kenh dang chay).
 * Chay vo han, moi ~15s. Chong trung: file lock non-block.
 */
require __DIR__ . '/../config.php';

$lock = @fopen(__DIR__ . '/.relay_watchdog.lock', 'c');
if (!$lock) exit(0);
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
fwrite($lock, (string)getmypid());
fflush($lock);

fwrite(STDOUT, "relay_watchdog started pid=" . getmypid() . "\n");
fflush(STDOUT);

while (true) {
    try {
        $st = db()->prepare(
            "SELECT p.*, pr.id AS proxy_id, pr.host AS proxy_host, pr.port AS proxy_port,
                    pr.username AS proxy_user, pr.password AS proxy_pass, pr.protocol AS proxy_protocol
             FROM profiles p JOIN proxies pr ON pr.id = p.proxy_id
             WHERE p.status = 'running'"
        );
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $rport = expected_relay_port($p);
            if ($rport === null) continue;
            // Buoc nhanh (0.5s): relay con lang nghe thi kenh van on -> bo qua
            // (relay_healthy co timeout 12s, quet loan vong theo do se tre kenh khac neu proxy cham)
            if (relay_listening($rport)) continue;
            $started = start_proxy_relay($p);
            if ($started !== null) {
                log_action((int)$p['id'], 'relay_watchdog', 'Relay ' . $rport . ' chet -> da khoi dong lai');
                fwrite(STDOUT, date('H:i:s') . " fixed relay $rport (profile {$p['id']})\n");
                fflush(STDOUT);
            } else {
                db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
                    ->execute(['dead', (int)($p['proxy_id'] ?? 0)]);
                fwrite(STDOUT, date('H:i:s') . " relay $rport (profile {$p['id']}) start FAILED (proxy chet?)\n");
                fflush(STDOUT);
            }
        }
    } catch (Throwable $e) {
        // MySQL tam mat -> cho, khong die (vong lap sau thu lai)
        fwrite(STDOUT, date('H:i:s') . " watchdog err: " . $e->getMessage() . "\n");
        fflush(STDOUT);
    }
    sleep(15);
}