# YT Manager

Quản lý nhiều kênh Chrome (profile riêng biệt) cho YouTube. Mỗi kênh có proxy riêng,
bảng điều khiển web, kiểm tra trạng thái và CLI/chế độ Console để chạy các kênh thật.

## Tính năng

- Quản lý kênh: mỗi kênh = 1 Chrome profile riêng (`profiles/`), user-data-dir duy nhất.
- Gán proxy cho từng kênh, kiểm tra proxy alive / xem kênh đang chạy cổng debug nào.
- Mở / đóng / mở lại kênh qua web (`api/browser.php`) dùng chung code với `api/sync.php`.
- Cơ chế **proxy relay** để Chrome gắn proxy có username/password hoạt động đầy đủ (xem bên dưới).
- Bảng activity log, cài đặt (đường dẫn Chrome, timeout...), thống kê.

## Yêu cầu

- XAMPP (PHP 8+ / MySQL) trên Windows, chạy **console mode** (Apache + MySQL).
- Google Chrome (mặc định: `C:\Program Files\Google\Chrome\Application\chrome.exe`).
- Gốc web: `http://localhost/yt-manager/`.

## Cài đặt

1. Copy thư mục dự án vào `C:\xampp\htdocs\yt-manager`.
2. Tạo database `yt_manager` rồi import `database/db.sql`.
   - Nếu đã có CSDL cũ, chạy lần lượt các file `database/migration_*.sql`.
3. Cấu hình kết nối DB ở đầu `config.php` (mặc định `root` / rỗng ở `127.0.0.1`).
4. Bật Apache + MySQL trong XAMPP Console, mở `http://localhost/yt-manager/`.

## Cấu trúc

```
api/            browser.php, profiles.php, proxies.php, settings.php, sync.php, logs.php
assets/         css, js giao diện
database/       db.sql + các migration
proxy_relay.php relay local (proxy forward) — core của fix proxy cho Chrome
config.php      kết nối DB, tiện ích + logic mở/đóng Chrome & relay
index.php       giao diện
profiles/       (gitignore) Chrome user-data-dir của các kênh
```

## Vì sao có proxy_relay.php?

Chrome 152 trở đi bỏ `Proxy-Authorization` cho subresource/CONNECT khi credentials
nhúng thẳng vào `--proxy-server=http://user:pass@host:port`. Kết quả: trang vẫn nạp
nhưng **mọi fetch/XHR trong trang fail**, nhìn như "mất internet".

Cách xử lý:

1. `launch_chrome()` khởi động `proxy_relay.php` cục bộ
   (`127.0.0.1`, port `9400 + proxy_id % 100`) — relay kết nối ra proxy thật có
   username/password và **tự thêm** `Proxy-Authorization` vào mọi yêu cầu.
2. Chrome được khởi động với `--proxy-server=http://127.0.0.1:<port>` (không kèm credentials).
3. Relay là một event-loop **multi-client** duy nhất — không nghẽn khi proxy chậm,
   hỗ trợ cả HTTP forward lẫn CONNECT (HTTPS) với connect không-blocking.
4. Khi đóng kênh, `kill_chrome_processes()` tự đóng relay nếu không kênh nào dùng nữa.

> Ghi chú tương thích: relay phải được sinh bằng **PowerShell Start-Process** (không
> dùng `proc_open`/`start /B` ngay dưới Apache) và ở chế độ web `PHP_BINARY` có thể là
> `httpd.exe` — code tự fallback về `C:/xampp/php/php.exe`.

## Bảo mật

- Credentials proxy (fileName `password` proxy) lấy từ DB, không ghi vào profile/log.
- Thư mục `profiles/` chứa cookie/đăng nhập cá nhân — đã loại khỏi git.