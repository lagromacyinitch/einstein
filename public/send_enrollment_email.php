<?php
/**
 * send_enrollment_email.php  –  background mailer (CLI only)
 * Called by save_enrollment.php via popen() so SMTP never blocks the web request.
 * Usage: php send_enrollment_email.php <email> <guardian> <child> <ref> <program>
 */
require_once __DIR__ . '/config.php';

$email    = $argv[1] ?? '';
$guardian = $argv[2] ?? 'Guardian';
$child    = $argv[3] ?? 'Student';
$ref      = $argv[4] ?? 'N/A';
$program  = $argv[5] ?? 'Program';

if (empty($email)) { echo "[BG Mail] No email — skip\n"; exit(0); }

$subject = "Enrollment Submitted - $ref";
$html = "<html><head></head><body style='font-family:Arial,sans-serif'>
<div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden'>
  <div style='background:#5E3A21;padding:28px 32px'><h1 style='color:#E1C39A;margin:0'>EINSTEIN-Center For Modern Education</h1><p style='color:rgba(255,255,255,.6);margin:4px 0 0;font-size:12px'>Tutorial • Workshop • Childcare</p></div>
  <div style='padding:32px'>
    <h2 style='color:#5E3A21'>Thank you, $guardian!</h2>
    <p style='color:#4A2E1C;font-size:14px'>Your enrollment application for <strong>$child</strong> has been received and is now under review.</p>
    <p style='color:#4A2E1C;font-size:14px'>Your reference number is:</p>
    <div style='background:#f5ede0;border-left:4px solid #CAA171;padding:14px 20px;margin:20px 0;font-size:22px;font-weight:700;color:#5E3A21'>$ref</div>
    <table style='width:100%;border-collapse:collapse;font-size:13px;margin:16px 0'>
      <tr><th style='background:#5E3A21;color:#E1C39A;padding:8px 12px;text-align:left' colspan='2'>Enrollment Details</th></tr>
      <tr><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'>Program</td><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'><strong>$program</strong></td></tr>
      <tr><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'>Student</td><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'>$child</td></tr>
      <tr><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'>Guardian</td><td style='padding:8px 12px;border-bottom:1px solid #f0e8da'>$guardian</td></tr>
      <tr><td style='padding:8px 12px'>Status</td><td style='padding:8px 12px'>Pending Confirmation</td></tr>
    </table>
    <p style='color:#4A2E1C;font-size:14px'>Contact us: <strong>0919-004-4939</strong> | facebook.com/einsteincenter.ph</p>
  </div>
  <div style='background:#f5ede0;padding:16px 32px;text-align:center;font-size:11px;color:#8D6A4E'>2F ARDC Building, CPG North Avenue, Tagbilaran, Bohol</div>
</div></body></html>";

try {
    // Load SMTP credentials from DB (falls back to hardcoded constants automatically)
    $creds = getSmtpCredentials();
    $smtpUsername  = $creds['username'];
    $smtpPassword  = $creds['password'];
    $smtpFromEmail = $creds['fromEmail'];
    $smtpFromName  = $creds['fromName'];

    $smtp = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
    if (!$smtp) { echo "[BG Mail] Connect failed: $errstr\n"; exit(1); }
    stream_set_timeout($smtp, 15);

    $r = fgets($smtp, 1024);
    if (strpos($r, '220') !== 0) { fclose($smtp); echo "[BG Mail] Bad greeting\n"; exit(1); }

    fputs($smtp, "EHLO localhost\r\n");
    while (($r = fgets($smtp, 1024)) && substr($r,3,1)==='-');
    fputs($smtp, "STARTTLS\r\n"); $r = fgets($smtp, 1024);
    if (strpos($r, '220') !== 0) { fclose($smtp); echo "[BG Mail] STARTTLS fail\n"; exit(1); }

    stream_context_set_option($smtp,'ssl','verify_peer',false);
    stream_context_set_option($smtp,'ssl','verify_peer_name',false);
    stream_context_set_option($smtp,'ssl','allow_self_signed',true);
    if (!stream_socket_enable_crypto($smtp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($smtp); echo "[BG Mail] TLS fail\n"; exit(1);
    }
    stream_set_blocking($smtp,false); fgets($smtp,512); stream_set_blocking($smtp,true);
    fputs($smtp,"EHLO localhost\r\n");
    while(($r=fgets($smtp,1024))&&substr($r,3,1)==='-');
    fputs($smtp,"AUTH PLAIN\r\n"); fgets($smtp,1024);
    fputs($smtp, base64_encode("\0".$smtpUsername."\0".$smtpPassword)."\r\n");
    $r=fgets($smtp,1024);
    if (strpos($r,'235')!==0) { fclose($smtp); echo "[BG Mail] Auth fail\n"; exit(1); }
    fputs($smtp,"MAIL FROM: <".$smtpFromEmail.">\r\n"); fgets($smtp,1024);
    fputs($smtp,"RCPT TO: <$email>\r\n"); fgets($smtp,1024);
    fputs($smtp,"DATA\r\n"); fgets($smtp,1024);
    $msg ="From: ".$smtpFromName." <".$smtpFromEmail.">\r\n";
    $msg.="To: $email\r\nSubject: $subject\r\n";
    $msg.="MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $msg.=$html."\r\n.";
    fputs($smtp,$msg); fgets($smtp,1024);
    fputs($smtp,"QUIT\r\n"); fclose($smtp);
    echo "[BG Mail] Sent to $email ($ref)\n";
} catch (Throwable $e) {
    echo "[BG Mail] Error: ".$e->getMessage()."\n"; exit(1);
}
