<?php
// ============================================================
// admin/change_password.php — REPLACE existing file
// Adds: CSRF check, clears must_change_password after success,
// shows a notice banner when the change was forced.
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireAdmin();

$forced = isset($_GET['forced']) || !empty($_SESSION['must_change_password']);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFToken();

    $new = $_POST['new_password'] ?? '';
    $cnf = $_POST['confirm']      ?? '';
    if (strlen($new) < 8) {
        $msg = 'error:Password must be at least 8 characters.';
    } elseif ($new !== $cnf) {
        $msg = 'error:Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $id   = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?");
        $stmt->bind_param("si", $hash, $id); $stmt->execute(); $stmt->close();

        $_SESSION['must_change_password'] = 0;
        $forced = false;
        $msg = 'success:Password changed successfully!';
    }
}
[$type, $text] = $msg ? explode(':', $msg, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Change Password — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
</head>
<body class="auth-page">
<div class="auth-container">
  <div class="auth-card">
    <h2>Change Admin Password</h2>

    <?php if ($forced): ?>
      <div class="alert alert-error"><p>For security, you must set a new password before continuing.</p></div>
    <?php endif; ?>

    <?php if ($text): ?>
      <div class="alert alert-<?= $type ?>"><p><?= htmlspecialchars($text) ?></p></div>
    <?php endif; ?>

    <form method="POST">
      <?php csrfField(); ?>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" placeholder="Minimum 8 characters" required>
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm" placeholder="Repeat password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Update Password</button>
    </form>

    <?php if (!$forced): ?>
    <p style="text-align:center;margin-top:1rem;">
      <a href="index.php" style="font-size:.85rem;">Back to dashboard</a>
    </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
