<?php
require_once __DIR__ . '/config.php';

if (current_user($pdo)) {
    redirect('index.php');
}

$flash = get_flash();
$errors = [];
$prefilledReferral = strtoupper(trim($_GET['ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $referralCode = strtoupper(trim($_POST['referral_code'] ?? ''));

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Password and confirm password do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $emailCheck->execute([$email]);
            if ($emailCheck->fetch()) {
                throw new Exception('Email already registered. Please login.');
            }

            $referredBy = null;
            $referrerBonus = 20.00;
            $newUserBonus = 10.00;
            $walletStart = 0.00;

            if ($referralCode !== '') {
                $refStmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
                $refStmt->execute([$referralCode]);
                $refUser = $refStmt->fetch();
                if (!$refUser) {
                    throw new Exception('Referral code is invalid.');
                }
                $referredBy = (int) $refUser['id'];
                $walletStart = $newUserBonus;
            }

            $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
            $isAdmin = $adminCount === 0 ? 1 : 0;

            $insert = $pdo->prepare('
                INSERT INTO users (name, email, password, wallet_balance, referral_code, referred_by, is_admin)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $name,
                $email,
                password_hash($password, PASSWORD_BCRYPT),
                money($walletStart),
                generate_referral_code($pdo),
                $referredBy,
                $isAdmin,
            ]);

            if ($referredBy) {
                $bonusStmt = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?');
                $bonusStmt->execute([money($referrerBonus), $referredBy]);
            }

            $pdo->commit();

            $msg = 'Registration successful. Please login.';
            if ($isAdmin === 1) {
                $msg .= ' Your account is the first account, so admin access is enabled.';
            }
            set_flash($msg, 'success');
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
    <title>Register - ZENTRAXX STORE</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand">ZENTRAXX STORE</div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Home</a>
            <a class="chip-link" href="login.php">Login</a>
            <a class="chip-link" href="install.php">Install</a>
        </div>
    </div>

    <div class="glass form-card">
        <h2>Create Account</h2>
        <p class="muted">Register to buy digital products instantly.</p>

        <?php if ($flash): ?>
            <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $error): ?>
                    <div>- <?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php echo csrf_input(); ?>
            <div class="field">
                <label>Name</label>
                <input type="text" name="name" required value="<?php echo e($_POST['name'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>
            <div class="field">
                <label>Referral Code (Optional)</label>
                <input type="text" name="referral_code" value="<?php echo e($_POST['referral_code'] ?? $prefilledReferral); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Register</button>
        </form>

        <p class="muted">Already have an account? <a href="login.php">Login here</a>.</p>
    </div>
</div>
</body>
</html>
