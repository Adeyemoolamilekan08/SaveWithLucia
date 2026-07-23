<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

requireLogin();
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['name'];
$user_code = $_SESSION['user_code'] ?? '';

$contributions = getUserContributions($conn, $user_id);
$total_paid    = getUserTotalPaid($conn, $user_id);

// Unread notifications
$stmt = $conn->prepare(
    "SELECT * FROM notifications WHERE user_id=? AND is_read=0
     ORDER BY created_at DESC LIMIT 5"
);
$stmt->bind_param("i", $user_id); $stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
if (!empty($notifications)) {
    $upd = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $upd->bind_param("i", $user_id); $upd->execute(); $upd->close();
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
<style>
/* ---- Payment cycle status card ---- */
.payment-cycle-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    background: var(--off-white);
    border: 1px solid var(--gray-light);
    border-radius: 10px;
    padding: .9rem 1.1rem;
    margin-bottom: 1rem;
    font-size: .875rem;
}
.payment-cycle-card__item {
    display: flex;
    flex-direction: column;
    gap: .15rem;
}
.payment-cycle-card__label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--gray-text);
    font-weight: 500;
}
.payment-cycle-card__value {
    font-weight: 600;
    color: var(--black);
    font-size: .95rem;
}
.payment-cycle-card__value--due    { color: var(--error); }
.payment-cycle-card__value--soon   { color: #B7860B; }
.payment-cycle-card__value--ok     { color: var(--success); }
.payment-cycle-card__value--gold   { color: var(--gold); }
.payment-cycle-card__value--done   { color: var(--gray-text); }

/* ---- Plan completed state ---- */
.plan-all-done {
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #EDF7F1;
    border: 1px solid #A8D5BC;
    border-radius: var(--radius);
    padding: .85rem 1rem;
    font-size: .9rem;
    color: var(--success);
    font-weight: 600;
}
.collected-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    background: #EEF2FF;
    color: #4338CA;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
    padding: .2rem .65rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}
</style>
</head>
<body class="inner-page">

<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="nav-brand"><?= SITE_NAME ?></a>
    <!-- Hamburger button — shows on mobile -->
    <button class="nav-toggle" id="userNavToggle" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <div class="nav-links" id="userNavLinks">
      <a href="dashboard.php" class="active">Dashboard</a>
      <a href="join_plan.php">Join a Plan</a>
      <a href="../logout.php" style="color:var(--error);">Logout</a>
    </div>
  </div>
</nav>
<script>
(function(){
  var btn   = document.getElementById('userNavToggle');
  var links = document.getElementById('userNavLinks');
  if (!btn || !links) return;
  btn.addEventListener('click', function() {
    links.classList.toggle('open');
    var sp = btn.querySelectorAll('span');
    if (links.classList.contains('open')) {
      sp[0].style.transform = 'rotate(45deg) translate(5px,5px)';
      sp[1].style.opacity   = '0';
      sp[2].style.transform = 'rotate(-45deg) translate(5px,-5px)';
      document.body.style.overflow = 'hidden';
    } else {
      sp[0].style.transform = sp[2].style.transform = '';
      sp[1].style.opacity   = '';
      document.body.style.overflow = '';
    }
  });
  links.querySelectorAll('a').forEach(function(a) {
    a.addEventListener('click', function() {
      links.classList.remove('open');
      var sp = btn.querySelectorAll('span');
      sp[0].style.transform = sp[2].style.transform = '';
      sp[1].style.opacity   = '';
      document.body.style.overflow = '';
    });
  });
})();
</script>

