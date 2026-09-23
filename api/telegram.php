<?php
declare(strict_types=1);
/**
 * api/telegram.php - Dieu khien 2 chieu qua UI.
 * GET  ?action=config              (inbound on/off, allowed, default role, polling status)
 * POST ?action=config_save         (inbound_enabled, allowed_chats[], default_role)
 * POST ?action=pair_create         (tao ma 6 so)
 * POST ?action=send {chat_id?, text} (gui tu UI = ADMIN local, qua Router)
 * GET  ?action=conversation&limit= (timeline)
 * GET  ?action=metrics             (bot/inbound/authorized/commands/jobs)
 * GET  ?action=commands&limit=     (audit log)
 * GET  ?action=cmdrules            (registry + role/confirm/enabled)
 * POST ?action=job_cancel {job_id}
 * GET/POST ?action=poll_start|poll_stop|poll_status, job_start|job_stop|job_status
 */
require_once __DIR__ . '/../sync/CommandRouter.php';
require_once __DIR__ . '/../sync/CommandRegistry.php';
require_once __DIR__ . '/../sync/ConversationService.php';
require_once __DIR__ . '/../sync/TelegramGateway.php';
require_once __DIR__ . '/../sync/TelegramConfig.php';
require_once __DIR__ . '/../sync/TelegramConfigService.php';
require_once __DIR__ . '/../sync/PairingService.php';
require_once __DIR__ . '/../sync/JobManager.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'config';

function tgp_pid(string $name): ?int
{
    $f = __DIR__ . '/../bin/.' . $name . '.pid';
    if (!is_file($f)) return null;
    $pid = (int)trim((string)@file_get_contents($f));
    if ($pid <= 0) return null;
    $out = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
    foreach ($out as $line) {
        if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
            && stripos($m[1], 'php') !== false) {
            return $pid;
        }
    }
    return null;
}

function tgp_spawn(string $script, string $pidName, string $log): bool
{
    $php = php_cli_binary();
    if ($php === '') return false;
    $logp = __DIR__ . '/../bin/' . $log;
    if (is_file($logp) && filesize($logp) > 2097152) @rename($logp, $logp . '.1');
    pclose(popen('start "" /B "' . $php . '" -f "' . __DIR__ . '/../bin/' . $script . '" >> "' . $logp . '" 2>&1', 'r'));
    for ($i = 0; $i < 10; $i++) {
        usleep(500000);
        if (tgp_pid($pidName) !== null) return true;
    }
    return false;
}

function tgp_kill(string $pidName): void
{
    $pid = tgp_pid($pidName);
    if ($pid !== null) {
        @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
    }
}

