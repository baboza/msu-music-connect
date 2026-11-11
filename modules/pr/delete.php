<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$current_user_id = $_SESSION['user_id'];
$itemId = (int)($_GET['id'] ?? 0);

if ($itemId > 0) {
    // ผู้ใช้ทั่วไปลบได้เฉพาะของตัวเองที่สถานะ 'pending' หรือถ้าเป็น admin/approver ลบได้
    $pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
    $is_approver = hasRole($pr_approver_roles);

    if ($is_approver) {
        $stmt = $pdo->prepare("DELETE FROM pr_signage_items WHERE item_id = :id");
        $stmt->execute([':id' => $itemId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM pr_signage_items WHERE item_id = :id AND user_id = :uid AND status = 'pending'");
        $stmt->execute([':id' => $itemId, ':uid' => $current_user_id]);
    }
}

header("Location: index.php?msg=deleted");
exit();
