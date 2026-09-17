<?php
declare(strict_types=1);
/**
 * SyncSession - Phien dong bo (spec muc 2). 1 row = 1 session.
 * State machine: IDLE->STARTING->RUNNING<->PAUSED->STOPPING->STOPPED; ERROR tu bat ky dau.
 * Chi 1 session ACTIVE (STARTING/RUNNING/PAUSED/STOPPING) tai 1 thoi diem.
 */
require_once __DIR__ . '/../config.php';

class SyncSession
{
    public const IDLE = 'IDLE';
    public const STARTING = 'STARTING';
    public const RUNNING = 'RUNNING';
    public const PAUSED = 'PAUSED';
    public const STOPPING = 'STOPPING';
    public const STOPPED = 'STOPPED';
    public const ERROR = 'ERROR';

    /** @var array<string,string[]> */
    private const TRANSITIONS = [
        self::IDLE => [self::STARTING],
        self::STARTING => [self::RUNNING, self::ERROR, self::STOPPED],
        self::RUNNING => [self::PAUSED, self::STOPPING, self::ERROR],
        self::PAUSED => [self::RUNNING, self::STOPPING, self::ERROR],
        self::STOPPING => [self::STOPPED, self::ERROR],
        self::STOPPED => [self::STARTING],
        self::ERROR => [self::STARTING, self::IDLE],
    ];

    public int $id = 0;
    public string $name = 'Default Session';
    public int $mainProfileId = 0;
    /** @var int[] */
    public array $controlledIds = [];
    public string $state = self::IDLE;
    public array $config = [];
    public ?int $enginePid = null;
    public ?string $lastError = null;

    public static function defaultConfig(): array
    {
        return [
            'fps' => 60,
            'clickDelay' => 20,
            'clickRandom' => false,
            'clickVariation' => 30,
            'typingMin' => 50,
            'typingMax' => 120,
            'mouseMove' => true,
            'mouseClick' => true,
            'mouseWheel' => true,
            'keyboard' => true,
            'text' => true,
            'inputMode' => 'AUTO',       // AUTO|CDP|WINDOWS_API (WINDOWS_API: Phase 8)
            'stopPolicy' => 'drain',     // drain|cancel
            'showCursor' => false,       // dot debug vi tri target (Phase 9)
            'textMode' => 'off',         // off|keyboard|direct
            'textStrategy' => 'identical',
            'textLines' => [],
            'reconnectIds' => [],        // engine tieu thu de reconnect gap
        ];
    }

