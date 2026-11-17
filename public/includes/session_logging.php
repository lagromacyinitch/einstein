<?php
require_once __DIR__ . '/activity_log_schema.php';

function saveTutorSessionWithActivity(PDO $db, int $tutorId, int $enrollmentId, string $studentName, string $date, float $hours, string $notes, bool $headAdmin, string $actorName, string $ip): int {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new RuntimeException('Valid session date required.');
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if (!$headAdmin && $date !== $today) throw new RuntimeException('You can only log a session for today (Philippine time).');
    if (!is_finite($hours) || $hours <= 0 || $hours > 24) throw new RuntimeException('Duration must be between 0 and 24 hours.');
    $db->beginTransaction();
    try {
        $tutor = $db->prepare('SELECT full_name FROM tutors WHERE id=?');
        $tutor->execute([$tutorId]);
        $tutorName = $tutor->fetchColumn();
        if (!$tutorName) throw new RuntimeException('Tutor not found.');
        if (!$enrollmentId && $studentName !== '') {
            $candidates = $db->prepare('SELECT e.id, e.child_name FROM enrollments e JOIN tutor_assignments ta ON ta.enrollment_id=e.id WHERE ta.tutor_id=?');
            $candidates->execute([$tutorId]);
            $matches = [];
            foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $candidate = decryptRow($candidate, ['child_name']);
                if (strcasecmp(trim($candidate['child_name']), $studentName) === 0) $matches[] = (int)$candidate['id'];
            }
            if (count($matches) !== 1) throw new RuntimeException('Please select the assigned student and program again.');
            $enrollmentId = $matches[0];
        }
        $query = $db->prepare('SELECT e.child_name, e.program FROM enrollments e WHERE e.id=?' . ($headAdmin ? '' : ' AND EXISTS (SELECT 1 FROM tutor_assignments ta WHERE ta.enrollment_id=e.id AND ta.tutor_id=?)'));
        $query->execute($headAdmin ? [$enrollmentId] : [$enrollmentId, $tutorId]);
        $student = $query->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new RuntimeException('Student is not assigned to this tutor.');
        $student = decryptRow($student, ['child_name']);
        $db->prepare('INSERT INTO sessions (enrollment_id, student_name, tutor_id, session_date, duration_hours, notes) VALUES (?,?,?,?,?,?)')
            ->execute([$enrollmentId, $student['child_name'], $tutorId, $date, $hours, $notes]);
        $sessionId = (int)$db->lastInsertId();
        $description = "{$tutorName} logged a class/session with {$student['child_name']} ({$student['program']}) on {$date}, {$hours} hour(s). Status: Logged. Session #{$sessionId}.";
        $db->prepare('INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?,?,?,?,?)')
            ->execute([$headAdmin ? $actorName : $tutorName, $headAdmin ? 'Head Admin' : 'Tutor', 'LOG_SESSION', $description, $ip]);
        $db->commit();
        return $sessionId;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
