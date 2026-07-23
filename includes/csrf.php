<?php
// ============================================================
// includes/csrf.php — NEW FILE
// Include this AFTER includes/auth.php (needs session started).
// ============================================================

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Prints a ready-to-use hidden input — drop this inside any <form>
function csrfField() {
    $token = generateCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// Call at the top of any POST handler, before touching $_POST data
function verifyCSRFToken() {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('Your session has expired or this form was submitted incorrectly. Please go back, refresh the page, and try again.');
    }
}
?>
