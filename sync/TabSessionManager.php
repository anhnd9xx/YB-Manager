<?php
declare(strict_types=1);
/**
 * TabSessionManager - Logic THUAN cho Tab Session (khong CDP/DB truc tiep o day
 * ngoai repository rieng; tach de unit test duoc).
 * - is_restorable_url(): 1 noi duy nhat dinh nghia URL dang luu.
 * - fingerprint(): md5(url list + active) de autosave skip khi khong doi.
 * - buildSnapshot(): loc + chot active_index hop le.
 * - restoreSteps(): ke hoach mo tab (dung tab blank dau cho URL dau).
 * - autosaveBatch(): chia profile thanh batch round-robin (tranh spike).
 */
class TabSessionManager
{
    /** URL noi bo khong bao gio luu/khoi phuc. */
    public static function is_restorable_url(?string $url): bool
    {
        $u = strtolower(trim((string)$url));
        if ($u === '') return false;
        if (str_starts_with($u, 'about:')) return false;
        if (str_starts_with($u, 'chrome://')) return false;
        if (str_starts_with($u, 'devtools://')) return false;
        if (str_starts_with($u, 'edge://')) return false;
        if (str_starts_with($u, 'view-source:')) return false;
        if (!str_starts_with($u, 'http://') && !str_starts_with($u, 'https://')) return false;
        return true;
    }

    /** @param array{id:string,url:string,title:string}[] $tabs */
    public static function fingerprint(array $tabs, int $activeIndex): string
    {
        $parts = [];
        foreach ($tabs as $t) $parts[] = (string)($t['url'] ?? '');
        return md5(implode("\n", $parts) . "\n@" . $activeIndex);
    }

    /**
     * Validate session truoc khi save (§3): tabs phai la list; URL phai la string
     * (entry rac bi SKIP chu khong fail ca session); KHONG tu tao URL.
     * Tra ve ['valid'=>bool,'tabs'=>[...]] (tabs da loc sach + gan tab_index).
     */
    public static function validate_session($tabs): array
    {
        if (!is_array($tabs)) return ['valid' => false, 'tabs' => []];
        $out = [];
        foreach (array_values($tabs) as $t) {
            if (!is_array($t)) continue;
            $url = isset($t['url']) && is_string($t['url']) ? trim($t['url']) : '';
            if ($url === '') continue;
            $title = isset($t['title']) && is_string($t['title']) ? mb_substr(trim($t['title']), 0, 200) : '';
            $out[] = ['i' => count($out), 'url' => $url, 'title' => $title];
        }
        return ['valid' => true, 'tabs' => $out];
    }

    /**
     * Loc tabs song -> snapshot sach {tabs:[{i,url,title}], active_index, fingerprint, good}.
     * good = co >= 1 restorable URL (moi duoc ghi last_good).
     * @param array{id:string,url:string,title:string}[] $tabs
     */
    public static function buildSnapshot(array $tabs, int $activeIndex = 0): array
    {
        $out = [];
        foreach ($tabs as $t) {
            $url = trim((string)($t['url'] ?? ''));
            if (!self::is_restorable_url($url)) continue;
            $out[] = ['i' => count($out), 'url' => $url,
                      'title' => mb_substr(trim((string)($t['title'] ?? '')), 0, 200)];
        }
        // active_index tro vao tab GOC: map qua url con lai, lech thi ve 0
        $active = 0;
        if ($out) {
            $origUrls = array_values(array_map(fn($t) => trim((string)($t['url'] ?? '')), $tabs));
            $want = $origUrls[$activeIndex] ?? null;
            if ($want !== null) {
                foreach ($out as $i => $o) {
                    if ($o['url'] === $want) {
                        $active = $i;
                        break;
                    }
                }
            }
        }
        return [
            'tabs' => $out,
            'active_index' => $active,
            'fingerprint' => self::fingerprint($out, $active),
            'good' => count($out) > 0,
        ];
    }

    /**
     * Ke hoach restore: [{action:navigate|new, url}] — tab blank dau tien nhan
     * URL dau (navigate), cac URL sau mo tab moi. Khong bao gio thua New Tab.
     * Sap theo tab_index (ORDER BY) de giu dung vi tri.
     * @param array{url:string}[] $savedTabs
     * @param bool $hasBlankTab Chrome vua start co tab blank/internal khong
     */
    public static function restoreSteps(array $savedTabs, bool $hasBlankTab): array
    {
        $steps = [];
        $tabs = array_values($savedTabs);
        // Chi sort khi co tab_index (du lieu moi); sort STABLE bang vi tri goc
        // de du lieu cu (khong co i) giu nguyen order.
        $hasI = false;
        foreach ($tabs as $t) {
            if (is_array($t) && array_key_exists('i', $t)) {
                $hasI = true;
                break;
            }
        }
        if ($hasI) {
            $dec = [];
            foreach ($tabs as $pos => $t) $dec[] = [$pos, $t];
            usort($dec, fn($a, $b) => ((int)($a[1]['i'] ?? 0)) <=> ((int)($b[1]['i'] ?? 0))
                ?: ($a[0] <=> $b[0]));
            $tabs = array_map(fn($d) => $d[1], $dec);
        }
        foreach ($tabs as $i => $t) {
            $url = trim((string)($t['url'] ?? ''));
            if (!self::is_restorable_url($url)) continue;
            $steps[] = ['action' => ($i === 0 && $hasBlankTab) ? 'navigate' : 'new', 'url' => $url];
        }
        return $steps;
    }

    /**
     * Chia profile thanh batch round-robin theo vong (tick) de autosave khong spike.
     * @return int[] ids cua batch hien tai
     */
    public static function autosaveBatch(array $profileIds, int $batchSize, int $tick): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        if (!$ids || $batchSize <= 0) return [];
        sort($ids);
        $batches = (int)ceil(count($ids) / $batchSize);
        if ($batches <= 0) return $ids;
        $k = ((int)$tick % $batches + $batches) % $batches;
        return array_slice($ids, $k * $batchSize, $batchSize);
    }
}
