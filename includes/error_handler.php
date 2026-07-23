<?php
// ============================================================
// includes/error_handler.php — NEW FILE
// Catches uncaught errors/exceptions so visitors never see a
// raw PHP error or stack trace on the live site.
// ============================================================

set_exception_handler(function ($e) {
    error_log('[Uncaught Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (APP_ENV === 'production') {
        http_response_code(500);
        $errorPage = __DIR__ . '/../pages/error.php';
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo "Something went wrong. Please try again shortly.";
        }
        exit();
    }
    // In development, let PHP show the full error for debugging
    throw $e;
});

set_error_handler(function ($severity, $message, $file, $line) {
    // Respect @-suppressed errors
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log("[Error] $message in $file:$line");

    if (APP_ENV === 'production' && in_array($severity, [E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        $errorPage = __DIR__ . '/../pages/error.php';
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo "Something went wrong. Please try again shortly.";
        }
        exit();
    }
    // Let PHP continue its normal handling for warnings/notices
    return false;
});
?>
