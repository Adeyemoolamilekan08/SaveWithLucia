<?php
// ============================================================
// cron/reminders.php — Advanced Multi-Plan Reminder Engine
// ============================================================
// HOW TO RUN MANUALLY (test):
//   http://localhost/swl/cron/reminders.php?secret=SWL_lucia_2024_Xk9mPq3z
//
// HOW TO RUN AUTOMATICALLY ON LIVE SERVER (cPanel Cron Jobs):
//   Time: 0 8 * * *   (runs at 8:00 AM every day)
//   Command: php /home/yourusername/public_html/swl/cron/reminders.php
//
// WHAT THIS SCRIPT DOES EACH DAY:
//   Loops through EVERY active plan independently.
//   For each plan it finds today's collector (if any) and:
//
//   STEP 1 — BEFORE (1 day before payout_date)
//     Sends to ALL PAYERS (not the collector):
//     "Tomorrow is [Collector]'s turn. Please be ready to pay ₦X."
//
//   STEP 2 — TODAY PAYERS (payout_date = today)
//     Sends to ALL PAYERS who have NOT yet paid:
//     "Today is [Collector]'s turn. Please pay ₦X now."
//
//   STEP 3 — TODAY COLLECTOR (payout_date = today)
//     Sends to the COLLECTOR only:
//     "Today is your turn to receive ₦total. Contact admin."
//
//   STEP 4 — LATE (after payout_date, payment not made)
//     Sends ONLY to payers who still have NOT paid:
//     "You have not paid for [Collector]'s turn. Pay now."
//
//   Each reminder type is logged in reminders_sent to prevent
//   sending the same message twice on the same day.
// ============================================================

define('CRON_MODE', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

// ---- Security: block browser access without secret key ----
$secret = defined('CRON_SECRET') ? CRON_SECRET : 'SWL_lucia_2024_Xk9mPq3z';
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        die("Forbidden. Add ?secret=YOUR_SECRET to run manually.");
    }
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));

$total_sent   = 0;
$total_failed = 0;

echo "[" . date('Y-m-d H:i:s') . "] === SaveWithLucia Reminder Job Started ===\n";
echo "Today: $today\n\n";

// ============================================================
// LOAD ALL ACTIVE PLANS
// Each plan is processed completely independently.
// Nothing from Plan A affects Plan B.
// ============================================================
$plans_result = $conn->query(
    "SELECT * FROM plans
     WHERE is_active = 1
       AND plan_status = 'active'
     ORDER BY id ASC"
);

if (!$plans_result || $plans_result->num_rows === 0) {
    echo "No active plans found. Nothing to do.\n";
    exit();
}

$plans = $plans_result->fetch_all(MYSQLI_ASSOC);
echo "Found " . count($plans) . " active plan(s).\n\n";

