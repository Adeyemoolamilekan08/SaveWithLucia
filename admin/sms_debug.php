<?php
// FILE: admin/sms_debug.php — Twilio SMS tester. Delete after SMS is working.
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$result = null; $to_tested = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone   = trim($_POST['phone']   ?? '');
    $message = trim($_POST['message'] ?? 'Test SMS from '.SITE_NAME.'. Please ignore.');

    $clean = preg_replace('/\D/', '', $phone);
    if (strlen($clean)===11 && $clean[0]==='0') $clean = '234'.substr($clean,1);
    elseif (strlen($clean)===10)                 $clean = '234'.$clean;
    $to_tested = '+' . $clean;

    $url     = 'https://api.twilio.com/2010-04-01/Accounts/'.TWILIO_SID.'/Messages.json';
    $payload = http_build_query(['To'=>$to_tested,'From'=>TWILIO_FROM,'Body'=>$message]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_USERPWD,        TWILIO_SID.':'.TWILIO_TOKEN);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT,        25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data    = $raw ? json_decode($raw, true) : [];
    $sent_ok = ($http === 201 && !empty($data['sid']));
    $err_msg = null;
    if (!$sent_ok) {
        if ($curl_err)     $err_msg = 'cURL error: '.$curl_err;
        elseif ($http===401) $err_msg = 'HTTP 401 Unauthorised — Account SID or Auth Token is wrong';
        elseif (isset($data['message'])) $err_msg = $data['message'];
        else   $err_msg = 'HTTP '.$http.' — '.$raw;
    }
    $result = ['ok'=>$sent_ok,'err'=>$err_msg,'http'=>$http,'raw'=>$raw,'data'=>$data];
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SMS Debug — <?= SITE_NAME ?></title><link rel="stylesheet" href="../assets/css/style.css?v=20260523">
<style>
pre{background:#1A1A1A;color:#E8D5A3;padding:1.25rem;border-radius:8px;font-size:.82rem;line-height:1.6;white-space:pre-wrap;word-break:break-all;overflow-x:auto;}
.dbg{background:var(--white);border:1px solid var(--gray-light);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;}
.dbg h3{font-size:1.1rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--gray-light);}
.row{display:flex;align-items:flex-start;gap:.75rem;padding:.5rem 0;border-bottom:1px solid var(--gray-light);font-size:.9rem;}
.row:last-child{border-bottom:none;}
</style>
</head>
<body class="inner-page"><?php include 'admin_nav.php'; ?>
<main class="main-content"><div class="container" style="max-width:760px;">

<div class="page-header"><h1>SMS Debug — Twilio</h1><p>Delete this file once SMS is working.</p></div>

<div class="dbg">
    <h3>Current Twilio Configuration</h3>
    <div class="row"><strong style="min-width:120px;">Account SID</strong>
        <?php $sid_ok = defined('TWILIO_SID') && substr(TWILIO_SID,0,2)==='AC' && strlen(TWILIO_SID)===34; ?>
        <?= $sid_ok
            ? '<span style="color:var(--success)">✓ '.htmlspecialchars(substr(TWILIO_SID,0,8)).'••••••••</span>'
            : '<span style="color:var(--error)">✗ Not set correctly — should start with AC and be 34 chars. Currently: '.htmlspecialchars(TWILIO_SID ?? 'empty').'</span>' ?>
    </div>
    <div class="row"><strong style="min-width:120px;">Auth Token</strong>
        <?php $tok_ok = defined('TWILIO_TOKEN') && TWILIO_TOKEN !== 'your_auth_token_here' && strlen(TWILIO_TOKEN) > 10; ?>
        <?= $tok_ok ? '<span style="color:var(--success)">✓ Set (hidden)</span>' : '<span style="color:var(--error)">✗ Not set — still placeholder value</span>' ?>
    </div>
    <div class="row"><strong style="min-width:120px;">From Number</strong>
        <?= '<code>'.htmlspecialchars(TWILIO_FROM ?? '').'</code>' ?>
        <?= (defined('TWILIO_FROM') && TWILIO_FROM[0]==='+') ? '<span style="color:var(--success)"> ✓ correct format</span>' : '<span style="color:var(--error)"> ✗ must start with +</span>' ?>
    </div>
    <div class="row"><strong style="min-width:120px;">SMS_ENABLED</strong>
        <?= SMS_ENABLED ? '<span style="color:var(--success)">✓ true</span>' : '<span style="color:var(--error)">✗ false</span>' ?>
    </div>
