<?php
// ============================================================
// FILE: admin/plans.php
// INSTRUCTION: REPLACE your existing admin/plans.php with this
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$errors = []; $editing = null;

if (isset($_GET['delete'])) {
    $did = intval($_GET['delete']);
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM contributions WHERE plan_id=$did")->fetch_assoc()['c'];
    if ($cnt>0) { setFlash('error','Cannot delete — members have joined this plan.'); }
    else { $conn->query("DELETE FROM plans WHERE id=$did"); setFlash('success','Plan deleted.'); }
    header("Location: plans.php"); exit();
}

if (isset($_GET['edit'])) {
    $s=$conn->prepare("SELECT * FROM plans WHERE id=?");
    $s->bind_param("i",intval($_GET['edit']));$s->execute();
    $editing=$s->get_result()->fetch_assoc();$s->close();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name       = trim($_POST['name']??'');
    $desc       = trim($_POST['description']??'');
    $amount     = floatval($_POST['contribution_amount']??0);
    $freq       = intval($_POST['frequency_days']??7);
    $total      = intval($_POST['total_participants']??5);
    $start_date = trim($_POST['plan_start_date']??'') ?: null;
    $is_active  = isset($_POST['is_active'])?1:0;
    $plan_id    = intval($_POST['plan_id']??0);

    if (empty($name))  $errors[]="Plan name is required.";
    if ($amount<=0)    $errors[]="Contribution amount must be greater than zero.";
    if ($freq<=0)      $errors[]="Frequency days must be at least 1.";
    if ($total<=1)     $errors[]="A group needs at least 2 participants.";

    if (empty($errors)) {
        if ($plan_id>0) {
            $s=$conn->prepare("UPDATE plans SET name=?,description=?,contribution_amount=?,frequency_days=?,total_participants=?,plan_start_date=?,is_active=? WHERE id=?");
            $s->bind_param("ssdiiisi",$name,$desc,$amount,$freq,$total,$start_date,$is_active,$plan_id);
            $s->execute();$s->close();
            recalculatePlanDates($conn,$plan_id);
            setFlash('success','Plan updated. Collection dates recalculated for all members.');
        } else {
            $s=$conn->prepare("INSERT INTO plans (name,description,contribution_amount,frequency_days,total_participants,plan_start_date,is_active) VALUES (?,?,?,?,?,?,?)");
            $s->bind_param("ssdiiis",$name,$desc,$amount,$freq,$total,$start_date,$is_active);
            $s->execute();$s->close();
            setFlash('success','Plan created.');
        }
        header("Location: plans.php"); exit();
    }
}

$plans = $conn->query("SELECT p.*,(SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status!='removed') AS slots_filled FROM plans p ORDER BY p.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$flash = getFlash();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manage Plans — <?= SITE_NAME ?></title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="inner-page"><?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">
<div class="page-header"><h1>Manage Plans</h1></div>
<?php if($flash): ?><div class="alert alert-<?=$flash['type']?>"><p><?=htmlspecialchars($flash['message'])?></p></div><?php endif; ?>

<div class="admin-two-col">
  <div class="admin-form-panel">
    <h2><?=$editing?'Edit Plan':'Create New Plan'?></h2>
    <?php if(!empty($errors)): ?><div class="alert alert-error"><?php foreach($errors as $e): ?><p><?=htmlspecialchars($e)?></p><?php endforeach; ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="plan_id" value="<?=$editing['id']??0?>">
      <div class="form-group"><label>Plan Name</label><input type="text" name="name" placeholder="e.g. Weekly Ajo — 5 People" value="<?=htmlspecialchars($_POST['name']??$editing['name']??'')?>" required></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"><?=htmlspecialchars($_POST['description']??$editing['description']??'')?></textarea></div>
      <div class="form-group"><label>Contribution Amount (₦) per person</label><input type="number" name="contribution_amount" step="0.01" min="1" value="<?=htmlspecialchars($_POST['contribution_amount']??$editing['contribution_amount']??'')?>" required><small class="form-hint">Each member pays this each cycle.</small></div>
      <div class="form-group"><label>Frequency (Days between collections)</label><input type="number" name="frequency_days" min="1" value="<?=htmlspecialchars($_POST['frequency_days']??$editing['frequency_days']??'7')?>" required><small class="form-hint">1=daily · 7=weekly · 14=fortnightly · 30=monthly</small></div>
      <div class="form-group"><label>Total Participants (Group Size)</label><input type="number" name="total_participants" min="2" value="<?=htmlspecialchars($_POST['total_participants']??$editing['total_participants']??'5')?>" required><small class="form-hint">Payout = contribution × participants</small></div>
      <div class="form-group"><label>Plan Start Date</label><input type="date" name="plan_start_date" value="<?=htmlspecialchars($_POST['plan_start_date']??$editing['plan_start_date']??'')?>"><small class="form-hint">Date Position 1 collects. Changing this recalculates ALL dates.</small></div>
      <div class="form-group form-group--checkbox"><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?=(!isset($_POST['is_active'])&&($editing['is_active']??1))||isset($_POST['is_active'])?'checked':''?>>Plan is active (visible to members)</label></div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary"><?=$editing?'Update Plan':'Create Plan'?></button>
        <?php if($editing): ?><a href="plans.php" class="btn btn-outline">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="admin-table-panel">
    <h2>All Plans</h2>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Amount</th><th>Slots</th><th>Freq.</th><th>Start</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($plans as $p):
            $slots_left=$p['total_participants']-$p['slots_filled'];
            $payout=calculatePayoutAmount($p['contribution_amount'],$p['total_participants']);
            $collected=getPlanCollectedCount($conn,$p['id']);
          ?>
          <tr>
            <td><strong><?=htmlspecialchars($p['name'])?></strong><br><small style="color:var(--gold)">Payout: <?=formatMoney($payout)?> · <?=$collected?>/<?=$p['total_participants']?> collected</small></td>
            <td><?=formatMoney($p['contribution_amount'])?></td>
            <td><?=$p['slots_filled']?>/<?=$p['total_participants']?><small style="color:<?=$slots_left<=0?'var(--error)':'var(--gray-text)'?>">(<?=$slots_left<=0?'Full':$slots_left.' left'?>)</small></td>
            <td><?=formatFrequency($p['frequency_days'])?></td>
            <td style="font-size:.8rem"><?=$p['plan_start_date']?date('M j, Y',strtotime($p['plan_start_date'])):'Not set'?></td>
            <td><span class="status-badge status-badge--<?=$p['plan_status']==='active'?'active':($p['plan_status']==='completed'?'completed':'pending')?>"><?=ucfirst($p['plan_status'])?></span></td>
            <td><div style="display:flex;gap:.4rem;flex-wrap:wrap;">
              <a href="plans.php?edit=<?=$p['id']?>" class="btn-action btn-action--edit">Edit</a>
              <a href="rotation.php?plan=<?=$p['id']?>" class="btn-action btn-action--view">Rotation</a>
              <a href="plans.php?delete=<?=$p['id']?>" class="btn-action btn-action--delete" onclick="return confirm('Delete this plan?')">Delete</a>
            </div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div></main></body></html>
