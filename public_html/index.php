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

$page = (string)($_GET['page'] ?? '');
$productId = (int)($_GET['product'] ?? 0);
$orderId = (int)($_GET['order'] ?? 0);

// Basic DB check
$dbOk = true;
try {
    db()->query("SELECT 1 FROM settings LIMIT 1");
} catch (Throwable) {
    $dbOk = false;
}

if (!$dbOk) {
    page_header(SITE_NAME . ' — Setup');
    echo '<div class="notice error">Database tables not found yet. Import <code>database.sql</code> in phpMyAdmin, then run <a href="install.php">install.php</a>.</div>';
    page_footer();
    exit;
}

// ---- Cart + profile actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cart_add') {
        if (maintenance_mode() && (!current_user() || empty(current_user()['is_admin']))) {
            flash_set('error', 'Maintenance mode: cart/checkout temporarily disabled.');
            redirect('index.php');
        }
        $u = current_user();
        if (!$u) {
            flash_set('error', 'Please login to add to cart.');
            redirect('login.php');
        }

        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = (int)($_POST['variant_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        if ($qty < 1) $qty = 1;
        if ($qty > 10) $qty = 10;

        $st = db()->prepare("SELECT id,type,name,is_hidden,stock FROM products WHERE id=? LIMIT 1");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p || (int)$p['is_hidden'] === 1) {
            flash_set('error', 'Product not found.');
            redirect('index.php');
        }

        $type = (string)$p['type'];
        $key = 'p' . $pid . 'v' . ($type === 'account' ? 0 : $vid);
        $cart = cart_get();

        if ($type === 'account') {
            if ((int)$p['stock'] < 1) {
                flash_set('error', 'Out of stock.');
                redirect('index.php?product=' . $pid);
            }
            $cart[$key] = ['product_id' => $pid, 'variant_id' => 0, 'qty' => 1];
            $_SESSION['cart'] = $cart;
            flash_set('success', 'Added to cart.');
            redirect('checkout.php?cart=1');
        }

        $stV = db()->prepare("SELECT id,stock,is_hidden FROM product_variants WHERE id=? AND product_id=? LIMIT 1");
        $stV->execute([$vid, $pid]);
        $v = $stV->fetch();
        if (!$v || (int)$v['is_hidden'] === 1) {
            flash_set('error', 'Select a valid option.');
            redirect('index.php?product=' . $pid);
        }
        if ((int)$v['stock'] < $qty) {
            flash_set('error', 'Not enough stock for that option.');
            redirect('index.php?product=' . $pid);
        }

        if (isset($cart[$key])) {
            $cart[$key]['qty'] = min(10, ((int)$cart[$key]['qty'] + $qty));
        } else {
            $cart[$key] = ['product_id' => $pid, 'variant_id' => $vid, 'qty' => $qty];
        }
        $_SESSION['cart'] = $cart;
        flash_set('success', 'Added to cart.');
        $go = (string)($_POST['go'] ?? '');
        if ($go === 'checkout') redirect('checkout.php?cart=1');
        redirect('index.php?product=' . $pid);
    }

    if ($action === 'profile_update') {
        $u = require_login();
        $name = trim((string)($_POST['display_name'] ?? ''));
        $avatar = trim((string)($_POST['avatar_url'] ?? ''));
        if ($name === '') $name = (string)$u['username'];
        if (strlen($name) > 80) $name = substr($name, 0, 80);
        if ($avatar !== '' && strlen($avatar) > 255) $avatar = '';

        $st = db()->prepare("UPDATE users SET display_name=?, avatar_url=? WHERE id=?");
        $st->execute([$name, ($avatar === '' ? null : $avatar), (int)$u['id']]);
        flash_set('success', 'Profile updated.');
        redirect('index.php?page=profile');
    }

    if ($action === 'password_change') {
        $u = require_login();
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        if (strlen($new) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
            redirect('index.php?page=profile');
        }
        if ($new !== $new2) {
            flash_set('error', 'New passwords do not match.');
            redirect('index.php?page=profile');
        }
        $st = db()->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
        $st->execute([(int)$u['id']]);
        $row = $st->fetch();
        if (!$row || !password_verify($old, (string)$row['password_hash'])) {
            flash_set('error', 'Old password is incorrect.');
            redirect('index.php?page=profile');
        }
        db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), (int)$u['id']]);
        flash_set('success', 'Password changed.');
        redirect('index.php?page=profile');
    }
}

