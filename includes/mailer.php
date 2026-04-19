<?php
// includes/mailer.php — Email + SMS for SaveWithLucia

$_r = dirname(__DIR__);
if (!class_exists('PHPMailer\PHPMailer\Exception')) require_once $_r.'/vendor/phpmailer/src/Exception.php';
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) require_once $_r.'/vendor/phpmailer/src/PHPMailer.php';
if (!class_exists('PHPMailer\PHPMailer\SMTP'))      require_once $_r.'/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -----------------------------------------------
// CORE EMAIL SEND
// -----------------------------------------------
function sendEmail($to_email, $to_name, $subject, $html) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->Port       = intval(MAIL_PORT);
        $mail->SMTPSecure = (intval(MAIL_PORT)===465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->Timeout    = 15;
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress(trim($to_email), $to_name);
        $mail->isHTML(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>','</p>','</div>'],"\n",$html)));
        $mail->send();
        return ['success'=>true,'error'=>null];
    } catch (Exception $e) {
        return ['success'=>false,'error'=>$mail->ErrorInfo];
    }
}

function logEmail($conn, $user_id, $to_email, $subject, $status) {
    $s = $conn->prepare("INSERT INTO email_logs (user_id,to_email,subject,status) VALUES (?,?,?,?)");
    if ($s) { $s->bind_param("isss",$user_id,$to_email,$subject,$status); $s->execute(); $s->close(); }
}

// -----------------------------------------------
// SMS VIA TERMII
// ============================================================
// WHERE TO ADD YOUR SMS CREDENTIALS:
//   Open config.php and find the SMS SETTINGS section.
//   Fill in TERMII_API_KEY and set SMS_ENABLED = true
//
// HOW TO GET TERMII:
//   1. Go to https://termii.com → sign up free
//   2. Dashboard → copy your API Key
//   3. Create a Sender ID (max 11 chars, e.g. "SaveLucia")
//   4. Paste into config.php and set SMS_ENABLED = true
// ============================================================
function sendSMS($conn, $user_id, $phone, $message) {
    // SMS is disabled by default — enable in config.php
    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        return ['success'=>false,'error'=>'SMS disabled. Set SMS_ENABLED=true in config.php after adding Termii credentials.'];
    }
    if (empty($phone)) {
        return ['success'=>false,'error'=>'No phone number provided.'];
    }

    // Normalise Nigerian phone number to international format
    // 08012345678 → 2348012345678
    $clean = preg_replace('/\D/','',$phone);
    if (strlen($clean)===11 && $clean[0]==='0') {
        $clean = '234'.substr($clean,1);
    }

    $payload = json_encode([
        'to'      => $clean,
        'from'    => TERMII_SENDER_ID,
        'sms'     => $message,
        'type'    => 'plain',
        'channel' => 'generic',
        'api_key' => TERMII_API_KEY,
    ]);

    $ch = curl_init('https://api.ng.termii.com/api/sms/send');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json','Content-Length: '.strlen($payload)]);
    curl_setopt($ch,CURLOPT_TIMEOUT,15);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $result = ['success'=>false,'error'=>$err];
    } else {
        $data   = json_decode($response,true);
        $result = ['success'=>isset($data['message_id']),'response'=>$data];
    }

    // Log the SMS attempt
    $status = $result['success'] ? 'sent' : 'failed';
    $s = $conn->prepare("INSERT INTO sms_logs (user_id,phone,message,status,provider) VALUES (?,?,?,?,'termii')");
    if ($s) { $s->bind_param("isss",$user_id,$clean,$message,$status); $s->execute(); $s->close(); }

    return $result;
}

