<?php
/**
 * tutors.php — Tutor management (admin only)
 * GET  ?action=list
 * POST action=create|update|delete
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/tutor_profile_helpers.php';

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

function digitsOnly(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

try {
    $db     = getDB();
    ensureTutorProfileSchema($db);
    foreach ($db->query("SELECT * FROM admin_accounts WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $account) linkTutorAccount($db,$account);
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? ($_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? ''));

    if ($method === 'GET') {
        $rows = $db->query("SELECT * FROM tutors WHERE is_active = true ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'tutors' => $rows]);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'create') {
        $name  = normalizeName($body['full_name'] ?? '', 'Full name');
        $email = trim($body['email'] ?? '');
        $phone = digitsOnly(trim($body['phone'] ?? ''));
        $rate  = $body['hourly_rate'] ?? null;
        $subj  = $body['subjects'] ?? [];
        $schedule = trim($body['schedule'] ?? '') ?: null;
        if ($rate === null || $rate === '' || !is_numeric($rate)) throw new Exception('Hourly rate must contain numbers only.');
        $stmt = $db->prepare("INSERT INTO tutors (full_name, email, phone, hourly_rate, subjects, schedule, teaching_specialization) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $name,
            $email ?: null,
            $phone ?: null,
            (float) $rate,
            '{' . implode(',', array_map('trim', $subj)) . '}',
            $schedule,
            trim($body['teaching_specialization'] ?? '') ?: null
        ]);
        echo json_encode(['success' => true, 'message' => 'Tutor added.', 'id' => $db->lastInsertId()]);

    } elseif ($action === 'update') {
        $id   = intval($body['id'] ?? 0);
        $name = normalizeName($body['full_name'] ?? '', 'Full name');
        $rate = $body['hourly_rate'] ?? null;
        if (!$id || !$name) throw new Exception('ID and name required.');
        if ($rate === null || $rate === '' || !is_numeric($rate)) throw new Exception('Hourly rate must contain numbers only.');
        $linked=$db->prepare('SELECT id FROM admin_accounts WHERE tutor_id=?');
        $linked->execute([$id]);
        $accountId=(int)$linked->fetchColumn();
        if ($accountId) validateTutorEmail($db,trim($body['email'] ?? ''),$accountId);
        $db->beginTransaction();
        $subj = $body['subjects'] ?? [];
        $stmt = $db->prepare("UPDATE tutors SET full_name=?, email=?, phone=?, hourly_rate=?, subjects=?, schedule=?, teaching_specialization=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([
            $name,
            trim($body['email'] ?? '') ?: null,
            digitsOnly(trim($body['phone'] ?? '')) ?: null,
            (float) $rate,
            '{' . implode(',', array_map('trim', (array)$subj)) . '}',
            trim($body['schedule'] ?? '') ?: null,
            trim($body['teaching_specialization'] ?? '') ?: null,
            $id
        ]);
        if ($accountId) $db->prepare('UPDATE admin_accounts SET display_name=?,email=?,updated_at=NOW() WHERE id=?')->execute([$name,trim($body['email']),$accountId]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Tutor updated.']);

    } elseif ($action === 'delete') {
        $id = intval($body['id'] ?? 0);
        if (!$id) throw new Exception('ID required.');
        $db->prepare("UPDATE tutors SET is_active=false WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Tutor removed.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
