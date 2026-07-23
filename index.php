<?php
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

$total_users  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];
$total_paid   = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='paid'")->fetch_assoc()['t'];
$open_count   = count($plans);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title><?= SITE_NAME ?> — Rotational Savings</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css?v=20260523">
<style>
/* ============================================================
   MOBILE-FIRST HOMEPAGE STYLES
   ============================================================ */

/* NAV */
.home-navbar {
    background: rgba(26,26,26,.95);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid rgba(255,255,255,.08);
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 200;
}
.home-nav-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1.25rem;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.home-nav-brand {
    font-family: var(--font-head);
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: .12em;
    color: var(--white);
    text-decoration: none;
    flex-shrink: 0;
}
/* Hamburger */
.nav-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: .5rem;
    background: none;
    border: none;
}
.nav-toggle span {
    display: block;
    width: 24px;
    height: 2px;
    background: var(--white);
    border-radius: 2px;
    transition: var(--transition);
}
/* Desktop links */
.home-nav-links {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}
.home-nav-links a {
    color: rgba(255,255,255,.8);
    font-size: .82rem;
    font-weight: 500;
    letter-spacing: .05em;
    text-transform: uppercase;
    text-decoration: none;
    transition: var(--transition);
}
.home-nav-links a:hover { color: var(--gold); }
.home-nav-links .btn-nav-gold {
    background: var(--gold);
    color: var(--white) !important;
    padding: .45rem 1.1rem;
    border-radius: 6px;
    font-weight: 600;
}
.home-nav-links .btn-nav-outline {
    border: 1.5px solid rgba(255,255,255,.4);
    color: var(--white) !important;
    padding: .4rem 1rem;
    border-radius: 6px;
}

/* HERO */
.home-hero {
    min-height: 100vh;
    background: var(--black);
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 100px 1.25rem 3rem;
    position: relative;
    overflow: hidden;
}
.home-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, rgba(201,168,76,.07) 0%, transparent 65%);
    pointer-events: none;
}
.home-hero__inner {
    position: relative;
    z-index: 1;
    max-width: 680px;
    margin: 0 auto;
    width: 100%;
}
.home-hero__logo-text {
    font-family: var(--font-head);
    font-size: .9rem;
    font-weight: 500;
    letter-spacing: .35em;
    color: var(--gold);
    text-transform: uppercase;
    display: block;
    margin-bottom: 1.5rem;
}
.home-hero__logo-img {
    height: 60px;
    width: auto;
    display: block;
    margin: 0 auto 1.5rem;
}
.home-hero h1 {
    font-family: var(--font-head);
    font-size: clamp(2.4rem, 8vw, 4rem);
    font-weight: 500;
    color: var(--white);
    line-height: 1.15;
    margin-bottom: 1.25rem;
}
.home-hero p {
    font-size: clamp(.9rem, 3vw, 1.05rem);
    color: rgba(255,255,255,.65);
    max-width: 480px;
    margin: 0 auto 2rem;
    line-height: 1.8;
    font-weight: 300;
}
.home-hero__cta {
    display: flex;
    gap: .85rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 2.5rem;
}
.home-hero__cta .btn {
    padding: .85rem 1.75rem;
    font-size: .88rem;
    min-width: 150px;
}
.home-hero__stats {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    max-width: 420px;
    margin: 0 auto;
}
.home-hero__stat { display: flex; flex-direction: column; align-items: center; gap: .15rem; }
.home-hero__stat-num {
    font-family: var(--font-head);
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--gold);
    line-height: 1;
}
.home-hero__stat-label {
    font-size: .68rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.45);
}
.home-hero__stat-div {
    width: 1px;
    height: 30px;
    background: rgba(255,255,255,.15);
}

/* SECTIONS */
.home-section {
    padding: 4rem 1.25rem;
}
.home-section--gray { background: var(--off-white); }
.home-section-title {
    text-align: center;
    margin-bottom: 2.5rem;
}
.home-section-title h2 {
    font-size: clamp(1.7rem, 5vw, 2.2rem);
    margin-bottom: .5rem;
}
.home-section-title p { color: var(--gray-text); font-size: .95rem; }

/* HOW IT WORKS */
.how-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
    max-width: 900px;
    margin: 0 auto;
}
.how-card {
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 14px;
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}
.how-card__num {
    font-family: var(--font-head);
    font-size: 2.2rem;
    font-weight: 600;
    color: var(--gold-light);
    line-height: 1;
    flex-shrink: 0;
    width: 44px;
    text-align: center;
}
.how-card h3 { font-size: 1.05rem; margin-bottom: .4rem; }
.how-card p  { font-size: .875rem; color: var(--gray-text); line-height: 1.7; margin: 0; }

