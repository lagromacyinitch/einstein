<?php
/**
 * Persistent notifications shown in the admin notification bell.
 * Notification rows are shared across admins; read state is tracked per admin
 * account so one admin reading an item does not hide it from another account.
 */

function ensureAdminNotificationsSchema(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;

    $db->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS admin_notification_reads (
        notification_id BIGINT UNSIGNED NOT NULL,
        admin_key VARCHAR(191) NOT NULL,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notification_id, admin_key),
        INDEX idx_admin_notification_read_admin (admin_key, read_at),
        CONSTRAINT fk_admin_notification_read_notification
            FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ensured = true;
}

function adminNotificationActorKey(): string
{
    $subAdminId = (int) ($_SESSION['sub_admin_id'] ?? 0);
    if ($subAdminId > 0) return 'sub_admin:' . $subAdminId;

    $username = strtolower(trim((string) ($_SESSION['portal_username'] ?? '')));
    if ($username !== '') return 'head_admin:' . substr($username, 0, 160);

    return 'session:' . substr(session_id(), 0, 160);
}

function createAdminNotification(PDO $db, array $notification): int
{
    ensureAdminNotificationsSchema($db);

    $eventKey = trim((string) ($notification['event_key'] ?? ''));
    if ($eventKey === '') throw new InvalidArgumentException('Notification event key is required.');

    $stmt = $db->prepare("INSERT INTO admin_notifications
        (event_key, type, title, message, entity_type, entity_id, target_page, target_view, target_ref)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->execute([
        substr($eventKey, 0, 191),
        substr(trim((string) ($notification['type'] ?? 'general')), 0, 50),
        substr(trim((string) ($notification['title'] ?? 'Admin Notification')), 0, 180),
        trim((string) ($notification['message'] ?? '')),
        substr(trim((string) ($notification['entity_type'] ?? 'enrollment')), 0, 50),
        isset($notification['entity_id']) && $notification['entity_id'] !== null ? (int) $notification['entity_id'] : null,
        substr(trim((string) ($notification['target_page'] ?? 'dashboard')), 0, 80),
        ($notification['target_view'] ?? null) !== null ? substr(trim((string) $notification['target_view']), 0, 80) : null,
        ($notification['target_ref'] ?? null) !== null ? substr(trim((string) $notification['target_ref']), 0, 191) : null,
    ]);

    return (int) $db->lastInsertId();
}

function listAdminNotifications(PDO $db, string $adminKey, int $limit = 50): array
{
    ensureAdminNotificationsSchema($db);
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare("SELECT n.id, n.event_key, n.type, n.title, n.message,
            n.entity_type, n.entity_id, n.target_page, n.target_view, n.target_ref,
            n.created_at, IF(r.notification_id IS NULL, 1, 0) AS unread
        FROM admin_notifications n
        LEFT JOIN admin_notification_reads r
            ON r.notification_id = n.id AND r.admin_key = ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT {$limit}");
    $stmt->execute([$adminKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countUnreadAdminNotifications(PDO $db, string $adminKey): int
{
    ensureAdminNotificationsSchema($db);
    $stmt = $db->prepare("SELECT COUNT(*)
        FROM admin_notifications n
        LEFT JOIN admin_notification_reads r
            ON r.notification_id = n.id AND r.admin_key = ?
        WHERE r.notification_id IS NULL");
    $stmt->execute([$adminKey]);
    return (int) $stmt->fetchColumn();
}

function markAdminNotificationRead(PDO $db, string $adminKey, int $notificationId): void
{
    ensureAdminNotificationsSchema($db);
    $stmt = $db->prepare("INSERT INTO admin_notification_reads (notification_id, admin_key, read_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE read_at = read_at");
    $stmt->execute([$notificationId, $adminKey]);
}

function markAllAdminNotificationsRead(PDO $db, string $adminKey): void
{
    ensureAdminNotificationsSchema($db);
    $stmt = $db->prepare("INSERT INTO admin_notification_reads (notification_id, admin_key, read_at)
        SELECT n.id, ?, NOW()
        FROM admin_notifications n
        LEFT JOIN admin_notification_reads r
            ON r.notification_id = n.id AND r.admin_key = ?
        WHERE r.notification_id IS NULL
        ON DUPLICATE KEY UPDATE read_at = read_at");
    $stmt->execute([$adminKey, $adminKey]);
}
