<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เฉพาะ admin และ งานพัสดุเท่านั้นที่ลบได้)
if (!hasRole(['admin', 'งานพัสดุ'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์ลบข้อมูลรายการพัสดุ";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

// 2. ตรวจสอบพารามิเตอร์ ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$project_id = $_GET['id'];

try {
    // 3. เช็คว่ามีรายการนี้อยู่ในระบบจริงหรือไม่
    $stmt_check = $pdo->prepare("SELECT project_name FROM procurement_tracking WHERE id = ?");
    $stmt_check->execute([$project_id]);
    $project = $stmt_check->fetch();

    if ($project) {
        // 4. ดำเนินการลบข้อมูล
        $stmt_delete = $pdo->prepare("DELETE FROM procurement_tracking WHERE id = ?");
        $stmt_delete->execute([$project_id]);

        // ตั้งค่าข้อความแจ้งเตือนเมื่อลบสำเร็จ
        $_SESSION['msg'] = "ลบรายการ <b>" . htmlspecialchars($project['project_name']) . "</b> ออกจากระบบเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['msg'] = "ไม่พบรายการที่ต้องการลบในระบบ";
        $_SESSION['msg_type'] = "danger";
    }

} catch (Exception $e) {
    $_SESSION['msg'] = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
    $_SESSION['msg_type'] = "danger";
}

// 5. กลับไปหน้าหลัก (index.php) พร้อมแนบ parameter ปีงบประมาณเดิมกลับไปด้วย (ถ้ามี)
$fy_param = isset($_GET['fy']) ? "?fy=" . urlencode($_GET['fy']) : "";
header("Location: index.php" . $fy_param);
exit();
?>