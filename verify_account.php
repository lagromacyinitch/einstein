<?php
// ─────────────────────────────────────────────
//  Einstein Center — Mark Account as Verified
//  Called after OTP success to set is_verified=1
// ─────────────────────────────────────────────
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
setSecurityHeaders();
try { rateLimit('verify_'   . md5($_SERVER['REMOTE_ADDR'] ?? ''), 10, 300); } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }

try {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $post   = array_merge($_POST, $input);
    $email  = strtolower(trim($post['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new Exception('Valid email is required.');

    $db   = getDB();
    $stmt = $db->prepare("UPDATE users SET is_verified=1 WHERE email=?");
    $stmt->execute([$email]);

    if ($stmt->rowCount() === 0)
        throw new Exception('User not found. Please create an account first.');

    // Fetch user details to set session
    $userStmt = $db->prepare("SELECT id FROM users WHERE email=?");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if ($user) {
        startUserSession();
        $_SESSION['role'] = 'user';
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['email'] = $email;
        unset($_SESSION['portal_username']);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Account verified.',
        'user_id' => $user ? (int)$user['id'] : null
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
