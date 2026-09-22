<?php
// ─────────────────────────────────────────────────────────────────
//  Einstein Center — config.php  (MySQL / Hostinger-compatible edition)
// ─────────────────────────────────────────────────────────────────

function loadEnv(): array
{
    static $loaded = false;
    static $env = [];
    if ($loaded) {
        return $env;
    }
    $loaded = true;
    $path = __DIR__ . '/.env';
    if (!file_exists($path) || !is_readable($path)) {
        return $env;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    return $env;
}

$env = loadEnv();

// ── DATABASE (MySQL / MariaDB) ───────────────────────────────────
// Every secret below comes from includes/.env (written by the deploy workflow);
// this file itself is committed to git.
// Keep these values in .env on Hostinger. Hostinger Web/Cloud plans use
// localhost for PHP-to-database connections and port 3306.
$localDbHost = '127.0.0.1';
define('DB_DRIVER', strtolower((string)($env['DB_DRIVER'] ?? 'mysql')));
define('DB_HOST', (string)($env['DB_HOST'] ?? $localDbHost));
define('DB_PORT', (int)($env['DB_PORT'] ?? 3306));
define('DB_NAME', (string)($env['DB_NAME'] ?? 'einstein_center'));
define('DB_USER', (string)($env['DB_USER'] ?? 'root'));
define('DB_PASS', (string)($env['DB_PASS'] ?? ''));
define('DB_CHARSET', (string)($env['DB_CHARSET'] ?? 'utf8mb4'));
define(
    'DB_AUTO_BOOTSTRAP',
    filter_var(
        $env['DB_AUTO_BOOTSTRAP'] ?? (in_array(DB_HOST, [$localDbHost, 'localhost'], true) ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    )
);

// ── SMTP (Gmail) ──────────────────────────────────────────────────
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', (string)($env['SMTP_USERNAME'] ?? ''));
define('SMTP_PASSWORD', (string)($env['SMTP_PASSWORD'] ?? ''));
define('SMTP_FROM_EMAIL', (string)($env['SMTP_FROM_EMAIL'] ?? SMTP_USERNAME));
define('SMTP_FROM_NAME', 'EINSTEIN- Center For Modern Education');

// ── HEAD ADMIN ────────────────────────────────────────────────────
// The Head Admin lives in admin_accounts (role 'head_admin'). These values
// only create that row when none exists yet; see includes/admin_accounts.php.
define('HEAD_ADMIN_SEED_USER', (string)($env['HEAD_ADMIN_USER'] ?? ''));
define('HEAD_ADMIN_SEED_PASS', (string)($env['HEAD_ADMIN_PASS'] ?? ''));
define('ADMIN_SESSION_NAME', 'EINSTEIN_ADMIN_SESSID');

function startPortalSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === ADMIN_SESSION_NAME) {
            return;
        }
        session_write_close();
    }
    session_name(ADMIN_SESSION_NAME);
    session_start();
}

function startUserSession(): void
{
    $defaultName = ini_get('session.name');
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === $defaultName) {
            return;
        }
        session_write_close();
    }
    session_name($defaultName);
    session_start();
}

function destroySessionByName(string $name): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== $name) {
            session_write_close();
            session_name($name);
            session_start();
        }
    } else {
        session_name($name);
        session_start();
    }

    $_SESSION = [];
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
}

// ── OTP SETTINGS ──────────────────────────────────────────────────
define('OTP_EXPIRY', 10 * 60);  // 600 seconds = 10 minutes
define('OTP_LENGTH', 6);
define('MAX_OTP_ATTEMPTS', 3);

// ── UPLOAD SETTINGS ───────────────────────────────────────────────
define('UPLOAD_DIR', EINSTEIN_ROOT . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);  // 5 MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ── DATABASE CONNECTION (PDO → MySQL) ─────────────────────────────


// ── LOCAL FILE UPLOAD (used by XAMPP and Hostinger) ───────────────
function uploadToLocal(string $tmpPath, string $filename): string
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $dest = UPLOAD_DIR . $filename;
    if (!move_uploaded_file($tmpPath, $dest)) {
        throw new Exception('File upload failed.');
    }
    return 'uploads/' . $filename;
}

// ── STUB: kept so any code calling uploadToSupabase() won't crash ──
// Remove this once you're fully on Supabase later.
function uploadToSupabase(string $tmpPath, string $filename): string
{
    return uploadToLocal($tmpPath, $filename);
}

