<?php
/**
 * Logout - End user session
 * Destroys session and returns JSON response
 */
require_once __DIR__ . '/config.php';
setSecurityHeaders();
header('Content-Type: application/json');

$defaultName = ini_get('session.name');
destroySessionByName($defaultName);
destroySessionByName(ADMIN_SESSION_NAME);

echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully'
]);
?>
