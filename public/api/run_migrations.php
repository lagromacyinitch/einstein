<?php
// Runs pending database/migrations/*.sql (copied to includes/migrations/ by the
// deploy workflow) against this server's database.
// The live database only accepts connections from the server itself, so the
// deploy workflow calls this with MIGRATION_TOKEN instead of connecting directly.
// Without a token in includes/.env the endpoint answers 404 to everyone.
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/migrations.php';

$token = (string)(loadEnv()['MIGRATION_TOKEN'] ?? '');
$given = (string)($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strlen($token) < 32 || !hash_equals($token, $given)) {
    http_response_code(404);
    exit;
}

set_time_limit(300);
try {
    foreach (runMigrations(getDB(), EINSTEIN_ROOT . '/includes/migrations') as $line) {
        echo $line, "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'FAILED: ', $e->getMessage(), "\n";
}
