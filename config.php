<?php
declare(strict_types=1);

// =========================
// ZENTRAXX STORE - CONFIG
// =========================

// 1) Edit these DB settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'zentraxx_store');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// 2) Site settings
define('SITE_NAME', 'ZENTRAXX STORE');
define('CURRENCY', '₹');

// 3) Manual UPI payment instructions (edit as needed)
define('UPI_PAYEE_NAME', 'ZENTRAXX STORE');
define('UPI_ID', 'yourupi@bank');
define('UPI_NOTE', 'Order payment for ZENTRAXX STORE');

// 4) Optional: protect cron with a token
// If non-empty, you must call cron as: cron.php?token=YOUR_TOKEN
define('CRON_TOKEN', ''); // example: 'change_this_to_a_long_random_string'

// 5) Upload limits (server must allow these sizes too)
define('MAX_SCREENSHOT_BYTES', 2 * 1024 * 1024); // 2MB
define('MAX_PRODUCT_FILE_BYTES', 50 * 1024 * 1024); // 50MB

// 6) Upload directories (will be auto-created)
define('UPLOAD_DIR_SCREENSHOTS', __DIR__ . '/uploads');
define('UPLOAD_DIR_FILES', __DIR__ . '/files');

// -------------------------
// Bootstrapping
// -------------------------

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
}

// Basic security headers (safe on shared hosting)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function ensure_dirs(): void {
    $dirs = [UPLOAD_DIR_SCREENSHOTS, UPLOAD_DIR_FILES];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir)) {
            @file_put_contents(rtrim($dir, '/') . '/index.html', '');
        }
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($amount): string {
    return CURRENCY . number_format((float)$amount, 2);
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

function flash_set(string $key, string $message): void {
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string {
    if (!isset($_SESSION['_flash'][$key])) return null;
    $m = (string)$_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $m;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

function is_post(): bool {
    return (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $uid = (int)$_SESSION['uid'];
    try {
        $st = db()->prepare('SELECT id, email, wallet, is_admin, referral_code FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        return $u ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        flash_set('error', 'Please login first.');
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $u;
}

function logout(): void {
    unset($_SESSION['uid']);
    session_regenerate_id(true);
}

function starts_with(string $haystack, string $prefix): bool {
    return substr($haystack, 0, strlen($prefix)) === $prefix;
}

function render_header(string $title = ''): void {
    $u = current_user();
    $msgOk = flash_get('ok');
    $msgErr = flash_get('error');

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e(($title ? $title . ' · ' : '') . SITE_NAME) ?></title>
        <link rel="stylesheet" href="assets/style.css?v=1">
    </head>
    <body>
    <div class="bg-glow"></div>
    <header class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="index.php"><?= e(SITE_NAME) ?></a>
            <nav class="nav">
                <a href="index.php">Store</a>
                <?php if ($u): ?>
                    <a href="checkout.php?my=1">My Orders</a>
                    <?php if (!empty($u['is_admin'])): ?>
                        <a href="admin.php">Admin</a>
                    <?php endif; ?>
                    <a class="pill" href="index.php?logout=1">Logout</a>
                <?php else: ?>
                    <a href="login.php">Login</a>
                    <a class="pill" href="register.php">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($msgOk): ?>
            <div class="alert ok"><?= e($msgOk) ?></div>
        <?php endif; ?>
        <?php if ($msgErr): ?>
            <div class="alert err"><?= e($msgErr) ?></div>
        <?php endif; ?>
    <?php
}

function render_footer(): void {
    ?>
    </main>
    <footer class="footer">
        <div class="container footer-inner">
            <div class="muted">© <?= date('Y') ?> <?= e(SITE_NAME) ?></div>
            <div class="muted">Dark neon digital store · shared-hosting ready</div>
        </div>
    </footer>
    </body>
    </html>
    <?php
}

