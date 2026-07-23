<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireAdmin();

$errors = []; $today = date('Y-m-d');

$open_plans = $conn->query(
    "SELECT p.id, p.name, p.contribution_amount, p.frequency_days,
            p.total_participants, p.plan_start_date, p.plan_status,
            (SELECT COUNT(*) FROM contributions c WHERE c.plan_id=p.id AND c.status='active') AS slots_filled
     FROM plans p WHERE p.is_active=1 AND p.plan_status IN ('open','active')
     ORDER BY p.name ASC"
)->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $password    = trim($_POST['password']    ?? '');
    $notify      = isset($_POST['notify_member']);
    $assign_plan = intval($_POST['assign_plan']     ?? 0);
    $assign_pos  = intval($_POST['assign_position'] ?? 0);
    $pay_method  = $_POST['payment_method'] ?? 'cash';

    if (empty($name))          $errors[] = 'Full name is required.';
    if (empty($email))         $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if (strlen($phone) < 10)   $errors[] = 'Enter a valid phone number.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $chk = $conn->prepare("SELECT id FROM users WHERE email=?");
        $chk->bind_param("s", $email); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) $errors[] = 'A member with that email already exists.';
        $chk->close();
    }

    $plan_data = null;
    if (empty($errors) && $assign_plan > 0) {
        $ps = $conn->prepare("SELECT * FROM plans WHERE id=? AND is_active=1");
        $ps->bind_param("i", $assign_plan); $ps->execute();
        $plan_data = $ps->get_result()->fetch_assoc(); $ps->close();
        if (!$plan_data) $errors[] = 'Selected plan not found.';
        if ($assign_pos > 0) {
            $pchk = $conn->query("SELECT id FROM contributions WHERE plan_id=$assign_plan AND position=$assign_pos AND status!='removed'");
            if ($pchk->num_rows > 0) $errors[] = "Position $assign_pos is already taken. Choose another.";
        }
    }

    if (empty($errors)) {
        $user_code = generateUserCode($conn);
        $hash      = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (user_code,name,email,phone,password,role,status) VALUES (?,?,?,?,?,'user','active')");
        $ins->bind_param("sssss", $user_code, $name, $email, $phone, $hash);

        if ($ins->execute()) {
            $new_uid = $ins->insert_id; $ins->close();
            $position = null; $col_date = null; $payout = null;

            if ($assign_plan > 0 && $plan_data) {
                $position = ($assign_pos > 0) ? $assign_pos
                    : getNextAvailablePosition($conn, $assign_plan, $plan_data['total_participants']);

                if ($position) {
                    $start = (!empty($plan_data['plan_start_date']) && $plan_data['plan_start_date'] !== '0000-00-00' && strtotime($plan_data['plan_start_date']) > 0)
                        ? $plan_data['plan_start_date'] : $today;
                    $col_date = calculateCollectionDate($start, $plan_data['frequency_days'], $position);
                    $payout   = calculatePayoutAmount($plan_data['contribution_amount'], $plan_data['total_participants']);
                    $ic = $conn->prepare("INSERT INTO contributions (user_id,plan_id,position,collection_date,payout_amount,payment_method,status,has_collected) VALUES (?,?,?,?,?,?,'active',0)");
                    $ic->bind_param("iiisds", $new_uid, $assign_plan, $position, $col_date, $payout, $pay_method);
                    $ic->execute(); $ic->close();
                    $conn->query("INSERT IGNORE INTO payout_schedule (plan_id,user_id,position,payout_date) VALUES ($assign_plan,$new_uid,$position," . ($col_date?"'$col_date'":"NULL") . ")");
                    $filled = getPlanMemberCount($conn, $assign_plan);
                    if (!empty($plan_data['plan_start_date']) && $filled >= $plan_data['total_participants'])
                        $conn->query("UPDATE plans SET plan_status='active' WHERE id=$assign_plan AND plan_status='open'");
                }
            }

            if ($notify) sendAdminWelcomeEmail($email, $name, $user_code, $password, $plan_data, $position, $col_date, $payout);

            $plan_msg = $position ? " Assigned to <strong>{$plan_data['name']}</strong> Position <strong>$position</strong>." : '';
            $mail_msg = $notify ? ' Welcome email sent.' : '';
            setFlash('success', "Member <strong>$name</strong> created. ID: <strong>$user_code</strong>.$plan_msg$mail_msg");
            header("Location: add_member.php"); exit();
        }
        $errors[] = 'Could not create account.';
    }
}

