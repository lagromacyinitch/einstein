<?php
/**
 * Require an active admin session for admin API endpoints.
 */
startPortalSession();

$role = $_SESSION['role'] ?? '';
if ($role !== 'admin' && $role !== 'staff') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin or staff access required.']);
    exit;
}
