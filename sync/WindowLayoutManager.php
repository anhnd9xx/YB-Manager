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
require_once __DIR__ . '/WindowPlacementManager.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncWindowLayoutManager
{
    public const VERIFY_TOLERANCE_PX = 20;
    public const LAYOUT_LOCK_MS = 3000;

    /** Immutable LayoutPlan envelope: tinh 1 lan, apply khong recalculate. */
    public static function buildLayoutPlan(string $layoutId, int $generation, array $live,
        array $slots, array $areaByMonitorId, string $mode): array
    {
        $items = [];
        foreach ($live as $i => $w) {
            $slot = $slots[$i] ?? null;
            if ($slot === null) continue;
            $mid = (int)($slot['monitorId'] ?? 0);
            $area = $areaByMonitorId[$mid] ?? null;
            $items[] = [
                'profile_id' => (int)$w['profileId'],
                'hwnd' => (int)$w['hwnd'],
                'pid' => (int)($w['pid'] ?? 0),
                'source_rect' => $w['sourceRect'] ?? null,
                'source_monitor' => $w['sourceMonitor'] ?? null,
                'target_monitor' => $area['device_name'] ?? ($area['name'] ?? ''),
                'target_rect' => ['x' => (int)$slot['x'], 'y' => (int)$slot['y'],
                                  'w' => (int)$slot['w'], 'h' => (int)$slot['h']],
                'target_state' => 'normal',
                'z_order_index' => $i,
            ];
        }
        return [
            'layout_id' => $layoutId,
            'generation' => $generation,
            'created_at' => date('Y-m-d H:i:s'),
            'scope' => count($live),
            'layout_mode' => $mode,
            'window_count' => count($items),
            'window_items' => $items,
        ];
    }

    public static function nextLayoutId(array $profileIds): string
    {
        return 'lay_' . date('His') . '_' . substr(md5(json_encode($profileIds) . microtime(true)), 0, 6);
    }

    private static function undoFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_layout_undo.json';
    }

    private static function lastLayoutFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_layout_last.json';
    }
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
        $tAll = microtime(true);
        $sessionId = self::nextLayoutId($profileIds);
        $layoutId = $sessionId;
        $generation = WindowPlacementManager::nextLayoutGeneration();
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

        // 0) PORTABLE: refresh monitor registry truoc moi arrange (khong dung
        // cache may cu / topology cu). Day la buoc bat buoc truoc resolve.
        require_once __DIR__ . '/MonitorRegistry.php';
        $regAreas = MonitorRegistry::refresh();
        try {
            $meta = MonitorRegistry::lastMeta();
            SyncLogger::info('layout_monitor', '[MONITOR REGISTRY] count=' . count($regAreas)
                . ' method=' . ($meta['monitorEnumMethod'] ?? '?')
                . ' session=' . json_encode($meta['session'] ?? null, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }

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
        // Resolve qua MonitorRegistry (fallback chain, khong fatal khi stale pref).
        if ($monSetting === 'profile' && empty($opts['monitors']) && empty($explicitNames)) {
            // Bridge registry snapshot -> work-area shape cho engine (runtime, tuoi)
            $areaByKey = [];
            foreach ($regAreas as $rm) {
                $wa = $rm['work_area'];
                $areaByKey[strtolower((string)$rm['device_name'])] = [
                    'monitorId' => (int)$rm['runtime_id'], 'name' => (string)$rm['device_name'],
                    'x' => (int)$wa['x'], 'y' => (int)$wa['y'], 'w' => (int)$wa['w'], 'h' => (int)$wa['h'],
                    'resW' => (int)$rm['bounds']['w'], 'resH' => (int)$rm['bounds']['h'],
                    'dpi' => (int)$rm['dpi_x'], 'primary' => !empty($rm['is_primary'])];
            }
            // can profile rows de resolve (monitor_mode/last/fixed)
            $profById = [];
            try {
                foreach (db()->query('SELECT * FROM profiles') as $r) $profById[(int)$r['id']] = $r;
            } catch (Throwable $e) {
            }
            $resInfo = self::windowResolutions($live, $profById, $opts);
            $remappedCount = (int)$resInfo['remapped'];
            $groups = []; // device(lower) => [windows]
            $groupAreas = [];
            $groupLives = [];
            foreach ($live as $i => $w) {
                $ri = $resInfo['items'][$i];
                $area = null;
                if (!empty($ri['resolved'])) {
                    $area = $areaByKey[strtolower((string)$ri['resolved'])] ?? null;
                }
                if ($area === null) continue; // registry rong -> xu ly o duoi
                $key = strtolower((string)$ri['resolved']);
                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                    $groupAreas[$key] = $area;
                    $groupLives[$key] = [];
                }
                $groups[$key][] = $w;
                $groupLives[$key][] = $w;
            }
            if (!$groupAreas) {
                // Registry THUC SU rong moi loi (kem diagnostics, khong chung chung)
                $diag = MonitorRegistry::diagnostics();
                $sess = $diag['session'] ?? null;
                return ['ok' => false, 'partial' => false,
                    'message' => 'YT Manager không phát hiện được màn hình Windows'
                        . ' (monitor count=0, enum=' . ($diag['enum_method'] ?? '?')
                        . ', process session=' . (is_array($sess) ? ($sess['processSession'] ?? $sess['process_session'] ?? '?') : '?') . ')',
                    'monitor_diag' => $diag,
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
                $orderedSlots[] = $allSlots[$i] ?? null;
            }
            if (!empty($opts['dryRun'])) {
                $areaById = [];
                foreach (array_values($groupAreas) as $a) {
                    if (isset($a['monitorId'])) $areaById[(int)$a['monitorId']] = $a;
                }
                $lp = self::buildLayoutPlan($layoutId, $generation, $live, $orderedSlots,
                    $areaById, (string)($layout['mode'] ?? 'smart_auto'));
                return ['ok' => true, 'partial' => false, 'dryRun' => true,
                    'message' => count($lp['window_items']) . ' slots (preview, profile affinity)'
                        . ($remappedCount > 0 ? " — $remappedCount cấu hình màn hình đã ánh xạ sang màn hình hiện có" : ''),
                    'plan' => ['ok' => true, 'slots' => $orderedSlots, 'breakdown' => $breakdown]
                        + self::planSummary($orderedSlots, $layout),
                    'breakdown' => $breakdown,
                    'layout_id' => $layoutId, 'generation' => $generation,
                    'layoutPlan' => $lp,
                    'resolutions' => $resInfo['items'],
                    'session' => self::session($sessionId, $ids, $live, array_values($groupAreas), $layout, $win, $orderedSlots),
                    'results' => []];
            }
            // Apply chung (USER_LAYOUT thang startup guard: khong skip locked,
            // claim lock sau apply de worker cu exit)
            $tPlan = (int)round((microtime(true) - $tAll) * 1000);
            $tail = self::applyPlanBatch($live, $orderedSlots, array_values($groupAreas),
                $layout, $opts, $layoutId, $generation);
            $fr = self::finalResult($layoutId, $generation, $tAll, $tPlan, $tail,
                $ids, $live, array_values($groupAreas), $layout, $win, $orderedSlots,
                $breakdown, ' (profile affinity)');
            $fr['resolutions'] = $resInfo['items'];
            if ($remappedCount > 0) {
                $fr['message'] .= " — $remappedCount cấu hình màn hình đã được ánh xạ sang màn hình hiện có";
                $fr['remapped'] = $remappedCount;
            }
            return $fr;
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
            // Registry THUC SU rong moi loi (kem diagnostics cu the)
            $diag = MonitorRegistry::diagnostics();
            $sess = $diag['session'] ?? null;
            return ['ok' => false, 'partial' => false,
                'message' => 'YT Manager không phát hiện được màn hình Windows'
                    . ' (monitor count=0, enum=' . ($diag['enum_method'] ?? '?')
                    . ', process session=' . (is_array($sess) ? ($sess['processSession'] ?? $sess['process_session'] ?? '?') : '?') . ')',
                'monitor_diag' => $diag,
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
        $tPlan0 = microtime(true);
        $spm = (int)($opts['slotsPerMonitor'] ?? 0);
        if ($spm > 0 && $manualCounts === null) {
            // "N o/man": phan phoi moi monitor toi da $spm windows (overflow round-robin)
            $manualCounts = self::slotsPerMonitorCounts(count($live), count($areas), $spm);
            if ($manualCounts !== null) {
                $byPid = [];
                foreach ($live as $w) $byPid[(int)$w['profileId']] = $w;
                // Group live theo area de pairing slot dung (giong manual)
                $order = [];
                $k = 0;
                foreach ($manualCounts as $c) {
                    for ($j = 0; $j < $c; $j++) {
                        if (isset($live[$k])) $order[] = (int)$live[$k]['profileId'];
                        $k++;
                    }
                }
                if (count($order) === count($live)) {
                    $tmp = [];
                    foreach ($order as $pid) {
                        if (isset($byPid[$pid])) $tmp[] = $byPid[$pid];
                    }
                    $live = $tmp;
                }
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
        $tPlan = (int)round((microtime(true) - $tPlan0) * 1000);
        // Overflow warning: cell nho hon minimum usable
        $tooSmall = ((int)($plan['cellW'] ?? 0) > 0 && (int)($plan['cellW'] ?? 0) < (int)($layout['minW'] ?? 0))
            || ((int)($plan['cellH'] ?? 0) > 0 && (int)($plan['cellH'] ?? 0) < (int)($layout['minH'] ?? 0));
        // dryRun (preview): tra plan + envelope, KHONG move (spec muc 16).
        // Preview dung CHINH engine calculate -> preview == actual.
        if (!empty($opts['dryRun'])) {
            $areaById = [];
            foreach ($areas as $a) {
                if (isset($a['monitorId'])) $areaById[(int)$a['monitorId']] = $a;
            }
            $lp = self::buildLayoutPlan($layoutId, $generation, $live, $plan['slots'],
                $areaById, (string)($layout['mode'] ?? 'smart_auto'));
            $resDry = ['items' => [], 'remapped' => 0];
            try {
                $profByIdDry = [];
                foreach (db()->query('SELECT * FROM profiles') as $r) $profByIdDry[(int)$r['id']] = $r;
                $resDry = self::windowResolutions($live, $profByIdDry, $opts);
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'partial' => false, 'dryRun' => true,
                'message' => count($plan['slots']) . ' slots (preview)'
                    . ($tooSmall ? ' — ⚠ cửa sổ sẽ rất nhỏ' : '')
                    . ((int)$resDry['remapped'] > 0 ? ' — ' . (int)$resDry['remapped'] . ' cấu hình màn hình sẽ được ánh xạ' : ''),
                'missingMonitors' => $missingMonitors, 'disconnected' => (bool)$missingMonitors,
                'plan' => $plan + self::planSummary($plan['slots'], $layout)
                    + ['tooSmall' => $tooSmall],
                'layout_id' => $layoutId, 'generation' => $generation,
                'layoutPlan' => $lp,
                'resolutions' => $resDry['items'],
                'remapped' => (int)$resDry['remapped'],
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

        // 3) Apply tail dung chung: undo + batch + verify/retry + lock + batch save.
        $tail = self::applyPlanBatch($live, $plan['slots'], $areas, $layout, $opts, $layoutId, $generation);
        if ($tooSmall) $tail['tooSmall'] = true;
        // Per-window resolution detail (stale pref -> fallback, khong fatal)
        try {
            $profById2 = [];
            foreach (db()->query('SELECT * FROM profiles') as $r) $profById2[(int)$r['id']] = $r;
            $resMain = self::windowResolutions($live, $profById2, $opts);
            $fr = self::finalResult($layoutId, $generation, $tAll, $tPlan, $tail,
                $ids, $live, $areas, $layout, $win, $plan['slots'], $plan, '');
            $fr['resolutions'] = $resMain['items'];
            if ((int)$resMain['remapped'] > 0) {
                $fr['message'] .= ' — ' . (int)$resMain['remapped'] . ' cấu hình màn hình đã được ánh xạ sang màn hình hiện có';
                $fr['remapped'] = (int)$resMain['remapped'];
            }
            return $fr;
        } catch (Throwable $e) {
            return self::finalResult($layoutId, $generation, $tAll, $tPlan, $tail,
                $ids, $live, $areas, $layout, $win, $plan['slots'], $plan, '');
        }
    }

    /**
     * "N o/man" (slotsPerMonitor): moi monitor toi da $slots windows, phan con
     * lai overflow round-robin. Tra ve counts[] khop thu tu $areas (tong = n).
     * PURE — test duoc khong can monitor that.
     */
    public static function slotsPerMonitorCounts(int $n, int $monitorCount, int $slots): ?array
    {
        if ($n <= 0 || $monitorCount <= 0 || $slots <= 0) return null;
        $counts = array_fill(0, $monitorCount, 0);
        $rest = $n;
        for ($i = 0; $i < $monitorCount && $rest > 0; $i++) {
            $take = min($slots, $rest);
            $counts[$i] = $take;
            $rest -= $take;
        }
        $i = 0;
        while ($rest > 0) {
            $counts[$i % $monitorCount]++;
            $rest--;
            $i++;
        }
        return $counts;
    }

    /**
     * Tom tat preview tu slots (cung so lieu apply se dung): phan bo monitor,
     * kich thuoc, overlap check. PURE.
     */
    public static function planSummary(array $slots, array $layout): array
    {
        $byMon = [];
        $minW = PHP_INT_MAX;
        $minH = PHP_INT_MAX;
        foreach ($slots as $s) {
            if (!is_array($s)) continue;
            $mid = (int)($s['monitorId'] ?? 0);
            $byMon[$mid] = ($byMon[$mid] ?? 0) + 1;
            if ((int)($s['w'] ?? 0) > 0) $minW = min($minW, (int)$s['w']);
            if ((int)($s['h'] ?? 0) > 0) $minH = min($minH, (int)$s['h']);
        }
        // Overlap check O(n^2) tren slots (n <= ~50, re)
        $overlap = false;
        $rects = array_values(array_filter($slots, fn($s) =>
            (int)($s['w'] ?? 0) > 0 && (int)($s['h'] ?? 0) > 0));
        for ($i = 0; $i < count($rects) && !$overlap; $i++) {
            for ($j = $i + 1; $j < count($rects); $j++) {
                $a = $rects[$i];
                $b = $rects[$j];
                if (max((int)$a['x'], (int)$b['x']) < min((int)$a['x'] + (int)$a['w'], (int)$b['x'] + (int)$b['w'])
                    && max((int)$a['y'], (int)$b['y']) < min((int)$a['y'] + (int)$a['h'], (int)$b['y'] + (int)$b['h'])) {
                    // Cascade/compact co y dinh overlap -> khong coi la loi
                    if (!in_array((string)($layout['mode'] ?? ''), ['cascade', 'compact'], true)) {
                        $overlap = true;
                    }
                    break;
                }
            }
        }
        return ['perMonitor' => $byMon,
            'cellMin' => ['w' => $minW === PHP_INT_MAX ? 0 : $minW, 'h' => $minH === PHP_INT_MAX ? 0 : $minH],
            'noOverlap' => !$overlap,
            'gap' => ['x' => (int)($layout['gapX'] ?? 0), 'y' => (int)($layout['gapY'] ?? 0)]];
    }

    /**
     * Envelope ket qua chuan: requested/valid/moved/verified/failed/skipped/
     * duration_ms + diag (layout_id, generation, plan/apply/verify ms, retries,
     * guardsClaimed, saved). Ghi last-layout diagnostics 1 lan.
     */
    private static function finalResult(string $layoutId, int $generation, float $tAll,
        int $planMs, array $tail, array $ids, array $live, array $areas,
        array $layout, array $win, array $slots, $planOrBreakdown, string $suffix): array
    {
        $requested = count($ids);
        $valid = count($live);
        $moved = (int)$tail['moved'];
        $verified = (int)$tail['verified'];
        $skipped = (int)$tail['skipped'] + max(0, $requested - $valid);
        $failed = $valid - $moved;
        $totalMs = (int)round((microtime(true) - $tAll) * 1000);
        $tooSmall = !empty($tail['tooSmall']);
        $msg = "Đã sắp xếp $verified/$requested"
            . ($failed > 0 ? ", $failed lỗi" : '')
            . ($skipped > 0 && $failed <= 0 ? ", $skipped bỏ qua" : '')
            . ($tooSmall ? ' — ⚠ cửa sổ rất nhỏ, nên phân bổ thêm màn hình' : '')
            . $suffix;
        $diag = ['layout_id' => $layoutId, 'generation' => $generation,
            'requested' => $requested, 'valid' => $valid, 'moved' => $moved,
            'verified' => $verified, 'failed' => max(0, $failed), 'skipped' => $skipped,
            'plan_ms' => $planMs, 'apply_ms' => (int)$tail['applyMs'],
            'verify_ms' => (int)$tail['verifyMs'], 'duration_ms' => $totalMs,
            'retries' => (int)$tail['retries'], 'guards_claimed' => (int)$tail['guardsClaimed'],
            'saved' => (int)$tail['saved'], 'too_small' => $tooSmall, 'undo' => true];
        try {
            SyncLogger::info('layout_done', '[LAYOUT DONE] id=' . $layoutId . ' gen=' . $generation
                . " requested=$requested valid=$valid moved=$moved verified=$verified"
                . " failed=" . max(0, $failed) . " skipped=$skipped"
                . " plan={$planMs}ms apply={$tail['applyMs']}ms verify={$tail['verifyMs']}ms"
                . " retries={$tail['retries']} guards={$tail['guardsClaimed']} saved={$tail['saved']}");
            require_once __DIR__ . '/StateStore.php';
            StateStore::writeJson(self::lastLayoutFile(),
                $diag + ['at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
        }
        $planOut = is_array($planOrBreakdown) && isset($planOrBreakdown['slots'])
            ? $planOrBreakdown + self::planSummary($planOrBreakdown['slots'], $layout)
            : ['ok' => true, 'slots' => $slots, 'breakdown' => $planOrBreakdown]
                + self::planSummary($slots, $layout);
        $planOut['tooSmall'] = $tooSmall;
        return [
            'ok' => $failed <= 0 && $skipped <= max(0, $requested - $valid),
            'partial' => $moved > 0 && ($failed > 0 || $tail['skipped'] > 0),
            'message' => $msg,
            'plan' => $planOut,
            'breakdown' => is_array($planOrBreakdown) && isset($planOrBreakdown['breakdown'])
                ? $planOrBreakdown['breakdown'] : $planOrBreakdown,
            'layout_id' => $layoutId, 'generation' => $generation,
            'layoutPlan' => $tail['layoutPlan'],
            'diag' => $diag,
            'requested' => $requested, 'valid' => $valid, 'moved' => $moved,
            'verified' => $verified, 'failed' => max(0, $failed), 'skipped' => $skipped,
            'duration_ms' => $totalMs,
            'session' => self::session($layoutId, $ids, $live, $areas, $layout, $win, $slots),
            'results' => $tail['results'],
        ];
    }

    /**
     * Resolve tung window: explicit modal > profile preference > current window >
     * primary > first. Khong bao gio fatal vi stale pref (tra method + remapped).
     * @return array{items: array, remapped: int} items khop thu tu $live
     */
    private static function windowResolutions(array $live, array $profById, array $opts): array
    {
        $explicitAll = [];
        if (!empty($opts['monitors']) && is_array($opts['monitors'])) {
            $explicitAll = array_values($opts['monitors']);
        }
        $items = [];
        $remapped = 0;
        foreach ($live as $w) {
            $pid = (int)$w['profileId'];
            $pr = $profById[$pid] ?? ['id' => $pid];
            $mode = strtoupper(trim((string)($pr['monitor_mode'] ?? 'LAST')));
            $saved = '';
            if ($mode === 'FIXED') $saved = (string)($pr['fixed_monitor_device'] ?? '');
            elseif ($mode === 'SECONDARY') $saved = '';
            else $saved = (string)($pr['last_monitor_device'] ?? '');
            // Explicit modal: 1 monitor cho ca batch (monitors[0]) hoac 'all'
            $explicit = '';
            if (count($explicitAll) === 1) $explicit = (string)$explicitAll[0];
            $r = MonitorRegistry::resolve($explicit, $mode, $saved, (int)($w['hwnd'] ?? 0));
            $mon = $r['monitor'];
            $items[] = ['profileId' => $pid,
                'requested' => $explicit !== '' ? $explicit : ($saved !== '' ? $saved : $mode),
                'resolved' => $mon['device_name'] ?? null,
                'method' => $r['method'], 'remapped' => !empty($r['remapped'])];
            if (!empty($r['remapped'])) $remapped++;
        }
        return ['items' => $items, 'remapped' => $remapped];
    }

    /**
     * Apply tail dung chung: undo snapshot -> 1 batch DeferWindowPos ->
     * verify all (tolerance) -> retry ONCE chi window lech -> claim USER_LAYOUT
     * lock (thang startup worker) -> batch save final rects 1 lan.
     * $slots: list slot (hoac null item) khop thu tu $live.
     * Tra ve ['results','okCount','failCount','skipped','moved','verified','retries',
     *          'guardsClaimed','saved','verifyMs','applyMs','layoutPlan'].
     */
    private static function applyPlanBatch(array $live, array $slots, array $areas,
        array $layout, array $opts, string $layoutId, int $generation): array
    {
        $tApply0 = microtime(true);
        $areaById = [];
        foreach ($areas as $a) {
            if (isset($a['monitorId'])) $areaById[(int)$a['monitorId']] = $a;
        }
        // Re-validate HWND ngay truoc batch (window dong giua chung -> skip, khong crash)
        $fresh = [];
        try {
            foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) $fresh[$w->hwnd] = $w;
        } catch (Throwable $e) {
        }
        // Gan source rect/monitor vao live (cho LayoutPlan immutable + undo)
        foreach ($live as $i => &$w) {
            $fw = $fresh[(int)$w['hwnd']] ?? null;
            if ($fw !== null && $fw->rect !== null) {
                $w['sourceRect'] = $fw->rect;
                $mon = WindowPlacementManager::findById((int)($fw->monitorId ?? -1));
                $w['sourceMonitor'] = $mon['device_name'] ?? null;
            }
        }
        unset($w);
        $layoutPlan = self::buildLayoutPlan($layoutId, $generation, $live, $slots,
            $areaById, (string)($layout['mode'] ?? 'smart_auto'));
        // Undo snapshot: rect truoc apply (chi 1 slot "last layout")
        try {
            $undoItems = [];
            foreach ($layoutPlan['window_items'] as $it) {
                if (!empty($it['source_rect'])) {
                    $undoItems[] = ['profileId' => $it['profile_id'], 'hwnd' => $it['hwnd'],
                        'rect' => $it['source_rect'], 'monitor' => $it['source_monitor']];
                }
            }
            if ($undoItems) {
                require_once __DIR__ . '/StateStore.php';
                StateStore::writeJson(self::undoFile(),
                    ['layout_id' => $layoutId, 'created_at' => date('Y-m-d H:i:s'),
                     'items' => $undoItems]);
            }
        } catch (Throwable $e) {
        }
        $noActivate = !array_key_exists('noActivate', $opts) || !empty($opts['noActivate']);
        $batchIn = [];
        $skipped = [];
        foreach ($live as $i => $w) {
            $slot = $slots[$i] ?? null;
            if ($slot === null) {
                $skipped[] = $i;
                continue;
            }
            if (!isset($fresh[(int)$w['hwnd']])) {
                $skipped[] = $i;
                continue;
            }
            $batchIn[] = ['hwnd' => (int)$w['hwnd'], 'x' => (int)$slot['x'], 'y' => (int)$slot['y'],
                          'w' => (int)$slot['w'], 'h' => (int)$slot['h']];
        }
        $batchOut = SyncWindowManager::applyLayoutBatch($batchIn, $noActivate,
            ['reason' => 'user_layout', 'batch_id' => $layoutId]);
        $applyMs = (int)round((microtime(true) - $tApply0) * 1000);
        // Verify all (nhe, dung rect script tra ve) -> retry ONCE chi window lech
        $tVerify0 = microtime(true);
        $tol = self::VERIFY_TOLERANCE_PX;
        $wrong = [];
        foreach ($live as $i => $w) {
            if (in_array($i, $skipped, true)) continue;
            $slot = $slots[$i] ?? null;
            if ($slot === null) continue;
            $one = $batchOut[(string)(int)$w['hwnd']] ?? null;
            if (empty($one['ok']) || !is_array($one['rect'] ?? null)) {
                $wrong[] = $i;
                continue;
            }
            $rc = $one['rect'];
            if (abs((int)$rc['x'] - (int)$slot['x']) > $tol || abs((int)$rc['y'] - (int)$slot['y']) > $tol
                || abs((int)$rc['w'] - (int)$slot['w']) > $tol || abs((int)$rc['h'] - (int)$slot['h']) > $tol) {
                $wrong[] = $i;
            }
        }
        $retries = 0;
        if ($wrong) {
            $retryIn = [];
            foreach ($wrong as $i) {
                $w = $live[$i];
                $slot = $slots[$i];
                $retryIn[] = ['hwnd' => (int)$w['hwnd'], 'x' => (int)$slot['x'], 'y' => (int)$slot['y'],
                              'w' => (int)$slot['w'], 'h' => (int)$slot['h']];
            }
            $retryOut = SyncWindowManager::applyLayoutBatch($retryIn, $noActivate,
                ['reason' => 'user_layout_retry', 'batch_id' => $layoutId]);
            foreach ($retryOut as $k => $one) $batchOut[$k] = $one;
            $retries = 1;
            try {
                SyncLogger::info('layout_verify', '[VERIFY] layout=' . $layoutId
                    . ' retry_once wrong=' . count($wrong));
            } catch (Throwable $e) {
            }
        }
        $verifyMs = (int)round((microtime(true) - $tVerify0) * 1000);
        // Ket qua cuoi (sau retry)
        $results = [];
        $okCount = 0;
        $verified = 0;
        foreach ($live as $i => $w) {
            $slot = $slots[$i] ?? null;
            $res = ['profileId' => $w['profileId'], 'profileName' => $w['profileName'],
                    'hwnd' => $w['hwnd'], 'slot' => $slot, 'ok' => false, 'error' => null, 'rect' => null];
            if ($slot === null || in_array($i, $skipped, true)) {
                $res['error'] = $slot === null ? 'Khong co slot (plan thieu)' : 'HWND khong con (window da dong?)';
                if (in_array($i, $skipped, true)) {
                    SyncLogger::warn('layout_apply', "[Layout] Skip profile #{$w['profileId']}: " . $res['error'], (int)$w['profileId']);
                }
            } else {
                $one = $batchOut[(string)(int)$w['hwnd']] ?? null;
                if ($one !== null && !empty($one['ok'])) {
                    $res['ok'] = true;
                    $res['rect'] = $one['rect'];
                    $okCount++;
                    $rc = is_array($one['rect'] ?? null) ? $one['rect'] : null;
                    if ($rc !== null && abs((int)$rc['x'] - (int)$slot['x']) <= $tol
                        && abs((int)$rc['y'] - (int)$slot['y']) <= $tol
                        && abs((int)$rc['w'] - (int)$slot['w']) <= $tol
                        && abs((int)$rc['h'] - (int)$slot['h']) <= $tol) {
                        $verified++;
                    }
                } else {
                    $res['error'] = (string)(($one['error'] ?? null) ?: 'moveResize that bai');
                    SyncLogger::warn('layout_apply', "[Layout] profile #{$w['profileId']}: " . $res['error'], (int)$w['profileId']);
                }
            }
            $results[] = $res;
        }
        // USER_LAYOUT thang STARTUP: claim lock de worker cu exit, giu on dinh
        $claim = [];
        foreach ($live as $i => $w) {
            $slot = $slots[$i] ?? null;
            if ($slot === null || in_array($i, $skipped, true)) continue;
            $mid = (int)($slot['monitorId'] ?? 0);
            $claim[(int)$w['profileId']] = [
                'hwnd' => (int)$w['hwnd'],
                'rect' => ['x' => (int)$slot['x'], 'y' => (int)$slot['y'],
                            'w' => (int)$slot['w'], 'h' => (int)$slot['h']],
                'monitor' => (string)($areaById[$mid]['device_name'] ?? ($areaById[$mid]['name'] ?? '')),
            ];
        }
        $guardsClaimed = 0;
        try {
            $guardsClaimed = WindowPlacementManager::claimLayoutLock($claim, $layoutId, $generation);
        } catch (Throwable $e) {
        }
        // Batch save final rects 1 lan (khong save intermediate)
        $saved = 0;
        try {
            $profById = [];
            $idsSave = array_map(fn($w) => (int)$w['profileId'], $live);
            if ($idsSave) {
                $in = implode(',', array_fill(0, count($idsSave), '?'));
                $st = db()->prepare("SELECT * FROM profiles WHERE id IN ($in)");
                $st->execute($idsSave);
                foreach ($st->fetchAll() as $r) $profById[(int)$r['id']] = $r;
            }
            $post = null;
            try {
                $post = SyncWindowDiscovery::discover(false)['windows'];
            } catch (Throwable $e) {
            }
            foreach ($live as $i => $w) {
                if (in_array($i, $skipped, true)) continue;
                $pr = $profById[(int)$w['profileId']] ?? null;
                if ($pr === null) continue;
                if (WindowPlacementManager::save_window_placement($pr, (int)$w['hwnd'], $post)) $saved++;
            }
        } catch (Throwable $e) {
        }
        return ['results' => $results, 'okCount' => $okCount, 'verified' => $verified,
            'skipped' => count($skipped), 'moved' => $okCount, 'retries' => $retries,
            'guardsClaimed' => $guardsClaimed, 'saved' => $saved,
            'applyMs' => $applyMs, 'verifyMs' => $verifyMs, 'layoutPlan' => $layoutPlan];
    }

    /**
     * Hoan tac layout gan nhat: restore rect truoc apply (chi HWND/profile con valid).
     */
    public static function arrangeUndo(): array
    {
        $t0 = microtime(true);
        require_once __DIR__ . '/StateStore.php';
        $u = StateStore::readJson(self::undoFile());
        if (!is_array($u) || empty($u['items'])) {
            return ['ok' => false, 'message' => !is_file(self::undoFile()) ? 'Chua co layout nao de hoan tac' : 'Snapshot hoan tac rong', 'results' => []];
        }
        $fresh = [];
        try {
            foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
                if ($w->profileId !== null) $fresh[(int)$w->profileId] = $w;
            }
        } catch (Throwable $e) {
        }
        $batchIn = [];
        $skipped = 0;
        foreach ((array)$u['items'] as $it) {
            $pid = (int)($it['profileId'] ?? 0);
            $rc = $it['rect'] ?? null;
            $fw = $fresh[$pid] ?? null;
            if ($pid <= 0 || !is_array($rc) || $fw === null) {
                $skipped++;
                continue;
            }
            if ((int)($rc['w'] ?? 0) <= 0 || (int)($rc['h'] ?? 0) <= 0) {
                $skipped++;
                continue;
            }
            $batchIn[] = ['hwnd' => (int)$fw->hwnd, 'x' => (int)$rc['x'], 'y' => (int)$rc['y'],
                          'w' => (int)$rc['w'], 'h' => (int)$rc['h']];
        }
        if (!$batchIn) {
            return ['ok' => false, 'message' => 'Khong con window hop le de hoan tac', 'results' => []];
        }
        $layoutId = self::nextLayoutId(array_column($batchIn, 'hwnd'));
        $gen = WindowPlacementManager::nextLayoutGeneration();
        $out = SyncWindowManager::applyLayoutBatch($batchIn, true,
            ['reason' => 'user_layout_undo', 'batch_id' => $layoutId]);
        $ok = 0;
        foreach ($out as $one) {
            if (!empty($one['ok'])) $ok++;
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        try {
            SyncLogger::info('layout_undo', '[UNDO] layout=' . $layoutId . ' ok=' . $ok
                . '/' . count($batchIn) . ' skipped=' . $skipped . ' ms=' . $ms);
        } catch (Throwable $e) {
        }
        return ['ok' => $ok > 0, 'message' => "Hoan tac $ok/" . count($batchIn) . ' window'
            . ($skipped > 0 ? " ($skipped khong con hop le)" : ''),
            'results' => $out, 'layout_id' => $layoutId, 'duration_ms' => $ms];
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
