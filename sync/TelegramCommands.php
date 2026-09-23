<?php
declare(strict_types=1);
/**
 * TelegramCommands - Handlers cho CommandRegistry (1 ham / command).
 * Nhe (status/info) tra loi ngay; nang (evaluate/start/stop/activity) tao Job
 * va ack ngay (§17), worker chay nen. Khong exec raw text (§11).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CommandRegistry.php';
require_once __DIR__ . '/CommandRouter.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/SyncLogger.php';

class TelegramCommands
{
    public static function help(array $ctx): array
    {
        $cmds = CommandRegistry::forRole($ctx['role'] ?? PermissionService::VIEWER);
        $lines = ["🤖 <b>Lệnh khả dụng:</b>"];
        foreach ($cmds as $name => $c) {
            if ($name === 'pair') continue;
            $lines[] = '/' . $name . ($c['args_hint'] ? ' ' . $c['args_hint'] : '')
                . ' — ' . $c['description'];
        }
        return ['text' => implode("\n", $lines)];
    }

    public static function status(array $ctx): array
    {
        try {
            $total = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
            $running = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
            $eval = 0;
            try {
                require_once __DIR__ . '/ChannelEvaluationManager.php';
                $eval = (int)db()->query("SELECT COUNT(*) FROM account_states WHERE eval_status='CHECKING'")->fetchColumn();
            } catch (Throwable $e) {
            }
            $act = 0;
            try {
                require_once __DIR__ . '/ActivityScheduler.php';
                $act = (int)ActivityScheduler::status()['enabled'];
            } catch (Throwable $e) {
            }
            require_once __DIR__ . '/AlertManager.php';
            $al = AlertManager::counts();
            $jobs = JobManager::active(50);
            $lines = ["🖥️ <b>YT Manager</b>", "Server: Online", "Channels: $total",
                "Chrome Running: $running", "Evaluation Running: $eval",
                "Auto Activity: $act", "Open Alerts: " . $al['total'],
                "Running Jobs: " . count($jobs)];
            return ['text' => implode("\n", $lines),
                'buttons' => [[['🔄 Làm mới', 'cmd:status']]]];
        } catch (Throwable $e) {
            return ['text' => '❌ Không đọc được trạng thái.', 'error' => 'db'];
        }
    }

    public static function jobs(array $ctx): array
    {
        $jobs = JobManager::active(10);
        if (!$jobs) return ['text' => '📭 Không có job nào đang chạy.'];
        $lines = ["📋 <b>Jobs:</b>"];
        foreach ($jobs as $j) {
            $lines[] = $j['job_id'] . ' · ' . $j['name'] . ' · ' . $j['status']
                . ' (' . $j['progress_done'] . '/' . $j['progress_total'] . ')';
        }
        return ['text' => implode("\n", $lines)];
    }

    public static function job(array $ctx): array
    {
        $id = strtoupper(trim($ctx['args'] ?? ''));
        if ($id === '') return ['text' => '❓ Dùng: /job <id> (vd /job EVA-AB12-123456).', 'error' => 'missing_id'];
        $j = JobManager::get($id);
        if (!$j) return ['text' => "❓ Không tìm thấy Job $id.", 'error' => 'not_found'];
        $t = is_array($jd = json_decode((string)($j['targets'] ?? ''), true)) ? count($jd) : (int)$j['progress_total'];
        $lines = ["📦 <b>Job {$j['job_id']}</b>", $j['name'], 'Module: ' . $j['module'],
            "Progress:\n{$j['progress_done']} / " . max($t, (int)$j['progress_total']),
            'Status: ' . $j['status'], 'Success: ' . $j['success_count'],
            'Warning: ' . $j['warning_count'], 'Failed: ' . $j['failed_count']];
        if (!empty($j['result'])) $lines[] = 'Kết quả: ' . mb_substr(strip_tags((string)$j['result']), 0, 500);
        if (!empty($j['error'])) $lines[] = 'Lỗi: ' . $j['error'];
        return ['text' => implode("\n", $lines)];
    }

    public static function channel(array $ctx): array
    {
        $id = (int)trim($ctx['args'] ?? '');
        if ($id <= 0) return ['text' => '❓ Dùng: /channel <id>.', 'error' => 'missing_id'];
        try {
            $st = db()->prepare('SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port
                FROM profiles p LEFT JOIN proxies pr ON pr.id=p.proxy_id WHERE p.id=?');
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) return ['text' => "❓ Không tìm thấy Kênh $id.", 'error' => 'not_found'];
            require_once __DIR__ . '/ChannelEvaluationManager.php';
            $ev = ChannelEvaluationManager::get_evaluation($id);
            $authMap = ['LOGGED_IN' => 'Signed in', 'LOGIN_REQUIRED' => 'Chưa đăng nhập',
                'VERIFICATION_REQUIRED' => 'Cần xác minh', 'UNKNOWN' => 'Chưa xác định'];
            $presMap = ['HAS_CHANNEL' => 'Đã có kênh', 'NO_CHANNEL' => 'No channel',
                'UNKNOWN' => '?', 'NOT_CHECKED' => '—'];
            $auth = $authMap[$ev['auth_status'] ?? ''] ?? ($ev['auth_status'] ?? '?');
            $yt = ($ev['youtube_status'] ?? '') === 'ACCESSIBLE' ? 'Accessible' : ($ev['youtube_status'] ?? '?');
            $pres = $presMap[$ev['channel_presence'] ?? ''] ?? '?';
            $readyMap = ['READY_TO_CREATE_CHANNEL' => 'Ready to create channel',
                'CHANNEL_CREATED' => 'Đã có kênh', 'NEED_LOGIN' => 'Cần đăng nhập',
                'CHECK_REQUIRED' => 'Cần kiểm tra'];
            $ready = $readyMap[$ev['readiness_status'] ?? ''] ?? ($ev['readiness_status'] ?? '?');
            $proxy = !empty($p['proxy_host']) ? $p['proxy_host'] . ':' . $p['proxy_port'] : '—';
            $tabs = '?';
            try {
                require_once __DIR__ . '/TabSessionStore.php';
                $c = TabSessionStore::counts([$id]);
                $tabs = (string)($c[$id]['count'] ?? '?');
            } catch (Throwable $e) {
            }
            $lastEval = '?';
            try {
                if (!empty($ev['last_completed_at'])) {
                    $lastEval = $ev['last_completed_at'];
                }
            } catch (Throwable $e) {
            }
            $lines = ["📺 <b>Kênh $id (" . strip_tags((string)$p['name']) . ")</b>",
                'Chrome: ' . (($p['status'] ?? '') === 'running' ? 'Running' : 'Stopped'),
                'Mail: ' . $auth, 'YouTube: ' . $yt, 'Channel: ' . $pres,
                'Readiness: ' . $ready, 'Proxy: ' . $proxy, 'Tabs: ' . $tabs,
                'Last evaluation: ' . $lastEval];
            return ['text' => implode("\n", $lines),
                'buttons' => [
                    [['📊 Đánh giá', 'cmd:evaluation.run ' . $id]],
                    [['▶ Mở', 'cmd:browser.start ' . $id], ['■ Đóng', 'cmd:browser.stop ' . $id]],
                    [['🔄 Làm mới', 'cmd:channel ' . $id]],
                ]];
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi đọc kênh.', 'error' => 'db'];
        }
    }

    public static function alerts(array $ctx): array
    {
        try {
            require_once __DIR__ . '/AlertManager.php';
            $list = AlertManager::list('OPEN', null, 10);
            if (!$list) return ['text' => '✅ Không có cảnh báo nào đang mở.'];
            $lines = ['⚠️ <b>Cảnh báo đang mở:</b>'];
            foreach ($list as $a) {
                $lines[] = '#' . ($a['profile_id'] ?? '?') . ' ' . $a['severity'] . '/' . $a['type']
                    . ' (x' . ($a['seen_count'] ?? 1) . ')';
            }
            return ['text' => implode("\n", $lines)];
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi đọc cảnh báo.', 'error' => 'db'];
        }
    }

    public static function proxyStatus(array $ctx): array
    {
        try {
            $ok = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='alive'")->fetchColumn();
            $err = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn();
            return ['text' => "🔌 <b>Proxy</b>\nOK: $ok\nError: $err"];
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi đọc proxy.', 'error' => 'db'];
        }
    }

    // ---------- Job commands (ack ngay, worker chay) ----------

    public static function evalRun(array $ctx): array
    {
        $t = CommandRouter::parseTargets($ctx['args'] ?? '', true);
        if (empty($t['ok'])) return ['text' => '❓ Dùng: /evaluate <id|1-10|all>.', 'error' => $t['error']];
        $targets = $t['ids'] === 'all' ? self::allProfileIds() : $t['ids'];
        if (!$targets) return ['text' => '❓ Không có kênh nào.', 'error' => 'empty'];
        $job = JobManager::create('EVALUATION', 'Đánh giá ' . count($targets) . ' kênh', $targets,
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id']]);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nĐánh giá " . count($targets) . " kênh\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id']];
    }

    public static function browserStart(array $ctx): array
    {
        $t = CommandRouter::parseTargets($ctx['args'] ?? '', false);
        if (empty($t['ok'])) return ['text' => '❓ Dùng: /start <id|1,3,5>.', 'error' => $t['error']];
        // Per-command lock: start dang chay? (§33)
        if (self::batchRunning('open')) {
            return ['text' => "⏳ Đang có batch mở chạy — thử lại sau.", 'error' => 'busy'];
        }
        $job = JobManager::create('BROWSER', 'Mở ' . count($t['ids']) . ' Chrome', $t['ids'],
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id'],
                'action' => 'start']);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nMở " . count($t['ids']) . " Chrome\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id'], 'action' => 'start'];
    }

    public static function browserStartAll(array $ctx): array
    {
        $ids = self::allProfileIds();
        if (!$ids) return ['text' => '❓ Không có kênh nào.', 'error' => 'empty'];
        if (self::batchRunning('open')) {
            return ['text' => "⏳ Đang có batch mở chạy — thử lại sau.", 'error' => 'busy'];
        }
        $job = JobManager::create('BROWSER', 'Mở TẤT CẢ (' . count($ids) . ')', $ids,
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id'],
                'action' => 'start']);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nMở tất cả " . count($ids) . " Chrome\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id'], 'action' => 'start'];
    }

    public static function browserStop(array $ctx): array
    {
        $args = strtolower(trim($ctx['args'] ?? ''));
        if ($args === 'all') {
            // Da confirm (router confirm flow) -> chay thang, khong hoi lai
            try {
                $st = db()->prepare('SELECT confirmed FROM app_commands WHERE command_id=?');
                $st->execute([$ctx['command_id'] ?? '']);
                if ((int)($st->fetchColumn() ?? 0) === 1) {
                    return self::browserStopAll($ctx);
                }
            } catch (Throwable $e) {
            }
            // Chuyen sang stop_all (can confirm) (§21)
            require_once __DIR__ . '/ConfirmationService.php';
            $cf = ConfirmationService::create($ctx['command_id'], $ctx['chat_id'], $ctx['user_id'],
                'Đóng TẤT CẢ profiles đang chạy');
            CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_WAIT_CONFIRM);
            return ['text' => "⚠ Đóng tất cả profiles đang chạy?\nXác nhận trong 60 giây.",
                'buttons' => [[['✅ Xác nhận', 'confirm:' . $cf['id']], ['❌ Hủy', 'cancel:' . $cf['id']]]],
                'confirmation_id' => $cf['id']];
        }
        $t = CommandRouter::parseTargets($ctx['args'] ?? '', false);
        if (empty($t['ok'])) return ['text' => '❓ Dùng: /stop <id> hoặc /stop all.', 'error' => $t['error']];
        $job = JobManager::create('BROWSER', 'Đóng ' . count($t['ids']) . ' Chrome', $t['ids'],
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id'],
                'action' => 'stop']);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nĐóng " . count($t['ids']) . " Chrome\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id'], 'action' => 'stop'];
    }

    public static function browserStopAll(array $ctx): array
    {
        try {
            $ids = array_map('intval', db()->query("SELECT id FROM profiles WHERE status='running' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $ids = [];
        }
        if (!$ids) return ['text' => '📭 Không có Chrome nào đang chạy.'];
        $job = JobManager::create('BROWSER', 'Đóng TẤT CẢ (' . count($ids) . ')', $ids,
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id'],
                'action' => 'stop']);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nĐóng tất cả " . count($ids) . " Chrome\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id'], 'action' => 'stop'];
    }

    public static function activityRun(array $ctx): array
    {
        $id = (int)trim($ctx['args'] ?? '');
        if ($id <= 0) return ['text' => '❓ Dùng: /activity <id>.', 'error' => 'missing_id'];
        $job = JobManager::create('AUTO_ACTIVITY', 'Activity kênh ' . $id, [$id],
            $ctx['source'], ['command_id' => $ctx['command_id'], 'chat_id' => $ctx['chat_id']]);
        CommandRouter::setStatus($ctx['command_id'], CommandRouter::ST_QUEUED, null, $job['job_id']);
        return ['text' => "✅ Đã nhận yêu cầu.\nActivity Kênh $id\nJob: #{$job['job_id']}",
            'job_id' => $job['job_id']];
    }

    public static function pair(array $ctx): array
    {
        $code = trim($ctx['args'] ?? '');
        if ($code === '') return ['text' => '❓ Dùng: /pair <mã 6 số>.', 'error' => 'missing_code'];
        require_once __DIR__ . '/PairingService.php';
        $r = PairingService::redeem($code, $ctx['chat_id'], $ctx['user_id']);
        if (empty($r['ok'])) {
            return ['text' => $r['error'] === 'expired' ? '⌛ Mã đã hết hạn.' : '❌ Mã không đúng.', 'error' => $r['error']];
        }
        return ['text' => "✅ Ghép nối thành công! Quyền của bạn: {$r['role']}."];
    }

    // ---------- helpers ----------

    private static function allProfileIds(): array
    {
        try {
            return array_map('intval', db()->query('SELECT id FROM profiles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Co batch start/stop nao dang chay (file life)? §33 */
    private static function batchRunning(string $kind): bool
    {
        try {
            foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_life_*.json') ?: [] as $f) {
                $j = json_decode((string)@file_get_contents($f), true);
                $st = is_array($j) ? (string)($j['state'] ?? '') : '';
                if ($kind === 'open' && in_array($st, ['QUEUED', 'PREPARING', 'STARTING', 'WINDOW_READY', 'VERIFYING'], true)) {
                    if ((microtime(true) - (float)($j['ts'] ?? 0)) < 300) return true;
                }
            }
        } catch (Throwable $e) {
        }
        return false;
    }
}
