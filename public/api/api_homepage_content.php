<?php
/**
 * Homepage copy API.
 * GET  - public read of homepage content (also returns editor metadata)
 * POST - authenticated admin/staff update of editable homepage copy
 */
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HomepageContent.php';
setSecurityHeaders();

try {
    $db = getDB();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $payload = HomepageContent::all($db);
        echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    startPortalSession();
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'admin' && $role !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin or staff access required.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $values = $body['content'] ?? $body['values'] ?? [];
    if (!is_array($values)) {
        throw new InvalidArgumentException('Invalid homepage content payload.');
    }

    $subAdminId = (int)($_SESSION['sub_admin_id'] ?? 0);
    if (empty($_SESSION['is_head_admin']) && $subAdminId > 0) {
        $permission = $db->prepare('SELECT can_modify_records FROM admin_accounts WHERE id = ? LIMIT 1');
        $permission->execute([$subAdminId]);
        if (!(int)$permission->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }
    }

    HomepageContent::save($db, $values);

    try {
        $log = $db->prepare("INSERT INTO admin_activity_log
            (actor_name, actor_role, action_type, description, ip_address)
            VALUES (?, ?, 'UPDATE_HOMEPAGE_CONTENT', 'Updated editable homepage content', ?)");
        $log->execute([
            $_SESSION['display_name'] ?? 'Admin',
            !empty($_SESSION['is_head_admin']) ? 'Head Admin' : ucfirst($role),
            explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0],
        ]);
    } catch (Throwable $ignored) {
        // Content has already been saved; activity logging must not block it.
    }

    echo json_encode(['success' => true, 'message' => 'Homepage content saved to database.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
