<?php
declare(strict_types=1);
/**
 * api/notify.php - Thong bao & Bao cao endpoints.
 * GET  ?action=config            (telegram config, token masked)
 * POST ?action=config_save {...} (enabled, bot_token, chat_id, send_*, daily/weekly, quiet)
 * POST ?action=test              (test_connection, khong luu)
 * POST ?action=send_test         (gui tin nhan thu)
 * GET  ?action=rules             (bang rules)
 * POST ?action=rule_save {id, mode, enabled}
 * GET  ?action=history&limit=    (lich su gui)
 * POST ?action=cancel {id}       (huy pending)
 * GET  ?action=badge             (failed/unsent + open alerts)
 * GET  ?action=report_daily|report_weekly
 * POST ?action=report_custom {start, end}
 * POST ?action=report_send {report_id}
 * GET  ?action=reports&limit=
 * GET/POST ?action=monitor_start|monitor_stop|monitor_status
 */
require_once __DIR__ . '/../sync/NotificationManager.php';
require_once __DIR__ . '/../sync/NotificationQueue.php';
require_once __DIR__ . '/../sync/TelegramProvider.php';
require_once __DIR__ . '/../sync/TelegramConfig.php';
require_once __DIR__ . '/../sync/TelegramSetup.php';
require_once __DIR__ . '/../sync/ReportManager.php';
require_once __DIR__ . '/../sync/ReportScheduler.php';
require_once __DIR__ . '/../sync/EventBus.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'config';

function nt_set_many(array $kv): void
{
    $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    foreach ($kv as $k => $v) $st->execute([$k, (string)$v]);
}

function nt_spawn(): bool
{
    $php = php_cli_binary();
    if ($php === '') return false;
    $script = __DIR__ . '/../bin/notify_worker.php';
    $log = __DIR__ . '/../bin/notify_worker.log';
    if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
    pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1', 'r'));
    for ($i = 0; $i < 10; $i++) {
        usleep(500000);
        if (nt_pid() !== null) return true;
    }
    return false;
}

