<?php
// modules/qrcode/action.php - Handle QR Code AJAX Actions
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles);

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    if ($action === 'create' || $action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $target_url = trim($_POST['target_url'] ?? '');
        $category = trim($_POST['category'] ?? 'ทั่วไป');
        $description = trim($_POST['description'] ?? '');
        $qr_color = trim($_POST['qr_color'] ?? '#000000');
        $qr_bg_color = trim($_POST['qr_bg_color'] ?? '#ffffff');
        $is_trackable = isset($_POST['is_trackable']) ? intval($_POST['is_trackable']) : 1;

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุชื่อเรื่อง / ชื่อ QR Code']);
            exit();
        }
        if (empty($target_url)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุลิงก์ปลายทาง (URL)']);
            exit();
        }

        // Validate URL format
        if (!preg_match("~^(?:f|ht)tps?://~i", $target_url)) {
            $target_url = "https://" . $target_url;
        }

        if ($action === 'create') {
            // Generate unique code_key
            $code_key = 'qr_' . substr(bin2hex(random_bytes(8)), 0, 10);
            
            $stmt = $pdo->prepare("
                INSERT INTO qr_codes (code_key, user_id, title, target_url, category, description, qr_color, qr_bg_color, is_trackable, scan_count, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$code_key, $current_user_id, $title, $target_url, $category, $description, $qr_color, $qr_bg_color, $is_trackable]);

            echo json_encode([
                'status' => 'success',
                'message' => 'สร้าง QR Code เรียบร้อยแล้ว',
                'id' => $pdo->lastInsertId(),
                'code_key' => $code_key
            ]);
            exit();
        } else {
            // Update
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัส QR Code']);
                exit();
            }

            // Check permission: owner or admin
            $checkStmt = $pdo->prepare("SELECT user_id FROM qr_codes WHERE id = ?");
            $checkStmt->execute([$id]);
            $owner = $checkStmt->fetchColumn();

            if (!$owner) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล QR Code ที่ต้องการแก้ไข']);
                exit();
            }
            if ($owner !== $current_user_id && !$is_admin) {
                echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์แก้ไข QR Code นี้']);
                exit();
            }

            $updateStmt = $pdo->prepare("
                UPDATE qr_codes 
                SET title = ?, target_url = ?, category = ?, description = ?, qr_color = ?, qr_bg_color = ?, is_trackable = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$title, $target_url, $category, $description, $qr_color, $qr_bg_color, $is_trackable, $id]);

            echo json_encode([
                'status' => 'success',
                'message' => 'อัปเดตข้อมูล QR Code เรียบร้อยแล้ว'
            ]);
            exit();
        }

    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัส QR Code']);
            exit();
        }

        $checkStmt = $pdo->prepare("SELECT user_id FROM qr_codes WHERE id = ?");
        $checkStmt->execute([$id]);
        $owner = $checkStmt->fetchColumn();

        if (!$owner) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล QR Code']);
            exit();
        }
        if ($owner !== $current_user_id && !$is_admin) {
            echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ลบ QR Code นี้']);
            exit();
        }

        $delStmt = $pdo->prepare("DELETE FROM qr_codes WHERE id = ?");
        $delStmt->execute([$id]);

        echo json_encode(['status' => 'success', 'message' => 'ลบ QR Code เรียบร้อยแล้ว']);
        exit();

    } elseif ($action === 'get') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE id = ?");
        $stmt->execute([$id]);
        $qr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$qr) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล']);
            exit();
        }

        echo json_encode(['status' => 'success', 'data' => $qr]);
        exit();

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        exit();
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    exit();
}
