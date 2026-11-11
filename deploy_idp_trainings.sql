-- ====================================================================
-- สคริปต์อัปเดตฐานข้อมูลสำหรับโมดูล ID Plan และ Trainings (Production Deploy)
-- วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
-- รองรับ MySQL 5.7+ / MariaDB 10.2+ / phpMyAdmin
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- 1. ตรวจสอบ/สร้างตาราง fiscal_years และกำหนดปีงบประมาณ 2570 เป็นปัจจุบัน
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `year_name` varchar(4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`year_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `fiscal_years` (`year_name`, `is_active`) VALUES
('2569', 0),
('2570', 1)
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

-- --------------------------------------------------------------------
-- 2. สร้างตารางระบบ ID Plan (3 ตารางหลัก)
-- --------------------------------------------------------------------

-- 2.1 ตาราง id_plans (แผนพัฒนาตนเองรายบุคคล)
CREATE TABLE IF NOT EXISTS `id_plans` (
  `id_plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `plan_year` varchar(4) NOT NULL,
  `term_type` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `competency_type` varchar(100) NOT NULL,
  `current_level` int(11) DEFAULT 1,
  `target_level` int(11) DEFAULT 1,
  `competency_gap` int(11) DEFAULT 0,
  `target_kpi` text DEFAULT NULL,
  `dev_method_type` varchar(50) DEFAULT 'training',
  `dev_method_detail` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `expected_outcome` text DEFAULT NULL,
  `status` enum('draft','submitted','endorsed','approved','returned','rejected','completed') DEFAULT 'draft',
  `current_step` int(11) DEFAULT 1,
  `submitted_at` datetime DEFAULT NULL,
  `supervisor_id` varchar(50) DEFAULT NULL,
  `supervisor_comment` text DEFAULT NULL,
  `supervisor_action_at` datetime DEFAULT NULL,
  `executive_id` varchar(50) DEFAULT NULL,
  `executive_comment` text DEFAULT NULL,
  `executive_action_at` datetime DEFAULT NULL,
  `hr_id` varchar(50) DEFAULT NULL,
  `hr_comment` text DEFAULT NULL,
  `hr_action_at` datetime DEFAULT NULL,
  `eval_actual_level` int(11) DEFAULT NULL,
  `eval_kpi_result` enum('exceeded','achieved','partially_achieved','not_achieved') DEFAULT NULL,
  `eval_kpi_detail` text DEFAULT NULL,
  `eval_gap_closed` int(11) DEFAULT NULL,
  `eval_gap_remaining` int(11) DEFAULT NULL,
  `eval_closure_status` enum('pending','fully_closed','partially_closed','not_closed') DEFAULT 'pending',
  `eval_continuous_plan` text DEFAULT NULL,
  `evaluated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_plan_id`),
  KEY `idx_user_year` (`user_id`, `plan_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 ตาราง id_plan_activities (กิจกรรมและประวัติชั่วโมงอบรมที่ซิงค์จาก Trainings)
CREATE TABLE IF NOT EXISTS `id_plan_activities` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `id_plan_id` int(11) NOT NULL,
  `training_report_id` int(11) DEFAULT NULL,
  `method_type` enum('self_development','training','knowledge_transfer') NOT NULL DEFAULT 'training',
  `activity_title` varchar(255) NOT NULL,
  `activity_date` date DEFAULT NULL,
  `location_or_platform` varchar(255) DEFAULT NULL,
  `hours` int(11) DEFAULT 0,
  `attachment_file` varchar(255) DEFAULT NULL,
  `attachment_url` varchar(500) DEFAULT NULL,
  `summary_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `idx_plan` (`id_plan_id`),
  KEY `idx_tr_report` (`training_report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 ตาราง id_plan_approvals (ประวัติการลงนามอนุมัติ ID Plan)
CREATE TABLE IF NOT EXISTS `id_plan_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `id_plan_id` int(11) NOT NULL,
  `step_level` int(11) NOT NULL COMMENT '1: Supervisor, 2: Executive, 3: Dean',
  `approver_id` varchar(50) NOT NULL,
  `action` enum('endorsed','approved','returned','rejected','commented') NOT NULL,
  `comment` text DEFAULT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`approval_id`),
  KEY `idx_plan` (`id_plan_id`),
  KEY `idx_approver` (`approver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 3. ตรวจสอบ/สร้างตาราง training_reports
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `training_reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `organizer` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `location` varchar(255) NOT NULL,
  `fiscal_year` varchar(4) NOT NULL,
  PRIMARY KEY (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. อัปเดตโครงสร้างคอลัมน์ใน training_reports (กรณีตารางมีอยู่เดิม)
-- --------------------------------------------------------------------

-- ปรับ ENUM ของ status ให้รองรับ 'returned' (ส่งกลับแก้ไข)
ALTER TABLE `training_reports` 
  MODIFY COLUMN `status` enum('pending','approved','reported','rejected','returned') DEFAULT 'pending';

-- เพิ่มฟิลด์เชื่อมโยง ID Plan
ALTER TABLE `training_reports` 
  ADD COLUMN IF NOT EXISTS `id_plan_id` int(11) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `position_name` varchar(255) DEFAULT NULL AFTER `id_plan_id`,
  ADD COLUMN IF NOT EXISTS `travel_start_date` date DEFAULT NULL AFTER `end_date`,
  ADD COLUMN IF NOT EXISTS `travel_end_date` date DEFAULT NULL AFTER `travel_start_date`,
  ADD COLUMN IF NOT EXISTS `training_hours` int(11) DEFAULT NULL AFTER `travel_end_date`,
  ADD COLUMN IF NOT EXISTS `report_type` varchar(100) DEFAULT 'ไปราชการ' AFTER `location`,
  ADD COLUMN IF NOT EXISTS `budget_used` decimal(10,2) DEFAULT 0.00 AFTER `fiscal_year`,
  ADD COLUMN IF NOT EXISTS `summary` text DEFAULT NULL AFTER `budget_used`,
  ADD COLUMN IF NOT EXISTS `certificate_file` varchar(255) DEFAULT NULL AFTER `summary`,
  ADD COLUMN IF NOT EXISTS `invitation_file` varchar(255) DEFAULT NULL AFTER `certificate_file`,
  ADD COLUMN IF NOT EXISTS `highway_map_file` varchar(255) DEFAULT NULL AFTER `invitation_file`,
  ADD COLUMN IF NOT EXISTS `evaluation` varchar(50) DEFAULT NULL AFTER `highway_map_file`,
  ADD COLUMN IF NOT EXISTS `apply_how` text DEFAULT NULL AFTER `evaluation`,
  ADD COLUMN IF NOT EXISTS `apply_expected_result` text DEFAULT NULL AFTER `apply_how`,
  ADD COLUMN IF NOT EXISTS `apply_completion_date` date DEFAULT NULL AFTER `apply_expected_result`,
  ADD COLUMN IF NOT EXISTS `current_step` int(11) DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `current_step`;

-- เพิ่มฟิลด์งบประมาณ 5 หมวด (ประมาณการ + จ่ายจริง)
ALTER TABLE `training_reports` 
  ADD COLUMN IF NOT EXISTS `est_budget_travel` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `est_budget_accom` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `est_budget_allowance` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `est_budget_regis` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `est_budget_other` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `act_budget_travel` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `act_budget_accom` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `act_budget_allowance` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `act_budget_regis` decimal(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `act_budget_other` decimal(10,2) DEFAULT 0.00;

-- เพิ่มฟิลด์ลำดับขั้นการพิจารณา 5 ขั้นตอน (Approval Chain)
ALTER TABLE `training_reports` 
  ADD COLUMN IF NOT EXISTS `step1_status` enum('pending','approved','rejected','returned') DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `step1_by` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step1_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step2_status` enum('pending','approved','rejected','returned') DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `step2_by` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step2_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step3_status` enum('pending','approved','rejected','returned') DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `step3_by` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step3_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step4_status` enum('pending','approved','rejected','returned') DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `step4_by` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step4_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step5_status` enum('pending','approved','rejected','returned') DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `step5_by` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step5_date` datetime DEFAULT NULL;

-- เพิ่มหมายเหตุการเงิน และข้อมูลส่งกลับแก้ไข
ALTER TABLE `training_reports` 
  ADD COLUMN IF NOT EXISTS `finance_comment` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `return_comment` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `return_step` int(11) DEFAULT NULL;

-- --------------------------------------------------------------------
-- 5. ตรวจสอบบทบาท (Roles) ในตาราง roles ให้มีครบทุกสิทธิ์การพิจารณา
-- --------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`role_name`) VALUES 
('admin'),
('งานการเงิน'),
('หัวหน้าสำนักงาน'),
('หัวหน้าภาควิชาดนตรีศึกษา'),
('หัวหน้าภาควิชาดุริยางคศาสตร์'),
('รองคณบดีฝ่ายบริหาร'),
('คณบดี'),
('รักษาการคณบดี');

SET FOREIGN_KEY_CHECKS = 1;
