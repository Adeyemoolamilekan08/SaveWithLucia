-- ============================================================
-- forgot_password.sql
-- Run this in phpMyAdmin BEFORE uploading the PHP files
-- ============================================================
USE iyvpahdu_savewithlucia;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    token      VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   DEFAULT 0,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