/* PLANS GRID */
.home-plans-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
    max-width: 1100px;
    margin: 0 auto;
}
.home-plan-card {
    background: var(--white);
    border: 1.5px solid var(--gray-light);
    border-radius: 16px;
    padding: 1.5rem;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: .85rem;
}
.home-plan-card:hover {
    border-color: var(--gold);
    box-shadow: 0 6px 28px rgba(201,168,76,.12);
    transform: translateY(-2px);
}
.home-plan-card__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: .5rem;
}
.home-plan-card__header h3 { font-size: 1.15rem; }
.home-plan-card__amount {
    font-family: var(--font-head);
    font-size: 1.9rem;
    font-weight: 600;
    color: var(--gold);
}
.home-plan-card__amount small {
    display: block;
    font-family: var(--font-body);
    font-size: .72rem;
    color: var(--gray-text);
    font-weight: 400;
}
.home-plan-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .6rem;
    background: var(--gray-light);
    border-radius: var(--radius);
    padding: .75rem .9rem;
}
.home-plan-info-item { display: flex; flex-direction: column; gap: .15rem; }
.home-plan-info-label {
    font-size: .67rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--gray-text);
}
.home-plan-info-value { font-size: .875rem; font-weight: 600; color: var(--black); }
.home-plan-info-value--gold { color: var(--gold); }

/* TRUST GRID */
.trust-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    max-width: 900px;
    margin: 0 auto;
}
.trust-card {
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 14px;
    padding: 1.25rem;
    transition: var(--transition);
}
.trust-card__icon { font-size: 1.6rem; display: block; margin-bottom: .6rem; }
.trust-card h3 { font-size: .95rem; margin-bottom: .4rem; }
.trust-card p  { font-size: .82rem; color: var(--gray-text); line-height: 1.6; margin: 0; }

/* FOOTER */
.home-footer {
    background: var(--black);
    color: rgba(255,255,255,.6);
    padding: 2.5rem 1.25rem 1.5rem;
}
.home-footer__inner {
    max-width: 1100px;
    margin: 0 auto;
}
.home-footer__top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,.1);
    margin-bottom: 1.25rem;
}
.home-footer__brand-name {
    font-family: var(--font-head);
    font-size: 1.4rem;
    color: var(--white);
    display: block;
    margin-bottom: .25rem;
}
.home-footer__brand p { font-size: .82rem; color: rgba(255,255,255,.4); margin: 0; }
.home-footer__links {
    display: flex;
    gap: 1.25rem;
    flex-wrap: wrap;
    align-items: center;
}
.home-footer__links a {
    color: rgba(255,255,255,.5);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    text-decoration: none;
    transition: var(--transition);
}
.home-footer__links a:hover { color: var(--gold); }
.home-footer__copy {
    text-align: center;
    font-size: .75rem;
    color: rgba(255,255,255,.3);
}

/* ============================================================
   MOBILE — max-width 480px
   ============================================================ */
@media (max-width: 480px) {
    .nav-toggle { display: flex; }
    .home-nav-links {
        display: none;
        position: fixed;
        top: 60px;
        left: 0; right: 0;
        background: rgba(26,26,26,.98);
        flex-direction: column;
        padding: 1.5rem 1.25rem 2rem;
        gap: 1rem;
        border-bottom: 1px solid rgba(255,255,255,.1);
        z-index: 199;
    }
    .home-nav-links.open { display: flex; }
    .home-nav-links a {
        font-size: .95rem;
        padding: .5rem 0;
        border-bottom: 1px solid rgba(255,255,255,.07);
        width: 100%;
    }
    .home-nav-links .btn-nav-gold,
    .home-nav-links .btn-nav-outline {
        text-align: center;
        width: 100%;
        padding: .75rem 1rem;
        border-bottom: none;
    }
    .home-hero { padding: 90px 1.1rem 3rem; }
    .home-hero h1 { font-size: 2.1rem; }
    .home-hero__cta { flex-direction: column; align-items: center; }
    .home-hero__cta .btn { width: 100%; max-width: 300px; }
    .home-hero__stats { gap: 1rem; padding: 1rem; }
    .home-hero__stat-div { display: none; }
    .trust-grid { grid-template-columns: 1fr; }
    .home-footer__top { flex-direction: column; }
    .home-footer__links { gap: .75rem; }
}

/* ============================================================
   TABLET — 481px to 768px
   ============================================================ */
@media (min-width: 481px) and (max-width: 768px) {
    .how-grid { grid-template-columns: 1fr; }
    .home-plans-grid { grid-template-columns: 1fr; }
    .trust-grid { grid-template-columns: 1fr 1fr; }
}

