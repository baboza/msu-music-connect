<?php
session_start();
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT b.*, a.account_name, a.meeting_id, a.passcode, a.meeting_url, u.first_name, u.last_name 
        FROM zoom_bookings b
        JOIN zoom_accounts a ON b.zoom_account_id = a.id
        JOIN users u ON b.user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    ");
    
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $events = [];

    // Colors mapping for different accounts
    $colors = [
        '#0d6efd', // primary
        '#198754', // success
        '#dc3545', // danger
        '#ffc107', // warning
        '#0dcaf0', // info
        '#6610f2'  // purple
    ];

    foreach ($bookings as $bk) {
        // assign color based on zoom_account_id
        $colorIndex = ($bk['zoom_account_id'] - 1) % count($colors);
        $bgColor = $colors[$colorIndex];

        $events[] = [
            'id' => $bk['id'],
            'title' => $bk['account_name'] . ' - ' . $bk['title'],
            'start' => $bk['start_time'],
            'end' => $bk['end_time'],
            'backgroundColor' => $bgColor,
            'borderColor' => $bgColor,
            'textColor' => '#ffffff',
            'extendedProps' => [
                'user_name' => $bk['first_name'] . ' ' . $bk['last_name'],
                'account_name' => $bk['account_name'],
                'booking_title' => $bk['title'],
                'meeting_id' => $bk['meeting_id'],
                'passcode' => $bk['passcode'],
                'meeting_url' => $bk['meeting_url'],
                'can_delete' => ($bk['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['roles']) && in_array('admin', $_SESSION['roles'])))
            ]
        ];
    }

    echo json_encode($events);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
