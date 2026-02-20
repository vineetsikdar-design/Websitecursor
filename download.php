<?php
require __DIR__ . '/config.php';

ensure_dirs();
$u = require_login();

// Admin: view payment screenshot
if (isset($_GET['shot'])) {
    $admin = require_admin();
    $oid = (int)$_GET['shot'];
    if ($oid <= 0) exit('Invalid.');

    $st = db()->prepare("SELECT screenshot_path FROM orders WHERE id = ? LIMIT 1");
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o || empty($o['screenshot_path'])) exit('Not found.');

    $path = (string)$o['screenshot_path'];
    if (!starts_with($path, 'uploads/')) exit('Invalid path.');
    $abs = __DIR__ . '/' . $path;
    if (!is_file($abs)) exit('File missing.');

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="screenshot_' . $oid . '.' . $ext . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

// User: download completed product
$oid = (int)($_GET['o'] ?? 0);
if ($oid <= 0) exit('Invalid.');

$st = db()->prepare(
    "SELECT o.id, o.status, o.user_id, p.file_path, p.file_name, p.name AS product_name
     FROM orders o
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?
     LIMIT 1"
);
$st->execute([$oid, (int)$u['id']]);
$row = $st->fetch();

if (!$row) exit('Not found.');
if ((string)$row['status'] !== 'completed') exit('Not available.');
if (empty($row['file_path'])) exit('No file attached to product.');

$path = (string)$row['file_path'];
if (!starts_with($path, 'files/')) exit('Invalid path.');
$abs = __DIR__ . '/' . $path;
if (!is_file($abs)) exit('File missing.');

$dlName = (string)($row['file_name'] ?: (basename($abs)));
$dlName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $dlName);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($abs));
header('X-Accel-Buffering: no');
readfile($abs);
exit;

