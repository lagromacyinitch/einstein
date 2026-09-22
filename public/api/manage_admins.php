<?php
/**
 * manage_admins.php
 * API for Head Admin → manage own credentials + sub-admin accounts.
 * Only the Head Admin (role === 'admin') is allowed.
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();

startPortalSession();

// ── Auth check ──────────────────────────────────────────────────────
$role = $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}
$isHeadAdmin = !empty($_SESSION['is_head_admin']);
$hasAdminAccess = $isHeadAdmin;

if (!$isHeadAdmin && !empty($_SESSION['sub_admin_id'])) {
    try {
        $pDb = getDB();
        $pStmt = $pDb->prepare("SELECT can_edit_prices, nav_permissions FROM admin_accounts WHERE id = ? AND is_active = 1");
        $pStmt->execute([(int)$_SESSION['sub_admin_id']]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $perms = json_decode($pRow['nav_permissions'] ?? '[]', true) ?: [];
            if ($pRow['can_edit_prices'] == 1 || in_array('admin', $perms)) {
                $hasAdminAccess = true;
            }
        }
    } catch (Exception $e) {}
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$post  = array_merge($_POST, $input);
$action = trim($post['action'] ?? $_GET['action'] ?? '');

// ── Activity log helper ──────────────────────────────────────────────────────
function logActivity(PDO $db, string $actionType, string $description): void
{
    global $isHeadAdmin;
    $actorName = $_SESSION['display_name'] ?? $_SESSION['portal_username'] ?? 'Unknown';
    $actorRole = $isHeadAdmin ? 'Head Admin' : 'Sub-Admin';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) $ip = explode(',', $ip)[0];
    try {
        $stmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$actorName, $actorRole, $actionType, $description, $ip]);
    } catch (Exception $e) {
        error_log('[ActivityLog] ' . $e->getMessage());
    }
}

function normalizeName(string $value, string $fieldLabel): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        throw new Exception($fieldLabel . ' is required.');
    }
    if (!preg_match('/^[A-Za-z\s]+$/', $value)) {
        throw new Exception($fieldLabel . ' may only contain letters and spaces.');
    }
    return ucwords(strtolower($value));
}

// ── Ensure Head Admin account is mirrored in database (admin_accounts) ────
function syncHeadAdminToDB(PDO $db, ?string $customUser = null, ?string $customPass = null): void
{
    try {
        $db->exec("ALTER TABLE admin_accounts MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'sub_admin'");
    } catch (Exception $e) {}

    $user = $customUser ?? (defined('PORTAL_ADMIN_USER') ? PORTAL_ADMIN_USER : 'sayaneatch@gmail.com');
    $pass = $customPass ?? (defined('PORTAL_ADMIN_PASS') ? PORTAL_ADMIN_PASS : 'admin1');
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $allNav = json_encode(["dashboard","students","programs","finance","tutors","admin"]);

    $stmt = $db->prepare("SELECT id FROM admin_accounts WHERE role = 'head_admin' OR LOWER(username) = ? OR LOWER(COALESCE(email, '')) = ? LIMIT 1");
    $stmt->execute([strtolower($user), strtolower($user)]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $db->prepare("UPDATE admin_accounts SET display_name = 'Head Admin', username = ?, email = ?, password_hash = ?, role = 'head_admin', is_active = 1, can_edit_prices = 1, can_modify_records = 1, nav_permissions = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$user, $user, $hash, $allNav, $existing['id']]);
    } else {
        $ins = $db->prepare("INSERT INTO admin_accounts (display_name, username, email, password_hash, role, is_active, can_edit_prices, can_modify_records, nav_permissions, created_at) VALUES ('Head Admin', ?, ?, ?, 'head_admin', 1, 1, 1, ?, NOW())");
        $ins->execute([$user, $user, $hash, $allNav]);
    }
}

// ── Ensure admin_accounts table exists ─────────────────────────────
function ensureAdminTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_accounts (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            display_name VARCHAR(120) NOT NULL,
            username     VARCHAR(80)  NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role         VARCHAR(50)  NOT NULL DEFAULT 'sub_admin',
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            can_edit_prices TINYINT(1) NOT NULL DEFAULT 0,
            can_modify_records TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Safely add columns if table already exists without them
    try {
        $db->exec("ALTER TABLE admin_accounts ADD COLUMN can_edit_prices TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }
    try {
        $db->exec("ALTER TABLE admin_accounts ADD COLUMN can_modify_records TINYINT(1) NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }
    try {
        $db->exec("ALTER TABLE admin_accounts ADD COLUMN nav_permissions TEXT DEFAULT NULL");
        $db->exec("ALTER TABLE admin_accounts MODIFY COLUMN username VARCHAR(191) NOT NULL");
        $db->exec("ALTER TABLE admin_accounts ADD COLUMN email VARCHAR(191) DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }
    try {
        $db->exec("ALTER TABLE admin_accounts MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'sub_admin'");
    } catch (Exception $e) {}

    // Ensure head admin row exists and stays in sync with config
    syncHeadAdminToDB($db);
}

try {
    $db = getDB();
    ensureAdminTable($db);
    require_once __DIR__ . '/../includes/tutor_profile_helpers.php';
    ensureTutorProfileSchema($db);
    foreach ($db->query('SELECT * FROM admin_accounts WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) as $account) linkTutorAccount($db,$account);

    switch ($action) {

        // ── GET current head-admin credentials (head admin only) ──
        case 'get_head_credentials':
            if (!$isHeadAdmin) throw new Exception('Head Admin access required.');
            ob_clean();
            echo json_encode([
                'success'  => true,
                'username' => PORTAL_ADMIN_USER,
                'password' => PORTAL_ADMIN_PASS,
            ]);
            break;

        // ── LIST all sub-admins & head admin (admin access required) ─────
        case 'list':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $stmt = $db->query("SELECT id, display_name, username, COALESCE(email, username) AS email, role, is_active, can_edit_prices, can_modify_records, nav_permissions, created_at, updated_at FROM admin_accounts ORDER BY CASE WHEN role = 'head_admin' THEN 0 ELSE 1 END, id ASC");
            $rows = $stmt->fetchAll();
            ob_clean();
            echo json_encode(['success' => true, 'accounts' => $rows]);
            logActivity($db, 'VIEW_SUB_ADMINS', 'Admin viewed accounts list.');
            break;

        // ── TOGGLE PRICE PERMISSION (head admin only) ─────────────
        case 'toggle_price_permission':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $id = (int)($post['id'] ?? 0);
            $canEdit = isset($post['can_edit_prices']) ? (int)(bool)$post['can_edit_prices'] : 0;
            if (!$id) throw new Exception('Account ID required.');
            $chk = $db->prepare("SELECT role FROM admin_accounts WHERE id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() === 'head_admin') {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Head Admin always has full access.']);
                break;
            }
            $stmt = $db->prepare("UPDATE admin_accounts SET can_edit_prices = ?, nav_permissions = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$canEdit, json_encode($canEdit ? ['dashboard','students','programs','finance','tutors','admin'] : []), $id]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Price editing permission updated.']);
            logActivity($db, 'EDIT_SUB_ADMIN', "Toggled price editing permission for sub-admin ID {$id} to " . ($canEdit ? 'Allowed' : 'Disabled') . ".");
            break;

        // ── TOGGLE MODIFY RECORDS PERMISSION (head admin only) ────
        case 'toggle_modify_records_permission':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $id = (int)($post['id'] ?? 0);
            $canModify = isset($post['can_modify_records']) ? (int)(bool)$post['can_modify_records'] : 0;
            if (!$id) throw new Exception('Account ID required.');
            $stmt = $db->prepare("UPDATE admin_accounts SET can_modify_records = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$canModify, $id]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Record modification permission updated.']);
            logActivity($db, 'EDIT_SUB_ADMIN', "Toggled record modification permission for sub-admin ID {$id} to " . ($canModify ? 'Allowed' : 'Disabled') . ".");
            break;

        // ── ADD a new sub-admin (head admin only) ─────────────────
        case 'add':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $displayName = normalizeName($post['display_name'] ?? '', 'Display name');
            $username    = trim($post['username'] ?? '');
            $password    = $post['password'] ?? '';
            $subrole     = 'sub_admin'; // All accounts are unified as Sub-Admin / Tutor

            if (!$username || strlen($password) < 6) {
                throw new Exception('Name, email, and password (min 6 chars) are required.');
            }
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            // Prevent duplicate with head admin username
            if (strtolower($username) === strtolower(PORTAL_ADMIN_USER)) {
                throw new Exception('That email is reserved for the Head Admin.');
            }
            // Prevent duplicate email in admin_accounts
            $chkDup = $db->prepare("SELECT id FROM admin_accounts WHERE LOWER(username) = ? OR LOWER(COALESCE(email, '')) = ?");
            $chkDup->execute([strtolower($username), strtolower($username)]);
            if ($chkDup->fetch()) {
                throw new Exception("An account with email '{$username}' already exists.");
            }

            $canEditPrices = isset($post['can_edit_prices']) ? (int)(bool)$post['can_edit_prices'] : 0;
            $canModifyRecords = isset($post['can_modify_records']) ? (int)(bool)$post['can_modify_records'] : 1;
            $navPerms = json_encode($canEditPrices ? ['dashboard','students','programs','finance','tutors','admin'] : []);
            if (!$canEditPrices && isset($post['nav_permissions']) && is_array($post['nav_permissions'])) {
                $navPerms = json_encode(array_values(array_filter($post['nav_permissions'], 'is_string')));
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("INSERT INTO admin_accounts (display_name, username, email, password_hash, role, can_edit_prices, can_modify_records, nav_permissions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$displayName, strtolower($username), strtolower($username), $hash, $subrole, $canEditPrices, $canModifyRecords, $navPerms]);
            $newAdminId = (int)$db->lastInsertId();

            // Also sync into tutors table
            try {
                $tChk = $db->prepare("SELECT id FROM tutors WHERE LOWER(full_name) = LOWER(?)");
                $tChk->execute([$displayName]);
                if (!$tChk->fetch()) {
                    $db->prepare("INSERT INTO tutors (full_name, is_active) VALUES (?, 1)")->execute([$displayName]);
                }
            } catch (Exception $e) { /* ignore */ }

            ob_clean();
            echo json_encode(['success' => true, 'id' => $newAdminId, 'message' => "Sub-admin '{$displayName}' created."]);
            logActivity($db, 'ADD_SUB_ADMIN', "Added new sub-admin: '{$displayName}' (username: {$username}, role: {$subrole}).");
            break;

        // ── EDIT a sub-admin (head admin only) ────────────────────
        case 'edit':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $id          = (int)($post['id'] ?? 0);
            $displayName = normalizeName($post['display_name'] ?? '', 'Display name');
            $username    = trim($post['username'] ?? '');
            $subrole     = 'sub_admin'; // All accounts are unified as Sub-Admin / Tutor
            $isActive    = isset($post['is_active']) ? (int)(bool)$post['is_active'] : 1;
            $password    = $post['password'] ?? '';

            if (!$id || !$username) {
                throw new Exception('ID, name and email are required.');
            }
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            if (strtolower($username) === strtolower(PORTAL_ADMIN_USER)) {
                throw new Exception('That email is reserved for the Head Admin.');
            }
            $chkDup = $db->prepare("SELECT id FROM admin_accounts WHERE (LOWER(username) = ? OR LOWER(COALESCE(email, '')) = ?) AND id != ?");
            $chkDup->execute([strtolower($username), strtolower($username), $id]);
            if ($chkDup->fetch()) {
                throw new Exception("An account with email '{$username}' already exists.");
            }

            $canEditPrices = isset($post['can_edit_prices']) ? (int)(bool)$post['can_edit_prices'] : 0;
            $canModifyRecords = isset($post['can_modify_records']) ? (int)(bool)$post['can_modify_records'] : 1;
            $navPerms = json_encode($canEditPrices ? ['dashboard','students','programs','finance','tutors','admin'] : []);
            if (!$canEditPrices && isset($post['nav_permissions']) && is_array($post['nav_permissions'])) {
                $navPerms = json_encode(array_values(array_filter($post['nav_permissions'], 'is_string')));
            }

            if ($password !== '') {
                if (strlen($password) < 6) throw new Exception('New password must be at least 6 characters.');
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE admin_accounts SET display_name=?, username=?, email=?, password_hash=?, role=?, is_active=?, can_edit_prices=?, can_modify_records=?, nav_permissions=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$displayName, strtolower($username), strtolower($username), $hash, $subrole, $isActive, $canEditPrices, $canModifyRecords, $navPerms, $id]);
            } else {
                $stmt = $db->prepare("UPDATE admin_accounts SET display_name=?, username=?, email=?, role=?, is_active=?, can_edit_prices=?, can_modify_records=?, nav_permissions=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$displayName, strtolower($username), strtolower($username), $subrole, $isActive, $canEditPrices, $canModifyRecords, $navPerms, $id]);
            }

            ob_clean();
            $sync=$db->prepare('SELECT * FROM admin_accounts WHERE id=?');
            $sync->execute([$id]);
            linkTutorAccount($db,$sync->fetch(PDO::FETCH_ASSOC));
            echo json_encode(['success' => true, 'message' => 'Sub-admin updated.']);
            logActivity($db, 'EDIT_SUB_ADMIN', "Updated sub-admin ID {$id}: name='{$displayName}', username='{$username}', role='{$subrole}', active={$isActive}" . ($password !== '' ? ', password changed' : '') . ".");
            break;

        // ── DELETE a sub-admin (head admin only) ──────────────────
        case 'delete':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $id = (int)($post['id'] ?? 0);
            if (!$id) throw new Exception('ID is required.');
            $chk = $db->prepare("SELECT role FROM admin_accounts WHERE id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() === 'head_admin') {
                throw new Exception('The Head Admin account cannot be deleted.');
            }
            $stmt = $db->prepare("DELETE FROM admin_accounts WHERE id=?");
            $stmt->execute([$id]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Sub-admin removed.']);
            logActivity($db, 'DELETE_SUB_ADMIN', "Deleted sub-admin with ID {$id}.");
            break;

        // ── CHANGE HEAD ADMIN credentials (head admin only) ───────
        case 'change_head_credentials':
            if (!$isHeadAdmin) {
                throw new Exception('Only the Head Admin can change Head Admin credentials.');
            }
            $currentPass = $post['current_password'] ?? '';
            $newUser     = trim($post['new_username'] ?? '');
            $newPass     = $post['new_password'] ?? '';
            $confirmPass = $post['confirm_password'] ?? '';

            if ($currentPass !== PORTAL_ADMIN_PASS) {
                throw new Exception('Current password is incorrect.');
            }
            // Accept a valid email OR a plain username (letters/numbers/._-).
            if ($newUser) {
                $newUserIsEmail = filter_var($newUser, FILTER_VALIDATE_EMAIL) !== false;
                if (!$newUserIsEmail && !preg_match('/^[a-zA-Z0-9_.\-]+$/', $newUser)) {
                    throw new Exception('Username may only contain letters, numbers, underscores, hyphens and dots — or use a valid email address.');
                }
            }
            if ($newPass !== '' && $newPass !== $confirmPass) {
                throw new Exception('New passwords do not match.');
            }
            if ($newPass !== '' && strlen($newPass) < 6) {
                throw new Exception('New password must be at least 6 characters.');
            }

            // Write updated constants to config.php
            $configPath = CONFIG_PATH;
            $configContent = file_get_contents($configPath);

            if ($newUser) {
                $configContent = preg_replace(
                    "/define\('PORTAL_ADMIN_USER',\s*'[^']*'\);/",
                    "define('PORTAL_ADMIN_USER', '" . addslashes($newUser) . "');",
                    $configContent
                );
                // If it is an email, also persist it as PORTAL_ADMIN_EMAIL for forgot-password OTP.
                if (filter_var($newUser, FILTER_VALIDATE_EMAIL) !== false) {
                    $emailLine = "define('PORTAL_ADMIN_EMAIL', '" . addslashes($newUser) . "'); // Head Admin recovery email – set via Admin > Credentials";
                    if (preg_match("/define\\('PORTAL_ADMIN_EMAIL',/", $configContent)) {
                        $configContent = preg_replace(
                            "/define\\('PORTAL_ADMIN_EMAIL',\s*'[^']*'\\);[^\n]*/",
                            $emailLine,
                            $configContent
                        );
                    } else {
                        $configContent = preg_replace(
                            "/(define\\('PORTAL_ADMIN_PASS',\s*'[^']*'\\);)/",
                            "$1\n" . $emailLine,
                            $configContent
                        );
                    }
                }
            }
            if ($newPass !== '') {
                $configContent = preg_replace(
                    "/define\('PORTAL_ADMIN_PASS',\s*'[^']*'\);/",
                    "define('PORTAL_ADMIN_PASS', '" . addslashes($newPass) . "');",
                    $configContent
                );
            }

            file_put_contents($configPath, $configContent);

            // Sync with admin_accounts database table
            $finalUser = $newUser ?: PORTAL_ADMIN_USER;
            $finalPass = $newPass !== '' ? $newPass : PORTAL_ADMIN_PASS;
            syncHeadAdminToDB($db, $finalUser, $finalPass);

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Head Admin credentials updated. Please log in again.', 'require_relogin' => true]);
            logActivity($db, 'CHANGE_HEAD_CREDENTIALS', 'Head Admin changed own login credentials' . ($newUser ? " (new username: {$newUser})" : '') . ($newPass !== '' ? ', password changed' : '') . ".");
            break;

        // ── SUB-ADMIN changes their OWN credentials ────────────────
        case 'change_self_credentials':
            if ($isHeadAdmin) throw new Exception('Only sub-admins may use this action.');

            $selfId      = (int)($_SESSION['sub_admin_id'] ?? 0);
            if (!$selfId) throw new Exception('Session error: sub-admin ID not found. Please re-login.');

            $currentPass = $post['current_password'] ?? '';
            $newUser     = trim($post['new_username'] ?? '');
            $newPass     = $post['new_password'] ?? '';
            $confirmPass = $post['confirm_password'] ?? '';

            // Verify current password against DB
            $stmt = $db->prepare("SELECT password_hash, display_name FROM admin_accounts WHERE id=?");
            $stmt->execute([$selfId]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('Account not found.');
            if (!password_verify($currentPass, $row['password_hash'])) {
                throw new Exception('Current password is incorrect.');
            }

            if ($newUser !== '') {
                if (!filter_var($newUser, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address.');
                }
                if (strtolower($newUser) === strtolower(PORTAL_ADMIN_USER)) {
                    throw new Exception('That email is reserved for the Head Admin.');
                }
                // Check uniqueness (excluding self)
                $uStmt = $db->prepare("SELECT id FROM admin_accounts WHERE (LOWER(username)=? OR LOWER(COALESCE(email,''))=?) AND id!=?");
                $uStmt->execute([strtolower($newUser), strtolower($newUser), $selfId]);
                if ($uStmt->fetch()) throw new Exception('That email is already in use by another account.');
            }

            if ($newPass !== '') {
                if (strlen($newPass) < 6) throw new Exception('New password must be at least 6 characters.');
                if ($newPass !== $confirmPass) throw new Exception('New passwords do not match.');
            }

            // Apply updates
            if ($newUser !== '' && $newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE admin_accounts SET username=?, email=?, password_hash=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([strtolower($newUser), strtolower($newUser), $hash, $selfId]);
                $_SESSION['portal_username'] = strtolower($newUser);
            } elseif ($newUser !== '') {
                $stmt = $db->prepare("UPDATE admin_accounts SET username=?, email=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([strtolower($newUser), strtolower($newUser), $selfId]);
                $_SESSION['portal_username'] = strtolower($newUser);
            } elseif ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE admin_accounts SET password_hash=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$hash, $selfId]);
            } else {
                throw new Exception('No changes submitted.');
            }

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Your credentials have been updated.']);
            logActivity($db, 'CHANGE_SELF_CREDENTIALS', 'Sub-Admin updated own credentials' . ($newUser !== '' ? " (new username: {$newUser})" : '') . ($newPass !== '' ? ', password changed' : '') . ".");
            break;

        // ── TOGGLE price-editing permission for a sub-admin (head admin only) ──
        case 'toggle_price_permission':
            if (!$hasAdminAccess) throw new Exception('Admin access required.');
            $id = (int)($post['id'] ?? 0);
            $canEdit = isset($post['can_edit_prices']) ? (int)(bool)$post['can_edit_prices'] : 0;
            if (!$id) throw new Exception('Sub-admin ID is required.');
            $navPerms = $canEdit ? json_encode(['dashboard', 'students', 'programs', 'finance', 'tutors', 'admin']) : json_encode([]);
            $stmt = $db->prepare("UPDATE admin_accounts SET can_edit_prices=?, nav_permissions=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$canEdit, $navPerms, $id]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Portal access and permissions updated.']);
            logActivity($db, 'TOGGLE_PRICE_PERM', 'Head Admin ' . ($canEdit ? 'granted' : 'revoked') . " portal access and edit permissions for sub-admin ID {$id}.");
            break;

        default:
            throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
