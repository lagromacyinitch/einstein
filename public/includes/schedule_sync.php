<?php
function activeScheduleStudents(PDO $db) {
    $records = $db->query("SELECT e.id, e.child_name, e.program, e.package_selected, e.notes
        FROM enrollments e LEFT JOIN student_archives a ON a.enrollment_id=e.id
        WHERE e.status='confirmed' AND a.archived_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $students = [];
    foreach ($records as $record) {
        $record = decryptRow($record, ['child_name', 'notes']);
        $program = strtolower($record['program'] ?? '');
        $package = trim($record['package_selected'] ?? '');
        $notes = json_decode($record['notes'] ?? '', true) ?: [];
        if (str_contains($program, 'vip') || str_contains($program, 'studio rental')) continue;
        if (str_contains($program, 'studio') && $package === '' && !empty($notes['studio_rental'])) continue;
        if (str_contains($program, 'summer') && !preg_match('/single\s+course|two\s+courses|special\s+course/i', $package)
            && (preg_match('/registration\s+fee|reservation/i', $program . ' ' . $package) || !empty($notes['summer_blast_registration']['registration_only']))) continue;
        $students[(int)$record['id']] = trim($record['child_name'] ?? '');
    }
    return $students;
}

function filterScheduleRoster(array $rows, array $students) {
    $names = array_fill_keys(array_map('strtolower', array_values($students)), true);
    foreach ($rows as &$row) {
        foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) {
            $value = trim($row[$day] ?? '');
            if (in_array(strtolower($value), ['', 'available', 'n/a', 'na'], true)) continue;
            $row[$day] = implode(', ', array_filter(scheduleCellNames($value), function ($name) use ($names) {
                return isset($names[strtolower($name)]);
            }));
        }
    }
    unset($row);
    return $rows;
}

// Schedule cells contain comma-separated names; match whole names, never substrings.
function scheduleCellNames($value) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$value)), function ($name) {
        return $name !== '' && !in_array(strtolower($name), ['available', 'n/a', 'na']);
    }));
}

function scheduleTimeslotForStudent($rows, $name) {
    $labels = ['mon'=>'Monday', 'tue'=>'Tuesday', 'wed'=>'Wednesday', 'thu'=>'Thursday',
        'fri'=>'Friday', 'sat'=>'Saturday', 'sun'=>'Sunday'];
    $slots = [];
    foreach ($rows as $row) {
        foreach ($labels as $day => $label) {
            foreach (scheduleCellNames($row[$day] ?? '') as $candidate) {
                if (strcasecmp($candidate, trim($name)) === 0) {
                    $slots[$row['timeslot']][$day] = $label;
                    break;
                }
            }
        }
    }
    $parts = [];
    foreach ($slots as $time => $days) {
        $parts[] = implode(', ', array_intersect_key($labels, $days)) . ' ' . $time;
    }
    return $parts ? implode('; ', $parts) : 'Unscheduled';
}

