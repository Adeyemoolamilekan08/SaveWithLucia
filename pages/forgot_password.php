<?php
// ============================================================
// pages/forgot_password.php
// Step 1 — Member enters their email to request a reset link
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

if (isLoggedIn()) {
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$message = '';
$type    = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $type    = 'error';
    } else {
        // Check if email exists
        $s = $conn->prepare("SELECT id, name FROM users WHERE email=? AND status='active'");
        $s->bind_param("s", $email); $s->execute();
        $user = $s->get_result()->fetch_assoc(); $s->close();

        if (!$user) {
            // Don't reveal if email exists — security best practice
            $message = 'If that email is registered, a reset link has been sent. Check your inbox.';
            $type    = 'success';
        } else {
            // Delete any old unused tokens for this user
            $conn->query("DELETE FROM password_resets WHERE user_id={$user['id']}");

            // Generate a secure token
            $token     = bin2hex(random_bytes(32)); // 64 char hex string
            $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Save token
            $ins = $conn->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)"
            );
            $ins->bind_param("iss", $user['id'], $token, $expires);
            $ins->execute(); $ins->close();

            // Build reset link
            $reset_link = SITE_URL . "/pages/reset_password.php?token=" . $token;

            // Send email
            $subject = SITE_NAME . " — Password Reset Request";
            $html    = "
            <div style='font-family:sans-serif;max-width:520px;margin:0 auto;'>
                <h2 style='font-family:Georgia,serif;color:#1A1A1A;'>Password Reset</h2>
                <p>Hello <strong>" . htmlspecialchars($user['name']) . "</strong>,</p>
                <p>We received a request to reset your " . SITE_NAME . " password.</p>
                <p>Click the button below to set a new password. This link expires in <strong>1 hour</strong>.</p>
                <div style='text-align:center;margin:28px 0;'>
                    <a href='{$reset_link}'
                       style='background:#C9A84C;color:#fff;padding:14px 32px;border-radius:8px;
                              text-decoration:none;font-weight:600;font-size:15px;display:inline-block;'>
                        Reset My Password
                    </a>
                </div>
                <p style='font-size:13px;color:#6B6860;'>
                    Or copy this link into your browser:<br>
                    <a href='{$reset_link}' style='color:#C9A84C;word-break:break-all;'>{$reset_link}</a>
                </p>
                <p style='font-size:13px;color:#6B6860;'>
                    If you did not request a password reset, ignore this email.
                    Your password will not change.
                </p>
                <p style='font-size:12px;color:#aaa;margin-top:24px;'>" . SITE_NAME . " — Rotational Savings</p>
            </div>";

            $result = sendEmail($email, $user['name'], $subject, $html);

            if ($result['success']) {
                $message = 'A password reset link has been sent to <strong>' . htmlspecialchars($email) . '</strong>. Check your inbox — it expires in 1 hour.';
                $type    = 'success';
            } else {
                $message = 'Could not send email. Please try again or contact admin.';
                $type    = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Forgot Password — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260527">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Jost', sans-serif; background: #FAF9F7; min-height: 100vh;
       display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem; }
.auth-wrap  { width: 100%; max-width: 420px; margin: 0 auto; }
.auth-logo  { text-align: center; margin-bottom: 1.75rem; }
.auth-logo a { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600;
               letter-spacing: .15em; color: #1A1A1A; text-decoration: none; display: block; }
.auth-logo p { font-size: .82rem; color: #6B6860; margin-top: .2rem; }
.auth-card  { background: #fff; border: 1px solid #F2F0ED; border-radius: 16px;
              padding: 2rem 1.75rem; box-shadow: 0 4px 24px rgba(0,0,0,.07); }
.auth-card h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.75rem; margin-bottom: .3rem; }
.auth-sub { font-size: .875rem; color: #6B6860; margin-bottom: 1.5rem; }
.form-group { margin-bottom: 1.1rem; }
.form-group label { display: block; font-size: .78rem; font-weight: 600; text-transform: uppercase;
                    letter-spacing: .05em; color: #1A1A1A; margin-bottom: .4rem; }
.form-group input { width: 100%; padding: .75rem 1rem; border: 1.5px solid #C8C5BF;
                    border-radius: 8px; font-size: .95rem; font-family: 'Jost', sans-serif;
                    outline: none; transition: border-color .2s; }
.form-group input:focus { border-color: #C9A84C; box-shadow: 0 0 0 3px rgba(201,168,76,.13); }
.btn-submit { width: 100%; padding: .85rem; background: #1A1A1A; color: #fff; border: none;
              border-radius: 8px; font-family: 'Jost', sans-serif; font-size: .9rem;
              font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
              cursor: pointer; margin-top: .5rem; transition: background .2s; }
.btn-submit:hover { background: #C9A84C; }
.alert-error   { background: #FDF0EF; border: 1px solid #E8BFBC; color: #C0392B;
                 border-radius: 8px; padding: .8rem 1rem; font-size: .875rem; margin-bottom: 1.25rem; }
.alert-success { background: #EDF7F1; border: 1px solid #A8D5BC; color: #1E7E4A;
                 border-radius: 8px; padding: .8rem 1rem; font-size: .875rem; margin-bottom: 1.25rem; }
.auth-footer { text-align: center; margin-top: 1.25rem; font-size: .875rem; color: #6B6860; }
.auth-footer a { color: #C9A84C; font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-logo">
        <a href="../index.php"><?= SITE_NAME ?></a>
        <p>Rotational Savings — Ajo/Esusu</p>
    </div>
    <div class="auth-card">
        <h2>Forgot Password?</h2>
        <p class="auth-sub">Enter your email and we will send you a link to reset your password.</p>

        <?php if ($message): ?>
        <div class="alert-<?= $type ?>"><p><?= $message ?></p></div>
        <?php endif; ?>

        <?php if ($type !== 'success'): ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($email) ?>"
                       placeholder="your@email.com"
                       required autocomplete="email">
            </div>
            <button type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php">← Back to Sign In</a>
        </div>
    </div>
</div>
</body>
</html>
