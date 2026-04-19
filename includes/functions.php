<?php
// includes/functions.php
// Core rotation logic — fixed collection tracking

// -----------------------------------------------
// FORMAT HELPERS
// -----------------------------------------------
function formatMoney($amount) {
    return '₦' . number_format((float)$amount, 2);
}
function formatFrequency($days) {
    switch (intval($days)) {
        case 1:  return 'Daily';
        case 7:  return 'Weekly';
        case 14: return 'Every 2 Weeks';
        case 30: return 'Monthly';
        default: return 'Every ' . $days . ' Days';
    }
}

// -----------------------------------------------
// GENERATE USER CODE e.g. SWL-000001
// -----------------------------------------------
function generateUserCode($conn) {
    $r = $conn->query("SELECT user_code FROM users WHERE user_code LIKE 'SWL-%' ORDER BY id DESC LIMIT 1");
    $last = $r ? $r->fetch_assoc() : null;
    $next = 1;
    if ($last && preg_match('/SWL-(\d+)/', $last['user_code'], $m)) $next = intval($m[1]) + 1;
    return 'SWL-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

// -----------------------------------------------
// GENERATE PAYMENT REFERENCE
// -----------------------------------------------
function generatePaymentReference($conn) {
    $date = date('Ymd');
    do {
        $rand = strtoupper(substr(md5(uniqid(rand(),true)),0,6));
        $ref  = 'SWL-'.$date.'-'.$rand;
        $s    = $conn->prepare("SELECT id FROM payments WHERE reference=?");
        $s->bind_param("s",$ref); $s->execute(); $s->store_result();
        $exists = $s->num_rows > 0; $s->close();
    } while ($exists);
    return $ref;
}

// -----------------------------------------------
// COLLECTION DATE FORMULA
// collection_date = start_date + (frequency_days * (position - 1))
// -----------------------------------------------
function calculateCollectionDate($plan_start_date, $frequency_days, $position) {
    if (!$plan_start_date) return null;
    $start = new DateTime($plan_start_date);
    $days  = $frequency_days * ($position - 1);
    if ($days > 0) $start->modify('+' . $days . ' days');
    return $start->format('Y-m-d');
}

function calculatePayoutAmount($contribution_amount, $total_participants) {
    return (float)$contribution_amount * (int)$total_participants;
}

// -----------------------------------------------
// FIXED: Mark ONE user as collected.
// Only marks the plan as completed when ALL
// participants have collected — not just one.
// -----------------------------------------------
function markUserCollected($conn, $contribution_id) {
    // Step 1: Load full details before any changes
    $stmt = $conn->prepare(
        "SELECT c.id, c.plan_id, c.position, c.has_collected,
                p.total_participants, p.plan_status, p.name AS plan_name
         FROM contributions c JOIN plans p ON c.plan_id=p.id
         WHERE c.id=?"
    );
    $stmt->bind_param("i",$contribution_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$row) return ['success'=>false,'error'=>'Contribution not found.'];
    if ($row['has_collected']) return ['success'=>false,'error'=>'Already marked as collected.'];

    // Step 2: Mark ONLY this one user as collected
    $now = date('Y-m-d H:i:s');
    $stmt2 = $conn->prepare("UPDATE contributions SET has_collected=1, collected_at=? WHERE id=? AND has_collected=0");
    $stmt2->bind_param("si",$now,$contribution_id); $stmt2->execute();
    $affected = $stmt2->affected_rows; $stmt2->close();

    if ($affected === 0) return ['success'=>false,'error'=>'Update failed or already collected.'];

    $plan_id = $row['plan_id'];

    // Step 3: Count how many have NOW collected AFTER the update
    $stmt3 = $conn->prepare("SELECT COUNT(*) AS c FROM contributions WHERE plan_id=? AND has_collected=1 AND status!='removed'");
    $stmt3->bind_param("i",$plan_id); $stmt3->execute();
    $collected_count = intval($stmt3->get_result()->fetch_assoc()['c']); $stmt3->close();

    // Step 4: Find next uncollected position
    $stmt4 = $conn->prepare("SELECT MIN(position) AS next_pos FROM contributions WHERE plan_id=? AND has_collected=0 AND status!='removed'");
    $stmt4->bind_param("i",$plan_id); $stmt4->execute();
    $next_pos = $stmt4->get_result()->fetch_assoc()['next_pos']; $stmt4->close();

    // Step 5: Plan is ONLY completed when ALL participants have collected
    $total        = intval($row['total_participants']);
    $is_completed = ($total > 0 && $collected_count >= $total);
    $new_status   = $is_completed ? 'completed' : $row['plan_status'];

    // Step 6: Update plan counters atomically
    $cur_pos = $next_pos ?? $total;
    $stmt5 = $conn->prepare("UPDATE plans SET total_collected_count=?, current_position=?, plan_status=? WHERE id=?");
    $stmt5->bind_param("iisi",$collected_count,$cur_pos,$new_status,$plan_id); $stmt5->execute(); $stmt5->close();

    return [
        'success'         => true,
        'collected_count' => $collected_count,
        'total'           => $total,
        'is_completed'    => $is_completed,
        'next_position'   => $next_pos,
        'plan_name'       => $row['plan_name'],
    ];
}

// -----------------------------------------------
// Get accurate collected count — always live from DB
// -----------------------------------------------
function getPlanCollectedCount($conn, $plan_id) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM contributions WHERE plan_id=? AND has_collected=1 AND status!='removed'");
    $s->bind_param("i",$plan_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c;
}

// -----------------------------------------------
// Get who is currently due to collect
// -----------------------------------------------
function getCurrentCollector($conn, $plan_id) {
    $today = date('Y-m-d');
    $s = $conn->prepare("SELECT c.*,u.name AS user_name,u.email,u.phone,u.user_code FROM contributions c JOIN users u ON c.user_id=u.id WHERE c.plan_id=? AND c.collection_date=? AND c.has_collected=0 AND c.status!='removed' ORDER BY c.position ASC LIMIT 1");
    $s->bind_param("is",$plan_id,$today); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) return $row;
    $s2 = $conn->prepare("SELECT c.*,u.name AS user_name,u.email,u.phone,u.user_code FROM contributions c JOIN users u ON c.user_id=u.id WHERE c.plan_id=? AND c.has_collected=0 AND c.status!='removed' ORDER BY c.position ASC LIMIT 1");
    $s2->bind_param("i",$plan_id); $s2->execute();
    $row2 = $s2->get_result()->fetch_assoc(); $s2->close();
    return $row2;
}

