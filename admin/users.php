<?php
// FILE: admin/users.php — REPLACE existing
require_once '../config.php'; require_once '../includes/db.php';
require_once '../includes/auth.php'; require_once '../includes/functions.php';
requireAdmin();
if(isset($_GET['action'],$_GET['uid'])){$uid=intval($_GET['uid']);$action=$_GET['action'];if(in_array($action,['suspend','activate'])){$status=$action==='suspend'?'suspended':'active';$s=$conn->prepare("UPDATE users SET status=? WHERE id=? AND role='user'");$s->bind_param("si",$status,$uid);$s->execute();$s->close();setFlash($action==='suspend'?'error':'success','User '.($status==='suspended'?'suspended':'activated').'.');}header("Location: users.php");exit();}
$search=trim($_GET['s']??'');$page=max(1,intval($_GET['page']??1));$per=15;$offset=($page-1)*$per;
$where="WHERE u.role='user'";$params=[];$types='';
if(!empty($search)){$like='%'.$search.'%';$where.=" AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.user_code LIKE ?)";$params=[$like,$like,$like,$like];$types='ssss';}
$cs=$conn->prepare("SELECT COUNT(*) AS c FROM users u $where");if(!empty($params))$cs->bind_param($types,...$params);$cs->execute();$total=$cs->get_result()->fetch_assoc()['c'];$cs->close();$total_pages=ceil($total/$per);
$sql="SELECT u.*,COUNT(DISTINCT c.id) AS groups_joined,COALESCE(SUM(CASE WHEN pay.status='paid' THEN pay.amount ELSE 0 END),0) AS total_paid FROM users u LEFT JOIN contributions c ON c.user_id=u.id AND c.status!='removed' LEFT JOIN payments pay ON pay.contribution_id=c.id $where GROUP BY u.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$ap=array_merge($params,[$per,$offset]);$at=$types.'ii';$stmt=$conn->prepare($sql);$stmt->bind_param($at,...$ap);$stmt->execute();$users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$flash=getFlash();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Users — <?=SITE_NAME?></title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="inner-page"><?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container">
<?php if($flash): ?><div class="alert alert-<?=$flash['type']?>" style="margin-bottom:1.5rem;"><p><?=htmlspecialchars($flash['message'])?></p></div><?php endif; ?>
<div class="page-header"><h1>All Members</h1><p><?=$total?> member(s).</p></div>
<div class="search-filter-bar"><form method="GET" class="search-form"><input type="text" name="s" class="search-input" placeholder="Search name, email, phone, or member ID..." value="<?=htmlspecialchars($search)?>"><button type="submit" class="btn btn-primary">Search</button><?php if(!empty($search)): ?><a href="users.php" class="btn btn-outline">Clear</a><?php endif; ?></form></div>
<div class="table-wrapper"><table class="data-table"><thead><tr><th>Member ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Groups</th><th>Total Paid</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody>
<?php foreach($users as $u): ?>
<tr><td><code class="user-code-badge"><?=htmlspecialchars($u['user_code']??'—')?></code></td><td><strong><?=htmlspecialchars($u['name'])?></strong></td><td><?=htmlspecialchars($u['email'])?></td><td><?=htmlspecialchars($u['phone'])?></td><td><?=$u['groups_joined']?></td><td><?=formatMoney($u['total_paid'])?></td><td><span class="status-badge status-badge--<?=$u['status']==='active'?'active':'paused'?>"><?=ucfirst($u['status'])?></span></td><td><?=date('M d, Y',strtotime($u['created_at']))?></td><td><?php if($u['status']==='active'): ?><a href="users.php?action=suspend&uid=<?=$u['id']?>" class="btn-action btn-action--delete" onclick="return confirm('Suspend <?=addslashes(htmlspecialchars($u['name']))?>?')">Suspend</a><?php else: ?><a href="users.php?action=activate&uid=<?=$u['id']?>" class="btn-action btn-action--edit" onclick="return confirm('Activate <?=addslashes(htmlspecialchars($u['name']))?>?')">Activate</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if(empty($users)): ?><tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--gray-text)">No members found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if($total_pages>1): ?><div class="pagination"><?php for($i=1;$i<=$total_pages;$i++): ?><a href="?s=<?=urlencode($search)?>&page=<?=$i?>" class="page-btn <?=$i===$page?'page-btn--active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
</div></main></body></html>
