<?php
declare(strict_types=1);
/**
 * SyncKeyboardManager - Dam bao tinh dung dan phim moi target (Phase 4).
 * - Giu tap phim dang nhan (DOWN) RIENG tung target: keyUp lac (khong co DOWN
 *   truoc do, vidu mat event khi reconnect) bi huy de tranh trang thai ket.
 * - keyDown (ke ca auto-repeat) luon cho qua de giu thu tu.
 * - WinVK resolve: bang SyncKeyMap theo code vat ly, fallback e.keyCode.
 */
require_once __DIR__ . '/SyncEvent.php';
require_once __DIR__ . '/InputInjector.php';

class SyncKeyboardManager
{
    /** @var array<string,true> key "targetKey|code|location" dang pressed */
    private array $down = [];
    private int $droppedStrayUp = 0;

    public function inject(SyncInputInjector $injector, string $targetKey, SyncEvent $e): string
    {
        if ($e->type !== SyncEvent::KEY_DOWN && $e->type !== SyncEvent::KEY_UP) return 'dropped';
        $vk = SyncKeyMap::winVk($e->code, $e->keyCode);
        if ($vk <= 0 && $e->key === '') return 'dropped'; // phim la: khong du du lieu inject
        $slot = $targetKey . '|' . ($e->code !== '' ? $e->code : 'vk' . $vk) . '|' . $e->location;

        if ($e->type === SyncEvent::KEY_DOWN) {
            $ok = $injector->sendKeyDown($e->key, $e->code, $vk, $e->modifiers, $e->location, $e->repeat);
            if ($ok) $this->down[$slot] = true;
            return $ok ? 'ok' : 'failed';
        }
        // KEY_UP: chi gui khi co DOWN truoc (tranh ket phim ao)
        if (empty($this->down[$slot])) {
            $this->droppedStrayUp++;
            return 'dropped';
        }
        unset($this->down[$slot]);
        return $injector->sendKeyUp($e->key, $e->code, $vk, $e->modifiers, $e->location) ? 'ok' : 'failed';
    }

    /** Reset phim dang nhan 1 target (khi reconnect/target doi). */
    public function resetTarget(string $targetKey): void
    {
        foreach (array_keys($this->down) as $k) {
            if (str_starts_with($k, $targetKey . '|')) unset($this->down[$k]);
        }
    }

    public function droppedStrayUp(): int
    {
        return $this->droppedStrayUp;
    }
}