// ── STUB: kept so any code calling getSignedUrl() won't crash ─────
// Remove this once you're fully on Supabase later.
function getSignedUrl(string $storagePath, int $expiresIn = 3600): string
{
    return '/' . $storagePath;
}

// ── EMAIL HELPER ─────────────────────────────────────────────────────
/**
 * Load SMTP credentials from the database (smtp_config in system_settings).
 * Falls back to config constants if no DB record exists.
 * Returns ['username', 'password', 'from_email', 'from_name']
 */
function getSmtpCredentials(): array
{
    $username  = SMTP_USERNAME;
    $password  = SMTP_PASSWORD;
    $fromEmail = SMTP_FROM_EMAIL;
    $fromName  = SMTP_FROM_NAME;

    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_config' LIMIT 1");
        if ($stmt) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $sc = json_decode($row['setting_value'], true);
                if (!empty($sc['email']))     { $username = $sc['email']; $fromEmail = $sc['email']; }
                if (!empty($sc['password']))  { $password = decryptAES256($sc['password']); }
                if (!empty($sc['from_name'])) { $fromName = $sc['from_name']; }
            }
        }
    } catch (\Throwable $e) {
        // DB not available yet — silently fall back to constants
        error_log('[SMTP] Could not load credentials from DB: ' . $e->getMessage());
    }

    return compact('username', 'password', 'fromEmail', 'fromName');
}

function sendEmail(string $toEmail, string $subject, string $htmlBody): bool
{
    $creds = getSmtpCredentials();
    $smtpUsername  = $creds['username'];
    $smtpPassword  = $creds['password'];
    $smtpFromEmail = $creds['fromEmail'];
    $smtpFromName  = $creds['fromName'];

    try {
        // Connect to Gmail SMTP
        $smtp = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
        if (!$smtp) {
            error_log('[OTP Mail] Cannot connect to smtp.gmail.com:587 - ' . $errstr);
            return false;
        }

        $read = function () use ($smtp) {
            return fgets($smtp, 1024);
        };
        $write = function ($cmd) use ($smtp) {
            return fputs($smtp, $cmd . "\r\n");
        };

        // Read initial response
        $resp = $read();
        if (strpos($resp, '220') === false) {
            error_log('[OTP Mail] SMTP not ready: ' . trim($resp));
            fclose($smtp);
            return false;
        }

        // Send EHLO
        $write('EHLO localhost');
        while (($resp = $read()) && substr($resp, 3, 1) === '-') {
        }

        // Start TLS
        $write('STARTTLS');
        $resp = $read();
        if (strpos($resp, '220') === false) {
            error_log('[OTP Mail] STARTTLS failed: ' . trim($resp));
            fclose($smtp);
            return false;
        }

        // Enable crypto
        stream_context_set_option($smtp, 'ssl', 'verify_peer', false);
        stream_context_set_option($smtp, 'ssl', 'verify_peer_name', false);
        if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('[OTP Mail] TLS crypto enable failed');
            fclose($smtp);
            return false;
        }

        // Re-send EHLO after TLS
        $write('EHLO localhost');
        while (($resp = $read()) && substr($resp, 3, 1) === '-') {
        }

        // Auth LOGIN
        $write('AUTH LOGIN');
        $resp = $read();

        // Send username (base64 encoded)
        $write(base64_encode($smtpUsername));
        $resp = $read();

        // Send password (base64 encoded)
        $write(base64_encode($smtpPassword));
        $resp = $read();
        if (strpos($resp, '235') === false) {
            error_log('[OTP Mail] Auth failed: ' . trim($resp));
            fclose($smtp);
            return false;
        }

        // Send mail
        $write('MAIL FROM:<' . $smtpFromEmail . '>');
        $resp = $read();

        $write('RCPT TO:<' . $toEmail . '>');
        $resp = $read();

        $write('DATA');
        $resp = $read();

        // Compose message
        $msg = "From: " . $smtpFromName . " <" . $smtpFromEmail . ">\r\n";
        $msg .= "To: " . $toEmail . "\r\n";
        $msg .= "Subject: " . $subject . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $msg .= $htmlBody . "\r\n.";

        $write($msg);
        $resp = $read();

        $write('QUIT');
        fclose($smtp);

        if (strpos($resp, '250') === 0) {
            error_log('[OTP Mail] Email sent successfully to ' . $toEmail);
            return true;
        } else {
            error_log('[OTP Mail] Mail submission failed: ' . trim($resp));
            return false;
        }
    } catch (Exception $e) {
        error_log('[OTP Mail] Exception: ' . $e->getMessage());
        return false;
    }
}

