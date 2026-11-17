<?php
require_once __DIR__ . '/../public/includes/parent_schedules.php';
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE enrollment_schedule_history (id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_id INTEGER, program TEXT, previous_timeslot TEXT, new_timeslot TEXT)');
recordScheduleChange($db,1,'Tutorial','Monday 8:00 AM - 9:00 AM','Unscheduled');
recordScheduleChange($db,2,'Tutorial','Friday 1:00 PM - 2:00 PM','Unscheduled');
recordScheduleChange($db,1,'Tutorial','Unscheduled','Tuesday 10:00 AM - 11:00 AM');
recordScheduleChange($db,3,'Tutorial','','Wednesday 8:00 AM - 9:00 AM');
$rows=scheduleNotificationPreviousSlots($db,$db->query('SELECT * FROM enrollment_schedule_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
if($rows[2]['previous_timeslot']!=='Monday 8:00 AM - 9:00 AM') throw new Exception('Move lost previous slot');
if($rows[3]['previous_timeslot']!=='') throw new Exception('Invented first assignment history');
if($rows[0]['new_timeslot']!=='Unscheduled') throw new Exception('Removal changed');
if($db->query('SELECT previous_timeslot FROM enrollment_schedule_history WHERE id=3')->fetchColumn()!=='Unscheduled') throw new Exception('Audit changed');
echo "PASS: prior slot recovered across cell move, enrollment isolation, first assignment and original audit preserved.\n";
