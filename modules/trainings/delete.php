<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$report_id = intval($_GET['id'] ?? 0);

if ($report_id > 0) {
    try {
        // ตรวจสอบว่ามีรายการนี้ และผู้ใช้เป็นเจ้าของ และสถานะไม่ใช่ reported
        $stmt = $pdo->prepare("SELECT report_id, status FROM training_reports WHERE report_id = ? AND user_id = ?");
        $stmt->execute([$report_id, $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($row['status'] === 'reported') {
                $_SESSION['msg'] = "ไม่สามารถลบรายงานที่บันทึกผลเรียบร้อยแล้วได้";
                $_SESSION['msg_type'] = "warning";
            } else {
                // ลบรายงาน
                $pdo->prepare("DELETE FROM training_reports WHERE report_id = ?")->execute([$report_id]);
                $_SESSION['msg'] = "ยกเลิกและลบคำขอไปราชการเรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            }
        } else {
            $_SESSION['msg'] = "ไม่พบรายการ หรือคุณไม่มีสิทธิ์ลบรายการนี้";
            $_SESSION['msg_type'] = "danger";
        }
    } catch (PDOException $e) {
        $_SESSION['msg'] = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        $_SESSION['msg_type'] = "danger";
    }
}

header("Location: index.php");
exit;