// Welcome popup once after first login (set by login.php)
$welcomeOnce = !empty($_SESSION['show_welcome_once']);
if ($welcomeOnce) unset($_SESSION['show_welcome_once']);

page_header(SITE_NAME . ' — Gaming Store');

if (maintenance_mode() && (!current_user() || empty(current_user()['is_admin']))) {
    echo '<div class="notice warn">Maintenance mode is ON. Browsing is available, but checkout may be limited.</div>';
}

if ($welcomeOnce) {
    $wt = setting_get('welcome_text', 'Welcome!');
    echo '<script>
      (function(){
        try{
          var p=' . json_encode($wt) . ';
          var m=document.createElement("div");
          m.className="modal";
          m.innerHTML=
            \'<div class="modal-backdrop"></div>\'+
            \'<div class="modal-card glass">\'+
              \'<div class="modal-head"><div class="modal-title">Welcome</div><button class="xbtn" aria-label="Close">×</button></div>\'+
              \'<div class="muted">\'+(p+"").replace(/</g,"&lt;")+\'</div>\'+
              \'<div class="modal-actions"><button class="btn close">Let\\\'s go</button></div>\'+
            \'</div>\';
          document.body.appendChild(m);
          function c(){ m.remove(); }
          m.querySelector(".modal-backdrop").addEventListener("click",c);
          m.querySelector(".xbtn").addEventListener("click",c);
          m.querySelector(".close").addEventListener("click",c);
        }catch(e){}
      })();
    </script>';
}

