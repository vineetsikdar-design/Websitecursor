<?php
declare(strict_types=1);

// ==========================
// ZENTRAXX STORE - config.php
// Edit DB credentials below.
// ==========================

// ---- Database credentials (edit these) ----
$DB_HOST = 'localhost';
$DB_NAME = 'your_database_name';
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';
$DB_CHARSET = 'utf8mb4';

// ---- Site settings (edit these) ----
define('SITE_NAME', 'ZENTRAXX STORE');
define('UPI_VPA', 'yourupi@bank');     // e.g. name@okhdfcbank
define('UPI_PAYEE', 'ZENTRAXX STORE'); // payee name shown to user
define('SUPPORT_EMAIL', 'support@example.com');

// If set (recommended), cron.php will require ?token=... to run.
define('CRON_TOKEN', ''); // set to a long random string after install

// Uploads folder for UPI screenshots (must be writable by PHP).
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL_PATH', 'uploads'); // relative to this directory

// Digital files folder (put your downloadable files here; block direct access via .htaccess)
define('FILES_DIR', __DIR__ . '/files');

// Set to true temporarily if you need to debug on hosting.
define('DEBUG', false);

if (DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// ---- Session hardening ----
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ---- PDO connection ----
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Database connection failed</h1>";
    if (DEBUG) {
        echo "<pre>" . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . "</pre>";
    } else {
        echo "<p>Please check your <code>config.php</code> DB credentials.</p>";
    }
    exit;
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}

// ---- Helpers ----
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function site_url(string $path = ''): string
{
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $base = ($base === '/' ? '' : $base);
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void
{
    $t = $_POST['csrf_token'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
        http_response_code(400);
        echo "Invalid CSRF token.";
        exit;
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare("SELECT id,email,wallet_balance,referral_code,referred_by,is_admin FROM users WHERE id=?");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash_set('error', 'Please login first.');
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
    return $u;
}

function installed(): bool
{
    try {
        $st = db()->query("SELECT `value` FROM settings WHERE `key`='installed' LIMIT 1");
        $row = $st->fetch();
        return ($row && (string)$row['value'] === '1');
    } catch (Throwable) {
        return false;
    }
}

function money_fmt($n): string
{
    return number_format((float)$n, 2, '.', '');
}

function validate_utr(string $utr): bool
{
    $utr = trim($utr);
    if (strlen($utr) < 12 || strlen($utr) > 22) return false;
    return (bool)preg_match('/^[A-Za-z0-9]{12,22}$/', $utr);
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function handle_screenshot_upload(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Screenshot too large (max 3MB).');
    }

    $tmp = $file['tmp_name'] ?? '';
    $info = @getimagesize($tmp);
    if (!$info) {
        throw new RuntimeException('Invalid image.');
    }
    $mime = $info['mime'] ?? '';
    $ext = null;
    if ($mime === 'image/jpeg') $ext = 'jpg';
    if ($mime === 'image/png') $ext = 'png';
    if (!$ext) {
        throw new RuntimeException('Only JPG/PNG allowed.');
    }

    $sha = hash_file('sha256', $tmp);
    ensure_dir(UPLOAD_DIR);
    $name = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $dest = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save upload.');
    }

    return [
        'sha256' => $sha,
        'rel_path' => UPLOAD_URL_PATH . '/' . $name,
    ];
}

function page_header(string $title): void
{
    $f = flash_get();
    $u = current_user();
    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css?v=1">';
    echo '</head><body>';
    echo '<div class="topbar"><div class="wrap">';
    echo '<div class="brand"><a href="index.php">' . e(SITE_NAME) . '</a></div>';
    echo '<div class="nav">';
    if ($u) {
        echo '<span class="chip">Wallet ₹' . e(money_fmt($u['wallet_balance'])) . '</span>';
        echo '<a class="btn btn-ghost" href="index.php#my-orders">My Orders</a>';
        if (!empty($u['is_admin'])) echo '<a class="btn btn-ghost" href="admin.php">Admin</a>';
        echo '<a class="btn btn-ghost" href="index.php?action=logout">Logout</a>';
    } else {
        echo '<a class="btn btn-ghost" href="login.php">Login</a>';
        echo '<a class="btn" href="register.php">Register</a>';
    }
    echo '</div></div></div>';
    echo '<main class="wrap">';
    if ($f) {
        $cls = $f['type'] === 'success' ? 'notice success' : ($f['type'] === 'error' ? 'notice error' : 'notice');
        echo '<div class="' . e($cls) . '">' . e($f['msg']) . '</div>';
    }
}

function page_footer(): void
{
    echo '<footer class="footer wrap">';
    echo '<div class="muted">© ' . date('Y') . ' ' . e(SITE_NAME) . ' · Support: <a href="mailto:' . e(SUPPORT_EMAIL) . '">' . e(SUPPORT_EMAIL) . '</a></div>';
    echo '</footer>';
    echo '</main></body></html>';
}

