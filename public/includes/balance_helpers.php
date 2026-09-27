<?php
function balanceStudioRentalTotal(array $e): float {
    $notes = $e['notes'] ?? '';
    $payload = is_array($notes) ? $notes : json_decode((string) $notes, true);
    $rental = is_array($payload) ? ($payload['studio_rental'] ?? null) : null;
    if (!is_array($rental)) return 0.0;
    if (isset($rental['total']) && is_numeric($rental['total'])) {
        return max(0.0, round((float) $rental['total'], 2));
    }
    $rate = isset($rental['rate']) && is_numeric($rental['rate']) ? (float) $rental['rate'] : 450.0;
    $total = 0.0;
    foreach ((array) ($rental['days'] ?? []) as $day) {
        $hours = isset($day['hours']) && is_numeric($day['hours']) ? max(0, (float) $day['hours']) : 0;
        $total += $hours * $rate;
    }
    return max(0.0, round($total, 2));
}

function balanceTotalFee(array $e): float {
    static $catalog = null;
    if ($catalog === null && function_exists('getDB')) {
        try {
            $catalog = getDB()->query('SELECT program_name, package_name, rate FROM program_packages')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {
            $catalog = [];
        }
    }
    if ($catalog === null) $catalog = [];

    $key = static function($s) {
        $s = strtolower($s);
        if (str_contains($s, 'rental')) return 'studio_rental';
        foreach (['tutor'=>'tutorial','academic'=>'tutorial','workshop'=>'workshop','play'=>'playschool','child'=>'childcare','care'=>'childcare','studio'=>'madstudio','summer'=>'summerblast','vip'=>'vip'] as $n=>$k) if(str_contains($s,$n)) return $k;
        return $s;
    };
    $program = $key($e['program'] ?? '');
    $studioRentalTotal = balanceStudioRentalTotal($e);
    $addRental = static fn(float $base): float => round($base + $studioRentalTotal, 2);

    if ($program === 'studio_rental') return $studioRentalTotal;

    // VIP Membership rate is locked at enrollment time from package_selected
    if ($program === 'vip') {
        if (preg_match('/[₱P]\s*([0-9]+(?:,[0-9]{3})*(?:\.[0-9]+)?)/u', $e['package_selected'] ?? '', $m)) {
            $v = (float)str_replace(',', '', $m[1]);
            if ($v > 0) return $addRental($v);
        }
        if (preg_match('/(?:–|-)\s*[₱P]?\s*([0-9]{3,6}(?:,[0-9]{3})*(?:\.[0-9]+)?)/u', $e['package_selected'] ?? '', $m)) {
            $v = (float)str_replace(',', '', $m[1]);
            if ($v > 0) return $addRental($v);
        }
        return 500.0;
    }

    // For non-VIP programs: if package_selected has VIP discount (e.g. "Regular Package (VIP) – ₱3,135")
    $pkgRaw = $e['package_selected'] ?? '';
    if (stripos($pkgRaw, '(vip)') !== false) {
        if (preg_match('/[₱P]\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]+)?)/u', $pkgRaw, $m)) {
            $v = (float)str_replace(',', '', $m[1]);
            if ($v > 0) return $addRental($v);
        }
        if (preg_match('/(?:–|—|-)\s*[₱P]?\s*([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?)/u', $pkgRaw, $m)) {
            $v = (float)str_replace(',', '', $m[1]);
            if ($v > 0) return $addRental($v);
        }
        if (preg_match('/(?:–|—|-)\s*[₱P]?\s*([0-9]{3,6}(?:\.[0-9]+)?)/u', $pkgRaw, $m)) {
            $v = (float)str_replace(',', '', $m[1]);
            if ($v > 0) return $addRental($v);
        }
    }

    $norm = static function($s) {
        $s = preg_replace('/₱.*/u','',strtolower($s));
        $s = str_replace(['group subscription','group class',' class',' package'],['group','group','',''],$s);
        return trim(preg_replace('/[^a-z0-9]+/',' ',$s));
    };
    $pkg = $norm($e['package_selected'] ?? ''); $best = null; $length = 0;
    foreach($catalog as $row) {
        if($key($row['program_name']) !== $program) continue;
        $name = $norm($row['package_name']);
        if($name && ($pkg === $name || str_starts_with($pkg,$name.' ')) && strlen($name)>$length) { $best=$row; $length=strlen($name); }
    }
    if($best && preg_match('/[0-9][0-9,]*(?:\.[0-9]+)?/',$best['rate'],$m)) return $addRental((float)str_replace(',','',$m[0]));
    if(preg_match('/([0-9]{1,3},[0-9]{3})/',$e['package_selected'] ?? '',$m)) return $addRental((float)str_replace(',','',$m[1]));
    if($program==='tutorial') return $addRental(str_contains($pkg,'daily double')?9900:(str_contains($pkg,'daily')||str_contains($pkg,'double')?5500:3300));
    if($program==='childcare') return $addRental(str_contains($pkg,'weekly')?3500:(str_contains($pkg,'daily')?800:12000));
    return $addRental(['playschool'=>5000,'workshop'=>6000,'madstudio'=>2500,'summerblast'=>3500][$program] ?? 3300);
}
function balanceParseRecord(?string $notes): array { $r=json_decode($notes ?? '',true); return is_array($r)?$r:[]; }
function balanceMoneyValue($value): ?float {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) return null;
    $n=round((float)$value,2); return is_finite($n)?$n:null;
}
function balanceCurrentPaidAmount(array $e,array $record,float $totalFee): float {
    if(array_key_exists('paid_amount',$record)) { $n=balanceMoneyValue($record['paid_amount']); if($n!==null && $n>=0) return min($totalFee,$n); }
    if(!empty($record['balance_settled'])) return $totalFee;
    if(($e['status'] ?? '')!=='confirmed' && ($e['payment_status'] ?? '')!=='confirmed') return 0;
    $paid=str_contains(strtolower($e['program'] ?? ''),'vip')?$totalFee:min(1000,$totalFee);
    foreach($record['balance_receipts'] ?? [] as $r) if(($r['status'] ?? '')==='approved' && !empty($r['approved_amount'])) $paid+=(float)$r['approved_amount'];
    return min($totalFee,$paid);
}

