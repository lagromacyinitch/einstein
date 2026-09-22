<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';

try {
    $defaults = [
        'tutoring'    => 30,
        'playschool'  => 25,
        'childcare'   => 35,
        'workshop'    => 40,
        'madstudio'   => 30,
        'summerblast' => 30,
    ];

    $db = getDB();
    $db->exec('CREATE TABLE IF NOT EXISTS slot_capacities (program_key VARCHAR(50) PRIMARY KEY, capacity INT UNSIGNED NOT NULL)');

    // Migrate old keys if any exist
    $existing = $db->query('SELECT program_key, capacity FROM slot_capacities')->fetchAll(PDO::FETCH_KEY_PAIR);
    $migrations = [
        'tutoring_regular'     => 'tutoring',
        'playschool_butterfly' => 'playschool',
        'workshop_group'       => 'workshop',
    ];
    foreach ($migrations as $oldKey => $newKey) {
        if (isset($existing[$oldKey])) {
            $cap = $existing[$oldKey];
            $db->prepare('INSERT INTO slot_capacities (program_key, capacity) VALUES (?, ?) ON DUPLICATE KEY UPDATE capacity=?')->execute([$newKey, $cap, $cap]);
            $db->prepare('DELETE FROM slot_capacities WHERE program_key=?')->execute([$oldKey]);
        }
    }

    $insert = $db->prepare('INSERT IGNORE INTO slot_capacities (program_key, capacity) VALUES (?, ?)');
    foreach ($defaults as $key => $defaultCap) {
        $insert->execute([$key, $defaultCap]);
    }

    require_once __DIR__ . '/../includes/student_archive_schema.php';
    ensureStudentArchiveTable($db);
    $filled = array_fill_keys(array_keys($defaults), 0);
    $programs = $db->query("SELECT e.program FROM enrollments e LEFT JOIN student_archives a ON a.enrollment_id=e.id WHERE e.status='confirmed' AND a.archived_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($programs as $program) {
        $name = strtolower($program);
        if (str_contains($name, 'tutor') || str_contains($name, 'academic')) $key = 'tutoring';
        elseif (str_contains($name, 'workshop')) $key = 'workshop';
        elseif (str_contains($name, 'playschool') || str_contains($name, 'play school')) $key = 'playschool';
        elseif (str_contains($name, 'child') || str_contains($name, 'care')) $key = 'childcare';
        elseif (str_contains($name, 'studio') || str_contains($name, 'm.a.d')) $key = 'madstudio';
        elseif (str_contains($name, 'summer') || str_contains($name, 'blast')) $key = 'summerblast';
        else continue;
        $filled[$key]++;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            throw new RuntimeException('Only administrators can edit capacity.');
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $key = trim($data['program'] ?? '');
        $capacity = filter_var($data['capacity'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($key) || !isset($defaults[$key]) || $capacity === false || $capacity < 0 || $capacity > 100000) {
            throw new RuntimeException('Capacity must be a valid whole number between 0 and 100,000.');
        }
        $db->prepare('UPDATE slot_capacities SET capacity=? WHERE program_key=?')->execute([$capacity, $key]);
    }

    echo json_encode([
        'success'    => true,
        'capacities' => $db->query('SELECT program_key, capacity FROM slot_capacities')->fetchAll(PDO::FETCH_KEY_PAIR),
        'filled'     => $filled,
        'can_edit'   => ($_SESSION['role'] ?? '') === 'admin'
    ]);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Unable to save capacity. Please try again.' : $e->getMessage()]);
}
