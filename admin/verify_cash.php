<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
require_once '../includes/security.php';
requireAdmin();

if (isset($_GET['approve'])) {
    $pay_id = intval($_GET['approve']);

    $stmt = $conn->prepare(
        "SELECT p.*, c.id AS cid, c.user_id, c.position, c.collection_date,
                c.payout_amount, c.plan_id,
                pl.name AS plan_name, pl.frequency_days, pl.plan_start_date,
                pl.total_participants,
                u.name AS user_name, u.email, u.user_code, u.phone
         FROM payments p
         JOIN contributions c ON p.contribution_id = c.id
         JOIN plans pl ON c.plan_id = pl.id
         JOIN users u ON c.user_id = u.id
         WHERE p.id = ? AND p.status = 'pending'"
    );
    $stmt->bind_param("i", $pay_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($row) {
        $now = date('Y-m-d H:i:s');

        // Get current cycle number
        $cycle_num = countMemberPayments($conn, $row['cid']) + 1;

        // Mark payment as paid
        $upd = $conn->prepare(
            "UPDATE payments SET status='paid', paid_at=?, cycle_number=? WHERE id=?"
        );
        $upd->bind_param("sii", $now, $cycle_num, $pay_id);
        $upd->execute(); $upd->close();

        // ---- Update next payment date for this member ----
        updateMemberPaymentCycle($conn, $row['cid']);

        // Re-read next_payment_date
        $s = $conn->prepare("SELECT next_payment_date FROM contributions WHERE id = ?");
        $s->bind_param("i", $row['cid']); $s->execute();
        $updated = $s->get_result()->fetch_assoc(); $s->close();
        $next_pay = $updated['next_payment_date'] ?? null;

        // Send confirmation email
        sendPaymentConfirmationEmail(
            $conn, $row['user_id'], $row['email'],
            $row['user_name'], $row['user_code'] ?? '',
            $row['plan_name'], $row['amount'], 'cash',
            $row['reference'], $now,
            $row['position'], $row['collection_date'], $row['payout_amount']
        );

        logAudit(
            $conn, $_SESSION['user_id'], 'cash_payment_approved',
            "Approved cash payment #{$pay_id} of " . formatMoney($row['amount']) .
            " for {$row['user_name']} ({$row['user_code']}) on plan '{$row['plan_name']}'"
        );

        $msg = 'Cash payment approved for ' . htmlspecialchars($row['user_name']) . '.';
        if ($next_pay) {
            $msg .= ' Their next payment is due on ' . date('M j, Y', strtotime($next_pay)) . '.';
        }
        setFlash('success', $msg);
    } else {
        setFlash('error', 'Payment not found or already processed.');
    }
    header("Location: verify_cash.php"); exit();
}

if (isset($_GET['reject'])) {
    $pay_id = intval($_GET['reject']);
    $s = $conn->prepare("UPDATE payments SET status='failed' WHERE id=? AND status='pending'");
    $s->bind_param("i", $pay_id); $s->execute(); $s->close();
    setFlash('error', 'Payment rejected and marked as failed.');
    header("Location: verify_cash.php"); exit();
}

$pending = $conn->query(
    "SELECT p.*, u.name AS user_name, u.email, u.phone, u.user_code,
            pl.name AS plan_name, c.position, c.collection_date,
            c.payout_amount, c.next_payment_date, c.total_cycles_paid,
            pl.total_participants
     FROM payments p
     JOIN contributions c ON p.contribution_id = c.id
     JOIN users u ON c.user_id = u.id
     JOIN plans pl ON c.plan_id = pl.id
     WHERE p.status = 'pending'
     ORDER BY p.id ASC"
)->fetch_all(MYSQLI_ASSOC);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Verify Cash — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
  <h1>Verify Cash Payments</h1>
  <p><?= count($pending) > 0
      ? '<strong style="color:var(--gold)">' . count($pending) . ' payment(s) awaiting verification.</strong>'
      : 'No pending cash payments.' ?></p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><p><?= htmlspecialchars($flash['message']) ?></p></div>
<?php endif; ?>

<?php if (empty($pending)): ?>
  <div class="empty-state"><p>&#10003; No cash payments awaiting verification.</p></div>
<?php else: ?>
<div class="verify-list">
  <?php foreach ($pending as $p): ?>
  <div class="verify-card">
    <div class="verify-card__info">
      <div style="margin-bottom:.4rem;">
        <code class="user-code-badge"><?= htmlspecialchars($p['user_code'] ?? '—') ?></code>
        <strong style="margin-left:.5rem;"><?= htmlspecialchars($p['user_name']) ?></strong>
        <span style="color:var(--gray-text);font-size:.85rem;margin-left:.5rem;"><?= htmlspecialchars($p['email']) ?></span>
      </div>
      <div style="font-size:.875rem;color:var(--gray-text);margin-bottom:.5rem;">
        Phone: <strong style="color:var(--black)"><?= htmlspecialchars($p['phone']) ?></strong>
        &bull; Plan: <strong><?= htmlspecialchars($p['plan_name']) ?></strong>
        &bull; Position <?= $p['position'] ?>
        &bull; Cycle: <strong><?= intval($p['total_cycles_paid'] ?? 0) + 1 ?>/<?= $p['total_participants'] ?></strong>
        <?php if ($p['collection_date']): ?>
          &bull; Collects: <strong style="color:var(--gold)"><?= date('M j, Y', strtotime($p['collection_date'])) ?></strong>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <span style="font-size:1.3rem;color:var(--gold);font-weight:600;"><?= formatMoney($p['amount']) ?></span>
        <code class="ref-code"><?= htmlspecialchars($p['reference']) ?></code>
      </div>
    </div>
    <div class="verify-card__actions">
      <a href="verify_cash.php?approve=<?= $p['id'] ?>"
         class="btn btn-gold"
         onclick="return confirm('Approve ₦<?= number_format($p['amount'], 2) ?> cash from <?= addslashes(htmlspecialchars($p['user_name'])) ?>?\nThis will update their next payment date automatically.')">
        &#10003; Approve
      </a>
      <a href="verify_cash.php?reject=<?= $p['id'] ?>"
         class="btn btn-danger"
         onclick="return confirm('Reject this payment?')">
        &#10007; Reject
      </a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
</body>
</html>