// ---- Order detail ----
if ($orderId > 0) {
    $u = require_login();
    db()->prepare("UPDATE users SET orders_last_seen_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
    $st = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$orderId, (int)$u['id']]);
    $o = $st->fetch();
    if (!$o) {
        echo '<div class="glass card"><h2>Order not found</h2></div>';
        page_footer();
        exit;
    }
    $items = db()->prepare("SELECT * FROM order_items WHERE order_id=?");
    $items->execute([(int)$o['id']]);
    $rows = $items->fetchAll();

    echo '<div class="glass card">';
    echo '<div class="row">';
    echo '<div><div class="muted small">Order</div><div class="mono big">#' . (int)$o['id'] . '</div></div>';
    echo '<div><div class="muted small">Status</div><div><span class="badge ' . ((string)$o['status'] === 'completed' ? 'good' : (((string)$o['status'] === 'submitted') ? 'warn' : (((string)$o['status'] === 'cancelled') ? 'bad' : ''))) . '">' . e((string)$o['status']) . '</span></div></div>';
    echo '</div>';
    echo '<div class="hr"></div>';
    echo '<div class="muted small">Items</div>';
    echo '<ul class="list">';
    foreach ($rows as $it) {
        $t = e((string)$it['product_name']);
        $vl = trim((string)($it['variant_label'] ?? ''));
        if ($vl !== '') $t .= ' <span class="muted small">[' . e($vl) . ']</span>';
        $t .= ' <span class="muted small">×' . (int)$it['qty'] . '</span>';
        echo '<li>' . $t . '</li>';
    }
    echo '</ul>';

    echo '<div class="hr"></div>';
    echo '<div class="row">';
    echo '<div><div class="muted small">Total</div><div class="price">₹' . e(money_fmt($o['total_amount'])) . '</div></div>';
    echo '<div><div class="muted small">Wallet used</div><div>₹' . e(money_fmt($o['wallet_used'])) . '</div></div>';
    echo '<div><div class="muted small">To pay</div><div class="mono big">₹' . e(money_fmt($o['pay_amount'])) . '</div></div>';
    echo '</div>';
    echo '<div class="muted small">Payment method: <b>' . e((string)($o['payment_method'] ?? '')) . '</b></div>';
    if (!empty($o['reference_id'])) echo '<div class="muted small">Reference: <span class="mono">' . e((string)$o['reference_id']) . '</span></div>';
    if (!empty($o['screenshot_path'])) echo '<div class="muted small">Screenshot: <a target="_blank" rel="noopener" href="' . e((string)$o['screenshot_path']) . '">view</a></div>';

    if ((string)$o['status'] === 'pending' && (float)$o['pay_amount'] > 0) {
        echo '<div class="hr"></div><a class="btn" href="checkout.php?order_id=' . (int)$o['id'] . '">Submit Payment Proof</a>';
    }

    if ((string)$o['status'] === 'completed') {
        echo '<div class="hr"></div><h2>Delivery (E-Box)</h2>';
        $dj = (string)($o['delivery_json'] ?? '');
        $data = [];
        if ($dj !== '') {
            $data = json_decode($dj, true);
            if (!is_array($data)) $data = [];
        }
        $msg = trim((string)($data['message'] ?? ''));
        $links = $data['links'] ?? [];
        $keys = $data['keys'] ?? [];
        $files = $data['files'] ?? [];

        if ($msg !== '') echo '<div class="glass card inner"><div class="muted">' . nl2br(e($msg)) . '</div></div>';
        if (is_array($keys) && $keys) echo '<div class="glass card inner"><div class="muted small">Keys</div><pre class="ebox">' . e(implode("\n", array_values($keys))) . '</pre></div>';
        if (is_array($links) && $links) {
            echo '<div class="glass card inner"><div class="muted small">Links</div><ul class="list">';
            foreach ($links as $ln) { $ln = trim((string)$ln); if ($ln === '') continue; echo '<li><a target="_blank" rel="noopener" href="' . e($ln) . '">' . e($ln) . '</a></li>'; }
            echo '</ul></div>';
        }
        if (is_array($files) && $files) {
            echo '<div class="glass card inner"><div class="muted small">Files</div><ul class="list">';
            foreach ($files as $fn) { $fn = basename((string)$fn); if ($fn === '') continue; echo '<li><a class="btn btn-ghost btn-sm" href="download.php?order_id=' . (int)$o['id'] . '&file=' . urlencode($fn) . '">Download ' . e($fn) . '</a></li>'; }
            echo '</ul></div>';
        }
    }

    echo '</div>';
    page_footer();
    exit;
}

