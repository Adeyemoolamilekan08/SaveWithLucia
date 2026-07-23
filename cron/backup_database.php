<?php
// ============================================================
// cron/backup_database.php — NEW FILE
// ============================================================
// HOW TO RUN MANUALLY (test):
//   http://localhost/swl/cron/backup_database.php?secret=SWL_lucia_2024_Xk9mPq3z
//
// HOW TO RUN AUTOMATICALLY ON LIVE SERVER (cPanel Cron Jobs):
//   Time: 0 3 * * *   (runs at 3:00 AM every day, off-peak)
//   Command: php /home/yourusername/public_html/swl/cron/backup_database.php
//
// WHAT THIS SCRIPT DOES:
//   Runs mysqldump to export the full database to a timestamped .sql file,
//   saved OUTSIDE the web root so it can't be downloaded by visiting a URL.
//   Keeps the last 14 daily backups and deletes anything older, so backups
//   don't quietly fill up your disk.
//
// IMPORTANT — cPanel setup notes:
//   1. Your host needs to allow shell_exec() / exec() for this to work.
//      Some shared hosts disable this — if so, use cPanel's own
//      "Backup Wizard" / "MySQL Databases" export feature on a schedule
//      instead, since this script can't run without shell access.
//   2. The backup folder below is created automatically one level ABOVE
//      your web root (same place as the error log folder from Batch 1),
//      so it's never publicly browsable.
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

// ---- Protect this script from public access ----
if (!isset($_GET['secret']) || $_GET['secret'] !== CRON_SECRET) {
    http_response_code(403);
    die('Forbidden.');
}

$backupDir = dirname(__DIR__, 2) . '/swl_db_backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Y-m-d_H-i-s');
$filename  = "savewithlucia_{$timestamp}.sql";
$filepath  = $backupDir . '/' . $filename;

// ---- Run mysqldump ----
$command = sprintf(
    'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_NAME),
    escapeshellarg($filepath)
);

exec($command, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
    error_log('[Backup Failed] mysqldump exited with code ' . $returnCode . '. Output: ' . implode("\n", $output));
    echo "Backup FAILED. Check your error log.\n";
    echo "If your host disables shell_exec(), use cPanel's own MySQL export/backup feature on a schedule instead.\n";
    exit();
}

echo "Backup created: {$filename} (" . round(filesize($filepath) / 1024, 1) . " KB)\n";

// ---- Rotate old backups — keep the most recent 14 ----
$keep  = 14;
$files = glob($backupDir . '/savewithlucia_*.sql');
usort($files, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});

$deleted = 0;
foreach (array_slice($files, $keep) as $old) {
    unlink($old);
    $deleted++;
}

if ($deleted > 0) {
    echo "Removed {$deleted} backup(s) older than the most recent {$keep}.\n";
}
?>
