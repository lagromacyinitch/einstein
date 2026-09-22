<?php
require_once __DIR__ . '/../public/includes/balance_helpers.php';
require_once __DIR__ . '/../public/includes/receipt_detection.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$receipt = detectReceiptPayment("Transfer successful\nAmount\nPHP 3,000.00\nReference No.\n1234567890123");
check($receipt && $receipt['amount'] === 3000, 'Read amount');
check($receipt['reference'] === '1234567890123', 'Read reference');
$enrollment = ['program' => 'Weekend Workshop', 'package_selected' => '', 'status' => 'confirmed'];
$total = balanceTotalFee($enrollment);
$paid = balanceCurrentPaidAmount($enrollment, [], $total);
check($total - $paid === 5000.0, 'Starting balance');
check($total - ($paid + $receipt['amount']) === 2000.0, '5000 minus 3000 = 2000');
check(detectReceiptPayment("Amount PHP 3,000.00\nAmount PHP 2,000.00\nRef No 123456789") === null, 'Ambiguous amount must not credit');
check(detectReceiptPayment("Failed\nAmount PHP 3,000.00\nRef No 123456789") === null, 'Failed payment must not credit');
check(detectReceiptPayment("Amount PHP 3,000.00") === null, 'Missing reference must not credit');
check(detectReceiptPayment("Account 123456789\nFee PHP 15.00") === null, 'Account and fee must not credit');
check(detectReceiptPayment("Amount PHP 3,000.00\nFee PHP 15.00\nTotal Amount PHP 3,015.00\nRef No 123456789") === null, 'Conflicting totals must go to review');
echo "PASS: receipt parsing, ambiguous/failed/missing-reference handling, and 5000 - 3000 = 2000.\n";
