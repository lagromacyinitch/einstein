<?php
/**
 * api_program_spans.php — Program Enrollment Duration / Re-Enrollment Spans API
 *
 * GET                     → Fetch all program enrollment spans
 * POST { spans: [...] }   → Save program enrollment spans (Admin only)
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();

try {
    $db = getDB();

    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS program_enrollment_spans (
        program_key VARCHAR(50) PRIMARY KEY,
        program_name VARCHAR(100) NOT NULL,
        duration_value INT UNSIGNED NOT NULL DEFAULT 1,
        duration_unit ENUM('months', 'years', 'weeks', 'days') NOT NULL DEFAULT 'months',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Default programs and default spans
    $defaults = [
        'tutoring'    => ['name' => 'Academic Tutorial',     'value' => 1, 'unit' => 'months'],
        'playschool'  => ['name' => 'PlaySchool',            'value' => 1, 'unit' => 'months'],
        'childcare'   => ['name' => 'Child Care',             'value' => 1, 'unit' => 'months'],
        'workshop'    => ['name' => 'Weekend Workshop',      'value' => 1, 'unit' => 'months'],
        'madstudio'   => ['name' => 'M.A.D. Studio',         'value' => 1, 'unit' => 'months'],
        'summerblast' => ['name' => 'Summer Blast',          'value' => 2, 'unit' => 'months'],
        'vip'         => ['name' => 'VIP Club Membership',   'value' => 1, 'unit' => 'years']
    ];

    // Seed defaults if missing
    $insertStmt = $db->prepare("INSERT IGNORE INTO program_enrollment_spans (program_key, program_name, duration_value, duration_unit) VALUES (?, ?, ?, ?)");
    foreach ($defaults as $key => $d) {
        $insertStmt->execute([$key, $d['name'], $d['value'], $d['unit']]);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->query("SELECT program_key, program_name, duration_value, duration_unit, updated_at FROM program_enrollment_spans ORDER BY FIELD(program_key, 'tutoring', 'playschool', 'childcare', 'workshop', 'madstudio', 'summerblast', 'vip')");
        $spans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map for easy key-based lookup
        $map = [];
        foreach ($spans as $s) {
            $map[$s['program_key']] = [
                'value' => (int)$s['duration_value'],
                'unit'  => $s['duration_unit'],
                'name'  => $s['program_name']
            ];
        }

        echo json_encode([
            'success' => true,
            'spans'   => $spans,
            'map'     => $map
        ]);
        exit;
    }

    if ($method === 'POST') {
        require_once __DIR__ . '/../includes/admin_auth.php';

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowedUnits = ['months', 'years', 'weeks', 'days'];

        $updateStmt = $db->prepare("INSERT INTO program_enrollment_spans (program_key, program_name, duration_value, duration_unit)
            VALUES (:key, :name, :val, :unit)
            ON DUPLICATE KEY UPDATE
                duration_value = VALUES(duration_value),
                duration_unit = VALUES(duration_unit),
                program_name = VALUES(program_name)");

        // Support batch or single update
        $items = [];
        if (isset($body['spans']) && is_array($body['spans'])) {
            $items = $body['spans'];
        } elseif (isset($body['program_key'])) {
            $items = [$body];
        } else {
            throw new Exception('Invalid payload. Expected "spans" array or "program_key".');
        }

        $db->beginTransaction();
        foreach ($items as $item) {
            $key = strtolower(trim($item['program_key'] ?? ''));
            if (!$key || !isset($defaults[$key])) continue;

            $val = max(1, (int)($item['duration_value'] ?? 1));
            $unit = strtolower(trim($item['duration_unit'] ?? 'months'));
            if (!in_array($unit, $allowedUnits, true)) $unit = 'months';
            $name = $item['program_name'] ?? $defaults[$key]['name'];

            $updateStmt->execute([
                ':key'  => $key,
                ':name' => $name,
                ':val'  => $val,
                ':unit' => $unit
            ]);
        }
        $db->commit();

        // Log admin activity
        try {
            $actor = $_SESSION['display_name'] ?? ($_SESSION['admin_user'] ?? 'Administrator');
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0];
            $logStmt = $db->prepare("INSERT INTO admin_activity_log (actor_name, actor_role, action_type, description, ip_address) VALUES (?, 'Head Admin', 'UPDATE_SETTINGS', 'Updated program enrollment duration spans', ?)");
            $logStmt->execute([$actor, $ip]);
        } catch (Throwable $e) {}

        // Return refreshed list
        $stmt = $db->query("SELECT program_key, program_name, duration_value, duration_unit, updated_at FROM program_enrollment_spans ORDER BY FIELD(program_key, 'tutoring', 'playschool', 'childcare', 'workshop', 'madstudio', 'summerblast', 'vip')");
        $spans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($spans as $s) {
            $map[$s['program_key']] = [
                'value' => (int)$s['duration_value'],
                'unit'  => $s['duration_unit'],
                'name'  => $s['program_name']
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => 'Program enrollment spans updated successfully.',
            'spans'   => $spans,
            'map'     => $map
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

} catch (Throwable $t) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $t->getMessage()]);
}
