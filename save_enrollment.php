<?php
ini_set('display_errors', 0);
// ─────────────────────────────────────────────
//  Einstein Center — Save Enrollment Endpoint
//  POST (multipart/form-data): enrollment fields + payment_screenshot file
// ─────────────────────────────────────────────
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();

function generateRef(): string {
    return 'ECL-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)) . '-' . date('y');
}

function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function digitsOnly(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalizeName(string $value, string $fieldLabel): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        throw new Exception($fieldLabel . ' is required.');
    }
    if (!preg_match('/^[A-Za-z\s]+$/', $value)) {
        throw new Exception($fieldLabel . ' may only contain letters and spaces.');
    }
    return ucwords(strtolower($value));
}

function normalizeFacebookName(string $value): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        throw new Exception('Facebook name is required.');
    }
    if (!preg_match('/^[A-Za-z\s]+$/', $value)) {
        throw new Exception('Facebook name may only contain letters and spaces.');
    }
    return ucwords(strtolower($value));
}

try {
    startUserSession();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Your session has expired. Please log in again to submit your enrollment.');
    }

    // ── Required fields ──────────────────────────────
    $isVip = stripos($_POST['program'] ?? '', 'vip') !== false;
    $required = $isVip ? ['email','program','guardian_name'] : ['email','program','child_name','guardian_name'];
    foreach ($required as $f) {
        if (empty($_POST[$f])) throw new Exception("Missing required field: $f");
    }

    $db      = getDB();
    $userId  = (int) $_SESSION['user_id'];
    $email   = strtolower(trim($_POST['email']));
    $emailHash = hashLookup($email);

    // Verify user account
    $stmt = $db->prepare("SELECT id FROM users WHERE id=? AND (email_hash=? OR email=?) AND is_verified=1");
    $stmt->execute([$userId, $emailHash, $email]);
    if (!$stmt->fetch())
        throw new Exception('User account not found or not verified. Please log in again.');

    // ── File Upload → Supabase Storage ───────────────
    $screenshotPath = null;
    if (!empty($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['payment_screenshot'];
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, ALLOWED_TYPES))
            throw new Exception('Invalid file type. Please upload a JPG, PNG, GIF, or WEBP image.');
        if ($file['size'] > MAX_FILE_SIZE)
            throw new Exception('File too large. Maximum size is 5MB.');

        $ext            = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename       = 'pmt_' . $userId . '_' . time() . '.' . strtolower($ext);
        $screenshotPath = uploadToSupabase($file['tmp_name'], $filename);
    }

    // ── Generate unique reference ─────────────────────
    do {
        $ref = generateRef();
        $check = $db->prepare("SELECT id FROM enrollments WHERE reference_no=?");
        $check->execute([$ref]);
    } while ($check->fetch());

    // ── Cryptographic Encryption (AES-256) of Sensitive Data ──
    $rawChildName     = sanitize($_POST['child_name'] ?? '');
    $rawChildAge      = sanitize($_POST['child_age'] ?? '');
    $rawChildGrade    = sanitize($_POST['child_grade'] ?? '');
    $rawChildSchool   = sanitize($_POST['child_school'] ?? '');
    $rawGuardianName  = sanitize($_POST['guardian_name'] ?? '');
    $rawAddress       = sanitize($_POST['address'] ?? '');
    $rawContact       = sanitize($_POST['contact'] ?? '');
    $rawFacebook      = sanitize($_POST['facebook_name'] ?? '');

    $rawGuardianName  = normalizeName($rawGuardianName, 'Guardian name');
    $rawContact       = digitsOnly($rawContact);
    $rawFacebook      = normalizeFacebookName($rawFacebook);

    if ($rawContact === '') {
        throw new Exception('Contact number is required and must contain numbers only.');
    }

    if ($isVip) {
        if (empty($rawChildName)) {
            $rawChildName = $rawGuardianName;
        } else {
            $rawChildName = normalizeName($rawChildName, 'Guardian name');
        }
        $rawChildAge = '0';
        $rawChildGrade = '0';
        if (empty($rawChildSchool)) {
            $rawChildSchool = 'N/A';
        }
    } else {
        $rawChildName     = normalizeName($rawChildName, 'Child name');
        $rawChildAge      = digitsOnly($rawChildAge);
        $rawChildGrade    = digitsOnly($rawChildGrade);

        if ($rawChildAge === '') {
            throw new Exception('Child age is required and must contain numbers only.');
        }
        if ($rawChildGrade === '') {
            throw new Exception('Child grade is required and must contain numbers only.');
        }
    }

    $extraNotes = [];
    if (!empty($_POST['extra_children']) && $_POST['extra_children'] !== '[]') {
        $extraNotes[] = 'Additional children: ' . $_POST['extra_children'];
    }
    if (!empty($_POST['barangay']) && !empty($_POST['purok'])) {
        $extraNotes[] = 'Home-Based Location: Brgy. ' . sanitize($_POST['barangay']) . ', Purok ' . digitsOnly($_POST['purok']);
    }
    $rawNotes = count($extraNotes) > 0 ? implode("\n", $extraNotes) : null;

    $childNameEnc    = encryptAES256($rawChildName);
    $childAgeEnc     = encryptAES256($rawChildAge);
    $childGradeEnc   = encryptAES256($rawChildGrade);
    $childSchoolEnc  = encryptAES256($rawChildSchool);
    $guardianNameEnc = encryptAES256($rawGuardianName);
    $addressEnc      = encryptAES256($rawAddress);
    $contactEnc      = encryptAES256($rawContact);
    $facebookEnc     = encryptAES256($rawFacebook);
    $notesEnc        = encryptAES256($rawNotes);

    // ── Insert enrollment ─────────────────────────────
    $sql = "INSERT INTO enrollments
        (reference_no, user_id, program, package_selected, start_date, timeslot,
         child_name, child_age, child_grade, child_school,
         guardian_name, address, contact, facebook_name, payment_screenshot, payment_method, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $db->prepare($sql)->execute([
        $ref,
        $userId,
        sanitize($_POST['program']          ?? ''),
        sanitize($_POST['package_selected'] ?? ''),
        !empty($_POST['start_date']) ? date('Y-m-d', strtotime($_POST['start_date'])) : null,
        sanitize($_POST['timeslot']         ?? ''),
        $childNameEnc,
        $childAgeEnc,
        $childGradeEnc,
        $childSchoolEnc,
        $guardianNameEnc,
        $addressEnc,
        $contactEnc,
        $facebookEnc,
        $screenshotPath,
        sanitize($_POST['payment_method']   ?? ''),
        $notesEnc,
    ]);

    $enrollmentId = (int) $db->lastInsertId();

    // ── Respond immediately, email in background ─────
    $jsonResponse = json_encode([
        'success'       => true,
        'message'       => 'Enrollment submitted successfully.',
        'reference_no'  => $ref,
        'enrollment_id' => $enrollmentId,
    ]);

    ignore_user_abort(true);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($jsonResponse));
    echo $jsonResponse;
    flush();

    // ── Send confirmation email AFTER response is sent ──
    sendConfirmationEmail($email, $_POST['guardian_name'], $_POST['child_name'], $ref, $_POST['program']);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ── CONFIRMATION EMAIL ────────────────────────────────
