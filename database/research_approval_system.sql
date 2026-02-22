-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 25, 2025 at 08:06 PM
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

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`assignment_id`, `assignment_type`, `context_type`, `context_id`, `user_id`, `role`, `is_active`, `assigned_date`) VALUES
(16, 'reviewer', 'submission', 14, 17, 'adviser', 1, '2025-10-21 18:51:26'),
(17, 'reviewer', 'submission', 14, 20, 'panel', 1, '2025-10-21 18:51:26'),
(18, 'reviewer', 'submission', 14, 16, 'panel', 1, '2025-10-21 18:51:26'),
(20, 'participant', 'discussion', 3, 17, 'adviser', 1, '2025-10-21 18:56:54'),
(21, 'reviewer', 'submission', 18, 17, 'adviser', 1, '2025-10-22 15:19:54'),
(22, 'reviewer', 'submission', 18, 20, 'panel', 1, '2025-10-22 15:19:54'),
(23, 'reviewer', 'submission', 18, 16, 'panel', 1, '2025-10-22 15:19:54'),
(24, 'reviewer', 'submission', 23, 17, 'adviser', 0, '2025-10-23 10:04:21'),
(25, 'reviewer', 'submission', 23, 20, 'panel', 0, '2025-10-23 10:04:21'),
(26, 'reviewer', 'submission', 23, 22, 'panel', 0, '2025-10-23 10:04:21'),
(27, 'reviewer', 'submission', 23, 17, 'adviser', 0, '2025-10-23 10:09:55'),
(28, 'reviewer', 'submission', 23, 16, 'panel', 0, '2025-10-23 10:09:55'),
(29, 'reviewer', 'submission', 23, 22, 'panel', 0, '2025-10-23 10:09:55'),
(30, 'participant', 'discussion', 4, 24, 'student', 1, '2025-10-23 10:15:20'),
(31, 'participant', 'discussion', 4, 17, 'adviser', 1, '2025-10-24 15:14:41'),
(32, 'participant', 'discussion', 4, 16, 'panel', 1, '2025-10-24 23:18:19'),
(33, 'participant', 'discussion', 4, 20, 'panel', 0, '2025-10-23 10:16:06'),
(34, 'participant', 'discussion', 4, 22, 'panel', 1, '2025-10-24 23:18:19'),
(35, 'reviewer', 'submission', 23, 20, 'panel', 0, '2025-10-24 13:33:54'),
(36, 'reviewer', 'submission', 23, 16, 'panel', 0, '2025-10-24 13:33:54'),
(37, 'reviewer', 'submission', 23, 22, 'panel', 0, '2025-10-24 13:33:54'),
(38, 'reviewer', 'submission', 23, 17, 'adviser', 0, '2025-10-24 13:33:54'),
(39, 'reviewer', 'submission', 23, 20, 'panel', 1, '2025-10-24 13:58:21'),
(40, 'reviewer', 'submission', 23, 16, 'panel', 1, '2025-10-24 13:58:21'),
(41, 'reviewer', 'submission', 23, 22, 'panel', 1, '2025-10-24 13:58:21'),
(42, 'reviewer', 'submission', 23, 17, 'adviser', 1, '2025-10-24 13:58:21'),
(43, 'participant', 'discussion', 4, 25, 'adviser', 1, '2025-10-24 23:18:19'),
(44, 'reviewer', 'submission', 27, 20, 'panel', 0, '2025-10-24 22:40:26'),
(45, 'reviewer', 'submission', 27, 16, 'panel', 0, '2025-10-24 22:40:26'),
(46, 'reviewer', 'submission', 27, 22, 'panel', 0, '2025-10-24 22:40:26'),
(47, 'reviewer', 'submission', 27, 17, 'adviser', 0, '2025-10-24 22:40:26'),
(48, 'reviewer', 'submission', 27, 25, 'adviser', 0, '2025-10-24 22:40:26'),
(49, 'reviewer', 'submission', 27, 20, 'panel', 0, '2025-10-24 22:44:00'),
(50, 'reviewer', 'submission', 27, 16, 'panel', 0, '2025-10-24 22:44:00'),
(51, 'reviewer', 'submission', 27, 22, 'panel', 0, '2025-10-24 22:44:00'),
(52, 'reviewer', 'submission', 27, 26, 'panel', 0, '2025-10-24 22:44:00'),
(53, 'reviewer', 'submission', 27, 17, 'adviser', 0, '2025-10-24 22:44:00'),
(54, 'reviewer', 'submission', 27, 25, 'adviser', 0, '2025-10-24 22:44:00'),
(55, 'reviewer', 'submission', 27, 20, 'panel', 0, '2025-10-24 22:48:33'),
(56, 'reviewer', 'submission', 27, 16, 'panel', 0, '2025-10-24 22:48:33'),
(57, 'reviewer', 'submission', 27, 22, 'panel', 0, '2025-10-24 22:48:33'),
(58, 'reviewer', 'submission', 27, 26, 'panel', 0, '2025-10-24 22:48:33'),
(59, 'reviewer', 'submission', 27, 27, 'panel', 0, '2025-10-24 22:48:33'),
(60, 'reviewer', 'submission', 27, 28, 'panel', 0, '2025-10-24 22:48:33'),
(61, 'reviewer', 'submission', 27, 17, 'adviser', 0, '2025-10-24 22:48:33'),
(62, 'reviewer', 'submission', 27, 25, 'adviser', 0, '2025-10-24 22:48:33'),
(63, 'reviewer', 'submission', 27, 29, 'adviser', 0, '2025-10-24 22:48:33'),
(64, 'reviewer', 'submission', 27, 20, 'panel', 0, '2025-10-24 23:00:31'),
(65, 'reviewer', 'submission', 27, 16, 'panel', 0, '2025-10-24 23:00:31'),
(66, 'reviewer', 'submission', 27, 22, 'panel', 0, '2025-10-24 23:00:31'),
(67, 'reviewer', 'submission', 27, 26, 'panel', 0, '2025-10-24 23:00:31'),
(68, 'reviewer', 'submission', 27, 27, 'panel', 0, '2025-10-24 23:00:31'),
(69, 'reviewer', 'submission', 27, 28, 'panel', 0, '2025-10-24 23:00:31'),
(70, 'reviewer', 'submission', 27, 17, 'adviser', 0, '2025-10-24 23:00:31'),
(71, 'reviewer', 'submission', 27, 25, 'adviser', 0, '2025-10-24 23:00:31'),
(72, 'reviewer', 'submission', 27, 29, 'adviser', 0, '2025-10-24 23:00:31'),
(73, 'reviewer', 'submission', 27, 30, 'adviser', 0, '2025-10-24 23:00:31'),
(74, 'reviewer', 'submission', 27, 20, 'panel', 1, '2025-10-24 23:02:06'),
(75, 'reviewer', 'submission', 27, 16, 'panel', 1, '2025-10-24 23:02:06'),
(76, 'reviewer', 'submission', 27, 22, 'panel', 1, '2025-10-24 23:02:06'),
(77, 'reviewer', 'submission', 27, 26, 'panel', 1, '2025-10-24 23:02:06'),
(78, 'reviewer', 'submission', 27, 27, 'panel', 1, '2025-10-24 23:02:06'),
(79, 'reviewer', 'submission', 27, 28, 'panel', 1, '2025-10-24 23:02:06'),
(80, 'reviewer', 'submission', 27, 17, 'adviser', 1, '2025-10-24 23:02:06'),
(81, 'reviewer', 'submission', 27, 25, 'adviser', 1, '2025-10-24 23:02:06'),
(82, 'reviewer', 'submission', 27, 29, 'adviser', 1, '2025-10-24 23:02:06'),
(83, 'participant', 'discussion', 4, 27, 'panel', 1, '2025-10-24 23:18:19');

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

