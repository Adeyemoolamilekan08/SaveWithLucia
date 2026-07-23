-- ============================================================
-- database_migration_v2.sql — Run in phpMyAdmin (SQL tab)
-- Adds the "force password change" flag
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`;

-- Force it on for the current admin account so the change is
-- picked up the next time that account logs in.
UPDATE `users`
  SET `must_change_password` = 1
  WHERE `role` = 'admin';
