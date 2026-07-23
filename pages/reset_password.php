<?php
// ============================================================
// pages/reset_password.php
// Step 2 — Member clicks link from email and sets new password
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';

if (isLoggedIn()) {
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$token   = trim($_GET['token'] ?? '');
$errors  = [];
$success = false;
$user    = null;
$reset   = null;

// Validate token
if (empty($token)) {
    $errors[] = 'Invalid or missing reset link. Please request a new one.';
} else {
    $s = $conn->prepare(
        "SELECT pr.*, u.name, u.email
         FROM password_resets pr
         JOIN users u ON pr.user_id = u.id
         WHERE pr.token = ? AND pr.used = 0"
    );
    $s->bind_param("s", $token); $s->execute();
    $reset = $s->get_result()->fetch_assoc(); $s->close();

    if (!$reset) {
        $errors[] = 'This reset link is invalid or has already been used.';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $errors[] = 'This reset link has expired (links are valid for 1 hour). Please request a new one.';
        // Clean up expired token
        $del = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
        $del->bind_param("s", $token); $del->execute(); $del->close();
    }
}

// Handle new password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    verifyCSRFToken();

    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $uid  = intval($reset['user_id']);

        // Update password
        $upd = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $upd->bind_param("si", $hash, $uid);
        $upd->execute(); $upd->close();

        // Mark token as used
        $used = $conn->prepare("UPDATE password_resets SET used=1 WHERE token = ?");
        $used->bind_param("s", $token); $used->execute(); $used->close();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Reset Password — <?= SITE_NAME ?></title>
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
                 border-radius: 8px; padding: .9rem 1rem; font-size: .9rem; margin-bottom: 1.25rem; }
.strength-bar { height: 5px; border-radius: 99px; background: #E5E7EB; margin-top: .4rem; overflow: hidden; }
.strength-fill { height: 100%; border-radius: 99px; transition: width .3s, background .3s; width: 0; }
.strength-text { font-size: .72rem; margin-top: .25rem; }
.auth-footer { text-align: center; margin-top: 1.25rem; font-size: .875rem; color: #6B6860; }
.auth-footer a { color: #C9A84C; font-weight: 500; text-decoration: none; }
.success-icon { width: 60px; height: 60px; background: #EDF7F1; color: #1E7E4A;
                border-radius: 50%; display: flex; align-items: center; justify-content: center;
                font-size: 1.75rem; margin: 0 auto 1rem; border: 2px solid #A8D5BC; }
</style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-logo">
        <a href="../index.php"><?= SITE_NAME ?></a>
        <p>Rotational Savings — Ajo/Esusu</p>
    </div>
    <div class="auth-card">

        <?php if ($success): ?>
        <!-- SUCCESS STATE -->
        <div style="text-align:center;padding:.5rem 0;">
            <div class="success-icon">&#10003;</div>
            <h2>Password Changed!</h2>
            <p class="auth-sub">Your password has been updated successfully.</p>
            <a href="login.php"
               style="display:block;padding:.85rem;background:#C9A84C;color:#fff;border-radius:8px;
                      font-weight:600;font-size:.9rem;letter-spacing:.08em;text-transform:uppercase;
                      text-decoration:none;margin-top:1rem;">
                Sign In with New Password
            </a>
        </div>

        <?php elseif (!empty($errors)): ?>
        <!-- ERROR STATE — invalid/expired token -->
        <h2>Reset Link Problem</h2>
        <p class="auth-sub">This link is not valid.</p>
        <div class="alert-error">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
        <a href="forgot_password.php"
           style="display:block;padding:.85rem;background:#1A1A1A;color:#fff;border-radius:8px;
                  font-weight:600;font-size:.9rem;letter-spacing:.08em;text-transform:uppercase;
                  text-decoration:none;text-align:center;margin-top:.5rem;">
            Request a New Reset Link
        </a>
        <div class="auth-footer" style="margin-top:1rem;">
            <a href="login.php">← Back to Sign In</a>
        </div>

        <?php else: ?>
        <!-- FORM STATE — enter new password -->
        <h2>Set New Password</h2>
        <p class="auth-sub">
            Hello <strong><?= htmlspecialchars($reset['name']) ?></strong>.
            Choose a new password for your account.
        </p>

        <?php if (!empty($errors)): ?>
        <div class="alert-error">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php csrfField(); ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" id="pwdInput"
                       placeholder="Minimum 6 characters"
                       required autocomplete="new-password">
                <div class="strength-bar">
                    <div class="strength-fill" id="strengthFill"></div>
                </div>
                <div class="strength-text" id="strengthText"></div>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm"
                       placeholder="Repeat your new password"
                       required autocomplete="new-password">
            </div>

            <button type="submit" class="btn-submit">Set New Password</button>
        </form>

        <div class="auth-footer">
            <a href="login.php">← Back to Sign In</a>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Password strength indicator
var pwd    = document.getElementById('pwdInput');
var fill   = document.getElementById('strengthFill');
var text   = document.getElementById('strengthText');
if (pwd && fill && text) {
    pwd.addEventListener('input', function() {
        var v = this.value;
        var score = 0;
        if (v.length >= 6)  score++;
        if (v.length >= 10) score++;
        if (/[A-Z]/.test(v))  score++;
        if (/[0-9]/.test(v))  score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var labels = ['', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
        var colors = ['', '#C0392B', '#E67E22', '#F1C40F', '#27AE60', '#1E7E4A'];
        var pct    = (score / 5) * 100;
        fill.style.width      = pct + '%';
        fill.style.background = colors[score] || '#E5E7EB';
        text.textContent      = v.length > 0 ? (labels[score] || '') : '';
        text.style.color      = colors[score] || '#6B6860';
    });
}
</script>
</body>
</html>
