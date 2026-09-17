<?php
declare(strict_types=1);
/**
 * MultiMonitorLayoutEngine - Phan phoi windows giua nhieu monitor (spec multi-monitor).
 * PURE: khong DB/API/log. SmartLayoutEngine giu nguyen (grid TRONG 1 monitor);
 * engine nay quyet dinh bao nhieu window vao tung monitor (spec muc 3).
 *
 * Capacity (spec muc 4): so window toi da co smart grid dat minimum size,
 * = floor((W+gapX)/(minW+gapX)) * floor((H+gapY)/(minH+gapY)).
 */
require_once __DIR__ . '/SmartLayoutEngine.php';

class MultiMonitorLayoutEngine
{
    /**
     * @param array<int,array{monitorId:int,name?:string,x:int,y:int,w:int,h:int}> $areas (giữ thứ tự selection cho sequential)
     * @param array|null $manualCounts [areaIdx => count] (distribution manual, tong phai = n)
     * @return array LayoutPlan + 'breakdown'=>[{monitorId,count,rows,cols,cellW,cellH}]
     */
    public static function plan(int $n, array $areas, array $layout, array $win,
        ?array $manualCounts = null, bool $debug = false): array
    {
        $areas = array_values($areas);
        if ($n <= 0 || !$areas) {
            return self::fail($n <= 0 ? 'Khong co window nao' : 'Khong co monitor nao');
        }
        $mode = (string)($layout['distribution'] ?? 'smart');
        if ($manualCounts !== null) {
            $counts = self::sanitizeCounts($manualCounts, count($areas), $n);
            if ($counts === null) return self::fail('Manual counts khong khop so window');
            return self::buildFromCounts($n, $areas, $counts, $layout, $win, 'manual', $debug);
        }
        switch ($mode) {
            case 'equal':
                return self::buildFromCounts($n, $areas, self::equalCounts($n, count($areas)), $layout, $win, 'equal', $debug);
            case 'sequential':
                return self::sequentialPlan($n, $areas, $layout, $win, $debug);
            default: // smart
                return self::smartPlan($n, $areas, $layout, $win, $debug);
        }
    }

    /** Suc chua hop ly 1 monitor voi minimum size (spec muc 4). */
    public static function capacity(array $a, int $gapX, int $gapY, int $minW, int $minH): int
    {
        $cols = (int)floor(((int)$a['w'] + $gapX) / (max(1, $minW) + $gapX));
        $rows = (int)floor(((int)$a['h'] + $gapY) / (max(1, $minH) + $gapY));
        return max(0, $cols) * max(0, $rows);
    }

    // ---------------- SMART ----------------