function sendConfirmationEmail(
    string $email, string $guardian, string $child, string $ref, string $program
): void {
    try {
        $subject = "Enrollment Submitted — $ref";
        $html = <<<HTML
        <html><head><style>
          body{font-family:'Segoe UI',Arial,sans-serif;background:#f5ede0;margin:0;padding:20px}
          .wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
          .hdr{background:#5E3A21;padding:28px 32px}
          .hdr h1{color:#E1C39A;margin:0;font-size:20px;letter-spacing:.04em}
          .hdr p{color:rgba(255,255,255,.6);margin:4px 0 0;font-size:12px}
          .body{padding:32px}
          h2{color:#5E3A21;font-size:18px;margin:0 0 16px}
          p{color:#4A2E1C;font-size:14px;line-height:1.7;margin:0 0 12px}
          .ref{background:#f5ede0;border-left:4px solid #CAA171;padding:14px 20px;margin:20px 0;font-size:22px;font-weight:700;color:#5E3A21;letter-spacing:.08em}
          table{width:100%;border-collapse:collapse;font-size:13px;margin:16px 0}
          th{background:#5E3A21;color:#E1C39A;padding:8px 12px;text-align:left}
          td{padding:8px 12px;border-bottom:1px solid #f0e8da;color:#4A2E1C}
          .ftr{background:#f5ede0;padding:16px 32px;text-align:center;font-size:11px;color:#8D6A4E}
        </style></head><body>
        <div class="wrap">
          <div class="hdr"><h1>Einstein Center</h1><p>Enrollment Confirmation</p></div>
          <div class="body">
            <h2>Thank you, $guardian!</h2>
            <p>Your enrollment application for <strong>$child</strong> has been received and is now under review. We will contact you shortly to confirm your schedule and payment.</p>
            <p>Your reference number is:</p>
            <div class="ref">$ref</div>
            <table>
              <tr><th colspan="2">Enrollment Details</th></tr>
              <tr><td>Program</td><td><strong>$program</strong></td></tr>
              <tr><td>Student</td><td>$child</td></tr>
              <tr><td>Guardian</td><td>$guardian</td></tr>
              <tr><td>Status</td><td>⏳ Pending Confirmation</td></tr>
            </table>
            <p>For inquiries, contact us at <strong>0919-004-4939</strong> or visit our Facebook page at <a href="https://facebook.com/einsteincenter.ph">facebook.com/einsteincenter.ph</a></p>
          </div>
          <div class="ftr">2F ARDC Building · CPG North Avenue · Tagbilaran, Bohol &nbsp;·&nbsp; 0919-004-4939</div>
        </div></body></html>
        HTML;

        $smtp = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 20);
        if (!$smtp) return;

        $r = fgets($smtp, 1024);
        if (strpos($r, '220') !== 0) { fclose($smtp); return; }

        fputs($smtp, "EHLO localhost\r\n");
        while (($r = fgets($smtp, 1024)) && substr($r,3,1)==='-');
        fputs($smtp, "STARTTLS\r\n"); $r = fgets($smtp, 1024);
        if (strpos($r, '220') !== 0) { fclose($smtp); return; }

        stream_context_set_option($smtp,'ssl','verify_peer',false);
        stream_context_set_option($smtp,'ssl','verify_peer_name',false);
        stream_context_set_option($smtp,'ssl','allow_self_signed',true);
        if (!stream_socket_enable_crypto($smtp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($smtp); return; }

        stream_set_blocking($smtp,false); fgets($smtp,512); stream_set_blocking($smtp,true);
        fputs($smtp,"EHLO localhost\r\n");
        while(($r=fgets($smtp,1024))&&substr($r,3,1)==='-');

        fputs($smtp,"AUTH PLAIN\r\n"); fgets($smtp,1024);
        fputs($smtp, base64_encode("\0".SMTP_USERNAME."\0".SMTP_PASSWORD)."\r\n");
        $r = fgets($smtp,1024);
        if (strpos($r,'235') !== 0) { fclose($smtp); return; }

        fputs($smtp,"MAIL FROM: <".SMTP_FROM_EMAIL.">\r\n"); fgets($smtp,1024);
        fputs($smtp,"RCPT TO: <$email>\r\n"); fgets($smtp,1024);
        fputs($smtp,"DATA\r\n"); fgets($smtp,1024);

        $msg  = "From: ".SMTP_FROM_NAME." <".SMTP_FROM_EMAIL.">\r\n";
        $msg .= "To: $email\r\nSubject: $subject\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
        $msg .= $html."\r\n.";
        fputs($smtp,$msg); fgets($smtp,1024);
        fputs($smtp,"QUIT\r\n");
        fclose($smtp);
    } catch (Exception $e) {
        error_log('[Confirm Mail] '.$e->getMessage());
    }
}
?>