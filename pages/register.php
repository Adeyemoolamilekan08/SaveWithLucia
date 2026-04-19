<?php
// ============================================================
// FILE: pages/register.php
// INSTRUCTION: NEW FILE — copy into /swl/pages/register.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireGuest();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim(strtolower($_POST['email'] ?? ''));
    $phone   = trim($_POST['phone'] ?? '');
    $password= $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($name))                                    $errors[] = "Full name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      $errors[] = "Enter a valid email address.";
    if (empty($phone))                                   $errors[] = "Phone number is required.";
    if (strlen($password) < 6)                           $errors[] = "Password must be at least 6 characters.";
    if ($password !== $confirm)                          $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        $s = $conn->prepare("SELECT id FROM users WHERE email=?");
        $s->bind_param("s",$email); $s->execute(); $s->store_result();
        if ($s->num_rows > 0) $errors[] = "This email is already registered.";
        $s->close();
    }

    if (empty($errors)) {
        $code   = generateUserCode($conn);
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("INSERT INTO users (user_code,name,email,phone,password,status) VALUES (?,?,?,?,?,'active')");
        $stmt->bind_param("sssss",$code,$name,$email,$phone,$hashed);
        if ($stmt->execute()) {
            $_SESSION['user_id']   = $stmt->insert_id;
            $_SESSION['name']      = $name;
            $_SESSION['role']      = 'user';
            $_SESSION['status']    = 'active';
            $_SESSION['user_code'] = $code;
            setFlash('success','Welcome! Your Member ID is '.$code);
            header("Location: ".SITE_URL."/pages/dashboard.php"); exit();
        }
        $stmt->close();
        $errors[] = "Registration failed. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Register — <?= SITE_NAME ?></title>
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
    <h2>Create Your Account</h2>
    <p class="auth-subtitle">You will be assigned a unique Member ID automatically.</p>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <?php foreach($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="POST" action="">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name" placeholder="e.g. Amara Obi"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" placeholder="08012345678"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Minimum 6 characters" required>
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" placeholder="Repeat password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Create Account</button>
    </form>
    <p class="auth-switch">Already have an account? <a href="login.php">Sign in here</a></p>
  </div>
</div>
</body>
</html>
