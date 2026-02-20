<?php
require_once __DIR__ . '/config.php';
require_admin($pdo);

$admin = current_user($pdo);
$flash = get_flash();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_product') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $stock = (int) ($_POST['stock'] ?? 0);
            $downloadLink = trim($_POST['download_link'] ?? '');
            $isHidden = isset($_POST['is_hidden']) ? 1 : 0;

            if ($title === '') {
                throw new Exception('Product title is required.');
            }
            if ($price < 0) {
                throw new Exception('Price must be zero or positive.');
            }
            if ($stock < 0) {
                throw new Exception('Stock must be zero or positive.');
            }

            $stmt = $pdo->prepare('
                INSERT INTO products (title, description, price, stock, download_link, is_hidden)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $description, money($price), $stock, $downloadLink ?: null, $isHidden]);
            set_flash('Product added successfully.', 'success');
            redirect('admin.php');
        }

        if ($action === 'delete_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new Exception('Invalid product ID.');
            }

            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            set_flash('Product deleted.', 'success');
            redirect('admin.php');
        }

        if ($action === 'toggle_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new Exception('Invalid product ID.');
            }

            $stmt = $pdo->prepare('UPDATE products SET is_hidden = IF(is_hidden = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$productId]);
            set_flash('Product visibility updated.', 'success');
            redirect('admin.php');
        }

        if ($action === 'update_order_status') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $adminNote = trim($_POST['admin_note'] ?? '');
            $allowedStatuses = ['pending', 'submitted', 'completed', 'cancelled'];

            if ($orderId <= 0) {
                throw new Exception('Invalid order ID.');
            }
            if (!in_array($newStatus, $allowedStatuses, true)) {
                throw new Exception('Invalid order status.');
            }
            if (strlen($adminNote) > 255) {
                throw new Exception('Admin note too long.');
            }

            $pdo->beginTransaction();

            $orderStmt = $pdo->prepare('
                SELECT id, user_id, status, wallet_used, wallet_refunded
                FROM orders
                WHERE id = ?
                FOR UPDATE
            ');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            if (!$order) {
                throw new Exception('Order not found.');
            }

            if ($order['status'] === 'cancelled' && $newStatus !== 'cancelled') {
                throw new Exception('Cancelled order cannot be moved to another status.');
            }

            $walletUsed = (float) $order['wallet_used'];
            $walletRefunded = (int) $order['wallet_refunded'];
            $oldStatus = $order['status'];

            if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled' && $walletUsed > 0 && $walletRefunded === 0) {
                $refundStmt = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?');
                $refundStmt->execute([money($walletUsed), (int) $order['user_id']]);
                $walletRefunded = 1;
            }

            $updateStmt = $pdo->prepare('
                UPDATE orders
                SET status = ?, admin_note = ?, wallet_refunded = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$newStatus, $adminNote !== '' ? $adminNote : null, $walletRefunded, $orderId]);

            $pdo->commit();
            set_flash('Order status updated.', 'success');
            redirect('admin.php');
        }

        if ($action === 'update_wallet') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newBalance = (float) ($_POST['wallet_balance'] ?? 0);

            if ($userId <= 0) {
                throw new Exception('Invalid user ID.');
            }
            if ($newBalance < 0) {
                throw new Exception('Wallet balance cannot be negative.');
            }

            $stmt = $pdo->prepare('UPDATE users SET wallet_balance = ? WHERE id = ?');
            $stmt->execute([money($newBalance), $userId]);
            set_flash('Wallet balance updated.', 'success');
            redirect('admin.php');
        }

        if ($action === 'update_settings') {
            $siteName = trim($_POST['site_name'] ?? 'ZENTRAXX STORE');
            $upiId = trim($_POST['upi_id'] ?? '');
            $upiName = trim($_POST['upi_name'] ?? '');
            $instructions = trim($_POST['payment_instructions'] ?? '');

            if ($siteName === '' || $upiId === '' || $upiName === '') {
                throw new Exception('Site name, UPI ID, and UPI name are required.');
            }

            upsert_settings($pdo, $siteName, $upiId, $upiName, $instructions);
            set_flash('Settings saved.', 'success');
            redirect('admin.php');
        }

        throw new Exception('Unknown action requested.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$settings = get_settings($pdo);

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
    'revenue' => (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status = 'completed'")->fetchColumn(),
];

$productsStmt = $pdo->query('SELECT id, title, price, stock, is_hidden, created_at FROM products ORDER BY id DESC');
$products = $productsStmt->fetchAll();

$ordersStmt = $pdo->query('
    SELECT o.id, o.quantity, o.total_amount, o.wallet_used, o.upi_amount, o.utr, o.screenshot_path,
           o.status, o.created_at, o.admin_note,
           u.name AS user_name, u.email AS user_email,
           p.title AS product_title
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id
    LEFT JOIN products p ON p.id = o.product_id
    ORDER BY o.id DESC
    LIMIT 200
');
$orders = $ordersStmt->fetchAll();

$usersStmt = $pdo->query('SELECT id, name, email, wallet_balance, is_admin FROM users ORDER BY id DESC LIMIT 200');
$users = $usersStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Panel - ZENTRAXX STORE</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand">ZENTRAXX ADMIN</div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Store</a>
            <a class="chip-link" href="checkout.php">Checkout</a>
            <a class="chip-link" href="index.php?logout=1">Logout</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="flash flash-error">
            <?php foreach ($errors as $error): ?>
                <div>- <?php echo e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="glass hero">
        <h1>Dashboard</h1>
        <p>Logged in as <?php echo e($admin['name']); ?> (<?php echo e($admin['email']); ?>)</p>
    </div>

    <div class="stats">
        <div class="glass stat-box">
            <div class="muted">Users</div>
            <div class="value"><?php echo e($stats['users']); ?></div>
        </div>
        <div class="glass stat-box">
            <div class="muted">Products</div>
            <div class="value"><?php echo e($stats['products']); ?></div>
        </div>
        <div class="glass stat-box">
            <div class="muted">Orders</div>
            <div class="value"><?php echo e($stats['orders']); ?></div>
        </div>
        <div class="glass stat-box">
            <div class="muted">Pending Orders</div>
            <div class="value"><?php echo e($stats['pending']); ?></div>
        </div>
        <div class="glass stat-box">
            <div class="muted">Completed Revenue</div>
            <div class="value">Rs <?php echo e(money($stats['revenue'])); ?></div>
        </div>
    </div>

    <div class="grid" style="align-items:start;">
        <div class="glass card">
            <h3>Store Settings</h3>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="update_settings">
                <div class="field">
                    <label>Site Name</label>
                    <input type="text" name="site_name" value="<?php echo e($settings['site_name']); ?>" required>
                </div>
                <div class="field">
                    <label>UPI ID</label>
                    <input type="text" name="upi_id" value="<?php echo e($settings['upi_id']); ?>" required>
                </div>
                <div class="field">
                    <label>UPI Receiver Name</label>
                    <input type="text" name="upi_name" value="<?php echo e($settings['upi_name']); ?>" required>
                </div>
                <div class="field">
                    <label>Payment Instructions</label>
                    <textarea name="payment_instructions" required><?php echo e($settings['payment_instructions']); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>

        <div class="glass card">
            <h3>Add Product</h3>
            <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="add_product">
                <div class="field">
                    <label>Title</label>
                    <input type="text" name="title" required>
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Price</label>
                        <input type="number" step="0.01" min="0" name="price" required>
                    </div>
                    <div class="field">
                        <label>Stock</label>
                        <input type="number" min="0" name="stock" required>
                    </div>
                </div>
                <div class="field">
                    <label>Download Link (URL or local file path)</label>
                    <input type="text" name="download_link" placeholder="https://example.com/file.zip">
                </div>
                <div class="field inline">
                    <input type="checkbox" id="is_hidden" name="is_hidden" value="1">
                    <label for="is_hidden" style="margin:0;">Hide Product</label>
                </div>
                <button type="submit" class="btn btn-primary">Add Product</button>
            </form>
        </div>
    </div>

    <hr class="sep">
    <h2>Products</h2>
    <div class="glass card table-wrap">
        <?php if (!$products): ?>
            <p class="muted">No products yet.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Visibility</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?php echo e($product['id']); ?></td>
                        <td><?php echo e($product['title']); ?></td>
                        <td>Rs <?php echo e(money($product['price'])); ?></td>
                        <td><?php echo e((int) $product['stock']); ?></td>
                        <td><?php echo ((int) $product['is_hidden'] === 1) ? '<span class="badge badge-danger">Hidden</span>' : '<span class="badge badge-success">Visible</span>'; ?></td>
                        <td class="inline">
                            <form method="post">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="toggle_product">
                                <input type="hidden" name="product_id" value="<?php echo e($product['id']); ?>">
                                <button type="submit" class="btn btn-small btn-muted">Toggle</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete product?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?php echo e($product['id']); ?>">
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <hr class="sep">
    <h2>Orders</h2>
    <div class="glass card table-wrap">
        <?php if (!$orders): ?>
            <p class="muted">No orders found.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Product</th>
                    <th>Total</th>
                    <th>UTR</th>
                    <th>Screenshot</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>#<?php echo e($order['id']); ?><br><span class="muted"><?php echo e($order['created_at']); ?></span></td>
                        <td>
                            <?php echo e($order['user_name']); ?><br>
                            <span class="muted"><?php echo e($order['user_email']); ?></span>
                        </td>
                        <td><?php echo e($order['product_title'] ?? 'Product removed'); ?> (x<?php echo e((int) $order['quantity']); ?>)</td>
                        <td>
                            Total: Rs <?php echo e(money($order['total_amount'])); ?><br>
                            <span class="muted">Wallet: Rs <?php echo e(money($order['wallet_used'])); ?> | UPI: Rs <?php echo e(money($order['upi_amount'])); ?></span>
                        </td>
                        <td><?php echo e($order['utr'] ?: '-'); ?></td>
                        <td>
                            <?php if (!empty($order['screenshot_path'])): ?>
                                <a href="<?php echo e($order['screenshot_path']); ?>" target="_blank" rel="noopener">View</a>
                            <?php else: ?>
                                <span class="muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo e(status_badge_class($order['status'])); ?>"><?php echo e($order['status']); ?></span></td>
                        <td>
                            <form method="post">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?php echo e($order['id']); ?>">
                                <div class="field" style="margin-bottom:8px;">
                                    <select name="status">
                                        <?php foreach (['pending', 'submitted', 'completed', 'cancelled'] as $status): ?>
                                            <option value="<?php echo e($status); ?>" <?php echo ($order['status'] === $status) ? 'selected' : ''; ?>>
                                                <?php echo e($status); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <input type="text" name="admin_note" maxlength="255" placeholder="Admin note" value="<?php echo e($order['admin_note'] ?? ''); ?>">
                                </div>
                                <button class="btn btn-small btn-primary" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <hr class="sep">
    <h2>Edit Wallets</h2>
    <div class="glass card table-wrap">
        <?php if (!$users): ?>
            <p class="muted">No users found.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Wallet</th>
                    <th>Set New Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><?php echo e($row['id']); ?></td>
                        <td><?php echo e($row['name']); ?><br><span class="muted"><?php echo e($row['email']); ?></span></td>
                        <td><?php echo ((int) $row['is_admin'] === 1) ? '<span class="badge badge-info">Admin</span>' : 'User'; ?></td>
                        <td>Rs <?php echo e(money($row['wallet_balance'])); ?></td>
                        <td>
                            <form method="post" class="inline">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="update_wallet">
                                <input type="hidden" name="user_id" value="<?php echo e($row['id']); ?>">
                                <input type="number" step="0.01" min="0" name="wallet_balance" value="<?php echo e(money($row['wallet_balance'])); ?>" required>
                                <button type="submit" class="btn btn-small btn-primary">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
