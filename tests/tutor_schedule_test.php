<?php
require __DIR__ . '/schedule_sync_test.php';
require_once __DIR__ . '/../public/includes/tutor_schedules.php';
$db->exec('CREATE TABLE tutor_schedule_reads (tutor_id INTEGER, history_id INTEGER, PRIMARY KEY(tutor_id,history_id));
INSERT INTO tutors (id,full_name) VALUES (10,"Tutor Test"), (11,"Other Tutor");
INSERT INTO enrollments (id,child_name,timeslot,program,package_selected,user_id) VALUES (10,"Tiya","Monday 8:00 AM - 9:00 AM","Academic Tutorial","Regular",10);
INSERT INTO tutor_assignments VALUES (10,10);');
setTutorAvailability($db, 10, 'assign_10', 'tue', true);
$row = $db->query('SELECT * FROM tutor_schedules WHERE tutor_id=10')->fetch(PDO::FETCH_ASSOC);
same($row['tue'], 'Available', 'Availability materializes in shared admin timetable');
same(slot($db,10), 'Monday 8:00 AM - 9:00 AM', 'Availability does not change enrolled schedule');
setTutorAvailability($db,10,(string)$row['id'],'tue',false);
same($db->query('SELECT tue FROM tutor_schedules WHERE id='.(int)$row['id'])->fetchColumn(),'','Unmark persisted');
foreach ([[11,'tue'],[10,'mon']] as [$owner,$day]) {
    try { setTutorAvailability($db,$owner,(string)$row['id'],$day,true); throw new Exception('Expected rejection'); }
    catch (RuntimeException $e) { /* Ownership and booked cells must reject. */ }
}
$db->exec('INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,wed) VALUES (10,"8:00 AM - 9:00 AM","CB","N/A")');
try { setTutorAvailability($db,10,(string)$row['id'],'wed',true); throw new Exception('Expected unavailable rejection'); }
catch (RuntimeException $e) {}
recordScheduleChange($db,10,'Academic Tutorial','Monday 7:00 AM - 8:00 AM','Monday 8:00 AM - 9:00 AM');
$notices=tutorScheduleNotifications($db,10);
same(count($notices),1,'Only assigned student notification returned');
same(str_contains($notices[0]['message'],"Admin updated Tiya's schedule for Academic Tutorial"),true,'Clear notification details');
same($notices[0]['previous_schedule'],'Monday 7:00 AM - 8:00 AM','Old schedule retained');
same($notices[0]['updated_schedule'],'Monday 8:00 AM - 9:00 AM','New schedule retained');
same(tutorScheduleNotifications($db,11),[],'Other tutor receives no notice');
$db->prepare('INSERT INTO tutor_schedule_reads VALUES (?,?)')->execute([10,$notices[0]['id']]);
same(tutorScheduleNotifications($db,10)[0]['unread'],0,'Read state persists for tutor');
$student=['enrollment_id'=>10,'tutor_id'=>10,'child_name'=>'Tiya','program'=>'Academic Tutorial','package_selected'=>'Regular'];
$highlight=addTutorScheduleHighlights($db,[['tutor_id'=>10,'timeslot'=>'8:00 AM - 9:00 AM','mon'=>'Tiya']],[$student]);
same($highlight[0]['updated_days']['mon'],true,'Changed class has updated highlight');
echo "PASS: tutor ownership, booked/unavailable protection, shared availability/unmark, notifications/read state and highlights.\n";
