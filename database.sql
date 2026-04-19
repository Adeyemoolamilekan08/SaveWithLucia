-- ============================================================
-- SAVEWITHLUCIA — Rotational Contribution System
-- Fixed Schema — Run this in phpMyAdmin
-- ============================================================

CREATE DATABASE IF NOT EXISTS savewithlucia
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE savewithlucia;

-- 1. USERS
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_code   VARCHAR(20)  UNIQUE DEFAULT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) NOT NULL UNIQUE,
    phone       VARCHAR(20)  NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('user','admin') DEFAULT 'user',
    status      ENUM('active','suspended') DEFAULT 'active',
    last_login  DATETIME DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. PLANS
CREATE TABLE IF NOT EXISTS plans (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(100)  NOT NULL,
    description           TEXT,
    contribution_amount   DECIMAL(10,2) NOT NULL,
    frequency_days        INT           DEFAULT 7,
    total_participants    INT           DEFAULT 5,
    total_collected_count INT           DEFAULT 0
        COMMENT 'Running count of members who have collected. Updated on each payout.',
    current_position      INT           DEFAULT 1
        COMMENT 'Which position is currently due to collect',
    plan_start_date       DATE          DEFAULT NULL,
    plan_status           ENUM('open','active','completed') DEFAULT 'open',
    is_active             TINYINT(1)    DEFAULT 1,
    created_at            DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. CONTRIBUTIONS
CREATE TABLE IF NOT EXISTS contributions (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT           NOT NULL,
    plan_id             INT           NOT NULL,
    position            INT           NOT NULL,
    collection_date     DATE          DEFAULT NULL,
    payout_amount       DECIMAL(10,2) DEFAULT 0.00,
    payment_method      ENUM('online','cash') DEFAULT 'online',
    status              ENUM('active','completed','removed') DEFAULT 'active',
    has_collected       TINYINT(1)    DEFAULT 0,
    collected_at        DATETIME      DEFAULT NULL,
    last_reminder_sent  DATE          DEFAULT NULL,
    joined_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slot (plan_id, position),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. PAYMENTS
CREATE TABLE IF NOT EXISTS payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    contribution_id INT           NOT NULL,
    reference       VARCHAR(100),
    amount          DECIMAL(10,2) NOT NULL,
    status          ENUM('paid','pending','failed') DEFAULT 'pending',
    receipt_file    VARCHAR(255)  DEFAULT NULL,
    paid_at         DATETIME      DEFAULT NULL,
    FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. PAYOUTS
CREATE TABLE IF NOT EXISTS payouts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT           NOT NULL,
    contribution_id INT           NOT NULL,
    user_id         INT           NOT NULL,
    position        INT           NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    collection_date DATE          NOT NULL,
    status          ENUM('pending','paid','skipped') DEFAULT 'pending',
    paid_at         DATETIME      DEFAULT NULL,
    notes           TEXT          DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id)         REFERENCES plans(id)         ON DELETE CASCADE,
    FOREIGN KEY (contribution_id) REFERENCES contributions(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id)         REFERENCES users(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    title      VARCHAR(150) NOT NULL,
    message    TEXT         NOT NULL,
    type       ENUM('payment','collection','reminder','info','warning') DEFAULT 'info',
    is_read    TINYINT(1)   DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. EMAIL LOGS
CREATE TABLE IF NOT EXISTS email_logs (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id   INT          NOT NULL,
    to_email  VARCHAR(150) NOT NULL,
    subject   VARCHAR(255) NOT NULL,
    status    ENUM('sent','failed') DEFAULT 'sent',
    sent_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. SMS LOGS
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

-- 9. REMINDERS SENT (prevents duplicate daily reminders)
CREATE TABLE IF NOT EXISTS reminders_sent (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT         NOT NULL,
    contribution_id INT         NOT NULL,
    reminder_type   VARCHAR(50) NOT NULL,
    sent_date       DATE        NOT NULL,
    sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY no_duplicate (contribution_id, reminder_type, sent_date),
    FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (contribution_id) REFERENCES contributions(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (user_code, name, email, phone, password, role, status) VALUES
('SWL-000000','Admin','admin@savewithlucia.com','08000000000',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin','active');

INSERT INTO plans (name, description, contribution_amount, frequency_days, total_participants, plan_status) VALUES
('Weekly Ajo — 5 People',  'Each member pays ₦5,000 weekly. Collect ₦25,000 on your turn.',  5000.00,  7,  5,  'open'),
('Weekly Ajo — 10 People', 'Each member pays ₦3,000 weekly. Collect ₦30,000 on your turn.',  3000.00,  7,  10, 'open'),
('Monthly Ajo — 6 People', 'Each member pays ₦10,000 monthly. Collect ₦60,000 on your turn.',10000.00, 30, 6,  'open'),
('Daily Ajo — 7 People',   'Each member pays ₦1,000 daily. Collect ₦7,000 on your turn.',    1000.00,  1,  7,  'open');
