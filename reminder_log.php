<?php
// ============================================================
// FILE: admin/reminder_log.php
// NEW FILE — copy into /swl/admin/reminder_log.php
// Shows full history of every reminder sent across all plans
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

// Filters
$filter_plan = intval($_GET['plan']   ?? 0);
$filter_type = trim($_GET['type']     ?? '');
$filter_date = trim($_GET['date']     ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$per         = 25;
$offset      = ($page - 1) * $per;

// Build WHERE
$where  = "WHERE 1=1";
$params = [];
$types  = '';

if ($filter_plan > 0) {
    $where  .= " AND rl.plan_id = ?";
    $params[]= $filter_plan;
    $types  .= 'i';
}
if (!empty($filter_type)) {
    $where  .= " AND rl.reminder_type = ?";
    $params[]= $filter_type;
    $types  .= 's';
}
if (!empty($filter_date)) {
    $where  .= " AND DATE(rl.sent_at) = ?";
    $params[]= $filter_date;
    $types  .= 's';
}

// Count
$count_sql = "SELECT COUNT(*) AS c FROM reminder_log rl $where";
$cs = $conn->prepare($count_sql);
if (!empty($params)) $cs->bind_param($types, ...$params);
$cs->execute();
$total_rows  = intval($cs->get_result()->fetch_assoc()['c']);
$cs->close();
$total_pages = max(1, ceil($total_rows / $per));

// Fetch
$sql = "SELECT rl.* FROM reminder_log rl $where ORDER BY rl.sent_at DESC LIMIT ? OFFSET ?";
$all_p = array_merge($params, [$per, $offset]);
$all_t = $types . 'ii';
$stmt  = $conn->prepare($sql);
$stmt->bind_param($all_t, ...$all_p);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary stats for today
$today = date('Y-m-d');
$stats = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='sent')   AS sent,
        SUM(status='failed') AS failed,
        COUNT(DISTINCT plan_id) AS plans_covered
     FROM reminder_log
     WHERE DATE(sent_at) = '$today'"
)->fetch_assoc();

// All plans for filter dropdown
$all_plans = $conn->query("SELECT id, name FROM plans ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$type_labels = [
    'before'          => 'Before (1 day warning)',
    'today_payer'     => 'Today — Payer',
    'today_collector' => 'Today — Collector',
    'late'            => 'Late Payment',
    'manual'          => 'Manual (Admin)',
];
$type_colors = [
    'before'          => '#B7860B',
    'today_payer'     => '#4338CA',
    'today_collector' => '#C9A84C',
    'late'            => '#C0392B',
    'manual'          => '#1E7E4A',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reminder Log — <?= SITE_NAME ?> Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.type-pill {
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
    padding: .2rem .6rem;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #fff;
}
.log-message {
    font-size: .8rem;
    color: var(--gray-text);
    max-width: 380px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-mini {
    text-align: center;
    padding: 1rem;
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 12px;
    min-width: 110px;
}
.stat-mini__num   { font-family: var(--font-head); font-size: 1.9rem; font-weight: 600; display: block; }
.stat-mini__label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--gray-text); display: block; margin-top: .15rem; }
</style>
<style>
@media (max-width: 640px) {
    .type-pill { font-size: .62rem; padding: .15rem .45rem; }
    .log-message { max-width: 180px; font-size: .75rem; }
    div[style*="display:flex"][style*="gap:1rem"] { flex-wrap: wrap; gap: .5rem !important; }
    .stat-mini { min-width: 60px; flex: 1; }
}
</style></head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Reminder Log</h1>
    <p>Full history of every reminder sent by the system across all plans.</p>
</div>

