<?php
/**
 * Music E-office Logout
 * เคลียร์ Session และออกจากระบบ
 */

session_start();

// 1. ล้างตัวแปร Session ทั้งหมด
$_SESSION = array();

// 2. ถ้าต้องการลบ Session Cookie ในเบราว์เซอร์ด้วย (เพื่อความปลอดภัยสูงสุด)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. ทำลาย Session
session_destroy();

// 4. ส่งกลับไปหน้า Login แบบเจาะจง Path ป้องกันปัญหาบน Server จริง
// หากไม่ได้ติดตั้งในโฟลเดอร์ /music-e-office/ ให้ปรับแก้ URL ตรงนี้ให้ตรงกับโฟลเดอร์จริงครับ
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_real_mobile = (bool)preg_match('/(android|iphone|ipod|blackberry|windows phone|mobile)/i', $user_agent);

$is_mobile = (isset($_GET['from']) && $_GET['from'] === 'mobile') 
          || (isset($_SESSION['login_target']) && $_SESSION['login_target'] === 'mobile')
          || ($is_real_mobile && empty($_SESSION['prefer_desktop']));

$login_url = $is_mobile ? "/music-e-office/mobile/login.php" : "/music-e-office/auth/login.php"; 

header("Location: " . $login_url);
exit();
?>