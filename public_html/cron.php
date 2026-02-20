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
        $stO = db()->prepare("SELECT id,user_id,wallet_used,status FROM orders WHERE id=? FOR UPDATE");
        $stO->execute([$oid]);
        $o = $stO->fetch();
        if (!$o || (string)$o['status'] !== 'pending') {
            db()->rollBack();
            continue;
        }

        $walletUsed = (float)$o['wallet_used'];

        if ($walletUsed > 0) {
            $upW = db()->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?");
            $upW->execute([money_fmt($walletUsed), (int)$o['user_id']]);
        }

        $stIt = db()->prepare("SELECT product_id,variant_id,product_type,qty FROM order_items WHERE order_id=? FOR UPDATE");
        $stIt->execute([$oid]);
        $items = $stIt->fetchAll();
        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $vid = (int)$it['variant_id'];
            $ptype = (string)$it['product_type'];
            $qty = (int)$it['qty'];
            if ($ptype === 'account') {
                db()->prepare("UPDATE products SET stock=stock+1 WHERE id=?")->execute([$pid]);
                if ($vid > 0) db()->prepare("UPDATE product_variants SET stock=stock+1 WHERE id=?")->execute([$vid]);
            } else {
                if ($vid > 0) db()->prepare("UPDATE product_variants SET stock=stock+? WHERE id=?")->execute([$qty, $vid]);
            }
        }

        $up = db()->prepare("UPDATE orders SET status='cancelled', cancelled_at=NOW(), cancel_reason='Expired (24h)' WHERE id=?");
        $up->execute([$oid]);

        db()->commit();
        $cancelled++;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
    }
}

echo "Cancelled: {$cancelled}\n";

// ---- Screenshot cleanup (older than ~2 months) ----
function tg_send_document(string $path, string $caption = ''): bool
{
    $token = trim(setting_get('telegram_bot_token', ''));
    $chatId = trim(setting_get('telegram_chat_id', ''));
    if ($token === '' || $chatId === '') return false;
    if (!function_exists('curl_init')) return false;
    if (!is_file($path)) return false;

    $url = "https://api.telegram.org/bot{$token}/sendDocument";
    $post = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => new CURLFile($path),
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    $ok = ($res !== false);
    curl_close($ch);
    return $ok;
}

$maxFiles = 40;
$oldOrderShots = db()->prepare("SELECT id,screenshot_path FROM orders WHERE screenshot_path IS NOT NULL AND screenshot_deleted_at IS NULL AND created_at < (NOW() - INTERVAL 2 MONTH) ORDER BY id ASC LIMIT {$maxFiles}");
$oldOrderShots->execute();
$orderShots = $oldOrderShots->fetchAll();

$oldTopupShots = db()->prepare("SELECT id,screenshot_path FROM wallet_topups WHERE screenshot_path IS NOT NULL AND screenshot_deleted_at IS NULL AND created_at < (NOW() - INTERVAL 2 MONTH) ORDER BY id ASC LIMIT {$maxFiles}");
$oldTopupShots->execute();
$topupShots = $oldTopupShots->fetchAll();

$totalShots = count($orderShots) + count($topupShots);
$archived = 0;
$deleted = 0;

if ($totalShots > 0 && class_exists('ZipArchive')) {
    $archivesDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'archives';
    ensure_dir($archivesDir);
    $zipPath = $archivesDir . DIRECTORY_SEPARATOR . 'proofs_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
        foreach ($orderShots as $r) {
            $rel = (string)$r['screenshot_path'];
            $abs = __DIR__ . '/' . $rel;
            if (is_file($abs)) {
                $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                $zip->addFile($abs, 'orders/order_' . (int)$r['id'] . ($ext ? ('.' . $ext) : ''));
                $archived++;
            }
        }
        foreach ($topupShots as $r) {
            $rel = (string)$r['screenshot_path'];
            $abs = __DIR__ . '/' . $rel;
            if (is_file($abs)) {
                $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                $zip->addFile($abs, 'topups/topup_' . (int)$r['id'] . ($ext ? ('.' . $ext) : ''));
                $archived++;
            }
        }
        $zip->close();

        $sent = tg_send_document($zipPath, 'ZENTRAXX STORE: old payment proofs backup (auto)');
        // Even if Telegram isn't configured, still delete originals after archiving zip locally.

        db()->beginTransaction();
        try {
            foreach ($orderShots as $r) {
                $rel = (string)$r['screenshot_path'];
                $abs = __DIR__ . '/' . $rel;
                if (is_file($abs)) @unlink($abs);
                db()->prepare("UPDATE orders SET screenshot_deleted_at=NOW(), screenshot_path=NULL WHERE id=?")->execute([(int)$r['id']]);
                $deleted++;
            }
            foreach ($topupShots as $r) {
                $rel = (string)$r['screenshot_path'];
                $abs = __DIR__ . '/' . $rel;
                if (is_file($abs)) @unlink($abs);
                db()->prepare("UPDATE wallet_topups SET screenshot_deleted_at=NOW(), screenshot_path=NULL WHERE id=?")->execute([(int)$r['id']]);
                $deleted++;
            }
            db()->commit();
        } catch (Throwable) {
            if (db()->inTransaction()) db()->rollBack();
        }

        echo "Archived proofs zip: " . basename($zipPath) . " (telegram=" . ($sent ? "sent" : "skip") . ")\n";
    }
}

echo "Proofs found: {$totalShots}, archived: {$archived}, deleted: {$deleted}\n";

// Cleanup expired password reset tokens
try {
    db()->exec("DELETE FROM password_resets WHERE (used_at IS NOT NULL) OR (expires_at < (NOW() - INTERVAL 1 DAY))");
} catch (Throwable) {
    // ignore
}

