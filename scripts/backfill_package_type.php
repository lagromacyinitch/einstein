<?php
// backfill_package_type.php
// Idempotent script to populate package_type for existing rows in program_packages.
// Run from CLI: php backfill_package_type.php
// Or open in browser: http://localhost/EINSTEIN-WEB14/backfill_package_type.php

require_once __DIR__ . '/../public/config.php';
setSecurityHeaders();
try {
    $db = getDB();
    $rows = $db->query('SELECT id, program_name, package_name, package_type FROM program_packages')->fetchAll(PDO::FETCH_ASSOC);
    $toUpdate = [];
    foreach ($rows as $r) {
        $existing = trim(strval($r['package_type'] ?? ''));
        if ($existing !== '') continue; // skip already set
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
        $toUpdate[] = ['id' => $r['id'], 'type' => $type, 'name' => $r['package_name']];
    }

    if (empty($toUpdate)) {
        echo "No rows need updating.\n";
        exit(0);
    }

    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE program_packages SET package_type = ? WHERE id = ?');
    $counts = ['childcare'=>0,'workshop'=>0,'playschool'=>0,'other'=>0];
    foreach ($toUpdate as $u) {
        $stmt->execute([$u['type'], $u['id']]);
        if (isset($counts[$u['type']])) $counts[$u['type']]++;
        else $counts['other']++;
    }
    $db->commit();

    echo "Backfill completed. Updated counts:\n";
    foreach ($counts as $k=>$v) echo " - $k: $v\n";
    echo "Total updated: " . array_sum($counts) . "\n";
    echo "Run admin UI save or reload to pick up changes.\n";
} catch (Exception $e) {
    if ($db && $db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
