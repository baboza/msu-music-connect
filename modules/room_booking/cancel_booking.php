<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// ตรวจสอบสิทธิ์ Admin และ งานอาคารสถานที่
$is_admin = hasRole(['admin', 'Admin', 'ผู้ดูแลระบบ']);
if (!$is_admin && isset($_SESSION['user_id'])) {
    try {
        $stmt_check_admin = $pdo->prepare("
            SELECT COUNT(*) FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.role_id 
            WHERE ur.user_id = ? AND (r.role_name = 'admin' OR r.role_id = 25)
        ");
        $stmt_check_admin->execute([$_SESSION['user_id']]);
        if ($stmt_check_admin->fetchColumn() > 0) {
            $is_admin = true;
            if (isset($_SESSION['roles']) && !in_array('admin', $_SESSION['roles'])) {
                $_SESSION['roles'][] = 'admin';
            }
        }
    } catch (Exception $e) {}
}
$is_building_staff = hasRole(['งานอาคารสถานที่', 'โสตทัศนูปกรณ์', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร']);
$can_manage_booking = ($is_admin || $is_building_staff);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: my_bookings.php");
    exit();
}

$booking_id = intval($_GET['id']);

// ตรวจสอบว่าคำขอนี้มีอยู่จริง และเป็นเจ้าของคำขอ (หรือเป็นงานอาคารสถานที่)
$stmt = $pdo->prepare("SELECT * FROM room_bookings WHERE booking_id = ?");
$stmt->execute([$booking_id]);
$bk = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bk) {
    header("Location: my_bookings.php");
    exit();
}

// ตรวจสอบสิทธิ์: ต้องเป็นเจ้าของรายการ หรือเป็นผู้ดูแลอาคาร/Admin
if ($bk['user_id'] !== $user_id && !$can_manage_booking) {
    echo "<script>alert('คุณไม่มีสิทธิ์ยกเลิกรายการนี้'); window.location.href='my_bookings.php';</script>";
    exit();
}

// ยกเลิกได้เฉพาะรายการที่ pending หรือ (approved ถ้าเป็นเจ้าหน้าที่อาคาร/Admin)
if ($bk['status'] == 'pending' || ($bk['status'] == 'approved' && $can_manage_booking)) {
    $stmt_update = $pdo->prepare("UPDATE room_bookings SET status = 'cancelled', approver_id = ?, reject_reason = ? WHERE booking_id = ?");
    $custom_reason = trim($_POST['cancel_reason'] ?? $_POST['reason'] ?? $_GET['cancel_reason'] ?? $_GET['reason'] ?? '');
    if (!empty($custom_reason)) {
        $reason = $custom_reason;
    } else {
        $reason = ($bk['user_id'] === $user_id) ? 'ผู้จองขอยกเลิกด้วยตนเอง' : ($is_admin ? 'ผู้ดูแลระบบ (Admin) ยกเลิก' : 'เจ้าหน้าที่อาคารสถานที่ยกเลิก');
    }
    $stmt_update->execute([$user_id, $reason, $booking_id]);
}

$redirect = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'my_bookings.php';
header("Location: " . $redirect);
exit();
