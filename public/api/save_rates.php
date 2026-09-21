<?php
// save_rates.php
// Persist program rates into program_packages table. Requires admin session.

ob_start();
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    require_once __DIR__ . '/../includes/bootstrap.php';
    setSecurityHeaders();
    require_once __DIR__ . '/../includes/admin_auth.php';

    // ── Permission check: Head Admin always allowed; sub-admins need can_edit_prices ──
    startPortalSession();
    $isHeadAdmin = !empty($_SESSION['is_head_admin']);
    if (!$isHeadAdmin) {
        $subAdminId = (int)($_SESSION['sub_admin_id'] ?? 0);
        if ($subAdminId) {
            $permDb = getDB();
            $permStmt = $permDb->prepare("SELECT can_edit_prices FROM admin_accounts WHERE id = ? LIMIT 1");
            $permStmt->execute([$subAdminId]);
            $canEdit = (int)$permStmt->fetchColumn();
            if (!$canEdit) {
                ob_clean();
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You do not have permission to edit prices. Contact the Head Admin.']);
                exit;
            }
        }
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $section = strtolower(trim($input['section'] ?? ''));
    $data = $input['data'] ?? null;
    if (!$section || !is_array($data)) {
        throw new Exception('Invalid payload.');
    }

    $map = [
        'childcare'   => 'Child Care',
        'workshop'    => 'Workshop',
        'playschool'  => 'PlaySchool',
        'tutorial'    => 'Academic Tutorial',
        'madstudio'   => 'M.A.D. Studio',
        'mad'         => 'M.A.D. Studio',
        'summerblast' => 'Summer Blast'
    ];
    if (!isset($map[$section])) {
        throw new Exception('Unknown section');
    }
    $programName = $map[$section];

    $db = getDB();
    $db->beginTransaction();

    $sanitizeRate = function ($value): string {
        if ($value === null || $value === '') return '';
        $cleaned = str_replace(['PHP', 'Peso', 'php', 'peso', '/mo', '+ transpo', '+transpo'], '', (string)$value);
        return trim(preg_replace('/[^0-9.]/', '', $cleaned));
    };

    $requireNumber = function ($value, string $label) use ($sanitizeRate): float {
        $clean = $sanitizeRate($value);
        if ($clean === '') {
            throw new Exception($label . ' is required.');
        }
        if (!is_numeric($clean)) {
            throw new Exception($label . ' must contain numbers only. Got: ' . $clean);
        }
        return (float) $clean;
    };

    $optionalNumber = function ($value, string $label) use ($requireNumber, $sanitizeRate) {
        $clean = $sanitizeRate($value);
        if ($clean === '') {
            return null;
        }
        return $requireNumber($clean, $label);
    };

    // Formats a cleaned numeric value back to ₱X,XXX for storage
    $formatRate = function ($value) use ($sanitizeRate): string {
        $clean = $sanitizeRate($value);
        if (!is_numeric($clean)) return (string)$value; // pass through if not numeric
        $num = (float)$clean;
        // Format with comma thousands separator, no decimals if whole number
        if ($num == floor($num)) {
            return '₱' . number_format((int)$num);
        }
        return '₱' . number_format($num, 2);
    };

    // Prepare statements for upsert-like behavior (select, insert, update)
    $selectStmt = $db->prepare('SELECT id FROM program_packages WHERE program_name = ? AND package_name = ? LIMIT 1');
    $insertStmt = $db->prepare('INSERT INTO program_packages (program_name, package_name, care_duration, rate, capacity_slots, package_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $updateStmt = $db->prepare('UPDATE program_packages SET care_duration = ?, rate = ?, capacity_slots = ?, package_type = ?, updated_at = NOW() WHERE id = ?');

    foreach ($data as $row) {
        if (!is_array($row)) continue;
        if ($section === 'childcare') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $duration = $row['duration'] ?? ($row['care_duration'] ?? null);
            // Validate rate is numeric, then store formatted ₱X,XXX string
            $requireNumber($row['rate'] ?? null, 'Child care rate'); // validate
            $rate = $formatRate($row['rate'] ?? null);
            $slots = $row['slots'] ?? null; // "Monthly", "Weekly", "Daily" — keep as text
            $type = $row['package_type'] ?? 'childcare';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$duration, $rate, $slots, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $duration, $rate, $slots, $type]);
            }
        } elseif ($section === 'workshop') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $capacity = $row['capacity'] ?? null; // keep as text (e.g. "Up to 10 students/class")
            $rawRate = trim((string)($row['rate'] ?? ''));
            if ($rawRate === '') throw new Exception('Workshop rate is required.');
            $rate = $rawRate;
            if (!preg_match('/[₱]|PHP|Peso/i', $rate)) {
                $rate = '₱' . $rate;
            }
            $schedule = $row['schedule'] ?? null;
            $type = 'workshop';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$schedule, $rate, $capacity, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $schedule, $rate, $capacity, $type]);
            }
        } elseif ($section === 'playschool') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $age = $row['age'] ?? null; // e.g. "2-3 yrs" — keep as text
            $requireNumber($row['rate'] ?? null, 'Playschool rate');
            $rate = $formatRate($row['rate'] ?? null) . '/mo';
            $notes = $row['notes'] ?? null;
            $type = 'playschool';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$age, $rate, $notes, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $age, $rate, $notes, $type]);
            }
        } elseif ($section === 'tutorial') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $detail = $row['detail'] ?? ($row['care_duration'] ?? null);
            $requireNumber($row['rate'] ?? null, 'Tutorial rate');
            $rate = $formatRate($row['rate'] ?? null);
            $type = $row['package_type'] ?? 'tutorial';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$detail, $rate, null, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $detail, $rate, null, $type]);
            }
        } elseif ($section === 'madstudio' || $section === 'mad') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $category = $row['category'] ?? ($row['care_duration'] ?? null);
            $requireNumber($row['rate'] ?? null, 'M.A.D. Studio rate');
            $rate = $formatRate($row['rate'] ?? null);
            $schedule = $row['schedule'] ?? ($row['capacity_slots'] ?? null);
            $type = $row['package_type'] ?? 'madstudio';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$category, $rate, $schedule, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $category, $rate, $schedule, $type]);
            }
        } elseif ($section === 'summerblast') {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $category = $row['category'] ?? ($row['care_duration'] ?? null);
            $requireNumber($row['rate'] ?? null, 'Summer Blast rate');
            $rate = $formatRate($row['rate'] ?? null);
            $detail = $row['detail'] ?? ($row['capacity_slots'] ?? null);
            $type = $row['package_type'] ?? 'summerblast';
            $selectStmt->execute([$programName, $name]);
            $found = $selectStmt->fetchColumn();
            if ($found) {
                $updateStmt->execute([$category, $rate, $detail, $type, $found]);
            } else {
                $insertStmt->execute([$programName, $name, $category, $rate, $detail, $type]);
            }
        }
    }

    $db->commit();

    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Rates saved to database.']);
    exit;
} catch (Exception $e) {
    // try fallback: save to local JSON file
    try {
        $payload = ['section' => $section ?? null, 'data' => $data ?? null, 'error' => $e->getMessage(), 'saved_at' => date('c')];
        file_put_contents(EINSTEIN_ROOT . '/saved_rates_fallback.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage() . '. Rates saved to saved_rates_fallback.json']);
        exit;
    } catch (Exception $e2) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to save rates: ' . $e->getMessage()]);
        exit;
    }
}