// ── SECURITY HELPERS ─────────────────────────────────────────────────────
// ── DATA SECURITY / AES-256 CRYPTOGRAPHY ENGINE ─────────────────────────────
// Computer Science Cryptographic Standard: AES-256-CBC with random IV & HMAC lookup
// Every encrypted column depends on this key: a different value makes existing
// data unreadable, so refuse to run without it rather than fall back.
if (empty($env['AES_SECRET_KEY'])) {
    error_log('[config] AES_SECRET_KEY is missing from includes/.env');
    http_response_code(500);
    exit('Server configuration is incomplete.');
}
define('AES_SECRET_KEY', (string)$env['AES_SECRET_KEY']);
define('AES_CIPHER_METHOD', 'aes-256-cbc');
define('AES_PREFIX', 'enc:v1:');

/**
 * Encrypts plaintext data using AES-256-CBC with a secure random 16-byte IV.
 * Stored Payload Format: enc:v1:<Base64(IV + Ciphertext)>
 */
function encryptAES256(?string $plaintext): ?string
{
    if ($plaintext === null || $plaintext === '') {
        return $plaintext;
    }
    if (str_starts_with($plaintext, AES_PREFIX)) {
        return $plaintext;
    }
    $key = hash('sha256', AES_SECRET_KEY, true); // 32-byte 256-bit key
    $ivLength = openssl_cipher_iv_length(AES_CIPHER_METHOD);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($plaintext, AES_CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new Exception('AES-256 Encryption failed.');
    }
    return AES_PREFIX . base64_encode($iv . $encrypted);
}

/**
 * Decrypts AES-256-CBC ciphertext stored in the database.
 * If data is not encrypted or null, returns it unchanged.
 */
function decryptAES256(?string $ciphertext): ?string
{
    if ($ciphertext === null || $ciphertext === '') {
        return $ciphertext;
    }
    if (!str_starts_with($ciphertext, AES_PREFIX)) {
        return $ciphertext;
    }
    $raw = base64_decode(substr($ciphertext, strlen(AES_PREFIX)), true);
    if ($raw === false) {
        return $ciphertext;
    }
    $key = hash('sha256', AES_SECRET_KEY, true);
    $ivLength = openssl_cipher_iv_length(AES_CIPHER_METHOD);
    if (strlen($raw) <= $ivLength) {
        return $ciphertext;
    }
    $iv = substr($raw, 0, $ivLength);
    $cipherData = substr($raw, $ivLength);
    $decrypted = openssl_decrypt($cipherData, AES_CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    return ($decrypted !== false) ? $decrypted : $ciphertext;
}

/**
 * Computes a deterministic HMAC-SHA256 blind index hash for encrypted searchable fields
 * (e.g. email, username) to allow indexed SQL lookup without decrypting the DB.
 */
function hashLookup(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return hash_hmac('sha256', strtolower(trim($value)), AES_SECRET_KEY);
}

/**
 * Helper to decrypt designated array keys in a database record for API front-end responses.
 */
function decryptRow(array $row, array $fields = []): array
{
    if (empty($fields)) {
        $fields = ['id', 'username', 'password', 'password_encrypted', 'firstname', 'lastname', 'middlename', 'birthdate', 'email', 'email_encrypted', 'address', 'contact', 'contact_number', 'phone', 'child_name', 'guardian_name', 'child_school', 'facebook_name'];
    }
    foreach ($fields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = decryptAES256($row[$field]);
        }
    }
    return $row;
}

function setSecurityHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}


function rateLimit(string $action, int $maxHits = 5, int $windowSec = 300): void
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $key = "_rl_$action";
    $now = time();
    $hits = array_values(array_filter($_SESSION[$key] ?? [], fn($t) => ($now - $t) < $windowSec));
    if (count($hits) >= $maxHits)
        throw new Exception('Too many attempts. Please wait a few minutes and try again.');
    $hits[] = $now;
    $_SESSION[$key] = $hits;
}

