<?php
/**
 * Applies the SQL files in includes/migrations/ that this database has not run yet.
 * They are kept in database/migrations/; the deploy workflow copies them here.
 *
 * Files run once each, in filename order, and are recorded in schema_migrations
 * with a checksum so an edited file is refused instead of silently skipped.
 * A file may start with "-- backup: table_a, table_b" to copy those tables to
 * _bak_<timestamp>_<table> before it runs.
 */

function runMigrations(PDO $db, string $dir): array
{
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(191) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = $db->query("SELECT filename, checksum FROM schema_migrations")->fetchAll(PDO::FETCH_KEY_PAIR);
    $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    $log = [];
    foreach ($files as $path) {
        $name = basename($path);
        // Git may check the file out with CRLF on Windows and LF on the server.
        $sql = str_replace("\r\n", "\n", (string)file_get_contents($path));
        $checksum = hash('sha256', $sql);

        if (isset($applied[$name])) {
            if (!hash_equals($applied[$name], $checksum)) {
                throw new RuntimeException("$name was edited after it ran. Put the change in a new migration instead.");
            }
            continue;
        }

        $stamp = date('YmdHis');
        foreach (migrationBackupTables($sql) as $table) {
            $copy = "_bak_{$stamp}_{$table}";
            if (strlen($copy) > 64) {
                throw new RuntimeException("$name: backup name $copy is longer than MySQL allows.");
            }
            $db->exec("CREATE TABLE `$copy` LIKE `$table`");
            $db->exec("INSERT INTO `$copy` SELECT * FROM `$table`");
            $log[] = "Backed up $table to $copy";
        }

        // MySQL commits DDL immediately, so a file that fails halfway keeps the
        // statements before the failure. Keep each file to one change.
        try {
            $stmt = $db->query($sql);
            // A multi-statement query only reports a later statement's error
            // when its result is reached.
            while ($stmt->nextRowset()) {
            }
            $stmt->closeCursor();
        } catch (PDOException $e) {
            throw new RuntimeException("$name failed: " . $e->getMessage(), 0, $e);
        }

        $db->prepare("INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)")->execute([$name, $checksum]);
        $log[] = "Applied $name";
    }

    if (!$log) {
        $log[] = 'No pending migrations.';
    }
    return $log;
}

function migrationBackupTables(string $sql): array
{
    if (!preg_match('/^--\s*backup:\s*(.+)$/mi', $sql, $m)) {
        return [];
    }
    $tables = array_filter(array_map('trim', explode(',', $m[1])), 'strlen');
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException("Invalid table name in backup line: $table");
        }
    }
    return array_values($tables);
}
