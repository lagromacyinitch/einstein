<?php
/**
 * schedules.php — Tutor Schedule of Bookings API
 *
 * Table: tutor_schedules
 *   id, tutor_id, timeslot, slot_type (CB/HB), mon, tue, wed, thu, fri, sat, sun, sort_order
 *
 * GET  ?action=list_all    Admin/Staff — all rows joined with tutor names
 * GET  ?action=list_mine   Tutor — only their rows
 * GET  ?action=list_tutors Admin — active tutor list for dropdown
 * POST action=create       Head Admin — insert new row
 * POST action=update_cell  Head Admin — update single day cell
 * POST action=update_row   Head Admin — update timeslot/type for a row
 * POST action=delete       Head Admin — delete a row
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

    // ── Ensure table exists ────────────────────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS tutor_schedules (
      id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      tutor_id   INT UNSIGNED NOT NULL,
      timeslot   VARCHAR(50)  NOT NULL DEFAULT '',
      slot_type  VARCHAR(10)  NOT NULL DEFAULT 'CB',
      mon        VARCHAR(255) NOT NULL DEFAULT '',
      tue        VARCHAR(255) NOT NULL DEFAULT '',
      wed        VARCHAR(255) NOT NULL DEFAULT '',
      thu        VARCHAR(255) NOT NULL DEFAULT '',
      fri        VARCHAR(255) NOT NULL DEFAULT '',
      sat        VARCHAR(255) NOT NULL DEFAULT '',
      sun        VARCHAR(255) NOT NULL DEFAULT '',
      sort_order INT          NOT NULL DEFAULT 0,
      created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_tutor (tutor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ── Migrate old schema if it exists (drop old columns, add new) ───────
    // Silently ignore errors if columns already exist
    $cols = [];
    $stmt = $db->query("SHOW COLUMNS FROM tutor_schedules");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $cols[] = $col['Field'];
    }
    $newCols = ['timeslot','slot_type','mon','tue','wed','thu','fri','sat','sun','sort_order'];
    foreach ($newCols as $c) {
        if (!in_array($c, $cols)) {
            $type = ($c === 'sort_order') ? 'INT NOT NULL DEFAULT 0' : 'VARCHAR(255) NOT NULL DEFAULT \'\'';
            if ($c === 'slot_type') $type = "VARCHAR(10) NOT NULL DEFAULT 'CB'";
            if ($c === 'timeslot') $type = "VARCHAR(50) NOT NULL DEFAULT ''";
            $db->exec("ALTER TABLE tutor_schedules ADD COLUMN $c $type");
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    if (!$action) {
        $body_raw = file_get_contents('php://input');
        $body = json_decode($body_raw, true) ?? [];
        $action = $body['action'] ?? ($_POST['action'] ?? '');
    } else {
        $body = [];
    }

    // ══════════════════════════════════════════════════════════════════════
    // GET handlers
    // ══════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        if ($action === 'list_all') {
            if ($role !== 'admin' && $role !== 'staff') {
                http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
            }
            $stmt = $db->query(
                "SELECT s.*, t.full_name AS tutor_name
                 FROM tutor_schedules s
                 JOIN tutors t ON s.tutor_id = t.id
                 WHERE t.is_active = 1
                 ORDER BY t.full_name ASC, s.sort_order ASC, s.id ASC"
            );
            echo json_encode(['success'=>true,'schedules'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif ($action === 'list_mine') {
            $tutorId = $_SESSION['tutor_id'] ?? null;
            $displayName = $_SESSION['display_name'] ?? '';
            
            // If tutor_id not in session, lookup by display_name in tutors table
            if (!$tutorId && $displayName) {
                $tLookup = $db->prepare("SELECT id FROM tutors WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
                $tLookup->execute([$displayName]);
                $tRow = $tLookup->fetch(PDO::FETCH_ASSOC);
                if ($tRow) {
                    $tutorId = (int)$tRow['id'];
                    $_SESSION['tutor_id'] = $tutorId;
                }
            }

            // If still not found and user is logged in as admin/staff, auto-create tutor profile
            if (!$tutorId && ($role === 'admin' || $role === 'staff') && $displayName) {
                $insTutor = $db->prepare("INSERT INTO tutors (full_name, is_active) VALUES (?, 1)");
                $insTutor->execute([$displayName]);
                $tutorId = (int)$db->lastInsertId();
                $_SESSION['tutor_id'] = $tutorId;
            }

            if (!$tutorId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Tutor account not identified.']);
                exit;
            }

            // 1. Fetch manual timetable slots for this tutor
            $stmtSched = $db->prepare(
                "SELECT s.*, t.full_name AS tutor_name
                 FROM tutor_schedules s
                 JOIN tutors t ON s.tutor_id = t.id
                 WHERE s.tutor_id = ?
                 ORDER BY s.sort_order ASC, s.id ASC"
            );
            $stmtSched->execute([$tutorId]);
            $manualSchedules = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch real assigned enrollments from tutor_assignments
            $stmtAssign = $db->prepare(
                "SELECT ta.id AS assignment_id, ta.assigned_at, ta.notes AS assign_notes,
                        e.id AS enrollment_id, e.reference_no, e.program, e.package_selected,
                        e.start_date, e.timeslot, e.status, e.created_at,
                        e.child_name, e.child_age, e.child_grade, e.child_school,
                        e.guardian_name, e.contact
                 FROM tutor_assignments ta
                 JOIN enrollments e ON ta.enrollment_id = e.id
                 JOIN tutors t ON ta.tutor_id = t.id
                 WHERE ta.tutor_id = ? OR LOWER(TRIM(t.full_name)) = LOWER(TRIM(?))
                 ORDER BY ta.assigned_at DESC"
            );
            $stmtAssign->execute([$tutorId, $displayName]);
            $rawAssigned = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);

            $assignedStudents = [];
            foreach ($rawAssigned as $r) {
                $decrypted = decryptRow($r, ['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'contact']);
                $assignedStudents[] = $decrypted;
            }

            // 3. Build unified schedule rows
            $compositeSchedules = $manualSchedules;

            // Map assigned enrollments into schedule slots
            foreach ($assignedStudents as $st) {
                $time = trim($st['timeslot'] ?? '');
                if (!$time) $time = 'Assigned Session';
                $cName = $st['child_name'] ?: 'Student';
                
                // Parse day patterns if present in timeslot string (e.g. MWF, TTH, Saturday, Mon, Tue, etc.)
                $tUpper = strtoupper($time);
                $isM = (strpos($tUpper, 'MON') !== false || strpos($tUpper, 'MWF') !== false);
                $isT = (strpos($tUpper, 'TUE') !== false || strpos($tUpper, 'TTH') !== false);
                $isW = (strpos($tUpper, 'WED') !== false || strpos($tUpper, 'MWF') !== false);
                $isTh = (strpos($tUpper, 'THU') !== false || strpos($tUpper, 'TTH') !== false);
                $isF = (strpos($tUpper, 'FRI') !== false || strpos($tUpper, 'MWF') !== false);
                $isSa = (strpos($tUpper, 'SAT') !== false);
                $isSu = (strpos($tUpper, 'SUN') !== false);

                // If no specific days mentioned, default to weekdays
                if (!$isM && !$isT && !$isW && !$isTh && !$isF && !$isSa && !$isSu) {
                    $isM = $isT = $isW = $isTh = $isF = true;
                }

                $compositeSchedules[] = [
                    'id' => 'assign_' . $st['enrollment_id'],
                    'tutor_id' => $tutorId,
                    'timeslot' => $time,
                    'slot_type' => (stripos($st['program'], 'Home') !== false || stripos($st['package_selected'], 'Home') !== false) ? 'HB' : 'CB',
                    'mon' => $isM ? $cName : '',
                    'tue' => $isT ? $cName : '',
                    'wed' => $isW ? $cName : '',
                    'thu' => $isTh ? $cName : '',
                    'fri' => $isF ? $cName : '',
                    'sat' => $isSa ? $cName : '',
                    'sun' => $isSu ? $cName : '',
                    'sort_order' => 99,
                    'is_assignment' => true,
                    'enrollment_id' => $st['enrollment_id']
                ];
            }

            echo json_encode([
                'success' => true,
                'tutor_id' => $tutorId,
                'schedules' => $compositeSchedules,
                'assigned_students' => $assignedStudents
            ]);

        } elseif ($action === 'list_tutors') {
            $stmt = $db->query("SELECT id, full_name FROM tutors WHERE is_active = 1 ORDER BY full_name ASC");
            echo json_encode(['success'=>true,'tutors'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } else {
            throw new Exception('Unknown GET action.');
        }
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST handlers — require admin/staff session
    // ══════════════════════════════════════════════════════════════════════
    if ($role !== 'admin' && $role !== 'staff') {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }

    $isHeadAdmin = !empty($_SESSION['is_head_admin']);
    if (!$isHeadAdmin) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Only the Head Admin may modify schedules.']);
        exit;
    }

    if (empty($body)) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? ($_POST['action'] ?? $action);
    }

    $actorName = $_SESSION['display_name'] ?? 'Head Admin';
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0];

    if ($action === 'create') {
        $tutor_id  = intval($body['tutor_id'] ?? 0);
        $timeslot  = trim($body['timeslot']  ?? '');
        $slot_type = strtoupper(trim($body['slot_type'] ?? 'CB'));
        if (!$tutor_id || !$timeslot) throw new Exception('Tutor and timeslot are required.');

        // Get max sort_order for this tutor
        $maxSort = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM tutor_schedules WHERE tutor_id=?");
        $maxSort->execute([$tutor_id]);
        $sortVal = $maxSort->fetchColumn();

        $stmt = $db->prepare(
            "INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,mon,tue,wed,thu,fri,sat,sun,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $days = ['mon','tue','wed','thu','fri','sat','sun'];
        $vals = [$tutor_id, $timeslot, $slot_type];
        foreach ($days as $d) $vals[] = trim($body[$d] ?? '');
        $vals[] = $sortVal;
        $stmt->execute($vals);
        $newId = $db->lastInsertId();

        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name,actor_role,action_type,description,ip_address) VALUES (?,'Head Admin','CREATE_SCHEDULE',?,?)");
        $logStmt->execute([$actorName,"Created schedule row: $timeslot for tutor ID $tutor_id", $ip]);

        echo json_encode(['success'=>true,'message'=>'Schedule row created.','id'=>$newId]);

    } elseif ($action === 'update_cell') {
        $id  = intval($body['id']  ?? 0);
        $day = strtolower(trim($body['day'] ?? ''));
        $val = trim($body['value'] ?? '');
        $allowed = ['mon','tue','wed','thu','fri','sat','sun'];
        if (!$id || !in_array($day,$allowed)) throw new Exception('Invalid row ID or day.');

        $stmt = $db->prepare("UPDATE tutor_schedules SET `$day`=? WHERE id=?");
        $stmt->execute([$val, $id]);
        echo json_encode(['success'=>true,'message'=>'Cell updated.']);

    } elseif ($action === 'update_row') {
        $id        = intval($body['id'] ?? 0);
        $timeslot  = trim($body['timeslot']  ?? '');
        $slot_type = strtoupper(trim($body['slot_type'] ?? 'CB'));
        if (!$id || !$timeslot) throw new Exception('Row ID and timeslot required.');

        $stmt = $db->prepare("UPDATE tutor_schedules SET timeslot=?, slot_type=? WHERE id=?");
        $stmt->execute([$timeslot, $slot_type, $id]);
        echo json_encode(['success'=>true,'message'=>'Row updated.']);

    } elseif ($action === 'delete') {
        $id = intval($body['id'] ?? 0);
        if (!$id) throw new Exception('Row ID required.');
        $db->prepare("DELETE FROM tutor_schedules WHERE id=?")->execute([$id]);

        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name,actor_role,action_type,description,ip_address) VALUES (?,'Head Admin','DELETE_SCHEDULE',?,?)");
        $logStmt->execute([$actorName,"Deleted schedule row ID $id", $ip]);

        echo json_encode(['success'=>true,'message'=>'Row deleted.']);

    } else {
        throw new Exception('Unknown POST action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
