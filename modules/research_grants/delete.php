<?php
session_start();
require_once '../../config/db.php';
require_once '../../auth/session_check.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = $_GET['id'];
$user_id = $_SESSION['user_id'];
$research_admin_roles = ['admin', 'งานวิจัย', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
$is_admin = hasRole($research_admin_roles);

try {
    if ($is_admin) {
        $stmt = $pdo->prepare("DELETE FROM research_grants WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM research_grants WHERE id = ? AND researcher_id = ?");
        $stmt->execute([$id, $user_id]);
    }
    
    $_SESSION['success'] = "ลบข้อมูลเรียบร้อยแล้ว";
} catch (PDOException $e) {
    $_SESSION['error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

if ($is_admin) {
    header("Location: index.php");
} else {
    header("Location: my_grants.php");
}
exit;
?>
