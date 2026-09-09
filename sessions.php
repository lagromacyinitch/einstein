<?php
/**
 * sessions.php — Session logging and payroll (admin only)
 *
 * GET  ?action=list&tutor_id=X&month=YYYY-MM   → sessions list
 * GET  ?action=payroll&tutor_id=X&month=YYYY-MM → payroll summary
 * GET  ?action=tutors_summary                    → all tutors with hours this month
 * POST action=log      { enrollment_id, tutor_id, session_date, duration_hours, notes }
 * POST action=verify   { session_id }
 * POST action=delete   { session_id }
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
require_once __DIR__ . '/admin_auth.php';

try {
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {

        if ($action === 'list') {
            $tid   = intval($_GET['tutor_id'] ?? 0);
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT s.*, t.full_name AS tutor_name, e.child_name, e.program, e.reference_no
                FROM sessions s
                JOIN tutors t ON t.id = s.tutor_id
                JOIN enrollments e ON e.id = s.enrollment_id
                WHERE s.tutor_id = ?
                  AND to_char(s.session_date, 'YYYY-MM') = ?
                ORDER BY s.session_date DESC");
            $stmt->execute([$tid, $month]);
            echo json_encode(['success' => true, 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif ($action === 'payroll') {
            $tid   = intval($_GET['tutor_id'] ?? 0);
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT
                    t.full_name, t.hourly_rate,
                    COUNT(s.id)                       AS total_sessions,
                    SUM(s.duration_hours)             AS total_hours,
                    SUM(CASE WHEN s.is_verified THEN s.duration_hours ELSE 0 END) AS verified_hours,
                    SUM(CASE WHEN s.is_verified THEN s.duration_hours ELSE 0 END) * t.hourly_rate AS gross_pay
                FROM sessions s
                JOIN tutors t ON t.id = s.tutor_id
                WHERE s.tutor_id = ?
                  AND to_char(s.session_date, 'YYYY-MM') = ?
                GROUP BY t.full_name, t.hourly_rate");
            $stmt->execute([$tid, $month]);
            echo json_encode(['success' => true, 'payroll' => $stmt->fetch(PDO::FETCH_ASSOC)]);

        } elseif ($action === 'tutors_summary') {
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT
                    t.id, t.full_name, t.hourly_rate,
                    COUNT(s.id)                       AS total_sessions,
                    COALESCE(SUM(s.duration_hours), 0) AS total_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified THEN s.duration_hours ELSE 0 END), 0) AS verified_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified THEN s.duration_hours ELSE 0 END), 0) * t.hourly_rate AS gross_pay
                FROM tutors t
                LEFT JOIN sessions s ON s.tutor_id = t.id
                    AND to_char(s.session_date, 'YYYY-MM') = ?
                WHERE t.is_active = true
                GROUP BY t.id, t.full_name, t.hourly_rate
                ORDER BY t.full_name");
            $stmt->execute([$month]);
            echo json_encode(['success' => true, 'tutors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } else {
            throw new Exception('Unknown action.');
        }
        exit;
    }

    // POST
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'log') {
        $eid  = intval($body['enrollment_id'] ?? 0);
        $tid  = intval($body['tutor_id'] ?? 0);
        $date = $body['session_date'] ?? '';
        $hrs  = floatval($body['duration_hours'] ?? 1);
        if (!$eid || !$tid || !$date) throw new Exception('enrollment_id, tutor_id, session_date are required.');
        $stmt = $db->prepare("INSERT INTO sessions (enrollment_id, tutor_id, session_date, duration_hours, notes) VALUES (?,?,?,?,?)");
        $stmt->execute([$eid, $tid, $date, $hrs, trim($body['notes'] ?? '')]);
        echo json_encode(['success' => true, 'message' => 'Session logged.', 'id' => $db->lastInsertId()]);

    } elseif ($action === 'verify') {
        $sid = intval($body['session_id'] ?? 0);
        if (!$sid) throw new Exception('session_id required.');
        $db->prepare("UPDATE sessions SET is_verified=true, verified_by=?, verified_at=NOW() WHERE id=?")
           ->execute([$_SESSION['admin_user'] ?? 'admin', $sid]);
        echo json_encode(['success' => true, 'message' => 'Session verified.']);

    } elseif ($action === 'delete') {
        $sid = intval($body['session_id'] ?? 0);
        if (!$sid) throw new Exception('session_id required.');
        $db->prepare("DELETE FROM sessions WHERE id=?")->execute([$sid]);
        echo json_encode(['success' => true, 'message' => 'Session deleted.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
