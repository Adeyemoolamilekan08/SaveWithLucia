<?php
// ============================================================
// FILE: admin/rotation.php
// INSTRUCTION: REPLACE your existing admin/rotation.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$plan_id   = intval($_GET['plan']??0);
$all_plans = $conn->query("SELECT * FROM plans WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$plan      = null; $members = [];

if ($plan_id>0) {
    $s=$conn->prepare("SELECT * FROM plans WHERE id=?"); $s->bind_param("i",$plan_id); $s->execute();
    $plan=$s->get_result()->fetch_assoc(); $s->close();
    if ($plan) $members=getPlanMembers($conn,$plan_id);
}
$flash=getFlash();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Rotation — <?=SITE_NAME?></title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="inner-page"><?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">
<div class="page-header"><h1>Rotation Schedule</h1><p>Full rotation order and collection dates for each plan.</p></div>
<?php if($flash): ?><div class="alert alert-<?=$flash['type']?>" style="margin-bottom:1.5rem;"><p><?=htmlspecialchars($flash['message'])?></p></div><?php endif; ?>

<div class="search-filter-bar" style="margin-bottom:2rem;">
  <form method="GET" class="search-form">
    <select name="plan" class="filter-select" onchange="this.form.submit()" style="min-width:280px;">
      <option value="">— Select a Plan —</option>
      <?php foreach($all_plans as $p): ?>
        <option value="<?=$p['id']?>" <?=$plan_id===$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?> (<?=$p['plan_status']?>)</option>
      <?php endforeach; ?>
    </select>
    <?php if($plan_id&&$plan): ?><a href="export.php?plan=<?=$plan_id?>" class="btn btn-outline">Export CSV</a><?php endif; ?>
  </form>
</div>

<?php if ($plan): ?>
<div class="rotation-plan-summary">
  <div><strong><?=htmlspecialchars($plan['name'])?></strong></div>
  <div>Contribution: <strong><?=formatMoney($plan['contribution_amount'])?></strong></div>
  <div>Payout: <strong style="color:var(--gold)"><?=formatMoney(calculatePayoutAmount($plan['contribution_amount'],$plan['total_participants']))?></strong></div>
  <div>Frequency: <strong><?=formatFrequency($plan['frequency_days'])?></strong></div>
  <div>Members: <strong><?=count($members)?>/<?=$plan['total_participants']?></strong></div>
  <div>Collected: <strong><?=getPlanCollectedCount($conn,$plan_id)?>/<?=$plan['total_participants']?></strong></div>
  <div>Start Date: <strong><?=$plan['plan_start_date']?date('M j, Y',strtotime($plan['plan_start_date'])):'<span style="color:var(--error)">Not set</span>'?></strong></div>
  <div>Status: <span class="status-badge status-badge--<?=$plan['plan_status']==='active'?'active':($plan['plan_status']==='completed'?'completed':'pending')?>"><?=ucfirst($plan['plan_status'])?></span></div>
</div>

<?php if(!$plan['plan_start_date']): ?>
<div class="alert alert-error" style="margin:1rem 0;"><p><strong>Start date not set.</strong> <a href="plans.php?edit=<?=$plan_id?>">Edit Plan</a> to set the start date. Collection dates will be calculated automatically.</p></div>
<?php endif; ?>

<?php if(empty($members)): ?>
  <div class="empty-state"><p>No members have joined this plan yet.</p></div>
<?php else: ?>
<div class="table-wrapper">
  <table class="data-table">
    <thead><tr><th>Position</th><th>Member ID</th><th>Name</th><th>Phone</th><th>Collection Date</th><th>Days Until</th><th>Payout</th><th>Status</th></tr></thead>
    <tbody>
      <?php $today=date('Y-m-d'); foreach($members as $m):
        $rot_status=getRotationStatus($m['collection_date'],$m['has_collected']);
        $days_left=getDaysUntilCollection($m['collection_date']);
        $is_today=$m['collection_date']===$today;
      ?>
      <tr class="<?=$is_today&&!$m['has_collected']?'row-today':''?>">
        <td><div class="position-circle <?=$m['has_collected']?'position-circle--done':($is_today?'position-circle--today':'')?>"><?=$m['position']?></div></td>
        <td><code class="user-code-badge"><?=htmlspecialchars($m['user_code']??'—')?></code></td>
        <td><strong><?=htmlspecialchars($m['user_name'])?></strong><br><small style="color:var(--gray-text)"><?=htmlspecialchars($m['email'])?></small></td>
        <td><?=htmlspecialchars($m['phone'])?></td>
        <td><?php if($m['collection_date']): ?><strong><?=date('M j, Y',strtotime($m['collection_date']))?></strong><?php if($is_today&&!$m['has_collected']): ?><span class="rotation-badge rotation-badge--today" style="margin-left:6px;">TODAY!</span><?php endif; ?><?php else: ?><span style="color:var(--gray-text)">TBD</span><?php endif; ?></td>
        <td><?php if($m['has_collected']): ?><span style="color:var(--success)">Done</span><?php elseif($m['collection_date']): ?><?php if($is_today): ?><strong style="color:var(--error)">TODAY</strong><?php elseif($m['collection_date']<$today): ?><strong style="color:var(--error)">Overdue</strong><?php else: ?><?=$days_left?> day(s)<?php endif; ?><?php else: ?>—<?php endif; ?></td>
        <td><strong style="color:var(--gold)"><?=formatMoney($m['payout_amount'])?></strong></td>
        <td><?php if($m['has_collected']): ?><span class="status-badge status-badge--completed">Collected</span><?php elseif($is_today): ?><span class="status-badge status-badge--today">Due Today</span><a href="payout.php?mark=<?=$m['id']?>" class="btn-action btn-action--edit" style="margin-left:4px;" onclick="return confirm('Mark payout done for <?=addslashes(htmlspecialchars($m['user_name']))?>?')">Mark Paid</a><?php elseif($rot_status==='upcoming'): ?><span class="status-badge status-badge--upcoming">Soon</span><?php else: ?><span class="status-badge status-badge--pending">Waiting</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php else: ?><div class="empty-state"><p>Select a plan above to see its full rotation schedule.</p></div><?php endif; ?>
</div></main></body></html>
