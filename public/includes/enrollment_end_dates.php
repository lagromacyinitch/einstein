<?php
function parentEndDateChanges(PDO $db, int $userId): array {
    $stmt = $db->prepare('SELECT h.id, h.previous_override, h.new_override, h.reason, h.changed_at,
        e.child_name, e.program FROM enrollment_end_date_history h
        JOIN enrollments e ON e.id = h.enrollment_id
        WHERE e.user_id = ? ORDER BY h.id DESC LIMIT 100');
    $stmt->execute([$userId]);
    return array_map(fn($row) => decryptRow($row, ['child_name']), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function ensureEnrollmentEndDateHistory(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS enrollment_end_date_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        previous_override DATE NULL,
        new_override DATE NULL,
        reason VARCHAR(500) NOT NULL,
        changed_by VARCHAR(160) NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX enrollment_history (enrollment_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
