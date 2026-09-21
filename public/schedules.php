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

require_once __DIR__ . '/config.php';
setSecurityHeaders();
startPortalSession();

$role = $_SESSION['role'] ?? '';
if (!$role) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

/**
 * Format single time token to 12-hour format (e.g. "13:00" -> "1:00 PM", "9:00" -> "9:00 AM")
 */
function formatSingleTime12Hour($t) {
    $t = trim($t);
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $t, $m)) {
        $h = (int)$m[1];
        $min = !empty($m[2]) ? $m[2] : '00';
        $ampm = strtoupper($m[3]);
        return "{$h}:{$min} {$ampm}";
    }
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $t, $m)) {
        $h = (int)$m[1];
        $min = !empty($m[2]) ? $m[2] : '00';
        $ampm = 'AM';
        if ($h >= 12) {
            $ampm = 'PM';
            if ($h > 12) $h -= 12;
        } elseif ($h === 0) {
            $h = 12;
        } elseif ($h >= 1 && $h <= 6) {
            // Standard afternoon tutorial center hours
            $ampm = 'PM';
        }
        return "{$h}:{$min} {$ampm}";
    }
    return $t;
}

/**
 * Converts timeslot strings (including military time) to 12-hour AM/PM format
 */
function formatTimeslot12Hour($raw) {
    if (!$raw) return '';
    $raw = trim($raw);
    $parts = preg_split('/\s*(?:-|–|—|to)\s*/i', $raw);
    if (count($parts) === 2) {
        return formatSingleTime12Hour($parts[0]) . ' - ' . formatSingleTime12Hour($parts[1]);
    }
    return formatSingleTime12Hour($raw);
}

/**
 * Helper: parse student timeslot preference into:
 * 1. Active days of week [mon, tue, wed, thu, fri, sat, sun] (boolean array)
 * 2. 12-hour formatted time string (e.g. "11:00 AM - 12:00 PM", "1:00 PM - 2:00 PM")
 */
function parseScheduleTimePreference($rawTimeslot, $program = '', $package = '') {
    $raw = trim($rawTimeslot ?? '');
    $prog = trim($program ?? '');
    $pkg = trim($package ?? '');
    
    $tUpper = strtoupper($raw);
    
    $isM = (strpos($tUpper, 'MON') !== false || strpos($tUpper, 'MWF') !== false);
    $isT = (strpos($tUpper, 'TUE') !== false || strpos($tUpper, 'TTH') !== false || strpos($tUpper, 'TTHS') !== false);
    $isW = (strpos($tUpper, 'WED') !== false || strpos($tUpper, 'MWF') !== false);
    $isTh = (strpos($tUpper, 'THU') !== false || strpos($tUpper, 'TTH') !== false || strpos($tUpper, 'TTHS') !== false);
    $isF = (strpos($tUpper, 'FRI') !== false || strpos($tUpper, 'MWF') !== false);
    $isSa = (strpos($tUpper, 'SAT') !== false || strpos($tUpper, 'TTHS') !== false);
    $isSu = (strpos($tUpper, 'SUN') !== false);

    if (preg_match('/MON(?:DAY)?\s*(?:-|TO)\s*FRI(?:DAY)?/i', $raw)) {
        $isM = $isT = $isW = $isTh = $isF = true;
    }

    if (!$isM && !$isT && !$isW && !$isTh && !$isF && !$isSa && !$isSu) {
        if (stripos($prog, 'Weekend') !== false || stripos($pkg, 'Weekend') !== false || stripos($prog, 'Saturday') !== false) {
            $isSa = true;
        } else {
            $isM = $isT = $isW = $isTh = $isF = true;
        }
    }

    $cleanTime = '';
    if (preg_match('/(\d{1,2}(?::\d{2})?\s*(?:am|pm)?\s*(?:-|–|—|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $raw, $m)) {
        $cleanTime = formatTimeslot12Hour($m[1]);
    } elseif (preg_match('/(\d{1,2}:\d{2}\s*(?:am|pm)?)/i', $raw, $m)) {
        $cleanTime = formatTimeslot12Hour($m[1]);
    }

    if (!$cleanTime) {
        $cleanTime = ($isSa || $isSu) ? '9:00 AM - 10:00 AM' : '8:00 AM - 9:00 AM';
    }

    return [
        'days' => [
            'mon' => $isM,
            'tue' => $isT,
            'wed' => $isW,
            'thu' => $isTh,
            'fri' => $isF,
            'sat' => $isSa,
            'sun' => $isSu,
        ],
        'timeslot' => $cleanTime
    ];
}

function normalizeTimeSlotKey($ts) {
    $ts = trim((string)$ts);
    $parts = preg_split('/\s*(?:-|–|—|to)\s*/i', $ts);
    $normParts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i', $p, $pm)) {
            $h = (int)$pm[1];
            $m = !empty($pm[2]) ? $pm[2] : '00';
            $ampm = !empty($pm[3]) ? strtoupper($pm[3]) : '';
            if ($ampm === 'PM' && $h < 12) $h += 12;
            if ($ampm === 'AM' && $h == 12) $h = 0;
            if ($ampm === '' && $h >= 1 && $h <= 6) $h += 12;
            $normParts[] = sprintf('%02d:%s', $h, $m);
        } else {
            $normParts[] = $p;
        }
    }
    return implode('-', $normParts);
}