function nt_pid(): ?int
{
    $f = __DIR__ . '/../bin/.notify_worker.pid';
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

try {
    switch ($action) {
        case 'config': {
            $token = get_setting('notify_bot_token', '');
            json_out(['ok' => true, 'data' => [
                'enabled' => get_setting('notify_telegram_enabled', '0') === '1',
                'bot_token_masked' => NotificationManager::maskToken($token),
                'has_token' => $token !== '',
                'chat_id' => get_setting('notify_chat_id', ''),
                'send_success' => get_setting('notify_send_success', '1') === '1',
                'send_warning' => get_setting('notify_send_warning', '1') === '1',
                'send_error' => get_setting('notify_send_error', '1') === '1',
                'send_critical' => get_setting('notify_send_critical', '1') === '1',
                'send_batch_summary' => get_setting('notify_send_batch', '1') === '1',
                'daily_enabled' => get_setting('notify_daily_enabled', '0') === '1',
                'daily_time' => get_setting('notify_daily_time', '22:00'),
                'weekly_enabled' => get_setting('notify_weekly_enabled', '0') === '1',
                'weekly_day' => get_setting('notify_weekly_day', '1'),
                'weekly_time' => get_setting('notify_weekly_time', '08:00'),
                'quiet_start' => get_setting('notify_quiet_start', ''),
                'quiet_end' => get_setting('notify_quiet_end', ''),
                'notify_recovery' => get_setting('notify_recovery', '0') === '1',
                'worker_running' => nt_pid() !== null,
            ]]);
            break;
        }

        case 'config_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $kv = [];
            $bool = fn($k, $fb) => array_key_exists($k, $b) ? (!empty($b[$k]) ? '1' : '0') : $fb;
            $kv['notify_telegram_enabled'] = $bool('enabled', get_setting('notify_telegram_enabled', '0'));
            if (array_key_exists('bot_token', $b) && trim((string)$b['bot_token']) !== '') {
                $kv['notify_bot_token'] = trim((string)$b['bot_token']); // rong = giu cu
            }
            if (array_key_exists('chat_id', $b)) $kv['notify_chat_id'] = trim((string)$b['chat_id']);
            foreach (['send_success' => 'notify_send_success', 'send_warning' => 'notify_send_warning',
                      'send_error' => 'notify_send_error', 'send_critical' => 'notify_send_critical',
                      'send_batch_summary' => 'notify_send_batch'] as $in => $out) {
                $kv[$out] = $bool($in, get_setting($out, '1'));
            }
            $kv['daily_enabled'] = $bool('daily_enabled', get_setting('notify_daily_enabled', '0'));
            if (array_key_exists('daily_time', $b) && preg_match('/^\d{2}:\d{2}$/', (string)$b['daily_time'])) {
                $kv['notify_daily_time'] = (string)$b['daily_time'];
            }
            $kv['weekly_enabled'] = $bool('weekly_enabled', get_setting('notify_weekly_enabled', '0'));
            if (array_key_exists('weekly_day', $b)) {
                $kv['notify_weekly_day'] = (string)max(1, min(7, (int)$b['weekly_day']));
            }
            if (array_key_exists('weekly_time', $b) && preg_match('/^\d{2}:\d{2}$/', (string)$b['weekly_time'])) {
                $kv['notify_weekly_time'] = (string)$b['weekly_time'];
            }
            if (array_key_exists('quiet_start', $b)) $kv['notify_quiet_start'] = trim((string)$b['quiet_start']);
            if (array_key_exists('quiet_end', $b)) $kv['notify_quiet_end'] = trim((string)$b['quiet_end']);
            $kv['notify_recovery'] = $bool('notify_recovery', get_setting('notify_recovery', '0'));
            nt_set_many($kv);
            json_out(['ok' => true, 'data' => ['saved' => true]]);
            break;
        }

        case 'test': {
            $b = $method === 'GET' ? $_GET : json_body();
            $token = trim((string)($b['bot_token'] ?? ''));
            $r = TelegramProvider::testConnection($token !== '' ? $token : null);
            json_out($r['ok']
                ? ['ok' => true, 'data' => ['bot' => $r['bot'] ?? '']]
                : ['ok' => false, 'message' => TelegramProvider::friendly($r)]);
            break;
        }

        case 'send_test': {
            $r = TelegramProvider::sendTest();
            json_out($r['ok']
                ? ['ok' => true, 'data' => ['sent' => true]]
                : ['ok' => false, 'message' => TelegramProvider::friendly($r)]);
            break;
        }

        case 'rules': {
            json_out(['ok' => true, 'data' => NotificationManager::rules()]);
            break;
        }

        case 'rule_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $ok = NotificationManager::saveRule((int)($b['id'] ?? 0),
                (string)($b['mode'] ?? ''), !empty($b['enabled']));
            json_out(['ok' => $ok]);
            break;
        }

        case 'history': {
            json_out(['ok' => true, 'data' => NotificationQueue::history((int)($_GET['limit'] ?? 100))]);
            break;
        }

        case 'cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => NotificationQueue::cancel((string)($b['id'] ?? ''))]);
            break;
        }

        case 'badge': {
            $c = NotificationQueue::pendingCounts();
            require_once __DIR__ . '/../sync/AlertManager.php';
            $a = AlertManager::counts();
            json_out(['ok' => true, 'data' => [
                'pending' => $c['pending'], 'failed' => $c['failed'],
                'alerts' => $a['total'], 'alertsCritical' => $a['CRITICAL'],
                'badge' => $c['failed'] + $c['pending'] + $a['total'],
            ]]);
            break;
        }

        case 'report_daily': {
            $doc = ReportManager::buildDaily(isset($_GET['day']) ? (string)$_GET['day'] : null);
            json_out(['ok' => true, 'data' => $doc]);
            break;
        }

        case 'report_weekly': {
            $doc = ReportManager::buildWeekly();
            json_out(['ok' => true, 'data' => $doc]);
            break;
        }

        case 'report_custom': {
            $b = $method === 'GET' ? $_GET : json_body();
            $s = trim((string)($b['start'] ?? ''));
            $e = trim((string)($b['end'] ?? ''));
            if ($s === '' || $e === '') json_out(['ok' => false, 'message' => 'Thieu start/end'], 400);
            $doc = ReportManager::buildRange($s, $e);
            json_out(['ok' => true, 'data' => $doc]);
            break;
        }

        case 'report_send': {
            $b = $method === 'GET' ? $_GET : json_body();
            $rep = ReportManager::get((string)($b['report_id'] ?? ''));
            if (!$rep || empty($rep['document'])) json_out(['ok' => false, 'message' => 'Khong thay bao cao'], 404);
            $nid = NotificationQueue::enqueueText(
                (string)($rep['document']['title'] ?? 'Báo cáo'),
                ReportScheduler::docBody((array)$rep['document']));
            if ($nid) {
                ReportManager::markDelivered($rep['report_id'], 'QUEUED');
                json_out(['ok' => true, 'data' => ['queued' => $nid]]);
            }
            json_out(['ok' => false, 'message' => 'Khong enqueue duoc'], 500);
            break;
        }

        case 'reports': {
            json_out(['ok' => true, 'data' => ReportManager::history((int)($_GET['limit'] ?? 50))]);
            break;
        }

        case 'monitor_status': {
            $pid = nt_pid();
            json_out(['ok' => true, 'data' => ['running' => $pid !== null, 'pid' => $pid]]);
            break;
        }

        case 'monitor_start': {
            $pid = nt_pid();
            if ($pid !== null) json_out(['ok' => true, 'data' => ['running' => true, 'pid' => $pid]]);
            if (!nt_spawn()) json_out(['ok' => false, 'message' => 'Khong spawn duoc worker'], 500);
            json_out(['ok' => true, 'data' => ['running' => true, 'pid' => nt_pid()]]);
            break;
        }

        case 'monitor_stop': {
            $pid = nt_pid();
            if ($pid !== null) {
                @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
            }
            json_out(['ok' => true, 'data' => ['running' => false]]);
            break;
        }

        // ---- One-field setup (§1-§2, §12, §15-§16) ----
        case 'tg_status': {
            $v = TelegramConfig::publicView();
            require_once __DIR__ . '/../sync/ConversationService.php';
            require_once __DIR__ . '/../sync/TelegramGateway.php';
            require_once __DIR__ . '/../sync/TelegramConfigService.php';
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $v['last_in_at'] = ConversationService::lastAt(ConversationService::IN);
            $v['last_out_at'] = ConversationService::lastAt(ConversationService::OUT);
            $v['polling'] = TelegramGateway::connectionState();
            $v['test_mode'] = get_setting('tg_chat_test_mode', '1') !== '0';
            // Transport + pairing tach rieng (§6)
            $v['transport'] = TelegramConfigService::transport_status();
            $v['pairing'] = TelegramConfigService::pairing_status();
            $eff = TelegramConfigService::get_effective_test_chat();
            $v['effective_chat'] = $eff ? ['source' => $eff['source'],
                'display_name' => $eff['display_name']] : null;
            $sess = TelegramSetup::active();
            $v['setup_session'] = $sess ? [
                'session_id' => $sess['session_id'], 'status' => $sess['status'],
                'candidate_display' => $sess['candidate_display'] ?? null,
                'candidate_username' => $sess['candidate_username'] ?? null,
                'candidate_count' => (int)($sess['candidate_count'] ?? 0),
                'expires_at' => $sess['expires_at'] ?? null] : null;
            // Primary connection (bot management UI)
            require_once __DIR__ . '/../sync/TgBotStore.php';
            try {
                TgBotStore::migrateLegacy();
            } catch (Throwable $e) {
            }
            $conn = TgBotStore::primary();
            if ($conn) {
                unset($conn['token_encrypted']);
                $v['connection'] = $conn;
                $v['connection']['destinations'] = TgBotStore::destinations((int)$conn['id']);
            } else {
                $v['connection'] = null;
            }
            require_once __DIR__ . '/../sync/TelegramCounters.php';
            $v['counters'] = TelegramCounters::all();
            json_out(['ok' => true, 'data' => $v]);
            break;
        }

        case 'setup_start': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TelegramSetup::start((string)($b['token'] ?? ''), !empty($b['force']));
            if (!$r['ok']) {
                if (($r['error'] ?? '') === 'webhook_active') {
                    json_out(['ok' => false, 'message' => 'Bot này đang được sử dụng bởi một webhook khác.',
                        'data' => ['webhook_active' => true, 'webhook_url' => $r['webhook_url'] ?? '']]);
                }
                json_out(['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            }
            $bot = $r['bot'] ?? [];
            json_out(['ok' => true, 'data' => [
                'session_id' => $r['session_id'], 'expires_at' => $r['expires_at'],
                'bot_username' => $bot['username'] ?? '']]);
            break;
        }

        case 'setup_start_existing': {
            // Doi nguoi nhan: dung token da luu, tao session moi (§15)
            $token = TelegramConfig::token();
            if ($token === '') json_out(['ok' => false, 'message' => 'Chưa có Bot Token'], 400);
            $me = TelegramProvider::getMeVia($token);
            if (empty($me['ok'])) json_out(['ok' => false, 'message' => 'Token không còn hợp lệ'], 400);
            $r = TelegramSetup::start($token);
            if (!$r['ok']) json_out(['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            json_out(['ok' => true, 'data' => [
                'session_id' => $r['session_id'], 'expires_at' => $r['expires_at'],
                'bot_username' => ($r['bot']['username'] ?? '')]]);
            break;
        }

        case 'setup_status': {
            $s = TelegramSetup::active();
            json_out(['ok' => true, 'data' => ['session' => $s]]);
            break;
        }

        case 'setup_cancel': {
            $b = $method === 'GET' ? $_GET : json_body();
            TelegramSetup::cancel((string)($b['session_id'] ?? ''));
            json_out(['ok' => true]);
            break;
        }

        case 'pair_confirm_tool': {
            // "Dung chat nay" tu UI (§21): ADMIN local xac nhan candidate.
            $b = $method === 'GET' ? $_GET : json_body();
            $sid = (string)($b['session_id'] ?? '');
            if ($sid === '') {
                $sess = TelegramSetup::active();
                $sid = $sess ? (string)($sess['session_id'] ?? '') : '';
            }
            if ($sid === '') json_out(['ok' => false, 'message' => 'Không có yêu cầu nào đang chờ'], 400);
            $r = TelegramSetup::confirmFromTool($sid);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['paired' => true]]
                : ['ok' => false, 'message' => $r['text'] ?? 'Lỗi']);
            break;
        }

        // ---- Bot connections (§20-§31, §37-§39) ----
        case 'bots_list': {
            require_once __DIR__ . '/../sync/TgBotStore.php';
            try {
                TgBotStore::migrateLegacy();
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'data' => TgBotStore::list()]);
            break;
        }

        case 'bot_add': {
            // [+ Thêm Bot]: validate getMe truoc, chi luu khi valid (§24-§25)
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TgBotStore::add((string)($b['name'] ?? ''), (string)($b['token'] ?? ''));
            if (!$r['ok']) json_out(['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            $c = $r['connection'];
            unset($c['token_encrypted']);
            json_out(['ok' => true, 'data' => $c]);
            break;
        }

        case 'bot_replace': {
            // [Thay Token]: validate truoc; invalid -> giu cu (§26). Bot khac -> confirm.
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TgBotStore::replaceToken((int)($b['id'] ?? 0), (string)($b['token'] ?? ''),
                !empty($b['confirmed']));
            if (!$r['ok']) {
                json_out(['ok' => false, 'message' => $r['error'] ?? 'Lỗi',
                    'data' => ['need_confirm' => !empty($r['need_confirm']),
                        'old' => $r['old'] ?? null, 'new' => $r['new'] ?? null]], 400);
            }
            $c = $r['connection'];
            unset($c['token_encrypted']);
            json_out(['ok' => true, 'data' => $c]);
            break;
        }

        case 'bot_rename': {
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => TgBotStore::rename((int)($b['id'] ?? 0), (string)($b['name'] ?? ''))]);
            break;
        }

        case 'bot_primary': {
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => TgBotStore::setPrimary((int)($b['id'] ?? 0))]);
            break;
        }

        case 'bot_check': {
            // [Kiểm tra Token]: getMe bang token da luu (decrypt), khong doi gi
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $id = (int)(($method === 'GET' ? $_GET : json_body())['id'] ?? 0);
            $conn = TgBotStore::get($id);
            if (!$conn) json_out(['ok' => false, 'message' => 'Không thấy Bot'], 404);
            $token = TgBotStore::runtimeToken($conn);
            if ($token === '') json_out(['ok' => false, 'message' => 'Không đọc được token đã lưu'], 500);
            $me = TelegramProvider::getMeVia($token);
            if (empty($me['ok'])) {
                TgBotStore::setStatus($id, TgBotStore::ST_INVALID_TOKEN, 'Token không hợp lệ.');
                json_out(['ok' => false, 'message' => 'Token không hợp lệ hoặc không kết nối được Telegram.']);
            }
            json_out(['ok' => true, 'data' => ['bot' => $me['bot']]]);
            break;
        }

        case 'bot_connect': {
            // [Kết nối]: decrypt -> getMe -> polling -> CONNECTED (§28, §60)
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TgBotStore::connect((int)($b['id'] ?? 0));
            json_out($r['ok'] ? ['ok' => true, 'data' => ['connected' => true]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            break;
        }

        case 'bot_disconnect': {
            // [Ngắt kết nối]: stop polling, GIU token/pairing/history (§29)
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TgBotStore::disconnect((int)($b['id'] ?? 0));
            json_out($r['ok'] ? ['ok' => true, 'data' => ['disconnected' => true]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            break;
        }

        case 'bot_remove': {
            // [Xóa Bot]: confirm o client; giu history mac dinh (§31-§33)
            require_once __DIR__ . '/../sync/TgBotStore.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $r = TgBotStore::remove((int)($b['id'] ?? 0), !empty($b['wipe_history']));
            json_out($r['ok'] ? ['ok' => true, 'data' => ['removed' => true]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            break;
        }

        case 'setup_diag': {
            json_out(['ok' => true, 'data' => TelegramSetup::diagnostics()]);
            break;
        }

        case 'supervisor_restart': {
            // [Restart receiver] chi restart inbound (§35), khong restart Tool
            require_once __DIR__ . '/../sync/TelegramSupervisor.php';
            $b = $method === 'GET' ? $_GET : json_body();
            $cid = (int)($b['connection_id'] ?? 0);
            if ($cid <= 0) {
                require_once __DIR__ . '/../sync/TgBotStore.php';
                $prim = TgBotStore::primary();
                $cid = $prim ? (int)$prim['id'] : 0;
            }
            $r = TelegramSupervisor::restart($cid);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['restarted' => true]]
                : ['ok' => false, 'message' => $r['error'] ?? 'Lỗi']);
            break;
        }

        case 'supervisor_health': {
            require_once __DIR__ . '/../sync/TelegramSupervisor.php';
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => TelegramSupervisor::health((int)($b['connection_id'] ?? 0) ?: null)]);
            break;
        }

        case 'setup_ping': {
            // "Toi da nhan /start" / "Tim tin nhan moi" (§24-§25):
            // 1 getUpdates truc tiep neu worker KHONG listening (tranh 409).
            // Neu worker listening -> bao worker dang nghe, cho vai giay.
            $probe = TelegramSetup::probeOnce(8);
            $sess = TelegramSetup::active();
            json_out(['ok' => true, 'data' => [
                'probe' => $probe,
                'session' => $sess,
                'diagnostics' => TelegramSetup::diagnostics(),
            ]]);
            break;
        }

        case 'disconnect': {
            // Ngat: disable inbound+outbound, remove authorized receiver. Giu token (§16).
            $b = $method === 'GET' ? $_GET : json_body();
            TelegramConfig::set('enabled', '0');
            TelegramConfig::set('inbound_enabled', '0');
            TelegramConfig::set('outbound_enabled', '0');
            TelegramConfig::set('connection_status', 'DISCONNECTED');
            require_once __DIR__ . '/../sync/PermissionService.php';
            $list = PermissionService::allowed();
            $chat = TelegramConfig::primaryChatId();
            $kept = array_values(array_filter($list, fn($a) => (string)$a['chat_id'] !== $chat));
            PermissionService::saveAllowed($kept);
            TelegramConfig::set('primary_chat_id', '');
            TelegramConfig::set('primary_user_id', '');
            TelegramConfig::set('primary_username', '');
            TelegramConfig::set('primary_display_name', '');
            TelegramConfig::set('role', '');
            if (!empty($b['wipe'])) {
                TelegramConfig::clearToken();
                TelegramConfig::set('bot_username', '');
                TelegramConfig::set('bot_first_name', '');
                TelegramConfig::set('bot_id', '');
            }
            json_out(['ok' => true, 'data' => ['disconnected' => true]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'notify exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
