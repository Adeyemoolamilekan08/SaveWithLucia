<?php
// ============================================================
// FILE: cron/reminders.php
// INSTRUCTION: NEW FILE — copy into /swl/cron/reminders.php
//
// HOW TO RUN THIS FILE:
//
// OPTION 1 — Test it manually in your browser:
//   http://localhost/swl/cron/reminders.php?secret=swl_cron_secret_change_me_2024
//   (Change the secret in config.php before going live)
//
// OPTION 2 — Run it daily automatically on your live server:
//   In cPanel → Cron Jobs, add a new cron:
//   Time: 0 8 * * *  (this means: run at 8:00 AM every day)
//   Command: php /home/yourusername/public_html/swl/cron/reminders.php
//
// WHAT THIS FILE DOES EVERY DAY:
//   1. Finds members whose payment is OVERDUE → sends email + SMS warning
//   2. Finds members whose payment is DUE TOMORROW → sends email + SMS reminder
//   3. Finds members whose COLLECTION DAY is today → sends email + SMS alert
//   4. Finds members whose collection day is in 3 days → sends upcoming reminder
//   5. Logs every reminder to the reminders_sent table (no duplicates sent)
//
// SMS SETUP:
//   Open config.php and set:
//     TERMII_API_KEY = your key from termii.com
//     SMS_ENABLED    = true
//   SMS will then send automatically alongside every email above.
// ============================================================

define('CRON_MODE', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

// ---- Security check ----
// Prevents random people from triggering this script via browser
$secret = defined('CRON_SECRET') ? CRON_SECRET : 'swl_cron_secret_change_me_2024';
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        die("Forbidden. Add ?secret=YOUR_SECRET to run this manually.");
    }
}

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$in3days  = date('Y-m-d', strtotime('+3 days'));

$sent   = 0;
$failed = 0;
$log    = [];

echo "[".date('Y-m-d H:i:s')."] SaveWithLucia reminder job started.\n";
echo "Today: $today | Tomorrow: $tomorrow\n";
echo str_repeat('-',60)."\n";

// ============================================================
// HELPER: Check if a reminder was already sent today
// Prevents sending duplicates if cron runs twice by accident
// ============================================================
function alreadySentToday($conn, $contribution_id, $reminder_type, $sent_date) {
    $s = $conn->prepare("SELECT id FROM reminders_sent WHERE contribution_id=? AND reminder_type=? AND sent_date=?");
    $s->bind_param("iss",$contribution_id,$reminder_type,$sent_date);
    $s->execute(); $s->store_result();
    $found = $s->num_rows > 0; $s->close();
    return $found;
}

// ============================================================
// HELPER: Record that a reminder was sent
// ============================================================
function recordReminderSent($conn, $user_id, $contribution_id, $reminder_type, $sent_date) {
    $s = $conn->prepare("INSERT IGNORE INTO reminders_sent (user_id,contribution_id,reminder_type,sent_date) VALUES (?,?,?,?)");
    $s->bind_param("iiss",$user_id,$contribution_id,$reminder_type,$sent_date);
    $s->execute(); $s->close();
}

// ============================================================
// REMINDER 1: PAYMENT OVERDUE
// Sends to members who have not made any payment this cycle
// and the plan has already started
// ============================================================
echo "\n[1/4] Checking overdue payments...\n";

$overdue = $conn->query(
    "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
            u.id AS uid, u.name, u.email, u.phone, u.user_code,
            p.name AS plan_name, p.contribution_amount, p.frequency_days,
            p.plan_start_date
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.status='active'
       AND c.has_collected=0
       AND p.plan_status='active'
       AND u.status='active'
       AND p.plan_start_date IS NOT NULL
       AND p.plan_start_date < CURDATE()
       AND (
           SELECT COUNT(*) FROM payments pay
           WHERE pay.contribution_id=c.id
             AND pay.status='paid'
             AND pay.paid_at >= DATE_SUB(NOW(), INTERVAL p.frequency_days DAY)
       ) = 0"
)->fetch_all(MYSQLI_ASSOC);

echo "Found ".count($overdue)." overdue members.\n";

