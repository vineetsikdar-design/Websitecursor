<?php
require __DIR__ . '/config.php';

ensure_dirs();

$dbOk = true;
$dbErr = '';
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $dbOk = false;
    $dbErr = $e->getMessage();
}

function table_exists(string $name): bool {
    $st = db()->prepare("SHOW TABLES LIKE ?");
    $st->execute([$name]);
    return (bool)$st->fetch();
}

$installed = false;
if ($dbOk) {
    $installed = table_exists('users') && table_exists('products') && table_exists('orders');
}

if (is_post() && $dbOk) {
    csrf_check();
    $sqlFile = __DIR__ . '/database.sql';
    if (!is_file($sqlFile)) {
        flash_set('error', 'database.sql not found.');
        redirect('install.php');
    }
    $sql = (string)file_get_contents($sqlFile);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = trim($sql);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Very simple splitter (SQL file is simple; no procedures)
        $parts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($parts as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $pdo->commit();
        ensure_dirs();
        flash_set('ok', 'Installed successfully. You can now login and start using the store.');
        redirect('install.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Install failed: ' . $e->getMessage());
        redirect('install.php');
    }
}

render_header('Installer');
?>

<div class="glass card">
    <h2>Installer</h2>
    <p class="muted">Use this once after editing <code>config.php</code>. Then delete <code>install.php</code> for security.</p>

    <div class="kv">
        <div class="k">DB Connection</div>
        <div class="v">
            <?php if ($dbOk): ?>
                <span class="pill small">OK</span>
            <?php else: ?>
                <span class="pill small">FAILED</span>
                <div class="muted small"><?= e($dbErr) ?></div>
            <?php endif; ?>
        </div>
        <div class="k">Tables</div>
        <div class="v">
            <?php if (!$dbOk): ?>
                <span class="muted">—</span>
            <?php else: ?>
                <?= $installed ? '<span class="pill small">Installed</span>' : '<span class="pill small">Not installed</span>' ?>
            <?php endif; ?>
        </div>
        <div class="k">Uploads</div>
        <div class="v">
            <span class="pill small"><?= is_dir(UPLOAD_DIR_SCREENSHOTS) ? 'uploads/ OK' : 'uploads/ missing' ?></span>
            <span class="pill small"><?= is_dir(UPLOAD_DIR_FILES) ? 'files/ OK' : 'files/ missing' ?></span>
        </div>
    </div>

    <?php if ($dbOk && !$installed): ?>
        <div class="divider"></div>
        <form method="post">
            <?= csrf_field() ?>
            <button class="btn primary" type="submit" onclick="return confirm('Run installer? This will create tables.');">Run Installer</button>
            <a class="btn ghost" href="index.php">Back</a>
        </form>
        <p class="muted small">This executes <code>database.sql</code> on your database (phpMyAdmin import also works).</p>
    <?php elseif ($dbOk && $installed): ?>
        <div class="divider"></div>
        <div class="row">
            <a class="btn primary" href="login.php">Login</a>
            <a class="btn ghost" href="index.php">Open Store</a>
            <a class="btn ghost" href="admin.php">Admin Panel</a>
        </div>
        <div class="glass inner-card">
            <h3>Default Admin (from database.sql)</h3>
            <div class="kv">
                <div class="k">Email</div><div class="v mono">admin@zentraxx.local</div>
                <div class="k">Password</div><div class="v mono">Admin@12345</div>
            </div>
            <p class="muted small">Change the password by updating this user in the DB or create a new admin user and set <code>is_admin=1</code>.</p>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>

