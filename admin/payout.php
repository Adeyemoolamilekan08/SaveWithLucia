<?php
// ============================================================
// FILE: admin/payout.php — REPLACE existing
// Syncs payout_schedule when marking a payout complete
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
require_once '../includes/security.php';
requireAdmin();

// ============================================================
// MARK A PAYOUT AS COMPLETED
// ============================================================
if (isset($_GET['mark'])) {
    $cid = intval($_GET['mark']);

    $stmt = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.user_code, u.phone,
                p.name AS plan_name, p.total_participants
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ?"
    );
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $contrib = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$contrib) {
        setFlash('error', 'Contribution not found.');
        header("Location: payout.php"); exit();
    }

    if ($contrib['has_collected']) {
        setFlash('error', htmlspecialchars($contrib['user_name']) . ' has already been marked as collected.');
        header("Location: payout.php"); exit();
    }

    // Mark this ONE member as collected
    $result = markUserCollected($conn, $cid);

    if (!$result['success']) {
        setFlash('error', $result['error']);
        header("Location: payout.php"); exit();
    }

    // Sync payout_schedule row to completed
    $ps = $conn->prepare(
        "UPDATE payout_schedule
         SET status = 'completed', completed_at = NOW()
         WHERE plan_id = ? AND user_id = ?"
    );
    $ps->bind_param("ii", $contrib['plan_id'], $contrib['user_id']);
    $ps->execute();
    $ps->close();

    // Send payout completed email + SMS
    sendPayoutCompletedEmail(
        $conn,
        $contrib['user_id'],
        $contrib['email'],
        $contrib['user_name'],
        $contrib['user_code'] ?? '',
        $contrib['plan_name'],
        $contrib['payout_amount'],
        $contrib['position']
    );

    logAudit(
        $conn, $_SESSION['user_id'], 'payout_marked_complete',
        "Marked payout complete for {$contrib['user_name']} ({$contrib['user_code']}) — " .
        formatMoney($contrib['payout_amount']) . " on plan '{$contrib['plan_name']}' (position {$contrib['position']})"
    );

    $progress = $result['collected_count'] . ' of ' . $result['total'] . ' members have now collected.';

    if ($result['is_completed']) {
        $msg = 'Payout marked for ' . htmlspecialchars($contrib['user_name']) .
               '. ' . $progress . ' THE PLAN IS NOW COMPLETE — all members have collected!';
    } else {
        $next = $result['next_position'] ? ' Next: Position ' . $result['next_position'] . '.' : '';
        $msg  = 'Payout marked for ' . htmlspecialchars($contrib['user_name']) .
                '. ' . $progress . $next;
    }

    setFlash('success', $msg);
    header("Location: payout.php"); exit();
}

// ============================================================
// LOAD PAGE DATA
// ============================================================
$today = date('Y-m-d');

// All members who have NOT yet collected
$pending = $conn->query(
    "SELECT c.id AS cid, c.position, c.collection_date,
            c.payout_amount, c.next_payment_date, c.total_cycles_paid,
            u.name AS user_name, u.email, u.phone, u.user_code,
            p.name AS plan_name, p.id AS plan_id,
            p.frequency_days, p.total_participants,
            (SELECT COUNT(*) FROM contributions cc
             WHERE cc.plan_id = p.id AND cc.has_collected = 1
               AND cc.status != 'removed') AS collected_so_far
     FROM contributions c
     JOIN users u ON c.user_id = u.id
     JOIN plans p ON c.plan_id = p.id
     WHERE c.has_collected = 0 AND c.status = 'active'
     ORDER BY c.collection_date ASC, p.id, c.position"
)->fetch_all(MYSQLI_ASSOC);

