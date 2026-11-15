<?php
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
