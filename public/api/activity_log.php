<?php
/**
 * activity_log.php
 * API for recording and retrieving admin/sub-admin activity logs.
 * GET  ?action=list  — returns log entries (Head Admin only)
 * POST action=log    — records an activity (any authenticated admin)
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
startPortalSession();

// ── Auth check ───────────────────────────────────────────────────────────────
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'sub_admin'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$isHeadAdmin = !empty($_SESSION['is_head_admin']);
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ── Ensure table exists ───────────────────────────────────────────────────────
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

try {
    $db = getDB();
    ensureActivityLogTable($db);

    // ── LIST logs (Head Admin only) ────────────────────────────────────────
    if ($action === 'list' || $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$isHeadAdmin) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Head Admin access required.']);
            exit;
        }

        $limit  = min((int)($_GET['limit']  ?? 200), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $search = trim($_GET['search'] ?? $_GET['actor'] ?? '');
        $filterType  = trim($_GET['type']  ?? '');
        $filterDate  = trim($_GET['date']  ?? '');

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = '(description LIKE ? OR actor_name LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($filterType !== '') {
            $where[]  = 'action_type = ?';
            $params[] = $filterType;
        }
        if ($filterDate !== '') {
            $where[]  = 'DATE(logged_at) = ?';
            $params[] = $filterDate;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM admin_activity_log $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT id, actor_name, actor_role, action_type, description, ip_address, logged_at
            FROM   admin_activity_log
            $whereSql
            ORDER  BY logged_at DESC
            LIMIT  $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // Fetch distinct action types for filter dropdown
        $types = $db->query("SELECT DISTINCT action_type FROM admin_activity_log ORDER BY action_type ASC")->fetchAll(PDO::FETCH_COLUMN);

        ob_clean();
        echo json_encode(['success' => true, 'logs' => $logs, 'total' => $total, 'types' => $types]);
        exit;
    }

    // ── RECORD a log entry (any authenticated admin) ───────────────────────
    if ($action === 'log' || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $post  = array_merge($_POST, $input);

        $actorName   = trim($post['actor_name']   ?? $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Unknown');
        $actorRole   = trim($post['actor_role']   ?? ($isHeadAdmin ? 'Head Admin' : 'Sub-Admin'));
        $actionType  = trim($post['action_type']  ?? '');
        $description = trim($post['description']  ?? '');

        if (!$actionType || !$description) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'action_type and description are required.']);
            exit;
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) $ip = explode(',', $ip)[0];

        $stmt = $db->prepare("
            INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$actorName, $actorRole, $actionType, $description, $ip]);

        ob_clean();
        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
