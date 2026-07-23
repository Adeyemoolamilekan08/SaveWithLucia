<?php
// ============================================================
// admin/plans.php — REPLACE existing
// Fixed: HTTP 500 on edit, date saving correctly, INSERT type string
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$errors  = [];
$editing = null;
$flash   = null;

// ---- DELETE ----
if (isset($_GET['delete'])) {
    $did = intval($_GET['delete']);
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM contributions WHERE plan_id=$did")->fetch_assoc()['c'];
    if ($cnt > 0) {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Cannot delete — members have joined this plan.'];
    } else {
        $conn->query("DELETE FROM plans WHERE id=$did");
        $_SESSION['flash'] = ['type'=>'success','message'=>'Plan deleted.'];
    }
    header("Location: plans.php"); exit();
}

// ---- CHANGE STATUS ----
if (isset($_GET['setstatus']) && isset($_GET['plan'])) {
    $sid    = intval($_GET['plan']);
    $status = $_GET['setstatus'];
    if (in_array($status, ['open','active','completed'])) {
        $conn->query("UPDATE plans SET plan_status='$status' WHERE id=$sid");
        if ($status === 'active') {
            recalculatePlanDates($conn, $sid);
        }
        $_SESSION['flash'] = ['type'=>'success','message'=>'Plan status changed to '.ucfirst($status).'.'];
    }
    header("Location: plans.php"); exit();
}

// ---- LOAD FOR EDIT ----
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $se  = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $se->bind_param("i", $eid);
    $se->execute();
    $editing = $se->get_result()->fetch_assoc();
    $se->close();
    if (!$editing) {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Plan not found.'];
        header("Location: plans.php"); exit();
    }
}

