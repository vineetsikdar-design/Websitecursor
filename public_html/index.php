<?php
require_once __DIR__ . '/config.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    flash_set('success', 'Logged out.');
    redirect('index.php');
}

page_header(SITE_NAME . ' — Digital Store');

$products = [];
$dbOk = true;
try {
    $st = db()->query("SELECT id,name,description,price,stock,is_hidden,download_file FROM products WHERE is_hidden=0 ORDER BY id DESC");
    $products = $st->fetchAll();
} catch (Throwable $e) {
    $dbOk = false;
}

echo '<div class="hero glass">';
echo '<div class="hero-left">';
echo '<h1>Welcome to <span class="neon">' . e(SITE_NAME) . '</span></h1>';
echo '<p class="muted">Instant digital products · Wallet + UPI checkout · Secure proof submission</p>';
echo '<div class="hero-actions">';
if (current_user()) {
    echo '<a class="btn" href="#products">Browse Products</a>';
} else {
    echo '<a class="btn" href="register.php">Create Account</a>';
    echo '<a class="btn btn-ghost" href="login.php">Login</a>';
}
echo '</div></div>';
echo '<div class="hero-right">';
echo '<div class="stat-row">';
echo '<div class="stat"><div class="stat-k">UPI</div><div class="stat-v">' . e(UPI_VPA) . '</div></div>';
echo '<div class="stat"><div class="stat-k">Payee</div><div class="stat-v">' . e(UPI_PAYEE) . '</div></div>';
echo '</div>';
echo '</div>';
echo '</div>';

if (!$dbOk) {
    echo '<div class="notice error">Database tables not found yet. Import <code>database.sql</code> in phpMyAdmin, then run <a href="install.php">install.php</a>.</div>';
    page_footer();
    exit;
}

echo '<section id="products" class="section">';
echo '<div class="section-title"><h2>Products</h2><div class="muted">Choose a product and checkout</div></div>';

if (!$products) {
    echo '<div class="glass card"><div class="muted">No products yet. Admin can add products in <a href="admin.php">admin panel</a>.</div></div>';
} else {
    echo '<div class="grid">';
    foreach ($products as $p) {
        $inStock = ((int)$p['stock'] > 0);
        echo '<div class="glass card">';
        echo '<div class="card-top">';
        echo '<div class="card-title">' . e($p['name']) . '</div>';
        echo '<div class="price">₹' . e(money_fmt($p['price'])) . '</div>';
        echo '</div>';
        if (!empty($p['description'])) {
            echo '<div class="muted small">' . nl2br(e((string)$p['description'])) . '</div>';
        }
        echo '<div class="card-bottom">';
        echo '<span class="pill ' . ($inStock ? 'ok' : 'bad') . '">' . ($inStock ? ('In stock: ' . (int)$p['stock']) : 'Out of stock') . '</span>';
        if ($inStock) {
            echo '<a class="btn" href="checkout.php?product_id=' . (int)$p['id'] . '">Buy</a>';
        } else {
            echo '<span class="btn btn-disabled">Unavailable</span>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}
echo '</section>';

$u = current_user();
if ($u) {
    echo '<section id="my-orders" class="section">';
    echo '<div class="section-title"><h2>My Orders</h2><div class="muted">Your recent orders & downloads</div></div>';

    echo '<div class="glass card">';
    echo '<div class="row">';
    echo '<div><div class="muted small">Your referral code</div><div class="mono big">' . e($u['referral_code']) . '</div></div>';
    echo '<div class="muted small">Share it during signup (optional).</div>';
    echo '</div>';
    echo '</div>';

    $st = db()->prepare("SELECT o.*, p.name AS product_name, p.download_file
                         FROM orders o
                         JOIN products p ON p.id=o.product_id
                         WHERE o.user_id=?
                         ORDER BY o.id DESC
                         LIMIT 20");
    $st->execute([$u['id']]);
    $orders = $st->fetchAll();

    if (!$orders) {
        echo '<div class="glass card"><div class="muted">No orders yet.</div></div>';
    } else {
        echo '<div class="glass card table-wrap">';
        echo '<table class="table">';
        echo '<thead><tr><th>Order</th><th>Product</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            $status = (string)$o['status'];
            $badge = 'badge';
            if ($status === 'completed') $badge .= ' good';
            if ($status === 'cancelled') $badge .= ' bad';
            if ($status === 'submitted') $badge .= ' warn';
            echo '<tr>';
            echo '<td class="mono">#' . (int)$o['id'] . '</td>';
            echo '<td>' . e($o['product_name']) . ' <span class="muted small">×' . (int)$o['qty'] . '</span></td>';
            echo '<td>₹' . e(money_fmt($o['total_amount'])) . '</td>';
            echo '<td><span class="' . e($badge) . '">' . e($status) . '</span></td>';
            echo '<td>';
            if ($status === 'pending' && (float)$o['upi_amount'] > 0) {
                echo '<a class="btn btn-ghost" href="checkout.php?order_id=' . (int)$o['id'] . '">Submit Payment</a>';
            } elseif ($status === 'completed' && !empty($o['download_file'])) {
                echo '<a class="btn" href="download.php?order_id=' . (int)$o['id'] . '">Download</a>';
            } else {
                echo '<span class="muted small">—</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    echo '</section>';
}

page_footer();

