<?php
// includes/functions.php — SaveWithLucia
// Core rotation + payment cycle logic

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
    $r    = $conn->query("SELECT user_code FROM users WHERE user_code LIKE 'SWL-%' ORDER BY id DESC LIMIT 1");
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
        $rand = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        $ref  = 'SWL-' . $date . '-' . $rand;
        $s    = $conn->prepare("SELECT id FROM payments WHERE reference=?");
        $s->bind_param("s", $ref); $s->execute(); $s->store_result();
        $exists = $s->num_rows > 0; $s->close();
    } while ($exists);
    return $ref;
}

// -----------------------------------------------
// COLLECTION DATE FORMULA
// Position 1 collects on start_date.
// Position N collects on start_date + frequency_days * (N-1).
// -----------------------------------------------
function calculateCollectionDate($plan_start_date, $frequency_days, $position) {
    if (!$plan_start_date) return null;
    $start = new DateTime($plan_start_date);
    $days  = intval($frequency_days) * (intval($position) - 1);
    if ($days > 0) $start->modify('+' . $days . ' days');
    return $start->format('Y-m-d');
}

function calculatePayoutAmount($contribution_amount, $total_participants) {
    return (float)$contribution_amount * (int)$total_participants;
}

// -----------------------------------------------
// CALCULATE NEXT PAYMENT DATE AFTER A PAYMENT
//
// The plan end date is:
//   start_date + frequency_days * (total_participants - 1)
//   (the day the last person collects)
//
// After each payment, the next payment date moves forward
// by one frequency cycle. If that date is past the plan
// end date, the member has finished paying.
// -----------------------------------------------
function calculateNextPaymentDate($plan_start_date, $frequency_days, $cycles_paid_so_far) {
    if (!$plan_start_date) return null;
    $start    = new DateTime($plan_start_date);
    $days_add = intval($frequency_days) * intval($cycles_paid_so_far);
    $start->modify('+' . $days_add . ' days');
    return $start->format('Y-m-d');
}

// -----------------------------------------------
// CALCULATE PLAN END DATE
// The plan ends when the last person collects:
//   start_date + frequency_days * (total_participants - 1)
// -----------------------------------------------
function calculatePlanEndDate($plan_start_date, $frequency_days, $total_participants) {
    if (!$plan_start_date) return null;
    $start    = new DateTime($plan_start_date);
    $days_add = intval($frequency_days) * (intval($total_participants) - 1);
    if ($days_add > 0) $start->modify('+' . $days_add . ' days');
    return $start->format('Y-m-d');
}

// -----------------------------------------------
// CHECK IF A MEMBER HAS FINISHED PAYING
// A member is done paying once the plan end date
// has passed AND the plan is completed.
// -----------------------------------------------
function memberHasFinishedPaying($conn, $contribution_id) {
    $s = $conn->prepare(
        "SELECT c.has_collected, c.total_cycles_paid,
                p.total_participants, p.plan_start_date,
                p.frequency_days, p.plan_status
         FROM contributions c JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ?"
    );
    $s->bind_param("i", $contribution_id); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) return true;

    // Plan not started yet — nobody is done paying
    if (!$row['plan_start_date']) return false;

    // Plan fully completed — everyone is done
    if ($row['plan_status'] === 'completed') return true;

    // Member is done paying when they have paid total_participants cycles
    // (one payment per cycle for the full rotation)
    return intval($row['total_cycles_paid']) >= intval($row['total_participants']);
}

// -----------------------------------------------
// UPDATE NEXT PAYMENT DATE + CYCLE COUNT
// Call this after every confirmed payment.
// -----------------------------------------------
function updateMemberPaymentCycle($conn, $contribution_id) {
    // Load current state
    $s = $conn->prepare(
        "SELECT c.total_cycles_paid, p.plan_start_date,
                p.frequency_days, p.total_participants
         FROM contributions c JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ?"
    );
    $s->bind_param("i", $contribution_id); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) return;

    $new_cycles_paid = intval($row['total_cycles_paid']) + 1;

    // Calculate next payment date
    $next_date = calculateNextPaymentDate(
        $row['plan_start_date'],
        $row['frequency_days'],
        $new_cycles_paid    // next due = cycles_paid * frequency_days from start
    );

    // If next date is past plan end, member is done paying
    $plan_end = calculatePlanEndDate(
        $row['plan_start_date'],
        $row['frequency_days'],
        $row['total_participants']
    );

    // If we have gone past the plan end date, clear next_payment_date
    if ($plan_end && $next_date && $next_date > $plan_end) {
        $next_date = null;
    }

    $u = $conn->prepare(
        "UPDATE contributions
         SET total_cycles_paid = ?, next_payment_date = ?
         WHERE id = ?"
    );
    $u->bind_param("isi", $new_cycles_paid, $next_date, $contribution_id);
    $u->execute(); $u->close();
}

