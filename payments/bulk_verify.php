<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireLogin();

$user_id   = $_SESSION['user_id'];
$cid       = intval($_GET['cid']       ?? 0);
$reference = trim($_GET['reference']   ?? '');
$cycles    = intval($_GET['cycles']    ?? 1);

if ($cid <= 0 || empty($reference) || $cycles < 1) {
    setFlash('error', 'Invalid request.');
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$stmt = $conn->prepare(
    "SELECT c.*, p.name AS plan_name, p.contribution_amount,
            p.frequency_days, p.total_participants
     FROM contributions c JOIN plans p ON c.plan_id=p.id
     WHERE c.id=? AND c.user_id=? AND c.status='active'"
);
$stmt->bind_param("ii", $cid, $user_id); $stmt->execute();
$contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$contrib) {
    setFlash('error', 'Contribution not found.');
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$stmt = $conn->prepare("SELECT email, name, user_code, phone FROM users WHERE id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

// Verify with Paystack
$ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . PAYSTACK_SECRET_KEY]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($result && $result['status'] === true && $result['data']['status'] === 'success') {

    $total_paid = $result['data']['amount'] / 100;
    $amount_per = floatval($contrib['contribution_amount']);
    $freq       = intval($contrib['frequency_days']);
    $paid_at    = date('Y-m-d H:i:s');

    // Check not already recorded
    $chk = $conn->prepare("SELECT id FROM payments WHERE reference=?");
    $chk->bind_param("s", $reference); $chk->execute(); $chk->store_result();
    $already = $chk->num_rows > 0; $chk->close();

    if (!$already) {
        $next_pay = $contrib['next_payment_date'] ?? date('Y-m-d');
        $cycle_start = countMemberPayments($conn, $cid) + 1;

        // Record EACH cycle as a separate payment row
        for ($i = 0; $i < $cycles; $i++) {
            $cycle_num  = $cycle_start + $i;
            $cycle_ref  = $reference . '-C' . ($i + 1);
            $cycle_date = date('Y-m-d', strtotime($next_pay . ' +' . ($i * $freq) . ' days'));
            $cycle_paid = date('Y-m-d H:i:s', strtotime($paid_at . ' +' . $i . ' seconds'));

            $ins = $conn->prepare(
                "INSERT INTO payments
                    (contribution_id, plan_id, reference, amount, status, paid_at, cycle_number)
                 VALUES (?, ?, ?, ?, 'paid', ?, ?)"
            );
            $plan_id = $contrib['plan_id'];
            $ins->bind_param("iisdsi", $cid, $plan_id, $cycle_ref, $amount_per, $cycle_paid, $cycle_num);
            $ins->execute(); $ins->close();
        }

        // Jump next_payment_date forward by N cycles
        $new_next = date('Y-m-d', strtotime($next_pay . ' +' . ($cycles * $freq) . ' days'));
        $new_total = intval($contrib['total_cycles_paid']) + $cycles;

        $upd = $conn->prepare(
            "UPDATE contributions SET next_payment_date=?, total_cycles_paid=? WHERE id=?"
        );
        $upd->bind_param("sii", $new_next, $new_total, $cid);
        $upd->execute(); $upd->close();

        // Send one confirmation email covering all cycles
        $freq_label = formatFrequency($freq);
        $start_date = date('M j, Y', strtotime($next_pay));
        $end_date   = date('M j, Y', strtotime($next_pay . ' +' . (($cycles-1) * $freq) . ' days'));
        $range_str  = ($cycles === 1) ? $start_date : "$start_date to $end_date";

        $subject = SITE_NAME . " — Bulk Payment Confirmed ({$cycles} cycle" . ($cycles>1?'s':'') . ")";
        $html = "
        <div style='font-family:sans-serif;max-width:520px;margin:0 auto;padding:20px;'>
            <h2 style='font-family:Georgia,serif;color:#1A1A1A;'>Payment Confirmed!</h2>
            <p>Hello <strong>{$user['name']}</strong>,</p>
            <p>Your bulk payment for <strong>{$contrib['plan_name']}</strong> has been confirmed.</p>
            <div style='background:#F2F0ED;border-radius:10px;padding:16px;margin:18px 0;'>
                <table style='width:100%;font-size:14px;border-collapse:collapse;'>
                    <tr><td style='padding:5px 0;color:#6B6860;width:40%'>Cycles Paid:</td><td><strong>{$cycles} cycle(s)</strong></td></tr>
                    <tr><td style='padding:5px 0;color:#6B6860;'>Dates Covered:</td><td><strong>{$range_str}</strong></td></tr>
                    <tr><td style='padding:5px 0;color:#6B6860;'>Amount Paid:</td><td><strong style='color:#C9A84C;font-size:16px;'>₦" . number_format($total_paid, 2) . "</strong></td></tr>
                    <tr><td style='padding:5px 0;color:#6B6860;'>Next Payment Due:</td><td><strong>" . date('M j, Y', strtotime($new_next)) . "</strong></td></tr>
                    <tr><td style='padding:5px 0;color:#6B6860;'>Reference:</td><td><code style='font-size:12px;'>{$reference}</code></td></tr>
                </table>
            </div>
            <p style='font-size:13px;color:#6B6860;'>Your next contribution is due on <strong>" . date('M j, Y', strtotime($new_next)) . "</strong>.</p>
            <div style='margin-top:20px;'>
                <a href='" . SITE_URL . "/pages/dashboard.php' style='background:#C9A84C;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>View Dashboard</a>
            </div>
        </div>";

        sendEmail($user['email'], $user['name'], $subject, $html);
        logEmail($conn, $user_id, $user['email'], $subject, 'sent');

        $freq_word = $freq===1?'day':($freq===7?'week':($freq===30?'month':'cycle'));
        $freq_word_pl = $cycles > 1 ? $freq_word.'s' : $freq_word;

        setFlash('success',
            "Bulk payment confirmed! You have paid <strong>$cycles $freq_word_pl</strong> " .
            "(" . formatMoney($total_paid) . "). " .
            "Covered: <strong>$range_str</strong>. " .
            "Next payment due: <strong>" . date('M j, Y', strtotime($new_next)) . "</strong>."
        );

    } else {
        setFlash('success', 'Payment already recorded.');
    }

} else {
    setFlash('error', 'Payment could not be verified. Please contact admin with reference: ' . htmlspecialchars($reference));
}

redirectTo(SITE_URL . "/pages/dashboard.php");
?>
