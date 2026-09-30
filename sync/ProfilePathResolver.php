<?php
declare(strict_types=1);
/**
 * ProfilePathResolver - Resolve user_data_dir portable cross-PC.
 * DB co the luu absolute path may cu (o dia khac). Resolve:
 *  1. stored path ton tai -> dung
 *  2. <project>/profiles/<safe_name> hoac basename variants -> dung + migrate DB
 *  3. khong thay -> giu stored + diagnostics (fail early o preflight)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class ProfilePathResolver
{
    /** @return array{path:string, migrated:bool, exists:bool} */
    public static function resolve(array $profile): array
    {
        $stored = trim((string)($profile['user_data_dir'] ?? ''));
        if ($stored !== '' && is_dir($stored)) {
            return ['path' => $stored, 'migrated' => false, 'exists' => true];
        }
        $cands = [];
        try {
            $base = PROFILES_DIR;
            $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($profile['name'] ?? ''));
            if ($safe === '' || $safe === null) $safe = 'profile';
            $cands[] = $base . DIRECTORY_SEPARATOR . $safe;
            if ($stored !== '') $cands[] = $base . DIRECTORY_SEPARATOR . basename($stored);
            // Chrome profile marker: Preferences hoac Default/
            foreach ($cands as $c) {
                if (is_dir($c) && (is_file($c . DIRECTORY_SEPARATOR . 'Preferences')
                    || is_dir($c . DIRECTORY_SEPARATOR . 'Default'))) {
                    self::migrate((int)($profile['id'] ?? 0), $c, $stored);
                    return ['path' => $c, 'migrated' => true, 'exists' => true];
                }
            }
            // Thu muc ton tai nhung chua co marker (profile moi) van chap nhan
            foreach ($cands as $c) {
                if (is_dir($c)) {
                    self::migrate((int)($profile['id'] ?? 0), $c, $stored);
                    return ['path' => $c, 'migrated' => true, 'exists' => true];
                }
            }
        } catch (Throwable $e) {
        }
        return ['path' => $stored, 'migrated' => false, 'exists' => false];
    }

    private static function migrate(int $id, string $newDir, string $oldDir): void
    {
        if ($id <= 0 || $newDir === $oldDir) return;
        try {
            db()->prepare('UPDATE profiles SET user_data_dir=? WHERE id=?')->execute([$newDir, $id]);
            SyncLogger::info('placement', '[PATH MIGRATE] profile=#' . $id
                . ' stored=' . $oldDir . ' resolved=' . $newDir, $id);
        } catch (Throwable $e) {
        }
    }

    /** Bao cao nhanh cho deploy check (khong migrate). */
    public static function diagnose(array $profile): array
    {
        $stored = trim((string)($profile['user_data_dir'] ?? ''));
        $r = self::resolve($profile);
        // resolve() co the da migrate; diagnose phan biet bang stored ban dau
        return ['stored_path' => $stored, 'resolved_path' => $r['path'],
            'exists' => $r['exists'], 'migrated' => $r['migrated']];
    }
}
