<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$title = trim($_POST['title'] ?? '');
$body = trim($_POST['body'] ?? '');
$link = trim($_POST['link'] ?? '#');
$target = trim($_POST['target_student'] ?? '');

if (empty($title) || empty($body)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกหัวข้อและข้อความ']);
    exit();
}

$db_success = 0;
$push_success = 0;

try {
    if (!empty($target) && $target !== 'all') {
        // ส่งให้นิสิตคนเดียว
        $stmt = $pdo->prepare("INSERT INTO student_notifications (student_id, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->execute([$target, $title, $body, $link]);
        $db_success = 1;
    } else {
        // ส่งให้นิสิตทุกคนที่สถานะ active
        $stmt_students = $pdo->query("SELECT student_id FROM students WHERE status = 'active' OR student_status = 'active'");
        $students = $stmt_students->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($students)) {
            $pdo->beginTransaction();
            $stmt_ins = $pdo->prepare("INSERT INTO student_notifications (student_id, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            foreach ($students as $sid) {
                $stmt_ins->execute([$sid, $title, $body, $link]);
                $db_success++;
            }
            $pdo->commit();
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'ส่งข้อความแจ้งเตือนสำเร็จ',
        'db_success' => $db_success,
        'push_success' => $push_success
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
