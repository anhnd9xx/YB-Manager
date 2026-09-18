<?php
declare(strict_types=1);
/**
 * SmartLayoutEngine - Thuật toán chia layout THUẦN TÍNH TOÁN (PHASE 4, spec muc 5/36/52).
 * PURE: input arrays -> output LayoutPlan. KHONG DB, KHONG WinAPI, KHONG log.
 * Caller (WindowLayoutManager, Phase 6) lo orchestrate + apply + logging.
 *
 * Input:
 *   $n: so window (>0)
 *   $areas: working areas [['monitorId'=>1,'x'=>0,'y'=>0,'w'=>1920,'h'=>1040], ...] (physical px)
 *   $layout: snapshot normalizeLayout (mode,sizeMode,monitor,multi,gapX,gapY,minW,minH,
 *             respectTaskbar,keepVisible,autoLaunch,reflow,fallback,compactX,compactY)
 *   $win: snapshot normalize window (width,height -> target aspect)
 *   $debug: true -> kem top candidates de log/test
 *
 * Output LayoutPlan:
 *   ['ok','mode','message','monitors'=>[ids],'rows','cols','cellW','cellH',
 *    'slots'=>[['x','y','w','h','monitorId']...],'score','fallbackUsed','candidates'?]
 */
class SmartLayoutEngine
{
    /**
     * @param array<int,array{monitorId:int,x:int,y:int,w:int,h:int}> $areas
     */
    public static function plan(int $n, array $areas, array $layout, array $win, bool $debug = false): array
    {
        $areas = array_values(array_filter($areas, fn($a) =>
            isset($a['w'], $a['h']) && (int)$a['w'] > 0 && (int)$a['h'] > 0));
        if ($n <= 0 || !$areas) {
            return self::emptyPlan($n <= 0 ? 'Khong co window nao' : 'Khong co monitor nao');
        }
        $mode = (string)($layout['mode'] ?? 'smart_auto');
        $gapX = max(0, (int)($layout['gapX'] ?? 5));
        $gapY = max(0, (int)($layout['gapY'] ?? 5));
        $minW = max(50, (int)($layout['minW'] ?? 500));
        $minH = max(50, (int)($layout['minH'] ?? 400));
        $setW = max(1, (int)($win['width'] ?? 1280));
        $setH = max(1, (int)($win['height'] ?? 720));
        $targetRatio = $setW / $setH;
        $multi = !empty($layout['multi']) && count($areas) > 1;

        // N=1: dung settings size, khong fullscreen (spec muc 38)
        if ($n === 1) {
            $a = $areas[0];
            $w = min($setW, $a['w']);
            $h = min($setH, $a['h']);
            $x = max($a['x'], min($a['x'] + $gapX, $a['x'] + $a['w'] - $w));
            $y = max($a['y'], min($a['y'] + $gapY, $a['y'] + $a['h'] - $h));
            return [
                'ok' => true, 'mode' => $mode, 'message' => null,
                'monitors' => [$a['monitorId']], 'rows' => 1, 'cols' => 1,
                'cellW' => $w, 'cellH' => $h,
                'slots' => [['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'monitorId' => $a['monitorId']]],
                'score' => 1.0, 'fallbackUsed' => null,
            ];
        }

        switch ($mode) {
            case 'grid':
            case 'horizontal':
            case 'vertical':
                $forceCols = ($mode === 'grid') ? max(0, min(12, (int)($layout['forceCols'] ?? 0))) : 0;
                return self::manualGrid($n, $areas[0], $mode, $gapX, $gapY, $minW, $minH, $setW, $setH, $layout, $debug, $forceCols);
            case 'cascade':
                return self::offsetPlan($n, $areas[0], $gapX, $gapY, $setW, $setH, 30, 30, $mode, $debug);
            case 'compact':
                return self::offsetPlan($n, $areas[0], $gapX, $gapY, $setW, $setH,
                    max(0, (int)($layout['compactX'] ?? 150)), max(0, (int)($layout['compactY'] ?? 40)), $mode, $debug);
            default: // smart_auto
                return self::smartAuto($n, $areas, $multi, $gapX, $gapY, $minW, $minH,
                    $setW, $setH, $targetRatio, $layout, $debug);
        }
    }

    // ---------------- SMART AUTO ----------------

    private static function smartAuto(int $n, array $areas, bool $multi, int $gapX, int $gapY,
        int $minW, int $minH, int $setW, int $setH, float $targetRatio, array $layout, bool $debug): array
    {
        // keep_size: uu tien giu settings size, fallback khi khong du cho
        if (($layout['sizeMode'] ?? 'auto_fit') === 'keep_size') {
            $p = self::keepSizePlan($n, $areas, $multi, $gapX, $gapY, $setW, $setH, $layout, $debug);
            if ($p['ok']) return $p;
            // that bai -> roi vao fallback chain ben duoi (multi/compact/force)
            $fb = self::fallbackPlan($n, $areas, $gapX, $gapY, $minW, $minH, $setW, $setH,
                $targetRatio, (string)($layout['fallback'] ?? 'auto'), $layout, $debug);
            $fb['fallbackUsed'] = $fb['fallbackUsed'] ?? 'keep_size_failed';
            return $fb;
        }
        // auto_fit: grid dep nhat vua min-size
        if ($multi) {
            $p = self::distributedGrid($n, $areas, $gapX, $gapY, $minW, $minH, $targetRatio, $debug);
            if ($p['ok']) return $p;
        } else {
            $p = self::bestGrid($n, $areas[0], $gapX, $gapY, $minW, $minH, $targetRatio, $debug);
            if ($p['ok']) return $p;
        }
        $fb = self::fallbackPlan($n, $areas, $gapX, $gapY, $minW, $minH, $setW, $setH,
            $targetRatio, (string)($layout['fallback'] ?? 'auto'), $layout, $debug);
        return $fb;
    }

    /**
     * Grid dep nhat tren 1 area: thu moi cols 1..N, cham theo spec muc 10/37.
     * Reject candidate duoi min-size; het candidate -> ok:false de fallback lo.
     */
    private static function bestGrid(int $n, array $a, int $gapX, int $gapY,
        int $minW, int $minH, float $targetRatio, bool $debug): array
    {
        $W = (int)$a['w'];
        $H = (int)$a['h'];
        $best = null;
        $cands = [];
        for ($cols = 1; $cols <= $n; $cols++) {
            $rows = (int)ceil($n / $cols);
            $cw = (int)floor(($W - $gapX * ($cols - 1)) / $cols);
            $ch = (int)floor(($H - $gapY * ($rows - 1)) / $rows);
            if ($cw < $minW || $ch < $minH || $cw <= 0 || $ch <= 0) {
                if ($debug) $cands[] = ['cols' => $cols, 'rows' => $rows, 'rejected' => 'below_min'];
                continue; // duoi minimum usable -> loai (spec muc 12)
            }
            $score = self::scoreGrid($cols, $rows, $n, $cw, $ch, $W, $H, $targetRatio);
            $c = ['cols' => $cols, 'rows' => $rows, 'cellW' => $cw, 'cellH' => $ch, 'score' => $score];
            if ($debug) $cands[] = $c;
            if ($best === null || $score > $best['score']) $best = $c;
        }
        if ($best === null) {
            $r = self::emptyPlan('Khong co grid nao dat minimum size');
            if ($debug) $r['candidates'] = $cands;
            return $r;
        }
        $plan = self::gridSlots($n, $a, $best['cols'], $best['rows'], $best['cellW'], $best['cellH'], $gapX, $gapY, 'smart_auto', $best['score']);
        if ($debug) {
            usort($cands, fn($x, $y) => ($y['score'] ?? -1) <=> ($x['score'] ?? -1));
            $plan['candidates'] = array_slice($cands, 0, 8);
        }
        return $plan;
    }

    /**
     * Score 0..~8, cao hon tot hon (spec muc 10/37):
     *  1. aspect gan target ratio (uu tien vua phai, khong danh doi size)
     *  2. fill: it o trong
     *  3. size: cell cang lon cang tot (nhung da bi min cat)
     *  4. balance: hinh dang grid gan voi hinh dang monitor
     */
    public static function scoreGrid(int $cols, int $rows, int $n, int $cw, int $ch, int $W, int $H, float $targetRatio): float
    {
        $r = $cw / max(1, $ch);
        $aspect = min($r, $targetRatio) / max($r, $targetRatio); // 0..1
        $fill = $n / max(1, $rows * $cols);                      // 0..1
        $size = ($cw * $ch) / max(1, $W * $H);                   // 0..1
        $gridRatio = $cols / max(1, $rows);
        $areaRatio = $W / max(1, $H);
        $balance = min($gridRatio, $areaRatio) / max($gridRatio, $areaRatio); // 0..1
        $empty = $rows * $cols - $n;
        return 3.0 * $aspect + 2.0 * $fill + 2.0 * $size + 1.0 * $balance - 0.5 * $empty;
    }

    /** Phan phoi windows theo dien tich work area (monitor lon nhan nhieu, spec muc 18). */
    private static function distributedGrid(int $n, array $areas, int $gapX, int $gapY,
        int $minW, int $minH, float $targetRatio, bool $debug): array
    {
        $px = [];
        $total = 0;
        foreach ($areas as $a) {
            $p = (int)$a['w'] * (int)$a['h'];
            $px[] = $p;
            $total += $p;
        }
        $counts = [];
        $assigned = 0;
        foreach ($px as $i => $p) {
            $c = ($i === count($px) - 1) ? $n - $assigned : (int)round($n * $p / max(1, $total));
            $c = max(0, $c);
            $counts[$i] = $c;
            $assigned += $c;
        }
        // Sua lech lam tron: don du/thieu vao monitor lon nhat con suc
        while (array_sum($counts) > $n) {
            $big = 0;
            foreach ($counts as $i => $c) if ($c > ($counts[$big] ?? 0)) $big = $i;
            $counts[$big]--;
        }
        $i = 0;
        while (array_sum($counts) < $n) {
            $counts[$i % count($counts)]++;
            $i++;
        }
        $slots = [];
        $monitors = [];
        $scoreSum = 0;
        $wSum = 0;
        $rows = 0;
        $cols = 0;
        $cands = [];
        foreach ($areas as $i => $a) {
            $c = (int)($counts[$i] ?? 0);
            if ($c <= 0) continue;
            $p = self::bestGrid($c, $a, $gapX, $gapY, $minW, $minH, $targetRatio, $debug);
            if (!$p['ok']) return self::emptyPlan('Multi-monitor: monitor ' . $a['monitorId'] . ' khong du cho');
            foreach ($p['slots'] as $s) $slots[] = $s;
            $monitors[] = $a['monitorId'];
            $scoreSum += $p['score'] * $c;
            $wSum += $c;
            $rows = max($rows, $p['rows']);
            $cols += $p['cols'];
            if ($debug && !empty($p['candidates'])) $cands[] = ['monitor' => $a['monitorId'], 'top' => $p['candidates'][0]];
        }
        if (count($slots) !== $n) return self::emptyPlan('Phan phoi multi-monitor lech');
        $plan = [
            'ok' => true, 'mode' => 'smart_auto', 'message' => null,
            'monitors' => $monitors, 'rows' => $rows, 'cols' => $cols,
            'cellW' => 0, 'cellH' => 0, 'slots' => $slots,
            'score' => $wSum > 0 ? $scoreSum / $wSum : 0, 'fallbackUsed' => null,
        ];
        if ($debug) $plan['candidates'] = $cands;
        return $plan;
    }

    /** keep_size: giu settings W/H, xep vua bao nhieu hay bay nhieu; khong du -> fail de fallback. */
    private static function keepSizePlan(int $n, array $areas, bool $multi, int $gapX, int $gapY,
        int $setW, int $setH, array $layout, bool $debug): array
    {
        $targets = $multi ? $areas : [$areas[0]];
        // Thu tung monitor (lon truoc) xem co du suc chua N window co settings khong
        foreach ($targets as $a) {
            $cols = (int)floor(((int)$a['w'] + $gapX) / ($setW + $gapX));
            $rows = (int)floor(((int)$a['h'] + $gapY) / ($setH + $gapY));
            if ($cols >= 1 && $rows >= 1 && $cols * $rows >= $n) {
                $w = min($setW, (int)$a['w']);
                $h = min($setH, (int)$a['h']);
                $slots = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = (int)floor($i / $cols);
                    $c = $i % $cols;
                    $slots[] = [
                        'x' => (int)$a['x'] + $c * ($w + $gapX),
                        'y' => (int)$a['y'] + $r * ($h + $gapY),
                        'w' => $w, 'h' => $h, 'monitorId' => $a['monitorId'],
                    ];
                }
                return [
                    'ok' => true, 'mode' => 'smart_auto', 'message' => 'keep_size vua man hinh',
                    'monitors' => [$a['monitorId']], 'rows' => (int)ceil($n / $cols), 'cols' => $cols,
                    'cellW' => $w, 'cellH' => $h, 'slots' => $slots,
                    'score' => 5.0, 'fallbackUsed' => null,
                ];
            }
        }
        return self::emptyPlan('keep_size khong du cho');
    }

    /** Fallback chain (spec muc 16): auto -> [multi?] -> compact -> force fit. */
    private static function fallbackPlan(int $n, array $areas, int $gapX, int $gapY,
        int $minW, int $minH, int $setW, int $setH, float $targetRatio, string $fallback, array $layout, bool $debug): array
    {
        $chain = match ($fallback) {
            'multi' => ['multi', 'compact', 'force'],
            'compact' => ['compact', 'force'],
            'force_fit' => ['force'],
            default => ['auto', 'multi', 'compact', 'force'],
        };
        foreach ($chain as $step) {
            if ($step === 'auto' || $step === 'multi') {
                if (count($areas) > 1) {
                    $p = self::distributedGrid($n, $areas, $gapX, $gapY, $minW, $minH, $targetRatio, $debug);
                    if ($p['ok']) {
                        $p['fallbackUsed'] = $step;
                        return $p;
                    }
                } elseif ($step === 'auto') {
                    continue;
                } else {
                    continue;
                }
            } elseif ($step === 'compact') {
                $p = self::offsetPlan($n, $areas[0], $gapX, $gapY, $setW, $setH,
                    max(0, (int)($layout['compactX'] ?? 150)), max(0, (int)($layout['compactY'] ?? 40)), 'smart_auto', $debug);
                if ($p['ok']) {
                    $p['fallbackUsed'] = 'compact';
                    return $p;
                }
            } else { // force: grid ke ca duoi min (duong cung), van trong work area
                $p = self::forceGrid($n, $areas[0], $gapX, $gapY, $debug);
                if ($p['ok']) {
                    $p['fallbackUsed'] = 'force_fit';
                    return $p;
                }
            }
        }
        $r = self::emptyPlan('Moi fallback deu that bai');
        $r['fallbackUsed'] = 'failed';
        return $r;
    }

    /** Grid bat chap min-size (duong cung): chon candidate dien tich lon nhat vua area. */
    private static function forceGrid(int $n, array $a, int $gapX, int $gapY, bool $debug): array
    {
        $W = (int)$a['w'];
        $H = (int)$a['h'];
        $best = null;
        for ($cols = 1; $cols <= $n; $cols++) {
            $rows = (int)ceil($n / $cols);
            $cw = (int)floor(($W - $gapX * ($cols - 1)) / $cols);
            $ch = (int)floor(($H - $gapY * ($rows - 1)) / $rows);
            if ($cw < 50 || $ch < 50) continue;
            $area = $cw * $ch - 0.5 * ($rows * $cols - $n) * $cw * $ch / max(1, $n);
            if ($best === null || $area > $best['area']) {
                $best = ['cols' => $cols, 'rows' => $rows, 'cellW' => $cw, 'cellH' => $ch, 'area' => $area];
            }
        }
        if ($best === null) return self::emptyPlan('Force fit that bai');
        return self::gridSlots($n, $a, $best['cols'], $best['rows'], $best['cellW'], $best['cellH'], $gapX, $gapY, 'smart_auto', 0.1);
    }

    // ---------------- MANUAL MODES ----------------

    private static function manualGrid(int $n, array $a, string $mode, int $gapX, int $gapY,
        int $minW, int $minH, int $setW, int $setH, array $layout, bool $debug, int $forceCols = 0): array
    {
        $W = (int)$a['w'];
        $H = (int)$a['h'];
        if ($mode === 'horizontal') {
            $cols = $n;
            $rows = 1;
        } elseif ($mode === 'vertical') {
            $cols = 1;
            $rows = $n;
        } else { // grid: cot theo ty le area (giong LayoutManager::computeGrid), hoac ep so cot (preset)
            $cols = max(1, (int)round(sqrt($n * $W / max(1, $H))));
            $rows = (int)ceil($n / $cols);
            while ($cols > 1 && ($rows - 1) * $cols >= $n) $cols--;
            $rows = (int)ceil($n / $cols);
            if ($forceCols > 0) {
                $cols = min($forceCols, max(1, $n));
                $rows = (int)ceil($n / $cols);
            }
        }
        $cw = (int)floor(($W - $gapX * ($cols - 1)) / $cols);
        $ch = (int)floor(($H - $gapY * ($rows - 1)) / $rows);
        if ($cw <= 0 || $ch <= 0) return self::emptyPlan('Manual grid tran area');
        // Manual mode van ton trong min-size: nho hon -> canh bao trong message (van apply)
        $msg = ($cw < $minW || $ch < $minH)
            ? "Cell {$cw}x{$ch} nho hon minimum {$minW}x{$minH} (manual mode van apply)"
            : null;
        $plan = self::gridSlots($n, $a, $cols, $rows, $cw, $ch, $gapX, $gapY, $mode, 5.0);
        $plan['message'] = $msg;
        return $plan;
    }

    /** Cascade/compact: cung kich thuoc, lech offset, wrap trong work area (khong ra ngoai). */
    private static function offsetPlan(int $n, array $a, int $gapX, int $gapY, int $setW, int $setH,
        int $offX, int $offY, string $mode, bool $debug): array
    {
        $W = (int)$a['w'];
        $H = (int)$a['h'];
        $w = max(200, min($setW, $W));
        $h = max(150, min($setH, $H));
        $offX = max(0, $offX);
        $offY = max(0, $offY);
        $slots = [];
        for ($i = 0; $i < $n; $i++) {
            $spanX = max(0, $W - $w);
            $spanY = max(0, $H - $h);
            // Wrap de window thu N van trong work area (khong day ra ngoai man hinh)
            $dx = $spanX > 0 && $offX > 0 ? ($i * $offX) % ($spanX + 1) : 0;
            $dy = $spanY > 0 && $offY > 0 ? ($i * $offY) % ($spanY + 1) : 0;
            $slots[] = [
                'x' => (int)$a['x'] + $dx, 'y' => (int)$a['y'] + $dy,
                'w' => $w, 'h' => $h, 'monitorId' => $a['monitorId'],
            ];
        }
        return [
            'ok' => true, 'mode' => $mode, 'message' => null,
            'monitors' => [$a['monitorId']], 'rows' => $n, 'cols' => 1,
            'cellW' => $w, 'cellH' => $h, 'slots' => $slots,
            'score' => 3.0, 'fallbackUsed' => null,
        ];
    }

    // ---------------- HELPERS ----------------

    /** Dung slots grid left-top trong area (khong gia dinh area bat dau 0,0). */
    private static function gridSlots(int $n, array $a, int $cols, int $rows, int $cw, int $ch,
        int $gapX, int $gapY, string $mode, float $score): array
    {
        $slots = [];
        for ($i = 0; $i < $n; $i++) {
            $r = (int)floor($i / $cols);
            $c = $i % $cols;
            $slots[] = [
                'x' => (int)$a['x'] + $c * ($cw + $gapX),
                'y' => (int)$a['y'] + $r * ($ch + $gapY),
                'w' => $cw, 'h' => $ch, 'monitorId' => $a['monitorId'],
            ];
        }
        return [
            'ok' => true, 'mode' => $mode, 'message' => null,
            'monitors' => [$a['monitorId']], 'rows' => $rows, 'cols' => $cols,
            'cellW' => $cw, 'cellH' => $ch, 'slots' => $slots,
            'score' => $score, 'fallbackUsed' => null,
        ];
    }

    private static function emptyPlan(string $msg): array
    {
        return [
            'ok' => false, 'mode' => 'smart_auto', 'message' => $msg,
            'monitors' => [], 'rows' => 0, 'cols' => 0, 'cellW' => 0, 'cellH' => 0,
            'slots' => [], 'score' => 0.0, 'fallbackUsed' => null,
        ];
    }
}