// ---- SAVE (CREATE or UPDATE) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_id    = intval($_POST['plan_id'] ?? 0);
    $name       = trim($_POST['name']               ?? '');
    $desc       = trim($_POST['description']         ?? '');
    $amount     = floatval($_POST['contribution_amount'] ?? 0);
    $freq       = intval($_POST['frequency_days']    ?? 7);
    $total      = intval($_POST['total_participants']?? 5);
    $start_raw  = trim($_POST['plan_start_date']     ?? '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    // Clean the date — if empty or invalid set to NULL
    $start_date = null;
    if (!empty($start_raw) && strtotime($start_raw) > 0) {
        $start_date = date('Y-m-d', strtotime($start_raw));
        // Block past dates
        if ($start_date < date('Y-m-d')) {
            $errors[] = "Start date cannot be in the past. Please select today or a future date.";
            $start_date = null;
        }
    }

    // Validation
    if (empty($name))    $errors[] = "Plan name is required.";
    if ($amount <= 0)    $errors[] = "Contribution amount must be greater than zero.";
    if ($freq   <= 0)    $errors[] = "Frequency days must be at least 1.";
    if ($total  <= 1)    $errors[] = "A group needs at least 2 participants.";

    if (empty($errors)) {
        if ($plan_id > 0) {
            // UPDATE existing plan
            $upd = $conn->prepare(
                "UPDATE plans
                 SET name = ?,
                     description = ?,
                     contribution_amount = ?,
                     frequency_days = ?,
                     total_participants = ?,
                     plan_start_date = ?,
                     is_active = ?
                 WHERE id = ?"
            );
            // Correct types: s=name, s=desc, d=amount, i=freq, i=total, s=start_date, i=is_active, i=plan_id
            $upd->bind_param("ssdiisii",
                $name, $desc, $amount, $freq, $total,
                $start_date, $is_active, $plan_id
            );
            $upd->execute();
            $upd->close();

            // Recalculate collection dates for all members
            recalculatePlanDates($conn, $plan_id);

            // Auto-activate if full and has start date
            $filled = getPlanMemberCount($conn, $plan_id);
            if ($start_date && $filled >= $total) {
                $conn->query("UPDATE plans SET plan_status='active' WHERE id=$plan_id AND plan_status='open'");
            }

            $_SESSION['flash'] = ['type'=>'success','message'=>'Plan updated. Collection dates recalculated.'];
            header("Location: plans.php"); exit();

        } else {
            // INSERT new plan
            $ins = $conn->prepare(
                "INSERT INTO plans
                    (name, description, contribution_amount, frequency_days, total_participants, plan_start_date, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            // Correct types: s=name, s=desc, d=amount, i=freq, i=total, s=start_date, i=is_active
            $ins->bind_param("ssdiisi",
                $name, $desc, $amount, $freq, $total,
                $start_date, $is_active
            );
            $ins->execute();
            $ins->close();

            $_SESSION['flash'] = ['type'=>'success','message'=>'Plan created. Members can now join.'];
            header("Location: plans.php"); exit();
        }
    }
}

// ---- LOAD ALL PLANS ----
$plans = $conn->query(
    "SELECT p.*,
        (SELECT COUNT(*) FROM contributions c
         WHERE c.plan_id = p.id AND c.status != 'removed') AS slots_filled
     FROM plans p
     ORDER BY FIELD(p.plan_status,'open','active','completed'), p.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// ---- FLASH MESSAGE ----
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Manage Plans — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260526">
<style>
.admin-form-panel input,
.admin-form-panel select,
.admin-form-panel textarea {
    max-width: 100%; box-sizing: border-box;
}
.plans-card-list { display: flex; flex-direction: column; gap: 1rem; }
.plan-row-card {
    background: var(--white); border: 1px solid var(--gray-light);
    border-radius: 12px; padding: 1.1rem 1.25rem; transition: var(--transition);
}
.plan-row-card:hover { border-color: var(--gold-light); }
.plan-row-card__top {
    display: flex; justify-content: space-between;
    align-items: flex-start; gap: .75rem; margin-bottom: .75rem;
}
.plan-row-card__name { font-weight: 700; font-size: 1rem; margin-bottom: .2rem; }
.plan-row-card__meta {
    display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem;
    font-size: .8rem; color: var(--gray-text); margin-bottom: .85rem;
    padding: .65rem .75rem; background: var(--gray-light); border-radius: 8px;
}
.plan-row-card__meta-item { display: flex; flex-direction: column; gap: .1rem; }
.plan-row-card__meta-label { font-size: .65rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
.plan-row-card__meta-value { font-size: .82rem; color: var(--black); font-weight: 500; }
.plan-row-card__meta-value--gold { color: var(--gold); }
.plan-row-card__meta-value--red  { color: var(--error); }
.plan-row-card__actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.plan-row-card__actions a { flex: 1; min-width: 70px; text-align: center; padding: .4rem .5rem; font-size: .78rem; }
.editing-highlight { border-color: var(--gold) !important; box-shadow: 0 0 0 3px rgba(201,168,76,.15); }
@media (max-width: 640px) {
    .admin-two-col { grid-template-columns: 1fr !important; }
}
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Manage Plans</h1>
    <p>Create plans, set start dates, and control when each group opens and closes.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>">
    <p><?= htmlspecialchars($flash['message']) ?></p>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-info-box" style="margin-bottom:1.5rem;">
    <strong>Plan Lifecycle:</strong>
    <strong style="color:#4338CA;">Open</strong> — accepting members →
    <strong style="color:var(--gold);">Active</strong> — started, locked →
    <strong style="color:var(--success);">Completed</strong> — all collected.
</div>

<div class="admin-two-col">

    <!-- ====================================================
         CREATE / EDIT FORM
         ==================================================== -->
    <div class="admin-form-panel <?= $editing ? 'editing-highlight' : '' ?>" id="planForm">
        <h2><?= $editing ? '✏ Edit: '.htmlspecialchars($editing['name']) : 'Create New Plan' ?></h2>

        <form method="POST" action="plans.php">
            <input type="hidden" name="plan_id" value="<?= $editing['id'] ?? 0 ?>">

            <div class="form-group">
                <label>Plan Name</label>
                <input type="text" name="name"
                       placeholder="e.g. Weekly Ajo — 5 People"
                       value="<?= htmlspecialchars($editing['name'] ?? $_POST['name'] ?? '') ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Description (optional)</label>
                <textarea name="description" rows="2"
                    placeholder="Short description shown to members"><?= htmlspecialchars($editing['description'] ?? $_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Contribution Amount (₦)</label>
                <input type="number" name="contribution_amount"
                       step="0.01" min="1"
                       placeholder="e.g. 5000"
                       value="<?= htmlspecialchars($editing['contribution_amount'] ?? $_POST['contribution_amount'] ?? '') ?>"
                       required>
                <span class="form-hint">Each member pays this every cycle.</span>
            </div>

            <div class="form-group">
                <label>Frequency (Days between collections)</label>
                <input type="number" name="frequency_days"
                       min="1"
                       value="<?= htmlspecialchars($editing['frequency_days'] ?? $_POST['frequency_days'] ?? '7') ?>"
                       required>
                <span class="form-hint">1=daily · 7=weekly · 14=fortnightly · 30=monthly</span>
            </div>

            <div class="form-group">
                <label>Group Size (Total Participants)</label>
                <input type="number" name="total_participants"
                       min="2"
                       value="<?= htmlspecialchars($editing['total_participants'] ?? $_POST['total_participants'] ?? '5') ?>"
                       required>
                <span class="form-hint">Group locks when full. Payout = amount × size.</span>
            </div>

            <?php
            $today_str      = date('Y-m-d');
            $current_date   = $editing['plan_start_date'] ?? $_POST['plan_start_date'] ?? '';
            $date_is_past   = !empty($current_date)
                              && $current_date !== '0000-00-00'
                              && strtotime($current_date) > 0
                              && $current_date < $today_str;
            ?>
            <div class="form-group">
                <label>Plan Start Date</label>
                <input type="date" name="plan_start_date"
                       min="<?= $today_str ?>"
                       value="<?= htmlspecialchars($current_date) ?>">
                <?php if ($date_is_past): ?>
                <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;padding:.6rem .85rem;margin-top:.4rem;font-size:.82rem;color:var(--error);">
                    ⚠ The current start date <strong><?= date('M j, Y', strtotime($current_date)) ?></strong> is in the past.
                    Please select a new start date from today onwards.
                    Dates before today are not allowed.
                </div>
                <?php else: ?>
                <span class="form-hint">Position 1 collects on this date. All other dates calculate automatically. Cannot be set in the past.</span>
                <?php endif; ?>
            </div>

            <div class="form-group form-group--checkbox">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1"
                        <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                    Plan is visible to members
                </label>
            </div>

            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    <?= $editing ? 'Update Plan' : 'Create Plan' ?>
                </button>
                <?php if ($editing): ?>
                <a href="plans.php" class="btn btn-outline" style="flex:1;text-align:center;">
                    Cancel
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ====================================================
         ALL PLANS LIST
         ==================================================== -->
    <div class="admin-table-panel">
        <h2>All Plans (<?= count($plans) ?>)</h2>

        <?php if (empty($plans)): ?>
            <div class="empty-state"><p>No plans yet. Create your first plan on the left.</p></div>
        <?php else: ?>
        <div class="plans-card-list">
            <?php foreach ($plans as $p):
                $slots_left = intval($p['total_participants']) - intval($p['slots_filled']);
                $payout     = calculatePayoutAmount($p['contribution_amount'], $p['total_participants']);
                $collected  = getPlanCollectedCount($conn, $p['id']);
                $is_full    = $slots_left <= 0;

                // Show date or "Not set" — never show broken dates
                $start_display = 'Not set';
                if (!empty($p['plan_start_date'])
                    && $p['plan_start_date'] !== '0000-00-00'
                    && strtotime($p['plan_start_date']) > 0
                    && date('Y', strtotime($p['plan_start_date'])) > 1970) {
                    $start_display = date('M j, Y', strtotime($p['plan_start_date']));
                }

                $is_editing_this = $editing && $editing['id'] === $p['id'];
            ?>
            <div class="plan-row-card <?= $is_editing_this ? 'editing-highlight' : '' ?>">

                <div class="plan-row-card__top">
                    <div style="flex:1;">
                        <div class="plan-row-card__name"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:.75rem;color:var(--gold);">
                            Payout: <?= formatMoney($payout) ?> &bull;
                            <?= $collected ?>/<?= $p['total_participants'] ?> collected
                        </div>
                    </div>
                    <span class="status-badge status-badge--<?= $p['plan_status']==='active'?'active':($p['plan_status']==='completed'?'completed':'pending') ?>">
                        <?= ucfirst($p['plan_status']) ?>
                    </span>
                </div>

                <div class="plan-row-card__meta">
                    <div class="plan-row-card__meta-item">
                        <span class="plan-row-card__meta-label">Amount</span>
                        <span class="plan-row-card__meta-value plan-row-card__meta-value--gold">
                            <?= formatMoney($p['contribution_amount']) ?>
                        </span>
                    </div>
                    <div class="plan-row-card__meta-item">
                        <span class="plan-row-card__meta-label">Freq</span>
                        <span class="plan-row-card__meta-value"><?= formatFrequency($p['frequency_days']) ?></span>
                    </div>
                    <div class="plan-row-card__meta-item">
                        <span class="plan-row-card__meta-label">Slots</span>
                        <span class="plan-row-card__meta-value <?= $is_full?'plan-row-card__meta-value--red':'' ?>">
                            <?= $p['slots_filled'] ?>/<?= $p['total_participants'] ?>
                            <?= $is_full ? ' 🔒' : '' ?>
                        </span>
                    </div>
                    <div class="plan-row-card__meta-item">
                        <span class="plan-row-card__meta-label">Start Date</span>
                        <span class="plan-row-card__meta-value <?= $start_display==='Not set'?'plan-row-card__meta-value--red':'' ?>">
                            <?= $start_display ?>
                        </span>
                    </div>
                    <div class="plan-row-card__meta-item">
                        <span class="plan-row-card__meta-label">Visible</span>
                        <span class="plan-row-card__meta-value"><?= $p['is_active'] ? 'Yes' : 'No' ?></span>
                    </div>
                </div>

                <div class="plan-row-card__actions">
                    <a href="plans.php?edit=<?= $p['id'] ?>"
                       class="btn-action btn-action--edit">✏ Edit</a>

                    <a href="rotation.php?plan=<?= $p['id'] ?>"
                       class="btn-action btn-action--view">⟳ Rotation</a>

                    <?php if ($p['plan_status'] === 'open'): ?>
                    <a href="plans.php?setstatus=active&plan=<?= $p['id'] ?>"
                       class="btn-action"
                       style="background:#EDF7F1;color:var(--success);"
                       onclick="return confirm('Start this plan? No new members can join after this.')">
                       ▶ Start
                    </a>
                    <?php endif; ?>

                    <a href="plans.php?delete=<?= $p['id'] ?>"
                       class="btn-action btn-action--delete"
                       onclick="return confirm('Delete this plan? This cannot be undone.')">
                       ✕ Delete
                    </a>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- end admin-two-col -->

</div></main>

<script>
// Auto-scroll to form when editing
<?php if ($editing): ?>
window.addEventListener('load', function() {
    var form = document.getElementById('planForm');
    if (form) {
        setTimeout(function() {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 300);
    }
});
<?php endif; ?>
</script>

</body>
</html>
