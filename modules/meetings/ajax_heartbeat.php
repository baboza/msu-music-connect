<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'unauthenticated',
        'message' => 'Session expired. Please login again.'
    ]);
    exit();
}

require_once __DIR__ . '/../../config/db.php';

try {
    $user_id = $_SESSION['user_id'];
    $current_time = time();
    $_SESSION['last_active_update'] = $current_time;
    
    // อัปเดตเวลาเข้าใช้งานล่าสุดในตาราง users เพื่อให้สถานะออนไลน์ไม่ขาดตอน
    $stmt = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    echo json_encode([
        'status' => 'alive',
        'user_id' => $user_id,
        'server_time' => date('H:i:s'),
        'timestamp' => $current_time
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
exit();
