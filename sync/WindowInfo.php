<?php
declare(strict_types=1);
/**
 * SyncWindowInfo - DTO mo ta 1 top-level window cua Chrome (PHASE 1).
 * Map sang Profile model cua spec: id(hwnd), processId, windowRect, clientRect,
 * dpiScale, monitorId, status. Role/syncEnabled/lastHeartbeat se dung o Phase 6+.
 */
class SyncWindowInfo
{
    public int $hwnd = 0;
    public int $pid = 0;
    public ?int $profileId = null;
    public string $profileName = '';
    public string $userDataDir = '';
    public string $title = '';
    public string $class = '';
    public bool $visible = false;
    public bool $minimized = false;
    public bool $maximized = false;
    /** @var array{x:int,y:int,w:int,h:int}|null */
    public ?array $rect = null;
    /** @var array{x:int,y:int,w:int,h:int}|null normal rect khi maximized (rcNormalPosition) */
    public ?array $normalRect = null;
    /** @var array{x:int,y:int,w:int,h:int}|null client area, goc toa do SCREEN */
    public ?array $clientRect = null;
    public int $dpi = 96;
    public float $dpiScale = 1.0;
    public ?int $monitorId = null;

    public static function fromArray(array $a): self
    {
        $o = new self();
        $o->hwnd = (int)($a['hwnd'] ?? 0);
        $o->pid = (int)($a['pid'] ?? 0);
        $o->title = (string)($a['title'] ?? '');
        $o->class = (string)($a['class'] ?? '');
        $o->visible = (bool)($a['visible'] ?? false);
        $o->minimized = (bool)($a['minimized'] ?? false);
        $o->maximized = (bool)($a['maximized'] ?? false);
        $o->rect = self::rectOrNull($a['rect'] ?? null);
        $o->normalRect = self::rectOrNull($a['normalRect'] ?? null);
        $o->clientRect = self::rectOrNull($a['clientRect'] ?? null);
        $o->dpi = (int)($a['dpi'] ?? 96);
        $o->dpiScale = isset($a['dpiScale']) ? (float)$a['dpiScale'] : round($o->dpi / 96, 4);
        $o->monitorId = isset($a['monitorId']) && $a['monitorId'] !== null ? (int)$a['monitorId'] : null;
        return $o;
    }

    private static function rectOrNull($r): ?array
    {
        if (!is_array($r)) return null;
        return ['x' => (int)($r['x'] ?? 0), 'y' => (int)($r['y'] ?? 0),
                'w' => (int)($r['w'] ?? 0), 'h' => (int)($r['h'] ?? 0)];
    }

    /** Cua so trinh duyet chinh (loai popup/renderer): visible + class Chrome + co dien tich. */
    public function isBrowserMain(): bool
    {
        return $this->visible && !$this->minimized
            && $this->class === 'Chrome_WidgetWin_1'
            && $this->rect !== null && $this->rect['w'] > 0 && $this->rect['h'] > 0;
    }

    public function area(): int
    {
        return $this->rect === null ? 0 : $this->rect['w'] * $this->rect['h'];
    }

    public function toArray(): array
    {
        return [
            'hwnd' => $this->hwnd, 'pid' => $this->pid,
            'profileId' => $this->profileId, 'profileName' => $this->profileName,
            'title' => $this->title, 'class' => $this->class,
            'visible' => $this->visible, 'minimized' => $this->minimized,
            'maximized' => $this->maximized, 'normalRect' => $this->normalRect,
            'rect' => $this->rect, 'clientRect' => $this->clientRect,
            'dpi' => $this->dpi, 'dpiScale' => $this->dpiScale, 'monitorId' => $this->monitorId,
        ];
    }
}
