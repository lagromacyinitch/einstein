<?php
/**
 * api_settings.php — System Settings, Payment QR Codes, and SMTP Config API
 *
 * GET                      → Fetch current center settings & payment QRs
 * GET ?action=get_smtp     → Fetch SMTP credentials (Head Admin only, password masked)
 * POST action=save         → Save settings / QR codes (Admin only)
 * POST action=save_smtp    → Save SMTP credentials (Head Admin only)
 * POST action=test_smtp    → Send test email using current SMTP settings (Head Admin only)
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
startPortalSession();

try {
    $db = getDB();

    // ── Auto-create system_settings table ─────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $method = $_SERVER['REQUEST_METHOD'];
    $getAction = $_GET['action'] ?? '';

    // Default settings
    $defaultSettings = [
        'prices' => ['reg' => 3300, 'doub' => 5500, 'daily' => 5500, 'ddoub' => 9900, 'vip' => 500],
        'vipDiscounts' => ['playschool' => 20, 'tutorial' => 5, 'workshop' => 5, 'childcare' => 15],
        'qrs' => [
            ['name' => 'GCash', 'url' => 'images/gcash.jpg'],
            ['name' => 'BPI', 'url' => 'images/bpi.jpg'],
            ['name' => 'SeaBank', 'url' => 'images/seabank.jpg']
        ],
        'info' => [
            'address' => '2nd Floor of ARDC Building, CPG North Avenue, Tagbilaran',
            'phone' => '0919-004-4939',
            'fb' => 'www.facebook.com/einsteincenter.ph'
        ],
        'hiring' => false,
        'staff' => []
    ];

    // ── GET: Fetch SMTP credentials (Head Admin only) ─────────────────────
    if ($method === 'GET' && $getAction === 'get_smtp') {
        $role = $_SESSION['role'] ?? '';
        $isHeadAdmin = !empty($_SESSION['is_head_admin']);
        if ($role !== 'admin' && $role !== 'staff') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access required.']);
            exit;
        }
        if (!$isHeadAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Head Admin access required.']);
            exit;
        }

        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_config' LIMIT 1");
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
        $smtp = ['from_name' => '', 'email' => '', 'password' => false];

        if ($row) {
            $stored = json_decode($row['setting_value'], true);
            if (is_array($stored)) {
                $smtp['from_name'] = $stored['from_name'] ?? '';
                $smtp['email'] = $stored['email'] ?? '';
                // Decrypt password for Head Admin
                $decrypted = isset($stored['password']) ? decryptAES256($stored['password']) : '';
                $smtp['password'] = $decrypted ?: (defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '');
            }
        } else {
            // Fall back to config.php hardcoded defaults
            $smtp['from_name'] = SMTP_FROM_NAME;
            $smtp['email'] = SMTP_FROM_EMAIL;
            $smtp['password'] = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        }

        echo json_encode(['success' => true, 'smtp' => $smtp]);
        exit;
    }

    // ── GET: Fetch center settings & QRs ─────────────────────────────────
    if ($method === 'GET') {
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        $all = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $all[$row['setting_key']] = json_decode($row['setting_value'], true);
        }

        $merged = array_merge($defaultSettings, $all['einstein_settings'] ?? []);
        if (isset($all['einstein_info']) && is_array($all['einstein_info'])) {
            $merged['info'] = array_merge($merged['info'] ?? [], $all['einstein_info']);
        }
        if (isset($all['payment_qrs']) && is_array($all['payment_qrs'])) {
            $merged['qrs'] = $all['payment_qrs'];
        }
        if (empty($merged['qrs']) || !is_array($merged['qrs'])) {
            $merged['qrs'] = $defaultSettings['qrs'];
        }

        echo json_encode(['success' => true, 'settings' => $merged]);
        exit;
    }

    // POST requires admin or staff
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'admin' && $role !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    $isHeadAdmin = !empty($_SESSION['is_head_admin']);
    $subAdminId = (int)($_SESSION['sub_admin_id'] ?? 0);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? 'save');
    $actorName = $_SESSION['display_name'] ?? 'Head Admin';
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0];

    // ── POST action=save_smtp ─────────────────────────────────────────────
    if ($action === 'save_smtp') {
        if (!$isHeadAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Head Admin access required.']);
            exit;
        }

        $smtpData = $body['smtp'] ?? [];
        $fromName = trim($smtpData['from_name'] ?? '');
        $email = trim($smtpData['email'] ?? '');
        $password = $smtpData['password'] ?? ''; // empty string = keep existing

        if (empty($email)) throw new Exception('Gmail address is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address format.');

        // Load existing stored smtp config (to keep password if not changing)
        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_config' LIMIT 1");
        $existingRow = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $existing = $existingRow ? (json_decode($existingRow['setting_value'], true) ?? []) : [];

        $toStore = [
            'from_name' => $fromName ?: 'EINSTEIN- Center For Modern Education',
            'email' => $email,
            'password' => $existing['password'] ?? '' // default: keep existing
        ];

        // Only update password if a new one was provided
        if (!empty(trim($password))) {
            $toStore['password'] = encryptAES256(trim($password));
        }

        if (empty($toStore['password'])) {
            throw new Exception('App Password is required. Please provide the Gmail App Password.');
        }

        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('smtp_config', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([json_encode($toStore)]);

        // Log
        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, 'Head Admin', 'UPDATE_SMTP', 'Updated SMTP email sender settings', ?)");
        $logStmt->execute([$actorName, $ip]);

        echo json_encode(['success' => true, 'message' => 'Email sender settings saved successfully.']);
        exit;
    }

    // ── POST action=test_smtp ─────────────────────────────────────────────
    if ($action === 'test_smtp') {
        if (!$isHeadAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Head Admin access required.']);
            exit;
        }

        // Load SMTP config from DB (or fallback to config.php constants)
        $smtpEmail = SMTP_FROM_EMAIL;
        $smtpPass = SMTP_PASSWORD;
        $smtpName = SMTP_FROM_NAME;

        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_config' LIMIT 1");
        $smtpRow = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($smtpRow) {
            $sc = json_decode($smtpRow['setting_value'], true);
            if (!empty($sc['email'])) $smtpEmail = $sc['email'];
            if (!empty($sc['password'])) $smtpPass = decryptAES256($sc['password']);
            if (!empty($sc['from_name'])) $smtpName = $sc['from_name'];
        }

        if (empty($smtpEmail) || empty($smtpPass)) {
            throw new Exception('SMTP credentials are not configured. Please save the email settings first.');
        }

        // Send test email to the configured sender address itself
        $toEmail = $smtpEmail;
        $subject = 'Einstein Center — SMTP Test Email';
        $html = '<html><body style="font-family:Arial,sans-serif;background:#f5ede0;padding:20px">'
              . '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 4px 16px rgba(0,0,0,.07)">'
              . '<h2 style="color:#5E3A21;margin-top:0">✅ SMTP Test Successful</h2>'
              . '<p style="color:#4A2E1C;font-size:14px">This is a test email sent from the <strong>Einstein Center Admin Panel</strong>.</p>'
              . '<p style="color:#4A2E1C;font-size:14px">Your email sender settings are correctly configured:</p>'
              . '<ul style="color:#5E3A21;font-size:13px"><li>From: ' . htmlspecialchars($smtpName) . '</li><li>Email: ' . htmlspecialchars($smtpEmail) . '</li></ul>'
              . '<p style="color:#8D6A4E;font-size:12px">If you received this, OTP codes and enrollment notifications will be delivered successfully.</p>'
              . '</div></body></html>';

        // Use the stored credentials to send (temporarily override constants if possible)
        // Since we can't redefine constants, we call sendEmailWithCredentials() helper inline
        $sent = sendEmailWithCredentials($toEmail, $subject, $html, $smtpEmail, $smtpPass, $smtpName);
        if (!$sent) throw new Exception('Failed to send test email. Please check your Gmail address and App Password.');

        echo json_encode(['success' => true, 'message' => "Test email sent to $smtpEmail."]);
        exit;
    }

    // ── POST action=save (existing settings save) ─────────────────────────
    if ($action === 'save') {
        if (!$isHeadAdmin && $subAdminId) {
            $canMod = (int)$db->query("SELECT can_modify_records FROM admin_accounts WHERE id = $subAdminId")->fetchColumn();
            if (!$canMod) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied.']);
                exit;
            }
        }

        $settings = $body['settings'] ?? [];
        if (!is_array($settings)) throw new Exception('Invalid settings payload.');

        // Merge with existing einstein_settings so no sub-keys are accidentally wiped
        $stmtExisting = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'einstein_settings'");
        $existingJson = $stmtExisting ? $stmtExisting->fetchColumn() : false;
        $existingSettings = $existingJson ? json_decode($existingJson, true) : [];
        if (is_array($existingSettings) && !empty($existingSettings)) {
            $settings = array_merge($existingSettings, $settings);
            if (isset($body['settings']['info']) && is_array($body['settings']['info'])) {
                $settings['info'] = array_merge($existingSettings['info'] ?? [], $body['settings']['info']);
            }
        }

        // Extract and separate QRs if present
        $qrs = $settings['qrs'] ?? ($body['qrs'] ?? null);

        // Save main settings
        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('einstein_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([json_encode($settings)]);

        // Also save einstein_info as an explicit row in system_settings for phpMyAdmin visibility
        if (isset($settings['info']) && is_array($settings['info'])) {
            $stmtInfo = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('einstein_info', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmtInfo->execute([json_encode($settings['info'])]);
        }

        // Save QRs separately if provided
        if (is_array($qrs)) {
            $stmtQr = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('payment_qrs', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmtQr->execute([json_encode($qrs)]);
        }

        // Log
        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, 'Head Admin', 'UPDATE_SETTINGS', 'Updated system settings and center info', ?)");
        $logStmt->execute([$actorName, $ip]);

        echo json_encode(['success' => true, 'message' => 'Settings saved to database.']);
        exit;

    } else {
        throw new Exception('Unknown action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Send an email using provided credentials instead of constants.
 * This is a copy of sendEmail() in config.php but with dynamic credentials.
 */
