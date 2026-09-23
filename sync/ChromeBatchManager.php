<?php
declare(strict_types=1);
/**
 * ChromeBatchManager - BATCH LIFECYCLE ENGINE DUY NHAT cho Start/Stop.
 *
 * start_all / start_selected / stop_all / stop_selected DEU dung engine nay
 * (chi khac danh sach profile IDs). Khong implementation rieng le.
 *
 * Runtime state chuan (§2): STOPPED|QUEUED|PREPARING|STARTING|WINDOW_READY|
 *   VERIFYING|RUNNING|CLOSING|STOPPING|ERROR (file ytm_life_<id>.json, 1 source
 *   of truth, khong boolean rac). STOPPED/RUNNING khong persist (suy tu process).
 *
 * START (§3-§6): PREPARE (build StartPlan truoc Popen) -> CHUNK (bounded,
 *   mac dinh 5, stagger 100ms, slot release khi PID co) -> POLL (HWND ready,
 *   placement verify, tab verify; KHONG doi website load).
 * STOP (§23-§32): Phase A snapshot nhanh (deadline/profile) -> Phase B dispatch
 *   WM_CLOSE 1 lan cho TAT CA HWND (PostMessage, khong SendMessage) -> shared
 *   monitor (100-150ms, 1 WMI scan/luot, khong wait tung PID) -> fallback chi
 *   stuck profiles (khong taskkill /IM).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/WindowPlacementManager.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/TabSessionStore.php';

class ChromeBatchManager
{
    // Runtime states (§2)
    public const ST_STOPPED = 'STOPPED';
    public const ST_QUEUED = 'QUEUED';
    public const ST_PREPARING = 'PREPARING';
    public const ST_STARTING = 'STARTING';
    public const ST_WINDOW_READY = 'WINDOW_READY';
    public const ST_VERIFYING = 'VERIFYING';
    public const ST_RUNNING = 'RUNNING';
    public const ST_CLOSING = 'CLOSING';
    public const ST_STOPPING = 'STOPPING';
    public const ST_ERROR = 'ERROR';
    public const ST_CANCELLED = 'CANCELLED';

    public const MAX_PARALLEL_START = 5;
    public const START_STAGGER_MS = 100;
    public const TAB_SNAPSHOT_CONCURRENCY = 8;
    public const SNAPSHOT_BUDGET_MS = 800;   // per profile trong batch (§25)
    public const GRACE_SAFE_MS = 2500;       // AN TOAN (§33)
    public const GRACE_FAST_MS = 1200;       // NHANH (§33)
    public const POLL_MS = 150;              // shared monitor (§31)
    public const LIFE_TTL_SEC = 120;         // transitional expiry

    // ================= Life state (per-profile lock) =================

    private static function lifeFile(int $id): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_life_' . $id . '.json';
    }

    /** @return array{state:string, batch_id?:string, ts:float}|null (null = khong transitional) */
    public static function lifeGet(int $id): ?array
    {
        $f = self::lifeFile($id);
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || empty($j['state'])) return null;
        if (in_array($j['state'], [self::ST_RUNNING, self::ST_STOPPED], true)) {
            @unlink($f);
            return null;
        }
        if ((microtime(true) - (float)($j['ts'] ?? 0)) > self::LIFE_TTL_SEC) {
            @unlink($f);
            return null;
        }
        return $j;
    }

    public static function lifeSet(int $id, string $state, ?string $batchId = null, array $extra = []): void
    {
        if (in_array($state, [self::ST_RUNNING, self::ST_STOPPED], true)) {
            @unlink(self::lifeFile($id));
            return;
        }
        @file_put_contents(self::lifeFile($id), json_encode(
            ['state' => $state, 'batch_id' => $batchId, 'ts' => microtime(true)] + $extra,
            JSON_UNESCAPED_UNICODE));
    }

    public static function lifeClear(int $id): void
    {
        @unlink(self::lifeFile($id));
    }

    /** Per-profile lifecycle lock (§38): STARTING/CLOSING khong chong lan. */
    public static function isBusy(int $id): bool
    {
        $l = self::lifeGet($id);
        if ($l === null) return false;
        return in_array($l['state'], [self::ST_QUEUED, self::ST_PREPARING, self::ST_STARTING,
            self::ST_WINDOW_READY, self::ST_VERIFYING, self::ST_CLOSING, self::ST_STOPPING], true);
    }

    // ================= Stop-active flag (freeze arrange/monitoring) =================

    private static function stopFlagFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_batch_stop_active.json';
    }

    public static function stopActive(): bool
    {
        $f = self::stopFlagFile();
        if (!is_file($f)) return false;
        if ((microtime(true) - (float)((json_decode((string)@file_get_contents($f), true)['ts'] ?? 0))) > 180) {
            @unlink($f);
            return false;
        }
        return true;
    }

    public static function setStopActive(bool $on, ?string $batchId = null): void
    {
        if ($on) {
            @file_put_contents(self::stopFlagFile(), json_encode(
                ['ts' => microtime(true), 'batch_id' => $batchId], JSON_UNESCAPED_UNICODE));
        } else {
            @unlink(self::stopFlagFile());
        }
    }

    // ================= Batch registry =================

    private static function batchFile(string $kind, string $batchId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_batch_' . $kind . '_' . $batchId . '.json';
    }

    private static function newBatchId(string $prefix): string
    {
        return $prefix . '_' . date('His') . '_' . substr(md5(microtime(true) . mt_rand()), 0, 6);
    }

    private static function loadBatch(string $kind, string $batchId): ?array
    {
        $f = self::batchFile($kind, $batchId);
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    private static function saveBatch(string $kind, string $batchId, array $b): void
    {
        @file_put_contents(self::batchFile($kind, $batchId), json_encode($b, JSON_UNESCAPED_UNICODE));
    }

    private static function gcBatches(): void
    {
        try {
            foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_batch_*_*.json') ?: [] as $f) {
                if (is_file($f) && (time() - (int)@filemtime($f)) > 3600) @unlink($f);
            }
        } catch (Throwable $e) {
        }
    }

    private static function profileRow(int $id): ?array
    {
        try {
            $st = db()->prepare(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.password AS proxy_pass, pr.status AS proxy_status,
                        pr.last_check AS proxy_last_check
                 FROM profiles p LEFT JOIN proxies pr ON pr.id = p.proxy_id WHERE p.id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ================= START =================

    /**
     * PREPARE: snapshot ids + build StartPlan truoc Popen (§3-§4).
     * @return array{ok, batch_id, total, queued, skipped}
     */
    public static function startPrepare(array $ids): array
    {
        self::gcBatches();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $batchId = self::newBatchId('st');
        $plans = [];
        $states = [];
        $skipped = [];
        try {
            $snap = SyncSettingsService::snapshot();
            $fbSize = ['w' => (int)($snap['width'] ?? 1280), 'h' => (int)($snap['height'] ?? 720)];
        } catch (Throwable $e) {
            $fbSize = ['w' => 1280, 'h' => 720];
        }
        // 1 monitor scan cho ca batch (cache 5s)
        try {
            WindowPlacementManager::monitors();
        } catch (Throwable $e) {
        }
        foreach ($ids as $id) {
            if (self::isBusy($id)) {
                $skipped[] = ['id' => $id, 'reason' => 'busy'];
                continue;
            }
            $p = self::profileRow($id);
            if (!$p) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            // Dang chay + CDP + config khop -> skip (khong relaunch mat tab)
            try {
                if (is_chrome_running($p) && !empty($p['debug_port'])
                    && cdp_reachable((int)$p['debug_port']) && chrome_cmdline_matches_config($p)) {
                    $skipped[] = ['id' => $id, 'reason' => 'already_running'];
                    continue;
                }
            } catch (Throwable $e) {
            }
            $mon = null;
            $rect = null;
            try {
                $mon = WindowPlacementManager::resolve_target_monitor($p);
                $rect = WindowPlacementManager::resolve_startup_rect($p, $mon, $fbSize);
            } catch (Throwable $e) {
            }
            $urls = [];
            $activeUrl = null;
            $savedCount = 0;
            try {
                require_once __DIR__ . '/TabSessionStore.php';
                $sess = TabSessionStore::getUrlsForLaunch($id);
                if (is_array($sess) && !empty($sess['urls'])) {
                    $urls = array_values($sess['urls']);
                    $activeUrl = $sess['activeUrl'] ?? null;
                    $savedCount = count($urls);
                }
            } catch (Throwable $e) {
            }
            // §4 StartPlan
            $plans[$id] = [
                'profile_id' => $id,
                'target_monitor' => $mon['device_name'] ?? null,
                'target_rect' => $rect,
                'saved_tab_count' => $savedCount,
                'saved_urls' => $urls,
                'saved_active_url' => $activeUrl,
            ];
            $states[$id] = self::ST_QUEUED;
            self::lifeSet($id, self::ST_QUEUED, $batchId);
            try {
                SyncLogger::info('start_batch', '[START PLAN] profile=' . $id
                    . ' monitor=' . ($mon['device_name'] ?? '?')
                    . ' rect=' . ($rect ? $rect['x'] . ',' . $rect['y'] . ',' . $rect['w'] . 'x' . $rect['h'] : '?')
                    . ' tabs=' . $savedCount, $id);
            } catch (Throwable $e) {
            }
        }
        $t0 = microtime(true);
        self::saveBatch('open', $batchId, ['batch_id' => $batchId, 'ids' => $ids,
            'plans' => $plans, 'states' => $states, 'skipped' => $skipped,
            't0' => $t0, 'status' => 'running', 'cancelled' => false]);
        try {
            SyncLogger::info('start_batch', '[START BATCH] id=' . $batchId
                . ' profiles=' . count($ids) . ' queued=' . count($plans)
                . ' skipped=' . count($skipped) . ' concurrency=' . self::MAX_PARALLEL_START);
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'batch_id' => $batchId, 'total' => count($ids),
            'queued' => count($plans), 'skipped' => $skipped];
    }

    /**
     * CHUNK: launch toi da $limit QUEUED plans (bounded parallel §5,
     * stagger 100ms non-blocking §6). Slot release khi PID co (§5:
     * khong cho website/proxy/eval/badge).
     */
    public static function startChunk(string $batchId, int $limit = self::MAX_PARALLEL_START): array
    {
        $limit = max(2, min(8, $limit));
        $b = self::loadBatch('open', $batchId);
        if (!$b) return ['ok' => false, 'message' => 'Batch khong ton tai'];
        if (!empty($b['cancelled'])) {
            return ['ok' => true, 'batch_id' => $batchId, 'launched' => [],
                'remaining' => 0, 'done' => true, 'cancelled' => true];
        }
        $launched = [];
        $errors = [];
        $n = 0;
        foreach ((array)($b['states'] ?? []) as $idStr => $st) {
            if ($n >= $limit) break;
            $id = (int)$idStr;
            if ($st !== self::ST_QUEUED) continue;
            if ($n > 0) usleep(self::START_STAGGER_MS * 1000); // stagger nhe, non-blocking
            $r = self::launchOne($id, $batchId, (array)(($b['plans'] ?? [])[$idStr] ?? []));
            $n++;
            if (!empty($r['ok'])) {
                $b['states'][$idStr] = self::ST_STARTING;
                $launched[] = ['id' => $id, 'port' => $r['port'] ?? null, 'popen_ms' => $r['popen_ms'] ?? 0];
            } else {
                $b['states'][$idStr] = self::ST_ERROR;
                $errors[] = ['id' => $id, 'error' => $r['error'] ?? 'launch_failed'];
                self::lifeSet($id, self::ST_ERROR, $batchId);
            }
        }
        $remaining = 0;
        foreach ((array)($b['states'] ?? []) as $st) {
            if ($st === self::ST_QUEUED) $remaining++;
        }
        if ($remaining <= 0) $b['status'] = 'dispatched';
        self::saveBatch('open', $batchId, $b);
        return ['ok' => true, 'batch_id' => $batchId, 'launched' => $launched,
            'errors' => $errors, 'remaining' => $remaining, 'done' => $remaining <= 0];
    }

    /** Generation token (§28): moi start tang 1; callback cu mang gen cu -> discard. */
    public static function nextGeneration(int $id): int
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_gen_' . $id . '.json';
        $gen = 0;
        try {
            if (is_file($f)) {
                $j = json_decode((string)@file_get_contents($f), true);
                $gen = (int)(is_array($j) ? ($j['gen'] ?? 0) : 0);
            }
        } catch (Throwable $e) {
        }
        $gen++;
        @file_put_contents($f, json_encode(['gen' => $gen], JSON_UNESCAPED_UNICODE));
        return $gen;
    }

    /** Launch 1 profile: checks -> port -> Popen (reuse launch_chrome) -> DB running. */
    private static function launchOne(int $id, string $batchId, array $plan): array
    {
        $t0 = microtime(true);
        try {
            $p = self::profileRow($id);
            if (!$p) return ['ok' => false, 'error' => 'profile_not_found'];
            // Per-profile mutex (§38)
            $life = self::lifeGet($id);
            if ($life !== null && !in_array($life['state'], [self::ST_QUEUED, self::ST_ERROR], true)) {
                return ['ok' => false, 'error' => 'busy_' . $life['state']];
            }
            self::lifeSet($id, self::ST_PREPARING, $batchId);
            if (!file_exists(chrome_path())) {
                return ['ok' => false, 'error' => 'chrome_not_found'];
            }
            // Cu chay sai config -> dong nhe truoc relaunch (snapshot nhanh, khong wait)
            try {
                if (is_chrome_running($p)) {
                    if (!empty($p['debug_port']) && cdp_reachable((int)$p['debug_port'])
                        && chrome_cmdline_matches_config($p)) {
                        self::lifeClear($id);
                        try {
                            db()->prepare('UPDATE profiles SET status=?, last_opened=NOW() WHERE id=?')
                                ->execute(['running', $id]);
                        } catch (Throwable $e) {
                        }
                        return ['ok' => true, 'port' => (int)$p['debug_port'], 'popen_ms' => 0, 'already' => true];
                    }
                    $oldPort = (int)($p['debug_port'] ?? 0);
                    if ($oldPort > 0 && cdp_reachable($oldPort)) {
                        $snap = TabSessionStore::snapshotLive($id, $oldPort, false, self::SNAPSHOT_BUDGET_MS);
                        if ($snap !== null) TabSessionStore::save($id, $snap);
                    }
                    kill_chrome_processes($p);
                }
            } catch (Throwable $e) {
            }
            // Proxy: relay dam bao (nhanh khi listening); non-relay test, SKIP neu alive <5ph
            if (!empty($p['proxy_host']) && expected_relay_port($p) === null && !proxy_recently_alive($p)) {
                $proxyTest = ['host' => $p['proxy_host'], 'port' => (int)$p['proxy_port'],
                    'protocol' => $p['proxy_protocol'] ?? 'http',
                    'username' => $p['proxy_user'], 'password' => $p['proxy_pass']];
                if (!test_proxy($proxyTest)) {
                    try {
                        db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
                            ->execute(['dead', (int)$p['proxy_id']]);
                    } catch (Throwable $e) {
                    }
                    return ['ok' => false, 'error' => 'proxy_dead', 'proxy_dead' => true];
                }
                try {
                    db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
                        ->execute(['alive', (int)$p['proxy_id']]);
                } catch (Throwable $e) {
                }
            }
            $port = allocate_debug_port($p);
            if (!$port) return ['ok' => false, 'error' => 'no_debug_port'];
            $url = get_setting('home_url', 'https://www.google.com/');
            $tp = microtime(true);
            try {
                // StartPlan bat bien (§26) + generation (§28): target lay tu plan,
                // khong resolve lai; worker cu thay gen doi phai discard.
                $gen = self::nextGeneration($id);
                $override = [];
                if (!empty($plan['target_rect']) && !empty($plan['target_monitor'])) {
                    try {
                        $mon = WindowPlacementManager::findByDevice((string)$plan['target_monitor']);
                        if ($mon === null) {
                            WindowPlacementManager::refreshMonitors();
                            $mon = WindowPlacementManager::findByDevice((string)$plan['target_monitor']);
                        }
                        if ($mon !== null) {
                            $override = ['placeOverride' => [
                                'monitor' => $mon, 'rect' => $plan['target_rect']]];
                        }
                    } catch (Throwable $e) {
                    }
                }
                // quickRelay: batch mo hang loat, khong cho relay_healthy 12s/profile
                launch_chrome($p, $url, $port, ['quickRelay' => true, 'batchId' => $batchId,
                    'generation' => $gen] + $override);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'proxy_dead' => true];
            }
            $popenMs = (int)round((microtime(true) - $tp) * 1000);
            try {
                db()->prepare('UPDATE profiles SET status=?, last_opened=NOW(), debug_port=? WHERE id=?')
                    ->execute(['running', $port, $id]);
            } catch (Throwable $e) {
            }
            self::lifeSet($id, self::ST_STARTING, $batchId, ['port' => $port]);
            try {
                SyncLogger::info('start_batch', '[START] profile=' . $id . ' popen=' . $popenMs . 'ms port=' . $port, $id);
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'port' => $port, 'popen_ms' => $popenMs];
        } catch (Throwable $e) {
            self::lifeSet($id, self::ST_ERROR, $batchId);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * POLL: 1 discovery scan -> per-profile WINDOW_READY/RUNNING.
     * stable = guard cleared (placement verify xong) + CDP reachable.
     */
    public static function startPoll(string $batchId): array
    {
        $b = self::loadBatch('open', $batchId);
        if (!$b) return ['ok' => false, 'message' => 'Batch khong ton tai'];
        $counts = ['queued' => 0, 'starting' => 0, 'windows' => 0, 'stable' => 0,
            'running' => 0, 'errors' => 0, 'total' => count((array)($b['states'] ?? []))];
        try {
            $disc = SyncWindowDiscovery::discover(false);
            $hwndByPid = [];
            foreach ($disc['windows'] as $w) {
                if ($w->profileId !== null && $w->class === 'Chrome_WidgetWin_1' && $w->visible) {
                    $hwndByPid[(int)$w->profileId] = $w->hwnd;
                }
            }
        } catch (Throwable $e) {
            $hwndByPid = [];
        }
        foreach ((array)($b['states'] ?? []) as $idStr => $st) {
            $id = (int)$idStr;
            if ($st === self::ST_QUEUED) {
                $counts['queued']++;
                continue;
            }
            if ($st === self::ST_ERROR || $st === self::ST_CANCELLED) {
                $counts['errors']++;
                continue;
            }
            if (!empty($b['cancelled'])) {
                $counts['errors']++;
                continue;
            }
            $hasHwnd = isset($hwndByPid[$id]);
            $guardOn = self::placementLocked($id);
            // Port tu life
            $life = self::lifeGet($id);
            $port = (int)(($life['port'] ?? 0));
            if ($port <= 0) {
                try {
                    $s = db()->prepare('SELECT debug_port FROM profiles WHERE id=?');
                    $s->execute([$id]);
                    $port = (int)($s->fetchColumn() ?? 0);
                } catch (Throwable $e) {
                }
            }
            $cdpOk = $port > 0 && cdp_reachable($port);
            if ($hasHwnd) {
                $counts['windows']++;
                if ($b['states'][$idStr] === self::ST_STARTING) {
                    $b['states'][$idStr] = self::ST_WINDOW_READY;
                    self::lifeSet($id, self::ST_WINDOW_READY, $batchId, ['port' => $port]);
                    try {
                        SyncLogger::info('start_batch', '[HWND] profile=' . $id . ' hwnd=' . $hwndByPid[$id], $id);
                    } catch (Throwable $e) {
                    }
                }
            } else {
                $counts['starting']++;
            }
            if ($hasHwnd && !$guardOn && $cdpOk) {
                $counts['stable']++;
                if ($b['states'][$idStr] !== self::ST_RUNNING) {
                    $b['states'][$idStr] = self::ST_RUNNING;
                    self::lifeClear($id);
                }
                $counts['running']++;
            } elseif ($hasHwnd) {
                if ($b['states'][$idStr] === self::ST_WINDOW_READY) {
                    $b['states'][$idStr] = self::ST_VERIFYING;
                    self::lifeSet($id, self::ST_VERIFYING, $batchId, ['port' => $port]);
                }
            }
        }
        $done = ($counts['queued'] === 0 && ($counts['starting'] === 0)
            && ($counts['windows'] === $counts['stable']));
        if ($done && empty($b['notified'])) {
            $b['status'] = 'done';
            $b['notified'] = true;
            $ms = (int)round((microtime(true) - (float)($b['t0'] ?? microtime(true))) * 1000);
            try {
                SyncLogger::info('start_batch', '[START COMPLETE] id=' . $batchId
                    . ' running=' . $counts['running'] . '/' . $counts['total']
                    . ' errors=' . $counts['errors'] . ' total=' . $ms . 'ms');
            } catch (Throwable $e) {
            }
            // Event: MỞ PROFILE HOÀN TẤT (§21) — 1 summary
            try {
                require_once __DIR__ . '/EventBus.php';
                $dur = $ms >= 60000 ? intdiv($ms, 60000) . 'm ' . intdiv(($ms % 60000), 1000) . 's' : round($ms / 1000, 1) . 's';
                EventBus::emit(AppEvent::BATCH_COMPLETED, AppEvent::MOD_BROWSER, AppEvent::SEV_SUCCESS,
                    'MỞ PROFILE HOÀN TẤT',
                    "Total: {$counts['total']}\nOpened: {$counts['running']}\nFailed: {$counts['errors']}\nDuration: $dur",
                    ['batch_id' => $batchId, 'status' => 'SUCCESS',
                        'data' => ['total' => $counts['total'], 'opened' => $counts['running'],
                            'failed' => $counts['errors'], 'duration_ms' => $ms]]);
            } catch (Throwable $e) {
            }
        } elseif ($done) {
            $b['status'] = 'done';
            $ms = (int)round((microtime(true) - (float)($b['t0'] ?? microtime(true))) * 1000);
            try {
                SyncLogger::info('start_batch', '[START COMPLETE] id=' . $batchId
                    . ' running=' . $counts['running'] . '/' . $counts['total']
                    . ' errors=' . $counts['errors'] . ' total=' . $ms . 'ms');
            } catch (Throwable $e) {
            }
        }
        self::saveBatch('open', $batchId, $b);
        $counts['done'] = $done;
        return ['ok' => true, 'batch_id' => $batchId, 'counts' => $counts, 'done' => $done];
    }

    public static function placementLocked(int $id): bool
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_guard_' . $id . '.json';
        if (!is_file($f)) return false;
        // TTL 60s: guard treo (apply crash) khong khoa mai mai
        if ((time() - (int)@filemtime($f)) > 60) {
            @unlink($f);
            return false;
        }
        return true;
    }

    public static function startCancel(string $batchId): bool
    {
        $b = self::loadBatch('open', $batchId);
        if (!$b) return false;
        $b['cancelled'] = true;
        $n = 0;
        foreach ((array)($b['states'] ?? []) as $idStr => $st) {
            if ($st === self::ST_QUEUED) {
                $b['states'][$idStr] = self::ST_CANCELLED;
                self::lifeClear((int)$idStr);
                $n++;
            }
        }
        $b['status'] = 'cancelled';
        self::saveBatch('open', $batchId, $b);
        try {
            SyncLogger::info('start_batch', '[START CANCEL] id=' . $batchId . ' cancelled_queued=' . $n);
        } catch (Throwable $e) {
        }
        return true;
    }

    // ================= STOP (two-phase) =================

    /**
     * Phase A+B: snapshot nhanh ALL -> save placement (1 scan) ->
     * dispatch WM_CLOSE 1 LAN cho tat ca HWND. Tra ve ngay (khong wait).
     * @return array{ok, batch_id, total, dispatched, snapshot_ms, dispatch_ms}
     */
    public static function stopBatch(array $ids, string $mode = 'safe'): array
    {
        self::gcBatches();
        $tAll = microtime(true);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $batchId = self::newBatchId('sp');
        $grace = ($mode === 'fast') ? self::GRACE_FAST_MS : self::GRACE_SAFE_MS;
        self::setStopActive(true, $batchId); // freeze arrange (§34-§35)
        // Cancel start batch dang chay cung ids? Caller (frontend) goi startCancel truoc.
        // 1 WMI+Enum scan duy nhat cho ca batch
        try {
            $disc = SyncWindowDiscovery::discover(false);
            $windows = $disc['windows'];
        } catch (Throwable $e) {
            $windows = [];
        }
        $hwndByPid = [];
        foreach ($windows as $w) {
            if ($w->profileId !== null && $w->class === 'Chrome_WidgetWin_1' && $w->visible && !$w->minimized) {
                $pid = (int)$w->profileId;
                if (!isset($hwndByPid[$pid]) || $w->area() > 0) $hwndByPid[$pid] = $w->hwnd;
            }
        }
        // Phase A: snapshot (nhanh, deadline/profile) + placement
        $tSnap = microtime(true);
        try {
            require_once __DIR__ . '/TabSessionStore.php';
            require_once __DIR__ . '/WindowPlacementManager.php';
        } catch (Throwable $e) {
        }
        $states = [];
        $ports = [];
        foreach ($ids as $id) {
            // Restart intent cho STARTING bi Stop chen ngang (§39): huy start, se CLOSING
            $life = self::lifeGet($id);
            if ($life !== null && in_array($life['state'], [self::ST_QUEUED, self::ST_PREPARING], true)) {
                self::lifeClear($id);
            }
            try {
                $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
                $st->execute([$id]);
                $p = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $p = null;
            }
            if (!$p) {
                $states[$id] = self::ST_ERROR;
                continue;
            }
            $port = (int)($p['debug_port'] ?? 0);
            if ($port > 0) $ports[$id] = $port;
            // Quick final snapshot (khong precise-rotate; dung autosave fallback) (§24-§26)
            try {
                if ($port > 0 && cdp_reachable($port)) {
                    $snap = TabSessionStore::snapshotLive($id, $port, false, self::SNAPSHOT_BUDGET_MS);
                    if ($snap !== null) TabSessionStore::save($id, $snap);
                }
            } catch (Throwable $e) {
            }
            // Placement: save TRUOC khi mark CLOSING (save tu choi transitional) (§20)
            try {
                $hwnd = $hwndByPid[$id] ?? null;
                WindowPlacementManager::save_window_placement($p, $hwnd, $windows);
            } catch (Throwable $e) {
            }
            self::lifeSet($id, self::ST_CLOSING, $batchId);
            $states[$id] = self::ST_CLOSING;
        }
        $snapshotMs = (int)round((microtime(true) - $tSnap) * 1000);
        // Phase B: dispatch WM_CLOSE 1 lan cho TAT CA (§27-§28)
        $tDisp = microtime(true);
        $hwnds = array_values(array_filter($hwndByPid, fn($h, $id) => in_array($id, $ids, true), ARRAY_FILTER_USE_BOTH));
        $hwnds = array_values(array_unique(array_map('intval', $hwnds)));
        $dispatched = 0;
        if ($hwnds) {
            try {
                $script = __DIR__ . '/win32_close.ps1';
                $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"'
                    . ' -Hwnd "' . implode(',', $hwnds) . '"';
                $out = @shell_exec($cmd);
                $j = is_string($out) ? json_decode(trim($out), true) : null;
                if (is_array($j) && isset($j['results'])) {
                    foreach ((array)$j['results'] as $r) {
                        if (!empty($r['ok'])) $dispatched++;
                    }
                } else {
                    $dispatched = count($hwnds); // post fire-and-forget, gia dinh ok
                }
            } catch (Throwable $e) {
            }
        }
        $dispatchMs = (int)round((microtime(true) - $tDisp) * 1000);
        // Don keepers 1 lan (tat ca ports)
        self::batchKillKeepers(array_values($ports));
        self::saveBatch('close', $batchId, ['batch_id' => $batchId, 'ids' => $ids,
            'states' => $states, 't0' => $tAll, 'grace_ms' => $grace, 'mode' => $mode,
            'status' => 'closing', 'snapshot_ms' => $snapshotMs, 'dispatch_ms' => $dispatchMs,
            'dispatched' => $dispatched, 'hwnds' => $hwnds]);
        try {
            SyncLogger::info('stop_batch', '[STOP BATCH] id=' . $batchId . ' profiles=' . count($ids)
                . ' mode=' . $mode);
            SyncLogger::info('stop_batch', '[SNAPSHOT] ' . count($ids) . ' profiles duration=' . $snapshotMs . 'ms');
            SyncLogger::info('stop_batch', '[WM_CLOSE] dispatched=' . $dispatched . '/' . count($hwnds)
                . ' duration=' . $dispatchMs . 'ms');
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'batch_id' => $batchId, 'total' => count($ids),
            'dispatched' => $dispatched, 'snapshot_ms' => $snapshotMs, 'dispatch_ms' => $dispatchMs];
    }

    /**
     * Shared process monitor (§30-§31): 1 scan/luot, khong wait tung PID,
     * khong thread/profile. Fallback chi stuck sau grace (§32).
     */
    public static function stopPoll(string $batchId): array
    {
        $b = self::loadBatch('close', $batchId);
        if (!$b) return ['ok' => false, 'message' => 'Batch khong ton tai'];
        $ids = array_map('intval', (array)($b['ids'] ?? []));
        $t0 = (float)($b['t0'] ?? microtime(true));
        $grace = (int)($b['grace_ms'] ?? self::GRACE_SAFE_MS);
        $pastGrace = ((microtime(true) - $t0) * 1000) >= $grace;
        // 1 scan duy nhat
        try {
            $aliveMap = chrome_running_info();
        } catch (Throwable $e) {
            $aliveMap = [];
        }
        $dirById = [];
        try {
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = db()->prepare("SELECT id, user_data_dir FROM profiles WHERE id IN ($in)");
                $st->execute($ids);
                foreach ($st->fetchAll() as $r) {
                    $dirById[(int)$r['id']] = strtolower(trim((string)$r['user_data_dir']));
                }
            }
        } catch (Throwable $e) {
        }
        $closed = 0;
        $stuck = [];
        $restarted = [];
        foreach ($ids as $id) {
            $st = (string)(($b['states'] ?? [])[$id] ?? self::ST_CLOSING);
            if (in_array($st, [self::ST_STOPPED, self::ST_ERROR], true)) {
                $closed += ($st === self::ST_STOPPED ? 1 : 0);
                continue;
            }
            $dir = $dirById[$id] ?? '';
            $alive = $dir !== '' && isset($aliveMap[$dir]);
            if (!$alive) {
                $b['states'][$id] = self::ST_STOPPED;
                $closed++;
                self::lifeClear($id);
                try {
                    db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute(['stopped', $id]);
                    WindowPlacementManager::clearGuard($id);
                    stop_proxy_relay_if_unused(['id' => $id, 'proxy_id' => self::proxyIdOf($id)]);
                } catch (Throwable $e) {
                }
                // Restart intent (§40): CLOSING + restart flag -> start lai khi da STOPPED
                $life = self::lifeGet($id);
                if ($life !== null && !empty($life['restart'])) {
                    self::lifeClear($id);
                    $lr = self::launchOne($id, $batchId . '-rs', []);
                    if (!empty($lr['ok'])) $restarted[] = $id;
                }
                continue;
            }
            // Con song: qua grace -> stuck (fallback o duoi)
            if ($pastGrace) $stuck[] = $id;
        }
        // Fallback: terminate CHI root tree cua stuck profiles (khong /IM chrome.exe) (§32)
        $fallback = 0;
        if ($stuck) {
            $fallback = self::forceKillDirs($stuck, $dirById);
            try {
                SyncLogger::info('stop_batch', '[FALLBACK] batch=' . $batchId . ' stuck=' . count($stuck)
                    . ' killed_dirs=' . $fallback);
            } catch (Throwable $e) {
            }
        }
        $total = count($ids);
        $states = (array)($b['states'] ?? []);
        $allClosed = true;
        foreach ($ids as $id) {
            if (!in_array((string)($states[$id] ?? ''), [self::ST_STOPPED, self::ST_ERROR], true)) {
                $allClosed = false;
                break;
            }
        }
        $done = $allClosed;
        if ($done && empty($b['notified'])) {
            $b['status'] = 'done';
            $b['notified'] = true;
            self::setStopActive(false);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            try {
                SyncLogger::info('stop_batch', '[GRACEFUL] batch=' . $batchId . ' closed=' . $closed . '/' . $total);
                SyncLogger::info('stop_batch', '[STOP COMPLETE] ' . $closed . '/' . $total . ' total=' . $ms . 'ms');
            } catch (Throwable $e) {
            }
            // Event: ĐÓNG PROFILE HOÀN TẤT (§21) — 1 summary (graceful/forced/duration)
            try {
                require_once __DIR__ . '/EventBus.php';
                // Forced fallback: stuck da force-kill trong cac poll truoc
                $forced = max(0, $total - $closed);
                EventBus::emit(AppEvent::BATCH_COMPLETED, AppEvent::MOD_BROWSER, AppEvent::SEV_SUCCESS,
                    'ĐÓNG PROFILE HOÀN TẤT',
                    "Total: $total\nGraceful: $closed\nForced fallback: $forced\nDuration: " . round($ms / 1000, 1) . 's',
                    ['batch_id' => $batchId, 'status' => 'SUCCESS',
                        'data' => ['total' => $total, 'graceful' => $closed,
                            'forced' => $forced, 'duration_ms' => $ms]]);
            } catch (Throwable $e) {
            }
        } elseif ($done) {
            $b['status'] = 'done';
            self::setStopActive(false);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            try {
                SyncLogger::info('stop_batch', '[GRACEFUL] batch=' . $batchId . ' closed=' . $closed . '/' . $total);
                SyncLogger::info('stop_batch', '[STOP COMPLETE] ' . $closed . '/' . $total . ' total=' . $ms . 'ms');
            } catch (Throwable $e) {
            }
        }
        self::saveBatch('close', $batchId, $b);
        return ['ok' => true, 'batch_id' => $batchId, 'closed' => $closed, 'total' => $total,
            'stuck' => $stuck, 'fallback_killed' => $fallback, 'restarted' => $restarted,
            'dispatched' => (int)($b['dispatched'] ?? 0), 'snapshot_ms' => (int)($b['snapshot_ms'] ?? 0),
            'dispatch_ms' => (int)($b['dispatch_ms'] ?? 0), 'done' => $done];
    }

    private static function proxyIdOf(int $id): int
    {
        try {
            $st = db()->prepare('SELECT proxy_id FROM profiles WHERE id=?');
            $st->execute([$id]);
            return (int)($st->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Force kill theo user_data_dir (1 ps duy nhat cho nhieu dirs). */
    private static function forceKillDirs(array $ids, array $dirById): int
    {
        $likes = [];
        foreach ($ids as $id) {
            $d = $dirById[$id] ?? '';
            if ($d === '') continue;
            $likes[] = $d;
        }
        if (!$likes) return 0;
        try {
            $conds = array_map(fn($d) => '$_.CommandLine -like \'*' . str_replace("'", "''", $d) . '*\'', $likes);
            $ps = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name=\'chrome.exe\'\" '
                . '| Where-Object { ' . implode(' -or ', $conds) . ' } '
                . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
            @shell_exec($ps);
            return count($likes);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Don tabtitle keepers cua nhieu ports trong 1 ps. */
    private static function batchKillKeepers(array $ports): void
    {
        $ports = array_values(array_unique(array_filter(array_map('intval', $ports))));
        if (!$ports) return;
        try {
            $conds = array_map(fn($p) => '$_.CommandLine -like \'*tabtitle_keeper.php ' . $p . '*\'', $ports);
            $ps = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process '
                . '| Where-Object { $_.CommandLine -like \'*tabtitle_keeper.php*\' -and ('
                . implode(' -or ', $conds) . ') } '
                . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
            @shell_exec($ps);
        } catch (Throwable $e) {
        }
    }
}
