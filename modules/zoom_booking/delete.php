<?php
session_start();
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $booking_id = $_POST['booking_id'];
    $user_id = $_SESSION['user_id'];
    $is_admin = isset($_SESSION['roles']) && in_array('admin', $_SESSION['roles']);

    try {
        // แอดมินลบได้หมด, ผู้ใช้ทั่วไปลบได้เฉพาะของตัวเอง
        if ($is_admin) {
            $stmt = $pdo->prepare("DELETE FROM zoom_bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM zoom_bookings WHERE id = ? AND user_id = ?");
            $stmt->execute([$booking_id, $user_id]);
        }

        if ($stmt->rowCount() > 0) {
            $_SESSION['msg'] = "ยกเลิกคิวจอง Zoom สำเร็จแล้ว";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg'] = "ไม่สามารถยกเลิกคิวได้ หรือคุณไม่มีสิทธิ์ลบรายการนี้";
            $_SESSION['msg_type'] = "danger";
        }
    } catch (PDOException $e) {
        $_SESSION['msg'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $_SESSION['msg_type'] = "danger";
    }

    header("Location: index.php");
    exit();
} else {
    header("Location: index.php");
    exit();
}
?>
