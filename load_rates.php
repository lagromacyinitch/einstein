<?php
// load_rates.php — returns saved program packages grouped by program_name
ob_start();
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';
    setSecurityHeaders();
    $db = getDB();

    // Clean up outdated childcare package defaults if present
    $db->exec("DELETE FROM program_packages WHERE package_type = 'childcare' AND package_name IN ('Daily Package', 'Weekly Package', 'Monthly Package')");

    // Purge obsolete legacy workshop entries from DB so default structure is clean
    try {
        $db->exec("DELETE FROM program_packages WHERE program_name = 'Workshop' AND package_name IN ('Piano Class', 'Guitar Class', 'Art & Painting', 'Dance Class', 'One-on-One (Center Based)', 'One-on-One (Home Based)')");
    } catch(Exception $e){}

    $defaultPackages = [
        // Academic Tutorial
        ['program_name' => 'Academic Tutorial', 'package_name' => 'Regular Package', 'care_duration' => 'One Tutor for One Child · 15 sessions', 'rate' => '₱3,300', 'capacity_slots' => null, 'package_type' => 'tutorial'],
        ['program_name' => 'Academic Tutorial', 'package_name' => 'Double Package', 'care_duration' => 'One Tutor for 1 or 2 Kids · 15 sessions', 'rate' => '₱5,500', 'capacity_slots' => null, 'package_type' => 'tutorial'],
        ['program_name' => 'Academic Tutorial', 'package_name' => 'Daily Package', 'care_duration' => 'Per month · 1 hour/day', 'rate' => '₱5,500', 'capacity_slots' => null, 'package_type' => 'tutorial'],
        ['program_name' => 'Academic Tutorial', 'package_name' => 'Daily Double Package', 'care_duration' => 'Per month · 2 hours/day', 'rate' => '₱9,900', 'capacity_slots' => null, 'package_type' => 'tutorial'],

        // Child Care (Toilet Trained & Non-Toilet Trained: Non-members / VIP Members x Monthly / Weekly / Daily)
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - Non-members (Monthly)', 'care_duration' => 'Toilet Trained', 'rate' => '₱12,250', 'capacity_slots' => 'Monthly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - Non-members (Weekly)', 'care_duration' => 'Toilet Trained', 'rate' => '₱3,600', 'capacity_slots' => 'Weekly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - Non-members (Daily)', 'care_duration' => 'Toilet Trained', 'rate' => '₱675', 'capacity_slots' => 'Daily', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - VIP Members (Monthly)', 'care_duration' => 'Toilet Trained', 'rate' => '₱10,320', 'capacity_slots' => 'Monthly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - VIP Members (Weekly)', 'care_duration' => 'Toilet Trained', 'rate' => '₱3,000', 'capacity_slots' => 'Weekly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Toilet Trained - VIP Members (Daily)', 'care_duration' => 'Toilet Trained', 'rate' => '₱560', 'capacity_slots' => 'Daily', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - Non-members (Monthly)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱13,500', 'capacity_slots' => 'Monthly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - Non-members (Weekly)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱4,500', 'capacity_slots' => 'Weekly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - Non-members (Daily)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱850', 'capacity_slots' => 'Daily', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - VIP Members (Monthly)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱11,800', 'capacity_slots' => 'Monthly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - VIP Members (Weekly)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱3,800', 'capacity_slots' => 'Weekly', 'package_type' => 'childcare'],
        ['program_name' => 'Child Care', 'package_name' => 'Non-Toilet Trained - VIP Members (Daily)', 'care_duration' => 'Non-Toilet Trained', 'rate' => '₱700', 'capacity_slots' => 'Daily', 'package_type' => 'childcare'],

        // Workshop
        ['program_name' => 'Workshop', 'package_name' => 'Group Subscription', 'care_duration' => 'Saturdays Only (Aug–Jan)', 'rate' => '₱2,500/mo', 'capacity_slots' => 'Up to 10 students/class', 'package_type' => 'workshop'],
        ['program_name' => 'Workshop', 'package_name' => 'Center Based', 'care_duration' => '10 sessions (1 hr each)', 'rate' => '₱4,800', 'capacity_slots' => '1 instructor to 1 student', 'package_type' => 'workshop'],
        ['program_name' => 'Workshop', 'package_name' => 'Home Based', 'care_duration' => '10 sessions (1 hr each)', 'rate' => '₱4,500 + transpo', 'capacity_slots' => '1 instructor to 1 student', 'package_type' => 'workshop'],

        // PlaySchool
        ['program_name' => 'PlaySchool', 'package_name' => 'Caterpillar', 'care_duration' => '2-3 yrs', 'rate' => '₱4,875/mo', 'capacity_slots' => 'Morning session', 'package_type' => 'playschool'],
        ['program_name' => 'PlaySchool', 'package_name' => 'Butterfly', 'care_duration' => '3-4 yrs', 'rate' => '₱4,875/mo', 'capacity_slots' => 'Full day', 'package_type' => 'playschool'],

        // M.A.D. Studio
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Hiphop Aerobics', 'care_duration' => 'Fitness Training', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'MWF · 6:45–7:45 PM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Kickboxing', 'care_duration' => 'Fitness Training', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'TTHS · 6:45–7:45 PM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Cross Training', 'care_duration' => 'Fitness Training', 'rate' => '₱3,500 /mo', 'capacity_slots' => 'Both schedules', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Gymnastics', 'care_duration' => 'After School Program', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'Sat · 8:30–10:30 AM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Ballet Class', 'care_duration' => 'After School Program', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'Sat · 10:30 AM–12:30 PM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Taekwondo', 'care_duration' => 'After School Program', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'Sat 12:30–2:30 PM · TTHS 5:30–6:30 PM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Pop Dancing', 'care_duration' => 'After School Program', 'rate' => '₱2,500 /mo', 'capacity_slots' => 'MWF · 5:30–6:30 PM', 'package_type' => 'madstudio'],
        ['program_name' => 'M.A.D. Studio', 'package_name' => 'Studio Rental', 'care_duration' => 'Studio Rental', 'rate' => '₱450 /hr', 'capacity_slots' => 'Available anytime · book in advance', 'package_type' => 'madstudio'],

        // Summer Blast
        ['program_name' => 'Summer Blast', 'package_name' => 'Registration Fee', 'care_duration' => 'Slot Reservation', 'rate' => '₱900', 'capacity_slots' => 'Includes free t-shirt & recital fee', 'package_type' => 'summerblast'],
        ['program_name' => 'Summer Blast', 'package_name' => 'Single Course', 'care_duration' => 'Tuition Package', 'rate' => '₱3,900', 'capacity_slots' => '1 Course + 1 Academics Free', 'package_type' => 'summerblast'],
        ['program_name' => 'Summer Blast', 'package_name' => 'Two Courses', 'care_duration' => 'Tuition Package', 'rate' => '₱4,900', 'capacity_slots' => '2 Courses + 1 Academics Free', 'package_type' => 'summerblast'],
        ['program_name' => 'Summer Blast', 'package_name' => 'Special Course', 'care_duration' => 'Tuition Package', 'rate' => '₱5,100', 'capacity_slots' => '1 Special + 1 Any Course Free', 'package_type' => 'summerblast']
    ];

    $selectCheck = $db->prepare('SELECT id FROM program_packages WHERE program_name = ? AND package_name = ? LIMIT 1');
    $insertStmt = $db->prepare('INSERT INTO program_packages (program_name, package_name, care_duration, rate, capacity_slots, package_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');

    foreach ($defaultPackages as $dp) {
        $selectCheck->execute([$dp['program_name'], $dp['package_name']]);
        if (!$selectCheck->fetchColumn()) {
            $insertStmt->execute([$dp['program_name'], $dp['package_name'], $dp['care_duration'], $dp['rate'], $dp['capacity_slots'], $dp['package_type']]);
        }
    }

    $stmt = $db->query('SELECT * FROM program_packages ORDER BY program_name, id');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = ['childcare' => [], 'workshop' => [], 'playschool' => [], 'tutorial' => [], 'madstudio' => [], 'summerblast' => []];
    foreach ($rows as $r) {
        $type = strtolower(trim($r['package_type'] ?? ''));
        if ($type === 'childcare' || (strpos(strtolower($r['program_name'] ?? ''), 'child') !== false && $type === '')) {
            $out['childcare'][] = [
                'name' => $r['package_name'],
                'duration' => $r['care_duration'],
                'rate' => $r['rate'],
                'slots' => $r['capacity_slots']
            ];
        } elseif ($type === 'workshop' || (strpos(strtolower($r['program_name'] ?? ''), 'work') !== false && $type === '')) {
            $out['workshop'][] = [
                'name' => $r['package_name'],
                'capacity' => $r['capacity_slots'],
                'rate' => $r['rate'],
                'schedule' => $r['care_duration']
            ];
        } elseif ($type === 'playschool' || (strpos(strtolower($r['program_name'] ?? ''), 'play') !== false && $type === '')) {
            $out['playschool'][] = [
                'name' => $r['package_name'],
                'age' => $r['care_duration'],
                'rate' => $r['rate'],
                'notes' => $r['capacity_slots']
            ];
        } elseif ($type === 'tutorial' || (preg_match('/tutor|tutorial|academic|tuition|tutoring/i', $r['program_name'] ?? '') && $type === '')) {
            $out['tutorial'][] = [
                'name' => $r['package_name'],
                'detail' => $r['care_duration'],
                'rate' => $r['rate']
            ];
        } elseif ($type === 'madstudio' || $type === 'mad' || (preg_match('/m\.a\.d|mad/i', $r['program_name'] ?? '') && $type === '')) {
            $out['madstudio'][] = [
                'name' => $r['package_name'],
                'category' => $r['care_duration'],
                'rate' => $r['rate'],
                'schedule' => $r['capacity_slots']
            ];
        } elseif ($type === 'summerblast' || (strpos(strtolower($r['program_name'] ?? ''), 'summer') !== false && $type === '')) {
            $out['summerblast'][] = [
                'name' => $r['package_name'],
                'category' => $r['care_duration'],
                'rate' => $r['rate'],
                'detail' => $r['capacity_slots']
            ];
        }
    }
    ob_clean();
    echo json_encode(['success' => true, 'rates' => $out]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
