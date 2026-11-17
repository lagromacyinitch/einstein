CREATE TABLE IF NOT EXISTS enrollment_schedule_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT UNSIGNED NOT NULL,
    program VARCHAR(255) NOT NULL,
    previous_timeslot VARCHAR(100) NOT NULL,
    new_timeslot VARCHAR(100) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX enrollment_schedule_changes (enrollment_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
