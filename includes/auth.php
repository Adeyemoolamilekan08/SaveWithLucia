<?php
// ============================================================
// includes/auth.php — REPLACE existing file
// Adds: forced password change redirect for admin accounts
// ============================================================

if (session_status() === PHP_SESSION_NONE) {

    // Adapts to whichever host you're actually running on (localhost while
    // developing, the live domain in production) instead of hardcoding the
    // production domain — hardcoding it broke sessions (and CSRF) on localhost,
    // since browsers won't set/send a cookie for a domain that doesn't match
    // the one you're actually visiting.
    $cookieDomain = defined('SITE_URL') ? parse_url(SITE_URL, PHP_URL_HOST) : '';
    $isHttps      = defined('SITE_URL') && strpos(SITE_URL, 'https') === 0;

    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => ($cookieDomain === 'localhost') ? '' : $cookieDomain,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode',  1);
    ini_set('session.gc_maxlifetime',   86400);

    session_name('SWL_SESSION');
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}
function redirectTo($url) {
    $url = rtrim($url, '/');
    if (headers_sent()) {
        echo '<script>window.location.href="' . addslashes($url) . '";</script>';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
    } else {
        header("Location: " . $url, true, 302);
    }
    exit();
}
function requireLogin() {
    if (!isLoggedIn()) {
        redirectTo(SITE_URL . "/pages/login.php");
    }
    if (!empty($_SESSION['status']) && $_SESSION['status'] === 'suspended') {
        session_destroy();
        redirectTo(SITE_URL . "/pages/login.php?err=suspended");
    }
}
function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        redirectTo(SITE_URL . "/pages/login.php");
    }
    // Force a password change before allowing access to anything
    // else in the admin panel, except the change-password page itself.
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!empty($_SESSION['must_change_password']) && $current !== 'change_password.php') {
        redirectTo(SITE_URL . "/admin/change_password.php?forced=1");
    }
}
function requireGuest() {
    if (isLoggedIn()) {
        redirectTo(isAdmin()
            ? SITE_URL . "/admin/index.php"
            : SITE_URL . "/pages/dashboard.php");
    }
}
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
?>
