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

require_once __DIR__ . '/../includes/bootstrap.php';
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
        throw new Exception('Facebook name / link is required.');
    }
    // Allow letters, digits, spaces, and common URL/FB characters: . / - _ : @ # ? & = % + ~
    if (!preg_match('/^[A-Za-z0-9\s\.\/:@\-_#?&=%+~]+$/', $value)) {
        throw new Exception('Facebook name / link contains invalid characters.');
    }
    return $value;
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
    if ($isVip) {
        $vipRate=$db->query("SELECT rate FROM program_packages WHERE program_name='VIP Club Membership' AND package_name='VIP Club Membership' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $vipFee=$vipRate===false ? 500 : (float)preg_replace('/[^0-9.]/','',$vipRate);
        $_POST['package_selected']='VIP Club Membership – ₱'.number_format($vipFee,2).' (2 years)';
    }
    if ($isVip) {
        require_once __DIR__ . '/../includes/vip_membership.php';
        if (vipState($db, $userId)['active']) throw new Exception('Your VIP membership is still active. Renewal is available after expiry.');
    }
    $email   = strtolower(trim($_POST['email']));
    $emailHash = hashLookup($email);

    // Verify user account
    $stmt = $db->prepare("SELECT id FROM users WHERE id=? AND (email_hash=? OR email=?) AND is_verified=1");
    $stmt->execute([$userId, $emailHash, $email]);
    if (!$stmt->fetch())
        throw new Exception('User account not found or not verified. Please log in again.');

    // Release session lock early to prevent blocking subsequent requests
    session_write_close();

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
        $filename       = 'pmt_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
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
    if ($isVip && $rawChildAge !== '' && (!preg_match('/^\d+$/', $rawChildAge) || (int)$rawChildAge < 1 || (int)$rawChildAge > 120)) {
        throw new Exception('Please enter a valid VIP member age from 1 to 120.');
    }
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
        // VIP membership is always registered under the parent/guardian's name
        $rawChildName = $rawGuardianName;
        if (($rawChildAge === '' || $rawChildAge === '0') && $userId > 0) {
            $uStmt = $db->prepare("SELECT birthdate FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow && !empty($uRow['birthdate'])) {
                $bdate = decryptAES256($uRow['birthdate']);
                if ($bdate && strtotime($bdate)) {
                    $rawChildAge = (string) (new DateTime($bdate))->diff(new DateTime())->y;
                }
            }
        }
        if ($rawChildAge === '') {
            $rawChildAge = '0';
        }
        $rawChildGrade = '0';
        if (empty($rawChildSchool)) {
            $rawChildSchool = 'N/A';
        }
    } else {
        $rawChildName     = normalizeName($rawChildName, 'Child name');
        $rawChildAge      = digitsOnly($rawChildAge);
        $rawChildGrade    = digitsOnly($rawChildGrade);
        $rawChildSchool   = preg_replace('/[^a-zA-Z\s]/', '', $rawChildSchool);

        if ($rawChildAge === '') {
            throw new Exception('Child age is required and must contain numbers only.');
        }
        if ($rawChildGrade === '') {
            throw new Exception('Child grade is required and must contain numbers only.');
        }
    }

    $rawGuardianAge  = sanitize($_POST['guardian_age'] ?? '');
    if ($rawGuardianAge !== '') {
        $rawGuardianAge = digitsOnly($rawGuardianAge);
    } elseif ($isVip && !empty($rawChildAge) && $rawChildAge !== '0') {
        $rawGuardianAge = $rawChildAge;
    } elseif ($userId > 0) {
        $uStmt = $db->prepare("SELECT birthdate FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow && !empty($uRow['birthdate'])) {
            $bdate = decryptAES256($uRow['birthdate']);
            if ($bdate && strtotime($bdate)) {
                $rawGuardianAge = (string) (new DateTime($bdate))->diff(new DateTime())->y;
            }
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
    $guardianAgeEnc  = encryptAES256($rawGuardianAge);
    $addressEnc      = encryptAES256($rawAddress);
    $contactEnc      = encryptAES256($rawContact);
    $facebookEnc     = encryptAES256($rawFacebook);
    $notesEnc        = encryptAES256($rawNotes);

    // ── Insert enrollment ─────────────────────────────
    $initialStatus = 'pending';
    $sql = "INSERT INTO enrollments
        (reference_no, user_id, program, package_selected, start_date, timeslot,
         child_name, child_age, child_grade, child_school,
         guardian_name, guardian_age, address, contact, facebook_name, payment_screenshot, payment_method, notes, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

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
        $guardianAgeEnc,
        $addressEnc,
        $contactEnc,
        $facebookEnc,
        $screenshotPath,
        sanitize($_POST['payment_method']   ?? ''),
        $notesEnc,
        $initialStatus,
    ]);

    $enrollmentId = (int) $db->lastInsertId();

    // ── Sync guardian details to user profile if empty ──
    if ($userId > 0) {
        try {
            $uStmt = $db->prepare("SELECT firstname, contact_number, address FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $uFields = [];
                $uParams = [];
                if (empty($uRow['firstname']) && !empty($rawGuardianName)) {
                    $uFields[] = "firstname = ?";
                    $uParams[] = encryptAES256($rawGuardianName);
                }
                if (empty($uRow['contact_number']) && !empty($rawContact)) {
                    $uFields[] = "contact_number = ?";
                    $uParams[] = encryptAES256($rawContact);
                }
                if (empty($uRow['address']) && !empty($rawAddress)) {
                    $uFields[] = "address = ?";
                    $uParams[] = encryptAES256($rawAddress);
                }
                if (!empty($uFields)) {
                    $uParams[] = $userId;
                    $db->prepare("UPDATE users SET " . implode(', ', $uFields) . " WHERE id = ?")->execute($uParams);
                }
            }
        } catch (Exception $ex) {
            error_log('[Sync User Profile Error] ' . $ex->getMessage());
        }
    }

    // ── Respond to browser NOW — no email code blocks this ────────────────
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
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // ── Fire-and-forget: spawn a background PHP process to send email ─────
    // popen() on Windows spawns a separate process that runs independently.
    // The current request is already answered above — this never blocks the user.
    $phpExe = (defined('PHP_BINARY') && str_ends_with(strtolower(PHP_BINARY), 'php.exe') && file_exists(PHP_BINARY))
        ? PHP_BINARY
        : (file_exists('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : 'php');
    $mailScript = EINSTEIN_ROOT . '/includes/send_enrollment_email.php';
    $emailArg   = escapeshellarg($email);
    $guardianArg = escapeshellarg($_POST['guardian_name'] ?? 'Guardian');
    $childArg   = escapeshellarg(!empty($_POST['child_name']) ? $_POST['child_name'] : ($_POST['guardian_name'] ?? 'Member'));
    $refArg     = escapeshellarg($ref);
    $programArg = escapeshellarg($_POST['program'] ?? 'Program');
    $logArg     = escapeshellarg(EINSTEIN_ROOT . '/logs/mail_bg.log');
    if (file_exists($mailScript)) {
        @popen("start /B \"\" \"$phpExe\" \"$mailScript\" $emailArg $guardianArg $childArg $refArg $programArg >> $logArg 2>&1", 'r');
    }
    exit;

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// ── CONFIRMATION EMAIL ────────────────────────────────
function sendConfirmationEmail(
    string $email, string $guardian, string $child, string $ref, string $program
): void {
    // Hard safety net: the entire email routine must finish in ≤3 s or abort
    // (set_time_limit is already 4 s at call-site, so we stay under that)
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
          <div class="hdr"><h1>EINSTEIN-Center For Modern Education</h1><p>Tutorial • Workshop • Childcare</p></div>
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

        $smtp = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 1.5);
        if (!$smtp) return;
        stream_set_timeout($smtp, 1);

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