<?php
require_once __DIR__ . '/config.php';

$me = require_admin();

function admin_cancel_order(int $oid): void
{
    db()->beginTransaction();
    $st = db()->prepare("SELECT id,user_id,product_id,qty,wallet_used,status FROM orders WHERE id=? FOR UPDATE");
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o) {
        db()->rollBack();
        throw new RuntimeException('Order not found.');
    }
    $status = (string)$o['status'];
    if ($status === 'cancelled') {
        db()->rollBack();
        return;
    }
    if ($status === 'completed') {
        db()->rollBack();
        throw new RuntimeException('Cannot cancel a completed order.');
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
}

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_product') {
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            $file = trim((string)($_POST['download_file'] ?? ''));
            $file = $file === '' ? null : basename($file);

            if ($name === '' || strlen($name) > 190) throw new RuntimeException('Product name is required (max 190 chars).');
            if ($price < 0) throw new RuntimeException('Invalid price.');
            if ($stock < 0) $stock = 0;
            if ($file !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,250}$/', $file)) {
                throw new RuntimeException('Invalid download file name.');
            }

            $st = db()->prepare("INSERT INTO products (name,description,price,stock,is_hidden,download_file) VALUES (?,?,?,?,?,?)");
            $st->execute([$name, ($desc === '' ? null : $desc), money_fmt($price), $stock, $hidden, $file]);
            flash_set('success', 'Product added.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'delete_product') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $st = db()->prepare("DELETE FROM products WHERE id=?");
            $st->execute([$pid]);
            flash_set('success', 'Product deleted.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'update_product') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
            if ($stock < 0) $stock = 0;
            $st = db()->prepare("UPDATE products SET stock=?, is_hidden=? WHERE id=?");
            $st->execute([$stock, $hidden, $pid]);
            flash_set('success', 'Product updated.');
            redirect('admin.php?tab=products');
        }

        if ($action === 'update_order_status') {
            $oid = (int)($_POST['order_id'] ?? 0);
            $new = (string)($_POST['status'] ?? '');
            $allowed = ['pending', 'submitted', 'completed', 'cancelled'];
            if (!in_array($new, $allowed, true)) throw new RuntimeException('Invalid status.');

            if ($new === 'cancelled') {
                admin_cancel_order($oid);
                flash_set('success', 'Order cancelled (wallet refunded / stock restored).');
                redirect('admin.php?tab=orders');
            }

            if ($new === 'completed') {
                $st = db()->prepare("UPDATE orders SET status='completed', completed_at=NOW() WHERE id=? AND status IN ('submitted','pending')");
                $st->execute([$oid]);
                flash_set('success', 'Order marked completed.');
                redirect('admin.php?tab=orders');
            }

            if ($new === 'submitted') {
                $st = db()->prepare("UPDATE orders SET status='submitted', submitted_at=COALESCE(submitted_at, NOW()) WHERE id=? AND status='pending'");
                $st->execute([$oid]);
                flash_set('success', 'Order marked submitted.');
                redirect('admin.php?tab=orders');
            }

            if ($new === 'pending') {
                flash_set('error', 'For safety, set status using the normal flow (or cancel/complete).');
                redirect('admin.php?tab=orders');
            }
        }

        if ($action === 'update_wallet') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $bal = (float)($_POST['wallet_balance'] ?? 0);
            if ($bal < 0) throw new RuntimeException('Wallet cannot be negative.');
            db()->beginTransaction();
            $st = db()->prepare("SELECT id FROM users WHERE id=? FOR UPDATE");
            $st->execute([$uid]);
            if (!$st->fetch()) {
                db()->rollBack();
                throw new RuntimeException('User not found.');
            }
            $up = db()->prepare("UPDATE users SET wallet_balance=? WHERE id=?");
            $up->execute([money_fmt($bal), $uid]);
            db()->commit();
            flash_set('success', 'Wallet updated.');
            redirect('admin.php?tab=wallet');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $msg = $e->getMessage();
        if (!DEBUG && ($action === 'delete_product')) {
            $msg = 'Cannot delete product (it may have orders). Hide it instead.';
        } elseif (!DEBUG) {
            $msg = 'Action failed. Try again.';
        }
        flash_set('error', $msg);
        redirect('admin.php');
    }
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
page_header('Admin — ' . SITE_NAME);

echo '<div class="admin-head glass">';
echo '<div><h1>Admin Panel</h1><div class="muted small">Manage products, orders, and wallets</div></div>';
echo '<div class="admin-tabs">';
echo '<a class="chip ' . ($tab === 'dashboard' ? 'active' : '') . '" href="admin.php?tab=dashboard">Dashboard</a>';
echo '<a class="chip ' . ($tab === 'orders' ? 'active' : '') . '" href="admin.php?tab=orders">Orders</a>';
echo '<a class="chip ' . ($tab === 'products' ? 'active' : '') . '" href="admin.php?tab=products">Products</a>';
echo '<a class="chip ' . ($tab === 'wallet' ? 'active' : '') . '" href="admin.php?tab=wallet">Wallet</a>';
echo '</div>';
echo '</div>';

