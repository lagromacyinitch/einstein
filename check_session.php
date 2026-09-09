<?php
/**
 * Check Session — logged-in state and role (user | staff | admin)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
setSecurityHeaders();

$defaultName = ini_get('session.name');
$adminCookie = $_COOKIE[ADMIN_SESSION_NAME] ?? null;
$userCookie  = $_COOKIE[$defaultName] ?? null;

// ── Check admin/staff portal session FIRST ──────────────────────
if ($adminCookie) {
    startPortalSession();
    $role = $_SESSION['role'] ?? null;
    if ($role === 'admin' && isset($_SESSION['portal_username'])) {
        $isHead = !empty($_SESSION['is_head_admin']);
        $canEditPrices = true;
        $canModifyRecords = true;
        if (!$isHead) {
            $canEditPrices = false;
            $canModifyRecords = true; // default: allow unless explicitly disabled
            $subAdminId = (int)($_SESSION['sub_admin_id'] ?? 0);
            if ($subAdminId) {
                try {
                    $db = getDB();
                    $stmt = $db->prepare("SELECT can_edit_prices, can_modify_records, display_name FROM admin_accounts WHERE id = ?");
                    $stmt->execute([$subAdminId]);
                    $aRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($aRow) {
                        $canEditPrices = (bool)$aRow['can_edit_prices'];
                        // can_modify_records defaults to 1 if column doesn't exist yet
                        $canModifyRecords = isset($aRow['can_modify_records']) ? (bool)$aRow['can_modify_records'] : true;
                        // Ensure tutor_id is set
                        if (empty($_SESSION['tutor_id']) && !empty($aRow['display_name'])) {
                            $tChk = $db->prepare("SELECT id FROM tutors WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
                            $tChk->execute([$aRow['display_name']]);
                            $tId = $tChk->fetchColumn();
                            if ($tId) {
                                $_SESSION['tutor_id'] = (int)$tId;
                            } else {
                                $db->prepare("INSERT INTO tutors (full_name, is_active) VALUES (?, 1)")->execute([$aRow['display_name']]);
                                $_SESSION['tutor_id'] = (int)$db->lastInsertId();
                            }
                        }
                    }
                } catch (Exception $e) {
                    $canEditPrices = false;
                }
            }
        }
        echo json_encode([
            'logged_in'          => true,
            'role'               => 'admin',
            'username'           => $_SESSION['portal_username'],
            'display_name'       => $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Admin',
            'is_head_admin'      => $isHead,
            'can_edit_prices'    => $canEditPrices,
            'can_modify_records' => $canModifyRecords,
            'tutor_id'           => $_SESSION['tutor_id'] ?? null,
        ]);
        exit;
    }
    if ($role === 'staff' && isset($_SESSION['portal_username'])) {
        echo json_encode([
            'logged_in'    => true,
            'role'         => 'staff',
            'username'     => $_SESSION['portal_username'],
            'display_name' => $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Staff',
            'is_head_admin'=> false,
        ]);
        exit;
    }
    session_write_close();
}

// ── Then check parent/user session ──────────────────────────────
if ($userCookie) {
    startUserSession();
    $role = $_SESSION['role'] ?? null;
    if (!$role && isset($_SESSION['user_id'], $_SESSION['email'])) {
        $role = 'user';
        $_SESSION['role'] = 'user';
    }
    if ($role === 'user' && isset($_SESSION['user_id'], $_SESSION['email'])) {
        $isVip = false;
        try {
            $db = getDB();
            $stmtVip = $db->prepare("SELECT created_at, updated_at FROM enrollments WHERE user_id = ? AND status = 'confirmed' AND LOWER(program) LIKE '%vip%' ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1");
            $stmtVip->execute([(int)$_SESSION['user_id']]);
            $vipRow = $stmtVip->fetch(PDO::FETCH_ASSOC);
            if ($vipRow) {
                $vDate = strtotime($vipRow['updated_at'] ?: $vipRow['created_at']);
                if ($vDate && (time() - $vDate) < (2 * 365 * 86400)) {
                    $isVip = true;
                }
            }
        } catch (Exception $e) {
            $isVip = false;
        }

        echo json_encode([
            'logged_in' => true,
            'role'      => 'user',
            'user_id'   => $_SESSION['user_id'],
            'email'     => $_SESSION['email'],
            'is_vip'    => $isVip,
        ]);
        exit;
    }
    session_write_close();
}

echo json_encode([
    'logged_in' => false,
    'role'      => null,
]);
