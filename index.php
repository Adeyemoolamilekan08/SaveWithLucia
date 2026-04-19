<?php
// ============================================================
// FILE: index.php
// INSTRUCTION: REPLACE your existing index.php with this
// ============================================================
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$plans = $conn->query(
    "SELECT p.*,
        (SELECT COUNT(*) FROM contributions c
         WHERE c.plan_id=p.id AND c.status!='removed') AS slots_filled
     FROM plans p
     WHERE p.is_active=1 AND p.plan_status IN ('open','active')
     ORDER BY p.contribution_amount ASC"
)->fetch_all(MYSQLI_ASSOC);

$total_users = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];
$total_paid  = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='paid'")->fetch_assoc()['t'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= SITE_NAME ?> — Rotational Savings</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="home-page">

<nav class="navbar home-navbar">
  <div class="nav-container">
    <a href="index.php" class="nav-brand"><?= SITE_NAME ?></a>
    <div class="nav-links">
      <a href="#plans">Plans</a>
      <a href="#how">How It Works</a>
      <?php if (isLoggedIn()): ?>
        <a href="pages/dashboard.php" class="btn btn-outline" style="padding:.5rem 1.2rem;">My Dashboard</a>
      <?php else: ?>
        <a href="pages/login.php">Sign In</a>
        <a href="pages/register.php" class="btn btn-primary" style="padding:.5rem 1.2rem;">Join Now</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="hero__content">
    <div class="hero__logo-wrap">
      <img src="assets/images/logo.png" alt="<?= SITE_NAME ?>" class="hero__logo-img"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
      <span class="hero__logo-text" style="display:none;"><?= SITE_NAME ?></span>
    </div>
    <h1 class="hero__headline">Save Together.<br>Collect in Turns.</h1>
    <p class="hero__sub">
      The modern Ajo/Esusu experience. Join a group, know your exact collection date,
      and receive your full payout when your turn comes.
    </p>
    <div class="hero__cta">
      <?php if (isLoggedIn()): ?>
        <a href="pages/join_plan.php" class="btn btn-gold hero__btn">Browse Groups</a>
        <a href="pages/dashboard.php" class="btn btn-outline hero__btn">My Dashboard</a>
      <?php else: ?>
        <a href="pages/register.php" class="btn btn-gold hero__btn">Join a Group</a>
        <a href="pages/login.php" class="btn btn-outline hero__btn">Sign In</a>
      <?php endif; ?>
    </div>
    <div class="hero__stats">
      <div class="hero__stat">
        <span class="hero__stat-num"><?= $total_users ?>+</span>
        <span class="hero__stat-label">Members</span>
      </div>
      <div class="hero__stat-divider"></div>
      <div class="hero__stat">
        <span class="hero__stat-num"><?= formatMoney($total_paid) ?></span>
        <span class="hero__stat-label">Paid Out</span>
      </div>
      <div class="hero__stat-divider"></div>
      <div class="hero__stat">
        <span class="hero__stat-num"><?= count($plans) ?></span>
        <span class="hero__stat-label">Open Groups</span>
      </div>
    </div>
  </div>
  <div class="hero__overlay"></div>
</section>

