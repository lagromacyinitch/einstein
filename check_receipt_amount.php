<?php
// ─────────────────────────────────────────────────────────────
// Einstein Center — Check Receipt for Payment Amount via OCR
// Returns JSON: { success: bool, has_amount: bool, amount?: float }
// ─────────────────────────────────────────────────────────────
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/receipt_detection.php';

setSecurityHeaders();

$src = trim($_GET['src'] ?? $_POST['src'] ?? '');
if (!$src) {
    echo json_encode(['success' => false, 'has_amount' => false, 'message' => 'Missing source.']);
    exit;
}

// Strip URL host / query if full URL passed
$parsed = parse_url($src, PHP_URL_PATH);
if ($parsed) $src = $parsed;

// Normalize path relative to project
$src = ltrim(str_replace('\\', '/', $src), '/');
// Remove project base prefix if present
$src = preg_replace('#^EINSTEIN-WEB18/#i', '', $src);

$realProject = realpath(__DIR__);
$targetPath = realpath(__DIR__ . '/' . $src);

if (!$targetPath || !file_exists($targetPath) || !str_starts_with($targetPath, $realProject)) {
    // If not found directly, check inside uploads
    $basename = basename($src);
    $inUploads = realpath(__DIR__ . '/uploads/' . $basename);
    if ($inUploads && file_exists($inUploads)) {
        $targetPath = $inUploads;
    } else {
        echo json_encode(['success' => false, 'has_amount' => false, 'message' => 'File not found.']);
        exit;
    }
}

// Check cache file in uploads
$cacheFile = __DIR__ . '/uploads/.ocr_amount_cache.json';
$cache = [];
if (file_exists($cacheFile)) {
    $cachedRaw = @file_get_contents($cacheFile);
    if ($cachedRaw) $cache = json_decode($cachedRaw, true) ?: [];
}

$fileKey = md5_file($targetPath);
if ($fileKey && isset($cache[$fileKey])) {
    echo json_encode(['success' => true, 'has_amount' => (bool)$cache[$fileKey]['has_amount'], 'amount' => $cache[$fileKey]['amount'] ?? null, 'cached' => true]);
    exit;
}

// Run OCR if on Windows
$text = '';
if (PHP_OS_FAMILY === 'Windows' && function_exists('proc_open')) {
    $process = proc_open([
        'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', __DIR__ . '/receipt_ocr.ps1', '-ImagePath', $targetPath
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 15;
        do {
            $output .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(50000);
        } while (microtime(true) < $deadline);

        if ($status['running']) proc_terminate($process);
        $output .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $parsedJson = json_decode(ltrim($output, "\xEF\xBB\xBF"), true);
        $text = $parsedJson['text'] ?? '';
    }
}

// Analyze text for presence of an amount
$hasAmount = false;
$foundAmount = null;

if (!empty($text)) {
    // 1. Check if detectReceiptPayment matches
    $payment = detectReceiptPayment($text);
    if ($payment && !empty($payment['amount'])) {
        $hasAmount = true;
        $foundAmount = (float)$payment['amount'];
    }

    if (!$hasAmount) {
        // Normalize common OCR misreadings of numbers (e.g. O for 0, l for 1) in currency contexts
        $norm = $text;
        $norm = preg_replace('/(?<=[0-9₱P])[,.]?[Oo]{2}\b/', '.00', $norm);
        $norm = preg_replace('/(?<=[0-9₱P])O(?=[0-9])/', '0', $norm);
        $norm = preg_replace('/(?<=[0-9])O(?=[.,0-9])/', '0', $norm);

        // Explicit amount / total keywords followed by digits
        if (preg_match('/(?:amount\s*(?:sent|paid|transferred)?|total\s*(?:amount)?|paid|transferred|cash|fee)\D{0,20}(?:php|p|₱)?\s*([0-9][0-9,]*\.[0-9]{2})/iu', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/(?:php|p|₱)\s*([0-9][0-9,]*\.[0-9]{2})/iu', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/(?:php|p|₱)\s*([0-9]{2,6})\b/iu', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)$m[1];
        } elseif (preg_match('/(?:amount|total|fee)\s*[:\-]?\s*([0-9]{2,6})/iu', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)$m[1];
        } elseif (preg_match('/\b([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?)\b/', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b([0-9]{2,6}\.[0-9]{2})\b/', $norm, $m)) {
            $hasAmount = true;
            $foundAmount = (float)$m[1];
        }
    }
}

// Save to cache
if ($fileKey) {
    $cache[$fileKey] = ['has_amount' => $hasAmount, 'amount' => $foundAmount, 'updated_at' => time()];
    @file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
}

echo json_encode([
    'success' => true,
    'has_amount' => $hasAmount,
    'amount' => $foundAmount,
    'scanned' => true
]);
