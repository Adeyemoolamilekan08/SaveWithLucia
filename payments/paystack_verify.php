<?php
// ============================================================
// FILE: payments/paystack_verify.php
// INSTRUCTION: NEW FILE — copy into /swl/payments/paystack_verify.php
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireLogin();

$user_id   = $_SESSION['user_id'];
$cid       = intval($_GET['cid'] ?? 0);
$reference = trim($_GET['reference'] ?? '');
if ($cid<=0||empty($reference)) { setFlash('error','Invalid verification.'); header("Location: ".SITE_URL."/pages/dashboard.php"); exit(); }

$stmt = $conn->prepare("SELECT c.*,p.name AS plan_name,p.contribution_amount FROM contributions c JOIN plans p ON c.plan_id=p.id WHERE c.id=? AND c.user_id=?");
$stmt->bind_param("ii",$cid,$user_id); $stmt->execute();
$contrib = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$contrib) { setFlash('error','Not found.'); header("Location: ".SITE_URL."/pages/dashboard.php"); exit(); }

$stmt = $conn->prepare("SELECT email,name,user_code,phone FROM users WHERE id=?");
$stmt->bind_param("i",$user_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

$ch = curl_init("https://api.paystack.co/transaction/verify/".urlencode($reference));
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_HTTPHEADER,["Authorization: Bearer ".PAYSTACK_SECRET_KEY]);
$result = json_decode(curl_exec($ch),true); curl_close($ch);

if ($result && $result['status']===true && $result['data']['status']==='success') {
    $paid_amt = $result['data']['amount']/100;
    $paid_at  = date('Y-m-d H:i:s');
    $chk = $conn->prepare("SELECT id FROM payments WHERE reference=?");
    $chk->bind_param("s",$reference); $chk->execute(); $chk->store_result();
    $already = $chk->num_rows>0; $chk->close();
    if (!$already) {
        $ins = $conn->prepare("INSERT INTO payments (contribution_id,reference,amount,status,paid_at) VALUES (?,?,?,'paid',?)");
        $ins->bind_param("isds",$cid,$reference,$paid_amt,$paid_at); $ins->execute(); $ins->close();
        sendPaymentConfirmationEmail($conn,$user_id,$user['email'],$user['name'],$user['user_code']??'',$contrib['plan_name'],$paid_amt,'online',$reference,$paid_at,$contrib['position'],$contrib['collection_date'],$contrib['payout_amount']);
    }
    setFlash('success','Payment of '.formatMoney($paid_amt).' confirmed! Check your email for a receipt.');
} else {
    $ins = $conn->prepare("INSERT INTO payments (contribution_id,reference,amount,status) VALUES (?,?,?,'failed')");
    $amt=$contrib['contribution_amount']; $ins->bind_param("isd",$cid,$reference,$amt); $ins->execute(); $ins->close();
    setFlash('error','Payment could not be verified. Please try again.');
}
header("Location: ".SITE_URL."/pages/dashboard.php"); exit();
?>
