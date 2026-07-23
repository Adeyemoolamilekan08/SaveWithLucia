<?php
// ============================================================
// FILE: pages/join_plan.php
// REPLACE your existing join_plan.php with this
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
$user_id = $_SESSION['user_id'];
$errors  = [];

// ============================================================
// HANDLE JOIN FORM SUBMISSION
// ============================================================
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

    if (empty($errors)) {
        // Block: plan already running or finished
        if ($plan['plan_status'] === 'active') {
            $errors[] = "This plan has already started and is no longer accepting new members.";
        } elseif ($plan['plan_status'] === 'completed') {
            $errors[] = "This plan has already completed. All members have collected.";
        }
    }

    if (empty($errors) && isPlanFull($conn, $plan_id)) {
        $errors[] = "This plan is full — no more slots available.";
    }

    if (empty($errors) && userAlreadyJoinedPlan($conn, $user_id, $plan_id)) {
        $errors[] = "You already joined this plan. Check your dashboard.";
    }

    if (empty($errors)) {
        $position = getNextAvailablePosition($conn, $plan_id, $plan['total_participants']);
        if (!$position) $errors[] = "No available slots. Plan is full.";
    }

    if (empty($errors)) {
        $collection_date = calculateCollectionDate(
            $plan['plan_start_date'],
            $plan['frequency_days'],
            $position
        );
        $payout_amount = calculatePayoutAmount(
            $plan['contribution_amount'],
            $plan['total_participants']
        );

        $stmt = $conn->prepare(
            "INSERT INTO contributions
                (user_id, plan_id, position, collection_date, payout_amount, payment_method, status, has_collected)
             VALUES (?,?,?,?,?,?,'active',0)"
        );
        $stmt->bind_param("iiisds",
            $user_id, $plan_id, $position,
            $collection_date, $payout_amount, $payment_method
        );

        if ($stmt->execute()) {
            $stmt->close();

            // Auto-lock plan when last slot is filled
            $filled = getPlanMemberCount($conn, $plan_id);
            if ($filled >= intval($plan['total_participants'])) {
                // Plan is now FULL — lock it so no more members can join
                // Status becomes 'active' only when admin sets a start date
                // If start date is already set, activate immediately
                if (!empty($plan['plan_start_date'])) {
                    $conn->query("UPDATE plans SET plan_status='active' WHERE id=$plan_id AND plan_status='open'");
                }
                // If no start date yet, admin will activate manually after setting start date
            }

            $col_txt = $collection_date
                ? ' Your collection date is <strong>' . date('F j, Y', strtotime($collection_date)) . '</strong>.'
                : ' The admin will set your collection date once the group starts.';

            setFlash('success',
                'You joined <strong>' . htmlspecialchars($plan['name']) . '</strong> at <strong>Position ' . $position . '</strong>.' . $col_txt
            );
            header("Location: " . SITE_URL . "/pages/dashboard.php");
            exit();
        }
        $stmt->close();
        $errors[] = "Could not join plan. Please try again.";
    }
}

// ============================================================
// LOAD PLANS
// Only show plans that are 'open' (still accepting members)
// 'active' plans have started — no new members allowed
// 'completed' plans are done — hide them
// ============================================================
$open_plans = $conn->query(
    "SELECT p.*,
        (SELECT COUNT(*) FROM contributions c
         WHERE c.plan_id=p.id AND c.status!='removed') AS slots_filled
     FROM plans p
     WHERE p.is_active=1
       AND p.plan_status = 'open'
     ORDER BY p.contribution_amount ASC"
)->fetch_all(MYSQLI_ASSOC);

// Also load FULL plans (status still open but slots maxed)
// so we can show them as suggestions in the "no space" message
$all_visible = $conn->query(
    "SELECT p.*,
        (SELECT COUNT(*) FROM contributions c
         WHERE c.plan_id=p.id AND c.status!='removed') AS slots_filled
     FROM plans p
     WHERE p.is_active=1
       AND p.plan_status IN ('open','active')
     ORDER BY p.contribution_amount ASC"
)->fetch_all(MYSQLI_ASSOC);

