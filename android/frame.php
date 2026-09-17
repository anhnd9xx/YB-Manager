<?php
declare(strict_types=1);
$id = (string)($_GET['id'] ?? '');
$key = preg_replace('/[^A-Za-z0-9_]/', '', $id);
if (preg_match('/^\d+$/', $key)) $key = 'ch' . $key;
if ($key === '' || !preg_match('/^ch\d+$/', $key)) { http_response_code(400); exit; }
$f = __DIR__ . '/frames/' . $key . '/frame.jpg';
if (!is_file($f)) { http_response_code(404); header('Content-Length: 0'); exit; }
$mt = (int)@filemtime($f);
$ims = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
if ($ims !== '' && strtotime($ims) >= $mt) { http_response_code(304); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($f));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mt) . ' GMT');
readfile($f);