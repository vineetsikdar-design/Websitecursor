<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

if (maintenance_mode()) {
    page_header('Maintenance — ' . SITE_NAME);
    echo '<div class="glass card"><h2>Maintenance</h2><p class="muted">Signup is temporarily unavailable.</p></div>';
    page_footer();
    exit;
}
if (stop_signup()) {
    page_header('Signup Closed — ' . SITE_NAME);
    echo '<div class="glass card"><h2>Signup closed</h2><p class="muted">New registrations are currently disabled.</p></div>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $terms = (string)($_POST['accept_terms'] ?? '');
    $ref = trim((string)($_POST['referral_code'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email.');
        redirect('register.php');
    }
    if ($username === '' || !preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
        flash_set('error', 'Username must be 3–20 chars (a-z, 0-9, underscore).');
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
    if ($terms !== '1') {
        flash_set('error', 'You must accept terms & conditions.');
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
        $eligibleUntil = date('Y-m-d H:i:s', time() + (90 * 24 * 60 * 60)); // ~3 months
        $st = db()->prepare("INSERT INTO users (email,username,display_name,avatar_url,password_hash,wallet_balance,referral_code,referred_by,referral_eligible_until,is_admin,is_banned)
                             VALUES (?,?,?,?,?, ?,?,?, ?, 0, 0)");
        $st->execute([
            $email,
            $username,
            $username,
            null,
            password_hash($pass, PASSWORD_BCRYPT),
            '0.00',
            $refCode,
            $referredBy,
            $eligibleUntil
        ]);
        $uid = (int)db()->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        flash_set('success', 'Account created!');
        redirect('index.php');
    } catch (Throwable $e) {
        $msg = 'Could not register. Email/username may already be used.';
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

      <label class="label">Username</label>
      <input class="input mono" type="text" name="username" required minlength="3" maxlength="20" placeholder="yourname_01">
      <div class="muted small">Allowed: a-z, 0-9, underscore</div>

      <label class="label">Password</label>
      <input class="input" type="password" name="password" required autocomplete="new-password" placeholder="Minimum 6 characters">

      <label class="label">Confirm password</label>
      <input class="input" type="password" name="password2" required autocomplete="new-password" placeholder="Repeat password">

      <label class="label">Referral code (optional)</label>
      <input class="input" type="text" name="referral_code" maxlength="32" placeholder="Enter code if you have one">

      <label class="check"><input type="checkbox" name="accept_terms" value="1" required> I accept Terms & Conditions</label>

      <button class="btn btn-full" type="submit">Register</button>
    </form>

    <div class="muted small">Already have an account? <a href="login.php">Login</a></div>
  </div>
</div>

<?php page_footer(); ?>