</div>

<div class="dbg">
    <h3>Send Test SMS</h3>
    <p style="font-size:.875rem;color:var(--gray-text);margin-bottom:1rem;">
        <strong>Trial mode:</strong> Twilio only sends to verified numbers in trial.
        Verify yours at console.twilio.com → Phone Numbers → Verified Caller IDs.
    </p>
    <form method="POST">
        <div class="form-group"><label>Phone Number</label>
            <input type="tel" name="phone" placeholder="08012345678" value="<?= htmlspecialchars($_POST['phone']??'') ?>" required></div>
        <div class="form-group"><label>Message</label>
            <textarea name="message" rows="2"><?= htmlspecialchars($_POST['message']??'Test SMS from '.SITE_NAME.'. Ignore.') ?></textarea></div>
        <button type="submit" class="btn btn-gold">Send Test SMS</button>
    </form>
</div>

<?php if ($result !== null): ?>
<?php if ($result['ok']): ?>
<div style="background:#EDF7F1;border:2px solid var(--success);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
    <strong style="color:var(--success);font-size:1.1rem;">✓ SMS sent successfully!</strong>
    <p style="margin-top:.35rem;font-size:.9rem;">SID: <code><?= htmlspecialchars($result['data']['sid']??'') ?></code> | To: <code><?= htmlspecialchars($to_tested) ?></code></p>
    <p style="font-size:.875rem;color:var(--gray-text);margin-top:.25rem;">Check your phone — the message should arrive within a few seconds.</p>
</div>
<?php else: ?>
<div style="background:#FDF0EF;border:2px solid var(--error);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
    <strong style="color:var(--error);font-size:1.1rem;display:block;margin-bottom:.5rem;">✗ Failed — Exact reason:</strong>
    <p style="font-size:1rem;color:#7B2020;font-weight:600;"><?= htmlspecialchars($result['err'] ?? 'unknown') ?></p>
    <p style="font-size:.8rem;color:var(--gray-text);margin-top:.5rem;">HTTP <?= $result['http'] ?> | Sent to: <?= htmlspecialchars($to_tested) ?></p>
</div>
<?php endif; ?>

<div class="dbg"><h3>Raw Twilio Response</h3>
<pre><?= htmlspecialchars(json_encode($result['data']??[], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
<?php endif; ?>

<div class="dbg">
    <h3>Common Twilio Errors — Quick Fix</h3>
    <div style="font-size:.875rem;line-height:2.2;">
        <div><strong style="color:var(--error);">HTTP 401 Unauthorised</strong> — Account SID or Auth Token is wrong. Re-copy from console.twilio.com.</div>
        <div><strong style="color:var(--error);">"The number +234... is unverified"</strong> — Trial mode restriction. Verify the number at console.twilio.com → Verified Caller IDs.</div>
        <div><strong style="color:var(--error);">"is not a valid phone number"</strong> — The recipient number format is wrong. Should become +2348012345678 after normalisation.</div>
        <div><strong style="color:var(--error);">cURL error / timeout</strong> — Your server cannot reach api.twilio.com. Works on live hosting, not always on localhost.</div>
        <div><strong style="color:var(--success);">HTTP 201 + sid present</strong> — Success! SMS was queued for delivery.</div>
    </div>
</div>

<div class="admin-info-box">Delete <code>admin/sms_debug.php</code> from your server once SMS is working.</div>
</div></main></body></html>
