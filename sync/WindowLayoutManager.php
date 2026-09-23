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
        // §34-§35: Stop batch active -> KHONG arrange (32 close events khong trigger 32 arrange)
        try {
            require_once __DIR__ . '/ChromeBatchManager.php';
            if (ChromeBatchManager::stopActive() && empty($opts['force'])) {
                return ['ok' => false, 'partial' => false,
                    'message' => 'Dang dong hang loat — tam dung tu xep',
                    'session' => self::session($sessionId, [], [], [], [], [], []),
                    'results' => [], 'suspended' => true];
            }
        } catch (Throwable $e) {
        }
        $layout = SyncSettingsService::layoutSnapshot();
        $win = SyncSettingsService::snapshot();
        if (!empty($opts['mode']) && in_array($opts['mode'], SyncSettingsService::LAYOUT_MODES, true)) {
            $layout['mode'] = $opts['mode'];
        }
        $monSetting = isset($opts['monitor']) && $opts['monitor'] !== ''
            ? (string)$opts['monitor'] : (string)$layout['monitor'];
        SyncLogger::info('layout_start', "[Layout] Smart Arrange started (session $sessionId)");

        // 1) Resolve live windows (gom ca minimized de restore+arrange; chi managed profiles)
        // §12: loai transitional (STARTING/VERIFYING/CLOSING) tru khi opts cho phep
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        $live = self::liveWindows($ids, empty($opts['includeTransitional']));
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
        // Profile-affinity: giu monitor rieng tung kenh (Start All khong keo ve primary).
        // monitor='profile' (hoac settings layout_monitor='profile'): group theo target monitor.
        if ($monSetting === 'profile' && empty($opts['monitors']) && empty($explicitNames)) {
            require_once __DIR__ . '/WindowPlacementManager.php';
            $groups = []; // device(lower) => [liveIdx...]
            $areaByKey = [];
            foreach (SyncMonitorManager::allWorkAreas() as $a) {
                $areaByKey[strtolower((string)($a['name'] ?? ''))] = $a;
            }
            // can profile rows de resolve (monitor_mode/last/fixed)
            $profById = [];
            try {
                foreach (db()->query('SELECT * FROM profiles') as $r) $profById[(int)$r['id']] = $r;
            } catch (Throwable $e) {
            }
            $groupAreas = [];
            $groupLives = [];
            foreach ($live as $w) {
                $pr = $profById[(int)$w['profileId']] ?? ['id' => $w['profileId']];
                $tm = WindowPlacementManager::resolve_target_monitor($pr);
                $key = $tm ? strtolower((string)($tm['device_name'] ?? '')) : '__primary__';
                $area = ($tm && isset($areaByKey[$key])) ? $areaByKey[$key] : null;
                if ($area === null) {
                    // fallback primary area
                    foreach (SyncMonitorManager::allWorkAreas() as $a) {
                        if (!empty($a['primary'])) {
                            $area = $a;
                            break;
                        }
                    }
                    if ($area === null) $area = SyncMonitorManager::allWorkAreas()[0] ?? null;
                    $key = $area ? strtolower((string)($area['name'] ?? '')) : '__primary__';
                }
                if ($area === null) continue;
                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                    $groupAreas[$key] = $area;
                    $groupLives[$key] = [];
                }
                $groups[$key][] = $w;
                $groupLives[$key][] = $w;
            }
            if (!$groupAreas) {
                return ['ok' => false, 'partial' => false, 'message' => 'Khong lay duoc monitor',
                    'session' => self::session($sessionId, $ids, $live, [], $layout, $win, []), 'results' => []];
            }
            // Tinh plan rieng tung work area (calculate), roi apply 1 batch chung (DeferWindowPos)
            require_once __DIR__ . '/SmartLayoutEngine.php';
            $allSlots = []; // liveIdx => slot
            $breakdown = [];
            // map profileId -> liveIdx
            $idxByPid = [];
            foreach ($live as $i => $w) $idxByPid[(int)$w['profileId']] = $i;
            foreach ($groups as $key => $gwins) {
                $area = $groupAreas[$key];
                $sub = SmartLayoutEngine::plan(count($gwins), [$area], $layout, $win, !empty($opts['debug']));
                if (empty($sub['ok'])) continue;
                foreach ($gwins as $k => $w) {
                    $slot = $sub['slots'][$k] ?? null;
                    if ($slot === null) continue;
                    $li = $idxByPid[(int)$w['profileId']] ?? null;
                    if ($li !== null) $allSlots[$li] = $slot;
                }
                $breakdown[] = ['monitorId' => $area['monitorId'], 'count' => count($gwins)];
                foreach ($area as $ak => $av) {
                }
                SyncLogger::info('layout_plan', '[Layout] Mon' . $area['monitorId'] . ' (' . ($area['name'] ?? '') . '): ' . count($gwins) . ' windows (profile affinity)');
            }
            ksort($allSlots);
            $orderedSlots = [];
            foreach ($live as $i => $w) {
                if (isset($allSlots[$i])) $orderedSlots[] = $allSlots[$i];
            }
            if (!empty($opts['dryRun'])) {
                return ['ok' => true, 'partial' => false, 'dryRun' => true,
                    'message' => count($orderedSlots) . ' slots (preview, profile affinity)',
                    'plan' => ['ok' => true, 'slots' => $orderedSlots, 'breakdown' => $breakdown],
                    'breakdown' => $breakdown,
                    'session' => self::session($sessionId, $ids, $live, array_values($groupAreas), $layout, $win, $orderedSlots),
                    'results' => []];
            }
            // Apply 1 batch (giong duong chung, co guard placement_locked)
            $noActivate = !array_key_exists('noActivate', $opts) || !empty($opts['noActivate']);
            $freshByHwnd = [];
            try {
                foreach (SyncWindowDiscovery::discover(false)['windows'] as $ww) $freshByHwnd[$ww->hwnd] = true;
            } catch (Throwable $e) {
            }
            $batchIn = [];
            $skipped = [];
            foreach ($live as $i => $w) {
                // Khong danh nhau voi startup guard: bo qua window dang locked tru khi batch nay
                // chinh la Start All (opts['allowLocked'] = true)
                $gf = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_guard_' . (int)$w['profileId'] . '.json';
                if (is_file($gf) && empty($opts['allowLocked'])) {
                    $skipped[] = $i;
                    continue;
                }
                $slot = $allSlots[$i] ?? null;
                if ($slot === null) {
                    $skipped[] = $i;
                    continue;
                }
                if (!isset($freshByHwnd[(int)$w['hwnd']])) {
                    $skipped[] = $i;
                    continue;
                }
                $batchIn[] = ['hwnd' => (int)$w['hwnd'], 'x' => (int)$slot['x'], 'y' => (int)$slot['y'], 'w' => (int)$slot['w'], 'h' => (int)$slot['h']];
            }
            $batchOut = SyncWindowManager::applyLayoutBatch($batchIn, $noActivate, ['reason' => 'auto_arrange']);
            $results = [];
            $okCount = 0;
            foreach ($live as $i => $w) {
                $slot = $allSlots[$i] ?? null;
                $res = ['profileId' => $w['profileId'], 'profileName' => $w['profileName'], 'hwnd' => $w['hwnd'], 'slot' => $slot, 'ok' => false, 'error' => null, 'rect' => null];
                if (in_array($i, $skipped, true)) {
                    $res['error'] = 'Skip (placement locked hoac HWND mat)';
                } else {
                    $one = $batchOut[(string)(int)$w['hwnd']] ?? null;
                    if ($one !== null && !empty($one['ok'])) {
                        $res['ok'] = true;
                        $res['rect'] = $one['rect'];
                        $okCount++;
                    } else {
                        $res['error'] = (string)(($one['error'] ?? null) ?: 'moveResize that bai');
                    }
                }
                $results[] = $res;
            }
            $failCount = count($results) - $okCount;
            return ['ok' => $failCount === 0, 'partial' => $failCount > 0 && $okCount > 0,
                'message' => $okCount . ' arranged (profile affinity)' . ($failCount > 0 ? ", $failCount failed" : ''),
                'plan' => ['ok' => true, 'slots' => $orderedSlots, 'breakdown' => $breakdown],
                'breakdown' => $breakdown,
                'session' => self::session($sessionId, $ids, $live, array_values($groupAreas), $layout, $win, $orderedSlots),
                'results' => $results];
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
        // Options theo lan goi (panel Arrange, khong ghi settings):
        // respectTaskbar=false -> mo rong work area ra full resolution (giu origin)
        if (array_key_exists('respectTaskbar', $opts)) {
            $layout['respectTaskbar'] = !empty($opts['respectTaskbar']);
            if (empty($opts['respectTaskbar'])) {
                foreach ($areas as &$ar) {
                    $ar['w'] = max((int)$ar['w'], (int)($ar['resW'] ?? 0));
                    $ar['h'] = max((int)$ar['h'], (int)($ar['resH'] ?? 0));
                }
                unset($ar);
            }
        }
        if (isset($opts['sizeBalance']) && in_array($opts['sizeBalance'], ['similar', 'maximize'], true)) {
            $layout['sizeBalance'] = $opts['sizeBalance'];
        }
        if (isset($opts['sizeMode']) && in_array($opts['sizeMode'], ['auto_fit', 'keep_size'], true)) {
            $layout['sizeMode'] = $opts['sizeMode'];
        }
        if (isset($opts['cols'])) $layout['forceCols'] = max(0, min(12, (int)$opts['cols']));
        // Size/density theo lan goi (drawer Sap xep): minW/minH/gapX/gapY override settings
        if (isset($opts['minW'])) $layout['minW'] = min(4000, max(200, (int)$opts['minW']));
        if (isset($opts['minH'])) $layout['minH'] = min(3000, max(150, (int)$opts['minH']));
        if (isset($opts['gapX'])) $layout['gapX'] = min(100, max(0, (int)$opts['gapX']));
        if (isset($opts['gapY'])) $layout['gapY'] = min(100, max(0, (int)$opts['gapY']));
        $noActivate = !array_key_exists('noActivate', $opts) || !empty($opts['noActivate']);
        // skipMinimized: bo qua cua so minimize (khong restore), chi xep dang hien
        if (!empty($opts['skipMinimized'])) {
            $live = array_values(array_filter($live, fn($w) => empty($w['minimized'])));
            if (!$live) {
                return ['ok' => false, 'partial' => false,
                    'message' => 'Tat ca window dang minimize (bat "Bỏ qua" thi khong xep)',
                    'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
                    'session' => self::session($sessionId, $ids, [], $areas, $layout, $win, []),
                    'results' => []];
            }
        }
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
        $batchOut = SyncWindowManager::applyLayoutBatch($batchIn, $noActivate);
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
     * $excludeTransitional=true: loai profile dang STARTING/VERIFYING/CLOSING de
     * arrange khong danh nhau voi restore/guard (§12). Mac dinh false (discover day du).
     * @return array{profileId:int,profileName:string,hwnd:int,pid:int,minimized:bool}[]
     */
    public static function liveWindows(array $ids = [], bool $excludeTransitional = false): array
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
            if ($excludeTransitional && class_exists('ChromeBatchManager')
                && ChromeBatchManager::isBusy((int)$w->profileId)) {
                continue; // dang STARTING/VERIFYING/CLOSING -> placement_locked (§12)
            }
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