// Completed payouts
$done = $conn->query(
    "SELECT c.id, c.position, c.collection_date, c.payout_amount, c.collected_at,
            u.name AS user_name, u.user_code,
            p.name AS plan_name, p.total_participants,
            (SELECT COUNT(*) FROM contributions cc
             WHERE cc.plan_id = p.id AND cc.has_collected = 1) AS plan_collected_total
     FROM contributions c
     JOIN users u ON c.user_id = u.id
     JOIN plans p ON c.plan_id = p.id
     WHERE c.has_collected = 1
     ORDER BY c.collected_at DESC LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payouts — <?= SITE_NAME ?> Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Payout Management</h1>
    <p>Mark members as paid when they have collected their rotation payout.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= htmlspecialchars($flash['message']) ?></p>
</div>
<?php endif; ?>

<div class="admin-info-box" style="margin-bottom:1.5rem;">
    <strong>&#128161; Important:</strong>
    Clicking "Mark Collected" marks ONLY that one member as paid.
    The plan stays active and all other members still need to contribute
    until the LAST person collects. The plan completes when everyone has collected.
</div>

<!-- Pending payouts -->
<section class="dashboard-section">
  <div class="section-header">
    <h2>Pending Payouts (<?= count($pending) ?>)</h2>
    <span style="font-size:.82rem;color:var(--gray-text);">Members waiting to collect</span>
  </div>

  <?php if (empty($pending)): ?>
    <div class="empty-state"><p>All payouts are complete!</p></div>
  <?php else: ?>
  <div class="verify-list">
    <?php foreach ($pending as $p):
      $is_today   = $p['collection_date'] === $today;
      $is_overdue = $p['collection_date'] && $p['collection_date'] < $today;
      $days_left  = getDaysUntilCollection($p['collection_date']);
      $pct        = $p['total_participants'] > 0
          ? round(($p['collected_so_far'] / $p['total_participants']) * 100) : 0;
    ?>
    <div class="verify-card <?= $is_today ? 'verify-card--today' : '' ?>">
      <div class="verify-card__info">

        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.4rem;">
          <div class="position-circle <?= $is_today ? 'position-circle--today' : '' ?>"><?= $p['position'] ?></div>
          <code class="user-code-badge"><?= htmlspecialchars($p['user_code'] ?? '—') ?></code>
          <strong><?= htmlspecialchars($p['user_name']) ?></strong>
          <span style="color:var(--gray-text);font-size:.85rem;"><?= htmlspecialchars($p['email']) ?></span>
        </div>

        <div style="font-size:.875rem;color:var(--gray-text);margin-bottom:.5rem;">
          Phone: <strong style="color:var(--black)"><?= htmlspecialchars($p['phone']) ?></strong>
          &bull; Plan: <strong style="color:var(--black)"><?= htmlspecialchars($p['plan_name']) ?></strong>
          &bull; <?= formatFrequency($p['frequency_days']) ?>
        </div>

        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem;">
          <span style="font-size:1.4rem;color:var(--gold);font-weight:600;">
            <?= formatMoney($p['payout_amount']) ?>
          </span>
          <?php if ($p['collection_date']): ?>
            <span style="font-size:.875rem;">
              Collection: <strong><?= date('M j, Y', strtotime($p['collection_date'])) ?></strong>
              <?php if ($is_today): ?>
                <span class="rotation-badge rotation-badge--today" style="margin-left:6px;">TODAY!</span>
              <?php elseif ($is_overdue): ?>
                <span class="rotation-badge rotation-badge--soon">Overdue</span>
              <?php else: ?>
                <span style="color:var(--gray-text);">(in <?= $days_left ?> day(s))</span>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </div>

        <!-- Plan progress bar -->
        <div style="font-size:.8rem;color:var(--gray-text);">
          Plan progress: <strong><?= $p['collected_so_far'] ?>/<?= $p['total_participants'] ?></strong>
          &nbsp;
          <div class="progress-bar-track" style="display:inline-block;width:120px;height:6px;vertical-align:middle;">
            <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
          </div>
        </div>

      </div>
      <div class="verify-card__actions">
        <a href="payout.php?mark=<?= $p['cid'] ?>"
           class="btn btn-gold"
           onclick="return confirm('Mark ₦<?= number_format($p['payout_amount'], 2) ?> payout as COLLECTED for <?= addslashes(htmlspecialchars($p['user_name'])) ?>?\n\nThis marks ONLY this member. The plan continues until ALL members collect.\n\nA confirmation email + SMS will be sent.')">
          &#10003; Mark Collected
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- Completed payouts -->
<section class="dashboard-section">
  <div class="section-header"><h2>Completed Payouts</h2></div>
  <?php if (empty($done)): ?>
    <div class="empty-state"><p>No payouts completed yet.</p></div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Position</th><th>Member ID</th><th>Name</th>
          <th>Plan</th><th>Amount</th><th>Collection Date</th>
          <th>Marked Done</th><th>Plan Progress</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($done as $d): ?>
        <tr>
          <td><div class="position-circle position-circle--done"><?= $d['position'] ?></div></td>
          <td><code class="user-code-badge"><?= htmlspecialchars($d['user_code'] ?? '—') ?></code></td>
          <td><?= htmlspecialchars($d['user_name']) ?></td>
          <td><?= htmlspecialchars($d['plan_name']) ?></td>
          <td><?= formatMoney($d['payout_amount']) ?></td>
          <td><?= $d['collection_date'] ? date('M j, Y', strtotime($d['collection_date'])) : '—' ?></td>
          <td><?= $d['collected_at'] ? date('M j, Y g:i A', strtotime($d['collected_at'])) : '—' ?></td>
          <td>
            <span style="font-size:.8rem;"><?= $d['plan_collected_total'] ?>/<?= $d['total_participants'] ?></span>
            <?php if ($d['plan_collected_total'] >= $d['total_participants']): ?>
              <span class="status-badge status-badge--completed" style="margin-left:4px;">Plan Complete</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

</div></main>
</body>
</html>
