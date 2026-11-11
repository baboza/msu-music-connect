<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ป้องกันการเข้าถึงไฟล์โดยตรงผ่าน URL (ต้องส่งค่าผ่าน POST เท่านั้น)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['papers'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$scholar_id = $_POST['scholar_id'] ?? null; // ดึงรหัส Scholar จาก Modal
$import_count = 0;

try {
    // เริ่มต้น Transaction (ถ้าพัง ให้พังทั้งหมด ไม่ให้ข้อมูลค้างคา)
    $pdo->beginTransaction();

    // 1. เตรียมคำสั่ง SQL สำหรับ INSERT งานวิจัย (อัปเดต: เพิ่มคอลัมน์ authors)
    $stmt = $pdo->prepare("INSERT INTO cv_research (user_id, title, authors, publisher, publish_year, url_link) 
                           VALUES (?, ?, ?, ?, ?, ?)");

    // 2. วนลูปบันทึกเฉพาะรายการที่ถูกเลือก (Selected)
    foreach ($_POST['papers'] as $paper) {
        if (isset($paper['selected'])) {
            $stmt->execute([
                $user_id,
                $paper['title'],
                $paper['authors'] ?? '', // ดึงและบันทึกชื่อผู้แต่ง
                $paper['publisher'] ?: 'ไม่ระบุแหล่งตีพิมพ์',
                $paper['publish_year'] ?: null,
                $paper['url_link'] ?: null
            ]);
            $import_count++;
        }
    }

    // 3. อัปเดต Scholar ID และ เวลา Sync ล่าสุดที่ตาราง users
    $last_sync_sql = "UPDATE users SET 
                        scholar_id = ?, 
                        last_scholar_sync = NOW() 
                      WHERE user_id = ?";
    $pdo->prepare($last_sync_sql)->execute([$scholar_id, $user_id]);

    // บันทึกข้อมูลทั้งหมดลง DB จริง
    $pdo->commit();

    // ส่งกลับหน้าหลักพร้อมแจ้งเตือนความสำเร็จ
    header("Location: index.php?status=success&count=$import_count");
    exit();

} catch (Exception $e) {
    // หากเกิด Error ให้ยกเลิกการบันทึกทั้งหมด
    $pdo->rollBack();
    echo "เกิดข้อผิดพลาด: " . $e->getMessage();
    exit();
}