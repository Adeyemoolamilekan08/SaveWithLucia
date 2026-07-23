<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$today = date('Y-m-d');

// ============================================================
// SAVE NEW POSITIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_positions'])) {
    $plan_id = intval($_POST['plan_id'] ?? 0);
    $order   = $_POST['member_order'] ?? [];

    if ($plan_id > 0 && !empty($order)) {
        $plan = $conn->query("SELECT * FROM plans WHERE id=$plan_id")->fetch_assoc();

        if ($plan) {
            // Block save if start date is in the past
            $start_ok = !empty($plan['plan_start_date'])
                        && $plan['plan_start_date'] !== '0000-00-00'
                        && strtotime($plan['plan_start_date']) > 0
                        && $plan['plan_start_date'] >= $today;

            if (!$start_ok) {
                setFlash('error', 'Cannot save positions — the plan start date is in the past or not set. Please update the start date in the Plans page first.');
                header("Location: assign_positions.php?plan=$plan_id"); exit();
            }
            $start = (!empty($plan['plan_start_date'])
                      && $plan['plan_start_date'] !== '0000-00-00'
                      && strtotime($plan['plan_start_date']) > 0)
                ? $plan['plan_start_date']
                : $today;

            $payout = floatval($plan['contribution_amount']) * intval($plan['total_participants']);

            // Step 1: First set all positions to a temporary negative value
            // This avoids unique key conflicts when swapping positions
            $tmp = -1;
            foreach ($order as $cid) {
                $cid = intval($cid);
                $conn->query("UPDATE contributions SET position=$tmp WHERE id=$cid AND plan_id=$plan_id");
                $tmp--;
            }

            // Step 2: Now set the real positions one by one
            $pos = 1;
            foreach ($order as $cid) {
                $cid      = intval($cid);
                $new_date = calculateCollectionDate($start, $plan['frequency_days'], $pos);

                $conn->query(
                    "UPDATE contributions
                     SET position = $pos,
                         collection_date = " . ($new_date ? "'$new_date'" : "NULL") . ",
                         payout_amount = $payout
                     WHERE id = $cid AND plan_id = $plan_id"
                );
                $pos++;
            }

            // Step 3: Rebuild payout_schedule completely for this plan
            // Delete all existing rows for this plan then reinsert
            $conn->query("DELETE FROM payout_schedule WHERE plan_id=$plan_id");

            $members_updated = $conn->query(
                "SELECT c.id, c.position, c.collection_date, c.user_id
                 FROM contributions c
                 WHERE c.plan_id=$plan_id AND c.status='active'
                 ORDER BY c.position ASC"
            );
            while ($row = $members_updated->fetch_assoc()) {
                $uid      = intval($row['user_id']);
                $rpos     = intval($row['position']);
                $rdate    = $row['collection_date'];
                $conn->query(
                    "INSERT IGNORE INTO payout_schedule (plan_id,user_id,position,payout_date)
                     VALUES ($plan_id,$uid,$rpos," . ($rdate ? "'$rdate'" : "NULL") . ")"
                );
            }

            setFlash('success', 'Positions saved and collection dates recalculated for all ' . ($pos - 1) . ' members.');
        }
    }
    header("Location: assign_positions.php?plan=$plan_id"); exit();
}

// ============================================================
// LOAD DATA
// ============================================================
$plan_id   = intval($_GET['plan'] ?? 0);
$all_plans = $conn->query(
    "SELECT p.id, p.name, p.plan_status, p.frequency_days, p.plan_start_date,
            p.total_participants,
            (SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status='active') AS member_count
     FROM plans p WHERE p.is_active=1
     ORDER BY FIELD(p.plan_status,'active','open','completed'), p.name"
)->fetch_all(MYSQLI_ASSOC);

$plan = null; $members = [];

if ($plan_id > 0) {
    $plan = $conn->query("SELECT * FROM plans WHERE id=$plan_id")->fetch_assoc();
    if ($plan) {
        $s = $conn->prepare(
            "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
                    c.has_collected, u.name, u.phone, u.user_code
             FROM contributions c JOIN users u ON c.user_id=u.id
             WHERE c.plan_id=? AND c.status='active'
             ORDER BY c.position ASC"
        );
        $s->bind_param("i", $plan_id); $s->execute();
        $members = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Assign Positions — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260527">
<style>
.plan-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px,1fr));
    gap: .85rem;
    margin-bottom: 2rem;
}
.plan-pick-card {
    display: block; border: 2px solid var(--gray-light);
    border-radius: 12px; padding: .9rem 1rem;
    text-decoration: none; color: var(--black);
    transition: var(--transition);
}
.plan-pick-card:hover, .plan-pick-card.active {
    border-color: var(--gold); background: #FDFAF3; color: var(--black);
}
.plan-pick-card__name { font-weight: 700; font-size: .9rem; margin-bottom: .2rem; }
.plan-pick-card__meta { font-size: .72rem; color: var(--gray-text); }

