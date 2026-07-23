<?php
// ============================================================
// admin/audit_log.php — NEW FILE
// Shows a simple, most-recent-first log of payment/payout actions
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$logs = $conn->query(
    "SELECT al.*, u.name AS admin_name
     FROM audit_log al
     JOIN users u ON al.admin_id = u.id
     ORDER BY al.created_at DESC
     LIMIT 200"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Audit Log — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<style>
.audit-table{width:100%;border-collapse:collapse;margin-top:1rem;}
.audit-table th,.audit-table td{padding:.6rem .8rem;border-bottom:1px solid #F2F0ED;font-size:.85rem;text-align:left;}
.audit-table th{text-transform:uppercase;font-size:.72rem;letter-spacing:.05em;color:#6B6860;}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;}
.badge-cash{background:#EDF7F1;color:#1E7E4A;}
.badge-payout{background:#FDF3E3;color:#B8860B;}
</style>
</head>
<body>
<div style="max-width:960px;margin:2rem auto;padding:0 1rem;">
  <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.75rem;margin-bottom:.25rem;">Audit Log</h2>
  <p style="color:#6B6860;font-size:.85rem;margin-bottom:.5rem;">Most recent 200 payment/payout admin actions.</p>
  <table class="audit-table">
    <thead>
      <tr><th>When</th><th>Admin</th><th>Action</th><th>Details</th></tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
      <tr><td colspan="4" style="text-align:center;color:#6B6860;padding:2rem 0;">No actions logged yet.</td></tr>
      <?php else: foreach ($logs as $log): ?>
      <tr>
        <td><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
        <td><?= htmlspecialchars($log['admin_name']) ?></td>
        <td>
          <?php $badgeClass = str_contains($log['action'], 'payout') ? 'badge-payout' : 'badge-cash'; ?>
          <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></span>
        </td>
        <td><?= htmlspecialchars($log['details']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="margin-top:1.5rem;"><a href="index.php" style="font-size:.85rem;">← Back to dashboard</a></p>
</div>
</body>
</html>