--
-- Dumping data for table `group_memberships`
--

INSERT INTO `group_memberships` (`membership_id`, `group_id`, `user_id`, `member_name`, `student_number`, `is_registered_user`, `join_date`) VALUES
(12, 7, 24, NULL, NULL, 1, '2025-10-23 09:51:42'),
(13, 7, NULL, 'Martin James Lapisboro', '22-01145', 0, '2025-10-23 09:51:42'),
(15, 9, 23, NULL, NULL, 1, '2025-10-24 22:38:18'),
(16, 9, NULL, 'Jhuzen Jhon Mengote', '22-01223', 0, '2025-10-24 22:38:18');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(30) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `successful` tinyint(1) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `username`, `ip_address`, `attempt_time`, `successful`, `user_id`) VALUES
(315, 'Khent123', '::1', '2025-10-24 22:35:54', 1, NULL),
(316, 'admin', '::1', '2025-10-24 22:36:48', 1, NULL),
(317, 'Student2', '::1', '2025-10-24 22:37:22', 1, NULL),
(318, 'admin', '::1', '2025-10-24 22:39:56', 1, NULL),
(319, 'Student2', '::1', '2025-10-24 22:41:03', 1, NULL),
(320, 'admin', '::1', '2025-10-24 22:43:06', 1, NULL),
(321, 'Student2', '::1', '2025-10-24 22:44:12', 1, NULL),
(322, 'admin', '::1', '2025-10-24 22:45:00', 1, NULL),
(323, 'Student2', '::1', '2025-10-24 22:49:11', 1, NULL),
(324, 'Adviser1', '::1', '2025-10-24 22:49:39', 1, NULL),
(325, 'Panel1', '::1', '2025-10-24 22:51:02', 1, NULL),
(326, 'Panel2', '::1', '2025-10-24 22:52:13', 1, NULL),
(327, 'Panel3', '::1', '2025-10-24 22:57:18', 1, NULL),
(328, 'Panel4', '::1', '2025-10-24 22:57:40', 1, NULL),
(329, 'Panel5', '::1', '2025-10-24 22:58:04', 1, NULL),
(330, 'Student2', '::1', '2025-10-24 22:58:19', 1, NULL),
(331, 'Panel6', '::1', '2025-10-24 22:58:45', 1, NULL),
(332, 'admin', '::1', '2025-10-24 22:59:13', 0, NULL),
(333, 'admin', '::1', '2025-10-24 22:59:18', 1, NULL),
(334, 'admin', '::1', '2025-10-24 23:00:12', 1, NULL),
(335, 'Student2', '::1', '2025-10-24 23:00:42', 1, NULL),
(336, 'Adviser1', '::1', '2025-10-24 23:01:19', 1, NULL),
(337, 'admin', '::1', '2025-10-24 23:01:52', 1, NULL),
(338, 'Adviser1', '::1', '2025-10-24 23:02:23', 1, NULL),
(339, 'Panel1', '::1', '2025-10-24 23:03:02', 1, NULL),
(340, 'Student2', '::1', '2025-10-24 23:03:19', 1, NULL),
(341, 'Adviser1', '::1', '2025-10-24 23:03:52', 1, NULL),
(342, 'Student2', '::1', '2025-10-24 23:04:57', 1, NULL),
(343, 'Khent123', '::1', '2025-10-24 23:08:11', 1, NULL),
(344, 'Adviser2', '::1', '2025-10-24 23:17:19', 1, NULL),
(345, 'admin', '::1', '2025-10-24 23:17:36', 0, NULL),
(346, 'admin', '::1', '2025-10-24 23:17:42', 1, NULL),
(347, 'Khent123', '::1', '2025-10-24 23:18:29', 1, NULL),
(348, 'Adviser2', '::1', '2025-10-24 23:18:59', 1, NULL),
(349, 'Adviser2', '::1', '2025-10-24 23:50:25', 1, NULL),
(350, 'admin', '::1', '2025-10-25 01:06:37', 1, NULL),
(351, 'Adviser1', '::1', '2025-10-25 14:32:31', 1, NULL),
(352, 'Adviser1', '::1', '2025-10-25 14:39:57', 1, NULL),
(353, 'Khent123', '::1', '2025-10-25 14:51:44', 1, NULL),
(354, 'Adviser1', '::1', '2025-10-25 14:52:43', 1, NULL),
(355, 'admin', '::1', '2025-10-25 18:05:43', 1, NULL);

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

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `context_type`, `context_id`, `user_id`, `message_type`, `message_text`, `file_path`, `original_filename`, `file_size`, `created_at`) VALUES
(43, 'discussion', 3, 17, 'text', '', 'uploads/thesis_discussion/discussion_3_1761073065_68f7d7a991185.jpg', 'IMG_20250421_163419.jpg', 0, '2025-10-21 18:57:45'),
(45, 'discussion', 3, 17, 'text', '', 'uploads/thesis_discussion/discussion_3_1761073331_68f7d8b362650.pdf', 'How to Play g11.pdf', 0, '2025-10-21 19:02:11'),
(51, 'submission', 22, 17, 'text', '**REVIEW DECISION: NEEDS REVISION**\n\nrevise', NULL, NULL, 0, '2025-10-22 15:47:20'),
(53, 'submission', 22, 17, 'text', '**REVIEW DECISION: NEEDS REVISION**\n\nrevise', NULL, NULL, 0, '2025-10-22 15:50:05'),
(55, 'submission', 22, 17, 'text', 'REVIEW DECISION: NEEDS REVISION\n\ndaw', NULL, NULL, 0, '2025-10-22 15:55:28'),
(58, 'submission', 22, 17, 'text', 'ano po mali', NULL, NULL, 0, '2025-10-22 16:17:55'),
(62, 'submission', 24, 24, 'file', 'Chapter 1 submitted for review.', 'uploads/chapters/chapter_1_7_1761214257.pdf', 'discussion_5_1758113050 (1).pdf', 0, '2025-10-23 10:10:57'),
(63, 'submission', 24, 17, 'text', 'Review Desicion: APPROVED\n\nYES', NULL, NULL, 0, '2025-10-23 10:11:20'),
(64, 'submission', 25, 24, 'file', 'Chapter 2 submitted for review.', 'uploads/chapters/chapter_2_7_1761214372.docx', 'title_6_1761146316.docx', 0, '2025-10-23 10:12:52'),
(65, 'submission', 25, 17, 'text', 'Review Desicion: APPROVED\n\nYes', NULL, NULL, 0, '2025-10-23 10:14:14'),
(66, 'submission', 26, 24, 'file', 'Chapter 3 submitted for review.', 'uploads/chapters/chapter_3_7_1761214482.docx', 'title_5_1761072668.docx', 0, '2025-10-23 10:14:42'),
(67, 'submission', 26, 17, 'text', 'Review Desicion: APPROVED\n\nYes', NULL, NULL, 0, '2025-10-23 10:15:04'),
(68, 'discussion', 4, 24, 'text', '', 'uploads/thesis_discussion/discussion_4_1761214598.pdf', 'discussion_5_1758113050 (1).pdf', 0, '2025-10-23 10:16:38'),
(69, 'discussion', 4, 24, 'text', 'hi', '', '', 0, '2025-10-24 23:18:34'),
(70, 'discussion', 4, 25, 'text', 'hello', '', '', 0, '2025-10-24 23:46:19'),
(71, 'discussion', 4, 25, 'text', '', 'uploads/thesis_discussion/discussion_4_1761349593_68fc0fd942a94.pdf', 'discussion_5_1758113050 (1).pdf', 0, '2025-10-24 23:46:33'),
(72, 'discussion', 4, 25, 'text', 'bye', '', '', 0, '2025-10-24 23:49:12'),
(73, 'discussion', 4, 17, 'text', 'oki', '', '', 0, '2025-10-25 14:33:09');

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `context_type`, `context_id`, `is_read`, `created_at`) VALUES
(79, 1, 'New Title Needs Reviewer Assignment', 'A new research title has been submitted by Philip John Cidro and needs reviewers assigned.', 'title_submission', 'submission', 13, 1, '2025-10-21 14:26:56'),
(80, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Cidro', 'title_assignment', 'submission', 12, 1, '2025-10-21 14:49:01'),
(82, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Cidro', 'title_assignment', 'submission', 12, 1, '2025-10-21 14:49:01'),
(83, 17, 'New Research Group Assigned', 'You have been assigned as adviser to a new research group: Cidro', 'group_assignment', NULL, NULL, 1, '2025-10-21 18:50:54'),
(84, 1, 'New Title Needs Reviewer Assignment', 'A new research title has been submitted by Philip John Cidro and needs reviewers assigned.', 'title_submission', 'submission', 14, 1, '2025-10-21 18:51:08'),
(85, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: oki', 'title_assignment', 'submission', 14, 1, '2025-10-21 18:51:26'),
(86, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: oki', 'title_assignment', 'submission', 14, 1, '2025-10-21 18:51:26'),
(87, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: oki', 'title_assignment', 'submission', 14, 1, '2025-10-21 18:51:26'),
(89, 17, 'New Chapter Submitted', 'Chapter 1 has been submitted by Philip John Cidro', 'chapter_submission', 'submission', 15, 1, '2025-10-21 18:54:05'),
(91, 17, 'New Chapter Submitted', 'Chapter 2 has been submitted by Philip John Cidro', 'chapter_submission', 'submission', 16, 1, '2025-10-21 18:54:51'),
(93, 17, 'New Chapter Submitted', 'Chapter 3 has been submitted by Philip John Cidro', 'chapter_submission', 'submission', 17, 1, '2025-10-21 18:55:27'),
(96, 17, 'New Thesis Message', 'Philip John Cidro sent a new message in thesis discussion', 'thesis_message', 'discussion', 3, 1, '2025-10-21 18:59:15'),
(98, 17, 'New Research Group Assigned', 'You have been assigned as adviser to a new research group: Gomez group', 'group_assignment', NULL, NULL, 1, '2025-10-22 15:16:17'),
(99, 1, 'New Title Needs Reviewer Assignment', 'A new research title has been submitted by Emmanuelle Gomez and needs reviewers assigned.', 'title_submission', 'submission', 18, 1, '2025-10-22 15:18:36'),
(100, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Online Flood Control System', 'title_assignment', 'submission', 18, 1, '2025-10-22 15:19:54'),
(101, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Online Flood Control System', 'title_assignment', 'submission', 18, 1, '2025-10-22 15:19:54'),
(102, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Online Flood Control System', 'title_assignment', 'submission', 18, 1, '2025-10-22 15:19:54'),
(104, 17, 'New Chapter Submitted', 'Chapter 1 has been submitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 19, 1, '2025-10-22 15:27:15'),
(106, 17, 'New Chapter Submitted', 'Chapter 2 has been submitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 20, 1, '2025-10-22 15:30:09'),
(108, 17, 'New Chapter Submitted', 'Chapter 3 has been submitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 21, 1, '2025-10-22 15:33:11'),
(110, 17, 'New Chapter Submitted', 'Chapter 4 has been submitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 22, 1, '2025-10-22 15:35:08'),
(112, 17, 'Chapter Resubmitted', 'Chapter 4 has been resubmitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 22, 1, '2025-10-22 15:46:52'),
(114, 17, 'Chapter Resubmitted', 'Chapter 4 has been resubmitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 22, 1, '2025-10-22 15:49:50'),
(116, 17, 'Chapter Resubmitted', 'Chapter 4 has been resubmitted by Emmanuelle Gomez', 'chapter_submission', 'submission', 22, 1, '2025-10-22 15:55:02'),
(118, 17, 'New Chapter Message', 'Emmanuelle Gomez sent you a message about Chapter 4', 'chapter_message', 'submission', 22, 1, '2025-10-22 16:14:13'),
(119, 17, 'New Chapter Message', 'Emmanuelle Gomez sent you a message about Chapter 4', 'chapter_message', 'submission', 22, 1, '2025-10-22 16:14:27'),
(121, 17, 'New Chapter Message', 'Emmanuelle Gomez sent you a message about Chapter 4', 'chapter_message', 'submission', 22, 1, '2025-10-22 16:23:03'),
(122, 17, 'New Chapter Message', 'Emmanuelle Gomez sent you a message about Chapter 4', 'chapter_message', 'submission', 22, 1, '2025-10-22 16:35:07'),
(123, 17, 'New Chapter Message', 'Emmanuelle Gomez sent you a message about Chapter 4', 'chapter_message', 'submission', 22, 1, '2025-10-22 16:38:43'),
(124, 17, 'New Research Group Assigned', 'You have been assigned as adviser to a new research group: Catalo', 'group_assignment', NULL, NULL, 1, '2025-10-23 09:51:42'),
(125, 1, 'New Title Needs Reviewer Assignment', 'A new research title has been submitted by Khent Gabrielle Catalo and needs reviewers assigned.', 'title_submission', 'submission', 23, 1, '2025-10-23 09:53:38'),
(126, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 1, '2025-10-23 10:04:21'),
(127, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-23 10:04:21'),
(128, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-23 10:04:21'),
(129, 24, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 23, 1, '2025-10-23 10:04:21'),
(130, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 1, '2025-10-23 10:09:55'),
(131, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-23 10:09:55'),
(132, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-23 10:09:55'),
(133, 24, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 23, 1, '2025-10-23 10:09:55'),
(134, 17, 'New Chapter Submitted', 'Chapter 1 has been submitted by Khent Gabrielle Catalo', 'chapter_submission', 'submission', 24, 1, '2025-10-23 10:10:57'),
(135, 24, 'Chapter Approved', 'Your chapter has been approved.', 'chapter_review', 'submission', 24, 1, '2025-10-23 10:11:20'),
(136, 17, 'New Chapter Submitted', 'Chapter 2 has been submitted by Khent Gabrielle Catalo', 'chapter_submission', 'submission', 25, 1, '2025-10-23 10:12:52'),
(137, 24, 'Chapter Approved', 'Your chapter has been approved.', 'chapter_review', 'submission', 25, 1, '2025-10-23 10:14:14'),
(138, 17, 'New Chapter Submitted', 'Chapter 3 has been submitted by Khent Gabrielle Catalo', 'chapter_submission', 'submission', 26, 1, '2025-10-23 10:14:42'),
(139, 24, 'Chapter Approved', 'Your chapter has been approved.', 'chapter_review', 'submission', 26, 1, '2025-10-23 10:15:04'),
(140, 16, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-23 10:16:06'),
(141, 20, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-23 10:16:06'),
(142, 22, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-23 10:16:06'),
(143, 17, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 1, '2025-10-23 10:16:38'),
(144, 16, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-23 10:16:38'),
(145, 20, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-23 10:16:38'),
(146, 22, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-23 10:16:38'),
(147, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:33:54'),
(148, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:33:54'),
(149, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:33:54'),
(150, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 1, '2025-10-24 13:33:54'),
(151, 24, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 23, 1, '2025-10-24 13:33:54'),
(152, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:58:21'),
(153, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:58:21'),
(154, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 0, '2025-10-24 13:58:21'),
(155, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title', 'title_assignment', 'submission', 23, 1, '2025-10-24 13:58:21'),
(156, 24, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 23, 1, '2025-10-24 13:58:21'),
(157, 22, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-24 15:12:57'),
(158, 17, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 1, '2025-10-24 15:12:57'),
(159, 17, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 1, '2025-10-24 15:14:41'),
(160, 25, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 1, '2025-10-24 15:18:38'),
(161, 25, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 1, '2025-10-24 15:20:08'),
(162, 25, 'New Research Group Assigned', 'You have been assigned as adviser to a new research group: Name', 'group_assignment', NULL, NULL, 1, '2025-10-24 22:38:18'),
(163, 1, 'New Title Needs Reviewer Assignment', 'A new research title \"Title 1\" has been submitted by Student 2 and needs reviewers assigned.', 'title_submission', 'submission', 27, 1, '2025-10-24 22:39:22'),
(164, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:40:26'),
(165, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:40:26'),
(166, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:40:26'),
(167, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:40:26'),
(168, 25, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:40:26'),
(169, 23, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 27, 1, '2025-10-24 22:40:26'),
(170, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:44:00'),
(171, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:44:00'),
(172, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:44:00'),
(173, 26, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:44:00'),
(174, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:44:00'),
(175, 25, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:44:00'),
(176, 23, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 27, 1, '2025-10-24 22:44:00'),
(177, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:48:33'),
(178, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(179, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(180, 26, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(181, 27, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(182, 28, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(183, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:48:33'),
(184, 25, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 22:48:33'),
(185, 29, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 22:48:33'),
(186, 23, 'Reviewers Assigned', 'Reviewers have been assigned to your research title by the administrator.', 'reviewer_assigned', 'submission', 27, 1, '2025-10-24 22:48:33'),
(187, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(188, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(189, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(190, 26, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(191, 27, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(192, 28, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(193, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 23:00:31'),
(194, 25, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 23:00:31'),
(195, 29, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(196, 30, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:00:31'),
(197, 23, 'Reviewers Assigned', 'New reviewers have been assigned to your research title by the administrator. Your existing approvals (5) remain valid.', 'reviewer_assigned', 'submission', 27, 0, '2025-10-24 23:00:31'),
(198, 20, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(199, 16, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(200, 22, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(201, 26, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(202, 27, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(203, 28, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(204, 17, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 23:02:06'),
(205, 25, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 1, '2025-10-24 23:02:06'),
(206, 29, 'New Research Title for Review', 'The administrator has assigned you to review a research title: Title 1', 'title_assignment', 'submission', 27, 0, '2025-10-24 23:02:06'),
(207, 23, 'Reviewers Assigned', 'New reviewers have been assigned to your research title by the administrator. Your existing approvals (5) remain valid.', 'reviewer_assigned', 'submission', 27, 0, '2025-10-24 23:02:06'),
(208, 16, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-24 23:18:19'),
(209, 22, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-24 23:18:19'),
(210, 27, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 0, '2025-10-24 23:18:19'),
(211, 25, 'Added to Thesis Discussion', 'You have been added to a thesis discussion by the administrator.', 'discussion_added', 'discussion', 4, 1, '2025-10-24 23:18:19'),
(212, 17, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 1, '2025-10-24 23:18:34'),
(213, 16, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-24 23:18:34'),
(214, 22, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-24 23:18:34'),
(215, 25, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 1, '2025-10-24 23:18:34'),
(216, 27, 'New Thesis Message', 'Khent Gabrielle Catalo sent a new message in thesis discussion', 'thesis_message', 'discussion', 4, 0, '2025-10-24 23:18:34'),
(217, 16, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:19'),
(218, 17, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 1, '2025-10-24 23:46:19'),
(219, 22, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:19'),
(220, 24, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:19'),
(221, 27, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:19'),
(222, 16, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:33'),
(223, 17, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 1, '2025-10-24 23:46:33'),
(224, 22, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:33'),
(225, 24, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:33'),
(226, 27, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:46:33'),
(227, 16, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:49:12'),
(228, 17, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 1, '2025-10-24 23:49:12'),
(229, 22, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:49:12'),
(230, 24, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:49:12'),
(231, 27, 'New Discussion Message', 'Adviser 2 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-24 23:49:12'),
(232, 16, 'New Discussion Message', 'Adviser 1 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-25 14:33:09'),
(233, 22, 'New Discussion Message', 'Adviser 1 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-25 14:33:09'),
(234, 24, 'New Discussion Message', 'Adviser 1 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 1, '2025-10-25 14:33:09'),
(235, 25, 'New Discussion Message', 'Adviser 1 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-25 14:33:09'),
(236, 27, 'New Discussion Message', 'Adviser 1 posted a new message in the thesis discussion', 'discussion', 'discussion', 4, 0, '2025-10-25 14:33:09');

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

--
-- Dumping data for table `research_groups`
--

INSERT INTO `research_groups` (`group_id`, `group_name`, `lead_student_id`, `adviser_id`, `college`, `program`, `year_level`, `created_at`) VALUES
(7, 'Catalo', 24, 17, 'College of Computer Studies', 'Information Technology', 'Third Year', '2025-10-23 09:51:42'),
(9, 'Name', 23, 25, 'College of Computer Studies', 'Information Technology', 'Third Year', '2025-10-24 22:38:18');

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
  `review_type` enum('title','chapter') NOT NULL DEFAULT 'title'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`review_id`, `submission_id`, `reviewer_id`, `comments`, `decision`, `review_date`, `review_type`) VALUES
(34, 23, 17, 'Yes', 'approve', '2025-10-23 10:06:10', 'title'),
(35, 23, 22, 'Yes', 'approve', '2025-10-23 10:07:27', 'title'),
(36, 23, 16, 'Yes', 'approve', '2025-10-23 10:10:14', 'title'),
(37, 24, 17, 'YES', 'approve', '2025-10-23 10:11:20', 'title'),
(38, 25, 17, 'Yes', 'approve', '2025-10-23 10:14:14', 'title'),
(39, 26, 17, 'Yes', 'approve', '2025-10-23 10:15:04', 'title'),
(40, 27, 17, 'Yes', 'approve', '2025-10-24 22:50:42', 'title'),
(41, 27, 20, 'Yes', 'approve', '2025-10-24 22:52:05', 'title'),
(42, 27, 16, 'No', 'reject', '2025-10-24 22:53:04', 'title'),
(43, 27, 22, 'Yes', 'approve', '2025-10-24 22:57:29', 'title'),
(44, 27, 26, 'Yes', 'approve', '2025-10-24 22:57:47', 'title'),
(45, 27, 27, 'Yes', 'approve', '2025-10-24 22:58:12', 'title');

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
  `approval_date` datetime DEFAULT NULL,
  `revision_number` int(11) DEFAULT 1,
  `parent_submission_id` int(11) DEFAULT NULL,
  `required_approvals` int(11) DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `submissions`
--

INSERT INTO `submissions` (`submission_id`, `group_id`, `submission_type`, `title`, `description`, `chapter_number`, `document_path`, `status`, `submission_date`, `approval_date`, `revision_number`, `parent_submission_id`, `required_approvals`) VALUES
(23, 7, 'title', 'Title', 'desctiption', NULL, 'uploads/titles/title_7_1761213218.pdf', 'approved', '2025-10-23 09:53:38', '2025-10-23 18:10:14', 1, NULL, 3),
(24, 7, 'chapter', 'Chapter 1', NULL, 1, 'uploads/chapters/chapter_1_7_1761214257.pdf', 'approved', '2025-10-23 10:10:57', '2025-10-23 12:11:20', 1, NULL, 3),
(25, 7, 'chapter', 'Chapter 2', NULL, 2, 'uploads/chapters/chapter_2_7_1761214372.docx', 'approved', '2025-10-23 10:12:52', '2025-10-23 12:14:14', 1, NULL, 3),
(26, 7, 'chapter', 'Chapter 3', NULL, 3, 'uploads/chapters/chapter_3_7_1761214482.docx', 'approved', '2025-10-23 10:14:42', '2025-10-23 12:15:04', 1, NULL, 3),
(27, 9, 'title', 'Title 1', 'short', NULL, 'uploads/titles/title_9_1761345562.pdf', 'approved', '2025-10-24 22:39:22', '2025-10-25 06:58:12', 1, NULL, 8);

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

--
-- Dumping data for table `thesis_discussions`
--

INSERT INTO `thesis_discussions` (`discussion_id`, `group_id`, `title_id`, `title`, `description`, `created_at`, `updated_at`) VALUES
(4, 7, 23, 'Title', NULL, '2025-10-23 10:15:20', '2025-10-23 10:15:20');

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
(1, 'admin', '$2y$10$iGX1ZgJwjdmt23nEV7KA/ukBvy2t7tbr5lg6Ln5sVbshDCbe35.Ee', 'admin@essu.edu.ph', 'System', 'Administrator', 'admin', NULL, NULL, NULL, NULL, 1, 'approved', NULL, 0, NULL, '2025-10-25 18:05:43', NULL, '2025-09-27 06:17:32', '2025-10-25 18:06:19'),
(16, 'Panel2', '$2y$10$uj7dVbmJXA/RKJvuea7ajefmK7.EvjR4VqKFELPn..VtBReK8UpJK', 'Panel2@gmail.com', 'Panel', '2', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 22:52:13', NULL, '2025-10-21 14:23:22', '2025-10-24 22:57:11'),
(17, 'Adviser1', '$2y$10$d.dAZsdtkc86nPMFm02Ye.ITi3F/OTtBhREANYNutltFqx/SeWXlu', 'Adviser@gmail.com', 'Adviser', '1', 'adviser', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-25 14:52:43', NULL, '2025-10-21 14:24:37', '2025-10-25 18:05:35'),
(20, 'Panel1', '$2y$10$DhNHpxK1u5z0JETbl2/rEOunchPj0J7aYj8IaKO7/fqQoeVFimt7W', 'Panel1@gmail.com', 'Panel', '1', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 23:03:02', NULL, '2025-10-21 18:50:15', '2025-10-24 23:03:11'),
(22, 'Panel3', '$2y$10$oclKXgZWbwyvxfbPxcPU9.frN0elIi0R.x9KGFzo24J.py4Il8oxi', 'Panel3@gmail.com', 'Panel', '3', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 22:57:18', NULL, '2025-10-23 07:48:11', '2025-10-24 22:57:32'),
(23, 'Student2', '$2y$10$hkooXl7CSOzfOuTj61hb/Oll2oDVd0qh9sATOu3Y87teMUtT00gQ.', 'Student2@gmail.com', 'Student', '2', 'student', 'College of Computer Studies', 'Information Technology', '22-01413', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 23:04:57', NULL, '2025-10-23 07:49:10', '2025-10-24 23:08:03'),
(24, 'Khent123', '$2y$10$THOyuTRZWU0g01Cih2IGV.GUIgBJYW1Njh1HGcet3olidlwlSAwLC', 'KhentGabrielle@gmail.com', 'Khent Gabrielle', 'Catalo', 'student', 'College of Computer Studies', 'Information Technology', '22-01312', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-25 14:51:44', NULL, '2025-10-23 09:49:05', '2025-10-25 14:52:37'),
(25, 'Adviser2', '$2y$10$e30.SoRd5DiFL65rPkiVM.tYHGvobstXHj8Fkrv3Q3cvcpT31cHv.', 'Adviser2@gmail.com', 'Adviser', '2', 'adviser', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 23:50:25', NULL, '2025-10-24 15:18:26', '2025-10-24 23:51:48'),
(26, 'Panel4', '$2y$10$dMWu5Q3IftFNiTOlYlxdvu4/P5cNEvT1GEuM2KIY1c9ff2ztvLPgy', 'Panel4@gmail.com', 'Panel', '4', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 22:57:40', NULL, '2025-10-24 22:43:42', '2025-10-24 22:57:54'),
(27, 'Panel5', '$2y$10$zht0K2sZUtgcvyIuCVfk0.VxCsbzfpi3JfbotHLi2X7bNxaXU0Ip2', 'Panel5@gmail.com', 'Panel', '5', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 22:58:04', NULL, '2025-10-24 22:45:44', '2025-10-24 22:58:15'),
(28, 'Panel6', '$2y$10$FVVeH/S7/XcW/ispB9QFq.6AN9sMf9hxcx1JRAK6I7/MxUs1OdPRq', 'Panel6@gmail.com', 'Panel', '6', 'panel', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, '2025-10-24 22:58:45', NULL, '2025-10-24 22:46:23', '2025-10-24 22:58:58'),
(29, 'Adviser3', '$2y$10$5EZr6kKSduNSrSnyINPJ9ueRIe4vTsL61tsm/FRNEWoQvUGlrpvJ.', 'adviser3@gmail.com', 'Adviser', '3', 'adviser', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, NULL, NULL, '2025-10-24 22:47:48', '2025-10-24 22:47:48'),
(30, 'Adviser4', '$2y$10$YvTcyW.2O2JLvo22iCMEjuFFUIKpoizXuzRqxagljQnW9poU6OD8y', 'Adviser4@gmail.com', 'Adviser', '4', 'adviser', 'College of Computer Studies', 'Information Technology', '', NULL, 1, 'approved', NULL, 0, NULL, NULL, NULL, '2025-10-24 23:00:01', '2025-10-24 23:00:01');

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
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `group_memberships`
--
ALTER TABLE `group_memberships`
  MODIFY `membership_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=356;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=237;

--
-- AUTO_INCREMENT for table `research_groups`
--
ALTER TABLE `research_groups`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2925;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `thesis_discussions`
--
ALTER TABLE `thesis_discussions`
  MODIFY `discussion_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

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
