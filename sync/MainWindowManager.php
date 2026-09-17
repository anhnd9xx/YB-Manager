<?php
declare(strict_types=1);
/**
 * SyncMainManager - Quan ly cua so MAIN (spec: MainWindowManager).
 * setMain: MAIN cu -> CONTROLLED (them vao danh sach neu chua co),
 * MAIN moi -> MAIN. Khong dong/restart browser. Engine nhan trong vai giay.
 */
require_once __DIR__ . '/SyncSession.php';
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncMainManager
{
    /** Kenh co window dang song khong? (tra ve WindowInfo hoac null) */
    public static function liveWindow(int $profileId): ?SyncWindowInfo
    {
        foreach (SyncWindowDiscovery::findBrowserWindows(true) as $w) {
            if ($w->profileId === $profileId) return $w;
        }
        return null;
    }

    public static function getMain(int $sessionId): ?array
    {
        $s = SyncSession::load($sessionId);
        if (!$s || $s->mainProfileId <= 0) return null;
        $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
        $st->execute([$s->mainProfileId]);
        return $st->fetch() ?: null;
    }

    /**
     * Doi MAIN. Validate: kenh ton tai + window dang song (khong doi main sang kenh tat).
     * MAIN cu tu dong thanh CONTROLLED.
     */
    public static function setMain(int $sessionId, int $profileId): SyncSession
    {
        $s = SyncSession::load($sessionId);
        if (!$s) throw new RuntimeException("Session #$sessionId khong ton tai");
        if (!in_array($s->state, [SyncSession::IDLE, SyncSession::STOPPED, SyncSession::ERROR, SyncSession::RUNNING, SyncSession::PAUSED], true)) {
            throw new RuntimeException('Khong doi MAIN khi state=' . $s->state);
        }
        $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
        $st->execute([$profileId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException("Kenh #$profileId khong ton tai");
        if (self::liveWindow($profileId) === null) {
            throw new RuntimeException('Kenh "' . $p['name'] . '" chua mo (mo kenh truoc khi dat MAIN)');
        }
        $old = $s->mainProfileId;
        if ($old === $profileId) return $s;
        $ctrl = $s->controlledIds;
        // MAIN cu -> CONTROLLED (spec muc 33)
        if ($old > 0 && !in_array($old, $ctrl, true)) $ctrl[] = $old;
        // MAIN moi ra khoi controlled
        $ctrl = array_values(array_filter($ctrl, fn($id) => $id !== $profileId));
        if (!$ctrl) throw new RuntimeException('Doi MAIN se khong con CONTROLLED nao');
        $s->mainProfileId = $profileId;
        $s->controlledIds = $ctrl;
        $s->save();
        db()->prepare('UPDATE profiles SET sync_role=? WHERE id=?')->execute(['MAIN', $profileId]);
        if ($old > 0) db()->prepare('UPDATE profiles SET sync_role=? WHERE id=?')->execute(['CONTROLLED', $old]);
        SyncLogger::info('MAIN_CHANGED', "MAIN #$old -> #$profileId (session #{$s->id})", $profileId);
        log_action($profileId, 'sync_main_changed', "MAIN #$old -> #$profileId");
        return $s;
    }
}
