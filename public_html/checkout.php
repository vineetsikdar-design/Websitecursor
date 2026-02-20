<?php
require_once __DIR__ . '/config.php';

$u = require_login();

function get_product(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ?: null;
}

function get_order_for_user(int $oid, int $uid): ?array
{
    $st = db()->prepare("SELECT o.*, p.name AS product_name, p.download_file
                         FROM orders o
                         JOIN products p ON p.id=o.product_id
                         WHERE o.id=? AND o.user_id=? LIMIT 1");
    $st->execute([$oid, $uid]);
    $o = $st->fetch();
    return $o ?: null;
}

// ---- Create order (pending or completed if wallet covers full) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_order') {
    csrf_check();
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);
    $walletReq = (float)($_POST['wallet_use'] ?? 0);
    if ($qty < 1) $qty = 1;
    if ($qty > 10) $qty = 10;
    if ($walletReq < 0) $walletReq = 0;

    $p = get_product($pid);
    if (!$p || (int)$p['is_hidden'] === 1) {
        flash_set('error', 'Product not found.');
        redirect('index.php');
    }

    try {
        db()->beginTransaction();

        $stP = db()->prepare("SELECT id,price,stock,is_hidden FROM products WHERE id=? FOR UPDATE");
        $stP->execute([$pid]);
        $pl = $stP->fetch();
        if (!$pl || (int)$pl['is_hidden'] === 1) {
            throw new RuntimeException('Product not available.');
        }
        if ((int)$pl['stock'] < $qty) {
            throw new RuntimeException('Not enough stock.');
        }

        $stU = db()->prepare("SELECT id,wallet_balance FROM users WHERE id=? FOR UPDATE");
        $stU->execute([$u['id']]);
        $ul = $stU->fetch();
        if (!$ul) throw new RuntimeException('User not found.');

        $unit = (float)$pl['price'];
        $total = round($unit * $qty, 2);
        $walletBal = (float)$ul['wallet_balance'];
        $walletUse = min($walletBal, $walletReq, $total);
        $walletUse = round($walletUse, 2);
        $upiAmt = round($total - $walletUse, 2);
        if ($upiAmt < 0) $upiAmt = 0.00;

        $upStock = db()->prepare("UPDATE products SET stock=stock-? WHERE id=?");
        $upStock->execute([$qty, $pid]);

        if ($walletUse > 0) {
            $upWal = db()->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=?");
            $upWal->execute([money_fmt($walletUse), $u['id']]);
        }

        $status = ($upiAmt <= 0.0) ? 'completed' : 'pending';
        $stIns = db()->prepare("INSERT INTO orders (user_id,product_id,qty,unit_price,total_amount,wallet_used,upi_amount,status,submitted_at,completed_at,cancelled_at)
                                VALUES (?,?,?,?,?,?,?, ?, NULL, ?, NULL)");
        $completedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
        $stIns->execute([
            $u['id'],
            $pid,
            $qty,
            money_fmt($unit),
            money_fmt($total),
            money_fmt($walletUse),
            money_fmt($upiAmt),
            $status,
            $completedAt
        ]);
        $oid = (int)db()->lastInsertId();

        db()->commit();

        if ($status === 'completed') {
            flash_set('success', 'Order completed using wallet!');
            redirect('checkout.php?order_id=' . $oid . '&success=1');
        }
        flash_set('success', 'Order created. Now submit your UPI payment proof.');
        redirect('checkout.php?order_id=' . $oid);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $msg = $e->getMessage();
        if (!DEBUG) $msg = 'Could not create order. Try again.';
        flash_set('error', $msg);
        redirect('checkout.php?product_id=' . $pid);
    }
}

