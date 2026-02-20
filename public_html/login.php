<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email.');
        redirect('login.php');
    }

    $st = db()->prepare("SELECT id,password_hash FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || !password_verify($pass, (string)$u['password_hash'])) {
        flash_set('error', 'Invalid email or password.');
        redirect('login.php');
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    flash_set('success', 'Welcome back!');
    redirect('index.php');
}

page_header('Login — ' . SITE_NAME);
?>

<div class="center">
  <div class="glass card auth">
    <h2>Login</h2>
    <p class="muted">Access your wallet and orders.</p>

    <form method="post" class="form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label class="label">Email</label>
      <input class="input" type="email" name="email" required autocomplete="email" placeholder="you@example.com">

      <label class="label">Password</label>
      <input class="input" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">

      <button class="btn btn-full" type="submit">Login</button>
    </form>

    <div class="muted small">No account? <a href="register.php">Register</a></div>
  </div>
</div>

<?php page_footer(); ?>

