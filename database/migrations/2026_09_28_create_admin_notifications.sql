CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(191) NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(50) NOT NULL DEFAULT 'enrollment',
    entity_id INT UNSIGNED DEFAULT NULL,
    target_page VARCHAR(80) NOT NULL DEFAULT 'dashboard',
    target_view VARCHAR(80) DEFAULT NULL,
    target_ref VARCHAR(191) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_notification_event (event_key),
    INDEX idx_admin_notification_created (created_at),
    INDEX idx_admin_notification_entity (entity_type, entity_id),
    INDEX idx_admin_notification_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_notification_reads (
    notification_id BIGINT UNSIGNED NOT NULL,
    admin_key VARCHAR(191) NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, admin_key),
    INDEX idx_admin_notification_read_admin (admin_key, read_at),
    CONSTRAINT fk_admin_notification_read_notification
        FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
