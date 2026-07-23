<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$plan_id = intval($_GET['plan'] ?? 0);
$today   = date('Y-m-d');

$all_plans = $conn->query(
    "SELECT p.id, p.name, p.plan_status, p.frequency_days,
            p.contribution_amount, p.total_participants, p.plan_start_date,
            (SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status='active') AS member_count
     FROM plans p WHERE p.is_active=1
     ORDER BY FIELD(p.plan_status,'active','open','completed'), p.name"
)->fetch_all(MYSQLI_ASSOC);

$plan = null; $members = [];
$summary = ['paid'=>0,'pending'=>0,'unpaid'=>0,'collected'=>0];

if ($plan_id > 0) {
    $plan = $conn->query("SELECT * FROM plans WHERE id=$plan_id")->fetch_assoc();
    if ($plan) {
        $freq = intval($plan['frequency_days']);
        $s = $conn->prepare(
            "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
                    c.has_collected, c.total_cycles_paid, c.next_payment_date,
                    c.payment_method,
                    u.id AS uid, u.name, u.email, u.phone, u.user_code,
                    (SELECT COUNT(*) FROM payments py
                     WHERE py.contribution_id=c.id AND py.status='paid'
                       AND DATE(py.paid_at) >= DATE_SUB(COALESCE(c.next_payment_date,CURDATE()), INTERVAL ? DAY)
                       AND DATE(py.paid_at) <= COALESCE(c.next_payment_date,CURDATE())
                    ) AS paid_this_cycle,
                    (SELECT COUNT(*) FROM payments py
                     WHERE py.contribution_id=c.id AND py.status='pending') AS has_pending,
                    (SELECT MAX(DATE(py.paid_at)) FROM payments py
                     WHERE py.contribution_id=c.id AND py.status='paid') AS last_paid_date,
                    (SELECT COUNT(*) FROM payments py
                     WHERE py.contribution_id=c.id AND py.status='paid') AS total_paid
             FROM contributions c JOIN users u ON c.user_id=u.id
             WHERE c.plan_id=? AND c.status='active'
             ORDER BY c.position ASC"
        );
        $s->bind_param("ii", $freq, $plan_id); $s->execute();
        $members = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

        foreach ($members as $m) {
            if ($m['has_collected'])       $summary['collected']++;
            elseif ($m['paid_this_cycle']) $summary['paid']++;
            elseif ($m['has_pending'])     $summary['pending']++;
            else                           $summary['unpaid']++;
        }
    }
}
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Payment Status — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260530">
<style>
.plan-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.75rem;}
.plan-tab{display:block;padding:.6rem .95rem;border-radius:10px;border:1.5px solid var(--gray-light);text-decoration:none;color:var(--black);font-size:.8rem;font-weight:500;transition:var(--transition);white-space:nowrap;}
.plan-tab:hover,.plan-tab.active{border-color:var(--gold);background:#FDFAF3;color:var(--black);}
.sum-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.5rem;}
.sum-card{background:var(--white);border:1px solid var(--gray-light);border-radius:12px;padding:.85rem 1rem;text-align:center;}
.sum-num{font-family:var(--font-head);font-size:2rem;font-weight:600;display:block;line-height:1;}
.sum-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-text);display:block;margin-top:.2rem;}
.s-green{color:var(--success);}.s-yellow{color:#B7860B;}.s-red{color:var(--error);}.s-purple{color:#4338CA;}
.pay-list{display:flex;flex-direction:column;gap:.5rem;}
.pay-card{display:flex;align-items:center;gap:.75rem;background:var(--white);border:1.5px solid var(--gray-light);border-left:4px solid var(--gray-mid);border-radius:10px;padding:.85rem 1rem;flex-wrap:wrap;}
.pay-card.c-paid{border-left-color:var(--success);background:#F0FAF4;}
.pay-card.c-pending{border-left-color:#F59E0B;background:#FFFBEB;}
.pay-card.c-unpaid{border-left-color:var(--error);background:#FEF2F2;}
.pay-card.c-collected{border-left-color:#4338CA;background:#EEF2FF;opacity:.85;}
.pay-pos{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff;flex-shrink:0;}
.pp-paid{background:var(--success);}.pp-pending{background:#B7860B;}.pp-unpaid{background:var(--error);}.pp-collected{background:#4338CA;}
.pay-info{flex:1;min-width:0;}
.pay-name{font-weight:600;font-size:.88rem;}
.pay-sub{font-size:.72rem;color:var(--gray-text);margin-top:.1rem;}
.pay-st{font-size:.78rem;font-weight:600;flex-shrink:0;text-align:right;}
@media(max-width:480px){
    .sum-bar{grid-template-columns:repeat(2,1fr);}
    .plan-tab{font-size:.72rem;padding:.45rem .7rem;}
    .pay-st{display:none;}
}
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Payment Status</h1>
    <p>See who has paid and who has not for each plan this cycle.</p>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><p><?= $flash['message'] ?></p></div><?php endif; ?>

<div class="plan-tabs">
    <?php foreach ($all_plans as $p): ?>
    <a href="payment_status.php?plan=<?= $p['id'] ?>"
       class="plan-tab <?= $plan_id===$p['id']?'active':'' ?>">
        <?= htmlspecialchars($p['name']) ?>
        <span style="font-size:.65rem;padding:.1rem .35rem;border-radius:20px;margin-left:.3rem;font-weight:700;background:<?= $p['plan_status']==='active'?'#EDF7F1':'#EEF2FF' ?>;color:<?= $p['plan_status']==='active'?'var(--success)':'#4338CA' ?>;">
            <?= ucfirst($p['plan_status']) ?>
        </span>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($plan && !empty($members)): ?>

<div style="background:var(--white);border:1px solid var(--gray-light);border-radius:12px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;gap:1.25rem;flex-wrap:wrap;font-size:.875rem;align-items:center;">
    <strong><?= htmlspecialchars($plan['name']) ?></strong>
    <span style="color:var(--gray-text);">Freq: <strong style="color:var(--black)"><?= formatFrequency($plan['frequency_days']) ?></strong></span>
    <span style="color:var(--gray-text);">Amount: <strong style="color:var(--gold)"><?= formatMoney($plan['contribution_amount']) ?></strong>/cycle</span>
    <a href="reminders.php?plan=<?= $plan_id ?>" class="btn-action btn-action--edit" style="margin-left:auto;">&#9993; Send Reminders</a>
</div>

<div class="sum-bar">
    <div class="sum-card"><span class="sum-num s-green"><?= $summary['paid'] ?></span><span class="sum-lbl">Paid</span></div>
    <div class="sum-card"><span class="sum-num s-yellow"><?= $summary['pending'] ?></span><span class="sum-lbl">Cash Pending</span></div>
    <div class="sum-card"><span class="sum-num s-red"><?= $summary['unpaid'] ?></span><span class="sum-lbl">Not Paid</span></div>
    <div class="sum-card"><span class="sum-num s-purple"><?= $summary['collected'] ?></span><span class="sum-lbl">Payout Collected</span></div>
</div>

<div class="pay-list">
<?php foreach ($members as $m):
    if ($m['has_collected'])       { $cc='c-collected'; $pc='pp-collected'; $st='✓ Collected Payout'; $sc='#4338CA'; }
    elseif ($m['paid_this_cycle']) { $cc='c-paid';      $pc='pp-paid';      $st='✓ Paid';             $sc='var(--success)'; }
    elseif ($m['has_pending'])     { $cc='c-pending';   $pc='pp-pending';   $st='⏳ Cash Pending';    $sc='#B7860B'; }
    else                           { $cc='c-unpaid';    $pc='pp-unpaid';    $st='✗ Not Paid';         $sc='var(--error)'; }
    $col = (!empty($m['collection_date'])&&$m['collection_date']!=='0000-00-00') ? date('M j, Y',strtotime($m['collection_date'])) : 'TBD';
?>
<div class="pay-card <?= $cc ?>">
    <div class="pay-pos <?= $pc ?>"><?= $m['position'] ?></div>
    <div class="pay-info">
        <div class="pay-name"><?= htmlspecialchars($m['name']) ?></div>
        <div class="pay-sub">
            <code class="user-code-badge"><?= htmlspecialchars($m['user_code']??'') ?></code>
            &nbsp;<?= htmlspecialchars($m['phone']) ?>
            &nbsp;·&nbsp; <?= $m['total_paid'] ?> paid total
            &nbsp;·&nbsp; Collects: <?= $col ?>
        </div>
    </div>
    <div class="pay-st" style="color:<?= $sc ?>;"><?= $st ?></div>
    <?php if (!$m['paid_this_cycle']&&!$m['has_collected']&&!$m['has_pending']): ?>
    <a href="reminders.php?send=<?= $m['cid'] ?>&plan=<?= $plan_id ?>" class="btn-action btn-action--edit" style="font-size:.7rem;">Remind</a>
    <?php elseif ($m['has_pending']): ?>
    <a href="verify_cash.php" class="btn-action" style="font-size:.7rem;background:#FFFBEB;color:#B7860B;">Verify</a>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php elseif ($plan && empty($members)): ?>
<div class="empty-state"><p>No members have joined this plan yet.</p></div>
<?php else: ?>
<div style="text-align:center;padding:3rem;background:var(--white);border:1px solid var(--gray-light);border-radius:16px;">
    <p style="font-size:1rem;color:var(--gray-text);">👆 Select a plan above to see payment status.</p>
</div>
<?php endif; ?>

</div></main>
</body>
</html>
