<?php
declare(strict_types=1);
/**
 * AlertManager - canh bao OPEN/RESOLVED, dedup theo (profile,type).
 * Cung loi ton tai -> update last_seen/seen_count (khong tao 100 alert giong nhau).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class AlertManager
{
    public const CRITICAL = 'CRITICAL';
    public const WARNING = 'WARNING';
    public const INFO = 'INFO';

    /** Mo (hoac refresh) alert. Tra ve id. */
    public static function open(int $profileId, string $severity, string $type, string $message): ?int
    {
        if ($profileId <= 0) return null;
        if (!in_array($severity, [self::CRITICAL, self::WARNING, self::INFO], true)) $severity = self::WARNING;
        $now = date('Y-m-d H:i:s');
        try {
            $st = db()->prepare("SELECT id, seen_count FROM channel_alerts WHERE profile_id=? AND type=? AND status='OPEN' LIMIT 1");
            $st->execute([$profileId, $type]);
            $ex = $st->fetch();
            if ($ex) {
                $seen = (int)$ex['seen_count'] + 1;
                db()->prepare('UPDATE channel_alerts SET last_seen=?, seen_count=?, severity=?, message=? WHERE id=?')
                    ->execute([$now, $seen, $severity, mb_substr($message, 0, 255), (int)$ex['id']]);
                // Escalate: fail nhieu lan -> CRITICAL event (dedup: chi khi vuot nguong) (§22-§23)
                if ($seen === 3) {
                    self::emitAlertEvent($profileId, 'CRITICAL', $type, $message, $seen);
                }
                return (int)$ex['id'];
            }
            db()->prepare("INSERT INTO channel_alerts (profile_id, severity, type, message, first_seen, last_seen, status) VALUES (?,?, ?, ?, ?, ?, 'OPEN')")
                ->execute([$profileId, $severity, $type, mb_substr($message, 0, 255), $now, $now]);
            $id = (int)db()->lastInsertId();
            try {
                SyncLogger::warn('alert', "[ALERT] #$profileId $severity/$type: $message", $profileId);
            } catch (Throwable $e) {
            }
            // Event cho Notification (module PROXY/EVALUATION tuy type) — dedup nho alert key
            self::emitAlertEvent($profileId, $severity, $type, $message, 1);
            return $id;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Emit event tu alert (module suy tu type; proxy_error -> PROXY). */
    private static function emitAlertEvent(int $profileId, string $severity, string $type, string $message, int $seen): void
    {
        try {
            require_once __DIR__ . '/EventBus.php';
            $module = str_starts_with($type, 'proxy') ? AppEvent::MOD_PROXY : AppEvent::MOD_EVALUATION;
            $sev = $severity === self::CRITICAL ? AppEvent::SEV_CRITICAL
                : ($severity === self::INFO ? AppEvent::SEV_INFO : AppEvent::SEV_WARNING);
            $evType = $severity === self::CRITICAL ? AppEvent::CRITICAL_ALERT : AppEvent::STATUS_CHANGED;
            EventBus::emit($evType, $module, $sev,
                $type === 'proxy_error' ? 'Proxy lỗi' : 'Cảnh báo kênh',
                "Kênh #$profileId: $message" . ($seen > 1 ? " (lần $seen)" : ''),
                ['profile_id' => $profileId, 'status' => 'OPEN',
                    'data' => ['alert_type' => $type, 'seen_count' => $seen]]);
        } catch (Throwable $e) {
        }
    }

    public static function resolve(int $profileId, string $type): void
    {
        try {
            $st = db()->prepare("UPDATE channel_alerts SET status='RESOLVED', resolved_at=NOW() WHERE profile_id=? AND type=? AND status='OPEN'");
            $st->execute([$profileId, $type]);
            // Recovery notification (§24): ERROR -> HEALTHY (optional setting)
            if ($st->rowCount() > 0 && get_setting('notify_recovery', '0') === '1') {
                try {
                    require_once __DIR__ . '/EventBus.php';
                    $module = str_starts_with($type, 'proxy') ? AppEvent::MOD_PROXY : AppEvent::MOD_EVALUATION;
                    EventBus::emit(AppEvent::STATUS_CHANGED, $module, AppEvent::SEV_SUCCESS,
                        'Đã hoạt động bình thường trở lại',
                        "✅ Kênh #$profileId đã hoạt động bình thường trở lại",
                        ['profile_id' => $profileId, 'status' => 'RESOLVED',
                            'data' => ['alert_type' => $type]]);
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    public static function resolveId(int $id): void
    {
        try {
            db()->prepare("UPDATE channel_alerts SET status='RESOLVED', resolved_at=NOW() WHERE id=?")->execute([$id]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Dong bo alert tu eval_status moi (goi sau moi lan danh gia xong).
     * Quy tac: ACTIVE -> resolve het eval alerts; LOGIN/VERIFY -> WARNING;
     * UNAVAILABLE -> CRITICAL; ERROR tool -> INFO (khong ket luan account hong).
     */
    public static function syncFromEval(int $profileId, string $evalStatus, ?string $reason, array $prefs): void
    {
        $onChange = !empty($prefs['alert_on_status_change']);
        $onLogin = !empty($prefs['alert_on_login_required']);
        if (!$onChange && !$onLogin) return;
        switch ($evalStatus) {
            case 'ACTIVE':
                self::resolve($profileId, 'login_required');
                self::resolve($profileId, 'verification_required');
                self::resolve($profileId, 'channel_unavailable');
                break;
            case 'LOGIN_REQUIRED':
                if ($onLogin) self::open($profileId, self::WARNING, 'login_required', 'Can dang nhap lai');
                self::resolve($profileId, 'verification_required');
                self::resolve($profileId, 'channel_unavailable');
                break;
            case 'VERIFICATION_REQUIRED':
                if ($onChange) self::open($profileId, self::WARNING, 'verification_required', $reason ?: 'Can xac minh');
                self::resolve($profileId, 'login_required');
                self::resolve($profileId, 'channel_unavailable');
                break;
            case 'CHANNEL_UNAVAILABLE':
                if ($onChange) self::open($profileId, self::CRITICAL, 'channel_unavailable', $reason ?: 'Khong truy cap duoc');
                self::resolve($profileId, 'login_required');
                self::resolve($profileId, 'verification_required');
                break;
            case 'RESTRICTED':
                if ($onChange) self::open($profileId, self::WARNING, 'verification_required', $reason ?: 'Bi han che');
                self::resolve($profileId, 'login_required');
                self::resolve($profileId, 'channel_unavailable');
                break;
            case 'ERROR':
                if ($onChange) self::open($profileId, self::INFO, 'eval_failed', $reason ?: 'Loi kiem tra');
                break;
        }
    }

    /** Alert proxy dead (lazy, co dedup). */
    public static function syncProxy(int $profileId, bool $dead, array $prefs): void
    {
        if (empty($prefs['alert_on_proxy_error'])) return;
        if ($dead) self::open($profileId, self::CRITICAL, 'proxy_error', 'Proxy loi/chet');
        else self::resolve($profileId, 'proxy_error');
    }

    public static function list(?string $status = 'OPEN', ?string $severity = null, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            $w = [];
            $p = [];
            if ($status !== null && $status !== '' && $status !== 'all') {
                $w[] = 'a.status=?';
                $p[] = $status;
            }
            if ($severity !== null && $severity !== '' && $severity !== 'all') {
                $w[] = 'a.severity=?';
                $p[] = $severity;
            }
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = db()->prepare("SELECT a.*, p.name AS profile_name FROM channel_alerts a LEFT JOIN profiles p ON p.id=a.profile_id $where ORDER BY a.last_seen DESC LIMIT $limit OFFSET $offset");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function counts(): array
    {
        try {
            $rows = db()->query("SELECT severity, COUNT(*) c FROM channel_alerts WHERE status='OPEN' GROUP BY severity")->fetchAll();
            $out = ['CRITICAL' => 0, 'WARNING' => 0, 'INFO' => 0, 'total' => 0];
            foreach ($rows as $r) {
                $out[$r['severity']] = (int)$r['c'];
                $out['total'] += (int)$r['c'];
            }
            return $out;
        } catch (Throwable $e) {
            return ['CRITICAL' => 0, 'WARNING' => 0, 'INFO' => 0, 'total' => 0];
        }
    }
}
