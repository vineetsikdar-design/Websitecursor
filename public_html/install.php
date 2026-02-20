<?php
require_once __DIR__ . '/config.php';

$flash = get_flash();
$errors = [];
$settings = get_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $siteName = trim($_POST['site_name'] ?? 'ZENTRAXX STORE');
    $upiId = trim($_POST['upi_id'] ?? 'demo@upi');
    $upiName = trim($_POST['upi_name'] ?? 'Zentraxx Store');
    $instructions = trim($_POST['payment_instructions'] ?? '');

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    if ($adminName === '') {
        $errors[] = 'Admin name is required.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid admin email is required.';
    }
    if (strlen($adminPassword) < 6) {
        $errors[] = 'Admin password must be at least 6 characters.';
    }

    if (empty($errors)) {
        $schemaSql = [
            "CREATE TABLE IF NOT EXISTS users (
                id int unsigned NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                email varchar(150) NOT NULL,
                password varchar(255) NOT NULL,
                wallet_balance decimal(12,2) NOT NULL DEFAULT '0.00',
                referral_code varchar(20) DEFAULT NULL,
                referred_by int unsigned DEFAULT NULL,
                is_admin tinyint(1) NOT NULL DEFAULT '0',
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_users_email (email),
                UNIQUE KEY uniq_users_ref_code (referral_code),
                KEY idx_users_referred_by (referred_by),
                CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS products (
                id int unsigned NOT NULL AUTO_INCREMENT,
                title varchar(150) NOT NULL,
                description text,
                price decimal(12,2) NOT NULL,
                stock int unsigned NOT NULL DEFAULT '0',
                download_link varchar(255) DEFAULT NULL,
                is_hidden tinyint(1) NOT NULL DEFAULT '0',
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_products_visible (is_hidden, stock)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS orders (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id int unsigned NOT NULL,
                product_id int unsigned DEFAULT NULL,
                quantity int unsigned NOT NULL DEFAULT '1',
                total_amount decimal(12,2) NOT NULL,
                wallet_used decimal(12,2) NOT NULL DEFAULT '0.00',
                upi_amount decimal(12,2) NOT NULL DEFAULT '0.00',
                utr varchar(22) DEFAULT NULL,
                screenshot_path varchar(255) DEFAULT NULL,
                screenshot_hash char(64) DEFAULT NULL,
                status enum('pending','submitted','completed','cancelled') NOT NULL DEFAULT 'pending',
                wallet_refunded tinyint(1) NOT NULL DEFAULT '0',
                admin_note varchar(255) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_orders_utr (utr),
                UNIQUE KEY uniq_orders_screenshot_hash (screenshot_hash),
                KEY idx_orders_user (user_id),
                KEY idx_orders_product (product_id),
                KEY idx_orders_status_time (status, created_at),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS settings (
                id tinyint unsigned NOT NULL DEFAULT '1',
                site_name varchar(100) NOT NULL DEFAULT 'ZENTRAXX STORE',
                upi_id varchar(120) NOT NULL DEFAULT 'demo@upi',
                upi_name varchar(120) NOT NULL DEFAULT 'Zentraxx Store',
                payment_instructions text,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            $pdo->beginTransaction();

            foreach ($schemaSql as $statement) {
                $pdo->exec($statement);
            }

            upsert_settings($pdo, $siteName, $upiId, $upiName, $instructions);

            $adminExistsStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $adminExistsStmt->execute([$adminEmail]);
            if ($adminExistsStmt->fetch()) {
                throw new Exception('Admin email already exists. Please use login.php.');
            }

            $newAdmin = $pdo->prepare('
                INSERT INTO users (name, email, password, wallet_balance, referral_code, is_admin)
                VALUES (?, ?, ?, 0.00, ?, 1)
            ');
            $newAdmin->execute([
                $adminName,
                $adminEmail,
                password_hash($adminPassword, PASSWORD_BCRYPT),
                generate_referral_code($pdo),
            ]);

            $pdo->commit();
            set_flash('Installation complete. Admin account created successfully.', 'success');
            redirect('login.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install - ZENTRAXX STORE</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand">ZENTRAXX STORE - INSTALL</div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Home</a>
            <a class="chip-link" href="login.php">Login</a>
            <a class="chip-link" href="register.php">Register</a>
        </div>
    </div>

    <div class="glass hero">
        <h1>Quick Setup Wizard</h1>
        <p>Use this only once to create tables and your first admin user.</p>
    </div>

    <div class="glass form-card">
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

        <form method="post">
            <?php echo csrf_input(); ?>

            <h3>Store Settings</h3>
            <div class="field">
                <label>Site Name</label>
                <input type="text" name="site_name" required value="<?php echo e($settings['site_name']); ?>">
            </div>
            <div class="form-grid">
                <div class="field">
                    <label>UPI ID</label>
                    <input type="text" name="upi_id" required value="<?php echo e($settings['upi_id']); ?>">
                </div>
                <div class="field">
                    <label>UPI Receiver Name</label>
                    <input type="text" name="upi_name" required value="<?php echo e($settings['upi_name']); ?>">
                </div>
            </div>
            <div class="field">
                <label>Payment Instructions</label>
                <textarea name="payment_instructions" required><?php echo e($settings['payment_instructions']); ?></textarea>
            </div>

            <hr class="sep">

            <h3>Admin User</h3>
            <div class="field">
                <label>Admin Name</label>
                <input type="text" name="admin_name" required>
            </div>
            <div class="field">
                <label>Admin Email</label>
                <input type="email" name="admin_email" required>
            </div>
            <div class="field">
                <label>Admin Password</label>
                <input type="password" name="admin_password" required minlength="6">
            </div>

            <button class="btn btn-primary" type="submit">Run Installation</button>
        </form>
    </div>
</div>
</body>
</html>
