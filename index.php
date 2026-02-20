<?php
require __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    logout();
    flash_set('ok', 'Logged out.');
    redirect('index.php');
}

render_header('Store');

ensure_dirs();

$u = current_user();

echo '<div class="hero">';
echo '<div class="hero-card glass">';
echo '<h1 class="hero-title">ZENTRAXX <span class="accent">STORE</span></h1>';
echo '<p class="hero-sub">Buy digital products with <b>wallet</b> + manual <b>UPI</b> payment.</p>';
if ($u) {
    echo '<div class="hero-meta">';
    echo '<div class="chip">Logged in as <b>' . e($u['email']) . '</b></div>';
    echo '<div class="chip">Wallet: <b>' . e(money($u['wallet'])) . '</b></div>';
    if (!empty($u['referral_code'])) {
        echo '<div class="chip">Referral: <b>' . e($u['referral_code']) . '</b></div>';
    }
    echo '</div>';
} else {
    echo '<div class="hero-actions">';
    echo '<a class="btn primary" href="register.php">Create account</a>';
    echo '<a class="btn ghost" href="login.php">Login</a>';
    echo '</div>';
}
echo '</div>';
echo '</div>';

try {
    $st = db()->query("SELECT id, name, description, price, stock, is_visible FROM products WHERE is_visible = 1 ORDER BY id DESC");
    $products = $st->fetchAll();
} catch (Throwable $e) {
    echo '<div class="glass card">';
    echo '<h2>Setup needed</h2>';
    echo '<p class="muted">Database tables are missing or DB credentials are incorrect.</p>';
    echo '<div class="row">';
    echo '<a class="btn primary" href="install.php">Open installer</a>';
    echo '<span class="muted small">Import <b>database.sql</b> in phpMyAdmin or run the installer.</span>';
    echo '</div>';
    echo '</div>';
    render_footer();
    exit;
}

echo '<h2 class="section-title">Products</h2>';

if (!$products) {
    echo '<div class="glass card"><p class="muted">No products yet. Admin can add products in <a href="admin.php">admin panel</a>.</p></div>';
    render_footer();
    exit;
}

echo '<div class="grid">';
foreach ($products as $p) {
    $inStock = ((int)$p['stock'] > 0);
    echo '<div class="glass card product">';
    echo '<div class="product-top">';
    echo '<div>';
    echo '<div class="product-name">' . e($p['name']) . '</div>';
    echo '<div class="product-desc">' . nl2br(e((string)$p['description'])) . '</div>';
    echo '</div>';
    echo '<div class="price">' . e(money($p['price'])) . '</div>';
    echo '</div>';
    echo '<div class="product-bottom">';
    echo '<div class="pill small">' . ($inStock ? ('Stock: ' . (int)$p['stock']) : 'Out of stock') . '</div>';
    if ($inStock) {
        if ($u) {
            echo '<a class="btn primary" href="checkout.php?pid=' . (int)$p['id'] . '">Buy</a>';
        } else {
            echo '<a class="btn primary" href="login.php">Login to buy</a>';
        }
    } else {
        echo '<button class="btn disabled" disabled>Sold out</button>';
    }
    echo '</div>';
    echo '</div>';
}
echo '</div>';

render_footer();