<main class="main-content"><div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= $flash['message'] ?></p>
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
      <span class="notif-time"><?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></span>
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
    <span class="stat-card__label">Payouts Received</span>
    <span class="stat-card__value"><?= count(array_filter($contributions, fn($c) => $c['has_collected'])) ?></span>
    <span class="stat-card__sub">Groups collected</span>
  </div>
  <div class="stat-card">
    <span class="stat-card__label">Still to Collect</span>
    <span class="stat-card__value"><?= count(array_filter($contributions, fn($c) => !$c['has_collected'])) ?></span>
    <span class="stat-card__sub">Waiting</span>
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
      $rot_status       = getRotationStatus($c['collection_date'], $c['has_collected']);
      $days_left        = getDaysUntilCollection($c['collection_date']);
      $pay_count        = countMemberPayments($conn, $c['id']);
      $collected_count  = getPlanCollectedCount($conn, $c['plan_id']);
      $total_members    = intval($c['total_participants']);
      $remaining        = $total_members - $collected_count;
      $current_collector= getCurrentCollector($conn, $c['plan_id']);
      $col_members      = getPlanMembers($conn, $c['plan_id']);
      $is_today         = $c['collection_date'] === $today && !$c['has_collected'];
      $plan_end_date    = calculatePlanEndDate($c['plan_start_date'], $c['frequency_days'], $total_members);

      // ---- Payment cycle state for THIS member ----
      $has_pending      = memberHasPendingPayment($conn, $c['id']);
      $paid_this_cycle  = memberHasPaidThisCycle($conn, $c['id']);
      $plan_completed   = $c['plan_status'] === 'completed';
      $finished_paying  = memberHasFinishedPaying($conn, $c['id']);

      // Next payment date
      $next_pay_date    = $c['next_payment_date'] ?? null;

      // Payment due status
      $pay_overdue = false;
      $pay_due_today = false;
      $pay_due_soon  = false;
      if ($next_pay_date && !$finished_paying && !$plan_completed) {
          if ($next_pay_date < $today)     $pay_overdue   = true;
          elseif ($next_pay_date === $today) $pay_due_today = true;
          elseif ($next_pay_date <= date('Y-m-d', strtotime('+2 days'))) $pay_due_soon = true;
      }
    ?>

    <!-- TODAY IS YOUR TURN banner -->
    <?php if ($is_today): ?>
    <div class="collection-today-banner">
      <div class="collection-today-banner__icon">&#127942;</div>
      <div>
        <strong>TODAY IS YOUR COLLECTION DAY!</strong>
        <p>You are scheduled to receive <?= formatMoney($c['payout_amount']) ?> from <strong><?= htmlspecialchars($c['plan_name']) ?></strong>. Contact your admin now!</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- PAYMENT OVERDUE banner -->
    <?php if ($pay_overdue && !$has_pending): ?>
    <div class="reminder-card reminder-card--danger">
      <div class="reminder-icon">&#9888;</div>
      <div>
        <strong>Payment Overdue — <?= htmlspecialchars($c['plan_name']) ?></strong>
        <p>Your contribution of <?= formatMoney($c['contribution_amount']) ?> was due on <?= date('M j, Y', strtotime($next_pay_date)) ?>. Please pay as soon as possible.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="contribution-card rotation-card">

      <!-- Header -->
      <div class="contribution-card__top">
        <div>
          <h3>
            <?= htmlspecialchars($c['plan_name']) ?>
            <?php if ($c['has_collected']): ?>
              <span class="collected-badge">&#10003; Collected</span>
            <?php endif; ?>
          </h3>
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
              <div class="rotation-date__value"><?= date('D, M j, Y', strtotime($c['collection_date'])) ?></div>
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
              <div class="rotation-date__value" style="color:var(--gray-text);font-size:.95rem;">
                Waiting for admin to set start date
              </div>
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
        <?php $s_icons = ['waiting'=>'&#8987;','upcoming'=>'&#9200;','your_turn'=>'&#127942;','overdue'=>'&#9888;','completed'=>'&#10003;']; ?>
        <span class="rotation-status-icon"><?= $s_icons[$rot_status] ?? '&#8505;' ?></span>
        <?= getRotationStatusLabel($rot_status) ?>
      </div>

      <!-- ============================================================
           PAYMENT CYCLE CARD
           Shows next payment date, cycles paid, and plan end date
           This updates after every confirmed payment
           ============================================================ -->
      <?php if ($c['plan_status'] !== 'open'): ?>
      <div class="payment-cycle-card">

        <div class="payment-cycle-card__item">
          <span class="payment-cycle-card__label">Payments Made</span>
          <span class="payment-cycle-card__value payment-cycle-card__value--gold">
            <?= $pay_count ?> / <?= $total_members ?>
          </span>
        </div>

        <div class="payment-cycle-card__item">
          <span class="payment-cycle-card__label">
            <?= $plan_completed ? 'Plan Status' : 'Next Payment Due' ?>
          </span>
          <?php if ($plan_completed || $finished_paying): ?>
            <span class="payment-cycle-card__value payment-cycle-card__value--ok">
              &#10003; All payments complete
            </span>
          <?php elseif ($has_pending): ?>
            <span class="payment-cycle-card__value" style="color:#B7860B;">
              &#8987; Cash payment pending verification
            </span>
          <?php elseif ($paid_this_cycle): ?>
            <span class="payment-cycle-card__value payment-cycle-card__value--ok">
              &#10003; Paid this cycle
              <?php if ($next_pay_date): ?>
                — next on <?= date('M j, Y', strtotime($next_pay_date)) ?>
              <?php endif; ?>
            </span>
          <?php elseif ($next_pay_date): ?>
            <span class="payment-cycle-card__value
              <?= $pay_overdue ? 'payment-cycle-card__value--due' : ($pay_due_today ? 'payment-cycle-card__value--due' : ($pay_due_soon ? 'payment-cycle-card__value--soon' : '')) ?>">
              <?= $pay_overdue ? '&#9888; OVERDUE — ' : ($pay_due_today ? '&#9888; Due TODAY — ' : '') ?>
              <?= date('M j, Y', strtotime($next_pay_date)) ?>
            </span>
          <?php else: ?>
            <span class="payment-cycle-card__value" style="color:var(--gray-text);">
              Start date not set yet
            </span>
          <?php endif; ?>
        </div>

        <?php if ($plan_end_date && !$plan_completed): ?>
        <div class="payment-cycle-card__item">
          <span class="payment-cycle-card__label">Plan Ends</span>
          <span class="payment-cycle-card__value" style="font-size:.85rem;">
            <?= date('M j, Y', strtotime($plan_end_date)) ?>
          </span>
        </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <!-- Group progress -->
      <div class="group-progress">
        <div class="group-progress__header">
          <span>
            <strong><?= $collected_count ?></strong> of
            <strong><?= $total_members ?></strong> members have collected
            &nbsp;·&nbsp;
            <span style="color:var(--gray-text)"><?= $remaining ?> remaining</span>
          </span>
          <span><?= $total_members > 0 ? round(($collected_count / $total_members) * 100) : 0 ?>%</span>
        </div>
        <div class="progress-bar-track">
          <div class="progress-bar-fill <?= $collected_count >= $total_members && $total_members > 0 ? 'progress-bar-fill--complete' : '' ?>"
               style="width:<?= $total_members > 0 ? round(($collected_count / $total_members) * 100) : 0 ?>%">
          </div>
        </div>
        <?php if ($current_collector && $c['plan_status'] === 'active'): ?>
        <div class="current-collector-note">
          <?php if ($current_collector['user_id'] === $user_id): ?>
            <span style="color:var(--gold);font-weight:600;">&#127942; You are next to collect!</span>
          <?php else: ?>
            <span style="color:var(--gray-text);">
              Currently collecting: Position <?= $current_collector['position'] ?>
              <?php if ($current_collector['collection_date']): ?>
                on <?= date('M j, Y', strtotime($current_collector['collection_date'])) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($plan_completed && $collected_count >= $total_members): ?>
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
          <span class="meta-label">Payout Status</span>
          <span class="status-badge status-badge--<?= $c['has_collected'] ? 'completed' : 'active' ?>">
            <?= $c['has_collected'] ? '&#10003; Collected' : 'Waiting' ?>
          </span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Method</span>
          <span class="meta-value"><?= $c['payment_method'] === 'online' ? 'Online' : 'Cash' ?></span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Payments Made</span>
          <span class="meta-value"><?= $pay_count ?> payment(s)</span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Plan Status</span>
          <span class="status-badge status-badge--<?= $c['plan_status'] === 'active' ? 'active' : ($c['plan_status'] === 'completed' ? 'completed' : 'pending') ?>">
            <?= ucfirst($c['plan_status']) ?>
          </span>
        </div>
      </div>

      <!-- Rotation roster -->
      <div class="rotation-roster">
        <div class="rotation-roster__title">Group Rotation Schedule</div>
        <div class="rotation-roster__list">
          <?php foreach ($col_members as $m):
            $m_status = getRotationStatus($m['collection_date'], $m['has_collected']);
            $is_me    = $m['user_id'] === $user_id;
          ?>
          <div class="roster-row <?= $is_me ? 'roster-row--me' : '' ?> <?= $m_status === 'your_turn' ? 'roster-row--today' : '' ?>">
            <span class="roster-pos"><?= $m['position'] ?></span>
            <span class="roster-name">
              <?= $is_me ? '<strong>You</strong>' : htmlspecialchars($m['user_name']) ?>
            </span>
            <span class="roster-date">
              <?= $m['collection_date'] ? date('M j, Y', strtotime($m['collection_date'])) : 'TBD' ?>
            </span>
            <span class="roster-status">
              <?php if ($m['has_collected']): ?>
                <span class="status-badge status-badge--completed">Collected</span>
              <?php elseif ($m_status === 'your_turn'): ?>
                <span class="status-badge status-badge--today">Today!</span>
              <?php elseif ($m_status === 'upcoming'): ?>
                <span class="status-badge status-badge--upcoming">Soon</span>
              <?php else: ?>
                <span class="status-badge status-badge--pending">Waiting</span>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ============================================================
           ACTION BUTTONS
           RULES:
           1. Plan not started yet (open) → show pay button (first payment)
           2. Plan active, member not finished paying → show pay button
           3. Member has pending cash → show "pending verification"
           4. Member has paid this cycle → show "paid, next due date"
           5. Plan completed AND member finished paying → plan all done
           IMPORTANT: has_collected = 1 does NOT hide the pay button.
           Member must keep paying until the WHOLE plan is completed.
           ============================================================ -->
      <div class="contribution-card__actions" style="margin-top:1rem;">

        <?php if ($plan_completed && $finished_paying): ?>
          <!-- Plan is fully done — everyone has collected -->
          <div class="plan-all-done">
            &#127942; This group has completed — all members have collected their payout!
          </div>

        <?php elseif ($has_pending): ?>
          <!-- Cash payment submitted, waiting for admin -->
          <span class="pending-badge">
            &#8987; Cash payment submitted — waiting for admin verification
          </span>

        <?php elseif ($paid_this_cycle): ?>
          <!-- Already paid this cycle -->
          <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <span style="background:#EDF7F1;color:var(--success);border:1px solid #A8D5BC;border-radius:var(--radius);padding:.5rem 1rem;font-size:.875rem;font-weight:600;">
              &#10003; Paid this cycle
            </span>
            <?php if ($next_pay_date && !$finished_paying): ?>
              <span style="font-size:.85rem;color:var(--gray-text);">
                Next payment: <strong><?= date('M j, Y', strtotime($next_pay_date)) ?></strong>
              </span>
            <?php endif; ?>
            <?php if ($c['has_collected']): ?>
              <span class="collected-badge">&#10003; You have collected your payout</span>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <!-- NEEDS TO PAY — show the button -->
          <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <!-- Member chooses payment method each time -->
              <a href="../payments/paystack_init.php?cid=<?= $c['id'] ?>"
                 class="btn <?= $pay_overdue ? 'btn-danger' : 'btn-gold' ?>">
                <?= $pay_overdue ? '&#9888; Pay Online (Overdue)' : 'Pay Online' ?>
              </a>
              <a href="../payments/cash_payment.php?cid=<?= $c['id'] ?>"
                 class="btn btn-outline">
                <?= $pay_overdue ? '&#9888; Submit Cash' : 'Submit Cash' ?>
              </a>
              <?php if (!$pay_overdue): ?>
              <a href="../payments/bulk_payment.php?cid=<?= $c['id'] ?>"
                 class="btn btn-outline" style="font-size:.82rem;border-style:dashed;">
                Pay Multiple Cycles
              </a>
              <?php endif; ?>
            <?php if ($pay_overdue): ?>
              <span style="font-size:.82rem;color:var(--error);">
                Was due <?= date('M j, Y', strtotime($next_pay_date)) ?>
              </span>
            <?php elseif ($next_pay_date): ?>
              <span style="font-size:.82rem;color:var(--gray-text);">
                Due: <?= date('M j, Y', strtotime($next_pay_date)) ?>
              </span>
            <?php endif; ?>
            <?php if ($c['has_collected']): ?>
              <span class="collected-badge">&#10003; Payout collected</span>
            <?php endif; ?>
          </div>
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
