<?php
require_once __DIR__ . '/schedule_sync.php';

function sessionScheduleTutorId(PDO $db): int {
    if (!empty($_SESSION['tutor_id'])) return (int)$_SESSION['tutor_id'];
    $name = trim($_SESSION['display_name'] ?? '');
    if ($name === '') return 0;
    $query = $db->prepare('SELECT id FROM tutors WHERE LOWER(full_name)=LOWER(?)');
    $query->execute([$name]);
    $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) !== 1) return 0;
    return $_SESSION['tutor_id'] = (int)$ids[0];
}

function ensureTutorScheduleReads(PDO $db): void {
    ensureScheduleChangeHistory($db);
    $db->exec('CREATE TABLE IF NOT EXISTS tutor_schedule_reads (
        tutor_id INT UNSIGNED NOT NULL, history_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (tutor_id, history_id)
    ) ENGINE=InnoDB');
}

function tutorScheduleNotifications(PDO $db, int $tutorId): array {
    $query = $db->prepare('SELECT h.*, e.child_name, CASE WHEN r.history_id IS NULL THEN 1 ELSE 0 END AS unread
        FROM enrollment_schedule_history h JOIN enrollments e ON e.id=h.enrollment_id
        LEFT JOIN tutor_schedule_reads r ON r.history_id=h.id AND r.tutor_id=?
        WHERE EXISTS (SELECT 1 FROM tutor_assignments ta WHERE ta.enrollment_id=e.id AND ta.tutor_id=?)
        ORDER BY h.id DESC');
    $query->execute([$tutorId, $tutorId]);
    return array_map(function ($record) {
        $record = decryptRow($record, ['child_name']);
        $describe = fn($value) => !trim($value) || strcasecmp(trim($value), 'Unscheduled') === 0 ? 'No schedule assigned' : $value;
        return [
            'id' => (int)$record['id'], 'type' => 'schedule', 'title' => 'Schedule updated',
            'message' => "Admin updated {$record['child_name']}'s schedule for {$record['program']}.",
            'previous_schedule' => $describe($record['previous_timeslot']),
            'updated_schedule' => $describe($record['new_timeslot']),
            'target_page' => 'my_schedule', 'entity_id' => (int)$record['enrollment_id'],
            'created_at' => $record['changed_at'], 'unread' => (int)$record['unread']
        ];
    }, $query->fetchAll(PDO::FETCH_ASSOC));
}

function setTutorAvailability(PDO $db, int $tutorId, string $rowId, string $day, bool $available): void {
    if ($tutorId <= 0 || !in_array($day, ['mon','tue','wed','thu','fri','sat','sun'], true)) throw new RuntimeException('Invalid schedule slot.');
    $db->beginTransaction();
    try {
        // Use the same tutor lock as Head Admin edits to prevent booking races.
        $lock = $db->prepare('SELECT id FROM tutors WHERE id=? FOR UPDATE');
        $lock->execute([$tutorId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Tutor not found.');
        $manual = $db->prepare('SELECT * FROM tutor_schedules WHERE tutor_id=? ORDER BY sort_order, id FOR UPDATE');
        $manual->execute([$tutorId]);
        $assigned = $db->prepare('SELECT e.*, e.id AS enrollment_id, ta.tutor_id FROM tutor_assignments ta JOIN enrollments e ON e.id=ta.enrollment_id WHERE ta.tutor_id=? FOR UPDATE');
        $assigned->execute([$tutorId]);
        $roster = activeScheduleStudents($db);
        $students = array_values(array_filter(array_map(fn($r) => decryptRow($r, ['child_name']), $assigned->fetchAll(PDO::FETCH_ASSOC)), fn($r) => isset($roster[(int)$r['enrollment_id']])));
        $rows = mergeSchedulesAndAssignments(filterScheduleRoster($manual->fetchAll(PDO::FETCH_ASSOC), $roster), $students);
        $selected = null;
        foreach ($rows as $row) if ((string)$row['id'] === $rowId) $selected = $row;
        if (!$selected) throw new RuntimeException('This slot is not in your schedule. Refresh and try again.');
        $matching = array_values(array_filter($rows, fn($row) => normalizeTimeSlotKey($row['timeslot']) === normalizeTimeSlotKey($selected['timeslot'])));
        foreach ($matching as $row) {
            if (!in_array(strtolower(trim($row[$day] ?? '')), ['', 'available'], true)) throw new RuntimeException('This slot is booked or unavailable. Refresh your schedule.');
        }
        $value = $available ? 'Available' : '';
        $saved = false;
        foreach ($matching as $row) {
            if (!ctype_digit((string)$row['id'])) continue;
            foreach ($row['source_ids'] ?? [$row['id']] as $sourceId) {
                $db->prepare("UPDATE tutor_schedules SET `$day`=? WHERE id=? AND tutor_id=?")->execute([$value, $sourceId, $tutorId]);
            }
            $saved = true;
        }
        if (!$saved) {
            $db->prepare("INSERT INTO tutor_schedules (tutor_id,timeslot,slot_type,`$day`) VALUES (?,?,?,?)")
                ->execute([$tutorId, $selected['timeslot'], $selected['slot_type'] ?? 'CB', $value]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function addTutorScheduleHighlights(PDO $db, array $rows, array $students): array {
    $history = $db->prepare('SELECT previous_timeslot FROM enrollment_schedule_history WHERE enrollment_id=? ORDER BY id DESC LIMIT 1');
    foreach ($students as $student) {
        $history->execute([$student['enrollment_id']]);
        $previous = $history->fetchColumn();
        if ($previous === false) continue;
        foreach ($rows as &$row) {
            if ((int)$row['tutor_id'] !== (int)$student['tutor_id']) continue;
            $days = [];
            foreach (['mon','tue','wed','thu','fri','sat','sun'] as $day) {
                $days[$day] = in_array(strtolower(trim($student['child_name'])), array_map('strtolower', scheduleCellNames($row[$day] ?? '')), true);
            }
            $updated = parentScheduleUpdatedDays(['days' => $days, 'timeslot' => $row['timeslot']], $previous, $student['program'], $student['package_selected']);
            foreach ($updated as $day => $changed) if ($changed) $row['updated_days'][$day] = true;
        }
        unset($row);
    }
    return $rows;
}
