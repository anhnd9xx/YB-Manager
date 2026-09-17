<?php
declare(strict_types=1);
/**
 * SyncMouseManager - Dam bao tinh dung dan chuot moi target (spec muc 6).
 * - Giua trang thai nut (pressed) RIENG tung target: UP lac (khong co DOWN truoc)
 *   bi huy de KHONG BAO GIO xay ra UP -> DOWN.
 * - Click delay (co dinh; random khi bat, mac dinh fixed) ap truoc mousePressed.
 * - Wheel qua thang (khong coalesce o day; queue da xu ly).
 */
require_once __DIR__ . '/SyncEvent.php';
require_once __DIR__ . '/CoordinateMapper.php';
require_once __DIR__ . '/InputInjector.php';

class SyncMouseManager
{
    /** @var array<string,bool> key "targetKey:button" -> dang pressed */
    private array $pressed = [];
    private int $clickDelayMs;
    private bool $randomDelay;
    private int $delayVariationMs;

    public function __construct(int $clickDelayMs = 20, bool $randomDelay = false, int $delayVariationMs = 30)
    {
        $this->clickDelayMs = max(0, min(2000, $clickDelayMs));
        $this->randomDelay = $randomDelay;
        $this->delayVariationMs = max(0, min(1000, $delayVariationMs));
    }

    private function delay(): void
    {
        $ms = $this->clickDelayMs;
        if ($this->randomDelay && $this->delayVariationMs > 0) {
            $ms = max(0, $ms + random_int(-$this->delayVariationMs, $this->delayVariationMs));
        }
        if ($ms > 0) usleep($ms * 1000);
    }

    /**
     * Inject 1 SyncEvent chuot vao target qua injector.
     * $targetKey: dinh danh target (vidu profile id) de giu trang thai nut rieng.
     * $vw/$vh: viewport CSS hien tai cua TARGET (de map normalized -> px).
     * Tra ve 'ok' | 'dropped' (UP lac) | 'failed' (inject loi).
     */
    public function inject(SyncInputInjector $injector, string $targetKey, SyncEvent $e, float $vw, float $vh): string
    {
        $pt = SyncCoordinateMapper::toViewport($e->normalizedX, $e->normalizedY, $vw, $vh);
        $x = $pt['x'];
        $y = $pt['y'];
        $btn = $e->cdpButton();

        switch ($e->type) {
            case SyncEvent::MOUSE_MOVE:
                return $injector->sendMouseMove($x, $y, $e->buttons, $e->modifiers) ? 'ok' : 'failed';

            case SyncEvent::MOUSE_WHEEL: {
                $px = SyncCoordinateMapper::wheelToPixels($e->deltaX, $e->deltaY, $e->deltaMode);
                return $injector->sendMouseWheel($x, $y, $px['dx'], $px['dy'], $e->modifiers) ? 'ok' : 'failed';
            }

            default:
                break;
        }

        if ($e->isDown()) {
            $this->delay();
            $ok = $injector->sendMouseDown($x, $y, $btn, $e->clickCount, $e->modifiers);
            if ($ok) $this->pressed[$targetKey . ':' . $btn] = true;
            return $ok ? 'ok' : 'failed';
        }
        if ($e->isUp()) {
            $k = $targetKey . ':' . $btn;
            if (empty($this->pressed[$k])) return 'dropped'; // UP lac: huy, giu nguyen tac DOWN->UP
            unset($this->pressed[$k]);
            return $injector->sendMouseUp($x, $y, $btn, $e->clickCount, $e->modifiers) ? 'ok' : 'failed';
        }
        return 'dropped';
    }

    /** Reset trang thai nut 1 target (khi reconnect/target doi). */
    public function resetTarget(string $targetKey): void
    {
        foreach (array_keys($this->pressed) as $k) {
            if (str_starts_with($k, $targetKey . ':')) unset($this->pressed[$k]);
        }
    }
}