// -----------------------------------------------
// HTML EMAIL WRAPPER
// -----------------------------------------------
function buildEmailWrapper($site, $content) {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#F4F4F4;font-family:Arial,Helvetica,sans-serif;padding:20px;}
.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);}
.hdr{background:#1A1A1A;padding:26px 36px;text-align:center;}
.hdr h1{color:#C9A84C;font-size:20px;font-weight:normal;letter-spacing:3px;}
.hdr p{color:rgba(255,255,255,.4);font-size:11px;letter-spacing:2px;margin-top:5px;}
.bdy{padding:28px 36px;}
.bdy h2{color:#1A1A1A;font-size:18px;margin:0 0 12px;}
.bdy p{color:#6B6860;font-size:14px;line-height:1.7;margin:0 0 16px;}
.box{background:#FAF9F7;border-radius:8px;padding:18px;margin:0 0 20px;}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #EDEBE6;font-size:14px;}
.row:last-child{border-bottom:none;}
.lbl{color:#6B6860;}.val{font-weight:bold;color:#1A1A1A;}
.val-gold{color:#C9A84C;font-size:17px;font-weight:bold;}
.val-purple{color:#534AB7;font-family:monospace;}
.val-ok{color:#1E7E4A;}
.val-err{color:#C0392B;}
.cta{text-align:center;margin:20px 0;}
.cta a{background:#1A1A1A;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:13px;letter-spacing:1px;text-transform:uppercase;display:inline-block;}
.ftr{background:#FAF9F7;padding:16px 36px;text-align:center;border-top:1px solid #EDEBE6;}
.ftr p{font-size:11px;color:#B4B2A9;margin:0;}
</style></head><body><div class="wrap">
<div class="hdr"><h1>'.htmlspecialchars($site).'</h1><p>ROTATIONAL SAVINGS</p></div>
<div class="bdy">'.$content.'</div>
<div class="ftr"><p>&copy; '.date('Y').' '.htmlspecialchars($site).'. All rights reserved.</p></div>
</div></body></html>';
}

// -----------------------------------------------
// PAYMENT CONFIRMATION EMAIL + SMS
// -----------------------------------------------
function sendPaymentConfirmationEmail(
    $conn,$user_id,$to_email,$user_name,$user_code,
    $plan_name,$amount,$method,$reference,$paid_at,
    $position=null,$collection_date=null,$payout_amount=null
) {
    $subject   = 'Payment Confirmed — '.SITE_NAME;
    $amt_fmt   = '&#8358;'.number_format((float)$amount,2);
    $date_fmt  = date('F j, Y \a\t g:i A',strtotime($paid_at));
    $meth_lbl  = $method==='online'?'Online (Paystack)':'Cash (Admin Verified)';
    $col_date  = $collection_date ? date('F j, Y',strtotime($collection_date)) : null;
    $payout_fmt= $payout_amount ? '&#8358;'.number_format((float)$payout_amount,2) : null;

    $rotation_info = '';
    if ($position && $col_date) {
        $rotation_info = '<div class="box" style="border-left:4px solid #C9A84C;background:#FDFAF3;">
        <p style="margin:0 0 8px;font-weight:bold;font-size:14px;">Your Collection Info</p>
        <div class="row"><span class="lbl">Your Position</span><span class="val">Position '.$position.'</span></div>
        <div class="row"><span class="lbl">Your Collection Date</span><span class="val" style="color:#C9A84C;">'.$col_date.'</span></div>
        '.($payout_fmt ? '<div class="row"><span class="lbl">You Will Receive</span><span class="val-gold">'.$payout_fmt.'</span></div>' : '').'
        </div>';
    }

    $content = '<h2>&#10003; Payment Confirmed!</h2>
    <p>Hi <strong>'.htmlspecialchars($user_name).'</strong>, your contribution payment has been received and confirmed.</p>
    <div class="box">
      <div class="row"><span class="lbl">Member ID</span><span class="val val-purple">'.htmlspecialchars($user_code??'N/A').'</span></div>
      <div class="row"><span class="lbl">Plan</span><span class="val">'.htmlspecialchars($plan_name).'</span></div>
      <div class="row"><span class="lbl">Amount Paid</span><span class="val-gold">'.$amt_fmt.'</span></div>
      <div class="row"><span class="lbl">Method</span><span class="val">'.$meth_lbl.'</span></div>
      <div class="row"><span class="lbl">Reference</span><span class="val" style="font-family:monospace;font-size:12px;">'.htmlspecialchars($reference??'N/A').'</span></div>
      <div class="row"><span class="lbl">Date</span><span class="val">'.$date_fmt.'</span></div>
    </div>'.$rotation_info.'
    <div class="cta"><a href="'.SITE_URL.'/pages/dashboard.php">View My Dashboard</a></div>';

    $html   = buildEmailWrapper(SITE_NAME,$content);
    $result = sendEmail($to_email,$user_name,$subject,$html);
    logEmail($conn,$user_id,$to_email,$subject,$result['success']?'sent':'failed');
    saveNotification($conn,$user_id,'Payment Confirmed','Your payment of '.$amt_fmt.' for '.$plan_name.' has been confirmed.','payment');
    return $result;
}

// -----------------------------------------------
// PAYOUT COMPLETED EMAIL + SMS
// -----------------------------------------------
function sendPayoutCompletedEmail(
    $conn,$user_id,$to_email,$user_name,$user_code,
    $plan_name,$payout_amount,$position
) {
    $subject  = 'Payout Completed — '.SITE_NAME;
    $payout   = '&#8358;'.number_format((float)$payout_amount,2);
    $payout_sms = '₦'.number_format((float)$payout_amount,2);

    $content = '<h2>Payout Completed!</h2>
    <p>Hi <strong>'.htmlspecialchars($user_name).'</strong>, your payout for the <strong>'.htmlspecialchars($plan_name).'</strong> plan has been marked as completed by the admin.</p>
    <div class="box" style="text-align:center;">
      <div style="font-size:38px;color:#1E7E4A;font-weight:bold;margin-bottom:6px;">'.$payout.'</div>
      <div style="font-size:13px;color:#6B6860;text-transform:uppercase;letter-spacing:1px;">Received — Position '.$position.'</div>
      <div style="font-size:12px;color:#534AB7;font-family:monospace;margin-top:4px;">'.htmlspecialchars($user_code??'').'</div>
    </div>
    <p>Thank you for participating. Please continue making your contributions so others can collect too!</p>
    <div class="cta"><a href="'.SITE_URL.'/pages/dashboard.php">View My Dashboard</a></div>';

    $html   = buildEmailWrapper(SITE_NAME,$content);
    $result = sendEmail($to_email,$user_name,$subject,$html);
    logEmail($conn,$user_id,$to_email,$subject,$result['success']?'sent':'failed');
    saveNotification($conn,$user_id,'Payout Completed!','Your payout of '.$payout.' for '.$plan_name.' has been confirmed by the admin.','collection');
    return $result;
}

// -----------------------------------------------
// COLLECTION DAY EMAIL + SMS
// -----------------------------------------------
function sendCollectionDayEmail(
    $conn,$user_id,$to_email,$user_name,$user_code,
    $plan_name,$payout_amount,$position
) {
    $subject  = 'Today Is Your Collection Day! — '.SITE_NAME;
    $payout   = '&#8358;'.number_format((float)$payout_amount,2);
    $payout_sms = '₦'.number_format((float)$payout_amount,2);

    $content = '<h2 style="color:#C9A84C;">&#127942; Today Is YOUR Day!</h2>
    <p>Congratulations, <strong>'.htmlspecialchars($user_name).'</strong>! Today is your scheduled collection day for the <strong>'.htmlspecialchars($plan_name).'</strong> plan.</p>
    <div class="box" style="text-align:center;border:2px solid #C9A84C;">
      <div style="font-size:38px;color:#C9A84C;font-weight:bold;margin-bottom:6px;">'.$payout.'</div>
      <div style="font-size:13px;color:#6B6860;text-transform:uppercase;letter-spacing:1px;">Your payout — Position '.$position.'</div>
    </div>
    <p>Please contact your group admin to arrange collection of your funds today.</p>
    <div class="cta"><a href="'.SITE_URL.'/pages/dashboard.php">View My Dashboard</a></div>';

    $html   = buildEmailWrapper(SITE_NAME,$content);
    $result = sendEmail($to_email,$user_name,$subject,$html);
    logEmail($conn,$user_id,$to_email,$subject,$result['success']?'sent':'failed');
    saveNotification($conn,$user_id,'Today Is Your Collection Day!','Your payout of '.$payout.' for '.$plan_name.' is due today. Contact admin to collect.','collection');
    return $result;
}

// -----------------------------------------------
// REMINDER EMAIL (payment due / overdue)
// Also sends SMS if enabled in config.php
// -----------------------------------------------
function sendReminderEmail(
    $conn,$user_id,$to_email,$user_name,$user_code,
    $plan_name,$amount,$due_date,$type='due_tomorrow',$phone=null
) {
    $site       = SITE_NAME;
    $site_url   = SITE_URL;
    $amount_fmt = '&#8358;'.number_format((float)$amount,2);
    $amount_sms = '₦'.number_format((float)$amount,2);
    $date_fmt   = date('F j, Y',strtotime($due_date));
    $date_sms   = date('M j',strtotime($due_date));

    if ($type === 'overdue') {
        $subject    = 'OVERDUE: Payment Required — '.$site;
        $headline   = '&#9888; Your Payment Is Overdue';
        $body_text  = 'Your contribution of <strong>'.$amount_fmt.'</strong> for the <strong>'.htmlspecialchars($plan_name).'</strong> plan is overdue. Please make your payment immediately to remain active in the rotation.';
        $btn_color  = '#C0392B';
        $btn_text   = 'Pay Now';
        $border_col = '#C0392B';
        $sms_msg    = $site.': Hi '.$user_name.', your '.$plan_name.' payment of '.$amount_sms.' is OVERDUE. Pay now to stay active in the group.';
    } else {
        $subject    = 'Payment Reminder — '.$site;
        $headline   = '&#8987; Payment Due Tomorrow';
        $body_text  = 'Just a friendly reminder that your contribution of <strong>'.$amount_fmt.'</strong> for the <strong>'.htmlspecialchars($plan_name).'</strong> plan is due on <strong>'.$date_fmt.'</strong>.';
        $btn_color  = '#C9A84C';
        $btn_text   = 'View Dashboard';
        $border_col = '#C9A84C';
        $sms_msg    = $site.': Hi '.$user_name.', reminder — your '.$plan_name.' contribution of '.$amount_sms.' is due on '.$date_sms.'. Keep it up!';
    }

    $content = '<h2>'.$headline.'</h2>
    <p>Hi <strong>'.htmlspecialchars($user_name).'</strong> (<span style="font-family:monospace;color:#534AB7">'.htmlspecialchars($user_code??'').'</span>),</p>
    <p>'.$body_text.'</p>
    <div class="box" style="border-left:4px solid '.$border_col.';">
      <div class="row"><span class="lbl">Plan</span><span class="val">'.htmlspecialchars($plan_name).'</span></div>
      <div class="row"><span class="lbl">Amount Due</span><span class="val-gold">'.$amount_fmt.'</span></div>
      <div class="row"><span class="lbl">Due Date</span><span class="val">'.$date_fmt.'</span></div>
    </div>
    <div class="cta"><a style="background:'.$btn_color.'" href="'.$site_url.'/pages/dashboard.php">'.$btn_text.'</a></div>';

    $html      = buildEmailWrapper($site,$content);
    $email_res = sendEmail($to_email,$user_name,$subject,$html);
    logEmail($conn,$user_id,$to_email,$subject,$email_res['success']?'sent':'failed');

    // Send SMS if enabled and phone provided
    // SMS is sent AFTER email — email failure does not block SMS
    $sms_res = null;
    if (!empty($phone)) {
        // Trim SMS to 160 chars max
        if (strlen($sms_msg) > 160) $sms_msg = substr($sms_msg,0,157).'...';
        $sms_res = sendSMS($conn,$user_id,$phone,$sms_msg);
    }

    return ['email'=>$email_res,'sms'=>$sms_res];
}

// -----------------------------------------------
// UPCOMING COLLECTION REMINDER (3 days before)
// -----------------------------------------------
function sendUpcomingCollectionEmail(
    $conn,$user_id,$to_email,$user_name,$user_code,
    $plan_name,$payout_amount,$collection_date,$days_left,$phone=null
) {
    $subject    = 'Your Collection Day Is Coming — '.SITE_NAME;
    $payout_fmt = '&#8358;'.number_format((float)$payout_amount,2);
    $payout_sms = '₦'.number_format((float)$payout_amount,2);
    $col_fmt    = date('F j, Y',strtotime($collection_date));
    $col_sms    = date('M j',strtotime($collection_date));
    $days_txt   = $days_left===1 ? 'tomorrow' : 'in '.$days_left.' days';

    $content = '<h2>Your Collection Day Is '.ucfirst($days_txt).'!</h2>
    <p>Hi <strong>'.htmlspecialchars($user_name).'</strong>, your collection day for <strong>'.htmlspecialchars($plan_name).'</strong> is '.$days_txt.'.</p>
    <div class="box" style="border-left:4px solid #C9A84C;background:#FDFAF3;">
      <div class="row"><span class="lbl">Collection Date</span><span class="val" style="color:#C9A84C;">'.$col_fmt.'</span></div>
      <div class="row"><span class="lbl">You Will Receive</span><span class="val-gold">'.$payout_fmt.'</span></div>
    </div>
    <p>Make sure your payments are up to date and that you are reachable on your collection day.</p>
    <div class="cta"><a href="'.SITE_URL.'/pages/dashboard.php">View My Dashboard</a></div>';

    $html      = buildEmailWrapper(SITE_NAME,$content);
    $email_res = sendEmail($to_email,$user_name,$subject,$html);
    logEmail($conn,$user_id,$to_email,$subject,$email_res['success']?'sent':'failed');

    $sms_res = null;
    if (!empty($phone)) {
        $sms_msg = SITE_NAME.': Hi '.$user_name.', your '.$plan_name.' collection of '.$payout_sms.' is '.$days_txt.' on '.$col_sms.'. Be available!';
        if (strlen($sms_msg)>160) $sms_msg = substr($sms_msg,0,157).'...';
        $sms_res = sendSMS($conn,$user_id,$phone,$sms_msg);
    }
    return ['email'=>$email_res,'sms'=>$sms_res];
}
?>
