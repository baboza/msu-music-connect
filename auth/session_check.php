<?php
if (session_status() === PHP_SESSION_NONE) {
    // กำหนด Session Lifetime 24 ชั่วโมง (86400 วินาที) รองรับการประชุมและการทำงานต่อเนื่องหลายชั่วโมง
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * ฟังก์ชันสำหรับตรวจสอบว่าผู้ใช้ Login หรือยัง
 * หากยังไม่ได้ Login ให้เด้งกลับไปหน้า Login ทันที
 */
if (!isset($_SESSION['user_id'])) {
    // กำหนด Path ให้เด้งกลับไปหน้า login.php 
    header("Location: /music-e-office/auth/login.php");
    exit();
}

// 💡 ---------------------------------------------------------
// ระบบอัปเดตสถานะ "ออนไลน์" (Throttling)
// อัปเดตข้อมูลลงฐานข้อมูลเฉพาะทุกๆ 5 นาที (300 วินาที) เพื่อลดภาระเซิร์ฟเวอร์
// ---------------------------------------------------------
if (isset($_SESSION['user_id'])) {
    $current_time = time();
    $update_interval = 300; // 300 วินาที = 5 นาที

    // เช็คว่าเคยอัปเดตหรือยัง หรือผ่านไปเกิน 5 นาทีแล้ว
    if (!isset($_SESSION['last_active_update']) || ($current_time - $_SESSION['last_active_update']) > $update_interval) {
        
        // ดึงไฟล์เชื่อมต่อ DB แบบอ้างอิงตำแหน่งไฟล์ปัจจุบันที่แน่นอน (Absolute Path)
        require_once __DIR__ . '/../config/db.php'; 

        try {
            if (isset($pdo)) {
                $stmt_active = $pdo->prepare("UPDATE users SET last_active = CURRENT_TIMESTAMP WHERE user_id = ?");
                $stmt_active->execute([$_SESSION['user_id']]);
                
                // ประทับเวลาล่าสุดใน Session
                $_SESSION['last_active_update'] = $current_time;
            }
        } catch (PDOException $e) {
            // ปล่อยผ่าน (Silent fail) ไม่ให้กระทบการใช้งานหาก DB โหลดชั่วคราว
        }
    }
}

/**
 * ฟังก์ชันสำหรับเช็คสิทธิ์ (Role Check)
 * @param array $allowed_roles รายชื่อสิทธิ์ที่อนุญาตให้เข้าถึงหน้านี้
 * @return bool
 */
function hasRole($allowed_roles) {
    if (!isset($_SESSION['roles'])) {
        return false;
    }
    
    // ตรวจสอบว่าสิทธิ์ของผู้ใช้ที่มีอยู่ (Array) มีค่าตรงกับที่อนุญาตหรือไม่
    $user_roles = $_SESSION['roles'];
    $result = array_intersect($user_roles, $allowed_roles);
    
    return !empty($result);
}

/**
 * ฟังก์ชันสำหรับป้องกันหน้าเว็บตามสิทธิ์ (Authorization)
 * หากไม่มีสิทธิ์ ให้หยุดการทำงานหรือเด้งไปหน้าอื่น
 */
function checkAccess($allowed_roles) {
    if (!hasRole($allowed_roles)) {
        echo "<script>
                alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
                window.location.href = '/music-e-office/index.php';
              </script>";
        exit();
    }
}
?>