// ---- Submit payment proof (pending -> submitted) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    csrf_check();
    $oid = (int)($_POST['order_id'] ?? 0);
    $utr = strtoupper(trim((string)($_POST['utr'] ?? '')));

    $order = get_order_for_user($oid, (int)$u['id']);
    if (!$order) {
        flash_set('error', 'Order not found.');
        redirect('index.php');
    }
    if ((string)$order['status'] !== 'pending') {
        flash_set('error', 'This order is not pending.');
        redirect('checkout.php?order_id=' . $oid);
    }
    if ((float)$order['upi_amount'] <= 0) {
        flash_set('success', 'No UPI required for this order.');
        redirect('checkout.php?order_id=' . $oid . '&success=1');
    }
    if (!validate_utr($utr)) {
        flash_set('error', 'UTR must be 12–22 characters (letters/numbers).');
        redirect('checkout.php?order_id=' . $oid);
    }
    if (empty($_FILES['screenshot'])) {
        flash_set('error', 'Please upload a screenshot.');
        redirect('checkout.php?order_id=' . $oid);
    }

    $uploadedRel = null;
    try {
        $up = handle_screenshot_upload($_FILES['screenshot']);
        $uploadedRel = $up['rel_path'];
        $sha = $up['sha256'];

        db()->beginTransaction();

        $stLock = db()->prepare("SELECT id,status FROM orders WHERE id=? AND user_id=? FOR UPDATE");
        $stLock->execute([$oid, $u['id']]);
        $locked = $stLock->fetch();
        if (!$locked || (string)$locked['status'] !== 'pending') {
            throw new RuntimeException('Order is no longer pending.');
        }

        $stDup = db()->prepare("SELECT id FROM orders WHERE utr=? LIMIT 1");
        $stDup->execute([$utr]);
        $dup = $stDup->fetch();
        if ($dup) {
            throw new RuntimeException('This UTR is already used.');
        }

        $stDup2 = db()->prepare("SELECT id FROM orders WHERE screenshot_sha256=? LIMIT 1");
        $stDup2->execute([$sha]);
        $dup2 = $stDup2->fetch();
        if ($dup2) {
            throw new RuntimeException('This screenshot was already used.');
        }

        $stUp = db()->prepare("UPDATE orders
                               SET utr=?, screenshot_path=?, screenshot_sha256=?, status='submitted', submitted_at=NOW()
                               WHERE id=? AND user_id=?");
        $stUp->execute([$utr, $uploadedRel, $sha, $oid, $u['id']]);

        db()->commit();
        flash_set('success', 'Payment proof submitted! Await admin verification.');
        redirect('checkout.php?order_id=' . $oid . '&success=1');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($uploadedRel) {
            $abs = __DIR__ . '/' . $uploadedRel;
            @unlink($abs);
        }
        $msg = $e->getMessage();
        if (!DEBUG) $msg = 'Could not submit proof. Try again.';
        flash_set('error', $msg);
        redirect('checkout.php?order_id=' . $oid);
    }
}

