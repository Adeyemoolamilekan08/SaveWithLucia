<?php
// ============================================================
// FILE: admin/rotation.php — REPLACE existing
// Updated: Uses payout_schedule for accurate status
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$plan_id   = intval($_GET['plan'] ?? 0);
$all_plans = $conn->query(
    "SELECT p.*, (SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status!='removed') AS member_count
     FROM plans p WHERE p.is_active=1 ORDER BY FIELD(p.plan_status,'active','open','completed'), p.name"
)->fetch_all(MYSQLI_ASSOC);

$plan = null; $members = [];

if ($plan_id > 0) {
    $s = $conn->prepare("SELECT * FROM plans WHERE id=?");
    $s->bind_param("i", $plan_id); $s->execute();
    $plan = $s->get_result()->fetch_assoc(); $s->close();
    if ($plan) $members = getPlanMembers($conn, $plan_id);
}

$collected_count = $plan_id ? getPlanCollectedCount($conn, $plan_id) : 0;
$today           = date('Y-m-d');
$flash           = getFlash();
$plan_end        = ($plan && $plan['plan_start_date'])
    ? calculatePlanEndDate($plan['plan_start_date'], $plan['frequency_days'], $plan['total_participants'])
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Rotation — <?= SITE_NAME ?> Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<style>
.rotation-legend {
    display: flex; gap: 1rem; flex-wrap: wrap;
    font-size: .78rem; color: var(--gray-text);
    margin-bottom: 1rem; align-items: center;
}
.leg-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: .3rem; }
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Rotation Schedule</h1>
    <p>Full rotation order and collection dates for each plan. Each plan is independent.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><p><?= htmlspecialchars($flash['message']) ?></p></div>
<?php endif; ?>

