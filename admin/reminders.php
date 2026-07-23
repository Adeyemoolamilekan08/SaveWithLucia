<?php
// ============================================================
// FILE: admin/reminders.php
// NEW FILE — copy into /swl/admin/reminders.php
//
// WHAT THIS PAGE DOES:
//   - Shows every plan with all its members
//   - For each member shows: name, phone, payment status
//   - Admin can send a reminder to ONE member (click Send)
//   - Admin can send reminders to ALL unpaid members at once
//   - Message is sent via Email + SMS (if SMS_ENABLED=true)
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

requireAdmin();

$today = date('Y-m-d');

// ============================================================
// ACTION: Send reminder to ONE member
// Triggered when admin clicks "Send Reminder" on one row
// URL: reminders.php?send=CONTRIBUTION_ID
// ============================================================
if (isset($_GET['send']) && !isset($_GET['bulk'])) {
    $cid = intval($_GET['send']);

    // Load contribution + user + plan details
    $stmt = $conn->prepare(
        "SELECT c.id, c.position, c.collection_date, c.payout_amount,
                u.id AS uid, u.name, u.email, u.phone, u.user_code,
                p.name AS plan_name, p.contribution_amount,
                p.frequency_days, p.plan_start_date,
                (SELECT COUNT(*) FROM payments py
                 WHERE py.contribution_id = c.id AND py.status = 'paid') AS paid_count
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         WHERE c.id = ? AND c.status = 'active'"
    );
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        setFlash('error', 'Member not found.');
        header("Location: reminders.php"); exit();
    }

    // Work out next due date
    $due_date = $row['plan_start_date'] ?? $today;

    // Send email reminder (always) + SMS (if enabled)
    $result = sendReminderEmail(
        $conn,
        $row['uid'],
        $row['email'],
        $row['name'],
        $row['user_code'] ?? '',
        $row['plan_name'],
        $row['contribution_amount'],
        $due_date,
        'due_tomorrow',    // type — shows friendly "payment due" message
        $row['phone']
    );

    $email_ok = $result['email']['success'] ?? false;
    $sms_ok   = $result['sms']['success']   ?? false;
    $sms_err  = $result['sms']['error']     ?? null;

    $msg  = 'Reminder sent to ' . htmlspecialchars($row['name']) . '.';
    $msg .= ' Email: ' . ($email_ok ? '✓ sent' : '✗ failed');
    if (SMS_ENABLED) {
        $msg .= ' | SMS: ' . ($sms_ok ? '✓ sent' : '✗ failed');
        if (!$sms_ok && $sms_err) {
            $msg .= ' (Reason: ' . htmlspecialchars($sms_err) . ')';
            $msg .= ' — <a href="sms_debug.php" style="color:inherit;font-weight:bold;">Run SMS Debug Tool</a>';
        }
    }

    // Log it in reminders_sent so we can show "last sent" on the page
    $plan_id = intval($_GET['plan'] ?? 0);
    $conn->query(
        "INSERT IGNORE INTO reminders_sent
            (user_id, contribution_id, reminder_type, sent_date)
         VALUES ({$row['uid']}, $cid, 'manual_reminder', '$today')"
    );
    // Update to allow sending again (remove UNIQUE constraint hit)
    $conn->query(
        "UPDATE reminders_sent SET sent_at=NOW()
         WHERE contribution_id=$cid AND reminder_type='manual_reminder' AND sent_date='$today'"
    );

    setFlash($email_ok ? 'success' : 'error', $msg);
    $plan_id = intval($_GET['plan'] ?? 0);
    header("Location: reminders.php" . ($plan_id ? "?plan=$plan_id" : ''));
    exit();
}

