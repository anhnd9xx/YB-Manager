<?php
declare(strict_types=1);
/**
 * SyncWindowLayoutManager - Dieu phoi Smart Arrange (PHASE 6, spec muc 5/53).
 * Orchestrate (KHONG tinh toan - viec do cua SmartLayoutEngine;
 *              KHONG thao tac HWND truc tiep - viec do cua SyncWindowManager).
 * Moi lan arrange tao 1 LayoutSession runtime (snapshot settings thong nhat,
 * khong persist DB): sessionId, profileIds, windows, monitors, layout, win, slots.
 * Loi 1 window -> skip + log, cac window khac van arrange (spec muc 43/44).
 * Move/resize dung flag NOACTIVATE (khong cuop focus, spec muc 42).
 */
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/MonitorManager.php';
require_once __DIR__ . '/SmartLayoutEngine.php';
require_once __DIR__ . '/MultiMonitorLayoutEngine.php';
require_once __DIR__ . '/WindowManager.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncWindowLayoutManager
{
    /**
     * Arrange cac profile chi dinh (thu tu = thu tu mang, giu Selected Order).
     * $opts: ['mode'=>override layout mode, 'mainFirst'=>profileId (slot 0, cho Synchronize),
     *         'monitor'=>override 'primary'|<id>|all,
     *         'monitors'=>[device names] (Arrange To Monitor / selection da nho),
     *         'distribution'=>smart|equal|sequential|manual (override settings),
     *         'assign'=>{profileId: deviceName} (manual + sync prep),
     *         'mainMonitor'=>deviceName, 'controlledMonitors'=>[names] (sync prep, §15),
     *         'confirmed'=>true (bo qua disconnect ask), 'debug'=>bool,
     *         'dryRun'=>true (tinh plan, KHONG move - cho preview)]
     * Tra ve ['ok'=>all_success, 'partial'=>bool, 'message', 'session'=>[...], 'results'=>[...],
     *          'missingMonitors'=>[], 'disconnected'=>bool].
     */
    public static function arrange(array $profileIds, array $opts = []): array
    {
        $sessionId = 'lay_' . date('His') . '_' . substr(md5(json_encode($profileIds) . microtime(true)), 0, 6);
        $layout = SyncSettingsService::layoutSnapshot();
        $win = SyncSettingsService::snapshot();
        if (!empty($opts['mode']) && in_array($opts['mode'], SyncSettingsService::LAYOUT_MODES, true)) {
            $layout['mode'] = $opts['mode'];
        }
        $monSetting = isset($opts['monitor']) && $opts['monitor'] !== ''
            ? (string)$opts['monitor'] : (string)$layout['monitor'];
        SyncLogger::info('layout_start', "[Layout] Smart Arrange started (session $sessionId)");

        // 1) Resolve live windows (gom ca minimized de restore+arrange; chi managed profiles)
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        $live = self::liveWindows($ids);
        // mainFirst (spec muc 31): MAIN luon slot 0
        $mainFirst = (int)($opts['mainFirst'] ?? 0);
        if ($mainFirst > 0) {
            usort($live, fn($a, $b) => ($a['profileId'] === $mainFirst ? 0 : 1) <=> ($b['profileId'] === $mainFirst ? 0 : 1));
        }
        SyncLogger::info('layout_start', '[Layout] Running windows: ' . count($live));
        if (!$live) {
            return ['ok' => false, 'partial' => false,
                'message' => 'Khong co Chrome nao dang chay trong danh sach chon',
                'session' => self::session($sessionId, $ids, [], [], $layout, $win, []),
                'results' => []];
        }

        // 2) Resolve monitors: explicit names > remembered selection > setting
        // (device name = stable identity; id mat -> missing -> disconnect handling)
        $missingMonitors = [];
        $areas = [];
        $explicitNames = [];
        if (!empty($opts['monitors']) && is_array($opts['monitors'])) {
            $explicitNames = array_values($opts['monitors']);
        } elseif (!empty($layout['rememberMonitors']) && !empty($layout['monitors'])) {
            $explicitNames = array_values($layout['monitors']);
        }
        if ($explicitNames) {
            [$areas, $missingMonitors] = SyncMonitorManager::resolveTargetsByNames($explicitNames);
            if ($missingMonitors) {
                $msg = '[Layout] Monitor mat: ' . implode(',', $missingMonitors);
                if (($layout['disconnect'] ?? 'ask') === 'auto' || !empty($opts['confirmed']) || !empty($opts['dryRun'])) {
                    SyncLogger::warn('layout_disconnect', $msg . ' -> dung monitors con lai', null);
                } else {
                    // ASK: tra ve de UI hoi user (Later/Arrange), chua move gi ca
                    SyncLogger::warn('layout_disconnect', $msg, null);
                    return ['ok' => false, 'partial' => false, 'disconnectAsk' => true,
                        'message' => 'Monitor khong con: ' . implode(',', $missingMonitors),
                        'missingMonitors' => $missingMonitors,
                        'availableMonitors' => array_map(fn($a) => $a['name'], SyncMonitorManager::allWorkAreas()),
                        'session' => self::session($sessionId, $ids, $live, [], $layout, $win, []),
                        'results' => []];
                }
            }
            if (!$areas) {
                // Khong con monitor duoc chon -> fallback Primary (spec muc 13/19)
                SyncLogger::warn('layout_disconnect', '[Layout] Het monitor duoc chon -> fallback Primary', null);
                $areas = SyncMonitorManager::resolveTargets('primary', false);
            }
        } else {
            $areas = SyncMonitorManager::resolveTargets($monSetting, !empty($layout['multi']));
        }
        if (!$areas) {
            return ['ok' => false, 'partial' => false, 'message' => 'Khong lay duoc monitor',
                'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
                'session' => self::session($sessionId, $ids, $live, [], $layout, $win, []),
                'results' => []];
        }
        foreach ($areas as $a) {
            SyncLogger::info('layout_monitor', '[Layout] Monitor ' . $a['monitorId'] . ' working area: '
                . $a['w'] . 'x' . $a['h'] . ' @' . $a['x'] . ',' . $a['y'] . ' -- ' . SyncMonitorManager::describe($a));
        }
        // 2b) Chon engine: multi areas + (explicit selection hoac multi ON) -> MultiMonitor;
        // con lai giu SmartLayoutEngine cu (khong doi hanh vi hien tai).
        $dist = isset($opts['distribution']) && $opts['distribution'] !== ''
            ? (string)$opts['distribution'] : (string)($layout['distribution'] ?? 'smart');
        if (!in_array($dist, SyncSettingsService::LAYOUT_DISTRIBUTIONS, true)) $dist = 'smart';
        $layout['distribution'] = $dist;
        $manual = self::manualAssignment($live, $areas, $opts);
        $manualCounts = $manual['counts'];
        if ($manualCounts !== null) {
            // Sap live theo nhom area de pairing slot song song dung (manual/mainFirst)
            $byPid = [];
            foreach ($live as $w) $byPid[(int)$w['profileId']] = $w;
            $live = [];
            foreach ($manual['order'] as $pid) {
                if (isset($byPid[$pid])) $live[] = $byPid[$pid];
            }
        }
        if ($manualCounts !== null || count($areas) > 1) {
            $plan = MultiMonitorLayoutEngine::plan(count($live), $areas, $layout, $win, $manualCounts, !empty($opts['debug']));
            if (!$plan['ok']) {
                // Multi that bai -> fallback duong cu (single-area + fallback chain)
                SyncLogger::warn('layout_plan', '[Layout] Multi that bai (' . ($plan['message'] ?? '') . ') -> fallback single', null);
                $plan = null;
            }
        } else {
            $plan = null;
        }
        if ($plan === null) {
            $plan = SmartLayoutEngine::plan(count($live), $areas, $layout, $win, !empty($opts['debug']));
        }
        if (!$plan['ok']) {
            SyncLogger::warn('layout_plan', '[Layout] Khong tinh duoc layout: ' . ($plan['message'] ?? ''), null);
            return ['ok' => false, 'partial' => false,
                'message' => 'Khong tinh duoc layout: ' . ($plan['message'] ?? ''),
                'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
                'session' => self::session($sessionId, $ids, $live, $areas, $layout, $win, []),
                'results' => []];
        }
        if (!empty($plan['breakdown']) && is_array($plan['breakdown'])) {
            foreach ($plan['breakdown'] as $bd) {
                SyncLogger::info('layout_plan', '[Layout] Mon' . $bd['monitorId'] . ': '
                    . $bd['count'] . ' windows ' . $bd['cols'] . 'x' . $bd['rows']
                    . ' cell ' . $bd['cellW'] . 'x' . $bd['cellH']);
            }
        }
        // dryRun (preview): tra plan, KHONG move (spec muc 16)
        if (!empty($opts['dryRun'])) {
            return ['ok' => true, 'partial' => false, 'dryRun' => true,
                'message' => count($plan['slots']) . ' slots (preview)',
                'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
                'plan' => $plan,
                'session' => self::session($sessionId, $ids, $live, $areas, $layout, $win, $plan['slots']),
                'results' => []];
        }
        SyncLogger::info('layout_plan', '[Layout] Selected layout: ' . $plan['cols'] . 'x' . $plan['rows']
            . ($plan['fallbackUsed'] ? ' (fallback ' . $plan['fallbackUsed'] . ')' : ''));
        SyncLogger::info('layout_plan', '[Layout] Window size: ' . $plan['cellW'] . 'x' . $plan['cellH']);
        if (!empty($plan['candidates']) && is_array($plan['candidates'])) {
            foreach (array_slice($plan['candidates'], 0, 5) as $c) {
                SyncLogger::debug('layout_candidate', '[Layout] Candidate: ' . json_encode($c));
            }
        }
        SyncLogger::info('layout_apply', '[Layout] Applying ' . count($plan['slots']) . ' slots (1 batch)');

        // 3) Apply 1 BATCH duy nhat (DeferWindowPos, khong cuop focus).
        // Re-validate HWND ngay truoc batch qua discovery tuoi (window dong giua chung -> skip).
        $tBatch = microtime(true);
        $freshByHwnd = [];
        try {
            foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
                $freshByHwnd[$w->hwnd] = true;
            }
        } catch (Throwable $e) {
        }
        $batchIn = [];
        $skipped = [];
        foreach ($live as $i => $w) {
            $slot = $plan['slots'][$i] ?? null;
            if ($slot === null) break;
            if (!isset($freshByHwnd[(int)$w['hwnd']])) {
                $skipped[] = $i;
                continue;
            }
            $batchIn[] = ['hwnd' => (int)$w['hwnd'], 'x' => (int)$slot['x'], 'y' => (int)$slot['y'],
                          'w' => (int)$slot['w'], 'h' => (int)$slot['h']];
        }
        $batchOut = SyncWindowManager::applyLayoutBatch($batchIn);
        $batchMs = (int)round((microtime(true) - $tBatch) * 1000);
        SyncLogger::info('layout_perf', '[PERF] batch arrange ' . count($batchIn) . ' windows: ' . $batchMs . 'ms');
        $results = [];
        $okCount = 0;
        foreach ($live as $i => $w) {
            $slot = $plan['slots'][$i] ?? null;
            if ($slot === null) break;
            $res = ['profileId' => $w['profileId'], 'profileName' => $w['profileName'],
                    'hwnd' => $w['hwnd'], 'slot' => $slot, 'ok' => false, 'error' => null, 'rect' => null];
            if (in_array($i, $skipped, true)) {
                $res['error'] = 'HWND khong con (window da dong?)';
                SyncLogger::warn('layout_apply', "[Layout] Skip profile #{$w['profileId']}: HWND mat", (int)$w['profileId']);
            } else {
                $one = $batchOut[(string)(int)$w['hwnd']] ?? null;
                if ($one !== null && !empty($one['ok'])) {
                    $res['ok'] = true;
                    $res['rect'] = $one['rect'];
                    $okCount++;
                } else {
                    $res['error'] = (string)(($one['error'] ?? null) ?: 'moveResize that bai');
                    SyncLogger::warn('layout_apply', "[Layout] profile #{$w['profileId']}: " . $res['error'], (int)$w['profileId']);
                }
            }
            $results[] = $res;
        }
        $failCount = count($results) - $okCount;
        $msg = "[Layout] Arrange completed: $okCount success" . ($failCount > 0 ? ", $failCount failed" : '');
        if ($failCount > 0) SyncLogger::warn('layout_done', $msg);
        else SyncLogger::info('layout_done', $msg);
        return [
            'ok' => $failCount === 0,
            'partial' => $failCount > 0 && $okCount > 0,
            'message' => $okCount . ' arranged' . ($failCount > 0 ? ", $failCount failed" : ''),
            'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
            'plan' => $plan,
            'session' => self::session($sessionId, $ids, $live, $areas, $layout, $win, $plan['slots']),
            'results' => $results,
        ];
    }

    /**
     * Manual assignment {profileId: deviceName} (distribution manual, §8)
     * + mainMonitor/controlledMonitors (§15 prep): MAIN vao mainMonitor, rest vao controlled.
     * Tra ve ['counts'=>[areaIdx=>n], 'order'=>[pid theo nhom area]] hoac
     * ['counts'=>null] (= khong manual, de engine tu quyet).
     * Live windows KHONG co trong map duoc don vao nhom CUOI.
     */
    private static function manualAssignment(array $live, array $areas, array $opts): array
    {
        $assign = [];
        if (!empty($opts['assign']) && is_array($opts['assign'])) {
            foreach ($opts['assign'] as $pid => $nm) {
                $assign[(int)$pid] = strtolower(trim((string)$nm));
            }
        } elseif (!empty($opts['mainFirst']) && !empty($opts['mainMonitor'])) {
            // Sync prep: MAIN vao mainMonitor; CONTROLLED round-robin tren controlledMonitors
            // (rong = chung mainMonitor). Giu order live (slot order on dinh).
            $mm = strtolower(trim((string)$opts['mainMonitor']));
            $ctls = isset($opts['controlledMonitors']) && is_array($opts['controlledMonitors'])
                ? array_values(array_filter(array_map(fn($s) => strtolower(trim((string)$s)), $opts['controlledMonitors']))) : [];
            $k = 0;
            foreach ($live as $w) {
                $pid = (int)$w['profileId'];
                if ($pid === (int)$opts['mainFirst']) {
                    $assign[$pid] = $mm;
                } elseif ($ctls) {
                    $assign[$pid] = $ctls[$k % count($ctls)];
                    $k++;
                } else {
                    $assign[$pid] = $mm;
                }
            }
        } else {
            return ['counts' => null, 'order' => []];
        }
        $idxByName = [];
        foreach ($areas as $i => $a) {
            if (!empty($a['name'])) $idxByName[strtolower($a['name'])] = $i;
        }
        $counts = array_fill(0, count($areas), 0);
        $lastIdx = count($areas) - 1;
        $groups = array_fill(0, count($areas), []);
        foreach ($live as $w) {
            $pid = (int)$w['profileId'];
            $nm = $assign[$pid] ?? null;
            $idx = ($nm !== null && isset($idxByName[$nm])) ? $idxByName[$nm] : $lastIdx;
            $counts[$idx]++;
            $groups[$idx][] = $pid;
        }
        $order = [];
        foreach ($groups as $g) {
            foreach ($g as $pid) $order[] = $pid;
        }
        return ['counts' => $counts, 'order' => $order];
    }

    /**
     * Arrange Running (spec muc 25): null -> tat ca managed windows dang chay;
     * mang ids -> chi nhung profile dang chay trong do (thu tu profileId on dinh).
     */
    public static function arrangeRunning(?array $profileIds = null, array $opts = []): array
    {
        if ($profileIds === null) {
            $ids = [];
            foreach (self::liveWindows([]) as $w) $ids[] = (int)$w['profileId'];
            sort($ids);
        } else {
            $want = array_flip(array_map('intval', $profileIds));
            $ids = [];
            foreach (self::liveWindows(array_keys($want)) as $w) $ids[] = (int)$w['profileId'];
            sort($ids); // on dinh, khong phu thuoc launch nhanh/cham (spec muc 22)
        }
        return self::arrange($ids, $opts);
    }

    /**
     * Live windows cua cac managed profiles (gom minimized de restore).
     * $ids rong -> tat ca. Moi profile giu window dien tich lon nhat (main window that,
     * loai DevTools/popup theo class + visible + dien tich).
     * @return array{profileId:int,profileName:string,hwnd:int,pid:int,minimized:bool}[]
     */
    public static function liveWindows(array $ids = []): array
    {
        $want = $ids ? array_flip(array_map('intval', $ids)) : null;
        $byProfile = [];
        try {
            $disc = SyncWindowDiscovery::discover(false);
        } catch (Throwable $e) {
            SyncLogger::warn('layout_discovery', '[Layout] Discovery that bai: ' . $e->getMessage());
            return [];
        }
        foreach ($disc['windows'] as $w) {
            if ($w->profileId === null) continue; // khong phai Chrome project (spec muc 4)
            if ($w->class !== 'Chrome_WidgetWin_1' || !$w->visible) continue;
            if ($w->rect === null || $w->rect['w'] <= 0 || $w->rect['h'] <= 0) continue;
            if ($want !== null && !isset($want[$w->profileId])) continue;
            $pid = (int)$w->profileId;
            $area = $w->rect['w'] * $w->rect['h'];
            if (!isset($byProfile[$pid]) || $area > $byProfile[$pid]['area']) {
                $byProfile[$pid] = ['profileId' => $pid, 'profileName' => $w->profileName,
                    'hwnd' => $w->hwnd, 'pid' => $w->pid, 'minimized' => $w->minimized, 'area' => $area];
            }
        }
        $out = array_values($byProfile);
        // Giu thu tu $ids (Selected Order) neu co, nguoc lai theo profileId
        if ($want !== null) {
            $pos = array_flip(array_values(array_unique(array_map('intval', $ids))));
            usort($out, fn($a, $b) => ($pos[$a['profileId']] ?? 999999) <=> ($pos[$b['profileId']] ?? 999999));
        } else {
            usort($out, fn($a, $b) => $a['profileId'] <=> $b['profileId']);
        }
        foreach ($out as &$r) unset($r['area']);
        unset($r);
        return $out;
    }

    private static function session(string $id, array $ids, array $windows, array $monitors,
        array $layout, array $win, array $slots): array
    {
        return [
            'sessionId' => $id,
            'profileIds' => $ids,
            'windows' => array_map(fn($w) => [
                'profileId' => $w['profileId'], 'hwnd' => $w['hwnd'] ?? null], $windows),
            'monitorIds' => array_map(fn($a) => $a['monitorId'], $monitors),
            'layoutMode' => $layout['mode'] ?? null,
            'sizeMode' => $layout['sizeMode'] ?? null,
            'slots' => $slots,
            'createdAt' => date('Y-m-d H:i:s'),
        ];
    }
}
