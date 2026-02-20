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
define('UPI_VPA', '');                // default fallback (admin can set in panel)
define('UPI_PAYEE', 'ZENTRAXX STORE'); // default fallback (admin can set in panel)
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

function now_dt(): string
{
    return date('Y-m-d H:i:s');
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

// ---- Settings (DB) ----
$__settings_cache = null;

function settings_load(): void
{
    global $__settings_cache;
    if (is_array($__settings_cache)) return;
    $__settings_cache = [];
    try {
        $st = db()->query("SELECT `key`,`value` FROM settings");
        foreach ($st->fetchAll() as $r) {
            $__settings_cache[(string)$r['key']] = (string)($r['value'] ?? '');
        }
    } catch (Throwable) {
        $__settings_cache = [];
    }
}

function setting_get(string $key, string $default = ''): string
{
    settings_load();
    global $__settings_cache;
    if (isset($__settings_cache[$key])) return (string)$__settings_cache[$key];
    return $default;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting_get($key, $default ? '1' : '0');
    return $v === '1' || strtolower($v) === 'true' || strtolower($v) === 'yes' || strtolower($v) === 'on';
}

function setting_set(string $key, string $value): void
{
    settings_load();
    global $__settings_cache;
    try {
        $st = db()->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        $st->execute([$key, $value]);
        $__settings_cache[$key] = $value;
    } catch (Throwable) {
        // ignore
    }
}

function wallet_enabled(): bool
{
    return setting_bool('wallet_enabled', false);
}

function wallet_mode(): string
{
    $m = strtolower(trim(setting_get('wallet_mode', 'partial')));
    if ($m !== 'partial' && $m !== 'wallet_only') $m = 'partial';
    return $m;
}

function upi_vpa(): string
{
    $v = trim(setting_get('upi_vpa', ''));
    if ($v !== '') return $v;
    return (string)UPI_VPA;
}

function upi_payee(): string
{
    $v = trim(setting_get('upi_payee', ''));
    if ($v !== '') return $v;
    return (string)UPI_PAYEE;
}

function binance_id(): string
{
    return trim(setting_get('binance_id', ''));
}

function maintenance_mode(): bool
{
    return setting_bool('maintenance_mode', false);
}

function stop_signup(): bool
{
    return setting_bool('stop_signup', false);
}

function stop_login(): bool
{
    return setting_bool('stop_login', false);
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare("SELECT id,email,username,display_name,avatar_url,wallet_balance,referral_code,referred_by,is_admin,is_banned,orders_last_seen_at,welcome_seen_at FROM users WHERE id=?");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u) return null;
    if (!empty($u['is_banned'])) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    return $u;
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
    return setting_get('installed', '0') === '1';
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

function handle_image_upload(array $file, string $subdir = '', int $maxBytes = 5242880): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Image too large.');
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
    $base = rtrim(UPLOAD_DIR, '/\\');
    $subdir = trim($subdir, "/\\");
    $targetDir = $base . ($subdir !== '' ? (DIRECTORY_SEPARATOR . $subdir) : '');
    ensure_dir($targetDir);
    $name = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $dest = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save upload.');
    }

    return [
        'sha256' => $sha,
        'rel_path' => UPLOAD_URL_PATH . ($subdir !== '' ? ('/' . $subdir) : '') . '/' . $name,
    ];
}

function validate_reference_id(string $method, string $ref): bool
{
    $ref = trim($ref);
    if ($method === 'upi') {
        return validate_utr($ref);
    }
    // binance: allow longer TXID/reference
    if (strlen($ref) < 8 || strlen($ref) > 64) return false;
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,64}$/', $ref);
}

function cart_get(): array
{
    $c = $_SESSION['cart'] ?? [];
    return is_array($c) ? $c : [];
}

function cart_count(): int
{
    $c = cart_get();
    $n = 0;
    foreach ($c as $it) {
        $q = (int)($it['qty'] ?? 0);
        if ($q > 0) $n += $q;
    }
    return $n;
}

function unseen_orders_count(int $uid, ?string $lastSeen): int
{
    if (!$lastSeen) $lastSeen = '1970-01-01 00:00:00';
    try {
        $st = db()->prepare("SELECT COUNT(*) c FROM orders WHERE user_id=? AND updated_at > ?");
        $st->execute([$uid, $lastSeen]);
        return (int)($st->fetch()['c'] ?? 0);
    } catch (Throwable) {
        return 0;
    }
}

function telegram_send(string $text): bool
{
    $token = trim(setting_get('telegram_bot_token', ''));
    $chatId = trim(setting_get('telegram_chat_id', ''));
    if ($token === '' || $chatId === '') return false;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = ['chat_id' => $chatId, 'text' => $text];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $ok = ($res !== false);
        curl_close($ch);
        return $ok;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($post),
            'timeout' => 10,
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false;
}