// -----------------------------------------------
// CHECK IF MEMBER HAS A PENDING PAYMENT
// -----------------------------------------------
function memberHasPendingPayment($conn, $contribution_id) {
    $s = $conn->prepare(
        "SELECT COUNT(*) AS c FROM payments
         WHERE contribution_id = ? AND status = 'pending'"
    );
    $s->bind_param("i", $contribution_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c > 0;
}

// -----------------------------------------------
// CHECK IF MEMBER HAS ALREADY PAID THIS CYCLE
// A cycle = the current frequency_days window
// -----------------------------------------------
function memberHasPaidThisCycle($conn, $contribution_id) {
    // Load frequency_days for this contribution's plan
    $s = $conn->prepare(
        "SELECT p.frequency_days, p.plan_start_date, c.total_cycles_paid
         FROM contributions c JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ?"
    );
    $s->bind_param("i", $contribution_id); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) return false;

    // Count payments made in the last frequency_days window
    $freq = intval($row['frequency_days']);
    $s2   = $conn->prepare(
        "SELECT COUNT(*) AS c FROM payments
         WHERE contribution_id = ?
           AND status = 'paid'
           AND paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $s2->bind_param("ii", $contribution_id, $freq); $s2->execute();
    $c = intval($s2->get_result()->fetch_assoc()['c']); $s2->close();
    return $c > 0;
}

// -----------------------------------------------
// FIXED: Mark ONE user as collected.
// IMPORTANT: This does NOT hide the pay button.
// The member still needs to keep paying every cycle
// until the LAST person in the plan collects.
// -----------------------------------------------
function markUserCollected($conn, $contribution_id) {
    $stmt = $conn->prepare(
        "SELECT c.id, c.plan_id, c.position, c.has_collected,
                p.total_participants, p.plan_status, p.name AS plan_name
         FROM contributions c JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ?"
    );
    $stmt->bind_param("i", $contribution_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$row)              return ['success' => false, 'error' => 'Contribution not found.'];
    if ($row['has_collected']) return ['success' => false, 'error' => 'Already marked as collected.'];

    $now   = date('Y-m-d H:i:s');
    $stmt2 = $conn->prepare(
        "UPDATE contributions SET has_collected = 1, collected_at = ?
         WHERE id = ? AND has_collected = 0"
    );
    $stmt2->bind_param("si", $now, $contribution_id); $stmt2->execute();
    $affected = $stmt2->affected_rows; $stmt2->close();

    if ($affected === 0) return ['success' => false, 'error' => 'Update failed or already collected.'];

    $plan_id = $row['plan_id'];

    // Recount after update
    $stmt3 = $conn->prepare(
        "SELECT COUNT(*) AS c FROM contributions
         WHERE plan_id = ? AND has_collected = 1 AND status != 'removed'"
    );
    $stmt3->bind_param("i", $plan_id); $stmt3->execute();
    $collected_count = intval($stmt3->get_result()->fetch_assoc()['c']); $stmt3->close();

    $stmt4 = $conn->prepare(
        "SELECT MIN(position) AS next_pos FROM contributions
         WHERE plan_id = ? AND has_collected = 0 AND status != 'removed'"
    );
    $stmt4->bind_param("i", $plan_id); $stmt4->execute();
    $next_pos = $stmt4->get_result()->fetch_assoc()['next_pos']; $stmt4->close();

    $total        = intval($row['total_participants']);
    $is_completed = ($total > 0 && $collected_count >= $total);
    $new_status   = $is_completed ? 'completed' : $row['plan_status'];
    $cur_pos      = $next_pos ?? $total;

    $stmt5 = $conn->prepare(
        "UPDATE plans SET total_collected_count = ?, current_position = ?, plan_status = ?
         WHERE id = ?"
    );
    $stmt5->bind_param("iisi", $collected_count, $cur_pos, $new_status, $plan_id);
    $stmt5->execute(); $stmt5->close();

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
// Get accurate collected count
// -----------------------------------------------
function getPlanCollectedCount($conn, $plan_id) {
    $s = $conn->prepare(
        "SELECT COUNT(*) AS c FROM contributions
         WHERE plan_id = ? AND has_collected = 1 AND status != 'removed'"
    );
    $s->bind_param("i", $plan_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c;
}

// -----------------------------------------------
// Get who is currently due to collect
// -----------------------------------------------
function getCurrentCollector($conn, $plan_id) {
    $today = date('Y-m-d');
    $s = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.phone, u.user_code
         FROM contributions c JOIN users u ON c.user_id = u.id
         WHERE c.plan_id = ? AND c.collection_date = ?
           AND c.has_collected = 0 AND c.status != 'removed'
         ORDER BY c.position ASC LIMIT 1"
    );
    $s->bind_param("is", $plan_id, $today); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) return $row;

    $s2 = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.phone, u.user_code
         FROM contributions c JOIN users u ON c.user_id = u.id
         WHERE c.plan_id = ? AND c.has_collected = 0 AND c.status != 'removed'
         ORDER BY c.position ASC LIMIT 1"
    );
    $s2->bind_param("i", $plan_id); $s2->execute();
    $row2 = $s2->get_result()->fetch_assoc(); $s2->close();
    return $row2;
}