// ============================================================
// PROCESS EACH PLAN INDEPENDENTLY
// ============================================================
foreach ($plans as $plan) {
    $plan_id   = $plan['id'];
    $plan_name = $plan['name'];
    $amount    = $plan['contribution_amount'];

    echo str_repeat('-', 55) . "\n";
    echo "PLAN: $plan_name (ID: $plan_id)\n";
    echo "Amount: ₦" . number_format($amount, 2) . " | Frequency: every {$plan['frequency_days']} day(s)\n\n";

    // --------------------------------------------------------
    // Find ALL members of this plan
    // We need this for:
    //   - Sending "before" and "today" reminders to payers
    //   - Knowing who the collector is so we can exclude them
    // --------------------------------------------------------
    $members_stmt = $conn->prepare(
        "SELECT c.id AS contribution_id, c.position, c.has_collected,
                c.collection_date, c.next_payment_date,
                u.id AS user_id, u.name, u.email, u.phone, u.user_code
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         WHERE c.plan_id = ?
           AND c.status = 'active'
           AND u.status = 'active'
         ORDER BY c.position ASC"
    );
    $members_stmt->bind_param("i", $plan_id);
    $members_stmt->execute();
    $all_members = $members_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $members_stmt->close();

    if (empty($all_members)) {
        echo "  No active members in this plan. Skipping.\n\n";
        continue;
    }

    $total_payout = $amount * count($all_members);

    // --------------------------------------------------------
    // Find TODAY'S COLLECTOR for this plan
    // The collector = member whose collection_date is today
    // --------------------------------------------------------
    $collector = null;
    foreach ($all_members as $m) {
        if ($m['collection_date'] === $today && !$m['has_collected']) {
            $collector = $m;
            break;
        }
    }

    // --------------------------------------------------------
    // Find TOMORROW'S COLLECTOR (for "before" reminder)
    // --------------------------------------------------------
    $tomorrow_collector = null;
    foreach ($all_members as $m) {
        if ($m['collection_date'] === $tomorrow && !$m['has_collected']) {
            $tomorrow_collector = $m;
            break;
        }
    }

    // ========================================================
    // STEP 1: BEFORE REMINDER — sent 1 day before payout
    // Sent to ALL PAYERS (not the collector)
    // ========================================================
    if ($tomorrow_collector) {
        echo "  [STEP 1] Tomorrow's collector: {$tomorrow_collector['name']} (Position {$tomorrow_collector['position']})\n";
        $before_sent = 0;

        foreach ($all_members as $m) {
            // Skip the collector — they receive money, not pay
            if ($m['user_id'] === $tomorrow_collector['user_id']) continue;
            // Skip members who have already collected (they still pay, but
            // some plans mark them differently — keep them in)

            $reminder_type = 'before_' . $tomorrow_collector['user_id'];

            // Check: already sent this "before" reminder today?
            if (alreadySentReminder($conn, $m['contribution_id'], $reminder_type, $today)) {
                echo "    SKIP (already sent): {$m['name']}\n";
                continue;
            }

            $message = "Hello {$m['name']}, tomorrow is {$tomorrow_collector['name']}'s turn to collect "
                     . "in the {$plan_name} group. Please be ready to pay "
                     . "₦" . number_format($amount, 2) . " on " . date('M j, Y', strtotime($tomorrow)) . ".";

            $result = sendReminderEmail(
                $conn, $m['user_id'], $m['email'], $m['name'], $m['user_code'] ?? '',
                $plan_name, $amount, $tomorrow, 'due_tomorrow', $m['phone']
            );

            logReminderActivity($conn, $plan_id, $plan_name, $m['user_id'], $m['name'],
                'before', 'email', $message,
                ($result['email']['success'] ?? false) ? 'sent' : 'failed'
            );

            recordReminderSent($conn, $m['user_id'], $m['contribution_id'], $reminder_type, $today);

            if ($result['email']['success'] ?? false) {
                $before_sent++;
                $total_sent++;
                echo "    ✓ Before reminder → {$m['name']} ({$m['email']})\n";
            } else {
                $total_failed++;
                echo "    ✗ Failed → {$m['name']}\n";
            }
        }
        echo "  Before reminders sent: $before_sent\n\n";
    } else {
        echo "  [STEP 1] No collection tomorrow for this plan.\n\n";
    }

    // ========================================================
    // STEP 2: TODAY — PAYERS reminder
    // Sent to ALL PAYERS who have NOT yet paid today
    // ========================================================
    if ($collector) {
        echo "  [STEP 2] Today's collector: {$collector['name']} (Position {$collector['position']})\n";
        $today_payer_sent = 0;

        foreach ($all_members as $m) {
            // Skip the collector themselves
            if ($m['user_id'] === $collector['user_id']) continue;

            // Check: has this member ALREADY PAID for today's cycle?
            if (memberPaidForCycle($conn, $m['contribution_id'], $today, $plan['frequency_days'])) {
                echo "    SKIP (already paid): {$m['name']}\n";
                continue;
            }

            $reminder_type = 'today_payer_' . $collector['user_id'];

            if (alreadySentReminder($conn, $m['contribution_id'], $reminder_type, $today)) {
                echo "    SKIP (already sent): {$m['name']}\n";
                continue;
            }

            $message = "Hello {$m['name']}, today is {$collector['name']}'s turn to collect "
                     . "in {$plan_name}. Please pay ₦" . number_format($amount, 2) . " now.";

            $result = sendReminderEmail(
                $conn, $m['user_id'], $m['email'], $m['name'], $m['user_code'] ?? '',
                $plan_name, $amount, $today, 'due_tomorrow', $m['phone']
            );

            logReminderActivity($conn, $plan_id, $plan_name, $m['user_id'], $m['name'],
                'today_payer', 'email', $message,
                ($result['email']['success'] ?? false) ? 'sent' : 'failed'
            );

            recordReminderSent($conn, $m['user_id'], $m['contribution_id'], $reminder_type, $today);

            if ($result['email']['success'] ?? false) {
                $today_payer_sent++;
                $total_sent++;
                echo "    ✓ Today-payer → {$m['name']}\n";
            } else {
                $total_failed++;
                echo "    ✗ Failed → {$m['name']}\n";
            }
        }
        echo "  Today-payer reminders sent: $today_payer_sent\n\n";

        // ====================================================
        // STEP 3: TODAY — COLLECTOR notification
        // Sent only to the collector
        // ====================================================
        echo "  [STEP 3] Notifying collector: {$collector['name']}\n";
        $reminder_type = 'today_collector';

        if (!alreadySentReminder($conn, $collector['contribution_id'], $reminder_type, $today)) {
            $result = sendCollectionDayEmail(
                $conn,
                $collector['user_id'], $collector['email'],
                $collector['name'],    $collector['user_code'] ?? '',
                $plan_name, $total_payout, $collector['position']
            );

            $msg = "Hello {$collector['name']}, today is your turn to collect "
                 . "₦" . number_format($total_payout, 2) . " from {$plan_name}. Contact admin.";

            logReminderActivity($conn, $plan_id, $plan_name,
                $collector['user_id'], $collector['name'],
                'today_collector', 'email', $msg,
                ($result['success'] ?? false) ? 'sent' : 'failed'
            );

            recordReminderSent($conn, $collector['user_id'], $collector['contribution_id'], $reminder_type, $today);

            if ($result['success'] ?? false) {
                $total_sent++;
                echo "    ✓ Collector notified: {$collector['name']}\n\n";
            } else {
                $total_failed++;
                echo "    ✗ Collector notify failed\n\n";
            }
        } else {
            echo "    SKIP (already sent today)\n\n";
        }

    } else {
        echo "  [STEP 2+3] No collection today for this plan.\n\n";
    }

    // ========================================================
    // STEP 4: LATE PAYMENT REMINDER
    // Find members who have NOT paid since the last payout date
    // Only send to members who are OVERDUE
    // Do NOT send to the collector for that date
    // ========================================================
    echo "  [STEP 4] Checking for late/unpaid members...\n";

    // Find the most recent past payout date for this plan
    // (the last day where someone was supposed to collect)
    $past_stmt = $conn->prepare(
        "SELECT c.id AS contribution_id, c.position,
                c.collection_date AS past_due_date,
                u.id AS collector_user_id, u.name AS collector_name
         FROM contributions c
         JOIN users u ON c.user_id = u.id
         WHERE c.plan_id = ?
           AND c.collection_date < ?
           AND c.collection_date >= DATE_SUB(?, INTERVAL ? DAY)
           AND c.has_collected = 0
           AND c.status = 'active'
         ORDER BY c.collection_date DESC
         LIMIT 1"
    );
    $freq = $plan['frequency_days'];
    $past_stmt->bind_param("issi", $plan_id, $today, $today, $freq);
    $past_stmt->execute();
    $past_collector = $past_stmt->get_result()->fetch_assoc();
    $past_stmt->close();

    if ($past_collector) {
        $past_date = $past_collector['past_due_date'];
        echo "    Past due date found: $past_date (collector: {$past_collector['collector_name']})\n";
        $late_sent = 0;

        foreach ($all_members as $m) {
            // Skip the collector for that date
            if ($m['user_id'] === $past_collector['collector_user_id']) continue;

            // Only remind if they have NOT paid since the past due date
            if (memberPaidForCycle($conn, $m['contribution_id'], $past_date, $plan['frequency_days'])) {
                echo "    SKIP (already paid): {$m['name']}\n";
                continue;
            }

            $reminder_type = 'late_' . $past_date;

            if (alreadySentReminder($conn, $m['contribution_id'], $reminder_type, $today)) {
                echo "    SKIP (already sent): {$m['name']}\n";
                continue;
            }

            $message = "Hello {$m['name']}, you have not paid your contribution for "
                     . "{$past_collector['collector_name']}'s turn in {$plan_name} "
                     . "(due " . date('M j', strtotime($past_date)) . "). "
                     . "Please pay ₦" . number_format($amount, 2) . " immediately.";

            $result = sendReminderEmail(
                $conn, $m['user_id'], $m['email'], $m['name'], $m['user_code'] ?? '',
                $plan_name, $amount, $past_date, 'overdue', $m['phone']
            );

            logReminderActivity($conn, $plan_id, $plan_name, $m['user_id'], $m['name'],
                'late', 'email', $message,
                ($result['email']['success'] ?? false) ? 'sent' : 'failed'
            );

            recordReminderSent($conn, $m['user_id'], $m['contribution_id'], $reminder_type, $today);

            // Also notify admin if someone is late
            notifyAdminOfLatePayer($conn, $m['name'], $plan_name, $past_date, $amount);

            if ($result['email']['success'] ?? false) {
                $late_sent++;
                $total_sent++;
                echo "    ✓ Late reminder → {$m['name']}\n";
            } else {
                $total_failed++;
                echo "    ✗ Failed → {$m['name']}\n";
            }
        }
        echo "  Late reminders sent: $late_sent\n\n";
    } else {
        echo "    No overdue payments found for this plan.\n\n";
    }
}

