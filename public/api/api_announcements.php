<?php
/**
 * api_announcements.php — Dashboard Announcements API
 *
 * GET                     → Fetch all announcements
 * POST action=create      → Create new announcement (Admin)
 * POST action=delete      → Delete announcement (Admin)
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
startPortalSession();

try {
    $db = getDB();

    // ── Auto-create table if not exists ──────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'General',
        audience VARCHAR(50) NOT NULL DEFAULT 'all',
        posted_by VARCHAR(100) NOT NULL DEFAULT 'Admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_audience (audience)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->query("SELECT *, created_at AS date FROM announcements ORDER BY id DESC");
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'announcements' => $announcements]);
        exit;
    }

    // POST requires admin or staff
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'admin' && $role !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? '');
    $actorName = $_SESSION['display_name'] ?? 'Head Admin';
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0];

    if ($action === 'create') {
        $title    = trim($body['title'] ?? '');
        $content  = trim($body['body'] ?? ($body['content'] ?? ''));
        $category = trim($body['category'] ?? 'General');
        $audience = trim($body['audience'] ?? 'all');

        if (!$title || !$content) {
            throw new Exception('Title and announcement body are required.');
        }

        $stmt = $db->prepare("INSERT INTO announcements (title, body, category, audience, posted_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $content, $category, $audience, $actorName]);
        $newId = $db->lastInsertId();

        // Audit log
        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, ?, 'CREATE_ANNOUNCEMENT', ?, ?)");
        $logStmt->execute([$actorName, $role === 'admin' ? 'Head Admin' : 'Staff', "Posted announcement: $title ($audience)", $ip]);

        echo json_encode(['success' => true, 'message' => 'Announcement posted.', 'id' => $newId]);

    } elseif ($action === 'delete') {
        $id = intval($body['id'] ?? 0);
        if (!$id) throw new Exception('Announcement ID required.');

        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);

        $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, ?, 'DELETE_ANNOUNCEMENT', ?, ?)");
        $logStmt->execute([$actorName, $role === 'admin' ? 'Head Admin' : 'Staff', "Deleted announcement ID: $id", $ip]);

        echo json_encode(['success' => true, 'message' => 'Announcement deleted.']);

    } else {
        throw new Exception('Unknown action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
