<?php
declare(strict_types=1);
/**
 * RuntimeContextService - Snapshot runtime co cau truc, read-only.
 * Khong dua raw object cho AI.
 */
require_once __DIR__ . '/../config.php';

class RuntimeContextService
{
    /** @return array snapshot gon */
    public static function snapshot(?int $projectId = null): array
    {
        $out = ['at' => date('Y-m-d H:i:s')];
        // Telegram
        try {
            require_once __DIR__ . '/TelegramSupervisor.php';
            $h = TelegramSupervisor::health();
            $out['telegram'] = ['state' => $h['state'] ?? '?',
                'worker' => !empty($h['worker_alive']) ? 'ALIVE' : 'DEAD',
                'last_poll' => $h['last_poll_success_at'] ?? null,
                'last_inbound' => $h['last_inbound_at'] ?? null,
                'last_error' => $h['last_error'] ?? null,
                'reconnects' => $h['reconnect_count'] ?? 0,
                'restarts' => $h['worker_restarts'] ?? 0];
        } catch (Throwable $e) {
            $out['telegram'] = ['state' => 'UNKNOWN'];
        }
        // Chrome/profiles
        try {
            $out['channels'] = [
                'total' => (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn(),
                'running' => (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn()];
        } catch (Throwable $e) {
            $out['channels'] = [];
        }
        // Proxy
        try {
            $out['proxy'] = [
                'total' => (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn(),
                'dead' => (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn()];
        } catch (Throwable $e) {
            $out['proxy'] = [];
        }
        // Jobs
        try {
            require_once __DIR__ . '/JobManager.php';
            $out['jobs_active'] = count(JobManager::active(10));
        } catch (Throwable $e) {
            $out['jobs_active'] = 0;
        }
        // Health overall
        try {
            require_once __DIR__ . '/SystemHealthService.php';
            $o = SystemHealthService::overall();
            $out['health'] = $o['label'] ?? '?';
        } catch (Throwable $e) {
            $out['health'] = '?';
        }
        // Activity today
        try {
            require_once __DIR__ . '/ActivityPlanner.php';
            $s = ActivityPlanner::dailySummary();
            $out['activity_today'] = ['sessions' => ($s['sessions_done'] ?? 0) . '/' . ($s['sessions'] ?? 0),
                'failed' => $s['failed'] ?? 0];
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function toText(array $snap): string
    {
        $t = $snap['telegram'] ?? [];
        $lines = ['RUNTIME @ ' . ($snap['at'] ?? '')];
        $lines[] = 'Telegram: ' . ($t['state'] ?? '?') . ' worker=' . ($t['worker'] ?? '?')
            . ' last_poll=' . ($t['last_poll'] ?? '—') . ' last_inbound=' . ($t['last_inbound'] ?? '—')
            . ' err=' . ($t['last_error'] ?? '—') . ' reconnects=' . ($t['reconnects'] ?? 0);
        $c = $snap['channels'] ?? [];
        $lines[] = 'Channels: ' . ($c['running'] ?? '?') . '/' . ($c['total'] ?? '?') . ' running';
        $p = $snap['proxy'] ?? [];
        $lines[] = 'Proxy: dead=' . ($p['dead'] ?? '?') . '/' . ($p['total'] ?? '?');
        $lines[] = 'Jobs active: ' . ($snap['jobs_active'] ?? 0) . ' · Health: ' . ($snap['health'] ?? '?');
        return implode("\n", $lines);
    }
}
