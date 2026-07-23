<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (isLoggedIn()) {
    redirectTo(SITE_URL . "/pages/dashboard.php");
}

$errors = [];
$name = $email = $phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (empty($name))             $errors[] = 'Full name is required.';
    if (empty($email))            $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (strlen($phone) < 10)      $errors[] = 'Enter a valid phone number.';
    if (strlen($password) < 6)    $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)   $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        // Check email not taken
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param("s", $email); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) $errors[] = 'An account with that email already exists.';
        $chk->close();
    }

    if (empty($errors)) {
        $user_code = generateUserCode($conn);
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $ins = $conn->prepare(
            "INSERT INTO users (user_code, name, email, phone, password, role, status)
             VALUES (?, ?, ?, ?, ?, 'user', 'active')"
        );
        $ins->bind_param("sssss", $user_code, $name, $email, $phone, $hash);

        if ($ins->execute()) {
            $uid = $ins->insert_id; $ins->close();

            session_regenerate_id(true);
            $_SESSION['user_id']   = $uid;
            $_SESSION['name']      = $name;
            $_SESSION['email']     = $email;
            $_SESSION['role']      = 'user';
            $_SESSION['status']    = 'active';
            $_SESSION['user_code'] = $user_code;

            setFlash('success', 'Welcome to ' . SITE_NAME . ', ' . $name . '! Your Member ID is ' . $user_code . '.');
            redirectTo(SITE_URL . "/pages/dashboard.php");
        } else {
            $ins->close();
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Create Account — <?= SITE_NAME ?></title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Jost', sans-serif; background: #FAF9F7; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem; }
.auth-wrap { width: 100%; max-width: 440px; margin: 0 auto; }
.auth-logo { text-align: center; margin-bottom: 1.75rem; }
.auth-logo a { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600; letter-spacing: .15em; color: #1A1A1A; text-decoration: none; display: block; }
.auth-logo p { font-size: .82rem; color: #6B6860; margin-top: .2rem; }
.auth-card { background: #fff; border: 1px solid #F2F0ED; border-radius: 16px; padding: 2rem 1.75rem; box-shadow: 0 4px 24px rgba(0,0,0,.07); }
.auth-card h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.75rem; margin-bottom: .3rem; }
.auth-sub { font-size: .875rem; color: #6B6860; margin-bottom: 1.5rem; }
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #1A1A1A; margin-bottom: .35rem; }
.form-group input { width: 100%; padding: .72rem 1rem; border: 1.5px solid #C8C5BF; border-radius: 8px; font-size: .95rem; font-family: 'Jost', sans-serif; outline: none; transition: border-color .2s; }
.form-group input:focus { border-color: #C9A84C; box-shadow: 0 0 0 3px rgba(201,168,76,.13); }
.btn-register { width: 100%; padding: .85rem; background: #1A1A1A; color: #fff; border: none; border-radius: 8px; font-family: 'Jost', sans-serif; font-size: .9rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; cursor: pointer; margin-top: .5rem; transition: background .2s; }
.btn-register:hover { background: #C9A84C; }
.alert-error { background: #FDF0EF; border: 1px solid #E8BFBC; color: #C0392B; border-radius: 8px; padding: .8rem 1rem; font-size: .875rem; margin-bottom: 1.25rem; }
.alert-error p { margin: .2rem 0; }
.auth-footer { text-align: center; margin-top: 1.25rem; font-size: .875rem; color: #6B6860; }
.auth-footer a { color: #C9A84C; font-weight: 500; text-decoration: none; }
@media (max-width: 400px) {
    .auth-card { padding: 1.5rem 1.1rem; }
}
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-logo">
    <a href="../index.php"><?= SITE_NAME ?></a>
    <p>Rotational Savings — Ajo/Esusu</p>
  </div>
  <div class="auth-card">
    <h2>Create Account</h2>
    <p class="auth-sub">Join a group and start saving together.</p>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
      <?php foreach ($errors as $e): ?>
        <p><?= htmlspecialchars($e) ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name"
               value="<?= htmlspecialchars($name) ?>"
               placeholder="Your full name" required autocomplete="name">
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email"
               value="<?= htmlspecialchars($email) ?>"
               placeholder="your@email.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone"
               value="<?= htmlspecialchars($phone) ?>"
               placeholder="08012345678" required autocomplete="tel">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password"
               placeholder="Minimum 6 characters" required autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm"
               placeholder="Repeat password" required autocomplete="new-password">
      </div>
      <p style="font-size:.78rem;color:#6B6860;margin-bottom:.9rem;">
        By creating an account, you agree to our
        <a href="terms.php" style="color:#C9A84C;">Terms of Service</a> and
        <a href="privacy.php" style="color:#C9A84C;">Privacy Policy</a>.
      </p>
      <button type="submit" class="btn-register">Create Account</button>
    </form>

    <div class="auth-footer">
      Already have an account? <a href="login.php">Sign in</a>
    </div>
  </div>
</div>
</body>
</html>
