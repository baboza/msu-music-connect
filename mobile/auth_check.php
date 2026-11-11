<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------
// สลับโหมดการแสดงผล (View Switcher: PC Desktop vs Mobile App)
// -------------------------------------------------------------
if (isset($_GET['pc'])) {
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + (86400 * 30), '/');
    header("Location: ../index.php?pc=1");
    exit();
}

if (isset($_GET['mobile'])) {
    $_SESSION['prefer_desktop'] = false;
    $_SESSION['app_mode'] = 'mobile';
    setcookie('app_mode', 'mobile', time() + (86400 * 30), '/');
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "กรุณาเข้าสู่ระบบก่อนใช้งาน";
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'ผู้ใช้งานระบบ';
$role = $_SESSION['role'] ?? 'staff';

// ฟังก์ชันสำหรับเช็คสิทธิ์ (อ้างอิงจากระบบหลัก)
function hasRole($allowed_roles) {
    if (!isset($_SESSION['roles']) || !is_array($_SESSION['roles'])) {
        // Fallback for older sessions that might use 'role' string
        if (isset($_SESSION['role'])) {
            $roles = [$_SESSION['role']];
        } else {
            return false;
        }
    } else {
        $roles = $_SESSION['roles'];
    }
    
    if (in_array('admin', $roles)) return true;
    
    if (!is_array($allowed_roles)) $allowed_roles = [$allowed_roles];
    
    foreach ($roles as $r) {
        if (in_array($r, $allowed_roles)) return true;
    }
    return false;
}

// ดึงรูปโปรไฟล์
$current_avatar = '';
try {
    $stmt_avatar = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $stmt_avatar->execute([$user_id]);
    $current_avatar = $stmt_avatar->fetchColumn();
} catch (PDOException $e) {}
?>