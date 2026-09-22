<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/student_archive_schema.php';
try {
    if (empty($_SESSION['is_head_admin'])) {
        http_response_code(403);
        throw new RuntimeException('Only the Head Admin can archive or restore students.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $id = filter_var($data['enrollment_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $data['action'] ?? '';
    if (!$id || $id < 1 || !in_array($action, ['archive', 'restore'], true)) throw new RuntimeException('Invalid student action.');
    $db = getDB(); ensureStudentArchiveTable($db);
    $db->beginTransaction();
    $query = $db->prepare('SELECT id, status FROM enrollments WHERE id=? FOR UPDATE');
    $query->execute([$id]);
    $record = $query->fetch(PDO::FETCH_ASSOC);
    if (!$record || $record['status'] !== 'confirmed') throw new RuntimeException('Confirmed student record not found.');
    // Archive only this program record. Financial and enrollment history stay intact.
    $db->prepare('INSERT INTO student_archives (enrollment_id, archived_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE archived_at=VALUES(archived_at)')
        ->execute([$id, $action === 'archive' ? date('Y-m-d H:i:s') : null]);
    $db->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Unable to update student. Please try again.' : $e->getMessage()]);
}
