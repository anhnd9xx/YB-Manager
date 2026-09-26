# DEV RULES — YT Manager (AI Dev Agent bắt buộc đọc mỗi Dev Job)

## Kiến trúc

- PHP 8 + MySQL (XAMPP Windows). Web UI vanilla JS (`assets/js/*.js`), API (`api/*.php`),
  services (`sync/*.php`), workers PHP-CLI (`bin/*`).
- `index.php` routes views; `config.php` chứa DB + helpers dùng chung.
- Mọi module giao tiếp qua `EventBus`; notification qua `NotificationManager` + rules.

## Quy tắc bắt buộc

- Không one-thread-per-profile; không global Chrome state.
- Telegram chỉ 1 polling consumer (`bin/telegram_polling.php` + queue/dispatcher).
- Window positioning chỉ qua `WindowPlacementManager`.
- Long operations phải dùng `JobManager` (progress thật từ completed/total).
- Secrets (bot token, proxy password, API key, cookie) không log, không đưa vào prompt thừa.
- PHP: `php -l` mọi file đổi trước khi xong; giữ UTF-8, giữ style hiện có.
- Không sửa file ngoài worktree được giao; không commit (Tool commit sau approve).
- DB migration: tách file `database/migration_*.sql`, idempotent, không migrate destructive
  nếu chưa có backup.
- Không install dependency lạ nếu chưa được yêu cầu rõ.
- Mọi thay đổi auth/Telegram-security/startup-scripts/delete-files là HIGH-RISK,
  phải nêu rõ trong báo cáo.

## Test

- Chỉ chạy test trong allowlist của project (`dev_projects.test_commands`).
- Báo cáo structured: passed/failed, lint, files changed. Không "seems okay".
- Test fail => KHÔNG apply.

## Telegram/AI

- Telegram text không bao giờ là shell. Mọi action qua intent/service/job đã đăng ký.
- Role: VIEWER (Q&A), OPERATOR (tool commands), DEVELOPER (plan/dev), ADMIN (approve/apply).
