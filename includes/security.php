<?php
// ============================================================
// includes/security.php — NEW FILE
// Include after includes/db.php + includes/auth.php.
//
// LOGIN RATE-LIMITING
//   5 failed attempts for the same email+IP within 15 minutes
//   locks that email+IP out for 15 minutes.
//
// AUDIT LOG
//   logAudit() records who (admin) did what, for payments/payouts.
// ============================================================

const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_WINDOW_MINUTES = 15;

function getClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Call BEFORE checking the password, right after you have $email
function isLoginLocked($conn, $email) {
    $ip = getClientIp();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts, MAX(attempted_at) AS last_try
         FROM login_attempts
         WHERE email = ? AND ip_address = ?
           AND attempted_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $window = LOGIN_WINDOW_MINUTES;
    $stmt->bind_param("ssi", $email, $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)$row['attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $unlockAt = strtotime($row['last_try']) + (LOGIN_WINDOW_MINUTES * 60);
        $minutesLeft = max(1, ceil(($unlockAt - time()) / 60));
        return $minutesLeft;
    }
    return false; // not locked
}

// Call on a failed password check
function recordFailedLogin($conn, $email) {
    $ip = getClientIp();
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
}

// Call on a successful login to clear the slate
function clearFailedLogins($conn, $email) {
    $ip = getClientIp();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// AUDIT LOG — call after any payment/payout admin action
// $action examples: 'cash_payment_approved', 'payout_marked_complete'
// $details: a short human-readable string (who/what/amount)
// ============================================================
function logAudit($conn, $adminId, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO audit_log (admin_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $adminId, $action, $details);
    $stmt->execute();
    $stmt->close();
}
?>
