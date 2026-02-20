<?php
require __DIR__ . '/config.php';

// Optional token protection
if (CRON_TOKEN !== '') {
    $t = (string)($_GET['token'] ?? '');
    if (!hash_equals(CRON_TOKEN, $t)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

ensure_dirs();

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->query(
        "SELECT id, product_id
         FROM orders
         WHERE status = 'pending'
           AND created_at < (NOW() - INTERVAL 24 HOUR)
           AND stock_released = 0
         FOR UPDATE"
    );
    $rows = $st->fetchAll();

    if (!$rows) {
        $pdo->commit();
        header('Content-Type: text/plain; charset=utf-8');
        echo "OK: 0 pending orders expired.\n";
        exit;
    }

    $ids = [];
    $counts = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['id'];
        $pid = (int)$r['product_id'];
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare(
        "UPDATE orders
         SET status='cancelled',
             cancelled_at=NOW(),
             updated_at=NOW(),
             stock_released=1
         WHERE id IN ($placeholders)"
    )->execute($ids);

    $upd = $pdo->prepare('UPDATE products SET stock = stock + ?, updated_at=NOW() WHERE id = ?');
    foreach ($counts as $pid => $c) {
        $upd->execute([(int)$c, (int)$pid]);
    }

    $pdo->commit();

    header('Content-Type: text/plain; charset=utf-8');
    echo "OK: " . count($ids) . " pending orders expired.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage() . "\n";
}

