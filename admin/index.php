<?php
// ============================================================
// FILE: admin/index.php
// INSTRUCTION: REPLACE your existing admin/index.php with this
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$total_users    = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];
$active_members = $conn->query("SELECT COUNT(*) AS c FROM contributions WHERE status='active'")->fetch_assoc()['c'];
$pending_cash   = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
$total_paid     = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='paid'")->fetch_assoc()['t'];

// Accurate: count only truly completed plans (all members collected)
$completed_plans = $conn->query(
    "SELECT COUNT(*) AS c FROM plans WHERE plan_status='completed'"
)->fetch_assoc()['c'];

// Accurate: total collected across all plans
$total_collected_members = $conn->query(
    "SELECT COUNT(*) AS c FROM contributions WHERE has_collected=1"
)->fetch_assoc()['c'];

$todays   = getTodaysCollectors($conn);
$upcoming = getUpcomingCollectors($conn,7);
$flash    = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><p><?= htmlspecialchars($flash['message']) ?></p></div>
<?php endif; ?>

<div class="page-header"><h1>Admin Dashboard</h1><p><?= date('l, F j, Y') ?></p></div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));">
  <div class="stat-card"><span class="stat-card__label">Total Members</span><span class="stat-card__value"><?= $total_users ?></span><span class="stat-card__sub">Registered</span></div>
  <div class="stat-card"><span class="stat-card__label">Active Slots</span><span class="stat-card__value"><?= $active_members ?></span><span class="stat-card__sub">Across all groups</span></div>
  <div class="stat-card"><span class="stat-card__label">Members Collected</span><span class="stat-card__value"><?= $total_collected_members ?></span><span class="stat-card__sub">Payouts done</span></div>
  <div class="stat-card"><span class="stat-card__label">Plans Completed</span><span class="stat-card__value"><?= $completed_plans ?></span><span class="stat-card__sub">All members paid</span></div>
  <div class="stat-card"><span class="stat-card__label">Total Contributions</span><span class="stat-card__value stat-card__value--gold"><?= formatMoney($total_paid) ?></span><span class="stat-card__sub">All confirmed</span></div>
  <div class="stat-card"><span class="stat-card__label">Pending Cash</span><span class="stat-card__value <?= $pending_cash>0?'text-warning':'' ?>"><?= $pending_cash ?></span><span class="stat-card__sub"><a href="verify_cash.php" style="color:var(--gold)">Verify now</a></span></div>
</div>

<div class="admin-quick-links">
  <a href="plans.php"       class="quick-link-card"><span class="quick-link-card__icon">&#9776;</span><span>Manage Plans</span></a>
  <a href="rotation.php"    class="quick-link-card"><span class="quick-link-card__icon">&#8635;</span><span>Rotation View</span></a>
  <a href="payout.php"      class="quick-link-card"><span class="quick-link-card__icon">&#127942;</span><span>Mark Payouts</span></a>
  <a href="users.php"       class="quick-link-card"><span class="quick-link-card__icon">&#9786;</span><span>Users</span></a>
  <a href="verify_cash.php" class="quick-link-card"><span class="quick-link-card__icon">&#10003;</span><span>Verify Cash</span></a>
  <a href="export.php"      class="quick-link-card"><span class="quick-link-card__icon">&#128190;</span><span>Export CSV</span></a>
</div>

<?php if (!empty($todays)): ?>
<div class="collection-today-banner" style="margin-bottom:2rem;">
  <div class="collection-today-banner__icon">&#127942;</div>
  <div>
    <strong><?= count($todays) ?> member(s) collecting TODAY!</strong>
    <p>Go to <a href="payout.php" style="color:var(--gold)">Payouts</a> to mark them as paid after handing over the money.</p>
  </div>
</div>
<?php endif; ?>

<section class="dashboard-section">
  <div class="section-header"><h2>Today's Collections</h2><a href="payout.php" class="section-link">Mark as paid</a></div>
  <?php if (empty($todays)): ?>
    <div class="empty-state"><p>No collections scheduled for today.</p></div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="data-table">
      <thead><tr><th>Member ID</th><th>Name</th><th>Plan</th><th>Position</th><th>Payout</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($todays as $t): ?>
        <tr>
          <td><code class="user-code-badge"><?= htmlspecialchars($t['user_code']??'—') ?></code></td>
          <td><strong><?= htmlspecialchars($t['user_name']) ?></strong><br><small><?= htmlspecialchars($t['phone']) ?></small></td>
          <td><?= htmlspecialchars($t['plan_name']) ?></td>
          <td>Position <?= $t['position'] ?></td>
          <td class="amount-gold"><?= formatMoney($t['payout_amount']) ?></td>
          <td><a href="payout.php?mark=<?= $t['id'] ?>" class="btn-action btn-action--edit" onclick="return confirm('Mark payout done for <?= addslashes(htmlspecialchars($t['user_name'])) ?>?')">Mark Paid</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="dashboard-section">
  <div class="section-header"><h2>Upcoming Collections (Next 7 Days)</h2></div>
  <?php if (empty($upcoming)): ?>
    <div class="empty-state"><p>No collections in the next 7 days.</p></div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="data-table">
      <thead><tr><th>Member ID</th><th>Name</th><th>Plan</th><th>Position</th><th>Collection Date</th><th>Payout</th></tr></thead>
      <tbody>
        <?php foreach ($upcoming as $u): ?>
        <tr>
          <td><code class="user-code-badge"><?= htmlspecialchars($u['user_code']??'—') ?></code></td>
          <td><?= htmlspecialchars($u['user_name']) ?></td>
          <td><?= htmlspecialchars($u['plan_name']) ?></td>
          <td>Position <?= $u['position'] ?></td>
          <td><?= date('M j, Y',strtotime($u['collection_date'])) ?></td>
          <td><?= formatMoney($u['payout_amount']) ?></td>
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
