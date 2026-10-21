<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/balance_helpers.php';
try {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        throw new RuntimeException('Admin approval required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    if (!in_array($action, ['approve', 'reject'], true)) throw new RuntimeException('Invalid review action.');
    $db = getDB();
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT * FROM enrollments WHERE id=? FOR UPDATE');
    $stmt->execute([(int) ($data['enrollment_id'] ?? 0)]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) throw new RuntimeException('Enrollment not found.');
    $notes = balanceParseRecord($enrollment['admin_notes']);
    $index = null;
    foreach (($notes['balance_receipts'] ?? []) as $i => $receipt) {
        if ($receipt['path'] === ($data['path'] ?? null)) $index = $i;
    }
    if ($index === null) throw new RuntimeException('Receipt not found.');
    $receipt = $notes['balance_receipts'][$index];
    if ($action === 'approve') {
        if (($receipt['status'] ?? '') === 'approved') {
            throw new RuntimeException('This receipt is already approved. Payment was already deducted.');
        }
    } else if ($action === 'reject') {
        if (($receipt['status'] ?? '') === 'approved') {
            throw new RuntimeException('Cannot reject an already approved receipt. Payment was already deducted.');
        }
    }
    $now = date('Y-m-d H:i:s');
    if ($action === 'approve') {
        if ($enrollment['status'] !== 'confirmed') throw new RuntimeException('Enrollment must be confirmed before recording payment.');
        $amount = balanceMoneyValue($data['amount'] ?? null);
        $ref = trim((string) ($data['reference'] ?? ''));
        if ($ref === '') {
            $ref = !empty($receipt['reference']) ? (string)$receipt['reference'] : '';
        }
        if ($ref === '') {
            $ref = 'BAL' . date('Ymd') . strtoupper(substr(md5($receipt['path'] . time()), 0, 6));
        }
        $ref = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $ref));
        if (!$amount || $amount <= 0) throw new RuntimeException('Verify the paid amount before approving.');
        $total = balanceTotalFee($enrollment);
        $paid = balanceCurrentPaidAmount($enrollment, $notes, $total);
        if ($amount > round($total - $paid, 2)) throw new RuntimeException('Amount exceeds the remaining balance. Check the receipt and existing payments.');
        $existingRefs = [];
        foreach (($notes['payments'] ?? []) as $payment) {
            $pRef = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($payment['receipt_no'] ?? '')));
            if ($pRef !== '') $existingRefs[$pRef] = true;
        }
        if ($ref === '' || isset($existingRefs[$ref])) {
            $ref = 'BAL' . date('Ymd') . strtoupper(substr(md5(($receipt['path'] ?? '') . microtime(true)), 0, 6));
        }

        if (($receipt['method'] ?? '') !== 'Walk-in') {
            $rawPath = ltrim(str_replace('\\', '/', (string)($receipt['path'] ?? '')), '/');
            $basename = basename($rawPath);
            $candidates = array_unique(array_filter([
                defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') . '/' . $basename : null,
                defined('EINSTEIN_ROOT') ? EINSTEIN_ROOT . '/' . $rawPath : null,
                defined('EINSTEIN_ROOT') ? dirname(EINSTEIN_ROOT) . '/' . $rawPath : null,
                defined('EINSTEIN_ROOT') ? EINSTEIN_ROOT . '/uploads/' . $basename : null,
                defined('EINSTEIN_ROOT') ? dirname(EINSTEIN_ROOT) . '/uploads/' . $basename : null,
                $rawPath,
            ]));

            $filePath = null;
            foreach ($candidates as $cand) {
                if ($cand && is_file($cand)) {
                    $filePath = $cand;
                    break;
                }
            }

            if ($filePath) {
                $hash = hash_file('sha256', $filePath);
            } elseif (!empty($receipt['file_hash'])) {
                $hash = (string)$receipt['file_hash'];
            } else {
                $hash = hash('sha256', (string)($receipt['path'] ?? '') . ':' . $enrollment['id']);
            }

            try {
                $claim = $db->prepare('SELECT * FROM balance_receipt_claims WHERE file_hash=? FOR UPDATE');
                $claim->execute([$hash]);
                $existing = $claim->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $db->prepare('UPDATE balance_receipt_claims SET transaction_ref=?, enrollment_id=? WHERE id=?')
                       ->execute([$ref, (int) $enrollment['id'], $existing['id']]);
                } else {
                    $db->prepare('INSERT INTO balance_receipt_claims (file_hash, transaction_ref, enrollment_id) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE transaction_ref=VALUES(transaction_ref), enrollment_id=VALUES(enrollment_id)')
                       ->execute([$hash, $ref, (int) $enrollment['id']]);
                }
            } catch (Throwable $claimError) {
                error_log('[Balance Receipt Claim] Notice: ' . $claimError->getMessage());
            }
        }
        $paid = round($paid + $amount, 2);
        $due = round($total - $paid, 2);
        $notes = array_merge($notes, ['paid_amount' => $paid, 'total_fee' => $total, 'balance_due' => $due,
            'balance_settled' => $due <= 0, 'settled_at' => $due <= 0 ? $now : null,
            'payment_method' => ($receipt['method'] ?? 'Online Payment'), 'last_payment_at' => $now]);
        $notes['payments'][] = ['amount' => $amount, 'method' => ($receipt['method'] ?? 'Online Payment'), 'receipt_no' => $ref,
            'recorded_at' => $now, 'source' => 'admin_receipt_approval'];
        $receipt['approved_amount'] = $amount;
        $receipt['reference'] = $ref;
    }
    $receipt['status'] = $action === 'approve' ? 'approved' : 'rejected';
    $receipt['reviewed_at'] = $now;
    $receipt['reviewed_by'] = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 'admin';
    $notes['balance_receipts'][$index] = $receipt;
    $db->prepare('UPDATE enrollments SET admin_notes=?, updated_at=NOW() WHERE id=?')->execute([json_encode($notes), $enrollment['id']]);
    $db->commit();
    echo json_encode(['success' => true, 'admin_notes' => json_encode($notes)]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Unable to approve: the transaction may already be used. Please check the payment records.' : $e->getMessage()]);
}
