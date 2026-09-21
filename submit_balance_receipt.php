<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
setSecurityHeaders();
startUserSession();
require_once __DIR__ . '/balance_helpers.php';
require_once __DIR__ . '/receipt_detection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new RuntimeException('Please log in again.');
    }
    $ref = $_POST['reference_no'] ?? '';
    if (!is_string($ref) || $ref === '') throw new RuntimeException('Choose an enrollment.');

    $file = $_FILES['receipt'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('Upload a receipt up to 5 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime])) {
        throw new RuntimeException('Use a JPG, PNG, or WEBP receipt.');
    }

    $hash = hash_file('sha256', $file['tmp_name']);

    // OCR detection is optional helper — wrap in try-catch so it never breaks submission
    $detected = null;
    try {
        if (function_exists('readReceiptPayment')) {
            $detected = readReceiptPayment(realpath($file['tmp_name']));
        }
    } catch (Throwable $t) {
        $detected = null;
    }

    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM enrollments WHERE reference_no=? AND user_id=? FOR UPDATE");
    $stmt->execute([$ref, (int) $_SESSION['user_id']]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) {
        throw new RuntimeException('Enrollment not found or not eligible for payment.');
    }

    $notes = json_decode($enrollment['admin_notes'] ?? '', true);
    if (!is_array($notes)) {
        $notes = ['previous_notes' => $enrollment['admin_notes']];
    }
    if (!isset($notes['balance_receipts']) || !is_array($notes['balance_receipts'])) {
        $notes['balance_receipts'] = [];
    }

    $total = balanceTotalFee($enrollment);
    $paid = balanceCurrentPaidAmount($enrollment, $notes, $total);
    $due = round($total - $paid, 2);

    $filename = 'balance_' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $path = uploadToLocal($file['tmp_name'], $filename);
    $now = date('Y-m-d H:i:s');

    $receiptEntry = [
        'path' => $path,
        'method' => 'Online (QR)',
        'submitted_at' => $now,
        'status' => 'pending',
        'file_hash' => $hash,
        'detected_amount' => $detected['amount'] ?? null,
        'reference' => $detected['reference'] ?? null
    ];

    $notes['balance_receipts'][] = $receiptEntry;

    $updateStmt = $db->prepare('UPDATE enrollments SET admin_notes=?, updated_at=NOW() WHERE id=?');
    $updateStmt->execute([json_encode($notes), $enrollment['id']]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'status' => 'pending',
        'balance_due' => $due,
        'message' => 'Receipt submitted for admin approval. Your balance will update only after approval.'
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
