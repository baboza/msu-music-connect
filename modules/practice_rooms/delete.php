<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$manage_room_roles = ['admin', 'งานอาคารสถานที่'];
if (!hasRole($manage_room_roles)) {
    header("Location: index.php");
    exit();
}

if (isset($_GET['id'])) {
    $room_id = trim($_GET['id']);
    
    try {
        // เช็คก่อนว่ามีรายการจองค้างอยู่หรือไม่ (เพื่อป้องกัน error จาก Foreign Key ถ้าไม่ได้ตั้ง ON DELETE CASCADE ไว้ หรือเผื่อต้องการเก็บประวัติไว้)
        $check = $pdo->prepare("SELECT COUNT(*) FROM practice_bookings WHERE room_id = ?");
        $check->execute([$room_id]);
        $booking_count = $check->fetchColumn();

        if ($booking_count > 0) {
            // กรณีที่มีประวัติการจองอยู่แล้ว ไม่อนุญาตให้ลบ เพื่อป้องกันข้อมูลประวัติสูญหาย
            // แนะนำให้ผู้ใช้ใช้วิธีปิดสถานะการใช้งาน (is_active = 0) แทน
            echo "<script>
                alert('ไม่สามารถลบห้องซ้อม $room_id ได้ เนื่องจากมีประวัติการจองอยู่ในระบบ (แนะนำให้ปิดสถานะการใช้งานแทนการลบ)');
                window.location.href = 'index.php';
            </script>";
            exit();
        } else {
            $stmt = $pdo->prepare("DELETE FROM practice_rooms WHERE room_id = ?");
            $stmt->execute([$room_id]);
            
            echo "<script>
                alert('ลบห้องซ้อม $room_id สำเร็จ');
                window.location.href = 'index.php';
            </script>";
            exit();
        }
    } catch (PDOException $e) {
        echo "<script>
            alert('เกิดข้อผิดพลาดฐานข้อมูล: " . addslashes($e->getMessage()) . "');
            window.location.href = 'index.php';
        </script>";
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
