<?php
// Manual enrollments use the same database and encryption as parent enrollments.
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

try {
    $data = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        ? json_decode(file_get_contents('php://input'), true) : $_POST;
    if (!is_array($data)) throw new InvalidArgumentException('Invalid enrollment data.');
    $children = isset($data['children']) ? (is_string($data['children']) ? json_decode($data['children'], true) : $data['children']) : [$data];
    if (!is_array($children) || !array_is_list($children) || count($children) < 1 || count($children) > 50) throw new InvalidArgumentException('Please submit between 1 and 50 children.');
    $db = getDB();
    $db->beginTransaction();
    $records = [];
    foreach ($children as $index => $data) {
    if (!is_array($data)) throw new InvalidArgumentException('Invalid child details.');
    $receiptKey = isset($_POST['children']) ? 'payment_screenshot_' . $index : 'payment_screenshot';
    $fields = ['program', 'package_selected', 'child_name', 'child_age', 'guardian_name', 'contact', 'address', 'timeslot', 'payment_method', 'reference_no'];
    $record = [];
    foreach ($fields as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            throw new InvalidArgumentException('Invalid field: ' . $field);
        }
        $record[$field] = trim($data[$field] ?? '');
    }
    if ($record['child_name'] === '' || $record['package_selected'] === '') {
        throw new InvalidArgumentException('Student name and package / section are required.');
    }
    foreach (['guardian_name' => 'Parent/Guardian', 'contact' => 'Contact', 'address' => 'Address', 'timeslot' => 'Timeslot'] as $field => $label) {
        if (in_array($record[$field], ['', '-', '—'], true)) throw new InvalidArgumentException($label . ' is required.');
    }
    if (strlen($record['timeslot']) > 100) throw new InvalidArgumentException('Timeslot must be at most 100 characters.');
    if (!in_array($record['program'], ['Academic Tutorial', 'Weekend Workshop', 'PlaySchool', 'Child Care', 'M.A.D Studio', 'Summer Blast', 'VIP Club Membership'], true)) {
        throw new InvalidArgumentException('Please select a valid program.');
    }
    if (!in_array($record['payment_method'], ['Online Payment', 'Walk-in (Cash)'], true)) {
        throw new InvalidArgumentException('Please select a valid payment method.');
    }
    if (strlen($record['reference_no']) > 20 || strlen($record['package_selected']) > 255) {
        throw new InvalidArgumentException('Reference number or package is too long.');
    }
    $record['admin_notes'] = null;
    $record['payment_screenshot'] = null;
    if ($record['payment_method'] === 'Walk-in (Cash)') {
        $amount = $data['paid_amount'] ?? '';
        if (!is_string($amount) || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)
            || !is_finite((float) $amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('Please enter a valid amount paid greater than zero (up to two decimal places).');
        }
        $paidNum = round((float) $amount, 2);
        $record['admin_notes'] = json_encode([
            'version' => 2,
            'paid_amount' => $paidNum,
            'payments' => [[
                'amount' => $paidNum,
                'method' => 'Walk-in (Cash)',
                'receipt_no' => $record['reference_no'] ?: ('ECL-' . strtoupper(bin2hex(random_bytes(4)))),
                'note' => 'Initial payment upon enrollment',
                'recorded_at' => date('Y-m-d H:i:s')
            ]]
        ]);
    } elseif (isset($_FILES[$receiptKey]) && $_FILES[$receiptKey]['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES[$receiptKey];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Receipt upload failed. Please select the file again.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || $file['size'] > MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Please upload a JPG, PNG, GIF, or WEBP receipt up to 5 MB.');
        }
        $record['payment_screenshot'] = uploadToSupabase($file['tmp_name'], 'pmt_admin_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime]);
    }
    $db = getDB();
    if ($record['reference_no'] === '') {
        $record['reference_no'] = 'ECL-' . strtoupper(bin2hex(random_bytes(6)));
    }
    $stored = $record;
    foreach (['child_name', 'child_age', 'guardian_name', 'contact', 'address'] as $field) {
        $stored[$field] = encryptAES256($record[$field]);
    }
    $db->prepare("INSERT INTO enrollments
        (program, package_selected, child_name, child_age, guardian_name, contact, address, timeslot, payment_method, reference_no, admin_notes, payment_screenshot, status, payment_status)
        VALUES (:program, :package_selected, :child_name, :child_age, :guardian_name, :contact, :address, :timeslot, :payment_method, :reference_no, :admin_notes, :payment_screenshot, 'confirmed', 'confirmed')")
        ->execute($stored);
    $record['id'] = (int) $db->lastInsertId();
    $record['status'] = 'confirmed';
    $record['payment_status'] = 'confirmed';
    $record['created_at'] = date('Y-m-d H:i:s');
    $record['updated_at'] = $record['created_at'];
    $records[] = $record;
    }
    $db->commit();
    ob_clean();
    echo json_encode(['success' => true, 'enrollment' => $records[0], 'enrollments' => $records]);
} catch (InvalidArgumentException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(422);
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Manual enrollment save failed: ' . $e->getMessage());
    http_response_code(500);
    ob_clean();
    $duplicate = $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062;
    echo json_encode(['success' => false, 'message' => $duplicate
        ? 'That reference number is already used. Enter a different reference or leave it blank.'
        : 'Unable to save the student. Please check the database connection and try again.']);
}
