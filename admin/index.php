<?php
// ============================================================
// FILE: admin/index.php — REPLACE existing
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$today = date('Y-m-d');

// Stats
$total_users     = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];
$active_members  = $conn->query("SELECT COUNT(*) AS c FROM contributions WHERE status='active'")->fetch_assoc()['c'];
$pending_cash    = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
$total_paid      = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='paid'")->fetch_assoc()['t'];
$completed_plans = $conn->query("SELECT COUNT(*) AS c FROM plans WHERE plan_status='completed'")->fetch_assoc()['c'];
$total_collected = $conn->query("SELECT COUNT(*) AS c FROM contributions WHERE has_collected=1")->fetch_assoc()['c'];

// Reminder stats for today
$rem_today = $conn->query(
    "SELECT COUNT(*) AS c, SUM(status='sent') AS sent, SUM(status='failed') AS failed
     FROM reminder_log WHERE DATE(sent_at) = '$today'"
)->fetch_assoc();

// Today's collectors + upcoming
$todays   = getTodaysCollectors($conn);
$upcoming = getUpcomingCollectors($conn, 7);

// Per-plan summary
$plan_summary = $conn->query(
    "SELECT p.id, p.name, p.plan_status, p.cycle_type,
            p.contribution_amount, p.total_participants,
            p.total_collected_count, p.frequency_days,
            (SELECT COUNT(*) FROM contributions c
             WHERE c.plan_id=p.id AND c.status='active') AS member_count,
            (SELECT COUNT(*) FROM payments py
             JOIN contributions c ON py.contribution_id=c.id
             WHERE c.plan_id=p.id AND py.status='paid'
               AND DATE(py.paid_at)=CURDATE()) AS paid_today
     FROM plans p
     WHERE p.is_active=1
     ORDER BY FIELD(p.plan_status,'active','open','completed'), p.id"
)->fetch_all(MYSQLI_ASSOC);

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin Dashboard — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<style>
.plan-overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}
.plan-overview-card {
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 14px;
    padding: 1.25rem;
    transition: var(--transition);
}
.plan-overview-card:hover { border-color: var(--gold); box-shadow: var(--shadow); }
.plan-overview-card__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: .75rem;
}
.plan-overview-card h3 { font-size: 1rem; }
.plan-overview-card__stats {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: .5rem;
    margin-top: .75rem;
}
.plan-stat { text-align: center; }
.plan-stat__num   { font-family: var(--font-head); font-size: 1.3rem; font-weight: 600; display: block; }
.plan-stat__label { font-size: .68rem; color: var(--gray-text); text-transform: uppercase; letter-spacing: .04em; }
.rem-today-box {
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
    margin-bottom: 2rem;
    font-size: .9rem;
}
.rem-today-box strong { color: #1E40AF; }

@media (max-width: 640px) {
    .plan-overview-grid { grid-template-columns: 1fr; }
    .rem-today-box { flex-direction: column; gap: .75rem; }
    .plan-overview-card__stats { grid-template-columns: repeat(3,1fr); }
    .stats-grid { grid-template-columns: 1fr 1fr !important; gap: .65rem !important; }
    .admin-quick-links { grid-template-columns: repeat(3,1fr); gap: .5rem; }
    .quick-link-card { padding: .7rem .4rem; font-size: .68rem; }
}
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= htmlspecialchars($flash['message']) ?></p>
</div>
<?php endif; ?>

<div class="page-header">
    <h1>Admin Dashboard</h1>
    <p><?= date('l, F j, Y') ?></p>
</div>

<!-- Key stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:2rem;">
    <div class="stat-card"><span class="stat-card__label">Total Members</span><span class="stat-card__value"><?= $total_users ?></span><span class="stat-card__sub">Registered</span></div>
    <div class="stat-card"><span class="stat-card__label">Active Slots</span><span class="stat-card__value"><?= $active_members ?></span><span class="stat-card__sub">Across all plans</span></div>
    <div class="stat-card"><span class="stat-card__label">Members Collected</span><span class="stat-card__value"><?= $total_collected ?></span><span class="stat-card__sub">Payouts done</span></div>
    <div class="stat-card"><span class="stat-card__label">Plans Completed</span><span class="stat-card__value"><?= $completed_plans ?></span><span class="stat-card__sub">Fully done</span></div>
    <div class="stat-card"><span class="stat-card__label">Total Contributions</span><span class="stat-card__value stat-card__value--gold"><?= formatMoney($total_paid) ?></span><span class="stat-card__sub">All confirmed</span></div>
    <div class="stat-card"><span class="stat-card__label">Pending Cash</span><span class="stat-card__value <?= $pending_cash>0?'text-warning':'' ?>"><?= $pending_cash ?></span><span class="stat-card__sub"><a href="verify_cash.php" style="color:var(--gold)">Verify now</a></span></div>
</div>

<!-- Today's reminder activity -->
<?php if (intval($rem_today['c'] ?? 0) > 0): ?>
<div class="rem-today-box">
    <span>&#128140;</span>
    <div>
        <strong>Reminders sent today: <?= $rem_today['c'] ?></strong>
        &nbsp;&bull;&nbsp; ✓ Delivered: <?= $rem_today['sent'] ?>
        &nbsp;&bull;&nbsp; ✗ Failed: <?= $rem_today['failed'] ?>
    </div>
    <a href="reminder_log.php" class="btn btn-outline" style="padding:.4rem 1rem;font-size:.82rem;">View Log</a>
</div>
<?php endif; ?>

<!-- Quick links -->
<div class="admin-quick-links">
    <a href="plans.php"        class="quick-link-card"><span class="quick-link-card__icon">&#9776;</span><span>Plans</span></a>
    <a href="rotation.php"       class="quick-link-card"><span class="quick-link-card__icon">&#8635;</span><span>Rotation</span></a>
    <a href="assign_positions.php" class="quick-link-card" style="border-color:#4338CA;"><span class="quick-link-card__icon">&#9776;</span><span>Assign Positions</span></a>
    <a href="payout.php"       class="quick-link-card"><span class="quick-link-card__icon">&#127942;</span><span>Payouts</span></a>
    <a href="reminders.php"    class="quick-link-card" style="border-color:var(--gold);"><span class="quick-link-card__icon">&#9993;</span><span>Reminders</span></a>
    <a href="reminder_log.php" class="quick-link-card"><span class="quick-link-card__icon">&#128140;</span><span>Reminder Log</span></a>
    <a href="users.php"        class="quick-link-card"><span class="quick-link-card__icon">&#9786;</span><span>Users</span></a>
    <a href="add_member.php"   class="quick-link-card" style="border-color:var(--success);"><span class="quick-link-card__icon">&#43;</span><span>Add Member</span></a>
    <a href="verify_cash.php"  class="quick-link-card"><span class="quick-link-card__icon">&#10003;</span><span>Verify Cash</span></a>
    <a href="export.php"       class="quick-link-card"><span class="quick-link-card__icon">&#128190;</span><span>Export</span></a>
</div>

<!-- Per-plan overview -->
<section class="dashboard-section">
    <div class="section-header">
        <h2>All Plans — Live Overview</h2>
        <a href="plans.php" class="section-link">Manage plans</a>
    </div>
    <div class="plan-overview-grid">
        <?php foreach ($plan_summary as $p):
            $pct = $p['total_participants'] > 0
                ? round(($p['total_collected_count'] / $p['total_participants']) * 100) : 0;
            $payout = calculatePayoutAmount($p['contribution_amount'], $p['total_participants']);
        ?>
        <div class="plan-overview-card">
            <div class="plan-overview-card__header">
                <div>
                    <h3><?= htmlspecialchars($p['name']) ?></h3>
                    <span class="freq-badge"><?= formatFrequency($p['frequency_days']) ?></span>
                </div>
                <span class="status-badge status-badge--<?= $p['plan_status']==='active'?'active':($p['plan_status']==='completed'?'completed':'pending') ?>">
                    <?= ucfirst($p['plan_status']) ?>
                </span>
            </div>

            <div style="font-size:.85rem;color:var(--gray-text);margin-bottom:.6rem;">
                <?= formatMoney($p['contribution_amount']) ?>/cycle &bull;
                Payout: <strong style="color:var(--gold)"><?= formatMoney($payout) ?></strong>
            </div>

            <div class="progress-bar-track" style="margin-bottom:.4rem;">
                <div class="progress-bar-fill <?= $pct>=100?'progress-bar-fill--complete':'' ?>"
                     style="width:<?= $pct ?>%"></div>
            </div>

            <div class="plan-overview-card__stats">
                <div class="plan-stat">
                    <span class="plan-stat__num"><?= $p['member_count'] ?>/<?= $p['total_participants'] ?></span>
                    <span class="plan-stat__label">Members</span>
                </div>
                <div class="plan-stat">
                    <span class="plan-stat__num" style="color:var(--success);"><?= $p['total_collected_count'] ?></span>
                    <span class="plan-stat__label">Collected</span>
                </div>
                <div class="plan-stat">
                    <span class="plan-stat__num" style="color:var(--gold);"><?= $p['paid_today'] ?></span>
                    <span class="plan-stat__label">Paid Today</span>
                </div>
            </div>

            <div style="display:flex;gap:.5rem;margin-top:.85rem;flex-wrap:wrap;">
                <a href="rotation.php?plan=<?= $p['id'] ?>"  class="btn-action btn-action--view" style="font-size:.75rem;">Rotation</a>
                <a href="reminders.php?plan=<?= $p['id'] ?>" class="btn-action btn-action--edit" style="font-size:.75rem;background:#FDFAF3;color:#92400E;">Remind</a>
                <a href="reminder_log.php?plan=<?= $p['id'] ?>" class="btn-action" style="font-size:.75rem;background:#EFF6FF;color:#1E40AF;">Log</a>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($plan_summary)): ?>
            <div style="grid-column:1/-1;text-align:center;color:var(--gray-text);padding:2rem;">
                No plans yet. <a href="plans.php">Create your first plan.</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Today's collectors -->