function smtp_send_mail(string $toEmail, string $subject, string $htmlBody): bool
{
    $enabled = setting_bool('smtp_enabled', false);
    if (!$enabled) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        return @mail($toEmail, $subject, $htmlBody, $headers);
    }

    $host = trim(setting_get('smtp_host', ''));
    $port = (int)setting_get('smtp_port', '587');
    $user = trim(setting_get('smtp_user', ''));
    $pass = (string)setting_get('smtp_pass', '');
    $fromEmail = trim(setting_get('smtp_from_email', ''));
    $fromName = trim(setting_get('smtp_from_name', SITE_NAME));
    if ($host === '' || $port <= 0 || $user === '' || $pass === '' || $fromEmail === '') return false;

    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$socket) return false;
    stream_set_timeout($socket, 10);

    $read = function() use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\d{3}\s/', $line)) break;
        }
        return $data;
    };
    $write = function(string $cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };
    $expect = function(string $resp, array $codes) {
        $code = (int)substr($resp, 0, 3);
        return in_array($code, $codes, true);
    };

    $r = $read();
    $write("EHLO localhost");
    $r = $read();
    if (!$expect($r, [250])) { fclose($socket); return false; }

    // STARTTLS
    $write("STARTTLS");
    $r = $read();
    if (!$expect($r, [220])) { fclose($socket); return false; }
    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); return false; }
    $write("EHLO localhost");
    $r = $read();
    if (!$expect($r, [250])) { fclose($socket); return false; }

    $write("AUTH LOGIN");
    $r = $read();
    if (!$expect($r, [334])) { fclose($socket); return false; }
    $write(base64_encode($user));
    $r = $read();
    if (!$expect($r, [334])) { fclose($socket); return false; }
    $write(base64_encode($pass));
    $r = $read();
    if (!$expect($r, [235])) { fclose($socket); return false; }

    $write("MAIL FROM:<{$fromEmail}>");
    $r = $read();
    if (!$expect($r, [250])) { fclose($socket); return false; }
    $write("RCPT TO:<{$toEmail}>");
    $r = $read();
    if (!$expect($r, [250, 251])) { fclose($socket); return false; }
    $write("DATA");
    $r = $read();
    if (!$expect($r, [354])) { fclose($socket); return false; }

    $fromHeader = 'From: ' . ($fromName !== '' ? ('"' . addslashes($fromName) . '" ') : '') . "<{$fromEmail}>";
    $headers = [];
    $headers[] = $fromHeader;
    $headers[] = "To: <{$toEmail}>";
    $headers[] = "Subject: " . $subject;
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Date: " . date('r');

    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody;
    $msg = str_replace(["\r\n.\r\n", "\n.\n"], ["\r\n..\r\n", "\n..\n"], $msg);
    $write($msg . "\r\n.");
    $r = $read();
    $ok = $expect($r, [250]);
    $write("QUIT");
    fclose($socket);
    return $ok;
}

function page_header(string $title): void
{
    $f = flash_get();
    $u = current_user();
    $annEnabled = setting_bool('announcement_enabled', false);
    $annText = setting_get('announcement_text', '');
    $offerEnabled = setting_bool('offer_enabled', false);
    $offerTitle = setting_get('offer_title', 'Daily Offer');
    $offerText = setting_get('offer_text', '');
    $offerImg = setting_get('offer_image_url', '');

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css?v=1">';
    echo '</head><body>';
    if ($annEnabled && trim($annText) !== '') {
        echo '<div class="announce"><div class="wrap"><div class="announce-text">' . e($annText) . '</div></div></div>';
    }
    echo '<div class="topbar"><div class="wrap">';
    echo '<div class="brand"><a href="index.php">' . e(SITE_NAME) . '</a></div>';
    echo '<div class="nav">';
    if ($u) {
        $unseen = unseen_orders_count((int)$u['id'], $u['orders_last_seen_at'] ?? null);
        echo '<span class="chip">Wallet ₹' . e(money_fmt($u['wallet_balance'])) . '</span>';
        if (wallet_enabled()) {
            echo '<a class="btn btn-ghost" href="checkout.php?action=wallet">Wallet</a>';
        }
        echo '<a class="btn btn-ghost" href="index.php?page=orders">My Orders' . ($unseen > 0 ? ' <span class="dot" title="New updates"></span>' : '') . '</a>';
        $cc = cart_count();
        echo '<a class="btn btn-ghost" href="checkout.php?cart=1">Cart' . ($cc > 0 ? ' <span class="count">' . (int)$cc . '</span>' : '') . '</a>';
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

    // Daily offer popup (24h once per device)
    if ($offerEnabled && (trim($offerTitle) !== '' || trim($offerText) !== '' || trim($offerImg) !== '')) {
        $payload = json_encode(['t' => $offerTitle, 'x' => $offerText, 'i' => $offerImg]);
        echo '<script>
          (function(){
            try{
              var k="zx_offer_seen_at";
              var last=parseInt(localStorage.getItem(k)||"0",10);
              var now=Date.now();
              if(now-last < 24*60*60*1000) return;
              var p=' . $payload . ';
              var m=document.createElement("div"); m.className="modal"; m.innerHTML=
                \'<div class="modal-backdrop"></div>\'+
                \'<div class="modal-card glass">\'+
                  \'<div class="modal-head"><div class="modal-title">\'+(p.t||"Offer")+\'</div><button class="xbtn" aria-label="Close">×</button></div>\'+
                  (p.i?\'<img class="modal-img" src="\'+p.i+\'" alt="offer">\':\'\')+
                  (p.x?\'<div class="muted">\'+(p.x+"").replace(/</g,"&lt;")+\'</div>\':\'\')+
                  \'<div class="modal-actions"><button class="btn btn-ghost close">Close</button></div>\'+
                \'</div>\';
              document.body.appendChild(m);
              function close(){ try{localStorage.setItem(k,String(now));}catch(e){} m.remove(); }
              m.querySelector(".modal-backdrop").addEventListener("click",close);
              m.querySelector(".xbtn").addEventListener("click",close);
              m.querySelector(".close").addEventListener("click",close);
            }catch(e){}
          })();
        </script>';
    }
}

function page_footer(): void
{
    echo '<footer class="footer wrap">';
    echo '<div class="muted">© ' . date('Y') . ' ' . e(SITE_NAME) . ' · Support: <a href="mailto:' . e(SUPPORT_EMAIL) . '">' . e(SUPPORT_EMAIL) . '</a></div>';
    echo '</footer>';
    echo '</main></body></html>';
}

