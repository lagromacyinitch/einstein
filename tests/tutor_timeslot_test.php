<?php
require __DIR__ . '/tutor_schedule_test.php';
$db->exec('INSERT INTO tutors (id,full_name) VALUES (30,"Custom Slots"), (31,"Other Owner")');
function rejectsSlot(callable $operation, string $label): void {
    try { $operation(); } catch (RuntimeException $error) { return; }
    throw new Exception($label);
}
$id = createScheduleRow($db, 30, '7:00 AM - 8:00 AM', 'CB');
foreach (['mon', 'tue', 'wed'] as $day) setTutorAvailability($db, 30, (string)$id, $day, true);
$rows = array_values(array_filter(grid($db), fn($row) => $row['tutor_id'] == 30));
same(count($rows), 1, 'Custom row appears in shared grid');
same($rows[0]['wed'], 'Available', 'Availability synced to shared grid');
rejectsSlot(fn() => createScheduleRow($db,30,'07:00-08:00','CB'), 'Normalized duplicate must reject');
$hb = createScheduleRow($db,30,'7:00 AM - 8:00 AM','HB');
same(count(array_filter(grid($db), fn($row) => $row['tutor_id'] == 30)), 2, 'CB and HB remain separate');
setTutorAvailability($db,30,(string)$hb,'mon',true);
same($db->query('SELECT tue FROM tutor_schedules WHERE id='.$hb)->fetchColumn(), '', 'CB availability does not leak to HB');
rejectsSlot(fn() => saveScheduleAndEnrollmentTimeslots($db,(string)$hb,['slot_type'=>'CB']), 'Admin edit cannot duplicate row');
rejectsSlot(fn() => deleteTutorScheduleRow($db,31,(string)$id), 'Cannot delete another tutor row');
$db->exec("UPDATE tutor_schedules SET fri='Manual Student' WHERE id=$id");
rejectsSlot(fn() => deleteTutorScheduleRow($db,30,(string)$id), 'Manual assigned student prevents deletion');
$db->exec("UPDATE tutor_schedules SET fri='' WHERE id=$id");
deleteTutorScheduleRow($db,30,(string)$id);
same((int)$db->query('SELECT COUNT(*) FROM tutor_schedules WHERE id='.$hb)->fetchColumn(),1,'Deleting CB preserves HB');
foreach (['garbage', '8:90 AM - 9:00 AM', '9:00 AM - 8:00 AM'] as $bad) rejectsSlot(fn() => createScheduleRow($db,30,$bad,'CB'), 'Invalid range must reject');
rejectsSlot(fn() => createScheduleRow($db,30,'9:00 AM - 10:00 AM','XX'), 'Invalid type must reject');
$booked = createScheduleRow($db,30,'10:00 AM - 11:00 AM','CB');
$db->exec('INSERT INTO enrollments (id,child_name,timeslot,program,package_selected) VALUES (30,"Booked Student","Monday 10:00 AM - 11:00 AM","Academic Tutorial","Regular"); INSERT INTO tutor_assignments VALUES (30,30)');
rejectsSlot(fn() => deleteTutorScheduleRow($db,30,(string)$booked), 'Assignment overlay protects empty manual row');
echo "PASS: custom rows, shared availability, normalized duplicates, class types, ownership, manual/assigned deletion protection and validation.\n";
