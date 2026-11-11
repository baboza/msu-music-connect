<?php
// process_scan.php
@ini_set('display_errors', '0');
require_once '../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบ session หากมี
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$photo_base64 = isset($_POST['photo']) ? $_POST['photo'] : '';
$requested_type = isset($_POST['scan_type']) ? trim($_POST['scan_type']) : ''; // 'in', 'out', or empty (auto)
$user_id_param = isset($_POST['user_id']) ? trim($_POST['user_id']) : '';
$qr_token = isset($_POST['qr_token']) ? trim($_POST['qr_token']) : '';
$ip_address = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 50);

if(empty($photo_base64)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาถ่ายภาพใบหน้าเพื่อลงเวลา']);
    exit;
}

try {
    // ตรวจสอบและอัปเกรดชนิดคอลัมน์ user_id ใน attendance_logs อัตโนมัติ (แก้ปัญหา int(11) ตัดข้อมูลรหัสผู้ใช้ที่เป็นตัวอักษรผสมตัวเลข เช่น 2d67fd96 บนโฮสต์จริงที่มี Strict SQL Mode)
    if (empty($_SESSION['attendance_uid_migrated'])) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM attendance_logs LIKE 'user_id'");
        $colInfo = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
            $uColStmt = $pdo->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_id' LIMIT 1");
            $uCol = $uColStmt ? $uColStmt->fetch(PDO::FETCH_ASSOC) : null;
            $charsetClause = (!empty($uCol['CHARACTER_SET_NAME']) && !empty($uCol['COLLATION_NAME']))
                ? ("CHARACTER SET " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['CHARACTER_SET_NAME']) . " COLLATE " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['COLLATION_NAME']))
                : "";
            $pdo->exec("ALTER TABLE attendance_logs MODIFY COLUMN user_id VARCHAR(50) {$charsetClause} NOT NULL");
            // กู้คืนรหัสผู้ใช้เดิมที่เคยถูกตัดเป็นตัวเลขจากชื่อไฟล์รูปถ่าย scan_<user_id>_<timestamp>
            $pdo->exec("
                UPDATE attendance_logs
                SET user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(photo_path, '_', 2), '_', -1)
                WHERE photo_path LIKE 'scan\\_%\\_%'
                  AND EXISTS (
                      SELECT 1 FROM users u
                      WHERE u.user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(attendance_logs.photo_path, '_', 2), '_', -1)
                  )
            ");
        }
        $_SESSION['attendance_uid_migrated'] = true;
    }

    $user = null;

    // ต้องระบุ user_id จากจุดถ่ายรูปลงเวลา (Kiosk/เครื่องส่วนกลาง) เท่านั้น เพื่อป้องกันการลงเวลาจากที่บ้าน
    if (!empty($user_id_param)) {
        $stmt = $pdo->prepare("SELECT user_id, prefix_name, first_name, last_name, profile_picture FROM users WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$user_id_param]);
        $user = $stmt->fetch();
    } elseif (!empty($qr_token)) {
        $stmt = $pdo->prepare("SELECT user_id, prefix_name, first_name, last_name, profile_picture FROM users WHERE (qr_token = ? OR user_id = ?) AND status = 'active'");
        $stmt->execute([$qr_token, $qr_token]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาลงเวลา ณ จุดถ่ายรูปบันทึกเวลาส่วนกลาง (Kiosk) เท่านั้น']);
        exit;
    }
    
    $user_id = (string)$user['user_id'];
    $full_name = ($user['prefix_name'] ?? '') . $user['first_name'] . ' ' . $user['last_name'];
    $avatar = $user['profile_picture'];
    
    // 2. ตรวจสอบประวัติการลงเวลาของวันนี้ (ป้องกันการลงเวลาเข้างานซ้ำ หรือออกงานซ้ำในวันเดียวกัน)
    $stmt_today = $pdo->prepare("
        SELECT scan_type, scan_time 
        FROM attendance_logs 
        WHERE user_id = ? AND DATE(scan_time) = CURDATE() 
        ORDER BY scan_time ASC
    ");
    $stmt_today->execute([$user_id]);
    $today_scans = $stmt_today->fetchAll(PDO::FETCH_ASSOC);

    $today_in_time = null;
    $today_out_time = null;
    foreach ($today_scans as $ts) {
        if ($ts['scan_type'] === 'in' && $today_in_time === null) {
            $today_in_time = $ts['scan_time'];
        } elseif ($ts['scan_type'] === 'out') {
            $today_out_time = $ts['scan_time'];
        }
    }

    if (in_array($requested_type, ['in', 'out'])) {
        $scan_type = $requested_type;
    } else {
        // โหมดอัตโนมัติ (กรณีไม่ระบุประเภท): ถ้ายังไม่เข้าให้เป็น 'in' ถ้าเข้าแล้วให้เป็น 'out'
        $scan_type = ($today_in_time === null) ? 'in' : 'out';
    }

    // บล็อกการลงเวลาเข้างานซ้ำในวันเดียวกัน (1 คน ลงเข้างานได้วันละ 1 ครั้ง)
    if ($scan_type === 'in' && $today_in_time !== null) {
        $t_fmt = date('H:i:s', strtotime($today_in_time));
        echo json_encode([
            'status' => 'duplicate',
            'scan_type' => 'in',
            'recorded_time' => date('H:i', strtotime($today_in_time)),
            'scan_time' => $today_in_time,
            'user_name' => $full_name,
            'avatar' => $avatar,
            'message' => "วันนี้ลงเวลาเข้างานไปแล้วเมื่อเวลา {$t_fmt} น."
        ]);
        exit;
    }

    // บล็อกการลงเวลาออกงานซ้ำในวันเดียวกัน (1 คน ลงออกงานได้วันละ 1 ครั้ง)
    if ($scan_type === 'out' && $today_out_time !== null) {
        $t_fmt = date('H:i:s', strtotime($today_out_time));
        echo json_encode([
            'status' => 'duplicate',
            'scan_type' => 'out',
            'recorded_time' => date('H:i', strtotime($today_out_time)),
            'scan_time' => $today_out_time,
            'user_name' => $full_name,
            'avatar' => $avatar,
            'message' => "วันนี้ลงเวลาออกงานไปแล้วเมื่อเวลา {$t_fmt} น."
        ]);
        exit;
    }
    
    // 3. Save Photo (สร้างโฟลเดอร์อัตโนมัติหากบนโฮสต์ยังไม่มี)
    $photo_path = null;
    if(!empty($photo_base64) && preg_match('/^data:image\/(\w+);base64,/', $photo_base64, $type)) {
        $photo_data = substr($photo_base64, strpos($photo_base64, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, etc.
        
        if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $photo_data = base64_decode($photo_data);
            if ($photo_data !== false) {
                $upload_dir = __DIR__ . '/../../uploads/attendance/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }
                $safe_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $user_id);
                $filename = 'scan_' . $safe_uid . '_' . time() . '.' . $type;
                $filepath = $upload_dir . $filename;
                if(@file_put_contents($filepath, $photo_data) !== false) {
                    $photo_path = $filename;
                }
            }
        }
    }
    
    // 4. Insert Record
    $current_time = date('Y-m-d H:i:s');
    $stmt_insert = $pdo->prepare("INSERT INTO attendance_logs (user_id, scan_type, scan_time, photo_path, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt_insert->execute([$user_id, $scan_type, $current_time, $photo_path, $ip_address]);
    
    echo json_encode([
        'status' => 'success',
        'scan_type' => $scan_type,
        'scan_time' => $current_time,
        'user_name' => $full_name,
        'avatar' => $avatar
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
