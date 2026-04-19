<?php
// ============================================================
// FILE: logout.php
// INSTRUCTION: NEW FILE — copy into /swl/logout.php
// ============================================================
require_once 'config.php';
require_once 'includes/auth.php';
session_destroy();
header("Location: " . SITE_URL . "/pages/login.php?msg=loggedout");
exit();
?>