if ($tab === 'dashboard') {
    $stats = [
        'users' => 0,
        'products' => 0,
        'pending' => 0,
        'submitted' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'revenue' => '0.00',
    ];
    $stats['users'] = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    $stats['products'] = (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
    foreach (['pending', 'submitted', 'completed', 'cancelled'] as $s) {
        $st = db()->prepare("SELECT COUNT(*) c FROM orders WHERE status=?");
        $st->execute([$s]);
        $stats[$s] = (int)$st->fetch()['c'];
    }
    $stats['revenue'] = (string)(db()->query("SELECT COALESCE(SUM(total_amount),0) s FROM orders WHERE status='completed'")->fetch()['s'] ?? '0.00');

    echo '<div class="grid stats">';
    echo '<div class="glass card statcard"><div class="muted small">Users</div><div class="big">' . (int)$stats['users'] . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Products</div><div class="big">' . (int)$stats['products'] . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Pending</div><div class="big">' . (int)$stats['pending'] . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Submitted</div><div class="big">' . (int)$stats['submitted'] . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Completed</div><div class="big">' . (int)$stats['completed'] . '</div></div>';
    echo '<div class="glass card statcard"><div class="muted small">Revenue</div><div class="big neon">₹' . e(money_fmt($stats['revenue'])) . '</div></div>';
    echo '</div>';

    echo '<div class="glass card">';
    echo '<h2>Setup tips</h2>';
    echo '<ul class="muted small">';
    echo '<li>After setup, delete <code>install.php</code> from hosting.</li>';
    echo '<li>Put digital files in <code>/files</code> (inside <code>public_html</code>) and set product <b>download file</b> to the file name.</li>';
    echo '<li>Set a random <code>CRON_TOKEN</code> in <code>config.php</code>, then run <code>cron.php?token=...</code> hourly.</li>';
    echo '</ul>';
    echo '</div>';
}

if ($tab === 'orders') {
    $st = db()->query("SELECT o.*, u.email, p.name AS product_name
                       FROM orders o
                       JOIN users u ON u.id=o.user_id
                       JOIN products p ON p.id=o.product_id
                       ORDER BY o.id DESC
                       LIMIT 100");
    $orders = $st->fetchAll();

    echo '<div class="glass card">';
    echo '<h2>Orders</h2>';
    echo '<div class="muted small">Latest 100 orders</div>';
    echo '</div>';

    if (!$orders) {
        echo '<div class="glass card"><div class="muted">No orders yet.</div></div>';
    } else {
        echo '<div class="glass card table-wrap">';
        echo '<table class="table">';
        echo '<thead><tr><th>#</th><th>User</th><th>Product</th><th>Total</th><th>Wallet</th><th>UPI</th><th>UTR</th><th>Shot</th><th>Status</th><th>Update</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            $status = (string)$o['status'];
            $badge = 'badge';
            if ($status === 'completed') $badge .= ' good';
            if ($status === 'cancelled') $badge .= ' bad';
            if ($status === 'submitted') $badge .= ' warn';

            $shot = (string)($o['screenshot_path'] ?? '');
            $shotHtml = $shot ? ('<a target="_blank" rel="noopener" href="' . e($shot) . '">view</a>') : '<span class="muted small">—</span>';

            echo '<tr>';
            echo '<td class="mono">#' . (int)$o['id'] . '</td>';
            echo '<td>' . e($o['email']) . '</td>';
            echo '<td>' . e($o['product_name']) . ' <span class="muted small">×' . (int)$o['qty'] . '</span></td>';
            echo '<td>₹' . e(money_fmt($o['total_amount'])) . '</td>';
            echo '<td>₹' . e(money_fmt($o['wallet_used'])) . '</td>';
            echo '<td>₹' . e(money_fmt($o['upi_amount'])) . '</td>';
            echo '<td class="mono small">' . e((string)($o['utr'] ?? '')) . '</td>';
            echo '<td class="small">' . $shotHtml . '</td>';
            echo '<td><span class="' . e($badge) . '">' . e($status) . '</span></td>';
            echo '<td>';
            echo '<form method="post" class="inline">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="update_order_status">';
            echo '<input type="hidden" name="order_id" value="' . (int)$o['id'] . '">';
            echo '<select class="select" name="status">';
            foreach (['submitted' => 'submitted', 'completed' => 'completed', 'cancelled' => 'cancelled'] as $k => $v) {
                echo '<option value="' . e($k) . '">' . e($v) . '</option>';
            }
            echo '</select>';
            echo '<button class="btn btn-ghost btn-sm" type="submit">Save</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}

if ($tab === 'products') {
    $products = db()->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();

    echo '<div class="grid admin-grid">';
    echo '<div class="glass card">';
    echo '<h2>Add product</h2>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="add_product">';
    echo '<label class="label">Name</label><input class="input" name="name" required maxlength="190" placeholder="Product name">';
    echo '<label class="label">Description</label><textarea class="input" name="description" rows="4" placeholder="Optional"></textarea>';
    echo '<div class="row">';
    echo '<div><label class="label">Price (₹)</label><input class="input mono" type="number" step="0.01" min="0" name="price" required value="0"></div>';
    echo '<div><label class="label">Stock</label><input class="input mono" type="number" min="0" name="stock" required value="0"></div>';
    echo '</div>';
    echo '<label class="label">Download file (optional)</label><input class="input mono" name="download_file" placeholder="example.zip">';
    echo '<label class="check"><input type="checkbox" name="is_hidden" value="1"> Hidden (don’t show on homepage)</label>';
    echo '<button class="btn btn-full" type="submit">Add</button>';
    echo '<div class="muted small">Upload the actual file into <code>/files</code> (inside <code>public_html</code>).</div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="glass card">';
    echo '<h2>Products</h2>';
    if (!$products) {
        echo '<div class="muted">No products yet.</div>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table class="table">';
        echo '<thead><tr><th>#</th><th>Name</th><th>Price</th><th>Stock</th><th>Hidden</th><th>File</th><th>Update</th><th>Delete</th></tr></thead><tbody>';
        foreach ($products as $p) {
            echo '<tr>';
            echo '<td class="mono">#' . (int)$p['id'] . '</td>';
            echo '<td>' . e((string)$p['name']) . '</td>';
            echo '<td>₹' . e(money_fmt($p['price'])) . '</td>';
            echo '<td class="mono">' . (int)$p['stock'] . '</td>';
            echo '<td>' . ((int)$p['is_hidden'] === 1 ? '<span class="badge bad">yes</span>' : '<span class="badge good">no</span>') . '</td>';
            echo '<td class="mono small">' . e((string)($p['download_file'] ?? '')) . '</td>';
            echo '<td>';
            echo '<form method="post" class="inline">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="update_product">';
            echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<input class="input input-sm mono" type="number" min="0" name="stock" value="' . (int)$p['stock'] . '">';
            echo '<label class="check small"><input type="checkbox" name="is_hidden" value="1" ' . ((int)$p['is_hidden'] === 1 ? 'checked' : '') . '> hidden</label>';
            echo '<button class="btn btn-ghost btn-sm" type="submit">Save</button>';
            echo '</form>';
            echo '</td>';
            echo '<td>';
            echo '<form method="post" class="inline" onsubmit="return confirm(\'Delete this product?\')">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="delete_product">';
            echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<button class="btn btn-danger btn-sm" type="submit">Delete</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

if ($tab === 'wallet') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare("SELECT id,email,wallet_balance,is_admin FROM users WHERE email LIKE ? ORDER BY id DESC LIMIT 50");
        $st->execute(['%' . $q . '%']);
        $users = $st->fetchAll();
    } else {
        $users = db()->query("SELECT id,email,wallet_balance,is_admin FROM users ORDER BY id DESC LIMIT 50")->fetchAll();
    }

    echo '<div class="glass card">';
    echo '<h2>Wallet</h2>';
    echo '<form method="get" class="row">';
    echo '<input type="hidden" name="tab" value="wallet">';
    echo '<input class="input" name="q" value="' . e($q) . '" placeholder="Search email...">';
    echo '<button class="btn btn-ghost" type="submit">Search</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="glass card table-wrap">';
    echo '<table class="table">';
    echo '<thead><tr><th>User</th><th>Wallet</th><th>Admin</th><th>Set wallet</th></tr></thead><tbody>';
    foreach ($users as $usr) {
        echo '<tr>';
        echo '<td>' . e($usr['email']) . '</td>';
        echo '<td class="mono">₹' . e(money_fmt($usr['wallet_balance'])) . '</td>';
        echo '<td>' . (!empty($usr['is_admin']) ? '<span class="badge warn">yes</span>' : '<span class="muted small">no</span>') . '</td>';
        echo '<td>';
        echo '<form method="post" class="inline">';
        echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="update_wallet">';
        echo '<input type="hidden" name="user_id" value="' . (int)$usr['id'] . '">';
        echo '<input class="input input-sm mono" type="number" step="0.01" min="0" name="wallet_balance" value="' . e(money_fmt($usr['wallet_balance'])) . '">';
        echo '<button class="btn btn-ghost btn-sm" type="submit">Update</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

page_footer();

