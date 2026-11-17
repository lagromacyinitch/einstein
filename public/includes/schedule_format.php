<?php
function formatSingleTime12Hour($t) {
    $t = trim($t);
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $t, $m)) {
        $h = (int)$m[1];
        $min = !empty($m[2]) ? $m[2] : '00';
        $ampm = strtoupper($m[3]);
        return "{$h}:{$min} {$ampm}";
    }
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $t, $m)) {
        $h = (int)$m[1];
        $min = !empty($m[2]) ? $m[2] : '00';
        $ampm = 'AM';
        if ($h >= 12) {
            $ampm = 'PM';
            if ($h > 12) $h -= 12;
        } elseif ($h === 0) {
            $h = 12;
        } elseif ($h >= 1 && $h <= 6) {
            // Standard afternoon tutorial center hours
            $ampm = 'PM';
        }
        return "{$h}:{$min} {$ampm}";
    }
    return $t;
}

/**
 * Converts timeslot strings (including military time) to 12-hour AM/PM format
 */
function formatTimeslot12Hour($raw) {
    if (!$raw) return '';
    $raw = trim($raw);
    $parts = preg_split('/\s*(?:-|–|—|to)\s*/i', $raw);
    if (count($parts) === 2) {
        return formatSingleTime12Hour($parts[0]) . ' - ' . formatSingleTime12Hour($parts[1]);
    }
    return formatSingleTime12Hour($raw);
}

/**
 * Helper: parse student timeslot preference into:
 * 1. Active days of week [mon, tue, wed, thu, fri, sat, sun] (boolean array)
 * 2. 12-hour formatted time string (e.g. "11:00 AM - 12:00 PM", "1:00 PM - 2:00 PM")
 */
function parseScheduleTimePreference($rawTimeslot, $program = '', $package = '') {
    $raw = trim($rawTimeslot ?? '');
    $prog = trim($program ?? '');
    $pkg = trim($package ?? '');
    if (strcasecmp($raw, 'Unscheduled') === 0) {
        return ['days' => array_fill_keys(['mon','tue','wed','thu','fri','sat','sun'], false), 'timeslot' => ''];
    }
    
    $tUpper = strtoupper($raw);
    
    $isM = (strpos($tUpper, 'MON') !== false || strpos($tUpper, 'MWF') !== false);
    $isT = (strpos($tUpper, 'TUE') !== false || strpos($tUpper, 'TTH') !== false || strpos($tUpper, 'TTHS') !== false);
    $isW = (strpos($tUpper, 'WED') !== false || strpos($tUpper, 'MWF') !== false);
    $isTh = (strpos($tUpper, 'THU') !== false || strpos($tUpper, 'TTH') !== false || strpos($tUpper, 'TTHS') !== false);
    $isF = (strpos($tUpper, 'FRI') !== false || strpos($tUpper, 'MWF') !== false);
    $isSa = (strpos($tUpper, 'SAT') !== false || strpos($tUpper, 'TTHS') !== false);
    $isSu = (strpos($tUpper, 'SUN') !== false);

    if (preg_match('/MON(?:DAY)?\s*(?:-|TO)\s*FRI(?:DAY)?/i', $raw)) {
        $isM = $isT = $isW = $isTh = $isF = true;
    }

    if (!$isM && !$isT && !$isW && !$isTh && !$isF && !$isSa && !$isSu) {
        if (stripos($prog, 'Weekend') !== false || stripos($pkg, 'Weekend') !== false || stripos($prog, 'Saturday') !== false) {
            $isSa = true;
        } else {
            $isM = $isT = $isW = $isTh = $isF = true;
        }
    }

    $cleanTime = '';
    if (preg_match('/(\d{1,2}(?::\d{2})?\s*(?:am|pm)?\s*(?:-|–|—|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $raw, $m)) {
        $cleanTime = formatTimeslot12Hour($m[1]);
    } elseif (preg_match('/(\d{1,2}:\d{2}\s*(?:am|pm)?)/i', $raw, $m)) {
        $cleanTime = formatTimeslot12Hour($m[1]);
    }

    if (!$cleanTime) {
        $cleanTime = ($isSa || $isSu) ? '9:00 AM - 10:00 AM' : '8:00 AM - 9:00 AM';
    }

    return [
        'days' => [
            'mon' => $isM,
            'tue' => $isT,
            'wed' => $isW,
            'thu' => $isTh,
            'fri' => $isF,
            'sat' => $isSa,
            'sun' => $isSu,
        ],
        'timeslot' => $cleanTime
    ];
}

function normalizeTimeSlotKey($ts) {
    $ts = trim((string)$ts);
    $parts = preg_split('/\s*(?:-|–|—|to)\s*/i', $ts);
    $normParts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i', $p, $pm)) {
            $h = (int)$pm[1];
            $m = !empty($pm[2]) ? $pm[2] : '00';
            $ampm = !empty($pm[3]) ? strtoupper($pm[3]) : '';
            if ($ampm === 'PM' && $h < 12) $h += 12;
            if ($ampm === 'AM' && $h == 12) $h = 0;
            if ($ampm === '' && $h >= 1 && $h <= 6) $h += 12;
            $normParts[] = sprintf('%02d:%s', $h, $m);
        } else {
            $normParts[] = $p;
        }
    }
    return implode('-', $normParts);
}

