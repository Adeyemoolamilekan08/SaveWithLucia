<?php
// ============================================================
// pages/privacy.php — NEW FILE
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
<title>Privacy Policy — <?= SITE_NAME ?></title>
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
  <h1>Privacy Policy</h1>
  <p class="legal-updated">Last updated: <?= date('F Y') ?></p>

  <p>This Privacy Policy explains what information <?= SITE_NAME ?> collects and how it's used.</p>

  <h2>1. Information We Collect</h2>
  <ul>
    <li><strong>Account details:</strong> name, email address, phone number, and password (stored securely hashed, never in plain text).</li>
    <li><strong>Savings activity:</strong> which plans you've joined, your contribution history, and payout records.</li>
    <li><strong>Payment references:</strong> transaction references for online payments. Card details themselves are handled directly by Paystack — we never see or store them.</li>
  </ul>

  <h2>2. How We Use Your Information</h2>
  <ul>
    <li>To operate your savings plan — tracking contributions, calculating payout order, and sending reminders.</li>
    <li>To send you email (and, where enabled, SMS) notifications about payments, payouts, and account activity.</li>
    <li>To verify your identity when you log in or reset your password.</li>
  </ul>

  <h2>3. Who Can See Your Information</h2>
  <p>Plan administrators can see your contribution and payout status in order to manage
  the savings plan. We do not sell or share your personal information with third parties
  for marketing purposes.</p>

  <h2>4. Data Security</h2>
  <p>Passwords are hashed and never stored in readable form. We use session security
  measures and access controls to limit who can view administrative data. No system is
  perfectly secure, but we take reasonable steps to protect your information.</p>

  <h2>5. Data Retention</h2>
  <p>We retain your account and contribution records for as long as your account is
  active, and as needed to keep accurate financial records for the plans you've
  participated in.</p>

  <h2>6. Your Choices</h2>
  <p>You can request a copy of your data or ask us to close your account by contacting
  us using the details below. Some records may need to be retained for a period even
  after account closure, to preserve the integrity of shared savings plans.</p>

  <h2>7. Contact</h2>
  <p>Questions about this policy can be sent to
  <a href="mailto:<?= htmlspecialchars(ADMIN_EMAIL) ?>"><?= htmlspecialchars(ADMIN_EMAIL) ?></a>.</p>
</div>
</body>
</html>
