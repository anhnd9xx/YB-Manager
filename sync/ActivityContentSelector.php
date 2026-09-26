<?php
declare(strict_types=1);
/**
 * ActivityContentSelector - Random engine chon noi dung tu pool user cau hinh.
 * UI khong tu random. Xem: enabled, weight, cooldown, usage_today, recent.
 * Khong bao gio tra domain ngoai Website Pool.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ActivityManager.php';

class ActivityContentSelector
{
    /**
     * Chon query: enabled + cooldown + weighted.
     * @return array|null row (query, id, category)
     */
    public static function select_query(int $profileId, int $defaultReuseMin = 120, bool $record = true, bool $ignoreCooldown = false): ?array
    {
        ActivityManager::ensureTables();
        try {
            $rows = db()->query('SELECT *, COALESCE(min_reuse_minutes,0) AS mrm FROM activity_search_pool
                WHERE enabled=1 ORDER BY id ASC LIMIT 1000')->fetchAll();
        } catch (Throwable $e) {
            return null;
        }
        $now = time();
        $cands = [];
        foreach ($rows as $r) {
            if (!$ignoreCooldown) {
                $reuseMin = (int)($r['mrm'] ?? 0) > 0 ? (int)$r['mrm'] : $defaultReuseMin;
                $last = !empty($r['last_used_at']) ? strtotime((string)$r['last_used_at']) : 0;
                if ($last > 0 && ($now - $last) < $reuseMin * 60) continue; // cooldown
            }
            $cands[] = $r;
        }
        if (!$cands) return null;
        // Tranh lap query vua dung nhat cua profile
        $recent = ActivityManager::recentQueries($profileId);
        $fresh = array_values(array_filter($cands, fn($r) => !in_array((string)$r['query'], $recent, true)));
        if ($fresh) $cands = $fresh;
        $pick = self::weighted($cands);
        if ($pick && $record) self::touchQuery((int)$pick['id']);
        return $pick;
    }

    /**
     * Chon website: enabled + cooldown + tranh recent domains.
     * @param string[] $excludeDomains domains bo qua (session + recent)
     * @return array|null row
     */
    public static function select_website(int $profileId, array $excludeDomains = [], int $defaultReuseMin = 60, bool $record = true): ?array
    {
        ActivityManager::ensureTables();
        $all = ActivityManager::webList(null, true);
        if (!$all) return null;
        $recent = array_map('strtolower', array_merge($excludeDomains, ActivityManager::recentDomains($profileId)));
        $now = time();
        $cands = [];
        foreach ($all as $r) {
            $reuseMin = (int)($r['min_reuse_minutes'] ?? 0) > 0 ? (int)$r['min_reuse_minutes'] : $defaultReuseMin;
            $last = !empty($r['last_used_at']) ? strtotime((string)$r['last_used_at']) : 0;
            if ($last > 0 && ($now - $last) < $reuseMin * 60) continue; // cooldown
            if (in_array(strtolower((string)$r['domain']), $recent, true)) continue;
            $cands[] = $r;
        }
        if (!$cands) return null; // tat ca cooldown hoac recent -> VISIT_SKIPPED (khong bo qua ngam)
        $pick = self::weighted($cands);
        if ($pick && $record) self::touchWebsite((int)$pick['id']);
        return $pick;
    }

    /**
     * Loc organic results theo Website Pool (§19-23).
     * @param array[] $results [{url, title?, sponsored}]
     * @return array{picked?, organic_count, approved:[...], reason}
     */
    public static function select_approved_search_result(array $results, int $depth = 10, bool $record = true): array
    {
        $depth = max(1, min(30, $depth));
        ActivityManager::ensureTables();
        $pool = [];
        try {
            foreach (ActivityManager::webList(null, true) as $w) {
                $pool[] = ['domain' => strtolower((string)$w['domain']), 'url' => (string)$w['url'],
                    'id' => (int)$w['id'], 'name' => (string)$w['name']];
            }
        } catch (Throwable $e) {
        }
        $organic = 0;
        $approved = [];
        $seen = [];
        foreach (array_slice($results, 0, $depth) as $r) {
            if (!empty($r['sponsored'])) continue; // §20: bo Sponsored/Ads
            $url = self::unwrapGoogleUrl((string)($r['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) continue;
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($host === '') continue;
            $organic++;
            foreach ($pool as $w) {
                if ($w['domain'] === '') continue;
                // Subdomain match, khong contains (§22): dung domainMatch san co
                if (!ActivityManager::domainMatch($url, $w['domain'])) continue;
                $key = $w['domain'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $approved[] = ['domain' => $w['domain'], 'url' => $url,
                    'website_id' => $w['id'], 'name' => $w['name'],
                    'title' => mb_substr((string)($r['title'] ?? ''), 0, 120)];
                break;
            }
        }
        if (!$approved) {
            return ['picked' => null, 'organic_count' => $organic, 'approved' => [],
                'reason' => 'NO_APPROVED_RESULT'];
        }
        $picked = $approved[array_rand($approved)]; // random, khong luon #1 (§23)
        if ($record) self::touchWebsite((int)$picked['website_id']);
        return ['picked' => $picked, 'organic_count' => $organic, 'approved' => $approved, 'reason' => ''];
    }

    /** Bo Google redirect /url?q=...&... ve URL that. */
    public static function unwrapGoogleUrl(string $u): string
    {
        $u = trim($u);
        if (preg_match('#^https?://www\.google\.[a-z.]+/url\?#i', $u)) {
            $qs = parse_url($u, PHP_URL_QUERY);
            if (is_string($qs)) {
                parse_str($qs, $q);
                if (!empty($q['q']) && preg_match('#^https?://#i', (string)$q['q'])) {
                    return (string)$q['q'];
                }
            }
            return '';
        }
        return $u;
    }

    /** Weighted random (weight * recency penalty nhe). */
    private static function weighted(array $rows): ?array
    {
        if (!$rows) return null;
        $total = 0;
        $ws = [];
        foreach ($rows as $i => $r) {
            $w = max(1, (int)($r['weight'] ?? 1)) * 10 / (1 + (int)($r['use_count'] ?? $r['total_usage'] ?? 0));
            $ws[$i] = $w;
            $total += $w;
        }
        $roll = mt_rand() / mt_getrandmax() * $total;
        foreach ($ws as $i => $w) {
            $roll -= $w;
            if ($roll <= 0) return $rows[$i];
        }
        return $rows[array_key_last($rows)];
    }

    private static function touchQuery(int $id): void
    {
        try {
            $today = date('Y-m-d');
            db()->prepare('UPDATE activity_search_pool SET last_used_at=NOW(), use_count=use_count+1,
                    use_today_count=CASE WHEN use_today=? THEN use_today_count+1 ELSE 1 END,
                    use_today=? WHERE id=?')->execute([$today, $today, $id]);
        } catch (Throwable $e) {
        }
    }

    private static function touchWebsite(int $id): void
    {
        try {
            $today = date('Y-m-d');
            db()->prepare('UPDATE activity_websites SET last_used_at=NOW(), use_count=use_count+1,
                    total_usage=total_usage+1,
                    usage_today_count=CASE WHEN usage_today=? THEN usage_today_count+1 ELSE 1 END,
                    usage_today=? WHERE id=?')->execute([$today, $today, $id]);
        } catch (Throwable $e) {
        }
    }
}
