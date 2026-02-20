<?php
require_once __DIR__ . '/config.php';

$u = require_login();
$isAdmin = !empty($u['is_admin']);

function whatsapp_ok(string $w): bool
{
    $w = trim($w);
    if ($w === '') return false;
    $w = preg_replace('/\s+/', '', $w);
    return (bool)preg_match('/^\+?[0-9]{10,15}$/', $w);
}

function cart_resolve_items(array $cart): array
{
    // returns [items=>[], total=>float]
    $items = [];
    $total = 0.0;

    foreach ($cart as $k => $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $vid = (int)($it['variant_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $st = db()->prepare("SELECT p.id,p.type,p.name,p.is_hidden,p.stock,p.image_url,p.image_path FROM products p WHERE p.id=? LIMIT 1");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p || (int)$p['is_hidden'] === 1) continue;

        $type = (string)$p['type'];
        if ($type === 'account') {
            $qty = 1;
            $price = null;
            // account products should have 1 variant as pricing option OR store in short_desc; we keep price via single hidden variant?
            // For simplicity: use a single variant row with label "ACCOUNT" to store price.
            $stV = db()->prepare("SELECT id,label,price,stock,is_hidden FROM product_variants WHERE product_id=? AND is_hidden=0 ORDER BY id ASC LIMIT 1");
            $stV->execute([$pid]);
            $v = $stV->fetch();
            if (!$v) continue;
            $vid = (int)$v['id'];
            $price = (float)$v['price'];
            $stock = (int)$p['stock'];
            if ($stock < 1) continue;
            $line = round($price * $qty, 2);
            $items[] = [
                'key' => (string)$k,
                'product_id' => $pid,
                'variant_id' => $vid,
                'product_type' => $type,
                'product_name' => (string)$p['name'],
                'variant_label' => (string)$v['label'],
                'qty' => $qty,
                'unit_price' => $price,
                'line_total' => $line,
            ];
            $total += $line;
            continue;
        }

        $stV = db()->prepare("SELECT id,label,price,stock,is_hidden FROM product_variants WHERE id=? AND product_id=? LIMIT 1");
        $stV->execute([$vid, $pid]);
        $v = $stV->fetch();
        if (!$v || (int)$v['is_hidden'] === 1) continue;
        if ($qty > 10) $qty = 10;
        if ((int)$v['stock'] < $qty) $qty = (int)$v['stock'];
        if ($qty < 1) continue;
        $price = (float)$v['price'];
        $line = round($price * $qty, 2);
        $items[] = [
            'key' => (string)$k,
            'product_id' => $pid,
            'variant_id' => (int)$v['id'],
            'product_type' => $type,
            'product_name' => (string)$p['name'],
            'variant_label' => (string)$v['label'],
            'qty' => $qty,
            'unit_price' => $price,
            'line_total' => $line,
        ];
        $total += $line;
    }

    return ['items' => $items, 'total' => round($total, 2)];
}

function coupon_compute_discount(?array $coupon, int $uid, array $items, float $total): float
{
    if (!$coupon) return 0.0;
    if (empty($coupon['is_active'])) return 0.0;
    if (!empty($coupon['starts_at']) && strtotime((string)$coupon['starts_at']) > time()) return 0.0;
    if (!empty($coupon['ends_at']) && strtotime((string)$coupon['ends_at']) < time()) return 0.0;
    if (!empty($coupon['min_order_amount']) && $total < (float)$coupon['min_order_amount']) return 0.0;

    // 1 use per user enforced via coupon_uses unique key (we pre-check)
    $st = db()->prepare("SELECT 1 FROM coupon_uses cu WHERE cu.user_id=? AND cu.coupon_id=? LIMIT 1");
    $st->execute([$uid, (int)$coupon['id']]);
    if ($st->fetch()) return 0.0;

    // total uses limit
    if (!empty($coupon['max_uses_total'])) {
        $st2 = db()->prepare("SELECT COUNT(*) c FROM coupon_uses WHERE coupon_id=?");
        $st2->execute([(int)$coupon['id']]);
        $used = (int)($st2->fetch()['c'] ?? 0);
        if ($used >= (int)$coupon['max_uses_total']) return 0.0;
    }

    $eligible = 0.0;
    $applies = (string)$coupon['applies_to'];
    foreach ($items as $it) {
        $ok = false;
        if ($applies === 'all') $ok = true;
        if ($applies === 'type' && !empty($coupon['product_type']) && (string)$it['product_type'] === (string)$coupon['product_type']) $ok = true;
        if ($applies === 'product' && !empty($coupon['product_id']) && (int)$it['product_id'] === (int)$coupon['product_id']) $ok = true;
        if ($applies === 'category' && !empty($coupon['category_id'])) {
            $stC = db()->prepare("SELECT category_id FROM products WHERE id=? LIMIT 1");
            $stC->execute([(int)$it['product_id']]);
            $row = $stC->fetch();
            if ($row && (int)$row['category_id'] === (int)$coupon['category_id']) $ok = true;
        }
        if ($ok) $eligible += (float)$it['line_total'];
    }
    $eligible = round($eligible, 2);
    if ($eligible <= 0) return 0.0;

    $dtype = (string)$coupon['discount_type'];
    $val = (float)$coupon['discount_value'];
    $disc = 0.0;
    if ($dtype === 'percent') {
        if ($val < 0) $val = 0;
        if ($val > 90) $val = 90;
        $disc = round(($eligible * $val) / 100.0, 2);
    } else {
        if ($val < 0) $val = 0;
        $disc = round(min($val, $eligible), 2);
    }
    if ($disc > $total) $disc = $total;
    return $disc;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (maintenance_mode() && !$isAdmin) {
        flash_set('error', 'Maintenance mode: actions temporarily disabled.');
        redirect('index.php');
    }
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'cart_remove') {
        $k = (string)($_POST['key'] ?? '');
        $cart = cart_get();
        if (isset($cart[$k])) unset($cart[$k]);
        $_SESSION['cart'] = $cart;
        flash_set('success', 'Removed from cart.');
        redirect('checkout.php?cart=1');
    }

    if ($act === 'cart_clear') {
        unset($_SESSION['cart']);
        flash_set('success', 'Cart cleared.');
        redirect('checkout.php?cart=1');
    }

    // Create order from cart
    if ($act === 'order_create') {
        $cart = cart_get();
        $resolved = cart_resolve_items($cart);
        $items = $resolved['items'];
        $total = (float)$resolved['total'];
        if (!$items) {
            flash_set('error', 'Cart is empty.');
            redirect('checkout.php?cart=1');
        }

        $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $coupon = null;
        if ($couponCode !== '') {
            $st = db()->prepare("SELECT * FROM coupons WHERE code=? LIMIT 1");
            $st->execute([$couponCode]);
            $coupon = $st->fetch() ?: null;
        }

        $discount = 0.0;
        try {
            if ($coupon) $discount = coupon_compute_discount($coupon, (int)$u['id'], $items, $total);
        } catch (Throwable) {
            $discount = 0.0;
        }
        $subAfter = round($total - $discount, 2);
        if ($subAfter < 0) $subAfter = 0.0;

        $walletReq = (float)($_POST['wallet_use'] ?? 0);
        if ($walletReq < 0) $walletReq = 0;
        $method = (string)($_POST['pay_method'] ?? 'upi');
        if ($method !== 'upi' && $method !== 'binance') $method = 'upi';

        try {
            db()->beginTransaction();

            // Lock user wallet
            $stU = db()->prepare("SELECT wallet_balance FROM users WHERE id=? FOR UPDATE");
            $stU->execute([(int)$u['id']]);
            $ur = $stU->fetch();
            if (!$ur) throw new RuntimeException('User not found.');
            $walletBal = (float)$ur['wallet_balance'];

            $walletUse = 0.0;
            if (wallet_enabled()) {
                if (wallet_mode() === 'wallet_only') {
                    $walletUse = $subAfter;
                    if ($walletBal + 0.00001 < $walletUse) {
                        throw new RuntimeException('Insufficient wallet balance. Please add funds.');
                    }
                } else {
                    $walletUse = min($walletBal, $walletReq, $subAfter);
                }
            }
            $walletUse = round($walletUse, 2);
            $payAmount = round($subAfter - $walletUse, 2);
            if ($payAmount < 0) $payAmount = 0.0;

            // Stock checks + decrement
            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $vid = (int)$it['variant_id'];
                $qty = (int)$it['qty'];
                $ptype = (string)$it['product_type'];
                if ($ptype === 'account') {
                    $stP = db()->prepare("SELECT stock FROM products WHERE id=? FOR UPDATE");
                    $stP->execute([$pid]);
                    $pr = $stP->fetch();
                    if (!$pr || (int)$pr['stock'] < 1) throw new RuntimeException('Account out of stock.');
                    db()->prepare("UPDATE products SET stock=stock-1 WHERE id=?")->execute([$pid]);
                    // also decrement variant stock to keep consistent
                    db()->prepare("UPDATE product_variants SET stock=stock-1 WHERE id=?")->execute([$vid]);
                } else {
                    $stV = db()->prepare("SELECT stock,is_hidden FROM product_variants WHERE id=? FOR UPDATE");
                    $stV->execute([$vid]);
                    $vr = $stV->fetch();
                    if (!$vr || (int)$vr['is_hidden'] === 1 || (int)$vr['stock'] < $qty) throw new RuntimeException('Not enough stock.');
                    db()->prepare("UPDATE product_variants SET stock=stock-? WHERE id=?")->execute([$qty, $vid]);
                }
            }

            // Deduct wallet
            if ($walletUse > 0) {
                db()->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=?")->execute([money_fmt($walletUse), (int)$u['id']]);
            }

            $status = ($payAmount <= 0.0) ? 'submitted' : 'pending';
            $payMethod = ($payAmount <= 0.0) ? 'wallet' : $method;
            $stO = db()->prepare("INSERT INTO orders (user_id,status,payment_method,total_amount,discount_amount,wallet_used,pay_amount,coupon_code,submitted_at)
                                  VALUES (?,?,?,?,?,?,?,?,?)");
            $stO->execute([
                (int)$u['id'],
                $status,
                $payMethod,
                money_fmt($total),
                money_fmt($discount),
                money_fmt($walletUse),
                money_fmt($payAmount),
                ($couponCode !== '' ? $couponCode : null),
                ($status === 'submitted' ? now_dt() : null),
            ]);
            $oid = (int)db()->lastInsertId();

            $stI = db()->prepare("INSERT INTO order_items (order_id,product_id,variant_id,product_type,product_name,variant_label,qty,unit_price,line_total)
                                  VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $stI->execute([
                    $oid,
                    (int)$it['product_id'],
                    (int)$it['variant_id'],
                    (string)$it['product_type'],
                    (string)$it['product_name'],
                    (string)$it['variant_label'],
                    (int)$it['qty'],
                    money_fmt($it['unit_price']),
                    money_fmt($it['line_total']),
                ]);
            }

            if ($coupon && $discount > 0) {
                $stCU = db()->prepare("INSERT INTO coupon_uses (coupon_id,user_id,order_id) VALUES (?,?,?)");
                $stCU->execute([(int)$coupon['id'], (int)$u['id'], $oid]);
            }

            db()->commit();
            unset($_SESSION['cart']);

            if ($status === 'submitted') {
                // Wallet-paid order: notify admin (Telegram)
                $itemCount = 0;
                foreach ($items as $it) $itemCount += (int)$it['qty'];
                $msg = "🟢 New Wallet Order\nOrder #{$oid}\nUser: {$u['username']} ({$u['email']})\nItems: {$itemCount}\nTotal: ₹" . money_fmt($total) . "\nDiscount: ₹" . money_fmt($discount) . "\nWallet used: ₹" . money_fmt($walletUse);
                telegram_send($msg);
                $to = trim(setting_get('notify_email', ''));
                if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $body = '<h3>New Wallet Order</h3>'
                        . '<p>Order #' . (int)$oid . '</p>'
                        . '<p>User: ' . e((string)$u['username']) . ' (' . e((string)$u['email']) . ')</p>'
                        . '<p>Total: ₹' . e(money_fmt($total)) . ' · Discount: ₹' . e(money_fmt($discount)) . ' · Wallet: ₹' . e(money_fmt($walletUse)) . '</p>';
                    smtp_send_mail($to, SITE_NAME . ' - New Wallet Order #' . (int)$oid, $body);
                }
                flash_set('success', 'Order created successfully! Wait for owner to deliver your order.');
                redirect('checkout.php?order_id=' . $oid . '&success=1');
            }
            flash_set('success', 'Order created. Please pay and submit proof.');
            redirect('checkout.php?order_id=' . $oid);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $msg = DEBUG ? $e->getMessage() : 'Could not create order.';
            flash_set('error', $msg);
            redirect('checkout.php?cart=1');
        }
    }

    // Submit payment proof for order
    if ($act === 'proof_submit') {
        $oid = (int)($_POST['order_id'] ?? 0);
        $telegram = trim((string)($_POST['telegram_username'] ?? ''));
        $whatsapp = trim((string)($_POST['whatsapp_number'] ?? ''));
        $ref = strtoupper(trim((string)($_POST['reference_id'] ?? '')));

        $st = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
        $st->execute([$oid, (int)$u['id']]);
        $o = $st->fetch();
        if (!$o) {
            flash_set('error', 'Order not found.');
            redirect('index.php?page=orders');
        }
        if ((string)$o['status'] !== 'pending') {
            flash_set('error', 'Order is not pending.');
            redirect('checkout.php?order_id=' . $oid);
        }
        $method = (string)($o['payment_method'] ?? 'upi');
        if (!validate_reference_id($method, $ref)) {
            flash_set('error', $method === 'upi' ? 'UTR must be 12–22 letters/numbers.' : 'Invalid reference id.');
            redirect('checkout.php?order_id=' . $oid);
        }
        if (!whatsapp_ok($whatsapp)) {
            flash_set('error', 'Enter a valid WhatsApp number.');
            redirect('checkout.php?order_id=' . $oid);
        }
        if (empty($_FILES['screenshot'])) {
            flash_set('error', 'Please upload screenshot (JPG/PNG).');
            redirect('checkout.php?order_id=' . $oid);
        }

        $uploadedRel = null;
        try {
            $up = handle_image_upload($_FILES['screenshot'], 'proofs', 5 * 1024 * 1024);
            $uploadedRel = $up['rel_path'];
            $sha = $up['sha256'];

            db()->beginTransaction();
            $stLock = db()->prepare("SELECT id,status FROM orders WHERE id=? AND user_id=? FOR UPDATE");
            $stLock->execute([$oid, (int)$u['id']]);
            $locked = $stLock->fetch();
            if (!$locked || (string)$locked['status'] !== 'pending') {
                throw new RuntimeException('Order is no longer pending.');
            }

            // Prevent duplicate ref across orders and topups
            $stDup = db()->prepare("SELECT 1 FROM orders WHERE reference_id=? LIMIT 1");
            $stDup->execute([$ref]);
            if ($stDup->fetch()) throw new RuntimeException('Reference already used.');
            $stDupT = db()->prepare("SELECT 1 FROM wallet_topups WHERE reference_id=? LIMIT 1");
            $stDupT->execute([$ref]);
            if ($stDupT->fetch()) throw new RuntimeException('Reference already used.');

            $stDup2 = db()->prepare("SELECT 1 FROM orders WHERE screenshot_sha256=? LIMIT 1");
            $stDup2->execute([$sha]);
            if ($stDup2->fetch()) throw new RuntimeException('Screenshot already used.');
            $stDup2T = db()->prepare("SELECT 1 FROM wallet_topups WHERE screenshot_sha256=? LIMIT 1");
            $stDup2T->execute([$sha]);
            if ($stDup2T->fetch()) throw new RuntimeException('Screenshot already used.');

            $upO = db()->prepare("UPDATE orders
                                  SET telegram_username=?, whatsapp_number=?, reference_id=?, screenshot_path=?, screenshot_sha256=?, status='submitted', submitted_at=NOW()
                                  WHERE id=? AND user_id=?");
            $upO->execute([
                ($telegram === '' ? null : $telegram),
                $whatsapp,
                $ref,
                $uploadedRel,
                $sha,
                $oid,
                (int)$u['id']
            ]);

            db()->commit();

            $msg = "🟣 New Order Proof\nOrder #{$oid}\nUser: {$u['username']} ({$u['email']})\nMethod: {$method}\nAmount: ₹" . money_fmt($o['pay_amount']) . "\nRef: {$ref}\nWhatsApp: {$whatsapp}\nTelegram: " . ($telegram ?: '-') . "\nScreenshot: " . site_url($uploadedRel);
            telegram_send($msg);
            $to = trim(setting_get('notify_email', ''));
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $body = '<h3>New Order Payment Proof</h3>'
                    . '<p>Order #' . (int)$oid . '</p>'
                    . '<p>User: ' . e((string)$u['username']) . ' (' . e((string)$u['email']) . ')</p>'
                    . '<p>Method: ' . e((string)$method) . ' · Amount: ₹' . e(money_fmt($o['pay_amount'])) . '</p>'
                    . '<p>Ref: <b>' . e($ref) . '</b></p>'
                    . '<p>WhatsApp: ' . e($whatsapp) . ' · Telegram: ' . e($telegram ?: '-') . '</p>';
                smtp_send_mail($to, SITE_NAME . ' - Order Proof #' . (int)$oid, $body);
            }

            flash_set('success', 'Proof submitted! Wait for admin verification.');
            redirect('checkout.php?order_id=' . $oid . '&success=1');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($uploadedRel) @unlink(__DIR__ . '/' . $uploadedRel);
            $msg = DEBUG ? $e->getMessage() : 'Could not submit proof.';
            flash_set('error', $msg);
            redirect('checkout.php?order_id=' . $oid);
        }
    }

    // Wallet topup submit
    if ($act === 'topup_submit') {
        if (!wallet_enabled()) {
            flash_set('error', 'Wallet is disabled.');
            redirect('index.php');
        }
        $amount = (float)($_POST['amount'] ?? 0);
        $method = (string)($_POST['method'] ?? 'upi');
        $telegram = trim((string)($_POST['telegram_username'] ?? ''));
        $whatsapp = trim((string)($_POST['whatsapp_number'] ?? ''));
        $ref = strtoupper(trim((string)($_POST['reference_id'] ?? '')));
        if ($amount < 10 || $amount > 10000) {
            flash_set('error', 'Amount must be between ₹10 and ₹10000.');
            redirect('checkout.php?action=wallet');
        }
        if ($method !== 'upi' && $method !== 'binance') $method = 'upi';
        if (!validate_reference_id($method, $ref)) {
            flash_set('error', $method === 'upi' ? 'UTR must be 12–22 letters/numbers.' : 'Invalid reference id.');
            redirect('checkout.php?action=wallet');
        }
        if (!whatsapp_ok($whatsapp)) {
            flash_set('error', 'Enter a valid WhatsApp number.');
            redirect('checkout.php?action=wallet');
        }
        if (empty($_FILES['screenshot'])) {
            flash_set('error', 'Please upload screenshot (JPG/PNG).');
            redirect('checkout.php?action=wallet');
        }

        $uploadedRel = null;
        try {
            $up = handle_image_upload($_FILES['screenshot'], 'topups', 5 * 1024 * 1024);
            $uploadedRel = $up['rel_path'];
            $sha = $up['sha256'];

            db()->beginTransaction();
            // lock user
            db()->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([(int)$u['id']]);

            // prevent duplicates across orders/topups
            $stDup = db()->prepare("SELECT 1 FROM orders WHERE reference_id=? LIMIT 1");
            $stDup->execute([$ref]);
            if ($stDup->fetch()) throw new RuntimeException('Reference already used.');
            $stDupT = db()->prepare("SELECT 1 FROM wallet_topups WHERE reference_id=? LIMIT 1");
            $stDupT->execute([$ref]);
            if ($stDupT->fetch()) throw new RuntimeException('Reference already used.');

            $stDup2 = db()->prepare("SELECT 1 FROM orders WHERE screenshot_sha256=? LIMIT 1");
            $stDup2->execute([$sha]);
            if ($stDup2->fetch()) throw new RuntimeException('Screenshot already used.');
            $stDup2T = db()->prepare("SELECT 1 FROM wallet_topups WHERE screenshot_sha256=? LIMIT 1");
            $stDup2T->execute([$sha]);
            if ($stDup2T->fetch()) throw new RuntimeException('Screenshot already used.');

            $ins = db()->prepare("INSERT INTO wallet_topups (user_id,amount,method,telegram_username,whatsapp_number,reference_id,screenshot_path,screenshot_sha256,status)
                                  VALUES (?,?,?,?,?,?,?,?, 'pending')");
            $ins->execute([
                (int)$u['id'],
                money_fmt($amount),
                $method,
                ($telegram === '' ? null : $telegram),
                $whatsapp,
                $ref,
                $uploadedRel,
                $sha,
            ]);
            $tid = (int)db()->lastInsertId();
            db()->commit();

            $msg = "🔵 Wallet Top-up Request\nTopup #{$tid}\nUser: {$u['username']} ({$u['email']})\nAmount: ₹" . money_fmt($amount) . "\nMethod: {$method}\nRef: {$ref}\nWhatsApp: {$whatsapp}\nTelegram: " . ($telegram ?: '-') . "\nScreenshot: " . site_url($uploadedRel);
            telegram_send($msg);
            $to = trim(setting_get('notify_email', ''));
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $body = '<h3>New Wallet Top-up Request</h3>'
                    . '<p>Topup #' . (int)$tid . '</p>'
                    . '<p>User: ' . e((string)$u['username']) . ' (' . e((string)$u['email']) . ')</p>'
                    . '<p>Amount: ₹' . e(money_fmt($amount)) . ' · Method: ' . e((string)$method) . '</p>'
                    . '<p>Ref: <b>' . e($ref) . '</b></p>'
                    . '<p>WhatsApp: ' . e($whatsapp) . ' · Telegram: ' . e($telegram ?: '-') . '</p>';
                smtp_send_mail($to, SITE_NAME . ' - Wallet Topup #' . (int)$tid, $body);
            }

            flash_set('success', 'Top-up submitted! Admin will approve soon.');
            redirect('checkout.php?action=wallet');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($uploadedRel) @unlink(__DIR__ . '/' . $uploadedRel);
            $msg = DEBUG ? $e->getMessage() : 'Could not submit top-up.';
            flash_set('error', $msg);
            redirect('checkout.php?action=wallet');
        }
    }
}