/* Member list */
.pos-list { display: flex; flex-direction: column; gap: .55rem; margin-bottom: 5rem; }

.pos-row {
    display: flex;
    align-items: center;
    gap: .75rem;
    background: var(--white);
    border: 1.5px solid var(--gray-light);
    border-radius: 10px;
    padding: .85rem 1rem;
    transition: border-color .2s;
}
.pos-row.moving    { border-color: var(--gold); background: #FDFAF3; box-shadow: 0 4px 16px rgba(201,168,76,.15); }
.pos-row.collected { background: #F0FAF4; border-color: #A8D5BC; }

/* Position badge */
.pos-num {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--black); color: var(--white);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head); font-size: 1.05rem; font-weight: 700;
    flex-shrink: 0;
}
.pos-num.collected-pos { background: var(--success); }

/* Info */
.pos-info { flex: 1; min-width: 0; }
.pos-name { font-weight: 600; font-size: .9rem; }
.pos-sub  { font-size: .72rem; color: var(--gray-text); margin-top: .1rem; }
.pos-date { font-size: .75rem; color: var(--gray-text); text-align: right; flex-shrink: 0; }

/* Arrow buttons */
.pos-btns { display: flex; flex-direction: column; gap: 3px; flex-shrink: 0; }
.pos-btn {
    width: 32px; height: 28px;
    border: 1.5px solid var(--gray-mid);
    border-radius: 6px;
    background: var(--white);
    color: var(--gray-text);
    font-size: .85rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: var(--transition);
    -webkit-tap-highlight-color: transparent;
}
.pos-btn:hover, .pos-btn:active {
    background: var(--gold);
    color: var(--white);
    border-color: var(--gold);
    transform: scale(1.1);
}
.pos-btn:disabled { opacity: .25; cursor: not-allowed; transform: none; }

/* Sticky save bar */
.save-bar {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: var(--white);
    border-top: 2px solid var(--gold-light);
    padding: 1rem 1.25rem;
    display: flex; align-items: center;
    justify-content: space-between; gap: 1rem;
    box-shadow: 0 -4px 20px rgba(0,0,0,.1);
    z-index: 100;
}
.save-bar p { font-size: .82rem; color: var(--gray-text); margin: 0; }

.info-box {
    background: #FDFAF3; border: 1px solid var(--gold-light);
    border-radius: 10px; padding: .9rem 1.1rem;
    font-size: .875rem; color: var(--black);
    margin-bottom: 1.5rem; line-height: 1.7;
}

@media (max-width: 480px) {
    .plan-picker { grid-template-columns: 1fr; }
    .pos-date { display: none; }
    .save-bar { flex-direction: column; align-items: stretch; }
    .save-bar .btn { width: 100%; text-align: center; }
}
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Assign Positions</h1>
    <p>Use the ▲ ▼ buttons to move members up or down. Tap Save when done.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><p><?= $flash['message'] ?></p></div>
<?php endif; ?>

<div class="info-box">
    <strong>How it works:</strong>
    Select a plan → use <strong>▲</strong> to move a member up or <strong>▼</strong> to move them down →
    position numbers update instantly → tap <strong>Save Positions</strong>.
    All collection dates recalculate automatically when saved.
    Members who already collected their payout cannot be moved.
</div>

<!-- PLAN SELECTOR -->
<div style="margin-bottom:1.5rem;">
    <h2 style="font-size:1rem;margin-bottom:.85rem;font-family:var(--font-body);font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-text);">
        Select a Plan
    </h2>
    <div class="plan-picker">
        <?php foreach ($all_plans as $p): ?>
        <a href="assign_positions.php?plan=<?= $p['id'] ?>"
           class="plan-pick-card <?= $plan_id===$p['id']?'active':'' ?>">
            <div class="plan-pick-card__name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="plan-pick-card__meta">
                <?= $p['member_count'] ?>/<?= $p['total_participants'] ?> members
                · <?= formatFrequency($p['frequency_days']) ?>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($all_plans)): ?>
        <p style="color:var(--gray-text);">No plans. <a href="plans.php">Create one first.</a></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($plan && !empty($members)): ?>

