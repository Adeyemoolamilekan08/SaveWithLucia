<?php
// ============================================================
// FILE: admin/export.php
// INSTRUCTION: NEW FILE — copy into /swl/admin/export.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$plan_id   = intval($_GET['plan'] ?? 0);
if (isset($_GET['type']) && $_GET['type'] === 'members') {
    exportMembersCSV($conn, $plan_id ?: null);
}

$all_plans = $conn->query("SELECT id, name FROM plans ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Export — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="inner-page">
<?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">
<div class="page-header"><h1>Export Data</h1><p>Download rotation schedules and member data as CSV files.</p></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;max-width:700px;">
  <div class="admin-form-panel" style="text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:1rem;">&#128101;</div>
    <h2 style="margin-bottom:.75rem;">All Members</h2>
    <p style="color:var(--gray-text);font-size:.9rem;margin-bottom:1.5rem;line-height:1.6;">
      All members across every plan — includes position, collection date, payout amount, and whether they have collected.
    </p>
    <a href="export.php?type=members" class="btn btn-primary btn-full">Download All Members CSV</a>
  </div>
  <div class="admin-form-panel" style="text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:1rem;">&#8635;</div>
    <h2 style="margin-bottom:.75rem;">By Plan</h2>
    <p style="color:var(--gray-text);font-size:.9rem;margin-bottom:1rem;line-height:1.6;">
      Rotation schedule for one specific plan only.
    </p>
    <form method="GET" action="">
      <input type="hidden" name="type" value="members">
      <div class="form-group">
        <select name="plan" class="filter-select" style="width:100%;margin-bottom:.75rem;">
          <option value="">— Select a Plan —</option>
          <?php foreach ($all_plans as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Download Plan CSV</button>
    </form>
  </div>
</div>

<div class="admin-form-panel" style="margin-top:1.5rem;max-width:700px;">
  <h2 style="margin-bottom:1rem;">How to open CSV files</h2>
  <div style="font-size:.9rem;color:var(--gray-text);line-height:1.8;">
    <p><strong>Microsoft Excel:</strong> File → Open → select the .csv file</p>
    <p><strong>Google Sheets:</strong> sheets.google.com → New → Import → upload the .csv file</p>
    <p><strong>Numbers (Mac):</strong> Double-click the .csv file — opens automatically</p>
  </div>
</div>
</div></main>
</body>
</html>
