<?php
function ensureTutorProfileSchema(PDO $db): void {
    foreach (['tutors' => ['teaching_specialization' => 'VARCHAR(255) DEFAULT NULL'], 'admin_accounts' => ['tutor_id' => 'INT DEFAULT NULL', 'email' => 'VARCHAR(191) DEFAULT NULL']] as $table => $columns) {
        $existing = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column => $definition) if (!in_array($column, $existing, true)) $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
function linkTutorAccount(PDO $db, array $account): int {
    $id = (int)($account['tutor_id'] ?? 0);
    if (!$id) {
        $q = $db->prepare('SELECT id FROM tutors WHERE LOWER(full_name)=LOWER(?) AND id NOT IN (SELECT tutor_id FROM admin_accounts WHERE tutor_id IS NOT NULL) ORDER BY id LIMIT 1');
        $q->execute([$account['display_name']]);
        $id = (int)$q->fetchColumn();
        if (!$id) {
            $db->prepare('INSERT INTO tutors (full_name,is_active) VALUES (?,1)')->execute([$account['display_name']]);
            $id = (int)$db->lastInsertId();
        }
        $db->prepare('UPDATE admin_accounts SET tutor_id=? WHERE id=?')->execute([$id,$account['id']]);
    }
    $email = $account['email'] ?: (filter_var($account['username'], FILTER_VALIDATE_EMAIL) ? $account['username'] : null);
    $db->prepare('UPDATE tutors SET full_name=?,email=? WHERE id=?')->execute([$account['display_name'],$email,$id]);
    return $id;
}
function validateTutorEmail(PDO $db, string $email, int $accountId): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>191) throw new Exception('Please enter a valid email address.');
    if (defined('PORTAL_ADMIN_USER') && strcasecmp($email, PORTAL_ADMIN_USER)===0) throw new Exception('That email is reserved for the Head Admin.');
    $q=$db->prepare("SELECT id FROM admin_accounts WHERE id<>? AND (LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?))");
    $q->execute([$accountId,$email,$email]);
    if ($q->fetchColumn()) throw new Exception('That email is already used by another account.');
}