<!-- PLAN INFO -->
<?php
$plan_start_ok   = !empty($plan['plan_start_date'])
                   && $plan['plan_start_date'] !== '0000-00-00'
                   && strtotime($plan['plan_start_date']) > 0
                   && date('Y', strtotime($plan['plan_start_date'])) > 1970;
$plan_start_past = $plan_start_ok && $plan['plan_start_date'] < $today;
$plan_start_str  = $plan_start_ok ? date('M j, Y', strtotime($plan['plan_start_date'])) : null;
?>
<div style="background:var(--white);border:1px solid var(--gray-light);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.25rem;font-size:.875rem;display:flex;gap:1.25rem;flex-wrap:wrap;align-items:center;">
    <strong><?= htmlspecialchars($plan['name']) ?></strong>
    <span style="color:var(--gray-text);">
        Start: <strong style="color:<?= !$plan_start_ok ? 'var(--error)' : ($plan_start_past ? 'var(--error)' : 'var(--black)') ?>">
            <?= $plan_start_str ?? 'Not set' ?>
            <?= $plan_start_past ? ' (PAST — update needed)' : '' ?>
        </strong>
    </span>
    <span style="color:var(--gray-text);">Frequency: <strong style="color:var(--black)"><?= formatFrequency($plan['frequency_days']) ?></strong></span>
    <span style="color:var(--gray-text);">Members: <strong style="color:var(--black)"><?= count($members) ?></strong></span>
</div>

<?php if ($plan_start_past): ?>
<div style="background:#FEF2F2;border:1.5px solid var(--error);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <strong style="color:var(--error);display:block;margin-bottom:.4rem;">
        ⚠ Start date is in the past (<?= $plan_start_str ?>)
    </strong>
    <p style="font-size:.875rem;color:#7B2020;margin:0 0 .75rem;line-height:1.6;">
        You cannot save positions using a past start date. Collection dates would be in the past which is wrong.
        Please set a new start date (today or future) first, then come back to assign positions.
    </p>
    <a href="plans.php?edit=<?= $plan_id ?>" class="btn btn-danger" style="padding:.6rem 1.25rem;font-size:.85rem;">
        → Edit Plan and Set New Start Date
    </a>
</div>
<?php elseif (!$plan_start_ok): ?>
<div class="alert alert-error" style="margin-bottom:1rem;">
    <p><strong>No start date set.</strong>
    <a href="plans.php?edit=<?= $plan_id ?>">Edit this plan</a> and set a start date first.</p>
</div>
<?php endif; ?>

<!-- MEMBER LIST with hidden form inputs -->
<form method="POST" action="assign_positions.php" id="posForm">
    <input type="hidden" name="save_positions" value="1">
    <input type="hidden" name="plan_id" value="<?= $plan_id ?>">

    <div class="pos-list" id="posList">
        <?php foreach ($members as $i => $m):
            $is_collected = (bool)$m['has_collected'];
            $col_date = (!empty($m['collection_date']) && $m['collection_date'] !== '0000-00-00')
                ? date('M j, Y', strtotime($m['collection_date'])) : 'TBD';
        ?>
        <div class="pos-row <?= $is_collected?'collected':'' ?>"
             data-cid="<?= $m['cid'] ?>"
             data-locked="<?= $is_collected?'1':'0' ?>">

            <!-- Hidden input — order maintained by JS -->
            <input type="hidden" name="member_order[]" value="<?= $m['cid'] ?>">

            <!-- Position number (updated by JS) -->
            <div class="pos-num <?= $is_collected?'collected-pos':'' ?>" id="badge_<?= $m['cid'] ?>">
                <?= $m['position'] ?>
            </div>

            <!-- Member info -->
            <div class="pos-info">
                <div class="pos-name">
                    <?= htmlspecialchars($m['name']) ?>
                    <?php if ($is_collected): ?>
                        <span style="font-size:.68rem;background:#EDF7F1;color:var(--success);padding:.1rem .4rem;border-radius:10px;margin-left:.3rem;">✓ Collected</span>
                    <?php endif; ?>
                </div>
                <div class="pos-sub">
                    <code style="background:#F0EDFF;color:#534AB7;font-size:.7rem;padding:.1rem .3rem;border-radius:3px;"><?= htmlspecialchars($m['user_code']??'') ?></code>
                    &nbsp;<?= htmlspecialchars($m['phone']) ?>
                </div>
            </div>

            <!-- Collection date -->
            <div class="pos-date"><?= $col_date ?></div>

            <!-- UP / DOWN buttons — only for non-collected members -->
            <?php if (!$is_collected): ?>
            <div class="pos-btns">
                <button type="button" class="pos-btn" data-action="up"
                        <?= $i===0?'disabled':'' ?> title="Move up">▲</button>
                <button type="button" class="pos-btn" data-action="down"
                        title="Move down">▼</button>
            </div>
            <?php else: ?>
            <div style="width:32px;flex-shrink:0;"></div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- STICKY SAVE BAR -->
    <div class="save-bar">
        <p>Reorder members then save.</p>
        <div style="display:flex;gap:.65rem;flex-wrap:wrap;">
            <a href="assign_positions.php?plan=<?= $plan_id ?>"
               class="btn btn-outline" style="padding:.7rem 1.25rem;">Reset</a>
            <button type="submit" class="btn btn-gold" style="padding:.7rem 1.5rem;">
                ✓ Save Positions
            </button>
        </div>
    </div>
