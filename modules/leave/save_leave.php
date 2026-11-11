<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions_noti.php'; // ตรวจสอบว่าในไฟล์นี้มีฟังก์ชัน sendTelegramWithButtons แล้ว

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $type_id = $_POST['type_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];

    // 1. จัดการไฟล์แนบ
    $attachment_name = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $upload_dir = '../../uploads/leaves/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_info = pathinfo($_FILES['attachment']['name']);
        $file_ext = strtolower($file_info['extension']);
        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_ext)) {
            $attachment_name = "leave_" . $user_id . "_" . date('YmdHis') . "." . $file_ext;
            $upload_path = $upload_dir . $attachment_name;

            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $_SESSION['msg_error'] = "ไม่สามารถอัปโหลดไฟล์ได้";
                header("Location: create.php");
                exit();
            }
        }
    }

    try {
        $pdo->beginTransaction();

        // 2. บันทึกลงฐานข้อมูล
        $sql = "INSERT INTO leave_requests (user_id, type_id, start_date, end_date, reason, attachment, current_step, status) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 'pending')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $type_id, $start_date, $end_date, $reason, $attachment_name]);
        
        $new_leave_id = $pdo->lastInsertId();
        $pdo->commit();

        // ==========================================
        // 3. ระบบแจ้งเตือนผ่าน Telegram (พร้อมปุ่มกด)
        // ==========================================
        
        // ดึงชื่อประเภทการลา
        $stmt_type = $pdo->prepare("SELECT type_name FROM leave_types WHERE type_id = ?");
        $stmt_type->execute([$type_id]);
        $type_name = $stmt_type->fetchColumn();

        // กลุ่มผู้รับแจ้งเตือน (งานบุคคล, บุคลากร, และ Admin)
        $target_roles = ['งานบุคคล', 'บุคลากร', 'admin']; 
        $placeholders = implode(',', array_fill(0, count($target_roles), '?'));

        $stmt_noti = $pdo->prepare("
            SELECT DISTINCT u.telegram_chat_id 
            FROM users u 
            JOIN user_roles ur ON u.user_id = ur.user_id 
            JOIN roles r ON ur.role_id = r.role_id 
            WHERE r.role_name IN ($placeholders) AND u.telegram_chat_id IS NOT NULL
        ");
        $stmt_noti->execute($target_roles);
        $notified_users = $stmt_noti->fetchAll();

        // เตรียมข้อความ HTML
        $msg = "🔔 <b>มีการยื่นใบลาใหม่รอการตรวจสอบ</b>\n";
        $msg .= "--------------------------------\n";
        $msg .= "👤 <b>ผู้ขอลา:</b> " . $_SESSION['full_name'] . "\n";
        $msg .= "📋 <b>ประเภท:</b> " . $type_name . "\n";
        $msg .= "📅 <b>วันที่:</b> " . date('d/m/Y', strtotime($start_date)) . " ถึง " . date('d/m/Y', strtotime($end_date)) . "\n";
        $msg .= "📂 <b>ไฟล์แนบ:</b> " . ($attachment_name ? "✅ มีใบรับรองแพทย์" : "❌ ไม่มี") . "\n";
        $msg .= "--------------------------------\n";
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
        $host_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $msg .= "🔗 <a href='" . $host_url . "/music-e-office/modules/leave/view.php?id=" . $new_leave_id . "'>เปิดดูรายละเอียดฉบับเต็ม</a>";

        // ส่งข้อความพร้อมปุ่ม Inline Approve/Reject
        foreach ($notified_users as $user) {
            sendTelegramWithButtons($user['telegram_chat_id'], $msg, $new_leave_id);
        }

        $_SESSION['msg_success'] = "ส่งใบลาและแจ้งเตือน Telegram สำเร็จ";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['msg_error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
        header("Location: create.php");
        exit();
    }
} else {
    header("Location: create.php");
    exit();
}