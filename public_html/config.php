<?php

// -------------------------------------------------
// Basic app + database configuration
// Edit these values for your hosting database.
// -------------------------------------------------
$DB_HOST = 'localhost';
$DB_NAME = 'zentraxx_store';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Please edit config.php with correct credentials.');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function set_flash($message, $type = 'info')
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function get_flash()
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_or_die()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Invalid CSRF token. Please refresh and try again.');
    }
}

function login_user($userId)
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $userId;
}

function logout_user()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user($pdo)
{
    static $cache = null;
    static $cacheUserId = null;

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return null;
    }

    if ($cache !== null && $cacheUserId === $userId) {
        return $cache;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, wallet_balance, referral_code, is_admin, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $cache = $user;
    $cacheUserId = $userId;
    return $user;
}

function require_login($pdo)
{
    if (!current_user($pdo)) {
        set_flash('Please login to continue.', 'error');
        redirect('login.php');
    }
}

function require_admin($pdo)
{
    $user = current_user($pdo);
    if (!$user || (int) $user['is_admin'] !== 1) {
        set_flash('Admin access required.', 'error');
        redirect('index.php');
    }
}

function get_settings($pdo)
{
    $defaults = [
        'site_name' => 'ZENTRAXX STORE',
        'upi_id' => 'demo@upi',
        'upi_name' => 'Zentraxx Store',
        'payment_instructions' => "1) Pay exact UPI amount\n2) Submit valid UTR\n3) Upload payment screenshot",
    ];

    $stmt = $pdo->query('SELECT site_name, upi_id, upi_name, payment_instructions FROM settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();

    if (!$row) {
        return $defaults;
    }

    return array_merge($defaults, $row);
}

function upsert_settings($pdo, $siteName, $upiId, $upiName, $instructions)
{
    $sql = 'INSERT INTO settings (id, site_name, upi_id, upi_name, payment_instructions)
            VALUES (1, :site_name, :upi_id, :upi_name, :payment_instructions)
            ON DUPLICATE KEY UPDATE
            site_name = VALUES(site_name),
            upi_id = VALUES(upi_id),
            upi_name = VALUES(upi_name),
            payment_instructions = VALUES(payment_instructions)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':site_name' => trim($siteName),
        ':upi_id' => trim($upiId),
        ':upi_name' => trim($upiName),
        ':payment_instructions' => trim($instructions),
    ]);
}

function generate_referral_code($pdo)
{
    for ($i = 0; $i < 8; $i++) {
        $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
}

function money($value)
{
    return number_format((float) $value, 2, '.', '');
}

function status_badge_class($status)
{
    $map = [
        'pending' => 'badge-warning',
        'submitted' => 'badge-info',
        'completed' => 'badge-success',
        'cancelled' => 'badge-danger',
    ];
    return $map[$status] ?? 'badge-info';
}

function is_valid_utr($utr)
{
    return (bool) preg_match('/^\d{12,22}$/', (string) $utr);
}

function ensure_uploads_dir()
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}
