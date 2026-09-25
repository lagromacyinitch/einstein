<?php
/**
 * admin_accounts table helpers shared by login, admin management and password reset.
 *
 * The Head Admin is the admin_accounts row with role 'head_admin'; the database is
 * the only source of truth for its username and password. HEAD_ADMIN_USER and
 * HEAD_ADMIN_PASS in includes/.env are used once, to create that row when it is missing.
 */

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
        $db->exec("ALTER TABLE admin_accounts ADD COLUMN display_name VARCHAR(120) NOT NULL DEFAULT 'Head Admin'");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }
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
}

// ── Head Admin row (created from .env on first use) ────────────────
function getHeadAdmin(PDO $db): ?array
{
    $find = "SELECT * FROM admin_accounts WHERE role = 'head_admin' ORDER BY id ASC LIMIT 1";
    try {
        $row = $db->query($find)->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fresh database: the table does not exist yet.
        ensureAdminTable($db);
        $row = $db->query($find)->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) {
        return $row;
    }
    if (HEAD_ADMIN_SEED_USER === '' || HEAD_ADMIN_SEED_PASS === '') {
        return null;
    }

    $user = strtolower(HEAD_ADMIN_SEED_USER);
    $allNav = json_encode(["dashboard","students","programs","finance","tutors","admin"]);
    $ins = $db->prepare("INSERT INTO admin_accounts (display_name, username, email, password_hash, role, is_active, can_edit_prices, can_modify_records, nav_permissions, created_at) VALUES ('Head Admin', ?, ?, ?, 'head_admin', 1, 1, 1, ?, NOW())");
    $ins->execute([$user, $user, password_hash(HEAD_ADMIN_SEED_PASS, PASSWORD_DEFAULT), $allNav]);
    return $db->query($find)->fetch(PDO::FETCH_ASSOC) ?: null;
}