// ============================================================
// ACTION: Send reminder to ALL unpaid members in a plan
// Triggered when admin clicks "Remind All Unpaid"
// URL: reminders.php?bulk=1&plan=PLAN_ID
// ============================================================
if (isset($_GET['bulk'], $_GET['plan'])) {
    $plan_id = intval($_GET['plan']);

    // Load all UNPAID active members in this plan
    $stmt = $conn->prepare(
        "SELECT c.id AS cid, c.position, c.payout_amount,
                u.id AS uid, u.name, u.email, u.phone, u.user_code,
                p.name AS plan_name, p.contribution_amount,
                p.frequency_days, p.plan_start_date
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         JOIN plans p ON c.plan_id = p.id
         WHERE c.plan_id = ?
           AND c.status = 'active'
           AND c.has_collected = 0
           AND (
               SELECT COUNT(*) FROM payments py
               WHERE py.contribution_id = c.id
                 AND py.status = 'paid'
                 AND py.paid_at >= DATE_SUB(NOW(), INTERVAL p.frequency_days DAY)
           ) = 0
         ORDER BY c.position ASC"
    );
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $unpaid_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($unpaid_members)) {
        setFlash('success', 'Everyone in this plan has paid. No reminders needed!');
        header("Location: reminders.php?plan=$plan_id"); exit();
    }

    $sent_count   = 0;
    $failed_count = 0;
    $names_sent   = [];
    $sms_fail_reason = null;

    foreach ($unpaid_members as $m) {
        $due_date = $m['plan_start_date'] ?? $today;

        $result = sendReminderEmail(
            $conn,
            $m['uid'],
            $m['email'],
            $m['name'],
            $m['user_code'] ?? '',
            $m['plan_name'],
            $m['contribution_amount'],
            $due_date,
            'due_tomorrow',
            $m['phone']
        );

        // Log in reminders_sent
        $conn->query(
            "INSERT INTO reminders_sent (user_id, contribution_id, reminder_type, sent_date)
             VALUES ({$m['uid']}, {$m['cid']}, 'manual_reminder', '$today')
             ON DUPLICATE KEY UPDATE sent_at=NOW()"
        );

        if ($result['email']['success'] ?? false) {
            $sent_count++;
            $names_sent[] = $m['name'];
        } else {
            $failed_count++;
        }

        // Log SMS failure reason on first failure only (for diagnosis)
        if (SMS_ENABLED && !($result['sms']['success'] ?? false) && empty($sms_fail_reason)) {
            $sms_fail_reason = $result['sms']['error'] ?? 'unknown';
        }
    }

    $msg = "Bulk reminder sent to $sent_count member(s).";
    if ($failed_count > 0) $msg .= " $failed_count email(s) failed.";
    if (!empty($names_sent)) $msg .= ' Notified: ' . implode(', ', array_map('htmlspecialchars', $names_sent)) . '.';
    if (SMS_ENABLED && $sms_fail_reason) {
        $msg .= ' SMS issue: ' . htmlspecialchars($sms_fail_reason) . '. <a href="sms_debug.php" style="color:inherit;font-weight:bold;">Debug SMS</a>';
    }

    setFlash($sent_count > 0 ? 'success' : 'error', $msg);
    header("Location: reminders.php?plan=$plan_id"); exit();
}

// ============================================================
// PAGE LOAD: Load plans and members for display
// ============================================================
$selected_plan_id = intval($_GET['plan'] ?? 0);

// All plans for the dropdown
$all_plans = $conn->query(
    "SELECT p.id, p.name, p.plan_status, p.frequency_days,
            p.contribution_amount, p.total_participants,
            p.plan_start_date,
            (SELECT COUNT(*) FROM contributions c
             WHERE c.plan_id = p.id AND c.status = 'active') AS member_count
     FROM plans p
     WHERE p.is_active = 1
     ORDER BY p.plan_status ASC, p.name ASC"
)->fetch_all(MYSQLI_ASSOC);

// If a plan is selected, load its members with payment status
$plan        = null;
$members     = [];
$unpaid_ids  = [];