try {
    switch ($action) {
        case 'config': {
            ConversationService::ensureTable();
            $allowed = PermissionService::allowed();
            // Mask user_id? Chat ID can thiet de nhan dien — hien full cho ADMIN local UI
            json_out(['ok' => true, 'data' => [
                'inbound_enabled' => TelegramGateway::inboundEnabled(),
                'allowed' => $allowed,
                'default_role' => PermissionService::defaultRole(),
                'polling' => TelegramGateway::connectionState(),
                'poll_running' => tgp_pid('tg_polling') !== null,
                'job_running' => tgp_pid('job_worker') !== null,
            ]]);
            break;
        }

        case 'config_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)
                ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
            if (array_key_exists('inbound_enabled', $b)) {
                // Mac dinh OFF (§50): chi bat khi user tich + co bot token
                $on = !empty($b['inbound_enabled']) ? '1' : '0';
                if ($on === '1' && trim((string)get_setting('notify_bot_token', '')) === '') {
                    json_out(['ok' => false, 'message' => 'Cần cấu hình Bot Token trước'], 400);
                }
                $st->execute(['notify_inbound_enabled', $on]);
            }
            if (array_key_exists('allowed', $b) && is_array($b['allowed'])) {
                PermissionService::saveAllowed($b['allowed']);
            }
            if (array_key_exists('default_role', $b)) {
                $r = strtoupper(trim((string)$b['default_role']));
                if (in_array($r, ['VIEWER', 'OPERATOR', 'ADMIN'], true)) {
                    $st->execute(['notify_default_role', $r]);
                }
            }
            json_out(['ok' => true, 'data' => ['saved' => true]]);
            break;
        }

        case 'pair_create': {
            $r = PairingService::generate();
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        case 'send': {
            // Gui tu UI: role ADMIN local, di qua Router day du (audit + job) (§8 source=UI)
            $b = $method === 'GET' ? $_GET : json_body();
            $text = trim((string)($b['text'] ?? ''));
            if ($text === '') json_out(['ok' => false, 'message' => 'Trống'], 400);
            $reply = CommandRouter::route($text, 'UI', 'local-ui', 'admin');
            ConversationService::log(ConversationService::IN, 'local-ui', $text,
                ['source' => 'UI', 'user_id' => 'admin', 'command_id' => $reply['command_id'] ?? null]);
            ConversationService::log(ConversationService::OUT, 'local-ui', (string)($reply['text'] ?? ''),
                ['source' => 'UI', 'command_id' => $reply['command_id'] ?? null, 'job_id' => $reply['job_id'] ?? null]);
            json_out(['ok' => true, 'data' => $reply]);
            break;
        }

        case 'conversation': {
            json_out(['ok' => true, 'data' => ConversationService::recent((int)($_GET['limit'] ?? 100))]);
            break;
        }

        case 'chat_page': {
            // Pagination (§AO): limit + before_id cursor + type filter
            $type = (string)($_GET['type'] ?? 'all');
            json_out(['ok' => true, 'data' => ConversationService::page(
                (int)($_GET['limit'] ?? 50), (int)($_GET['before_id'] ?? 0), $type)]);
            break;
        }

        case 'send_text': {
            // Chat test 2 chieu tu UI (§F, §9): text thuong, khong parse command.
            // Chat dich: primary (paired) hoac candidate (dang pairing) (§5).
            // Status QUEUED -> SENDING -> SENT/FAILED. Loi friendly, log stack dev (§12).
            $b = $method === 'GET' ? $_GET : json_body();
            $text = trim((string)($b['text'] ?? ''));
            if ($text === '') json_out(['ok' => false, 'message' => 'Trống'], 400);
            if (mb_strlen($text) > 4000) json_out(['ok' => false, 'message' => 'Tin nhắn quá dài (tối đa 4000 ký tự)'], 400);
            try {
                $eff = TelegramConfigService::get_effective_test_chat();
                if ($eff === null) {
                    json_out(['ok' => false,
                        'message' => "Chưa tìm thấy Telegram nhận tin.\nHãy nhắn một tin cho Bot trước."], 400);
                }
                $dest = (string)$eff['chat_id'];
                $clientId = trim((string)($b['client_id'] ?? ''));
                if ($clientId === '' || strlen($clientId) > 64) {
                    $clientId = 'MSG-' . substr(md5(microtime(true) . mt_rand()), 0, 12);
                }
                require_once __DIR__ . '/../sync/ConversationService.php';
                // Upsert: client da gui (double submit) -> tra ve row cu (§7)
                $rowId = ConversationService::logOutbound($dest, $text,
                    ['source' => 'UI', 'status' => 'SENDING', 'client_message_id' => $clientId]);
                $r = TelegramProvider::sendTo($dest, $text);
                ConversationService::setStatus($rowId, !empty($r['ok']) ? 'SENT' : 'FAILED',
                    $r['ok'] ? null : TelegramProvider::friendly($r),
                    !empty($r['message_id']) ? (string)$r['message_id'] : null);
                if (!empty($r['ok'])) {
                    require_once __DIR__ . '/../sync/TelegramCounters.php';
                    TelegramCounters::bump('out_sent');
                }
                // telegram_message_id that su
                json_out(!empty($r['ok'])
                    ? ['ok' => true, 'data' => ['id' => $rowId, 'client_id' => $clientId, 'status' => 'SENT']]
                    : ['ok' => false, 'message' => 'Không gửi được tin nhắn Telegram.',
                        'data' => ['id' => $rowId, 'client_id' => $clientId]]);
            } catch (Throwable $e) {
                try {
                    SyncLogger::error('telegram', 'send_text loi: ' . get_class($e), null, $e);
                } catch (Throwable $e2) {
                }
                json_out(['ok' => false, 'message' => 'Không gửi được tin nhắn Telegram.'], 500);
            }
            break;
        }

        case 'resend': {
            // [Thử lại] tin FAILED (§11): dung message.chat_id hoac effective hien tai.
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            try {
                ConversationService::ensureTable();
                $st = db()->prepare("SELECT * FROM tg_messages WHERE id=? AND direction='OUTBOUND'");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) json_out(['ok' => false, 'message' => 'Không thấy tin nhắn'], 404);
                $dest = trim((string)($row['chat_id'] ?? ''));
                if ($dest === '') {
                    $eff = TelegramConfigService::get_effective_test_chat();
                    if ($eff === null) {
                        json_out(['ok' => false,
                            'message' => "Chưa tìm thấy Telegram nhận tin.\nHãy nhắn một tin cho Bot trước."], 400);
                    }
                    $dest = (string)$eff['chat_id'];
                }
                ConversationService::setStatus($id, 'SENDING');
                $r = TelegramProvider::sendTo($dest, (string)($row['text'] ?? ''));
                ConversationService::setStatus($id, !empty($r['ok']) ? 'SENT' : 'FAILED',
                    $r['ok'] ? null : TelegramProvider::friendly($r));
                json_out(!empty($r['ok']) ? ['ok' => true, 'data' => ['status' => 'SENT']]
                    : ['ok' => false, 'message' => 'Không gửi được tin nhắn Telegram.']);
            } catch (Throwable $e) {
                try {
                    SyncLogger::error('telegram', 'resend loi: ' . get_class($e), null, $e);
                } catch (Throwable $e2) {
                }
                json_out(['ok' => false, 'message' => 'Không gửi được tin nhắn Telegram.'], 500);
            }
            break;
        }

        case 'notify_preset': {
            // Preset thong bao (§V-§AA): balanced/minimal/all/custom(detect)
            $b = $method === 'GET' ? $_GET : json_body();
            $preset = (string)($b['preset'] ?? '');
            $map = [
                'balanced' => ['send_success' => 0, 'send_warning' => 1, 'send_error' => 1,
                    'send_critical' => 1, 'send_batch_summary' => 1, 'notify_recovery' => 1,
                    'send_info' => 0,
                    'daily_enabled' => 1, 'daily_time' => '23:00', 'weekly_enabled' => 0],
                'minimal' => ['send_success' => 0, 'send_warning' => 0, 'send_error' => 1,
                    'send_critical' => 1, 'send_batch_summary' => 0, 'notify_recovery' => 0,
                    'send_info' => 0,
                    'daily_enabled' => 1, 'daily_time' => '23:00', 'weekly_enabled' => 0],
                'all' => ['send_success' => 1, 'send_warning' => 1, 'send_error' => 1,
                    'send_critical' => 1, 'send_batch_summary' => 1, 'notify_recovery' => 1,
                    'send_info' => 1,
                    'daily_enabled' => 1, 'daily_time' => '23:00', 'weekly_enabled' => 1],
            ];
            if (!isset($map[$preset])) json_out(['ok' => false, 'message' => 'Preset không hợp lệ'], 400);
            $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)
                ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
            $keyMap = ['send_success' => 'notify_send_success', 'send_warning' => 'notify_send_warning',
                'send_error' => 'notify_send_error', 'send_critical' => 'notify_send_critical',
                'send_batch_summary' => 'notify_send_batch', 'notify_recovery' => 'notify_recovery',
                'send_info' => 'notify_send_info',
                'daily_enabled' => 'notify_daily_enabled', 'daily_time' => 'notify_daily_time',
                'weekly_enabled' => 'notify_weekly_enabled'];
            foreach ($map[$preset] as $k => $v) {
                $sv = in_array($k, ['daily_time'], true) ? (string)$v : ((int)$v ? '1' : '0');
                $st->execute([$keyMap[$k], $sv]);
                $GLOBALS['__setting_override'][$keyMap[$k]] = $sv;
            }
            json_out(['ok' => true, 'data' => ['preset' => $preset]]);
            break;
        }

        case 'notify_preset_get': {
            // Detect preset hien tai (custom neu khac) + first-run fill (§AC-§AD)
            $cur = [
                'send_success' => get_setting('notify_send_success', ''),
                'send_info' => get_setting('notify_send_info', ''),
                'send_warning' => get_setting('notify_send_warning', ''),
                'send_error' => get_setting('notify_send_error', ''),
                'send_critical' => get_setting('notify_send_critical', ''),
                'send_batch_summary' => get_setting('notify_send_batch', ''),
                'notify_recovery' => get_setting('notify_recovery', ''),
                'daily_enabled' => get_setting('notify_daily_enabled', ''),
                'daily_time' => get_setting('notify_daily_time', ''),
                'weekly_enabled' => get_setting('notify_weekly_enabled', ''),
            ];
            $missing = false;
            foreach ($cur as $v) {
                if ($v === '') {
                    $missing = true;
                    break;
                }
            }
            if ($missing) {
                // First run: fill BALANCED cho key THIEU, khong overwrite cu (§AC-§AD)
                $fill = ['notify_send_success' => '0', 'notify_send_info' => '0',
                    'notify_send_warning' => '1',
                    'notify_send_error' => '1', 'notify_send_critical' => '1',
                    'notify_send_batch' => '1', 'notify_recovery' => '1',
                    'notify_daily_enabled' => '1', 'notify_daily_time' => '23:00',
                    'notify_weekly_enabled' => '0'];
                try {
                    $have = [];
                    foreach (db()->query('SELECT skey FROM settings WHERE skey LIKE \'notify_%\'')->fetchAll() as $r) {
                        $have[(string)$r['skey']] = true;
                    }
                    foreach ($fill as $k => $v) {
                        if (empty($have[$k])) set_setting($k, $v);
                    }
                } catch (Throwable $e) {
                }
                // Doc lai sau fill
                $map = ['send_success' => 'notify_send_success', 'send_info' => 'notify_send_info',
                    'send_warning' => 'notify_send_warning',
                    'send_error' => 'notify_send_error', 'send_critical' => 'notify_send_critical',
                    'send_batch_summary' => 'notify_send_batch', 'notify_recovery' => 'notify_recovery',
                    'daily_enabled' => 'notify_daily_enabled', 'daily_time' => 'notify_daily_time',
                    'weekly_enabled' => 'notify_weekly_enabled'];
                foreach ($map as $short => $full) {
                    $cur[$short] = get_setting($full, $cur[$short] ?? '');
                }
            }
            $norm = [];
            foreach ($cur as $k => $v) {
                $norm[$k] = ($k === 'daily_time') ? $v : ($v === '1' ? 1 : 0);
            }
            $which = 'custom';
            $bal = ['send_success' => 0, 'send_info' => 0, 'send_warning' => 1, 'send_error' => 1, 'send_critical' => 1,
                'send_batch_summary' => 1, 'notify_recovery' => 1, 'daily_enabled' => 1, 'weekly_enabled' => 0];
            $min = ['send_success' => 0, 'send_info' => 0, 'send_warning' => 0, 'send_error' => 1, 'send_critical' => 1,
                'send_batch_summary' => 0, 'notify_recovery' => 0, 'daily_enabled' => 1, 'weekly_enabled' => 0];
            $all = ['send_success' => 1, 'send_info' => 1, 'send_warning' => 1, 'send_error' => 1, 'send_critical' => 1,
                'send_batch_summary' => 1, 'notify_recovery' => 1, 'daily_enabled' => 1, 'weekly_enabled' => 1];
            foreach (['balanced' => $bal, 'minimal' => $min, 'all' => $all] as $name => $ref) {
                $match = true;
                foreach ($ref as $k => $v) {
                    if ((int)($norm[$k] ?? -1) !== $v) {
                        $match = false;
                        break;
                    }
                }
                if ($match && ($norm['daily_time'] ?? '') === '23:00') {
                    $which = $name;
                    break;
                }
            }
            json_out(['ok' => true, 'data' => ['preset' => $which, 'values' => $norm,
                'command_mode' => get_setting('tg_chat_command_mode', '1') === '1',
                'retention_days' => (int)get_setting('tg_retention_days', '30')]]);
            break;
        }

        case 'notify_autosave': {
            // Auto-save 1 key (debounce o client) (§AB)
            $b = $method === 'GET' ? $_GET : json_body();
            $allow = ['notify_send_success', 'notify_send_warning', 'notify_send_error',
                'notify_send_critical', 'notify_send_batch', 'notify_recovery',
                'notify_daily_enabled', 'notify_daily_time', 'notify_weekly_enabled',
                'notify_weekly_day', 'notify_weekly_time', 'notify_quiet_start', 'notify_quiet_end',
                'tg_chat_command_mode', 'tg_retention_days', 'notify_telegram_enabled'];
            $k = (string)($b['key'] ?? '');
            if (!in_array($k, $allow, true)) json_out(['ok' => false, 'message' => 'Key không hợp lệ'], 400);
            $v = (string)($b['value'] ?? '');
            if (in_array($k, ['notify_daily_time'], true) && !preg_match('/^\d{2}:\d{2}$/', $v)) {
                json_out(['ok' => false, 'message' => 'Giờ không hợp lệ'], 400);
            }
            if ($k === 'tg_retention_days') {
                $v = (string)max(7, min(3650, (int)$v));
            }
            if ($k === 'notify_weekly_day') {
                $v = (string)max(1, min(7, (int)$v));
            }
            set_setting($k, $v);
            json_out(['ok' => true, 'data' => ['saved' => true]]);
            break;
        }

        case 'metrics': {
            json_out(['ok' => true, 'data' => ConversationService::metrics()]);
            break;
        }

        case 'commands': {
            json_out(['ok' => true, 'data' => CommandRouter::auditLog((int)($_GET['limit'] ?? 100))]);
            break;
        }

        case 'cmdrules': {
            $out = [];
            foreach (CommandRegistry::all() as $name => $c) {
                $out[] = ['command' => $name, 'module' => $c['module'],
                    'role' => $c['required_role'], 'confirmation' => !empty($c['confirmation']) ? 1 : 0,
                    'destructive' => !empty($c['destructive']) ? 1 : 0,
                    'enabled' => !empty($c['enabled']) ? 1 : 0,
                    'description' => $c['description']];
            }
            json_out(['ok' => true, 'data' => $out]);
            break;
        }

        case 'job_cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => JobManager::cancel((string)($b['job_id'] ?? ''))]);
            break;
        }

        case 'poll_start': {
            $pid = tgp_pid('tg_polling');
            if ($pid !== null) json_out(['ok' => true, 'data' => ['running' => true, 'pid' => $pid]]);
            if (!tgp_spawn('telegram_polling.php', 'tg_polling', 'telegram_polling.log')) {
                json_out(['ok' => false, 'message' => 'Khong spawn duoc polling worker'], 500);
            }
            json_out(['ok' => true, 'data' => ['running' => true, 'pid' => tgp_pid('tg_polling')]]);
            break;
        }

        case 'poll_stop': {
            tgp_kill('tg_polling');
            json_out(['ok' => true, 'data' => ['running' => false]]);
            break;
        }

        case 'poll_status': {
            $pid = tgp_pid('tg_polling');
            json_out(['ok' => true, 'data' => ['running' => $pid !== null, 'pid' => $pid]
                + TelegramGateway::connectionState()]);
            break;
        }

        case 'job_start': {
            $pid = tgp_pid('job_worker');
            if ($pid !== null) json_out(['ok' => true, 'data' => ['running' => true, 'pid' => $pid]]);
            if (!tgp_spawn('job_worker.php', 'job_worker', 'job_worker.log')) {
                json_out(['ok' => false, 'message' => 'Khong spawn duoc job worker'], 500);
            }
            json_out(['ok' => true, 'data' => ['running' => true, 'pid' => tgp_pid('job_worker')]]);
            break;
        }

        case 'job_stop': {
            tgp_kill('job_worker');
            json_out(['ok' => true, 'data' => ['running' => false]]);
            break;
        }

        case 'job_status': {
            $pid = tgp_pid('job_worker');
            json_out(['ok' => true, 'data' => ['running' => $pid !== null, 'pid' => $pid]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'telegram exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
