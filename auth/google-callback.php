<?php
// 1. ปิดการแสดงผล Error บนหน้าจอเมื่อขึ้น Server จริง
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params(86400, '/; samesite=Lax', '', false, true);
    }
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

// --- [ค่าคงที่จาก Google JSON ของคุณ] ---
$client_id     = GOOGLE_CLIENT_ID;
$client_secret = GOOGLE_CLIENT_SECRET;
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirect_uri  = $host_url . '/music-e-office/auth/google-callback.php';

if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // 2. แลกเปลี่ยน Authorization Code เป็น Access Token
    $token_url = "https://oauth2.googleapis.com/token";
    $params = [
        'code'          => $code,
        'client_id'      => $client_id,
        'client_secret'  => $client_secret,
        'redirect_uri'   => $redirect_uri,
        'grant_type'     => 'authorization_code'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    $response = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);

    if (isset($token_data['access_token'])) {
        $access_token = $token_data['access_token'];

        // 3. ดึงข้อมูล Profile จาก Google API
        $userinfo_url = "https://www.googleapis.com/oauth2/v3/userinfo";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userinfo_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $user_info_json = curl_exec($ch);
        $google_user = json_decode($user_info_json, true);
        curl_close($ch);

        if (isset($google_user['email'])) {
            $google_email = $google_user['email'];

            // 4. ตรวจสอบผู้ใช้งานในฐานข้อมูล
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$google_email]);
            $user = $stmt->fetch();

            if ($user) {
                // --- ตั้งค่า Session พื้นฐาน ---
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['full_name'] = ($user['prefix_name'] ?? '') . $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['email']     = $user['email'];
                
                // --- 5. ดึงชื่อสิทธิ์ (Roles) ---
                $stmt_roles = $pdo->prepare("
                    SELECT r.role_name 
                    FROM user_roles ur 
                    INNER JOIN roles r ON ur.role_id = r.role_id 
                    WHERE ur.user_id = ?
                ");
                $stmt_roles->execute([$user['user_id']]);
                $roles = $stmt_roles->fetchAll(PDO::FETCH_COLUMN);
                $_SESSION['roles'] = $roles;

                // 💡 ---------------------------------------------------------
                // 6. ระบบบันทึกประวัติการ Login และอัปเดตสถานะออนไลน์
                // ---------------------------------------------------------
                try {
                    // ก. ดึงข้อมูลสภาพแวดล้อม
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

                    // ข. บันทึกลงตาราง login_logs
                    $stmt_log = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
                    $stmt_log->execute([$user['user_id'], $ip_address, $user_agent]);

                    // ค. อัปเดตเวลาเข้าใช้งานล่าสุดในตาราง users
                    $google_pic = $google_user['picture'] ?? '';
                    if (!empty($google_pic)) {
                        $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_active = NOW(), profile_picture = CASE WHEN profile_picture IS NULL OR profile_picture = '' OR profile_picture LIKE 'https://lh3.googleusercontent.com%' THEN ? ELSE profile_picture END WHERE user_id = ?");
                        $update_stmt->execute([$google_pic, $user['user_id']]);
                    } else {
                        $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_active = NOW() WHERE user_id = ?");
                        $update_stmt->execute([$user['user_id']]);
                    }

                    // ง. รีเซ็ตตัวนับ Throttling ใน Session เพื่อให้จุดออนไลน์สีเขียวแสดงผลทันที
                    $_SESSION['last_active_update'] = time();

} catch (PDOException $e) {
                    // โชว์ Error ให้เห็นชัดๆ จะได้รู้ว่าติดตรงไหน
                    die("Database Error in Login Log: " . $e->getMessage()); 
                }
                // ---------------------------------------------------------

                // ตรวจสอบที่มา: ส่งไป Mobile เฉพาะกรณีที่ล็อกอินผ่าน mobile/login.php (state=mobile) เท่านั้น!
                $is_mobile_login = (isset($_GET['state']) && strpos((string)$_GET['state'], 'mobile') !== false) 
                                || (isset($_SESSION['login_target']) && $_SESSION['login_target'] === 'mobile');

                unset($_SESSION['login_target']);
                setcookie('login_from_mobile', '', time() - 3600, '/');

                if ($is_mobile_login) {
                    $_SESSION['prefer_desktop'] = false;
                    $_SESSION['app_mode'] = 'mobile';
                    setcookie('app_mode', 'mobile', time() + (86400 * 30), '/');
                    header("Location: ../mobile/index.php");
                    exit();
                } else {
                    // หากล็อกอินผ่าน auth/login.php ให้เข้าสู่ระบบหน้า PC (Desktop) เสมอ 100%!
                    $_SESSION['prefer_desktop'] = true;
                    $_SESSION['app_mode'] = 'desktop';
                    setcookie('app_mode', 'desktop', time() + (86400 * 30), '/');
                    header("Location: ../index.php");
                    exit();
                }
            } else {
                $_SESSION['error'] = "อีเมล $google_email ยังไม่ได้ลงทะเบียนในระบบ กรุณาติดต่อแอดมิน";
                $is_mobile_err = (isset($_GET['state']) && strpos((string)$_GET['state'], 'mobile') !== false) 
                              || (isset($_SESSION['login_target']) && $_SESSION['login_target'] === 'mobile');
                unset($_SESSION['login_target']);
                header("Location: " . ($is_mobile_err ? "../mobile/login.php" : "login.php"));
                exit();
            }
        }
    } else {
        $_SESSION['error'] = "Google Auth Failed: " . ($token_data['error_description'] ?? 'Unauthorized');
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_mobile = (isset($_GET['state']) && strpos((string)$_GET['state'], 'mobile') !== false) 
                  || (isset($_SESSION['login_target']) && $_SESSION['login_target'] === 'mobile')
                  || (isset($_SESSION['app_mode']) && $_SESSION['app_mode'] === 'mobile')
                  || (isset($_COOKIE['login_from_mobile']) && $_COOKIE['login_from_mobile'] === '1')
                  || (isset($_COOKIE['app_mode']) && $_COOKIE['app_mode'] === 'mobile')
                  || (bool)preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone|mobile)/i', $user_agent);
        unset($_SESSION['login_target']);
        header("Location: " . ($is_mobile ? "../mobile/login.php" : "login.php"));
        exit();
    }
} else {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_mobile = (isset($_GET['state']) && strpos((string)$_GET['state'], 'mobile') !== false) 
              || (isset($_SESSION['login_target']) && $_SESSION['login_target'] === 'mobile')
              || (isset($_SESSION['app_mode']) && $_SESSION['app_mode'] === 'mobile')
              || (isset($_COOKIE['login_from_mobile']) && $_COOKIE['login_from_mobile'] === '1')
              || (isset($_COOKIE['app_mode']) && $_COOKIE['app_mode'] === 'mobile')
              || (bool)preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone|mobile)/i', $user_agent);
    unset($_SESSION['login_target']);
    header("Location: " . ($is_mobile ? "../mobile/login.php" : "login.php"));
    exit();
}