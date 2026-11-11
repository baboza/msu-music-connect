-- ====================================================================
-- อัปเดตฐานข้อมูลสำหรับระบบ Smart Practice Room Booking (e-Service)
-- รองรับ:
-- 1. Check-in ภายใน 15 นาที
-- 2. Auto-Cancel No-show (สถานะ no_show)
-- 3. Check-out เลิกซ้อมก่อนเวลา (บันทึกเวลา check_in_at, check_out_at)
-- 4. คืนห้องให้ผู้อื่นทันที (Real-time Release)
-- 5. กฎ No-show 3 ครั้งใน 30 วัน แบน 7 วัน
-- 6. ฟังก์ชันยกเลิกการจองห้องซ้อมพร้อมระบุเหตุผล (cancel_reason)
-- ====================================================================

ALTER TABLE `practice_bookings` 
  MODIFY COLUMN `status` ENUM('approved','active','completed','cancelled','no_show') DEFAULT 'approved';

SET @dbname = DATABASE();
SET @tablename = 'practice_bookings';

SET @precheck1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'check_in_at');
SET @sql1 = IF(@precheck1 = 0, 'ALTER TABLE practice_bookings ADD COLUMN check_in_at DATETIME NULL AFTER status;', 'SELECT 1;');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @precheck2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'check_out_at');
SET @sql2 = IF(@precheck2 = 0, 'ALTER TABLE practice_bookings ADD COLUMN check_out_at DATETIME NULL AFTER check_in_at;', 'SELECT 1;');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @precheck3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'cancel_reason');
SET @sql3 = IF(@precheck3 = 0, 'ALTER TABLE practice_bookings ADD COLUMN cancel_reason TEXT NULL AFTER check_out_at;', 'SELECT 1;');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
