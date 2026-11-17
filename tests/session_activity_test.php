<?php
require __DIR__ . '/../public/includes/session_logging.php';
function decryptRow($row, $fields) { return $row; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE tutors (id INTEGER PRIMARY KEY, full_name TEXT);
CREATE TABLE enrollments (id INTEGER PRIMARY KEY, child_name TEXT, program TEXT);
CREATE TABLE tutor_assignments (enrollment_id INTEGER, tutor_id INTEGER);
CREATE TABLE sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_id INTEGER, student_name TEXT, tutor_id INTEGER, session_date TEXT, duration_hours REAL, notes TEXT);
CREATE TABLE admin_activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_name TEXT, actor_role TEXT, action_type TEXT, description TEXT, ip_address TEXT);
INSERT INTO tutors VALUES (1,"Teacher Ana"), (2,"Teacher Bea");
INSERT INTO enrollments VALUES (1,"Tiya","Academic Tutorial"),(2,"Tiya","Weekend Workshop");
INSERT INTO tutor_assignments VALUES (1,1),(2,1);');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$id = saveTutorSessionWithActivity($db,1,2,'Forged name',$today,1.5,'Lesson',false,'Forged actor','127.0.0.1');
$log = $db->query('SELECT * FROM admin_activity_log')->fetch(PDO::FETCH_ASSOC);
check($id === 1 && $log['actor_name'] === 'Teacher Ana', 'Canonical tutor identity');
foreach (['Tiya','Weekend Workshop',$today,'1.5','Status: Logged'] as $text) check(str_contains($log['description'],$text),'Activity details: '.$text);
check($log['action_type'] === 'LOG_SESSION','Filterable session activity');
foreach (['-1 day', '+1 day'] as $offset) {
    $otherDate = (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))->modify($offset)->format('Y-m-d');
    try {
        saveTutorSessionWithActivity($db,1,1,'',$otherDate,1,'',false,'','');
        throw new Exception('Expected today-only rejection');
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'today'), 'Clear date validation');
    }
}
try { saveTutorSessionWithActivity($db,2,1,'',$today,1,'',false,'',''); throw new Exception('Expected assignment rejection'); } catch (RuntimeException $expected) {}
try { saveTutorSessionWithActivity($db,1,0,'Tiya',$today,1,'',false,'',''); throw new Exception('Expected ambiguous name rejection'); } catch (RuntimeException $expected) {}
check((int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 1, 'Rejected request creates no session');
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON admin_activity_log BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
try { saveTutorSessionWithActivity($db,1,1,'',$today,1,'',false,'',''); throw new Exception('Expected audit failure'); } catch (PDOException $expected) {}
check((int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 1, 'Audit failure rolls back session');
echo "PASS: session activity details, correct tutor/student/program, assignment checks, ambiguous-name protection and atomic save.\n";
