<?php
declare(strict_types=1);
/**
 * EvaluationTarget - Dedicated evaluation target (§13-§15).
 * Moi evaluation dung target RIENG cua profile, khong dung targets[0] /
 * active tab bat ky. Khong activate ra foreground, khong navigate tab user.
 *
 * Chien luoc: reuse tab danh dau `about:blank#ytm-eval-<pid>` neu con song,
 * neu khong thi tao moi. Giu lai de reuse (background), khong dong sau moi
 * lan tru khi caller yeu cau (tiet kiem CDP open/close).
 */
class EvaluationTarget
{
    private static function marker(int $profileId): string
    {
        return '#ytm-eval-' . $profileId;
    }

    /**
     * @return array{tabId:?string, reused:bool, url:string}
     */
    public static function acquire(int $port, int $profileId, string $url = 'https://www.youtube.com/'): array
    {
        $marker = self::marker($profileId);
        try {
            foreach (cdp_page_targets($port) as $t) {
                $u = (string)($t['url'] ?? '');
                if (strpos($u, $marker) !== false && !empty($t['id'])) {
                    // Reuse: navigate den URL kiem tra (khong activate)
                    self::navigate($port, (string)$t['id'], $url . $marker);
                    return ['tabId' => (string)$t['id'], 'reused' => true, 'url' => $url];
                }
            }
        } catch (Throwable $e) {
        }
        // Tao moi (background): PUT /json/new (Chrome moi tra 405 cho GET)
        $tabId = null;
        try {
            $r = cdp_http($port, 'PUT', '/json/new?' . urlencode($url . $marker), 2000);
            if ($r !== null) {
                $t = json_decode($r['body'], true);
                if (is_array($t) && !empty($t['id'])) $tabId = (string)$t['id'];
            }
        } catch (Throwable $e) {
        }
        return ['tabId' => $tabId, 'reused' => false, 'url' => $url];
    }

    public static function navigate(int $port, string $tabId, string $url): bool
    {
        try {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)($t['id'] ?? '') === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                    return cdp_ws_send($port, (string)$t['webSocketDebuggerUrl'],
                        json_encode(['id' => 31, 'method' => 'Page.navigate', 'params' => ['url' => $url]]));
                }
            }
        } catch (Throwable $e) {
        }
        return false;
    }

    /** Giu lai de reuse; chi dong khi tab loi/mat context lien tuc. */
    public static function release(int $port, ?string $tabId, bool $close = false): void
    {
        if ($close && $tabId !== null && $tabId !== '') {
            try {
                cdp_http($port, 'GET', '/json/close/' . $tabId, 1500);
            } catch (Throwable $e) {
            }
        }
    }

    /** Don tat ca eval tab cua 1 profile (khi mismatch/doi port). */
    public static function cleanup(int $port, int $profileId): void
    {
        $marker = self::marker($profileId);
        try {
            foreach (cdp_page_targets($port) as $t) {
                if (strpos((string)($t['url'] ?? ''), $marker) !== false && !empty($t['id'])) {
                    cdp_http($port, 'GET', '/json/close/' . (string)$t['id'], 1500);
                }
            }
        } catch (Throwable $e) {
        }
    }
}