function sendAdminWelcomeEmail($email, $name, $user_code, $plain_pwd, $plan_data, $position, $col_date, $payout) {
    $site = SITE_NAME; $url = SITE_URL;
    $plan_section = '';
    if ($plan_data && $position) {
        $col_str  = $col_date ? date('F j, Y', strtotime($col_date)) : 'TBD';
        $pay_str  = $payout ? formatMoney($payout) : '';
        $freq_str = formatFrequency($plan_data['frequency_days']);
        $plan_section = "<div style='background:#FDFAF3;border:1px solid #E8D5A3;border-radius:8px;padding:16px;margin-top:16px;'>
            <p style='margin:0 0 8px;font-weight:700;color:#C9A84C;'>Your Group Assignment</p>
            <p style='margin:3px 0;font-size:14px;'>Group: <strong>{$plan_data['name']}</strong></p>
            <p style='margin:3px 0;font-size:14px;'>Frequency: <strong>$freq_str</strong></p>
            <p style='margin:3px 0;font-size:14px;'>Position: <strong>$position</strong></p>
            <p style='margin:3px 0;font-size:14px;'>Collection Date: <strong>$col_str</strong></p>
            " . ($pay_str ? "<p style='margin:3px 0;font-size:14px;'>You Will Receive: <strong style='color:#C9A84C;'>$pay_str</strong></p>" : "") . "
        </div>";
    }
    $html = "<div style='font-family:sans-serif;max-width:520px;margin:0 auto;padding:20px;'>
        <h2 style='font-family:Georgia,serif;'>Welcome to $site, $name!</h2>
        <p>Your admin has created an account for you. Your login details:</p>
        <div style='background:#F2F0ED;border-radius:10px;padding:16px;margin:16px 0;'>
            <p style='margin:4px 0;'>Member ID: <strong style='font-family:monospace;color:#534AB7;'>$user_code</strong></p>
            <p style='margin:4px 0;'>Email: <strong>$email</strong></p>
            <p style='margin:4px 0;'>Password: <strong style='font-size:16px;letter-spacing:.05em;'>$plain_pwd</strong></p>
        </div>
        <p style='font-size:12px;color:#999;'>Please change your password after your first login.</p>
        $plan_section
        <div style='margin-top:20px;text-align:center;'>
            <a href='$url/pages/login.php' style='background:#C9A84C;color:#fff;padding:13px 32px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;'>Login to Your Account</a>
        </div>
    </div>";
    sendEmail($email, $name, "Welcome to $site — Your Login Details", $html);
}

$flash  = getFlash();
$recent = $conn->query("SELECT u.id,u.user_code,u.name,u.email,u.phone,u.created_at,(SELECT COUNT(*) FROM contributions c WHERE c.user_id=u.id) AS plans_joined FROM users u WHERE u.role='user' ORDER BY u.created_at DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Add Member — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260530">
<style>
/* ===== CRITICAL MOBILE FIX ===== */
*, *::before, *::after { box-sizing: border-box !important; }
html, body { overflow-x: hidden !important; max-width: 100% !important; }
.main-content { overflow-x: hidden; }
.container    { overflow-x: hidden; }

/* Grid — single column always, two columns on wide screens */
.add-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
    width: 100%;
}
@media (min-width: 960px) {
    .add-grid { grid-template-columns: 400px 1fr; }
}

