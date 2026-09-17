<?php
declare(strict_types=1);
/**
 * SyncInputInjector - Interface抽象 (spec muc 12: IInputInjector).
 * Phase 3: mouse day du. Keyboard/text mo rong o Phase 4/5 (interface extension,
 * khong pha vo implement hien co).
 */
require_once __DIR__ . '/SyncEvent.php';

interface SyncInputInjector
{
    public function sendMouseMove(float $x, float $y, int $buttons, int $modifiers): bool;
    public function sendMouseDown(float $x, float $y, string $button, int $clickCount, int $modifiers): bool;
    public function sendMouseUp(float $x, float $y, string $button, int $clickCount, int $modifiers): bool;
    public function sendMouseWheel(float $x, float $y, float $deltaX, float $deltaY, int $modifiers): bool;
    // Phase 4: keyboard that (giu thu tu DOWN/UP de hotkey hoat dong)
    public function sendKeyDown(string $key, string $code, int $winVk, int $modifiers, int $location, bool $repeat): bool;
    public function sendKeyUp(string $key, string $code, int $winVk, int $modifiers, int $location): bool;
    // Phase 5: direct text (chen chuoi thang vao field dang focus, khong qua phim)
    public function sendText(string $text): bool;
    /** Kha nang backend (vidu: ['mouse','wheel','key']). */
    public function capabilities(): array;
}

/**
 * Bang map JS KeyboardEvent.code (vat ly, layout-independent) -> Windows VK.
 * Tra ve 0 neu khong biet (caller fallback e.keyCode).
 */
class SyncKeyMap
{
    /** @var array<string,int>|null */
    private static ?array $table = null;

    private static function build(): array
    {
        $t = [
            'Backspace' => 8, 'Tab' => 9, 'Enter' => 13, 'ShiftLeft' => 16, 'ShiftRight' => 16,
            'ControlLeft' => 17, 'ControlRight' => 17, 'AltLeft' => 18, 'AltRight' => 18,
            'Pause' => 19, 'CapsLock' => 20, 'Escape' => 27, 'Space' => 32,
            'PageUp' => 33, 'PageDown' => 34, 'End' => 35, 'Home' => 36,
            'ArrowLeft' => 37, 'ArrowUp' => 38, 'ArrowRight' => 39, 'ArrowDown' => 40,
            'PrintScreen' => 44, 'Insert' => 45, 'Delete' => 46,
            'MetaLeft' => 91, 'MetaRight' => 92, 'OSLeft' => 91, 'OSRight' => 92,
            'ContextMenu' => 93,
            'NumLock' => 144, 'ScrollLock' => 145,
            'NumpadMultiply' => 106, 'NumpadAdd' => 107, 'NumpadSubtract' => 109,
            'NumpadDecimal' => 110, 'NumpadDivide' => 111, 'NumpadEnter' => 13,
            'Minus' => 189, 'Equal' => 187, 'BracketLeft' => 219, 'BracketRight' => 221,
            'Backslash' => 220, 'Semicolon' => 186, 'Quote' => 222, 'Backquote' => 192,
            'Comma' => 188, 'Period' => 190, 'Slash' => 191,
        ];
        for ($i = 0; $i <= 9; $i++) $t['Digit' . $i] = 48 + $i;          // 48-57
        for ($c = 0; $c < 26; $c++) $t['Key' . chr(65 + $c)] = 65 + $c;  // 65-90
        for ($f = 1; $f <= 12; $f++) $t['F' . $f] = 111 + $f;            // 112-123
        for ($i = 0; $i <= 9; $i++) $t['Numpad' . $i] = 96 + $i;         // 96-105
        return $t;
    }

    public static function winVk(string $code, int $fallback = 0): int
    {
        if (self::$table === null) self::$table = self::build();
        if (isset(self::$table[$code])) return self::$table[$code];
        return $fallback > 0 ? $fallback : 0;
    }
}

/**
 * SyncCdpInjector - Backend CDP (spec: CdpInputInjector).
 * Bam Input.dispatchMouseEvent tren WS persistent cua target.
 * Toa do: CSS px tuong doi viewport (khop CoordinateMapper::toViewport).
 */
require_once __DIR__ . '/CdpConnection.php';

class SyncCdpInjector implements SyncInputInjector
{
    private SyncCdpConnection $conn;

    public function __construct(SyncCdpConnection $conn)
    {
        $this->conn = $conn;
    }

