-- ====================================================================
-- สคริปต์ฐานข้อมูลสำหรับโมดูลใหม่: PR (Digital Signage & Interactive Kiosk)
-- วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
-- รองรับ MySQL 5.7+ / MariaDB 10.2+ / phpMyAdmin
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- 1. ตารางป้ายประชาสัมพันธ์ (จอที่ 1: เล่นวนอัตโนมัติ และจอที่ 2)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pr_signage_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL COMMENT 'ผู้ส่งคำขอ',
  `title` varchar(255) NOT NULL COMMENT 'ชื่อเรื่อง/หัวข้อประชาสัมพันธ์',
  `media_type` enum('image','canva','youtube') NOT NULL DEFAULT 'image',
  `media_url` text NOT NULL COMMENT 'พาธไฟล์รูปภาพ หรือลิงก์ Canva / YouTube',
  `duration_seconds` int(11) NOT NULL DEFAULT 10 COMMENT 'เวลาเล่นวน (วินาที)',
  `start_date` date NOT NULL COMMENT 'วันที่เริ่มแสดงผล',
  `end_date` date NOT NULL COMMENT 'วันสิ้นสุดการแสดงผล',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'ลำดับการแสดงผล',
  `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `target_screen` enum('screen1_only','both') NOT NULL DEFAULT 'screen1_only',
  `approved_by` varchar(255) DEFAULT NULL COMMENT 'รหัสผู้พิจารณาอนุมัติ',
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL COMMENT 'เหตุผลกรณีปฏิเสธ/ยกเลิก',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_status_date` (`status`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 2. ตารางเนื้อหาจอ Interactive Kiosk (จอที่ 2: 8 หมวดหมู่ 3 ภาษา)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pr_kiosk_contents` (
  `content_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_key` enum('news','concerts','about','programs','staff','rooms','services','achievements') NOT NULL,
  `title_th` varchar(255) NOT NULL,
  `title_en` varchar(255) DEFAULT NULL,
  `title_cn` varchar(255) DEFAULT NULL,
  `detail_th` text DEFAULT NULL,
  `detail_en` text DEFAULT NULL,
  `detail_cn` text DEFAULT NULL,
  `thumbnail_image` varchar(255) DEFAULT NULL,
  `media_url` text DEFAULT NULL COMMENT 'รูปภาพเพิ่มเติม/วิดีโอ',
  `qr_link` text DEFAULT NULL COMMENT 'ลิงก์สำหรับสร้าง QR Code ให้สแกนต่อบนมือถือ',
  `event_date` date DEFAULT NULL COMMENT 'สำหรับงานคอนเสิร์ต/กิจกรรม',
  `location` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `view_count` int(11) NOT NULL DEFAULT 0 COMMENT 'สถิติจำนวนครั้งที่มีการแตะดู',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`content_id`),
  KEY `idx_cat_active` (`category_key`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 3. ตารางตั้งค่าระบบจอแสดงผล (ข้อความตัววิ่ง, เวลาตัดกลับหน้าแรก)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pr_display_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed ข้อมูลเริ่มต้น
INSERT INTO `pr_display_settings` (`setting_key`, `setting_value`, `description`) VALUES
('ticker_text_th', 'ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม | College of Music, Mahasarakham University', 'ข้อความตัววิ่งภาษาไทย'),
('ticker_text_en', 'Welcome to College of Music, Mahasarakham University | Excellence in Music Education & Innovation', 'ข้อความตัววิ่งภาษาอังกฤษ'),
('ticker_text_cn', '欢迎来到玛哈沙拉坎大学音乐学院 | 卓越的音乐教育与表演', 'ข้อความตัววิ่งภาษาจีน'),
('kiosk_idle_timeout', '60', 'วินาทีที่ไม่มีการสัมผัสจอ ก่อนตัดกลับหน้าหลัก'),
('default_slide_duration', '10', 'เวลาแสดงภาพนิ่งเริ่มต้น (วินาที)'),
('screen1_theme', 'dark_gold', 'ธีมสีของจอที่ 1'),
('screen2_theme', 'royal_music', 'ธีมสีของจอที่ 2')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
