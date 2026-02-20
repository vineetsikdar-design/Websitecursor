<?php
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->query("
        SELECT id, user_id, wallet_used, wallet_refunded, admin_note
        FROM orders
        WHERE status = 'pending'
          AND created_at < (NOW() - INTERVAL 24 HOUR)
        FOR UPDATE
    ");
    $expiredOrders = $stmt->fetchAll();

    $cancelCount = 0;
    $refundCount = 0;

    if ($expiredOrders) {
        $refundStmt = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?');
        $cancelStmt = $pdo->prepare('
            UPDATE orders
            SET status = ?, wallet_refunded = ?, admin_note = ?
            WHERE id = ?
        ');

        foreach ($expiredOrders as $order) {
            $walletUsed = (float) $order['wallet_used'];
            $walletRefunded = (int) $order['wallet_refunded'];

            if ($walletUsed > 0 && $walletRefunded === 0) {
                $refundStmt->execute([money($walletUsed), (int) $order['user_id']]);
                $walletRefunded = 1;
                $refundCount++;
            }

            $existingNote = trim((string) ($order['admin_note'] ?? ''));
            $autoNote = 'Auto-cancelled after 24 hours by cron.';
            $newNote = $existingNote === '' ? $autoNote : ($existingNote . ' | ' . $autoNote);

            $cancelStmt->execute(['cancelled', $walletRefunded, $newNote, (int) $order['id']]);
            $cancelCount++;
        }
    }

    $pdo->commit();

    echo "ZENTRAXX CRON OK\n";
    echo 'Expired pending orders cancelled: ' . $cancelCount . "\n";
    echo 'Wallet refunds processed: ' . $refundCount . "\n";
    echo 'Run at: ' . date('Y-m-d H:i:s') . "\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo 'Cron failed: ' . $e->getMessage() . "\n";
}
