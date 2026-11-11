-- =================================================================================
-- MSU Music Connect (Enterprise ERP 2.0) - Production SQL Migration & Schema Patch
-- ใช้สำหรับอัปเดตฐานข้อมูลบน Production Host ให้ครบถ้วนตรงกับเวอร์ชันล่าสุด (Idempotent)
-- =================================================================================

SET NAMES utf8mb4;

-- 1. ตารางแผนพัฒนาบุคลากรรายบุคคล (training_idp) สำหรับระบบสัมมนา/อบรม
CREATE TABLE IF NOT EXISTS `training_idp` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `fiscal_year` VARCHAR(10) NOT NULL,
    `competency_type` VARCHAR(100) NOT NULL,
    `topic_interest` VARCHAR(255) NOT NULL,
    `expected_quarter` VARCHAR(20) NOT NULL,
    `estimated_budget` DECIMAL(10,2) DEFAULT 0.00,
    `rationale` TEXT,
    `status` VARCHAR(50) DEFAULT 'planned',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ตารางระบบสร้าง QR Code อัจฉริยะและสถิติการสแกน (qrcodes & qrcode_scans)
CREATE TABLE IF NOT EXISTS `qrcodes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `short_code` VARCHAR(32) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) DEFAULT 'ทั่วไป/อื่นๆ',
    `target_url` TEXT NOT NULL,
    `scan_count` INT DEFAULT 0,
    `fg_color` VARCHAR(20) DEFAULT '#35005e',
    `bg_color` VARCHAR(20) DEFAULT '#ffffff',
    `dot_style` VARCHAR(30) DEFAULT 'rounded',
    `corner_style` VARCHAR(30) DEFAULT 'extra-rounded',
    `use_logo` TINYINT(1) DEFAULT 1,
    `logo_path` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_short_code` (`short_code`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qrcode_scans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `qrcode_id` INT NOT NULL,
    `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `referer` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_qrcode_id` (`qrcode_id`),
    INDEX `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ตารางระบบจอประชาสัมพันธ์ Digital Signage (pr_signage_items & pr_signage_settings)
CREATE TABLE IF NOT EXISTS `pr_signage_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `media_type` ENUM('image','video','youtube') DEFAULT 'image',
    `file_path` VARCHAR(500) NULL,
    `youtube_url` VARCHAR(500) NULL,
    `target_screen` ENUM('screen1','screen2','both') DEFAULT 'both',
    `display_duration` INT DEFAULT 10,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `approved_by` VARCHAR(50) NULL,
    `approved_at` DATETIME NULL,
    `reject_reason` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pr_signage_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ตารางคำร้องขอสอบวิทยานิพนธ์ระดับบัณฑิตศึกษา (grad_thesis_exams)
CREATE TABLE IF NOT EXISTS `grad_thesis_exams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` VARCHAR(20) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `degree_level` VARCHAR(100) NOT NULL,
    `major` VARCHAR(255) NOT NULL,
    `study_plan` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `exam_type` VARCHAR(50) NOT NULL,
    `thesis_title_th` TEXT NOT NULL,
    `thesis_title_en` TEXT NOT NULL,
    `advisor_id` INT NULL,
    `co_advisor_id` INT NULL,
    `committee_chair` VARCHAR(255) NOT NULL,
    `committee_1` VARCHAR(255) NOT NULL,
    `committee_2` VARCHAR(255) DEFAULT NULL,
    `committee_3` VARCHAR(255) DEFAULT NULL,
    `committee_4` VARCHAR(255) DEFAULT NULL,
    `exam_date` DATE NOT NULL,
    `exam_time` VARCHAR(50) NOT NULL,
    `exam_room` VARCHAR(255) NOT NULL,
    `file_manuscript` VARCHAR(255) DEFAULT NULL,
    `file_turnitin` VARCHAR(255) DEFAULT NULL,
    `file_publication` VARCHAR(255) DEFAULT NULL,
    `file_transcript` VARCHAR(255) DEFAULT NULL,
    `file_other` VARCHAR(255) DEFAULT NULL,
    `grad_staff_status` VARCHAR(50) DEFAULT 'pending',
    `grad_staff_comment` TEXT DEFAULT NULL,
    `grad_staff_date` DATETIME DEFAULT NULL,
    `grad_chair_status` VARCHAR(50) DEFAULT 'pending',
    `grad_chair_comment` TEXT DEFAULT NULL,
    `grad_chair_date` DATETIME DEFAULT NULL,
    `advisor_status` VARCHAR(50) DEFAULT 'pending',
    `deputy_dean_status` VARCHAR(50) DEFAULT 'pending',
    `deputy_dean_comment` TEXT DEFAULT NULL,
    `deputy_dean_date` DATETIME DEFAULT NULL,
    `dean_status` VARCHAR(50) DEFAULT 'pending',
    `dean_comment` TEXT DEFAULT NULL,
    `dean_date` DATETIME DEFAULT NULL,
    `overall_status` VARCHAR(50) DEFAULT 'pending_staff',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ตรวจสอบและเพิ่มคอลัมน์ใหม่ในตารางที่มีอยู่เดิมอย่างปลอดภัย (Idempotent Columns)
SET @dbname = DATABASE();

-- 6.1 training_reports.idp_id (เชื่อมรายงานอบรมกับแผน IDP)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'training_reports' AND COLUMN_NAME = 'idp_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `training_reports` ADD COLUMN `idp_id` INT DEFAULT NULL AFTER `user_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6.2 grad_thesis_exams.advisor_id, co_advisor_id, advisor_status
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'grad_thesis_exams' AND COLUMN_NAME = 'advisor_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `grad_thesis_exams` ADD COLUMN `advisor_id` INT NULL AFTER `thesis_title_en`, ADD COLUMN `co_advisor_id` INT NULL AFTER `advisor_id`, ADD COLUMN `advisor_status` VARCHAR(50) DEFAULT ''pending'' AFTER `grad_chair_status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6.3 users.employment_type (สายวิชาการ / สายสนับสนุน)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employment_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `employment_type` VARCHAR(100) NULL DEFAULT ''สายสนับสนุน''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- 5. ตารางประวัติการอนุมัติใบลาอิเล็กทรอนิกส์ (leave_approvals)
CREATE TABLE IF NOT EXISTS `leave_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_id` int(11) NOT NULL,
  `approver_id` varchar(50) DEFAULT NULL,
  `step_order` int(11) NOT NULL COMMENT 'ลำดับการอนุมัติ 1, 2, 3, 4',
  `approve_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `comment` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`approval_id`),
  KEY `leave_id` (`leave_id`),
  KEY `approver_id` (`approver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