/* ============================================================
   DESKTOP — 769px+
   ============================================================ */
@media (min-width: 769px) {
    .how-grid { grid-template-columns: repeat(3, 1fr); }
    .how-card { flex-direction: column; }
    .how-card__num { width: auto; }
    .home-plans-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
    .trust-grid { grid-template-columns: repeat(4, 1fr); }
}
</style>
</head>
<body class="home-page">

<!-- ============================================================
     NAVBAR — mobile hamburger menu
     ============================================================ -->
<nav class="home-navbar">
  <div class="home-nav-inner">
    <a href="index.php" class="home-nav-brand"><?= SITE_NAME ?></a>

    <!-- Hamburger button (mobile only) -->
    <button class="nav-toggle" id="navToggle" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>

    <div class="home-nav-links" id="navLinks">
      <a href="#plans">Plans</a>
      <a href="#how">How It Works</a>
      <?php if (isLoggedIn()): ?>
        <a href="pages/dashboard.php" class="btn-nav-outline">My Dashboard</a>
      <?php else: ?>
        <a href="pages/login.php">Sign In</a>
        <a href="pages/register.php" class="btn-nav-gold">Join Now</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="home-hero">
  <div class="home-hero__inner">
    <img src="assets/images/logo.png" alt="<?= SITE_NAME ?>" class="home-hero__logo-img"
         onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
    <span class="home-hero__logo-text" style="display:none;"><?= SITE_NAME ?></span>

    <h1>Save Together.<br>Collect in Turns.</h1>
    <p>The modern Ajo/Esusu experience. Join a group, know your exact collection date, and receive your full payout when your turn comes.</p>

    <div class="home-hero__cta">
      <?php if (isLoggedIn()): ?>
        <a href="pages/join_plan.php" class="btn btn-gold">Browse Groups</a>
        <a href="pages/dashboard.php" class="btn btn-outline" style="border-color:rgba(255,255,255,.4);color:#fff;">My Dashboard</a>
      <?php else: ?>
        <a href="pages/register.php" class="btn btn-gold">Join a Group</a>
        <a href="pages/login.php" class="btn btn-outline" style="border-color:rgba(255,255,255,.4);color:#fff;">Sign In</a>
      <?php endif; ?>
    </div>

    <div class="home-hero__stats">
      <div class="home-hero__stat">
        <span class="home-hero__stat-num"><?= $total_users ?>+</span>
        <span class="home-hero__stat-label">Members</span>
      </div>
      <div class="home-hero__stat-div"></div>
      <div class="home-hero__stat">
        <span class="home-hero__stat-num"><?= formatMoney($total_paid) ?></span>
        <span class="home-hero__stat-label">Paid Out</span>
      </div>
      <div class="home-hero__stat-div"></div>
      <div class="home-hero__stat">
        <span class="home-hero__stat-num"><?= $open_count ?></span>
        <span class="home-hero__stat-label">Open Groups</span>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     HOW IT WORKS
     ============================================================ -->
<section class="home-section" id="how">
  <div class="home-section-title">
    <h2>How It Works</h2>
    <p>Simple, transparent, and fair for everyone.</p>
  </div>
  <div class="how-grid">
    <div class="how-card">
      <span class="how-card__num">01</span>
      <div>
        <h3>Join a Group</h3>
        <p>Pick a savings group. You are assigned a position. Your exact collection date is shown before you join.</p>
      </div>
    </div>
    <div class="how-card">
      <span class="how-card__num">02</span>
      <div>
        <h3>Everyone Contributes</h3>
        <p>Every member pays their contribution each cycle via Paystack or cash. The full pot builds together.</p>
      </div>
    </div>
    <div class="how-card">
      <span class="how-card__num">03</span>
      <div>
        <h3>Collect on Your Day</h3>
        <p>When your position comes up, you receive the full pot. The rotation continues until everyone has collected.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     OPEN PLANS
     ============================================================ -->
