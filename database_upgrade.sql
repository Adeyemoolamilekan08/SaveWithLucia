-- ============================================================
-- SAVEWITHLUCIA — Database Upgrade SQL
-- ============================================================
-- WHAT TO DO WITH THIS FILE:
--   1. Open phpMyAdmin
--   2. Click your "savewithlucia" database on the left sidebar
--   3. Click the SQL tab at the top
--   4. Copy everything below and paste it in
--   5. Click Go
--   This is safe to run — it only ADDS columns and tables,
--   it never deletes your existing data.
-- ============================================================

USE savewithlucia;

-- Add total_collected_count to plans if it does not exist
ALTER TABLE plans
    ADD COLUMN IF NOT EXISTS total_collected_count INT DEFAULT 0
        COMMENT 'How many members have collected so far. Updated on each payout.',
    ADD COLUMN IF NOT EXISTS current_position INT DEFAULT 1
        COMMENT 'Which position slot is currently due to collect';

-- Add last_reminder_sent to contributions if it does not exist
ALTER TABLE contributions
    ADD COLUMN IF NOT EXISTS last_reminder_sent DATE DEFAULT NULL
        COMMENT 'Date the last payment reminder was sent — prevents duplicate reminders';

-- Fix any plans incorrectly marked completed when members still waiting
UPDATE plans p
SET p.plan_status = 'active'
WHERE p.plan_status = 'completed'
  AND (
      SELECT COUNT(*) FROM contributions c
      WHERE c.plan_id = p.id
        AND c.has_collected = 0
        AND c.status != 'removed'
  ) > 0;

-- Backfill total_collected_count from real data
UPDATE plans p
SET p.total_collected_count = (
    SELECT COUNT(*) FROM contributions c
    WHERE c.plan_id = p.id
      AND c.has_collected = 1
      AND c.status != 'removed'
);

-- Backfill current_position
UPDATE plans p
SET p.current_position = COALESCE(
    (SELECT MIN(c.position) FROM contributions c
     WHERE c.plan_id = p.id
       AND c.has_collected = 0
       AND c.status != 'removed'),
    p.total_participants
);

-- Create sms_logs table (tracks every SMS attempt)
CREATE TABLE IF NOT EXISTS sms_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    phone      VARCHAR(20)  NOT NULL,
    message    TEXT         NOT NULL,
    status     ENUM('sent','failed') DEFAULT 'sent',
    provider   VARCHAR(50)  DEFAULT 'termii',
    sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create reminders_sent table (prevents sending the same reminder twice in one day)
CREATE TABLE IF NOT EXISTS reminders_sent (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT         NOT NULL,
    contribution_id INT         NOT NULL,
    reminder_type   VARCHAR(50) NOT NULL
        COMMENT 'payment_overdue, payment_due_tomorrow, collection_today, collection_upcoming',
    sent_date       DATE        NOT NULL
        COMMENT 'Calendar day this reminder was sent',
    sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY no_duplicate (contribution_id, reminder_type, sent_date),
    FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (contribution_id) REFERENCES contributions(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verify everything looks right (uncomment to run these checks)
-- SELECT id, name, total_participants, total_collected_count, current_position, plan_status FROM plans;
-- SELECT id, plan_id, position, has_collected, collection_date FROM contributions ORDER BY plan_id, position;
-- SHOW TABLES;
