-- School Management Database Schema (Complete Rebuild)

CREATE TABLE IF NOT EXISTS `sm_schools` (
  `id` VARCHAR(255) PRIMARY KEY,
  `slug` VARCHAR(100) UNIQUE NOT NULL,
  `custom_domain` VARCHAR(255) UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `logo_url` TEXT,
  `motto` TEXT,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `wallet_balance` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_super_moderators` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fullname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_moderators` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `fullname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_teachers` (
  `id_number` VARCHAR(100) NOT NULL,
  `school_id_number` VARCHAR(255) NOT NULL,
  `lastname` VARCHAR(100) NOT NULL,
  `firstname` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `class` VARCHAR(100) DEFAULT NULL,
  `phone_number` VARCHAR(100) DEFAULT NULL,
  `gender` VARCHAR(50) DEFAULT NULL,
  `dob` VARCHAR(50) DEFAULT NULL,
  `home_address` TEXT DEFAULT NULL,
  `can_view_payments` TINYINT(1) DEFAULT 0,
  `is_sub_admin` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`school_id_number`, `id_number`),
  UNIQUE KEY `school_teacher_email` (`school_id_number`, `email`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_parents` (
  `id_number` VARCHAR(100) NOT NULL,
  `school_id_number` VARCHAR(255) NOT NULL,
  `fullname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone_number` VARCHAR(100) DEFAULT NULL,
  `home_address` TEXT DEFAULT NULL,
  PRIMARY KEY (`school_id_number`, `id_number`),
  UNIQUE KEY `school_parent_email` (`school_id_number`, `email`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_students` (
  `admission_number` VARCHAR(100) NOT NULL,
  `school_id_number` VARCHAR(255) NOT NULL,
  `parent_id_number` VARCHAR(100) DEFAULT NULL,
  `lastname` VARCHAR(100) NOT NULL,
  `firstname` VARCHAR(100) NOT NULL,
  `othername` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `current_class` VARCHAR(100),
  `session` VARCHAR(100),
  `gender` VARCHAR(50) DEFAULT NULL,
  `dob` VARCHAR(50) DEFAULT NULL,
  `blood_group` VARCHAR(10) DEFAULT NULL,
  `home_address` TEXT DEFAULT NULL,
  `bus_id_number` VARCHAR(100) DEFAULT NULL,
  `bed_id_number` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`school_id_number`, `admission_number`),
  UNIQUE KEY `school_student_email` (`school_id_number`, `email`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`school_id_number`, `parent_id_number`) REFERENCES `sm_parents` (`school_id_number`, `id_number`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_payment_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) UNIQUE, 
  `paystack_public` VARCHAR(255),
  `paystack_secret` VARCHAR(255),
  `flutterwave_public` VARCHAR(255),
  `flutterwave_secret` VARCHAR(255),
  `bank_name` VARCHAR(150),
  `account_number` VARCHAR(50),
  `account_name` VARCHAR(150),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_school_addons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `addon_name` VARCHAR(100) NOT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'inactive',
  `activated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `school_addon` (`school_id_number`, `addon_name`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academics & Curricula
CREATE TABLE IF NOT EXISTS `sm_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_terms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `id_number` VARCHAR(100) NOT NULL,
  `term_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) DEFAULT NULL,
  `next_term_begins` DATE DEFAULT NULL,
  `school_open_days` INT DEFAULT 0,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_class_category` (
  `numeric_class_category_name` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `class_category_name` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `class_name` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `student_capacity` INT NOT NULL DEFAULT 40,
  `session` VARCHAR(100) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `subject_name` VARCHAR(255) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `teacher_id_number` VARCHAR(100) DEFAULT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`teacher_id_number`) REFERENCES `sm_teachers` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evaluation & Testing
CREATE TABLE IF NOT EXISTS `sm_exams` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `term_id_number` VARCHAR(100) NOT NULL,
  `pass_mark` INT NOT NULL DEFAULT 40,
  `total_mark` INT NOT NULL DEFAULT 100,
  `exam_start_date` DATE NOT NULL,
  `exam_end_date` DATE NOT NULL,
  `exam_comment` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_grades` (
  `grade_auto_number` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `grade_name` VARCHAR(50) NOT NULL,
  `mark_from` INT NOT NULL,
  `mark_upto` INT NOT NULL,
  `grade_comment` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `term_id_number` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `first_ca` INT DEFAULT 0,
  `second_ca` INT DEFAULT 0,
  `third_ca` INT DEFAULT 0,
  `exam` INT DEFAULT 0,
  `comment` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_result_remarks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `term_id_number` VARCHAR(100) NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `principal_remark` TEXT NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_result_release_dates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `term_id_number` VARCHAR(100) NOT NULL,
  `release_date` DATE NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CBT System
CREATE TABLE IF NOT EXISTS `sm_cbt_schedules` (
  `cbt_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `paper_title` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `term_id_number` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `cbt_type` VARCHAR(100) NOT NULL DEFAULT 'exam',
  `exam_json` LONGTEXT NOT NULL,
  `exam_date` DATE NOT NULL,
  `exam_time` TIME NOT NULL,
  `exam_questions` INT NOT NULL DEFAULT 0,
  `attempt_questions` INT NOT NULL DEFAULT 0,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 1,
  `exam_duration` INT NOT NULL DEFAULT 60, -- minutes
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_cbt_started` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `cbt_id` INT NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `date_started` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`cbt_id`) REFERENCES `sm_cbt_schedules` (`cbt_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_cbt_submitted` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `cbt_id` INT NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `date_submitted` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `score` FLOAT NOT NULL DEFAULT 0.0,
  `answers` LONGTEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`cbt_id`) REFERENCES `sm_cbt_schedules` (`cbt_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Registry and Logistics
CREATE TABLE IF NOT EXISTS `sm_hostels` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `hostel_name` VARCHAR(255) NOT NULL,
  `hostel_type` VARCHAR(100) NOT NULL,
  `hostel_description` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_rooms` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `hostel_id_number` VARCHAR(100) NOT NULL,
  `category_number` VARCHAR(100) NOT NULL,
  `bed_capacity` INT NOT NULL DEFAULT 4,
  `room_description` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`hostel_id_number`) REFERENCES `sm_hostels` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_room_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `category_number` VARCHAR(100) NOT NULL,
  `room_category` VARCHAR(255) NOT NULL,
  UNIQUE KEY `category_school` (`school_id_number`, `category_number`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_beds` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `room_id_number` VARCHAR(100) NOT NULL,
  `bed_description` TEXT,
  `status` ENUM('available', 'occupied') DEFAULT 'available',
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id_number`) REFERENCES `sm_rooms` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Book Library Registry
CREATE TABLE IF NOT EXISTS `sm_book_categories` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `category_name` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_book_rack_locations` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `rack_name` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_book_lists` (
  `book_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `isbn` VARCHAR(100) NOT NULL,
  `book_category_id` VARCHAR(100) NOT NULL,
  `book_name` VARCHAR(255) NOT NULL,
  `author_name` VARCHAR(255) NOT NULL,
  `rack_location` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` INT NOT NULL DEFAULT 1,
  `description` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`book_category_id`) REFERENCES `sm_book_categories` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_issue_lists` (
  `issue_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `issue_date` DATE NOT NULL,
  `return_date` DATE NOT NULL,
  `book_id` INT NOT NULL,
  `status` ENUM('issued', 'returned', 'overdue') DEFAULT 'issued',
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`book_id`) REFERENCES `sm_book_lists` (`book_id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transportation
CREATE TABLE IF NOT EXISTS `sm_transports` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `route_name` VARCHAR(255) NOT NULL,
  `reg_number` VARCHAR(100) NOT NULL,
  `driver_name` VARCHAR(255) NOT NULL,
  `phone_number` VARCHAR(100) NOT NULL,
  `home_address` TEXT NOT NULL,
  `route_fares` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `description` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timetable Lists
CREATE TABLE IF NOT EXISTS `sm_route_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `day_code` VARCHAR(50) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_exam_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `day_code` VARCHAR(50) NOT NULL,
  `exam_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Homework
CREATE TABLE IF NOT EXISTS `sm_homework_lists` (
  `homework_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `document_title` VARCHAR(255) DEFAULT NULL,
  `document_link` TEXT DEFAULT NULL,
  `content` LONGTEXT,
  `submission_date` DATE NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_submitted_homework_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `homework_id` INT NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `document_link` TEXT NOT NULL,
  `date_submitted` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `feedback_grade` VARCHAR(10) DEFAULT NULL,
  `feedback_comment` TEXT DEFAULT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`homework_id`) REFERENCES `sm_homework_lists` (`homework_id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Communications
CREATE TABLE IF NOT EXISTS `sm_sms_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) DEFAULT NULL,
  `price_per_sms` DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  `payment_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY `school_sms` (`school_id_number`),
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_sms_purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL,
  `credits_purchased` INT NOT NULL,
  `transaction_ref` VARCHAR(100) UNIQUE NOT NULL,
  `status` ENUM('pending', 'approved', 'failed') DEFAULT 'pending',
  `purchase_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_sms_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `sender_id` VARCHAR(50) NOT NULL,
  `recipient` VARCHAR(100) NOT NULL,
  `message` TEXT NOT NULL,
  `credits_used` INT NOT NULL DEFAULT 1,
  `status` ENUM('sent', 'failed') DEFAULT 'sent',
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_email_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `template_name` VARCHAR(100) NOT NULL,
  `template_title` VARCHAR(255) NOT NULL,
  `template_message` LONGTEXT NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_notices` (
  `notice_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `notice_title` VARCHAR(255) NOT NULL,
  `notice_comment` TEXT,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `notice_for` VARCHAR(50) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `user` VARCHAR(100) NOT NULL, -- Target user groups or roles
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance tracking
CREATE TABLE IF NOT EXISTS `sm_student_attendances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `date_taken` DATE NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `attendance_remark` ENUM('present', 'absent', 'late', 'sick') NOT NULL,
  `comment` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_teacher_attendances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `date_taken` DATE NOT NULL,
  `teacher_id_number` VARCHAR(100) NOT NULL,
  `attendance_remark` ENUM('present', 'absent', 'late', 'sick') NOT NULL,
  `comment` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`teacher_id_number`) REFERENCES `sm_teachers` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Holidays
CREATE TABLE IF NOT EXISTS `sm_holidays` (
  `holiday_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `holiday_title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Billing & Invoicing
CREATE TABLE IF NOT EXISTS `sm_fee_types` (
  `id_number` VARCHAR(100) PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `fee_name` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_fee_lists` (
  `fee_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `fee_type_id` VARCHAR(100) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `description` TEXT,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`fee_type_id`) REFERENCES `sm_fee_types` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_fee_payment_lists` (
  `fee_payment_id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `fee_type_id` VARCHAR(100) NOT NULL,
  `online_ref` VARCHAR(150) DEFAULT NULL,
  `manual_ref` VARCHAR(150) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL,
  `starting_year` INT NOT NULL,
  `ending_year` INT NOT NULL,
  `status` ENUM('paid', 'partial', 'unpaid') NOT NULL DEFAULT 'unpaid',
  `description` TEXT,
  `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE,
  FOREIGN KEY (`fee_type_id`) REFERENCES `sm_fee_types` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_online_pre_fee_payment_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `fee_type_id` VARCHAR(100) NOT NULL,
  `online_ref` VARCHAR(150) NOT NULL,
  `admission_number` VARCHAR(100) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `session` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL,
  `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`admission_number`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE,
  FOREIGN KEY (`fee_type_id`) REFERENCES `sm_fee_types` (`id_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LMS Systems
CREATE TABLE IF NOT EXISTS `sm_lms_modules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `order_index` INT DEFAULT 0,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_lms_lessons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `module_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content_type` ENUM('text', 'video', 'pdf', 'document') DEFAULT 'text',
  `content_body` LONGTEXT,
  `attachment_url` TEXT,
  `order_index` INT DEFAULT 0,
  FOREIGN KEY (`module_id`) REFERENCES `sm_lms_modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_lms_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lesson_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `instruction` TEXT,
  `max_points` INT NOT NULL DEFAULT 100,
  `due_date` DATETIME NOT NULL,
  FOREIGN KEY (`lesson_id`) REFERENCES `sm_lms_lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_lms_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `assignment_id` INT NOT NULL,
  `student_id` VARCHAR(100) NOT NULL,
  `file_url` TEXT,
  `text_submission` LONGTEXT,
  `points_awarded` INT DEFAULT NULL,
  `graded_by` VARCHAR(100) DEFAULT NULL,
  `graded_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`assignment_id`) REFERENCES `sm_lms_assignments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live Meetings & Interactive Lessons
CREATE TABLE IF NOT EXISTS `sm_live_classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `class_name` VARCHAR(255) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `meeting_link` VARCHAR(255) NOT NULL,
  `start_time` DATETIME NOT NULL,
  `status` ENUM('scheduled', 'live', 'completed') DEFAULT 'scheduled',
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Educator core
CREATE TABLE IF NOT EXISTS `sm_ai_learning_resources` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `numeric_class_name` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(100) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `resource_text` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_ai_study_buddy_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `student_id` VARCHAR(100) NOT NULL,
  `question` TEXT NOT NULL,
  `answer` TEXT NOT NULL,
  `asked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sm_ai_warnings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id_number` VARCHAR(255) NOT NULL,
  `student_id` VARCHAR(100) NOT NULL,
  `warning_type` ENUM('attendance', 'grades', 'composite') NOT NULL,
  `severity` ENUM('low', 'medium', 'high') NOT NULL,
  `triggers` TEXT NOT NULL,
  `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('active', 'resolved') DEFAULT 'active',
  FOREIGN KEY (`school_id_number`) REFERENCES `sm_schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `sm_students` (`admission_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
