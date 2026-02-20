<?php
require_once __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    logout_user();
    redirect('index.php?loggedout=1');
}

$settings = get_settings($pdo);
$flash = get_flash();
$user = current_user($pdo);

$productsStmt = $pdo->query('
    SELECT id, title, description, price, stock
    FROM products
    WHERE is_hidden = 0 AND stock > 0
    ORDER BY id DESC
');
$products = $productsStmt->fetchAll();

$orders = [];
if ($user) {
    $ordersStmt = $pdo->prepare('
        SELECT o.id, o.quantity, o.total_amount, o.wallet_used, o.upi_amount, o.status, o.created_at,
               p.title, p.download_link
        FROM orders o
        LEFT JOIN products p ON p.id = o.product_id
        WHERE o.user_id = ?
        ORDER BY o.id DESC
        LIMIT 15
    ');
    $ordersStmt->execute([(int) $user['id']]);
    $orders = $ordersStmt->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($settings['site_name']); ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand"><?php echo e($settings['site_name']); ?></div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Home</a>
            <?php if ($user): ?>
                <a class="chip-link" href="checkout.php">Checkout</a>
                <?php if ((int) $user['is_admin'] === 1): ?>
                    <a class="chip-link" href="admin.php">Admin</a>
                <?php endif; ?>
                <a class="chip-link" href="index.php?logout=1">Logout</a>
            <?php else: ?>
                <a class="chip-link" href="login.php">Login</a>
                <a class="chip-link" href="register.php">Register</a>
                <a class="chip-link" href="install.php">Install</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['loggedout'])): ?>
        <div class="flash flash-success">Logged out successfully.</div>
    <?php endif; ?>

    <div class="glass hero">
        <h1>Digital Products with Fast Manual UPI Checkout</h1>
        <p>Secure wallet + UPI split payment, screenshot proof, and admin-verified delivery.</p>
        <?php if ($user): ?>
            <p style="margin-top:10px;">
                Welcome, <strong><?php echo e($user['name']); ?></strong> |
                Wallet: <strong>Rs <?php echo e(money($user['wallet_balance'])); ?></strong>
            </p>
            <p class="muted" style="margin-top:8px;">
                Your referral code: <strong><?php echo e($user['referral_code']); ?></strong>
                (share: <code>register.php?ref=<?php echo e($user['referral_code']); ?></code>)
            </p>
        <?php endif; ?>
    </div>

    <h2>Available Products</h2>
    <?php if (!$products): ?>
        <div class="glass card">
            <p class="muted">No products available right now.</p>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($products as $product): ?>
                <div class="glass card">
                    <h3><?php echo e($product['title']); ?></h3>
                    <p><?php echo nl2br(e($product['description'] ?? '')); ?></p>
                    <div class="price">Rs <?php echo e(money($product['price'])); ?></div>
                    <div class="stock">Stock: <?php echo e((int) $product['stock']); ?></div>
                    <a class="btn btn-primary" href="checkout.php?product=<?php echo e($product['id']); ?>">Buy Now</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($user): ?>
        <hr class="sep">
        <h2>Your Recent Orders</h2>
        <div class="glass card table-wrap">
            <?php if (!$orders): ?>
                <p class="muted">No orders yet. Start with a product above.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Total</th>
                        <th>Wallet</th>
                        <th>UPI</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Download</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo e($order['id']); ?></td>
                            <td><?php echo e($order['title'] ?? 'Product removed'); ?> (x<?php echo e((int) $order['quantity']); ?>)</td>
                            <td>Rs <?php echo e(money($order['total_amount'])); ?></td>
                            <td>Rs <?php echo e(money($order['wallet_used'])); ?></td>
                            <td>Rs <?php echo e(money($order['upi_amount'])); ?></td>
                            <td><span class="badge <?php echo e(status_badge_class($order['status'])); ?>"><?php echo e($order['status']); ?></span></td>
                            <td><?php echo e($order['created_at']); ?></td>
                            <td>
                                <?php if ($order['status'] === 'completed' && !empty($order['download_link'])): ?>
                                    <a class="btn btn-small btn-success" href="download.php?order_id=<?php echo e($order['id']); ?>">Download</a>
                                <?php else: ?>
                                    <span class="muted">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
