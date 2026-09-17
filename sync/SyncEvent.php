<?php
declare(strict_types=1);
/**
 * SyncEvent - Object su kien dong bo (spec muc 5).
 * Moi event co sequenceNumber de CONTROLLED nhan dung thu tu.
 * Forward-compatible: da co san field keyboard/text (Phase 4/5) va window (Phase 7).
 */
class SyncEvent
{
    // Mouse
    public const MOUSE_MOVE = 'MOUSE_MOVE';
    public const MOUSE_LEFT_DOWN = 'MOUSE_LEFT_DOWN';
    public const MOUSE_LEFT_UP = 'MOUSE_LEFT_UP';
    public const MOUSE_RIGHT_DOWN = 'MOUSE_RIGHT_DOWN';
    public const MOUSE_RIGHT_UP = 'MOUSE_RIGHT_UP';
    public const MOUSE_MIDDLE_DOWN = 'MOUSE_MIDDLE_DOWN';
    public const MOUSE_MIDDLE_UP = 'MOUSE_MIDDLE_UP';
    public const MOUSE_WHEEL = 'MOUSE_WHEEL';
    public const MOUSE_XBUTTON_DOWN = 'MOUSE_XBUTTON_DOWN';
    public const MOUSE_XBUTTON_UP = 'MOUSE_XBUTTON_UP';
    // Keyboard (Phase 4)
    public const KEY_DOWN = 'KEY_DOWN';
    public const KEY_UP = 'KEY_UP';
    // Text (Phase 5)
    public const TEXT_INPUT = 'TEXT_INPUT';
    // Window (Phase 7, engine poll Win32 - khong qua page capture)
    public const WINDOW_MOVED = 'WINDOW_MOVED';
    public const WINDOW_RESIZED = 'WINDOW_RESIZED';
    public const WINDOW_MINIMIZED = 'WINDOW_MINIMIZED';
    public const WINDOW_RESTORED = 'WINDOW_RESTORED';
    public const WINDOW_CLOSED = 'WINDOW_CLOSED';
    public const WINDOW_FOCUS = 'WINDOW_FOCUS';
    public const WINDOW_BLUR = 'WINDOW_BLUR';

    public int $id = 0;
    public int $sequenceNumber = 0;
    public float $timestamp = 0.0;
    public int $sourceProfileId = 0;
    public int $sourceWindowHandle = 0;
    public string $type = '';
    // Toa do goc (viewport CSS px, tu capture)
    public float $clientX = 0.0;
    public float $clientY = 0.0;
    public float $viewportW = 0.0;
    public float $viewportH = 0.0;
    // Screen px goc cua MAIN (Win32 path / debug, CDP path de 0)
    public float $screenX = 0.0;
    public float $screenY = 0.0;
    // Toa do chuan hoa 0..1 (sau NORMALIZE)
    public float $normalizedX = 0.0;
    public float $normalizedY = 0.0;
    // Mouse extra
    public int $button = 0;      // JS button: 0 left, 1 middle, 2 right
    public int $buttons = 0;     // JS buttons bitmask (giong CDP buttons)
    public int $clickCount = 0;  // e.detail (1 single, 2 double...)
    public float $deltaX = 0.0;
    public float $deltaY = 0.0;
    public int $deltaMode = 0;   // JS wheel deltaMode: 0 pixel, 1 line, 2 page
    public int $modifiers = 0;   // CDP bitmask: Alt=1,Ctrl=2,Meta=4,Shift=8
    // Keyboard (Phase 4)
    public int $keyCode = 0;
    public int $scanCode = 0;
    public string $key = '';    // JS e.key: 'a', 'A', 'Enter', 'F1', 'ArrowLeft'...
    public string $code = '';   // JS e.code vat ly: 'KeyA', 'Enter', 'ControlLeft'...
    public int $location = 0;   // 0 chuan, 1 trai, 2 phai, 3 numpad
    public bool $repeat = false;
    // Text (Phase 5)
    public string $text = '';

