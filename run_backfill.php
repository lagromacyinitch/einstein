<?php
// run_backfill.php — run package_type backfill from admin UI (admin-only)
header('Content-Type: application/json; charset=utf-8');
try {
    require_once __DIR__ . '/config.php';
    setSecurityHeaders();
    require_once __DIR__ . '/admin_auth.php';
    $db = getDB();
    $rows = $db->query('SELECT id, program_name, package_name, package_type FROM program_packages')->fetchAll(PDO::FETCH_ASSOC);
    $toUpdate = [];
    foreach ($rows as $r) {
        $existing = trim(strval($r['package_type'] ?? ''));
        if ($existing !== '' && $existing !== 'general') continue; // skip already set to explicit
        $combined = strtolower(($r['program_name'] ?? '') . ' ' . ($r['package_name'] ?? ''));
        $type = 'other';
        if (preg_match('/play|toddler|butterfly|caterpillar|preschool|playschool|early childhood|toddlers?/i', $combined)) {
            $type = 'playschool';
        } elseif (preg_match('/workshop|weekend|music|guitar|piano|drum|voice|studio|subscription|one-on-one|center based|home based|group subscription|instructor|coach/i', $combined)) {
            $type = 'workshop';
        } elseif (preg_match('/child|care|nanny|toilet trained|monthly|weekly|daily|vip|non-members|child care|full-day|full day/i', $combined)) {
            $type = 'childcare';
        } elseif (preg_match('/tutor|tutorial|tuition|academic|tutoring|one tutor/i', $combined)) {
            $type = 'tutorial';
        }
        if ($type !== 'other') $toUpdate[] = ['id' => $r['id'], 'type' => $type];
    }
    if (isset($_GET['preview']) && $_GET['preview']) {
        // Return preview: counts and first 8 samples
        $sample = array_slice($toUpdate, 0, 8);
        $counts = ['childcare'=>0,'workshop'=>0,'playschool'=>0,'tutorial'=>0,'other'=>0];
        foreach ($toUpdate as $u) if (isset($counts[$u['type']])) $counts[$u['type']]++; else $counts['other']++;
        echo json_encode(['success' => true, 'preview' => true, 'total' => count($toUpdate), 'counts' => $counts, 'sample' => $sample]);
        exit;
    }

    if (empty($toUpdate)) {
        echo json_encode(['success' => true, 'updated' => 0, 'message' => 'No rows needed updating.']);
        exit;
    }
    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE program_packages SET package_type = ? WHERE id = ?');
    $counts = ['childcare'=>0,'workshop'=>0,'playschool'=>0,'tutorial'=>0,'other'=>0];
    foreach ($toUpdate as $u) {
        $stmt->execute([$u['type'], $u['id']]);
        if (isset($counts[$u['type']])) $counts[$u['type']]++;
        else $counts['other']++;
    }
    $db->commit();
    echo json_encode(['success' => true, 'updated' => array_sum($counts), 'counts' => $counts]);
    exit;
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
