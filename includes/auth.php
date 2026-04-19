<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function isLoggedIn()  { return isset($_SESSION['user_id']); }
function isAdmin()     { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . SITE_URL . "/pages/login.php"); exit();
    }
    if (isset($_SESSION['status']) && $_SESSION['status'] === 'suspended') {
        session_destroy();
        header("Location: " . SITE_URL . "/pages/login.php?err=suspended"); exit();
    }
}
function requireGuest() {
    if (isLoggedIn()) { header("Location: " . SITE_URL . "/pages/dashboard.php"); exit(); }
}
function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header("Location: " . SITE_URL . "/pages/login.php"); exit();
    }
}
function setFlash($type, $message) { $_SESSION['flash'] = ['type'=>$type,'message'=>$message]; }
function getFlash() {
    if (isset($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}
?>
