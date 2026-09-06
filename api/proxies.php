<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'list':
            $proxies = db()->query('SELECT * FROM proxies ORDER BY id DESC')->fetchAll();
            json_out(['ok' => true, 'data' => $proxies]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM proxies WHERE id = ?');
            $stmt->execute([$id]);
            $proxy = $stmt->fetch();
            $proxy ? json_out(['ok' => true, 'data' => $proxy])
                   : json_out(['ok' => false, 'message' => 'Proxy khong ton tai'], 404);
            break;

        case 'add':
            $b = json_body();
            if (empty($b['host']) || empty($b['port'])) {
                json_out(['ok' => false, 'message' => 'Thieu host hoac port'], 400);
            }
            $stmt = db()->prepare('INSERT INTO proxies (name, host, port, username, password, protocol, country)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $b['name'] ?? ($b['host'] . ':' . $b['port']),
                trim($b['host']),
                (int)$b['port'],
                $b['username'] ?? null,
                $b['password'] ?? null,
                $b['protocol'] ?? 'http',
                strtoupper($b['country'] ?? ''),
            ]);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
            break;

        case 'update':
            $b = json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $stmt = db()->prepare('UPDATE proxies SET name=?, host=?, port=?, username=?, password=?, protocol=?, country=?
                                   WHERE id=?');
            $stmt->execute([
                $b['name'] ?? '',
                trim($b['host'] ?? ''),
                (int)($b['port'] ?? 0),
                $b['username'] ?? null,
                $b['password'] ?? null,
                $b['protocol'] ?? 'http',
                strtoupper($b['country'] ?? ''),
                $id,
            ]);
            json_out(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? (json_body()['id'] ?? 0));
            $stmt = db()->prepare('DELETE FROM proxies WHERE id = ?');
            $stmt->execute([$id]);
            json_out(['ok' => true]);
            break;

        case 'import':
            $b = json_body();
            $lines = preg_split('/\r?\n/', trim($b['list'] ?? ''));
            $added = 0;
            $stmt = db()->prepare('INSERT INTO proxies (name, host, port, username, password, protocol, country)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $proxy = parse_proxy_string($line);
                if (!$proxy) continue;
                $stmt->execute([
                    $proxy['name'],
                    $proxy['host'],
                    $proxy['port'],
                    $proxy['username'],
                    $proxy['password'],
                    $proxy['protocol'],
                    $proxy['country'],
                ]);
                $added++;
            }
            json_out(['ok' => true, 'added' => $added]);
            break;

        case 'import_test':
            $b = json_body();
            $lines = preg_split('/\r?\n/', trim($b['list'] ?? ''));
            $results = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $proxy = parse_proxy_string($line);
                if (!$proxy) continue;
                $results[] = ['text' => $line, 'result' => test_proxy($proxy)] ;
            }
            json_out(['ok' => true, 'data' => $results]);
            break;

        case 'test':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM proxies WHERE id = ?');
            $stmt->execute([$id]);
            $proxy = $stmt->fetch();
            if (!$proxy) json_out(['ok' => false, 'message' => 'Khong tim thay proxy'], 404);
            $alive = test_proxy($proxy);
            db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
                ->execute([$alive ? 'alive' : 'dead', $id]);
            json_out(['ok' => true, 'alive' => $alive, 'status' => $alive ? 'alive' : 'dead']);
            break;

        case 'test_all':
            $proxies = db()->query('SELECT * FROM proxies')->fetchAll();
            $update = db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?');
            $aliveCount = 0;
            foreach ($proxies as $p) {
                $alive = test_proxy($p);
                if ($alive) $aliveCount++;
                $update->execute([$alive ? 'alive' : 'dead', $p['id']]);
            }
            json_out(['ok' => true, 'total' => count($proxies), 'alive' => $aliveCount]);
            break;

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
