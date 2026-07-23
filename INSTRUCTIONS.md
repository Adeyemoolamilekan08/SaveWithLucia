# SaveWithLucia — Professionalization Patch (Batch 4)
# Scheduled DB Backups + Payment Form Polish

## Quick File Map

| File in this zip              | What to do | Where it goes                     |
|---------------------------------|------------|-------------------------------------|
| cron/backup_database.php        | NEW FILE   | /swl/cron/backup_database.php      |
| payments/cash_payment.php       | REPLACE    | /swl/payments/cash_payment.php     |

Good news: your online payment page (`payments/paystack_init.php`) already disables
the button and shows "Processing..." while Paystack is loading — nothing needed there.
This batch only had to add the same protection to the cash payment form, plus give it
CSRF protection to match your login/change-password/reset-password forms.

---

## Part 1 — Scheduled Database Backups

### Step 1 — Copy the file
Copy `cron/backup_database.php` into `/swl/cron/backup_database.php`.

### Step 2 — Check your host allows shell commands
This script uses `mysqldump` via PHP's `exec()`. Some shared hosts disable this for
security. To check, try running it manually first (Step 4 below) — if it fails with
a permissions-style error, your host likely blocks `exec()`, and you should use
cPanel's own **Backup Wizard** or **phpMyAdmin → Export** on a manual/scheduled basis
instead, since this script won't be able to run.

### Step 3 — Set up the cron job (cPanel)
In cPanel → Cron Jobs, add a new job:
- **Time:** `0 3 * * *` (3:00 AM daily — off-peak, matches your existing reminders cron pattern)
- **Command:** `php /home/yourusername/public_html/swl/cron/backup_database.php`

(Same pattern as your existing `cron/reminders.php` job — check what path you used
there and mirror it.)

### Step 4 — Test manually first
Visit in your browser (replace with your actual `CRON_SECRET` from `.env`):
```
https://yourdomain.com/swl/cron/backup_database.php?secret=YOUR_CRON_SECRET
```
You should see a confirmation with the backup file size. If it fails, check the message —
it'll tell you whether `mysqldump` isn't available.

### Where backups are stored
Backups save to a folder called `swl_db_backups`, created automatically **two levels above**
your web root (same idea as the `swl_error_log` folder from Batch 1) — so it's never
reachable by URL. The script automatically keeps the most recent 14 daily backups and
deletes anything older, so it won't quietly fill up your disk over time.

### Restoring from a backup (if you ever need to)
In phpMyAdmin: select the database → Import tab → choose the `.sql` file → Go.
Or via SSH: `mysql -u youruser -p savewithlucia < backup_file.sql`

---

## Part 2 — Cash Payment Form Polish
`payments/cash_payment.php` now:
- Disables the button and shows "Submitting..." the moment it's clicked, so a slow
  connection or an impatient double-click can't fire the request twice
- Has CSRF protection, matching the pattern used on your other forms

### Test
1. Go through a cash payment request as a regular user — click submit and confirm the
   button disables immediately with "Submitting..." text
2. Confirm the request still lands correctly in the admin's pending cash payments list

---

## What's left from the original list
At this point the high/medium-impact items are all done. What remains is lower priority:
- Extending login rate-limiting to `forgot_password.php`
- Extending the audit log to non-payment admin actions (add member, delete plan, change status)
- A full input-validation helper library (nice-to-have, not urgent — your existing
  per-field checks are doing the job)
