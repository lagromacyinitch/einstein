<?php
ob_start();
ini_set('display_errors', 0);
// ─────────────────────────────────────────────
//  Einstein Center — Get User Enrollments Endpoint
//  GET: returns logged-in user's enrollments and stats
// ─────────────────────────────────────────────
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vip_membership.php';
require_once __DIR__ . '/balance_helpers.php';
setSecurityHeaders();

startUserSession();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Please log in to view your dashboard data.');
    }

    $userId = (int) $_SESSION['user_id'];
    $db = getDB();

    // Query all enrollments for this user
    $stmt = $db->prepare("SELECT * FROM enrollments WHERE user_id=? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $rawEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $enrollments = [];
    $active = 0;
    $pending = 0;
    $completed = 0;

    foreach ($rawEnrollments as $e) {
        $decrypted = decryptRow($e, ['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'address', 'contact', 'facebook_name', 'notes']);
        $decrypted['current_total_fee'] = balanceTotalFee($decrypted);
        $enrollments[] = $decrypted;

        $status = $e['status'];
        if ($status === 'confirmed') {
            $active++;
        } elseif ($status === 'pending') {
            $pending++;
        } elseif ($status === 'cancelled') {
            // Cancelled status
        }
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'enrollments' => $enrollments,
        'vip_membership' => vipState($db, (int)$_SESSION['user_id']),
        'stats' => [
            'active' => $active,
            'pending' => $pending,
            'completed' => $completed
        ]
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
