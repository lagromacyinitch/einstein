<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

try {
    $db = getDB();
    ensureAdminNotificationsSchema($db);
    $adminKey = adminNotificationActorKey();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
        $notifications = listAdminNotifications($db, $adminKey, $limit ?: 50);
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => countUnreadAdminNotifications($db, $adminKey),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('GET or POST required.');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $action = (string) ($data['action'] ?? '');

    if ($action === 'mark_read') {
        $notificationId = filter_var($data['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) throw new RuntimeException('Invalid notification.');
        markAdminNotificationRead($db, $adminKey, (int) $notificationId);
    } elseif ($action === 'mark_all_read') {
        markAllAdminNotificationsRead($db, $adminKey);
    } else {
        http_response_code(422);
        throw new RuntimeException('Invalid notification action.');
    }

    echo json_encode([
        'success' => true,
        'unread_count' => countUnreadAdminNotifications($db, $adminKey),
    ]);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
