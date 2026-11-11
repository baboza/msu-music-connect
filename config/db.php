<?php
/**
 * Music E-office Database Configuration
 * College of Music, Mahasarakham University
 */

// 1. ตั้งค่าพื้นฐานของฐานข้อมูล
$host     = 'localhost';
$db_name  = 'e-office'; // ชื่อ Database ที่สร้างจาก SQL ก่อนหน้า
$username = 'root';           // Username (ปกติคือ root บน XAMPP)
$password = '';               // Password (ปกติจะว่างบน XAMPP)
$charset  = 'utf8mb4';

// 2. ตั้งค่า Data Source Name (DSN)
$dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";

// 3. ตั้งค่า Options ของ PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // ให้พ่น Error ออกมาเมื่อมีปัญหา
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // ให้ดึงข้อมูลมาเป็น Array แบบชื่อ Column
    PDO::ATTR_EMULATE_PREPARES   => false,                  // ปิดการจำลอง Prepared Statements เพื่อความปลอดภัย
];

try {
    // 4. เริ่มต้นการเชื่อมต่อ
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // 5. ตั้งค่า Timezone ให้เป็นประเทศไทย (สำคัญมากสำหรับระบบลาและติดตามโครงการ)
    $pdo->exec("SET time_zone = '+07:00'");

} catch (\PDOException $e) {
    // 6. หากเชื่อมต่อไม่ได้ ให้แสดงข้อความ Error
    // ในขั้นตอนใช้งานจริง (Production) ควรเปลี่ยนเป็นบันทึก Log แทนการ echo ออกหน้าจอ
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}

if (!function_exists('ensureCourseRequestCols')) {
    /**
     * ตรวจสอบและซิงค์คอลัมน์ประทับเวลาอนุมัติใน course_requests อัตโนมัติ (5 ขั้นตอนวิชาการจริง)
     */
    function ensureCourseRequestCols($pdo) {
        static $cols = null;
        if ($cols !== null) return $cols;
        try {
            $stmt = $pdo->query("DESCRIBE course_requests");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $needed = [
                'instructor_approved_at' => "ALTER TABLE `course_requests` ADD COLUMN `instructor_approved_at` DATETIME NULL AFTER `instructor_comment`",
                'advisor_approved_at'    => "ALTER TABLE `course_requests` ADD COLUMN `advisor_approved_at` DATETIME NULL AFTER `advisor_comment`",
                'head_approved_at'       => "ALTER TABLE `course_requests` ADD COLUMN `head_approved_at` DATETIME NULL AFTER `head_comment`",
                'deputy_approved_at'     => "ALTER TABLE `course_requests` ADD COLUMN `deputy_approved_at` DATETIME NULL AFTER `deputy_comment`",
                'academic_approved_at'   => "ALTER TABLE `course_requests` ADD COLUMN `academic_approved_at` DATETIME NULL AFTER `academic_comment`"
            ];

            $did_alter = false;
            foreach ($needed as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    try {
                        $pdo->exec($sql);
                        $did_alter = true;
                    } catch (\Exception $e) {}
                }
            }

            if ($did_alter) {
                $stmt = $pdo->query("DESCRIBE course_requests");
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (\Exception $e) {
            $cols = [];
        }
        return $cols;
    }
}
?>
