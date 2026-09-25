<?php
require_once __DIR__ . '/bootstrap.php';

function vipSetup($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS vip_settings (id INT PRIMARY KEY, duration_years INT NOT NULL DEFAULT 2)");
    $db->exec('INSERT IGNORE INTO vip_settings (id, duration_years) VALUES (1, 2)');
    $db->exec('CREATE TABLE IF NOT EXISTS vip_unsubscriptions (user_id INT UNSIGNED PRIMARY KEY, unsubscribed_at DATETIME NOT NULL)');
}
function vipState($db, int $userId): array {
    vipSetup($db);
    $years = (int) $db->query('SELECT duration_years FROM vip_settings WHERE id=1')->fetchColumn();
    $q = $db->prepare("SELECT id, created_at FROM enrollments WHERE user_id=? AND status='confirmed' AND LOWER(program) LIKE '%vip%' ORDER BY created_at, id");
    $q->execute([$userId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare('SELECT unsubscribed_at FROM vip_unsubscriptions WHERE user_id=?');
    $q->execute([$userId]);
    $unsubscribed = $q->fetchColumn();
    $expiry = null; $joined = null;
    foreach ($rows as $row) {
        if ($unsubscribed && $row['created_at'] <= $unsubscribed) continue;
        $start = new DateTimeImmutable($row['created_at']);
        $joined = $joined ?? $start;
        // If a confirmed VIP enrollment was created while an existing term is still active,
        // it belongs to the same parent enrollment session or multi-child submission.
        // Do NOT stack an extra term per child!
        if ($expiry && $start < $expiry) {
            continue;
        }
        $expiry = $start->modify('+' . $years . ' years');
    }
    $days = $expiry ? (int) ceil(($expiry->getTimestamp() - time()) / 86400) : null;
    // An unsubscribe ends the previous VIP lifecycle. Any pending record from
    // before that point must not keep the re-join control locked. A new request
    // submitted after unsubscribing is a fresh lifecycle and may be pending.
    $pendingSql = "SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='pending' AND LOWER(program) LIKE '%vip%'";
    $pendingParams = [$userId];
    if ($unsubscribed) {
        $pendingSql .= ' AND created_at > ?';
        $pendingParams[] = $unsubscribed;
    }
    $qPending = $db->prepare($pendingSql);
    $qPending->execute($pendingParams);
    $hasPending = ((int) $qPending->fetchColumn()) > 0;

    return ['years' => $years, 'active' => $days !== null && $days > 0,
        'joined_at' => $joined ? $joined->format(DATE_ATOM) : null,
        'expires_at' => $expiry ? $expiry->format(DATE_ATOM) : null,
        'days_left' => $days,
        'unsubscribed' => (bool) $unsubscribed && !$expiry && !$hasPending,
        'pending' => $hasPending];
}