foreach ($overdue as $m) {
    if (alreadySentToday($conn,$m['cid'],'payment_overdue',$today)) {
        echo "  SKIP (already sent today): {$m['name']}\n"; continue;
    }
    $result = sendReminderEmail(
        $conn,$m['uid'],$m['email'],$m['name'],$m['user_code']??'',
        $m['plan_name'],$m['contribution_amount'],$today,'overdue',$m['phone']
    );
    recordReminderSent($conn,$m['uid'],$m['cid'],'payment_overdue',$today);
    $email_ok = $result['email']['success'] ?? false;
    $sms_ok   = $result['sms']['success']   ?? false;
    if ($email_ok) { $sent++; $log[] = "OVERDUE email ✓ → {$m['name']} ({$m['email']})"; }
    else           { $failed++; $log[] = "OVERDUE email ✗ → {$m['name']} — ".($result['email']['error']??'unknown'); }
    if ($sms_ok)   { $log[] = "OVERDUE SMS ✓ → {$m['phone']}"; }
    elseif (SMS_ENABLED) { $log[] = "OVERDUE SMS ✗ → {$m['phone']}"; }
    echo "  Sent to: {$m['name']} | Email:".($email_ok?'OK':'FAIL')." | SMS:".($sms_ok?'OK':(SMS_ENABLED?'FAIL':'OFF'))."\n";
}

// ============================================================
// REMINDER 2: PAYMENT DUE TOMORROW
// Sends to members whose contribution cycle is due next day
// ============================================================
echo "\n[2/4] Checking payments due tomorrow ($tomorrow)...\n";

// Members joining a plan whose start date is tomorrow
$due_tomorrow = $conn->query(
    "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
            u.id AS uid, u.name, u.email, u.phone, u.user_code,
            p.name AS plan_name, p.contribution_amount, p.frequency_days
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.status='active'
       AND c.has_collected=0
       AND p.plan_status IN ('open','active')
       AND u.status='active'
       AND p.plan_start_date = '$tomorrow'"
)->fetch_all(MYSQLI_ASSOC);

// Members in active plans whose payment cycle falls on tomorrow
$cycle_due = $conn->query(
    "SELECT c.id AS cid, c.position, c.collection_date, c.payout_amount,
            u.id AS uid, u.name, u.email, u.phone, u.user_code,
            p.name AS plan_name, p.contribution_amount, p.frequency_days
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.status='active'
       AND c.has_collected=0
       AND p.plan_status='active'
       AND u.status='active'
       AND p.plan_start_date IS NOT NULL
       AND p.plan_start_date < CURDATE()
       AND DATEDIFF('$tomorrow', p.plan_start_date) % p.frequency_days = 0"
)->fetch_all(MYSQLI_ASSOC);

// Merge and de-duplicate
$all_due = []; $seen_cids = [];
foreach (array_merge($due_tomorrow, $cycle_due) as $r) {
    if (!isset($seen_cids[$r['cid']])) { $seen_cids[$r['cid']]=true; $all_due[]=$r; }
}
echo "Found ".count($all_due)." members with payment due tomorrow.\n";

foreach ($all_due as $m) {
    if (alreadySentToday($conn,$m['cid'],'payment_due_tomorrow',$today)) {
        echo "  SKIP (already sent today): {$m['name']}\n"; continue;
    }
    $result = sendReminderEmail(
        $conn,$m['uid'],$m['email'],$m['name'],$m['user_code']??'',
        $m['plan_name'],$m['contribution_amount'],$tomorrow,'due_tomorrow',$m['phone']
    );
    recordReminderSent($conn,$m['uid'],$m['cid'],'payment_due_tomorrow',$today);
    $email_ok = $result['email']['success'] ?? false;
    $sms_ok   = $result['sms']['success']   ?? false;
    if ($email_ok) { $sent++; $log[] = "DUE TOMORROW email ✓ → {$m['name']}"; }
    else           { $failed++; $log[] = "DUE TOMORROW email ✗ → {$m['name']}"; }
    echo "  Sent to: {$m['name']} | Email:".($email_ok?'OK':'FAIL')." | SMS:".($sms_ok?'OK':(SMS_ENABLED?'FAIL':'OFF'))."\n";
}

// ============================================================
// REMINDER 3: COLLECTION DAY IS TODAY
// Notifies members that it is their payout day right now
// ============================================================
echo "\n[3/4] Checking today's collectors ($today)...\n";

