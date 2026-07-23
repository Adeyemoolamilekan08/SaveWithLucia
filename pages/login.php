<?php
// ============================================================
// pages/login.php — REPLACE existing file
// Adds: CSRF token check, must_change_password flag on session
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// Already logged in — redirect correctly based on role
if (isLoggedIn()) {
    if (isAdmin()) redirectTo(SITE_URL . "/admin/index.php");
    else           redirectTo(SITE_URL . "/pages/dashboard.php");
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFToken();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $lockedMinutes = empty($email) ? false : isLoginLocked($conn, $email);

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } elseif ($lockedMinutes !== false) {
        $error = "Too many failed attempts. Please try again in {$lockedMinutes} minute(s).";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, email, password, role, status, user_code, must_change_password
             FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            recordFailedLogin($conn, $email);
            $error = 'No account found with that email address.';
        } elseif ($user['status'] === 'suspended') {
            $error = 'Your account has been suspended. Please contact admin.';
        } elseif (!password_verify($password, $user['password'])) {
            recordFailedLogin($conn, $email);
            $error = 'Incorrect password. Please try again.';
        } else {
            clearFailedLogins($conn, $email);
            // Destroy any existing session first — prevents session fixation
            // and ensures no old admin session bleeds into a new user login
            session_unset();
            session_destroy();

            // Start fresh session
            session_set_cookie_params([
                'lifetime' => 86400,
                'path'     => '/',
                'domain'   => parse_url(SITE_URL, PHP_URL_HOST),
                'secure'   => (strpos(SITE_URL, 'https') === 0),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_only_cookies', 1);
            session_name('SWL_SESSION');
            session_start();
            session_regenerate_id(true);

            // Set session variables
            $_SESSION['user_id']              = $user['id'];
            $_SESSION['name']                 = $user['name'];
            $_SESSION['email']                = $user['email'];
            $_SESSION['role']                 = $user['role'];
            $_SESSION['status']               = $user['status'];
            $_SESSION['user_code']            = $user['user_code'] ?? '';
            $_SESSION['must_change_password'] = (int) ($user['must_change_password'] ?? 0);

            // Update last login
            $upd = $conn->prepare("UPDATE users SET last_login=NOW() WHERE id=?");
            $upd->bind_param("i", $user['id']); $upd->execute(); $upd->close();

            setFlash('success', 'Welcome back, ' . $user['name'] . '!');

            // Redirect based on role — admin to admin panel, user to dashboard
            if ($user['role'] === 'admin') {
                redirectTo(SITE_URL . "/admin/index.php");
            } else {
                redirectTo(SITE_URL . "/pages/dashboard.php");
            }
        }
    }
}

if (isset($_GET['err']) && $_GET['err'] === 'suspended') {
    $error = 'Your account has been suspended. Please contact admin.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Sign In — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260530">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Jost',sans-serif;background:#FAF9F7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem 1rem;}
.wrap{width:100%;max-width:420px;margin:0 auto;}
.logo{text-align:center;margin-bottom:1.75rem;}
.logo a{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:600;letter-spacing:.15em;color:#1A1A1A;text-decoration:none;display:block;}
.logo p{font-size:.82rem;color:#6B6860;margin-top:.2rem;}
.card{background:#fff;border:1px solid #F2F0ED;border-radius:16px;padding:2rem 1.75rem;box-shadow:0 4px 24px rgba(0,0,0,.07);}
.card h2{font-family:'Cormorant Garamond',serif;font-size:1.75rem;margin-bottom:.3rem;}
.sub{font-size:.875rem;color:#6B6860;margin-bottom:1.5rem;}
.fg{margin-bottom:1.1rem;}
.fg label{display:block;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#1A1A1A;margin-bottom:.4rem;}
.fg input{width:100%;padding:.75rem 1rem;border:1.5px solid #C8C5BF;border-radius:8px;font-size:.95rem;font-family:'Jost',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box;}
.fg input:focus{border-color:#C9A84C;box-shadow:0 0 0 3px rgba(201,168,76,.13);}
.btn-login{width:100%;padding:.85rem;background:#1A1A1A;color:#fff;border:none;border-radius:8px;font-family:'Jost',sans-serif;font-size:.9rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;margin-top:.5rem;transition:background .2s;}
.btn-login:hover{background:#C9A84C;}
.alert-error{background:#FDF0EF;border:1px solid #E8BFBC;color:#C0392B;border-radius:8px;padding:.8rem 1rem;font-size:.875rem;margin-bottom:1.25rem;}
.foot{text-align:center;margin-top:.85rem;font-size:.875rem;color:#6B6860;}
.foot a{color:#C9A84C;font-weight:500;text-decoration:none;}
@media(max-width:400px){.card{padding:1.5rem 1.1rem;}}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <a href="../index.php"><?= SITE_NAME ?></a>
    <p>Rotational Savings — Ajo/Esusu</p>
  </div>
  <div class="card">
    <h2>Sign In</h2>
    <p class="sub">Welcome back. Enter your details to continue.</p>

    <?php if ($error): ?>
    <div class="alert-error"><p><?= htmlspecialchars($error) ?></p></div>
    <?php endif; ?>

    <form method="POST" action="">
      <?php csrfField(); ?>
      <div class="fg">
        <label>Email Address</label>
        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>"
               placeholder="your@email.com" required autocomplete="email">
      </div>
      <div class="fg">
        <label>Password</label>
        <input type="password" name="password"
               placeholder="Enter your password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn-login">Sign In</button>
    </form>

    <div class="foot" style="margin-top:.75rem;">
      <a href="forgot_password.php" style="color:#6B6860;font-size:.82rem;">Forgot your password?</a>
    </div>
    <div class="foot">
      Don't have an account? <a href="register.php">Create one free</a>
    </div>
  </div>
</div>
</body>
</html>
