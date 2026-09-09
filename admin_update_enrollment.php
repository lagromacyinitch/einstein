<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';
setSecurityHeaders();
require_once __DIR__ . '/admin_auth.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $post = array_merge($_POST, $input);

    $id = (int) ($post['enrollment_id'] ?? $post['id'] ?? 0);
    $action = strtolower(trim($post['action'] ?? ''));

    if ($id <= 0) {
        throw new Exception('Invalid enrollment ID.');
    }

    // ── Balance Monitoring Action (Head Admin Only) ─────────────────
    if (in_array($action, ['record_balance', 'settle_balance', 'undo_balance'], true)) {
        if (empty($_SESSION['is_head_admin'])) {
            throw new Exception('Only the Head Admin is authorized to record or modify balances.');
        }
        $db = getDB();

        if ($action === 'undo_balance') {
            $stmt = $db->prepare("UPDATE enrollments SET admin_notes = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Balance status reset.']);
            exit;
        }

        $paidAmount = isset($post['paid_amount']) ? (float)$post['paid_amount'] : 0.0;
        $balanceDue = isset($post['balance_due']) ? (float)$post['balance_due'] : 0.0;
        $isSettled = !empty($post['is_settled']) || $balanceDue <= 0.001;
        $paymentMethod = htmlspecialchars(strip_tags(trim($post['payment_method'] ?? 'Walk-in (Cash)')), ENT_QUOTES, 'UTF-8');
        $receiptNo = htmlspecialchars(strip_tags(trim($post['receipt_no'] ?? '')), ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars(strip_tags(trim($post['note'] ?? '')), ENT_QUOTES, 'UTF-8');

        $balancePayload = [
            'balance_settled' => $isSettled,
            'paid_amount'     => $paidAmount,
            'balance_due'     => $balanceDue,
            'payment_method'  => $paymentMethod,
            'receipt_no'      => $receiptNo,
            'settled_at'      => date('Y-m-d H:i:s'),
            'admin_note'      => $note
        ];

        $stmt = $db->prepare("UPDATE enrollments SET admin_notes = ?, payment_status = 'confirmed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([json_encode($balancePayload), $id]);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Balance payment recorded successfully.',
            'balance' => $balancePayload
        ]);
        exit;
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new Exception('Invalid action. Use approve or reject.');
    }

    $status = $action === 'approve' ? 'confirmed' : 'cancelled';
    $paymentStatus = $action === 'approve' ? 'confirmed' : 'rejected';

    $db = getDB();

    // Fetch enrollment details for email notification
    $row = $db->prepare("SELECT child_name, guardian_name, program, package_selected, reference_no, u.email AS parent_email
        FROM enrollments e
        LEFT JOIN users u ON u.id = e.user_id
        WHERE e.id = ?");
    $row->execute([$id]);
    $enroll = $row->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        UPDATE enrollments
        SET status = ?, payment_status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $paymentStatus, $id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Enrollment not found.');
    }

    // Send email notification to parent
    if ($enroll && !empty($enroll['parent_email'])) {
        $childName    = htmlspecialchars($enroll['child_name'] ?? '');
        $program      = htmlspecialchars($enroll['program'] ?? '');
        $package      = htmlspecialchars($enroll['package_selected'] ?? '');
        $refNo        = htmlspecialchars($enroll['reference_no'] ?? 'N/A');
        $guardianName = htmlspecialchars($enroll['guardian_name'] ?? 'Parent/Guardian');

        if ($action === 'approve') {
            $subject = "✅ Enrollment Approved — Einstein Child Care & Learning Center";
            $body = "
            <div style='font-family:Georgia,serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5d9ce'>
              <div style='background:#3d1f0a;padding:28px 32px;text-align:center'>
                <h1 style='color:#DAB55C;font-size:22px;margin:0;letter-spacing:.04em'>Einstein Center</h1>
                <p style='color:rgba(255,255,255,.6);font-size:13px;margin:6px 0 0'>Child Care & Learning Center</p>
              </div>
              <div style='padding:32px'>
                <h2 style='color:#2e7d32;font-size:18px;margin:0 0 16px'>🎉 Enrollment Approved!</h2>
                <p style='color:#555;line-height:1.6'>Dear <strong>{$guardianName}</strong>,</p>
                <p style='color:#555;line-height:1.6'>We are pleased to inform you that the enrollment of <strong>{$childName}</strong> has been <strong>approved</strong>.</p>
                <div style='background:#f9f5f0;border-left:4px solid #DAB55C;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0'>
                  <p style='margin:0 0 6px;font-size:13px;color:#8b6f47;font-weight:600;text-transform:uppercase;letter-spacing:.05em'>Enrollment Details</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Student:</strong> {$childName}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Program:</strong> {$program}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Package:</strong> {$package}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Reference No.:</strong> {$refNo}</p>
                </div>
                <p style='color:#555;line-height:1.6'>Please visit the center at your scheduled time. If you have questions, feel free to contact us.</p>
                <p style='color:#555;line-height:1.6;margin-top:24px'>Warm regards,<br><strong>Einstein Child Care & Learning Center</strong></p>
              </div>
            </div>";
        } else {
            $subject = "❌ Enrollment Update — Einstein Child Care & Learning Center";
            $body = "
            <div style='font-family:Georgia,serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5d9ce'>
              <div style='background:#3d1f0a;padding:28px 32px;text-align:center'>
                <h1 style='color:#DAB55C;font-size:22px;margin:0;letter-spacing:.04em'>Einstein Center</h1>
                <p style='color:rgba(255,255,255,.6);font-size:13px;margin:6px 0 0'>Child Care & Learning Center</p>
              </div>
              <div style='padding:32px'>
                <h2 style='color:#c62828;font-size:18px;margin:0 0 16px'>Enrollment Not Approved</h2>
                <p style='color:#555;line-height:1.6'>Dear <strong>{$guardianName}</strong>,</p>
                <p style='color:#555;line-height:1.6'>We regret to inform you that the enrollment of <strong>{$childName}</strong> for <strong>{$program}</strong> could not be processed at this time.</p>
                <p style='color:#555;line-height:1.6'>This may be due to incomplete payment verification or limited slots. Please contact us for more details or to re-submit your enrollment.</p>
                <p style='color:#555;line-height:1.6;margin-top:24px'>Warm regards,<br><strong>Einstein Child Care & Learning Center</strong></p>
              </div>
            </div>";
        }

        sendEmail($enroll['parent_email'], $subject, $body);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => $action === 'approve' ? 'Enrollment approved.' : 'Enrollment rejected.',
        'enrollment_id' => $id,
        'status' => $status,
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
