<?php
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (CRON_TOKEN !== '') {
    $t = (string)($_GET['token'] ?? '');
    if (!hash_equals(CRON_TOKEN, $t)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$limit = 200;
$st = db()->prepare("SELECT id FROM orders WHERE status='pending' AND created_at < (NOW() - INTERVAL 24 HOUR) ORDER BY id ASC LIMIT {$limit}");
$st->execute();
$ids = $st->fetchAll();

$cancelled = 0;
foreach ($ids as $r) {
    $oid = (int)$r['id'];
    try {
        db()->beginTransaction();
        $stO = db()->prepare("SELECT id,user_id,product_id,qty,wallet_used,status FROM orders WHERE id=? FOR UPDATE");
        $stO->execute([$oid]);
        $o = $stO->fetch();
        if (!$o || (string)$o['status'] !== 'pending') {
            db()->rollBack();
            continue;
        }

        $qty = (int)$o['qty'];
        $walletUsed = (float)$o['wallet_used'];

        $up = db()->prepare("UPDATE orders SET status='cancelled', cancelled_at=NOW() WHERE id=?");
        $up->execute([$oid]);

        if ($walletUsed > 0) {
            $upW = db()->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?");
            $upW->execute([money_fmt($walletUsed), (int)$o['user_id']]);
        }

        $upS = db()->prepare("UPDATE products SET stock=stock+? WHERE id=?");
        $upS->execute([$qty, (int)$o['product_id']]);

        db()->commit();
        $cancelled++;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
    }
}

echo "Cancelled: {$cancelled}\n";