function saveScheduleAndEnrollmentTimeslots(PDO $db, $rowId, array $changes) {
    $db->beginTransaction();
    try {
        if (ctype_digit((string)$rowId)) {
            $lookup = $db->prepare('SELECT tutor_id FROM tutor_schedules WHERE id=? FOR UPDATE');
            $lookup->execute([$rowId]);
        } elseif (preg_match('/^assign_(\d+)(?:_[a-f0-9]{8})?$/', $rowId, $match)) {
            $lookup = $db->prepare('SELECT tutor_id FROM tutor_assignments WHERE enrollment_id=? FOR UPDATE');
            $lookup->execute([$match[1]]);
        } else {
            throw new Exception('Invalid schedule row.');
        }
        $tutorId = $lookup->fetchColumn();
        if (!$tutorId) throw new Exception('Schedule row no longer exists. Refresh and try again.');
        // Serialize edits for this tutor before reading the complete timetable.
        $lock = $db->prepare('SELECT id FROM tutors WHERE id=? FOR UPDATE');
        $lock->execute([$tutorId]);
        $manual = $db->prepare('SELECT * FROM tutor_schedules WHERE tutor_id=? ORDER BY sort_order, id FOR UPDATE');
        $manual->execute([$tutorId]);
        $assigned = $db->prepare('SELECT e.id AS enrollment_id, ta.tutor_id, e.child_name, e.timeslot, e.program, e.package_selected
            FROM tutor_assignments ta JOIN enrollments e ON e.id=ta.enrollment_id WHERE ta.tutor_id=? FOR UPDATE');
        $assigned->execute([$tutorId]);
        $students = array_map(function ($row) { return decryptRow($row, ['child_name']); }, $assigned->fetchAll(PDO::FETCH_ASSOC));
        $roster = activeScheduleStudents($db);
        $students = array_values(array_filter($students, fn($student) => isset($roster[(int)$student['enrollment_id']])));
        $rows = mergeSchedulesAndAssignments(filterScheduleRoster($manual->fetchAll(PDO::FETCH_ASSOC), $roster), $students);
        $index = null;
        foreach ($rows as $i => $row) {
            if ((string)$row['id'] === (string)$rowId) { $index = $i; break; }
        }
        if ($index === null) throw new Exception('Schedule changed. Refresh and try again.');
        $before = $rows[$index];
        $rows[$index] = array_replace($before, $changes);
        $after = $rows[$index];
        $days = ['mon','tue','wed','thu','fri','sat','sun'];
        // Legacy edits could create duplicate rows for the same tutor/time.
        // Removing a student from a day must clear that same booking in every
        // duplicate, otherwise merging the enrollment restores the removed name.
        $duplicateEdits = [];
        foreach ($days as $day) {
            if (!array_key_exists($day, $changes)) continue;
            $removed = array_diff(
                array_map('strtolower', scheduleCellNames($before[$day] ?? '')),
                array_map('strtolower', scheduleCellNames($after[$day] ?? ''))
            );
            if (!$removed) continue;
            foreach ($rows as $i => $row) {
                if ($i === $index || normalizeTimeSlotKey($row['timeslot']) !== normalizeTimeSlotKey($before['timeslot'])) continue;
                $names = scheduleCellNames($row[$day] ?? '');
                $kept = array_values(array_filter($names, function ($name) use ($removed) {
                    return !in_array(strtolower($name), $removed, true);
                }));
                if ($kept === $names) continue;
                $rows[$i][$day] = implode(', ', $kept);
                if (ctype_digit((string)$row['id'])) $duplicateEdits[$row['id']][$day] = $rows[$i][$day];
            }
        }
        $affected = [];
        foreach ($days as $day) {
            foreach (array_merge(scheduleCellNames($before[$day] ?? ''), scheduleCellNames($after[$day] ?? '')) as $name) {
                $affected[strtolower($name)] = true;
            }
        }
        $updates = [];
        $seen = [];
        foreach ($students as $student) {
            $key = strtolower(trim($student['child_name']));
            if (!isset($affected[$key])) continue;
            if (isset($seen[$key]) && $seen[$key] !== $student['enrollment_id']) {
                throw new Exception('Multiple enrollments have the same student name for this tutor. Please resolve the duplicate assignment before editing.');
            }
            $seen[$key] = $student['enrollment_id'];
            $updates[$student['enrollment_id']] = scheduleTimeslotForStudent($rows, $student['child_name']);
            if (strlen($updates[$student['enrollment_id']]) > 100) {
                throw new Exception('The combined schedule exceeds the 100-character enrollment timeslot limit. Please use fewer time ranges.');
            }
        }
        // Materialize all visible days, including assignment-generated names, so
        // clearing one day does not discard the student's other scheduled days.
        $values = [$after['timeslot'], $after['slot_type']];
        foreach ($days as $day) $values[] = $after[$day] ?? '';
        if (ctype_digit((string)$rowId)) {
            $values[] = $rowId;
            $db->prepare('UPDATE tutor_schedules SET timeslot=?,slot_type=?,mon=?,tue=?,wed=?,thu=?,fri=?,sat=?,sun=? WHERE id=?')->execute($values);
            $savedId = (int)$rowId;
        } else {
            $values[] = $tutorId;
            $db->prepare('INSERT INTO tutor_schedules (timeslot,slot_type,mon,tue,wed,thu,fri,sat,sun,tutor_id) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($values);
            $savedId = (int)$db->lastInsertId();
        }
        foreach ($duplicateEdits as $duplicateId => $dayValues) {
            foreach ($dayValues as $day => $value) {
                $db->prepare("UPDATE tutor_schedules SET `$day`=? WHERE id=?")->execute([$value, $duplicateId]);
            }
        }
        $update = $db->prepare('UPDATE enrollments SET timeslot=? WHERE id=?');
        foreach ($updates as $id => $timeslot) $update->execute([$timeslot, $id]);
        $db->commit();
        return $savedId;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