function mergeSchedulesAndAssignments($manualSchedules, $assignedStudents, $tutorMap = []) {
    $days = ['mon','tue','wed','thu','fri','sat','sun'];

    $tutorRows = [];
    foreach ($manualSchedules as $ms) {
        $tId = (int)$ms['tutor_id'];
        if (!isset($tutorRows[$tId])) {
            $tutorRows[$tId] = [];
        }
        $ms['timeslot'] = formatTimeslot12Hour($ms['timeslot']);
        
        foreach ($days as $d) {
            if (preg_match('/^\s*\d{1,2}(:\d{2})?\s*(-|to)\s*\d{1,2}(:\d{2})?\s*(am|pm)?\s*$/i', trim($ms[$d] ?? ''))) {
                $ms[$d] = '';
            }
        }
        $tutorRows[$tId][] = $ms;
    }

    foreach ($assignedStudents as $st) {
        $tId = (int)$st['tutor_id'];
        $cName = trim($st['child_name'] ?: 'Student');
        $parsed = parseScheduleTimePreference($st['timeslot'] ?? '', $st['program'] ?? '', $st['package_selected'] ?? '');
        $targetNormKey = normalizeTimeSlotKey($parsed['timeslot']);
        $slotType = (stripos($st['program'] ?? '', 'Home') !== false || stripos($st['package_selected'] ?? '', 'Home') !== false) ? 'HB' : 'CB';
        $tName = $st['tutor_name'] ?? ($tutorMap[$tId] ?? 'Tutor');

        if (!isset($tutorRows[$tId])) {
            $tutorRows[$tId] = [];
        }

        $matchedIndex = -1;
        foreach ($tutorRows[$tId] as $idx => $r) {
            if (normalizeTimeSlotKey($r['timeslot']) === $targetNormKey) {
                $matchedIndex = $idx;
                break;
            }
        }

        if ($matchedIndex !== -1) {
            foreach ($days as $d) {
                if (!empty($parsed['days'][$d])) {
                    $currVal = trim($tutorRows[$tId][$matchedIndex][$d] ?? '');
                    if (!$currVal || $currVal === 'AVAILABLE' || $currVal === 'Available') {
                        $tutorRows[$tId][$matchedIndex][$d] = $cName;
                    } elseif (stripos($currVal, $cName) === false) {
                        $tutorRows[$tId][$matchedIndex][$d] = $currVal . ', ' . $cName;
                    }
                }
            }
            if (!empty($st['package_selected']) && stripos($st['package_selected'], 'Home') !== false) {
                $tutorRows[$tId][$matchedIndex]['slot_type'] = 'HB';
            }
        } else {
            $newRow = [
                'id' => 'assign_' . $st['enrollment_id'],
                'tutor_id' => $tId,
                'tutor_name' => $tName,
                'timeslot' => $parsed['timeslot'], // Digits only!
                'slot_type' => $slotType,
                'mon' => !empty($parsed['days']['mon']) ? $cName : '',
                'tue' => !empty($parsed['days']['tue']) ? $cName : '',
                'wed' => !empty($parsed['days']['wed']) ? $cName : '',
                'thu' => !empty($parsed['days']['thu']) ? $cName : '',
                'fri' => !empty($parsed['days']['fri']) ? $cName : '',
                'sat' => !empty($parsed['days']['sat']) ? $cName : '',
                'sun' => !empty($parsed['days']['sun']) ? $cName : '',
                'sort_order' => 50,
                'is_assignment' => true,
                'enrollment_id' => $st['enrollment_id']
            ];
            $tutorRows[$tId][] = $newRow;
        }
    }

    $result = [];
    foreach ($tutorRows as $tId => $rows) {
        usort($rows, function($a, $b) {
            return strcmp(normalizeTimeSlotKey($a['timeslot']), normalizeTimeSlotKey($b['timeslot']));
        });
        foreach ($rows as $r) {
            $result[] = $r;
        }
    }
    return $result;
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

            $merged = mergeSchedulesAndAssignments($manualSchedules, $assignedStudents, $tutorMap);

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
                        e.guardian_name, e.contact,
                        t.full_name AS tutor_name
                 FROM tutor_assignments ta
                 JOIN enrollments e ON ta.enrollment_id = e.id
                 JOIN tutors t ON ta.tutor_id = t.id
                 WHERE ta.tutor_id = ? OR LOWER(TRIM(t.full_name)) = LOWER(TRIM(?))
                 ORDER BY ta.assigned_at ASC"
            );
            $stmtAssign->execute([$tutorId, $displayName]);
            $rawAssigned = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);

            $assignedStudents = [];
            foreach ($rawAssigned as $r) {
                $decrypted = decryptRow($r, ['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'contact']);
                $assignedStudents[] = $decrypted;
            }

            $tutorMap = [$tutorId => $displayName ?: 'Me'];
            $merged = mergeSchedulesAndAssignments($manualSchedules, $assignedStudents, $tutorMap);

            echo json_encode([
                'success' => true,
                'tutor_id' => $tutorId,
                'schedules' => $merged,
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

        if (is_numeric($idRaw)) {
            $id = intval($idRaw);
            $stmt = $db->prepare("UPDATE tutor_schedules SET `$day`=? WHERE id=?");
            $stmt->execute([$val, $id]);
        } else if (strpos($idRaw, 'assign_') === 0) {
            $eid = intval(substr($idRaw, 7));
            $aStmt = $db->prepare("SELECT ta.tutor_id, e.timeslot, e.program, e.package_selected FROM tutor_assignments ta JOIN enrollments e ON ta.enrollment_id = e.id WHERE ta.enrollment_id = ?");
            $aStmt->execute([$eid]);
            $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
            if ($aRow) {
                $p = parseScheduleTimePreference($aRow['timeslot'], $aRow['program'], $aRow['package_selected']);
                $ins = $db->prepare("INSERT INTO tutor_schedules (tutor_id, timeslot, slot_type, `$day`, sort_order) VALUES (?, ?, 'CB', ?, 50)");
                $ins->execute([$aRow['tutor_id'], $p['timeslot'], $val]);
            }
        }
        echo json_encode(['success'=>true,'message'=>'Cell updated.']);

    } elseif ($action === 'update_timeslot' || $action === 'update_row') {
        $idRaw     = trim((string)($body['id'] ?? ''));
        $timeslot  = trim($body['timeslot']  ?? '');
        $slot_type = strtoupper(trim($body['slot_type'] ?? ''));
        if (!$idRaw || !$timeslot) throw new Exception('Row ID and timeslot required.');

        // Format timeslot to 12-hour format
        $timeslot = formatTimeslot12Hour($timeslot) ?: $timeslot;

        if (is_numeric($idRaw)) {
            $id = intval($idRaw);
            if ($slot_type) {
                $stmt = $db->prepare("UPDATE tutor_schedules SET timeslot=?, slot_type=? WHERE id=?");
                $stmt->execute([$timeslot, $slot_type, $id]);
            } else {
                $stmt = $db->prepare("UPDATE tutor_schedules SET timeslot=? WHERE id=?");
                $stmt->execute([$timeslot, $id]);
            }
        } else if (strpos($idRaw, 'assign_') === 0) {
            $eid = intval(substr($idRaw, 7));
            $db->prepare("UPDATE enrollments SET timeslot=? WHERE id=?")->execute([$timeslot, $eid]);
        }
        echo json_encode(['success'=>true,'message'=>'Timeslot updated.']);

    } elseif ($action === 'delete') {
        $idRaw = trim((string)($body['id'] ?? ''));
        if (!$idRaw) throw new Exception('Row ID required.');

        if (is_numeric($idRaw)) {
            $id = intval($idRaw);
            $rStmt = $db->prepare("SELECT tutor_id, timeslot FROM tutor_schedules WHERE id=?");
            $rStmt->execute([$id]);
            $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);

            $db->prepare("DELETE FROM tutor_schedules WHERE id=?")->execute([$id]);

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
