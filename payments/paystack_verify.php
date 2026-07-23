<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireLogin();

$user_id   = $_SESSION['user_id'];
$cid       = intval($_GET['cid'] ?? 0);
$reference = trim($_GET['reference'] ?? '');

if ($cid <= 0 || empty($reference)) {
    setFlash('error', 'Invalid verification.');
    header("Location: " . SITE_URL . "/pages/dashboard.php"); exit();
}

$stmt = $conn->prepare(
    "SELECT c.*, p.name AS plan_name, p.contribution_amount,
            p.frequency_days, p.plan_start_date, p.total_participants
     FROM contributions c JOIN plans p ON c.plan_id = p.id
     WHERE c.id = ? AND c.user_id = ?"
);
$stmt->bind_param("ii", $cid, $user_id); $stmt->execute();
$contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$contrib) {
    setFlash('error', 'Contribution not found.');
    header("Location: " . SITE_URL . "/pages/dashboard.php"); exit();
}

$stmt = $conn->prepare("SELECT email, name, user_code, phone FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

// Verify with Paystack
$ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . PAYSTACK_SECRET_KEY]);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($result && $result['status'] === true && $result['data']['status'] === 'success') {

    $paid_amt = $result['data']['amount'] / 100;
    $paid_at  = date('Y-m-d H:i:s');

    // Check not already recorded
    $chk = $conn->prepare("SELECT id FROM payments WHERE reference = ?");
    $chk->bind_param("s", $reference); $chk->execute(); $chk->store_result();
    $already = $chk->num_rows > 0; $chk->close();

    if (!$already) {
        // Get current cycle number
        $cycle_num = countMemberPayments($conn, $cid) + 1;

        // Record the payment
        $ins = $conn->prepare(
            "INSERT INTO payments (contribution_id, reference, amount, status, paid_at, cycle_number)
             VALUES (?, ?, ?, 'paid', ?, ?)"
        );
        $ins->bind_param("isdsi", $cid, $reference, $paid_amt, $paid_at, $cycle_num);
        $ins->execute(); $ins->close();

        // ---- Update member's payment cycle (next payment date) ----
        updateMemberPaymentCycle($conn, $cid);

        // Re-read next_payment_date to include in email
        $s = $conn->prepare("SELECT next_payment_date FROM contributions WHERE id = ?");
        $s->bind_param("i", $cid); $s->execute();
        $updated = $s->get_result()->fetch_assoc(); $s->close();
        $next_pay = $updated['next_payment_date'] ?? null;

        // Send confirmation email
        sendPaymentConfirmationEmail(
            $conn, $user_id, $user['email'], $user['name'], $user['user_code'] ?? '',
            $contrib['plan_name'], $paid_amt, 'online', $reference, $paid_at,
            $contrib['position'], $contrib['collection_date'], $contrib['payout_amount']
        );

        $msg = 'Payment of ' . formatMoney($paid_amt) . ' confirmed!';
        if ($next_pay) {
            $msg .= ' Your next contribution is due on <strong>' . date('F j, Y', strtotime($next_pay)) . '</strong>.';
        }
        $msg .= ' A confirmation email has been sent.';
        setFlash('success', $msg);
    } else {
        setFlash('success', 'Payment already recorded.');
    }
} else {
    $ins = $conn->prepare(
        "INSERT INTO payments (contribution_id, reference, amount, status) VALUES (?, ?, ?, 'failed')"
    );
    $amt = $contrib['contribution_amount'];
    $ins->bind_param("isd", $cid, $reference, $amt); $ins->execute(); $ins->close();
    setFlash('error', 'Payment could not be verified. Please try again or contact admin.');
}

header("Location: " . SITE_URL . "/pages/dashboard.php"); exit();
?>
