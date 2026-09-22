<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();

// Account verification is intentionally performed only by api_otp.php after it
// validates a current OTP. Keeping this endpoint non-mutating prevents anyone
// from verifying an arbitrary email address by posting it directly.
ob_clean();
echo json_encode([
    'success' => false,
    'message' => 'Account verification is completed after a valid OTP is entered.'
]);
