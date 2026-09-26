<?php
declare(strict_types=1);
/**
 * DevProjectRegistry - Du an dev (hien tai: YT Manager la Primary).
 * Root security: canonical path, phai la dir co .git, khong cho Windows/home.
 */
require_once __DIR__ . '/../config.php';

class DevProjectRegistry
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS dev_projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL DEFAULT '',
                root_path VARCHAR(500) NOT NULL DEFAULT '',
                default_branch VARCHAR(80) NOT NULL DEFAULT 'master',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                opencode_config TEXT NULL,
                test_commands TEXT NULL,
                lint_commands TEXT NULL,
                build_commands TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dev_project_root (root_path(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
        // Seed primary project
        try {
            $n = (int)db()->query('SELECT COUNT(*) FROM dev_projects')->fetchColumn();
            if ($n === 0) {
                db()->prepare('INSERT INTO dev_projects (name, root_path, default_branch, enabled,
                        test_commands, lint_commands) VALUES (?,?,?,?,?,?)')
                    ->execute(['YT Manager', BASE_DIR, 'master', 1,
                        json_encode([['key' => 'php_lint', 'label' => 'PHP lint changed files']],
                            JSON_UNESCAPED_UNICODE),
                        json_encode([['key' => 'php_lint', 'label' => 'PHP lint']],
                            JSON_UNESCAPED_UNICODE)]);
            }
        } catch (Throwable $e) {
        }
    }

    /** @return array[] */
    public static function list(): array
    {
        self::ensureTable();
        try {
            return db()->query('SELECT * FROM dev_projects ORDER BY id')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function primary(): ?array
    {
        self::ensureTable();
        try {
            $r = db()->query('SELECT * FROM dev_projects WHERE enabled=1 ORDER BY id LIMIT 1')->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function get(int $id): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare('SELECT * FROM dev_projects WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Validate root: ton tai, co .git, khong phai Windows/home/arbitrary.
     * @return array{ok, error?, canonical?}
     */
    public static function validateRoot(string $path): array
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return ['ok' => false, 'error' => 'Thư mục không tồn tại'];
        }
        $low = strtolower(str_replace('/', '\\', $real));
        foreach (['c:\\windows', 'c:\\program files', 'c:\\program files (x86)'] as $ban) {
            if ($low === $ban || str_starts_with($low, $ban . '\\')) {
                return ['ok' => false, 'error' => 'Không được thao tác thư mục hệ thống'];
            }
        }
        $home = strtolower((string)getenv('USERPROFILE'));
        if ($home !== '' && ($low === $home)) {
            return ['ok' => false, 'error' => 'Không được thao tác toàn bộ user home'];
        }
        if (!is_dir($real . DIRECTORY_SEPARATOR . '.git')) {
            return ['ok' => false, 'error' => 'Project chưa có Git (cần Git để coding an toàn)'];
        }
        return ['ok' => true, 'canonical' => $real];
    }

    /** Path co nam trong project root (hoac worktree cua no)? */
    public static function contains(int $projectId, string $path): bool
    {
        $p = self::get($projectId);
        if (!$p) return false;
        $root = realpath((string)$p['root_path']);
        $real = realpath($path) ?: $path;
        if ($root === false) return false;
        $rl = strtolower(str_replace('/', '\\', $root));
        $pl = strtolower(str_replace('/', '\\', (string)$real));
        return $pl === $rl || str_starts_with($pl, $rl . '\\');
    }
}
