<?php
// ============================================================
// FILE: pages/join_plan.php
// INSTRUCTION: REPLACE your existing join_plan.php with this
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
$user_id = $_SESSION['user_id'];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_id        = intval($_POST['plan_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'online';

    if ($plan_id <= 0) { $errors[] = "Invalid plan selected."; }

    if (empty($errors)) {
        $s = $conn->prepare("SELECT * FROM plans WHERE id=? AND is_active=1");
        $s->bind_param("i",$plan_id); $s->execute();
        $plan = $s->get_result()->fetch_assoc(); $s->close();
        if (!$plan) $errors[] = "Plan not found or unavailable.";
    }

    if (empty($errors) && $plan['plan_status'] === 'completed')
        $errors[] = "This plan has already completed. Choose another.";

    if (empty($errors) && isPlanFull($conn,$plan_id))
        $errors[] = "This plan is full. No more slots available.";

    if (empty($errors) && userAlreadyJoinedPlan($conn,$user_id,$plan_id))
        $errors[] = "You already joined this plan. Check your dashboard.";

    if (empty($errors)) {
        $position = getNextAvailablePosition($conn,$plan_id,$plan['total_participants']);
        if (!$position) $errors[] = "No available slots. Plan is full.";
    }

    if (empty($errors)) {
        $collection_date = calculateCollectionDate($plan['plan_start_date'],$plan['frequency_days'],$position);
        $payout_amount   = calculatePayoutAmount($plan['contribution_amount'],$plan['total_participants']);

        $stmt = $conn->prepare("INSERT INTO contributions (user_id,plan_id,position,collection_date,payout_amount,payment_method,status,has_collected) VALUES (?,?,?,?,?,?,'active',0)");
        $stmt->bind_param("iiisds",$user_id,$plan_id,$position,$collection_date,$payout_amount,$payment_method);

        if ($stmt->execute()) {
            $cid = $stmt->insert_id; $stmt->close();
            $count = getPlanMemberCount($conn,$plan_id);
            if ($count >= intval($plan['total_participants'])) {
                $upd = $conn->prepare("UPDATE plans SET plan_status='active' WHERE id=? AND plan_status='open'");
                $upd->bind_param("i",$plan_id); $upd->execute(); $upd->close();
            }
            setFlash('success','You joined '.$plan['name'].' at Position '.$position.($collection_date ? '. Your collection date is '.date('F j, Y',strtotime($collection_date)).'.' : '.'));
            header("Location: ".SITE_URL."/pages/dashboard.php"); exit();
        }
        $stmt->close();
        $errors[] = "Could not join plan. Please try again.";
    }
}

$plans = $conn->query("SELECT p.*,(SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status!='removed') AS slots_filled FROM plans p WHERE p.is_active=1 AND p.plan_status IN ('open','active') ORDER BY p.contribution_amount ASC")->fetch_all(MYSQLI_ASSOC);
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Join a Plan — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="inner-page">
<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="nav-brand"><?= SITE_NAME ?></a>
    <div class="nav-links">
      <a href="dashboard.php">Dashboard</a>
      <a href="join_plan.php" class="active">Join a Plan</a>
      <a href="../logout.php">Logout</a>
    </div>
  </div>
</nav>
<main class="main-content"><div class="container">
<div class="page-header"><h1>Available Plans</h1><p>Choose a group — see your position and collection date before you commit.</p></div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><p><?= htmlspecialchars($flash['message']) ?></p></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?php foreach($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if (empty($plans)): ?>
  <div class="empty-state"><p>No plans available right now. Check back soon.</p></div>
<?php else: ?>
<div class="plans-grid">
  <?php foreach ($plans as $plan):
    $slots_filled = intval($plan['slots_filled']);
    $total        = intval($plan['total_participants']);
    $slots_left   = $total - $slots_filled;
    $is_full      = $slots_left <= 0;
    $already      = userAlreadyJoinedPlan($conn,$user_id,$plan['id']);
    $payout       = calculatePayoutAmount($plan['contribution_amount'],$total);
    $pct          = $total > 0 ? round(($slots_filled/$total)*100) : 0;
    $next_pos     = !$is_full ? getNextAvailablePosition($conn,$plan['id'],$total) : null;
    $next_col_date= ($next_pos && $plan['plan_start_date']) ? calculateCollectionDate($plan['plan_start_date'],$plan['frequency_days'],$next_pos) : null;
  ?>
  <div class="plan-card <?= $is_full?'plan-card--full':($already?'plan-card--joined':'') ?>">
    <div class="plan-card__header">
      <h3><?= htmlspecialchars($plan['name']) ?></h3>
      <span class="plan-badge"><?= formatFrequency($plan['frequency_days']) ?></span>
    </div>
    <div class="plan-card__amount"><?= formatMoney($plan['contribution_amount']) ?><small>per cycle per member</small></div>

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
        <span class="plan-info-label">Slots Filled</span>
        <span class="plan-info-value"><?= $slots_filled ?>/<?= $total ?></span>
      </div>
      <div class="plan-info-item">
        <span class="plan-info-label">Slots Left</span>
        <span class="plan-info-value <?= $slots_left<=2?'text-warning':'' ?>">
          <?= $is_full ? '<span class="text-danger">Full</span>' : $slots_left.' open' ?>
        </span>
      </div>
    </div>

    <div class="slot-progress">
      <div class="slot-progress__bar"><div class="slot-progress__fill" style="width:<?= $pct ?>%"></div></div>
      <span class="slot-progress__label"><?= $pct ?>% filled</span>
    </div>

    <!-- Show collection date preview BEFORE joining -->
    <?php if ($next_pos && $next_col_date && !$already && !$is_full): ?>
    <div class="collection-preview">
      <div class="collection-preview__icon">&#128197;</div>
      <div>
        <div class="collection-preview__label">If you join now — Position <?= $next_pos ?></div>
        <div class="collection-preview__value">
          Your collection day: <strong style="color:var(--gold)"><?= date('F j, Y',strtotime($next_col_date)) ?></strong>
        </div>
      </div>
    </div>
    <?php elseif (!$plan['plan_start_date'] && !$already && !$is_full): ?>
    <div class="collection-preview">
      <div class="collection-preview__icon">&#8987;</div>
      <div>
        <div class="collection-preview__label">Start date not set yet</div>
        <div class="collection-preview__value">Admin will set the start date. Your date will be calculated automatically.</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($plan['description']): ?>
      <p class="plan-card__desc"><?= htmlspecialchars($plan['description']) ?></p>
    <?php endif; ?>

    <?php if ($already): ?>
      <div class="plan-card__joined"><span>&#10003; You are in this group</span></div>
    <?php elseif ($is_full): ?>
      <div class="plan-card__full"><span>&#10007; This group is full</span></div>
    <?php else: ?>
      <form method="POST" action=""
        onsubmit="return confirmJoin(this,'<?= addslashes(htmlspecialchars($plan['name'])) ?>','<?= formatMoney($plan['contribution_amount']) ?>',<?= $next_pos??'null' ?>,'<?= $next_col_date?date('F j, Y',strtotime($next_col_date)):'TBD' ?>','<?= formatMoney($payout) ?>')">
        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <div class="form-group">
          <label>Payment Method</label>
          <select name="payment_method">
            <option value="online">Pay Online (Paystack)</option>
            <option value="cash">Pay by Cash</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Join This Group</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div></main>

<script>
function confirmJoin(form,name,amount,pos,colDate,payout) {
  var method = form.payment_method.value==='online' ? 'Online (Paystack)' : 'Cash';
  var posText = pos ? 'Position '+pos : 'Auto-assigned';
  return confirm('=== CONFIRM YOUR SLOT ===\n\nGroup: '+name+'\nYour contribution: '+amount+' per cycle\nYour position: '+posText+'\nYour collection day: '+colDate+'\nYou will receive: '+payout+'\nPayment method: '+method+'\n\nDo you want to join?');
}
</script>
</body>
</html>
