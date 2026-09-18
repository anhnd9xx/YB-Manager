<?php
declare(strict_types=1);
/**
 * api/syncwin.php - Endpoint Window Discovery + Window Manager (module Synchronize, PHASE 1).
 * GET  ?action=discover|monitors|rect&hwnd=
 * POST ?action=move|resize|moveresize|minimize|restore|show|hide|front {hwnd,x?,y?,w?,h?}
 */
require_once __DIR__ . '/../sync/WindowManager.php';
require_once __DIR__ . '/../sync/LayoutManager.php';
require_once __DIR__ . '/../sync/WindowLayoutManager.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'discover';

try {
    switch ($action) {
        case 'discover':
            // Tat ca window trinh duyet chinh cua profile duoc quan ly (+ monitors)
            $all = SyncWindowDiscovery::discover(false);
            $wins = array_map(fn($w) => $w->toArray(), SyncWindowManager::findBrowserWindows(true));
            $data = ['windows' => $wins, 'monitors' => SyncWindowDiscovery::monitors()];
            if (isset($_GET['dbg'])) {
                $nMain = 0;
                foreach ($all['windows'] as $w) if ($w->isBrowserMain()) $nMain++;
                $data['dbg'] = ['rawWindows' => count($all['windows']), 'browserMain' => $nMain,
                                'managed' => count($wins), 'processes' => count($all['processes'])];
            }
            json_out(['ok' => true, 'data' => $data]);
            break;

        case 'monitors':
            json_out(['ok' => true, 'data' => SyncWindowDiscovery::monitors()]);
            break;

        case 'rect': {
            $hwnd = (int)($_GET['hwnd'] ?? 0);
            $w = SyncWindowManager::findWindow($hwnd);
            if (!$w) json_out(['ok' => false, 'message' => 'Khong tim thay window'], 404);
            json_out(['ok' => true, 'data' => $w->toArray()]);
            break;
        }

        case 'move':
        case 'resize':
        case 'moveresize':
        case 'minimize':
        case 'restore':
        case 'show':
        case 'hide':
        case 'front': {
            $b = $method === 'GET' ? $_GET : json_body();
            $hwnd = (int)($b['hwnd'] ?? 0);
            if ($hwnd <= 0) json_out(['ok' => false, 'message' => 'Thieu hwnd'], 400);
            $x = (int)($b['x'] ?? 0); $y = (int)($b['y'] ?? 0);
            $w = (int)($b['w'] ?? 0); $h = (int)($b['h'] ?? 0);
            $r = match ($action) {
                'move' => SyncWindowManager::moveWindow($hwnd, $x, $y),
                'resize' => SyncWindowManager::resizeWindow($hwnd, $w, $h),
                'moveresize' => SyncWindowManager::moveResize($hwnd, $x, $y, $w, $h),
                'minimize' => SyncWindowManager::minimizeWindow($hwnd),
                'restore' => SyncWindowManager::restoreWindow($hwnd),
                'show' => SyncWindowManager::showWindow($hwnd),
                'hide' => SyncWindowManager::hideWindow($hwnd),
                default => SyncWindowManager::bringToFront($hwnd),
            };
            if (!$r['ok']) json_out(['ok' => false, 'message' => $r['error'] ?? 'Loi'], 500);
            json_out(['ok' => true, 'rect' => $r['rect'] ?? null]);
            break;
        }

        case 'dpi': {
            // Thong tin DPI: ?hwnd= -> window; ?monitor= -> monitor; khong co -> ca 2 + scale demo
            $hwnd = (int)($_GET['hwnd'] ?? 0);
            $mon = isset($_GET['monitor']) ? (int)$_GET['monitor'] : null;
            $data = ['monitors' => SyncWindowDiscovery::monitors()];
            if ($hwnd > 0) {
                $w = SyncWindowManager::findWindow($hwnd);
                if (!$w) json_out(['ok' => false, 'message' => 'Khong tim thay window'], 404);
                $data['window'] = ['hwnd' => $w->hwnd, 'dpi' => $w->dpi, 'dpiScale' => $w->dpiScale, 'monitorId' => $w->monitorId];
            }
            if ($mon !== null) $data['monitorDpi'] = SyncDpiManager::getMonitorDpi($mon);
            json_out(['ok' => true, 'data' => $data]);
            break;
        }

        case 'layout': {
            // POST {mode: tile|uniform|overlap, monitorId?, margin?, gapX?, gapY?,
            //        width?, height?, offset?, hwnds?[], profileIds?[]}
            $b = $method === 'GET' ? $_GET : json_body();
            $mode = strtolower(trim((string)($b['mode'] ?? 'tile')));
            $hwnds = array_map('intval', (array)($b['hwnds'] ?? []));
            $pids = array_map('intval', (array)($b['profileIds'] ?? []));
            $mon = isset($b['monitorId']) && $b['monitorId'] !== '' ? (int)$b['monitorId'] : null;
            if ($mode === 'uniform') {
                $r = SyncLayoutManager::uniformSize((int)($b['width'] ?? 0), (int)($b['height'] ?? 0), $hwnds, $pids);
            } elseif ($mode === 'overlap') {
                $r = SyncLayoutManager::overlap($hwnds, $pids, $mon,
                    (int)($b['offset'] ?? 28), (int)($b['width'] ?? 0), (int)($b['height'] ?? 0));
            } else {
                $r = SyncLayoutManager::tile($hwnds, $pids, $mon,
                    (int)($b['margin'] ?? 8), (int)($b['gapX'] ?? 8), (int)($b['gapY'] ?? 8));
            }
            if (!$r['ok']) json_out(['ok' => false, 'message' => $r['message'] ?? 'Loi'], 400);
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        case 'arrange': {
            // Smart Arrange (spec: Arrange Running / Selected / Multi-Monitor).
            // POST {mode?: smart_auto|grid|horizontal|vertical|cascade|compact,
            //        profileIds?: int[] (rong = tat ca dang chay),
            //        monitor?: 'primary'|<id>|all, monitors?: [device names] (Arrange To Monitor),
            //        distribution?: smart|equal|sequential|manual,
            //        assign?: {profileId: deviceName} (manual),
            //        mainProfileId?: int, mainMonitor?: name, controlledMonitors?: [names] (sync prep),
            //        confirmed?: bool (bo qua disconnect ask), dryRun?: bool (preview, khong move),
            //        debug?: bool}
            // Ket qua tung window rieng (ok/failed), khong fail hang loat.
            $b = $method === 'GET' ? $_GET : json_body();
            $pids = array_map('intval', (array)($b['profileIds'] ?? []));
            $opts = ['debug' => !empty($b['debug'])];
            if (isset($b['mode']) && $b['mode'] !== '') $opts['mode'] = strtolower(trim((string)$b['mode']));
            if (isset($b['monitor']) && $b['monitor'] !== '') $opts['monitor'] = (string)$b['monitor'];
            if (isset($b['monitors']) && is_array($b['monitors'])) {
                $opts['monitors'] = array_values(array_filter(array_map(fn($s) => trim((string)$s), $b['monitors'])));
            }
            if (isset($b['distribution']) && $b['distribution'] !== '') {
                $opts['distribution'] = strtolower(trim((string)$b['distribution']));
            }
            if (isset($b['assign']) && is_array($b['assign'])) {
                $as = [];
                foreach ($b['assign'] as $pid => $nm) {
                    if ((int)$pid > 0 && trim((string)$nm) !== '') $as[(int)$pid] = trim((string)$nm);
                }
                if ($as) {
                    $opts['assign'] = $as;
                    $opts['distribution'] = 'manual';
                }
            }
            if (isset($b['mainProfileId']) && (int)$b['mainProfileId'] > 0) {
                $opts['mainFirst'] = (int)$b['mainProfileId'];
            }
            if (isset($b['mainMonitor']) && trim((string)$b['mainMonitor']) !== '') {
                $opts['mainMonitor'] = trim((string)$b['mainMonitor']);
            }
            if (isset($b['controlledMonitors']) && is_array($b['controlledMonitors'])) {
                $opts['controlledMonitors'] = array_values(array_filter(array_map(fn($s) => trim((string)$s), $b['controlledMonitors'])));
            }
            if (!empty($b['confirmed'])) $opts['confirmed'] = true;
            if (!empty($b['dryRun'])) $opts['dryRun'] = true;
            $tArrange = microtime(true);
            $r = $pids ? SyncWindowLayoutManager::arrange($pids, $opts)
                       : SyncWindowLayoutManager::arrangeRunning(null, $opts);
            SyncLogger::info('layout_perf', '[PERF] arrange request: '
                . (int)round((microtime(true) - $tArrange) * 1000) . 'ms'
                . ' windows=' . count($r['session']['windows'] ?? []));
            $code = ($r['ok'] || ($r['partial'] ?? false) || !empty($r['dryRun'])) ? 200 : 400;
            $out = ['ok' => $r['ok'] || ($r['partial'] ?? false), 'partial' => $r['partial'] ?? false,
                    'message' => $r['message'], 'session' => $r['session'], 'results' => $r['results']];
            foreach (['plan', 'breakdown', 'missingMonitors', 'disconnected', 'disconnectAsk',
                      'availableMonitors', 'dryRun'] as $k) {
                if (array_key_exists($k, $r)) $out[$k] = $r[$k];
            }
            if (isset($r['plan']['breakdown'])) $out['breakdown'] = $r['plan']['breakdown'];
            json_out($out, $code);
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'syncwin exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
