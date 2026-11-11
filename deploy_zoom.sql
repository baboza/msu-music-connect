-- 1. สร้างตารางเก็บรายชื่อบัญชี Zoom
CREATE TABLE IF NOT EXISTS `zoom_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `meeting_id` varchar(50) DEFAULT NULL,
  `passcode` varchar(50) DEFAULT NULL,
  `meeting_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. สร้างตารางเก็บประวัติการจอง Zoom
CREATE TABLE IF NOT EXISTS `zoom_bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zoom_account_id` int(11) NOT NULL,
  `user_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. เพิ่มข้อมูลทดสอบตั้งต้นสำหรับ Zoom Accounts
INSERT INTO `zoom_accounts` (`account_name`, `email`, `meeting_id`, `passcode`, `meeting_url`) VALUES
('Zoom 1 (ส่วนกลาง)', 'zoom1@example.com', '123 456 7890', '123456', 'https://zoom.us/j/1234567890'),
('Zoom 2 (ห้องประชุม)', 'zoom2@example.com', '098 765 4321', '654321', 'https://zoom.us/j/0987654321');
