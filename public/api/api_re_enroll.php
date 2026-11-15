<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/enrollment_end_dates.php';
setSecurityHeaders();

function generateRef($db): string {
    for ($i = 0; $i < 10; $i++) {
        $ref = 'ECL-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)) . '-' . date('y');
        $chk = $db->prepare('SELECT id FROM enrollments WHERE reference_no = ?');
        $chk->execute([$ref]);
        if (!$chk->fetch()) return $ref;
    }
    return 'ECL-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)) . '-' . date('y');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    startUserSession();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new RuntimeException('Please log in again to continue.');
    }

    $userId = (int) $_SESSION['user_id'];
    $db = getDB();
    ensureEnrollmentEndDateHistory($db);

    $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    if ($paymentMethod === '' || strlen($paymentMethod) > 64 || strcasecmp($paymentMethod, 'Walk-in') === 0) {
        throw new RuntimeException('Choose an online payment method.');
    }
    $file = $_FILES['receipt'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('Upload a payment receipt up to 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime]) || !@getimagesize($file['tmp_name'])) {
        throw new RuntimeException('Use a valid JPG, PNG, or WEBP receipt.');
    }

    if ($enrollmentId <= 0) {
        throw new RuntimeException('Invalid enrollment ID.');
    }

    $db->beginTransaction();
    // Serialize requests from the same account so two submissions cannot race.
    $lock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $lock->execute([$userId]);
    if (!$lock->fetch()) throw new RuntimeException('User account not found.');

    // Fetch previous enrollment
    $stmt = $db->prepare("SELECT * FROM enrollments WHERE id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$enrollmentId, $userId]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prev) {
        throw new RuntimeException('Enrollment record not found.');
    }
    if ($prev['status'] !== 'confirmed') {
        throw new RuntimeException('Only confirmed enrollments can be re-enrolled.');
    }
    $endStmt = $db->prepare('SELECT new_override FROM enrollment_end_date_history WHERE enrollment_id = ? ORDER BY id DESC LIMIT 1');
    $endStmt->execute([$enrollmentId]);
    $override = $endStmt->fetchColumn();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if ($override && $today < $override) {
        throw new RuntimeException('Re-enrollment opens on ' . $override . '.');
    }

    // Check if there is already a pending re-enrollment for the same child and program
    $allUserPending = $db->prepare("SELECT id, reference_no, program, child_name FROM enrollments WHERE user_id = ? AND status = 'pending'");
    $allUserPending->execute([$userId]);
    $decryptedPrevChild = strtolower(trim(decryptAES256($prev['child_name']) ?: $prev['child_name']));
    $prevProg = strtolower(trim($prev['program']));

    foreach ($allUserPending->fetchAll(PDO::FETCH_ASSOC) as $pendingRow) {
        $pendingProg = strtolower(trim($pendingRow['program']));
        $pendingChild = strtolower(trim(decryptAES256($pendingRow['child_name']) ?: $pendingRow['child_name']));
        if ($pendingProg === $prevProg && $pendingChild === $decryptedPrevChild) {
            throw new RuntimeException('A re-enrollment request for this student is already pending review (Ref: ' . $pendingRow['reference_no'] . ').');
        }
    }

    $ref = generateRef($db);
    $filename = 'reenroll_' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $screenshotPath = uploadToLocal($file['tmp_name'], $filename);

    // Decrypt child name for readable notes
    $decryptedChild = decryptAES256($prev['child_name']) ?: 'Student';
    $previousNotes = decryptAES256((string) ($prev['notes'] ?? ''));
    $notes = json_decode($previousNotes ?: '', true);
    if (!is_array($notes)) $notes = $previousNotes ? ['previous_notes' => $previousNotes] : [];
    $notes['re_enrollment'] = ['previous_reference' => $prev['reference_no']];
    $notes = encryptAES256(json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $sql = "INSERT INTO enrollments
        (reference_no, user_id, program, package_selected, start_date, timeslot,
         child_name, child_age, child_grade, child_school,
         guardian_name, address, contact, facebook_name,
         payment_method, payment_screenshot, payment_status, status, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 'pending', ?, NOW(), NOW())";

    $insertStmt = $db->prepare($sql);
    $insertStmt->execute([
        $ref,
        $userId,
        $prev['program'],
        $prev['package_selected'],
        $prev['start_date'],
        $prev['timeslot'],
        $prev['child_name'],
        $prev['child_age'],
        $prev['child_grade'],
        $prev['child_school'],
        $prev['guardian_name'],
        $prev['address'],
        $prev['contact'],
        $prev['facebook_name'],
        $paymentMethod,
        $screenshotPath,
        $notes
    ]);

    $newId = (int) $db->lastInsertId();
    $db->commit();
    $receiptCommitted = true;

    try {
        $decryptedGuardian = decryptAES256((string) ($prev['guardian_name'] ?? '')) ?: 'Guardian';
        createAdminNotification($db, [
            'event_key' => 'reenrollment:' . $newId,
            'type' => 'enrollment',
            'title' => 'Re-enrollment Submitted',
            'message' => $decryptedChild . ' requested re-enrollment for ' . $prev['program'] . ' • Ref: ' . $ref,
            'entity_type' => 'enrollment',
            'entity_id' => $newId,
            'target_page' => 'enrollment',
            'target_ref' => $ref,
        ]);
    } catch (Throwable $notificationError) {
        error_log('[Admin Notification] Re-enrollment notification failed: ' . $notificationError->getMessage());
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Re-enrollment request submitted successfully.',
        'reference_no' => $ref,
        'enrollment_id' => $newId
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (empty($receiptCommitted) && !empty($screenshotPath) && isset($filename)) {
        // Remove only the file created by this failed request.
        @unlink(UPLOAD_DIR . $filename);
    }
    ob_clean();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
