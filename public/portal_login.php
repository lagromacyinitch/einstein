<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
setSecurityHeaders();

// ── Activity log helper ──────────────────────────────────────────────────────
function logPortalActivity(string $actorName, string $actorRole, string $actionType, string $description): void
{
    try {
        $db = getDB();
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
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) $ip = explode(',', $ip)[0];
        $stmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$actorName, $actorRole, $actionType, $description, $ip]);
    } catch (Exception $e) {
        // Non-blocking — don't fail login if logging fails
        error_log('[ActivityLog] ' . $e->getMessage());
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $post = array_merge($_POST, $input);

    $username = trim($post['username'] ?? $post['email'] ?? '');
    $password = $post['password'] ?? '';

    if (!$username || $password === '') {
        throw new Exception('Username and password are required.');
    }

    $usernameLower = strtolower($username);

    // ── 1. Check Head Admin (hardcoded config) ──────────────────────
    if ($usernameLower === strtolower(PORTAL_ADMIN_USER)) {
        if ($password !== PORTAL_ADMIN_PASS) {
            throw new Exception('Incorrect email/username or password. Please try again.');
        }
        startPortalSession();
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        $_SESSION['portal_username'] = PORTAL_ADMIN_USER;
        $_SESSION['display_name'] = 'Head Admin';
        $_SESSION['is_head_admin'] = true;
        unset($_SESSION['user_id'], $_SESSION['email']);

        logPortalActivity('Head Admin', 'Head Admin', 'LOGIN', 'Head Admin logged in to Admin Portal.');

        ob_clean();
        echo json_encode([
            'success' => true,
            'role' => 'admin',
            'redirect' => 'admin.html',
            'display_name' => 'Head Admin',
        ]);
        exit;
    }

    // ── 2. (Removed) Old hardcoded staff/tutor accounts no longer used.
    //    All sub-admin/tutor accounts are now managed via the DB (admin_accounts table).

    // ── 3. Check DB-backed Admin/Staff accounts (admin_accounts) ───
    try {
        $dbConn = getDB();
        $stmt = $dbConn->prepare("SELECT * FROM admin_accounts WHERE (LOWER(username)=? OR LOWER(COALESCE(email, ''))=?) AND is_active=1");
        $stmt->execute([$usernameLower, $usernameLower]);
        $acc = $stmt->fetch();

        if ($acc && password_verify($password, $acc['password_hash'])) {
            startPortalSession();
            session_regenerate_id(true);
            // All DB accounts are Sub-Admin / Tutor — always redirect to admin.html
            $resolvedRole = 'admin';
            $redirectPage = 'admin.html';

            $_SESSION['role'] = $resolvedRole;
            $_SESSION['portal_username'] = $acc['username'];
            $_SESSION['display_name'] = $acc['display_name'];
            $_SESSION['is_head_admin'] = false;
            $_SESSION['sub_admin_id'] = (int)$acc['id'];
            $_SESSION['email'] = $acc['email'] ?? $acc['username'];

            // Link/set tutor_id in session
            try {
                require_once __DIR__.'/tutor_profile_helpers.php';
                ensureTutorProfileSchema($dbConn);
                $_SESSION['tutor_id'] = linkTutorAccount($dbConn,$acc);
            } catch (Exception $e) { /* ignore */ }

            unset($_SESSION['user_id']);

            logPortalActivity($acc['display_name'], 'Sub-Admin/Tutor', 'LOGIN', 'Sub-Admin/Tutor "' . $acc['display_name'] . '" (' . $acc['username'] . ') logged in.');

            ob_clean();
            echo json_encode([
                'success' => true,
                'role' => $resolvedRole,
                'redirect' => $redirectPage,
                'display_name' => $acc['display_name'],
            ]);
            exit;
        }
    } catch (Exception $e) {
        // Fall through if database error or no matching admin account
    }

    // ── 4. Check Parent / User accounts (users table) ───────────────
    startUserSession();
    session_regenerate_id(true);

    $db = getDB();
    $lookupHash = hashLookup($username);
    $stmt = $db->prepare('SELECT * FROM users WHERE email_hash=? OR username_hash=? OR email=?');
    $stmt->execute([$lookupHash, $lookupHash, strtolower($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new Exception('Incorrect email/username or password. Please try again.');
    }

    if (!$user['is_verified']) {
        throw new Exception('Your email is not verified yet. Please complete sign up and OTP verification first.');
    }

    $decryptedUser = decryptRow($user);
    $userEmail = $decryptedUser['email'] ?? strtolower($username);

    $_SESSION['role'] = 'user';
    unset($_SESSION['returning_portal_visit']);
    $_SESSION['user_id'] = (int) $decryptedUser['id'];
    $_SESSION['email'] = $userEmail;
    unset($_SESSION['portal_username']);

    $displayName = !empty($decryptedUser['firstname']) 
        ? $decryptedUser['firstname'] . ' ' . $decryptedUser['lastname']
        : explode('@', $userEmail)[0];

    ob_clean();
    echo json_encode([
        'success' => true,
        'role' => 'user',
        'user_id' => (int) $decryptedUser['id'],
        'email' => $userEmail,
        'redirect' => 'user.html',
        'display_name' => trim($displayName),
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