    private static function smartPlan(int $n, array $areas, array $layout, array $win, bool $debug): array
    {
        $m = count($areas);
        if ($m === 1) {
            return self::buildFromCounts($n, $areas, [$n], $layout, $win, 'smart', $debug);
        }
        $gapX = max(0, (int)($layout['gapX'] ?? 5));
        $gapY = max(0, (int)($layout['gapY'] ?? 5));
        $minW = max(50, (int)($layout['minW'] ?? 500));
        $minH = max(50, (int)($layout['minH'] ?? 400));
        $caps = [];
        foreach ($areas as $a) $caps[] = self::capacity($a, $gapX, $gapY, $minW, $minH);
        $totalCap = array_sum($caps);
        if ($totalCap < $n) {
            return self::fail('Tong capacity ' . $totalCap . ' < ' . $n . ' windows');
        }
        // Base: ty le capacity (monitor lon nhan nhieu, spec muc 2/18)
        $base = [];
        $assigned = 0;
        foreach ($caps as $i => $c) {
            $k = ($i === $m - 1) ? $n - $assigned : (int)round($n * $c / max(1, $totalCap));
            $k = max(0, min($c, $k));
            $base[$i] = $k;
            $assigned += $k;
        }
        self::fixSum($base, $n, $caps);
        if ($n >= $m) {
            // Moi monitor it nhat 1 window (lay tu monitor du)
            foreach ($base as $i => $k) {
                if ($k === 0) {
                    $donor = self::richest($base, $caps);
                    if ($donor >= 0 && $base[$donor] > 1) {
                        $base[$donor]--;
                        $base[$i]++;
                    }
                }
            }
            self::fixSum($base, $n, $caps);
        }
        // Candidates: base + dich chuyen +-1..3 giua cap monitor (bounded, spec muc 5)
        $cands = [$base];
        for ($i = 0; $i < $m; $i++) {
            for ($j = 0; $j < $m; $j++) {
                if ($i === $j) continue;
                foreach ([1, 2, 3] as $k) {
                    $c = $base;
                    if ($c[$i] - $k >= ($n >= $m ? 1 : 0) && $c[$j] + $k <= $caps[$j]) {
                        $c[$i] -= $k;
                        $c[$j] += $k;
                        $cands[] = $c;
                    }
                }
            }
        }
        // Loai trung
        $seen = [];
        $uniq = [];
        foreach ($cands as $c) {
            $key = implode(',', $c);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniq[] = $c;
            }
        }
        $best = null;
        $bestScore = null;
        $tried = 0;
        foreach ($uniq as $c) {
            $r = self::buildFromCounts($n, $areas, $c, $layout, $win, 'smart', false);
            if (!$r['ok']) continue;
            $tried++;
            $s = self::scoreDistribution($r, $layout);
            if ($bestScore === null || $s > $bestScore) {
                $bestScore = $s;
                $best = $r;
            }
        }
        if ($best === null) return self::fail('Khong co phan phoi nao dat minimum size');
        if ($debug) {
            $best['triedCandidates'] = count($uniq);
            $best['validCandidates'] = $tried;
        }
        return $best;
    }

    /**
     * Score phan phoi (spec muc 5): tong score/grid theo so window
     * + balance kich thuoc giua monitors (similar) + phat empty.
     */
    private static function scoreDistribution(array $plan, array $layout): float
    {
        $score = (float)($plan['score'] ?? 0);
        $cells = [];
        foreach ($plan['breakdown'] ?? [] as $b) {
            $cells[] = (int)$b['cellW'] * (int)$b['cellH'];
        }
        if (($layout['sizeBalance'] ?? 'similar') === 'similar' && count($cells) > 1) {
            $mx = max($cells);
            if ($mx > 0) $score -= 2.0 * ($mx - min($cells)) / $mx; // prefer similar size (tot cho sync)
        }
        return $score;
    }

    // ---------------- EQUAL / SEQUENTIAL / MANUAL ----------------

    /** @return int[] */
    private static function equalCounts(int $n, int $m): array
    {
        $base = (int)floor($n / max(1, $m));
        $rem = $n - $base * $m;
        $out = array_fill(0, $m, $base);
        for ($i = 0; $i < $rem; $i++) $out[$i]++;
        return $out;
    }

    private static function sequentialPlan(int $n, array $areas, array $layout, array $win, bool $debug): array
    {
        $gapX = max(0, (int)($layout['gapX'] ?? 5));
        $gapY = max(0, (int)($layout['gapY'] ?? 5));
        $minW = max(50, (int)($layout['minW'] ?? 500));
        $minH = max(50, (int)($layout['minH'] ?? 400));
        $counts = array_fill(0, count($areas), 0);
        $rest = $n;
        foreach ($areas as $i => $a) {
            if ($rest <= 0) break;
            $cap = self::capacity($a, $gapX, $gapY, $minW, $minH);
            $take = min($rest, max(0, $cap));
            $counts[$i] = $take;
            $rest -= $take;
        }
        if ($rest > 0) return self::fail('Sequential: het capacity, thua ' . $rest . ' windows');
        return self::buildFromCounts($n, $areas, $counts, $layout, $win, 'sequential', $debug);
    }

    /** @return int[]|null */
    private static function sanitizeCounts(array $in, int $m, int $n): ?array
    {
        $out = array_fill(0, $m, 0);
        foreach ($in as $idx => $c) {
            $idx = (int)$idx;
            if ($idx < 0 || $idx >= $m || (int)$c < 0) return null;
            $out[$idx] = (int)$c;
        }
        return array_sum($out) === $n ? $out : null;
    }

    // ---------------- BUILD ----------------

    /**
     * Dung SmartLayoutEngine cho tung monitor (grid tot nhat trong 1 monitor).
     * Monitor 0 window duoc bo qua (slot khong tao).
     */
    private static function buildFromCounts(int $n, array $areas, array $counts, array $layout,
        array $win, string $distLabel, bool $debug): array
    {
        $one = $layout;
        $one['mode'] = 'smart_auto';
        $one['multi'] = false;
        $slots = [];
        $monitors = [];
        $breakdown = [];
        $scoreSum = 0;
        $wSum = 0;
        $rows = 0;
        $cols = 0;
        foreach ($areas as $i => $a) {
            $c = (int)($counts[$i] ?? 0);
            if ($c <= 0) continue;
            $p = SmartLayoutEngine::plan($c, [$a], $one, $win, $debug);
            if (!$p['ok']) return self::fail('Monitor ' . $a['monitorId'] . ': ' . ($p['message'] ?? 'khong du cho'));
            foreach ($p['slots'] as $s) $slots[] = $s;
            $monitors[] = $a['monitorId'];
            $breakdown[] = ['monitorId' => $a['monitorId'], 'name' => $a['name'] ?? '',
                'count' => $c, 'rows' => $p['rows'], 'cols' => $p['cols'],
                'cellW' => $p['cellW'], 'cellH' => $p['cellH']];
            $scoreSum += (float)$p['score'] * $c;
            $wSum += $c;
            $rows = max($rows, (int)$p['rows']);
            $cols += (int)$p['cols'];
        }
        if (count($slots) !== $n) return self::fail('Tong slots lech');
        $plan = [
            'ok' => true, 'mode' => 'smart_auto', 'message' => null,
            'monitors' => $monitors, 'rows' => $rows, 'cols' => $cols,
            'cellW' => 0, 'cellH' => 0, 'slots' => $slots,
            'score' => $wSum > 0 ? $scoreSum / $wSum : 0,
            'fallbackUsed' => null, 'distribution' => $distLabel, 'breakdown' => $breakdown,
        ];
        $plan['score'] = self::scoreDistribution($plan, $layout);
        return $plan;
    }

    // ---------------- HELPERS ----------------

    /** Dieu chinh tong counts = n trong gioi han capacity (uu tien monitor lon). */
    private static function fixSum(array &$counts, int $n, array $caps): void
    {
        $guard = 0;
        while (array_sum($counts) > $n && $guard++ < 1000) {
            $big = self::richest($counts, $caps);
            if ($big < 0) break;
            $counts[$big]--;
        }
        $guard = 0;
        $order = array_keys($counts);
        usort($order, fn($a, $b) => $caps[$b] <=> $caps[$a]);
        while (array_sum($counts) < $n && $guard++ < 1000) {
            $placed = false;
            foreach ($order as $i) {
                if ($counts[$i] < $caps[$i]) {
                    $counts[$i]++;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) break;
        }
    }

    private static function richest(array $counts, array $caps): int
    {
        $best = -1;
        foreach ($counts as $i => $c) {
            if ($c > 0 && ($best < 0 || $c > $counts[$best])) $best = $i;
        }
        return $best;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'mode' => 'smart_auto', 'message' => $msg,
            'monitors' => [], 'rows' => 0, 'cols' => 0, 'cellW' => 0, 'cellH' => 0,
            'slots' => [], 'score' => 0.0, 'fallbackUsed' => null, 'breakdown' => []];
    }
}
