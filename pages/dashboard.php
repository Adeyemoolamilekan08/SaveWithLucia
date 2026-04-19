<?php
// ============================================================
// FILE: pages/dashboard.php
// INSTRUCTION: REPLACE your existing dashboard.php with this.
//
// KEY FIXES IN THIS FILE:
//   - Progress bar now shows accurate X/10 collected count
//   - "Completed" badge only shows when ALL members collected
//   - Current collector is shown correctly
//   - Uses getPlanCollectedCount() — always reads live from DB
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

requireLogin();
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['name'];
$user_code = $_SESSION['user_code'] ?? '';

$contributions = getUserContributions($conn,$user_id);
$total_paid    = getUserTotalPaid($conn,$user_id);

// Load unread notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i",$user_id); $stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
if (!empty($notifications)) {
    $upd = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $upd->bind_param("i",$user_id); $upd->execute(); $upd->close();
}

$flash = getFlash();
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Dashboard — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="inner-page">
<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="nav-brand"><?= SITE_NAME ?></a>
    <div class="nav-links">
      <a href="dashboard.php" class="active">Dashboard</a>
      <a href="join_plan.php">Join a Plan</a>
      <a href="../logout.php">Logout</a>
    </div>
  </div>
</nav>
<main class="main-content"><div class="container">

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= htmlspecialchars($flash['message']) ?></p>
  </div>
<?php endif; ?>

<!-- Notifications -->
<?php if (!empty($notifications)): ?>
<div class="notifications-wrap">
  <?php foreach ($notifications as $n):
    $icons = ['payment'=>'&#10003;','collection'=>'&#127942;','reminder'=>'&#8987;','info'=>'&#8505;','warning'=>'&#9888;'];
  ?>
  <div class="notif-card notif-card--<?= $n['type'] ?>">
    <div class="notif-card__icon"><?= $icons[$n['type']] ?? '&#8505;' ?></div>
    <div class="notif-card__body">
      <strong><?= htmlspecialchars($n['title']) ?></strong>
      <p><?= htmlspecialchars($n['message']) ?></p>
      <span class="notif-time"><?= date('M d, Y g:i A',strtotime($n['created_at'])) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Welcome -->
<div class="dashboard-welcome">
  <div>
    <h1>Welcome, <?= htmlspecialchars($user_name) ?>.</h1>
    <div style="display:flex;align-items:center;gap:.75rem;margin-top:.35rem;flex-wrap:wrap;">
      <span class="member-id-badge"><?= htmlspecialchars($user_code ?: 'N/A') ?></span>
      <span style="font-size:.85rem;color:var(--gray-text);">Your Member ID</span>
    </div>
  </div>
  <a href="join_plan.php" class="btn btn-gold">+ Join a Group</a>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <span class="stat-card__label">Groups Joined</span>
    <span class="stat-card__value"><?= count($contributions) ?></span>
    <span class="stat-card__sub">Active groups</span>
  </div>
  <div class="stat-card">
    <span class="stat-card__label">Total Contributed</span>
    <span class="stat-card__value stat-card__value--gold"><?= formatMoney($total_paid) ?></span>
    <span class="stat-card__sub">All time</span>
  </div>
  <div class="stat-card">
    <span class="stat-card__label">Already Collected</span>
    <span class="stat-card__value"><?= count(array_filter($contributions,fn($c)=>$c['has_collected'])) ?></span>
    <span class="stat-card__sub">Payouts received</span>
  </div>
  <div class="stat-card">
    <span class="stat-card__label">Still Waiting</span>
    <span class="stat-card__value"><?= count(array_filter($contributions,fn($c)=>!$c['has_collected'])) ?></span>
    <span class="stat-card__sub">To collect</span>
  </div>
</div>

