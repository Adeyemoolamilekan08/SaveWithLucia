<?php
// ============================================================
// config.php — REPLACE existing file at /swl/config.php
// Now loads real values from .env instead of hardcoding them.
// ============================================================

require_once __DIR__ . '/includes/env.php';
loadEnv(__DIR__ . '/.env');

define('APP_ENV', env('APP_ENV', 'production')); // 'development' or 'production'

// ============================================================
// ERROR DISPLAY — controlled by APP_ENV
// development: show errors on screen (easier to debug locally)
// production:  hide errors from visitors, log them to a file instead
// ============================================================
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    // Logged OUTSIDE the web root so it can't be browsed to directly
    ini_set('error_log', dirname(__DIR__) . '/swl_error_log/php_errors.log');
}

require_once __DIR__ . '/includes/error_handler.php';

// ============================================================
// DATABASE
// ============================================================
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', 'savewithlucia'));

define('SITE_NAME', 'SaveWithLucia');
define('SITE_URL',  env('SITE_URL', 'http://localhost/swl'));

// ============================================================
// PAYSTACK — auto-switches between test and live keys
// based on APP_ENV, so you never have to remember to swap them
// ============================================================
if (APP_ENV === 'production') {
    define('PAYSTACK_PUBLIC_KEY', env('PAYSTACK_PUBLIC_KEY_LIVE'));
    define('PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY_LIVE'));
} else {
    define('PAYSTACK_PUBLIC_KEY', env('PAYSTACK_PUBLIC_KEY_TEST'));
    define('PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY_TEST'));
}

define('ADMIN_EMAIL', env('ADMIN_EMAIL'));

// ============================================================
// EMAIL — Gmail SMTP
// ============================================================
define('MAIL_HOST',       env('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT',       (int) env('MAIL_PORT', 587));
define('MAIL_USERNAME',   env('MAIL_USERNAME'));
define('MAIL_PASSWORD',   env('MAIL_PASSWORD'));
define('MAIL_FROM_EMAIL', env('MAIL_FROM_EMAIL'));
define('MAIL_FROM_NAME',  env('MAIL_FROM_NAME', 'SaveWithLucia'));
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls'));

// ============================================================
// SMS — Twilio
// ============================================================
define('TWILIO_SID',   env('TWILIO_SID'));
define('TWILIO_TOKEN', env('TWILIO_TOKEN'));
define('TWILIO_FROM',  env('TWILIO_FROM'));
define('SMS_ENABLED',  env('SMS_ENABLED', 'false') === 'true');

// ============================================================
// CRON SECRET — protects cron/reminders.php from public access
// ============================================================
define('CRON_SECRET', env('CRON_SECRET'));

define('PHPMAILER_PATH', __DIR__ . '/vendor/phpmailer/src/');
define('RECEIPTS_PATH',  __DIR__ . '/receipts/');
?>