/* All form inputs MUST stay in screen */
.add-form input,
.add-form select,
.add-form textarea {
    display: block;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    box-sizing: border-box !important;
}
.add-form .form-hint { word-break: break-word; white-space: normal; }
.add-form .checkbox-label { white-space: normal; word-break: break-word; }

/* Position grid */
.pos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(38px,1fr));
    gap: .35rem; margin-top: .5rem; width: 100%;
}
.pos-slot {
    height: 38px; border-radius: 7px; border: 1.5px solid var(--gray-mid);
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 600; cursor: pointer;
    transition: var(--transition); background: var(--white);
}
.pos-slot.taken    { background:#FEF2F2;color:var(--error);border-color:#FECACA;cursor:not-allowed; }
.pos-slot.selected { background:var(--gold);color:#fff;border-color:var(--gold); }
.pos-slot.free:hover { border-color:var(--gold);background:#FDFAF3; }

/* Plan info */
.plan-box {
    background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;
    padding:.6rem .85rem;font-size:.82rem;color:#1E40AF;
    margin-top:.4rem;display:none;word-break:break-word;
}
.plan-box.show { display:block; }

/* Table fix */
.admin-table-panel { width:100%;overflow-x:hidden; }
.table-wrapper { overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%; }
.data-table { min-width:360px; }
.data-table td { word-break:break-word;max-width:150px; }
small { display:block;word-break:break-all; }

@media (max-width:640px) {
    .admin-form-panel,.admin-table-panel { padding:1rem !important; }
    .data-table th:last-child,.data-table td:last-child { display:none; }
}
</style>
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">

<div class="page-header">
    <h1>Add Member</h1>
    <p>Create a member account and optionally assign them to a plan.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><p><?= $flash['message'] ?></p></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error"><?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<div class="add-grid">
  <!-- FORM -->
  <div class="admin-form-panel add-form">
    <h2>Member Details</h2>
    <form method="POST" action="">

      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name']??'') ?>" placeholder="Full name" required>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email']??'') ?>" placeholder="member@email.com" required>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone']??'') ?>" placeholder="08012345678" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="text" name="password" value="<?= htmlspecialchars($_POST['password']??'') ?>" placeholder="Min. 6 characters" required>
        <span class="form-hint">Shown as plain text so you can share it with the member.</span>
      </div>

      <hr style="border:none;border-top:1px solid var(--gray-light);margin:1.25rem 0;">
      <h3 style="font-size:.95rem;margin-bottom:.85rem;font-family:var(--font-body);font-weight:600;">
        Assign to Plan <span style="font-weight:400;color:var(--gray-text);font-size:.8rem;">(optional)</span>
      </h3>

      <div class="form-group">
        <label>Select Plan</label>
        <select name="assign_plan" id="planSel">
          <option value="0">— Do not assign to any plan —</option>
          <?php foreach ($open_plans as $p):
            $left = $p['total_participants'] - $p['slots_filled'];
          ?>
          <option value="<?= $p['id'] ?>"
                  data-total="<?= $p['total_participants'] ?>"
                  data-name="<?= htmlspecialchars($p['name']) ?>"
                  data-amount="<?= formatMoney($p['contribution_amount']) ?>"
                  data-freq="<?= htmlspecialchars(formatFrequency($p['frequency_days'])) ?>"
                  <?= (isset($_POST['assign_plan']) && intval($_POST['assign_plan'])===$p['id'])? 'selected':'' ?>>
            <?= htmlspecialchars($p['name']) ?> (<?= $left ?> slot<?= $left!==1?'s':'' ?> left)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="plan-box" id="planBox"></div>

      <div id="posSection" style="display:none;" class="form-group">
        <label>Choose Position <span style="font-weight:400;font-size:.78rem;color:var(--gray-text);">(optional — leave blank for auto)</span></label>
        <input type="hidden" name="assign_position" id="posInput" value="0">
        <div class="pos-grid" id="posGrid"></div>
        <div id="posLbl" style="font-size:.78rem;color:var(--gray-text);margin-top:.35rem;">No position selected — will auto-assign.</div>
      </div>

      <div id="paySection" style="display:none;" class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <option value="cash">Cash</option>
          <option value="online">Online (Paystack)</option>
        </select>
      </div>

      <hr style="border:none;border-top:1px solid var(--gray-light);margin:1.25rem 0;">
      <div class="form-group form-group--checkbox">
        <label class="checkbox-label">
          <input type="checkbox" name="notify_member" value="1" checked>
          Send welcome email with login details and plan info
        </label>
      </div>
      <button type="submit" class="btn btn-gold btn-full">✓ Create Member Account</button>
    </form>
  </div>

  <!-- RECENTLY ADDED -->
  <div class="admin-table-panel">
    <h2>Recently Added (<?= count($recent) ?>)</h2>
    <?php if (empty($recent)): ?>
    <div class="empty-state"><p>No members yet.</p></div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Plans</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
          <tr>
            <td><code class="user-code-badge"><?= htmlspecialchars($r['user_code']??'—') ?></code></td>
            <td>
              <strong><?= htmlspecialchars($r['name']) ?></strong>
              <small><?= htmlspecialchars($r['email']) ?></small>
            </td>
            <td><?= htmlspecialchars($r['phone']) ?></td>
            <td style="text-align:center;"><strong><?= $r['plans_joined'] ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:1rem;">
      <a href="users.php" class="btn btn-outline" style="font-size:.85rem;padding:.6rem 1.25rem;">View All Members →</a>
    </div>
    <?php endif; ?>
  </div>
</div>

</div></main>

<script>
var planData = <?php
  $js = [];
  foreach ($open_plans as $p) {
      $taken = $conn->query("SELECT position FROM contributions WHERE plan_id={$p['id']} AND status!='removed'")->fetch_all(MYSQLI_ASSOC);
      $js[$p['id']] = ['name'=>$p['name'],'total'=>intval($p['total_participants']),'amount'=>formatMoney($p['contribution_amount']),'freq'=>formatFrequency($p['frequency_days']),'taken'=>array_column($taken,'position')];
  }
  echo json_encode($js);
?>;
(function(){
  var sel=document.getElementById('planSel'),box=document.getElementById('planBox'),
      posS=document.getElementById('posSection'),payS=document.getElementById('paySection'),
      grid=document.getElementById('posGrid'),inp=document.getElementById('posInput'),
      lbl=document.getElementById('posLbl');
  function update(){
    var pid=parseInt(sel.value);
    if(!pid||!planData[pid]){box.classList.remove('show');posS.style.display=payS.style.display='none';inp.value='0';return;}
    var p=planData[pid],left=p.total-p.taken.length;
    box.innerHTML='<strong>'+p.name+'</strong> &bull; '+p.amount+'/cycle &bull; '+p.freq+' &bull; '+left+' slot(s) left';
    box.classList.add('show');
    grid.innerHTML='';inp.value='0';lbl.textContent='No position selected — will auto-assign.';lbl.style.color='';
    for(var i=1;i<=p.total;i++){
      var t=p.taken.indexOf(i)>=0,b=document.createElement('div');
      b.className='pos-slot '+(t?'taken':'free');b.textContent=i;b.dataset.pos=i;b.dataset.taken=t?'1':'0';
      if(!t)b.addEventListener('click',function(){
        grid.querySelectorAll('.pos-slot.free').forEach(function(s){s.classList.remove('selected');});
        if(inp.value===this.dataset.pos){inp.value='0';lbl.textContent='No position selected — will auto-assign.';lbl.style.color='';}
        else{this.classList.add('selected');inp.value=this.dataset.pos;lbl.textContent='Selected: Position '+this.dataset.pos;lbl.style.color='var(--gold)';}
      });
      grid.appendChild(b);
    }
    posS.style.display=payS.style.display='block';
  }
  sel.addEventListener('change',update);
  if(parseInt(sel.value)>0)update();
})();
</script>
</body>
</html>
