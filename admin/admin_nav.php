<?php
$cur = basename($_SERVER['PHP_SELF']);
?>
<nav class="admin-navbar-wrap">
  <div class="admin-nav-inner">
    <div class="admin-nav-left">
      <a href="index.php" class="admin-nav-brand">
        <?= SITE_NAME ?> <span class="admin-badge">Admin</span>
      </a>
    </div>
    <button class="admin-nav-toggle" id="adminNavToggle" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <div class="admin-nav-links" id="adminNavLinks">
      <a href="index.php"           class="<?= $cur==='index.php'          ?'anl-active':'' ?>">Dashboard</a>
      <a href="plans.php"           class="<?= $cur==='plans.php'          ?'anl-active':'' ?>">Plans</a>
      <a href="rotation.php"        class="<?= $cur==='rotation.php'       ?'anl-active':'' ?>">Rotation</a>
      <a href="assign_positions.php"class="<?= $cur==='assign_positions.php'?'anl-active':'' ?>">Assign Positions</a>
      <a href="payout.php"          class="<?= $cur==='payout.php'         ?'anl-active':'' ?>">Payouts</a>
      <a href="payment_status.php"  class="<?= $cur==='payment_status.php' ?'anl-active':'' ?>">Payment Status</a>
      <a href="reminders.php"       class="<?= $cur==='reminders.php'      ?'anl-active':'' ?> anl-gold">&#9993; Reminders</a>
      <a href="reminder_log.php"    class="<?= $cur==='reminder_log.php'   ?'anl-active':'' ?>">Reminder Log</a>
      <a href="add_member.php"      class="<?= $cur==='add_member.php'     ?'anl-active':'' ?>">Add Member</a>
      <a href="users.php"           class="<?= $cur==='users.php'          ?'anl-active':'' ?>">Users</a>
      <a href="verify_cash.php"     class="<?= $cur==='verify_cash.php'    ?'anl-active':'' ?>">Verify Cash</a>
      <a href="export.php"          class="<?= $cur==='export.php'         ?'anl-active':'' ?>">Export</a>
      <a href="change_password.php" class="<?= $cur==='change_password.php'?'anl-active':'' ?>">Change Password</a>
      <a href="../logout.php" class="anl-logout">Logout</a>
    </div>
  </div>
</nav>

<style>
.admin-navbar-wrap {
    background: var(--white); border-bottom: 2px solid var(--gold-light);
    position: sticky; top: 0; z-index: 200; width: 100%;
}
.admin-nav-inner {
    max-width: 1300px; margin: 0 auto; padding: 0 1.25rem;
    height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.admin-nav-brand {
    font-family: var(--font-head); font-size: 1.3rem; font-weight: 600;
    letter-spacing: .1em; color: var(--black); text-decoration: none;
    white-space: nowrap; flex-shrink: 0;
}
.admin-badge {
    display: inline-block; background: var(--gold); color: var(--white);
    font-family: var(--font-body); font-size: .62rem; font-weight: 600;
    letter-spacing: .07em; padding: .12rem .45rem; border-radius: 20px;
    margin-left: .4rem; text-transform: uppercase; vertical-align: middle;
}
.admin-nav-links {
    display: flex; align-items: center; gap: .1rem; flex-wrap: nowrap; overflow-x: auto;
}
.admin-nav-links a {
    font-size: .74rem; font-weight: 500; letter-spacing: .04em; color: var(--gray-text);
    text-transform: uppercase; text-decoration: none; padding: .32rem .5rem;
    border-radius: 5px; white-space: nowrap; transition: var(--transition);
}
.admin-nav-links a:hover  { color: var(--black); background: var(--gray-light); }
.admin-nav-links .anl-active { color: var(--black); font-weight: 700; }
.admin-nav-links .anl-gold   { color: var(--gold);  font-weight: 700; }
.admin-nav-links .anl-logout { color: var(--error) !important; }
.admin-nav-toggle {
    display: none; flex-direction: column; gap: 5px;
    cursor: pointer; padding: .4rem; background: none; border: none; flex-shrink: 0;
}
.admin-nav-toggle span {
    display: block; width: 22px; height: 2px; background: var(--black);
    border-radius: 2px; transition: all .25s ease;
}
@media (max-width: 768px) {
    .admin-nav-toggle { display: flex; }
    .admin-nav-links {
        display: none; position: fixed; top: 56px; left: 0; right: 0;
        background: var(--white); flex-direction: column; padding: .5rem 0 1rem;
        border-bottom: 2px solid var(--gold-light); box-shadow: 0 8px 20px rgba(0,0,0,.1);
        z-index: 199; overflow-x: visible; gap: 0;
    }
    .admin-nav-links.open { display: flex; }
    .admin-nav-links a { padding: .75rem 1.5rem; font-size: .9rem; border-radius: 0; border-bottom: 1px solid var(--gray-light); width: 100%; }
    .admin-nav-links a:last-child { border-bottom: none; }
}
</style>

<script>
(function(){
    var toggle = document.getElementById('adminNavToggle');
    var links  = document.getElementById('adminNavLinks');
    if (!toggle || !links) return;
    toggle.addEventListener('click', function() {
        links.classList.toggle('open');
        var sp = this.querySelectorAll('span');
        if (links.classList.contains('open')) {
            sp[0].style.transform = 'rotate(45deg) translate(5px,5px)';
            sp[1].style.opacity   = '0';
            sp[2].style.transform = 'rotate(-45deg) translate(5px,-5px)';
        } else {
            sp[0].style.transform = sp[2].style.transform = '';
            sp[1].style.opacity   = '';
        }
    });
    links.querySelectorAll('a').forEach(function(a){
        a.addEventListener('click', function(){
            links.classList.remove('open');
            var sp = toggle.querySelectorAll('span');
            sp[0].style.transform = sp[2].style.transform = '';
            sp[1].style.opacity   = '';
        });
    });
})();
</script>
