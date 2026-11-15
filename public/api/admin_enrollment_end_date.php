<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/enrollment_end_dates.php';
setSecurityHeaders();

try {
    $db = getDB();
    ensureEnrollmentEndDateHistory($db);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $id = (int) ($_GET['enrollment_id'] ?? 0);
        $stmt = $db->prepare('SELECT previous_override, new_override, reason, changed_by, changed_at FROM enrollment_end_date_history WHERE enrollment_id = ? ORDER BY id DESC');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($method !== 'POST') { http_response_code(405); throw new RuntimeException('POST required.'); }
    if (empty($_SESSION['is_head_admin'])) { http_response_code(403); throw new RuntimeException('Head admin access required to change an end date.'); }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($input['enrollment_id'] ?? 0);
    $date = trim((string) ($input['end_date'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));
    if ($id <= 0) throw new RuntimeException('Provide a valid enrollment.');
    if (mb_strlen($reason) > 500) throw new RuntimeException('Reason must be at most 500 characters.');
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid end date.');
    }
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id, status, start_date, created_at FROM enrollments WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment || $enrollment['status'] !== 'confirmed') throw new RuntimeException('Only confirmed enrollments can have an end date changed.');
    $start = substr((string) ($enrollment['start_date'] ?: $enrollment['created_at']), 0, 10);
    if ($date !== '' && $start && $date < $start) throw new RuntimeException('End date cannot be before the start date.');
    $latest = $db->prepare('SELECT new_override FROM enrollment_end_date_history WHERE enrollment_id = ? ORDER BY id DESC LIMIT 1');
    $latest->execute([$id]);
    $previous = $latest->fetchColumn();
    $previous = $previous === false ? null : $previous;
    $new = $date === '' ? null : $date;
    if ($previous === $new) throw new RuntimeException('The end date has not changed.');
    $actor = trim((string) ($_SESSION['display_name'] ?? $_SESSION['admin_user'] ?? $_SESSION['username'] ?? 'Administrator'));
    $stmt = $db->prepare('INSERT INTO enrollment_end_date_history (enrollment_id, previous_override, new_override, reason, changed_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$id, $previous, $new, $reason, mb_substr($actor, 0, 160)]);
    $db->commit();
    echo json_encode(['success' => true, 'end_date_override' => $new]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