// -----------------------------------------------
// Plan slot helpers
// -----------------------------------------------
function getNextAvailablePosition($conn, $plan_id, $total_participants) {
    for ($pos = 1; $pos <= $total_participants; $pos++) {
        $s = $conn->prepare("SELECT id FROM contributions WHERE plan_id=? AND position=?");
        $s->bind_param("ii",$plan_id,$pos); $s->execute(); $s->store_result();
        $taken = $s->num_rows > 0; $s->close();
        if (!$taken) return $pos;
    }
    return null;
}

function getPlanMemberCount($conn, $plan_id) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM contributions WHERE plan_id=? AND status!='removed'");
    $s->bind_param("i",$plan_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c;
}

function isPlanFull($conn, $plan_id) {
    $s = $conn->prepare("SELECT total_participants FROM plans WHERE id=?");
    $s->bind_param("i",$plan_id); $s->execute();
    $plan = $s->get_result()->fetch_assoc(); $s->close();
    if (!$plan) return true;
    return getPlanMemberCount($conn,$plan_id) >= intval($plan['total_participants']);
}

function userAlreadyJoinedPlan($conn, $user_id, $plan_id) {
    $s = $conn->prepare("SELECT id FROM contributions WHERE user_id=? AND plan_id=? AND status!='removed'");
    $s->bind_param("ii",$user_id,$plan_id); $s->execute(); $s->store_result();
    $f = $s->num_rows > 0; $s->close(); return $f;
}

// -----------------------------------------------
// Rotation status helpers
// -----------------------------------------------
function getRotationStatus($collection_date, $has_collected) {
    if ($has_collected) return 'completed';
    if (!$collection_date) return 'waiting';
    $today = date('Y-m-d');
    if ($collection_date === $today) return 'your_turn';
    if ($collection_date < $today)   return 'overdue';
    if ($collection_date <= date('Y-m-d', strtotime('+3 days'))) return 'upcoming';
    return 'waiting';
}
function getRotationStatusLabel($status) {
    return [
        'waiting'   => 'Waiting for your turn',
        'upcoming'  => 'Your turn is coming soon!',
        'your_turn' => 'Today is YOUR turn!',
        'overdue'   => 'Payout pending',
        'completed' => 'Collected',
    ][$status] ?? 'Unknown';
}
function getDaysUntilCollection($collection_date) {
    if (!$collection_date) return null;
    $today = new DateTime(); $cdate = new DateTime($collection_date);
    if ($today > $cdate) return 0;
    return intval($today->diff($cdate)->days);
}

