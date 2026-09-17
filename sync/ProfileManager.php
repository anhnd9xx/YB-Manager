<?php
declare(strict_types=1);
/**
 * SyncProfileManager - Component ProfileManager cua module Synchronize (spec muc 1, PHASE 1).
 * Adapter mong tren bang `profiles` + SyncWindowDiscovery (khong tao ban sao WindowManager).
 * Map sang Profile model cua spec:
 *   id, name, processId, windowHandle, browserType, browserVersion,
 *   status, role, syncEnabled, windowRect, clientRect, dpiScale,
 *   monitorId, connectionState, lastHeartbeat.
 *
 * Role: NONE|MAIN|CONTROLLED (cot profiles.sync_role).
 * Status: STOPPED|STARTING|RUNNING|SYNCING|PAUSED|CRASHED|DISCONNECTED|CLOSED
 *   (suy ra tu process/window/CDP + session state, giong SyncHealthMonitor nhung gon
 *   nhe cho UI Phase 1, khong phu thuoc engine heartbeat).
 */
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/SyncSession.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncProfileManager
{
    public const ROLE_NONE = 'NONE';
    public const ROLE_MAIN = 'MAIN';
    public const ROLE_CONTROLLED = 'CONTROLLED';

    /** Lay 1 profile tu DB ke ca khi Chrome tat. null neu khong ton tai. */
    public static function get(int $profileId): ?array
    {
        try {
            $st = db()->prepare('SELECT id, name, status, debug_port, sync_role, user_data_dir FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            SyncLogger::error('profile_get', 'Khong doc duoc profile #' . $profileId, $profileId, $e);
            return null;
        }
    }

    /** Tat ca profiles trong DB (sap xep theo id). */
    public static function listAll(): array
    {
        try {
            return db()->query('SELECT id, name, status, debug_port, sync_role, user_data_dir FROM profiles ORDER BY id')->fetchAll();
        } catch (Throwable $e) {
            SyncLogger::error('profile_list', 'Khong doc duoc danh sach profiles', null, $e);
            return [];
        }
    }

    /**
     * Map 1 row DB + WindowInfo (neu co) thanh Profile model cua spec.
     * $sessionState: truyen vao khi UI dang o session RUNNING/PAUSED de hien SYNCING/PAUSED.
     */
    public static function toProfile(array $row, ?SyncWindowInfo $win = null, ?string $sessionState = null): array
    {
        $pid = (int)($row['id'] ?? 0);
        $port = (int)($row['debug_port'] ?? 0);
        $dbRunning = (($row['status'] ?? '') === 'running') && $port > 0;
        // CDP chi kiem tra khi process/window con song de tranh TCP connect thua
        $cdpAlive = false;
        if ($win !== null && $dbRunning) {
            try {
                $cdpAlive = cdp_reachable($port);
            } catch (Throwable $e) {
                $cdpAlive = false;
            }
        }
        $role = (string)($row['sync_role'] ?? self::ROLE_NONE);
        if (!in_array($role, [self::ROLE_NONE, self::ROLE_MAIN, self::ROLE_CONTROLLED], true)) {
            $role = self::ROLE_NONE;
        }

        if ($win === null) {
            $status = $dbRunning ? 'CRASHED' : 'CLOSED';
            // Profile chua tung mo van hien STOPPED cho de hieu hon CLOSED
            if (($row['status'] ?? '') !== 'running') $status = 'STOPPED';
        } elseif (!$cdpAlive) {
            $status = 'DISCONNECTED';
        } elseif ($sessionState === SyncSession::RUNNING) {
            $status = 'SYNCING';
        } elseif ($sessionState === SyncSession::PAUSED) {
            $status = 'PAUSED';
        } else {
            $status = 'RUNNING';
        }

        return [
            'id' => $pid,
            'name' => (string)($row['name'] ?? ''),
            'processId' => $win?->pid,
            'windowHandle' => $win?->hwnd,
            'browserType' => 'chrome',
            'browserVersion' => null, // lay lazy qua BrowserManager::getVersion() khi can
            'status' => $status,
            'role' => $role,
            'syncEnabled' => $role !== self::ROLE_NONE,
            'windowRect' => $win?->rect,
            'clientRect' => $win?->clientRect,
            'dpiScale' => $win?->dpiScale ?? 1.0,
            'monitorId' => $win?->monitorId,
            'connectionState' => $cdpAlive ? 'connected' : 'disconnected',
            'lastHeartbeat' => null, // Phase 7 (HealthMonitor/engine heartbeat) dien sau
        ];
    }

    /**
     * Danh sach Profile model cho UI Synchronize:
     * join DB profiles voi window dang song (uu tien window co dien tich lon nhat moi profile).
     * DUNG raw discover (gom ca minimized) thay vi findBrowserWindows (loc minimized),
     * de kenh minimize hien dung status thay vi CRASHED nham.
     */
    public static function listProfiles(?string $sessionState = null): array
    {
        $byProfile = [];
        try {
            foreach (SyncWindowDiscovery::discover()['windows'] as $w) {
                if ($w->class !== 'Chrome_WidgetWin_1' || !$w->visible) continue;
                if ($w->rect === null || $w->rect['w'] <= 0 || $w->rect['h'] <= 0) continue;
                if ($w->profileId !== null && (!isset($byProfile[$w->profileId]) || $w->area() > $byProfile[$w->profileId]->area())) {
                    $byProfile[$w->profileId] = $w;
                }
            }
        } catch (Throwable $e) {
            SyncLogger::warn('profile_discovery', 'Discovery that bai khi listProfiles: ' . $e->getMessage());
        }
        $out = [];
        foreach (self::listAll() as $row) {
            $pid = (int)$row['id'];
            $out[] = self::toProfile($row, $byProfile[$pid] ?? null, $sessionState);
        }
        return $out;
    }
}
