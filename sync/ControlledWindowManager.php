<?php
declare(strict_types=1);
/**
 * SyncControlledManager - Quan ly cua so CONTROLLED (spec: ControlledWindowManager).
 * add/remove/reconnect + kiem tra backend CDP.
 */
require_once __DIR__ . '/SyncSession.php';
require_once __DIR__ . '/MainWindowManager.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncControlledManager
{
    /** Kiem tra kenh du dieu kien lam CONTROLLED: ton tai + window song + CDP (neu can). */
    public static function validate(int $profileId, string $inputMode, bool $needWindow = true): array
    {
        $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
        $st->execute([$profileId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException("Kenh #$profileId khong ton tai");
        if ($needWindow && SyncMainManager::liveWindow($profileId) === null) {
            throw new RuntimeException('Kenh "' . $p['name'] . '" chua mo (mo kenh truoc)');
        }
        if (in_array($inputMode, ['AUTO', 'CDP'], true)) {
            $port = (int)($p['debug_port'] ?? 0);
            if ($p['status'] !== 'running' || $port <= 0 || !cdp_reachable($port)) {
                throw new RuntimeException('Kenh "' . $p['name'] . '" mat CDP (mo lai kenh)');
            }
        }
        return $p;
    }

    public static function add(int $sessionId, int $profileId): SyncSession
    {
        $s = SyncSession::load($sessionId);
        if (!$s) throw new RuntimeException("Session #$sessionId khong ton tai");
        if ($profileId === $s->mainProfileId) throw new RuntimeException('Kenh nay dang la MAIN');
        if (in_array($profileId, $s->controlledIds, true)) return $s;
        self::validate($profileId, $s->config['inputMode'] ?? 'AUTO');
        $s->controlledIds[] = $profileId;
        $s->save();
        db()->prepare('UPDATE profiles SET sync_role=? WHERE id=?')->execute(['CONTROLLED', $profileId]);
        SyncLogger::info('PROFILE_ADDED', "CONTROLLED #$profileId (session #{$s->id})", $profileId);
        return $s;
    }

    public static function remove(int $sessionId, int $profileId): SyncSession
    {
        $s = SyncSession::load($sessionId);
        if (!$s) throw new RuntimeException("Session #$sessionId khong ton tai");
        $s->controlledIds = array_values(array_filter($s->controlledIds, fn($id) => $id !== $profileId));
        $s->save();
        db()->prepare('UPDATE profiles SET sync_role=? WHERE id=?')->execute(['NONE', $profileId]);
        SyncLogger::info('PROFILE_REMOVED', "CONTROLLED #$profileId (session #{$s->id})", $profileId);
        return $s;
    }

    /**
     * Danh sach controlled kem trang thai live (window + CDP) cho UI.
     * @return array{id,name,windowAlive,cdpAlive,hwnd?,rect?}
     */
    public static function listLive(int $sessionId): array
    {
        $s = SyncSession::load($sessionId);
        if (!$s) throw new RuntimeException("Session #$sessionId khong ton tai");
        $out = [];
        foreach ($s->controlledIds as $cid) {
            $st = db()->prepare('SELECT id, name, status, debug_port FROM profiles WHERE id=?');
            $st->execute([$cid]);
            $p = $st->fetch();
            if (!$p) continue;
            $w = SyncMainManager::liveWindow($cid);
            $port = (int)($p['debug_port'] ?? 0);
            $cdp = $p['status'] === 'running' && $port > 0 && cdp_reachable($port);
            $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'windowAlive' => $w !== null,
                      'cdpAlive' => $cdp, 'hwnd' => $w?->hwnd, 'rect' => $w?->rect];
        }
        return $out;
    }

    /**
     * Yeu cau engine reconnect gap 1 controlled (ghi vao config, engine tieu thu).
     * Dung cho nut Reconnect khi window crash (spec muc 28).
     */
    public static function reconnect(int $sessionId, int $profileId): SyncSession
    {
        $s = SyncSession::load($sessionId);
        if (!$s) throw new RuntimeException("Session #$sessionId khong ton tai");
        if (!in_array($profileId, $s->controlledIds, true)) {
            throw new RuntimeException("Kenh #$profileId khong nam trong session");
        }
        $ids = $s->config['reconnectIds'] ?? [];
        if (!in_array($profileId, $ids, true)) $ids[] = $profileId;
        $s->config['reconnectIds'] = $ids;
        $s->save();
        SyncLogger::info('WINDOW_RECONNECTED', "Yeu cau reconnect #$profileId (session #{$s->id})", $profileId);
        return $s;
    }
}
