<?php
require __DIR__ . '/tutor_schedule_test.php';
same(scheduleHighlightIsRecent('2026-11-15 09:00:01','2026-11-18 09:00:00'),true,'Green just before 72 hours');
same(scheduleHighlightIsRecent('2026-11-15 09:00:00','2026-11-18 09:00:00'),false,'Blue at 72 hours');
same(scheduleHighlightIsRecent(null,'2026-11-18 09:00:00'),false,'No history is blue');
$db->exec("UPDATE enrollment_schedule_history SET changed_at=datetime(CURRENT_TIMESTAMP,'-4 days')");
foreach (parentScheduleRows($db,10) as $r) same(in_array(true,$r['updated_days'],true),false,'Expired parent highlights cleared');
$rows=addTutorScheduleHighlights($db,[['tutor_id'=>10,'timeslot'=>'8:00 AM - 9:00 AM','mon'=>'Tiya','updated_days'=>['mon'=>true]]],[$student]);
same($rows[0]['updated_days'],[],'Expired tutor highlights cleared');
same(count(tutorScheduleNotifications($db,10)),1,'Expiry does not remove notifications');
echo "PASS: three-day boundary and expired parent/tutor highlights; notifications retained.\n";