if ($selected_plan_id > 0) {
    $s = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $s->bind_param("i", $selected_plan_id);
    $s->execute();
    $plan = $s->get_result()->fetch_assoc();
    $s->close();

    if ($plan) {
        // Load all active members with their last payment date
        $s2 = $conn->prepare(
            "SELECT
                c.id AS cid,
                c.position,
                c.collection_date,
                c.payout_amount,
                c.has_collected,
                c.payment_method,
                u.id AS uid,
                u.name,
                u.email,
                u.phone,
                u.user_code,
                u.status AS user_status,
                -- Count of ALL paid payments
                (SELECT COUNT(*) FROM payments py
                 WHERE py.contribution_id = c.id AND py.status = 'paid') AS total_payments_made,
                -- Most recent payment date
                (SELECT MAX(py.paid_at) FROM payments py
                 WHERE py.contribution_id = c.id AND py.status = 'paid') AS last_paid_at,
                -- Has paid in the current frequency window?
                (SELECT COUNT(*) FROM payments py
                 WHERE py.contribution_id = c.id
                   AND py.status = 'paid'
                   AND py.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS paid_this_cycle,
                -- Any pending cash payment?
                (SELECT COUNT(*) FROM payments py
                 WHERE py.contribution_id = c.id AND py.status = 'pending') AS has_pending,
                -- Last reminder sent today?
                (SELECT COUNT(*) FROM reminders_sent rs
                 WHERE rs.contribution_id = c.id
                   AND rs.reminder_type = 'manual_reminder'
                   AND rs.sent_date = CURDATE()) AS reminded_today
             FROM contributions c
             JOIN users u ON c.user_id = u.id
             WHERE c.plan_id = ? AND c.status = 'active'
             ORDER BY c.position ASC"
        );
        $freq = intval($plan['frequency_days']);
        $s2->bind_param("ii", $freq, $selected_plan_id);
        $s2->execute();
        $members = $s2->get_result()->fetch_all(MYSQLI_ASSOC);
        $s2->close();

        // Build list of unpaid member contribution IDs for bulk action
        foreach ($members as $m) {
            if (!$m['paid_this_cycle'] && !$m['has_collected'] && !$m['has_pending']) {
                $unpaid_ids[] = $m['cid'];
            }
        }
    }
}

$flash = getFlash();

// Counts for summary bar
$total_in_plan  = count($members);
$paid_count     = count(array_filter($members, fn($m) => $m['paid_this_cycle'] > 0 || $m['has_pending'] > 0));
$unpaid_count   = count($unpaid_ids);
$collected_count= count(array_filter($members, fn($m) => $m['has_collected']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Send Reminders — <?= SITE_NAME ?> Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<style>
/* ---- Reminder page specific styles ---- */
.reminder-plan-selector {
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.reminder-plan-selector h2 {
    font-size: 1.1rem;
    margin-bottom: 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--gray-light);
}
.plan-select-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
}
.plan-select-card {
    display: block;
    border: 2px solid var(--gray-light);
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    color: var(--black);
}
.plan-select-card:hover {
    border-color: var(--gold);
    background: #FDFAF3;
    transform: translateY(-1px);
    color: var(--black);
}
.plan-select-card.active {
    border-color: var(--gold);
    background: #FDFAF3;
    box-shadow: 0 0 0 3px rgba(201,168,76,.15);
}
.plan-select-card__name {
    font-family: var(--font-head);
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: .35rem;
}
.plan-select-card__meta {
    font-size: .78rem;
    color: var(--gray-text);
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
}
.plan-select-card__badge {
    font-size: .68rem;
    font-weight: 600;
    padding: .15rem .5rem;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .04em;
    display: inline-block;
    margin-top: .4rem;
}
.badge-open     { background: #EEF2FF; color: #4338CA; }
.badge-active   { background: #EDF7F1; color: #1E7E4A; }
.badge-completed{ background: var(--gray-light); color: var(--gray-text); }

/* Summary bar */
.reminder-summary {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
    background: var(--white);
    border: 1px solid var(--gray-light);
    border-radius: 12px;
    padding: 1.1rem 1.5rem;
    margin-bottom: 1.5rem;
    font-size: .9rem;
}
.reminder-summary__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .1rem;
    min-width: 70px;
}
.reminder-summary__num {
    font-family: var(--font-head);
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1;
}
.reminder-summary__label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--gray-text);
}
.reminder-summary__divider {
    width: 1px;
    height: 40px;
    background: var(--gray-light);
}
.num-gold    { color: var(--gold); }
.num-green   { color: var(--success); }
.num-red     { color: var(--error); }
.num-purple  { color: var(--purple); }

/* Bulk action bar */
.bulk-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    background: #FFFBEB;
    border: 1px solid #F59E0B;
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
}
.bulk-bar p { font-size: .9rem; color: var(--black); margin: 0; }
.bulk-bar strong { color: var(--error); }

