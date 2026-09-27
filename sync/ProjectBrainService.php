<?php
declare(strict_types=1);
/**
 * ProjectBrainService - Structured project intelligence (khong phai LLM).
 * Symbol index (PHP tokenizer + JS regex fallback), dependency graph on-demand,
 * module map, incremental update theo mtime + git HEAD.
 */
require_once __DIR__ . '/../config.php';

class ProjectBrainService
{
    public const MODULES = [
        'TELEGRAM' => ['Telegram', 'TgBot', 'TgSecret', 'Conversation', 'Pairing', 'Permission'],
        'CHROME' => ['Chrome', 'Window', 'Profile', 'Cdp', 'Browser', 'Tab', 'MonitorLayout', 'Dpi', 'Coordinate'],
        'AUTO_ACTIVITY' => ['Activity'],
        'EVALUATION' => ['Eval', 'Evaluation', 'Account', 'Auth', 'YoutubeSignals', 'HealthCheckPipeline'],
        'JOBS' => ['Job', 'Command', 'Confirmation'],
        'PROXY' => ['Proxy', 'proxy_relay'],
        'NOTIFICATION' => ['Notif', 'Report', 'EventBus', 'AppEvent', 'EventQueue'],
        'AIDEV' => ['OpenCode', 'AIDev', 'AIIntent', 'AIDIag', 'DevJob', 'DevProject', 'ProjectBrain',
            'ProjectKnowledge', 'SmartContext', 'RuntimeContext', 'AILog', 'DevPlan', 'ArchitectureRule',
            'AICodeReview', 'TestSelection', 'KnownIssue', 'ModuleRegistry'],
        'HEALTH' => ['Health', 'Monitoring', 'MonitorManager', 'Alert'],
        'SCHEDULER' => ['Schedul', 'cron'],
        'SYNCHRONIZE' => ['Sync', 'TabSession'],
        'API' => [],
        'CORE' => [],
    ];

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS project_symbols (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL DEFAULT 0,
                symbol_name VARCHAR(190) NOT NULL DEFAULT '',
                symbol_type VARCHAR(20) NOT NULL DEFAULT 'CLASS',
                file_path VARCHAR(500) NOT NULL DEFAULT '',
                start_line INT NOT NULL DEFAULT 0,
                end_line INT NOT NULL DEFAULT 0,
                parent_symbol VARCHAR(190) NOT NULL DEFAULT '',
                module VARCHAR(40) NOT NULL DEFAULT '',
                language VARCHAR(10) NOT NULL DEFAULT 'php',
                signature VARCHAR(500) NOT NULL DEFAULT '',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_brain_symbol (project_id, symbol_type, symbol_name, file_path(255)),
                KEY idx_brain_search (project_id, symbol_name),
                KEY idx_brain_file (project_id, file_path(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS project_index_state (
                project_id INT PRIMARY KEY,
                head_commit VARCHAR(80) NOT NULL DEFAULT '',
                files_indexed INT NOT NULL DEFAULT 0,
                symbols_count INT NOT NULL DEFAULT 0,
                last_full_at DATETIME NULL,
                last_incremental_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    public static function moduleOf(string $path, string $symbol = ''): string
    {
        $base = basename($path);
        if (str_starts_with($path, 'api/') || str_starts_with($base, 'api')) {
            if ($base !== 'api' && str_contains($path, 'api/')) return 'API';
        }
        foreach (self::MODULES as $mod => $keys) {
            foreach ($keys as $k) {
                if ($k !== '' && (stripos($base, $k) !== false || ($symbol !== '' && stripos($symbol, $k) !== false))) {
                    return $mod;
                }
            }
        }
        if (str_starts_with($path, 'bin/')) return 'CORE';
        if (str_starts_with($path, 'assets/')) return 'CORE';
        return 'CORE';
    }

    /** @return string[] files php/js (bo worktrees, profiles, .git, logs) */
    public static function sourceFiles(string $root): array
    {
        $out = [];
        $skip = ['.git/', '.worktrees/', 'profiles/', 'node_modules/', 'vendor/', 'shortcuts/', 'android/frames/'];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if ($ext !== 'php' && $ext !== 'js') continue;
                if (str_contains($rel, '.min.js')) continue;
                $bad = false;
                foreach ($skip as $s) {
                    if (str_starts_with($rel, $s)) {
                        $bad = true;
                        break;
                    }
                }
                if ($bad) continue;
                if (filesize($f->getPathname()) > 500000) continue;
                $out[] = $rel;
            }
        } catch (Throwable $e) {
        }
        sort($out);
        return $out;
    }

    /**
     * Index (full lan dau, incremental sau). @return array{files, symbols, full}
     */
    public static function index(int $projectId, bool $forceFull = false): array
    {
        self::ensureTables();
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            require_once __DIR__ . '/DevJobManager.php';
            $p = DevProjectRegistry::get($projectId);
            if (!$p) return ['files' => 0, 'symbols' => 0, 'full' => false];
            $root = realpath((string)$p['root_path']);
            if ($root === false) return ['files' => 0, 'symbols' => 0, 'full' => false];
            $files = self::sourceFiles($root);
            $stateKey = 'ai_brain_files_' . $projectId;
            $old = json_decode((string)get_setting($stateKey, ''), true);
            if (!is_array($old)) $old = [];
            $head = DevJobManager::headCommit($root);
            $st = db()->prepare('SELECT head_commit FROM project_index_state WHERE project_id=?');
            $st->execute([$projectId]);
            $oldHead = (string)($st->fetchColumn() ?: '');
            $full = $forceFull || $oldHead === '';
            $todo = [];
            $map = [];
            foreach ($files as $rel) {
                $mt = (int)@filemtime($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
                $map[$rel] = $mt . ':' . substr($head, 0, 12);
                if ($full || ($old[$rel] ?? '') !== $map[$rel]) $todo[] = $rel;
            }
            // Xoa symbols cua file da xoa
            try {
                $gone = array_diff(array_keys($old), $files);
                foreach (array_chunk($gone, 100) as $ch) {
                    $in = implode(',', array_fill(0, count($ch), '?'));
                    $del = db()->prepare("DELETE FROM project_symbols WHERE project_id=? AND file_path IN ($in)");
                    $del->execute(array_merge([$projectId], array_values($ch)));
                }
            } catch (Throwable $e) {
            }
            $symCount = 0;
            foreach ($todo as $rel) {
                $syms = self::parseFile($root, $rel);
                self::storeSymbols($projectId, $rel, $syms);
                $symCount += count($syms);
            }
            set_setting($stateKey, json_encode($map));
            $total = (int)db()->query('SELECT COUNT(*) FROM project_symbols WHERE project_id=' . $projectId)->fetchColumn();
            db()->prepare('INSERT INTO project_index_state (project_id, head_commit, files_indexed, symbols_count,
                    last_full_at, last_incremental_at) VALUES (?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE head_commit=VALUES(head_commit), files_indexed=VALUES(files_indexed),
                    symbols_count=VALUES(symbols_count),
                    last_full_at=CASE WHEN VALUES(last_full_at) IS NOT NULL THEN VALUES(last_full_at) ELSE last_full_at END,
                    last_incremental_at=NOW()')
                ->execute([$projectId, $head, count($files), $total, $full ? date('Y-m-d H:i:s') : null]);
            return ['files' => count($todo), 'symbols' => $total, 'full' => $full];
        } catch (Throwable $e) {
            return ['files' => 0, 'symbols' => 0, 'full' => false];
        }
    }

    /** Parse 1 file: PHP dung tokenizer, JS dung regex. */
    private static function parseFile(string $root, string $rel): array
    {
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $src = (string)@file_get_contents($full);
        if ($src === '') return [];
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'php') return self::parsePhp($src, $rel);
        return self::parseJs($src, $rel);
    }

    /** @return array[] {name,type,line,parent,signature} */
    private static function parsePhp(string $src, string $rel): array
    {
        $out = [];
        try {
            $toks = token_get_all($src);
        } catch (Throwable $e) {
            return [];
        }
        $n = count($toks);
        $class = '';
        $classLine = 0;
        $lastDoc = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t)) continue;
            [$id, $text, $line] = $t;
            if ($id === T_DOC_COMMENT) {
                $lastDoc = trim(preg_replace('/\s*\*\s?/', ' ', substr($text, 3, -2)));
                $lastDoc = mb_substr(preg_replace('/\s+/', ' ', $lastDoc), 0, 200);
                continue;
            }
            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) {
                // Bo qua ::class, new class
                $j = $i + 1;
                while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
                if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    $class = $toks[$j][1];
                    $classLine = $toks[$j][2];
                    $out[] = ['name' => $class, 'type' => $id === T_CLASS ? 'CLASS' : 'SERVICE',
                        'line' => $line, 'parent' => '', 'signature' => $lastDoc];
                }
                $lastDoc = '';
                continue;
            }
            if ($id === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
                if ($j < $n && $toks[$j] === '&') $j++;
                while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
                if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    // Signature ngan: lay toi )
                    $sig = '';
                    $k = $j;
                    $depth = 0;
                    while ($k < $n && count(explode(' ', $sig)) < 12) {
                        $tk = $toks[$k];
                        $s = is_array($tk) ? $tk[1] : $tk;
                        $sig .= $s;
                        if ($s === '(') $depth++;
                        if ($s === ')') {
                            break;
                        }
                        $k++;
                        if ($k - $j > 40) break;
                    }
                    $out[] = ['name' => $toks[$j][1],
                        'type' => $class !== '' ? 'METHOD' : 'FUNCTION',
                        'line' => $toks[$j][2], 'parent' => $class,
                        'signature' => mb_substr(preg_replace('/\s+/', ' ', $sig), 0, 300)];
                }
                $lastDoc = '';
                continue;
            }
            if ($id === T_STRING && $text === '') $lastDoc = '';
        }
        // Routes: api case '...' + CommandRegistry commands kho phan biet tinh — lay case labels
        if (preg_match_all('/case\s+[\'"]([a-z0-9_]+)[\'"]\s*:/i', $src, $m)) {
            foreach (array_unique($m[1]) as $route) {
                $out[] = ['name' => strtolower($route), 'type' => 'ROUTE', 'line' => 0,
                    'parent' => '', 'signature' => 'api action'];
            }
        }
        // Events: AppEvent::XXX
        if (preg_match_all('/AppEvent::([A-Z_]{3,})/', $src, $m)) {
            foreach (array_unique($m[1]) as $ev) {
                $out[] = ['name' => $ev, 'type' => 'EVENT', 'line' => 0, 'parent' => '', 'signature' => 'event'];
            }
        }
        return $out;
    }

    private static function parseJs(string $src, string $rel): array
    {
        $out = [];
        if (preg_match_all('/^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/m', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $f) {
                $line = substr_count(substr($src, 0, $f[1]), "\n") + 1;
                $out[] = ['name' => $f[0], 'type' => 'FUNCTION', 'line' => $line, 'parent' => '', 'signature' => ''];
            }
        }
        if (preg_match_all('/^\s*class\s+([A-Za-z_$][\w$]*)/m', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $f) {
                $line = substr_count(substr($src, 0, $f[1]), "\n") + 1;
                $out[] = ['name' => $f[0], 'type' => 'COMPONENT', 'line' => $line, 'parent' => '', 'signature' => ''];
            }
        }
        return $out;
    }

    private static function storeSymbols(int $projectId, string $rel, array $syms): void
    {
        try {
            db()->prepare('DELETE FROM project_symbols WHERE project_id=? AND file_path=?')
                ->execute([$projectId, $rel]);
            if (!$syms) return;
            $st = db()->prepare('INSERT INTO project_symbols (project_id, symbol_name, symbol_type,
                    file_path, start_line, parent_symbol, module, language, signature)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $lang = strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'js' ? 'js' : 'php';
            foreach ($syms as $s) {
                $st->execute([$projectId, mb_substr((string)$s['name'], 0, 190),
                    (string)$s['type'], $rel, (int)($s['line'] ?? 0),
                    mb_substr((string)($s['parent'] ?? ''), 0, 190),
                    self::moduleOf($rel, (string)$s['name']), $lang,
                    mb_substr((string)($s['signature'] ?? ''), 0, 500)]);
            }
        } catch (Throwable $e) {
        }
    }

    /** @return array[] symbols match (LIKE + exact truoc) */
    public static function search(int $projectId, string $q, int $limit = 20, ?string $module = null): array
    {
        self::ensureTables();
        $limit = max(1, min(50, $limit));
        try {
            $w = 'project_id=? AND LOWER(symbol_name) LIKE LOWER(?)';
            $p = [$projectId, '%' . mb_substr($q, 0, 120) . '%'];
            if ($module !== null && $module !== '') {
                $w .= ' AND module=?';
                $p[] = $module;
            }
            $st = db()->prepare("SELECT * FROM project_symbols WHERE $w
                ORDER BY (symbol_name=?) DESC, LENGTH(symbol_name) ASC LIMIT $limit");
            $p[] = $q;
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Related: callers (file khac nhac symbol) + callees (Class:: trong file cua symbol)
     * + cung module. @return array{callers, callees, siblings}
     */
    public static function related(int $projectId, string $symbol, int $limit = 15): array
    {
        self::ensureTables();
        $out = ['callers' => [], 'callees' => [], 'siblings' => []];
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get($projectId);
            $root = $p ? realpath((string)$p['root_path']) : false;
            $st = db()->prepare('SELECT * FROM project_symbols WHERE project_id=? AND symbol_name=? LIMIT 5');
            $st->execute([$projectId, $symbol]);
            $defs = $st->fetchAll();
            if (!$defs) return $out;
            $def = $defs[0];
            // Callers: symbols trong file khac co ten file? Dung LIKE tren ten file? Chuan:
            // tim file chua chuoi symbol (gioi han 200 file da index)
            $st2 = db()->prepare('SELECT DISTINCT file_path FROM project_symbols WHERE project_id=? LIMIT 500');
            $st2->execute([$projectId]);
            $files = array_column($st2->fetchAll(), 'file_path');
            $callers = [];
            if ($root !== false) {
                foreach ($files as $rel) {
                    if ($rel === $def['file_path']) continue;
                    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                    $src = (string)@file_get_contents($full);
                    if ($src !== '' && strlen($src) < 500000 && str_contains($src, $symbol)) {
                        $callers[] = $rel;
                        if (count($callers) >= $limit) break;
                    }
                }
            }
            $out['callers'] = $callers;
            // Callees: Class::... trong file dinh nghia
            $callees = [];
            if ($root !== false) {
                $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$def['file_path']);
                $src = (string)@file_get_contents($full);
                if ($src !== '' && preg_match_all('/\b([A-Z][A-Za-z0-9_\\\\]+)::/', $src, $m)) {
                    foreach (array_unique($m[1]) as $c) {
                        $c = ltrim($c, '\\');
                        if ($c !== $symbol) $callees[] = $c;
                        if (count($callees) >= $limit) break;
                    }
                }
            }
            $out['callees'] = $callees;
            // Siblings: cung module, class/service
            $st3 = db()->prepare("SELECT symbol_name, file_path FROM project_symbols
                WHERE project_id=? AND module=? AND symbol_type IN ('CLASS','SERVICE')
                AND symbol_name<>? ORDER BY symbol_name LIMIT $limit");
            $st3->execute([$projectId, (string)$def['module'], $symbol]);
            $out['siblings'] = $st3->fetchAll();
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Module map: dem files/services/symbols theo module. @return array[] */
    public static function moduleMap(int $projectId): array
    {
        self::ensureTables();
        $out = [];
        try {
            $rows = db()->query("SELECT module, COUNT(DISTINCT file_path) files, COUNT(*) syms,
                    SUM(symbol_type IN ('CLASS','SERVICE')) svcs
                FROM project_symbols WHERE project_id=$projectId GROUP BY module ORDER BY syms DESC")->fetchAll();
            foreach ($rows as $r) {
                $out[] = ['module' => $r['module'], 'files' => (int)$r['files'],
                    'services' => (int)$r['svcs'], 'symbols' => (int)$r['syms']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function state(int $projectId): array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM project_index_state WHERE project_id=?');
            $st->execute([$projectId]);
            return $st->fetch() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Git awareness: branch/HEAD/dirty hien tai. */
    public static function gitState(int $projectId): array
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            require_once __DIR__ . '/DevJobManager.php';
            $p = DevProjectRegistry::get($projectId);
            if (!$p) return [];
            $root = realpath((string)$p['root_path']);
            if ($root === false) return [];
            $br = DevJobManager::git($root, ['branch', '--show-current']);
            $dirty = DevJobManager::git($root, ['status', '--porcelain', '--untracked-files=no']);
            return ['branch' => trim((string)($br['out'] ?? '')),
                'head' => DevJobManager::headCommit($root),
                'dirty' => trim((string)($dirty['out'] ?? '')) !== ''];
        } catch (Throwable $e) {
            return [];
        }
    }
}
