<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

$action = (string)($_GET['action'] ?? '');

function password_reset_create(int $uid): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $exp = date('Y-m-d H:i:s', time() + 30 * 60);
    $st = db()->prepare("INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)");
    $st->execute([$uid, $hash, $exp]);
    return $token;
}

function password_reset_find_user(string $token): ?array
{
    $hash = hash('sha256', $token);
    $st = db()->prepare("SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used_at, u.email
                         FROM password_resets pr
                         JOIN users u ON u.id=pr.user_id
                         WHERE pr.token_hash=? ORDER BY pr.id DESC LIMIT 1");
    $st->execute([$hash]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!empty($r['used_at'])) return null;
    if (strtotime((string)$r['expires_at']) < time()) return null;
    return $r;
}

if ($action === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // Always show success to avoid account enumeration
    try {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = db()->prepare("SELECT id,email FROM users WHERE email=? AND is_banned=0 LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u) {
                $token = password_reset_create((int)$u['id']);
                $link = site_url('login.php?action=reset&token=' . urlencode($token));
                $body = '<p>Reset your password:</p><p><a href="' . e($link) . '">' . e($link) . '</a></p><p>If you did not request this, ignore.</p>';
                smtp_send_mail((string)$u['email'], SITE_NAME . ' Password Reset', $body);
            }
        }
    } catch (Throwable) {
        // ignore
    }
    flash_set('success', 'If the email exists, a reset link has been sent.');
    redirect('login.php?action=forgot');
}

if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token = (string)($_POST['token'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if (strlen($pass) < 6) {
        flash_set('error', 'Password must be at least 6 characters.');
        redirect('login.php?action=reset&token=' . urlencode($token));
    }
    if ($pass !== $pass2) {
        flash_set('error', 'Passwords do not match.');
        redirect('login.php?action=reset&token=' . urlencode($token));
    }
    $r = password_reset_find_user($token);
    if (!$r) {
        flash_set('error', 'Invalid or expired token.');
        redirect('login.php');
    }
    db()->beginTransaction();
    try {
        $up = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $up->execute([password_hash($pass, PASSWORD_BCRYPT), (int)$r['user_id']]);
        $up2 = db()->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?");
        $up2->execute([(int)$r['reset_id']]);
        db()->commit();
        flash_set('success', 'Password updated. Please login.');
        redirect('login.php');
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('error', 'Could not reset password.');
        redirect('login.php');
    }
}

if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = strtolower(trim((string)($_POST['login'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if ($login === '') {
        flash_set('error', 'Enter email or username.');
        redirect('login.php');
    }

    $st = db()->prepare("SELECT id,password_hash,is_banned,is_admin,welcome_seen_at FROM users WHERE (email=? OR username=?) LIMIT 1");
    $st->execute([$login, $login]);
    $u = $st->fetch();

    if (stop_login() && (!$u || empty($u['is_admin']))) {
        flash_set('error', 'Login is temporarily disabled.');
        redirect('login.php');
    }

    if (!$u || !password_verify($pass, (string)$u['password_hash']) || !empty($u['is_banned'])) {
        flash_set('error', 'Invalid credentials.');
        redirect('login.php');
    }

    // allow admin even if stop_login (handled above)
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    try {
        db()->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
        if (setting_bool('welcome_enabled', true) && empty($u['welcome_seen_at'])) {
            db()->prepare("UPDATE users SET welcome_seen_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
            $_SESSION['show_welcome_once'] = 1;
        }
    } catch (Throwable) { }

    flash_set('success', 'Welcome back!');
    redirect('index.php');
}

page_header('Login — ' . SITE_NAME);
?>

<div class="center">
  <div class="glass card auth">
    <?php if ($action === 'forgot'): ?>
      <h2>Forgot password</h2>
      <p class="muted">We will email you a reset link (if your email exists).</p>
      <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="label">Email</label>
        <input class="input" type="email" name="email" required autocomplete="email" placeholder="you@example.com">
        <button class="btn btn-full" type="submit">Send reset link</button>
      </form>
      <div class="muted small"><a href="login.php">Back to login</a></div>
    <?php elseif ($action === 'reset'): ?>
      <h2>Reset password</h2>
      <p class="muted">Choose a new password.</p>
      <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e((string)($_GET['token'] ?? '')) ?>">
        <label class="label">New password</label>
        <input class="input" type="password" name="password" required autocomplete="new-password" placeholder="Minimum 6 characters">
        <label class="label">Confirm password</label>
        <input class="input" type="password" name="password2" required autocomplete="new-password" placeholder="Repeat password">
        <button class="btn btn-full" type="submit">Update password</button>
      </form>
      <div class="muted small"><a href="login.php">Back to login</a></div>
    <?php else: ?>
      <h2>Login</h2>
      <p class="muted">Login with email or username.</p>

      <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="label">Email or Username</label>
        <input class="input" type="text" name="login" required autocomplete="username" placeholder="email@example.com or username">

        <label class="label">Password</label>
        <input class="input" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">

        <button class="btn btn-full" type="submit">Login</button>
      </form>

      <div class="row">
        <div class="muted small">No account? <a href="register.php">Register</a></div>
        <div class="muted small"><a href="login.php?action=forgot">Forgot password?</a></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php page_footer(); ?>

