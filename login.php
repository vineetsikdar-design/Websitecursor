<?php
require __DIR__ . '/config.php';

ensure_dirs();

if (current_user()) {
    redirect('index.php');
}

if (is_post()) {
    csrf_check();

    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email.');
        redirect('login.php');
    }

    $st = db()->prepare('SELECT id, password_hash, is_admin FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($pass, (string)$user['password_hash'])) {
        flash_set('error', 'Invalid email or password.');
        redirect('login.php');
    }

    $_SESSION['uid'] = (int)$user['id'];
    session_regenerate_id(true);
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int)$user['id']]);

    flash_set('ok', 'Welcome back!');
    redirect(!empty($_GET['to']) ? (string)$_GET['to'] : 'index.php');
}

render_header('Login');
?>

<div class="glass card form-card">
    <h2>Login</h2>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <label>
            <span>Email</span>
            <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
        </label>
        <button class="btn primary" type="submit">Login</button>
        <div class="muted small">No account? <a href="register.php">Register</a></div>
    </form>
</div>

<?php render_footer(); ?>

