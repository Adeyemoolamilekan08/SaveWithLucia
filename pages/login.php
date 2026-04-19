<?php
// ============================================================
// FILE: pages/login.php
// INSTRUCTION: NEW FILE — copy into /swl/pages/login.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireGuest();
$errors = [];
$flash  = null;

if (isset($_GET['msg']) && $_GET['msg'] === 'loggedout')
    $flash = ['type'=>'success','message'=>'You have been logged out successfully.'];
if (isset($_GET['err']) && $_GET['err'] === 'suspended')
    $flash = ['type'=>'error','message'=>'Your account has been suspended. Contact admin.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT id,user_code,name,password,role,status FROM users WHERE email=?");
        $stmt->bind_param("s",$email); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'suspended') {
                $errors[] = "Your account has been suspended. Please contact admin.";
            } else {
                $upd = $conn->prepare("UPDATE users SET last_login=NOW() WHERE id=?");
                $upd->bind_param("i",$user['id']); $upd->execute(); $upd->close();
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['name']      = $user['name'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['status']    = $user['status'];
                $_SESSION['user_code'] = $user['user_code'];
                setFlash('success','Welcome back, '.$user['name'].'!');
                header("Location: ".SITE_URL.($user['role']==='admin'?"/admin/index.php":"/pages/dashboard.php"));
                exit();
            }
        } else {
            $errors[] = "Incorrect email or password.";
        }
    }
}
if (!$flash) $flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Login — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
  <div class="auth-logo">
    <a href="../index.php">
      <img src="../assets/images/logo.png" alt="<?= SITE_NAME ?>" class="site-logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
      <span class="brand-text" style="display:none;"><?= SITE_NAME ?></span>
    </a>
    <p class="brand-tagline">Save together, collect in turns.</p>
  </div>
  <div class="auth-card">
    <h2>Welcome Back</h2>
    <p class="auth-subtitle">Sign in to see your collection schedule.</p>
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>"><p><?= htmlspecialchars($flash['message']) ?></p></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <?php foreach($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="POST" action="">
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Your password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Sign In</button>
    </form>
    <p class="auth-switch">Don't have an account? <a href="register.php">Register here</a></p>
  </div>
</div>
</body>
</html>
