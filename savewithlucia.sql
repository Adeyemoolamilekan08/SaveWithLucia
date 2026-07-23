-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 22, 2026 at 05:19 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `savewithlucia`
--

-- --------------------------------------------------------

--
-- Table structure for table `contributions`
--

CREATE TABLE `contributions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `collection_date` date DEFAULT NULL,
  `payout_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` enum('online','cash') DEFAULT 'online',
  `status` enum('active','completed','removed') DEFAULT 'active',
  `has_collected` tinyint(1) DEFAULT 0,
  `collected_at` datetime DEFAULT NULL,
  `last_reminder_sent` date DEFAULT NULL,
  `joined_at` datetime DEFAULT current_timestamp(),
  `next_payment_date` date DEFAULT NULL,
  `total_cycles_paid` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `to_email` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('payment','collection','reminder','info','warning') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `contribution_id` int(11) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('paid','pending','failed') DEFAULT 'pending',
  `receipt_file` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payouts`
--

CREATE TABLE `payouts` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `contribution_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `collection_date` date NOT NULL,
  `status` enum('pending','paid','skipped') DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payout_schedule`
--

CREATE TABLE `payout_schedule` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `payout_date` date DEFAULT NULL,
  `status` enum('pending','completed','skipped') DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `contribution_amount` decimal(10,2) NOT NULL,
  `frequency_days` int(11) DEFAULT 7,
  `total_participants` int(11) DEFAULT 5,
  `total_collected_count` int(11) DEFAULT 0 COMMENT 'Running count of members who have collected. Updated on each payout.',
  `current_position` int(11) DEFAULT 1 COMMENT 'Which position is currently due to collect',
  `plan_start_date` date DEFAULT NULL,
  `plan_status` enum('open','active','completed') DEFAULT 'open',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `cycle_type` enum('daily','weekly','monthly','custom') DEFAULT 'custom'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `name`, `description`, `contribution_amount`, `frequency_days`, `total_participants`, `total_collected_count`, `current_position`, `plan_start_date`, `plan_status`, `is_active`, `created_at`, `cycle_type`) VALUES
(1, 'Weekly Ajo — 5 People', 'Each member pays ₦5,000 weekly. Collect ₦25,000 on your turn.', 5000.00, 7, 5, 0, 5, NULL, 'open', 1, '2026-07-22 07:51:57', 'weekly'),
(2, 'Weekly Ajo — 10 People', 'Each member pays ₦3,000 weekly. Collect ₦30,000 on your turn.', 3000.00, 7, 10, 0, 10, NULL, 'open', 1, '2026-07-22 07:51:57', 'weekly'),
(3, 'Monthly Ajo — 6 People', 'Each member pays ₦10,000 monthly. Collect ₦60,000 on your turn.', 10000.00, 30, 6, 0, 6, NULL, 'open', 1, '2026-07-22 07:51:57', 'monthly'),
(4, 'Daily Ajo — 7 People', 'Each member pays ₦1,000 daily. Collect ₦7,000 on your turn.', 1000.00, 1, 7, 0, 7, NULL, 'open', 1, '2026-07-22 07:51:57', 'daily'),
(5, 'Weekly Ajo — 5 People', 'Each member pays ₦5,000 weekly. Collect ₦25,000 on your turn.', 5000.00, 7, 5, 0, 5, NULL, 'open', 1, '2026-07-22 07:54:58', 'weekly'),
(6, 'Weekly Ajo — 10 People', 'Each member pays ₦3,000 weekly. Collect ₦30,000 on your turn.', 3000.00, 7, 10, 0, 10, NULL, 'open', 1, '2026-07-22 07:54:58', 'weekly'),
(7, 'Monthly Ajo — 6 People', 'Each member pays ₦10,000 monthly. Collect ₦60,000 on your turn.', 10000.00, 30, 6, 0, 6, NULL, 'open', 1, '2026-07-22 07:54:58', 'monthly'),
(8, 'Daily Ajo — 7 People', 'Each member pays ₦1,000 daily. Collect ₦7,000 on your turn.', 1000.00, 1, 7, 0, 7, NULL, 'open', 1, '2026-07-22 07:54:58', 'daily'),
(9, 'Daily Ajo — 7 People', 'Pay ₦1,000 daily. Collect ₦7,000 on your turn.', 1000.00, 1, 7, 0, 7, NULL, 'open', 1, '2026-07-22 07:54:58', 'daily'),
(10, 'Weekly Ajo — 5 People', 'Pay ₦5,000 weekly. Collect ₦25,000 on your turn.', 5000.00, 7, 5, 0, 5, NULL, 'open', 1, '2026-07-22 07:54:58', 'weekly'),
(11, 'Weekly Ajo — 10 People', 'Pay ₦3,000 weekly. Collect ₦30,000 on your turn.', 3000.00, 7, 10, 0, 10, NULL, 'open', 1, '2026-07-22 07:54:58', 'weekly'),
(12, 'Monthly Ajo — 6 People', 'Pay ₦10,000 monthly. Collect ₦60,000 on your turn.', 10000.00, 30, 6, 0, 6, NULL, 'open', 1, '2026-07-22 07:54:58', 'monthly');

