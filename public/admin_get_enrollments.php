<?php
/**
 * admin_get_enrollments.php
 * Returns all enrollments as JSON — always outputs valid JSON, never HTML
 */

// Catch EVERYTHING before any output
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Always JSON — no matter what happens
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// Safety wrapper — any uncaught error returns clean JSON
set_exception_handler(function($e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'hint'    => 'Check that MySQL is running and tables are created via einstein_mysql_setup.sql'
    ]);
    exit;
});

set_error_handler(function($errno, $errstr) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => "PHP Error ($errno): $errstr",
        'hint'    => 'Check PHP error log for details'
    ]);
    exit;
}, E_ERROR | E_PARSE | E_USER_ERROR);

try {
    require_once __DIR__ . '/config.php';
require_once __DIR__ . '/balance_helpers.php';
    setSecurityHeaders();
    require_once __DIR__ . '/admin_auth.php';

    $db   = getDB();
    require_once __DIR__ . '/student_archive_schema.php';
    ensureStudentArchiveTable($db);
    $stmt = $db->query("
        SELECT e.*, u.email AS user_email, u.email_encrypted AS u_email_enc, u.birthdate AS u_birthdate_enc, sa.archived_at AS student_archived_at
        FROM enrollments e
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN student_archives sa ON sa.enrollment_id = e.id
        ORDER BY e.created_at DESC
    ");
    $rawEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $enrollments = [];
    $counts = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0];

    foreach ($rawEnrollments as $row) {
        $decrypted = decryptRow($row, ['child_name', 'child_age', 'child_grade', 'child_school', 'guardian_name', 'guardian_age', 'address', 'contact', 'facebook_name', 'notes', 'user_email']);
        if (!empty($row['u_email_enc'])) {
            $decrypted['user_email'] = decryptAES256($row['u_email_enc']);
        }
        if (!empty($row['u_birthdate_enc'])) {
            $bdate = decryptAES256($row['u_birthdate_enc']);
            if ($bdate && strtotime($bdate)) {
                $calcAge = (string) (new DateTime($bdate))->diff(new DateTime())->y;
                $decrypted['user_calculated_age'] = $calcAge;
                if (($decrypted['child_age'] === '0' || empty($decrypted['child_age'])) && stripos($decrypted['program'] ?? '', 'vip') !== false) {
                    $decrypted['child_age'] = $calcAge;
                }
                if (empty($decrypted['guardian_age'])) {
                    $decrypted['guardian_age'] = $calcAge;
                }
            }
        }
        if (empty($decrypted['guardian_age']) && stripos($decrypted['program'] ?? '', 'vip') !== false && !empty($decrypted['child_age']) && $decrypted['child_age'] !== '0') {
            $decrypted['guardian_age'] = $decrypted['child_age'];
        }
        $decrypted['current_total_fee'] = balanceTotalFee($decrypted);
        $enrollments[] = $decrypted;

        $s = $row['status'] ?? 'pending';
        if (isset($counts[$s])) $counts[$s]++;
    }

    ob_clean();
    echo json_encode([
        'success'     => true,
        'enrollments' => $enrollments,
        'counts'      => $counts,
    ]);

} catch (PDOException $e) {
    ob_clean();
    http_response_code(200);
    $msg = $e->getMessage();
    // Give helpful hint based on error type
    if (str_contains($msg, 'Access denied') || str_contains($msg, 'SQLSTATE[28000]')) {
        $hint = 'Wrong DB username or password in config.php';
    } elseif (str_contains($msg, "Unknown database") || str_contains($msg, 'SQLSTATE[HY000] [1049]')) {
        $hint = 'Database does not exist. Create it in phpMyAdmin first, then run einstein_mysql_setup.sql';
    } elseif (str_contains($msg, "Table") && str_contains($msg, "doesn't exist")) {
        $hint = 'Tables missing. Run einstein_mysql_setup.sql in phpMyAdmin';
    } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, 'SQLSTATE[HY000] [2002]')) {
        $hint = 'MySQL is not running. Start it in XAMPP Control Panel';
    } else {
        $hint = 'Check DB credentials in config.php';
    }
    echo json_encode(['success' => false, 'message' => $msg, 'hint' => $hint]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