// -----------------------------------------------
// Plan slot helpers
// -----------------------------------------------
function getNextAvailablePosition($conn, $plan_id, $total_participants) {
    for ($pos = 1; $pos <= $total_participants; $pos++) {
        $s = $conn->prepare("SELECT id FROM contributions WHERE plan_id = ? AND position = ?");
        $s->bind_param("ii", $plan_id, $pos); $s->execute(); $s->store_result();
        $taken = $s->num_rows > 0; $s->close();
        if (!$taken) return $pos;
    }
    return null;
}

function getPlanMemberCount($conn, $plan_id) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM contributions WHERE plan_id = ? AND status != 'removed'");
    $s->bind_param("i", $plan_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c;
}

function isPlanFull($conn, $plan_id) {
    $s = $conn->prepare("SELECT total_participants FROM plans WHERE id = ?");
    $s->bind_param("i", $plan_id); $s->execute();
    $plan = $s->get_result()->fetch_assoc(); $s->close();
    if (!$plan) return true;
    return getPlanMemberCount($conn, $plan_id) >= intval($plan['total_participants']);
}

function userAlreadyJoinedPlan($conn, $user_id, $plan_id) {
    $s = $conn->prepare("SELECT id FROM contributions WHERE user_id = ? AND plan_id = ? AND status != 'removed'");
    $s->bind_param("ii", $user_id, $plan_id); $s->execute(); $s->store_result();
    $f = $s->num_rows > 0; $s->close();
    return $f;
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
        'completed' => 'Collected ✓ — keep paying until everyone collects',
    ][$status] ?? 'Unknown';
}
function getDaysUntilCollection($collection_date) {
    if (!$collection_date) return null;
    $today = new DateTime();
    $cdate = new DateTime($collection_date);
    if ($today > $cdate) return 0;
    return intval($today->diff($cdate)->days);
}

// -----------------------------------------------
// User data helpers
// -----------------------------------------------
function getPlanMembers($conn, $plan_id) {
    $s = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.phone, u.user_code
         FROM contributions c JOIN users u ON c.user_id = u.id
         WHERE c.plan_id = ? AND c.status != 'removed'
         ORDER BY c.position ASC"
    );
    $s->bind_param("i", $plan_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    return $rows;
}

function getUserContributions($conn, $user_id) {
    $s = $conn->prepare(
        "SELECT c.*, p.name AS plan_name, p.contribution_amount,
                p.frequency_days, p.total_participants,
                p.plan_start_date, p.plan_status, p.total_collected_count
         FROM contributions c JOIN plans p ON c.plan_id = p.id
         WHERE c.user_id = ? AND c.status != 'removed'
         ORDER BY c.joined_at DESC"
    );
    $s->bind_param("i", $user_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    return $rows;
}

function getUserTotalPaid($conn, $user_id) {
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) AS t
         FROM payments p JOIN contributions c ON p.contribution_id = c.id
         WHERE c.user_id = ? AND p.status = 'paid'"
    );
    $s->bind_param("i", $user_id); $s->execute();
    $t = floatval($s->get_result()->fetch_assoc()['t']); $s->close();
    return $t;
}