$collecting_today = $conn->query(
    "SELECT c.id AS cid, c.position, c.payout_amount,
            u.id AS uid, u.name, u.email, u.phone, u.user_code,
            p.name AS plan_name
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.collection_date='$today'
       AND c.has_collected=0
       AND c.status='active'
       AND u.status='active'"
)->fetch_all(MYSQLI_ASSOC);

echo "Found ".count($collecting_today)." members collecting today.\n";

foreach ($collecting_today as $m) {
    if (alreadySentToday($conn,$m['cid'],'collection_today',$today)) {
        echo "  SKIP (already sent today): {$m['name']}\n"; continue;
    }
    $result = sendCollectionDayEmail(
        $conn,$m['uid'],$m['email'],$m['name'],$m['user_code']??'',
        $m['plan_name'],$m['payout_amount'],$m['position']
    );
    // Also send SMS if enabled
    if (SMS_ENABLED && !empty($m['phone'])) {
        $payout_sms = '₦'.number_format((float)$m['payout_amount'],2);
        $sms_msg = SITE_NAME.': Hi '.$m['name'].', TODAY is your collection day for '.
                   $m['plan_name'].'. Your payout of '.$payout_sms.' is ready. Contact admin now!';
        if (strlen($sms_msg)>160) $sms_msg=substr($sms_msg,0,157).'...';
        sendSMS($conn,$m['uid'],$m['phone'],$sms_msg);
    }
    recordReminderSent($conn,$m['uid'],$m['cid'],'collection_today',$today);
    $ok = $result['success'] ?? false;
    if ($ok) { $sent++; $log[] = "COLLECTION TODAY email ✓ → {$m['name']}"; }
    else     { $failed++; $log[] = "COLLECTION TODAY email ✗ → {$m['name']}"; }
    echo "  Sent to: {$m['name']} | ".($ok?'OK':'FAIL')."\n";
}

// ============================================================
// REMINDER 4: COLLECTION COMING IN 3 DAYS
// Early heads-up so members can prepare
// ============================================================
echo "\n[4/4] Checking upcoming collections in 3 days ($in3days)...\n";

$upcoming = $conn->query(
    "SELECT c.id AS cid, c.position, c.payout_amount, c.collection_date,
            u.id AS uid, u.name, u.email, u.phone, u.user_code,
            p.name AS plan_name
     FROM contributions c
     JOIN users u ON c.user_id=u.id
     JOIN plans p ON c.plan_id=p.id
     WHERE c.collection_date='$in3days'
       AND c.has_collected=0
       AND c.status='active'
       AND u.status='active'"
)->fetch_all(MYSQLI_ASSOC);

echo "Found ".count($upcoming)." members collecting in 3 days.\n";

foreach ($upcoming as $m) {
    if (alreadySentToday($conn,$m['cid'],'collection_upcoming',$today)) {
        echo "  SKIP (already sent today): {$m['name']}\n"; continue;
    }
    $result = sendUpcomingCollectionEmail(
        $conn,$m['uid'],$m['email'],$m['name'],$m['user_code']??'',
        $m['plan_name'],$m['payout_amount'],$m['collection_date'],3,$m['phone']
    );
    recordReminderSent($conn,$m['uid'],$m['cid'],'collection_upcoming',$today);
    $email_ok = $result['email']['success'] ?? false;
    $sms_ok   = $result['sms']['success']   ?? false;
    if ($email_ok) { $sent++; $log[] = "UPCOMING email ✓ → {$m['name']}"; }
    else           { $failed++; $log[] = "UPCOMING email ✗ → {$m['name']}"; }
    echo "  Sent to: {$m['name']} | Email:".($email_ok?'OK':'FAIL')." | SMS:".($sms_ok?'OK':(SMS_ENABLED?'FAIL':'OFF'))."\n";
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n".str_repeat('-',60)."\n";
echo "[".date('Y-m-d H:i:s')."] DONE. Sent: $sent | Failed: $failed\n";
echo "SMS status: ".(SMS_ENABLED ? "ENABLED (Termii)" : "DISABLED — set SMS_ENABLED=true in config.php to enable")."\n\n";
if (!empty($log)) {
    echo "Detail log:\n";
    foreach ($log as $line) echo "  → $line\n";
}
?>
