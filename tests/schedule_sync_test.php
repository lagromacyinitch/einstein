<?php
require __DIR__ . '/../public/includes/schedule_sync.php';
// Load only the pure scheduling functions, without running the HTTP endpoint.
$source = file_get_contents(__DIR__ . '/../public/api/schedules.php');
$start = strpos($source, 'function formatSingleTime12Hour');
$end = strpos($source, "\ntry {", $start);
eval(substr($source, $start, $end - $start));
function decryptRow($row, $fields) { return $row; }
class ScheduleTestDB extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function same($actual, $expected, $label) {
    if ($actual !== $expected) throw new Exception($label . ': ' . var_export($actual, true));
}
$db = new ScheduleTestDB();
$db->exec('CREATE TABLE tutors (id INTEGER PRIMARY KEY); INSERT INTO tutors VALUES (1), (2);
CREATE TABLE enrollments (id INTEGER PRIMARY KEY, child_name TEXT, timeslot TEXT, program TEXT, package_selected TEXT);
CREATE TABLE tutor_assignments (enrollment_id INTEGER, tutor_id INTEGER);
CREATE TABLE tutor_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, tutor_id INTEGER, timeslot TEXT, slot_type TEXT,
mon TEXT DEFAULT "", tue TEXT DEFAULT "", wed TEXT DEFAULT "", thu TEXT DEFAULT "", fri TEXT DEFAULT "", sat TEXT DEFAULT "", sun TEXT DEFAULT "", sort_order INTEGER DEFAULT 0);
INSERT INTO enrollments VALUES (1,"Ethan","Monday, Thursday 11:00 AM - 12:00 PM","Academic Tutorial","Regular"),
(2,"Ethan Jr","Monday 11:00 AM - 12:00 PM","Childcare","Daily"),
(3,"Ethan","Friday 2:00 PM - 3:00 PM","PlaySchool","Class");
INSERT INTO tutor_assignments VALUES (1,1), (2,1), (3,2);');
$db->exec('ALTER TABLE enrollments ADD COLUMN status TEXT DEFAULT "confirmed";
ALTER TABLE enrollments ADD COLUMN notes TEXT;
CREATE TABLE student_archives (enrollment_id INTEGER PRIMARY KEY, archived_at TEXT);');
function slot($db, $id) { return $db->query('SELECT timeslot FROM enrollments WHERE id=' . (int)$id)->fetchColumn(); }
function grid($db) {
    $students = $db->query('SELECT e.*, e.id AS enrollment_id, ta.tutor_id FROM enrollments e JOIN tutor_assignments ta ON ta.enrollment_id=e.id')->fetchAll(PDO::FETCH_ASSOC);
    $roster = activeScheduleStudents($db);
    $students = array_values(array_filter($students, fn($s) => isset($roster[$s['enrollment_id']])));
    return mergeSchedulesAndAssignments(filterScheduleRoster($db->query('SELECT * FROM tutor_schedules')->fetchAll(PDO::FETCH_ASSOC), $roster), $students);
}
$id = saveScheduleAndEnrollmentTimeslots($db, 'assign_1', ['mon'=>'Ethan Jr']);
same(slot($db,1), 'Thursday 11:00 AM - 12:00 PM', 'Remove Monday, retain Thursday');
saveScheduleAndEnrollmentTimeslots($db, (string)$id, ['tue'=>'Ethan']);
same(slot($db,1), 'Tuesday, Thursday 11:00 AM - 12:00 PM', 'Move to Tuesday');
same(slot($db,2), 'Monday 11:00 AM - 12:00 PM', 'Do not match name prefixes');
same(slot($db,3), 'Friday 2:00 PM - 3:00 PM', 'Do not change another tutor student');
$reloaded = array_values(array_filter(grid($db), fn($r) => (string)$r['id'] === (string)$id))[0];
same($reloaded['mon'], 'Ethan Jr', 'Refresh does not restore Ethan on Monday');
same($reloaded['tue'], 'Ethan', 'Refresh retains Tuesday');
saveScheduleAndEnrollmentTimeslots($db, (string)$id, ['timeslot'=>'1:00 PM - 2:00 PM']);
same(slot($db,1), 'Tuesday, Thursday 1:00 PM - 2:00 PM', 'Changing time preserves days');
saveScheduleAndEnrollmentTimeslots($db, (string)$id, ['thu'=>'']);
saveScheduleAndEnrollmentTimeslots($db, (string)$id, ['tue'=>'']);
same(slot($db,1), 'Unscheduled', 'Last day removal');
foreach (grid($db) as $r) {
    if ($r['tutor_id'] != 1) continue;
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) {
        same(in_array('Ethan', scheduleCellNames($r[$day] ?? '')), false, 'Unscheduled student stays removed');
    }
}
saveScheduleAndEnrollmentTimeslots($db, (string)$id, ['wed'=>'Ethan, Ethan Jr']);
same(slot($db,1), 'Wednesday 1:00 PM - 2:00 PM', 'Reassign cleared student');
same(slot($db,2), 'Monday, Wednesday 1:00 PM - 2:00 PM', 'Shared slot retains each student days');
$db->exec('INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,fri) VALUES (1,"3:00 PM - 4:00 PM","CB","")');
$second = $db->lastInsertId();
saveScheduleAndEnrollmentTimeslots($db, $second, ['fri'=>'Ethan']);
same(slot($db,1), 'Wednesday 1:00 PM - 2:00 PM; Friday 3:00 PM - 4:00 PM', 'Multiple time ranges');
$db->exec('INSERT INTO enrollments (id,child_name,timeslot,program,package_selected) VALUES (4,"Ethan","Wednesday 1:00 PM - 2:00 PM","Childcare","Daily"); INSERT INTO tutor_assignments VALUES (4,1)');
try {
    saveScheduleAndEnrollmentTimeslots($db, $second, ['fri'=>'']);
    throw new Exception('Expected duplicate-name rejection');
} catch (Exception $e) {
    if (!str_contains($e->getMessage(), 'Multiple enrollments')) throw $e;
}
same($db->query('SELECT fri FROM tutor_schedules WHERE id=' . (int)$second)->fetchColumn(), 'Ethan', 'Failed edit rolls back');
$db->exec('INSERT INTO tutors VALUES (3);
INSERT INTO enrollments (id,child_name,timeslot,program,package_selected) VALUES (5,"Sassa","Monday - Friday 8:00 AM - 9:00 AM","PlaySchool","Class"), (6,"Another Child","","Childcare","Daily");
INSERT INTO tutor_assignments VALUES (5,3);
INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,mon,tue,wed,thu,fri) VALUES (3,"8:00 AM - 9:00 AM","CB","Sassa","Sassa","Sassa","Sassa","Sassa");');
$sassaRow = $db->lastInsertId();
$db->exec('INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,fri) VALUES (3,"08:00-09:00","CB","Sassa, Another Child")');
$duplicateRow = $db->lastInsertId();
saveScheduleAndEnrollmentTimeslots($db, $sassaRow, ['fri'=>'']);
same(slot($db,5), 'Monday, Tuesday, Wednesday, Thursday 8:00 AM - 9:00 AM', 'Duplicate Friday must not restore Sassa');
same($db->query('SELECT fri FROM tutor_schedules WHERE id=' . (int)$duplicateRow)->fetchColumn(), 'Another Child', 'Preserve other students in duplicate row');
foreach (grid($db) as $r) {
    if ($r['tutor_id'] == 3) same(in_array('Sassa', scheduleCellNames($r['fri'])), false, 'Sassa stays removed after reload');
}
echo "PASS: day moves, reloads, time edits, shared slots, name isolation, clearing/reassigning, multiple ranges, rollback, duplicate Friday removal.\n";
$db->exec('INSERT INTO student_archives VALUES (5,"2026-10-21 12:00:00");
INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,wed,sun) VALUES (3,"9:00 AM - 10:00 AM","CB","Buding Tea, Seth Seth, Sassa, Another Child","Available")');
$staleId = $db->lastInsertId();
foreach (grid($db) as $r) {
    if ($r['tutor_id'] != 3) continue;
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) {
        same(in_array('Sassa', scheduleCellNames($r[$day])), false, 'Archived assignment stays hidden');
    }
    if ($r['id'] == $staleId) {
        same($r['wed'], 'Another Child', 'Removed names disappear while active names remain');
        same($r['sun'], 'Available', 'Keep availability markers');
    }
}
$db->exec('DELETE FROM student_archives WHERE enrollment_id=5');
same(isset(activeScheduleStudents($db)[5]), true, 'Restoring student restores schedule eligibility');
echo "PASS: active roster filtering, stale manual names, archived assignments, availability markers, restore eligibility.\n";
$db->exec('INSERT INTO enrollments (id,child_name,timeslot,program,package_selected) VALUES (7,"Sherl","Monday - Friday 9:00 AM - 10:00 AM","Academic Tutorial","Double Package")');
$hasSherl = function ($rows) {
    foreach ($rows as $r) foreach (['mon','tue','wed','thu','fri'] as $day) {
        if (in_array('Sherl', scheduleCellNames($r[$day] ?? ''))) return true;
    }
    return false;
};
same($hasSherl(grid($db)), false, 'Unassigned child has no tutor schedule');
$db->exec('INSERT INTO tutor_assignments VALUES (7,3)');
$sherlDays = [];
foreach (grid($db) as $r) {
    if ($r['tutor_id'] != 3) continue;
    foreach (['mon','tue','wed','thu','fri'] as $day) {
        if (in_array('Sherl', scheduleCellNames($r[$day] ?? ''))) $sherlDays[$day] = true;
    }
}
same(count($sherlDays), 5, 'Assigning Sherl produces all five saved weekdays');
echo "PASS: newly assigned student appears in the chosen tutor schedule on the saved weekdays.\n";
