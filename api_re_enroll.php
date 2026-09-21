<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
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
    startUserSession();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new RuntimeException('Please log in again to continue.');
    }

    $userId = (int) $_SESSION['user_id'];
    $db = getDB();

    $input = json_decode(file_get_contents('php://input'), true);
    $enrollmentId = (int) ($input['enrollment_id'] ?? 0);

    if ($enrollmentId <= 0) {
        throw new RuntimeException('Invalid enrollment ID.');
    }

    // Fetch previous enrollment
    $stmt = $db->prepare("SELECT * FROM enrollments WHERE id = ? AND user_id = ?");
    $stmt->execute([$enrollmentId, $userId]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prev) {
        throw new RuntimeException('Enrollment record not found.');
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

    // Decrypt child name for readable notes
    $decryptedChild = decryptAES256($prev['child_name']) ?: 'Student';
    $notes = 'Re-enrollment for ' . $decryptedChild . ' (Previous Ref: ' . $prev['reference_no'] . ')';

    $sql = "INSERT INTO enrollments
        (reference_no, user_id, program, package_selected, start_date, timeslot,
         child_name, child_age, child_grade, child_school,
         guardian_name, address, contact, facebook_name,
         payment_method, payment_screenshot, payment_status, status, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'unpaid', 'pending', ?, NOW(), NOW())";

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
        'Re-enrollment (Pending Admin)',
        $notes
    ]);

    $newId = (int) $db->lastInsertId();

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Re-enrollment request submitted successfully.',
        'reference_no' => $ref,
        'enrollment_id' => $newId
    ]);
    exit;

} catch (Throwable $e) {
    ob_clean();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