// ---- Page rendering ----
$orderId = (int)($_GET['order_id'] ?? 0);
$success = (int)($_GET['success'] ?? 0) === 1;
$cartPage = (int)($_GET['cart'] ?? 0) === 1;
$walletPage = ((string)($_GET['action'] ?? '') === 'wallet');

// Wallet page
if ($walletPage) {
    if (!wallet_enabled()) {
        flash_set('error', 'Wallet is disabled.');
        redirect('index.php');
    }
    page_header('Wallet — ' . SITE_NAME);
    echo '<div class="glass card"><div class="row"><div><h2>Wallet</h2><div class="muted">Balance: <b>₹' . e(money_fmt($u['wallet_balance'])) . '</b></div></div></div></div>';

    echo '<div class="grid admin-grid">';
    echo '<div class="glass card">';
    echo '<h2>Add funds</h2>';
    echo '<div class="muted small">Pay to UPI/Binance, then submit proof below.</div>';
    echo '<div class="hr"></div>';
    echo '<div class="paybox">';
    echo '<div><div class="muted small">UPI ID</div><div class="mono big">' . e(upi_vpa() ?: 'Not set') . '</div></div>';
    echo '<div><div class="muted small">Binance</div><div class="mono big">' . e(binance_id() ?: 'Ask admin') . '</div></div>';
    echo '</div>';
    echo '<form method="post" enctype="multipart/form-data" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="topup_submit">';
    echo '<label class="label">Amount (₹)</label><input class="input mono" type="number" step="0.01" min="10" max="10000" name="amount" required value="10">';
    echo '<label class="label">Payment method</label><select class="select" name="method"><option value="upi">UPI</option><option value="binance">Binance</option></select>';
    echo '<label class="label">Reference (UTR / TXID)</label><input class="input mono" name="reference_id" required placeholder="UTR or TXID">';
    echo '<label class="label">Telegram username (optional)</label><input class="input mono" name="telegram_username" placeholder="@username">';
    echo '<label class="label">WhatsApp number</label><input class="input mono" name="whatsapp_number" required placeholder="+91...">';
    echo '<label class="label">Screenshot (JPG/PNG, max 5MB)</label><input class="input" type="file" name="screenshot" required accept="image/png,image/jpeg" id="topupShot">';
    echo '<div class="preview" id="topupPrev" style="display:none;"><img alt="preview" id="topupPrevImg"></div>';
    echo '<button class="btn btn-full" type="submit">Submit top-up</button>';
    echo '</form>';
    echo '</div>';

    $st = db()->prepare("SELECT * FROM wallet_topups WHERE user_id=? ORDER BY id DESC LIMIT 30");
    $st->execute([(int)$u['id']]);
    $rows = $st->fetchAll();
    echo '<div class="glass card">';
    echo '<h2>Top-up history</h2>';
    if (!$rows) {
        echo '<div class="muted">No top-ups yet.</div>';
    } else {
        echo '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Amount</th><th>Method</th><th>Ref</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $stt = (string)$r['status'];
            $b = 'badge' . ($stt === 'approved' ? ' good' : ($stt === 'cancelled' ? ' bad' : ' warn'));
            echo '<tr>';
            echo '<td class="mono">#' . (int)$r['id'] . '<div class="muted small">' . e((string)$r['created_at']) . '</div></td>';
            echo '<td>₹' . e(money_fmt($r['amount'])) . '</td>';
            echo '<td>' . e((string)$r['method']) . '</td>';
            echo '<td class="mono small">' . e((string)$r['reference_id']) . '</td>';
            echo '<td><span class="' . e($b) . '">' . e($stt) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<script>
      const f=document.getElementById("topupShot");
      const box=document.getElementById("topupPrev");
      const img=document.getElementById("topupPrevImg");
      if(f){f.addEventListener("change",()=>{const file=f.files&&f.files[0]; if(!file){box.style.display="none";return;}
        const r=new FileReader(); r.onload=()=>{img.src=r.result; box.style.display="block";}; r.readAsDataURL(file);
      });}
    </script>';

    page_footer();
    exit;
}

// Cart page
if ($cartPage) {
    $cart = cart_get();
    $resolved = cart_resolve_items($cart);
    $items = $resolved['items'];
    $total = (float)$resolved['total'];

    page_header('Cart — ' . SITE_NAME);
    echo '<div class="section-title"><h2>Cart</h2><div class="muted">Checkout with wallet + UPI/Binance</div></div>';

    if (!$items) {
        echo '<div class="glass card"><div class="muted">Your cart is empty.</div><a class="btn" href="index.php">Browse products</a></div>';
        page_footer();
        exit;
    }

    echo '<div class="glass card table-wrap">';
    echo '<table class="table"><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th></tr></thead><tbody>';
    foreach ($items as $it) {
        echo '<tr>';
        echo '<td>' . e($it['product_name']) . ' <span class="muted small">' . e($it['variant_label']) . '</span></td>';
        echo '<td class="mono">' . (int)$it['qty'] . '</td>';
        echo '<td>₹' . e(money_fmt($it['unit_price'])) . '</td>';
        echo '<td>₹' . e(money_fmt($it['line_total'])) . '</td>';
        echo '<td><form method="post" class="inline">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="cart_remove">';
        echo '<input type="hidden" name="key" value="' . e((string)$it['key']) . '">';
        echo '<button class="btn btn-danger btn-sm" type="submit">Remove</button>';
        echo '</form></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="glass card">';
    echo '<form method="post" class="inline" style="justify-content:flex-end">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="cart_clear">';
    echo '<button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm(\'Clear cart?\')">Clear cart</button>';
    echo '</form>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="order_create">';
    echo '<label class="label">Coupon (optional)</label>';
    echo '<input class="input mono" name="coupon_code" placeholder="ENTER CODE">';
    if (wallet_enabled()) {
        echo '<label class="label">Use wallet (₹)</label>';
        $hint = 'Available: ₹' . money_fmt($u['wallet_balance']);
        if (wallet_mode() === 'wallet_only') $hint = 'Wallet-only mode is ON. You must have enough balance.';
        echo '<input class="input mono" type="number" step="0.01" min="0" name="wallet_use" value="0">';
        echo '<div class="muted small">' . e($hint) . '</div>';
    } else {
        echo '<input type="hidden" name="wallet_use" value="0">';
    }
    echo '<label class="label">Payment method (if remaining)</label>';
    echo '<select class="select" name="pay_method"><option value="upi">UPI</option><option value="binance">Binance</option></select>';
    echo '<div class="hr"></div>';
    echo '<div class="row"><div class="muted small">Cart total</div><div class="price">₹' . e(money_fmt($total)) . '</div></div>';
    echo '<button class="btn btn-full" type="submit">Create order</button>';
    echo '</form>';
    echo '</div>';

    page_footer();
    exit;
}

// Order payment/proof page
if ($orderId > 0) {
    $st = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$orderId, (int)$u['id']]);
    $o = $st->fetch();
    if (!$o) {
        flash_set('error', 'Order not found.');
        redirect('index.php?page=orders');
    }
    $items = db()->prepare("SELECT * FROM order_items WHERE order_id=?");
    $items->execute([$orderId]);
    $rows = $items->fetchAll();

    page_header('Checkout — Order #' . $orderId);

    echo '<div class="glass card">';
    echo '<div class="row"><div><div class="muted small">Order</div><div class="mono big">#' . (int)$o['id'] . '</div></div>';
    $status = (string)$o['status'];
    $badge = 'badge' . ($status === 'completed' ? ' good' : ($status === 'cancelled' ? ' bad' : ($status === 'submitted' ? ' warn' : '')));
    echo '<div><div class="muted small">Status</div><div><span class="' . e($badge) . '">' . e($status) . '</span></div></div></div>';
    echo '<div class="hr"></div>';
    echo '<div class="muted small">Items</div><ul class="list">';
    foreach ($rows as $it) {
        echo '<li>' . e((string)$it['product_name']) . ' <span class="muted small">' . e((string)($it['variant_label'] ?? '')) . '</span> <span class="muted small">×' . (int)$it['qty'] . '</span></li>';
    }
    echo '</ul>';
    echo '<div class="hr"></div>';
    echo '<div class="row">';
    echo '<div><div class="muted small">Total</div><div class="price">₹' . e(money_fmt($o['total_amount'])) . '</div></div>';
    echo '<div><div class="muted small">Discount</div><div>₹' . e(money_fmt($o['discount_amount'])) . '</div></div>';
    echo '<div><div class="muted small">Wallet used</div><div>₹' . e(money_fmt($o['wallet_used'])) . '</div></div>';
    echo '<div><div class="muted small">Remaining</div><div class="mono big">₹' . e(money_fmt($o['pay_amount'])) . '</div></div>';
    echo '</div>';
    echo '</div>';

    if ($success) {
        echo '<div class="success-anim glass card"><div class="checkmark"></div><h3>Success</h3>';
        if ($status === 'submitted') echo '<p class="muted">Proof submitted. Admin will verify and deliver soon.</p>';
        elseif ($status === 'completed') echo '<p class="muted">Order completed.</p>';
        else echo '<p class="muted">Order created.</p>';
        echo '<a class="btn btn-ghost" href="index.php?page=orders">Go to orders</a></div>';
    }

    if ($status === 'pending' && (float)$o['pay_amount'] > 0) {
        $method = (string)($o['payment_method'] ?? 'upi');
        echo '<div class="glass card">';
        echo '<h2>Pay & submit proof</h2>';
        echo '<div class="muted">Pay exactly <b>₹' . e(money_fmt($o['pay_amount'])) . '</b></div>';
        if ($method === 'upi') {
            $vpa = upi_vpa();
            $payee = upi_payee();
            $upiLink = 'upi://pay?pa=' . rawurlencode($vpa) . '&pn=' . rawurlencode($payee) . '&am=' . rawurlencode(money_fmt($o['pay_amount'])) . '&cu=INR&tn=' . rawurlencode('Order #' . (int)$o['id']);
            echo '<div class="paybox">';
            echo '<div><div class="muted small">UPI ID (tap to copy)</div><div class="mono big copy" data-copy="' . e($vpa) . '">' . e($vpa) . '</div></div>';
            echo '<div><div class="muted small">Payee</div><div>' . e($payee) . '</div></div>';
            echo '</div>';
            echo '<a class="btn btn-full" href="' . e($upiLink) . '">Pay using UPI app</a>';
        } else {
            echo '<div class="paybox">';
            echo '<div><div class="muted small">Binance ID</div><div class="mono big copy" data-copy="' . e(binance_id()) . '">' . e(binance_id() ?: 'Not set') . '</div></div>';
            echo '<div><div class="muted small">Note</div><div class="muted">Send exact amount and copy TXID.</div></div>';
            echo '</div>';
        }

        echo '<div class="hr"></div>';
        echo '<form method="post" enctype="multipart/form-data" class="form">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="proof_submit">';
        echo '<input type="hidden" name="order_id" value="' . (int)$o['id'] . '">';
        echo '<label class="label">Reference (UTR / TXID)</label>';
        echo '<input class="input mono" name="reference_id" required placeholder="Enter UTR/TXID">';
        echo '<label class="label">Telegram username (optional)</label>';
        echo '<input class="input mono" name="telegram_username" placeholder="@username">';
        echo '<label class="label">WhatsApp number</label>';
        echo '<input class="input mono" name="whatsapp_number" required placeholder="+91...">';
        echo '<label class="label">Screenshot (JPG/PNG, max 5MB)</label>';
        echo '<input class="input" type="file" name="screenshot" accept="image/png,image/jpeg" required id="shot">';
        echo '<div class="preview" id="preview" style="display:none;"><img alt="preview" id="previewImg"></div>';
        echo '<button class="btn btn-full" type="submit">Submit proof</button>';
        echo '</form>';
        echo '</div>';

        echo '<script>
          document.querySelectorAll(".copy").forEach(el=>{el.addEventListener("click",()=>{const t=el.getAttribute("data-copy")||""; if(!t) return; navigator.clipboard&&navigator.clipboard.writeText(t); el.classList.add("copied"); setTimeout(()=>el.classList.remove("copied"),900);});});
          const f=document.getElementById("shot");
          const box=document.getElementById("preview");
          const img=document.getElementById("previewImg");
          if(f){f.addEventListener("change",()=>{const file=f.files&&f.files[0]; if(!file){box.style.display="none";return;}
            const r=new FileReader(); r.onload=()=>{img.src=r.result; box.style.display="block";}; r.readAsDataURL(file);
          });}
        </script>';
    } elseif ($status === 'submitted') {
        echo '<div class="glass card"><h2>Submitted</h2><p class="muted">Admin will verify and deliver your order soon.</p><a class="btn btn-ghost" href="index.php?page=orders">Back</a></div>';
    } elseif ($status === 'completed') {
        echo '<div class="glass card"><h2>Completed</h2><p class="muted">Your order is completed. Open order to view E-Box.</p><a class="btn" href="index.php?order=' . (int)$o['id'] . '">Open E-Box</a></div>';
    } elseif ($status === 'cancelled') {
        echo '<div class="glass card"><h2>Cancelled</h2><p class="muted">This order was cancelled.</p><a class="btn btn-ghost" href="index.php?page=orders">Back</a></div>';
    }

    page_footer();
    exit;
}

// Default route
redirect('checkout.php?cart=1');

