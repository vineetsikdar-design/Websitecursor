<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $ref = trim((string)($_POST['referral_code'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email.');
        redirect('register.php');
    }
    if (strlen($pass) < 6) {
        flash_set('error', 'Password must be at least 6 characters.');
        redirect('register.php');
    }
    if ($pass !== $pass2) {
        flash_set('error', 'Passwords do not match.');
        redirect('register.php');
    }

    $referredBy = null;
    if ($ref !== '') {
        $st = db()->prepare("SELECT id FROM users WHERE referral_code=? LIMIT 1");
        $st->execute([$ref]);
        $r = $st->fetch();
        if (!$r) {
            flash_set('error', 'Invalid referral code.');
            redirect('register.php');
        }
        $referredBy = (int)$r['id'];
    }

    $refCode = '';
    for ($i = 0; $i < 6; $i++) {
        $refCode = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars, uppercase
        $st = db()->prepare("SELECT 1 FROM users WHERE referral_code=? LIMIT 1");
        $st->execute([$refCode]);
        if (!$st->fetch()) break;
    }
    if ($refCode === '') {
        flash_set('error', 'Could not generate referral code. Try again.');
        redirect('register.php');
    }

    try {
        $st = db()->prepare("INSERT INTO users (email,password_hash,wallet_balance,referral_code,referred_by,is_admin) VALUES (?,?,?,?,?,0)");
        $st->execute([
            $email,
            password_hash($pass, PASSWORD_BCRYPT),
            '0.00',
            $refCode,
            $referredBy
        ]);
        $uid = (int)db()->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        flash_set('success', 'Account created!');
        redirect('index.php');
    } catch (Throwable $e) {
        $msg = 'Could not register. Email may already be used.';
        if (DEBUG) $msg = (string)$e;
        flash_set('error', $msg);
        redirect('register.php');
    }
}

page_header('Register — ' . SITE_NAME);
?>

<div class="center">
  <div class="glass card auth">
    <h2>Create account</h2>
    <p class="muted">Register to use wallet and purchase products.</p>

    <form method="post" class="form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <label class="label">Email</label>
      <input class="input" type="email" name="email" required autocomplete="email" placeholder="you@example.com">

      <label class="label">Password</label>
      <input class="input" type="password" name="password" required autocomplete="new-password" placeholder="Minimum 6 characters">

      <label class="label">Confirm password</label>
      <input class="input" type="password" name="password2" required autocomplete="new-password" placeholder="Repeat password">

      <label class="label">Referral code (optional)</label>
      <input class="input" type="text" name="referral_code" maxlength="32" placeholder="Enter code if you have one">

      <button class="btn btn-full" type="submit">Register</button>
    </form>

    <div class="muted small">Already have an account? <a href="login.php">Login</a></div>
  </div>
</div>

<?php page_footer(); ?>

