<?php
// ============================================================
// FILE: payments/paystack_init.php
// INSTRUCTION: NEW FILE — copy into /swl/payments/paystack_init.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$cid     = intval($_GET['cid'] ?? 0);
if ($cid <= 0) { setFlash('error','Invalid request.'); header("Location: ".SITE_URL."/pages/dashboard.php"); exit(); }

$stmt = $conn->prepare("SELECT c.*,p.name AS plan_name,p.contribution_amount,p.frequency_days FROM contributions c JOIN plans p ON c.plan_id=p.id WHERE c.id=? AND c.user_id=?");
$stmt->bind_param("ii",$cid,$user_id); $stmt->execute();
$contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$contrib) { setFlash('error','Not found.'); header("Location: ".SITE_URL."/pages/dashboard.php"); exit(); }

$stmt = $conn->prepare("SELECT email,name,user_code FROM users WHERE id=?");
$stmt->bind_param("i",$user_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

$ref         = generatePaymentReference($conn);
$amount_kobo = $contrib['contribution_amount'] * 100;
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Make Payment — <?= SITE_NAME ?></title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="auth-page"><div class="auth-container" style="max-width:500px;">
<div class="auth-logo"><a href="../index.php"><img src="../assets/images/logo.png" alt="<?= SITE_NAME ?>" class="site-logo" onerror="this.style.display='none';this.nextElementSibling.style.display='block';"><span class="brand-text" style="display:none;"><?= SITE_NAME ?></span></a><p class="brand-tagline">Contribution Payment</p></div>
<div class="auth-card">
<h2>Make Your Contribution</h2>
<p class="auth-subtitle">Member ID: <strong style="color:var(--gold)"><?= htmlspecialchars($user['user_code']??'') ?></strong></p>
<div class="payment-summary">
  <div class="payment-summary__row"><span>Group</span><strong><?= htmlspecialchars($contrib['plan_name']) ?></strong></div>
  <div class="payment-summary__row"><span>Your Position</span><strong>Position <?= $contrib['position'] ?></strong></div>
  <div class="payment-summary__row"><span>Collection Day</span><strong style="color:var(--gold)"><?= $contrib['collection_date']?date('M j, Y',strtotime($contrib['collection_date'])):'TBD' ?></strong></div>
  <div class="payment-summary__row"><span>Amount</span><strong class="amount-gold"><?= formatMoney($contrib['contribution_amount']) ?></strong></div>
  <div class="payment-summary__row"><span>Reference</span><strong style="font-family:monospace;font-size:.85rem"><?= $ref ?></strong></div>
</div>
<button onclick="payWithPaystack()" class="btn btn-primary btn-full" id="pay-btn">Pay <?= formatMoney($contrib['contribution_amount']) ?></button>
<p style="text-align:center;margin-top:1rem;"><a href="../pages/dashboard.php" style="font-size:.85rem;color:var(--gray-text);">Cancel</a></p>
</div></div>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
function payWithPaystack() {
  document.getElementById('pay-btn').disabled=true;
  document.getElementById('pay-btn').textContent='Processing...';
  var handler=PaystackPop.setup({
    key:'<?= PAYSTACK_PUBLIC_KEY ?>',email:'<?= htmlspecialchars($user['email']) ?>',
    amount:<?= $amount_kobo ?>,currency:'NGN',ref:'<?= $ref ?>',
    metadata:{custom_fields:[
      {display_name:'Member ID',variable_name:'user_code',value:'<?= htmlspecialchars($user['user_code']??'') ?>'},
      {display_name:'Group',variable_name:'plan_name',value:'<?= htmlspecialchars($contrib['plan_name']) ?>'},
      {display_name:'Position',variable_name:'position',value:'<?= $contrib['position'] ?>'}
    ]},
    callback:function(res){window.location.href='<?= SITE_URL ?>/payments/paystack_verify.php?cid=<?= $cid ?>&reference='+res.reference;},
    onClose:function(){document.getElementById('pay-btn').disabled=false;document.getElementById('pay-btn').textContent='Pay <?= formatMoney($contrib['contribution_amount']) ?>';}
  });
  handler.openIframe();
}
</script></body></html>