</form>

<?php elseif ($plan && empty($members)): ?>
<div class="empty-state"><p>No members have joined this plan yet.</p></div>

<?php elseif ($plan_id > 0): ?>
<div class="alert alert-error"><p>Plan not found.</p></div>

<?php else: ?>
<div style="text-align:center;padding:3rem;background:var(--white);border:1px solid var(--gray-light);border-radius:16px;">
    <p style="font-size:1rem;color:var(--gray-text);">👆 Select a plan above to manage positions.</p>
</div>
<?php endif; ?>

</div></main>

<script>
// ============================================================
// Position management — arrow buttons only
// Simple, reliable, works on all browsers including old Android
// ============================================================
(function() {
    var list = document.getElementById('posList');
    if (!list) return;

    // Renumber all badges and update hidden inputs after every move
    function refresh() {
        var rows = list.querySelectorAll('.pos-row');
        // Remove all hidden inputs first
        list.querySelectorAll('input[type="hidden"]').forEach(function(inp) {
            inp.remove();
        });
        var pos = 1;
        rows.forEach(function(row) {
            var cid   = row.dataset.cid;
            var badge = document.getElementById('badge_' + cid);
            if (badge) badge.textContent = pos;

            // Re-add hidden input in correct order
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'member_order[]';
            inp.value = cid;
            row.appendChild(inp);

            // Update up/down button disabled states
            var btns = row.querySelectorAll('.pos-btn');
            btns.forEach(function(btn) {
                if (btn.dataset.action === 'up') {
                    // Disable if first non-collected row
                    var prev = row.previousElementSibling;
                    while (prev && prev.dataset.locked === '1') prev = prev.previousElementSibling;
                    btn.disabled = !prev;
                } else {
                    var next = row.nextElementSibling;
                    while (next && next.dataset.locked === '1') next = next.nextElementSibling;
                    btn.disabled = !next;
                }
            });
            pos++;
        });
    }

    // Handle button clicks — use event delegation on the list
    list.addEventListener('click', function(e) {
        // Find the button that was clicked
        var btn = e.target;
        if (!btn.classList.contains('pos-btn')) {
            btn = btn.parentElement;
            if (!btn || !btn.classList.contains('pos-btn')) return;
        }

        var action = btn.dataset.action;
        var row    = btn.closest('.pos-row');
        if (!row || row.dataset.locked === '1') return;

        // Add visual feedback
        row.classList.add('moving');
        setTimeout(function() { row.classList.remove('moving'); }, 400);

        if (action === 'up') {
            // Find previous non-collected sibling
            var prev = row.previousElementSibling;
            while (prev && prev.dataset.locked === '1') prev = prev.previousElementSibling;
            if (prev) list.insertBefore(row, prev);

        } else if (action === 'down') {
            // Find next non-collected sibling
            var next = row.nextElementSibling;
            while (next && next.dataset.locked === '1') next = next.nextElementSibling;
            if (next) list.insertBefore(next, row);
        }

        refresh();
    });

    // Initial numbering
    refresh();
})();
</script>

</body>
</html>