<?php if (!empty($todays)): ?>
<div class="collection-today-banner" style="margin-bottom:2rem;">
    <div class="collection-today-banner__icon">&#127942;</div>
    <div>
        <strong><?= count($todays) ?> member(s) collecting TODAY!</strong>
        <p>Go to <a href="payout.php" style="color:var(--gold)">Payouts</a> to mark them as paid after handing over the money.</p>
    </div>
</div>
<?php endif; ?>

<section class="dashboard-section">
    <div class="section-header"><h2>Today's Collections</h2><a href="payout.php" class="section-link">Mark as paid</a></div>
    <?php if (empty($todays)): ?>
        <div class="empty-state"><p>No collections scheduled for today.</p></div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Member ID</th><th>Name</th><th>Plan</th><th>Position</th><th>Payout</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($todays as $t): ?>
                <tr>
                    <td><code class="user-code-badge"><?= htmlspecialchars($t['user_code']??'—') ?></code></td>
                    <td><strong><?= htmlspecialchars($t['user_name']) ?></strong><br><small><?= htmlspecialchars($t['phone']) ?></small></td>
                    <td><?= htmlspecialchars($t['plan_name']) ?></td>
                    <td>Position <?= $t['position'] ?></td>
                    <td class="amount-gold"><?= formatMoney($t['payout_amount']) ?></td>
                    <td>
                        <a href="payout.php?mark=<?= $t['id'] ?>"
                           class="btn-action btn-action--edit"
                           onclick="return confirm('Mark payout done for <?= addslashes(htmlspecialchars($t['user_name'])) ?>?')">
                           Mark Paid
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="dashboard-section">
    <div class="section-header"><h2>Upcoming Collections (Next 7 Days)</h2></div>
    <?php if (empty($upcoming)): ?>
        <div class="empty-state"><p>No collections in the next 7 days.</p></div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Member ID</th><th>Name</th><th>Plan</th><th>Position</th><th>Collection Date</th><th>Payout</th></tr></thead>
            <tbody>
                <?php foreach ($upcoming as $u): ?>
                <tr>
                    <td><code class="user-code-badge"><?= htmlspecialchars($u['user_code']??'—') ?></code></td>
                    <td><?= htmlspecialchars($u['user_name']) ?></td>
                    <td><?= htmlspecialchars($u['plan_name']) ?></td>
                    <td>Position <?= $u['position'] ?></td>
                    <td><?= date('M j, Y', strtotime($u['collection_date'])) ?></td>
                    <td><?= formatMoney($u['payout_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

</div></main>
</body>
</html>
