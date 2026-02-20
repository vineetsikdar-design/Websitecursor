<?php
require_once __DIR__ . '/config.php';

$me = require_admin();

function safe_filename(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim($name, '._-');
    if ($name === '') $name = 'file';
    if (strlen($name) > 120) $name = substr($name, -120);
    return $name;
}

function upload_delivery_files(array $files): array
{
    $out = [];
    if (empty($files['name']) || !is_array($files['name'])) return $out;
    ensure_dir(FILES_DIR);
    $allowedExt = ['zip','rar','7z','txt','pdf','apk','png','jpg','jpeg','webp'];

    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $orig = safe_filename((string)($files['name'][$i] ?? 'file'));
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) continue;
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > 30 * 1024 * 1024) continue; // 30MB each

        $final = bin2hex(random_bytes(8)) . '_' . $orig;
        $dest = rtrim(FILES_DIR, '/\\') . DIRECTORY_SEPARATOR . $final;
        if (@move_uploaded_file($tmp, $dest)) {
            $out[] = $final;
        }
    }
    return $out;
}

function admin_restore_stock_for_order(int $oid): void
{
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
}

function admin_refund_wallet_for_order(array $o): void
{
    $walletUsed = (float)($o['wallet_used'] ?? 0);
    if ($walletUsed > 0) {
        db()->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([money_fmt($walletUsed), (int)$o['user_id']]);
    }
}

function admin_cancel_order(int $oid, string $reason): void
{
    db()->beginTransaction();
    $st = db()->prepare("SELECT id,user_id,status,wallet_used FROM orders WHERE id=? FOR UPDATE");
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o) { db()->rollBack(); throw new RuntimeException('Order not found.'); }
    if ((string)$o['status'] === 'completed') { db()->rollBack(); throw new RuntimeException('Cannot cancel completed order.'); }
    if ((string)$o['status'] === 'cancelled') { db()->rollBack(); return; }

    admin_refund_wallet_for_order($o);
    admin_restore_stock_for_order($oid);
    db()->prepare("UPDATE orders SET status='cancelled', cancelled_at=NOW(), cancel_reason=? WHERE id=?")->execute([$reason, $oid]);
    db()->commit();
}

function admin_pay_referral_if_eligible(int $oid): void
{
    $st = db()->prepare("SELECT o.id,o.user_id,o.total_amount,o.discount_amount,o.referral_paid
                         FROM orders o WHERE o.id=? FOR UPDATE");
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o) return;
    if (!empty($o['referral_paid'])) return;

    $stU = db()->prepare("SELECT referred_by,referral_eligible_until FROM users WHERE id=? FOR UPDATE");
    $stU->execute([(int)$o['user_id']]);
    $usr = $stU->fetch();
    if (!$usr || empty($usr['referred_by'])) return;
    $until = (string)($usr['referral_eligible_until'] ?? '');
    if ($until === '' || strtotime($until) < time()) return;

    $base = round(((float)$o['total_amount'] - (float)$o['discount_amount']), 2);
    if ($base <= 0) return;
    $commission = round($base * 0.03, 2);
    if ($commission <= 0) return;

    db()->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([money_fmt($commission), (int)$usr['referred_by']]);
    db()->prepare("UPDATE orders SET referral_paid=1, referral_paid_amount=? WHERE id=?")->execute([money_fmt($commission), (int)$o['id']]);
}

function admin_complete_order(int $oid, array $delivery, bool $payReferral): void
{
    db()->beginTransaction();
    $st = db()->prepare("SELECT id,status FROM orders WHERE id=? FOR UPDATE");
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o) { db()->rollBack(); throw new RuntimeException('Order not found.'); }
    if ((string)$o['status'] === 'cancelled') { db()->rollBack(); throw new RuntimeException('Order cancelled.'); }

    $json = json_encode($delivery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db()->prepare("UPDATE orders SET status='completed', delivery_json=?, delivered_at=NOW(), completed_at=NOW() WHERE id=?")->execute([$json, $oid]);
    if ($payReferral) {
        admin_pay_referral_if_eligible($oid);
    }
    db()->commit();
}

