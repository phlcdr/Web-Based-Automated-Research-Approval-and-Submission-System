-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 06:31 PM
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
-- Database: `research_approval_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `assignment_type` enum('reviewer','participant') NOT NULL,
  `context_type` enum('submission','discussion') NOT NULL,
  `context_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('student','adviser','panel') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `assigned_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_memberships`
--

CREATE TABLE `group_memberships` (
  `membership_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `member_name` varchar(100) DEFAULT NULL,
  `student_number` varchar(15) DEFAULT NULL,
  `is_registered_user` tinyint(1) DEFAULT 1,
  `join_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(30) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `successful` tinyint(1) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `context_type` enum('submission','discussion','general') NOT NULL,
  `context_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message_type` enum('text','file','image','system') DEFAULT 'text',
  `message_text` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `original_filename` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(80) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(30) NOT NULL,
  `context_type` enum('submission','discussion','group','system') DEFAULT NULL,
  `context_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `research_groups`
--

CREATE TABLE `research_groups` (
  `group_id` int(11) NOT NULL,
  `group_name` varchar(80) NOT NULL,
  `lead_student_id` int(11) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `college` varchar(80) NOT NULL,
  `program` varchar(50) NOT NULL,
  `year_level` varchar(15) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `comments` text DEFAULT NULL,
  `decision` enum('approve','reject','needs_revision') NOT NULL,
  `review_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `review_type` enum('title','chapter') NOT NULL DEFAULT 'title'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`, `created_by`, `updated_by`) VALUES
(1, 'site_title', 'Research Approval System', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(2, 'admin_email', 'admin@essu.edu.ph', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(3, 'max_file_size', '25', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(4, 'allowed_file_types', 'doc,docx,pdf', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(5, 'smtp_host', '', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(6, 'smtp_port', '587', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(7, 'smtp_user', '', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(8, 'smtp_pass', '', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(9, 'min_password_length', '6', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(10, 'require_special_chars', '1', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(11, 'session_timeout', '30', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(12, 'max_login_attempts', '3', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(13, 'lockout_duration', '5', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL),
(14, 'require_admin_approval', '1', '2025-09-27 06:17:32', '2025-09-27 06:17:32', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `submission_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `submission_type` enum('title','chapter') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `chapter_number` int(11) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `submission_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approval_date` datetime DEFAULT NULL,
  `revision_number` int(11) DEFAULT 1,
  `parent_submission_id` int(11) DEFAULT NULL,
  `required_approvals` int(11) DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `thesis_discussions`
--

CREATE TABLE `thesis_discussions` (
  `discussion_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `title_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(30) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `first_name` varchar(30) NOT NULL,
  `last_name` varchar(30) NOT NULL,
  `role` enum('student','adviser','panel','admin') NOT NULL,
  `college` varchar(80) DEFAULT NULL,
  `department` varchar(80) DEFAULT NULL,
  `student_id` varchar(15) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `registration_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `rejection_reason` text DEFAULT NULL,
  `account_locked` tinyint(1) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `session_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `first_name`, `last_name`, `role`, `college`, `department`, `student_id`, `profile_picture`, `is_active`, `registration_status`, `rejection_reason`, `account_locked`, `locked_until`, `last_login`, `session_expires_at`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$iGX1ZgJwjdmt23nEV7KA/ukBvy2t7tbr5lg6Ln5sVbshDCbe35.Ee', 'admin@essu.edu.ph', 'System', 'Administrator', 'admin', NULL, NULL, NULL, NULL, 1, 'approved', NULL, 0, NULL, '2026-04-08 10:35:21', NULL, '2025-09-27 06:17:32', '2026-04-08 10:37:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `idx_assignments_context` (`context_type`,`context_id`),
  ADD KEY `idx_assignments_user_type` (`user_id`,`assignment_type`);

--
-- Indexes for table `group_memberships`
--
ALTER TABLE `group_memberships`
  ADD PRIMARY KEY (`membership_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_login_attempts_username_ip` (`username`,`ip_address`),
  ADD KEY `idx_login_attempts_time` (`attempt_time`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_messages_context` (`context_type`,`context_id`),
  ADD KEY `idx_messages_user_date` (`user_id`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `research_groups`
--
ALTER TABLE `research_groups`
  ADD PRIMARY KEY (`group_id`),
  ADD KEY `lead_student_id` (`lead_student_id`),
  ADD KEY `adviser_id` (`adviser_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `reviewer_id` (`reviewer_id`),
  ADD KEY `idx_reviews_submission` (`submission_id`),
  ADD KEY `idx_reviews_decision` (`decision`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD KEY `parent_submission_id` (`parent_submission_id`),
  ADD KEY `idx_submissions_group_type` (`group_id`,`submission_type`),
  ADD KEY `idx_submissions_status` (`status`),
  ADD KEY `idx_submissions_type_status` (`submission_type`,`status`);

--
-- Indexes for table `thesis_discussions`
--
ALTER TABLE `thesis_discussions`
  ADD PRIMARY KEY (`discussion_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `title_id` (`title_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_memberships`
--
ALTER TABLE `group_memberships`
  MODIFY `membership_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `research_groups`
--
ALTER TABLE `research_groups`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3373;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `thesis_discussions`
--
ALTER TABLE `thesis_discussions`
  MODIFY `discussion_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `group_memberships`
--
ALTER TABLE `group_memberships`
  ADD CONSTRAINT `group_memberships_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `research_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_memberships_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD CONSTRAINT `login_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `research_groups`
--
ALTER TABLE `research_groups`
  ADD CONSTRAINT `research_groups_ibfk_1` FOREIGN KEY (`lead_student_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `research_groups_ibfk_2` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`submission_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `settings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `settings_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `fk_submissions_group` FOREIGN KEY (`group_id`) REFERENCES `research_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `research_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`parent_submission_id`) REFERENCES `submissions` (`submission_id`) ON DELETE SET NULL;

--
-- Constraints for table `thesis_discussions`
--
ALTER TABLE `thesis_discussions`
  ADD CONSTRAINT `thesis_discussions_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `research_groups` (`group_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `thesis_discussions_ibfk_2` FOREIGN KEY (`title_id`) REFERENCES `submissions` (`submission_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