-- --------------------------------------------------------

--
-- Table structure for table `reminders_sent`
--

CREATE TABLE `reminders_sent` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `contribution_id` int(11) NOT NULL,
  `reminder_type` varchar(50) NOT NULL,
  `sent_date` date NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reminder_log`
--

CREATE TABLE `reminder_log` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `reminder_type` enum('before','today_payer','today_collector','late','manual') NOT NULL,
  `channel` varchar(20) DEFAULT 'email',
  `message` text DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `provider` varchar(50) DEFAULT 'termii',
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `user_code` varchar(20) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `status` enum('active','suspended') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `user_code`, `name`, `email`, `phone`, `password`, `role`, `status`, `last_login`, `created_at`) VALUES
(1, 'SWL-000000', 'Admin', 'admin@savewithlucia.com', '08000000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', NULL, '2026-07-22 07:51:56'),
(5, 'SWL-000001', 'Adeyemo Olamilekan', 'adeyemoolamilekan08@gmail.com', '09133808000', '$2y$10$LPV/wVKxBuQmVnTiswz/3e2QexnlkkRSeG0IZ2rtUZpZmVxwkbwhm', 'admin', 'active', '2026-07-22 08:17:02', '2026-07-22 08:16:09');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contributions`
--
ALTER TABLE `contributions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_slot` (`plan_id`,`position`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contribution_id` (`contribution_id`);

--
-- Indexes for table `payouts`
--
ALTER TABLE `payouts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `contribution_id` (`contribution_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payout_schedule`
--
ALTER TABLE `payout_schedule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_plan_position` (`plan_id`,`position`),
  ADD UNIQUE KEY `unique_plan_user` (`plan_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reminders_sent`
--
ALTER TABLE `reminders_sent`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `no_duplicate` (`contribution_id`,`reminder_type`,`sent_date`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `reminder_log`
--
ALTER TABLE `reminder_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `user_code` (`user_code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contributions`
--
ALTER TABLE `contributions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payouts`
--
ALTER TABLE `payouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payout_schedule`
--
ALTER TABLE `payout_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `reminders_sent`
--
ALTER TABLE `reminders_sent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reminder_log`
--
ALTER TABLE `reminder_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contributions`
--
ALTER TABLE `contributions`
  ADD CONSTRAINT `contributions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `contributions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`contribution_id`) REFERENCES `contributions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payouts`
--
ALTER TABLE `payouts`
  ADD CONSTRAINT `payouts_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payouts_ibfk_2` FOREIGN KEY (`contribution_id`) REFERENCES `contributions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payouts_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payout_schedule`
--
ALTER TABLE `payout_schedule`
  ADD CONSTRAINT `payout_schedule_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payout_schedule_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reminders_sent`
--
ALTER TABLE `reminders_sent`
  ADD CONSTRAINT `reminders_sent_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminders_sent_ibfk_2` FOREIGN KEY (`contribution_id`) REFERENCES `contributions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reminder_log`
--
ALTER TABLE `reminder_log`
  ADD CONSTRAINT `reminder_log_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminder_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