function sendEmailWithCredentials(string $toEmail, string $subject, string $htmlBody,
                                   string $smtpUsername, string $smtpPassword, string $smtpFromName): bool {
    try {
        $smtp = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
        if (!$smtp) {
            error_log('[SMTP Test] Cannot connect: ' . $errstr);
            return false;
        }

        $read  = fn() => fgets($smtp, 1024);
        $write = fn($cmd) => fputs($smtp, $cmd . "\r\n");

        $resp = $read();
        if (strpos($resp, '220') === false) { fclose($smtp); return false; }

        $write('EHLO localhost');
        while (($resp = $read()) && substr($resp, 3, 1) === '-') {}

        $write('STARTTLS');
        $resp = $read();
        if (strpos($resp, '220') === false) { fclose($smtp); return false; }

        stream_context_set_option($smtp, 'ssl', 'verify_peer', false);
        stream_context_set_option($smtp, 'ssl', 'verify_peer_name', false);
        if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($smtp); return false;
        }

        $write('EHLO localhost');
        while (($resp = $read()) && substr($resp, 3, 1) === '-') {}

        $write('AUTH LOGIN');
        $resp = $read();
        $write(base64_encode($smtpUsername));
        $resp = $read();
        $write(base64_encode($smtpPassword));
        $resp = $read();
        if (strpos($resp, '235') === false) {
            error_log('[SMTP Test] Auth failed: ' . trim($resp));
            fclose($smtp); return false;
        }

        $write('MAIL FROM:<' . $smtpUsername . '>');
        $read();
        $write('RCPT TO:<' . $toEmail . '>');
        $read();
        $write('DATA');
        $read();

        $msg  = "From: {$smtpFromName} <{$smtpUsername}>\r\n";
        $msg .= "To: {$toEmail}\r\n";
        $msg .= "Subject: {$subject}\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
        $msg .= $htmlBody . "\r\n.";

        $write($msg);
        $resp = $read();
        $write('QUIT');
        fclose($smtp);

        return strpos($resp, '250') === 0;
    } catch (Throwable $e) {
        error_log('[SMTP Test] Exception: ' . $e->getMessage());
        return false;
    }
}
