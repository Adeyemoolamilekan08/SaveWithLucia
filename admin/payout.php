<?php
// ============================================================
// FILE: admin/payout.php
// INSTRUCTION: REPLACE your existing payout.php with this.
//
// THIS IS THE MAIN FIX FILE.
// The old code marked the entire plan as completed when
// just one person collected. This version:
//   - ONLY marks that one user as collected
//   - Counts how many have collected AFTER the update
//   - ONLY marks the plan as completed when ALL members collected
//   - Uses markUserCollected() from functions.php (the fixed function)
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireAdmin();

// ============================================================
// MARK A PAYOUT AS COMPLETED
// This is the fixed logic — reads, marks, recounts, then decides
// ============================================================
if (isset($_GET['mark'])) {
    $cid = intval($_GET['mark']);

    // Load full member + plan details
    $stmt = $conn->prepare(
        "SELECT c.*, u.name AS user_name, u.email, u.user_code, u.phone,
                p.name AS plan_name, p.total_participants
         FROM contributions c
         JOIN users u ON c.user_id=u.id
         JOIN plans p ON c.plan_id=p.id
         WHERE c.id=?"
    );
    $stmt->bind_param("i",$cid); $stmt->execute();
    $contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$contrib) {
        setFlash('error','Contribution not found.');
        header("Location: payout.php"); exit();
    }

    if ($contrib['has_collected']) {
        setFlash('error', htmlspecialchars($contrib['user_name']).' has already been marked as collected.');
        header("Location: payout.php"); exit();
    }

    // ---- CALL THE FIXED FUNCTION ----
    // markUserCollected() in functions.php:
    //   1. Marks only this one user as has_collected=1
    //   2. Counts collected AFTER update (accurate)
    //   3. Only sets plan_status='completed' when count >= total_participants
    $result = markUserCollected($conn, $cid);

    if (!$result['success']) {
        setFlash('error', $result['error']);
        header("Location: payout.php"); exit();
    }

    // Send payout completed email + SMS to the member
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

    // Build success message with accurate progress count
    $progress = $result['collected_count'].' of '.$result['total'].' members have now collected.';

    if ($result['is_completed']) {
        // This was the LAST person — plan is now truly complete
        $msg = 'Payout marked for '.htmlspecialchars($contrib['user_name']).
               '. '.$progress.' THE PLAN IS NOW COMPLETE! All members have collected.';
    } else {
        // Plan continues — more people still waiting
        $next_txt = $result['next_position'] ? ' Next to collect: Position '.$result['next_position'].'.' : '';
        $msg = 'Payout marked for '.htmlspecialchars($contrib['user_name']).
               '. '.$progress.$next_txt;
    }

    setFlash('success', $msg);
    header("Location: payout.php"); exit();
}

// ============================================================
// LOAD PAGE DATA
// ============================================================
$today = date('Y-m-d');

// All members who have NOT yet collected (pending payouts)
$pending = $conn->query(
    "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
            u.name AS user_name, u.email, u.phone, u.user_code,
            p.name AS plan_name, p.id AS plan_id, p.frequency_days,
            p.total_participants,
            (SELECT COUNT(*) FROM contributions cc WHERE cc.plan_id=p.id AND cc.has_collected=1 AND cc.status!='removed') AS collected_so_far
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.has_collected=0 AND c.status='active'
     ORDER BY c.collection_date ASC, p.id, c.position"
)->fetch_all(MYSQLI_ASSOC);

