<?php
// ============================================================
// pages/terms.php — NEW FILE
// Public page — no login required
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Terms of Service — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<style>
.legal-wrap{max-width:760px;margin:0 auto;padding:3rem 1.25rem 4rem;}
.legal-wrap h1{font-family:'Cormorant Garamond',serif;font-size:2.2rem;margin-bottom:.3rem;}
.legal-updated{color:var(--gray-text);font-size:.85rem;margin-bottom:2rem;}
.legal-wrap h2{font-family:'Cormorant Garamond',serif;font-size:1.4rem;margin:2rem 0 .6rem;color:var(--black);}
.legal-wrap p,.legal-wrap li{font-size:.92rem;line-height:1.7;color:#3a3833;}
.legal-wrap ul{margin:.5rem 0 .5rem 1.3rem;}
.legal-back{display:inline-block;margin-bottom:1.5rem;font-size:.85rem;color:var(--gray-text);text-decoration:none;}
.legal-back:hover{color:var(--gold);}
</style>
</head>
<body>
<div class="legal-wrap">
  <a href="../index.php" class="legal-back">&larr; Back to <?= SITE_NAME ?></a>
  <h1>Terms of Service</h1>
  <p class="legal-updated">Last updated: <?= date('F Y') ?></p>

  <p>These Terms of Service ("Terms") govern your use of <?= SITE_NAME ?>, a rotational
  savings (Ajo/Esusu) platform. By creating an account, you agree to these Terms.</p>

  <h2>1. What This Platform Does</h2>
  <p><?= SITE_NAME ?> helps groups of members organize rotational savings plans, where
  each member contributes a fixed amount on a regular schedule, and members take turns
  collecting the pooled contributions ("payout") on a rotating basis.</p>

  <h2>2. Your Responsibilities</h2>
  <ul>
    <li>Make your contributions on time, in the amount agreed for your plan.</li>
    <li>Keep your login details confidential and notify us if you suspect unauthorized access.</li>
    <li>Provide accurate information when registering (name, email, phone number).</li>
  </ul>

  <h2>3. Payments</h2>
  <p>Contributions may be made online via Paystack or recorded in person as cash payments
  verified by an administrator. Online payments are processed by Paystack; we do not
  store your card details. Cash payments are only reflected in your account once an
  administrator confirms receipt.</p>

  <h2>4. Rotational Savings Risk</h2>
  <p>Rotational savings groups rely on every member contributing on schedule. While plan
  administrators track contributions and payout order, <?= SITE_NAME ?> cannot guarantee
  that every member will contribute on time. Please only join a plan with members and an
  amount you're comfortable with.</p>

  <h2>5. Account Suspension</h2>
  <p>We may suspend an account that repeatedly misses contributions, provides false
  information, or is used to interfere with a plan's operation.</p>

  <h2>6. Changes to These Terms</h2>
  <p>We may update these Terms from time to time. Continued use of the platform after a
  change means you accept the updated Terms.</p>

  <h2>7. Contact</h2>
  <p>Questions about these Terms can be sent to
  <a href="mailto:<?= htmlspecialchars(ADMIN_EMAIL) ?>"><?= htmlspecialchars(ADMIN_EMAIL) ?></a>.</p>
</div>
</body>
</html>