// Split into joinable vs full/running
$joinable = [];
$full_or_running = [];
foreach ($all_visible as $p) {
    $filled = intval($p['slots_filled']);
    $total  = intval($p['total_participants']);
    if ($p['plan_status'] === 'open' && $filled < $total) {
        $joinable[] = $p;
    } else {
        $full_or_running[] = $p;
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Join a Plan — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
/* ---- Plan status banners ---- */
.full-plans-section {
    margin-top: 3rem;
    padding-top: 2rem;
    border-top: 2px dashed var(--gray-light);
}
.full-plans-section h2 {
    font-size: 1.3rem;
    color: var(--gray-text);
    margin-bottom: .5rem;
}
.full-plans-section p {
    font-size: .875rem;
    color: var(--gray-text);
    margin-bottom: 1.5rem;
}
.plan-card--locked {
    opacity: .75;
    border-color: var(--gray-mid);
    pointer-events: none;
}
.plan-card--locked:hover {
    transform: none;
    box-shadow: none;
    border-color: var(--gray-mid);
}
.plan-locked-tag {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #F2F0ED;
    color: var(--gray-text);
    border-radius: var(--radius);
    padding: .7rem 1rem;
    font-size: .875rem;
    font-weight: 500;
}
.plan-running-tag {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #EEF2FF;
    color: #4338CA;
    border-radius: var(--radius);
    padding: .7rem 1rem;
    font-size: .875rem;
    font-weight: 500;
}
.no-plans-box {
    text-align: center;
    padding: 3.5rem 2rem;
    background: var(--white);
    border-radius: 16px;
    border: 2px dashed var(--gray-mid);
}
.no-plans-box .icon { font-size: 3rem; margin-bottom: 1rem; display: block; }
.no-plans-box h2 { font-size: 1.6rem; margin-bottom: .75rem; }
.no-plans-box p  { color: var(--gray-text); font-size: .95rem; line-height: 1.7; max-width: 460px; margin: 0 auto .5rem; }
.urgency-badge {
    display: inline-block;
    background: #FEF3C7;
    color: #92400E;
    font-size: .72rem;
    font-weight: 700;
    padding: .2rem .65rem;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-left: .4rem;
}
.urgency-badge--hot {
    background: #FEF2F2;
    color: #B91C1C;
}
</style>
</head>
<body class="inner-page">
<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="nav-brand"><?= SITE_NAME ?></a>
    <button class="nav-toggle" id="userNavToggle" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <div class="nav-links" id="userNavLinks">
      <a href="dashboard.php">Dashboard</a>
      <a href="join_plan.php" class="active">Join a Plan</a>
      <a href="../logout.php" style="color:var(--error);">Logout</a>
    </div>
  </div>
</nav>
<script>
(function(){
  var btn=document.getElementById('userNavToggle'),links=document.getElementById('userNavLinks');
  if(!btn||!links)return;
  btn.addEventListener('click',function(){
    links.classList.toggle('open');
    var sp=btn.querySelectorAll('span');
    if(links.classList.contains('open')){sp[0].style.transform='rotate(45deg) translate(5px,5px)';sp[1].style.opacity='0';sp[2].style.transform='rotate(-45deg) translate(5px,-5px)';}
    else{sp[0].style.transform=sp[2].style.transform='';sp[1].style.opacity='';}
  });
  links.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){links.classList.remove('open');var sp=btn.querySelectorAll('span');sp[0].style.transform=sp[2].style.transform='';sp[1].style.opacity='';});});
})();
</script>

<main class="main-content"><div class="container">

<div class="page-header">
  <h1>Join a Contribution Group</h1>
  <p>Pick your slot — your position and exact collection date are shown before you commit.</p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= $flash['message'] ?></p>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (empty($joinable)): ?>
<!-- ============================================================
     NO OPEN PLANS — show friendly message + suggestions
     ============================================================ -->
<div class="no-plans-box">
  <span class="icon">&#128197;</span>
  <h2>No Open Groups Right Now</h2>
  <p>
    All current groups are either <strong>full</strong> or have already <strong>started</strong>.
    New groups open regularly — check back soon or ask your admin when the next one opens.
  </p>
  <p style="margin-top:.5rem;">
    You can also contact the admin to be added to a <strong>waiting list</strong> for the next group.
  </p>
  <a href="dashboard.php" class="btn btn-primary" style="margin-top:1.5rem;">Go to My Dashboard</a>
</div>

