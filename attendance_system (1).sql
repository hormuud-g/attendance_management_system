-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 26, 2026 at 03:22 PM
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
-- Database: `attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_term`
--

CREATE TABLE `academic_term` (
  `academic_term_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `term_name` enum('A','B') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `academic_term`
--

INSERT INTO `academic_term` (`academic_term_id`, `academic_year_id`, `term_name`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'A', '2026-02-26', '2026-03-13', 'active', '2026-02-25 16:17:12', '2026-02-25 19:17:43');

-- --------------------------------------------------------

--
-- Table structure for table `academic_year`
--

CREATE TABLE `academic_year` (
  `academic_year_id` int(11) NOT NULL,
  `year_name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `academic_year`
--

INSERT INTO `academic_year` (`academic_year_id`, `year_name`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 'sanad 2025/26', '2026-02-25', '2026-03-27', 'active', '2026-02-25 16:16:47', '2026-02-25 16:16:47');

-- --------------------------------------------------------

--
-- Table structure for table `announcement`
--

CREATE TABLE `announcement` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `target_role` enum('all','student','teacher','parent','admin') DEFAULT 'all',
  `section_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `subject_id` int(11) UNSIGNED DEFAULT NULL,
  `class_id` int(11) UNSIGNED DEFAULT NULL,
  `teacher_id` int(11) UNSIGNED DEFAULT NULL,
  `room_id` int(11) UNSIGNED DEFAULT NULL,
  `academic_term_id` int(11) UNSIGNED NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','excused') DEFAULT 'present',
  `locked` tinyint(1) DEFAULT 0,
  `locked_by` int(11) UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `student_id`, `subject_id`, `class_id`, `teacher_id`, `room_id`, `academic_term_id`, `attendance_date`, `status`, `locked`, `locked_by`, `locked_at`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, NULL, 1, '2026-02-26', 'absent', 0, NULL, NULL, NULL, '2026-02-26 17:19:49', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_audit`
--

CREATE TABLE `attendance_audit` (
  `audit_id` int(11) NOT NULL,
  `attendance_id` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `old_status` enum('present','absent','late','excused') DEFAULT NULL,
  `new_status` enum('present','absent','late','excused') DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `change_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_correction`
--

CREATE TABLE `attendance_correction` (
  `leave_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `subject_id` int(10) UNSIGNED DEFAULT NULL,
  `teacher_id` int(10) UNSIGNED DEFAULT NULL,
  `academic_term_id` int(10) UNSIGNED NOT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `reason` enum('sick','family','travel','other') NOT NULL,
  `reason_details` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `days_count` int(11) NOT NULL CHECK (`days_count` between 1 and 30),
  `original_status` varchar(20) DEFAULT NULL,
  `corrected_status` varchar(20) DEFAULT NULL,
  `end_date` date GENERATED ALWAYS AS (`start_date` + interval `days_count` - 1 day) STORED,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `is_closed` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `attendance_correction`
--
DELIMITER $$
CREATE TRIGGER `tr_attendance_correction_approved` AFTER UPDATE ON `attendance_correction` FOR EACH ROW BEGIN
                DECLARE v_current_date DATE;
                DECLARE v_counter INT DEFAULT 0;
                
                IF OLD.status = 'pending' AND NEW.status = 'approved' THEN
                    SET v_current_date = NEW.start_date;
                    
                    WHILE v_counter < NEW.days_count DO
                        INSERT INTO attendance (
                            student_id, class_id, teacher_id, subject_id, 
                            academic_term_id, attendance_date, status, created_at
                        )
                        SELECT 
                            NEW.student_id,
                            tt.class_id,
                            tt.teacher_id,
                            tt.subject_id,
                            NEW.academic_term_id,
                            v_current_date,
                            'excused',
                            NOW()
                        FROM timetable tt
                        WHERE tt.subject_id = NEW.subject_id
                        AND tt.teacher_id = NEW.teacher_id
                        ON DUPLICATE KEY UPDATE 
                            status = 'excused',
                            updated_at = NOW();
                        
                        SET v_current_date = DATE_ADD(v_current_date, INTERVAL 1 DAY);
                        SET v_counter = v_counter + 1;
                    END WHILE;
                    
                    UPDATE attendance_correction 
                    SET notification_sent = TRUE,
                        notification_date = NOW()
                    WHERE leave_id = NEW.leave_id;
                END IF;
            END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_lock`
--

CREATE TABLE `attendance_lock` (
  `lock_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `academic_term_id` int(11) DEFAULT NULL,
  `lock_date` date NOT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` datetime DEFAULT current_timestamp(),
  `unlocked_by` int(11) DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `action_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`log_id`, `user_id`, `action_type`, `description`, `ip_address`, `user_agent`, `action_time`) VALUES
(1, 1, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 16:21:14'),
(2, 30, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 16:21:20'),
(3, 30, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 16:31:01'),
(4, 1, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 16:31:13'),
(5, 1, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 17:00:06'),
(6, 27, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 17:00:18'),
(7, 27, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 17:00:51'),
(8, 1, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 17:01:06'),
(9, 1, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 19:24:11'),
(10, 28, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 19:24:36'),
(11, 28, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 19:31:40'),
(12, 27, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-25 19:31:52'),
(13, 1, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 14:11:55'),
(14, 1, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 14:35:04'),
(15, 27, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 14:51:30'),
(16, 27, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 15:02:40'),
(17, 30, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 15:02:49'),
(18, 30, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 17:14:03'),
(19, 27, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 17:14:14'),
(20, 27, 'logout', 'User logged out of the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 17:15:18'),
(21, 1, 'login', 'User logged into the system', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Avast/144.0.0.0', '2026-02-26 17:15:36');

-- --------------------------------------------------------

--
-- Table structure for table `campus`
--

CREATE TABLE `campus` (
  `campus_id` int(11) NOT NULL,
  `campus_name` varchar(100) NOT NULL,
  `campus_code` varchar(20) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Somalia',
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `campus`
--

INSERT INTO `campus` (`campus_id`, `campus_name`, `campus_code`, `address`, `contact_number`, `city`, `country`, `phone_number`, `email`, `profile_photo_path`, `status`, `created_at`, `updated_at`) VALUES
(1, 'banadir', 'BN', 'karaan', NULL, 'Mogadishu', 'somalia', '6172272728', 'banadir.hu.edu.som@gmail.com', NULL, 'active', '2026-02-25 16:12:00', NULL),
(2, 'THree', 'km13', 'Garasbaleey', NULL, 'Mogadishu', 'somalia', '619655335', 'KM13.hu.edu.som@gmail.com', NULL, 'active', '2026-02-25 16:12:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `class_id` int(11) NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `study_mode` enum('Full-Time','Part-Time') NOT NULL DEFAULT 'Full-Time',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `class_name`, `campus_id`, `department_id`, `faculty_id`, `program_id`, `study_mode`, `status`, `created_at`, `updated_at`) VALUES
(1, 'BSE1', 1, 1, 1, 1, 'Full-Time', 'Active', '2026-02-25 16:16:41', '2026-02-26 14:19:32'),
(2, 'BSE1', 1, 1, 1, 1, 'Part-Time', 'Inactive', '2026-02-26 11:34:32', '2026-02-26 14:16:50');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `head_of_department` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `office_location` varchar(255) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `campus_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `faculty_id`, `department_name`, `department_code`, `head_of_department`, `phone_number`, `email`, `office_location`, `profile_photo_path`, `status`, `created_at`, `campus_id`) VALUES
(1, 1, 'Computer science and it', 'CIT', 'ikraan ali', '617227272', 'ikraan@gmail.com', 'karaan, Mogadishu, somalia', NULL, 'active', '2026-02-25 16:14:06', 1),
(2, 1, 'Computer science and it', 'CIT', 'Sheikh noor', '617227283', 'sheikhnoor@gmail.com', 'Garasbaleey, Mogadishu, somalia', NULL, 'active', '2026-02-25 16:14:46', 2),
(3, 3, 'software eng', 'SE b', 'ENG IKRAAN', '123', 'ikii@gmail.com', 'karaan, Mogadishu, somalia', NULL, 'active', '2026-02-25 19:16:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `log_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `message_type` varchar(50) DEFAULT NULL,
  `absence_count` int(11) DEFAULT 0,
  `status` varchar(20) DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`log_id`, `student_id`, `recipient_email`, `subject`, `message`, `message_type`, `absence_count`, `status`, `error_message`, `sent_at`) VALUES
(1, 1, 'cabdirahmanjmaxamad@gmail.com', 'Digniin Maqnaansho Maanta', '\r\nMudane/Marwo abdirahman mohamed,\r\n\r\nTani waa fariin rasmi ah oo ka timid Xafiiska Kuliyadaada.\r\n\r\nDiiwaannadeenna Attendance System waxay muujinayaan inaad maanta maqantahay, waxaana tani tahay maqnaanshahaaga 1 ee maadada: MAP PRO.\r\n\r\nFadlan ogow in haddii maqnaanshahaagu gaaro shan (5) jeer, lagaa joojin doono maadadan (MAP PRO) waxaana ay noqon doontaa RECOURSE.\r\n\r\nWaxaan ku dhiirigelinaynaa inaad ka soo qayb gasho casharrada haray si aad uga fogaato cawaaqib waxbarasho.\r\n\r\nHaddii aad u maleyneyso in digniintani ay khalad tahay, fadlan si degdeg ah ula xiriir Xafiiska Arrimaha Tacliinta.\r\n\r\nMahadsanid,\r\nXafiiska Arrimaha Tacliinta\r\n', 'absence', 1, 'sent', NULL, '2026-02-26 17:19:55'),
(2, 1, 'nimco@gmail.com', 'Ardaygaagu waa Maqan Yahay nimco abdulle', '\r\nMudane/Marwo nimco abdulle,\r\n\r\nTani waa fariin rasmi ah oo ka timid Xafiiska Arrimaha Tacliinta ee jamacadda Hormuud.\r\n\r\nArdaygaaga abdirahman mohamed maanta waa maqan yahay, waxaana maqnaanshihiisu hadda gaaray 1 jeer ee maadada: MAP PRO.\r\n\r\nFadlan la soco ka soo qaybgalka casharrada si aad uga fogaato cawaaqib waxbarasho ee ku imaan karto ardaygaaga abdirahman mohamed.\r\n\r\nMahadsanid,\r\nXafiiska Arrimaha Tacliinta\r\n', 'absence', 1, 'sent', NULL, '2026-02-26 17:19:59');

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `template_id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` enum('student_welcome','student_update','absence_warning','recourse_notification') NOT NULL,
  `subject_so` varchar(255) NOT NULL,
  `subject_en` varchar(255) NOT NULL,
  `body_so` text NOT NULL,
  `body_en` text NOT NULL,
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`variables`)),
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculties`
--

CREATE TABLE `faculties` (
  `faculty_id` int(11) NOT NULL,
  `faculty_name` varchar(100) NOT NULL,
  `faculty_code` varchar(20) NOT NULL,
  `dean_name` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `office_address` varchar(255) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculties`
--

INSERT INTO `faculties` (`faculty_id`, `faculty_name`, `faculty_code`, `dean_name`, `phone_number`, `email`, `office_address`, `profile_photo_path`, `status`, `created_at`) VALUES
(1, 'computer science and information technology', 'CIT', 'abdirahman Huseen', '617171717', 'abdi@gmail.com', 'km13', NULL, 'active', '2026-02-25 16:13:29'),
(3, 'Economic', 'ec', 'abdirahman', '61717171717', 'abdir@gmail.com', 'kr', NULL, 'active', '2026-02-25 19:15:29');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_campus`
--

CREATE TABLE `faculty_campus` (
  `faculty_id` int(11) NOT NULL,
  `campus_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_campus`
--

INSERT INTO `faculty_campus` (`faculty_id`, `campus_id`) VALUES
(1, 1),
(1, 2),
(3, 1);

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

CREATE TABLE `login_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `announcement_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `read_status` enum('unread','read') DEFAULT 'unread',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `campus_id` int(11) DEFAULT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `notification_type` enum('announcement','absence','recourse','system') DEFAULT 'system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `parent_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `gender` enum('male','female') DEFAULT 'male',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `parents`
--

INSERT INTO `parents` (`parent_id`, `full_name`, `gender`, `phone`, `email`, `address`, `occupation`, `photo_path`, `status`, `created_at`) VALUES
(1, 'nimco abdulle', 'male', '+252616586913', 'nimco@gmail.com', 'karaan', 'mamuule', NULL, 'active', '2026-02-25 19:21:05');

-- --------------------------------------------------------

--
-- Table structure for table `parent_student`
--

CREATE TABLE `parent_student` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `relation_type` enum('father','mother','sister','brother','guardian','other') DEFAULT 'guardian',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `parent_student`
--

INSERT INTO `parent_student` (`id`, `parent_id`, `student_id`, `relation_type`, `created_at`) VALUES
(2, 1, 1, 'guardian', '2026-02-26 17:19:32');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `campus_id` int(11) NOT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program_name` varchar(100) NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `duration_years` int(11) NOT NULL DEFAULT 4,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program_id`, `campus_id`, `faculty_id`, `department_id`, `program_name`, `program_code`, `duration_years`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'software enginer', 'SE', 4, '', 'active', '2026-02-25 13:32:10', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `promotion_history`
--

CREATE TABLE `promotion_history` (
  `promotion_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `old_faculty_id` int(11) DEFAULT NULL,
  `old_department_id` int(11) DEFAULT NULL,
  `old_program_id` int(11) DEFAULT NULL,
  `old_semester_id` int(11) DEFAULT NULL,
  `old_class_id` int(11) DEFAULT NULL,
  `new_faculty_id` int(11) DEFAULT NULL,
  `new_department_id` int(11) DEFAULT NULL,
  `new_program_id` int(11) DEFAULT NULL,
  `new_semester_id` int(11) DEFAULT NULL,
  `new_class_id` int(11) DEFAULT NULL,
  `old_campus_id` int(11) DEFAULT NULL,
  `new_campus_id` int(11) DEFAULT NULL,
  `promoted_by` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `promotion_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recourse_student`
--

CREATE TABLE `recourse_student` (
  `recourse_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `original_campus_id` int(11) NOT NULL,
  `original_faculty_id` int(11) NOT NULL,
  `original_department_id` int(11) NOT NULL,
  `original_program_id` int(11) NOT NULL,
  `original_class_id` int(11) NOT NULL,
  `original_semester_id` int(11) NOT NULL,
  `recourse_campus_id` int(11) NOT NULL,
  `recourse_faculty_id` int(11) NOT NULL,
  `recourse_department_id` int(11) NOT NULL,
  `recourse_program_id` int(11) NOT NULL,
  `recourse_class_id` int(11) NOT NULL,
  `recourse_semester_id` int(11) NOT NULL,
  `academic_term_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` int(11) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `building_name` varchar(100) DEFAULT NULL,
  `floor_no` varchar(10) DEFAULT NULL,
  `room_name` varchar(100) NOT NULL,
  `room_code` varchar(20) NOT NULL,
  `capacity` int(11) DEFAULT 0,
  `room_type` enum('Lecture','Lab','Seminar','Office','Online') DEFAULT 'Lecture',
  `description` varchar(255) DEFAULT NULL,
  `status` enum('available','maintenance','inactive') DEFAULT 'available',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `campus_id`, `faculty_id`, `department_id`, `building_name`, `floor_no`, `room_name`, `room_code`, `capacity`, `room_type`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'flor1', 'flor2', 'room1', 'mr1', 50, 'Lecture', '', 'available', '2026-02-25 16:15:29', '2026-02-25 16:15:29'),
(2, 2, 1, 2, 'flor1', 'flor2', 'room1', 'mr1', 50, 'Lecture', '', 'available', '2026-02-25 16:15:49', '2026-02-25 16:15:49');

-- --------------------------------------------------------

--
-- Table structure for table `room_allocation`
--

CREATE TABLE `room_allocation` (
  `allocation_id` int(11) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `allocated_to` enum('Class','Exam','Maintenance') DEFAULT 'Class',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_count` int(11) DEFAULT 0,
  `status` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `semester`
--

CREATE TABLE `semester` (
  `semester_id` int(11) NOT NULL,
  `semester_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semester`
--

INSERT INTO `semester` (`semester_id`, `semester_name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'foundation 1', '', 'active', '2026-02-25 16:17:29', '2026-02-25 16:17:29'),
(2, 'foundation 2', '', 'active', '2026-02-25 16:17:36', '2026-02-25 16:17:36'),
(3, 'semester 1', '', 'active', '2026-02-25 16:17:48', '2026-02-25 16:17:48'),
(4, 'semester 2', '', 'active', '2026-02-25 16:18:00', '2026-02-25 16:18:00'),
(5, 'semester 3', '', 'active', '2026-02-25 16:18:12', '2026-02-25 16:18:12'),
(6, 'semester 4', '', 'active', '2026-02-25 16:18:25', '2026-02-25 16:18:25'),
(7, 'semester 5', '', 'active', '2026-02-25 16:18:37', '2026-02-25 16:18:37'),
(8, 'semester 6', '', 'active', '2026-02-25 16:18:50', '2026-02-25 16:18:50'),
(9, 'semester 7', '', 'active', '2026-02-25 16:19:01', '2026-02-25 16:19:01'),
(10, 'semester 8', '', 'active', '2026-02-25 16:19:13', '2026-02-25 16:19:13');

-- --------------------------------------------------------

--
-- Table structure for table `stopped_subjects`
--

CREATE TABLE `stopped_subjects` (
  `stopped_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `campus_id` int(11) NOT NULL,
  `absence_count` int(11) DEFAULT 0,
  `stopped_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `student_uuid` char(36) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `reg_no` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `gender` enum('male','female') DEFAULT 'male',
  `dob` date DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `student_uuid`, `parent_id`, `campus_id`, `faculty_id`, `department_id`, `reg_no`, `full_name`, `gender`, `dob`, `phone_number`, `email`, `address`, `photo_path`, `class_id`, `program_id`, `semester_id`, `guardian_name`, `guardian_phone`, `status`, `created_at`, `updated_at`) VALUES
(1, 'e447c2ca-1260-11f1-adf5-24ee9af0e497', 1, 1, 1, 1, 'HU0045678', 'abdirahman mohamed', 'male', '2005-02-25', '619655335', 'cabdirahmanjmaxamad@gmail.com', 'karaan', '', 1, 1, 1, NULL, NULL, 'active', '2026-02-25 19:21:05', '2026-02-26 17:19:32');

-- --------------------------------------------------------

--
-- Table structure for table `student_enroll`
--

CREATE TABLE `student_enroll` (
  `enroll_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `academic_term_id` int(11) NOT NULL,
  `status` enum('active','dropped','completed') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_enroll`
--

INSERT INTO `student_enroll` (`enroll_id`, `student_id`, `campus_id`, `faculty_id`, `department_id`, `program_id`, `subject_id`, `class_id`, `semester_id`, `academic_term_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 'active', '2026-02-26 17:18:53', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_grades`
--

CREATE TABLE `student_grades` (
  `grade_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `grade` varchar(5) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_subject`
--

CREATE TABLE `student_subject` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `assigned_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subject`
--

CREATE TABLE `subject` (
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `academic_term_id` int(11) DEFAULT NULL,
  `subject_name` varchar(100) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `credit_hours` int(11) DEFAULT 3,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subject`
--

INSERT INTO `subject` (`subject_id`, `class_id`, `campus_id`, `faculty_id`, `department_id`, `semester_id`, `academic_year_id`, `academic_term_id`, `subject_name`, `subject_code`, `program_id`, `credit_hours`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, 1, 1, 'MAP PRO', 'MPI', 1, 2, '', 'active', '2026-02-25 19:18:27', '2026-02-26 17:17:04');

-- --------------------------------------------------------

--
-- Table structure for table `subject_allocations`
--

CREATE TABLE `subject_allocations` (
  `allocation_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `campus_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `teacher_uuid` varchar(20) NOT NULL,
  `teacher_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `qualification` varchar(255) DEFAULT NULL,
  `position_title` varchar(255) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`teacher_id`, `teacher_uuid`, `teacher_name`, `email`, `phone_number`, `gender`, `qualification`, `position_title`, `profile_photo_path`, `status`, `created_at`) VALUES
(1, 'HU0000001', 'Hasan ali', 'hassan@gmail.com', '617432334', 'Male', 'pdf', 'lecture', NULL, 'active', '2026-02-25 16:19:44');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_attendance`
--

CREATE TABLE `teacher_attendance` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `minutes_worked` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teacher_attendance`
--

INSERT INTO `teacher_attendance` (`id`, `teacher_id`, `academic_term_id`, `date`, `time_in`, `time_out`, `minutes_worked`, `notes`, `locked`, `locked_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-02-26', '2026-02-26 17:15:46', NULL, NULL, '', 0, NULL, '2026-02-26 14:15:46', '2026-02-26 14:15:46');

-- --------------------------------------------------------

--
-- Table structure for table `test_attendance_correction`
--

CREATE TABLE `test_attendance_correction` (
  `leave_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `timetable_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timetable`
--

CREATE TABLE `timetable` (
  `timetable_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `academic_term_id` int(11) NOT NULL,
  `day_of_week` enum('Sun','Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('active','inactive','cancelled') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `campus_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `timetable`
--

INSERT INTO `timetable` (`timetable_id`, `subject_id`, `class_id`, `faculty_id`, `teacher_id`, `room_id`, `academic_term_id`, `day_of_week`, `start_time`, `end_time`, `status`, `created_at`, `updated_at`, `campus_id`, `department_id`, `program_id`) VALUES
(1, 1, 1, 1, 1, 1, 1, 'Thu', '16:20:00', '23:20:00', 'active', '2026-02-26 16:18:19', '2026-02-26 17:16:36', 1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `user_uuid` char(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_plain` varchar(255) DEFAULT NULL,
  `role` enum('super_admin','campus_admin','faculty_admin','department_admin','teacher','student','parent','auditor') NOT NULL,
  `linked_id` int(11) DEFAULT NULL,
  `linked_table` enum('campus','faculty','department','teacher','student','parent','auditor') DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `user_uuid`, `username`, `first_name`, `last_name`, `email`, `phone_number`, `profile_photo_path`, `password`, `password_plain`, `role`, `linked_id`, `linked_table`, `last_login`, `status`, `created_at`, `updated_at`) VALUES
(1, 'user_695562cf793c56.87438041', 'abdirahman_mohamed', 'abdirahman', 'mohamed', 'abdirahmanmohamed@gmail.com', '619655335', 'upload/profiles/profile_695562cf78769_IMG-20241228-WA0013.jpg', '$2y$10$PMgM4RuLRKVHQwm4s5AAC.0MxdD7JBJd0X1qrzrHmISpowRER7GCu', 'Mamaan@123', 'super_admin', NULL, NULL, '2026-02-26 17:15:36', 'active', '2025-12-31 20:52:15', '2026-02-26 17:15:36'),
(27, '4a1f2b1f8b710c3f28b540346bb101ed', 'banadir', '', '', 'banadir.hu.edu.som@gmail.com', '6172272728', NULL, '$2y$10$V2aL.8PRmPp.6yiHYFljkO0MwsjZiPbll3kSRvFIkOPrXzHLWyOrW', '123', 'campus_admin', 1, 'campus', '2026-02-26 17:14:14', 'active', '2026-02-25 16:12:01', '2026-02-26 17:14:14'),
(28, 'e0858476e590118789916ea0febd6627', 'THree', '', '', 'KM13.hu.edu.som@gmail.com', '619655335', NULL, '$2y$10$rQkdu7i6zKVhEV44cG3bkOzM8FJ9J7cRX1s9AdChIM5yFkhmh7Ux.', '123', 'campus_admin', 2, 'campus', '2026-02-25 19:24:36', 'active', '2026-02-25 16:12:32', '2026-02-25 19:24:36'),
(29, '2619bcbd18eb1e56c2c6b80a164fbdc7', 'computer science and information technology (CIT)', '', '', 'abdi@gmail.com', '617171717', NULL, '$2y$10$Om4/IVHlMMYqkk1.xKPVu.rA/Eg33jszft7XBYAuFmgTT.p0EtyAa', '123', 'faculty_admin', 1, 'faculty', NULL, 'active', '2026-02-25 16:13:29', '2026-02-25 16:13:29'),
(30, '1a3fefd2fdcf044b9b6844373b3358d5', 'Computer science and it', '', '', 'ikraan@gmail.com', '617227272', NULL, '$2y$10$4J6xyIW4jkDYb3cTnm6Z8.hNWQPffumlK4uE5xSIUhb0YZQtEcsOu', '123', 'department_admin', 1, 'department', '2026-02-26 15:02:49', 'active', '2026-02-25 16:14:06', '2026-02-26 15:02:49'),
(31, '497c51e91eca0095a04ece189bbdffa8', 'Computer science and it', '', '', 'sheikhnoor@gmail.com', '617227283', NULL, '$2y$10$ykIx4CbqwtT4pfzSq3YtPO9OVxBe/Oo.rD2bD31LmZ8Bd1ebuQubG', '123', 'department_admin', 2, 'department', NULL, 'active', '2026-02-25 16:14:47', '2026-02-25 16:14:47'),
(33, 'd4a3ec1ecedb4a7f312f7381669351f3', 'Economic (ec)', '', '', 'abdir@gmail.com', '61717171717', NULL, '$2y$10$QLaW5t/KfjKKbZCiq03lz.VuaaO9h7hXEjeay3kbyLUwvxXn2Ad2i', '123', 'faculty_admin', 3, 'faculty', NULL, 'active', '2026-02-25 19:15:29', '2026-02-25 19:15:29'),
(34, '809f9de43c37ecddf5642ec706c67444', 'software eng', '', '', 'ikii@gmail.com', '123', NULL, '$2y$10$mL1T9OZFtyqa2vGbWO3KZeZXnNTJu3Rs6paYFzuZ0q3QbPwnp8MBG', '123', 'department_admin', 3, 'department', NULL, 'active', '2026-02-25 19:16:00', '2026-02-25 19:16:00'),
(35, 'HU0000001', 'Hasan ali', '', '', 'hassan@gmail.com', '617432334', NULL, '$2y$10$4iJ.xUi.M/WFHOx60YK7LeMC2YY.8H0AGVDV.FlvZByeB2yeA7nlO', '123', 'teacher', 1, '', NULL, 'active', '2026-02-25 19:19:44', '2026-02-25 19:19:44'),
(36, 'e447a7b1-1260-11f1-adf5-24ee9af0e497', 'nimco abdulle', '', '', 'nimco@gmail.com', '+252616586913', 'upload/profiles/default.png', '$2y$10$60ZZUVrvzugGH6TvIXT2qu1VZm0vn6OVG2dr.Vr5xkH.sAQeaL70K', '123', 'parent', 1, 'parent', NULL, 'active', '2026-02-25 19:21:05', '2026-02-25 19:21:05'),
(37, 'e450dcd2-1260-11f1-adf5-24ee9af0e497', 'HU0045678', '', '', 'abdihaliim@gmail.com', '619655335', 'upload/profiles/default.png', '$2y$10$MBFSO4ucsshiQt2Wyqo2yOuQYWbeidD/kgMb1ZQeMmkb336pydjNa', '123', 'student', 1, 'student', NULL, 'active', '2026-02-25 19:21:05', '2026-02-25 19:21:05');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `menu_item` varchar(100) NOT NULL,
  `status` enum('allowed','restricted') DEFAULT 'allowed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_term`
--
ALTER TABLE `academic_term`
  ADD PRIMARY KEY (`academic_term_id`),
  ADD KEY `academic_year_id` (`academic_year_id`);

--
-- Indexes for table `academic_year`
--
ALTER TABLE `academic_year`
  ADD PRIMARY KEY (`academic_year_id`),
  ADD UNIQUE KEY `year_name` (`year_name`);

--
-- Indexes for table `announcement`
--
ALTER TABLE `announcement`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_subject_id` (`subject_id`),
  ADD KEY `idx_class_id` (`class_id`),
  ADD KEY `idx_teacher_id` (`teacher_id`),
  ADD KEY `idx_room_id` (`room_id`),
  ADD KEY `idx_academic_term_id` (`academic_term_id`);

--
-- Indexes for table `attendance_audit`
--
ALTER TABLE `attendance_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `attendance_id` (`attendance_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `attendance_correction`
--
ALTER TABLE `attendance_correction`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_term` (`academic_term_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requested_by` (`requested_by`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `idx_dates` (`start_date`,`end_date`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `attendance_lock`
--
ALTER TABLE `attendance_lock`
  ADD PRIMARY KEY (`lock_id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `locked_by` (`locked_by`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `audit_log_ibfk_1` (`user_id`);

--
-- Indexes for table `campus`
--
ALTER TABLE `campus`
  ADD PRIMARY KEY (`campus_id`),
  ADD UNIQUE KEY `campus_code` (`campus_code`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`class_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD UNIQUE KEY `unique_template_name` (`template_name`);

--
-- Indexes for table `faculties`
--
ALTER TABLE `faculties`
  ADD PRIMARY KEY (`faculty_id`),
  ADD UNIQUE KEY `faculty_code` (`faculty_code`);

--
-- Indexes for table `faculty_campus`
--
ALTER TABLE `faculty_campus`
  ADD PRIMARY KEY (`faculty_id`,`campus_id`),
  ADD KEY `campus_id` (`campus_id`);

--
-- Indexes for table `login_history`
--
ALTER TABLE `login_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `announcement_id` (`announcement_id`),
  ADD KEY `idx_user_read_status` (`user_id`,`read_status`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`parent_id`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `parent_student`
--
ALTER TABLE `parent_student`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `parent_id` (`parent_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD UNIQUE KEY `unique_program_per_campus` (`program_name`,`campus_id`),
  ADD UNIQUE KEY `unique_code_per_campus` (`program_code`,`campus_id`),
  ADD KEY `fk_program_campus` (`campus_id`),
  ADD KEY `fk_program_faculty` (`faculty_id`),
  ADD KEY `fk_program_department` (`department_id`);

--
-- Indexes for table `promotion_history`
--
ALTER TABLE `promotion_history`
  ADD PRIMARY KEY (`promotion_id`);

--
-- Indexes for table `recourse_student`
--
ALTER TABLE `recourse_student`
  ADD PRIMARY KEY (`recourse_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `original_campus_id` (`original_campus_id`),
  ADD KEY `original_faculty_id` (`original_faculty_id`),
  ADD KEY `original_department_id` (`original_department_id`),
  ADD KEY `original_program_id` (`original_program_id`),
  ADD KEY `original_class_id` (`original_class_id`),
  ADD KEY `original_semester_id` (`original_semester_id`),
  ADD KEY `recourse_campus_id` (`recourse_campus_id`),
  ADD KEY `recourse_faculty_id` (`recourse_faculty_id`),
  ADD KEY `recourse_department_id` (`recourse_department_id`),
  ADD KEY `recourse_program_id` (`recourse_program_id`),
  ADD KEY `recourse_class_id` (`recourse_class_id`),
  ADD KEY `recourse_semester_id` (`recourse_semester_id`),
  ADD KEY `academic_term_id` (`academic_term_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `unique_room_per_campus` (`campus_id`,`room_code`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `room_allocation`
--
ALTER TABLE `room_allocation`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `unique_room` (`room_id`,`academic_term_id`,`allocated_to`,`start_date`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `semester`
--
ALTER TABLE `semester`
  ADD PRIMARY KEY (`semester_id`),
  ADD UNIQUE KEY `semester_name` (`semester_name`);

--
-- Indexes for table `stopped_subjects`
--
ALTER TABLE `stopped_subjects`
  ADD PRIMARY KEY (`stopped_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `idx_student_subject` (`student_id`,`subject_id`,`academic_term_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `student_uuid` (`student_uuid`),
  ADD KEY `reg_no` (`reg_no`),
  ADD KEY `email` (`email`),
  ADD KEY `campus_id` (`campus_id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `program_id` (`program_id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `student_enroll`
--
ALTER TABLE `student_enroll`
  ADD PRIMARY KEY (`enroll_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_subject_id` (`subject_id`),
  ADD KEY `idx_class_id` (`class_id`),
  ADD KEY `idx_semester_id` (`semester_id`),
  ADD KEY `idx_academic_term_id` (`academic_term_id`),
  ADD KEY `fk_enroll_campus` (`campus_id`),
  ADD KEY `fk_enroll_faculty` (`faculty_id`),
  ADD KEY `fk_enroll_department` (`department_id`),
  ADD KEY `fk_enroll_program` (`program_id`);

--
-- Indexes for table `student_grades`
--
ALTER TABLE `student_grades`
  ADD PRIMARY KEY (`grade_id`),
  ADD KEY `fk_grade_subject` (`subject_id`);

--
-- Indexes for table `student_subject`
--
ALTER TABLE `student_subject`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `subject`
--
ALTER TABLE `subject`
  ADD PRIMARY KEY (`subject_id`),
  ADD UNIQUE KEY `unique_subject_code_per_campus` (`campus_id`,`subject_code`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `semester_id` (`semester_id`),
  ADD KEY `fk_subject_faculty` (`faculty_id`),
  ADD KEY `fk_subject_program` (`program_id`),
  ADD KEY `idx_subject_academic_year` (`academic_year_id`),
  ADD KEY `idx_subject_academic_term` (`academic_term_id`);

--
-- Indexes for table `subject_allocations`
--
ALTER TABLE `subject_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `fk_allocation_subject` (`subject_id`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`teacher_id`),
  ADD UNIQUE KEY `teacher_uuid` (`teacher_uuid`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `teacher_attendance`
--
ALTER TABLE `teacher_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_teacher_day` (`teacher_id`,`date`),
  ADD KEY `idx_teacher_date` (`teacher_id`,`date`),
  ADD KEY `locked_by` (`locked_by`),
  ADD KEY `academic_term_id` (`academic_term_id`);

--
-- Indexes for table `test_attendance_correction`
--
ALTER TABLE `test_attendance_correction`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `timetable_id` (`timetable_id`);

--
-- Indexes for table `timetable`
--
ALTER TABLE `timetable`
  ADD PRIMARY KEY (`timetable_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `user_uuid` (`user_uuid`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_menu` (`user_id`,`menu_item`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_term`
--
ALTER TABLE `academic_term`
  MODIFY `academic_term_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `academic_year`
--
ALTER TABLE `academic_year`
  MODIFY `academic_year_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `announcement`
--
ALTER TABLE `announcement`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_audit`
--
ALTER TABLE `attendance_audit`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_correction`
--
ALTER TABLE `attendance_correction`
  MODIFY `leave_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_lock`
--
ALTER TABLE `attendance_lock`
  MODIFY `lock_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `campus`
--
ALTER TABLE `campus`
  MODIFY `campus_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculties`
--
ALTER TABLE `faculties`
  MODIFY `faculty_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `parent_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `parent_student`
--
ALTER TABLE `parent_student`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `promotion_history`
--
ALTER TABLE `promotion_history`
  MODIFY `promotion_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recourse_student`
--
ALTER TABLE `recourse_student`
  MODIFY `recourse_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `room_allocation`
--
ALTER TABLE `room_allocation`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `semester`
--
ALTER TABLE `semester`
  MODIFY `semester_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `stopped_subjects`
--
ALTER TABLE `stopped_subjects`
  MODIFY `stopped_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_enroll`
--
ALTER TABLE `student_enroll`
  MODIFY `enroll_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_grades`
--
ALTER TABLE `student_grades`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_subject`
--
ALTER TABLE `student_subject`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subject`
--
ALTER TABLE `subject`
  MODIFY `subject_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subject_allocations`
--
ALTER TABLE `subject_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `teacher_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `teacher_attendance`
--
ALTER TABLE `teacher_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `test_attendance_correction`
--
ALTER TABLE `test_attendance_correction`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timetable`
--
ALTER TABLE `timetable`
  MODIFY `timetable_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academic_term`
--
ALTER TABLE `academic_term`
  ADD CONSTRAINT `academic_term_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_year` (`academic_year_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `announcement`
--
ALTER TABLE `announcement`
  ADD CONSTRAINT `announcement_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `attendance_audit`
--
ALTER TABLE `attendance_audit`
  ADD CONSTRAINT `attendance_audit_ibfk_1` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `attendance_audit_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `faculty_campus`
--
ALTER TABLE `faculty_campus`
  ADD CONSTRAINT `faculty_campus_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `faculty_campus_ibfk_2` FOREIGN KEY (`campus_id`) REFERENCES `campus` (`campus_id`) ON DELETE CASCADE;

--
-- Constraints for table `login_history`
--
ALTER TABLE `login_history`
  ADD CONSTRAINT `login_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_announcement_id` FOREIGN KEY (`announcement_id`) REFERENCES `announcement` (`announcement_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `fk_program_campus` FOREIGN KEY (`campus_id`) REFERENCES `campus` (`campus_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_program_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_program_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`) ON DELETE SET NULL;

--
-- Constraints for table `recourse_student`
--
ALTER TABLE `recourse_student`
  ADD CONSTRAINT `recourse_student_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `recourse_student_ibfk_10` FOREIGN KEY (`recourse_faculty_id`) REFERENCES `faculties` (`faculty_id`),
  ADD CONSTRAINT `recourse_student_ibfk_11` FOREIGN KEY (`recourse_department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `recourse_student_ibfk_12` FOREIGN KEY (`recourse_program_id`) REFERENCES `programs` (`program_id`),
  ADD CONSTRAINT `recourse_student_ibfk_13` FOREIGN KEY (`recourse_class_id`) REFERENCES `classes` (`class_id`),
  ADD CONSTRAINT `recourse_student_ibfk_14` FOREIGN KEY (`recourse_semester_id`) REFERENCES `semester` (`semester_id`),
  ADD CONSTRAINT `recourse_student_ibfk_15` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_term` (`academic_term_id`),
  ADD CONSTRAINT `recourse_student_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `recourse_student_ibfk_3` FOREIGN KEY (`original_campus_id`) REFERENCES `campus` (`campus_id`),
  ADD CONSTRAINT `recourse_student_ibfk_4` FOREIGN KEY (`original_faculty_id`) REFERENCES `faculties` (`faculty_id`),
  ADD CONSTRAINT `recourse_student_ibfk_5` FOREIGN KEY (`original_department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `recourse_student_ibfk_6` FOREIGN KEY (`original_program_id`) REFERENCES `programs` (`program_id`),
  ADD CONSTRAINT `recourse_student_ibfk_7` FOREIGN KEY (`original_class_id`) REFERENCES `classes` (`class_id`),
  ADD CONSTRAINT `recourse_student_ibfk_8` FOREIGN KEY (`original_semester_id`) REFERENCES `semester` (`semester_id`),
  ADD CONSTRAINT `recourse_student_ibfk_9` FOREIGN KEY (`recourse_campus_id`) REFERENCES `campus` (`campus_id`);

--
-- Constraints for table `stopped_subjects`
--
ALTER TABLE `stopped_subjects`
  ADD CONSTRAINT `stopped_subjects_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `stopped_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`),
  ADD CONSTRAINT `stopped_subjects_ibfk_3` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_term` (`academic_term_id`);

--
-- Constraints for table `student_enroll`
--
ALTER TABLE `student_enroll`
  ADD CONSTRAINT `fk_enroll_campus` FOREIGN KEY (`campus_id`) REFERENCES `campus` (`campus_id`),
  ADD CONSTRAINT `fk_enroll_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`),
  ADD CONSTRAINT `fk_enroll_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `fk_enroll_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`),
  ADD CONSTRAINT `fk_enroll_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`),
  ADD CONSTRAINT `fk_enroll_semester` FOREIGN KEY (`semester_id`) REFERENCES `semester` (`semester_id`),
  ADD CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `fk_enroll_subject` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`);

--
-- Constraints for table `student_grades`
--
ALTER TABLE `student_grades`
  ADD CONSTRAINT `fk_grade_subject` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `subject`
--
ALTER TABLE `subject`
  ADD CONSTRAINT `fk_subject_academic_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_term` (`academic_term_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_subject_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_year` (`academic_year_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `subject_allocations`
--
ALTER TABLE `subject_allocations`
  ADD CONSTRAINT `fk_allocation_subject` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `test_attendance_correction`
--
ALTER TABLE `test_attendance_correction`
  ADD CONSTRAINT `test_attendance_correction_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `test_attendance_correction_ibfk_2` FOREIGN KEY (`timetable_id`) REFERENCES `timetable` (`timetable_id`);

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
