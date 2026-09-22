<?php
/**
 * Check Session — logged-in state and role (user | staff | admin)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();

$portalPreference = $_GET['portal'] ?? $_GET['prefer'] ?? $_COOKIE['einstein_active_portal'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (empty($portalPreference) && str_contains($referer, 'user.html')) {
    $portalPreference = 'user';
}

function checkUserSessionData(): ?array {
    startUserSession();
    $role = $_SESSION['role'] ?? null;
    if (!$role && isset($_SESSION['user_id'])) {
        $role = 'user';
        $_SESSION['role'] = 'user';
    }
    if (($role === 'user' || empty($role)) && !empty($_SESSION['user_id'])) {
        if (empty($_SESSION['email'])) {
            try {
                $db = getDB();
                $uStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                $uStmt->execute([$_SESSION['user_id']]);
                $_SESSION['email'] = $uStmt->fetchColumn() ?: '';
            } catch (Exception $e) {}
        }
        $isVip = false;
        $hasPendingVip = false;
        try {
            $db = getDB();
            require_once __DIR__ . '/../includes/vip_membership.php';
            $vState = vipState($db, (int) $_SESSION['user_id']);
            $isVip = !empty($vState['active']);
            $hasPendingVip = !empty($vState['pending']);
        } catch (Exception $e) {
            $isVip = false;
            $hasPendingVip = false;
        }
        $data = [
            'logged_in'   => true,
            'role'        => 'user',
            'user_id'     => $_SESSION['user_id'],
            'email'       => $_SESSION['email'] ?? '',
            'is_vip'      => $isVip,
            'vip_pending' => $hasPendingVip,
        ];
        session_write_close();
        return $data;
    }
    session_write_close();
    return null;
}

function checkAdminSessionData(): ?array {
    $adminCookie = $_COOKIE[ADMIN_SESSION_NAME] ?? null;
    if (!$adminCookie) return null;
    startPortalSession();
    $role = $_SESSION['role'] ?? null;
    if ($role === 'admin' && isset($_SESSION['portal_username'])) {
        $isHead = !empty($_SESSION['is_head_admin']);
        $canEditPrices = true;
        $canModifyRecords = true;
        if (!$isHead) {
            $canEditPrices = false;
            $canModifyRecords = true;
            $subAdminId = (int)($_SESSION['sub_admin_id'] ?? 0);
            if ($subAdminId) {
                try {
                    $db = getDB();
                    require_once __DIR__ . '/../includes/tutor_profile_helpers.php';
                    ensureTutorProfileSchema($db);
                    $stmt = $db->prepare("SELECT can_edit_prices, can_modify_records, nav_permissions, display_name, id, username, email, tutor_id FROM admin_accounts WHERE id = ?");
                    $stmt->execute([$subAdminId]);
                    $aRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($aRow) {
                        $canEditPrices = (bool)$aRow['can_edit_prices'];
                        $canModifyRecords = isset($aRow['can_modify_records']) ? (bool)$aRow['can_modify_records'] : true;
                        $navPermsRaw = $aRow['nav_permissions'] ?? null;
                        $navPermissions = ($navPermsRaw && $decoded = json_decode($navPermsRaw, true)) ? $decoded : [];
                        if ($canEditPrices) $navPermissions = ['dashboard','students','programs','finance','tutors','admin'];
                        require_once __DIR__ . '/../includes/tutor_profile_helpers.php';
                        ensureTutorProfileSchema($db);
                        $_SESSION['tutor_id'] = linkTutorAccount($db,$aRow);
                        $_SESSION['display_name'] = $aRow['display_name'];
                    }
                } catch (Exception $e) {
                    $canEditPrices = false;
                }
            }
        }
        $data = [
            'logged_in'          => true,
            'role'               => 'admin',
            'username'           => $_SESSION['portal_username'],
            'display_name'       => $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Admin',
            'is_head_admin'      => $isHead,
            'can_edit_prices'    => $canEditPrices,
            'can_modify_records' => $canModifyRecords,
            'nav_permissions'    => $navPermissions ?? null,
            'tutor_id'           => $_SESSION['tutor_id'] ?? null,
        ];
        session_write_close();
        return $data;
    }
    if ($role === 'staff' && isset($_SESSION['portal_username'])) {
        $data = [
            'logged_in'    => true,
            'role'         => 'staff',
            'username'     => $_SESSION['portal_username'],
            'display_name' => $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Staff',
            'is_head_admin'=> false,
        ];
        session_write_close();
        return $data;
    }
    session_write_close();
    return null;
}

if ($portalPreference === 'user') {
    $uData = checkUserSessionData();
    if ($uData) {
        echo json_encode($uData);
        exit;
    }
    $aData = checkAdminSessionData();
    if ($aData) {
        echo json_encode($aData);
        exit;
    }
} else {
    $aData = checkAdminSessionData();
    if ($aData && $portalPreference === 'admin') {
        echo json_encode($aData);
        exit;
    }
    // If no preference specified, check if user session exists first if no admin session
    if ($aData) {
        echo json_encode($aData);
        exit;
    }
    $uData = checkUserSessionData();
    if ($uData) {
        echo json_encode($uData);
        exit;
    }
}

echo json_encode([
    'logged_in' => false,
    'role'      => null,
]);
