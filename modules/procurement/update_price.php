<?php
/**
 * ไฟล์: update_price.php
 * หน้าที่: บันทึกผลราคาจัดซื้อจริงและรายละเอียดผลการจัดซื้อ (procurement_result)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ตรวจสอบสิทธิ์ (เฉพาะ Admin และ งานพัสดุ เท่านั้นที่บันทึกได้)
$allowed_manage = ['admin', 'งานพัสดุ'];
if (!hasRole($allowed_manage)) {
    $_SESSION['msg'] = "คุณไม่มีสิทธิ์ในการแก้ไขข้อมูลนี้";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รับค่าและทำความสะอาดข้อมูล
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $actual_price = isset($_POST['actual_price']) ? floatval($_POST['actual_price']) : 0.00;
    $procurement_result = isset($_POST['procurement_result']) ? trim($_POST['procurement_result']) : '';

    if ($id > 0) {
        try {
            // เตรียมคำสั่ง SQL อัปเดตข้อมูล
            $sql = "UPDATE procurement_tracking 
                    SET actual_price = :actual_price, 
                        procurement_result = :procurement_result, 
                        updated_at = NOW() 
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $params = [
                ':actual_price' => $actual_price,
                ':procurement_result' => $procurement_result,
                ':id' => $id
            ];

            if ($stmt->execute($params)) {
                $_SESSION['msg'] = "บันทึกผลการจัดซื้อและราคาจริงเรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            } else {
                $_SESSION['msg'] = "ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง";
                $_SESSION['msg_type'] = "danger";
            }

        } catch (PDOException $e) {
            $_SESSION['msg'] = "เกิดข้อผิดพลาดทางฐานข้อมูล: " . $e->getMessage();
            $_SESSION['msg_type'] = "danger";
        }
    } else {
        $_SESSION['msg'] = "ข้อมูลไม่ถูกต้อง (Invalid ID)";
        $_SESSION['msg_type'] = "warning";
    }
}

// ส่งกลับไปยังหน้าหลักของโมดูลพัสดุ
header("Location: index.php");
exit();