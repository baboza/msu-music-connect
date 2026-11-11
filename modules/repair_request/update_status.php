<?php
session_start();

// 1. เช็คสิทธิ์การเข้าถึง (ต้องล็อกอินและมีสิทธิ์แอดมิน/ช่าง)
if (!isset($_SESSION['user_id'])) {
    header("Content-Type: application/json");
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/db.php';

// 2. ตรวจสอบว่าเป็นการส่งค่าแบบ POST หรือไม่
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // รับค่าและทำความสะอาดข้อมูล
    $repair_id = isset($_POST['repair_id']) ? intval($_POST['repair_id']) : 0;
    $new_status = isset($_POST['status']) ? $_POST['status'] : '';
    $repair_note = isset($_POST['repair_note']) ? trim($_POST['repair_note']) : '';
    $technician_id = $_SESSION['user_id']; // ดึง ID ผู้ที่ล็อกอินอยู่มาเป็นคนซ่อม

    // ตรวจสอบค่าที่จำเป็น
    if ($repair_id <= 0 || empty($new_status)) {
        $_SESSION['msg'] = "ข้อมูลไม่ครบถ้วน ไม่สามารถอัปเดตได้";
        $_SESSION['msg_type'] = "danger";
        header("Location: view_repair.php?id=" . $repair_id);
        exit();
    }

    try {
        // 3. อัปเดตข้อมูลลงฐานข้อมูล (ตาราง repair_requests)
        // บันทึกทั้งสถานะ, หมายเหตุ, ID ผู้ซ่อม และเวลาที่แก้ไขล่าสุด
        $stmt = $pdo->prepare("
            UPDATE repair_requests 
            SET status = ?, 
                repair_note = ?, 
                technician_id = ?, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE repair_id = ?
        ");
        
        $result = $stmt->execute([$new_status, $repair_note, $technician_id, $repair_id]);

        if ($result) {
            $_SESSION['msg'] = "อัปเดตสถานะงานซ่อมเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg'] = "ไม่สามารถอัปเดตข้อมูลได้ กรุณาลองใหม่อีกครั้ง";
            $_SESSION['msg_type'] = "warning";
        }

    } catch (PDOException $e) {
        $_SESSION['msg'] = "เกิดข้อผิดพลาดทางฐานข้อมูล: " . $e->getMessage();
        $_SESSION['msg_type'] = "danger";
    }

    // 4. Redirect กลับไปยังหน้าดูรายละเอียดเดิม
    header("Location: view_repair.php?id=" . $repair_id);
    exit();

} else {
    // ถ้าไม่ได้มาด้วย POST ให้เด้งกลับหน้าหลัก
    header("Location: index.php");
    exit();
}