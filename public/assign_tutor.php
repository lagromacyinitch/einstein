<?php
/**
 * assign_tutor.php — Assign/unassign tutor to enrollment (admin only)
 * POST { action: 'assign'|'unassign', enrollment_id, tutor_id, notes }
 * GET  ?enrollment_id=X  →  current assignment
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
require_once __DIR__ . '/admin_auth.php';

try {
    $db = getDB();

    // Ensure table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS tutor_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            tutor_id INT NOT NULL,
            assigned_by VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ta_enrollment (enrollment_id),
            INDEX idx_ta_tutor (tutor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $eid  = intval($_GET['enrollment_id'] ?? 0);
        $row  = $db->prepare("
            SELECT ta.*, t.full_name, t.email, t.phone, t.hourly_rate
            FROM tutor_assignments ta
            JOIN tutors t ON t.id = ta.tutor_id
            WHERE ta.enrollment_id = ?
            ORDER BY ta.assigned_at DESC LIMIT 1");
        $row->execute([$eid]);
        $assignment = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'assignment' => $assignment ?: null]);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $eid    = intval($body['enrollment_id'] ?? 0);
    $tid    = intval($body['tutor_id'] ?? 0);

    if (!$eid) throw new Exception('enrollment_id required.');

    if ($action === 'assign') {
        if (!$tid) throw new Exception('tutor_id required.');
        // Remove previous assignment for this enrollment
        $db->prepare("DELETE FROM tutor_assignments WHERE enrollment_id = ?")->execute([$eid]);
        $stmt = $db->prepare("INSERT INTO tutor_assignments (enrollment_id, tutor_id, assigned_by, notes) VALUES (?,?,?,?)");
        $assignedBy = $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Head Admin';
        $stmt->execute([$eid, $tid, $assignedBy, trim($body['notes'] ?? '')]);
        echo json_encode(['success' => true, 'message' => 'Tutor assigned.']);

    } elseif ($action === 'unassign') {
        $db->prepare("DELETE FROM tutor_assignments WHERE enrollment_id = ?")->execute([$eid]);
        echo json_encode(['success' => true, 'message' => 'Assignment removed.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