    /** Tao tu payload JSON cua capture binding. Tra ve null neu payload rac. */
    public static function fromCapture(int $profileId, array $d): ?self
    {
        $t = (string)($d['t'] ?? '');
        $valid = [self::MOUSE_MOVE, self::MOUSE_LEFT_DOWN, self::MOUSE_LEFT_UP,
                  self::MOUSE_RIGHT_DOWN, self::MOUSE_RIGHT_UP,
                  self::MOUSE_MIDDLE_DOWN, self::MOUSE_MIDDLE_UP, self::MOUSE_WHEEL,
                  self::MOUSE_XBUTTON_DOWN, self::MOUSE_XBUTTON_UP,
                  self::KEY_DOWN, self::KEY_UP];
        if (!in_array($t, $valid, true)) return null;
        $e = new self();
        $e->timestamp = microtime(true);
        $e->sourceProfileId = $profileId;
        $e->sourceWindowHandle = (int)($d['hwnd'] ?? 0);
        $e->type = $t;
        $e->screenX = (float)($d['sx'] ?? 0);
        $e->screenY = (float)($d['sy'] ?? 0);
        $e->clientX = (float)($d['x'] ?? 0);
        $e->clientY = (float)($d['y'] ?? 0);
        $e->viewportW = max(1.0, (float)($d['vw'] ?? 1));
        $e->viewportH = max(1.0, (float)($d['vh'] ?? 1));
        $e->button = (int)($d['btn'] ?? 0);
        $e->buttons = (int)($d['bts'] ?? 0);
        $e->clickCount = max(1, (int)($d['n'] ?? 1));
        $e->deltaX = (float)($d['dx'] ?? 0);
        $e->deltaY = (float)($d['dy'] ?? 0);
        $e->deltaMode = (int)($d['dm'] ?? 0);
        $e->modifiers = (int)($d['mod'] ?? 0);
        $e->key = (string)($d['key'] ?? '');
        $e->code = (string)($d['code'] ?? '');
        $e->keyCode = (int)($d['keyCode'] ?? 0);
        $e->location = (int)($d['loc'] ?? 0);
        $e->repeat = !empty($d['repeat']);
        return $e;
    }

    public function isMove(): bool
    {
        return $this->type === self::MOUSE_MOVE;
    }

    /** Chi CLICK/KEY/TEXT duoc bao toan tuyet doi (khong bao gio coalesce/drop). */
    public function isCritical(): bool
    {
        return !$this->isMove();
    }

    public function isDown(): bool
    {
        return in_array($this->type, [self::MOUSE_LEFT_DOWN, self::MOUSE_RIGHT_DOWN, self::MOUSE_MIDDLE_DOWN, self::MOUSE_XBUTTON_DOWN], true);
    }

    public function isUp(): bool
    {
        return in_array($this->type, [self::MOUSE_LEFT_UP, self::MOUSE_RIGHT_UP, self::MOUSE_MIDDLE_UP, self::MOUSE_XBUTTON_UP], true);
    }

    /** Nut CDP tuong ung (left/middle/right/back/forward/none). XBUTTON: btn 3=back, 4=forward. */
    public function cdpButton(): string
    {
        if ($this->type === self::MOUSE_XBUTTON_DOWN || $this->type === self::MOUSE_XBUTTON_UP) {
            return $this->button === 4 ? 'forward' : 'back';
        }
        return match (true) {
            $this->type === self::MOUSE_LEFT_DOWN || $this->type === self::MOUSE_LEFT_UP => 'left',
            $this->type === self::MOUSE_MIDDLE_DOWN || $this->type === self::MOUSE_MIDDLE_UP => 'middle',
            $this->type === self::MOUSE_RIGHT_DOWN || $this->type === self::MOUSE_RIGHT_UP => 'right',
            default => 'none',
        };
    }
}
