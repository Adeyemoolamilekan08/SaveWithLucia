<?php
// ============================================================
// FILE: admin/admin_nav.php
// INSTRUCTION: NEW FILE — copy into /swl/admin/admin_nav.php
// ============================================================
$cur = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar admin-navbar">
  <div class="nav-container">
    <a href="index.php" class="nav-brand"><?= SITE_NAME ?> <span class="admin-badge">Admin</span></a>
    <div class="nav-links">
      <a href="index.php"          <?= $cur==='index.php'?'class="active"':''          ?>>Dashboard</a>
      <a href="plans.php"          <?= $cur==='plans.php'?'class="active"':''          ?>>Plans</a>
      <a href="rotation.php"       <?= $cur==='rotation.php'?'class="active"':''       ?>>Rotation</a>
      <a href="payout.php"         <?= $cur==='payout.php'?'class="active"':''         ?>>Payouts</a>
      <a href="users.php"          <?= $cur==='users.php'?'class="active"':''          ?>>Users</a>
      <a href="verify_cash.php"    <?= $cur==='verify_cash.php'?'class="active"':''    ?>>Verify Cash</a>
      <a href="export.php"         <?= $cur==='export.php'?'class="active"':''         ?>>Export</a>
      <a href="../logout.php" class="nav-logout">Logout</a>
    </div>
  </div>
</nav>
