<?php
declare(strict_types=1);
/**
 * api/accounts.php - Account Evaluation endpoints.
 * GET  ?action=list|get|history&...   (list: filter stage/channel/minDays/minStab/minConf)
 * POST ?action=evaluate {ids[], batch?} (chunked: tra remaining de UI goi tiep)
 * POST ?action=refresh {id}
 * GET/POST ?action=monitor_start|monitor_stop|monitor_status
 */
require_once __DIR__ . '/../sync/AccountService.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

function acc_monitor_pid(): ?int
{
    $f = __DIR__ . '/../bin/.account_monitor.lock';
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

function acc_spawn_monitor(): bool
{
    $php = php_cli_binary();
    if ($php === '') return false;
    $script = __DIR__ . '/../bin/account_monitor.php';
    $log = __DIR__ . '/../bin/account_monitor.log';
    if (is_file($log) && filesize($log) > 2097152) @rename($log, $log . '.1');
    $cmdline = 'start "" /B "' . $php . '" -f "' . $script . '" >> "' . $log . '" 2>&1';
    pclose(popen($cmdline, 'r'));
    for ($i = 0; $i < 10; $i++) {
        usleep(500000);
        if (acc_monitor_pid() !== null) return true;
    }
    return false;
}

try {
    switch ($action) {
        case 'list': {
            $rows = AccountRepository::listAll();
            // Filters (khong che dau, loc bo nho sau khi co du lieu day du)
            $stage = strtolower(trim((string)($_GET['stage'] ?? '')));
            $channel = strtolower(trim((string)($_GET['channel'] ?? ''))); // exists|none|unknown
            $minDays = (int)($_GET['minDays'] ?? 0);
            $minStab = (int)($_GET['minStab'] ?? 0);
            $minConf = (int)($_GET['minConf'] ?? 0);
            $out = [];
            foreach ($rows as $r) {
                $days = (int)floor((time() - strtotime((string)$r['imported_at'])) / 86400);
                $r['managed_days'] = $days;
                if ($stage !== '' && strtolower((string)$r['stage']) !== $stage) continue;
                if ($channel === 'exists' && ($r['channel_state'] ?? '') !== 'exists') continue;
                if ($channel === 'none' && ($r['channel_state'] ?? '') !== 'none') continue;
                if ($channel === 'unknown' && in_array($r['channel_state'] ?? '', ['exists', 'none'], true)) continue;
                if ($minDays > 0 && $days < $minDays) continue;
                if ($minStab > 0 && (int)$r['stability'] < $minStab) continue;
                if ($minConf > 0 && (int)$r['confidence'] < $minConf) continue;
                $out[] = $r;
            }
            json_out(['ok' => true, 'data' => $out]);
            break;
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            $st = AccountRepository::ensure($id);
            if (!$st) json_out(['ok' => false, 'message' => 'Khong thay account'], 404);
            $p = db()->prepare('SELECT id, name, status, last_opened, created_at FROM profiles WHERE id=?');
            $p->execute([$id]);
            $prof = $p->fetch() ?: null;
            $st['managed_days'] = (int)floor((time() - strtotime((string)$st['imported_at'])) / 86400);
            json_out(['ok' => true, 'data' => ['state' => $st, 'profile' => $prof,
                'history' => AccountRepository::history($id, 100)]]);
            break;
        }

        case 'history': {
            $id = (int)($_GET['id'] ?? 0);
            json_out(['ok' => true, 'data' => AccountRepository::history($id, (int)($_GET['limit'] ?? 200))]);
            break;
        }

        case 'evaluate': {
            $b = $method === 'GET' ? $_GET : json_body();
            $ids = array_map('intval', (array)($b['ids'] ?? ($b['id'] ?? [])));
            if (isset($b['id']) && (int)$b['id'] > 0 && empty($ids)) $ids = [(int)$b['id']];
            if (!$ids) json_out(['ok' => false, 'message' => 'Chua chon account nao'], 400);
            $policy = SyncSettingsService::getAccountPolicy();
            $r = AccountService::evaluateBatch($ids, (int)($policy['batch'] ?? 10));
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        case 'refresh': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => true, 'data' => AccountService::evaluateProfile($id)]);
            break;
        }

        case 'monitor_status': {
            $pid = acc_monitor_pid();
            json_out(['ok' => true, 'data' => ['running' => $pid !== null, 'pid' => $pid]]);
            break;
        }

        case 'monitor_start': {
            $pid = acc_monitor_pid();
            if ($pid !== null) json_out(['ok' => true, 'data' => ['running' => true, 'pid' => $pid]]);
            if (!acc_spawn_monitor()) json_out(['ok' => false, 'message' => 'Khong spawn duoc monitor'], 500);
            SyncLogger::info('acc_monitor', '[Acc] monitor started');
            json_out(['ok' => true, 'data' => ['running' => true, 'pid' => acc_monitor_pid()]]);
            break;
        }

        case 'monitor_stop': {
            $pid = acc_monitor_pid();
            if ($pid !== null) {
                @shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
            }
            SyncLogger::info('acc_monitor', '[Acc] monitor stopped');
            json_out(['ok' => true, 'data' => ['running' => false]]);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'accounts exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