    public static function normalizeConfig(array $in): array
    {
        $c = self::defaultConfig();
        if (isset($in['fps'])) { $f = (int)$in['fps']; $c['fps'] = in_array($f, [30, 60, 120], true) ? $f : 60; }
        foreach (['clickDelay' => [0, 2000], 'typingMin' => [0, 5000], 'typingMax' => [0, 5000], 'clickVariation' => [0, 1000]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) $c[$k] = max($lo, min($hi, (int)$in[$k]));
        }
        if ($c['typingMax'] < $c['typingMin']) $c['typingMax'] = $c['typingMin'];
        foreach (['mouseMove', 'mouseClick', 'mouseWheel', 'keyboard', 'text', 'clickRandom', 'showCursor'] as $k) {
            if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]);
        }
        if (isset($in['inputMode']) && in_array($in['inputMode'], ['AUTO', 'CDP', 'WINDOWS_API'], true)) $c['inputMode'] = $in['inputMode'];
        if (isset($in['stopPolicy']) && in_array($in['stopPolicy'], ['drain', 'cancel'], true)) $c['stopPolicy'] = $in['stopPolicy'];
        if (isset($in['textMode']) && in_array($in['textMode'], ['off', 'keyboard', 'direct'], true)) $c['textMode'] = $in['textMode'];
        if (isset($in['textStrategy']) && in_array($in['textStrategy'], ['identical', 'design', 'in_order', 'random'], true)) $c['textStrategy'] = $in['textStrategy'];
        if (isset($in['textLines'])) {
            $ls = is_array($in['textLines']) ? $in['textLines'] : explode('|', (string)$in['textLines']);
            $c['textLines'] = array_values(array_filter(array_map(fn($l) => (string)$l, $ls), fn($l) => $l !== ''));
        }
        return $c;
    }

    public static function fromRow(array $r): self
    {
        $s = new self();
        $s->id = (int)$r['id'];
        $s->name = (string)($r['name'] ?? 'Default Session');
        $s->mainProfileId = (int)($r['main_profile_id'] ?? 0);
        $ids = json_decode((string)($r['controlled_ids'] ?? '[]'), true);
        $s->controlledIds = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        $s->state = (string)($r['state'] ?? self::IDLE);
        $cfg = json_decode((string)($r['config'] ?? '{}'), true);
        $s->config = self::normalizeConfig(is_array($cfg) ? $cfg : []);
        $s->enginePid = $r['engine_pid'] !== null ? (int)$r['engine_pid'] : null;
        $s->lastError = $r['last_error'] ?? null;
        return $s;
    }

    public static function load(int $id): ?self
    {
        $st = db()->prepare('SELECT * FROM sync_sessions WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? self::fromRow($r) : null;
    }

    /** Session ACTIVE hien tai (STARTING/RUNNING/PAUSED/STOPPING), null neu khong co. */
    public static function getActive(): ?self
    {
        $r = db()->query("SELECT * FROM sync_sessions WHERE state IN ('STARTING','RUNNING','PAUSED','STOPPING') ORDER BY id DESC LIMIT 1")->fetch();
        return $r ? self::fromRow($r) : null;
    }

    public static function latest(): ?self
    {
        $r = db()->query('SELECT * FROM sync_sessions ORDER BY id DESC LIMIT 1')->fetch();
        return $r ? self::fromRow($r) : null;
    }

    public function save(): void
    {
        db()->prepare('UPDATE sync_sessions SET name=?, main_profile_id=?, controlled_ids=?, state=?, config=?, engine_pid=?, last_error=? WHERE id=?')
            ->execute([$this->name, $this->mainProfileId ?: null, json_encode($this->controlledIds),
                $this->state, json_encode($this->config), $this->enginePid, $this->lastError, $this->id]);
    }

    public function canTransition(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->state] ?? [], true);
    }

    /** Chuyen state co validate. Nem RuntimeException neu khong hop le. */
    public function transition(string $to, ?string $error = null): void
    {
        if (!$this->canTransition($to)) {
            throw new RuntimeException("Chuyen state {$this->state} -> $to khong hop le");
        }
        $this->state = $to;
        if ($error !== null) $this->lastError = mb_substr($error, 0, 500);
        if ($to !== self::ERROR) $this->lastError = null;
        $this->save();
    }

    /**
     * Tao session moi: validate main + controlled ton tai, khong trung main;
     * reset role tat ca ve NONE roi gan MAIN/CONTROLLED.
     */
    public static function create(string $name, int $mainId, array $controlledIds, array $config = []): self
    {
        $mainId = (int)$mainId;
        $controlledIds = array_values(array_unique(array_filter(array_map('intval', $controlledIds))));
        if ($mainId <= 0) throw new RuntimeException('Chua chon kenh MAIN');
        $st = db()->prepare('SELECT id FROM profiles WHERE id=?');
        $st->execute([$mainId]);
        if (!$st->fetch()) throw new RuntimeException("Kenh MAIN #$mainId khong ton tai");
        foreach ($controlledIds as $cid) {
            if ($cid === $mainId) throw new RuntimeException("Kenh #$cid vua MAIN vua CONTROLLED");
            $st->execute([$cid]);
            if (!$st->fetch()) throw new RuntimeException("Kenh CONTROLLED #$cid khong ton tai");
        }
        if (!$controlledIds) throw new RuntimeException('Chua chon kenh CONTROLLED nao');
        db()->exec("UPDATE profiles SET sync_role='NONE'");
        db()->prepare('UPDATE profiles SET sync_role=? WHERE id=?')->execute(['MAIN', $mainId]);
        $in = implode(',', array_fill(0, count($controlledIds), '?'));
        db()->prepare("UPDATE profiles SET sync_role='CONTROLLED' WHERE id IN ($in)")->execute($controlledIds);
        db()->prepare('INSERT INTO sync_sessions (name, main_profile_id, controlled_ids, state, config) VALUES (?,?,?, ?, ?)')
            ->execute([$name !== '' ? $name : 'Default Session', $mainId, json_encode($controlledIds), self::IDLE, json_encode(self::normalizeConfig($config))]);
        $s = self::load((int)db()->lastInsertId());
        if (!$s) throw new RuntimeException('Khong tao duoc session');
        return $s;
    }

    /** Xoa session (chi khi STOPPED/IDLE/ERROR) + xoa role cac kenh cua no. */
    public function delete(): void
    {
        if (!in_array($this->state, [self::STOPPED, self::IDLE, self::ERROR], true)) {
            throw new RuntimeException('Phai STOP session truoc khi xoa (state=' . $this->state . ')');
        }
        $ids = $this->controlledIds;
        if ($this->mainProfileId > 0) $ids[] = $this->mainProfileId;
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE profiles SET sync_role='NONE' WHERE id IN ($in)")->execute($ids);
        }
        db()->prepare('DELETE FROM sync_sessions WHERE id=?')->execute([$this->id]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'main' => $this->mainProfileId,
            'controlled' => $this->controlledIds, 'state' => $this->state,
            'config' => $this->config, 'enginePid' => $this->enginePid, 'lastError' => $this->lastError,
        ];
    }
}
