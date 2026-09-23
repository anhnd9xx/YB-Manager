<?php
declare(strict_types=1);
/**
 * CommandRegistry - Registry lenh generic (§10, §33, §46).
 * Module dang ky: name, module, required_role, confirmation, destructive, enabled,
 * description, aliases, args_hint. Khong sua Telegram core khi them module.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PermissionService.php';

class CommandRegistry
{
    /** @var array<string,array>|null */
    private static ?array $commands = null;

    public static function register(array $def): void
    {
        self::all();
        $name = strtolower(trim((string)($def['name'] ?? '')));
        if ($name === '') return;
        self::$commands[$name] = [
            'name' => $name,
            'module' => strtoupper(trim((string)($def['module'] ?? 'SYSTEM'))),
            'required_role' => strtoupper(trim((string)($def['required_role'] ?? PermissionService::VIEWER))),
            'confirmation' => !empty($def['confirmation']),
            'destructive' => !empty($def['destructive']),
            'enabled' => !array_key_exists('enabled', $def) || !empty($def['enabled']),
            'description' => (string)($def['description'] ?? ''),
            'aliases' => array_values(array_unique(array_map(fn($a) => strtolower(trim((string)$a)),
                (array)($def['aliases'] ?? [])))),
            'args_hint' => (string)($def['args_hint'] ?? ''),
            'handler' => $def['handler'] ?? null, // callable(CommandRequest): CommandResult
        ];
    }

    /** @return array<string,array> */
    public static function all(): array
    {
        if (self::$commands === null) {
            self::$commands = [];
            self::registerCore();
        }
        return self::$commands;
    }

    public static function find(string $name): ?array
    {
        $name = strtolower(trim(ltrim(trim($name), '/')));
        $all = self::all();
        if (isset($all[$name])) return $all[$name];
        foreach ($all as $cmd) {
            if (in_array($name, $cmd['aliases'], true)) return $cmd;
        }
        return null;
    }

    /** @return array<string,array> theo role (help filter) */
    public static function forRole(string $role): array
    {
        $out = [];
        foreach (self::all() as $name => $cmd) {
            if (empty($cmd['enabled'])) continue;
            if (!PermissionService::can($role, $cmd['required_role'])) continue;
            $out[$name] = $cmd;
        }
        return $out;
    }

    private static function registerCore(): void
    {
        $H = TelegramCommands::class;
        $defs = [
            ['name' => 'help', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Danh sách lệnh', 'handler' => [$H, 'help']],
            ['name' => 'status', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Trạng thái tool', 'aliases' => ['trangthai'],
                'handler' => [$H, 'status']],
            ['name' => 'jobs', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Jobs đang chạy', 'aliases' => ['congviec'],
                'handler' => [$H, 'jobs']],
            ['name' => 'job', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Chi tiết job', 'args_hint' => '<id>',
                'handler' => [$H, 'job']],
            ['name' => 'channel', 'module' => 'CHANNEL', 'required_role' => 'VIEWER',
                'description' => 'Thông tin kênh', 'args_hint' => '<id>', 'aliases' => ['kenh'],
                'handler' => [$H, 'channel']],
            ['name' => 'alerts', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Cảnh báo đang mở', 'aliases' => ['canhbao'],
                'handler' => [$H, 'alerts']],
            ['name' => 'proxy.status', 'module' => 'PROXY', 'required_role' => 'VIEWER',
                'description' => 'Trạng thái proxy', 'aliases' => ['proxy'],
                'handler' => [$H, 'proxyStatus']],
            ['name' => 'evaluation.run', 'module' => 'EVALUATION', 'required_role' => 'OPERATOR',
                'description' => 'Đánh giá kênh', 'args_hint' => '<id|1-10|all>', 'aliases' => ['evaluate', 'danhgia'],
                'handler' => [$H, 'evalRun']],
            ['name' => 'browser.start', 'module' => 'BROWSER', 'required_role' => 'OPERATOR',
                'description' => 'Mở Chrome', 'args_hint' => '<id|1,3,5|all>', 'aliases' => ['start', 'mo'],
                'handler' => [$H, 'browserStart']],
            ['name' => 'browser.start_all', 'module' => 'BROWSER', 'required_role' => 'OPERATOR',
                'description' => 'Mở TẤT CẢ (xác nhận)', 'confirmation' => true, 'destructive' => true,
                'handler' => [$H, 'browserStartAll']],
            ['name' => 'browser.stop', 'module' => 'BROWSER', 'required_role' => 'OPERATOR',
                'description' => 'Đóng Chrome', 'args_hint' => '<id|all>', 'aliases' => ['stop', 'dong'],
                'confirmation' => false,
                'handler' => [$H, 'browserStop']],
            ['name' => 'browser.stop_all', 'module' => 'BROWSER', 'required_role' => 'OPERATOR',
                'description' => 'Đóng TẤT CẢ (xác nhận)', 'confirmation' => true, 'destructive' => true,
                'handler' => [$H, 'browserStopAll']],
            ['name' => 'activity.run', 'module' => 'AUTO_ACTIVITY', 'required_role' => 'OPERATOR',
                'description' => 'Chạy activity', 'args_hint' => '<id>', 'aliases' => ['activity'],
                'handler' => [$H, 'activityRun']],
            ['name' => 'pair', 'module' => 'SYSTEM', 'required_role' => 'VIEWER',
                'description' => 'Ghép nối chat', 'args_hint' => '<mã>',
                'handler' => [$H, 'pair']],
        ];
        foreach ($defs as $d) self::register($d);
    }
}