// ---- Page rendering ----
$orderId = (int)($_GET['order_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$success = (int)($_GET['success'] ?? 0) === 1;

if ($orderId > 0) {
    $order = get_order_for_user($orderId, (int)$u['id']);
    if (!$order) {
        flash_set('error', 'Order not found.');
        redirect('index.php');
    }

    page_header('Checkout — Order #' . $orderId);

    echo '<div class="glass card">';
    echo '<div class="row">';
    echo '<div><div class="muted small">Order</div><div class="mono big">#' . (int)$order['id'] . '</div></div>';
    echo '<div><div class="muted small">Status</div><div><span class="badge ' . ((string)$order['status'] === 'completed' ? 'good' : (((string)$order['status'] === 'submitted') ? 'warn' : '')) . '">' . e((string)$order['status']) . '</span></div></div>';
    echo '</div>';
    echo '<div class="hr"></div>';
    echo '<div class="row">';
    echo '<div><div class="muted small">Product</div><div>' . e((string)$order['product_name']) . ' <span class="muted small">×' . (int)$order['qty'] . '</span></div></div>';
    echo '<div><div class="muted small">Total</div><div class="price">₹' . e(money_fmt($order['total_amount'])) . '</div></div>';
    echo '</div>';
    echo '<div class="row">';
    echo '<div><div class="muted small">Wallet used</div><div>₹' . e(money_fmt($order['wallet_used'])) . '</div></div>';
    echo '<div><div class="muted small">UPI amount</div><div>₹' . e(money_fmt($order['upi_amount'])) . '</div></div>';
    echo '</div>';
    echo '</div>';

    if ($success) {
        echo '<div class="success-anim glass card">';
        echo '<div class="checkmark"></div>';
        echo '<h3>Success</h3>';
        if ((string)$order['status'] === 'completed') {
            echo '<p class="muted">Your order is completed.</p>';
        } else {
            echo '<p class="muted">Your proof is submitted. Admin will verify soon.</p>';
        }
        if ((string)$order['status'] === 'completed' && !empty($order['download_file'])) {
            echo '<a class="btn" href="download.php?order_id=' . (int)$order['id'] . '">Download now</a>';
        } else {
            echo '<a class="btn btn-ghost" href="index.php#my-orders">Go to orders</a>';
        }
        echo '</div>';
    }

    if ((string)$order['status'] === 'pending' && (float)$order['upi_amount'] > 0) {
        echo '<div class="glass card">';
        echo '<h2>Pay via UPI & submit proof</h2>';
        echo '<div class="muted">Send exactly <b>₹' . e(money_fmt($order['upi_amount'])) . '</b> to:</div>';
        echo '<div class="paybox">';
        echo '<div><div class="muted small">UPI ID</div><div class="mono big">' . e(UPI_VPA) . '</div></div>';
        echo '<div><div class="muted small">Payee</div><div>' . e(UPI_PAYEE) . '</div></div>';
        echo '</div>';
        echo '<div class="hr"></div>';

        echo '<form method="post" enctype="multipart/form-data" class="form">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="submit_payment">';
        echo '<input type="hidden" name="order_id" value="' . (int)$order['id'] . '">';

        echo '<label class="label">UTR / Transaction reference</label>';
        echo '<input class="input mono" name="utr" maxlength="22" minlength="12" required placeholder="12–22 letters/numbers">';
        echo '<div class="muted small">We reject duplicate UTRs.</div>';

        echo '<label class="label">Payment screenshot (JPG/PNG)</label>';
        echo '<input class="input" type="file" name="screenshot" accept="image/png,image/jpeg" required id="shot">';
        echo '<div class="preview" id="preview" style="display:none;"><img alt="preview" id="previewImg"></div>';
        echo '<div class="muted small">We also reject duplicate screenshots (SHA-256).</div>';

        echo '<button class="btn btn-full" type="submit">Submit proof</button>';
        echo '</form>';
        echo '</div>';

        echo '<script>
          const f=document.getElementById("shot");
          const box=document.getElementById("preview");
          const img=document.getElementById("previewImg");
          if(f){f.addEventListener("change",()=>{const file=f.files&&f.files[0]; if(!file){box.style.display="none";return;}
            const r=new FileReader(); r.onload=()=>{img.src=r.result; box.style.display="block";}; r.readAsDataURL(file);
          });}
        </script>';
    } elseif ((string)$order['status'] === 'submitted') {
        echo '<div class="glass card"><h2>Submitted</h2><p class="muted">Admin will review your proof and complete the order.</p></div>';
    } elseif ((string)$order['status'] === 'completed') {
        echo '<div class="glass card"><h2>Completed</h2><p class="muted">Thanks! Your order is completed.</p>';
        if (!empty($order['download_file'])) {
            echo '<a class="btn" href="download.php?order_id=' . (int)$order['id'] . '">Download</a>';
        } else {
            echo '<div class="muted small">No download file configured for this product.</div>';
        }
        echo '</div>';
    } elseif ((string)$order['status'] === 'cancelled') {
        echo '<div class="glass card"><h2>Cancelled</h2><p class="muted">This order was cancelled.</p></div>';
    }

    page_footer();
    exit;
}

// product checkout step
if ($productId <= 0) {
    flash_set('error', 'Select a product first.');
    redirect('index.php#products');
}
$p = get_product($productId);
if (!$p || (int)$p['is_hidden'] === 1) {
    flash_set('error', 'Product not found.');
    redirect('index.php');
}

page_header('Checkout — ' . $p['name']);

echo '<div class="glass card">';
echo '<h2>' . e((string)$p['name']) . '</h2>';
if (!empty($p['description'])) {
    echo '<div class="muted">' . nl2br(e((string)$p['description'])) . '</div>';
}
echo '<div class="hr"></div>';
echo '<div class="row">';
echo '<div><div class="muted small">Price</div><div class="price">₹' . e(money_fmt($p['price'])) . '</div></div>';
echo '<div><div class="muted small">Available stock</div><div>' . (int)$p['stock'] . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="glass card">';
echo '<h2>Place order</h2>';
echo '<form method="post" class="form" id="orderForm">';
echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
echo '<input type="hidden" name="action" value="create_order">';
echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';

echo '<label class="label">Quantity</label>';
echo '<input class="input" type="number" name="qty" min="1" max="10" value="1" required id="qty">';

echo '<label class="label">Use wallet (₹)</label>';
echo '<input class="input mono" type="number" step="0.01" min="0" name="wallet_use" value="0" id="wallet">';
echo '<div class="muted small">Available wallet: <b>₹' . e(money_fmt($u['wallet_balance'])) . '</b></div>';

echo '<div class="hr"></div>';
echo '<div class="row">';
echo '<div><div class="muted small">Total</div><div class="price" id="total">₹' . e(money_fmt($p['price'])) . '</div></div>';
echo '<div><div class="muted small">UPI to pay</div><div class="mono big" id="upi">₹' . e(money_fmt($p['price'])) . '</div></div>';
echo '</div>';

echo '<button class="btn btn-full" type="submit">Create order</button>';
echo '</form>';
echo '</div>';

echo '<script>
  const price=' . json_encode((float)$p['price']) . ';
  const walMax=' . json_encode((float)$u['wallet_balance']) . ';
  const qty=document.getElementById("qty");
  const wallet=document.getElementById("wallet");
  const totalEl=document.getElementById("total");
  const upiEl=document.getElementById("upi");
  function recalc(){
    let q=parseInt(qty.value||"1",10); if(isNaN(q)||q<1) q=1; if(q>10) q=10;
    let t=Math.round((price*q)*100)/100;
    let w=parseFloat(wallet.value||"0"); if(isNaN(w)||w<0) w=0;
    if(w>walMax) w=walMax;
    if(w>t) w=t;
    let u=Math.round((t-w)*100)/100;
    totalEl.textContent="₹"+t.toFixed(2);
    upiEl.textContent="₹"+u.toFixed(2);
  }
  qty.addEventListener("input",recalc);
  wallet.addEventListener("input",recalc);
  recalc();
</script>';

page_footer();