// ── DATABASE CONNECTION ───────────────────────────────────────────────────
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;
    $usePostgres = DB_DRIVER === 'pgsql';
    if ($usePostgres) {
        $schema = defined('DB_SCHEMA') ? DB_SCHEMA : 'public';
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=--search_path=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            $schema
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        if (DB_AUTO_BOOTSTRAP) {
            // Local development may create the database and bootstrap missing tables.
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `" . DB_NAME . "`");
            bootstrapMySQL($pdo);
        } else {
            // Hostinger production databases already exist and shared-hosting users
            // normally cannot create databases from the application.
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        }
    }
    return $pdo;
}

function bootstrapMySQL(PDO $pdo): void
{
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        email_encrypted TEXT DEFAULT NULL,
        email_hash VARCHAR(64) DEFAULT NULL,
        username TEXT DEFAULT NULL,
        username_hash VARCHAR(64) DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        password_encrypted TEXT DEFAULT NULL,
        firstname TEXT DEFAULT NULL,
        lastname TEXT DEFAULT NULL,
        middlename TEXT DEFAULT NULL,
        birthdate TEXT DEFAULT NULL,
        address TEXT DEFAULT NULL,
        contact_number TEXT DEFAULT NULL,
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_u_email_hash (email_hash),
        INDEX idx_u_username_hash (username_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS otp_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        attempts INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_otp_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS password_reset_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email_hash VARCHAR(64) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        attempts INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_reset_user (user_id, is_used),
        INDEX idx_password_reset_lookup (email_hash, is_used)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference_no VARCHAR(30) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        program VARCHAR(100) NOT NULL,
        package_selected VARCHAR(200) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        timeslot VARCHAR(100) DEFAULT NULL,
        child_name TEXT NOT NULL,
        child_age TEXT DEFAULT NULL,
        child_grade TEXT DEFAULT NULL,
        child_school TEXT DEFAULT NULL,
        guardian_name TEXT NOT NULL,
        address TEXT DEFAULT NULL,
        contact TEXT DEFAULT NULL,
        facebook_name TEXT DEFAULT NULL,
        payment_method VARCHAR(64) DEFAULT 'qr',
        payment_screenshot VARCHAR(500) DEFAULT NULL,
        payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
        admin_notes TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_enroll_user (user_id),
        INDEX idx_enroll_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS tutors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT DEFAULT NULL,
        phone TEXT DEFAULT NULL,
        hourly_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        subjects VARCHAR(500) DEFAULT NULL,
        schedule VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tutor_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS tutor_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT NOT NULL,
        tutor_id INT NOT NULL,
        assigned_by VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ta_enrollment (enrollment_id),
        INDEX idx_ta_tutor (tutor_id),
        FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS program_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_name VARCHAR(100) NOT NULL,
        package_name VARCHAR(150) NOT NULL,
        care_duration VARCHAR(100) DEFAULT NULL,
        rate VARCHAR(100) NOT NULL,
        capacity_slots VARCHAR(100) DEFAULT NULL,
        package_type VARCHAR(50) DEFAULT 'general',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pp_program (program_name),
        INDEX idx_pp_type (package_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS admin_activity_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        actor_name   VARCHAR(120) NOT NULL,
        actor_role   VARCHAR(30)  NOT NULL,
        action_type  VARCHAR(80)  NOT NULL,
        description  TEXT         NOT NULL,
        ip_address   VARCHAR(45)  DEFAULT NULL,
        logged_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_aal_logged (logged_at),
        INDEX idx_aal_actor  (actor_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Dynamic Schema Alterations for existing database upgrade
    $alterQueries = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_encrypted TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_hash VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS username TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS username_hash VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS password_encrypted TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS firstname TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS lastname TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS middlename TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS birthdate TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number TEXT DEFAULT NULL",
        "ALTER TABLE password_reset_codes ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL",
        "ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS admin_notes TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN child_name TEXT NOT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN guardian_name TEXT NOT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN child_age TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN child_grade TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN child_school TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN address TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN contact TEXT DEFAULT NULL",
        "ALTER TABLE enrollments MODIFY COLUMN facebook_name TEXT DEFAULT NULL"
    ];

    foreach ($alterQueries as $q) {
        try {
            $pdo->exec($q);
        } catch (Exception $e) {
            // Ignore if column/index already present
        }
    }
}

?>