function countMemberPayments($conn, $contribution_id) {
    $s = $conn->prepare(
        "SELECT COUNT(*) AS c FROM payments WHERE contribution_id = ? AND status = 'paid'"
    );
    $s->bind_param("i", $contribution_id); $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']); $s->close();
    return $c;
}

// -----------------------------------------------
// Admin helpers
// -----------------------------------------------
function getTodaysCollectors($conn) {
    $today = date('Y-m-d');
    $s = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.phone, u.user_code,
                p.name AS plan_name, p.contribution_amount, p.total_participants
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         WHERE c.collection_date = ? AND c.has_collected = 0
         ORDER BY p.id, c.position"
    );
    $s->bind_param("s", $today); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    return $rows;
}

function getUpcomingCollectors($conn, $days = 7) {
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime("+$days days"));
    $s = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.user_code,
                p.name AS plan_name, p.total_participants, p.contribution_amount
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         WHERE c.collection_date BETWEEN ? AND ? AND c.has_collected = 0
         ORDER BY c.collection_date ASC, p.id"
    );
    $s->bind_param("ss", $today, $limit); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    return $rows;
}

function recalculatePlanDates($conn, $plan_id) {
    $s = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $s->bind_param("i", $plan_id); $s->execute();
    $plan = $s->get_result()->fetch_assoc(); $s->close();
    if (!$plan || !$plan['plan_start_date']) return;

    $members = getPlanMembers($conn, $plan_id);
    $payout  = calculatePayoutAmount($plan['contribution_amount'], $plan['total_participants']);

    foreach ($members as $m) {
        $cdate = calculateCollectionDate(
            $plan['plan_start_date'],
            $plan['frequency_days'],
            $m['position']
        );
        // Also recalculate next_payment_date
        $next_pay = calculateNextPaymentDate(
            $plan['plan_start_date'],
            $plan['frequency_days'],
            intval($m['total_cycles_paid'] ?? 0)
        );
        $s2 = $conn->prepare(
            "UPDATE contributions
             SET collection_date = ?, payout_amount = ?, next_payment_date = ?
             WHERE id = ?"
        );
        $s2->bind_param("sdsi", $cdate, $payout, $next_pay, $m['id']);
        $s2->execute(); $s2->close();
    }
}

function saveNotification($conn, $user_id, $title, $message, $type = 'info') {
    $s = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)"
    );
    if ($s) {
        $s->bind_param("isss", $user_id, $title, $message, $type);
        $s->execute(); $s->close();
    }
}

function exportMembersCSV($conn, $plan_id = null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members_' . date('Ymd') . '.csv"');
    $out   = fopen('php://output', 'w');
    fputcsv($out, [
        'Member Code','Name','Email','Phone','Plan','Position',
        'Collection Date','Payout Amount','Collected',
        'Cycles Paid','Next Payment Date','Joined'
    ]);
    $where = $plan_id ? "WHERE c.plan_id=$plan_id" : '';
    $r = $conn->query(
        "SELECT u.user_code, u.name, u.email, u.phone,
                p.name AS plan_name, c.position, c.collection_date,
                c.payout_amount, c.has_collected,
                c.total_cycles_paid, c.next_payment_date, c.joined_at
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         $where ORDER BY p.id, c.position"
    );
    while ($row = $r->fetch_assoc()) {
        fputcsv($out, [
            $row['user_code'], $row['name'], $row['email'], $row['phone'],
            $row['plan_name'], 'Position ' . $row['position'],
            $row['collection_date'] ?: 'TBD',
            number_format($row['payout_amount'], 2),
            $row['has_collected'] ? 'Yes' : 'No',
            $row['total_cycles_paid'] ?? 0,
            $row['next_payment_date'] ?: 'Done',
            date('Y-m-d', strtotime($row['joined_at'])),
        ]);
    }
    fclose($out);
    exit();
}
?>
