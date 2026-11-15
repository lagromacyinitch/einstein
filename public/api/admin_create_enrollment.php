<?php
// Manual enrollments use the same database and encryption as parent enrollments.
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';

function adminIsSummerRegistrationPackage(string $package): bool {
    return (bool) preg_match('/registration\s+fee|slot\s+reservation|reservation/i', trim($package));
}

function adminValidateSummerBlastSelection(string $package, string $rawSelection): array {
    $label = strtolower(trim($package));
    if (preg_match('/^single\s+course\b/i', $label)) {
        $rule = ['key' => 'single', 'count' => 1, 'main' => ['Academics', 'Music', 'Arts & Dance', 'Sports', 'Special Courses'], 'free' => ['Academics']];
    } elseif (preg_match('/^two\s+courses\b/i', $label)) {
        $rule = ['key' => 'two', 'count' => 2, 'main' => ['Academics', 'Music', 'Arts & Dance', 'Sports', 'Special Courses'], 'free' => ['Academics']];
    } elseif (preg_match('/^special\s+course\b/i', $label)) {
        $rule = ['key' => 'special', 'count' => 1, 'main' => ['Special Courses'], 'free' => ['Academics', 'Music', 'Arts & Dance', 'Sports', 'Special Courses']];
    } else {
        throw new InvalidArgumentException('Please select a valid Summer Blast tuition package.');
    }
    $catalog = [
        'Academics' => ['Basic Read & Write', 'Reading w/ Comprehension', 'Math for Elementary', 'Math for High School', 'Public Speaking / Hosting', 'Wikang Tagalog / Filipino'],
        'Music' => ['Drum Lessons', 'Guitar Lessons', 'Piano Lessons', 'Violin Lessons', 'Voice Coaching'],
        'Arts & Dance' => ['Ballet Lessons', 'Drawing & Painting', 'Pop Dancing & Afro Dance', 'Gymnastics', 'Modeling Class', 'Photography'],
        'Sports' => ['Basketball Clinic', 'Chess Clinic'],
        'Special Courses' => ['Baking Class', 'Swimming Lessons', 'Taekwondo Class'],
    ];
    $coursesFor = static function (array $categories) use ($catalog): array {
        $result = [];
        foreach ($categories as $category) foreach ($catalog[$category] ?? [] as $course) $result[] = $course;
        return $result;
    };
    $payload = json_decode($rawSelection, true);
    if (!is_array($payload) || !is_array($payload['main'] ?? null)) throw new InvalidArgumentException('Please complete the Summer Blast course selection.');
    $main = array_values(array_filter(array_map(static fn($course) => trim((string) $course), $payload['main']), static fn($course) => $course !== ''));
    $free = trim((string) ($payload['free'] ?? ''));
    $mainAllowed = $coursesFor($rule['main']);
    $freeAllowed = $coursesFor($rule['free']);
    if (count($main) !== $rule['count'] || count(array_unique($main)) !== count($main)) throw new InvalidArgumentException('Please select the exact number of required Summer Blast main courses.');
    foreach ($main as $course) if (!in_array($course, $mainAllowed, true)) throw new InvalidArgumentException('One or more selected Summer Blast courses are not allowed for this package.');
    if ($free === '' || !in_array($free, $freeAllowed, true) || in_array($free, $main, true)) throw new InvalidArgumentException('Please select a valid free Summer Blast course.');
    return ['package_rule' => $rule['key'], 'main_courses' => $main, 'free_course' => $free];
}

