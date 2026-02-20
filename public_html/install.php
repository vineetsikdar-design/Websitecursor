<?php
require_once __DIR__ . '/config.php';

page_header('Install — ' . SITE_NAME);

if (installed()) {
    echo '<div class="glass card"><h2>Already installed</h2><p class="muted">Your store is already installed. For safety, delete <code>install.php</code> from your hosting after setup.</p><a class="btn" href="index.php">Go to store</a></div>';
    page_footer();
    exit;
}

function sql_split_statements(string $sql): array
{
    $sql = str_replace("\r\n", "\n", $sql);
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '' || substr($t, 0, 2) === '--') continue;
        $clean[] = $ln;
    }
    $sql = implode("\n", $clean);

    $out = [];
    $buf = '';
    $inStr = false;
    $strChar = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buf .= $ch;
        if ($inStr) {
            if ($ch === $strChar) {
                $prev = $sql[$i - 1] ?? '';
                if ($prev !== '\\') {
                    $inStr = false;
                    $strChar = '';
                }
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inStr = true;
            $strChar = $ch;
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($buf);
            $buf = '';
            if ($stmt !== ';' && $stmt !== '') $out[] = $stmt;
        }
    }
    $tail = trim($buf);
    if ($tail !== '') $out[] = $tail;
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $adminUser = strtolower(trim((string)($_POST['admin_username'] ?? '')));
    $adminPass = (string)($_POST['admin_password'] ?? '');
    $adminPass2 = (string)($_POST['admin_password2'] ?? '');

    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid admin email.');
        redirect('install.php');
    }
    if ($adminUser === '' || !preg_match('/^[a-z0-9_]{3,20}$/', $adminUser)) {
        flash_set('error', 'Admin username must be 3–20 chars (a-z, 0-9, underscore).');
        redirect('install.php');
    }
    if (strlen($adminPass) < 6) {
        flash_set('error', 'Admin password must be at least 6 characters.');
        redirect('install.php');
    }
    if ($adminPass !== $adminPass2) {
        flash_set('error', 'Admin passwords do not match.');
        redirect('install.php');
    }

    try {
        // Run schema (idempotent)
        $sqlPath = __DIR__ . '/database.sql';
        $sql = @file_get_contents($sqlPath);
        if ($sql === false) {
            throw new RuntimeException('Could not read database.sql');
        }
        $stmts = sql_split_statements($sql);
        foreach ($stmts as $stmt) {
            db()->exec($stmt);
        }

        // Create admin user if none exists
        $st = db()->query("SELECT id FROM users WHERE is_admin=1 LIMIT 1");
        $existingAdmin = $st->fetch();
        if (!$existingAdmin) {
            $refCode = strtoupper(bin2hex(random_bytes(4)));
            $ins = db()->prepare("INSERT INTO users (email,username,display_name,password_hash,wallet_balance,referral_code,referred_by,referral_eligible_until,is_admin,is_banned)
                                  VALUES (?,?,?,?,?, ?, NULL, NULL, 1, 0)");
            $ins->execute([
                $adminEmail,
                $adminUser,
                'Admin',
                password_hash($adminPass, PASSWORD_BCRYPT),
                '0.00',
                $refCode
            ]);
        }

        setting_set('installed', '1');

        flash_set('success', 'Installed! You can now login as admin.');
        redirect('login.php');
    } catch (Throwable $e) {
        $msg = 'Install failed. Import database.sql manually and retry.';
        if (DEBUG) $msg = (string)$e;
        flash_set('error', $msg);
        redirect('install.php');
    }
}

?>

<div class="center">
  <div class="glass card auth">
    <h2>Install <?= e(SITE_NAME) ?></h2>
    <p class="muted">This will create DB tables (if needed) and create your first admin user.</p>

    <form method="post" class="form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <label class="label">Admin email</label>
      <input class="input" type="email" name="admin_email" required placeholder="admin@example.com">

      <label class="label">Admin username</label>
      <input class="input mono" type="text" name="admin_username" required minlength="3" maxlength="20" placeholder="admin">
      <div class="muted small">Allowed: a-z, 0-9, underscore</div>

      <label class="label">Admin password</label>
      <input class="input" type="password" name="admin_password" required placeholder="Minimum 6 characters">

      <label class="label">Confirm password</label>
      <input class="input" type="password" name="admin_password2" required placeholder="Repeat password">

      <button class="btn btn-full" type="submit">Install</button>
    </form>

    <div class="muted small">If install fails, import <code>database.sql</code> in phpMyAdmin and refresh this page.</div>
  </div>
</div>

<?php page_footer(); ?>

