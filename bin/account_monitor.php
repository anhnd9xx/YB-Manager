<?php
declare(strict_types=1);
/**
 * account_monitor.php - AccountMonitor chay background (spec muc 11).
 * Vong lap: lay profile den han (running + qua interval) -> danh gia TUAN TU
 * (concurrency = 1, khong mo/check hang loat) -> ngu.
 * 1 account loi khong fail batch (try/catch tung cai).
 * Chong trung: file lock non-block (giong relay_watchdog).
 * Tat: tat setting acc_background hoac xoa tien trinh (xem api/accounts.php monitor_stop).
 */
require __DIR__ . '/../sync/AccountService.php';

$lock = @fopen(__DIR__ . '/.account_monitor.lock', 'c');
if (!$lock) exit(0);
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
fwrite($lock, (string)getmypid());
fflush($lock);

fwrite(STDOUT, "account_monitor started pid=" . getmypid() . "\n");
fflush(STDOUT);

while (true) {
    try {
        $policy = SyncSettingsService::getAccountPolicy();
        if (empty($policy['background'])) {
            fwrite(STDOUT, date('H:i:s') . " background OFF -> exit\n");
            fflush(STDOUT);
            break;
        }
        $due = AccountRepository::dueProfiles((int)$policy['checkIntervalMin'], 5);
        if (!$due) {
            sleep(60);
            continue;
        }
        foreach ($due as $pid) {
            try {
                $r = AccountService::evaluateProfile((int)$pid);
                fwrite(STDOUT, date('H:i:s') . " #{$pid} {$r['status']}" .
                    (isset($r['stage']) ? " {$r['stage']}" : '') . "\n");
                fflush(STDOUT);
            } catch (Throwable $e) {
                fwrite(STDOUT, date('H:i:s') . " #$pid err: " . mb_substr($e->getMessage(), 0, 150) . "\n");
                fflush(STDOUT);
            }
            sleep(5); // nghi giua cac account (khong dot dap lien tuc)
        }
    } catch (Throwable $e) {
        fwrite(STDOUT, date('H:i:s') . " monitor err: " . mb_substr($e->getMessage(), 0, 150) . "\n");
        fflush(STDOUT);
        sleep(60);
    }
}