<section class="section" id="how">
  <div class="container">
    <div class="section-title"><h2>How It Works</h2><p>Simple, transparent, and fair for everyone.</p></div>
    <div class="steps-grid">
      <div class="step-card">
        <div class="step-card__num">01</div>
        <h3>Join a Group</h3>
        <p>Pick a savings group. You are assigned a position number automatically. Your exact collection date is shown before you join.</p>
      </div>
      <div class="step-card">
        <div class="step-card__num">02</div>
        <h3>Everyone Contributes</h3>
        <p>Every member pays their contribution each cycle via Paystack or cash. The full pot builds together.</p>
      </div>
      <div class="step-card">
        <div class="step-card__num">03</div>
        <h3>Collect on Your Day</h3>
        <p>When your position comes up, you receive the full pot from all members. The rotation continues until everyone has collected.</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--gray" id="plans">
  <div class="container">
    <div class="section-title"><h2>Open Groups</h2><p>Join before slots fill up — see your collection date before committing.</p></div>
    <?php if (empty($plans)): ?>
      <p style="text-align:center;color:var(--gray-text);">No groups available right now. Check back soon.</p>
    <?php else: ?>
    <div class="plans-grid">
      <?php foreach ($plans as $p):
        $slots_filled = intval($p['slots_filled']);
        $total        = intval($p['total_participants']);
        $slots_left   = $total - $slots_filled;
        $payout       = calculatePayoutAmount($p['contribution_amount'], $total);
        $pct          = $total > 0 ? round(($slots_filled / $total) * 100) : 0;
      ?>
      <div class="plan-card home-plan-card">
        <div class="plan-card__header">
          <h3><?= htmlspecialchars($p['name']) ?></h3>
          <span class="plan-badge"><?= formatFrequency($p['frequency_days']) ?></span>
        </div>
        <div class="plan-card__amount"><?= formatMoney($p['contribution_amount']) ?><small>per cycle</small></div>
        <div class="plan-info-grid">
          <div class="plan-info-item">
            <span class="plan-info-label">Group Size</span>
            <span class="plan-info-value"><?= $total ?> people</span>
          </div>
          <div class="plan-info-item">
            <span class="plan-info-label">You Receive</span>
            <span class="plan-info-value plan-info-value--gold"><?= formatMoney($payout) ?></span>
          </div>
          <div class="plan-info-item">
            <span class="plan-info-label">Slots Left</span>
            <span class="plan-info-value <?= $slots_left <= 2 ? 'text-warning' : '' ?>">
              <?= $slots_left <= 0 ? '<span class="text-danger">Full</span>' : $slots_left . ' open' ?>
            </span>
          </div>
          <div class="plan-info-item">
            <span class="plan-info-label">Frequency</span>
            <span class="plan-info-value"><?= formatFrequency($p['frequency_days']) ?></span>
          </div>
        </div>
        <div class="slot-progress">
          <div class="slot-progress__bar">
            <div class="slot-progress__fill" style="width:<?= $pct ?>%"></div>
          </div>
          <span class="slot-progress__label"><?= $slots_filled ?>/<?= $total ?> slots filled</span>
        </div>
        <a href="<?= isLoggedIn() ? 'pages/join_plan.php' : 'pages/register.php' ?>"
           class="btn btn-primary btn-full">
          <?= $slots_left <= 0 ? 'View Details' : 'Join This Group' ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-title"><h2>Why <?= SITE_NAME ?>?</h2></div>
    <div class="trust-grid">
      <div class="trust-card"><div class="trust-card__icon">&#128197;</div><h3>Know Your Date</h3><p>The moment you join, you see exactly which day you will collect. No surprises.</p></div>
      <div class="trust-card"><div class="trust-card__icon">&#128274;</div><h3>Secure Payments</h3><p>Pay online via Paystack — Nigeria's most trusted gateway. Cash payments are admin-verified.</p></div>
      <div class="trust-card"><div class="trust-card__icon">&#8635;</div><h3>Fair Rotation</h3><p>Positions are auto-assigned. Dates are calculated transparently for everyone in the group.</p></div>
      <div class="trust-card"><div class="trust-card__icon">&#128241;</div><h3>Daily Reminders</h3><p>Get email and SMS alerts before your payment is due and before your collection day arrives.</p></div>
    </div>
  </div>
</section>

<footer class="footer">
  <div class="container">
    <div class="footer__inner">
      <div class="footer__brand">
        <span class="brand-text" style="font-size:1.6rem;"><?= SITE_NAME ?></span>
        <p>Save together, collect in turns.</p>
      </div>
      <div class="footer__links">
        <a href="#plans">Plans</a>
        <a href="#how">How It Works</a>
        <a href="pages/register.php">Register</a>
        <a href="pages/login.php">Login</a>
      </div>
    </div>
    <div class="footer__copy"><p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p></div>
  </div>
</footer>
</body>
</html>