<?php if (!empty($full_or_running)): ?>
<!-- Show what is available even though you can't join -->
<div class="full-plans-section">
  <h2>Currently Running Groups</h2>
  <p>These groups are active or full. You cannot join them, but you can see what is coming up.</p>
  <div class="plans-grid">
    <?php foreach ($full_or_running as $plan):
      $filled  = intval($plan['slots_filled']);
      $total   = intval($plan['total_participants']);
      $payout  = calculatePayoutAmount($plan['contribution_amount'], $total);
      $pct     = $total > 0 ? round(($filled / $total) * 100) : 0;
      $is_running = $plan['plan_status'] === 'active';
    ?>
    <div class="plan-card plan-card--locked">
      <div class="plan-card__header">
        <h3><?= htmlspecialchars($plan['name']) ?></h3>
        <span class="plan-badge"><?= formatFrequency($plan['frequency_days']) ?></span>
      </div>
      <div class="plan-card__amount">
        <?= formatMoney($plan['contribution_amount']) ?><small>per cycle</small>
      </div>
      <div class="plan-info-grid">
        <div class="plan-info-item">
          <span class="plan-info-label">Group Size</span>
          <span class="plan-info-value"><?= $total ?> people</span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Payout</span>
          <span class="plan-info-value plan-info-value--gold"><?= formatMoney($payout) ?></span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Slots</span>
          <span class="plan-info-value"><?= $filled ?>/<?= $total ?></span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Start Date</span>
          <span class="plan-info-value" style="font-size:.82rem;">
            <?= $plan['plan_start_date'] ? date('M j, Y', strtotime($plan['plan_start_date'])) : 'TBD' ?>
          </span>
        </div>
      </div>
      <div class="slot-progress">
        <div class="slot-progress__bar">
          <div class="slot-progress__fill" style="width:<?= $pct ?>%"></div>
        </div>
        <span class="slot-progress__label"><?= $pct ?>% filled</span>
      </div>
      <?php if ($is_running): ?>
        <div class="plan-running-tag">&#9654; This group has started — rotation in progress</div>
      <?php else: ?>
        <div class="plan-locked-tag">&#128274; Full — no slots remaining</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ============================================================
     OPEN PLANS — show joinable groups
     ============================================================ -->
<div class="plans-grid">
  <?php foreach ($joinable as $plan):
    $slots_filled  = intval($plan['slots_filled']);
    $total         = intval($plan['total_participants']);
    $slots_left    = $total - $slots_filled;
    $already       = userAlreadyJoinedPlan($conn, $user_id, $plan['id']);
    $payout        = calculatePayoutAmount($plan['contribution_amount'], $total);
    $pct           = $total > 0 ? round(($slots_filled / $total) * 100) : 0;
    $next_pos      = getNextAvailablePosition($conn, $plan['id'], $total);
    $next_col_date = ($next_pos && $plan['plan_start_date'])
                     ? calculateCollectionDate($plan['plan_start_date'], $plan['frequency_days'], $next_pos)
                     : null;

    // Urgency label
    $urgency = null;
    if ($slots_left === 1)       $urgency = ['label' => 'Last slot!',   'class' => 'urgency-badge--hot'];
    elseif ($slots_left <= 3)    $urgency = ['label' => 'Almost full',  'class' => ''];
  ?>
  <div class="plan-card <?= $already ? 'plan-card--joined' : '' ?>">

    <div class="plan-card__header">
      <h3>
        <?= htmlspecialchars($plan['name']) ?>
        <?php if ($urgency): ?>
          <span class="urgency-badge <?= $urgency['class'] ?>"><?= $urgency['label'] ?></span>
        <?php endif; ?>
      </h3>
      <span class="plan-badge"><?= formatFrequency($plan['frequency_days']) ?></span>
    </div>

    <div class="plan-card__amount">
      <?= formatMoney($plan['contribution_amount']) ?><small>per cycle per member</small>
    </div>

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
        <span class="plan-info-value <?= $slots_left <= 2 ? 'text-warning' : '' ?>">
          <strong><?= $slots_left ?></strong> open
        </span>
      </div>
    </div>

    <!-- Slot progress bar -->
    <div class="slot-progress">
      <div class="slot-progress__bar">
        <div class="slot-progress__fill" style="width:<?= $pct ?>%"></div>
      </div>
      <span class="slot-progress__label"><?= $pct ?>% filled</span>
    </div>

    <!-- Collection date preview — shown BEFORE joining -->
    <?php if ($next_pos && $next_col_date && !$already): ?>
    <div class="collection-preview">
      <div class="collection-preview__icon">&#128197;</div>
      <div>
        <div class="collection-preview__label">If you join now — Position <?= $next_pos ?></div>
        <div class="collection-preview__value">
          Your collection day:
          <strong style="color:var(--gold)"><?= date('F j, Y', strtotime($next_col_date)) ?></strong>
        </div>
      </div>
    </div>
    <?php elseif (!$plan['plan_start_date'] && !$already): ?>
    <div class="collection-preview">
      <div class="collection-preview__icon">&#8987;</div>
      <div>
        <div class="collection-preview__label">Start date not set yet</div>
        <div class="collection-preview__value">
          Admin will set the start date. Your collection date will be calculated automatically.
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($plan['description']): ?>
      <p class="plan-card__desc"><?= htmlspecialchars($plan['description']) ?></p>
    <?php endif; ?>

    <!-- Action area -->
    <?php if ($already): ?>
      <div class="plan-card__joined"><span>&#10003; You are already in this group</span></div>

    <?php else: ?>
      <form method="POST" action=""
        onsubmit="return confirmJoin(
          this,
          '<?= addslashes(htmlspecialchars($plan['name'])) ?>',
          '<?= formatMoney($plan['contribution_amount']) ?>',
          <?= $next_pos ?? 'null' ?>,
          '<?= $next_col_date ? date('F j, Y', strtotime($next_col_date)) : 'TBD — admin will confirm' ?>',
          '<?= formatMoney($payout) ?>'
        )">
        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <div class="form-group">
          <label>Payment Method</label>
          <select name="payment_method">
            <option value="online">Pay Online (Paystack)</option>
            <option value="cash">Pay by Cash</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-full">
          Join This Group
          <?php if ($urgency): ?> — <?= $urgency['label'] ?><?php endif; ?>
        </button>
      </form>
    <?php endif; ?>

  </div>
  <?php endforeach; ?>
