<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();

function resetPasswordEmailHtml(string $otp): string
{
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5ede0;font-family:Arial,sans-serif">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<div style="padding:26px 32px;background:#5e3a21;text-align:center;color:#e1c39a">'
        . '<strong style="font-size:22px;letter-spacing:.04em">EINSTEIN-Center For Modern Education</strong>'
        . '<div style="margin-top:5px;font-size:12px;color:#ffffff99">Password Reset</div></div>'
        . '<div style="padding:30px 32px;color:#4a2e1c;font-size:14px;line-height:1.65">'
        . '<p style="margin-top:0">We received a request to reset your password.</p>'
        . '<p>Enter this six-digit code on the EINSTEIN-Center For Modern Education sign-in page:</p>'
        . '<div style="margin:24px 0;padding:20px;background:#f5ede0;border:2px dashed #caa171;border-radius:8px;text-align:center;color:#5e3a21;font-family:monospace;font-size:34px;font-weight:700;letter-spacing:.35em">'
        . $safeOtp . '</div>'
        . '<p style="margin-bottom:0;color:#8d6a4e;font-size:12px">This code expires in 10 minutes and can only be used once. If you did not request a password reset, you can safely ignore this email.</p>'
        . '</div></div></body></html>';
}

function passwordResetResponse(array $payload): void
{
    ob_clean();
    echo json_encode($payload);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
        throw new Exception('Method not allowed.');
    }

    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
    $post = array_merge($_POST, $input);
    $action = trim((string) ($post['action'] ?? ''));
    $email = strtolower(trim((string) ($post['email'] ?? '')));

    if (!in_array($action, ['request_reset', 'reset_password'], true)) {
        throw new Exception('Invalid action.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }

    $db = getDB();
    $emailHash = hashLookup($email);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Check users table
    $accountQuery = $db->prepare(
        'SELECT id, "user" as acct_type FROM users WHERE (email_hash = ? OR email = ?) AND is_verified = 1 LIMIT 1'
    );
    $accountQuery->execute([$emailHash, $email]);
    $account = $accountQuery->fetch();

    // If not in users table, check admin_accounts table
    if (!$account) {
        $adminQuery = $db->prepare(
            'SELECT id, "admin" as acct_type FROM admin_accounts WHERE (LOWER(username) = ? OR LOWER(COALESCE(email, username)) = ?) AND is_active = 1 LIMIT 1'
        );
        $adminQuery->execute([$email, $email]);
        $account = $adminQuery->fetch();
    }

    if ($action === 'request_reset') {
        rateLimit('password_reset_request_' . hash('sha256', $ip . ':' . $emailHash), 3, 600);

        if ($account) {
            // head_admin and sub-admin both store NULL for user_id (keyed by email_hash)
            $userId = ($account['acct_type'] === 'user') ? (int) $account['id'] : null;
            $otp = str_pad((string) random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY);

            // Keep any prior usable code valid unless the replacement email is sent successfully.
            $db->beginTransaction();
            $insert = $db->prepare(
                'INSERT INTO password_reset_codes (user_id, email_hash, otp_code, expires_at) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$userId, $emailHash, $otp, $expiresAt]);
            $resetCodeId = (int) $db->lastInsertId();

            if (!sendEmail($email, 'EINSTEIN-Center For Modern Education - Password Reset Code', resetPasswordEmailHtml($otp))) {
                $db->rollBack();
                passwordResetResponse([
                    'success' => false,
                    'message' => 'We could not send reset instructions right now. Please try again shortly.'
                ]);
            }

            $invalidatePrior = $db->prepare(
                'UPDATE password_reset_codes SET is_used = 1 '
                . 'WHERE id <> ? AND is_used = 0 AND (user_id = ? OR (user_id IS NULL AND email_hash = ?))'
            );
            $invalidatePrior->execute([$resetCodeId, $userId, $emailHash]);
            $db->commit();
        }

        passwordResetResponse([
            'success' => true,
            'message' => 'If an eligible account uses this address, a reset code has been sent.'
        ]);
    }

    rateLimit('password_reset_confirm_' . hash('sha256', $ip . ':' . $emailHash), 5, 600);

    $otp = trim((string) ($post['otp'] ?? ''));
    $newPassword = trim((string) ($post['new_password'] ?? ''));
    $confirmation = trim((string) ($post['confirm_password'] ?? ''));

    if (!ctype_digit($otp) || strlen($otp) !== OTP_LENGTH) {
        throw new Exception('Enter the six-digit reset code.');
    }
    if (strlen($newPassword) < 8) {
        throw new Exception('Password must be at least 8 characters.');
    }
    if ($newPassword !== $confirmation) {
        throw new Exception('The new passwords do not match.');
    }
    if (!$account) {
        throw new Exception('No active reset code was found. Please request a new one.');
    }

    $userId = ($account['acct_type'] === 'user') ? (int) $account['id'] : null;
    $db->beginTransaction();
    $codeQuery = $db->prepare(
        'SELECT id, otp_code, expires_at, attempts FROM password_reset_codes '
        . 'WHERE is_used = 0 AND email_hash = ? '
        . 'ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
    );
    $codeQuery->execute([$emailHash]);
    $code = $codeQuery->fetch();

    if (!$code) {
        $db->rollBack();
        throw new Exception('No active reset code was found. Please request a new one.');
    }
    if (new DateTime() > new DateTime($code['expires_at'])) {
        $db->prepare('UPDATE password_reset_codes SET is_used = 1 WHERE id = ?')->execute([$code['id']]);
        $db->commit();
        throw new Exception('This reset code has expired. Please request a new one.');
    }
    if ((int) $code['attempts'] >= MAX_OTP_ATTEMPTS) {
        $db->prepare('UPDATE password_reset_codes SET is_used = 1 WHERE id = ?')->execute([$code['id']]);
        $db->commit();
        throw new Exception('Too many incorrect attempts. Please request a new code.');
    }
    if (!hash_equals((string) $code['otp_code'], $otp)) {
        $attemptsLeft = MAX_OTP_ATTEMPTS - ((int) $code['attempts'] + 1);
        $db->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE id = ?')
            ->execute([$code['id']]);
        $db->commit();
        throw new Exception('Incorrect reset code. ' . $attemptsLeft . ' attempt(s) left.');
    }

    if ($account['acct_type'] === 'user') {
        $updateAccount = $db->prepare(
            'UPDATE users SET password_hash = ?, password_encrypted = NULL WHERE id = ?'
        );
        $updateAccount->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$account['id']]);
    } else {
        $updateAccount = $db->prepare(
            'UPDATE admin_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?'
        );
        $updateAccount->execute([password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), (int)$account['id']]);
    }
    $db->prepare('UPDATE password_reset_codes SET is_used = 1 WHERE id = ?')->execute([$code['id']]);
    $db->commit();

    passwordResetResponse([
        'success' => true,
        'message' => 'Password updated. You can now sign in.'
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    passwordResetResponse(['success' => false, 'message' => $e->getMessage()]);
}
