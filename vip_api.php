<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/vip_membership.php';
setSecurityHeaders();
$admin = ($_GET['scope'] ?? '') === 'admin';
if ($admin) { require_once __DIR__ . '/admin_auth.php'; }
else { startUserSession(); }
try {
    if ($admin && ($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); throw new RuntimeException('Head admin access required.'); }
    if (!$admin && empty($_SESSION['user_id'])) { http_response_code(401); throw new RuntimeException('Please log in again.'); }
    $db = getDB(); vipSetup($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($admin) {
            $years = filter_var($data['years'] ?? null, FILTER_VALIDATE_INT);
            if (!$years || $years < 1 || $years > 10) throw new RuntimeException('Enter 1 to 10 years.');
            $db->prepare('UPDATE vip_settings SET duration_years=? WHERE id=1')->execute([$years]);
        } else {
            if (($data['action'] ?? '') !== 'unsubscribe') throw new RuntimeException('Invalid action.');
            $userId = (int) $_SESSION['user_id'];
            $state = vipState($db, $userId);
            if (empty($state['active'])) {
                throw new RuntimeException('You do not have an active VIP membership to unsubscribe.');
            }
            $db->prepare('INSERT INTO vip_unsubscriptions (user_id, unsubscribed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE unsubscribed_at=VALUES(unsubscribed_at)')->execute([$userId]);
        }
    }
    if (!$admin) {
        echo json_encode(['success' => true, 'membership' => vipState($db, (int) $_SESSION['user_id'])]); exit;
    }
    $events = []; $seen = []; $members = [];
    foreach ($db->query("SELECT id, user_id, guardian_name, created_at FROM enrollments WHERE LOWER(program) LIKE '%vip%' ORDER BY created_at, id") as $row) {
        $key = $row['user_id'] ?: 'enrollment-' . $row['id'];
        $events[] = ['title' => isset($seen[$key]) ? 'VIP Renewal Submitted' : 'VIP Join Submitted', 'name' => decryptAES256($row['guardian_name']), 'at' => $row['created_at']];
        $seen[$key] = true;
        if ($row['user_id']) $members[$row['user_id']] = vipState($db, (int) $row['user_id']);
    }
    foreach ($db->query('SELECT v.unsubscribed_at, (SELECT guardian_name FROM enrollments e WHERE e.user_id=v.user_id AND (LOWER(e.program) LIKE \'%vip%\' OR LOWER(e.package_selected) LIKE \'%(vip)%\') ORDER BY e.created_at DESC LIMIT 1) AS guardian_name FROM vip_unsubscriptions v') as $row) {
        $events[] = ['title' => 'VIP Unsubscribed', 'name' => decryptAES256($row['guardian_name']), 'at' => $row['unsubscribed_at']];
    }
    usort($events, fn($a, $b) => strcmp($b['at'], $a['at']));
    echo json_encode(['success' => true, 'years' => (int) $db->query('SELECT duration_years FROM vip_settings WHERE id=1')->fetchColumn(), 'events' => array_slice($events, 0, 30), 'members' => $members]);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Unable to load VIP membership.' : $e->getMessage()]);
}
