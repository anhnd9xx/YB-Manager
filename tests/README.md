# Auto Activity tests

Chạy toàn bộ:

```
php tests/run.php
```

Hoặc từng phần:

```
php tests/backend/test_bulk_assign.php
php tests/backend/test_scheduler.php
node tests/frontend/run.js
```

## Quy ước

- Frontend: Node thuần (không thêm framework), stub DOM trong `tests/frontend/harness.js`.
  Test thật `assets/js/app.js` (không copy logic).
- Backend: PHP CLI, database `yt_manager` thật, fixture profiles id 9001+,
  tự dọn sau khi chạy (finally).
- Không mock layer nghiệp vụ — assert DB/repository thật.

## Bao phủ

- `bulk_assign.test.js`: snapshot regression (PHASE 12), count từ IDs (13),
  zero target (14), double submit (15), old-impl FAIL proof.
- `id_resolver.test.js`: resolveProfileId (PHASE 11).
- `test_bulk_assign.php`: create/update/idempotent/invalid/disable/persist (16-20).
- `test_scheduler.php`: plan spread, SKIP chrome stopped, missed window,
  boot recovery, next_run, tick, daily summary (21-26, 31-33).
