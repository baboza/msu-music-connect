-- ====================================================================
-- สคริปต์ฐานข้อมูลสำหรับระบบการประชุมอิเล็กทรอนิกส์ (E-Meeting Module)
-- วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
-- รองรับ MySQL 5.7+ / MariaDB 10.2+ / phpMyAdmin
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- 1. ตารางการประชุม (meetings)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meetings` (
  `meeting_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT 'ชื่อการประชุม',
  `meeting_no` varchar(50) NOT NULL COMMENT 'ครั้งที่ประชุม เช่น 1/2569',
  `meeting_date` date NOT NULL COMMENT 'วันที่ประชุม',
  `start_time` time NOT NULL COMMENT 'เวลาเริ่ม',
  `end_time` time DEFAULT NULL COMMENT 'เวลาสิ้นสุด',
  `location` varchar(255) DEFAULT NULL COMMENT 'สถานที่ประชุม / ลิงก์ออนไลน์',
  `status` enum('draft','upcoming','in_progress','completed') DEFAULT 'draft' COMMENT 'สถานะการประชุม',
  `minutes_content` longtext DEFAULT NULL COMMENT 'เนื้อหารายงานการประชุมฉบับร่าง/สมบูรณ์',
  `previous_meeting_id` int(11) DEFAULT NULL COMMENT 'อ้างอิงการประชุมครั้งก่อนหน้านี้',
  `created_by` varchar(20) NOT NULL COMMENT 'รหัสผู้สร้าง',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`meeting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 2. ตารางวาระการประชุม (meeting_agendas)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meeting_agendas` (
  `agenda_id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL COMMENT 'รหัสการประชุม',
  `agenda_number` varchar(20) NOT NULL COMMENT 'เลขวาระ เช่น 1, 1.1, 2',
  `agenda_title` varchar(255) NOT NULL COMMENT 'หัวข้อวาระ',
  `agenda_detail` text DEFAULT NULL COMMENT 'รายละเอียด / ข้อเท็จจริง (รองรับ HTML/ตาราง)',
  `proposal` text DEFAULT NULL COMMENT 'ประเด็นเสนอ',
  `resolution` text DEFAULT NULL COMMENT 'มติที่ประชุม',
  `assigned_to` varchar(255) DEFAULT NULL COMMENT 'ผู้รับผิดชอบดำเนินการ',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'ลำดับการเรียง',
  PRIMARY KEY (`agenda_id`),
  KEY `meeting_id` (`meeting_id`),
  CONSTRAINT `meeting_agendas_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`meeting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 3. ตารางเอกสารแนบประจำวาระ (meeting_attachments)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meeting_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `agenda_id` int(11) NOT NULL COMMENT 'รหัสวาระ',
  `file_name` varchar(255) NOT NULL COMMENT 'ชื่อไฟล์เดิม',
  `file_path` varchar(255) NOT NULL COMMENT 'พาธไฟล์ที่จัดเก็บบนเซิร์ฟเวอร์',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `agenda_id` (`agenda_id`),
  CONSTRAINT `meeting_attachments_ibfk_1` FOREIGN KEY (`agenda_id`) REFERENCES `meeting_agendas` (`agenda_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. ตารางรายชื่อผู้เข้าร่วมประชุม (meeting_attendees)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meeting_attendees` (
  `attendee_id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL COMMENT 'รหัสการประชุม',
  `user_id` varchar(20) NOT NULL COMMENT 'รหัสผู้ใช้งาน',
  `attendance_status` enum('pending','attending','absent') DEFAULT 'pending' COMMENT 'สถานะตอบรับ',
  `attend_mode` enum('onsite','online') DEFAULT NULL COMMENT 'รูปแบบเข้าร่วม',
  `absence_reason` text DEFAULT NULL COMMENT 'เหตุผลกรณีลาประชุม',
  PRIMARY KEY (`attendee_id`),
  KEY `meeting_id` (`meeting_id`),
  CONSTRAINT `meeting_attendees_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`meeting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 5. ตารางกล่องรับเอกสารวาระล่วงหน้า (meeting_submissions) *** จำเป็นมาก ***
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meeting_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL COMMENT 'รหัสการประชุมที่ส่งเข้า',
  `user_id` varchar(20) NOT NULL COMMENT 'รหัสผู้ส่งเอกสาร',
  `original_file_name` varchar(255) NOT NULL COMMENT 'ชื่อไฟล์ต้นฉบับ',
  `file_path` varchar(255) NOT NULL COMMENT 'พาธไฟล์ที่จัดเก็บบนเซิร์ฟเวอร์',
  `note` text DEFAULT NULL COMMENT 'บันทึก/หมายเหตุจากผู้ส่ง',
  `status` enum('pending','assigned','rejected') DEFAULT 'pending' COMMENT 'สถานะเอกสาร',
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`submission_id`),
  KEY `meeting_id` (`meeting_id`),
  CONSTRAINT `meeting_submissions_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`meeting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