    public function capabilities(): array
    {
        return ['mouse', 'wheel', 'key', 'text'];
    }

    private function dispatch(string $type, array $params): bool
    {
        if (!$this->conn->isConnected()) return false;
        return $this->conn->sendCommand('Input.dispatchMouseEvent', array_merge(['type' => $type], $params)) > 0;
    }

    public function sendMouseMove(float $x, float $y, int $buttons, int $modifiers): bool
    {
        $p = ['x' => round($x, 2), 'y' => round($y, 2), 'modifiers' => $modifiers];
        if ($buttons > 0) $p['buttons'] = $buttons;
        else $p['button'] = 'none';
        return $this->dispatch('mouseMoved', $p);
    }

    public function sendMouseDown(float $x, float $y, string $button, int $clickCount, int $modifiers): bool
    {
        return $this->dispatch('mousePressed', [
            'x' => round($x, 2), 'y' => round($y, 2), 'button' => $button,
            'clickCount' => max(1, $clickCount), 'modifiers' => $modifiers,
        ]);
    }

    public function sendMouseUp(float $x, float $y, string $button, int $clickCount, int $modifiers): bool
    {
        return $this->dispatch('mouseReleased', [
            'x' => round($x, 2), 'y' => round($y, 2), 'button' => $button,
            'clickCount' => max(1, $clickCount), 'modifiers' => $modifiers,
        ]);
    }

    public function sendMouseWheel(float $x, float $y, float $deltaX, float $deltaY, int $modifiers): bool
    {
        if ($deltaX == 0.0 && $deltaY == 0.0) return true; // wheel rong: bo qua, van tinh thanh cong
        return $this->dispatch('mouseWheel', [
            'x' => round($x, 2), 'y' => round($y, 2),
            'deltaX' => round($deltaX, 2), 'deltaY' => round($deltaY, 2),
            'modifiers' => $modifiers,
        ]);
    }

    /**
     * Phim nhan (giu hotkey): type keyDown + text cho phim chu (khong Ctrl/Alt/Meta),
     * Enter kem "\r" khi khong co modifier chu. Phim dieu khien/shortcut khong kem
     * text (tranh go nham + giu hotkey Ctrl+A/C/V/F/L, Alt+..., Win+...).
     * Shift duoc phep kem text (Shift+A -> 'A'). keyUp khong bao gio kem text.
     */
    public function sendKeyDown(string $key, string $code, int $winVk, int $modifiers, int $location, bool $repeat): bool
    {
        if ($winVk <= 0 && $key === '') return false;
        $p = ['type' => 'keyDown', 'modifiers' => $modifiers, 'location' => $location];
        if ($key !== '') $p['key'] = $key;
        if ($code !== '') $p['code'] = $code;
        if ($winVk > 0) {
            $p['windowsVirtualKeyCode'] = $winVk;
            $p['nativeVirtualKeyCode'] = $winVk;
        }
        $charMod = ($modifiers & 7) !== 0; // Alt=1,Ctrl=2,Meta=4 -> shortcut, khong go chu
        if (!$charMod && mb_strlen($key) === 1) {
            $p['text'] = $key;
        } elseif ($key === 'Enter' && !$charMod) {
            $p['text'] = "\r";
        }
        if ($repeat) $p['autoRepeat'] = true;
        return $this->dispatchKey($p);
    }

    public function sendKeyUp(string $key, string $code, int $winVk, int $modifiers, int $location): bool
    {
        if ($winVk <= 0 && $key === '') return false;
        $p = ['type' => 'keyUp', 'modifiers' => $modifiers, 'location' => $location];
        if ($key !== '') $p['key'] = $key;
        if ($code !== '') $p['code'] = $code;
        if ($winVk > 0) {
            $p['windowsVirtualKeyCode'] = $winVk;
            $p['nativeVirtualKeyCode'] = $winVk;
        }
        return $this->dispatchKey($p);
    }

    private function dispatchKey(array $params): bool
    {
        if (!$this->conn->isConnected()) return false;
        return $this->conn->sendCommand('Input.dispatchKeyEvent', $params) > 0;
    }

    /** Chen truc tiep 1 chuoi (DIRECT TEXT mode). Bo qua chuoi rong (tinh thanh cong). */
    public function sendText(string $text): bool
    {
        if ($text === '') return true;
        if (!$this->conn->isConnected()) return false;
        return $this->conn->sendCommand('Input.insertText', ['text' => $text]) > 0;
    }
}
