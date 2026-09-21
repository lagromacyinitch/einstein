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

/**
 * Resolve the package fee the same way the balance-monitoring screens do.
 * The server owns this calculation so a browser cannot submit a forged total.
 */
require_once __DIR__ . '/balance_helpers.php';

function balanceCleanText($value, int $limit): string
{
    $text = trim(strip_tags((string) $value));
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $post = array_merge($_POST, $input);

    $id = (int) ($post['enrollment_id'] ?? $post['id'] ?? 0);
    $action = strtolower(trim($post['action'] ?? ''));

    if ($id <= 0) {
        throw new Exception('Invalid enrollment ID.');
    }

    // ── Reservation Credit on Approval (Any Admin) ─────────────────────────
    // Called automatically when admin approves an enrollment.
    // Credits exactly ₱1,000 (reservation deposit) to the balance ledger.
    // Idempotent: skips silently if already credited.
    if ($action === 'credit_reservation') {
        $db = getDB();
        $db->beginTransaction();
        try {
            $rowStmt = $db->prepare("
                SELECT id, program, package_selected, status, payment_status, admin_notes
                FROM enrollments
                WHERE id = ?
                FOR UPDATE
            ");
            $rowStmt->execute([$id]);
            $enrollment = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$enrollment) {
                throw new Exception('Enrollment not found.');
            }

            $previousRecord = balanceParseRecord($enrollment['admin_notes'] ?? null);

            // Idempotency: skip if reservation was already credited
            if (!empty($previousRecord['reservation_credited'])) {
                $db->rollBack();
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Reservation already credited (skipped).']);
                exit;
            }

            $totalFee = balanceTotalFee($enrollment);
            if ($totalFee <= 0) {
                $db->rollBack();
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Could not determine fee — balance credit skipped.']);
                exit;
            }

            $isVip = str_contains(strtolower($enrollment['program'] ?? ''), 'vip');
            $reservationAmount = $isVip ? min(500.00, $totalFee) : min(1000.00, $totalFee);
            $currentPaid = 0.0;
            if (array_key_exists('paid_amount', $previousRecord)) {
                $recorded = balanceMoneyValue($previousRecord['paid_amount']);
                if ($recorded !== null && $recorded >= 0) {
                    $currentPaid = min($totalFee, $recorded);
                }
            }
            $newPaidAmount = round(min($totalFee, $currentPaid + $reservationAmount), 2);
            $newBalanceDue = round(max(0, $totalFee - $newPaidAmount), 2);
            $isSettled     = $newBalanceDue <= 0.009;
            $recordedAt    = date('c');

            $paymentMethod = balanceCleanText($post['payment_method'] ?? 'GCash', 50);
            $receiptNo     = balanceCleanText($post['receipt_no'] ?? '', 100);
            $note          = balanceCleanText($post['note'] ?? 'Reservation deposit credited upon enrollment approval', 500);

            $paymentHistory = isset($previousRecord['payments']) && is_array($previousRecord['payments'])
                ? array_values($previousRecord['payments'])
                : [];
            $paymentHistory[] = [
                'amount'      => $reservationAmount,
                'method'      => $paymentMethod,
                'receipt_no'  => $receiptNo,
                'note'        => $note,
                'recorded_at' => $recordedAt,
            ];

            $balancePayload = array_merge($previousRecord, [
                'version'              => 2,
                'reservation_credited' => true,
                'approved_at'          => $recordedAt,
                'balance_settled'      => $isSettled,
                'total_fee'            => $totalFee,
                'paid_amount'          => $newPaidAmount,
                'balance_due'          => $newBalanceDue,
                'payment_method'       => $paymentMethod,
                'receipt_no'           => $receiptNo,
                'admin_note'           => $note,
                'last_payment_at'      => $recordedAt,
                'settled_at'           => $isSettled ? $recordedAt : null,
                'payments'             => $paymentHistory,
            ]);

            $payloadJson = json_encode($balancePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payloadJson === false) {
                throw new Exception('Could not serialize balance payload.');
            }

            $stmt = $db->prepare("UPDATE enrollments SET admin_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payloadJson, $id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        ob_clean();
        echo json_encode([
            'success'        => true,
            'message'        => '₱1,000 reservation deposit credited to balance.',
            'paid_amount'    => $newPaidAmount ?? 0,
            'balance_due'    => $newBalanceDue ?? $totalFee,
        ]);
        exit;
    }

    // ── Balance Monitoring Action (Head Admin Only) ─────────────────
    if (in_array($action, ['record_balance', 'settle_balance', 'undo_balance'], true)) {
        if (empty($_SESSION['is_head_admin'])) {
            throw new Exception('Only the Head Admin is authorized to record or modify balances.');
        }
        $db = getDB();

        if ($action === 'undo_balance') {
            $db->beginTransaction();
            try {
                $check = $db->prepare("SELECT id, admin_notes FROM enrollments WHERE id = ? FOR UPDATE");
                $check->execute([$id]);
                $existingEnrollment = $check->fetch(PDO::FETCH_ASSOC);
                if (!$existingEnrollment) {
                    throw new Exception('Enrollment not found.');
                }

                $preservedNotes = balanceParseRecord($existingEnrollment['admin_notes']);
                foreach (['version', 'balance_settled', 'total_fee', 'paid_amount', 'balance_due', 'payment_method', 'receipt_no', 'admin_note', 'last_payment_at', 'settled_at', 'payments'] as $field) {
                    unset($preservedNotes[$field]);
                }
                $stmt = $db->prepare("UPDATE enrollments SET admin_notes = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([json_encode($preservedNotes), $id]);
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Balance status reset.']);
            exit;
        }

        $paymentMethod = balanceCleanText($post['payment_method'] ?? 'Walk-in (Cash)', 50);
        $allowedMethods = ['Walk-in (Cash)', 'GCash', 'Bank Transfer', 'Online Payment', 'Cash'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            throw new Exception('Please select a valid payment method.');
        }
        $receiptNo = balanceCleanText($post['receipt_no'] ?? '', 100);
        $note = balanceCleanText($post['note'] ?? '', 500);

        $db->beginTransaction();
        try {
            $rowStmt = $db->prepare("
                SELECT id, program, package_selected, status, payment_status, admin_notes
                FROM enrollments
                WHERE id = ?
                FOR UPDATE
            ");
            $rowStmt->execute([$id]);
            $enrollment = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$enrollment) {
                throw new Exception('Enrollment not found.');
            }
            if (($enrollment['status'] ?? '') === 'cancelled') {
                throw new Exception('A cancelled enrollment cannot receive a balance payment.');
            }

            $totalFee = balanceTotalFee($enrollment);
            if ($totalFee <= 0) {
                throw new Exception('Could not determine the enrollment fee.');
            }

            $previousRecord = balanceParseRecord($enrollment['admin_notes'] ?? null);
            $currentPaid = balanceCurrentPaidAmount($enrollment, $previousRecord, $totalFee);
            $currentBalance = round(max(0, $totalFee - $currentPaid), 2);
            if ($currentBalance <= 0.009) {
                throw new Exception('This enrollment is already fully settled.');
            }

            // payment_amount is the actual amount received. The legacy paid_amount
            // fallback keeps an already-open admin page working during deployment.
            if (array_key_exists('payment_amount', $post)) {
                $paymentAmount = balanceMoneyValue($post['payment_amount']);
            } elseif (array_key_exists('paid_amount', $post)) {
                $legacyTotal = balanceMoneyValue($post['paid_amount']);
                $paymentAmount = $legacyTotal === null ? null : round($legacyTotal - $currentPaid, 2);
            } else {
                $paymentAmount = null;
            }

            if ($paymentAmount === null || $paymentAmount <= 0) {
                throw new Exception('Please enter a valid payment amount.');
            }
            if ($paymentAmount > $currentBalance + 0.009) {
                throw new Exception('Payment amount cannot be greater than the remaining balance of ₱' . number_format($currentBalance, 2) . '.');
            }

            $newPaidAmount = round(min($totalFee, $currentPaid + $paymentAmount), 2);
            $newBalanceDue = round(max(0, $totalFee - $newPaidAmount), 2);
            $isSettled = $newBalanceDue <= 0.009;
            $recordedAt = date('c');

            $paymentHistory = isset($previousRecord['payments']) && is_array($previousRecord['payments'])
                ? array_values($previousRecord['payments'])
                : [];
            $paymentHistory[] = [
                'amount'      => $paymentAmount,
                'method'      => $paymentMethod,
                'receipt_no'  => $receiptNo,
                'note'        => $note,
                'recorded_at' => $recordedAt,
            ];
            $paymentHistory = array_slice($paymentHistory, -50);

            $balancePayload = array_merge($previousRecord, [
                'version'          => 2,
                'balance_settled'  => $isSettled,
                'total_fee'        => $totalFee,
                'paid_amount'      => $newPaidAmount,
                'balance_due'      => $newBalanceDue,
                'payment_method'   => $paymentMethod,
                'receipt_no'       => $receiptNo,
                'admin_note'       => $note,
                'last_payment_at'  => $recordedAt,
                'settled_at'       => $isSettled ? $recordedAt : null,
                'payments'         => $paymentHistory,
            ]);
            $payloadJson = json_encode($balancePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payloadJson === false) {
                throw new Exception('Could not save the balance payment.');
            }

            // Keep enrollment approval/payment-review status intact. Balance
            // monitoring is a separate ledger and must not approve a record.
            $stmt = $db->prepare("UPDATE enrollments SET admin_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$payloadJson, $id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Balance payment recorded successfully.',
            'balance' => $balancePayload,
            'payment_amount' => $paymentAmount,
        ]);
        exit;
    }

    if (!in_array($action, ['approve', 'reject', 'unsubscribe_vip'], true)) {
        throw new Exception('Invalid action. Use approve, reject, or unsubscribe_vip.');
    }

    $db = getDB();

    if ($action === 'unsubscribe_vip') {
        $stmt = $db->prepare("SELECT id, user_id, program FROM enrollments WHERE id = ?");
        $stmt->execute([$id]);
        $enroll = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enroll) {
            throw new Exception('Enrollment not found.');
        }

        $db->prepare("UPDATE enrollments SET status = 'cancelled', payment_status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$id]);

        $enrollUserId = (int)($enroll['user_id'] ?? 0);
        if ($enrollUserId > 0) {
            $db->prepare("UPDATE enrollments SET status = 'cancelled', payment_status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND LOWER(program) LIKE '%vip%'")->execute([$enrollUserId]);
            require_once __DIR__ . '/vip_membership.php';
            vipSetup($db);
            $db->prepare('INSERT INTO vip_unsubscriptions (user_id, unsubscribed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE unsubscribed_at=VALUES(unsubscribed_at)')->execute([$enrollUserId]);
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'VIP membership unsubscribed successfully.',
            'enrollment_id' => $id,
            'status' => 'cancelled'
        ]);
        exit;
    }

    $status = $action === 'approve' ? 'confirmed' : 'cancelled';
    $paymentStatus = $action === 'approve' ? 'confirmed' : 'rejected';

    // Fetch enrollment details for balance calculation and email notification
    $row = $db->prepare("SELECT e.id, e.child_name, e.guardian_name, e.program, e.package_selected, e.reference_no, e.payment_method, e.admin_notes, e.user_id, u.email AS parent_email
        FROM enrollments e
        LEFT JOIN users u ON u.id = e.user_id
        WHERE e.id = ?");
    $row->execute([$id]);
    $enroll = $row->fetch(PDO::FETCH_ASSOC);
    if (!$enroll) {
        throw new Exception('Enrollment not found.');
    }

    $adminNotesPayload = null;
    if ($action === 'approve') {
        try {
            $totalFee = balanceTotalFee($enroll);
            $previousRecord = balanceParseRecord($enroll['admin_notes'] ?? null);
            $isVip = stripos($enroll['program'] ?? '', 'vip') !== false;

            if (isset($post['verified_payment_amount']) && is_numeric($post['verified_payment_amount']) && (float)$post['verified_payment_amount'] >= 0) {
                $initialAmount = min($totalFee, round((float)$post['verified_payment_amount'], 2));
            } else {
                $initialAmount = $isVip ? min(500.00, $totalFee) : min(1000.00, $totalFee);
            }

            $newPaidAmount = $initialAmount;
            $newBalanceDue = round(max(0, $totalFee - $newPaidAmount), 2);
            $isSettled = $newBalanceDue <= 0.009;
            $recordedAt = date('c');
            $method = !empty($enroll['payment_method']) ? $enroll['payment_method'] : 'Online Payment';
            $refNo = !empty($enroll['reference_no']) ? $enroll['reference_no'] : '';

            $paymentHistory = isset($previousRecord['payments']) && is_array($previousRecord['payments']) ? array_values($previousRecord['payments']) : [];
            $paymentHistory[] = [
                'amount'      => $initialAmount,
                'method'      => $method,
                'receipt_no'  => $refNo,
                'note'        => 'Initial payment verified upon enrollment approval',
                'recorded_at' => $recordedAt,
            ];

            $balancePayload = array_merge($previousRecord, [
                'version'              => 2,
                'reservation_credited' => true,
                'approved_at'          => $recordedAt,
                'balance_settled'      => $isSettled,
                'total_fee'            => $totalFee,
                'paid_amount'          => $newPaidAmount,
                'balance_due'          => $newBalanceDue,
                'payment_method'       => $method,
                'receipt_no'           => $refNo,
                'admin_note'           => 'Initial payment verified upon enrollment approval',
                'last_payment_at'      => $recordedAt,
                'settled_at'           => $isSettled ? $recordedAt : null,
                'payments'             => $paymentHistory,
            ]);

            $adminNotesPayload = json_encode($balancePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $be) {
            error_log('Balance credit upon approval error: ' . $be->getMessage());
        }
    }

    if ($enroll) {
        foreach (['child_name', 'guardian_name', 'parent_email'] as $field) {
            $value = decryptAES256($enroll[$field] ?? '');
            // Never put undecryptable ciphertext into an outgoing message.
            $enroll[$field] = str_starts_with($value, AES_PREFIX) ? '' : $value;
        }
    }

    if ($adminNotesPayload !== null) {
        $stmt = $db->prepare("
            UPDATE enrollments
            SET status = ?, payment_status = ?, admin_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $paymentStatus, $adminNotesPayload, $id]);
    } else {
        $stmt = $db->prepare("
            UPDATE enrollments
            SET status = ?, payment_status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $paymentStatus, $id]);
    }

    if ($stmt->rowCount() === 0 && $action !== 'approve') {
        throw new Exception('Enrollment not found.');
    }

    $enrollUserId = (int)($enroll['user_id'] ?? 0);
    $isVipAction = stripos($enroll['program'] ?? '', 'vip') !== false;
    if ($action === 'approve' && $enrollUserId > 0 && $isVipAction) {
        // Also confirm any pending VIP Club Membership enrollment for this user
        $db->prepare("UPDATE enrollments SET status = 'confirmed', payment_status = 'confirmed', updated_at = NOW() WHERE user_id = ? AND LOWER(program) LIKE '%vip%' AND status = 'pending'")->execute([$enrollUserId]);
    } elseif ($action === 'reject' && $enrollUserId > 0 && $isVipAction) {
        // If rejected, cancel any pending VIP Club Membership enrollment for this user as well
        $db->prepare("UPDATE enrollments SET status = 'cancelled', payment_status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND LOWER(program) LIKE '%vip%' AND status = 'pending'")->execute([$enrollUserId]);
    }

    // Send email notification to parent
    if ($enroll && filter_var($enroll['parent_email'], FILTER_VALIDATE_EMAIL)) {
        $childName    = htmlspecialchars($enroll['child_name'] ?? '');
        $program      = htmlspecialchars($enroll['program'] ?? '');
        $package      = htmlspecialchars($enroll['package_selected'] ?? '');
        $refNo        = htmlspecialchars($enroll['reference_no'] ?? 'N/A');
        $guardianName = htmlspecialchars($enroll['guardian_name'] ?: 'Parent/Guardian');

        $isVip = stripos($enroll['program'] ?? '', 'vip') !== false;
        $approvalTitle = $isVip ? 'VIP Membership Approved' : 'Enrollment Approved';
        $detailsTitle = $isVip ? 'Membership Details' : 'Enrollment Details';
        $personLabel = $isVip ? 'Member' : 'Student';
        $approvalMessage = $isVip
            ? 'Your VIP membership application has been <strong>approved</strong>. You can view your membership status in the client portal.'
            : "We are pleased to inform you that the enrollment of <strong>{$childName}</strong> has been <strong>approved</strong>.";
        $nextStep = $isVip ? 'Visit the VIP Section in your account to view your membership details. If you have questions, please contact the center.' : 'Please visit the center at your scheduled time. If you have questions, feel free to contact us.';
        if ($action === 'approve') {
            $subject = "{$approvalTitle} — EINSTEIN-Center For Modern Education";
            $body = "
            <div style='font-family:Georgia,serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5d9ce'>
              <div style='background:#3d1f0a;padding:28px 32px;text-align:center'>
                <h1 style='color:#DAB55C;font-size:22px;margin:0;letter-spacing:.04em'>EINSTEIN-Center For Modern Education</h1>
                <p style='color:rgba(255,255,255,.6);font-size:13px;margin:6px 0 0'>Tutorial • Workshop • Childcare</p>
              </div>
              <div style='padding:32px'>
                <h2 style='color:#2e7d32;font-size:18px;margin:0 0 16px'>{$approvalTitle}</h2>
                <p style='color:#555;line-height:1.6'>Dear <strong>{$guardianName}</strong>,</p>
                <p style='color:#555;line-height:1.6'>{$approvalMessage}</p>
                <div style='background:#f9f5f0;border-left:4px solid #DAB55C;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0'>
                  <p style='margin:0 0 6px;font-size:13px;color:#8b6f47;font-weight:600;text-transform:uppercase;letter-spacing:.05em'>{$detailsTitle}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>{$personLabel}:</strong> {$childName}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Program:</strong> {$program}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Package:</strong> {$package}</p>
                  <p style='margin:4px 0;font-size:14px;color:#333'><strong>Reference No.:</strong> {$refNo}</p>
                </div>
                <p style='color:#555;line-height:1.6'>{$nextStep}</p>
                <p style='color:#555;line-height:1.6;margin-top:24px'>Warm regards,<br><strong>EINSTEIN-Center For Modern Education</strong></p>
              </div>
            </div>";
        } else {
            $subject = "❌ Enrollment Update — EINSTEIN-Center For Modern Education";
            $body = "
            <div style='font-family:Georgia,serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5d9ce'>
              <div style='background:#3d1f0a;padding:28px 32px;text-align:center'>
                <h1 style='color:#DAB55C;font-size:22px;margin:0;letter-spacing:.04em'>EINSTEIN-Center For Modern Education</h1>
                <p style='color:rgba(255,255,255,.6);font-size:13px;margin:6px 0 0'>Tutorial • Workshop • Childcare</p>
              </div>
              <div style='padding:32px'>
                <h2 style='color:#c62828;font-size:18px;margin:0 0 16px'>Enrollment Not Approved</h2>
                <p style='color:#555;line-height:1.6'>Dear <strong>{$guardianName}</strong>,</p>
                <p style='color:#555;line-height:1.6'>We regret to inform you that the enrollment of <strong>{$childName}</strong> for <strong>{$program}</strong> could not be processed at this time.</p>
                <p style='color:#555;line-height:1.6'>This may be due to incomplete payment verification or limited slots. Please contact us for more details or to re-submit your enrollment.</p>
                <p style='color:#555;line-height:1.6;margin-top:24px'>Warm regards,<br><strong>EINSTEIN-Center For Modern Education</strong></p>
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
