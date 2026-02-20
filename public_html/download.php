<?php
require_once __DIR__ . '/config.php';

$u = require_login();
$oid = (int)($_GET['order_id'] ?? 0);
if ($oid <= 0) {
    http_response_code(400);
    echo "Missing order_id";
    exit;
}

$st = db()->prepare("SELECT o.id,o.user_id,o.status,p.download_file,p.name AS product_name
                     FROM orders o
                     JOIN products p ON p.id=o.product_id
                     WHERE o.id=? AND o.user_id=? LIMIT 1");
$st->execute([$oid, $u['id']]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    echo "Order not found.";
    exit;
}
if ((string)$row['status'] !== 'completed') {
    http_response_code(403);
    echo "Order not completed.";
    exit;
}
$file = (string)($row['download_file'] ?? '');
if ($file === '') {
    http_response_code(404);
    echo "No download file configured.";
    exit;
}

$file = basename($file);
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,250}$/', $file)) {
    http_response_code(400);
    echo "Invalid file name.";
    exit;
}

ensure_dir(FILES_DIR);
$path = rtrim(FILES_DIR, '/\\') . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo "File not found on server. Ask admin to upload it into /files.";
    exit;
}

// Serve file
if (ob_get_level()) {
    @ob_end_clean();
}
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
$mime = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;

