<?php
require __DIR__ . '/config.php';

ensure_dirs();
$admin = require_admin();

$tab = (string)($_GET['tab'] ?? 'dashboard');
if (!in_array($tab, ['dashboard', 'orders', 'products', 'wallet'], true)) {
    $tab = 'dashboard';
}

function allowed_product_ext(string $name): ?string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ok = ['zip', 'pdf', 'png', 'jpg', 'jpeg'];
    return in_array($ext, $ok, true) ? $ext : null;
}

function order_status_badge(string $s): string {
    return '<span class="status ' . e($s) . '">' . e($s) . '</span>';
}

// -------------------------
// Actions (POST)
// -------------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_product') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
        $stock = (int)($_POST['stock'] ?? 0);
        $visible = isset($_POST['is_visible']) ? 1 : 0;

        if ($name === '' || $price <= 0 || $stock < 0) {
            flash_set('error', 'Fill name, price (>0), and stock (>=0).');
            redirect('admin.php?tab=products');
        }

        $filePath = null;
        $fileName = null;

        if (!empty($_FILES['digital_file']) && (int)$_FILES['digital_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$_FILES['digital_file']['error'] !== UPLOAD_ERR_OK) {
                flash_set('error', 'File upload failed.');
                redirect('admin.php?tab=products');
            }
            if ((int)$_FILES['digital_file']['size'] > MAX_PRODUCT_FILE_BYTES) {
                flash_set('error', 'Product file too large.');
                redirect('admin.php?tab=products');
            }

            $orig = (string)$_FILES['digital_file']['name'];
            $ext = allowed_product_ext($orig);
            if (!$ext) {
                flash_set('error', 'Only ZIP/PDF/PNG/JPG files are allowed for product file.');
                redirect('admin.php?tab=products');
            }

            $rand = bin2hex(random_bytes(10));
            $stored = 'prod_' . time() . '_' . $rand . '.' . $ext;
            $destAbs = rtrim(UPLOAD_DIR_FILES, '/') . '/' . $stored;
            $destRel = 'files/' . $stored;

            if (!move_uploaded_file((string)$_FILES['digital_file']['tmp_name'], $destAbs)) {
                flash_set('error', 'Could not save uploaded file.');
                redirect('admin.php?tab=products');
            }
            @chmod($destAbs, 0644);

            $filePath = $destRel;
            $fileName = $orig;
        }

        db()->prepare(
            'INSERT INTO products (name, description, price, stock, is_visible, file_path, file_name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$name, $desc, $price, $stock, $visible, $filePath, $fileName]);

        flash_set('ok', 'Product added.');
        redirect('admin.php?tab=products');
    }

    if ($action === 'toggle_product') {
        $pid = (int)($_POST['pid'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        db()->prepare('UPDATE products SET is_visible = ?, updated_at = NOW() WHERE id = ?')->execute([$val ? 1 : 0, $pid]);
        flash_set('ok', 'Product updated.');
        redirect('admin.php?tab=products');
    }

    if ($action === 'delete_product') {
        $pid = (int)($_POST['pid'] ?? 0);
        if ($pid <= 0) redirect('admin.php?tab=products');

        $st = db()->prepare('SELECT file_path FROM products WHERE id = ?');
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) {
            flash_set('error', 'Product not found.');
            redirect('admin.php?tab=products');
        }

        $st2 = db()->prepare('SELECT COUNT(*) AS c FROM orders WHERE product_id = ?');
        $st2->execute([$pid]);
        $c = (int)($st2->fetch()['c'] ?? 0);
        if ($c > 0) {
            flash_set('error', 'Cannot delete product with existing orders. Hide it instead.');
            redirect('admin.php?tab=products');
        }

        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);

        if (!empty($p['file_path'])) {
            $path = (string)$p['file_path'];
            if (starts_with($path, 'files/')) {
                $abs = __DIR__ . '/' . $path;
                if (is_file($abs)) @unlink($abs);
            }
        }

        flash_set('ok', 'Product deleted.');
        redirect('admin.php?tab=products');
    }

    if ($action === 'update_order') {
        $oid = (int)($_POST['oid'] ?? 0);
        $new = (string)($_POST['status'] ?? '');
        if ($oid <= 0 || !in_array($new, ['completed', 'cancelled'], true)) {
            flash_set('error', 'Invalid request.');
            redirect('admin.php?tab=orders');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT o.id, o.status, o.user_id, o.product_id, o.wallet_used, o.wallet_refunded, o.stock_released
                 FROM orders o
                 WHERE o.id = ? FOR UPDATE"
            );
            $st->execute([$oid]);
            $o = $st->fetch();
            if (!$o) throw new RuntimeException('Order not found.');

            $cur = (string)$o['status'];
            if ($cur === $new) {
                $pdo->commit();
                flash_set('ok', 'No changes.');
                redirect('admin.php?tab=orders');
            }

            if ($cur === 'completed' && $new === 'cancelled') {
                throw new RuntimeException('Do not cancel completed orders.');
            }

            if ($new === 'completed') {
                if ($cur !== 'submitted') {
                    throw new RuntimeException('Only submitted orders can be completed.');
                }
                $pdo->prepare("UPDATE orders SET status='completed', completed_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$oid]);
            }

            if ($new === 'cancelled') {
                if (!in_array($cur, ['pending', 'submitted'], true)) {
                    throw new RuntimeException('Only pending/submitted orders can be cancelled.');
                }

                // Refund wallet if used (only once)
                if ((float)$o['wallet_used'] > 0 && empty($o['wallet_refunded'])) {
                    $pdo->prepare('UPDATE users SET wallet = wallet + ? WHERE id = ?')->execute([(float)$o['wallet_used'], (int)$o['user_id']]);
                    $pdo->prepare('UPDATE orders SET wallet_refunded = 1 WHERE id = ?')->execute([$oid]);
                }

                // Release stock reservation (only once)
                if (empty($o['stock_released'])) {
                    $pdo->prepare('UPDATE products SET stock = stock + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$o['product_id']]);
                    $pdo->prepare('UPDATE orders SET stock_released = 1 WHERE id = ?')->execute([$oid]);
                }

                $pdo->prepare("UPDATE orders SET status='cancelled', cancelled_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$oid]);
            }

            $pdo->commit();
            flash_set('ok', 'Order updated.');
            redirect('admin.php?tab=orders');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', $e->getMessage());
            redirect('admin.php?tab=orders');
        }
    }

    if ($action === 'set_wallet') {
        $uid = (int)($_POST['uid'] ?? 0);
        $amount = (float)str_replace(',', '.', (string)($_POST['wallet'] ?? '0'));
        if ($uid <= 0 || $amount < 0) {
            flash_set('error', 'Invalid wallet amount.');
            redirect('admin.php?tab=wallet');
        }
        db()->prepare('UPDATE users SET wallet = ? WHERE id = ?')->execute([$amount, $uid]);
        flash_set('ok', 'Wallet updated.');
        redirect('admin.php?tab=wallet');
    }

    flash_set('error', 'Unknown action.');
    redirect('admin.php');
}

// -------------------------
// Page
// -------------------------
render_header('Admin');

?>

<div class="glass card">
    <div class="admin-head">
        <div>
            <h2>Admin Panel</h2>
            <div class="muted">Logged in as <b><?= e((string)$admin['email']) ?></b></div>
        </div>
        <div class="tabs">
            <a class="<?= $tab==='dashboard'?'active':'' ?>" href="admin.php?tab=dashboard">Dashboard</a>
            <a class="<?= $tab==='orders'?'active':'' ?>" href="admin.php?tab=orders">Orders</a>
            <a class="<?= $tab==='products'?'active':'' ?>" href="admin.php?tab=products">Products</a>
            <a class="<?= $tab==='wallet'?'active':'' ?>" href="admin.php?tab=wallet">Wallet</a>
        </div>
    </div>
</div>

<?php if ($tab === 'dashboard'): ?>
    <?php
    $stats = [];
    $stats['users'] = (int)(db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0);
    $stats['products'] = (int)(db()->query('SELECT COUNT(*) c FROM products')->fetch()['c'] ?? 0);
    $stats['orders'] = (int)(db()->query('SELECT COUNT(*) c FROM orders')->fetch()['c'] ?? 0);
    $by = db()->query("SELECT status, COUNT(*) c FROM orders GROUP BY status")->fetchAll();
    $map = ['pending'=>0,'submitted'=>0,'completed'=>0,'cancelled'=>0];
    foreach ($by as $r) $map[(string)$r['status']] = (int)$r['c'];
    $sales = db()->query("SELECT COALESCE(SUM(price),0) s, COALESCE(SUM(wallet_used),0) w, COALESCE(SUM(upi_amount),0) u FROM orders WHERE status='completed'")->fetch();
    ?>
    <div class="grid stats">
        <div class="glass card"><div class="muted">Users</div><div class="big"><?= (int)$stats['users'] ?></div></div>
        <div class="glass card"><div class="muted">Products</div><div class="big"><?= (int)$stats['products'] ?></div></div>
        <div class="glass card"><div class="muted">Orders</div><div class="big"><?= (int)$stats['orders'] ?></div></div>
        <div class="glass card"><div class="muted">Pending</div><div class="big"><?= (int)$map['pending'] ?></div></div>
        <div class="glass card"><div class="muted">Submitted</div><div class="big"><?= (int)$map['submitted'] ?></div></div>
        <div class="glass card"><div class="muted">Completed</div><div class="big"><?= (int)$map['completed'] ?></div></div>
        <div class="glass card"><div class="muted">Cancelled</div><div class="big"><?= (int)$map['cancelled'] ?></div></div>
        <div class="glass card">
            <div class="muted">Completed Sales</div>
            <div class="big"><?= e(money($sales['s'] ?? 0)) ?></div>
            <div class="muted small">Wallet: <?= e(money($sales['w'] ?? 0)) ?> · UPI: <?= e(money($sales['u'] ?? 0)) ?></div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'orders'): ?>
    <?php
    $filter = (string)($_GET['status'] ?? '');
    $where = '';
    $args = [];
    if (in_array($filter, ['pending','submitted','completed','cancelled'], true)) {
        $where = 'WHERE o.status = ?';
        $args[] = $filter;
    }
    $st = db()->prepare(
        "SELECT o.id, o.status, o.price, o.wallet_used, o.upi_amount, o.utr, o.created_at,
                u.email AS user_email,
                p.name AS product_name,
                o.screenshot_path
         FROM orders o
         JOIN users u ON u.id = o.user_id
         JOIN products p ON p.id = o.product_id
         $where
         ORDER BY o.id DESC
         LIMIT 300"
    );
    $st->execute($args);
    $orders = $st->fetchAll();
    ?>
    <div class="glass card">
        <div class="row between">
            <h3>Orders</h3>
            <div class="row">
                <a class="btn small ghost" href="admin.php?tab=orders">All</a>
                <a class="btn small ghost" href="admin.php?tab=orders&status=pending">Pending</a>
                <a class="btn small ghost" href="admin.php?tab=orders&status=submitted">Submitted</a>
                <a class="btn small ghost" href="admin.php?tab=orders&status=completed">Completed</a>
                <a class="btn small ghost" href="admin.php?tab=orders&status=cancelled">Cancelled</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th><th>User</th><th>Product</th><th>Status</th><th>Total</th><th>Wallet</th><th>UPI</th><th>UTR</th><th>Proof</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <?php
                        $oid = (int)$o['id'];
                        $s = (string)$o['status'];
                        $canComplete = ($s === 'submitted');
                        $canCancel = ($s === 'pending' || $s === 'submitted');
                    ?>
                    <tr>
                        <td><?= $oid ?></td>
                        <td><?= e((string)$o['user_email']) ?></td>
                        <td><?= e((string)$o['product_name']) ?></td>
                        <td><?= order_status_badge($s) ?></td>
                        <td><?= e(money($o['price'])) ?></td>
                        <td><?= e(money($o['wallet_used'])) ?></td>
                        <td><?= e(money($o['upi_amount'])) ?></td>
                        <td class="mono"><?= e((string)($o['utr'] ?? '')) ?></td>
                        <td>
                            <?php if (!empty($o['screenshot_path'])): ?>
                                <a class="btn small ghost" target="_blank" href="download.php?shot=<?= $oid ?>">View</a>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="row" style="gap:8px;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_order">
                                <input type="hidden" name="oid" value="<?= $oid ?>">
                                <?php if ($canComplete): ?>
                                    <button class="btn small primary" name="status" value="completed" type="submit">Complete</button>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <button class="btn small danger" name="status" value="cancelled" type="submit" onclick="return confirm('Cancel order #<?= $oid ?>?');">Cancel</button>
                                <?php endif; ?>
                                <?php if (!$canCancel && !$canComplete): ?>
                                    <span class="muted small">—</span>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'products'): ?>
    <?php
    $products = db()->query('SELECT id, name, price, stock, is_visible, file_name FROM products ORDER BY id DESC')->fetchAll();
    ?>
    <div class="grid two">
        <div class="glass card">
            <h3>Add Product</h3>
            <form method="post" enctype="multipart/form-data" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_product">
                <label><span>Name</span><input name="name" required maxlength="120"></label>
                <label><span>Description</span><textarea name="description" rows="4" placeholder="What the buyer gets..."></textarea></label>
                <div class="pay-grid">
                    <label><span>Price</span><input name="price" type="number" min="0" step="0.01" required></label>
                    <label><span>Stock</span><input name="stock" type="number" min="0" step="1" value="1" required></label>
                </div>
                <label>
                    <span>Digital file (optional)</span>
                    <input type="file" name="digital_file" accept=".zip,.pdf,.png,.jpg,.jpeg">
                    <div class="muted small">Direct access is blocked; downloads happen via `download.php` after completion.</div>
                </label>
                <label class="row" style="gap:10px;align-items:center;">
                    <input type="checkbox" name="is_visible" checked>
                    <span>Visible on store</span>
                </label>
                <button class="btn primary" type="submit">Add</button>
            </form>
        </div>

        <div class="glass card">
            <h3>All Products</h3>
            <?php if (!$products): ?>
                <p class="muted">No products yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>#</th><th>Name</th><th>Price</th><th>Stock</th><th>Visible</th><th>File</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?= (int)$p['id'] ?></td>
                                <td><?= e((string)$p['name']) ?></td>
                                <td><?= e(money($p['price'])) ?></td>
                                <td><?= (int)$p['stock'] ?></td>
                                <td><?= !empty($p['is_visible']) ? '<span class="pill small">Yes</span>' : '<span class="pill small">No</span>' ?></td>
                                <td><?= e((string)($p['file_name'] ?? '')) ?></td>
                                <td>
                                    <div class="row" style="gap:8px;">
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_product">
                                            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                                            <input type="hidden" name="val" value="<?= !empty($p['is_visible']) ? 0 : 1 ?>">
                                            <button class="btn small ghost" type="submit"><?= !empty($p['is_visible']) ? 'Hide' : 'Show' ?></button>
                                        </form>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                                            <button class="btn small danger" type="submit" onclick="return confirm('Delete product #<?= (int)$p['id'] ?>?');">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'wallet'): ?>
    <?php
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare('SELECT id, email, wallet, is_admin FROM users WHERE email LIKE ? ORDER BY id DESC LIMIT 50');
        $st->execute(['%' . $q . '%']);
        $users = $st->fetchAll();
    } else {
        $users = db()->query('SELECT id, email, wallet, is_admin FROM users ORDER BY id DESC LIMIT 50')->fetchAll();
    }
    ?>
    <div class="glass card">
        <div class="row between">
            <h3>Wallet Management</h3>
            <form method="get" class="row" style="gap:10px;">
                <input type="hidden" name="tab" value="wallet">
                <input name="q" placeholder="Search email..." value="<?= e($q) ?>">
                <button class="btn small ghost" type="submit">Search</button>
            </form>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ID</th><th>Email</th><th>Wallet</th><th>Role</th><th>Set Wallet</th></tr></thead>
                <tbody>
                <?php foreach ($users as $usr): ?>
                    <tr>
                        <td><?= (int)$usr['id'] ?></td>
                        <td><?= e((string)$usr['email']) ?></td>
                        <td><?= e(money($usr['wallet'])) ?></td>
                        <td><?= !empty($usr['is_admin']) ? '<span class="pill small">admin</span>' : '<span class="pill small">user</span>' ?></td>
                        <td>
                            <form method="post" class="row" style="gap:8px;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_wallet">
                                <input type="hidden" name="uid" value="<?= (int)$usr['id'] ?>">
                                <input type="number" step="0.01" min="0" name="wallet" value="<?= e((string)$usr['wallet']) ?>" style="width:140px;">
                                <button class="btn small primary" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>

