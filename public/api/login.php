<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
try { rateLimit('login_' . md5($_SERVER['REMOTE_ADDR'] ?? ''), 10, 300); } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }

try {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $post     = array_merge($_POST, $input);

    $email    = strtolower(trim($post['email']    ?? ''));
    $password = trim($post['password'] ?? '');

    if (!$email || !$password)
        throw new Exception('Email and password are required.');

    $db   = getDB();
    $emailHash = hashLookup($email);
    $stmt = $db->prepare("SELECT * FROM users WHERE email_hash=? OR email=?");
    $stmt->execute([$emailHash, $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        throw new Exception('Incorrect email or password. Please try again.');

    if (!$user['is_verified'])
        throw new Exception('Your email is not verified yet. Please complete the OTP step first.');

    // Decrypt user row fields for front-end session / response
    $decryptedUser = decryptRow($user);

    startUserSession();
    $_SESSION['role'] = 'user';
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$decryptedUser['id'];
    $_SESSION['email'] = $decryptedUser['email'] ?? $email;
    unset($_SESSION['portal_username']);

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'role'    => 'user',
        'user_id' => (int)$decryptedUser['id'],
        'email'   => $decryptedUser['email'] ?? $email,
        'profile' => [
            'id'             => (int)$decryptedUser['id'],
            'username'       => $decryptedUser['username'] ?? '',
            'firstname'      => $decryptedUser['firstname'] ?? '',
            'lastname'       => $decryptedUser['lastname'] ?? '',
            'middlename'     => $decryptedUser['middlename'] ?? '',
            'birthdate'      => $decryptedUser['birthdate'] ?? '',
            'email'          => $decryptedUser['email'] ?? $email,
            'address'        => $decryptedUser['address'] ?? '',
            'contact_number' => $decryptedUser['contact_number'] ?? ''
        ]
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
