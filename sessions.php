<?php
/**
 * sessions.php — Session logging and payroll (MySQL compatible)
 *
 * GET  ?action=list&tutor_id=X&month=YYYY-MM   → sessions list for admin
 * GET  ?action=my_sessions                      → sessions list for logged-in tutor
 * GET  ?action=payroll&tutor_id=X&month=YYYY-MM → payroll summary
 * GET  ?action=tutors_summary                    → all tutors with hours this month
 * POST action=log      { enrollment_id, tutor_id, student_name, session_date, duration_hours, notes }
 * POST action=verify   { session_id }
 * POST action=delete   { session_id }
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
startPortalSession();

$role = $_SESSION['role'] ?? '';
if (!$role) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

try {
    $db = getDB();

    // ── Ensure sessions table exists ───────────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL DEFAULT 0,
        student_name VARCHAR(150) NOT NULL DEFAULT '',
        tutor_id INT UNSIGNED NOT NULL,
        session_date DATE NOT NULL,
        duration_hours DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        notes TEXT NULL,
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        verified_by VARCHAR(100) NULL,
        verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tutor_date (tutor_id, session_date),
        INDEX idx_enrollment (enrollment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Add student_name column if missing from existing table
    try {
        $cols = $db->query("SHOW COLUMNS FROM sessions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('student_name', $cols)) {
            $db->exec("ALTER TABLE sessions ADD COLUMN student_name VARCHAR(150) NOT NULL DEFAULT '' AFTER enrollment_id");
        }
    } catch(Exception $ignore) {}

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    if (!$action) {
        $body_raw = file_get_contents('php://input');
        $body = json_decode($body_raw, true) ?? [];
        $action = $body['action'] ?? ($_POST['action'] ?? '');
    } else {
        $body = [];
    }

    // Helper: resolve tutor_id for current user
    $resolveTutorId = function() use ($db) {
        $tid = intval($_SESSION['tutor_id'] ?? 0);
        if ($tid) return $tid;
        $displayName = $_SESSION['display_name'] ?? '';
        if ($displayName) {
            $stmt = $db->prepare("SELECT id FROM tutors WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$displayName]);
            $found = $stmt->fetchColumn();
            if ($found) {
                $_SESSION['tutor_id'] = (int)$found;
                return (int)$found;
            }
        }
        return 0;
    };

    if ($method === 'GET') {

        if ($action === 'list') {
            $tid   = intval($_GET['tutor_id'] ?? 0);
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT s.*, t.full_name AS tutor_name, e.child_name, e.program, e.reference_no
                FROM sessions s
                JOIN tutors t ON t.id = s.tutor_id
                LEFT JOIN enrollments e ON e.id = s.enrollment_id
                WHERE s.tutor_id = ?
                  AND DATE_FORMAT(s.session_date, '%Y-%m') = ?
                ORDER BY s.session_date DESC, s.id DESC");
            $stmt->execute([$tid, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $decrypted = [];
            foreach ($rows as $r) {
                $d = decryptRow($r, ['child_name']);
                if (empty($d['child_name']) && !empty($d['student_name'])) {
                    $d['child_name'] = $d['student_name'];
                }
                $decrypted[] = $d;
            }
            echo json_encode(['success' => true, 'sessions' => $decrypted]);

        } elseif ($action === 'my_sessions') {
            $tutorId = $resolveTutorId();
            if (!$tutorId) {
                echo json_encode(['success' => true, 'sessions' => []]);
                exit;
            }
            $stmt = $db->prepare("
                SELECT s.*, e.child_name, e.program, e.reference_no
                FROM sessions s
                LEFT JOIN enrollments e ON e.id = s.enrollment_id
                WHERE s.tutor_id = ?
                ORDER BY s.session_date DESC, s.id DESC");
            $stmt->execute([$tutorId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $sessions = [];
            foreach ($rows as $r) {
                $dec = decryptRow($r, ['child_name']);
                $sName = !empty($dec['child_name']) ? $dec['child_name'] : ($r['student_name'] ?: 'Student');
                $sessions[] = [
                    'id' => (int)$r['id'],
                    'date' => $r['session_date'],
                    'student' => $sName,
                    'program' => $dec['program'] ?? 'Academic Tutorial',
                    'hours' => (float)$r['duration_hours'],
                    'notes' => $r['notes'] ?? '',
                    'is_verified' => (bool)$r['is_verified']
                ];
            }
            echo json_encode(['success' => true, 'sessions' => $sessions]);

        } elseif ($action === 'payroll') {
            $tid   = intval($_GET['tutor_id'] ?? 0);
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT
                    t.full_name, t.hourly_rate,
                    COUNT(s.id)                       AS total_sessions,
                    COALESCE(SUM(s.duration_hours), 0) AS total_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified = 1 THEN s.duration_hours ELSE 0 END), 0) AS verified_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified = 1 THEN s.duration_hours ELSE 0 END) * t.hourly_rate, 0) AS gross_pay
                FROM tutors t
                LEFT JOIN sessions s ON s.tutor_id = t.id AND DATE_FORMAT(s.session_date, '%Y-%m') = ?
                WHERE t.id = ?
                GROUP BY t.id, t.full_name, t.hourly_rate");
            $stmt->execute([$month, $tid]);
            echo json_encode(['success' => true, 'payroll' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);

        } elseif ($action === 'tutors_summary') {
            $month = $_GET['month'] ?? date('Y-m');
            $stmt  = $db->prepare("
                SELECT
                    t.id, t.full_name, t.hourly_rate,
                    COUNT(s.id)                       AS total_sessions,
                    COALESCE(SUM(s.duration_hours), 0) AS total_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified = 1 THEN s.duration_hours ELSE 0 END), 0) AS verified_hours,
                    COALESCE(SUM(CASE WHEN s.is_verified = 1 THEN s.duration_hours ELSE 0 END) * t.hourly_rate, 0) AS gross_pay
                FROM tutors t
                LEFT JOIN sessions s ON s.tutor_id = t.id
                    AND DATE_FORMAT(s.session_date, '%Y-%m') = ?
                WHERE t.is_active = 1
                GROUP BY t.id, t.full_name, t.hourly_rate
                ORDER BY t.full_name ASC");
            $stmt->execute([$month]);
            echo json_encode(['success' => true, 'tutors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } else {
            throw new Exception('Unknown GET action.');
        }
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST handlers
    // ══════════════════════════════════════════════════════════════════════
    if (empty($body)) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? ($_POST['action'] ?? $action);
    }

    if ($action === 'log') {
        $tid   = intval($body['tutor_id'] ?? 0);
        if (!$tid) {
            $tid = $resolveTutorId();
        }
        $eid   = intval($body['enrollment_id'] ?? 0);
        $sName = trim($body['student_name'] ?? ($body['student'] ?? ''));
        $date  = trim($body['session_date'] ?? ($body['date'] ?? ''));
        $hrs   = floatval($body['duration_hours'] ?? ($body['hours'] ?? 1));
        $notes = trim($body['notes'] ?? '');

        if (!$tid) throw new Exception('Tutor ID required.');
        if (!$date) throw new Exception('Session date required.');
        if ($hrs <= 0) throw new Exception('Duration hours must be greater than 0.');

        // If enrollment_id is not given but student_name is provided, attempt to lookup
        if (!$eid && $sName) {
            $eStmt = $db->query("SELECT id, child_name FROM enrollments ORDER BY id DESC LIMIT 100");
            while ($erow = $eStmt->fetch(PDO::FETCH_ASSOC)) {
                $dec = decryptRow($erow, ['child_name']);
                if (strcasecmp(trim($dec['child_name'] ?? ''), $sName) === 0) {
                    $eid = (int)$erow['id'];
                    break;
                }
            }
        }

        $stmt = $db->prepare("INSERT INTO sessions (enrollment_id, student_name, tutor_id, session_date, duration_hours, notes) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$eid, $sName, $tid, $date, $hrs, $notes]);
        $newId = $db->lastInsertId();

        echo json_encode(['success' => true, 'message' => 'Session logged.', 'id' => $newId]);

    } elseif ($action === 'verify') {
        if ($role !== 'admin') throw new Exception('Only admin can verify sessions.');
        $sid = intval($body['session_id'] ?? 0);
        if (!$sid) throw new Exception('session_id required.');
        $adminName = $_SESSION['display_name'] ?? 'Head Admin';
        $db->prepare("UPDATE sessions SET is_verified=1, verified_by=?, verified_at=NOW() WHERE id=?")
           ->execute([$adminName, $sid]);
        echo json_encode(['success' => true, 'message' => 'Session verified.']);

    } elseif ($action === 'delete') {
        $sid = intval($body['session_id'] ?? 0);
        if (!$sid) throw new Exception('session_id required.');
        // Allow admin or the tutor who owns it
        $isHeadAdmin = !empty($_SESSION['is_head_admin']);
        $tutorId = $resolveTutorId();
        if ($isHeadAdmin) {
            $db->prepare("DELETE FROM sessions WHERE id=?")->execute([$sid]);
        } else if ($tutorId) {
            $db->prepare("DELETE FROM sessions WHERE id=? AND tutor_id=?")->execute([$sid, $tutorId]);
        } else {
            throw new Exception('Permission denied.');
        }
        echo json_encode(['success' => true, 'message' => 'Session deleted.']);

    } else {
        throw new Exception('Unknown POST action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
