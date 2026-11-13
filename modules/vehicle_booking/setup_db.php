<?php
require_once __DIR__ . '/../../config/db.php';

try {
    // 1. ตารางข้อมูลยานพาหนะ (Vehicles)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicles` (
        `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
        `plate_number` varchar(50) NOT NULL COMMENT 'เลขทะเบียนรถ',
        `brand` varchar(50) NOT NULL DEFAULT 'Toyota',
        `model` varchar(150) NOT NULL COMMENT 'ยี่ห้อและรุ่น',
        `year_made` int(11) NOT NULL DEFAULT 2026,
        `vehicle_type` enum('passenger','cargo_instruments','other') NOT NULL DEFAULT 'passenger' COMMENT 'ประเภทรถ',
        `seating_capacity` int(11) NOT NULL DEFAULT 13 COMMENT 'จำนวนที่นั่ง',
        `fuel_type` varchar(50) NOT NULL DEFAULT 'ดีเซล' COMMENT 'ประเภทน้ำมัน',
        `current_odometer` int(11) NOT NULL DEFAULT 0 COMMENT 'เลขไมล์สะสมล่าสุด',
        `status` enum('available','in_use','maintenance','inactive') NOT NULL DEFAULT 'available',
        `image_url` varchar(255) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`vehicle_id`),
        UNIQUE KEY `idx_plate` (`plate_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed รถวิทยาลัย 2 คัน (ถ้ายังไม่มี)
    $chk = $pdo->query("SELECT COUNT(*) FROM `vehicles`")->fetchColumn();
    if ($chk == 0) {
        $pdo->exec("
        INSERT INTO `vehicles` (`plate_number`, `brand`, `model`, `year_made`, `vehicle_type`, `seating_capacity`, `fuel_type`, `current_odometer`, `status`, `notes`) VALUES
        ('นข-2026 มหาสารคาม', 'Toyota', 'Toyota Commuter VIP 2026', 2026, 'passenger', 13, 'ดีเซล B7', 15200, 'available', 'รถตู้ปรับอากาศ VIP ประจำวิทยาลัยดุริยางคศิลป์ สำหรับรับส่งผู้บริหาร อาจารย์ นิสิต และภารกิจทั่วไป'),
        ('บพ-2569 มหาสารคาม', 'Toyota', 'Toyota Hilux Revo ตู้บรรทุกเครื่องดนตรี', 2025, 'cargo_instruments', 3, 'ดีเซล', 28450, 'available', 'รถยนต์บรรทุกตู้ทึบดัดแปลงพิเศษ ติดตั้งแร็คและสายรัด สำหรับขนย้ายเครื่องดนตรีวงดุริยางค์ เครื่องดนตรีไทย-สากล และอุปกรณ์การแสดง')
        ");
    }

    // 2. ตารางคำขอใช้รถ (Vehicle Bookings)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicle_bookings` (
        `booking_id` int(11) NOT NULL AUTO_INCREMENT,
        `booking_code` varchar(50) NOT NULL UNIQUE COMMENT 'รหัสคำขอ เช่น VB-2026-0001',
        `user_id` varchar(50) NOT NULL COMMENT 'ผู้ขอใช้รถ',
        `vehicle_id` int(11) NOT NULL COMMENT 'รหัสรถที่ขอใช้',
        `title` varchar(255) NOT NULL COMMENT 'วัตถุประสงค์ / โครงการ / ภารกิจ',
        `destination` varchar(255) NOT NULL COMMENT 'สถานที่ปลายทาง',
        `route_detail` text DEFAULT NULL COMMENT 'เส้นทางการเดินทาง',
        `start_datetime` datetime NOT NULL COMMENT 'วันเวลาเริ่มใช้รถ',
        `end_datetime` datetime NOT NULL COMMENT 'วันเวลาสิ้นสุด',
        `passenger_count` int(11) NOT NULL DEFAULT 1 COMMENT 'จำนวนผู้โดยสาร',
        `passenger_names` text DEFAULT NULL COMMENT 'รายชื่อผู้ร่วมเดินทาง',
        `instruments_detail` text DEFAULT NULL COMMENT 'รายการเครื่องดนตรีหรือสัมภาระที่บรรทุก',
        `driver_type` enum('driver_assigned','self_drive','other') NOT NULL DEFAULT 'driver_assigned',
        `driver_id` varchar(50) DEFAULT NULL COMMENT 'พนักงานขับรถ',
        `driver_name` varchar(150) DEFAULT NULL,
        `status` enum('pending_head','pending_deputy','approved','rejected','completed','cancelled') NOT NULL DEFAULT 'pending_head',
        `head_comment` text DEFAULT NULL,
        `head_approved_at` datetime DEFAULT NULL,
        `head_approved_by` varchar(50) DEFAULT NULL,
        `deputy_comment` text DEFAULT NULL,
        `deputy_approved_at` datetime DEFAULT NULL,
        `deputy_approved_by` varchar(50) DEFAULT NULL,
        `reject_reason` text DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`booking_id`),
        KEY `idx_booking_dates` (`vehicle_id`, `start_datetime`, `end_datetime`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. ตารางบันทึกการเดินทาง ค่าใช้จ่าย และอัตราสิ้นเปลือง (Trip Logs & Expenses)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicle_trip_logs` (
        `log_id` int(11) NOT NULL AUTO_INCREMENT,
        `booking_id` int(11) NOT NULL,
        `vehicle_id` int(11) NOT NULL,
        `driver_id` varchar(50) DEFAULT NULL,
        `driver_name` varchar(150) DEFAULT NULL,
        `start_odometer` int(11) NOT NULL DEFAULT 0 COMMENT 'เลขไมล์ก่อนออกเดินทาง',
        `end_odometer` int(11) NOT NULL DEFAULT 0 COMMENT 'เลขไมล์เมื่อกลับถึง',
        `total_distance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ระยะทางรวม (กิโลเมตร)',
        `fuel_liters` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ปริมาณน้ำมันที่เติม (ลิตร)',
        `fuel_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าน้ำมันเชื้อเพลิง (บาท)',
        `toll_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าผ่านทาง / ทางด่วน (บาท)',
        `parking_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าที่จอดรถ (บาท)',
        `driver_allowance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าเบี้ยเลี้ยง/ที่พักคนขับ (บาท)',
        `misc_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าใช้จ่ายอื่นๆ (บาท)',
        `total_expenses` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าใช้จ่ายรวมทั้งสิ้น (บาท)',
        `fuel_consumption_rate` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'อัตราสิ้นเปลือง (กิโลเมตร/ลิตร)',
        `cost_per_km` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าใช้จ่ายต่อกิโลเมตร (บาท/กิโลเมตร)',
        `trip_notes` text DEFAULT NULL,
        `receipt_file` varchar(255) DEFAULT NULL,
        `recorded_by` varchar(50) DEFAULT NULL,
        `recorded_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `idx_booking_trip` (`booking_id`, `vehicle_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. ตารางตรวจเช็คสภาพรถ (Vehicle Inspections Checklist)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicle_inspections` (
        `inspection_id` int(11) NOT NULL AUTO_INCREMENT,
        `booking_id` int(11) DEFAULT NULL,
        `vehicle_id` int(11) NOT NULL,
        `inspection_type` enum('pre_trip','post_trip','routine') NOT NULL DEFAULT 'pre_trip',
        `inspector_id` varchar(50) DEFAULT NULL,
        `inspector_name` varchar(150) DEFAULT NULL,
        `inspection_date` datetime NOT NULL,
        `odometer_reading` int(11) NOT NULL DEFAULT 0,
        `engine_oil_check` enum('good','low','needs_change') NOT NULL DEFAULT 'good' COMMENT 'ระดับน้ำมันเครื่อง',
        `coolant_check` enum('good','low','abnormal') NOT NULL DEFAULT 'good' COMMENT 'น้ำยาหล่อเย็นหม้อน้ำ',
        `tires_check` enum('good','low_pressure','worn','defect') NOT NULL DEFAULT 'good' COMMENT 'ลมยางและสภาพยาง',
        `brakes_check` enum('good','worn','noise','defect') NOT NULL DEFAULT 'good' COMMENT 'ระบบเบรก',
        `lights_check` enum('good','bulb_out','defect') NOT NULL DEFAULT 'good' COMMENT 'ระบบไฟส่องสว่าง/สัญญาณไฟ',
        `battery_check` enum('good','weak','defect') NOT NULL DEFAULT 'good' COMMENT 'แบตเตอรี่',
        `cleanliness_check` enum('clean','normal','dirty') NOT NULL DEFAULT 'clean' COMMENT 'ความสะอาดห้องโดยสาร',
        `cargo_safety_check` enum('good','strap_damage','not_applicable') NOT NULL DEFAULT 'good' COMMENT 'สภาพตู้บรรทุกเครื่องดนตรีและสายรัด',
        `overall_status` enum('pass','pass_with_conditions','fail_grounded') NOT NULL DEFAULT 'pass' COMMENT 'ผลการตรวจสภาพโดยรวม',
        `defect_notes` text DEFAULT NULL COMMENT 'อาการผิดปกติที่ตรวจพบ',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`inspection_id`),
        KEY `idx_insp_veh` (`vehicle_id`, `inspection_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

} catch (PDOException $e) {
    error_log("Vehicle Booking Setup Error: " . $e->getMessage());
}