<!-- Plan selector -->
<div class="search-filter-bar" style="margin-bottom:2rem;">
    <form method="GET" class="search-form">
        <select name="plan" class="filter-select" onchange="this.form.submit()" style="min-width:300px;">
            <option value="">— Select a Plan —</option>
            <?php foreach ($all_plans as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $plan_id===$p['id']?'selected':'' ?>>
                <?= htmlspecialchars($p['name']) ?>
                (<?= ucfirst($p['plan_status']) ?> · <?= $p['member_count'] ?>/<?= $p['total_participants'] ?> members)
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($plan_id && $plan): ?>
            <a href="export.php?plan=<?= $plan_id ?>" class="btn btn-outline">Export CSV</a>
            <a href="reminders.php?plan=<?= $plan_id ?>" class="btn btn-outline" style="color:var(--gold);border-color:var(--gold);">&#9993; Send Reminders</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($plan): ?>

<!-- Plan summary bar -->
<div class="rotation-plan-summary" style="margin-bottom:1.5rem;">
    <div><strong><?= htmlspecialchars($plan['name']) ?></strong></div>
    <div>Contribution: <strong><?= formatMoney($plan['contribution_amount']) ?></strong>/cycle</div>
    <div>Payout: <strong style="color:var(--gold)"><?= formatMoney(calculatePayoutAmount($plan['contribution_amount'], $plan['total_participants'])) ?></strong></div>
    <div>Frequency: <strong><?= formatFrequency($plan['frequency_days']) ?></strong></div>
    <div>Members: <strong><?= count($members) ?>/<?= $plan['total_participants'] ?></strong></div>
    <div>Collected: <strong><?= $collected_count ?>/<?= $plan['total_participants'] ?></strong></div>
    <div>Starts: <strong><?= $plan['plan_start_date'] ? date('M j, Y', strtotime($plan['plan_start_date'])) : '<span style="color:var(--error)">Not set</span>' ?></strong></div>
    <?php if ($plan_end): ?>
    <div>Ends: <strong><?= date('M j, Y', strtotime($plan_end)) ?></strong></div>
    <?php endif; ?>
    <div><span class="status-badge status-badge--<?= $plan['plan_status']==='active'?'active':($plan['plan_status']==='completed'?'completed':'pending') ?>"><?= ucfirst($plan['plan_status']) ?></span></div>
</div>

<?php if (!$plan['plan_start_date']): ?>
<div class="alert alert-error" style="margin-bottom:1rem;">
    <p><strong>Start date not set.</strong> <a href="plans.php?edit=<?= $plan_id ?>">Edit this plan</a> to set a start date. Collection dates will be calculated automatically for all members.</p>
</div>
<?php endif; ?>

<!-- Progress bar -->
<?php
$pct = $plan['total_participants'] > 0 ? round(($collected_count / $plan['total_participants']) * 100) : 0;
?>
<div style="margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;font-size:.82rem;color:var(--gray-text);margin-bottom:.4rem;">
        <span><?= $collected_count ?> of <?= $plan['total_participants'] ?> members have collected their payout</span>
        <span><?= $pct ?>%</span>
    </div>
    <div class="progress-bar-track" style="height:10px;">
        <div class="progress-bar-fill <?= $pct>=100?'progress-bar-fill--complete':'' ?>" style="width:<?= $pct ?>%"></div>
    </div>
</div>

<!-- Legend -->
<div class="rotation-legend">
    <span>Row key:</span>
    <span><span class="leg-dot" style="background:#1E7E4A;"></span> Collected payout</span>
    <span><span class="leg-dot" style="background:var(--gold);"></span> Collection today</span>
    <span><span class="leg-dot" style="background:#C0392B;"></span> Collection overdue</span>
    <span><span class="leg-dot" style="background:#F59E0B;"></span> Collecting soon (3 days)</span>
    <span><span class="leg-dot" style="background:var(--gray-mid);"></span> Waiting</span>
</div>

<?php if (empty($members)): ?>
<div class="empty-state"><p>No members have joined this plan yet.</p></div>
<?php else: ?>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Position</th>
                <th>Member ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Collection Date</th>
                <th>Days Until</th>
                <th>Payout Amount</th>
                <th>Cycles Paid</th>
                <th>Next Payment</th>
                <th>Payout Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($members as $m):
                $rot_status = getRotationStatus($m['collection_date'], $m['has_collected']);
                $days_left  = getDaysUntilCollection($m['collection_date']);
                $is_today   = $m['collection_date'] === $today;
                $is_overdue = $m['collection_date'] && $m['collection_date'] < $today && !$m['has_collected'];
                $is_soon    = !$is_today && !$is_overdue && $m['collection_date']
                              && $m['collection_date'] <= date('Y-m-d', strtotime('+3 days'))
                              && !$m['has_collected'];

                // Row highlight colour
                if ($m['has_collected'])  $row_style = 'background:#F0FAF4;';
                elseif ($is_today)        $row_style = 'background:#FFFBEB;';
                elseif ($is_overdue)      $row_style = 'background:#FEF2F2;';
                elseif ($is_soon)         $row_style = 'background:#FFFBEB;';
                else                      $row_style = '';

                $cycles_paid = intval($m['total_cycles_paid'] ?? 0);
                $next_pay    = $m['next_payment_date'] ?? null;
            ?>
            <tr style="<?= $row_style ?>">
                <td>
                    <div class="position-circle <?= $m['has_collected']?'position-circle--done':($is_today?'position-circle--today':'') ?>">
                        <?= $m['position'] ?>
                    </div>
                </td>
                <td><code class="user-code-badge"><?= htmlspecialchars($m['user_code']??'—') ?></code></td>
                <td>
                    <strong><?= htmlspecialchars($m['user_name']) ?></strong><br>
                    <small style="color:var(--gray-text)"><?= htmlspecialchars($m['email']) ?></small>
                </td>
                <td style="font-size:.85rem;"><?= htmlspecialchars($m['phone']) ?></td>
                <td>
                    <?php if ($m['collection_date']): ?>
                        <strong><?= date('M j, Y', strtotime($m['collection_date'])) ?></strong>
                        <?php if ($is_today && !$m['has_collected']): ?>
                            <span class="rotation-badge rotation-badge--today" style="margin-left:4px;">TODAY!</span>
                        <?php elseif ($is_overdue): ?>
                            <span class="rotation-badge rotation-badge--soon">Overdue</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--gray-text)">TBD</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($m['has_collected']): ?>
                        <span style="color:var(--success)">Done</span>
                    <?php elseif ($m['collection_date']): ?>
                        <?php if ($is_today): ?>
                            <strong style="color:var(--error)">TODAY</strong>
                        <?php elseif ($is_overdue): ?>
                            <strong style="color:var(--error)">Overdue</strong>
                        <?php else: ?>
                            <?= $days_left ?> day(s)
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><strong style="color:var(--gold)"><?= formatMoney($m['payout_amount']) ?></strong></td>
                <td style="text-align:center;">
                    <strong><?= $cycles_paid ?></strong>
                    <span style="color:var(--gray-text);font-size:.78rem;">/<?= $plan['total_participants'] ?></span>
                </td>
                <td style="font-size:.82rem;">
                    <?php if ($next_pay): ?>
                        <?= date('M j, Y', strtotime($next_pay)) ?>
                        <?php if ($next_pay < $today): ?>
                            <span style="color:var(--error);font-weight:600;"> !</span>
                        <?php endif; ?>
                    <?php elseif ($cycles_paid >= $plan['total_participants']): ?>
                        <span style="color:var(--success)">All paid ✓</span>
                    <?php else: ?>
                        <span style="color:var(--gray-text)">TBD</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($m['has_collected']): ?>
                        <span class="status-badge status-badge--completed">Collected</span>
                    <?php elseif ($is_today): ?>
                        <span class="status-badge status-badge--today">Due Today</span>
                        <a href="payout.php?mark=<?= $m['id'] ?>"
                           class="btn-action btn-action--edit" style="margin-left:4px;"
                           onclick="return confirm('Mark payout done for <?= addslashes(htmlspecialchars($m['user_name'])) ?>?')">
                           Mark Paid
                        </a>
                    <?php elseif ($is_soon): ?>
                        <span class="status-badge status-badge--upcoming">Soon</span>
                    <?php else: ?>
                        <span class="status-badge status-badge--pending">Waiting</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
<?php else: ?>
<div class="empty-state" style="background:var(--white);border:1px solid var(--gray-light);border-radius:16px;">
    <p style="font-size:1rem;">&#8635; Select a plan above to see its full rotation schedule.</p>
</div>
<?php endif; ?>

</div></main>
</body>
</html>
