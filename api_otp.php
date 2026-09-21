<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
setSecurityHeaders();

// sendEmail() is defined in config.php

// ── OTP EMAIL TEMPLATE ────────────────────────────────────────────
function otpEmailHtml(string $otp): string {
    return <<<HTML
    <html><head><style>
      body{font-family:'Segoe UI',Arial,sans-serif;background:#f5ede0;margin:0;padding:20px}
      .wrap{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
      .hdr{background:#5E3A21;padding:28px 32px;text-align:center}
      .hdr h1{color:#E1C39A;font-size:22px;margin:0;letter-spacing:.04em}
      .hdr p{color:rgba(255,255,255,.6);font-size:12px;margin:4px 0 0}
      .body{padding:32px}
      .body p{color:#4A2E1C;font-size:14px;line-height:1.7;margin:0 0 16px}
      .code{background:#f5ede0;border:2px dashed #CAA171;border-radius:8px;padding:24px;text-align:center;margin:24px 0;font-size:40px;font-weight:700;letter-spacing:.5em;color:#5E3A21;font-family:monospace}
      .note{font-size:12px!important;color:#8D6A4E!important}
      .ftr{background:#f5ede0;padding:16px 32px;text-align:center;font-size:11px;color:#8D6A4E}
    </style></head>
    <body><div class="wrap">
      <div class="hdr"><h1>EINSTEIN-Center For Modern Education</h1><p>Enrollment Verification</p></div>
      <div class="body">
        <p>Thank you for enrolling at <strong>EINSTEIN-Center For Modern Education</strong>.</p>
        <p>Your one-time verification code is:</p>
        <div class="code">$otp</div>
        <p class="note">This code expires in <strong>10 minutes</strong>. Do not share this code with anyone. If you did not request this, please disregard this email.</p>
      </div>
      <div class="ftr">© EINSTEIN-Center For Modern Education &nbsp;·&nbsp; Tagbilaran, Bohol</div>
    </div></body></html>
    HTML;
}

// ── MAIN HANDLER ──────────────────────────────────────────────────
$db = null;
try {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $post   = array_merge($_POST, $input);
    $action = trim($post['action'] ?? '');

    if (!in_array($action, ['send_otp', 'verify_otp', 'resend_otp'], true))
        throw new Exception('Invalid action.');

    $email = strtolower(trim($post['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new Exception('Please enter a valid email address.');

    $db = getDB();

    // ── SEND / RESEND ─────────────────────────────────────────────
    if (in_array($action, ['send_otp', 'resend_otp'], true)) {

        rateLimit('otp_send_' . md5($email), 5, 600);

        // Invalidate any existing unused OTPs for this email
        $db->prepare("UPDATE otp_codes SET is_used=1 WHERE email=? AND is_used=0")
           ->execute([$email]);

        // Generate new 6-digit OTP
        $otp       = str_pad((string) random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY);

        // Insert — try with channel column, fall back if column doesn't exist yet
        try {
            $db->prepare("INSERT INTO otp_codes (email, otp_code, expires_at, channel) VALUES (?,?,?,?)")
               ->execute([$email, $otp, $expiresAt, 'email']);
        } catch (\PDOException $e) {
            $db->prepare("INSERT INTO otp_codes (email, otp_code, expires_at) VALUES (?,?,?)")
               ->execute([$email, $otp, $expiresAt]);
        }
        $otpId = (int) $db->lastInsertId();

        // Send email. Never expose the OTP in a response or log it in plaintext.
        $sent = sendEmail($email, 'EINSTEIN-Center For Modern Education — Your Verification Code', otpEmailHtml($otp));
        if (!$sent) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")
                ->execute([$otpId]);
            throw new Exception('We could not send the verification email. Please try again shortly.');
        }

        ob_clean();
        echo json_encode([
            'success'  => true,
            'message'  => "Verification code sent to $email. Check your inbox (and spam folder).",
        ]);
        exit;
    }

    // ── VERIFY ────────────────────────────────────────────────────
    if ($action === 'verify_otp') {
        $enteredOtp = trim($post['otp'] ?? '');
        if (!ctype_digit($enteredOtp) || strlen($enteredOtp) !== OTP_LENGTH) {
            throw new Exception('Please enter the verification code.');
        }

        rateLimit('otp_verify_' . md5($email), MAX_OTP_ATTEMPTS + 2, OTP_EXPIRY);
        $db->beginTransaction();

        $stmt = $db->prepare(
            "SELECT id, otp_code, expires_at, attempts
             FROM otp_codes
             WHERE email = ? AND is_used = 0
             ORDER BY created_at DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            throw new Exception('No active verification code found. Please request a new one.');
        }

        if (new DateTime() > new DateTime($row['expires_at'])) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
            $db->commit();
            throw new Exception('Code expired. Please request a new one.');
        }

        if ($row['attempts'] >= MAX_OTP_ATTEMPTS) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
            $db->commit();
            throw new Exception('Too many failed attempts. Please request a new code.');
        }

        if (!hash_equals((string) $row['otp_code'], $enteredOtp)) {
            $db->prepare("UPDATE otp_codes SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
            $left = MAX_OTP_ATTEMPTS - $row['attempts'] - 1;
            $db->commit();
            throw new Exception("Incorrect code. $left attempt(s) remaining.");
        }

        $emailHash = hashLookup($email);
        $userStmt = $db->prepare(
            "SELECT id FROM users WHERE email_hash = ? OR email = ? LIMIT 1 FOR UPDATE"
        );
        $userStmt->execute([$emailHash, $email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
            $db->commit();
            throw new Exception('No account was found for this verification. Please create an account first.');
        }

        // A valid OTP is the only path that verifies an account.
        $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
        $db->prepare("UPDATE users SET is_verified=1 WHERE id=?")->execute([$user['id']]);
        $db->commit();

        startUserSession();
        session_regenerate_id(true);
        $_SESSION['role'] = 'user';
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['email'] = $email;
        unset($_SESSION['portal_username']);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully.',
            'email'   => $email,
            'user_id' => (int) $user['id'],
        ]);
        exit;
    }

} catch (Exception $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    ob_clean();
    http_response_code(200); // keep 200 so fetch() resolves in JS
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
