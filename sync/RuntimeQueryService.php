<?php
declare(strict_types=1);
/**
 * RuntimeQueryService - Doc DATA THAT tu stores (AI khong doan runtime).
 * Dung cho: RUNTIME_QUERY, FAQ dynamic, Telegram status.
 */
require_once __DIR__ . '/../config.php';

class RuntimeQueryService
{
    /** @return array{text} */
    public static function run(string $actionId, array $entity = []): array
    {
        switch ($actionId) {
            case 'system.status':
                return ['text' => self::systemStatus()];
            case 'channel.summary':
                return ['text' => self::channelSummary()];
            case 'channel.status':
                return ['text' => self::channelStatus((int)($entity['id'] ?? 0))];
            case 'proxy.status_summary':
                return ['text' => self::proxySummary()];
            case 'telegram.status':
                return ['text' => self::telegramStatus()];
            case 'job.summary':
                return ['text' => self::jobSummary()];
            case 'auto_activity.status':
                return ['text' => self::autoStatus()];
            default:
                return ['text' => ''];
        }
    }

    public static function systemStatus(): string
    {
        $ch = ['total' => '?', 'running' => '?'];
        try {
            $ch['total'] = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
            $ch['running'] = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
        } catch (Throwable $e) {
        }
        $px = ['alive' => '?', 'total' => '?'];
        try {
            $px['total'] = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
            $px['alive'] = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='alive'")->fetchColumn();
        } catch (Throwable $e) {
        }
        $tg = '?';
        try {
            require_once __DIR__ . '/TelegramSupervisor.php';
            $h = TelegramSupervisor::health();
            $tg = ($h['state'] ?? '?') . (!empty($h['worker_alive']) ? ' (worker sống)' : ' (worker chết)');
        } catch (Throwable $e) {
        }
        $jobs = '?';
        try {
            require_once __DIR__ . '/JobManager.php';
            $jobs = (string)count(JobManager::active(50));
        } catch (Throwable $e) {
        }
        $alerts = '?';
        try {
            require_once __DIR__ . '/AlertManager.php';
            $alerts = (string)(AlertManager::counts()['total'] ?? '?');
        } catch (Throwable $e) {
        }
        return "🖥 YT Manager\nKênh: {$ch['running']}/{$ch['total']} đang chạy\n"
            . "Proxy khỏe: {$px['alive']}/{$px['total']}\nTelegram: $tg\n"
            . "Jobs active: $jobs\nCảnh báo mở: $alerts";
    }

    public static function channelSummary(): string
    {
        try {
            $tot = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
            $run = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
            return "Kênh: tổng $tot · đang chạy $run · dừng " . ($tot - $run);
        } catch (Throwable $e) {
            return '❌ Không đọc được kênh.';
        }
    }

    public static function channelStatus(int $id): string
    {
        if ($id <= 0) return 'Chưa xác định được kênh nào. Thử "kênh 8" hoặc tên kênh.';
        try {
            $st = db()->prepare('SELECT p.id, p.name, p.channel_handle, p.status, p.proxy_id,
                    pr.name AS proxy_name, pr.status AS proxy_status,
                    a.eval_status, a.login_state, a.channel_state, a.last_error
                FROM profiles p
                LEFT JOIN proxies pr ON pr.id=p.proxy_id
                LEFT JOIN account_states a ON a.profile_id=p.id
                WHERE p.id=?');
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) return "Không thấy kênh #$id.";
            $auto = '';
            try {
                require_once __DIR__ . '/ActivityManager.php';
                $cfg = ActivityManager::getConfig($id);
                $auto = "\nAuto: " . (!empty($cfg['enabled']) ? 'BẬT' . (!empty($cfg['next_run_at']) ? ' · tiếp theo ' . $cfg['next_run_at'] : '') : 'tắt');
            } catch (Throwable $e) {
            }
            return "Kênh #$id " . ($c['name'] ?? '') . "\n"
                . 'Chrome: ' . strtoupper((string)($c['status'] ?? '?')) . "\n"
                . 'Proxy: ' . ($c['proxy_name'] ?? 'không có') . ' (' . strtoupper((string)($c['proxy_status'] ?? '?')) . ")\n"
                . 'Evaluation: ' . (string)($c['eval_status'] ?? 'UNCHECKED')
                . ' · login=' . (string)($c['login_state'] ?? '?')
                . ' · channel=' . (string)($c['channel_state'] ?? '?') . $auto
                . (!empty($c['last_error']) ? "\nLỗi gần nhất: " . mb_substr((string)$c['last_error'], 0, 150) : '');
        } catch (Throwable $e) {
            return '❌ Không đọc được kênh.';
        }
    }

    public static function proxySummary(): string
    {
        try {
            $tot = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
            $dead = db()->query("SELECT id, name FROM proxies WHERE status='dead' ORDER BY id LIMIT 5")->fetchAll();
            $out = 'Proxy: khỏe ' . ($tot - count($dead)) . "/$tot";
            if ($dead) {
                $out .= "\nLỗi: " . implode(', ', array_map(fn($p) => '#' . $p['id'] . ' ' . ($p['name'] ?? ''), $dead));
            }
            return $out;
        } catch (Throwable $e) {
            return '❌ Không đọc được proxy.';
        }
    }

    public static function telegramStatus(): string
    {
        try {
            require_once __DIR__ . '/TelegramSupervisor.php';
            $h = TelegramSupervisor::health();
            return 'Telegram' . "\n"
                . 'Receiver: ' . ($h['state'] ?? '?') . "\n"
                . 'Worker: ' . (!empty($h['worker_alive']) ? 'ALIVE' : 'DEAD') . "\n"
                . 'Poll thành công cuối: ' . ($h['last_poll_success_at'] ?? '—') . "\n"
                . 'Tin nhận cuối: ' . ($h['last_inbound_at'] ?? '—') . "\n"
                . 'Reconnect: ' . ($h['reconnect_count'] ?? 0) . "\n"
                . 'Lỗi: ' . ($h['last_error'] ?? 'không');
        } catch (Throwable $e) {
            return '❌ Không đọc được Telegram.';
        }
    }

    public static function jobSummary(): string
    {
        try {
            require_once __DIR__ . '/JobManager.php';
            $rows = JobManager::active(5);
            if (!$rows) return 'Không có job nào đang chạy.';
            $lines = ['Jobs đang chạy:'];
            foreach ($rows as $j) {
                $lines[] = '• ' . $j['job_id'] . ' · ' . ($j['status'] ?? '')
                    . ' · ' . ($j['progress_done'] ?? 0) . '/' . ($j['progress_total'] ?? 0);
            }
            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '❌ Không đọc được jobs.';
        }
    }

    public static function autoStatus(): string
    {
        try {
            require_once __DIR__ . '/ActivityScheduler.php';
            $s = ActivityScheduler::status();
            require_once __DIR__ . '/ActivityPlanner.php';
            $sum = ActivityPlanner::dailySummary();
            return 'Auto Activity hôm nay: ' . ($sum['sessions_done'] ?? 0) . '/' . ($sum['sessions'] ?? 0) . " sessions\n"
                . 'Kênh bật: ' . ($s['enabled'] ?? 0) . ' · đang chạy: ' . ($s['running'] ?? 0)
                . ' · lỗi: ' . ($s['errors'] ?? 0);
        } catch (Throwable $e) {
            return '❌ Không đọc được Auto Activity.';
        }
    }
}
