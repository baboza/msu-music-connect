-- ====================================================================
-- สคริปต์ฐานข้อมูลระบบงานพัสดุ (Supplies Module) สำหรับเซิร์ฟเวอร์จริง
-- วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
-- เพิ่มฟิลด์: วันที่นำเข้า (received_date) และ วันที่สร้างข้อมูล (created_at)
-- ====================================================================

-- 1. เพิ่มคอลัมน์ received_date และ created_at ในตาราง supplies_items (หากยังไม่มี)
SET @dbname = DATABASE();
SET @tablename = "supplies_items";
SET @columnname = "received_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE supplies_items ADD COLUMN received_date DATE NULL COMMENT 'วันที่นำเข้า/รับเข้าคลัง' AFTER stock_qty;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. เพิ่ม created_at
SET @columnname = "created_at";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE supplies_items ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่บันทึกเข้าระบบ' AFTER is_active;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 3. กำหนดค่าเริ่มต้นให้กับข้อมูลวัสดุเดิมที่ยังไม่มีวันที่นำเข้า
UPDATE supplies_items SET received_date = '2026-03-01' WHERE received_date IS NULL;
