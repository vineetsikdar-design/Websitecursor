<?php
require_once __DIR__ . '/config.php';

if (current_user($pdo)) {
    redirect('index.php');
}

$flash = get_flash();
$errors = [];
$next = trim($_GET['next'] ?? ($_POST['next'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter valid email.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id, password, is_admin FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid login credentials.';
        } else {
            login_user((int) $user['id']);
            set_flash('Welcome back!', 'success');

            $allowed = ['index.php', 'checkout.php', 'admin.php'];
            if (!in_array($next, $allowed, true)) {
                $next = ((int) $user['is_admin'] === 1) ? 'admin.php' : 'index.php';
            }
            redirect($next);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - ZENTRAXX STORE</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand">ZENTRAXX STORE</div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Home</a>
            <a class="chip-link" href="register.php">Register</a>
            <a class="chip-link" href="install.php">Install</a>
        </div>
    </div>

    <div class="glass form-card">
        <h2>Login</h2>
        <p class="muted">Access your wallet, orders, and downloads.</p>

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
            <input type="hidden" name="next" value="<?php echo e($next); ?>">
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button class="btn btn-primary" type="submit">Login</button>
        </form>
        <p class="muted">No account yet? <a href="register.php">Register now</a>.</p>
    </div>
</div>
</body>
</html>
