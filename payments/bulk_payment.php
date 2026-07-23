<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$cid     = intval($_GET['cid'] ?? 0);

if ($cid <= 0) {
    setFlash('error', 'Invalid request.');
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$stmt = $conn->prepare(
    "SELECT c.*, p.name AS plan_name, p.contribution_amount,
            p.frequency_days, p.plan_start_date, p.total_participants,
            p.plan_status
     FROM contributions c JOIN plans p ON c.plan_id = p.id
     WHERE c.id = ? AND c.user_id = ? AND c.status='active'"
);
$stmt->bind_param("ii", $cid, $user_id); $stmt->execute();
$contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$contrib) {
    setFlash('error', 'Not found.');
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$stmt = $conn->prepare("SELECT email, name, user_code FROM users WHERE id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

$freq        = intval($contrib['frequency_days']);
$amount      = floatval($contrib['contribution_amount']);
$cycles_paid = countMemberPayments($conn, $cid);
$total_slots = intval($contrib['total_participants']);
$remaining   = $total_slots - $cycles_paid;
$next_pay    = $contrib['next_payment_date'] ?? date('Y-m-d');

// Max cycles they can pay in advance
$max_bulk = min($remaining, 12); // cap at 12 to be safe

// Build cycle preview
$cycles_preview = [];
for ($i = 1; $i <= $max_bulk; $i++) {
    $cycle_date = date('Y-m-d', strtotime($next_pay . ' +' . (($i-1) * $freq) . ' days'));
    $cycles_preview[] = [
        'cycle_num' => $cycles_paid + $i,
        'date'      => $cycle_date,
        'amount'    => $amount,
    ];
}

$selected_cycles = intval($_GET['cycles'] ?? 1);
if ($selected_cycles < 1) $selected_cycles = 1;
if ($selected_cycles > $max_bulk) $selected_cycles = $max_bulk;

$total_amount = $amount * $selected_cycles;
$amount_kobo  = intval($total_amount * 100);
$ref          = generatePaymentReference($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Bulk Payment — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260530">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Jost',sans-serif;background:#FAF9F7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem 1rem;}
.wrap{width:100%;max-width:500px;margin:0 auto;}
.logo{text-align:center;margin-bottom:1.5rem;}
.logo a{font-family:'Cormorant Garamond',serif;font-size:1.9rem;font-weight:600;letter-spacing:.15em;color:#1A1A1A;text-decoration:none;display:block;}
.logo p{font-size:.78rem;color:#6B6860;margin-top:.2rem;}
.card{background:#fff;border:1px solid #F2F0ED;border-radius:16px;padding:1.75rem;box-shadow:0 4px 24px rgba(0,0,0,.07);}
.card h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;margin-bottom:.25rem;}
.mid{font-size:.82rem;color:#6B6860;margin-bottom:1.25rem;}

/* Cycle selector */
.cycle-selector{display:flex;flex-direction:column;gap:.65rem;margin-bottom:1.5rem;}
.cycle-label{font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#1A1A1A;margin-bottom:.25rem;display:block;}
.cycle-btns{display:flex;gap:.5rem;flex-wrap:wrap;}
.cycle-btn{
    padding:.55rem 1rem;border-radius:8px;border:1.5px solid #C8C5BF;
    background:#fff;font-family:'Jost',sans-serif;font-size:.85rem;
    font-weight:600;cursor:pointer;transition:all .2s;color:#1A1A1A;
}
.cycle-btn:hover{border-color:#C9A84C;background:#FDFAF3;}
.cycle-btn.selected{border-color:#C9A84C;background:#C9A84C;color:#fff;}

/* Cycle preview list */
.cycle-list{background:#FAF9F7;border-radius:10px;padding:.9rem 1rem;margin-bottom:1.25rem;max-height:220px;overflow-y:auto;}
.cycle-item{display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid #F2F0ED;font-size:.82rem;}
.cycle-item:last-child{border-bottom:none;}
.cycle-item .cn{color:#6B6860;}
.cycle-item .cd{font-weight:600;color:#1A1A1A;}
.cycle-item .ca{color:#C9A84C;font-weight:600;}
.cycle-item .ck{color:var(--success);font-size:1rem;}

/* Total */
.total-box{background:#1A1A1A;border-radius:10px;padding:.9rem 1.1rem;display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;}
.total-box .tl{color:rgba(255,255,255,.7);font-size:.85rem;}
.total-box .ta{color:#C9A84C;font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:600;}

.btn-pay{width:100%;padding:.9rem;background:#C9A84C;color:#fff;border:none;border-radius:8px;font-family:'Jost',sans-serif;font-size:.9rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:background .2s;}
.btn-pay:hover{background:#1A1A1A;}
.btn-pay:disabled{background:#C8C5BF;cursor:not-allowed;}
.cancel{display:block;text-align:center;margin-top:.85rem;font-size:.82rem;color:#6B6860;text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <a href="../index.php"><?= SITE_NAME ?></a>
    <p>Bulk Contribution Payment</p>
  </div>
  <div class="card">
    <h2>Pay Multiple Cycles</h2>
    <p class="mid">
      <strong style="color:#C9A84C"><?= htmlspecialchars($user['user_code']??'') ?></strong>
      &nbsp;·&nbsp; <?= htmlspecialchars($contrib['plan_name']) ?>
      &nbsp;·&nbsp; Position <?= $contrib['position'] ?>
      &nbsp;·&nbsp; <?= formatMoney($amount) ?>/<?= formatFrequency($freq) ?>
    </p>

    <!-- How many cycles to pay -->
    <span class="cycle-label">How many cycles do you want to pay?</span>
    <div class="cycle-btns" id="cycleBtns">
      <?php
      $presets = [1,2,3,5,10];
      if ($max_bulk < 10) $presets = array_filter($presets, fn($x) => $x <= $max_bulk);
      if (!in_array($max_bulk, $presets) && $max_bulk > 1) $presets[] = $max_bulk;
      sort($presets);
      foreach ($presets as $n): ?>
      <button type="button" class="cycle-btn <?= $selected_cycles===$n?'selected':'' ?>"
              data-cycles="<?= $n ?>"
              onclick="selectCycles(<?= $n ?>)">
        <?= $n ?> <?= $freq===1?($n===1?'day':'days'):($freq===7?($n===1?'week':'weeks'):($freq===30?($n===1?'month':'months'):'cycle'.($n>1?'s':''))) ?>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Cycle breakdown -->
    <div style="margin-top:1.1rem;">
      <div style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6B6860;margin-bottom:.5rem;">
        You are paying for these cycles:
      </div>
      <div class="cycle-list" id="cycleList">
        <?php foreach ($cycles_preview as $i => $cy): ?>
        <div class="cycle-item cycle-row" data-idx="<?= $i+1 ?>">
          <span class="cn">Cycle <?= $cy['cycle_num'] ?></span>
          <span class="cd"><?= date('M j, Y', strtotime($cy['date'])) ?></span>
          <span class="ca"><?= formatMoney($cy['amount']) ?></span>
          <span class="ck" style="<?= ($i+1) > $selected_cycles ? 'opacity:.25' : '' ?>">
            <?= ($i+1) <= $selected_cycles ? '✓' : '○' ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Total -->
    <div class="total-box" id="totalBox">
      <div>
        <div class="tl">Total for <span id="cycleCount"><?= $selected_cycles ?></span> cycle(s)</div>
        <div style="color:rgba(255,255,255,.5);font-size:.72rem;margin-top:.15rem;">
          <?= $selected_cycles ?> × <?= formatMoney($amount) ?>
        </div>
      </div>
      <div class="ta" id="totalAmt"><?= formatMoney($total_amount) ?></div>
    </div>

    <button onclick="payWithPaystack()" class="btn-pay" id="pay-btn">
      Pay <?= formatMoney($total_amount) ?> Now
    </button>
    <a href="../pages/dashboard.php" class="cancel">Cancel — Back to Dashboard</a>
  </div>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
var amount_per_cycle = <?= $amount ?>;
var all_cycles = <?= json_encode($cycles_preview) ?>;
var selected   = <?= $selected_cycles ?>;
var ref        = '<?= $ref ?>';

function selectCycles(n) {
    selected = n;

    // Update buttons
    document.querySelectorAll('.cycle-btn').forEach(function(b) {
        b.classList.toggle('selected', parseInt(b.dataset.cycles) === n);
    });

    // Update cycle list
    document.querySelectorAll('.cycle-row').forEach(function(row) {
        var idx  = parseInt(row.dataset.idx);
        var tick = row.querySelector('.ck');
        tick.textContent = idx <= n ? '✓' : '○';
        tick.style.opacity = idx <= n ? '1' : '.25';
    });

    // Update total
    var total = amount_per_cycle * n;
    document.getElementById('cycleCount').textContent = n;
    document.getElementById('totalAmt').textContent   = '₦' + total.toLocaleString('en-NG', {minimumFractionDigits:2});

    // Update button
    var btn = document.getElementById('pay-btn');
    btn.textContent = 'Pay ₦' + total.toLocaleString('en-NG', {minimumFractionDigits:2}) + ' Now';
}

function payWithPaystack() {
    var btn   = document.getElementById('pay-btn');
    var total = amount_per_cycle * selected;

    btn.disabled    = true;
    btn.textContent = 'Processing...';

    var handler = PaystackPop.setup({
        key:      '<?= PAYSTACK_PUBLIC_KEY ?>',
        email:    '<?= htmlspecialchars($user['email']) ?>',
        amount:   Math.round(total * 100),
        currency: 'NGN',
        ref:      ref,
        metadata: {
            custom_fields: [
                {display_name:'Member ID',  variable_name:'user_code',     value:'<?= htmlspecialchars($user['user_code']??'') ?>'},
                {display_name:'Group',      variable_name:'plan_name',     value:'<?= htmlspecialchars($contrib['plan_name']) ?>'},
                {display_name:'Cycles',     variable_name:'bulk_cycles',   value: selected},
                {display_name:'Amount/Cycle',variable_name:'amount_cycle', value: amount_per_cycle}
            ]
        },
        callback: function(res) {
            window.location.href = '<?= SITE_URL ?>/payments/bulk_verify.php?cid=<?= $cid ?>&reference=' + res.reference + '&cycles=' + selected;
        },
        onClose: function() {
            btn.disabled    = false;
            btn.textContent = 'Pay ₦' + total.toLocaleString('en-NG', {minimumFractionDigits:2}) + ' Now';
        }
    });
    handler.openIframe();
}
</script>
</body>
</html>
