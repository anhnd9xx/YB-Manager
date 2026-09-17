<?php
declare(strict_types=1);
/**
 * SyncHealthMonitor - Kiem tra suc khoe dinh ky (spec muc 29).
 * Moi profile: HWND, process, window alive, connection (CDP) alive,
 * last event + last heartbeat (tu engine). Khong polling duoi 5s cho full scan.
 * Trang thai: SYNCING / PAUSED / RUNNING / DISCONNECTED / CRASHED / CLOSED / IDLE...
 */
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/SyncSession.php';

class SyncHealthMonitor
{
    /** Doc heartbeat engine ghi (bin/sync_heartbeat_<sessionId>.json). */
    public static function heartbeat(?int $sessionId): ?array
    {
        if ($sessionId === null || $sessionId <= 0) return null;
        $f = __DIR__ . '/../bin/sync_heartbeat_' . $sessionId . '.json';
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    /**
     * Check 1 session: tra ve ['session'=>state, 'engine'=>alive, 'heartbeat'=>?,
     * 'profiles'=>[{id,name,role,hwnd,pid,windowAlive,processAlive,cdpAlive,
     *              minimized,focused,rect,status,lastEventAgo?}]].
     */
    public static function check(?int $sessionId): array
    {
        $s = $sessionId !== null && $sessionId > 0 ? SyncSession::load($sessionId) : SyncSession::getActive();
        $disc = SyncWindowDiscovery::discover(false);
        $byProfile = [];
        foreach ($disc['windows'] as $w) {
            if ($w->profileId !== null && (!isset($byProfile[$w->profileId]) || $w->area() > $byProfile[$w->profileId]->area())) {
                $byProfile[$w->profileId] = $w;
            }
        }
        $pids = [];
        foreach ($disc['processes'] as $pr) $pids[(int)$pr['pid']] = true;
        $fg = (int)($disc['foregroundHwnd'] ?? 0);

        $hb = self::heartbeat($s?->id);
        $out = ['session' => $s?->state, 'sessionId' => $s?->id, 'engine' => false,
                'heartbeat' => $hb, 'heartbeatAge' => $hb ? (time() - (int)($hb['ts'] ?? 0)) : null,
                'profiles' => []];
        if ($s !== null) {
            $out['engine'] = self::engineAlive($s->id);
        }
        foreach (db()->query('SELECT id, name, status, debug_port, sync_role FROM profiles ORDER BY id') as $p) {
            $pid = (int)$p['id'];
            $w = $byProfile[$pid] ?? null;
            $port = (int)($p['debug_port'] ?? 0);
            $cdp = $p['status'] === 'running' && $port > 0 && cdp_reachable($port);
            $procAlive = $w !== null && isset($pids[$w->pid]);
            $role = (string)($p['sync_role'] ?? 'NONE');
            if ($role === 'NONE') $st = 'IDLE';
            elseif ($w === null) $st = ($p['status'] === 'running') ? 'CRASHED' : 'CLOSED';
            elseif (!$cdp) $st = 'DISCONNECTED';
            elseif ($s !== null && $s->state === SyncSession::RUNNING) $st = 'SYNCING';
            elseif ($s !== null && $s->state === SyncSession::PAUSED) $st = 'PAUSED';
            elseif ($s !== null && in_array($s->state, [SyncSession::STARTING, SyncSession::STOPPING], true)) $st = $s->state;
            else $st = ($p['status'] === 'running') ? 'RUNNING' : 'STOPPED';
            $out['profiles'][] = [
                'id' => $pid, 'name' => $p['name'], 'role' => $role, 'status' => $st,
                'hwnd' => $w?->hwnd, 'pid' => $w?->pid, 'rect' => $w?->rect,
                'windowAlive' => $w !== null, 'processAlive' => $procAlive, 'cdpAlive' => $cdp,
                'minimized' => $w?->minimized ?? false, 'focused' => $w !== null && $fg > 0 && $w->hwnd === $fg,
            ];
        }
        return $out;
    }

    private static function engineAlive(int $sessionId): bool
    {
        // Kiem tra PID trong DB + tasklist (nhanh, khong WMI/wmic)
        try {
            $st = db()->prepare('SELECT engine_pid FROM sync_sessions WHERE id=?');
            $st->execute([$sessionId]);
            $pid = (int)($st->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            return false;
        }
        if ($pid <= 0) return false;
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        foreach ($out as $line) {
            if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                && stripos($m[1], 'php') !== false) {
                return true;
            }
        }
        return false;
    }
}