/* Member table rows */
.member-row-paid    { background: #F0FAF4 !important; }
.member-row-pending { background: #FFFBEB !important; }
.member-row-unpaid  { background: #FEF2F2 !important; }
.member-row-collected { background: #F9FAFB !important; opacity: .75; }

/* Payment status pill */
.pay-pill {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .78rem;
    font-weight: 600;
    padding: .25rem .7rem;
    border-radius: 20px;
    white-space: nowrap;
}
.pay-pill--paid     { background: #EDF7F1; color: #1E7E4A; }
.pay-pill--pending  { background: #FEF9EC; color: #B7860B; }
.pay-pill--unpaid   { background: #FEF2F2; color: #B91C1C; }
.pay-pill--collected{ background: #EEF2FF; color: #4338CA; }

/* Send button variants */
.btn-remind {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .4rem .9rem;
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    letter-spacing: .03em;
    white-space: nowrap;
}
.btn-remind--send   { background: var(--gold); color: var(--white); }
.btn-remind--send:hover { background: var(--black); color: var(--white); }
.btn-remind--sent   { background: #EDF7F1; color: var(--success); cursor: default; }
.btn-remind--na     { background: var(--gray-light); color: var(--gray-text); cursor: default; }

.sms-note {
    font-size: .75rem;
    color: var(--gray-text);
    font-style: italic;
}
.table-section-header {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--gray-text);
    padding: .6rem 1.25rem;
    background: var(--gray-light);
}

/* Legend */
.legend {
    display: flex;
    gap: 1.25rem;
    flex-wrap: wrap;
    font-size: .78rem;
    color: var(--gray-text);
    margin-bottom: 1rem;
    align-items: center;
}
.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: .3rem;
}
.dot-green  { background: #1E7E4A; }
.dot-yellow { background: #B7860B; }
.dot-red    { background: #B91C1C; }
.dot-purple { background: #4338CA; }
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Send Reminders</h1>
    <p>Select a plan to see who has paid and who has not — then send reminders to individuals or everyone unpaid at once.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;">
    <p><?= htmlspecialchars($flash['message']) ?></p>
</div>
<?php endif; ?>

<!-- SMS status notice -->
<?php if (!SMS_ENABLED): ?>
<div class="admin-info-box" style="margin-bottom:1.5rem;">
    &#128241; <strong>SMS is currently OFF.</strong>
    Reminders will be sent by email only.
    To enable SMS, open <code>config.php</code>, add your Termii API key, and set
    <code>SMS_ENABLED = true</code>. After that, every reminder will send both email + SMS automatically.
</div>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:1.5rem;">
    &#128241; <strong>SMS is active (Termii).</strong>
    Every reminder sends email + SMS automatically.
    If SMS is failing, use the <a href="sms_debug.php" style="color:var(--success);font-weight:600;">SMS Debug Tool</a> to find out why.
</div>
<?php endif; ?>

<!-- ============================================================
     PLAN SELECTOR
     ============================================================ -->
<div class="reminder-plan-selector">
    <h2>Step 1 — Choose a Plan</h2>
    <div class="plan-select-grid">
        <?php foreach ($all_plans as $p):
            $is_active_card = $p['id'] === $selected_plan_id;
            $payout = calculatePayoutAmount($p['contribution_amount'], $p['total_participants']);
        ?>
        <a href="reminders.php?plan=<?= $p['id'] ?>"
           class="plan-select-card <?= $is_active_card ? 'active' : '' ?>">
            <div class="plan-select-card__name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="plan-select-card__meta">
                <span><?= $p['member_count'] ?>/<?= $p['total_participants'] ?> members</span>
                <span><?= formatMoney($p['contribution_amount']) ?>/cycle</span>
                <span>Payout: <?= formatMoney($payout) ?></span>
            </div>
            <span class="plan-select-card__badge badge-<?= $p['plan_status'] ?>">
                <?= ucfirst($p['plan_status']) ?>
            </span>
        </a>
        <?php endforeach; ?>

        <?php if (empty($all_plans)): ?>
        <p style="color:var(--gray-text);">No active plans found. Create a plan first.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($plan && !empty($members)): ?>

<!-- ============================================================
     SUMMARY BAR — shows paid / unpaid counts at a glance
     ============================================================ -->
<div class="reminder-summary">
    <div class="reminder-summary__item">
        <span class="reminder-summary__num num-gold"><?= $total_in_plan ?></span>
        <span class="reminder-summary__label">Total Members</span>
    </div>
    <div class="reminder-summary__divider"></div>
    <div class="reminder-summary__item">
        <span class="reminder-summary__num num-green"><?= $paid_count ?></span>
        <span class="reminder-summary__label">Paid / Pending</span>
    </div>
    <div class="reminder-summary__divider"></div>
    <div class="reminder-summary__item">
        <span class="reminder-summary__num num-red"><?= $unpaid_count ?></span>
        <span class="reminder-summary__label">Not Paid</span>
    </div>
    <div class="reminder-summary__divider"></div>
    <div class="reminder-summary__item">
        <span class="reminder-summary__num num-purple"><?= $collected_count ?></span>
        <span class="reminder-summary__label">Collected</span>
    </div>
    <div class="reminder-summary__divider"></div>
    <div class="reminder-summary__item">
        <span class="reminder-summary__num" style="font-size:1rem;color:var(--black);">
            <?= $plan['plan_start_date'] ? date('M j, Y', strtotime($plan['plan_start_date'])) : 'Not set' ?>
        </span>
        <span class="reminder-summary__label">Start Date</span>
    </div>
</div>

<!-- ============================================================
     BULK ACTION BAR — only shown when there are unpaid members
     ============================================================ -->
<?php if ($unpaid_count > 0): ?>
<div class="bulk-bar">
    <p>
        <strong><?= $unpaid_count ?> member(s)</strong> in
        <strong><?= htmlspecialchars($plan['name']) ?></strong>
        have not paid this cycle.
        Send them all a reminder at once:
    </p>
    <a href="reminders.php?bulk=1&plan=<?= $selected_plan_id ?>"
       class="btn btn-primary"
       onclick="return confirm('Send reminders to ALL <?= $unpaid_count ?> unpaid member(s) in <?= addslashes(htmlspecialchars($plan['name'])) ?>?\n\nThey will each receive an email<?= SMS_ENABLED ? ' and SMS' : '' ?> reminder.')">
        &#9993; Remind All <?= $unpaid_count ?> Unpaid Members
    </a>
</div>
<?php else: ?>
<div class="alert alert-success">
    &#10003; <strong>All members in this plan have paid or are verified.</strong> No reminders needed right now.
</div>
<?php endif; ?>

<!-- Legend -->
<div class="legend">
    <span>Row colour key:</span>
    <span><span class="legend-dot dot-green"></span> Paid this cycle</span>
    <span><span class="legend-dot dot-yellow"></span> Cash pending verification</span>
    <span><span class="legend-dot dot-red"></span> Has NOT paid</span>
    <span><span class="legend-dot dot-purple"></span> Already collected payout</span>
</div>

<!-- ============================================================
     MEMBER TABLE — Step 2
     ============================================================ -->
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th>Member</th>
                <th>Phone</th>
                <th>Collection Date</th>
                <th>Total Paid</th>
                <th>Payment Status</th>
                <th>Last Paid</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m):
            // Determine this member's payment status for the current cycle
            if ($m['has_collected']) {
                $pay_status    = 'collected';
                $row_class     = 'member-row-collected';
                $pill_class    = 'pay-pill--collected';
                $pill_label    = '&#10003; Collected Payout';
            } elseif ($m['paid_this_cycle'] > 0) {
                $pay_status    = 'paid';
                $row_class     = 'member-row-paid';
                $pill_class    = 'pay-pill--paid';
                $pill_label    = '&#10003; Paid';
            } elseif ($m['has_pending'] > 0) {
                $pay_status    = 'pending';
                $row_class     = 'member-row-pending';
                $pill_class    = 'pay-pill--pending';
                $pill_label    = '&#8987; Cash Pending';
            } else {
                $pay_status    = 'unpaid';
                $row_class     = 'member-row-unpaid';
                $pill_class    = 'pay-pill--unpaid';
                $pill_label    = '&#10007; Not Paid';
            }

            $col_date     = $m['collection_date'] ? date('M j, Y', strtotime($m['collection_date'])) : 'TBD';
            $last_paid    = $m['last_paid_at']     ? date('M j, Y', strtotime($m['last_paid_at']))    : '—';
            $is_suspended = $m['user_status'] !== 'active';
        ?>
        <tr class="<?= $row_class ?>">
            <!-- Position number -->
            <td>
                <div class="position-circle <?= $m['has_collected'] ? 'position-circle--done' : '' ?>"
                     style="width:28px;height:28px;font-size:.75rem;">
                    <?= $m['position'] ?>
                </div>
            </td>

            <!-- Member identity -->
            <td>
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                    <code class="user-code-badge"><?= htmlspecialchars($m['user_code'] ?? '—') ?></code>
                    <strong><?= htmlspecialchars($m['name']) ?></strong>
                    <?php if ($is_suspended): ?>
                        <span style="font-size:.72rem;background:#FDF0EF;color:var(--error);padding:.1rem .4rem;border-radius:20px;">Suspended</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.78rem;color:var(--gray-text);margin-top:.2rem;">
                    <?= htmlspecialchars($m['email']) ?>
                </div>
            </td>

            <!-- Phone -->
            <td style="font-size:.875rem;">
                <?= htmlspecialchars($m['phone'] ?: '—') ?>
                <?php if (!SMS_ENABLED && $m['phone']): ?>
                    <div class="sms-note">(SMS off)</div>
                <?php endif; ?>
            </td>

            <!-- Collection date -->
            <td style="font-size:.875rem;">
                <?= $col_date ?>
                <?php if ($m['collection_date'] === $today && !$m['has_collected']): ?>
                    <span class="rotation-badge rotation-badge--today" style="margin-left:4px;">TODAY!</span>
                <?php endif; ?>
            </td>

            <!-- How many payments made total -->
            <td style="text-align:center;">
                <span style="font-size:.875rem;font-weight:600;"><?= $m['total_payments_made'] ?></span>
                <span style="font-size:.72rem;color:var(--gray-text);display:block;">payment(s)</span>
            </td>

            <!-- Payment status pill -->
            <td>
                <span class="pay-pill <?= $pill_class ?>">
                    <?= $pill_label ?>
                </span>
                <?php if ($m['has_pending']): ?>
                    <div style="font-size:.72rem;color:var(--gray-text);margin-top:.2rem;">
                        Awaiting admin verification
                    </div>
                <?php endif; ?>
            </td>

            <!-- Last payment date -->
            <td style="font-size:.8rem;color:var(--gray-text);"><?= $last_paid ?></td>

            <!-- ACTION: Send reminder button -->
            <td>
                <?php if ($m['has_collected']): ?>
                    <!-- Already collected — no reminder needed -->
                    <span class="btn-remind btn-remind--na">&#10003; Done</span>

                <?php elseif ($pay_status === 'paid'): ?>
                    <!-- Already paid — no reminder needed -->
                    <span class="btn-remind btn-remind--na">Already Paid</span>

                <?php elseif ($pay_status === 'pending'): ?>
                    <!-- Cash pending — admin action needed, not reminder -->
                    <a href="../admin/verify_cash.php" class="btn-remind btn-remind--na"
                       title="Go to Verify Cash to approve this payment">
                        Verify Cash &#8594;
                    </a>

                <?php elseif ($is_suspended): ?>
                    <!-- Suspended account -->
                    <span class="btn-remind btn-remind--na">Suspended</span>

                <?php else: ?>
                    <!-- UNPAID — show the Send Reminder button -->
                    <?php if ($m['reminded_today']): ?>
                        <!-- Already reminded today — show differently -->
                        <div style="display:flex;flex-direction:column;gap:.3rem;align-items:flex-start;">
                            <span class="btn-remind btn-remind--sent">&#10003; Sent Today</span>
                            <a href="reminders.php?send=<?= $m['cid'] ?>&plan=<?= $selected_plan_id ?>"
                               class="btn-remind btn-remind--send"
                               style="font-size:.72rem;padding:.25rem .6rem;"
                               onclick="return confirm('Send ANOTHER reminder to <?= addslashes(htmlspecialchars($m['name'])) ?>?')">
                                Send Again
                            </a>
                        </div>
                    <?php else: ?>
                        <a href="reminders.php?send=<?= $m['cid'] ?>&plan=<?= $selected_plan_id ?>"
                           class="btn-remind btn-remind--send"
                           onclick="return confirm('Send a payment reminder to <?= addslashes(htmlspecialchars($m['name'])) ?>?\n\nEmail: <?= addslashes(htmlspecialchars($m['email'])) ?>\nPhone: <?= addslashes(htmlspecialchars($m['phone'])) ?>\n<?= SMS_ENABLED ? "Email + SMS will be sent." : "Only email (SMS is off)." ?>')">
                            &#9993; Send Reminder
                        </a>
                    <?php endif; ?>

                    <!-- Contact details shortcut -->
                    <div style="font-size:.72rem;color:var(--gray-text);margin-top:.3rem;">
                        <?= htmlspecialchars($m['phone'] ?: 'No phone') ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($plan && empty($members)): ?>
<div class="empty-state"><p>No members have joined this plan yet.</p></div>

<?php elseif (!$plan && $selected_plan_id > 0): ?>
<div class="alert alert-error"><p>Plan not found.</p></div>

<?php else: ?>
<div class="empty-state" style="padding:3rem;background:var(--white);border-radius:16px;border:1px solid var(--gray-light);">
    <p style="font-size:1rem;">&#128071; Click a plan above to see its members and payment status.</p>
</div>
<?php endif; ?>

</div></main>
</body>
</html>