<!-- My Groups -->
<section class="dashboard-section">
  <div class="section-header">
    <h2>My Contribution Groups</h2>
    <a href="join_plan.php" class="section-link">Join another</a>
  </div>

  <?php if (empty($contributions)): ?>
  <div class="empty-state">
    <p>You have not joined any group yet.</p>
    <a href="join_plan.php" class="btn btn-primary" style="margin-top:1rem;">Browse Plans</a>
  </div>
  <?php else: ?>
  <div class="contributions-list">
    <?php foreach ($contributions as $c):
      $rot_status = getRotationStatus($c['collection_date'],$c['has_collected']);
      $days_left  = getDaysUntilCollection($c['collection_date']);
      $pay_count  = countMemberPayments($conn,$c['id']);

      // FIXED: Always read live from database — never trust stale cached value
      $collected_count   = getPlanCollectedCount($conn,$c['plan_id']);
      $total_members     = intval($c['total_participants']);
      $remaining_members = $total_members - $collected_count;

      // Get who is currently collecting in this plan
      $current_collector = getCurrentCollector($conn,$c['plan_id']);

      $col_members = getPlanMembers($conn,$c['plan_id']);
      $is_today    = $c['collection_date'] === $today && !$c['has_collected'];
    ?>

    <!-- TODAY IS YOUR TURN banner -->
    <?php if ($is_today): ?>
    <div class="collection-today-banner">
      <div class="collection-today-banner__icon">&#127942;</div>
      <div>
        <strong>TODAY IS YOUR COLLECTION DAY!</strong>
        <p>You are scheduled to receive <?= formatMoney($c['payout_amount']) ?> from the <strong><?= htmlspecialchars($c['plan_name']) ?></strong> group. Contact your admin now!</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="contribution-card rotation-card">

      <!-- Header -->
      <div class="contribution-card__top">
        <div>
          <h3><?= htmlspecialchars($c['plan_name']) ?></h3>
          <span class="freq-badge"><?= formatFrequency($c['frequency_days']) ?></span>
        </div>
        <div class="contribution-card__amount">
          <?= formatMoney($c['contribution_amount']) ?><small>per cycle</small>
        </div>
      </div>

      <!-- ROTATION STATUS CARD -->
      <div class="rotation-status-card rotation-status-card--<?= $rot_status ?>">
        <div class="rotation-status-card__left">
          <div class="rotation-position">
            <span class="rotation-position__num"><?= $c['position'] ?></span>
            <span class="rotation-position__label">of <?= $total_members ?></span>
          </div>
          <div class="rotation-position__title">Your Position</div>
        </div>
        <div class="rotation-status-card__right">
          <div class="rotation-date">
            <?php if ($c['collection_date']): ?>
              <div class="rotation-date__label">Your Collection Day</div>
              <div class="rotation-date__value"><?= date('D, M j, Y',strtotime($c['collection_date'])) ?></div>
              <?php if (!$c['has_collected']): ?>
                <?php if ($days_left === 0): ?>
                  <span class="rotation-badge rotation-badge--today">TODAY!</span>
                <?php elseif ($days_left <= 3): ?>
                  <span class="rotation-badge rotation-badge--soon">In <?= $days_left ?> day(s)</span>
                <?php else: ?>
                  <span class="rotation-badge rotation-badge--waiting">In <?= $days_left ?> days</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="rotation-badge rotation-badge--done">&#10003; Collected</span>
              <?php endif; ?>
            <?php else: ?>
              <div class="rotation-date__label">Collection Date</div>
              <div class="rotation-date__value" style="color:var(--gray-text);font-size:.95rem;">Waiting for admin to set start date</div>
            <?php endif; ?>
          </div>
          <div class="rotation-payout">
            <div class="rotation-payout__label">You Will Receive</div>
            <div class="rotation-payout__amount"><?= formatMoney($c['payout_amount']) ?></div>
          </div>
        </div>
      </div>

      <!-- Status label -->
      <div class="rotation-status-label">
        <?php $s_icons=['waiting'=>'&#8987;','upcoming'=>'&#9200;','your_turn'=>'&#127942;','overdue'=>'&#9888;','completed'=>'&#10003;']; ?>
        <span class="rotation-status-icon"><?= $s_icons[$rot_status] ?? '&#8505;' ?></span>
        <?= getRotationStatusLabel($rot_status) ?>
      </div>

      <!-- ============================================================
           FIXED GROUP PROGRESS
           Shows accurate X/10 collected — ONLY shows "Completed"
           when every single member has collected, not just one.
           ============================================================ -->
      <div class="group-progress">
        <div class="group-progress__header">
          <span>
            <strong><?= $collected_count ?></strong> of
            <strong><?= $total_members ?></strong> members have collected
            &nbsp;·&nbsp;
            <span style="color:var(--gray-text)"><?= $remaining_members ?> remaining</span>
          </span>
          <span><?= $total_members > 0 ? round(($collected_count/$total_members)*100) : 0 ?>%</span>
        </div>
        <div class="progress-bar-track">
          <div class="progress-bar-fill <?= $collected_count>= $total_members && $total_members>0 ?'progress-bar-fill--complete':'' ?>"
               style="width:<?= $total_members>0?round(($collected_count/$total_members)*100):0 ?>%"></div>
        </div>

        <!-- Who is currently collecting -->
        <?php if ($current_collector && !$c['has_collected']): ?>
        <div class="current-collector-note">
          <?php if ($current_collector['user_id'] === $user_id): ?>
            <span style="color:var(--gold);font-weight:600;">&#127942; You are next to collect!</span>
          <?php else: ?>
            <span style="color:var(--gray-text);">
              Currently collecting: Position <?= $current_collector['position'] ?>
              <?php if ($current_collector['collection_date']): ?>
                on <?= date('M j, Y',strtotime($current_collector['collection_date'])) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- FIXED: "Completed" ONLY shows when ALL members have collected -->
        <?php if ($c['plan_status'] === 'completed' && $collected_count >= $total_members): ?>
        <div style="margin-top:.5rem;">
          <span class="status-badge status-badge--completed">
            &#10003; All <?= $total_members ?> members have collected — Plan Complete!
          </span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Meta info -->
      <div class="contribution-card__meta">
        <div class="meta-item">
          <span class="meta-label">My Status</span>
          <span class="status-badge status-badge--<?= $c['has_collected']?'completed':'active' ?>">
            <?= $c['has_collected'] ? 'Collected' : 'Active' ?>
          </span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Method</span>
          <span class="meta-value"><?= $c['payment_method']==='online'?'Online':'Cash' ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">My Payments Made</span>
          <span class="meta-value"><?= $pay_count ?> payment(s)</span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Plan Status</span>
          <span class="status-badge status-badge--<?= $c['plan_status']==='active'?'active':($c['plan_status']==='completed'?'completed':'pending') ?>">
            <?= ucfirst($c['plan_status']) ?>
          </span>
        </div>
      </div>

      <!-- Full rotation roster -->
      <div class="rotation-roster">
        <div class="rotation-roster__title">Group Rotation Schedule</div>
        <div class="rotation-roster__list">
          <?php foreach ($col_members as $m):
            $m_status = getRotationStatus($m['collection_date'],$m['has_collected']);
            $is_me    = $m['user_id'] === $user_id;
          ?>
          <div class="roster-row <?= $is_me?'roster-row--me':'' ?> <?= $m_status==='your_turn'?'roster-row--today':'' ?>">
            <span class="roster-pos"><?= $m['position'] ?></span>
            <span class="roster-name"><?= $is_me?'<strong>You</strong>':htmlspecialchars($m['user_name']) ?></span>
            <span class="roster-date"><?= $m['collection_date']?date('M j, Y',strtotime($m['collection_date'])):'TBD' ?></span>
            <span class="roster-status">
              <?php if ($m['has_collected']): ?>
                <span class="status-badge status-badge--completed">Collected</span>
              <?php elseif ($m_status==='your_turn'): ?>
                <span class="status-badge status-badge--today">Today!</span>
              <?php elseif ($m_status==='upcoming'): ?>
                <span class="status-badge status-badge--upcoming">Soon</span>
              <?php else: ?>
                <span class="status-badge status-badge--pending">Waiting</span>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Action button -->
      <div class="contribution-card__actions" style="margin-top:1rem;">
        <?php if (!$c['has_collected']): ?>
          <?php if ($c['payment_method']==='online'): ?>
            <a href="../payments/paystack_init.php?cid=<?= $c['id'] ?>" class="btn btn-gold">Make Contribution Payment</a>
          <?php else: ?>
            <a href="../payments/cash_payment.php?cid=<?= $c['id'] ?>" class="btn btn-outline">Submit Cash Payment</a>
          <?php endif; ?>
        <?php else: ?>
          <span class="plan-completed-tag">&#127942; You have collected your payout</span>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

</div></main>
</body>
</html>