// Completed payouts history
$done = $conn->query(
    "SELECT c.id, c.position, c.collection_date, c.payout_amount, c.collected_at,
            u.name AS user_name, u.user_code,
            p.name AS plan_name, p.total_participants,
            (SELECT COUNT(*) FROM contributions cc WHERE cc.plan_id=p.id AND cc.has_collected=1) AS plan_collected_total
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.has_collected=1
     ORDER BY c.collected_at DESC LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payouts — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
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

<!-- HOW THE LOGIC WORKS — visible reminder for admin -->
<div class="admin-info-box">
  <strong>&#128161; How this works:</strong>
  Clicking "Mark Collected" marks ONLY that one member as paid.
  The plan stays active until ALL members have collected.
  The plan is only marked "Completed" when the last member collects.
</div>

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
      $progress_pct = $p['total_participants'] > 0
        ? round(($p['collected_so_far']/$p['total_participants'])*100) : 0;
    ?>
    <div class="verify-card <?= $is_today?'verify-card--today':'' ?>">
      <div class="verify-card__info">

        <!-- Member identity -->
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.4rem;">
          <div class="position-circle <?= $is_today?'position-circle--today':'' ?>"><?= $p['position'] ?></div>
          <code class="user-code-badge"><?= htmlspecialchars($p['user_code']??'—') ?></code>
          <strong><?= htmlspecialchars($p['user_name']) ?></strong>
          <span style="color:var(--gray-text);font-size:.85rem;"><?= htmlspecialchars($p['email']) ?></span>
        </div>

        <!-- Plan + collection details -->
        <div style="font-size:.875rem;color:var(--gray-text);margin-bottom:.5rem;">
          Phone: <strong style="color:var(--black)"><?= htmlspecialchars($p['phone']) ?></strong>
          &bull; Plan: <strong style="color:var(--black)"><?= htmlspecialchars($p['plan_name']) ?></strong>
          &bull; <?= formatFrequency($p['frequency_days']) ?>
        </div>

        <!-- Payout amount + date -->
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem;">
          <span style="font-size:1.4rem;color:var(--gold);font-weight:600;"><?= formatMoney($p['payout_amount']) ?></span>
          <?php if ($p['collection_date']): ?>
            <span style="font-size:.875rem;">
              Collection: <strong><?= date('M j, Y',strtotime($p['collection_date'])) ?></strong>
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

        <!-- PLAN PROGRESS — shows X/10 collected so far -->
        <div style="font-size:.8rem;color:var(--gray-text);margin-top:.25rem;">
          Plan progress: <strong><?= $p['collected_so_far'] ?>/<?= $p['total_participants'] ?></strong> members collected
          &nbsp;
          <div class="progress-bar-track" style="display:inline-block;width:100px;height:6px;vertical-align:middle;">
            <div class="progress-bar-fill" style="width:<?= $progress_pct ?>%"></div>
          </div>
        </div>

      </div>
      <div class="verify-card__actions">
        <a href="payout.php?mark=<?= $p['cid'] ?>"
           class="btn btn-gold"
           onclick="return confirm('Mark ₦<?= number_format($p['payout_amount'],2) ?> payout as COLLECTED for <?= addslashes(htmlspecialchars($p['user_name'])) ?>?\n\nThis marks ONLY this member — the plan stays active until all members collect.\n\nA confirmation email will be sent to the member.')">
          &#10003; Mark Collected
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="dashboard-section">
  <div class="section-header"><h2>Completed Payouts</h2></div>
  <?php if (empty($done)): ?>
    <div class="empty-state"><p>No payouts completed yet.</p></div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Position</th><th>Member ID</th><th>Name</th><th>Plan</th><th>Amount</th><th>Collection Date</th><th>Marked Done</th><th>Plan Progress</th></tr>
      </thead>
      <tbody>
        <?php foreach ($done as $d): ?>
        <tr>
          <td><div class="position-circle position-circle--done"><?= $d['position'] ?></div></td>
          <td><code class="user-code-badge"><?= htmlspecialchars($d['user_code']??'—') ?></code></td>
          <td><?= htmlspecialchars($d['user_name']) ?></td>
          <td><?= htmlspecialchars($d['plan_name']) ?></td>
          <td><?= formatMoney($d['payout_amount']) ?></td>
          <td><?= $d['collection_date']?date('M j, Y',strtotime($d['collection_date'])):'—' ?></td>
          <td><?= $d['collected_at']?date('M j, Y g:i A',strtotime($d['collected_at'])):'—' ?></td>
          <td>
            <span style="font-size:.8rem;"><?= $d['plan_collected_total'] ?>/<?= $d['total_participants'] ?></span>
            <?php if ($d['plan_collected_total'] >= $d['total_participants']): ?>
              <span class="status-badge status-badge--completed">Plan Complete</span>
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