function adminParseRentalDays($raw): array {
    if ($raw === '' || $raw === null || $raw === '[]') return [];
    $days = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($days) || count($days) > 60) throw new InvalidArgumentException('Invalid Studio Rental day selection.');
    $clean = [];
    foreach ($days as $day) {
        $date = trim((string) ($day['date'] ?? ''));
        $hours = filter_var($day['hours'] ?? null, FILTER_VALIDATE_INT);
        $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $hours === false || $hours < 1 || $hours > 99) {
            throw new InvalidArgumentException('Each Studio Rental day must have a valid date and 1–99 hours.');
        }
        $clean[] = ['date' => $date, 'hours' => $hours];
    }
    return $clean;
}

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
    $parentEmail = strtolower(trim((string) ($data['parent_email'] ?? '')));
    $parentPassword = (string) ($data['parent_password'] ?? '');
    if (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL) || strlen($parentEmail) > 255) throw new InvalidArgumentException('Enter a valid parent email.');
    if (strlen($parentPassword) < 8 || strlen($parentPassword) > 72) throw new InvalidArgumentException('Parent password must be 8 to 72 characters.');
    $guardianName = trim((string) ($children[0]['guardian_name'] ?? ''));
    if ($guardianName === '') throw new InvalidArgumentException('Parent/Guardian is required.');
    $db = getDB();
    $accountLockName = 'parent_' . substr(hash('sha256', $parentEmail), 0, 48);
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$accountLockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) throw new RuntimeException('Could not create the parent account. Please try again.');
    $accountLockHeld = true;
    $db->beginTransaction();
    $adminEmailCheck = $db->prepare("SELECT id FROM admin_accounts WHERE LOWER(username) = ? OR LOWER(COALESCE(email, '')) = ? LIMIT 1");
    $adminEmailCheck->execute([$parentEmail, $parentEmail]);
    if ($adminEmailCheck->fetch()) throw new InvalidArgumentException('This email is used by an admin account. Please use a different parent email.');
    $emailHash = hashLookup($parentEmail);
    $existing = $db->prepare('SELECT id, password_hash, is_verified FROM users WHERE email_hash = ? OR email = ? LIMIT 1 FOR UPDATE');
    $existing->execute([$emailHash, $parentEmail]);
    $existingAccount = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existingAccount) {
        if (empty($existingAccount['is_verified']) || !password_verify($parentPassword, $existingAccount['password_hash'])) {
            throw new InvalidArgumentException('This email already has an account. Enter its existing password; unverified accounts must complete email verification first.');
        }
        $parentUserId = (int) $existingAccount['id'];
    } else {
        $account = $db->prepare('INSERT INTO users (email, email_encrypted, email_hash, password_hash, firstname, address, contact_number, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        $account->execute([
            $parentEmail, encryptAES256($parentEmail), $emailHash,
            password_hash($parentPassword, PASSWORD_DEFAULT), encryptAES256($guardianName),
            encryptAES256((string) ($children[0]['address'] ?? '')),
            encryptAES256((string) ($children[0]['contact'] ?? ''))
        ]);
        $parentUserId = (int) $db->lastInsertId();
    }
    $records = [];
    foreach ($children as $index => $data) {
    if (!is_array($data)) throw new InvalidArgumentException('Invalid child details.');
    $receiptKey = isset($_POST['children']) ? 'payment_screenshot_' . $index : 'payment_screenshot';
    $fields = ['program', 'package_selected', 'child_name', 'child_age', 'child_grade', 'child_school', 'start_date', 'guardian_name', 'guardian_age', 'contact', 'address', 'timeslot', 'payment_method', 'reference_no'];
    $record = [];
    foreach ($fields as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            throw new InvalidArgumentException('Invalid field: ' . $field);
        }
        $record[$field] = trim($data[$field] ?? '');
    }
    if ($record['child_name'] === '') {
        throw new InvalidArgumentException('Student name is required.');
    }
    if (!preg_match('/^\d{1,3}$/', $record['child_age'])) {
        throw new InvalidArgumentException('Age must contain 1 to 3 digits.');
    }
    if (!preg_match('/^\d{1,2}$/', $record['guardian_age'])) {
        throw new InvalidArgumentException('Parent/Guardian age must contain 1 to 2 digits.');
    }
    $isSummerProgram = (bool) preg_match('/summer\s*blast/i', $record['program']);
    $isMadProgram = (bool) preg_match('/m\.?a\.?d\.?\s+studio/i', $record['program']);
    $rentalDays = adminParseRentalDays($data['studio_rental_days'] ?? '');
    $packageHasRegistrationFee = $isSummerProgram && adminIsSummerRegistrationPackage($record['package_selected']);
    $registrationFeeChecked = $isSummerProgram && filter_var($data['summer_registration_fee'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $hasRegistrationFee = $isSummerProgram && ($packageHasRegistrationFee || $registrationFeeChecked);
    $registrationOnly = $hasRegistrationFee && ($record['package_selected'] === '' || $packageHasRegistrationFee);
    if ($record['package_selected'] === '' && !$hasRegistrationFee && (!$isMadProgram || !$rentalDays)) {
        throw new InvalidArgumentException('Package / section is required, or add a Studio Rental day.');
    }
    if ($isSummerProgram && !$hasRegistrationFee && $record['package_selected'] === '') {
        throw new InvalidArgumentException('Select a Summer Blast package or Registration Fee.');
    }
    foreach (['guardian_name' => 'Parent/Guardian', 'contact' => 'Contact', 'address' => 'Address'] as $field => $label) {
        if (in_array($record[$field], ['', '-', '—'], true)) throw new InvalidArgumentException($label . ' is required.');
    }
    // Summer Blast and M.A.D. Studio use optional preferred schedule fields in
    // the client flow; registration-only and rental-only submissions are also
    // intentionally allowed without a timeslot.
    if ($record['timeslot'] === '' && !$isSummerProgram && !$isMadProgram) {
        throw new InvalidArgumentException('Timeslot is required.');
    }
    if (strlen($record['timeslot']) > 100) throw new InvalidArgumentException('Timeslot must be at most 100 characters.');
    if ($record['program'] !== 'VIP Club Membership' && $record['timeslot'] !== '' && !preg_match('/^[0-9:;.\- aApPmM]+$/', $record['timeslot'])) {
        throw new InvalidArgumentException('Preferred Timeslot may contain numbers, colons, semicolons, dashes, spaces, and AM/PM.');
    }
    if ($record['start_date'] !== '') {
        $startDate = DateTime::createFromFormat('!Y-m-d', $record['start_date']);
        if (!$startDate || $startDate->format('Y-m-d') !== $record['start_date']) {
            throw new InvalidArgumentException('Preferred Date must be a valid date.');
        }
    }
    if (!in_array($record['program'], ['Academic Tutorial', 'Weekend Workshop', 'PlaySchool', 'Child Care', 'M.A.D Studio', 'M.A.D. Studio', 'Summer Blast', 'VIP Club Membership'], true)) {
        throw new InvalidArgumentException('Please select a valid program.');
    }
    $summerSelection = null;
    if ($isSummerProgram && $record['package_selected'] !== '' && !$packageHasRegistrationFee) {
        $summerSelection = adminValidateSummerBlastSelection($record['package_selected'], (string) ($data['summerblast_courses'] ?? ''));
    }
    $registrationPackage = trim((string) ($data['summer_registration_fee_package'] ?? ''));
    if ($hasRegistrationFee) {
        if (!adminIsSummerRegistrationPackage($registrationPackage)) $registrationPackage = 'Registration Fee – ₱900';
        $storedPackageSelected = $registrationOnly ? $registrationPackage : ($record['package_selected'] . ' + ' . $registrationPackage);
    } else {
        $storedPackageSelected = $record['package_selected'];
    }
    $storedProgram = ($isMadProgram && !$record['package_selected'] && $rentalDays) ? 'M.A.D. Studio • Rental' : $record['program'];
    if ($registrationOnly) $storedProgram = 'Summer Blast Registration Fee';
    if (!in_array($record['payment_method'], ['Online Payment', 'Walk-in (Cash)'], true)) {
        throw new InvalidArgumentException('Please select a valid payment method.');
    }
    if (strlen($record['reference_no']) > 20 || strlen($storedPackageSelected) > 255) {
        throw new InvalidArgumentException('Reference number or package is too long.');
    }
    $record['admin_notes'] = null;
    $record['payment_screenshot'] = null;
    $notesPayload = [];
    if ($summerSelection) $notesPayload['summer_blast'] = $summerSelection;
    if ($hasRegistrationFee) $notesPayload['summer_blast_registration'] = ['registration_only' => $registrationOnly];
    if ($rentalDays) {
        $rate = (float) ($data['studio_rental_rate'] ?? 450);
        if ($rate <= 0) $rate = 450;
        $notesPayload['studio_rental'] = [
            'rate' => $rate,
            'days' => $rentalDays,
            'total' => round(array_reduce($rentalDays, static fn(float $sum, array $day): float => $sum + ($day['hours'] * $rate), 0.0), 2),
        ];
    }
    $record['notes'] = $notesPayload ? json_encode($notesPayload, JSON_UNESCAPED_UNICODE) : null;
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
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || $file['size'] > MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Please upload a JPG, PNG, or WEBP receipt up to 5 MB.');
        }
        $record['payment_screenshot'] = uploadToSupabase($file['tmp_name'], 'pmt_admin_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime]);
    }
    $db = getDB();
    if ($record['reference_no'] === '') {
        $record['reference_no'] = 'ECL-' . strtoupper(bin2hex(random_bytes(6)));
    }
    $stored = $record;
    foreach (['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'guardian_age', 'contact', 'address', 'notes'] as $field) {
        $stored[$field] = encryptAES256($record[$field]);
    }
    $db->prepare("INSERT INTO enrollments
        (user_id, program, package_selected, child_name, child_age, child_grade, child_school, start_date, guardian_name, guardian_age, contact, address, timeslot, payment_method, reference_no, notes, admin_notes, payment_screenshot, status, payment_status)
        VALUES (:user_id, :program, :package_selected, :child_name, :child_age, :child_grade, :child_school, :start_date, :guardian_name, :guardian_age, :contact, :address, :timeslot, :payment_method, :reference_no, :notes, :admin_notes, :payment_screenshot, 'confirmed', 'confirmed')")
        ->execute([
            'user_id' => $parentUserId,
            'program' => $storedProgram,
            'package_selected' => $storedPackageSelected,
            'child_name' => $stored['child_name'],
            'child_age' => $stored['child_age'],
            'child_grade' => $stored['child_grade'],
            'child_school' => $stored['child_school'],
            'start_date' => $record['start_date'] !== '' ? $record['start_date'] : null,
            'guardian_name' => $stored['guardian_name'],
            'guardian_age' => $stored['guardian_age'],
            'contact' => $stored['contact'],
            'address' => $stored['address'],
            'timeslot' => $registrationOnly ? '' : $record['timeslot'],
            'payment_method' => $record['payment_method'],
            'reference_no' => $record['reference_no'],
            'notes' => $stored['notes'],
            'admin_notes' => $record['admin_notes'],
            'payment_screenshot' => $record['payment_screenshot'],
        ]);
    $record['program'] = $storedProgram;
    $record['package_selected'] = $storedPackageSelected;
    $record['timeslot'] = $registrationOnly ? '' : $record['timeslot'];
    $record['id'] = (int) $db->lastInsertId();
    $record['user_id'] = $parentUserId;
    $record['status'] = 'confirmed';
    $record['payment_status'] = 'confirmed';
    $record['created_at'] = date('Y-m-d H:i:s');
    $record['updated_at'] = $record['created_at'];
    $records[] = $record;
    }
    $db->commit();
    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$accountLockName]);
    $accountLockHeld = false;
    ob_clean();
    echo json_encode(['success' => true, 'enrollment' => $records[0], 'enrollments' => $records]);
} catch (InvalidArgumentException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (!empty($accountLockHeld)) $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$accountLockName]);
    http_response_code(422);
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (!empty($accountLockHeld)) $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$accountLockName]);
    error_log('Manual enrollment save failed: ' . $e->getMessage());
    http_response_code(500);
    ob_clean();
    $duplicate = $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062;
    echo json_encode(['success' => false, 'message' => $duplicate
        ? 'That reference number is already used. Enter a different reference or leave it blank.'
        : 'Unable to save the student. Please check the database connection and try again.']);
}
