<?php
// ============================================================
// config.php — SaveWithLucia Configuration
// ============================================================

// --- Database ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'savewithlucia');

// --- Site ---
define('SITE_NAME', 'SaveWithLucia');
define('SITE_URL',  'http://localhost/swl');

// --- Paystack Keys (you'll get these from paystack.com) ---
define('PAYSTACK_PUBLIC_KEY', 'pk_test_fb44af4dc6172d9ee69fd452d4d3f188a9738b9e');
define('PAYSTACK_SECRET_KEY', 'sk_test_e4edaef1498d8037f49a728c59535f10b0b86738');

// --- Admin Email (unchanged) ---
define('ADMIN_EMAIL', 'oadeyemo318@gmail.com');

// ============================================================
// EMAIL SMTP SETTINGS
// MAIL_USERNAME and MAIL_FROM_EMAIL must be the SAME address.
// Gmail: go to myaccount.google.com -> Security -> App Passwords
// ============================================================
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'adeyemoolamilekan08@gmail.com');
define('MAIL_PASSWORD',   'lbfs iqkg dsst ugvd');
define('MAIL_FROM_EMAIL', 'adeyemoolamilekan08@gmail.com');
define('MAIL_FROM_NAME',  'SaveWithLucia');
define('MAIL_ENCRYPTION', 'tls');



// ============================================================
// SMS SETTINGS — Termii (Nigerian SMS Gateway)
// ============================================================
// HOW TO SET UP SMS:
// 1. Go to https://termii.com and create a free account
// 2. From your dashboard, copy your API Key
// 3. Create a Sender ID (e.g. "SaveLucia") — max 11 characters
//    Note: Sender ID approval may take 1-2 business days in Nigeria
// 4. Paste your API key below and set SMS_ENABLED to true
// 5. That's it — SMS will work automatically in cron/reminders.php
//
// COST: Termii charges per SMS unit. Check termii.com/pricing
// ALTERNATIVE: You can also use Infobip, Vonage, or Africa's Talking
// ============================================================
define('TERMII_API_KEY',    'TLIYcuQkihIcVuCHAvOEAYIrlGlNQXfyyTuEhzjvmGbRZCFnFaHhtcCLRuPoZr'); // <- change this
define('TERMII_SENDER_ID',  'SaveWithLuc');                // <- your sender ID
define('SMS_ENABLED',       TRUE);   // <- set to TRUE when Termii is set up

// ============================================================
// CRON JOB SECRET KEY
// This protects the reminder script from being run by strangers
// Change this to any random string before going live
// ============================================================
define('CRON_SECRET', 'swl_cron_secret_change_me_2024');

// --- Paths ---
define('PHPMAILER_PATH', __DIR__ . '/vendor/phpmailer/src/');
define('RECEIPTS_PATH',  __DIR__ . '/receipts/');
?>
