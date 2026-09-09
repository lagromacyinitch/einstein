<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
require_once __DIR__ . '/config.php';
setSecurityHeaders();

function sendResetEmail(string $to, string $otp): bool {
    $subj = 'Einstein Center — Password Reset Code';
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;background:#FAF4EC;border-radius:10px">
      <h2 style="color:#5E3A21">Password Reset</h2>
      <p>Use this code to reset your password. Expires in <strong>10 minutes</strong>.</p>
      <div style="font-size:36px;font-weight:700;letter-spacing:8px;color:#5E3A21;text-align:center;padding:20px;background:#fff;border-radius:8px;border:2px solid #CAA171;margin:20px 0">'.$otp.'</div>
      <p style="color:#888;font-size:13px">If you did not request this, ignore this email.</p>
    </div>';
    $sock = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
    if (!$sock) return false;
    $rd = fn() => fgets($sock, 515);
    $sn = fn($cmd) => fwrite($sock, "$cmd\r\n");
    $rd(); $sn('EHLO localhost'); while(($l=$rd())&&substr($l,3,1)=='-');
    $sn('STARTTLS'); $rd();
    stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $sn('EHLO localhost'); while(($l=$rd())&&substr($l,3,1)=='-');
    $sn('AUTH LOGIN'); $rd();
    $sn(base64_encode(SMTP_USERNAME)); $rd();
    $sn(base64_encode(SMTP_PASSWORD)); $rd();
    $sn("MAIL FROM:<".SMTP_FROM_EMAIL.">"); $rd();
    $sn("RCPT TO:<$to>"); $rd();
    $sn('DATA'); $rd();
    $msg  = "From: Einstein Center <".SMTP_FROM_EMAIL.">\r\nTo: <$to>\r\nSubject: $subj\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n.\r\n";
    $sn($msg); $r = $rd(); $sn('QUIT'); fclose($sock);
    return str_starts_with($r, '250');
}

try {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $post   = array_merge($_POST, $input);
    $action = trim($post['action'] ?? '');
    $email  = strtolower(trim($post['email'] ?? ''));
    if (!in_array($action, ['request_reset','reset_password'], true)) throw new Exception('Invalid action.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address.');
    $db = getDB();

    if ($action === 'request_reset') {
        rateLimit('pwd_reset_' . md5($email), 3, 600);
        $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND is_verified=1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE email=? AND is_used=0")->execute([$email]);
            $otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
            $exp = date('Y-m-d H:i:s', time()+600);
            $db->prepare("INSERT INTO otp_codes (email,otp_code,expires_at) VALUES (?,?,?)")->execute([$email,$otp,$exp]);
            sendResetEmail($email, $otp);
        }
        ob_clean();
        echo json_encode(['success'=>true,'message'=>"If $email is registered, a reset code has been sent."]);
        exit;
    }

    if ($action === 'reset_password') {
        rateLimit('pwd_confirm_' . md5($email), 5, 600);
        $otp = trim($post['otp'] ?? '');
        $pw  = trim($post['new_password'] ?? '');
        if (!$otp) throw new Exception('Please enter the reset code.');
        if (strlen($pw) < 8) throw new Exception('Password must be at least 8 characters.');
        $stmt = $db->prepare("SELECT id,otp_code,expires_at,attempts FROM otp_codes WHERE email=? AND is_used=0 ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row) throw new Exception('No active reset code. Please request a new one.');
        if (new DateTime() > new DateTime($row['expires_at'])) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
            throw new Exception('Code expired. Please request a new one.');
        }
        if ((int)$row['attempts'] >= 3) {
            $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
            throw new Exception('Too many attempts. Request a new code.');
        }
        if ($row['otp_code'] !== $otp) {
            $db->prepare("UPDATE otp_codes SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
            throw new Exception('Incorrect code. ' . (3-(int)$row['attempts']-1) . ' attempt(s) left.');
        }
        $db->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash($pw,PASSWORD_BCRYPT),$email]);
        $db->prepare("UPDATE otp_codes SET is_used=1 WHERE id=?")->execute([$row['id']]);
        ob_clean();
        echo json_encode(['success'=>true,'message'=>'Password updated. You can now log in.']);
        exit;
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
