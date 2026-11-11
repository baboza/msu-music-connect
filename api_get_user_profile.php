<?php
session_start();
require_once 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_GET['user_id'] ?? null;
$filter = $_GET['filter'] ?? 'day';

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
    exit();
}

try {
    // 1. Get user info
    $stmt_user = $pdo->prepare("SELECT first_name, last_name, position, profile_picture FROM users WHERE user_id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit();
    }

    // Prepare date ranges for queries
    if ($filter === 'day') {
        $start_date = date('Y-m-d 00:00:00');
        $end_date = date('Y-m-d 23:59:59');
    } elseif ($filter === 'week') {
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end_date = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    } else { // month
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
    }

    // 2. Get Work Logs
    $stmt_work = $pdo->prepare("
        SELECT w.log_id, w.log_date, w.hours_spent, t.task_name, w.description 
        FROM work_logs w 
        LEFT JOIN work_tasks t ON w.task_id = t.task_id 
        WHERE w.user_id = ? AND w.log_date BETWEEN ? AND ? 
        ORDER BY w.log_date DESC
    ");
    $stmt_work->execute([$user_id, $start_date, $end_date]);
    $work_logs = $stmt_work->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get Leaves
    $stmt_leave = $pdo->prepare("
        SELECT l.start_date, l.end_date, l.status, t.type_name 
        FROM leave_requests l 
        JOIN leave_types t ON l.type_id = t.type_id 
        WHERE l.user_id = ? AND (l.start_date BETWEEN ? AND ? OR l.end_date BETWEEN ? AND ?)
        ORDER BY l.start_date DESC
    ");
    $stmt_leave->execute([$user_id, $start_date, $end_date, $start_date, $end_date]);
    $leaves = $stmt_leave->fetchAll(PDO::FETCH_ASSOC);

    // 4. Get Trips/Training
    $stmt_trip = $pdo->prepare("
        SELECT tr.project_name as title, tr.start_date, tr.end_date, tr.status 
        FROM training_reports tr 
        WHERE tr.user_id = ? AND (tr.start_date BETWEEN ? AND ? OR tr.end_date BETWEEN ? AND ?)
        ORDER BY tr.start_date DESC
    ");
    $stmt_trip->execute([$user_id, $start_date, $end_date, $start_date, $end_date]);
    $trips = $stmt_trip->fetchAll(PDO::FETCH_ASSOC);

    // 5. Get Projects
    $stmt_proj = $pdo->prepare("
        SELECT project_id, project_name, status, start_date, end_date 
        FROM projects 
        WHERE responsible_id = ? OR coordinator_id = ?
        ORDER BY start_date DESC
    ");
    $stmt_proj->execute([$user_id, $user_id]);
    $projects = $stmt_proj->fetchAll(PDO::FETCH_ASSOC);

    // 6. Get Finance Requests
    $stmt_fin = $pdo->prepare("
        SELECT req_id, title, amount, req_type, current_status 
        FROM finance_requests 
        WHERE user_id = ? 
        ORDER BY req_id DESC 
        LIMIT 20
    ");
    $stmt_fin->execute([$user_id]);
    $finances = $stmt_fin->fetchAll(PDO::FETCH_ASSOC);

    // 7. Get Room Bookings
    $stmt_room = $pdo->prepare("
        SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.purpose, b.status, r.room_name 
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        WHERE b.user_id = ? AND b.booking_date BETWEEN ? AND ?
        ORDER BY b.booking_date DESC
    ");
    $stmt_room->execute([$user_id, $start_date, $end_date]);
    $rooms = $stmt_room->fetchAll(PDO::FETCH_ASSOC);

    // 8. Get Repair Requests
    $stmt_repair = $pdo->prepare("
        SELECT rr.repair_id, rr.equipment_name, rr.created_at, rr.status, r.room_name 
        FROM repair_requests rr
        LEFT JOIN rooms r ON rr.room_id = r.room_id
        WHERE rr.reporter_id = ? AND rr.created_at BETWEEN ? AND ?
        ORDER BY rr.created_at DESC
    ");
    $stmt_repair->execute([$user_id, $start_date, $end_date]);
    $repairs = $stmt_repair->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate Data
    $profileData = [
        'status' => 'success',
        'user' => [
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'position' => $user['position'] ?? 'บุคลากร',
            'profile_picture' => $user['profile_picture'] ?? 'default.jpg'
        ],
        'stats' => [
            'worklog_count' => count($work_logs),
            'leave_count' => count($leaves),
            'trip_count' => count($trips),
            'project_count' => count($projects),
            'finance_count' => count($finances),
            'room_count' => count($rooms),
            'repair_count' => count($repairs)
        ],
        'details' => [
            'work_logs' => array_slice($work_logs, 0, 10), // Top 10 for display
            'leaves' => $leaves,
            'trips' => $trips,
            'projects' => $projects,
            'finances' => $finances,
            'rooms' => $rooms,
            'repairs' => $repairs
        ]
    ];

    echo json_encode($profileData);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
