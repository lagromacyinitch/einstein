CREATE TABLE IF NOT EXISTS tutor_schedule_reads (
    tutor_id INT UNSIGNED NOT NULL,
    history_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tutor_id, history_id)
) ENGINE=InnoDB;