<section class="home-section home-section--gray" id="plans">
  <div class="home-section-title">
    <h2>Open Groups</h2>
    <p>Join before slots fill up — see your collection date before committing.</p>
  </div>

  <?php if (empty($plans)): ?>
    <p style="text-align:center;color:var(--gray-text);">No groups available right now. Check back soon.</p>
  <?php else: ?>
  <div class="home-plans-grid">
    <?php foreach ($plans as $p):
      $filled    = intval($p['slots_filled']);
      $total     = intval($p['total_participants']);
      $slots_left= $total - $filled;
      $payout    = calculatePayoutAmount($p['contribution_amount'], $total);
      $pct       = $total > 0 ? round(($filled / $total) * 100) : 0;
    ?>
    <div class="home-plan-card">
      <div class="home-plan-card__header">
        <h3><?= htmlspecialchars($p['name']) ?></h3>
        <span class="plan-badge"><?= formatFrequency($p['frequency_days']) ?></span>
      </div>
      <div class="home-plan-card__amount">
        <?= formatMoney($p['contribution_amount']) ?><small>per cycle</small>
      </div>
      <div class="home-plan-info">
        <div class="home-plan-info-item">
          <span class="home-plan-info-label">Group Size</span>
          <span class="home-plan-info-value"><?= $total ?> people</span>
        </div>
        <div class="home-plan-info-item">
          <span class="home-plan-info-label">You Receive</span>
          <span class="home-plan-info-value home-plan-info-value--gold"><?= formatMoney($payout) ?></span>
        </div>
        <div class="home-plan-info-item">
          <span class="home-plan-info-label">Slots Left</span>
          <span class="home-plan-info-value <?= $slots_left<=2?'text-warning':'' ?>">
            <?= $slots_left <= 0 ? '<span class="text-danger">Full</span>' : $slots_left.' open' ?>
          </span>
        </div>
        <div class="home-plan-info-item">
          <span class="home-plan-info-label">Frequency</span>
          <span class="home-plan-info-value"><?= formatFrequency($p['frequency_days']) ?></span>
        </div>
      </div>
      <div class="slot-progress">
        <div class="slot-progress__bar">
          <div class="slot-progress__fill" style="width:<?= $pct ?>%"></div>
        </div>
        <span class="slot-progress__label"><?= $filled ?>/<?= $total ?></span>
      </div>
      <a href="<?= isLoggedIn() ? 'pages/join_plan.php' : 'pages/register.php' ?>"
         class="btn btn-primary btn-full">
        <?= $slots_left <= 0 ? 'View Details' : 'Join This Group' ?>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ============================================================
     WHY SAVEWITHLUCIA
     ============================================================ -->
<section class="home-section">
  <div class="home-section-title">
    <h2>Why <?= SITE_NAME ?>?</h2>
  </div>
  <div class="trust-grid">
    <div class="trust-card">
      <span class="trust-card__icon">&#128197;</span>
      <h3>Know Your Date</h3>
      <p>The moment you join, you see exactly which day you will collect.</p>
    </div>
    <div class="trust-card">
      <span class="trust-card__icon">&#128274;</span>
      <h3>Secure Payments</h3>
      <p>Pay online via Paystack — Nigeria's most trusted payment gateway.</p>
    </div>
    <div class="trust-card">
      <span class="trust-card__icon">&#8635;</span>
      <h3>Fair Rotation</h3>
      <p>Positions auto-assigned. Dates calculated transparently for everyone.</p>
    </div>
    <div class="trust-card">
      <span class="trust-card__icon">&#128241;</span>
      <h3>Daily Reminders</h3>
      <p>Get email and SMS alerts before your payment is due.</p>
    </div>
  </div>
</section>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<footer class="home-footer">
  <div class="home-footer__inner">
    <div class="home-footer__top">
      <div class="home-footer__brand">
        <span class="home-footer__brand-name"><?= SITE_NAME ?></span>
        <p>Save together, collect in turns.</p>
      </div>
      <div class="home-footer__links">
        <a href="#plans">Plans</a>
        <a href="#how">How It Works</a>
        <a href="pages/register.php">Register</a>
        <a href="pages/login.php">Sign In</a>
        <a href="pages/terms.php">Terms</a>
        <a href="pages/privacy.php">Privacy</a>
      </div>
    </div>
    <div class="home-footer__copy">
      <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
    </div>
  </div>
</footer>

<script>
// Hamburger menu toggle
document.getElementById('navToggle').addEventListener('click', function() {
    var links = document.getElementById('navLinks');
    links.classList.toggle('open');
    // Animate hamburger
    var spans = this.querySelectorAll('span');
    if (links.classList.contains('open')) {
        spans[0].style.transform = 'rotate(45deg) translate(5px, 5px)';
        spans[1].style.opacity   = '0';
        spans[2].style.transform = 'rotate(-45deg) translate(5px, -5px)';
    } else {
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
    }
});

// Close menu when a link is clicked
document.querySelectorAll('.home-nav-links a').forEach(function(link) {
    link.addEventListener('click', function() {
        document.getElementById('navLinks').classList.remove('open');
        var spans = document.querySelectorAll('.nav-toggle span');
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
    });
});
</script>
</body>
</html>