function mergeSchedulesAndAssignments($manualSchedules, $assignedStudents, $tutorMap = []) {
    $days = ['mon','tue','wed','thu','fri','sat','sun'];
    $expanded = [];
    foreach ($assignedStudents as $student) {
        if (strcasecmp(trim($student['timeslot'] ?? ''), 'Unscheduled') === 0) continue;
        foreach (explode(';', $student['timeslot'] ?? '') as $slotIndex => $slot) {
            $copy = $student;
            $copy['timeslot'] = trim($slot);
            $copy['schedule_suffix'] = $slotIndex ? '_' . substr(sha1(trim($slot)), 0, 8) : '';
            $expanded[] = $copy;
        }
    }
    $assignedStudents = $expanded;

    $tutorRows = [];
    foreach ($manualSchedules as $ms) {
        $tId = (int)$ms['tutor_id'];
        if (!isset($tutorRows[$tId])) {
            $tutorRows[$tId] = [];
        }
        $ms['timeslot'] = formatTimeslot12Hour($ms['timeslot']);
        
        foreach ($days as $d) {
            if (preg_match('/^\s*\d{1,2}(:\d{2})?\s*(-|to)\s*\d{1,2}(:\d{2})?\s*(am|pm)?\s*$/i', trim($ms[$d] ?? ''))) {
                $ms[$d] = '';
            }
        }
        $ms['source_ids'] = [$ms['id']];
        $duplicateIndex = null;
        foreach ($tutorRows[$tId] as $index => $existing) {
            if (normalizeTimeSlotKey($existing['timeslot']) === normalizeTimeSlotKey($ms['timeslot'])) {
                $duplicateIndex = $index;
                break;
            }
        }
        if ($duplicateIndex === null) {
            $tutorRows[$tId][] = $ms;
        } else {
            $existing = &$tutorRows[$tId][$duplicateIndex];
            $existing['source_ids'][] = $ms['id'];
            foreach ($days as $day) $existing[$day] = mergeScheduleCellValues($existing[$day] ?? '', $ms[$day] ?? '');
            if (($ms['slot_type'] ?? '') === 'HB') $existing['slot_type'] = 'HB';
            unset($existing);
        }
    }

    foreach ($assignedStudents as $st) {
        $tId = (int)$st['tutor_id'];
        $cName = trim($st['child_name'] ?: 'Student');
        $parsed = parseScheduleTimePreference($st['timeslot'] ?? '', $st['program'] ?? '', $st['package_selected'] ?? '');
        $targetNormKey = normalizeTimeSlotKey($parsed['timeslot']);
        $slotType = (stripos($st['program'] ?? '', 'Home') !== false || stripos($st['package_selected'] ?? '', 'Home') !== false) ? 'HB' : 'CB';
        $tName = $st['tutor_name'] ?? ($tutorMap[$tId] ?? 'Tutor');

        if (!isset($tutorRows[$tId])) {
            $tutorRows[$tId] = [];
        }

        $matchedIndex = -1;
        foreach ($tutorRows[$tId] as $idx => $r) {
            if (normalizeTimeSlotKey($r['timeslot']) === $targetNormKey) {
                $matchedIndex = $idx;
                break;
            }
        }

        if ($matchedIndex !== -1) {
            foreach ($days as $d) {
                if (!empty($parsed['days'][$d])) {
                    $currVal = trim($tutorRows[$tId][$matchedIndex][$d] ?? '');
                    if (!$currVal || $currVal === 'AVAILABLE' || $currVal === 'Available') {
                        $tutorRows[$tId][$matchedIndex][$d] = $cName;
                    } elseif (!in_array(strtolower($cName), array_map('strtolower', scheduleCellNames($currVal)), true)) {
                        $tutorRows[$tId][$matchedIndex][$d] = $currVal . ', ' . $cName;
                    }
                }
            }
            if (!empty($st['package_selected']) && stripos($st['package_selected'], 'Home') !== false) {
                $tutorRows[$tId][$matchedIndex]['slot_type'] = 'HB';
            }
        } else {
            $newRow = [
                'id' => 'assign_' . $st['enrollment_id'] . ($st['schedule_suffix'] ?? ''),
                'tutor_id' => $tId,
                'tutor_name' => $tName,
                'timeslot' => $parsed['timeslot'], // Digits only!
                'slot_type' => $slotType,
                'mon' => !empty($parsed['days']['mon']) ? $cName : '',
                'tue' => !empty($parsed['days']['tue']) ? $cName : '',
                'wed' => !empty($parsed['days']['wed']) ? $cName : '',
                'thu' => !empty($parsed['days']['thu']) ? $cName : '',
                'fri' => !empty($parsed['days']['fri']) ? $cName : '',
                'sat' => !empty($parsed['days']['sat']) ? $cName : '',
                'sun' => !empty($parsed['days']['sun']) ? $cName : '',
                'sort_order' => 50,
                'is_assignment' => true,
                'enrollment_id' => $st['enrollment_id']
            ];
            $tutorRows[$tId][] = $newRow;
        }
    }

    $result = [];
    foreach ($tutorRows as $tId => $rows) {
        usort($rows, function($a, $b) {
            return strcmp(normalizeTimeSlotKey($a['timeslot']), normalizeTimeSlotKey($b['timeslot']));
        });
        foreach ($rows as $r) {
            $result[] = $r;
        }
    }
    return $result;
}

function mergeScheduleCellValues(string $first, string $second): string {
    $names = [];
    foreach (array_merge(scheduleCellNames($first), scheduleCellNames($second)) as $name) {
        $names[strtolower($name)] ??= $name;
    }
    if ($names) return implode(', ', array_values($names));
    $markers = [strtolower(trim($first)), strtolower(trim($second))];
    if (in_array('n/a', $markers, true) || in_array('na', $markers, true)) return 'N/A';
    if (in_array('available', $markers, true)) return 'Available';
    return '';
}

