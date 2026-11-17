<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/tutor_schedules.php';

try {
    $db = getDB();
    ensureAdminNotificationsSchema($db);
    $adminKey = adminNotificationActorKey();
    if (empty($_SESSION['is_head_admin'])) {
        ensureTutorScheduleReads($db);
        $tutorId = sessionScheduleTutorId($db);
        $items = tutorScheduleNotifications($db, $tutorId);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $action = $input['action'] ?? '';
            if (!in_array($action, ['mark_read', 'mark_all_read'], true)) throw new RuntimeException('Invalid notification action.');
            foreach ($items as $item) {
                if ($action === 'mark_read' && -$item['id'] !== (int)($input['notification_id'] ?? 0)) continue;
                $db->prepare('INSERT IGNORE INTO tutor_schedule_reads (tutor_id, history_id) VALUES (?,?)')->execute([$tutorId, $item['id']]);
            }
            $items = tutorScheduleNotifications($db, $tutorId);
            if ($action === 'mark_all_read') markAllAdminNotificationsRead($db, $adminKey);
            elseif ((int)($input['notification_id'] ?? 0) > 0) markAdminNotificationRead($db, $adminKey, (int)$input['notification_id']);
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new RuntimeException('GET or POST required.');
        }
        $unread = array_sum(array_column($items, 'unread')) + countUnreadAdminNotifications($db, $adminKey);
        // History IDs use a separate negative namespace from shared admin notices.
        foreach ($items as &$item) $item['id'] = -$item['id'];
        unset($item);
        $items = array_merge($items, listAdminNotifications($db, $adminKey, 100));
        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        echo json_encode(['success' => true, 'notifications' => array_slice($items, 0, 100), 'unread_count' => $unread]);
        exit;
    }
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