// ---- Product detail ----
if ($productId > 0) {
    $st = db()->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.is_hidden=0 LIMIT 1");
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p) {
        echo '<div class="glass card"><h2>Product not found</h2></div>';
        page_footer();
        exit;
    }
    $img = (string)($p['image_path'] ?: $p['image_url']);
    $type = (string)$p['type'];
    $isLogged = (bool)current_user();

    echo '<div class="grid prod-page">';
    echo '<div class="glass card">';
    if ($img !== '') echo '<img class="pimg" src="' . e($img) . '" alt="product">';
    echo '<div class="muted small">' . e((string)($p['category_name'] ?? '')) . ' · <span class="badge">' . e($type) . '</span></div>';
    echo '<h2>' . e((string)$p['name']) . '</h2>';
    if (!empty($p['short_desc'])) echo '<div class="muted">' . e((string)$p['short_desc']) . '</div>';
    if (!empty($p['description'])) echo '<div class="muted" style="margin-top:10px">' . nl2br(e((string)$p['description'])) . '</div>';
    echo '</div>';

    echo '<div class="glass card">';
    echo '<h2>Buy</h2>';
    if ($type === 'account') {
        $inStock = ((int)$p['stock'] > 0);
        echo '<div class="row"><span class="pill ' . ($inStock ? 'ok' : 'bad') . '">' . ($inStock ? 'In stock' : 'Out of stock') . '</span></div>';
        if ($inStock) {
            echo '<form method="post" class="form">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="cart_add">';
            echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<input type="hidden" name="variant_id" value="0">';
            echo '<input type="hidden" name="qty" value="1">';
            echo '<input type="hidden" name="go" value="checkout">';
            if ($isLogged) echo '<button class="btn btn-full" type="submit">Buy now</button>';
            else echo '<button class="btn btn-full js-login-required" type="button">Buy now</button>';
            echo '</form>';
        }
    } else {
        $stV = db()->prepare("SELECT id,label,price,stock FROM product_variants WHERE product_id=? AND is_hidden=0 ORDER BY sort_order ASC, id ASC");
        $stV->execute([(int)$p['id']]);
        $vars = $stV->fetchAll();
        if (!$vars) {
            echo '<div class="notice error">No options configured for this product (admin needs to add variants).</div>';
        } else {
            echo '<form method="post" class="form">';
            echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="cart_add">';
            echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
            echo '<input type="hidden" name="go" value="">';
            echo '<div class="muted small">Select option</div><div class="optlist">';
            foreach ($vars as $i => $v) {
                $disabled = ((int)$v['stock'] <= 0);
                echo '<label class="opt ' . ($disabled ? 'disabled' : '') . '">';
                echo '<input type="radio" name="variant_id" value="' . (int)$v['id'] . '" ' . ($i === 0 ? 'checked' : '') . ' ' . ($disabled ? 'disabled' : '') . '>';
                echo '<span class="opt-l">' . e((string)$v['label']) . '</span>';
                echo '<span class="opt-r">₹' . e(money_fmt($v['price'])) . '</span>';
                echo '<span class="opt-s ' . ((int)$v['stock'] > 0 ? 'ok' : 'bad') . '">' . ((int)$v['stock'] > 0 ? ('stock ' . (int)$v['stock']) : 'out') . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo '<label class="label">Quantity</label><input class="input mono" type="number" name="qty" min="1" max="10" value="1" required>';
            echo '<div class="row">';
            if ($isLogged) {
                echo '<button class="btn" type="submit" onclick="this.form.go.value=\'\'">Add to cart</button>';
                echo '<button class="btn btn-ghost" type="submit" onclick="this.form.go.value=\'checkout\'">Buy now</button>';
            } else {
                echo '<button class="btn js-login-required" type="button">Add to cart</button>';
                echo '<button class="btn btn-ghost js-login-required" type="button">Buy now</button>';
            }
            echo '</div></form>';
        }
    }
    echo '</div></div>';

    echo '<div class="glass card"><a class="btn btn-ghost" href="index.php">← Back</a></div>';
    if (!$isLogged) {
        echo '<div class="modal" id="loginModal" style="display:none"><div class="modal-backdrop"></div><div class="modal-card glass"><div class="modal-head"><div class="modal-title">Login required</div><button class="xbtn" aria-label="Close">×</button></div><div class="muted">Buy karne ke liye login/signup zaroori hai.</div><div class="modal-actions"><a class="btn" href="login.php">Login</a><a class="btn btn-ghost" href="register.php">Register</a></div></div></div>';
        echo '<script>(function(){var m=document.getElementById("loginModal");function o(){m.style.display="block";}function c(){m.style.display="none";}document.querySelectorAll(".js-login-required").forEach(function(b){b.addEventListener("click",o);});m.querySelector(".modal-backdrop").addEventListener("click",c);m.querySelector(".xbtn").addEventListener("click",c);})();</script>';
    }
    page_footer();
    exit;
}

