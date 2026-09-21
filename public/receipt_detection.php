<?php
function detectReceiptPayment(string $text): ?array {
    // Require a clearly labelled amount and transaction reference; ambiguous
    // receipts remain pending instead of guessing from account numbers or fees.
    if (preg_match('/\b(failed|unsuccessful|cancelled|refunded)\b/i', $text)) return null;
    $amounts = [];
    preg_match_all('/(?:amount\s+(?:sent|paid|transferred)|transfer\s+amount|amount)\s*[:\-]?\s*(?:PHP|PHP\.|P|₱)?\s*([0-9][0-9,]*\.[0-9]{2})(?!\d)/iu', $text, $matches);
    foreach ($matches[1] as $raw) $amounts[] = (int) round((float) str_replace(',', '', $raw) * 100);
    $amounts = array_unique($amounts);
    if (count($amounts) !== 1 || reset($amounts) <= 0) return null;
    if (!preg_match('/(?:reference|ref\.?|transaction)\s*(?:number|no\.?|id)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9 -]{5,50})/i', $text, $reference)) return null;
    $ref = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($reference[1])));
    if (strlen($ref) < 6 || !preg_match('/\d/', $ref)) return null;
    return ['amount' => reset($amounts) / 100, 'reference' => $ref];
}

function readReceiptPayment(string $path): ?array {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) return null;
    $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', __DIR__ . '/receipt_ocr.ps1', '-ImagePath', $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return null;
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = ''; $deadline = microtime(true) + 30;
    do {
        $output .= stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $running = proc_get_status($process)['running'];
        if (!$running) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    if ($running) proc_terminate($process);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $result = json_decode(ltrim($output, "\xEF\xBB\xBF"), true);
    return isset($result['text']) ? detectReceiptPayment($result['text']) : null;
}
