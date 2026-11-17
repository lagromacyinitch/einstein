<?php
require_once __DIR__ . '/schedule_format.php';

function ensureScheduleChangeHistory(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS enrollment_schedule_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        program VARCHAR(255) NOT NULL,
        previous_timeslot VARCHAR(100) NOT NULL,
        new_timeslot VARCHAR(100) NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX enrollment_schedule_changes (enrollment_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function recordScheduleChange(PDO $db, int $enrollmentId, string $program, string $before, string $after): void {
    if (trim($before) === trim($after)) return;
    $db->prepare('INSERT INTO enrollment_schedule_history (enrollment_id, program, previous_timeslot, new_timeslot) VALUES (?,?,?,?)')
        ->execute([$enrollmentId, $program, $before, $after]);
}

function parentScheduleChanges(PDO $db, int $userId): array {
    $query = $db->prepare('SELECT h.*, e.child_name FROM enrollment_schedule_history h
        JOIN enrollments e ON e.id=h.enrollment_id WHERE e.user_id=? ORDER BY h.id DESC LIMIT 100');
    $query->execute([$userId]);
    return scheduleNotificationPreviousSlots($db, array_map(fn($row) => decryptRow($row, ['child_name']), $query->fetchAll(PDO::FETCH_ASSOC)));
}

// Moving a student between cells saves a removal followed by an assignment.
// Keep the raw audit events intact, but show the last assigned slot as "From"
// on the reassignment notice instead of the intermediate empty slot.
function scheduleNotificationPreviousSlots(PDO $db, array $records): array {
    $empty = static fn($slot) => trim((string)$slot) === '' || strcasecmp(trim((string)$slot), 'Unscheduled') === 0;
    $previous = $db->prepare('SELECT previous_timeslot, new_timeslot FROM enrollment_schedule_history
        WHERE enrollment_id=? AND id<? ORDER BY id DESC LIMIT 1');
    foreach ($records as &$record) {
        if (!$empty($record['previous_timeslot']) || $empty($record['new_timeslot'])) continue;
        $previous->execute([$record['enrollment_id'], $record['id']]);
        $prior = $previous->fetch(PDO::FETCH_ASSOC);
        if ($prior && $empty($prior['new_timeslot']) && !$empty($prior['previous_timeslot'])) {
            $record['previous_timeslot'] = $prior['previous_timeslot'];
        }
    }
    unset($record);
    return $records;
}

function parentScheduleRows(PDO $db, int $userId): array {
    $now = (string)$db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    // Never return the shared tutor cells: those may contain other families.
    $query = $db->prepare("SELECT e.id AS enrollment_id, e.child_name, e.program, e.package_selected,
        e.timeslot, e.notes, ta.tutor_id, t.full_name AS tutor_name, h.previous_timeslot, h.changed_at
        FROM enrollments e JOIN tutor_assignments ta ON ta.enrollment_id=e.id
        JOIN tutors t ON t.id=ta.tutor_id
        LEFT JOIN student_archives a ON a.enrollment_id=e.id
        LEFT JOIN enrollment_schedule_history h ON h.id=(SELECT MAX(latest.id) FROM enrollment_schedule_history latest WHERE latest.enrollment_id=e.id)
        WHERE e.user_id=? AND e.status='confirmed' AND a.archived_at IS NULL
        ORDER BY t.full_name, e.id");
    $query->execute([$userId]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $record = decryptRow($record, ['child_name', 'notes']);
        $program = strtolower($record['program']);
        $notes = json_decode($record['notes'] ?? '', true) ?: [];
        if (str_contains($program, 'vip') || str_contains($program, 'studio rental')) continue;
        if (str_contains($program, 'studio') && trim($record['package_selected'] ?? '') === '' && !empty($notes['studio_rental'])) continue;
        if (str_contains($program, 'summer') && !preg_match('/single\s+course|two\s+courses|special\s+course/i', $record['package_selected'] ?? '')
            && (preg_match('/registration\s+fee|reservation/i', $program . ' ' . ($record['package_selected'] ?? '')) || !empty($notes['summer_blast_registration']['registration_only']))) continue;
        if (!trim($record['timeslot'] ?? '') || strcasecmp(trim($record['timeslot']), 'Unscheduled') === 0) continue;
        foreach (explode(';', $record['timeslot']) as $slot) {
            $parsed = parseScheduleTimePreference($slot, $record['program'], $record['package_selected']);
            $rows[] = [
                'enrollment_id' => (int)$record['enrollment_id'],
                'child_name' => $record['child_name'], 'program' => $record['program'],
                'tutor_id' => (int)$record['tutor_id'], 'tutor_name' => $record['tutor_name'],
                'timeslot' => $parsed['timeslot'], 'days' => $parsed['days'],
                'updated_days' => parentScheduleUpdatedDays($parsed, scheduleHighlightIsRecent($record['changed_at'], $now) ? $record['previous_timeslot'] : null, $record['program'], $record['package_selected']),
                'slot_type' => stripos($record['program'] . ' ' . $record['package_selected'], 'Home') !== false ? 'HB' : 'CB'
            ];
        }
    }
    return $rows;
}

// Compare both timestamps using the database clock, avoiding browser/server timezone differences.
function scheduleHighlightIsRecent(?string $changedAt, string $now): bool {
    if (!$changedAt) return false;
    try {
        $zone = new DateTimeZone('UTC');
        $age = (new DateTimeImmutable($now, $zone))->getTimestamp() - (new DateTimeImmutable($changedAt, $zone))->getTimestamp();
        return $age >= 0 && $age < 3 * 24 * 60 * 60;
    } catch (Exception $error) {
        return false;
    }
}

// Highlight only occupied cells that changed in the latest saved edit.
function parentScheduleUpdatedDays(array $current, ?string $previous, string $program, string $package): array {
    $updated = array_fill_keys(array_keys($current['days']), false);
    if ($previous === null || trim($previous) === '') return $updated;
    $oldSlots = array_map(fn($slot) => parseScheduleTimePreference($slot, $program, $package), explode(';', $previous));
    foreach ($current['days'] as $day => $occupied) {
        if (!$occupied) continue;
        $unchanged = false;
        foreach ($oldSlots as $old) {
            if (!empty($old['days'][$day]) && normalizeTimeSlotKey($old['timeslot']) === normalizeTimeSlotKey($current['timeslot'])) {
                $unchanged = true;
                break;
            }
        }
        $updated[$day] = !$unchanged;
    }
    return $updated;
}