// ============================================================
// SUMMARY
// ============================================================
echo str_repeat('=', 55) . "\n";
echo "[" . date('Y-m-d H:i:s') . "] DONE.\n";
echo "Total sent:   $total_sent\n";
echo "Total failed: $total_failed\n";
echo "SMS: " . (SMS_ENABLED ? "ENABLED (Twilio)" : "DISABLED") . "\n";


// ============================================================
// HELPER FUNCTIONS (defined here so this file is self-contained)
// ============================================================

// Check if a reminder was already sent today (prevents duplicates)
function alreadySentReminder($conn, $contribution_id, $reminder_type, $sent_date) {
    $s = $conn->prepare(
        "SELECT id FROM reminders_sent
         WHERE contribution_id = ? AND reminder_type = ? AND sent_date = ?"
    );
    $s->bind_param("iss", $contribution_id, $reminder_type, $sent_date);
    $s->execute();
    $s->store_result();
    $found = $s->num_rows > 0;
    $s->close();
    return $found;
}

// Record that a reminder was sent (prevents future duplicates)
function recordReminderSent($conn, $user_id, $contribution_id, $reminder_type, $sent_date) {
    $s = $conn->prepare(
        "INSERT IGNORE INTO reminders_sent
            (user_id, contribution_id, reminder_type, sent_date)
         VALUES (?, ?, ?, ?)"
    );
    $s->bind_param("iiss", $user_id, $contribution_id, $reminder_type, $sent_date);
    $s->execute();
    $s->close();
}

