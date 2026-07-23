<?php
// ============================================================
// admin/test_email.php
// Tests if confirmation email works on live server
// DELETE this file after confirming email works
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';
requireAdmin();

$result   = null;
$test_to  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_to = trim($_POST['test_email'] ?? '');
    if (!empty($test_to)) {
        $subject = 'Test Email — ' . SITE_NAME;
        $html    = "
        <div style='font-family:sans-serif;max-width:500px;margin:0 auto;padding:20px;'>
            <h2 style='color:#C9A84C;'>✓ Email is Working!</h2>
            <p>This is a test email from <strong>" . SITE_NAME . "</strong>.</p>
            <p>If you received this, your email confirmation system is working correctly on the live server.</p>
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='font-size:12px;color:#999;'>Sent from: " . MAIL_FROM_EMAIL . "<br>
            SMTP: " . MAIL_HOST . ":" . MAIL_PORT . "<br>
            Time: " . date('Y-m-d H:i:s') . "</p>
        </div>";

        $result = sendEmail($test_to, 'Test Recipient', $subject, $html);
    }
}

// Check recent email logs
$logs = $conn->query(
    "SELECT el.*, u.name FROM email_logs el
     LEFT JOIN users u ON el.user_id = u.id
     ORDER BY el.sent_at DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Test Email — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260528">
<style>
pre { background:#1A1A1A;color:#E8D5A3;padding:1rem;border-radius:8px;font-size:.82rem;overflow-x:auto;white-space:pre-wrap; }
.dbg { background:var(--white);border:1px solid var(--gray-light);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem; }
.dbg h3 { font-size:1rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--gray-light); }
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container" style="max-width:760px;">

<div class="page-header">
    <h1>Email Test</h1>
    <p>Test if emails are sending correctly from the live server. Delete this file after confirming.</p>
</div>

<!-- Config check -->
<div class="dbg">
    <h3>Email Configuration</h3>
    <div style="font-size:.875rem;line-height:2;">
        <div>SMTP Host: <strong><?= MAIL_HOST ?></strong></div>
        <div>Port: <strong><?= MAIL_PORT ?></strong></div>
        <div>Username: <strong><?= MAIL_USERNAME ?></strong></div>
        <div>From Email: <strong><?= MAIL_FROM_EMAIL ?></strong></div>
        <div>Encryption: <strong><?= MAIL_ENCRYPTION ?></strong></div>
    </div>
</div>

<!-- Send test -->
<div class="dbg">
    <h3>Send Test Email</h3>
    <form method="POST">
        <div class="form-group">
            <label>Send test email to</label>
            <input type="email" name="test_email"
                   value="<?= htmlspecialchars($test_to ?: ADMIN_EMAIL) ?>"
                   placeholder="test@email.com" required>
        </div>
        <button type="submit" class="btn btn-gold">Send Test Email</button>
    </form>
</div>

<?php if ($result !== null): ?>
<div class="dbg">
    <h3>Result</h3>
    <?php if ($result['success']): ?>
    <div class="alert alert-success">
        <p>✓ Email sent successfully to <strong><?= htmlspecialchars($test_to) ?></strong></p>
        <p>Check your inbox. If you do not see it, check your spam folder.</p>
    </div>
    <?php else: ?>
    <div class="alert alert-error">
        <p>✗ Email failed to send.</p>
        <p><strong>Error:</strong> <?= htmlspecialchars($result['error']) ?></p>
    </div>
    <div style="margin-top:1rem;">
        <p style="font-size:.875rem;"><strong>Common fixes:</strong></p>
        <ul style="font-size:.875rem;color:var(--gray-text);margin-left:1rem;line-height:2;">
            <li>Gmail App Password expired — generate a new one at myaccount.google.com → Security → App Passwords</li>
            <li>cPanel server blocking outbound port 587 — contact your host to unblock it</li>
            <li>Wrong MAIL_PASSWORD in config.php</li>
        </ul>
    </div>
    <?php endif; ?>
    <pre><?= htmlspecialchars(print_r($result, true)) ?></pre>
</div>
<?php endif; ?>

<!-- Recent email logs -->
<div class="dbg">
    <h3>Recent Email Log (last 10)</h3>
    <?php if (empty($logs)): ?>
    <p style="color:var(--gray-text);">No emails logged yet.</p>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>To</th><th>Subject</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($l['to_email']) ?></td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($l['subject']) ?></td>
                    <td>
                        <span class="status-badge status-badge--<?= $l['status']==='sent'?'active':'failed' ?>">
                            <?= $l['status'] ?>
                        </span>
                    </td>
                    <td style="font-size:.75rem;color:var(--gray-text);">
                        <?= date('M j g:i A', strtotime($l['sent_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="admin-info-box">
    <strong>Delete</strong> <code>admin/test_email.php</code> after confirming emails work.
</div>

</div></main>
</body>
</html>
