<?php
declare(strict_types=1);
/**
 * SyncBrowserManager - Component BrowserManager cua module Synchronize (spec muc 2+21, PHASE 1).
 * Adapter mong tren config.php (launch_chrome / kill_chrome_processes / cdp_* / profile_live),
 * khong lai logic mo Chrome. Mọi thao tac loi 1 profile khong nem lan sang profile khac:
 * tra ve ['ok'=>bool, ...] de UI/API xu ly tung dong.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncBrowserManager
{
    /** Profile Chrome co process dang chay khong? (khong goi CDP). */
    public static function isRunning(int $profileId): bool
    {
        $p = self::row($profileId);
        if (!$p) return false;
        try {
            return profile_live($p);
        } catch (Throwable $e) {
            SyncLogger::warn('browser_isrunning', "profile #$profileId: " . $e->getMessage(), $profileId);
            return false;
        }
    }

    /** CDP cua profile co ket noi duoc khong? */
    public static function isConnected(int $profileId): bool
    {
        $p = self::row($profileId);
        if (!$p) return false;
        $port = (int)($p['debug_port'] ?? 0);
        if (($p['status'] ?? '') !== 'running' || $port <= 0) return false;
        try {
            return cdp_reachable($port);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Mo (hoac focus neu da chay dung config) 1 profile. Dung chung launch_chrome() cua app. */
    public static function launch(int $profileId, string $url = ''): array
    {
        $p = self::row($profileId);
        if (!$p) return ['ok' => false, 'error' => "Kenh #$profileId khong ton tai"];
        try {
            if ($url === '') $url = get_setting('home_url', 'https://www.youtube.com/');
            $port = (int)($p['debug_port'] ?? 0);
            if ($port <= 0) $port = null;
            launch_chrome($p, $url, $port);
            return ['ok' => true];
        } catch (Throwable $e) {
            SyncLogger::error('browser_launch', "Mo kenh #$profileId that bai: " . $e->getMessage(), $profileId, $e);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * View Window (spec muc 27): restore/hien window, KHONG kill/restart browser.
     * Tra ve ['ok'=>false] neu window chua ton tai (caller bao user mo kenh truoc).
     */
    public static function viewWindow(int $profileId): array
    {
        require_once __DIR__ . '/WindowManager.php';
        require_once __DIR__ . '/ProfileManager.php';
        $prof = SyncProfileManager::get($profileId);
        if (!$prof) return ['ok' => false, 'error' => "Kenh #$profileId khong ton tai"];
        $win = null;
        foreach (SyncWindowManager::findBrowserWindows(false) as $w) {
            if ($w->profileId === $profileId) { $win = $w; break; }
        }
        if ($win === null) return ['ok' => false, 'error' => 'Kenh chua mo (mo kenh truoc khi View)'];
        $r = SyncWindowManager::restoreWindow($win->hwnd);
        if (!$r['ok']) return $r;
        return SyncWindowManager::bringToFront($win->hwnd);
    }

    /** Version Chrome cua profile qua CDP /json/version (lazy, co timeout ngan). null neu khong lay duoc. */
    public static function getVersion(int $profileId): ?string
    {
        $p = self::row($profileId);
        if (!$p) return null;
        $port = (int)($p['debug_port'] ?? 0);
        if ($port <= 0) return null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $json = @file_get_contents("http://127.0.0.1:$port/json/version", false, $ctx);
            if (!is_string($json) || $json === '') return null;
            $d = json_decode($json, true);
            $v = (string)($d['Browser'] ?? '');
            return $v !== '' ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function row(int $profileId): ?array
    {
        try {
            $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            SyncLogger::error('browser_row', "Khong doc duoc profile #$profileId", $profileId, $e);
            return null;
        }
    }
}
