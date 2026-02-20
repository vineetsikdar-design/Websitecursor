<?php
require_once __DIR__ . '/config.php';
require_login($pdo);

$user = current_user($pdo);
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    set_flash('Invalid download request.', 'error');
    redirect('index.php');
}

$stmt = $pdo->prepare('
    SELECT o.id, o.status, p.title, p.download_link
    FROM orders o
    LEFT JOIN products p ON p.id = o.product_id
    WHERE o.id = ? AND o.user_id = ?
    LIMIT 1
');
$stmt->execute([$orderId, (int) $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('Order not found.', 'error');
    redirect('index.php');
}

if ($order['status'] !== 'completed') {
    set_flash('Download is locked until order is completed.', 'warning');
    redirect('index.php');
}

$link = trim((string) ($order['download_link'] ?? ''));
if ($link === '') {
    set_flash('No download link found for this product. Contact admin.', 'warning');
    redirect('index.php');
}

if (filter_var($link, FILTER_VALIDATE_URL)) {
    header('Location: ' . $link);
    exit;
}

$relativePath = ltrim($link, '/');
if (strpos($relativePath, '..') !== false) {
    set_flash('Invalid download path.', 'error');
    redirect('index.php');
}

$absolutePath = __DIR__ . '/' . $relativePath;
if (!is_file($absolutePath)) {
    set_flash('Download file not found on server.', 'error');
    redirect('index.php');
}

$fileName = basename($absolutePath);
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($absolutePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');
readfile($absolutePath);
exit;
