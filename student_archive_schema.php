<?php
function ensureStudentArchiveTable(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS student_archives (
        enrollment_id INT UNSIGNED PRIMARY KEY,
        archived_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
}
