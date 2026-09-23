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
