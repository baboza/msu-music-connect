-- ====================================================================
-- สคริปต์อัปเดตตาราง attendance_logs สำหรับโฮสต์จริง (Production)
-- 1. สร้างตารางหากยังไม่มี
-- 2. แก้ชนิดฟิลด์ user_id จาก INT(11) -> VARCHAR(50) (แก้ Error 1265 Data truncated)
-- 3. ตรวจสอบและเพิ่มฟิลด์ที่อาจตกหล่นบนโฮสต์จริง (photo_path, ip_address, created_at, remark)
-- ====================================================================

CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(50) NOT NULL,
  `scan_type` enum('in','out') NOT NULL,
  `scan_time` datetime NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remark` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `scan_time` (`scan_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- แก้ชนิดคอลัมน์ user_id จาก int(11) เป็น VARCHAR(50) ให้เก็บรหัสแบบ '2d67fd96' ได้
ALTER TABLE `attendance_logs`
  MODIFY COLUMN `user_id` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

-- เพิ่มฟิลด์ photo_path หากบนโฮสต์จริงยังไม่มี
SET @dbname = DATABASE();
SET @prepared = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_logs' AND COLUMN_NAME = 'photo_path') > 0,
  'SELECT 1',
  'ALTER TABLE `attendance_logs` ADD COLUMN `photo_path` VARCHAR(255) DEFAULT NULL AFTER `scan_time`'
));
PREPARE stmt FROM @prepared; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- เพิ่มฟิลด์ ip_address หากบนโฮสต์จริงยังไม่มี
SET @prepared = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_logs' AND COLUMN_NAME = 'ip_address') > 0,
  'SELECT 1',
  'ALTER TABLE `attendance_logs` ADD COLUMN `ip_address` VARCHAR(50) DEFAULT NULL AFTER `photo_path`'
));
PREPARE stmt FROM @prepared; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- เพิ่มฟิลด์ remark (หมายเหตุเข้าสาย) หากบนโฮสต์จริงยังไม่มี
SET @prepared = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_logs' AND COLUMN_NAME = 'remark') > 0,
  'SELECT 1',
  'ALTER TABLE `attendance_logs` ADD COLUMN `remark` TEXT DEFAULT NULL'
));
PREPARE stmt FROM @prepared; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- กู้คืนรหัส user_id เดิมที่เคยถูกตัดเหลือตัวเลขเดียว (ถ้ามีข้อมูลเก่าค้างอยู่)
UPDATE `attendance_logs`
SET `user_id` = SUBSTRING_INDEX(SUBSTRING_INDEX(`photo_path`, '_', 2), '_', -1)
WHERE `photo_path` LIKE 'scan\_%\_%'
  AND EXISTS (
      SELECT 1 FROM `users` u
      WHERE u.`user_id` = SUBSTRING_INDEX(SUBSTRING_INDEX(`attendance_logs`.`photo_path`, '_', 2), '_', -1)
  );