</div>

<!-- Show full/running plans below as context (cannot join) -->
<?php if (!empty($full_or_running)): ?>
<div class="full-plans-section">
  <h2>Other Groups</h2>
  <p>These groups are already full or have started. Shown for your reference.</p>
  <div class="plans-grid">
    <?php foreach ($full_or_running as $plan):
      $filled     = intval($plan['slots_filled']);
      $total      = intval($plan['total_participants']);
      $payout     = calculatePayoutAmount($plan['contribution_amount'], $total);
      $pct        = $total > 0 ? round(($filled / $total) * 100) : 0;
      $is_running = $plan['plan_status'] === 'active';
    ?>
    <div class="plan-card plan-card--locked">
      <div class="plan-card__header">
        <h3><?= htmlspecialchars($plan['name']) ?></h3>
        <span class="plan-badge"><?= formatFrequency($plan['frequency_days']) ?></span>
      </div>
      <div class="plan-card__amount">
        <?= formatMoney($plan['contribution_amount']) ?><small>per cycle</small>
      </div>
      <div class="plan-info-grid">
        <div class="plan-info-item">
          <span class="plan-info-label">Group Size</span>
          <span class="plan-info-value"><?= $total ?> people</span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Payout</span>
          <span class="plan-info-value plan-info-value--gold"><?= formatMoney($payout) ?></span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Slots</span>
          <span class="plan-info-value"><?= $filled ?>/<?= $total ?></span>
        </div>
        <div class="plan-info-item">
          <span class="plan-info-label">Start Date</span>
          <span class="plan-info-value" style="font-size:.82rem;">
            <?= $plan['plan_start_date'] ? date('M j, Y', strtotime($plan['plan_start_date'])) : 'TBD' ?>
          </span>
        </div>
      </div>
      <div class="slot-progress">
        <div class="slot-progress__bar">
          <div class="slot-progress__fill" style="width:<?= $pct ?>%"></div>
        </div>
        <span class="slot-progress__label"><?= $pct ?>% filled</span>
      </div>
      <?php if ($is_running): ?>
        <div class="plan-running-tag">&#9654; Running — rotation in progress</div>
      <?php else: ?>
        <div class="plan-locked-tag">&#128274; Full — no slots available</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

</div></main>

<script>
function confirmJoin(form, name, amount, pos, colDate, payout) {
  var method  = form.payment_method.value === 'online' ? 'Online (Paystack)' : 'Cash';
  var posText = pos ? 'Position ' + pos : 'Auto-assigned';
  return confirm(
    '=== CONFIRM YOUR SLOT ===\n\n' +
    'Group:               ' + name    + '\n' +
    'Your contribution:   ' + amount  + ' per cycle\n' +
    'Your position:       ' + posText + '\n' +
    'Your collection day: ' + colDate + '\n' +
    'You will receive:    ' + payout  + '\n' +
    'Payment method:      ' + method  + '\n\n' +
    'Do you want to join this group?'
  );
}
</script>
</body>
</html>
