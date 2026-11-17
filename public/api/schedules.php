<?php
/**
 * schedules.php — Tutor Schedule of Bookings API
 *
 * Table: tutor_schedules
 *   id, tutor_id, timeslot, slot_type (CB/HB), mon, tue, wed, thu, fri, sat, sun, sort_order
 *
 * GET  ?action=list_all    Admin/Staff — all rows joined with tutor names and assigned students
 * GET  ?action=list_mine   Tutor — only their rows merged with assigned students
 * GET  ?action=list_tutors Admin — active tutor list for dropdown
 * POST action=create       Head Admin — insert new row (supports tutor_id or tutor_name)
 * POST action=update_cell  Head Admin — update single day cell
 * POST action=update_row   Head Admin — update timeslot/type for a row
 * POST action=delete       Head Admin — delete a row
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schedule_sync.php';
require_once __DIR__ . '/../includes/tutor_schedules.php';
require_once __DIR__ . '/../includes/student_archive_schema.php';
setSecurityHeaders();
startPortalSession();

$role = $_SESSION['role'] ?? '';
if (!$role) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

require_once __DIR__ . '/../includes/schedule_format.php';

try {
    $db = getDB();
    // Schema creation must happen before the schedule-edit transaction.
    ensureScheduleChangeHistory($db);
    ensureStudentArchiveTable($db);

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

    // ── Migrate old schema if it exists ───────────────────────────────────
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
            
            // 1. Fetch manual schedules
            $stmt = $db->query(
                "SELECT s.*, t.full_name AS tutor_name
                 FROM tutor_schedules s
                 JOIN tutors t ON s.tutor_id = t.id
                 WHERE t.is_active = 1
                 ORDER BY t.full_name ASC, s.sort_order ASC, s.id ASC"
            );
            $manualSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch all assigned enrollments
            $stmtAssign = $db->query(
                "SELECT ta.id AS assignment_id, ta.tutor_id, ta.assigned_at, ta.notes AS assign_notes,
                        e.id AS enrollment_id, e.reference_no, e.program, e.package_selected,
                        e.start_date, e.timeslot, e.status, e.created_at,
                        e.child_name, e.child_age, e.child_grade, e.child_school,
                        e.guardian_name, e.contact,
                        t.full_name AS tutor_name
                 FROM tutor_assignments ta
                 JOIN enrollments e ON ta.enrollment_id = e.id
                 JOIN tutors t ON ta.tutor_id = t.id
                 WHERE t.is_active = 1
                 ORDER BY ta.assigned_at ASC"
            );
            $rawAssigned = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);
            $assignedStudents = [];
            foreach ($rawAssigned as $r) {
                $decrypted = decryptRow($r, ['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'contact']);
                $assignedStudents[] = $decrypted;
            }

            // Tutor map
            $tutorsList = $db->query("SELECT id, full_name FROM tutors WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            $tutorMap = [];
            foreach ($tutorsList as $t) {
                $tutorMap[$t['id']] = $t['full_name'];
            }

            $roster = activeScheduleStudents($db);
            $assignedStudents = array_values(array_filter($assignedStudents, fn($student) => isset($roster[(int)$student['enrollment_id']])));
            $merged = mergeSchedulesAndAssignments(filterScheduleRoster($manualSchedules, $roster), $assignedStudents, $tutorMap);

            echo json_encode([
                'success' => true,
                'schedules' => $merged,
                'assigned_students' => $assignedStudents
            ]);

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
                "SELECT ta.id AS assignment_id, ta.tutor_id, ta.assigned_at, ta.notes AS assign_notes,
                        e.id AS enrollment_id, e.reference_no, e.program, e.package_selected,
                        e.start_date, e.timeslot, e.status, e.created_at,
                        e.child_name, e.child_age, e.child_grade, e.child_school,
                        a.archived_at,
                        t.full_name AS tutor_name
                 FROM tutor_assignments ta
                 JOIN enrollments e ON ta.enrollment_id = e.id
                 JOIN tutors t ON ta.tutor_id = t.id
                 LEFT JOIN student_archives a ON a.enrollment_id=e.id
                 WHERE ta.tutor_id = ?
                 ORDER BY ta.assigned_at ASC"
            );
            $stmtAssign->execute([$tutorId]);
            $rawAssigned = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);

            $assignedStudents = [];
            foreach ($rawAssigned as $r) {
                $decrypted = decryptRow($r, ['child_name', 'child_age', 'child_grade', 'child_school']);
                $assignedStudents[] = $decrypted;
            }

            $tutorMap = [$tutorId => $displayName ?: 'Me'];
            $roster = activeScheduleStudents($db);
            $allAssignedStudents = $assignedStudents;
            $assignedStudents = array_values(array_filter($assignedStudents, fn($student) => isset($roster[(int)$student['enrollment_id']])));
            $merged = mergeSchedulesAndAssignments(filterScheduleRoster($manualSchedules, $roster), $assignedStudents, $tutorMap);

            echo json_encode([
                'success' => true,
                'tutor_id' => $tutorId,
                'schedules' => addTutorScheduleHighlights($db, $merged, $assignedStudents),
                'assigned_students' => $allAssignedStudents
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
    if ($action === 'set_availability') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($body['available']) || !is_bool($body['available'])) throw new RuntimeException('Invalid availability request.');
        setTutorAvailability($db, sessionScheduleTutorId($db), (string)($body['id'] ?? ''), (string)($body['day'] ?? ''), $body['available']);
        echo json_encode(['success' => true]);
        exit;
    }
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
        $tutor_id   = intval($body['tutor_id'] ?? 0);
        $tutor_name = trim($body['tutor_name'] ?? '');
        $timeslot   = trim($body['timeslot']  ?? '');
        $slot_type  = strtoupper(trim($body['slot_type'] ?? 'CB'));

        // If tutor_id not supplied, lookup or create by tutor_name
        if (!$tutor_id && $tutor_name) {
            $tFind = $db->prepare("SELECT id FROM tutors WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1");
            $tFind->execute([$tutor_name]);
            $tRow = $tFind->fetch(PDO::FETCH_ASSOC);
            if ($tRow) {
                $tutor_id = (int)$tRow['id'];
            } else {
                $tIns = $db->prepare("INSERT INTO tutors (full_name, is_active) VALUES (?, 1)");
                $tIns->execute([$tutor_name]);
                $tutor_id = (int)$db->lastInsertId();
            }
        }

        if (!$tutor_id || !$timeslot) throw new Exception('Tutor and timeslot are required.');

        // Format timeslot to 12-hour format
        $timeslot = formatTimeslot12Hour($timeslot) ?: $timeslot;

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
        $idRaw = trim((string)($body['id'] ?? ''));
        $day = strtolower(trim($body['day'] ?? ''));
        $val = trim($body['value'] ?? '');
        $allowed = ['mon','tue','wed','thu','fri','sat','sun'];
        if (!$idRaw || !in_array($day,$allowed)) throw new Exception('Invalid row ID or day.');

        $savedId = saveScheduleAndEnrollmentTimeslots($db, $idRaw, [$day => $val]);
        echo json_encode(['success'=>true,'message'=>'Cell updated.', 'id'=>$savedId]);

    } elseif ($action === 'update_timeslot' || $action === 'update_row') {
        $idRaw     = trim((string)($body['id'] ?? ''));
        $timeslot  = trim($body['timeslot']  ?? '');
        $slot_type = strtoupper(trim($body['slot_type'] ?? ''));
        if (!$idRaw || !$timeslot) throw new Exception('Row ID and timeslot required.');

        // Format timeslot to 12-hour format
        $timeslot = formatTimeslot12Hour($timeslot) ?: $timeslot;

        $changes = ['timeslot' => $timeslot];
        if ($slot_type) $changes['slot_type'] = $slot_type;
        $savedId = saveScheduleAndEnrollmentTimeslots($db, $idRaw, $changes);
        echo json_encode(['success'=>true,'message'=>'Timeslot updated.', 'id'=>$savedId]);

    } elseif ($action === 'delete') {
        $idRaw = trim((string)($body['id'] ?? ''));
        if (!$idRaw) throw new Exception('Row ID required.');

        if (is_numeric($idRaw)) {
            $id = intval($idRaw);
            $rStmt = $db->prepare("SELECT tutor_id, timeslot FROM tutor_schedules WHERE id=?");
            $rStmt->execute([$id]);
            $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);

            if ($rRow) {
                $copies = $db->prepare('SELECT id, timeslot FROM tutor_schedules WHERE tutor_id=?');
                $copies->execute([$rRow['tutor_id']]);
                foreach ($copies->fetchAll(PDO::FETCH_ASSOC) as $copy) {
                    if (normalizeTimeSlotKey($copy['timeslot']) === normalizeTimeSlotKey($rRow['timeslot'])) {
                        $db->prepare('DELETE FROM tutor_schedules WHERE id=?')->execute([$copy['id']]);
                    }
                }
            }

            if ($rRow) {
                $tKey = normalizeTimeSlotKey($rRow['timeslot']);
                $aStmt = $db->prepare(
                    "SELECT ta.id, e.timeslot, e.program, e.package_selected 
                     FROM tutor_assignments ta 
                     JOIN enrollments e ON ta.enrollment_id = e.id 
                     WHERE ta.tutor_id = ?"
                );
                $aStmt->execute([$rRow['tutor_id']]);
                foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ta) {
                    $p = parseScheduleTimePreference($ta['timeslot'], $ta['program'], $ta['package_selected']);
                    if (normalizeTimeSlotKey($p['timeslot']) === $tKey) {
                        $db->prepare("DELETE FROM tutor_assignments WHERE id=?")->execute([$ta['id']]);
                    }
                }
            }
        } else if (strpos($idRaw, 'assign_') === 0) {
            $eid = intval(substr($idRaw, 7));
            $db->prepare("DELETE FROM tutor_assignments WHERE enrollment_id=?")->execute([$eid]);
        }

        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name,actor_role,action_type,description,ip_address) VALUES (?,'Head Admin','DELETE_SCHEDULE',?,?)");
        $logStmt->execute([$actorName,"Deleted schedule row ID $idRaw", $ip]);

        echo json_encode(['success'=>true,'message'=>'Row deleted.']);

    } elseif ($action === 'restore') {
        $row = $body['row'] ?? [];
        if (empty($row)) throw new Exception('Row data required to restore.');

        $tutor_id  = intval($row['tutor_id'] ?? 0);
        $timeslot  = trim($row['timeslot'] ?? '');
        $slot_type = strtoupper(trim($row['slot_type'] ?? 'CB'));
        $days      = ['mon','tue','wed','thu','fri','sat','sun'];

        if (!empty($row['is_assignment']) && !empty($row['enrollment_id'])) {
            $eid = intval($row['enrollment_id']);
            $db->prepare("DELETE FROM tutor_assignments WHERE enrollment_id=?")->execute([$eid]);
            $stmt = $db->prepare("INSERT INTO tutor_assignments (enrollment_id, tutor_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$eid, $tutor_id, $actorName]);
        } else {
            if (!$tutor_id || !$timeslot) throw new Exception('Tutor ID and timeslot required to restore.');
            $stmt = $db->prepare(
                "INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,mon,tue,wed,thu,fri,sat,sun,sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $vals = [$tutor_id, $timeslot, $slot_type];
            foreach ($days as $d) $vals[] = trim($row[$d] ?? '');
            $vals[] = intval($row['sort_order'] ?? 50);
            $stmt->execute($vals);
        }

        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name,actor_role,action_type,description,ip_address) VALUES (?,'Head Admin','RESTORE_SCHEDULE',?,?)");
        $logStmt->execute([$actorName,"Restored schedule row: $timeslot for tutor ID $tutor_id", $ip]);

        echo json_encode(['success'=>true,'message'=>'Row restored.']);

    } else {
        throw new Exception('Unknown POST action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