// -----------------------------------------------
// User data helpers
// -----------------------------------------------
function getPlanMembers($conn, $plan_id) {
    $s = $conn->prepare("SELECT c.*,u.name AS user_name,u.email,u.phone,u.user_code FROM contributions c JOIN users u ON c.user_id=u.id WHERE c.plan_id=? AND c.status!='removed' ORDER BY c.position ASC");
    $s->bind_param("i",$plan_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
}
function getUserContributions($conn, $user_id) {
    $s = $conn->prepare("SELECT c.*,p.name AS plan_name,p.contribution_amount,p.frequency_days,p.total_participants,p.plan_start_date,p.plan_status,p.total_collected_count FROM contributions c JOIN plans p ON c.plan_id=p.id WHERE c.user_id=? AND c.status!='removed' ORDER BY c.joined_at DESC");
    $s->bind_param("i",$user_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
}
function getUserTotalPaid($conn, $user_id) {
    $s = $conn->prepare("SELECT COALESCE(SUM(p.amount),0) AS t FROM payments p JOIN contributions c ON p.contribution_id=c.id WHERE c.user_id=? AND p.status='paid'");
    $s->bind_param("i",$user_id); $s->execute();
    $t = floatval($s->get_result()->fetch_assoc()['t']); $s->close(); return $t;
}
function countMemberPayments($conn, $contribution_id) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM payments WHERE contribution_id=? AND status='paid'");
    $s->bind_param("i",$contribution_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close(); return $c;
}

// -----------------------------------------------
// Admin helpers
// -----------------------------------------------
function getTodaysCollectors($conn) {
    $today = date('Y-m-d');
    $s = $conn->prepare("SELECT c.*,u.name AS user_name,u.email,u.phone,u.user_code,p.name AS plan_name,p.contribution_amount,p.total_participants FROM contributions c JOIN users u ON c.user_id=u.id JOIN plans p ON c.plan_id=p.id WHERE c.collection_date=? AND c.has_collected=0 ORDER BY p.id,c.position");
    $s->bind_param("s",$today); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
}
function getUpcomingCollectors($conn, $days=7) {
    $today = date('Y-m-d'); $limit = date('Y-m-d',strtotime("+$days days"));
    $s = $conn->prepare("SELECT c.*,u.name AS user_name,u.user_code,p.name AS plan_name,p.total_participants,p.contribution_amount FROM contributions c JOIN users u ON c.user_id=u.id JOIN plans p ON c.plan_id=p.id WHERE c.collection_date BETWEEN ? AND ? AND c.has_collected=0 ORDER BY c.collection_date ASC,p.id");
    $s->bind_param("ss",$today,$limit); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
}
function recalculatePlanDates($conn, $plan_id) {
    $s = $conn->prepare("SELECT * FROM plans WHERE id=?");
    $s->bind_param("i",$plan_id); $s->execute();
    $plan = $s->get_result()->fetch_assoc(); $s->close();
    if (!$plan || !$plan['plan_start_date']) return;
    $members = getPlanMembers($conn,$plan_id);
    $payout  = calculatePayoutAmount($plan['contribution_amount'],$plan['total_participants']);
    foreach ($members as $m) {
        $cdate = calculateCollectionDate($plan['plan_start_date'],$plan['frequency_days'],$m['position']);
        $s2 = $conn->prepare("UPDATE contributions SET collection_date=?,payout_amount=? WHERE id=?");
        $s2->bind_param("sdi",$cdate,$payout,$m['id']); $s2->execute(); $s2->close();
    }
}
function saveNotification($conn, $user_id, $title, $message, $type='info') {
    $s = $conn->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)");
    if ($s) { $s->bind_param("isss",$user_id,$title,$message,$type); $s->execute(); $s->close(); }
}
function exportMembersCSV($conn, $plan_id=null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Member Code','Name','Email','Phone','Plan','Position','Collection Date','Payout Amount','Collected','Joined']);
    $where = $plan_id ? "WHERE c.plan_id=$plan_id" : '';
    $r = $conn->query("SELECT u.user_code,u.name,u.email,u.phone,p.name AS plan_name,c.position,c.collection_date,c.payout_amount,c.has_collected,c.joined_at FROM contributions c JOIN users u ON c.user_id=u.id JOIN plans p ON c.plan_id=p.id $where ORDER BY p.id,c.position");
    while ($row=$r->fetch_assoc()) {
        fputcsv($out,[$row['user_code'],$row['name'],$row['email'],$row['phone'],$row['plan_name'],'Position '.$row['position'],$row['collection_date']?:' TBD',number_format($row['payout_amount'],2),$row['has_collected']?'Yes':'No',date('Y-m-d',strtotime($row['joined_at']))]);
    }
    fclose($out); exit();
}
?>
