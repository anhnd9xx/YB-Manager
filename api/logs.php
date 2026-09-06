<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$limit = (int)($_GET['limit'] ?? 50);
$limit = max(1, min(500, $limit));

try {
    $logs = db()->query(
        'SELECT l.id, l.action, l.detail, l.created_at, p.name AS profile_name
         FROM activity_logs l
         LEFT JOIN profiles p ON p.id = l.profile_id
         ORDER BY l.id DESC
         LIMIT ' . $limit
    )->fetchAll();
    json_out(['ok' => true, 'data' => $logs]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}