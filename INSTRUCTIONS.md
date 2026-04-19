# SaveWithLucia — Where Every File Goes
# READ THIS FIRST

This zip contains all files for the Logic Fix upgrade.
Each file has a comment at the top telling you exactly what to do.

## The 3 types of instructions you will see:

  [NEW FILE]    = This file does not exist yet. Just copy it into the folder shown.
  [REPLACE]     = You already have this file. Delete the old one and paste this one.
  [ADD TO EXISTING] = Open your existing file and add the code shown at a specific line.

---

## Quick File Map

| File in this zip             | What to do      | Where it goes in your project        |
|------------------------------|-----------------|--------------------------------------|
| database_upgrade.sql         | Run in phpMyAdmin | —                                  |
| config.php                   | REPLACE         | /swl/config.php                      |
| includes/functions.php       | REPLACE         | /swl/includes/functions.php          |
| includes/mailer.php          | REPLACE         | /swl/includes/mailer.php             |
| includes/db.php              | REPLACE         | /swl/includes/db.php                 |
| includes/auth.php            | REPLACE         | /swl/includes/auth.php               |
| pages/register.php           | NEW FILE        | /swl/pages/register.php              |
| pages/login.php              | NEW FILE        | /swl/pages/login.php                 |
| pages/dashboard.php          | REPLACE         | /swl/pages/dashboard.php             |
| pages/join_plan.php          | REPLACE         | /swl/pages/join_plan.php             |
| payments/paystack_init.php   | NEW FILE        | /swl/payments/paystack_init.php      |
| payments/paystack_verify.php | NEW FILE        | /swl/payments/paystack_verify.php    |
| payments/cash_payment.php    | NEW FILE        | /swl/payments/cash_payment.php       |
| admin/admin_nav.php          | NEW FILE        | /swl/admin/admin_nav.php             |
| admin/index.php              | REPLACE         | /swl/admin/index.php                 |
| admin/plans.php              | REPLACE         | /swl/admin/plans.php                 |
| admin/rotation.php           | REPLACE         | /swl/admin/rotation.php              |
| admin/payout.php             | REPLACE         | /swl/admin/payout.php                |
| admin/verify_cash.php        | REPLACE         | /swl/admin/verify_cash.php           |
| admin/users.php              | REPLACE         | /swl/admin/users.php                 |
| admin/export.php             | NEW FILE        | /swl/admin/export.php                |
| admin/change_password.php    | NEW FILE        | /swl/admin/change_password.php       |
| cron/reminders.php           | NEW FILE        | /swl/cron/reminders.php              |
| assets/css/style.css         | REPLACE         | /swl/assets/css/style.css            |
| index.php                    | REPLACE         | /swl/index.php                       |
| logout.php                   | NEW FILE        | /swl/logout.php                      |

---

## Step 1 — Run the SQL first (REQUIRED)
1. Open phpMyAdmin
2. Click your "savewithlucia" database on the left
3. Click the "SQL" tab at the top
4. Open the file "database_upgrade.sql" from this zip
5. Copy ALL the text inside it
6. Paste into phpMyAdmin SQL box
7. Click Go
8. You should see "Your SQL query has been executed successfully"

## Step 2 — Update config.php
Open config.php, find the SMS SETTINGS section and add your Termii key.
SMS is OFF by default — your site will work fine without it.

## Step 3 — Copy all files
Copy each file from this zip into your project folder as shown in the table above.
Replace when told to replace. Create new when told to create new.

## Step 4 — Add PHPMailer
If you don't have PHPMailer yet:
1. Go to https://github.com/PHPMailer/PHPMailer
2. Click Code → Download ZIP → unzip it
3. Copy these 3 files from inside the "src" folder:
   - PHPMailer.php
   - SMTP.php
   - Exception.php
4. Paste them into: /swl/vendor/phpmailer/src/

## Step 5 — Add your logo
Place your logo image at: /swl/assets/images/logo.png

## Step 6 — Test
Visit: http://localhost/swl
Admin login: admin@savewithlucia.com / Admin@1234
Change password at: http://localhost/swl/admin/change_password.php

## Step 7 — Test the reminder script manually
Visit: http://localhost/swl/cron/reminders.php?secret=swl_cron_secret_change_me_2024
You should see output like: "[2024-01-01 08:00:00] Reminder job started."

## Step 8 — Set up the daily cron job (on your live server)
In cPanel → Cron Jobs, add:
  Time: 0 8 * * *  (runs at 8am every day)
  Command: php /home/yourusername/public_html/swl/cron/reminders.php

---

## SMS Setup (Do this AFTER everything else is working)
1. Go to https://termii.com and create a free account
2. From your Termii dashboard, copy your API Key
3. Create a Sender ID (e.g. "SaveLucia" — max 11 characters)
4. Open /swl/config.php
5. Find the line:  define('TERMII_API_KEY', 'your_termii_api_key_here');
6. Replace 'your_termii_api_key_here' with your actual key
7. Find the line:  define('SMS_ENABLED', false);
8. Change false to true
9. Save config.php
SMS will now send automatically when reminders run.

---

## Default Admin Login
Email:    admin@savewithlucia.com
Password: Admin@1234