// ---- Profile ----
if ($page === 'profile') {
    $u = require_login();
    echo '<div class="glass card"><h2>Profile</h2>';
    echo '<form method="post" class="form"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="profile_update">';
    echo '<label class="label">Display name</label><input class="input" name="display_name" value="' . e((string)($u['display_name'] ?? $u['username'])) . '" maxlength="80">';
    echo '<label class="label">Avatar URL (optional)</label><input class="input mono" name="avatar_url" value="' . e((string)($u['avatar_url'] ?? '')) . '" maxlength="255" placeholder="https://...">';
    echo '<button class="btn btn-full" type="submit">Save</button></form></div>';

    echo '<div class="glass card"><h2>Change password</h2>';
    echo '<form method="post" class="form"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="password_change">';
    echo '<label class="label">Old password</label><input class="input" type="password" name="old_password" required>';
    echo '<label class="label">New password</label><input class="input" type="password" name="new_password" required>';
    echo '<label class="label">Confirm new password</label><input class="input" type="password" name="new_password2" required>';
    echo '<button class="btn btn-full" type="submit">Update password</button></form></div>';
    page_footer();
    exit;
}

// ---- Orders list ----
if ($page === 'orders') {
    $u = require_login();
    db()->prepare("UPDATE users SET orders_last_seen_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
    echo '<div class="section-title"><h2>My Orders</h2><div class="muted">Track payments and deliveries</div></div>';
    echo '<div class="glass card"><div class="row"><div><div class="muted small">Referral code</div><div class="mono big">' . e((string)$u['referral_code']) . '</div></div><div class="muted small">Share it during signup (optional).</div></div></div>';
    $st = db()->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 50");
    $st->execute([(int)$u['id']]);
    $orders = $st->fetchAll();
    if (!$orders) {
        echo '<div class="glass card"><div class="muted">No orders yet.</div></div>';
        page_footer();
        exit;
    }
    echo '<div class="glass card table-wrap"><table class="table"><thead><tr><th>Order</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $status = (string)$o['status'];
        $badge = 'badge';
        if ($status === 'completed') $badge .= ' good';
        if ($status === 'cancelled') $badge .= ' bad';
        if ($status === 'submitted') $badge .= ' warn';
        echo '<tr>';
        echo '<td class="mono">#' . (int)$o['id'] . '<div class="muted small">' . e((string)$o['created_at']) . '</div></td>';
        echo '<td>₹' . e(money_fmt($o['total_amount'])) . '</td>';
        echo '<td><span class="' . e($badge) . '">' . e($status) . '</span></td>';
        echo '<td>';
        if ($status === 'pending' && (float)$o['pay_amount'] > 0) echo '<a class="btn btn-ghost btn-sm" href="checkout.php?order_id=' . (int)$o['id'] . '">Submit payment</a> ';
        echo '<a class="btn btn-sm" href="index.php?order=' . (int)$o['id'] . '">Open</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    page_footer();
    exit;
}

// ---- Home ----
$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);

$categories = db()->query("SELECT * FROM categories WHERE is_hidden=0 ORDER BY sort_order ASC, id DESC")->fetchAll();

$sql = "SELECT p.*,
               c.name AS category_name,
               COALESCE(MIN(CASE WHEN v.is_hidden=0 THEN v.price END), NULL) AS min_price,
               COALESCE(MAX(CASE WHEN v.is_hidden=0 THEN v.price END), NULL) AS max_price,
               COALESCE(SUM(CASE WHEN v.is_hidden=0 THEN v.stock ELSE 0 END), 0) AS variant_stock
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN product_variants v ON v.product_id=p.id
        WHERE p.is_hidden=0";
$params = [];
if ($cat > 0) { $sql .= " AND p.category_id=?"; $params[] = $cat; }
if ($q !== '') { $sql .= " AND (p.name LIKE ? OR p.short_desc LIKE ?)"; $params[]='%' . $q . '%'; $params[]='%' . $q . '%'; }
$sql .= " GROUP BY p.id ORDER BY p.id DESC LIMIT 60";
$st = db()->prepare($sql);
$st->execute($params);
$products = $st->fetchAll();

