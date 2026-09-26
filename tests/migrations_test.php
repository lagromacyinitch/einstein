<?php
// Needs the local XAMPP MySQL; works in a throwaway database it drops afterwards.
require_once __DIR__ . '/../public/includes/migrations.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function throws(callable $fn, string $needle): bool {
    try { $fn(); } catch (RuntimeException $e) { return str_contains($e->getMessage(), $needle); }
    return false;
}

$db = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
]);
$db->exec('DROP DATABASE IF EXISTS einstein_migrations_test');
$db->exec('CREATE DATABASE einstein_migrations_test');
$db->exec('USE einstein_migrations_test');
$dir = sys_get_temp_dir() . '/einstein_migrations_' . getmypid();
@mkdir($dir);

try {
    file_put_contents("$dir/001_create.sql", "-- Is this ok? A comment with a question mark.\nCREATE TABLE notes (id INT PRIMARY KEY, body TEXT);\nINSERT INTO notes VALUES (1, 'a;b?');\n");
    file_put_contents("$dir/002_more.sql", "INSERT INTO notes VALUES (2, 'two');\r\nINSERT INTO notes VALUES (3, 'three');\r\n");
    check(runMigrations($db, $dir) === ['Applied 001_create.sql', 'Applied 002_more.sql'], 'Runs files in order');
    check((int)$db->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 3, 'Every statement in a file runs');
    check(runMigrations($db, $dir) === ['No pending migrations.'], 'Applied files do not run again');

    file_put_contents("$dir/002_more.sql", "INSERT INTO notes VALUES (2, 'two');\nINSERT INTO notes VALUES (3, 'three');\n");
    check(runMigrations($db, $dir) === ['No pending migrations.'], 'CRLF vs LF is not an edit');
    file_put_contents("$dir/002_more.sql", "INSERT INTO notes VALUES (9, 'changed');\n");
    check(throws(fn() => runMigrations($db, $dir), 'was edited'), 'Edited applied file is refused');
    file_put_contents("$dir/002_more.sql", "INSERT INTO notes VALUES (2, 'two');\nINSERT INTO notes VALUES (3, 'three');\n");

    file_put_contents("$dir/003_bad.sql", "INSERT INTO notes VALUES (4, 'four');\nINSERT INTO missing_table VALUES (1);\n");
    check(throws(fn() => runMigrations($db, $dir), '003_bad.sql failed'), 'Error in a later statement is reported');
    check(!$db->query("SELECT 1 FROM schema_migrations WHERE filename = '003_bad.sql'")->fetchColumn(), 'Failed file is not recorded');
    unlink("$dir/003_bad.sql");
    $db->exec('DELETE FROM notes WHERE id = 4');

    file_put_contents("$dir/004_wipe.sql", "-- backup: notes\nDELETE FROM notes;\n");
    $log = runMigrations($db, $dir);
    check(count($log) === 2 && str_starts_with($log[0], 'Backed up notes to _bak_'), 'Backup runs before the file');
    $copy = substr($log[0], strlen('Backed up notes to '));
    check((int)$db->query("SELECT COUNT(*) FROM `$copy`")->fetchColumn() === 3, 'Backup holds the rows');
    check((int)$db->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 0, 'Migration ran after backup');

    check(throws(fn() => migrationBackupTables("-- backup: notes; DROP TABLE x\n"), 'Invalid table name'), 'Backup names are validated');
} finally {
    $db->exec('DROP DATABASE IF EXISTS einstein_migrations_test');
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
}
echo "PASS: ordering, run-once, CRLF, edited-file guard, later-statement errors, backups.\n";
