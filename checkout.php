<?php
require __DIR__ . '/config.php';

ensure_dirs();
$u = require_login();

// My Orders list
if (isset($_GET['my'])) {
    render_header('My Orders');

    $st = db()->prepare(
        "SELECT o.id, o.status, o.price, o.wallet_used, o.upi_amount, o.created_at, o.submitted_at, o.completed_at,
                p.name AS product_name
         FROM orders o
         JOIN products p ON p.id = o.product_id
         WHERE o.user_id = ?
         ORDER BY o.id DESC
         LIMIT 200"
    );
    $st->execute([(int)$u['id']]);
    $orders = $st->fetchAll();

    echo '<div class="glass card">';
    echo '<h2>My Orders</h2>';
    if (!$orders) {
        echo '<p class="muted">No orders yet. Go back to <a href="index.php">store</a>.</p>';
        echo '</div>';
        render_footer();
        exit;
    }

    echo '<div class="table-wrap">';
    echo '<table class="table">';
    echo '<thead><tr><th>#</th><th>Product</th><th>Status</th><th>Total</th><th>Wallet</th><th>UPI</th><th>Action</th></tr></thead>';
    echo '<tbody>';
    foreach ($orders as $o) {
        $id = (int)$o['id'];
        $status = (string)$o['status'];
        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . e((string)$o['product_name']) . '</td>';
        echo '<td><span class="status ' . e($status) . '">' . e($status) . '</span></td>';
        echo '<td>' . e(money($o['price'])) . '</td>';
        echo '<td>' . e(money($o['wallet_used'])) . '</td>';
        echo '<td>' . e(money($o['upi_amount'])) . '</td>';
        echo '<td>';
        if ($status === 'completed') {
            echo '<a class="btn small primary" href="download.php?o=' . $id . '">Download</a>';
        } elseif ($status === 'pending') {
            echo '<a class="btn small ghost" href="checkout.php?oid=' . $id . '">Pay now</a>';
        } else {
            echo '<span class="muted small">—</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
    render_footer();
    exit;
}

// Create a pending order (reserve stock)
if (isset($_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    if ($pid <= 0) {
        flash_set('error', 'Invalid product.');
        redirect('index.php');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, name, price, stock, is_visible FROM products WHERE id = ? FOR UPDATE');
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p || empty($p['is_visible'])) {
            throw new RuntimeException('Product not available.');
        }
        if ((int)$p['stock'] <= 0) {
            throw new RuntimeException('Out of stock.');
        }

        $pdo->prepare('UPDATE products SET stock = stock - 1, updated_at = NOW() WHERE id = ?')->execute([$pid]);
        $ins = $pdo->prepare("INSERT INTO orders (user_id, product_id, status, price, wallet_used, upi_amount, stock_released, wallet_refunded, created_at, updated_at)
                              VALUES (?, ?, 'pending', ?, 0, 0, 0, 0, NOW(), NOW())");
        $ins->execute([(int)$u['id'], $pid, (float)$p['price']]);
        $oid = (int)$pdo->lastInsertId();

        $pdo->commit();
        redirect('checkout.php?oid=' . $oid);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', $e->getMessage());
        redirect('index.php');
    }
}

$oid = (int)($_GET['oid'] ?? 0);
if ($oid <= 0) {
    redirect('index.php');
}

// Load order
$st = db()->prepare(
    "SELECT o.*, p.name AS product_name, p.description AS product_desc
     FROM orders o
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?
     LIMIT 1"
);
$st->execute([$oid, (int)$u['id']]);
$order = $st->fetch();
if (!$order) {
    flash_set('error', 'Order not found.');
    redirect('checkout.php?my=1');
}

function valid_utr(string $utr): bool {
    return (bool)preg_match('/^[A-Z0-9]{12,22}$/', $utr);
}

if (is_post()) {
    csrf_check();

    if ((string)$order['status'] !== 'pending') {
        flash_set('error', 'This order is not pending.');
        redirect('checkout.php?oid=' . $oid);
    }

    $walletUse = (string)($_POST['wallet_use'] ?? '0');
    $walletUse = str_replace(',', '.', $walletUse);
    $walletUseF = (float)$walletUse;
    if ($walletUseF < 0) $walletUseF = 0.0;

    $price = (float)$order['price'];
    if ($walletUseF > $price) {
        flash_set('error', 'Wallet usage cannot exceed order total.');
        redirect('checkout.php?oid=' . $oid);
    }

    $utr = strtoupper(trim((string)($_POST['utr'] ?? '')));
    $remaining = round($price - $walletUseF, 2);

    $needUpi = ($remaining > 0.0);
    if ($needUpi) {
        if ($utr === '' || !valid_utr($utr)) {
            flash_set('error', 'Enter a valid UTR (12–22 letters/numbers).');
            redirect('checkout.php?oid=' . $oid);
        }
        if (empty($_FILES['screenshot']) || (int)$_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Upload a UPI screenshot (JPG/PNG).');
            redirect('checkout.php?oid=' . $oid);
        }
        if ((int)$_FILES['screenshot']['size'] > MAX_SCREENSHOT_BYTES) {
            flash_set('error', 'Screenshot too large. Max 2MB.');
            redirect('checkout.php?oid=' . $oid);
        }
    } else {
        // Wallet fully covers it
        $utr = null;
    }

    $shotPath = null;
    $shotHash = null;
    if ($needUpi) {
        $tmp = (string)$_FILES['screenshot']['tmp_name'];
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
        } else {
            $info = @getimagesize($tmp);
            $mime = is_array($info) && !empty($info['mime']) ? (string)$info['mime'] : '';
        }
        $ext = null;
        if ($mime === 'image/jpeg') $ext = 'jpg';
        if ($mime === 'image/png') $ext = 'png';
        if (!$ext) {
            flash_set('error', 'Only JPG or PNG screenshots are allowed.');
            redirect('checkout.php?oid=' . $oid);
        }

        $shotHash = hash_file('sha256', $tmp);
        $rand = bin2hex(random_bytes(8));
        $fileName = 'shot_' . $oid . '_' . $rand . '.' . $ext;
        $destAbs = rtrim(UPLOAD_DIR_SCREENSHOTS, '/') . '/' . $fileName;
        $destRel = 'uploads/' . $fileName;

        if (!move_uploaded_file($tmp, $destAbs)) {
            flash_set('error', 'Upload failed. Please try again.');
            redirect('checkout.php?oid=' . $oid);
        }
        @chmod($destAbs, 0644);
        $shotPath = $destRel;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock user wallet
        $stU = $pdo->prepare('SELECT wallet FROM users WHERE id = ? FOR UPDATE');
        $stU->execute([(int)$u['id']]);
        $uw = $stU->fetch();
        if (!$uw) throw new RuntimeException('User not found.');

        $balance = (float)$uw['wallet'];
        if ($walletUseF > $balance) {
            throw new RuntimeException('Insufficient wallet balance.');
        }

        // Ensure order still pending
        $stO = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
        $stO->execute([$oid, (int)$u['id']]);
        $os = $stO->fetch();
        if (!$os || (string)$os['status'] !== 'pending') {
            throw new RuntimeException('Order is no longer pending.');
        }

        if ($walletUseF > 0) {
            $pdo->prepare('UPDATE users SET wallet = wallet - ? WHERE id = ?')->execute([$walletUseF, (int)$u['id']]);
        }

        $upd = $pdo->prepare(
            "UPDATE orders
             SET status = 'submitted',
                 wallet_used = ?,
                 upi_amount = ?,
                 utr = ?,
                 screenshot_path = ?,
                 screenshot_sha256 = ?,
                 submitted_at = NOW(),
                 updated_at = NOW()
             WHERE id = ? AND user_id = ? AND status = 'pending'"
        );
        $upd->execute([
            $walletUseF,
            $remaining,
            $utr,
            $shotPath,
            $shotHash,
            $oid,
            (int)$u['id']
        ]);

        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Could not submit order. Please retry.');
        }

        $pdo->commit();
        flash_set('ok', 'Payment submitted! Admin will verify and complete your order.');
        redirect('checkout.php?oid=' . $oid . '&done=1');
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Remove uploaded file on duplicate constraints
        if ($shotPath) {
            $abs = __DIR__ . '/' . $shotPath;
            if (is_file($abs)) @unlink($abs);
        }
        if ($e->getCode() === '23000') {
            flash_set('error', 'Duplicate UTR or screenshot detected. Please double-check and try again.');
            redirect('checkout.php?oid=' . $oid);
        }
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($shotPath) {
            $abs = __DIR__ . '/' . $shotPath;
            if (is_file($abs)) @unlink($abs);
        }
        flash_set('error', $e->getMessage());
        redirect('checkout.php?oid=' . $oid);
    }
}

// Reload order (after possible submission)
$st = db()->prepare(
    "SELECT o.*, p.name AS product_name, p.description AS product_desc
     FROM orders o
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?
     LIMIT 1"
);
$st->execute([$oid, (int)$u['id']]);
$order = $st->fetch();

$status = (string)$order['status'];
$price = (float)$order['price'];
$walletMax = (float)$u['wallet'];

render_header('Checkout');
?>

<div class="glass card">
    <div class="checkout-head">
        <div>
            <h2>Checkout</h2>
            <div class="muted">Order #<?= (int)$order['id'] ?> · <?= e((string)$order['product_name']) ?></div>
        </div>
        <div class="status <?= e($status) ?>"><?= e($status) ?></div>
    </div>

    <div class="split">
        <div class="glass inner-card">
            <h3>Order summary</h3>
            <div class="kv">
                <div class="k">Product</div><div class="v"><?= e((string)$order['product_name']) ?></div>
                <div class="k">Total</div><div class="v"><?= e(money($price)) ?></div>
                <div class="k">Wallet balance</div><div class="v"><?= e(money($u['wallet'])) ?></div>
            </div>
            <p class="muted small"><?= nl2br(e((string)$order['product_desc'])) ?></p>
        </div>

        <div class="glass inner-card">
            <h3>Payment instructions</h3>
            <div class="kv">
                <div class="k">UPI Payee</div><div class="v"><?= e(UPI_PAYEE_NAME) ?></div>
                <div class="k">UPI ID</div><div class="v mono"><?= e(UPI_ID) ?></div>
                <div class="k">Note</div><div class="v"><?= e(UPI_NOTE) ?> #<?= (int)$order['id'] ?></div>
            </div>
            <p class="muted small">Pay the remaining amount (if any), then submit UTR + screenshot below.</p>
        </div>
    </div>

    <?php if ($status === 'pending'): ?>
        <div class="divider"></div>
        <form method="post" enctype="multipart/form-data" class="form" id="payForm">
            <?= csrf_field() ?>
            <label>
                <span>Use wallet (optional)</span>
                <input type="number" step="0.01" min="0" max="<?= e((string)$walletMax) ?>" name="wallet_use" value="0" id="walletUse">
                <div class="muted small">Max: <?= e(money($walletMax)) ?> · You can use wallet + UPI together.</div>
            </label>

            <div class="pay-grid">
                <label>
                    <span>UTR (12–22)</span>
                    <input type="text" name="utr" maxlength="22" placeholder="E.g. 1234ABCD5678EFGH9012">
                </label>
                <label>
                    <span>Screenshot (JPG/PNG)</span>
                    <input type="file" name="screenshot" accept="image/png,image/jpeg" id="shotInput">
                </label>
            </div>

            <div class="preview-row" id="shotPreviewWrap" style="display:none;">
                <div class="muted small">Preview</div>
                <img id="shotPreview" class="shot-preview" alt="Screenshot preview">
            </div>

            <div class="row">
                <button class="btn primary" type="submit">Submit Payment</button>
                <a class="btn ghost" href="checkout.php?my=1">Back to My Orders</a>
            </div>
            <div class="muted small">Note: If wallet covers full amount, UTR/screenshot can be left empty.</div>
        </form>
    <?php elseif ($status === 'submitted'): ?>
        <div class="divider"></div>
        <div class="success">
            <div class="check-anim" aria-hidden="true">
                <div class="check-circle"></div>
                <div class="check-stem"></div>
                <div class="check-kick"></div>
            </div>
            <div>
                <h3>Submitted</h3>
                <p class="muted">Your payment proof is submitted. Admin will verify and complete the order.</p>
                <div class="row">
                    <a class="btn ghost" href="checkout.php?my=1">My Orders</a>
                </div>
            </div>
        </div>
    <?php elseif ($status === 'completed'): ?>
        <div class="divider"></div>
        <div class="success">
            <div class="check-anim" aria-hidden="true">
                <div class="check-circle"></div>
                <div class="check-stem"></div>
                <div class="check-kick"></div>
            </div>
            <div>
                <h3>Completed</h3>
                <p class="muted">Your order is completed. Download is now available.</p>
                <div class="row">
                    <a class="btn primary" href="download.php?o=<?= (int)$order['id'] ?>">Download</a>
                    <a class="btn ghost" href="checkout.php?my=1">My Orders</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="divider"></div>
        <div class="glass inner-card">
            <h3>Cancelled</h3>
            <p class="muted">This order was cancelled.</p>
            <div class="row">
                <a class="btn ghost" href="index.php">Back to store</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
  const input = document.getElementById('shotInput');
  const wrap = document.getElementById('shotPreviewWrap');
  const img = document.getElementById('shotPreview');
  if (!input) return;
  input.addEventListener('change', () => {
    const f = input.files && input.files[0];
    if (!f) { wrap.style.display = 'none'; return; }
    const url = URL.createObjectURL(f);
    img.src = url;
    wrap.style.display = 'block';
  });
})();
</script>

<?php render_footer(); ?>

