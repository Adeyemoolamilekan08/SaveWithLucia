<?php
// ============================================================
// FILE: admin/change_password.php
// INSTRUCTION: NEW FILE — copy into /swl/admin/change_password.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = $_POST['new_password'] ?? '';
    $cnf = $_POST['confirm']      ?? '';
    if (strlen($new) < 8) {
        $msg = 'error:Password must be at least 8 characters.';
    } elseif ($new !== $cnf) {
        $msg = 'error:Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $id   = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si",$hash,$id); $stmt->execute(); $stmt->close();
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
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
  <div class="auth-card">
    <h2>Change Admin Password</h2>
    <?php if ($text): ?>
      <div class="alert alert-<?= $type ?>"><p><?= htmlspecialchars($text) ?></p></div>
    <?php endif; ?>
    <form method="POST">
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
    <p style="text-align:center;margin-top:1rem;">
      <a href="index.php" style="font-size:.85rem;">Back to dashboard</a>
    </p>
  </div>
</div>
</body>
</html>
