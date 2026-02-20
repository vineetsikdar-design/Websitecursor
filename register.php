<?php
require __DIR__ . '/config.php';

ensure_dirs();

if (current_user()) {
    redirect('index.php');
}

function new_referral_code(): string {
    $raw = bin2hex(random_bytes(6)); // 12 chars
    return strtoupper(substr($raw, 0, 10));
}

if (is_post()) {
    csrf_check();

    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $ref = strtoupper(trim((string)($_POST['ref'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email.');
        redirect('register.php');
    }
    if (strlen($pass) < 8) {
        flash_set('error', 'Password must be at least 8 characters.');
        redirect('register.php');
    }
    if ($pass !== $pass2) {
        flash_set('error', 'Passwords do not match.');
        redirect('register.php');
    }

    $referredBy = null;
    if ($ref !== '') {
        $st = db()->prepare('SELECT id FROM users WHERE referral_code = ?');
        $st->execute([$ref]);
        $r = $st->fetch();
        if (!$r) {
            flash_set('error', 'Invalid referral code.');
            redirect('register.php');
        }
        $referredBy = (int)$r['id'];
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    $refCode = new_referral_code();
    // ensure unique referral code (rare collision)
    for ($i = 0; $i < 5; $i++) {
        $chk = db()->prepare('SELECT id FROM users WHERE referral_code = ?');
        $chk->execute([$refCode]);
        if (!$chk->fetch()) break;
        $refCode = new_referral_code();
    }

    try {
        $st = db()->prepare('INSERT INTO users (email, password_hash, wallet, is_admin, referral_code, referred_by, created_at) VALUES (?, ?, 0, 0, ?, ?, NOW())');
        $st->execute([$email, $hash, $refCode, $referredBy]);
        $uid = (int)db()->lastInsertId();

        $_SESSION['uid'] = $uid;
        session_regenerate_id(true);
        flash_set('ok', 'Account created! Welcome to ZENTRAXX STORE.');
        redirect('index.php');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash_set('error', 'Email already registered. Please login.');
            redirect('login.php');
        }
        throw $e;
    }
}

render_header('Register');
?>

<div class="glass card form-card">
    <h2>Create account</h2>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <label>
            <span>Email</span>
            <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" required autocomplete="new-password" placeholder="Minimum 8 characters">
        </label>
        <label>
            <span>Confirm password</span>
            <input type="password" name="password2" required autocomplete="new-password" placeholder="Re-type password">
        </label>
        <label>
            <span>Referral code (optional)</span>
            <input type="text" name="ref" maxlength="20" placeholder="E.g. A1B2C3D4E5">
        </label>
        <button class="btn primary" type="submit">Register</button>
        <div class="muted small">Already have an account? <a href="login.php">Login</a></div>
    </form>
</div>

<?php render_footer(); ?>

