<?php
// ============================================================
// FILE: payments/cash_payment.php
// INSTRUCTION: NEW FILE — copy into /swl/payments/cash_payment.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
$user_id=$_SESSION['user_id'];
$cid=intval($_GET['cid']??0);
if($cid<=0){setFlash('error','Invalid request.');header("Location: ".SITE_URL."/pages/dashboard.php");exit();}
$stmt=$conn->prepare("SELECT c.*,p.name AS plan_name,p.contribution_amount FROM contributions c JOIN plans p ON c.plan_id=p.id WHERE c.id=? AND c.user_id=?");
$stmt->bind_param("ii",$cid,$user_id);$stmt->execute();$contrib=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$contrib){setFlash('error','Not found.');header("Location: ".SITE_URL."/pages/dashboard.php");exit();}
$submitted=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $ref='CASH-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid()),0,6));
    $amt=$contrib['contribution_amount'];
    $chk=$conn->prepare("SELECT id FROM payments WHERE contribution_id=? AND status='pending'");
    $chk->bind_param("i",$cid);$chk->execute();$chk->store_result();
    if($chk->num_rows>0){$error="You already have a pending cash payment. Wait for admin to verify.";}
    else{
        $ins=$conn->prepare("INSERT INTO payments (contribution_id,reference,amount,status) VALUES (?,?,?,'pending')");
        $ins->bind_param("isd",$cid,$ref,$amt);
        if($ins->execute())$submitted=true;else $error="Something went wrong.";
        $ins->close();
    }$chk->close();
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Cash Payment — <?= SITE_NAME ?></title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="auth-page"><div class="auth-container" style="max-width:500px;">
<div class="auth-logo"><a href="../index.php"><img src="../assets/images/logo.png" alt="<?= SITE_NAME ?>" class="site-logo" onerror="this.style.display='none';this.nextElementSibling.style.display='block';"><span class="brand-text" style="display:none;"><?= SITE_NAME ?></span></a></div>
<div class="auth-card">
<?php if($submitted): ?>
<div style="text-align:center;padding:1rem 0;"><div class="cash-success-icon">&#10003;</div>
<h2 style="margin-top:1rem;">Request Submitted!</h2>
<p class="auth-subtitle">Cash payment of <strong><?= formatMoney($contrib['contribution_amount']) ?></strong> for <strong><?= htmlspecialchars($contrib['plan_name']) ?></strong> recorded.</p>
<div class="alert alert-success" style="text-align:left;margin-top:1.5rem;">
<p><strong>Next steps:</strong></p>
<p>1. Hand over <strong><?= formatMoney($contrib['contribution_amount']) ?></strong> in cash to the admin.</p>
<p>2. Admin will verify and mark as paid.</p>
<p>3. You will receive a confirmation email.</p>
</div>
<a href="../pages/dashboard.php" class="btn btn-primary btn-full" style="margin-top:1rem;">Go to Dashboard</a>
</div>
<?php else: ?>
<h2>Cash Payment</h2>
<?php if($error): ?><div class="alert alert-error"><p><?=htmlspecialchars($error)?></p></div><?php endif; ?>
<div class="payment-summary">
  <div class="payment-summary__row"><span>Group</span><strong><?=htmlspecialchars($contrib['plan_name'])?></strong></div>
  <div class="payment-summary__row"><span>Position</span><strong>Position <?=$contrib['position']?></strong></div>
  <div class="payment-summary__row"><span>Collection Day</span><strong style="color:var(--gold)"><?=$contrib['collection_date']?date('M j, Y',strtotime($contrib['collection_date'])):'TBD'?></strong></div>
  <div class="payment-summary__row"><span>Amount Due</span><strong class="amount-gold"><?=formatMoney($contrib['contribution_amount'])?></strong></div>
</div>
<form method="POST"><button type="submit" class="btn btn-primary btn-full">Confirm Cash Payment Request</button></form>
<p style="text-align:center;margin-top:1rem;"><a href="../pages/dashboard.php" style="font-size:.85rem;color:var(--gray-text);">Cancel</a></p>
<?php endif; ?>
</div></div></body></html>
