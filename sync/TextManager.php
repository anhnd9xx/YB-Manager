<?php
declare(strict_types=1);
/**
 * SyncTextManager - Nhap text theo kich ban (spec muc 10, 11). Module RIENG,
 * khong nam trong SyncEngine: engine chi goi assign() + enterText().
 *
 * 2 mode nhap:
 *   keyboard - go tung ky tu qua phim (nhu nguoi go, co delay human typing)
 *   direct   - chen chuoi thang qua Input.insertText (nhanh)
 *
 * 4 chien luoc phan phoi (cho danh sach lines):
 *   identical - moi target nhan TOAN BO text (giong het nhau)
 *   design    - target thu i nhan lines[i] (thieu -> bo qua target do)
 *   in_order  - xoay vong: moi trigger dich con tro; target i nhan lines[(cursor+i)%n]
 *   random    - moi target nhan 1 line ngau nhien
 */
require_once __DIR__ . '/SyncEvent.php';
require_once __DIR__ . '/InputInjector.php';

class SyncTextManager
{
    private string $mode;       // keyboard|direct
    private string $strategy;   // identical|design|in_order|random
    private int $delayMinMs;
    private int $delayMaxMs;
    private int $cursor = 0;

    public function __construct(string $mode = 'keyboard', string $strategy = 'identical', int $delayMinMs = 50, int $delayMaxMs = 120)
    {
        $this->mode = $mode === 'direct' ? 'direct' : 'keyboard';
        $this->strategy = in_array($strategy, ['identical', 'design', 'in_order', 'random'], true) ? $strategy : 'identical';
        $this->delayMinMs = max(0, min(5000, $delayMinMs));
        $this->delayMaxMs = max($this->delayMinMs, min(5000, $delayMaxMs));
    }

    /**
     * Phan phoi lines cho danh sach target (theo thu tu).
     * Tra ve [targetKey => text]. Text rong = bo qua target do.
     * @param string[] $targets
     * @param string[] $lines
     */
    public function assign(array $targets, array $lines): array
    {
        $lines = array_values(array_filter(array_map(fn($l) => (string)$l, $lines), fn($l) => $l !== ''));
        $out = [];
        if (!$targets || !$lines) return $out;
        $n = count($lines);
        switch ($this->strategy) {
            case 'identical':
                $full = implode("\n", $lines);
                foreach ($targets as $t) $out[(string)$t] = $full;
                break;
            case 'design':
                foreach ($targets as $i => $t) {
                    if (isset($lines[$i])) $out[(string)$t] = $lines[$i];
                }
                break;
            case 'in_order':
                foreach ($targets as $i => $t) {
                    $out[(string)$t] = $lines[($this->cursor + $i) % $n];
                }
                $this->cursor = ($this->cursor + count($targets)) % $n;
                break;
            case 'random':
                foreach ($targets as $t) {
                    $out[(string)$t] = $lines[random_int(0, $n - 1)];
                }
                break;
        }
        return $out;
    }

    /**
     * Nhap 1 text vao target (field dang focus). Tra ve so ky tu da nhap (direct: mb_strlen).
     * Keyboard mode: delay human typing giua cac ky tu; ky tu la (khong ASCII map)
     * fallback insertText truc tiep tung ky tu do.
     */
    public function enterText(SyncInputInjector $injector, string $text): int
    {
        if ($text === '') return 0;
        if ($this->mode === 'direct') {
            return $injector->sendText($text) ? mb_strlen($text) : 0;
        }
        $done = 0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $ch) {
            $this->humanDelay();
            if ($ch === "\n" || $ch === "\r") {
                if ($injector->sendKeyDown('Enter', 'Enter', 13, 0, 0, false)) {
                    $injector->sendKeyUp('Enter', 'Enter', 13, 0, 0);
                    $done++;
                }
                continue;
            }
            $m = self::charKey($ch);
            if ($m === null) {
                // Ky tu la (VD tieng Viet): chen truc tiep de khong mat chu
                if ($injector->sendText($ch)) $done++;
                continue;
            }
            [$key, $code, $vk, $shift] = $m;
            if ($shift) $injector->sendKeyDown('Shift', 'ShiftLeft', 16, 0, 1, false);
            $mod = $shift ? 8 : 0;
            if ($injector->sendKeyDown($key, $code, $vk, $mod, 0, false)) {
                $injector->sendKeyUp($key, $code, $vk, $mod, 0);
                $done++;
            }
            if ($shift) $injector->sendKeyUp('Shift', 'ShiftLeft', 16, 0, 1);
        }
        return $done;
    }

    private function humanDelay(): void
    {
        $ms = $this->delayMaxMs <= $this->delayMinMs
            ? $this->delayMinMs
            : random_int($this->delayMinMs, $this->delayMaxMs);
        if ($ms > 0) usleep($ms * 1000);
    }

    /**
     * Map 1 ky tu ASCII in duoc -> [key, code, vk, shift] (layout US).
     * Tra ve null neu khong map duoc (caller fallback insertText).
     */
    public static function charKey(string $ch): ?array
    {
        if (strlen($ch) !== 1) return null;
        $o = ord($ch);
        if ($ch >= 'a' && $ch <= 'z') return [$ch, 'Key' . strtoupper($ch), $o - 32, false];
        if ($ch >= 'A' && $ch <= 'Z') return [$ch, 'Key' . $ch, $o, true];
        if ($ch >= '0' && $ch <= '9') return [$ch, 'Digit' . $ch, $o, false];
        if ($ch === ' ') return [' ', 'Space', 32, false];
        static $sym = [
            '-' => ['-', 'Minus', 189, false], '=' => ['=', 'Equal', 187, false],
            '[' => ['[', 'BracketLeft', 219, false], ']' => [']', 'BracketRight', 221, false],
            '\\' => ['\\', 'Backslash', 220, false], ';' => [';', 'Semicolon', 186, false],
            "'" => ["'", 'Quote', 222, false], '`' => ['`', 'Backquote', 192, false],
            ',' => [',', 'Comma', 188, false], '.' => ['.', 'Period', 190, false],
            '/' => ['/', 'Slash', 191, false],
            '!' => ['!', 'Digit1', 49, true], '@' => ['@', 'Digit2', 50, true],
            '#' => ['#', 'Digit3', 51, true], '$' => ['$', 'Digit4', 52, true],
            '%' => ['%', 'Digit5', 53, true], '^' => ['^', 'Digit6', 54, true],
            '&' => ['&', 'Digit7', 55, true], '*' => ['*', 'Digit8', 56, true],
            '(' => ['(', 'Digit9', 57, true], ')' => [')', 'Digit0', 48, true],
            '_' => ['_', 'Minus', 189, true], '+' => ['+', 'Equal', 187, true],
            '{' => ['{', 'BracketLeft', 219, true], '}' => ['}', 'BracketRight', 221, true],
            '|' => ['|', 'Backslash', 220, true], ':' => [':', 'Semicolon', 186, true],
            '"' => ['"', 'Quote', 222, true], '~' => ['~', 'Backquote', 192, true],
            '<' => ['<', 'Comma', 188, true], '>' => ['>', 'Period', 190, true],
            '?' => ['?', 'Slash', 191, true],
        ];
        return $sym[$ch] ?? null;
    }
}
