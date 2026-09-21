<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
setSecurityHeaders();
startUserSession();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new RuntimeException('Please log in again.');
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $ref = $data['reference_no'] ?? '';
    if (!is_string($ref) || $ref === '') throw new RuntimeException('Choose a student from My Balance.');
    $db = getDB();
    $db->beginTransaction();
    $query = $db->prepare("SELECT id, admin_notes FROM enrollments WHERE reference_no=? AND user_id=? AND status='confirmed' FOR UPDATE");
    $query->execute([$ref, (int) $_SESSION['user_id']]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Enrollment not found.');
    $notes = json_decode($row['admin_notes'] ?? '', true);
    if (!is_array($notes)) $notes = ['previous_notes' => $row['admin_notes']];
    $pending = false;
    foreach ($notes['balance_receipts'] ?? [] as $receipt) if (($receipt['method'] ?? '') === 'Walk-in' && ($receipt['status'] ?? '') === 'pending') $pending = true;
    if (!$pending) {
        $notes['balance_receipts'][] = ['path'=>'walkin_' . bin2hex(random_bytes(12)), 'method'=>'Walk-in', 'status'=>'pending', 'submitted_at'=>date('Y-m-d H:i:s')];
        $db->prepare('UPDATE enrollments SET admin_notes=?, updated_at=NOW() WHERE id=?')->execute([json_encode($notes), $row['id']]);
    }
    // A payment preference is not proof of payment. Preserve the entire ledger.
    if (empty($notes['walkin_request'])) {
        $notes['walkin_request'] = ['requested_at' => date('Y-m-d H:i:s'), 'method' => 'Walk-In'];
        $db->prepare('UPDATE enrollments SET admin_notes=?, updated_at=NOW() WHERE id=?')->execute([json_encode($notes), $row['id']]);
    }
    $db->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Unable to save request. Please try again.' : $e->getMessage()]);
}
