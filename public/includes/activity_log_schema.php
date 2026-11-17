<?php
function ensureActivityLogTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_activity_log (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            actor_name   VARCHAR(120) NOT NULL,
            actor_role   VARCHAR(30)  NOT NULL,
            action_type  VARCHAR(80)  NOT NULL,
            description  TEXT         NOT NULL,
            ip_address   VARCHAR(45)  DEFAULT NULL,
            logged_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aal_logged (logged_at),
            INDEX idx_aal_actor  (actor_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

