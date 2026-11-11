<?php
require_once 'auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$room_id = $_GET['room_id'] ?? '';
$date = $_GET['date'] ?? '';

if (empty($room_id) || empty($date)) {
    echo json_encode([]);
    exit;
}

try {
    // อัปเดตสถานะ 'active' และ 'approved' เป็น 'completed' อัตโนมัติหากเลยเวลาไปแล้ว
    $pdo->exec("
        UPDATE practice_bookings 
        SET status = 'completed' 
        WHERE status IN ('active', 'approved') 
        AND CONCAT(booking_date, ' ', end_time) < NOW()
    ");

    $stmt = $pdo->prepare("SELECT start_time, end_time, status FROM practice_bookings WHERE room_id = ? AND booking_date = ? AND status != 'cancelled' ORDER BY start_time ASC");
    $stmt->execute([$room_id, $date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($bookings);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
