<?php
/**
 * Logout - End user session
 * Destroys session and returns JSON response
 */
require_once __DIR__ . '/config.php';
setSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Helper to reliably expire cookies across all common path variations
function expireAllCookiePaths(string $cookieName): void {
    $params = session_get_cookie_params();
    $paths = ['/', '', '/EINSTEIN-WEB18', '/EINSTEIN-WEB18/', $params['path'] ?? '/'];
    $paths = array_unique(array_filter($paths, fn($p) => $p !== null));
    foreach ($paths as $p) {
        setcookie($cookieName, '', [
            'expires'  => time() - 86400 * 30,
            'path'     => $p,
            'domain'   => $params['domain'] ?? '',
            'secure'   => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        setcookie($cookieName, '', time() - 86400 * 30, $p);
    }
    if (isset($_COOKIE[$cookieName])) {
        unset($_COOKIE[$cookieName]);
    }
}

// 1. Destroy portal / admin session
startPortalSession();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 2. Destroy user session
startUserSession();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 3. Clear all session cookies & portal cookies
$sessNames = array_unique([
    ini_get('session.name') ?: 'PHPSESSID',
    'PHPSESSID',
    ADMIN_SESSION_NAME,
    'EINSTEIN_ADMIN_SESSID',
    'einstein_active_portal'
]);

foreach ($sessNames as $sName) {
    expireAllCookiePaths($sName);
}

// Clear PHP session superglobal
$_SESSION = [];

echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully'
]);
exit;