function topup_approve(int $tid, int $adminId): void
{
    db()->beginTransaction();
    $st = db()->prepare("SELECT * FROM wallet_topups WHERE id=? FOR UPDATE");
    $st->execute([$tid]);
    $t = $st->fetch();
    if (!$t) { db()->rollBack(); throw new RuntimeException('Top-up not found.'); }
    if ((string)$t['status'] !== 'pending') { db()->rollBack(); return; }

    db()->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([money_fmt($t['amount']), (int)$t['user_id']]);
    db()->prepare("UPDATE wallet_topups SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$adminId, $tid]);
    db()->commit();
}

function topup_cancel(int $tid, int $adminId, string $reason): void
{
    db()->beginTransaction();
    $st = db()->prepare("SELECT status FROM wallet_topups WHERE id=? FOR UPDATE");
    $st->execute([$tid]);
    $t = $st->fetch();
    if (!$t) { db()->rollBack(); throw new RuntimeException('Top-up not found.'); }
    if ((string)$t['status'] !== 'pending') { db()->rollBack(); return; }
    db()->prepare("UPDATE wallet_topups SET status='cancelled', reviewed_by=?, reviewed_at=NOW(), cancel_reason=? WHERE id=?")->execute([$adminId, $reason, $tid]);
    db()->commit();
}

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            $pairs = [
                'maintenance_mode' => !empty($_POST['maintenance_mode']) ? '1' : '0',
                'stop_signup' => !empty($_POST['stop_signup']) ? '1' : '0',
                'stop_login' => !empty($_POST['stop_login']) ? '1' : '0',
                'wallet_enabled' => !empty($_POST['wallet_enabled']) ? '1' : '0',
                'wallet_mode' => (string)($_POST['wallet_mode'] ?? 'partial'),
                'upi_vpa' => trim((string)($_POST['upi_vpa'] ?? '')),
                'upi_payee' => trim((string)($_POST['upi_payee'] ?? '')),
                'binance_id' => trim((string)($_POST['binance_id'] ?? '')),
                'announcement_enabled' => !empty($_POST['announcement_enabled']) ? '1' : '0',
                'announcement_text' => trim((string)($_POST['announcement_text'] ?? '')),
                'welcome_enabled' => !empty($_POST['welcome_enabled']) ? '1' : '0',
                'welcome_text' => trim((string)($_POST['welcome_text'] ?? '')),
                'offer_enabled' => !empty($_POST['offer_enabled']) ? '1' : '0',
                'offer_title' => trim((string)($_POST['offer_title'] ?? '')),
                'offer_text' => trim((string)($_POST['offer_text'] ?? '')),
                'offer_image_url' => trim((string)($_POST['offer_image_url'] ?? '')),
                'telegram_bot_token' => trim((string)($_POST['telegram_bot_token'] ?? '')),
                'telegram_chat_id' => trim((string)($_POST['telegram_chat_id'] ?? '')),
                'smtp_enabled' => !empty($_POST['smtp_enabled']) ? '1' : '0',
                'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
                'smtp_port' => trim((string)($_POST['smtp_port'] ?? '587')),
                'smtp_user' => trim((string)($_POST['smtp_user'] ?? '')),
                'smtp_pass' => (string)($_POST['smtp_pass'] ?? ''),
                'smtp_from_email' => trim((string)($_POST['smtp_from_email'] ?? '')),
                'smtp_from_name' => trim((string)($_POST['smtp_from_name'] ?? SITE_NAME)),
                'notify_email' => trim((string)($_POST['notify_email'] ?? '')),
            ];
            $wm = strtolower(trim($pairs['wallet_mode']));
            if ($wm !== 'partial' && $wm !== 'wallet_only') $pairs['wallet_mode'] = 'partial';
            foreach ($pairs as $k => $v) setting_set($k, (string)$v);
            flash_set('success', 'Settings saved.');
            redirect('admin.php?tab=settings');
        }

        if ($action === 'category_add' || $action === 'category_update') {
            $cid = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $imgUrl = trim((string)($_POST['image_url'] ?? ''));
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '' || strlen($name) > 120) throw new RuntimeException('Category name required (max 120).');

            $imgPath = null;
            if (!empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $up = handle_image_upload($_FILES['image_file'], 'cats', 5 * 1024 * 1024);
                $imgPath = $up['rel_path'];
            }

            if ($action === 'category_add') {
                $st = db()->prepare("INSERT INTO categories (name,image_url,image_path,is_hidden,sort_order) VALUES (?,?,?,?,?)");
                $st->execute([$name, ($imgUrl === '' ? null : $imgUrl), $imgPath, $hidden, $sort]);
                flash_set('success', 'Category added.');
                redirect('admin.php?tab=categories');
            } else {
                $st = db()->prepare("UPDATE categories SET name=?, image_url=?, is_hidden=?, sort_order=? WHERE id=?");
                $st->execute([$name, ($imgUrl === '' ? null : $imgUrl), $hidden, $sort, $cid]);
                if ($imgPath) db()->prepare("UPDATE categories SET image_path=? WHERE id=?")->execute([$imgPath, $cid]);
                flash_set('success', 'Category updated.');
                redirect('admin.php?tab=categories');
            }
        }

        if ($action === 'category_delete') {
            $cid = (int)($_POST['category_id'] ?? 0);
            db()->prepare("DELETE FROM categories WHERE id=?")->execute([$cid]);
            flash_set('success', 'Category deleted.');
            redirect('admin.php?tab=categories');
        }

        if ($action === 'product_add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (string)($_POST['type'] ?? 'key');
            $catId = (int)($_POST['category_id'] ?? 0);
            $short = trim((string)($_POST['short_desc'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $imgUrl = trim((string)($_POST['image_url'] ?? ''));
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            $stock = (int)($_POST['stock'] ?? 0);
            $price = (float)($_POST['account_price'] ?? 0);

            if ($name === '' || strlen($name) > 190) throw new RuntimeException('Product name required (max 190).');
            if (!in_array($type, ['key','file','account'], true)) $type = 'key';
            if ($catId <= 0) $catId = null;
            if ($short !== '' && strlen($short) > 255) $short = substr($short, 0, 255);

            $imgPath = null;
            if (!empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $up = handle_image_upload($_FILES['image_file'], 'products', 5 * 1024 * 1024);
                $imgPath = $up['rel_path'];
            }

            db()->beginTransaction();
            $st = db()->prepare("INSERT INTO products (category_id,type,name,short_desc,description,image_url,image_path,stock,is_hidden) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $catId,
                $type,
                $name,
                ($short === '' ? null : $short),
                ($desc === '' ? null : $desc),
                ($imgUrl === '' ? null : $imgUrl),
                $imgPath,
                ($type === 'account' ? max(0, min(1, $stock ?: 1)) : 0),
                $hidden
            ]);
            $pid = (int)db()->lastInsertId();

            // For account products: create a default pricing variant
            if ($type === 'account') {
                if ($price < 0) $price = 0;
                $stV = db()->prepare("INSERT INTO product_variants (product_id,label,price,stock,is_hidden,sort_order) VALUES (?,?,?,?,0,0)");
                $stV->execute([$pid, 'ACCOUNT', money_fmt($price), 1]);
            }
            db()->commit();

            flash_set('success', 'Product added. Now add variants/options if needed.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'product_update') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            $stock = (int)($_POST['stock'] ?? 0);
            $st = db()->prepare("SELECT type FROM products WHERE id=? LIMIT 1");
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) throw new RuntimeException('Product not found.');
            $type = (string)$p['type'];
            if ($type === 'account') {
                $stock = max(0, min(1, $stock));
                db()->prepare("UPDATE products SET stock=?, is_hidden=? WHERE id=?")->execute([$stock, $hidden, $pid]);
            } else {
                db()->prepare("UPDATE products SET is_hidden=? WHERE id=?")->execute([$hidden, $pid]);
            }
            flash_set('success', 'Product updated.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'product_delete') {
            $pid = (int)($_POST['product_id'] ?? 0);
            db()->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
            flash_set('success', 'Product deleted.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'variant_add') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($label === '' || strlen($label) > 80) throw new RuntimeException('Variant label required (max 80).');
            if ($price < 0) $price = 0;
            if ($stock < 0) $stock = 0;
            $st = db()->prepare("INSERT INTO product_variants (product_id,label,price,stock,is_hidden,sort_order) VALUES (?,?,?,?,0,?)");
            $st->execute([$pid, $label, money_fmt($price), $stock, $sort]);
            flash_set('success', 'Variant added.');
            redirect('admin.php?tab=products&product_id=' . $pid);
        }

        if ($action === 'variant_update') {
            $vid = (int)($_POST['variant_id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($label === '' || strlen($label) > 80) throw new RuntimeException('Variant label required.');
            if ($price < 0) $price = 0;
            if ($stock < 0) $stock = 0;
            $st = db()->prepare("UPDATE product_variants SET label=?, price=?, stock=?, is_hidden=?, sort_order=? WHERE id=?");
            $st->execute([$label, money_fmt($price), $stock, $hidden, $sort, $vid]);
            $pid = (int)($_POST['product_id'] ?? 0);
            flash_set('success', 'Variant updated.');
            redirect('admin.php?tab=products&product_id=' . $pid);
        }

        if ($action === 'variant_delete') {
            $vid = (int)($_POST['variant_id'] ?? 0);
            $pid = (int)($_POST['product_id'] ?? 0);
            db()->prepare("DELETE FROM product_variants WHERE id=?")->execute([$vid]);
            flash_set('success', 'Variant deleted.');
            redirect('admin.php?tab=products&product_id=' . $pid);
        }

        if ($action === 'order_cancel') {
            $oid = (int)($_POST['order_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? 'Cancelled by admin'));
            if ($reason === '') $reason = 'Cancelled by admin';
            admin_cancel_order($oid, $reason);
            flash_set('success', 'Order cancelled.');
            redirect('admin.php?tab=orders');
        }

        if ($action === 'order_complete') {
            $oid = (int)($_POST['order_id'] ?? 0);
            $msg = trim((string)($_POST['delivery_message'] ?? ''));
            $keysText = trim((string)($_POST['delivery_keys'] ?? ''));
            $linksText = trim((string)($_POST['delivery_links'] ?? ''));
            $payReferral = !empty($_POST['pay_referral']);

            $keys = [];
            if ($keysText !== '') {
                foreach (preg_split('/\r?\n/', $keysText) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '') $keys[] = $ln;
                }
            }
            $links = [];
            if ($linksText !== '') {
                foreach (preg_split('/\r?\n/', $linksText) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '') $links[] = $ln;
                }
            }
            $files = upload_delivery_files($_FILES['delivery_files'] ?? []);

            // merge with existing delivery_json files if any
            $st = db()->prepare("SELECT delivery_json FROM orders WHERE id=? LIMIT 1");
            $st->execute([$oid]);
            $row = $st->fetch();
            $existing = [];
            if ($row && !empty($row['delivery_json'])) {
                $existing = json_decode((string)$row['delivery_json'], true);
                if (!is_array($existing)) $existing = [];
            }
            $prevFiles = (isset($existing['files']) && is_array($existing['files'])) ? $existing['files'] : [];
            $allFiles = array_values(array_unique(array_merge($prevFiles, $files)));

            $delivery = [
                'message' => $msg,
                'keys' => $keys,
                'links' => $links,
                'files' => $allFiles,
            ];
            admin_complete_order($oid, $delivery, $payReferral);
            flash_set('success', 'Order completed and delivered.');
            redirect('admin.php?tab=orders&order_id=' . $oid);
        }

        if ($action === 'topup_approve') {
            $tid = (int)($_POST['topup_id'] ?? 0);
            topup_approve($tid, (int)$me['id']);
            flash_set('success', 'Top-up approved.');
            redirect('admin.php?tab=wallet');
        }

        if ($action === 'topup_cancel') {
            $tid = (int)($_POST['topup_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? 'Cancelled'));
            if ($reason === '') $reason = 'Cancelled';
            topup_cancel($tid, (int)$me['id'], $reason);
            flash_set('success', 'Top-up cancelled.');
            redirect('admin.php?tab=wallet');
        }

        if ($action === 'coupon_create') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            if ($code === '') $code = strtoupper(bin2hex(random_bytes(4)));
            if (!preg_match('/^[A-Z0-9]{4,32}$/', $code)) throw new RuntimeException('Invalid coupon code.');
            $dtype = (string)($_POST['discount_type'] ?? 'percent');
            if ($dtype !== 'percent' && $dtype !== 'flat') $dtype = 'percent';
            $dval = (float)($_POST['discount_value'] ?? 0);
            if ($dval < 0) $dval = 0;
            $applies = (string)($_POST['applies_to'] ?? 'all');
            if (!in_array($applies, ['all','category','product','type'], true)) $applies = 'all';
            $catId = (int)($_POST['category_id'] ?? 0) ?: null;
            $prodId = (int)($_POST['product_id'] ?? 0) ?: null;
            $ptype = (string)($_POST['product_type'] ?? '');
            if (!in_array($ptype, ['key','file','account'], true)) $ptype = null;
            $st = db()->prepare("INSERT INTO coupons (code,discount_type,discount_value,is_active,applies_to,category_id,product_id,product_type,note) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([$code, $dtype, money_fmt($dval), 1, $applies, $catId, $prodId, $ptype, trim((string)($_POST['note'] ?? '')) ?: null]);
            flash_set('success', 'Coupon created: ' . $code);
            redirect('admin.php?tab=coupons');
        }

        if ($action === 'coupon_delete') {
            $cid = (int)($_POST['coupon_id'] ?? 0);
            db()->prepare("DELETE FROM coupons WHERE id=?")->execute([$cid]);
            flash_set('success', 'Coupon deleted.');
            redirect('admin.php?tab=coupons');
        }

        if ($action === 'user_wallet_set') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $bal = (float)($_POST['wallet_balance'] ?? 0);
            if ($bal < 0) throw new RuntimeException('Wallet cannot be negative.');
            db()->beginTransaction();
            db()->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$uid]);
            db()->prepare("UPDATE users SET wallet_balance=? WHERE id=?")->execute([money_fmt($bal), $uid]);
            db()->commit();
            flash_set('success', 'Wallet updated.');
            redirect('admin.php?tab=users');
        }

        if ($action === 'user_ban_toggle') {
            $uid = (int)($_POST['user_id'] ?? 0);
            db()->prepare("UPDATE users SET is_banned=IF(is_banned=1,0,1) WHERE id=?")->execute([$uid]);
            flash_set('success', 'User updated.');
            redirect('admin.php?tab=users');
        }

        if ($action === 'user_reset_password') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $new = (string)($_POST['new_password'] ?? '');
            if (strlen($new) < 6) throw new RuntimeException('Password must be at least 6 characters.');
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
            flash_set('success', 'Password reset.');
            redirect('admin.php?tab=users');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', DEBUG ? $e->getMessage() : 'Action failed.');
        redirect('admin.php?tab=' . urlencode((string)($_GET['tab'] ?? 'dashboard')));
    }
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
$orderId = (int)($_GET['order_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);

$submittedCount = (int)(db()->query("SELECT COUNT(*) c FROM orders WHERE status='submitted'")->fetch()['c'] ?? 0);
$pendingTopups = (int)(db()->query("SELECT COUNT(*) c FROM wallet_topups WHERE status='pending'")->fetch()['c'] ?? 0);

page_header('Admin — ' . SITE_NAME);

echo '<div class="admin-head glass">';
echo '<div><h1>Admin Panel</h1><div class="muted small">Categories · Products · Orders · Wallet · Coupons</div></div>';
echo '<div class="admin-tabs">';
echo '<a class="chip ' . ($tab === 'dashboard' ? 'active' : '') . '" href="admin.php?tab=dashboard">Dashboard</a>';
echo '<a class="chip ' . ($tab === 'settings' ? 'active' : '') . '" href="admin.php?tab=settings">Settings</a>';
echo '<a class="chip ' . ($tab === 'categories' ? 'active' : '') . '" href="admin.php?tab=categories">Categories</a>';
echo '<a class="chip ' . ($tab === 'products' ? 'active' : '') . '" href="admin.php?tab=products">Products</a>';
echo '<a class="chip ' . ($tab === 'orders' ? 'active' : '') . '" href="admin.php?tab=orders">Orders' . ($submittedCount > 0 ? ' <span class="count">' . $submittedCount . '</span>' : '') . '</a>';
if (wallet_enabled()) {
    echo '<a class="chip ' . ($tab === 'wallet' ? 'active' : '') . '" href="admin.php?tab=wallet">Wallet' . ($pendingTopups > 0 ? ' <span class="count">' . $pendingTopups . '</span>' : '') . '</a>';
}
echo '<a class="chip ' . ($tab === 'coupons' ? 'active' : '') . '" href="admin.php?tab=coupons">Coupons</a>';
echo '<a class="chip ' . ($tab === 'users' ? 'active' : '') . '" href="admin.php?tab=users">Users</a>';
echo '</div></div>';

if ($tab === 'dashboard') {
    $users = (int)(db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'] ?? 0);
    $cats = (int)(db()->query("SELECT COUNT(*) c FROM categories")->fetch()['c'] ?? 0);
    $prods = (int)(db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'] ?? 0);
    $pending = (int)(db()->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'] ?? 0);
    $completed = (int)(db()->query("SELECT COUNT(*) c FROM orders WHERE status='completed'")->fetch()['c'] ?? 0);
    $rev = (string)(db()->query("SELECT COALESCE(SUM(total_amount-discount_amount),0) s FROM orders WHERE status='completed'")->fetch()['s'] ?? '0.00');

    echo '<div class="grid stats">';
    echo '<div class="glass card statcard"><div class="muted small">Users</div><div class="big">' . $users . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Categories</div><div class="big">' . $cats . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Products</div><div class="big">' . $prods . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Pending</div><div class="big">' . $pending . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Submitted</div><div class="big">' . $submittedCount . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Revenue</div><div class="big neon">₹' . e(money_fmt($rev)) . '</div></div>';
    echo '</div>';

    echo '<div class="glass card"><h2>Quick tips</h2><ul class="muted small">';
    echo '<li>After setup, delete <code>install.php</code> from hosting.</li>';
    echo '<li>Put delivery files into <code>/files</code> (or upload from order delivery form).</li>';
    echo '<li>Set <code>CRON_TOKEN</code> in <code>config.php</code>, then run cron hourly.</li>';
    echo '</ul></div>';
}

if ($tab === 'settings') {
    echo '<div class="glass card"><h2>Store settings</h2>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="save_settings">';

    echo '<div class="row">';
    echo '<label class="check"><input type="checkbox" name="maintenance_mode" value="1" ' . (maintenance_mode() ? 'checked' : '') . '> Maintenance mode</label>';
    echo '<label class="check"><input type="checkbox" name="stop_signup" value="1" ' . (stop_signup() ? 'checked' : '') . '> Stop signup</label>';
    echo '<label class="check"><input type="checkbox" name="stop_login" value="1" ' . (stop_login() ? 'checked' : '') . '> Stop login (admins can still login)</label>';
    echo '</div>';

    echo '<div class="hr"></div>';
    echo '<div class="row">';
    echo '<label class="check"><input type="checkbox" name="wallet_enabled" value="1" ' . (wallet_enabled() ? 'checked' : '') . '> Enable wallet</label>';
    echo '<div><label class="label">Wallet mode</label><select class="select" name="wallet_mode">';
    echo '<option value="partial" ' . (wallet_mode() === 'partial' ? 'selected' : '') . '>Wallet + UPI/Binance (partial)</option>';
    echo '<option value="wallet_only" ' . (wallet_mode() === 'wallet_only' ? 'selected' : '') . '>Wallet only (hide manual product payments)</option>';
    echo '</select></div>';
    echo '</div>';

    echo '<div class="hr"></div>';
    echo '<div class="row">';
    echo '<div><label class="label">UPI VPA</label><input class="input mono" name="upi_vpa" value="' . e(setting_get('upi_vpa', '')) . '" placeholder="name@bank"></div>';
    echo '<div><label class="label">UPI Payee</label><input class="input" name="upi_payee" value="' . e(setting_get('upi_payee', SITE_NAME)) . '" placeholder="Payee name"></div>';
    echo '</div>';
    echo '<label class="label">Binance ID</label><input class="input mono" name="binance_id" value="' . e(setting_get('binance_id', '')) . '" placeholder="Binance Pay ID">';

    echo '<div class="hr"></div>';
    echo '<label class="check"><input type="checkbox" name="announcement_enabled" value="1" ' . (setting_bool('announcement_enabled', false) ? 'checked' : '') . '> Announcement bar</label>';
    echo '<label class="label">Announcement text</label><input class="input" name="announcement_text" value="' . e(setting_get('announcement_text', '')) . '">';

    echo '<div class="hr"></div>';
    echo '<label class="check"><input type="checkbox" name="welcome_enabled" value="1" ' . (setting_bool('welcome_enabled', true) ? 'checked' : '') . '> Welcome popup (first login)</label>';
    echo '<label class="label">Welcome text</label><input class="input" name="welcome_text" value="' . e(setting_get('welcome_text', 'Welcome!')) . '">';

    echo '<div class="hr"></div>';
    echo '<label class="check"><input type="checkbox" name="offer_enabled" value="1" ' . (setting_bool('offer_enabled', false) ? 'checked' : '') . '> Daily offer popup (24h once)</label>';
    echo '<div class="row">';
    echo '<div><label class="label">Offer title</label><input class="input" name="offer_title" value="' . e(setting_get('offer_title', 'Daily Offer')) . '"></div>';
    echo '<div><label class="label">Offer image URL</label><input class="input mono" name="offer_image_url" value="' . e(setting_get('offer_image_url', '')) . '" placeholder="https://..."></div>';
    echo '</div>';
    echo '<label class="label">Offer text</label><textarea class="input" name="offer_text" rows="3">' . e(setting_get('offer_text', '')) . '</textarea>';

    echo '<div class="hr"></div>';
    echo '<h3 style="margin:0">Telegram</h3><div class="row">';
    echo '<div><label class="label">Bot token</label><input class="input mono" name="telegram_bot_token" value="' . e(setting_get('telegram_bot_token', '')) . '"></div>';
    echo '<div><label class="label">Chat ID</label><input class="input mono" name="telegram_chat_id" value="' . e(setting_get('telegram_chat_id', '')) . '"></div>';
    echo '</div>';

    echo '<div class="hr"></div>';
    echo '<h3 style="margin:0">SMTP (Forgot password)</h3>';
    echo '<label class="check"><input type="checkbox" name="smtp_enabled" value="1" ' . (setting_bool('smtp_enabled', false) ? 'checked' : '') . '> Enable SMTP</label>';
    echo '<div class="row">';
    echo '<div><label class="label">Host</label><input class="input mono" name="smtp_host" value="' . e(setting_get('smtp_host', '')) . '"></div>';
    echo '<div><label class="label">Port</label><input class="input mono" name="smtp_port" value="' . e(setting_get('smtp_port', '587')) . '"></div>';
    echo '</div>';
    echo '<div class="row">';
    echo '<div><label class="label">User</label><input class="input mono" name="smtp_user" value="' . e(setting_get('smtp_user', '')) . '"></div>';
    echo '<div><label class="label">Pass</label><input class="input mono" type="password" name="smtp_pass" value="' . e(setting_get('smtp_pass', '')) . '"></div>';
    echo '</div>';
    echo '<div class="row">';
    echo '<div><label class="label">From email</label><input class="input mono" name="smtp_from_email" value="' . e(setting_get('smtp_from_email', '')) . '"></div>';
    echo '<div><label class="label">From name</label><input class="input" name="smtp_from_name" value="' . e(setting_get('smtp_from_name', SITE_NAME)) . '"></div>';
    echo '</div>';
    echo '<label class="label">Notification email (orders/topups)</label><input class="input mono" name="notify_email" value="' . e(setting_get('notify_email', '')) . '" placeholder="admin@example.com">';

    echo '<button class="btn btn-full" type="submit">Save settings</button>';
    echo '</form></div>';
}

if ($tab === 'categories') {
    $cats = db()->query("SELECT * FROM categories ORDER BY sort_order ASC, id DESC")->fetchAll();
    echo '<div class="grid admin-grid">';
    echo '<div class="glass card"><h2>Add category</h2>';
    echo '<form method="post" enctype="multipart/form-data" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="category_add">';
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="120">';
    echo '<label class="label">Image URL (optional)</label><input class="input mono" name="image_url" placeholder="https://...">';
    echo '<label class="label">Or upload image (JPG/PNG)</label><input class="input" type="file" name="image_file" accept="image/png,image/jpeg">';
    echo '<div class="row"><div><label class="label">Sort order</label><input class="input mono" type="number" name="sort_order" value="0"></div>';
    echo '<label class="check" style="margin-top:22px"><input type="checkbox" name="is_hidden" value="1"> Hidden</label></div>';
    echo '<button class="btn btn-full" type="submit">Add</button></form></div>';

    echo '<div class="glass card"><h2>Categories</h2>';
    if (!$cats) echo '<div class="muted">No categories yet.</div>';
    else {
        echo '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Name</th><th>Hidden</th><th>Sort</th><th>Update</th><th>Delete</th></tr></thead><tbody>';
        foreach ($cats as $c) {
            echo '<tr>';
            echo '<td class="mono">#' . (int)$c['id'] . '</td>';
            echo '<td>' . e((string)$c['name']) . '</td>';
            echo '<td>' . ((int)$c['is_hidden'] ? '<span class="badge bad">yes</span>' : '<span class="badge good">no</span>') . '</td>';
            echo '<td class="mono">' . (int)$c['sort_order'] . '</td>';
            echo '<td>';
            echo '<form method="post" enctype="multipart/form-data" class="inline">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="category_update">';
            echo '<input type="hidden" name="category_id" value="' . (int)$c['id'] . '">';
            echo '<input class="input input-sm" name="name" value="' . e((string)$c['name']) . '" maxlength="120">';
            echo '<input class="input input-sm mono" name="image_url" value="' . e((string)($c['image_url'] ?? '')) . '" placeholder="img url">';
            echo '<input class="input input-sm" type="file" name="image_file" accept="image/png,image/jpeg">';
            echo '<input class="input input-sm mono" type="number" name="sort_order" value="' . (int)$c['sort_order'] . '" style="max-width:90px">';
            echo '<label class="check small"><input type="checkbox" name="is_hidden" value="1" ' . ((int)$c['is_hidden'] ? 'checked' : '') . '> hidden</label>';
            echo '<button class="btn btn-ghost btn-sm" type="submit">Save</button>';
            echo '</form>';
            echo '</td>';
            echo '<td><form method="post" class="inline" onsubmit="return confirm(\'Delete category?\')">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="category_delete"><input type="hidden" name="category_id" value="' . (int)$c['id'] . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">Delete</button></form></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
}

if ($tab === 'products') {
    $cats = db()->query("SELECT id,name FROM categories ORDER BY sort_order ASC, id DESC")->fetchAll();
    if ($productId > 0) {
        $st = db()->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
        $st->execute([$productId]);
        $p = $st->fetch();
        if (!$p) {
            echo '<div class="glass card"><h2>Product not found</h2></div>';
        } else {
            $vars = db()->prepare("SELECT * FROM product_variants WHERE product_id=? ORDER BY sort_order ASC, id ASC");
            $vars->execute([$productId]);
            $rows = $vars->fetchAll();

            echo '<div class="glass card"><div class="row"><div><h2>Variants for ' . e((string)$p['name']) . '</h2><div class="muted small">Add pricing options like: 7 DAYS, 30 DAYS</div></div><a class="btn btn-ghost" href="admin.php?tab=products">Back</a></div></div>';
            echo '<div class="glass card"><h2>Add variant</h2>';
            echo '<form method="post" class="form"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="variant_add"><input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<div class="row"><div><label class="label">Label</label><input class="input" name="label" required placeholder="7 DAYS"></div>';
            echo '<div><label class="label">Price</label><input class="input mono" type="number" step="0.01" min="0" name="price" required value="0"></div>';
            echo '<div><label class="label">Stock</label><input class="input mono" type="number" min="0" name="stock" required value="0"></div></div>';
            echo '<button class="btn btn-full" type="submit">Add</button></form></div>';

            echo '<div class="glass card"><h2>Variants</h2>';
            if (!$rows) echo '<div class="muted">No variants yet.</div>';
            else {
                echo '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Label</th><th>Price</th><th>Stock</th><th>Hidden</th><th>Sort</th><th>Actions</th></tr></thead><tbody>';
                foreach ($rows as $v) {
                    echo '<tr>';
                    echo '<td class="mono">#' . (int)$v['id'] . '</td>';
                    echo '<td>' . e((string)$v['label']) . '</td>';
                    echo '<td>₹' . e(money_fmt($v['price'])) . '</td>';
                    echo '<td class="mono">' . (int)$v['stock'] . '</td>';
                    echo '<td>' . ((int)$v['is_hidden'] ? '<span class="badge bad">yes</span>' : '<span class="badge good">no</span>') . '</td>';
                    echo '<td class="mono">' . (int)$v['sort_order'] . '</td>';
                    echo '<td>';
                    echo '<form method="post" class="inline">';
                    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="variant_update">';
                    echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '"><input type="hidden" name="variant_id" value="' . (int)$v['id'] . '">';
                    echo '<input class="input input-sm" name="label" value="' . e((string)$v['label']) . '" maxlength="80">';
                    echo '<input class="input input-sm mono" type="number" step="0.01" min="0" name="price" value="' . e(money_fmt($v['price'])) . '">';
                    echo '<input class="input input-sm mono" type="number" min="0" name="stock" value="' . (int)$v['stock'] . '">';
                    echo '<input class="input input-sm mono" type="number" name="sort_order" value="' . (int)$v['sort_order'] . '" style="max-width:80px">';
                    echo '<label class="check small"><input type="checkbox" name="is_hidden" value="1" ' . ((int)$v['is_hidden'] ? 'checked' : '') . '> hidden</label>';
                    echo '<button class="btn btn-ghost btn-sm" type="submit">Save</button>';
                    echo '</form>';
                    echo '<form method="post" class="inline" onsubmit="return confirm(\'Delete variant?\')">';
                    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="variant_delete"><input type="hidden" name="product_id" value="' . (int)$p['id'] . '"><input type="hidden" name="variant_id" value="' . (int)$v['id'] . '">';
                    echo '<button class="btn btn-danger btn-sm" type="submit">Delete</button></form>';
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div>';
        }
        page_footer();
        exit;
    }

    $products = db()->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC LIMIT 200")->fetchAll();
    echo '<div class="grid admin-grid">';
    echo '<div class="glass card"><h2>Add product</h2>';
    echo '<form method="post" enctype="multipart/form-data" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="product_add">';
    echo '<label class="label">Type</label><select class="select" name="type"><option value="key">Key / License</option><option value="file">File / Digital</option><option value="account">Account (single stock)</option></select>';
    echo '<label class="label">Category</label><select class="select" name="category_id"><option value="0">—</option>';
    foreach ($cats as $c) echo '<option value="' . (int)$c['id'] . '">' . e((string)$c['name']) . '</option>';
    echo '</select>';
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="190">';
    echo '<label class="label">Short description</label><input class="input" name="short_desc" maxlength="255" placeholder="Card subtitle">';
    echo '<label class="label">Description</label><textarea class="input" name="description" rows="4" placeholder="Full details"></textarea>';
    echo '<label class="label">Image URL (optional)</label><input class="input mono" name="image_url" placeholder="https://...">';
    echo '<label class="label">Or upload image (JPG/PNG)</label><input class="input" type="file" name="image_file" accept="image/png,image/jpeg">';
    echo '<div class="row"><div><label class="label">Account stock (0/1)</label><input class="input mono" type="number" name="stock" value="1" min="0" max="1"></div>';
    echo '<div><label class="label">Account price (₹)</label><input class="input mono" type="number" step="0.01" min="0" name="account_price" value="0"></div></div>';
    echo '<label class="check"><input type="checkbox" name="is_hidden" value="1"> Hidden</label>';
    echo '<button class="btn btn-full" type="submit">Add product</button>';
    echo '<div class="muted small">For key/file products, add variants after creating the product.</div>';
    echo '</form></div>';

    echo '<div class="glass card"><h2>Products</h2>';
    if (!$products) echo '<div class="muted">No products yet.</div>';
    else {
        echo '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Name</th><th>Type</th><th>Category</th><th>Hidden</th><th>Manage</th></tr></thead><tbody>';
        foreach ($products as $p) {
            echo '<tr>';
            echo '<td class="mono">#' . (int)$p['id'] . '</td>';
            echo '<td>' . e((string)$p['name']) . '</td>';
            echo '<td><span class="badge">' . e((string)$p['type']) . '</span></td>';
            echo '<td class="muted small">' . e((string)($p['category_name'] ?? '')) . '</td>';
            echo '<td>' . ((int)$p['is_hidden'] ? '<span class="badge bad">yes</span>' : '<span class="badge good">no</span>') . '</td>';
            echo '<td>';
            echo '<a class="btn btn-ghost btn-sm" href="admin.php?tab=products&product_id=' . (int)$p['id'] . '">Variants</a> ';
            echo '<form method="post" class="inline">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="product_update"><input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            if ((string)$p['type'] === 'account') echo '<input class="input input-sm mono" type="number" min="0" max="1" name="stock" value="' . (int)$p['stock'] . '" style="max-width:70px">';
            else echo '<input type="hidden" name="stock" value="0">';
            echo '<label class="check small"><input type="checkbox" name="is_hidden" value="1" ' . ((int)$p['is_hidden'] ? 'checked' : '') . '> hidden</label>';
            echo '<button class="btn btn-ghost btn-sm" type="submit">Save</button>';
            echo '</form> ';
            echo '<form method="post" class="inline" onsubmit="return confirm(\'Delete product?\')">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="product_delete"><input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
}

if ($tab === 'orders') {
    $status = (string)($_GET['status'] ?? '');
    if ($status !== '' && !in_array($status, ['pending','submitted','completed','cancelled'], true)) $status = '';

    if ($orderId > 0) {
        $st = db()->prepare("SELECT o.*, u.email, u.username FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? LIMIT 1");
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) {
            echo '<div class="glass card"><h2>Order not found</h2></div>';
            page_footer();
            exit;
        }
        $it = db()->prepare("SELECT * FROM order_items WHERE order_id=?");
        $it->execute([$orderId]);
        $items = $it->fetchAll();

        echo '<div class="glass card"><div class="row"><div><h2>Order #' . (int)$o['id'] . '</h2><div class="muted small">User: ' . e((string)$o['username']) . ' · ' . e((string)$o['email']) . '</div></div><a class="btn btn-ghost" href="admin.php?tab=orders">Back</a></div></div>';
        echo '<div class="glass card"><div class="row">';
        echo '<div><div class="muted small">Status</div><div><span class="badge">' . e((string)$o['status']) . '</span></div></div>';
        echo '<div><div class="muted small">Total</div><div class="price">₹' . e(money_fmt($o['total_amount'])) . '</div></div>';
        echo '<div><div class="muted small">Discount</div><div>₹' . e(money_fmt($o['discount_amount'])) . '</div></div>';
        echo '<div><div class="muted small">Wallet used</div><div>₹' . e(money_fmt($o['wallet_used'])) . '</div></div>';
        echo '<div><div class="muted small">Remaining</div><div class="mono big">₹' . e(money_fmt($o['pay_amount'])) . '</div></div>';
        echo '</div>';
        echo '<div class="muted small">Method: <b>' . e((string)($o['payment_method'] ?? '')) . '</b> · Ref: <span class="mono">' . e((string)($o['reference_id'] ?? '')) . '</span></div>';
        echo '<div class="muted small">WhatsApp: <span class="mono">' . e((string)($o['whatsapp_number'] ?? '')) . '</span> · Telegram: <span class="mono">' . e((string)($o['telegram_username'] ?? '')) . '</span></div>';
        if (!empty($o['screenshot_path'])) echo '<div class="muted small">Screenshot: <a target="_blank" rel="noopener" href="' . e((string)$o['screenshot_path']) . '">view</a></div>';
        echo '<div class="hr"></div><div class="muted small">Items</div><ul class="list">';
        foreach ($items as $r) echo '<li>' . e((string)$r['product_name']) . ' <span class="muted small">' . e((string)($r['variant_label'] ?? '')) . '</span> <span class="muted small">×' . (int)$r['qty'] . '</span></li>';
        echo '</ul></div>';

        echo '<div class="grid admin-grid">';
        echo '<div class="glass card"><h2>Cancel order</h2>';
        echo '<form method="post" class="form" onsubmit="return confirm(\'Cancel order?\')">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="order_cancel"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">';
        echo '<label class="label">Reason</label><input class="input" name="reason" value="Cancelled by admin">';
        echo '<button class="btn btn-danger btn-full" type="submit">Cancel</button></form></div>';

        echo '<div class="glass card"><h2>Deliver & complete (E-Box)</h2>';
        echo '<form method="post" enctype="multipart/form-data" class="form">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="order_complete"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">';
        echo '<label class="label">Message / Instructions</label><textarea class="input" name="delivery_message" rows="4" placeholder="Instructions, warnings, thank you..."></textarea>';
        echo '<label class="label">Keys (one per line)</label><textarea class="input mono" name="delivery_keys" rows="4" placeholder="KEY-1\nKEY-2"></textarea>';
        echo '<label class="label">Links (one per line)</label><textarea class="input mono" name="delivery_links" rows="3" placeholder="https://..."></textarea>';
        echo '<label class="label">Attach files (optional)</label><input class="input" type="file" name="delivery_files[]" multiple>';
        echo '<label class="check"><input type="checkbox" name="pay_referral" value="1" checked> Pay referral commission (3%) if eligible</label>';
        echo '<button class="btn btn-full" type="submit">Complete order</button>';
        echo '</form></div>';
        echo '</div>';

        page_footer();
        exit;
    }

    $sql = "SELECT o.id,o.status,o.total_amount,o.discount_amount,o.wallet_used,o.pay_amount,o.payment_method,o.reference_id,o.created_at,
                   u.email,u.username
            FROM orders o JOIN users u ON u.id=o.user_id";
    $params = [];
    if ($status !== '') { $sql .= " WHERE o.status=?"; $params[] = $status; }
    $sql .= " ORDER BY o.id DESC LIMIT 200";
    $st = db()->prepare($sql);
    $st->execute($params);
    $orders = $st->fetchAll();

    echo '<div class="glass card"><div class="row"><div><h2>Orders</h2><div class="muted small">Click an order to review proof & deliver</div></div>';
    echo '<div class="row"><a class="btn btn-ghost btn-sm" href="admin.php?tab=orders">All</a>';
    foreach (['pending','submitted','completed','cancelled'] as $s) echo '<a class="btn btn-ghost btn-sm" href="admin.php?tab=orders&status=' . e($s) . '">' . e($s) . '</a>';
    echo '</div></div></div>';

    if (!$orders) {
        echo '<div class="glass card"><div class="muted">No orders.</div></div>';
    } else {
        echo '<div class="glass card table-wrap"><table class="table"><thead><tr><th>#</th><th>User</th><th>Total</th><th>Wallet</th><th>Remaining</th><th>Method</th><th>Ref</th><th>Status</th><th>Open</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            $stt = (string)$o['status'];
            $b = 'badge' . ($stt === 'completed' ? ' good' : ($stt === 'cancelled' ? ' bad' : ($stt === 'submitted' ? ' warn' : '')));
            echo '<tr>';
            echo '<td class="mono">#' . (int)$o['id'] . '<div class="muted small">' . e((string)$o['created_at']) . '</div></td>';
            echo '<td>' . e((string)$o['username']) . '<div class="muted small">' . e((string)$o['email']) . '</div></td>';
            echo '<td>₹' . e(money_fmt($o['total_amount'])) . '</td>';
            echo '<td>₹' . e(money_fmt($o['wallet_used'])) . '</td>';
            echo '<td>₹' . e(money_fmt($o['pay_amount'])) . '</td>';
            echo '<td>' . e((string)$o['payment_method']) . '</td>';
            echo '<td class="mono small">' . e((string)($o['reference_id'] ?? '')) . '</td>';
            echo '<td><span class="' . e($b) . '">' . e($stt) . '</span></td>';
            echo '<td><a class="btn btn-ghost btn-sm" href="admin.php?tab=orders&order_id=' . (int)$o['id'] . '">Open</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($tab === 'wallet') {
    if (!wallet_enabled()) {
        echo '<div class="glass card"><h2>Wallet disabled</h2><p class="muted">Enable wallet in Settings to manage top-ups.</p></div>';
        page_footer();
        exit;
    }
    $rows = db()->query("SELECT wt.*, u.email, u.username FROM wallet_topups wt JOIN users u ON u.id=wt.user_id ORDER BY wt.id DESC LIMIT 200")->fetchAll();
    echo '<div class="glass card"><h2>Wallet top-ups</h2><div class="muted small">Approve to add balance</div></div>';
    if (!$rows) {
        echo '<div class="glass card"><div class="muted">No top-ups yet.</div></div>';
    } else {
        echo '<div class="glass card table-wrap"><table class="table"><thead><tr><th>#</th><th>User</th><th>Amount</th><th>Method</th><th>Ref</th><th>Shot</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $stt = (string)$t['status'];
            $b = 'badge' . ($stt === 'approved' ? ' good' : ($stt === 'cancelled' ? ' bad' : ' warn'));
            $shot = (string)($t['screenshot_path'] ?? '');
            $shotHtml = $shot ? '<a target="_blank" rel="noopener" href="' . e($shot) . '">view</a>' : '<span class="muted small">—</span>';
            echo '<tr>';
            echo '<td class="mono">#' . (int)$t['id'] . '<div class="muted small">' . e((string)$t['created_at']) . '</div></td>';
            echo '<td>' . e((string)$t['username']) . '<div class="muted small">' . e((string)$t['email']) . '</div></td>';
            echo '<td>₹' . e(money_fmt($t['amount'])) . '</td>';
            echo '<td>' . e((string)$t['method']) . '</td>';
            echo '<td class="mono small">' . e((string)$t['reference_id']) . '</td>';
            echo '<td class="small">' . $shotHtml . '</td>';
            echo '<td><span class="' . e($b) . '">' . e($stt) . '</span></td>';
            echo '<td>';
            if ($stt === 'pending') {
                echo '<form method="post" class="inline">';
                echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="topup_approve"><input type="hidden" name="topup_id" value="' . (int)$t['id'] . '">';
                echo '<button class="btn btn-ghost btn-sm" type="submit">Approve</button></form>';
                echo '<form method="post" class="inline" onsubmit="return confirm(\'Cancel top-up?\')">';
                echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="topup_cancel"><input type="hidden" name="topup_id" value="' . (int)$t['id'] . '">';
                echo '<input class="input input-sm" name="reason" value="Invalid proof" style="max-width:160px">';
                echo '<button class="btn btn-danger btn-sm" type="submit">Cancel</button></form>';
            } else {
                echo '<span class="muted small">—</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($tab === 'coupons') {
    $cats = db()->query("SELECT id,name FROM categories ORDER BY sort_order ASC, id DESC")->fetchAll();
    $products = db()->query("SELECT id,name FROM products ORDER BY id DESC LIMIT 200")->fetchAll();
    $rows = db()->query("SELECT * FROM coupons ORDER BY id DESC LIMIT 200")->fetchAll();

    echo '<div class="grid admin-grid">';
    echo '<div class="glass card"><h2>Create coupon</h2>';
    echo '<form method="post" class="form"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="coupon_create">';
    echo '<label class="label">Code (leave empty to auto)</label><input class="input mono" name="code" placeholder="SAVE10">';
    echo '<div class="row"><div><label class="label">Type</label><select class="select" name="discount_type"><option value="percent">Percent</option><option value="flat">Flat ₹</option></select></div>';
    echo '<div><label class="label">Value</label><input class="input mono" type="number" step="0.01" min="0" name="discount_value" value="10"></div></div>';
    echo '<label class="label">Applies to</label><select class="select" name="applies_to"><option value="all">All</option><option value="type">Product type</option><option value="category">Category</option><option value="product">Product</option></select>';
    echo '<div class="row">';
    echo '<div><label class="label">Product type</label><select class="select" name="product_type"><option value="">—</option><option value="key">key</option><option value="file">file</option><option value="account">account</option></select></div>';
    echo '<div><label class="label">Category</label><select class="select" name="category_id"><option value="0">—</option>';
    foreach ($cats as $c) echo '<option value="' . (int)$c['id'] . '">' . e((string)$c['name']) . '</option>';
    echo '</select></div></div>';
    echo '<label class="label">Product</label><select class="select" name="product_id"><option value="0">—</option>';
    foreach ($products as $p) echo '<option value="' . (int)$p['id'] . '">' . e((string)$p['name']) . '</option>';
    echo '</select>';
    echo '<label class="label">Note (optional)</label><input class="input" name="note">';
    echo '<button class="btn btn-full" type="submit">Create</button></form></div>';

    echo '<div class="glass card"><h2>Coupons</h2>';
    if (!$rows) echo '<div class="muted">No coupons.</div>';
    else {
        echo '<div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Active</th><th>Applies</th><th>Delete</th></tr></thead><tbody>';
        foreach ($rows as $c) {
            echo '<tr>';
            echo '<td class="mono">' . e((string)$c['code']) . '</td>';
            echo '<td>' . e((string)$c['discount_type']) . '</td>';
            echo '<td>' . e(money_fmt($c['discount_value'])) . '</td>';
            echo '<td>' . ((int)$c['is_active'] ? '<span class="badge good">yes</span>' : '<span class="badge bad">no</span>') . '</td>';
            echo '<td class="muted small">' . e((string)$c['applies_to']) . '</td>';
            echo '<td><form method="post" class="inline" onsubmit="return confirm(\'Delete coupon?\')">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="coupon_delete"><input type="hidden" name="coupon_id" value="' . (int)$c['id'] . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">Delete</button></form></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
}

if ($tab === 'users') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare("SELECT id,email,username,wallet_balance,is_admin,is_banned FROM users WHERE email LIKE ? OR username LIKE ? ORDER BY id DESC LIMIT 200");
        $st->execute(['%' . $q . '%', '%' . $q . '%']);
        $users = $st->fetchAll();
    } else {
        $users = db()->query("SELECT id,email,username,wallet_balance,is_admin,is_banned FROM users ORDER BY id DESC LIMIT 200")->fetchAll();
    }
    echo '<div class="glass card"><h2>Users</h2>';
    echo '<form method="get" class="row"><input type="hidden" name="tab" value="users"><input class="input" name="q" value="' . e($q) . '" placeholder="Search email/username"><button class="btn btn-ghost" type="submit">Search</button></form>';
    echo '</div>';

    echo '<div class="glass card table-wrap"><table class="table"><thead><tr><th>User</th><th>Wallet</th><th>Admin</th><th>Banned</th><th>Actions</th></tr></thead><tbody>';
    foreach ($users as $usr) {
        echo '<tr>';
        echo '<td>' . e((string)$usr['username']) . '<div class="muted small">' . e((string)$usr['email']) . '</div></td>';
        echo '<td class="mono">₹' . e(money_fmt($usr['wallet_balance'])) . '</td>';
        echo '<td>' . (!empty($usr['is_admin']) ? '<span class="badge warn">yes</span>' : '<span class="muted small">no</span>') . '</td>';
        echo '<td>' . (!empty($usr['is_banned']) ? '<span class="badge bad">yes</span>' : '<span class="badge good">no</span>') . '</td>';
        echo '<td>';
        echo '<form method="post" class="inline">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="user_wallet_set"><input type="hidden" name="user_id" value="' . (int)$usr['id'] . '">';
        echo '<input class="input input-sm mono" type="number" step="0.01" min="0" name="wallet_balance" value="' . e(money_fmt($usr['wallet_balance'])) . '">';
        echo '<button class="btn btn-ghost btn-sm" type="submit">Set wallet</button>';
        echo '</form>';
        echo '<form method="post" class="inline">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="user_ban_toggle"><input type="hidden" name="user_id" value="' . (int)$usr['id'] . '">';
        echo '<button class="btn btn-danger btn-sm" type="submit">' . (!empty($usr['is_banned']) ? 'Unban' : 'Ban') . '</button></form>';
        echo '<form method="post" class="inline">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="user_reset_password"><input type="hidden" name="user_id" value="' . (int)$usr['id'] . '">';
        echo '<input class="input input-sm mono" type="text" name="new_password" placeholder="new pass" minlength="6">';
        echo '<button class="btn btn-ghost btn-sm" type="submit">Reset pass</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

page_footer();