echo '<div class="hero glass"><div class="hero-left">';
echo '<h1><span class="neon">' . e(SITE_NAME) . '</span></h1>';
echo '<p class="muted">Gaming digital store · Categories · Manual UPI/Binance verification · E-Box delivery</p>';
echo '<div class="hero-actions">';
if (current_user()) {
    echo '<a class="btn" href="checkout.php?cart=1">Open Cart</a>';
    echo '<a class="btn btn-ghost" href="index.php?page=orders">My Orders</a>';
    echo '<a class="btn btn-ghost" href="index.php?page=profile">Profile</a>';
} else {
    echo '<a class="btn" href="register.php">Create Account</a>';
    echo '<a class="btn btn-ghost" href="login.php">Login</a>';
}
echo '</div></div><div class="hero-right"><div class="stat-row">';
echo '<div class="stat"><div class="stat-k">UPI ID</div><div class="stat-v">' . e(upi_vpa() !== '' ? upi_vpa() : 'Not set') . '</div></div>';
echo '<div class="stat"><div class="stat-k">Payee</div><div class="stat-v">' . e(upi_payee()) . '</div></div>';
echo '</div></div></div>';

echo '<section class="section"><div class="section-title"><h2>Categories</h2><div class="muted">Browse by category</div></div>';
if (!$categories) {
    echo '<div class="glass card"><div class="muted">No categories yet.</div></div>';
} else {
    echo '<div class="cats">';
    echo '<a class="cat ' . ($cat === 0 ? 'active' : '') . '" href="index.php">All</a>';
    foreach ($categories as $c) {
        $img = (string)($c['image_path'] ?: $c['image_url']);
        echo '<a class="cat ' . ((int)$c['id'] === $cat ? 'active' : '') . '" href="index.php?cat=' . (int)$c['id'] . '">';
        if ($img !== '') echo '<img src="' . e($img) . '" alt="cat">';
        echo '<span>' . e((string)$c['name']) . '</span></a>';
    }
    echo '</div>';
}
echo '</section>';

echo '<section class="section"><div class="section-title"><h2>Products</h2><div class="muted">Search & buy</div></div>';
echo '<div class="glass card"><form method="get" class="row">';
echo '<input type="hidden" name="cat" value="' . (int)$cat . '">';
echo '<input class="input" name="q" value="' . e($q) . '" placeholder="Search products...">';
echo '<button class="btn btn-ghost" type="submit">Search</button>';
echo '</form></div>';

if (!$products) {
    echo '<div class="glass card"><div class="muted">No products found.</div></div>';
} else {
    echo '<div class="grid">';
    foreach ($products as $p) {
        $img = (string)($p['image_path'] ?: $p['image_url']);
        $type = (string)$p['type'];
        $stock = ($type === 'account') ? (int)$p['stock'] : (int)$p['variant_stock'];
        $inStock = $stock > 0;
        $min = $p['min_price'];
        $max = $p['max_price'];
        $priceText = ($min === null) ? '—' : ('₹' . money_fmt((float)$min) . ($max !== null && (float)$max > (float)$min ? ' - ₹' . money_fmt((float)$max) : ''));

        echo '<div class="glass card pcard">';
        if ($img !== '') echo '<img class="thumb" src="' . e($img) . '" alt="product">';
        echo '<div class="pcard-meta"><span class="badge">' . e($type) . '</span>' . (!empty($p['category_name']) ? '<span class="muted small">' . e((string)$p['category_name']) . '</span>' : '') . '</div>';
        echo '<div class="card-title">' . e((string)$p['name']) . '</div>';
        if (!empty($p['short_desc'])) echo '<div class="muted small">' . e((string)$p['short_desc']) . '</div>';
        echo '<div class="row" style="margin-top:10px">';
        echo '<div class="price">' . e($priceText) . '</div>';
        echo '<span class="pill ' . ($inStock ? 'ok' : 'bad') . '">' . ($inStock ? ('Stock ' . (int)$stock) : 'Out') . '</span>';
        echo '</div>';
        echo '<div class="card-bottom">';
        echo '<a class="btn btn-ghost" href="index.php?product=' . (int)$p['id'] . '">View</a>';
        if ($inStock) echo '<a class="btn" href="index.php?product=' . (int)$p['id'] . '">Buy</a>';
        else echo '<span class="btn btn-disabled">Unavailable</span>';
        echo '</div></div>';
    }
    echo '</div>';
}
echo '</section>';

page_footer();