// Check if a member has paid within the current cycle window
// "current cycle" = a payment in the last frequency_days days
function memberPaidForCycle($conn, $contribution_id, $due_date, $frequency_days) {
    $window_start = date('Y-m-d', strtotime($due_date . ' -' . intval($frequency_days) . ' days'));
    $window_end   = $due_date;

    $s = $conn->prepare(
        "SELECT COUNT(*) AS c FROM payments
         WHERE contribution_id = ?
           AND status = 'paid'
           AND DATE(paid_at) BETWEEN ? AND ?"
    );
    $s->bind_param("iss", $contribution_id, $window_start, $window_end);
    $s->execute();
    $c = intval($s->get_result()->fetch_assoc()['c']);
    $s->close();
    return $c > 0;
}

// Write to reminder_log so admin can see full history
function logReminderActivity($conn, $plan_id, $plan_name, $user_id, $user_name, $type, $channel, $message, $status) {
    $s = $conn->prepare(
        "INSERT INTO reminder_log
            (plan_id, plan_name, user_id, user_name, reminder_type, channel, message, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $s->bind_param("isisssss",
        $plan_id, $plan_name, $user_id, $user_name,
        $type, $channel, $message, $status
    );
    $s->execute();
    $s->close();
}

// Notify admin by saving a notification if someone is late
function notifyAdminOfLatePayer($conn, $payer_name, $plan_name, $due_date, $amount) {
    // Find admin user ID
    $s = $conn->prepare("SELECT id FROM users WHERE role='admin' LIMIT 1");
    $s->execute();
    $admin = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$admin) return;

    $msg = $payer_name . ' has not paid ₦' . number_format($amount, 2)
         . ' for ' . $plan_name . ' (due ' . date('M j, Y', strtotime($due_date)) . ').';

    $title = 'Late Payment — ' . $plan_name;

    $ins = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type)
         VALUES (?, ?, ?, 'warning')"
    );
    $ins->bind_param("iss", $admin['id'], $title, $msg);
    $ins->execute();
    $ins->close();
}
?>