<!-- Today's stats -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2rem;">
    <div class="stat-mini">
        <span class="stat-mini__num" style="color:var(--gold);"><?= $stats['total'] ?? 0 ?></span>
        <span class="stat-mini__label">Sent Today</span>
    </div>
    <div class="stat-mini">
        <span class="stat-mini__num" style="color:var(--success);"><?= $stats['sent'] ?? 0 ?></span>
        <span class="stat-mini__label">Delivered</span>
    </div>
    <div class="stat-mini">
        <span class="stat-mini__num" style="color:var(--error);"><?= $stats['failed'] ?? 0 ?></span>
        <span class="stat-mini__label">Failed</span>
    </div>
    <div class="stat-mini">
        <span class="stat-mini__num"><?= $stats['plans_covered'] ?? 0 ?></span>
        <span class="stat-mini__label">Plans Covered</span>
    </div>
    <div class="stat-mini">
        <span class="stat-mini__num"><?= $total_rows ?></span>
        <span class="stat-mini__label">Total Records</span>
    </div>
</div>

<!-- Filters -->
<div class="search-filter-bar" style="margin-bottom:1.5rem;">
    <form method="GET" class="search-form">
        <select name="plan" class="filter-select">
            <option value="">All Plans</option>
            <?php foreach ($all_plans as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filter_plan===$p['id']?'selected':'' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="filter-select">
            <option value="">All Types</option>
            <?php foreach ($type_labels as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $filter_type===$val?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" class="search-input" style="max-width:180px;"
               value="<?= htmlspecialchars($filter_date) ?>" placeholder="Filter by date">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filter_plan || $filter_type || $filter_date): ?>
            <a href="reminder_log.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Log table -->
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Plan</th>
                <th>Member</th>
                <th>Type</th>
                <th>Channel</th>
                <th>Status</th>
                <th>Message Preview</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:3rem;color:var(--gray-text);">
                        No reminders logged yet. Run the cron job to generate reminders.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-size:.82rem;white-space:nowrap;">
                        <?= date('M j, Y', strtotime($log['sent_at'])) ?><br>
                        <span style="color:var(--gray-text);"><?= date('g:i A', strtotime($log['sent_at'])) ?></span>
                    </td>
                    <td style="font-size:.85rem;"><?= htmlspecialchars($log['plan_name']) ?></td>
                    <td>
                        <strong style="font-size:.85rem;"><?= htmlspecialchars($log['user_name']) ?></strong>
                    </td>
                    <td>
                        <span class="type-pill" style="background:<?= $type_colors[$log['reminder_type']] ?? '#888' ?>">
                            <?= $type_labels[$log['reminder_type']] ?? $log['reminder_type'] ?>
                        </span>
                    </td>
                    <td style="font-size:.82rem;text-transform:uppercase;">
                        <?= htmlspecialchars($log['channel']) ?>
                    </td>
                    <td>
                        <span class="status-badge status-badge--<?= $log['status']==='sent'?'active':'failed' ?>">
                            <?= $log['status'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="log-message" title="<?= htmlspecialchars($log['message'] ?? '') ?>">
                            <?= htmlspecialchars(substr($log['message'] ?? '—', 0, 80)) ?>
                            <?= strlen($log['message'] ?? '') > 80 ? '…' : '' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?plan=<?= $filter_plan ?>&type=<?= urlencode($filter_type) ?>&date=<?= urlencode($filter_date) ?>&page=<?= $i ?>"
           class="page-btn <?= $i === $page ? 'page-btn--active' : '' ?>">
            <?= $i ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- How to run -->
<div class="admin-info-box" style="margin-top:2rem;">
    <strong>How to run reminders:</strong><br>
    <strong>Manual test:</strong>
    <code>http://localhost/swl/cron/reminders.php?secret=<?= defined('CRON_SECRET') ? CRON_SECRET : 'SWL_lucia_2024_Xk9mPq3z' ?></code><br>
    <strong>Automatic (cPanel Cron Jobs):</strong>
    Time: <code>0 8 * * *</code> &nbsp;
    Command: <code>php /home/yourusername/public_html/swl/cron/reminders.php</code>
</div>

</div></main>
</body>
</html>
