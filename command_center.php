<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'auth/session_check.php';
require_once 'config/db.php';

// เช็คสิทธิ์ (เข้าได้เฉพาะผู้บริหาร)
$exec_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($exec_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: สำหรับผู้บริหารเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$current_month = date('Y-m');
$active_fy = date('Y') + 543; 

// --- Time Range Filter Logic ---
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'day';
if ($view_mode === 'day') {
    $start_date = $today;
    $end_date = $today;
    $scope_label = "วันนี้";
} elseif ($view_mode === 'week') {
    // Assuming week starts on Monday
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
    $scope_label = "สัปดาห์นี้";
} else {
    // month (default)
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
    $scope_label = "เดือนนี้";
}

// ดึงปีงบประมาณล่าสุดที่ active
try {
    $stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
    $res_fy = $stmt_fy->fetchColumn();
    if ($res_fy) $active_fy = $res_fy;
} catch (PDOException $e) {}

// Fetch Live Data (KPIs)
// 1. Leave
$pending_leaves_count = 0; $leaves_scope = 0;
try {
    $pending_sql = "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'";
    if (hasRole(['หัวหน้าสำนักงาน'])) { $pending_sql .= " AND current_step = 2"; } 
    elseif (hasRole(['รองคณบดีฝ่ายบริหาร'])) { $pending_sql .= " AND current_step = 3"; } 
    elseif (hasRole(['คณบดี', 'รักษาการคณบดี'])) { $pending_sql .= " AND current_step = 4"; } 
    elseif (hasRole(['admin'])) { $pending_sql .= " AND current_step > 1"; }
    $pending_leaves_count = $pdo->query($pending_sql)->fetchColumn() ?: 0;
    
    // Overlapping condition: start_date <= scope_end AND end_date >= scope_start
    $stmt_today = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status NOT IN ('rejected', 'cancelled') AND start_date <= :end_date AND end_date >= :start_date");
    $stmt_today->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $leaves_scope = $stmt_today->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// 2. Training
$training_scope = 0;
try {
    $stmt_train_today = $pdo->prepare("SELECT COUNT(*) FROM training_reports WHERE status NOT IN ('rejected', 'cancelled') AND start_date <= :end_date AND end_date >= :start_date");
    $stmt_train_today->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $training_scope = $stmt_train_today->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// 3. Rooms
$pending_rooms = 0; $rooms_scope = 0;
try {
    $pending_rooms = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'")->fetchColumn() ?: 0;
    $stmt_rt = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE status = 'approved' AND date BETWEEN :start_date AND :end_date");
    $stmt_rt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $rooms_scope = $stmt_rt->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// 4. Repairs
$pending_repairs = 0;
try { $pending_repairs = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status IN ('pending', 'in_progress')")->fetchColumn() ?: 0; } catch (PDOException $e) {}

// 5. Projects Status for Cabinets
$proj_status = ['completed' => 0, 'delayed' => 0, 'active' => 0, 'not_started' => 0];
try {
    $stmt_proj = $pdo->query("SELECT task_approve, task_borrow, task_execute, task_report, task_clear_money, status, deadline FROM projects");
    $raw_projects = $stmt_proj->fetchAll();
    $today = strtotime(date('Y-m-d'));
    foreach ($raw_projects as $row) {
        $tasks = [$row['task_approve'], $row['task_borrow'], $row['task_execute'], $row['task_report'], $row['task_clear_money']];
        $completed_tasks = array_sum($tasks);
        $progress = ($completed_tasks / 5) * 100;
        
        $curr_status = $row['status'];
        if ($progress < 100 && strtotime($row['deadline']) < $today) {
            $curr_status = 'ล่าช้า';
        }
        
        if ($curr_status == 'ดำเนินการเรียบร้อย' || strpos($curr_status, 'เรียบร้อย') !== false || $progress == 100) {
            $proj_status['completed']++;
        } elseif ($curr_status == 'ล่าช้า') {
            $proj_status['delayed']++;
        } elseif ($curr_status == 'กำลังดำเนินการ' || strpos($curr_status, 'กำลัง') !== false || $progress > 0) {
            $proj_status['active']++;
        } else {
            $proj_status['not_started']++;
        }
    }
    $proj_delayed = $proj_status['delayed'];
} catch (PDOException $e) {}

// 6. Finance & Supplies
$finance_pending_amount = 0; $pending_supplies = 0;
try {
    $stmt_fin = $pdo->prepare("SELECT SUM(amount) FROM finance_requests WHERE fiscal_year = ? AND current_status != 'paid'");
    $stmt_fin->execute([$active_fy]);
    $finance_pending_amount = $stmt_fin->fetchColumn() ?: 0;
    $pending_supplies = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE head_status = 'pending' OR supply_status = 'pending'")->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// 6.5. Active Supplies for 3D
$supplies_data_3d = [];
try {
    $stmt_sup = $pdo->query("SELECT request_id, head_status, supply_status FROM supplies_requests WHERE head_status != 'rejected' ORDER BY request_date DESC LIMIT 40");
    $supplies_data_3d = $stmt_sup->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// 7. Research
$total_research = 0;
try { $total_research = $pdo->query("SELECT COUNT(*) FROM cv_research")->fetchColumn() ?: 0; } catch (PDOException $e) {}

// 8. Academics
$pending_course_reqs = 0;
try { $pending_course_reqs = $pdo->query("SELECT COUNT(*) FROM course_requests WHERE status LIKE 'pending%'")->fetchColumn() ?: 0; } catch (PDOException $e) {}

// 9. Complaints
$pending_complaints = 0;
try { $pending_complaints = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'pending'")->fetchColumn() ?: 0; } catch (PDOException $e) {}

// 10. Core (Mood)
$avg_mood = 0;
try {
    $stmt_work = $pdo->prepare("SELECT AVG(harmony_score) as avg_mood FROM work_logs WHERE DATE_FORMAT(log_date, '%Y-%m') = ?");
    $stmt_work->execute([$current_month]);
    $avg_mood = round($stmt_work->fetchColumn() ?: 0, 1);
} catch (PDOException $e) {}


// Avatars Data Fetching
$leave_users = [];
$trip_users = [];
$work_users = [];
$work_logs = [];
$all_users = [];

try {
    // 1. Get users on leave within scope
    $stmt_leave = $pdo->prepare("SELECT u.user_id, t.type_name as leave_type, l.start_date, l.end_date FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id JOIN users u ON l.user_id = u.user_id WHERE l.status NOT IN ('rejected', 'cancelled') AND l.start_date <= :end_date AND l.end_date >= :start_date");
    $stmt_leave->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $leave_data_rows = $stmt_leave->fetchAll(PDO::FETCH_ASSOC);
    foreach ($leave_data_rows as $lr) {
        $leave_users[$lr['user_id']] = [
            'type' => $lr['leave_type'],
            'start' => $lr['start_date'],
            'end' => $lr['end_date']
        ];
    }
    
    // 2. Get users on training/trip within scope
    $stmt_trip = $pdo->prepare("SELECT u.user_id, tr.start_date, tr.end_date FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.status NOT IN ('rejected', 'cancelled') AND tr.start_date <= :end_date AND tr.end_date >= :start_date");
    $stmt_trip->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $trip_data_rows = $stmt_trip->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trip_data_rows as $tr) {
        $trip_users[$tr['user_id']] = [
            'start' => $tr['start_date'],
            'end' => $tr['end_date']
        ];
    }

    // 3. Get users who logged work within scope (pick latest task or just any task in range)
    // We group by user_id to avoid duplicates if they logged multiple times in the scope
    $stmt_work = $pdo->prepare("
        SELECT w.user_id, MAX(t.task_name) as task_name, GROUP_CONCAT(w.description) as descriptions, AVG(w.harmony_score) as avg_score 
        FROM work_logs w 
        LEFT JOIN work_tasks t ON w.task_id = t.task_id 
        WHERE w.log_date BETWEEN :start_date AND :end_date 
        GROUP BY w.user_id
    ");
    $stmt_work->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    
    $work_logs = [];
    while ($row = $stmt_work->fetch(PDO::FETCH_ASSOC)) {
        $work_logs[$row['user_id']] = [
            'task_name' => $row['task_name'],
            'descriptions' => $row['descriptions'],
            'avg_score' => $row['avg_score']
        ];
    }

    // 3.5 Get active technicians from repair_requests
    $active_technicians = [];
    $stmt_tech = $pdo->query("SELECT technician_id FROM repair_requests WHERE status = 'in_progress' AND technician_id IS NOT NULL");
    while ($row = $stmt_tech->fetch(PDO::FETCH_ASSOC)) {
        $active_technicians[] = $row['technician_id'];
    }

    // 4. Fetch ALL ACTIVE USERS in the system
    $stmt_all_users = $pdo->query("
        SELECT u.user_id, u.first_name, u.position, GROUP_CONCAT(r.role_name) as roles 
        FROM users u 
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id 
        LEFT JOIN roles r ON ur.role_id = r.role_id 
        WHERE u.status = 'active'
        GROUP BY u.user_id, u.first_name, u.position
    ");
    $all_users = $stmt_all_users->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<!-- Database Error: " . $e->getMessage() . " -->";
}

// Build JS Array string for charactersData
$js_characters_data = [];
$char_id = 0;

$sick_count = 0;
$vacation_count = 0;
$personal_count = 0;
foreach ($all_users as $user) {
    $uid = $user['user_id'];
    $name = addslashes(str_replace(["\r", "\n"], ' ', $user['first_name']));
    $job = isset($user['position']) ? addslashes(str_replace(["\r", "\n"], ' ', $user['position'])) : '-';
    
    // Randomize colors for variety
    $colors = ['0x3b82f6', '0x10b981', '0xf59e0b', '0x8b5cf6', '0xec4899', '0x06b6d4', '0x14b8a6', '0x64748b'];
    $color = $colors[$char_id % count($colors)];

    if (isset($leave_users[$uid])) {
        $ld = $leave_users[$uid];
        $l_type = isset($ld['type']) ? (string)$ld['type'] : '';
        $date_str = date('d/m/y', strtotime($ld['start'])) . ' - ' . date('d/m/y', strtotime($ld['end']));
        
        if (strpos($l_type, 'ป่วย') !== false) {
            // Sick Leave -> Bed
            $bed_z = -8 + ($sick_count * 4.5); 
            $avatar_z = $bed_z + 1.5; 
            $js_characters_data[] = "{ id: {$char_id}, user_id: '{$uid}', name: '{$name}', job: '{$job}', color: 0xec4899, skin: 0xffe0bd, pos: {x: -14, z: {$avatar_z}}, state: 'sickSleeping', task: 'ลาป่วย ({$date_str})', rawStatus: 'พักฟื้น', bedPos: {x: -14, z: {$bed_z}} }";
            $sick_count++;
        } elseif (strpos($l_type, 'พักผ่อน') !== false) {
            // Vacation -> Runway
            $vx = 26;
            $vz = -10 + ($vacation_count * 2.5);
            $js_characters_data[] = "{ id: {$char_id}, user_id: '{$uid}', name: '{$name}', job: '{$job}', color: 0x06b6d4, skin: 0xffe0bd, pos: {x: {$vx}, z: {$vz}}, state: 'vacation', task: 'ลาพักผ่อน ({$date_str})', rawStatus: 'ท่องเที่ยว' }";
            $vacation_count++;
        } else {
            // Personal Leave -> Private Room
            $px = -24;
            $pz = -10 + ($personal_count * 2);
            $js_characters_data[] = "{ id: {$char_id}, user_id: '{$uid}', name: '{$name}', job: '{$job}', color: 0x8b5cf6, skin: 0xffe0bd, pos: {x: {$px}, z: {$pz}}, state: 'personal', task: 'ลากิจ ({$date_str})', rawStatus: 'ธุระส่วนตัว' }";
            $personal_count++;
        }
    } elseif (isset($trip_users[$uid])) {
        // Trip
        $td = $trip_users[$uid];
        $date_str = date('d/m/y', strtotime($td['start'])) . ' - ' . date('d/m/y', strtotime($td['end']));
        $js_characters_data[] = "{ id: {$char_id}, user_id: '{$uid}', name: '{$name}', job: '{$job}', color: 0xf59e0b, skin: 0xf1c27d, pos: {x: 24, z: 4}, state: 'vanished', task: 'ไปราชการ/อบรม ({$date_str})', rawStatus: 'อยู่นอกสถานที่' }";
    } else {
        $task_info = $work_logs[$uid] ?? null;
        $task = $task_info ? addslashes(str_replace(["\r", "\n"], ' ', mb_substr($task_info['task_name'], 0, 50))) : 'ปฏิบัติงานประจำวัน';
        $desc = $task_info ? $task_info['descriptions'] : '';
        
        $is_active_tech = in_array($uid, $active_technicians);
        $is_maintenance = $is_active_tech || strpos($task, 'ซ่อม') !== false || strpos($task, 'อาคาร') !== false || strpos($task, 'สถานที่') !== false || strpos($task, 'ทำความสะอาด') !== false || strpos($desc, 'ซ่อม') !== false || strpos($desc, 'อาคาร') !== false || strpos($desc, 'สถานที่') !== false || strpos($desc, 'ทำความสะอาด') !== false;
        
        $is_it = strpos($task, 'พัฒนาระบบ') !== false || strpos($task, 'สารสนเทศ') !== false || strpos($task, 'เว็บไซต์') !== false || strpos($task, 'โปรแกรม') !== false || strpos($task, 'คอมพิวเตอร์') !== false || strpos($desc, 'พัฒนาระบบ') !== false || strpos($desc, 'สารสนเทศ') !== false || strpos($desc, 'เว็บไซต์') !== false || strpos($desc, 'โปรแกรม') !== false || strpos($desc, 'คอมพิวเตอร์') !== false;

        if (strpos($task, 'ประชุม') !== false || strpos($task, 'โครงการ') !== false || strpos($desc, 'ประชุม') !== false || strpos($desc, 'โครงการ') !== false) {
            $state = 'meeting';
            $randX = rand(-20, -12);
            $randZ = rand(9, 15);
            $rawStatus = 'ประชุม/โครงการ';
        } elseif ($is_maintenance) {
            $state = 'maintenance';
            $randX = rand(12, 20);
            $randZ = rand(-13, -7);
            $rawStatus = 'อาคาร/ซ่อมบำรุง';
        } elseif ($is_it) {
            $state = 'it';
            $randX = rand(12, 20);
            $randZ = rand(9, 15);
            $rawStatus = 'พัฒนาระบบสารสนเทศ';
        } else {
            $state = 'roam';
            $randX = rand(-6, 6);
            $randZ = rand(-6, 6);
            $rawStatus = 'ปฏิบัติงาน';
        }
        
        $js_characters_data[] = "{ id: {$char_id}, user_id: '{$uid}', name: '{$name}', job: '{$job}', color: {$color}, skin: 0xffdbac, pos: {x: {$randX}, z: {$randZ}}, state: '{$state}', task: '{$task}', rawStatus: '{$rawStatus}' }";
    }
    $char_id++;
}

$js_characters_array_string = "[\n" . implode(",\n", $js_characters_data) . "\n]";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSU Music Connect 3D</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- ThreeJS & Tween for Animations -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tween.js/18.6.4/tween.umd.js"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', 'Noto Sans Thai', sans-serif;
            background-color: #f8fafc; /* Slate 50 - Soft Clean Modern */
            color: #334155; /* Slate 700 */
            overflow-x: hidden;
        }

        /* Glassmorphism Panel style - Light Mode */
        .premium-panel {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(226, 232, 240, 0.8); /* Slate 200 */
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03), inset 0 1px 1px rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }
        .premium-panel:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
        }

        /* Module Icon Container */
        .module-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }

        /* Webkit scrollbar customized (Light) */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .blink-dot { animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
    </style>
</head>
<body class="p-4 md:p-6 min-h-screen flex flex-col justify-between">

    <!-- Top Executive Header -->
    <header class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6 pb-4 border-b border-slate-200">
        <div class="flex items-center gap-4">
            <!-- Back Button -->
            <a href="index.php" class="bg-white border border-slate-200 text-slate-600 hover:text-indigo-600 hover:border-indigo-300 rounded-full w-10 h-10 flex items-center justify-center shadow-sm transition">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 blink-dot shadow-[0_0_8px_rgba(16,185,129,0.5)]"></span>
                    <h1 class="text-xl md:text-2xl font-bold tracking-wide text-slate-800">MSU Music Connect 3D</h1>
                </div>
                <p class="text-sm text-slate-500 mt-1">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม • Live 3D Operations Dash</p>
            </div>
        </div>
        <div class="flex items-center gap-3 bg-white border border-slate-200 shadow-sm px-4 py-2 rounded-lg">
            <span class="text-[10px] text-slate-500 font-semibold tracking-wider uppercase">SERVER TIME:</span>
            <span id="digital-clock" class="text-sm font-bold text-indigo-600 font-mono">00:00:00</span>
            
            <form method="GET" id="viewForm" class="ml-2 mb-0">
                <select name="view" onchange="document.getElementById('viewForm').submit()" class="text-xs bg-slate-50 border border-slate-200 text-slate-700 py-1 px-2 rounded outline-none focus:border-indigo-400 cursor-pointer">
                    <option value="day" <?php if($view_mode=='day') echo 'selected'; ?>>มุมมอง: วันนี้</option>
                    <option value="week" <?php if($view_mode=='week') echo 'selected'; ?>>มุมมอง: สัปดาห์นี้</option>
                    <option value="month" <?php if($view_mode=='month') echo 'selected'; ?>>มุมมอง: เดือนนี้</option>
                </select>
            </form>

            <a href="executive_dashboard.php" class="ml-1 text-xs bg-indigo-50 text-indigo-600 hover:bg-indigo-100 font-medium py-1.5 px-3 rounded-full transition">Standard Dash</a>
        </div>
    </header>

    <!-- Executive KPI Grid Summary -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Card 1: Personnel & Leave -->
        <div class="premium-panel p-4 flex items-center gap-4 cursor-pointer hover:border-blue-300" onclick="window.location.href='modules/leave/approve_list.php'">
            <div class="module-icon bg-blue-50 text-blue-500"><i class="fa-solid fa-user-minus"></i></div>
            <div class="flex-grow">
                <span class="text-xs text-slate-500 font-semibold tracking-wider block">การลาพัก / HR</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-2xl font-bold text-slate-800"><?php echo $leaves_scope; ?></span>
                    <span class="text-xs text-slate-400">คนลา<?php echo $scope_label; ?></span>
                </div>
                <div class="text-[10px] <?php echo $pending_leaves_count>0 ? 'text-rose-500 font-bold' : 'text-slate-400'; ?> mt-1">
                    รออนุมัติ: <?php echo $pending_leaves_count; ?> รายการ
                </div>
            </div>
        </div>

        <!-- Card 2: Training & Rooms -->
        <div class="premium-panel p-4 flex items-center gap-4 cursor-pointer hover:border-emerald-300" onclick="window.location.href='modules/trainings/dashboard_admin.php'">
            <div class="module-icon bg-emerald-50 text-emerald-500"><i class="fa-solid fa-car-side"></i></div>
            <div class="flex-grow">
                <span class="text-xs text-slate-500 font-semibold tracking-wider block">ไปราชการ & ใช้ห้อง</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-2xl font-bold text-slate-800"><?php echo $training_scope; ?></span>
                    <span class="text-xs text-slate-400">คนเดินทาง</span>
                </div>
                <div class="text-[10px] text-slate-400 mt-1">
                    ใช้ห้อง<?php echo $scope_label; ?>: <?php echo $rooms_scope; ?> (รอ <?php echo $pending_rooms; ?>)
                </div>
            </div>
        </div>

        <!-- Card 3: Finance & Repairs -->
        <div class="premium-panel p-4 flex items-center gap-4 cursor-pointer hover:border-amber-300" onclick="window.location.href='modules/finance/index.php'">
            <div class="module-icon bg-amber-50 text-amber-500"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div class="flex-grow">
                <span class="text-xs text-slate-500 font-semibold tracking-wider block">การเงิน & พัสดุ/ซ่อม</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-2xl font-bold text-slate-800">฿<?php echo number_format($finance_pending_amount); ?></span>
                    <span class="text-xs text-slate-400">รอเบิก</span>
                </div>
                <div class="text-[10px] <?php echo $pending_repairs>0 ? 'text-rose-500 font-bold' : 'text-slate-400'; ?> mt-1">
                    แจ้งซ่อมรอดำเนินการ: <?php echo $pending_repairs; ?>
                </div>
            </div>
        </div>

        <!-- Card 4: Academics & Research -->
        <div class="premium-panel p-4 flex items-center gap-4 cursor-pointer hover:border-purple-300" onclick="window.location.href='modules/projects/index.php'">
            <div class="module-icon bg-purple-50 text-purple-500"><i class="fa-solid fa-diagram-project"></i></div>
            <div class="flex-grow">
                <span class="text-xs text-slate-500 font-semibold tracking-wider block">แผนงาน & วิชาการ</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-2xl font-bold text-slate-800"><?php echo $proj_delayed; ?></span>
                    <span class="text-xs text-slate-400">โครงการล่าช้า</span>
                </div>
                <div class="text-[10px] text-slate-400 mt-1">
                    ผลงานวิจัย: <?php echo $total_research; ?> | ร้องเรียน: <?php echo $pending_complaints; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Interactive Workspace -->
    <main class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch flex-grow pb-6">
        
        <!-- LEFT SIDE: Personnel Directory (6/12 cols) -->
        <section class="lg:col-span-6 flex flex-col gap-6">
            
            <!-- Highly Readable Directory List of College Staff -->
            <div class="premium-panel p-5 flex-grow flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-center border-b border-slate-200 pb-3 mb-4">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-users-viewfinder text-indigo-500 text-lg"></i>
                            <h2 class="text-base font-bold text-slate-700">Live Personnel Tracking</h2>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-2.5 top-2 text-slate-400 text-xs"></i>
                                <input type="text" id="personnelSearch" onkeyup="filterPersonnelTable()" placeholder="ค้นหาชื่อ, สถานะ..." class="pl-7 pr-3 py-1.5 text-xs border border-slate-200 rounded-md focus:outline-none focus:border-indigo-400 w-36 shadow-sm transition-all">
                            </div>
                            <span class="text-xs text-slate-400 bg-slate-100 px-2 py-1.5 rounded hidden sm:inline-block">คลิกชื่อซูม 3D</span>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto" style="max-height: 380px; overflow-y: auto;">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="sticky top-0 bg-white/90 backdrop-blur-sm shadow-sm z-10">
                                <tr class="text-slate-500 uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-3">ชื่อบุคลากร</th>
                                    <th class="py-3 px-3 text-center">สถานภาพ</th>
                                    <th class="py-3 px-3">สถานะงาน / ภารกิจ</th>
                                </tr>
                            </thead>
                            <tbody id="directory-tbody" class="divide-y divide-slate-100">
                                <!-- Generated Dynamically by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Live Notification Console -->
                <div class="mt-4 p-3 bg-indigo-50 border border-indigo-100 rounded-lg flex gap-3 items-center">
                    <div class="text-[10px] bg-indigo-600 text-white px-2 py-1 rounded font-bold shrink-0">SYSTEM LOG</div>
                    <div id="system-log-text" class="text-xs text-indigo-900 font-medium">ยินดีต้อนรับสู่ 3D Command Center ข้อมูลดึงจาก Database จริง...</div>
                </div>
            </div>
            
            <!-- Quick System Links (Modules) -->
            <div class="premium-panel p-4">
                <h2 class="text-xs font-bold tracking-wider text-slate-500 uppercase mb-3">Quick System Access</h2>
                <div class="flex flex-wrap gap-2">
                    <a href="modules/worklog/admin_dashboard.php" class="text-[11px] font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 py-1.5 px-3 rounded-full transition"><i class="fa-solid fa-heart-pulse text-rose-500 mr-1"></i> Org Mood (<?php echo $avg_mood; ?>)</a>
                    <a href="modules/course_requests/index.php" class="text-[11px] font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 py-1.5 px-3 rounded-full transition"><i class="fa-solid fa-graduation-cap text-purple-500 mr-1"></i> งานวิชาการ/TQF</a>
                    <a href="modules/complaints/index.php" class="text-[11px] font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 py-1.5 px-3 rounded-full transition"><i class="fa-solid fa-bullhorn text-orange-500 mr-1"></i> ร้องเรียน</a>
                    <a href="modules/research/index.php" class="text-[11px] font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 py-1.5 px-3 rounded-full transition"><i class="fa-solid fa-microscope text-indigo-500 mr-1"></i> ฐานข้อมูลวิจัย</a>
                </div>
            </div>
        </section>

        <!-- RIGHT SIDE: 3D Campus Monitor (6/12 cols) -->
        <section class="lg:col-span-6 flex flex-col gap-6">
            
            <!-- Live Monitor Box -->
            <div class="premium-panel p-4 flex flex-col h-[550px] lg:h-auto lg:flex-grow relative overflow-hidden">
                <div class="flex justify-between items-center pb-3 mb-3 border-b border-slate-200 z-10">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse shadow-[0_0_5px_#f43f5e]"></span>
                        <h2 class="text-sm font-bold text-slate-700">3D LIVE MONITORING</h2>
                    </div>
                    <!-- Quick Cameras -->
                    <div class="flex gap-1.5 text-[10px]">
                        <button onclick="focusOnBed()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">พยาบาล</button>
                        <button onclick="focusOnVan()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">รถตู้</button>
                        <button onclick="focusOnRunway()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">เครื่องบิน</button>
                        <button onclick="focusOnRoom()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">ห้องส่วนตัว</button>
                        <button onclick="focusOnSupply()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">ติดตามพัสดุ</button>
                        <button onclick="focusOnMaintenance()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">ซ่อมบำรุง</button>
                        <button onclick="focusOnIT()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">สารสนเทศ</button>
                        <button onclick="focusOnProjects()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">ภาพรวมโครงการ</button>
                        <button onclick="focusOnMeeting()" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 py-1.5 px-2 rounded-md font-semibold shadow-sm transition">เข้าประชุม</button>
                        <button onclick="resetView()" class="bg-indigo-600 hover:bg-indigo-700 text-white py-1.5 px-2 rounded-md font-semibold shadow-sm transition">รวม</button>
                    </div>
                </div>

                <!-- Monitor Frame Container for Three.js -->
                <div class="flex-grow w-full h-full relative rounded-xl overflow-hidden border border-slate-200 bg-slate-50 shadow-inner" id="threejs-monitor-frame">
                    <!-- Canvas appended here via JS -->
                    <div id="canvas-container" class="w-full h-full"></div>
                </div>
                
                <div class="absolute bottom-6 right-6 z-10 pointer-events-none bg-white/90 backdrop-blur px-3 py-1.5 rounded-md text-[10px] text-slate-500 flex items-center gap-2 font-mono shadow-sm border border-slate-200">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full blink-dot"></span>
                    CAM-01 ACTIVE
                </div>
            </div>

        </section>
    </main>

    <!-- User Profile Modal -->
    <div id="userProfileModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm flex items-center justify-center opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-2xl shadow-2xl w-11/12 max-w-6xl mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="userProfileModalContent">
            <div class="p-8">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-xl font-bold text-slate-800">โปรไฟล์บุคลากร (ประจำเดือน)</h3>
                    <button onclick="closeProfile()" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="profileLoading" class="py-10 text-center text-slate-500">
                    <i class="fas fa-spinner fa-spin text-3xl mb-3 text-indigo-500"></i>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
                <div id="profileContent" class="hidden">
                    <div class="flex items-center gap-4 mb-6 pb-6 border-b border-slate-100">
                        <div class="w-16 h-16 rounded-full bg-slate-200 overflow-hidden">
                            <img id="profImg" src="" alt="Profile" class="w-full h-full object-cover">
                        </div>
                        <div>
                            <h4 id="profName" class="text-lg font-bold text-slate-800"></h4>
                            <p id="profJob" class="text-sm text-slate-500"></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-4 sm:grid-cols-7 gap-3 mb-6">
                        <div class="bg-indigo-50 rounded-xl py-3 px-2 text-center border border-indigo-100 shadow-sm">
                            <div class="text-indigo-600 text-xs font-bold mb-1"><i class="fas fa-tasks"></i> งาน</div>
                            <div id="profWork" class="text-2xl font-bold text-indigo-700">0</div>
                        </div>
                        <div class="bg-emerald-50 rounded-xl py-3 px-2 text-center border border-emerald-100 shadow-sm">
                            <div class="text-emerald-600 text-xs font-bold mb-1"><i class="fas fa-folder-open"></i> โปรเจกต์</div>
                            <div id="profProj" class="text-2xl font-bold text-emerald-700">0</div>
                        </div>
                        <div class="bg-cyan-50 rounded-xl py-3 px-2 text-center border border-cyan-100 shadow-sm">
                            <div class="text-cyan-600 text-xs font-bold mb-1"><i class="fas fa-file-invoice-dollar"></i> การเงิน</div>
                            <div id="profFinance" class="text-2xl font-bold text-cyan-700">0</div>
                        </div>
                        <div class="bg-amber-50 rounded-xl py-3 px-2 text-center border border-amber-100 shadow-sm">
                            <div class="text-amber-600 text-xs font-bold mb-1"><i class="fas fa-car-side"></i> ราชการ</div>
                            <div id="profTrip" class="text-2xl font-bold text-amber-700">0</div>
                        </div>
                        <div class="bg-rose-50 rounded-xl py-3 px-2 text-center border border-rose-100 shadow-sm">
                            <div class="text-rose-600 text-xs font-bold mb-1"><i class="fas fa-procedures"></i> ลา</div>
                            <div id="profLeave" class="text-2xl font-bold text-rose-700">0</div>
                        </div>
                        <div class="bg-fuchsia-50 rounded-xl py-3 px-2 text-center border border-fuchsia-100 shadow-sm">
                            <div class="text-fuchsia-600 text-xs font-bold mb-1"><i class="fas fa-door-open"></i> จองห้อง</div>
                            <div id="profRoom" class="text-2xl font-bold text-fuchsia-700">0</div>
                        </div>
                        <div class="bg-orange-50 rounded-xl py-3 px-2 text-center border border-orange-100 shadow-sm">
                            <div class="text-orange-600 text-xs font-bold mb-1"><i class="fas fa-screwdriver-wrench"></i> แจ้งซ่อม</div>
                            <div id="profRepair" class="text-2xl font-bold text-orange-700">0</div>
                        </div>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 pb-2 mb-4">
                        <h5 id="profRecordsTitle" class="font-bold text-slate-700 text-base m-0">ความรับผิดชอบและบันทึกล่าสุด</h5>
                        <div class="flex bg-slate-100 rounded-lg p-1">
                            <button onclick="changeProfileFilter('day')" id="btnFilterDay" class="px-3 py-1 text-xs font-bold rounded-md bg-white text-indigo-600 shadow-sm transition-all">วัน</button>
                            <button onclick="changeProfileFilter('week')" id="btnFilterWeek" class="px-3 py-1 text-xs font-bold rounded-md text-slate-500 hover:text-slate-700 transition-all">สัปดาห์</button>
                            <button onclick="changeProfileFilter('month')" id="btnFilterMonth" class="px-3 py-1 text-xs font-bold rounded-md text-slate-500 hover:text-slate-700 transition-all">เดือน</button>
                        </div>
                    </div>
                    <div id="profWorkList" class="space-y-3 max-h-[50vh] overflow-y-auto pr-4 text-sm text-slate-600">
                        <!-- Items -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JS Code and Simulations Logic -->
    <script>
        // Load PHP data into JS Array
        const charactersData = <?php echo $js_characters_array_string; ?>;
        const projStatusData = <?php echo json_encode($proj_status); ?>;
        const suppliesData3D = <?php echo json_encode($supplies_data_3d); ?>;

        let selectedCharId = null;
        let characterMeshes = [];
        let zzzParticles = [];

        // Setup Three.js confined inside its container
        const monitorFrame = document.getElementById('threejs-monitor-frame');
        const canvasContainer = document.getElementById('canvas-container');
        
        const scene = new THREE.Scene();
        // Light clean background
        scene.background = new THREE.Color(0xf1f5f9); 
        // scene.fog = new THREE.Fog(0xf1f5f9, 30, 90); // Removed per user request

        const camera = new THREE.PerspectiveCamera(40, canvasContainer.clientWidth / canvasContainer.clientHeight, 0.1, 100);
        // Position camera to see the ENTIRE scene clearly
        camera.position.set(0, 30, 42);
        camera.lookAt(0, 0, -10);

        const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setSize(canvasContainer.clientWidth, canvasContainer.clientHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        canvasContainer.appendChild(renderer.domElement);

        // --- LIGHTING ---
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.8);
        scene.add(ambientLight);

        const dirLight = new THREE.DirectionalLight(0xffffff, 0.6);
        dirLight.position.set(10, 20, 10);
        dirLight.castShadow = true;
        dirLight.shadow.mapSize.width = 1024;
        dirLight.shadow.mapSize.height = 1024;
        dirLight.shadow.camera.left = -15;
        dirLight.shadow.camera.right = 15;
        dirLight.shadow.camera.top = 15;
        dirLight.shadow.camera.bottom = -15;
        scene.add(dirLight);

        // --- 3D ENVIRONMENT TERRAIN (MUSIC REHEARSAL ROOM) ---
        
        // Darker Grid for Studio Vibe
        const gridHelper = new THREE.GridHelper(40, 40, 0x94a3b8, 0xcbd5e1);
        gridHelper.position.y = -0.15;
        scene.add(gridHelper);

        // Wooden Floor (Stage Base)
        const floorGeo = new THREE.PlaneGeometry(24, 20);
        const floorMat = new THREE.MeshStandardMaterial({ color: 0xd97706, roughness: 0.8, depthWrite: false }); // Amber/Wood color
        const floorMesh = new THREE.Mesh(floorGeo, floorMat);
        floorMesh.rotation.x = -Math.PI / 2;
        floorMesh.position.y = -0.14;
        floorMesh.receiveShadow = true;
        scene.add(floorMesh);

        // Main Stage Platform
        const stageGeo = new THREE.BoxGeometry(16, 0.4, 10);
        const stageMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 }); // Slate 800
        const stageMesh = new THREE.Mesh(stageGeo, stageMat);
        stageMesh.position.set(0, 0.05, -3);
        stageMesh.receiveShadow = true;
        scene.add(stageMesh);

        // --- MUSIC INSTRUMENTS ---
        
        // 1. Drum Set (Center Stage)
        const drumGroup = new THREE.Group();
        drumGroup.position.set(0, 0.3, -1); // Moved forward to avoid cabinets
        const drumMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9 }); // White Pearl
        const cymbalMat = new THREE.MeshStandardMaterial({ color: 0xfcd34d, metalness: 0.8, roughness: 0.2 }); // Gold/Brass
        
        // Bass Drum
        const bassDrum = new THREE.Mesh(new THREE.CylinderGeometry(0.8, 0.8, 1.2, 16), drumMat);
        bassDrum.rotation.z = Math.PI / 2;
        bassDrum.position.set(0, 0.8, 0);
        bassDrum.castShadow = true;
        drumGroup.add(bassDrum);
        
        // Snare
        const snare = new THREE.Mesh(new THREE.CylinderGeometry(0.4, 0.4, 0.3, 16), drumMat);
        snare.position.set(-1, 0.9, 0.5);
        snare.castShadow = true;
        drumGroup.add(snare);

        // Floor Tom
        const floorTom = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.5, 0.8, 16), drumMat);
        floorTom.position.set(1.2, 0.6, 0.5);
        floorTom.castShadow = true;
        drumGroup.add(floorTom);

        // Cymbal
        const cymbal = new THREE.Mesh(new THREE.CylinderGeometry(0.6, 0.6, 0.05, 16), cymbalMat);
        cymbal.position.set(-1.2, 1.6, -0.2);
        cymbal.rotation.x = 0.2;
        cymbal.rotation.z = -0.2;
        cymbal.castShadow = true;
        drumGroup.add(cymbal);
        
        // Cymbal Stand
        const stand = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 1.6, 8), new THREE.MeshStandardMaterial({color: 0x94a3b8}));
        stand.position.set(-1.2, 0.8, -0.2);
        drumGroup.add(stand);

        scene.add(drumGroup);

        // 2. Grand Piano (Stage Left)
        const pianoGroup = new THREE.Group();
        pianoGroup.position.set(-5, 0.3, 0); // Moved forward and left to avoid cabinets
        pianoGroup.rotation.y = Math.PI / 6;
        
        const pianoBodyMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.1 }); // Glossy Black
        const pianoBody = new THREE.Mesh(new THREE.BoxGeometry(2.5, 0.6, 3.5), pianoBodyMat);
        pianoBody.position.set(0, 1.2, 0);
        pianoBody.castShadow = true;
        pianoGroup.add(pianoBody);

        const keysMat = new THREE.MeshStandardMaterial({ color: 0xffffff });
        const keys = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.1, 0.4), keysMat);
        keys.position.set(0, 1.15, 1.8);
        pianoGroup.add(keys);

        // Piano Legs
        for(let pos of [[-1, 0.6, 1.5], [1, 0.6, 1.5], [0, 0.6, -1.2]]) {
            const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 1.2), pianoBodyMat);
            leg.position.set(pos[0], pos[1], pos[2]);
            pianoGroup.add(leg);
        }
        
        // Piano Bench
        const bench = new THREE.Mesh(new THREE.BoxGeometry(1.2, 0.2, 0.6), new THREE.MeshStandardMaterial({ color: 0x1e293b }));
        bench.position.set(0, 0.6, 2.5);
        pianoGroup.add(bench);

        scene.add(pianoGroup);

        // 3. Amplifiers / Speakers (Stage Rear)
        const ampMat = new THREE.MeshStandardMaterial({ color: 0x172033, roughness: 0.8 }); // Dark Charcoal
        const grillMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 1.0 }); // Speaker Grill
        
        const createAmp = (x, z) => {
            const ampGroup = new THREE.Group();
            ampGroup.position.set(x, 0.3, z);
            
            const box = new THREE.Mesh(new THREE.BoxGeometry(1.2, 2.0, 0.8), ampMat);
            box.position.y = 1.0;
            box.castShadow = true;
            ampGroup.add(box);
            
            const grill = new THREE.Mesh(new THREE.PlaneGeometry(1.0, 1.8), grillMat);
            grill.position.set(0, 1.0, 0.41);
            ampGroup.add(grill);
            
            scene.add(ampGroup);
        };
        createAmp(-8, -6);
        createAmp(8, -6);

        // 4. Project Status Cabinets (Stage Rear Wall)
        const createCabinet = (x, z, colorHex, label, count) => {
            const cabGroup = new THREE.Group();
            cabGroup.position.set(x, 0.3, z);
            
            // Cabinet Body
            const cabMat = new THREE.MeshStandardMaterial({ color: colorHex, roughness: 0.6, metalness: 0.2 });
            const cabGeo = new THREE.BoxGeometry(1.5, 3.0, 1.0);
            const box = new THREE.Mesh(cabGeo, cabMat);
            box.position.y = 1.5;
            box.castShadow = true;
            cabGroup.add(box);
            
            // Cabinet Door Details
            const doorMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.5, transparent: true, opacity: 0.2 });
            const doorGeo = new THREE.PlaneGeometry(1.3, 2.8);
            const door = new THREE.Mesh(doorGeo, doorMat);
            door.position.set(0, 1.5, 0.51);
            cabGroup.add(door);
            
            // Floating Label
            const canvas = document.createElement('canvas');
            canvas.width = 256; canvas.height = 128;
            const ctx = canvas.getContext('2d');
            
            // Draw background pill
            ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
            ctx.beginPath();
            ctx.roundRect(10, 20, 236, 88, 44);
            ctx.fill();
            
            // Draw Text
            ctx.fillStyle = '#' + new THREE.Color(colorHex).getHexString();
            ctx.font = 'bold 32px "Noto Sans Thai", Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(`${label}`, 128, 54);
            
            ctx.font = 'bold 36px Arial';
            ctx.fillStyle = '#334155';
            ctx.fillText(`${count}`, 128, 90);
            
            const tex = new THREE.CanvasTexture(canvas);
            const planeGeo = new THREE.PlaneGeometry(2.0, 1.0);
            const planeMat = new THREE.MeshBasicMaterial({ map: tex, transparent: true });
            const plane = new THREE.Mesh(planeGeo, planeMat);
            plane.position.set(0, 3.6, 0);
            cabGroup.add(plane);
            
            scene.add(cabGroup);
        };

        createCabinet(-6, -6, 0x10b981, 'เสร็จสมบูรณ์', projStatusData.completed);
        createCabinet(-3, -6, 0x3b82f6, 'กำลังดำเนิน', projStatusData.active);
        createCabinet(0, -6, 0xf59e0b, 'ยังไม่เริ่ม', projStatusData.not_started);
        createCabinet(3, -6, 0xef4444, 'ล่าช้า', projStatusData.delayed);

        // 5. Supply Instruments Zones
        const createZoneSign = (x, z, label1, label2, colorHex) => {
            const canvas = document.createElement('canvas');
            canvas.width = 350; canvas.height = 140;
            const ctx = canvas.getContext('2d');
            
            // Post
            const postGroup = new THREE.Group();
            postGroup.position.set(x, 0, z);
            const postMat = new THREE.MeshStandardMaterial({color: 0x94a3b8});
            const post = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 2), postMat);
            post.position.y = 1;
            postGroup.add(post);

            // Sign background
            ctx.fillStyle = '#' + new THREE.Color(colorHex).getHexString();
            ctx.beginPath();
            ctx.roundRect(10, 10, 330, 120, 20);
            ctx.fill();
            
            ctx.fillStyle = 'white';
            ctx.font = 'bold 30px "Noto Sans Thai", Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(label1, 175, 45);

            ctx.font = 'bold 44px Arial';
            ctx.fillText(label2, 175, 95);
            
            const tex = new THREE.CanvasTexture(canvas);
            const planeGeo = new THREE.PlaneGeometry(2.2, 0.9);
            const planeMat = new THREE.MeshBasicMaterial({ map: tex, transparent: true });
            const plane = new THREE.Mesh(planeGeo, planeMat);
            plane.position.set(0, 2, 0.11); // Moved forward to prevent Z-fighting and post blocking
            postGroup.add(plane);
            scene.add(postGroup);
        };

        const createInstrumentProp = (x, z) => {
            const icons = ['🎸', '🎷', '🎹', '🥁', '🎺', '🎻'];
            const icon = icons[Math.floor(Math.random() * icons.length)];
            
            const canvas = document.createElement('canvas');
            canvas.width = 128; canvas.height = 128;
            const ctx = canvas.getContext('2d');
            ctx.font = '80px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(icon, 64, 64);
            const tex = new THREE.CanvasTexture(canvas);
            const spriteMat = new THREE.SpriteMaterial({ map: tex, transparent: true, depthTest: true });
            const sprite = new THREE.Sprite(spriteMat);
            sprite.position.set(x, 0.5 + Math.random()*0.2, z);
            sprite.scale.set(1.5, 1.5, 1);
            
            // tiny shadow
            const shadowGeo = new THREE.CircleGeometry(0.4, 16);
            const shadowMat = new THREE.MeshBasicMaterial({color: 0x000000, transparent: true, opacity: 0.15});
            const shadow = new THREE.Mesh(shadowGeo, shadowMat);
            shadow.rotation.x = -Math.PI/2;
            shadow.position.set(x, 0.02, z);
            
            scene.add(shadow);
            scene.add(sprite);
        };

        let inProgressCount = 0;
        let completedCount = 0;
        
        suppliesData3D.forEach(sup => {
            if (sup.supply_status === 'issued') {
                completedCount++;
            } else {
                inProgressCount++;
            }
        });

        let totalSup = suppliesData3D.length || 1;
        let inProgPct = Math.round((inProgressCount / totalSup) * 100);
        let compPct = Math.round((completedCount / totalSup) * 100);

        // Zone 1: In Progress (closer to center, x: -4.5)
        createZoneSign(-4.5, -16.5, 'กำลังดำเนินการ', `${inProgPct}%`, 0xf59e0b); // Orange
        // Zone 2: Completed (closer to center, x: 4.5)
        createZoneSign(4.5, -16.5, 'เสร็จสมบูรณ์', `${compPct}%`, 0x10b981); // Green

        let renderedInProg = 0;
        let renderedComp = 0;
        
        suppliesData3D.forEach(sup => {
            if (sup.supply_status === 'issued') {
                const row = Math.floor(renderedComp / 4);
                const col = renderedComp % 4;
                createInstrumentProp(4.5 + (col * 1.2) - 1.8, -15.5 + (row * 1.2));
                renderedComp++;
            } else { // pending or waiting for head/supply
                const row = Math.floor(renderedInProg / 4);
                const col = renderedInProg % 4;
                createInstrumentProp(-4.5 + (col * 1.2) - 1.8, -15.5 + (row * 1.2));
                renderedInProg++;
            }
        });

        // --- BACKDROP BOARD ---
        const boardGeo = new THREE.BoxGeometry(60, 15, 0.5);
        const boardMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.7 });
        const backdropBoard = new THREE.Mesh(boardGeo, boardMat);
        backdropBoard.position.set(0, 7.5, -19.5);
        backdropBoard.receiveShadow = true;
        backdropBoard.castShadow = true;
        scene.add(backdropBoard);

        // --- MEETING & PROJECTS ZONE ---
        const meetingGroup = new THREE.Group();
        meetingGroup.position.set(-16, 0, 12);
        
        // Rug
        const meetRug = new THREE.Mesh(new THREE.CylinderGeometry(5, 5, 0.05, 32), new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.9 }));
        meetRug.position.y = 0.02;
        meetRug.receiveShadow = true;
        meetingGroup.add(meetRug);

        // Table
        const tableTop = new THREE.Mesh(new THREE.BoxGeometry(6, 0.2, 2.5), new THREE.MeshStandardMaterial({ color: 0x64748b }));
        tableTop.position.y = 1.0;
        tableTop.castShadow = true;
        meetingGroup.add(tableTop);
        
        const legGeo = new THREE.CylinderGeometry(0.1, 0.1, 1.0);
        const legMat = new THREE.MeshStandardMaterial({ color: 0x334155 });
        [[-2.8, -1.0], [2.8, -1.0], [-2.8, 1.0], [2.8, 1.0]].forEach(pos => {
            const leg = new THREE.Mesh(legGeo, legMat);
            leg.position.set(pos[0], 0.5, pos[1]);
            leg.castShadow = true;
            meetingGroup.add(leg);
        });

        // Add a blackboard
        const wbGeo = new THREE.BoxGeometry(0.1, 2.5, 3.5);
        const wbMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.8 });
        const whiteboard = new THREE.Mesh(wbGeo, wbMat);
        whiteboard.position.set(-4.5, 1.5, 0);
        meetingGroup.add(whiteboard);
        
        const wbStandGeo = new THREE.CylinderGeometry(0.1, 0.1, 1.5);
        const wbStandMat = new THREE.MeshStandardMaterial({ color: 0x475569 });
        const wbStandL = new THREE.Mesh(wbStandGeo, wbStandMat);
        wbStandL.position.set(-4.5, 0.75, -1.6);
        meetingGroup.add(wbStandL);
        const wbStandR = new THREE.Mesh(wbStandGeo, wbStandMat);
        wbStandR.position.set(-4.5, 0.75, 1.6);
        meetingGroup.add(wbStandR);

        // Label
        const mtCanvas = document.createElement('canvas');
        mtCanvas.width = 256; mtCanvas.height = 64;
        const mtCtx = mtCanvas.getContext('2d');
        mtCtx.fillStyle = 'rgba(255, 255, 255, 0.7)';
        mtCtx.beginPath();
        mtCtx.roundRect(0, 0, 256, 64, 10);
        mtCtx.fill();
        mtCtx.fillStyle = '#475569';
        mtCtx.font = 'bold 24px "Noto Sans Thai", Arial';
        mtCtx.textAlign = 'center';
        mtCtx.textBaseline = 'middle';
        mtCtx.fillText('โซนห้องประชุม/โครงการ', 128, 32);
        
        const mtTex = new THREE.CanvasTexture(mtCanvas);
        const mtPlane = new THREE.Mesh(new THREE.PlaneGeometry(3.5, 0.8), new THREE.MeshBasicMaterial({ map: mtTex, transparent: true }));
        mtPlane.position.set(0, 0.05, 5);
        mtPlane.rotation.x = -Math.PI / 2;
        meetingGroup.add(mtPlane);

        scene.add(meetingGroup);
        
        // --- MAINTENANCE ZONE ---
        const maintGroup = new THREE.Group();
        maintGroup.position.set(16, 0, -10);
        
        // Rug
        const maintRug = new THREE.Mesh(new THREE.CylinderGeometry(5, 5, 0.05, 32), new THREE.MeshStandardMaterial({ color: 0xffedd5, roughness: 0.9 })); 
        maintRug.position.y = 0.02;
        maintRug.receiveShadow = true;
        maintGroup.add(maintRug);

        // Scaffold 
        const scaffoldMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8 });
        const leg1 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 4), scaffoldMat);
        leg1.position.set(-1, 2, -1);
        maintGroup.add(leg1);
        const leg2 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 4), scaffoldMat);
        leg2.position.set(1, 2, -1);
        maintGroup.add(leg2);
        const leg3 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 4), scaffoldMat);
        leg3.position.set(-1, 2, 1);
        maintGroup.add(leg3);
        const leg4 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 4), scaffoldMat);
        leg4.position.set(1, 2, 1);
        maintGroup.add(leg4);
        
        for(let i=0.5; i<4; i+=0.8) {
            const cross1 = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 2), scaffoldMat);
            cross1.rotation.z = Math.PI / 2;
            cross1.position.set(0, i, -1);
            maintGroup.add(cross1);
            
            const cross2 = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 2), scaffoldMat);
            cross2.rotation.z = Math.PI / 2;
            cross2.position.set(0, i, 1);
            maintGroup.add(cross2);
        }
        
        // Platform
        const plat = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.1, 2.2), new THREE.MeshStandardMaterial({ color: 0x854d0e }));
        plat.position.set(0, 3.5, 0);
        maintGroup.add(plat);

        // Toolbox
        const toolbox = new THREE.Mesh(new THREE.BoxGeometry(1, 0.6, 0.5), new THREE.MeshStandardMaterial({ color: 0xdc2626 })); 
        toolbox.position.set(2.5, 0.3, 2);
        toolbox.castShadow = true;
        maintGroup.add(toolbox);

        // Label
        const mnCanvas = document.createElement('canvas');
        mnCanvas.width = 256; mnCanvas.height = 64;
        const mnCtx = mnCanvas.getContext('2d');
        mnCtx.fillStyle = 'rgba(255, 255, 255, 0.7)';
        mnCtx.beginPath();
        mnCtx.roundRect(0, 0, 256, 64, 10);
        mnCtx.fill();
        mnCtx.fillStyle = '#c2410c'; 
        mnCtx.font = 'bold 24px "Noto Sans Thai", Arial';
        mnCtx.textAlign = 'center';
        mnCtx.textBaseline = 'middle';
        mnCtx.fillText('โซนอาคาร/ซ่อมบำรุง', 128, 32);
        
        const mnTex = new THREE.CanvasTexture(mnCanvas);
        const mnPlane = new THREE.Mesh(new THREE.PlaneGeometry(3.5, 0.8), new THREE.MeshBasicMaterial({ map: mnTex, transparent: true }));
        mnPlane.position.set(0, 0.05, 4);
        mnPlane.rotation.x = -Math.PI / 2;
        maintGroup.add(mnPlane);

        scene.add(maintGroup);

        // --- IT ZONE (พัฒนาระบบสารสนเทศ) ---
        const itGroup = new THREE.Group();
        itGroup.position.set(16, 0, 12);
        
        // Rug
        const itRug = new THREE.Mesh(new THREE.CylinderGeometry(4.5, 4.5, 0.05, 32), new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.9 })); 
        itRug.position.y = 0.02;
        itRug.receiveShadow = true;
        itGroup.add(itRug);

        // Desk
        const itDeskTop = new THREE.Mesh(new THREE.BoxGeometry(4, 0.15, 2), new THREE.MeshStandardMaterial({ color: 0xcbd5e1 }));
        itDeskTop.position.y = 1.0;
        itDeskTop.castShadow = true;
        itGroup.add(itDeskTop);
        
        const itLegGeo = new THREE.CylinderGeometry(0.1, 0.1, 1.0);
        const itLegMat = new THREE.MeshStandardMaterial({ color: 0x475569 });
        [[-1.8, -0.8], [1.8, -0.8], [-1.8, 0.8], [1.8, 0.8]].forEach(pos => {
            const leg = new THREE.Mesh(itLegGeo, itLegMat);
            leg.position.set(pos[0], 0.5, pos[1]);
            leg.castShadow = true;
            itGroup.add(leg);
        });

        // iMac (Monitor)
        const imacStand = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.5, 0.2), new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }));
        imacStand.position.set(0, 1.25, -0.4);
        imacStand.castShadow = true;
        itGroup.add(imacStand);
        
        const imacBase = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.05, 0.4), new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }));
        imacBase.position.set(0, 1.1, -0.4);
        itGroup.add(imacBase);

        const imacScreenGeo = new THREE.BoxGeometry(2.0, 1.2, 0.1);
        const imacScreenMat = [
            new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }), 
            new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }), 
            new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }), 
            new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }), 
            new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.1, metalness: 0.8 }), // Front screen
            new THREE.MeshStandardMaterial({ color: 0xe2e8f0 })  
        ];
        const imacScreen = new THREE.Mesh(imacScreenGeo, imacScreenMat);
        imacScreen.position.set(0, 1.6, -0.35);
        imacScreen.rotation.x = -0.05;
        imacScreen.castShadow = true;
        itGroup.add(imacScreen);
        
        // Keyboard & Mouse
        const kb = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.02, 0.3), new THREE.MeshStandardMaterial({ color: 0xffffff }));
        kb.position.set(0, 1.08, 0.3);
        itGroup.add(kb);
        const ms = new THREE.Mesh(new THREE.BoxGeometry(0.15, 0.03, 0.2), new THREE.MeshStandardMaterial({ color: 0xffffff }));
        ms.position.set(0.7, 1.08, 0.3);
        itGroup.add(ms);

        // Office Chair
        const chair = new THREE.Group();
        chair.position.set(0, 0, 1.2);
        const seat = new THREE.Mesh(new THREE.BoxGeometry(1, 0.15, 1), new THREE.MeshStandardMaterial({ color: 0x1e1b4b }));
        seat.position.y = 0.5;
        chair.add(seat);
        const backrest = new THREE.Mesh(new THREE.BoxGeometry(1, 0.8, 0.15), new THREE.MeshStandardMaterial({ color: 0x1e1b4b }));
        backrest.position.set(0, 0.9, 0.4);
        chair.add(backrest);
        const chairLeg = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 0.5), new THREE.MeshStandardMaterial({ color: 0x111111 }));
        chairLeg.position.y = 0.25;
        chair.add(chairLeg);
        itGroup.add(chair);

        // Label
        const itCanvas = document.createElement('canvas');
        itCanvas.width = 256; itCanvas.height = 64;
        const itCtx = itCanvas.getContext('2d');
        itCtx.fillStyle = 'rgba(255, 255, 255, 0.7)';
        itCtx.beginPath();
        itCtx.roundRect(0, 0, 256, 64, 10);
        itCtx.fill();
        itCtx.fillStyle = '#4f46e5'; // indigo-600
        itCtx.font = 'bold 22px "Noto Sans Thai", Arial';
        itCtx.textAlign = 'center';
        itCtx.textBaseline = 'middle';
        itCtx.fillText('งานพัฒนาระบบสารสนเทศ', 128, 32);
        
        const itTex = new THREE.CanvasTexture(itCanvas);
        const itPlane = new THREE.Mesh(new THREE.PlaneGeometry(3.5, 0.8), new THREE.MeshBasicMaterial({ map: itTex, transparent: true }));
        itPlane.position.set(0, 0.05, 4);
        itPlane.rotation.x = -Math.PI / 2;
        itGroup.add(itPlane);

        scene.add(itGroup);

        const bdCanvas = document.createElement('canvas');
        bdCanvas.width = 4096; bdCanvas.height = 1024;
        const bdCtx = bdCanvas.getContext('2d');
        
        bdCtx.fillStyle = '#ffffff';
        bdCtx.font = 'Bold 200px Arial';
        bdCtx.textAlign = 'center';
        bdCtx.fillText('College of Music', 2048, 450);
        
        bdCtx.font = '100px Arial';
        bdCtx.fillStyle = '#94a3b8';
        bdCtx.fillText('Mahasarakham University. Thailand', 2048, 700);
        
        const bdTexture = new THREE.CanvasTexture(bdCanvas);
        const textPlaneGeo = new THREE.PlaneGeometry(50, 12.5);
        const textPlaneMat = new THREE.MeshBasicMaterial({ map: bdTexture, transparent: true });
        const textPlane = new THREE.Mesh(textPlaneGeo, textPlaneMat);
        textPlane.position.set(0, 7.5, -19.24);
        scene.add(textPlane);

        // Garage/Road (For Van - Moved right)
        const roadGeo = new THREE.BoxGeometry(6, 0.05, 40);
        const roadMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.95 });
        const road = new THREE.Mesh(roadGeo, roadMat);
        road.position.set(16, -0.12, 0);
        road.receiveShadow = true;
        scene.add(road);

        // --- AIRPLANE RUNWAY (For Vacation) ---
        const runwayGroup = new THREE.Group();
        runwayGroup.position.set(26, -0.14, 0);
        
        // Tarmac
        const tarmac = new THREE.Mesh(new THREE.PlaneGeometry(8, 40), new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 1.0, depthWrite: false }));
        tarmac.rotation.x = -Math.PI / 2;
        tarmac.receiveShadow = true;
        runwayGroup.add(tarmac);
        
        // Dashed Center Line
        for(let i=-18; i<=18; i+=4) {
            const dash = new THREE.Mesh(new THREE.PlaneGeometry(0.4, 2), new THREE.MeshStandardMaterial({ color: 0xffffff, depthWrite: false }));
            dash.rotation.x = -Math.PI / 2;
            dash.position.set(0, 0.02, i);
            runwayGroup.add(dash);
        }
        
        // Small Airplane (Prop)
        const plane = new THREE.Group();
        plane.position.set(0, 2, -12);
        
        const fuselage = new THREE.Mesh(new THREE.CylinderGeometry(1, 1, 6, 16), new THREE.MeshStandardMaterial({ color: 0xffffff }));
        fuselage.rotation.x = Math.PI / 2;
        fuselage.castShadow = true;
        plane.add(fuselage);
        
        const wings = new THREE.Mesh(new THREE.BoxGeometry(10, 0.2, 2), new THREE.MeshStandardMaterial({ color: 0x38bdf8 }));
        wings.position.set(0, 0, 0);
        wings.castShadow = true;
        plane.add(wings);
        
        const tail = new THREE.Mesh(new THREE.BoxGeometry(0.2, 2, 2), new THREE.MeshStandardMaterial({ color: 0x38bdf8 }));
        tail.position.set(0, 1, 2.5);
        plane.add(tail);
        
        runwayGroup.add(plane);
        scene.add(runwayGroup);
        
        // --- PRIVATE ROOM (For Personal Leave) ---
        const roomGroup = new THREE.Group();
        roomGroup.position.set(-24, 0, -10);
        
        // Floor
        const roomFloor = new THREE.Mesh(new THREE.BoxGeometry(8, 0.2, 8), new THREE.MeshStandardMaterial({ color: 0xe2e8f0 }));
        roomFloor.position.y = 0.1;
        roomFloor.receiveShadow = true;
        roomGroup.add(roomFloor);
        
        // Glass Walls (Left, Right, Back)
        const glassMatRoom = new THREE.MeshStandardMaterial({ color: 0xbae6fd, transparent: true, opacity: 0.3, roughness: 0.1, side: THREE.DoubleSide });
        
        const wallL = new THREE.Mesh(new THREE.BoxGeometry(0.2, 4, 8), glassMatRoom);
        wallL.position.set(-3.9, 2.1, 0);
        roomGroup.add(wallL);
        
        const wallR = new THREE.Mesh(new THREE.BoxGeometry(0.2, 4, 8), glassMatRoom);
        wallR.position.set(3.9, 2.1, 0);
        roomGroup.add(wallR);
        
        const wallB = new THREE.Mesh(new THREE.BoxGeometry(8, 4, 0.2), glassMatRoom);
        wallB.position.set(0, 2.1, -3.9);
        roomGroup.add(wallB);
        
        // Sofa
        const sofa = new THREE.Mesh(new THREE.BoxGeometry(3, 1, 1.5), new THREE.MeshStandardMaterial({ color: 0x8b5cf6 }));
        sofa.position.set(0, 0.6, -2);
        sofa.castShadow = true;
        roomGroup.add(sofa);
        
        scene.add(roomGroup);


        // --- INFIRMARY CLINIC BED GENERATOR ---
        const createBed = (x, z) => {
            const bedGroup = new THREE.Group();
            bedGroup.position.set(x, 0, z);

            const bedFrame = new THREE.Mesh(new THREE.BoxGeometry(2, 0.5, 3.8), new THREE.MeshStandardMaterial({color: 0x94a3b8}));
            bedFrame.position.y = 0.25;
            bedFrame.castShadow = true;
            bedGroup.add(bedFrame);

            const bedMattress = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.4, 3.5), new THREE.MeshStandardMaterial({color: 0xffffff}));
            bedMattress.position.set(0, 0.6, 0);
            bedMattress.receiveShadow = true;
            bedGroup.add(bedMattress);

            const bedBlanket = new THREE.Mesh(new THREE.BoxGeometry(1.82, 0.45, 2.2), new THREE.MeshStandardMaterial({color: 0x60a5fa}));
            bedBlanket.position.set(0, 0.65, 0.6);
            bedGroup.add(bedBlanket);

            scene.add(bedGroup);
        };

        // --- OFFICIAL VAN ---
        const vanGroup = new THREE.Group();
        vanGroup.position.set(24, 0, 6); // Moved to avoid exhausted zone

        const vanBodyMat = new THREE.MeshStandardMaterial({color: 0x3b82f6, roughness: 0.3}); // Blue
        const glassMat = new THREE.MeshStandardMaterial({color: 0x93c5fd, transparent: true, opacity: 0.8});
        const wheelMat = new THREE.MeshStandardMaterial({color: 0x1e293b, roughness: 0.9});

        const vanChassis = new THREE.Mesh(new THREE.BoxGeometry(2.8, 1.8, 5.2), vanBodyMat);
        vanChassis.position.y = 1.1;
        vanChassis.castShadow = true;
        vanGroup.add(vanChassis);

        const vanCabin = new THREE.Mesh(new THREE.BoxGeometry(2.8, 1.4, 1.5), vanBodyMat);
        vanCabin.position.set(0, 1.1, 2.1);
        vanGroup.add(vanCabin);

        const frontWindshield = new THREE.Mesh(new THREE.BoxGeometry(2.6, 1.0, 0.1), glassMat);
        frontWindshield.position.set(0, 1.5, 2.85);
        vanGroup.add(frontWindshield);

        for (let xOffset of [-1.5, 1.5]) {
            for (let zOffset of [-1.8, 1.8]) {
                const wheel = new THREE.Mesh(new THREE.CylinderGeometry(0.55, 0.55, 0.4, 10), wheelMat);
                wheel.rotation.z = Math.PI / 2;
                wheel.position.set(xOffset, 0.55, zOffset);
                wheel.castShadow = true;
                vanGroup.add(wheel);
            }
        }
        scene.add(vanGroup);

        // --- 8-BIT VOXEL CHARACTER BUILDER ---
        console.log("Characters Data:", charactersData);
        function buildVoxelCharacter(data) {
            const group = new THREE.Group();
            const voxelSize = 0.15;
            const primaryMat = new THREE.MeshStandardMaterial({ color: data.color });
            const skinMat = new THREE.MeshStandardMaterial({ color: data.skin });
            const darkMat = new THREE.MeshStandardMaterial({ color: 0x1e293b });

            const boxGeo = new THREE.BoxGeometry(voxelSize, voxelSize, voxelSize);

            const addVoxel = (x, y, z, mat) => {
                const mesh = new THREE.Mesh(boxGeo, mat);
                mesh.position.set(x * voxelSize, y * voxelSize, z * voxelSize);
                mesh.castShadow = true;
                group.add(mesh);
            };

            // Voxel Humanoid Layout
            addVoxel(-1, 0, 0, primaryMat); addVoxel(1, 0, 0, primaryMat);
            addVoxel(-1, 1, 0, primaryMat); addVoxel(1, 1, 0, primaryMat);
            for(let x=-2; x<=2; x++) for(let y=2; y<=5; y++) addVoxel(x, y, 0, primaryMat);
            addVoxel(-3, 5, 0, skinMat); addVoxel(-3, 4, 0, primaryMat);
            addVoxel(3, 5, 0, skinMat); addVoxel(3, 4, 0, primaryMat);
            for(let x=-2; x<=2; x++) for(let y=6; y<=8; y++) addVoxel(x, y, 0, skinMat);
            addVoxel(-1, 7, 1, darkMat); addVoxel(1, 7, 1, darkMat);
            for(let x=-2; x<=2; x++) addVoxel(x, 9, 0, darkMat);

            group.position.set(data.pos.x, 0, data.pos.z);
            group.userData = { ...data, originPos: new THREE.Vector3(data.pos.x, 0, data.pos.z) };

            // Selection indicator ring
            const ringGeo = new THREE.RingGeometry(0.8, 1.0, 16);
            const ringMat = new THREE.MeshBasicMaterial({ color: 0x6366f1, side: THREE.DoubleSide, transparent: true, opacity: 0 });
            const ring = new THREE.Mesh(ringGeo, ringMat);
            ring.rotation.x = Math.PI / 2;
            ring.position.y = 0.02;
            group.add(ring);
            group.userData.ring = ring;

            group.userData.targetPos = new THREE.Vector3(data.pos.x, 0, data.pos.z);
            group.userData.speed = 0.01 + Math.random() * 0.015;

            // Apply immediate state
            if (data.state === 'sickSleeping') {
                group.position.set(data.pos.x, 0.85, data.pos.z);
                group.rotation.set(-Math.PI / 2, 0, 0); // Lay flat
                
                // Add floating "Zzz" sprite
                const canvas = document.createElement('canvas');
                canvas.width = 128;
                canvas.height = 64;
                const ctx = canvas.getContext('2d');
                ctx.font = 'Bold 40px Arial';
                ctx.fillStyle = '#60a5fa'; // Blue-400
                ctx.textAlign = 'center';
                ctx.fillText('Zzz...', 64, 45);
                
                const texture = new THREE.CanvasTexture(canvas);
                const spriteMat = new THREE.SpriteMaterial({ map: texture, transparent: true, depthTest: false });
                const sprite = new THREE.Sprite(spriteMat);
                // Since the group is rotated -90deg on X, local Y points to world -Z, local Z points to world Y
                sprite.position.set(0, 1.5, 0.5); 
                sprite.scale.set(1.5, 0.75, 1);
                
                // Animate the Zzz sprite using TWEEN
                new TWEEN.Tween(sprite.position)
                    .to({ y: 3.5, x: 0.5 }, 2000)
                    .yoyo(true)
                    .repeat(Infinity)
                    .easing(TWEEN.Easing.Quadratic.InOut)
                    .start();
                    
                new TWEEN.Tween(spriteMat)
                    .to({ opacity: 0.2 }, 2000)
                    .yoyo(true)
                    .repeat(Infinity)
                    .start();
                    
                group.add(sprite);
                
            } else if (data.state === 'vanished') {
                group.visible = false;
            } else if (data.state === 'vacation') {
                group.rotation.y = -Math.PI / 2; // Look towards the plane
            } else if (data.state === 'personal') {
                group.rotation.y = Math.PI / 4; 
            }

            scene.add(group);
            characterMeshes.push(group);
            return group;
        }

        charactersData.forEach(char => {
            buildVoxelCharacter(char);
            // Spawn a bed for sick characters dynamically
            if (char.state === 'sickSleeping' && char.bedPos) {
                createBed(char.bedPos.x, char.bedPos.z);
            }
        });

        // --- UI & INTERACTIONS ---
        function renderDirectoryTable(data = charactersData) {
            const tbody = document.getElementById('directory-tbody');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-slate-400">ไม่พบข้อมูลค้นหา</td></tr>';
                return;
            }

            data.forEach(char => {
                let badgeColor = "bg-emerald-100 text-emerald-600";
                
                if (char.state === 'sickSleeping') {
                    badgeColor = "bg-rose-100 text-rose-600";
                } else if (char.state === 'vacation') {
                    badgeColor = "bg-sky-100 text-sky-600";
                } else if (char.state === 'personal') {
                    badgeColor = "bg-purple-100 text-purple-600";
                } else if (char.state === 'vanished') {
                    badgeColor = "bg-amber-100 text-amber-600";
                }

                const tr = document.createElement('tr');
                tr.className = `border-b border-slate-100 hover:bg-slate-50 transition cursor-pointer ${selectedCharId === char.id ? 'bg-indigo-50 border-indigo-200' : ''}`;
                tr.onclick = () => selectPersonnel(char.id);

                tr.innerHTML = `
                    <td class="py-3 px-3 font-semibold ${selectedCharId === char.id ? 'text-indigo-700' : 'text-slate-700'} flex items-center justify-between">
                        <span class="truncate">${char.name}</span>
                        <button onclick="event.stopPropagation(); viewProfile(${char.id})" class="text-indigo-500 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-full w-7 h-7 flex-shrink-0 flex items-center justify-center transition shadow-sm border border-indigo-100" title="ดูข้อมูล Profile">
                            <i class="fas fa-user text-xs"></i>
                        </button>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="inline-block px-2.5 py-1 text-[10px] font-bold rounded-full ${badgeColor}">${char.rawStatus}</span>
                    </td>
                    <td class="py-3 px-3 max-w-[150px] truncate text-slate-500" title="${char.task}">${char.task}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function selectPersonnel(id) {
            selectedCharId = id;
            const data = charactersData.find(c => c.id === id);
            const mesh = characterMeshes.find(m => m.userData.id === id);
            if (!data || !mesh) return;

            // Highlight ring
            characterMeshes.forEach(m => {
                m.userData.ring.material.opacity = m.userData.id === id ? 1.0 : 0.0;
            });

            // Focus Camera
            if (data.state === 'sickSleeping') {
                focusCameraOn(-8, 0.5, -6, 11);
            } else if (data.state === 'vanished') {
                focusCameraOn(vanGroup.position.x, vanGroup.position.y, vanGroup.position.z, 12);
            } else {
                focusCameraOn(mesh.position.x, mesh.position.y + 0.8, mesh.position.z, 12);
            }

            writeLog(`กำลังจับภาพ: ${data.name} สถานะ: ${data.rawStatus} (${data.task})`);
            
            // Keep the search filter when re-rendering
            filterPersonnelTable();
        }

        function filterPersonnelTable() {
            const query = document.getElementById('personnelSearch').value.toLowerCase();
            const filteredData = charactersData.filter(char => 
                char.name.toLowerCase().includes(query) || 
                char.rawStatus.toLowerCase().includes(query) || 
                char.task.toLowerCase().includes(query)
            );
            renderDirectoryTable(filteredData);
        }

        let currentProfileUserId = null;
        let currentProfileFilter = 'day';

        async function viewProfile(id) {
            const data = charactersData.find(c => c.id === id);
            if (!data) return;
            
            currentProfileUserId = data.user_id;
            currentProfileFilter = 'day'; // Default to day when opening

            const modal = document.getElementById('userProfileModal');
            const modalContent = document.getElementById('userProfileModalContent');
            
            // Show Modal
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95');
            }, 10);
            
            await loadProfileData();
        }

        async function loadProfileData() {
            if (!currentProfileUserId) return;

            const loading = document.getElementById('profileLoading');
            const content = document.getElementById('profileContent');
            
            loading.classList.remove('hidden');
            content.classList.add('hidden');
            
            // Update filter buttons styling
            ['day', 'week', 'month'].forEach(f => {
                const btn = document.getElementById('btnFilter' + f.charAt(0).toUpperCase() + f.slice(1));
                if (f === currentProfileFilter) {
                    btn.className = 'px-3 py-1 text-xs font-bold rounded-md bg-white text-indigo-600 shadow-sm transition-all';
                } else {
                    btn.className = 'px-3 py-1 text-xs font-bold rounded-md text-slate-500 hover:text-slate-700 transition-all';
                }
            });

            // Update Heading
            const titleMap = { 'day': 'วันนี้', 'week': 'สัปดาห์นี้', 'month': 'เดือนนี้' };
            document.getElementById('profRecordsTitle').innerText = `ความรับผิดชอบและบันทึกล่าสุด (${titleMap[currentProfileFilter]})`;

            try {
                const response = await fetch(`api_get_user_profile.php?user_id=${currentProfileUserId}&filter=${currentProfileFilter}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    document.getElementById('profName').innerText = result.user.name;
                    document.getElementById('profJob').innerText = result.user.position;
                    // Fix image path to uploads/avatars/
                    document.getElementById('profImg').src = result.user.profile_picture === 'default.jpg' ? `https://ui-avatars.com/api/?name=${encodeURIComponent(result.user.name)}&background=e0e7ff&color=4f46e5` : `uploads/avatars/${result.user.profile_picture}`;
                    
                    document.getElementById('profWork').innerText = result.stats.worklog_count || 0;
                    document.getElementById('profProj').innerText = result.stats.project_count || 0;
                    document.getElementById('profFinance').innerText = result.stats.finance_count || 0;
                    document.getElementById('profLeave').innerText = result.stats.leave_count || 0;
                    document.getElementById('profTrip').innerText = result.stats.trip_count || 0;
                    document.getElementById('profRoom').innerText = result.stats.room_count || 0;
                    document.getElementById('profRepair').innerText = result.stats.repair_count || 0;
                    
                    const listContainer = document.getElementById('profWorkList');
                    listContainer.innerHTML = '';
                    
                    let hasContent = false;
                    
                    // Show Projects first
                    if (result.details.projects && result.details.projects.length > 0) {
                        hasContent = true;
                        result.details.projects.forEach(proj => {
                            listContainer.innerHTML += `<a href="modules/projects/view_project.php?id=${proj.project_id}" target="_blank" class="block p-3 bg-emerald-50 rounded-xl border border-emerald-100 hover:bg-emerald-100 transition-colors cursor-pointer text-decoration-none">
                                <div class="font-bold text-emerald-700 text-sm"><i class="fas fa-folder-open me-2"></i> โครงการ: ${proj.project_name}</div>
                                <div class="text-xs text-emerald-600 mt-1">สถานะ: ${proj.status}</div>
                            </a>`;
                        });
                    }
                    
                    // Show Finances
                    if (result.details.finances && result.details.finances.length > 0) {
                        hasContent = true;
                        result.details.finances.forEach(fin => {
                            let typeText = fin.req_type === 'reimbursement' ? 'เบิกจ่าย' : (fin.req_type === 'advance' ? 'ยืมเงิน' : (fin.req_type === 'clear_advance' ? 'หักล้างเงินยืม' : 'ค่าสอน'));
                            
                            // แปลงสถานะเป็นภาษาไทย
                            let statusMap = {
                                'pending': 'รออนุมัติ',
                                'approved': 'อนุมัติแล้ว',
                                'rejected': 'ไม่อนุมัติ',
                                'paid': 'เบิกจ่ายแล้ว',
                                'completed': 'เสร็จสิ้น',
                                'draft': 'ฉบับร่าง'
                            };
                            let statusThai = statusMap[fin.current_status] || fin.current_status;

                            listContainer.innerHTML += `<a href="modules/finance/view.php?id=${fin.req_id}" target="_blank" class="block p-3 bg-cyan-50 rounded-xl border border-cyan-100 hover:bg-cyan-100 transition-colors cursor-pointer text-decoration-none">
                                <div class="font-bold text-cyan-700 text-sm"><i class="fas fa-file-invoice-dollar me-2"></i> ${typeText}: ${fin.title}</div>
                                <div class="text-xs text-cyan-600 mt-1">ยอดเงิน: ฿${parseFloat(fin.amount).toLocaleString()} | สถานะ: ${statusThai}</div>
                            </a>`;
                        });
                    }

                    // Show Rooms
                    if (result.details.rooms && result.details.rooms.length > 0) {
                        hasContent = true;
                        result.details.rooms.forEach(room => {
                            let statusMap = { 'pending': 'รออนุมัติ', 'approved': 'อนุมัติ', 'rejected': 'ไม่อนุมัติ', 'cancelled': 'ยกเลิก' };
                            let statusThai = statusMap[room.status] || room.status;
                            listContainer.innerHTML += `<a href="modules/room_booking/view_booking.php?id=${room.booking_id}" target="_blank" class="block p-3 bg-fuchsia-50 rounded-xl border border-fuchsia-100 hover:bg-fuchsia-100 transition-colors cursor-pointer text-decoration-none">
                                <div class="font-bold text-fuchsia-700 text-sm"><i class="fas fa-door-open me-2"></i> จองห้อง: ${room.room_name}</div>
                                <div class="text-xs text-fuchsia-600 mt-1"><i class="far fa-calendar"></i> ${room.booking_date} | เวลา: ${room.start_time} - ${room.end_time} | สถานะ: ${statusThai}</div>
                            </a>`;
                        });
                    }

                    // Show Repairs
                    if (result.details.repairs && result.details.repairs.length > 0) {
                        hasContent = true;
                        result.details.repairs.forEach(rep => {
                            let statusMap = { 'pending': 'รอรับเรื่อง', 'in_progress': 'กำลังดำเนินการ', 'completed': 'เสร็จสิ้น', 'cancelled': 'ยกเลิก' };
                            let statusThai = statusMap[rep.status] || rep.status;
                            listContainer.innerHTML += `<a href="modules/repair_request/view_repair.php?id=${rep.repair_id}" target="_blank" class="block p-3 bg-orange-50 rounded-xl border border-orange-100 hover:bg-orange-100 transition-colors cursor-pointer text-decoration-none">
                                <div class="font-bold text-orange-700 text-sm"><i class="fas fa-screwdriver-wrench me-2"></i> แจ้งซ่อม: ${rep.equipment_name}</div>
                                <div class="text-xs text-orange-600 mt-1">ห้อง: ${rep.room_name || 'ไม่ระบุ'} | สถานะ: ${statusThai}</div>
                            </a>`;
                        });
                    }
                    
                    // Then Work Logs
                    if (result.details.work_logs && result.details.work_logs.length > 0) {
                        hasContent = true;
                        result.details.work_logs.forEach(log => {
                            listContainer.innerHTML += `<a href="modules/worklog/show.php?id=${log.log_id}" target="_blank" class="block p-3 bg-slate-50 rounded-xl border border-slate-100 hover:bg-slate-100 transition-colors cursor-pointer text-decoration-none">
                                <div class="font-bold text-slate-700 text-sm"><i class="fas fa-tasks me-2"></i> ${log.task_name || 'งานอื่นๆ'}</div>
                                <div class="text-xs text-slate-500 mt-1"><i class="far fa-clock"></i> ${log.log_date} | ${log.hours_spent} ชม.</div>
                            </a>`;
                        });
                    }
                    
                    if (!hasContent) {
                        listContainer.innerHTML = '<div class="text-center py-4 text-slate-400">ยังไม่มีบันทึกข้อมูลในช่วงเวลานี้</div>';
                    }
                    
                    loading.classList.add('hidden');
                    content.classList.remove('hidden');
                } else {
                    alert('Error loading profile: ' + result.message);
                    closeProfile();
                }
            } catch (error) {
                console.error(error);
                alert('Connection error while fetching profile.');
                closeProfile();
            }
        }

        function changeProfileFilter(filter) {
            currentProfileFilter = filter;
            loadProfileData();
        }

        function closeProfile() {
            const modal = document.getElementById('userProfileModal');
            const modalContent = document.getElementById('userProfileModalContent');
            modal.classList.add('opacity-0');
            modalContent.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function writeLog(text) {
            document.getElementById('system-log-text').innerText = text;
        }

        function focusCameraOn(x, y, z, zoomDist = 14, camY = 15) {
            new TWEEN.Tween(camera.position)
                .to({ x: x + 2, y: camY, z: z + zoomDist }, 1000)
                .easing(TWEEN.Easing.Cubic.Out)
                .start();

            const targetLook = new THREE.Vector3(x, y, z);
            let currentLook = new THREE.Vector3().copy(camera.getWorldDirection(new THREE.Vector3())).add(camera.position);
            
            new TWEEN.Tween(currentLook)
                .to(targetLook, 1000)
                .onUpdate(() => camera.lookAt(currentLook))
                .start();
        }

        function focusOnBed() {
            writeLog("ปรับกล้อง: ส่องดูเตียงพยาบาลวิทยาลัย สำหรับผู้ลาพัก...");
            focusCameraOn(-8, 0.5, -6, 11);
        }

        function focusOnVan() {
            writeLog("ปรับกล้อง: จับภาพยานพาหนะ สำหรับผู้ไปปฏิบัติราชการนอกสถานที่...");
            focusCameraOn(vanGroup.position.x, vanGroup.position.y, vanGroup.position.z, 12);
        }

        function focusOnProjects() {
            writeLog("ปรับกล้อง: ส่องดูภาพรวมโครงการ...");
            focusCameraOn(0, 1.5, -9, 15);
        }

        function focusOnMeeting() {
            writeLog("ปรับกล้อง: ส่องดูโซนห้องประชุม...");
            focusCameraOn(-16, 1.5, 12, 12);
        }

        function focusOnMaintenance() {
            writeLog("ปรับกล้อง: ส่องดูโซนอาคารและซ่อมบำรุง...");
            focusCameraOn(16, 1.5, -10, 12);
        }

        function focusOnIT() {
            writeLog("ปรับกล้อง: ส่องดูโซนพัฒนาระบบสารสนเทศ...");
            focusCameraOn(16, 1.5, 12, 12);
        }

        function focusOnRunway() {
            writeLog("ปรับกล้อง: ส่องดูลานเครื่องบิน สำหรับผู้ลาพักผ่อน...");
            focusCameraOn(26, 1, 0, 15);
        }

        function focusOnRoom() {
            writeLog("ปรับกล้อง: ส่องดูห้องส่วนตัว สำหรับผู้ลากิจ...");
            focusCameraOn(-24, 1, -10, 12);
        }

        function focusOnSupply() {
            const inProg = suppliesData3D.filter(r => r.supply_status !== 'issued').length;
            const completed = suppliesData3D.filter(r => r.supply_status === 'issued').length;
            writeLog(`ติดตามพัสดุ: กำลังดำเนินการ ${inProg} รายการ | เสร็จสมบูรณ์ ${completed} รายการ`);
            focusCameraOn(0, 2, -17, 12, 8); // Zoom distance 12, Camera Y 8 (Closer and lower)
        }

        function resetView() {
            selectedCharId = null;
            characterMeshes.forEach(m => m.userData.ring.material.opacity = 0);
            
            new TWEEN.Tween(camera.position)
                .to({ x: 0, y: 30, z: 42 }, 1200)
                .easing(TWEEN.Easing.Cubic.Out)
                .start();
            
            let currentLook = new THREE.Vector3().copy(camera.getWorldDirection(new THREE.Vector3())).add(camera.position);
            new TWEEN.Tween(currentLook)
                .to(new THREE.Vector3(0, 0, -10), 1200)
                .onUpdate(() => camera.lookAt(currentLook))
                .start();

            writeLog("ปรับคืนค่ากล้อง: แสดงภาพรวมวิทยาลัย");
            filterPersonnelTable();
        }

        // --- ZZZ PARTICLES EFFECT ---
        function spawnZzz(x, y, z) {
            const canvas = document.createElement('canvas');
            canvas.width = 64; canvas.height = 64;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#60a5fa';
            ctx.font = 'bold 36px "Inter", sans-serif';
            ctx.fillText('Z', 15, 45);

            const texture = new THREE.CanvasTexture(canvas);
            const mat = new THREE.SpriteMaterial({ map: texture, transparent: true });
            const sprite = new THREE.Sprite(mat);
            sprite.scale.set(0.8, 0.8, 0.8);
            sprite.position.set(x + (Math.random() - 0.5) * 0.2, y + 1.2, z);
            scene.add(sprite);
            zzzParticles.push({ sprite, age: 0 });
        }

        // --- ANIMATION LOOP ---
        const raycaster = new THREE.Raycaster();
        const mouse = new THREE.Vector2();

        canvasContainer.addEventListener('click', (event) => {
            const rect = renderer.domElement.getBoundingClientRect();
            mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

            raycaster.setFromCamera(mouse, camera);
            const intersects = raycaster.intersectObjects(characterMeshes, true);

            if (intersects.length > 0) {
                let object = intersects[0].object;
                while (object.parent && object.parent.type === "Group") {
                    object = object.parent;
                }
                if (object.userData && object.userData.id !== undefined) {
                    if (selectedCharId === object.userData.id) {
                        viewProfile(object.userData.id);
                    } else {
                        selectPersonnel(object.userData.id);
                    }
                }
            }
        });

        setInterval(() => {
            const now = new Date();
            document.getElementById('digital-clock').innerText = 
                `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}`;
        }, 1000);

        const resizeObserver = new ResizeObserver(() => {
            if (canvasContainer.clientWidth > 0 && canvasContainer.clientHeight > 0) {
                camera.aspect = canvasContainer.clientWidth / canvasContainer.clientHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(canvasContainer.clientWidth, canvasContainer.clientHeight);
            }
        });
        resizeObserver.observe(canvasContainer);

        const gameClock = new THREE.Clock();
        let lastZzzTime = 0;

        function animate(time) {
            requestAnimationFrame(animate);
            TWEEN.update(time);

            const delta = gameClock.getDelta();
            const elapsedTime = gameClock.getElapsedTime();

            // Zzz Particles
            for (let i = zzzParticles.length - 1; i >= 0; i--) {
                const p = zzzParticles[i];
                p.sprite.position.y += delta * 0.5;
                p.sprite.position.x += Math.sin(elapsedTime * 4 + p.age) * 0.01;
                p.age += delta;
                p.sprite.material.opacity = 1.0 - (p.age / 2.0);
                if (p.age > 2.0) {
                    scene.remove(p.sprite);
                    zzzParticles.splice(i, 1);
                }
            }

            // Characters behavior
            characterMeshes.forEach((mesh, index) => {
                const state = mesh.userData.state;
                const target = mesh.userData.targetPos;

                if (state === 'sickSleeping') {
                    mesh.position.y = 0.75 + Math.sin(elapsedTime * 1.5) * 0.04;
                    if (index === 0 && elapsedTime - lastZzzTime > 1.5) {
                        spawnZzz(mesh.position.x, mesh.position.y, mesh.position.z);
                        lastZzzTime = elapsedTime;
                    }
                }
                else if (state === 'meeting') {
                    const distToTarget = mesh.position.distanceTo(target);
                    if (distToTarget < 0.25) {
                        // Roam closely around the meeting table
                        const randX = -16 + (Math.random() - 0.5) * 8;
                        const randZ = 12 + (Math.random() - 0.5) * 6;
                        mesh.userData.targetPos.set(randX, 0, randZ);
                    } else {
                        const dir = new THREE.Vector3().subVectors(target, mesh.position).normalize();
                        mesh.position.add(dir.multiplyScalar(mesh.userData.speed * 0.4));
                        mesh.position.y = Math.abs(Math.sin(elapsedTime * 6 + index)) * 0.1;
                        const nextStep = target.clone().setY(mesh.position.y);
                        mesh.lookAt(nextStep);
                    }
                }
                else if (state === 'maintenance') {
                    const distToTarget = mesh.position.distanceTo(target);
                    if (distToTarget < 0.25) {
                        const randX = 16 + (Math.random() - 0.5) * 8;
                        const randZ = -10 + (Math.random() - 0.5) * 6;
                        mesh.userData.targetPos.set(randX, 0, randZ);
                    } else {
                        const dir = new THREE.Vector3().subVectors(target, mesh.position).normalize();
                        mesh.position.add(dir.multiplyScalar(mesh.userData.speed * 0.4));
                        mesh.position.y = Math.abs(Math.sin(elapsedTime * 6 + index)) * 0.1;
                        const nextStep = target.clone().setY(mesh.position.y);
                        mesh.lookAt(nextStep);
                    }
                }
                else if (state === 'it') {
                    const distToTarget = mesh.position.distanceTo(target);
                    if (distToTarget < 0.25) {
                        const randX = 16 + (Math.random() - 0.5) * 6;
                        const randZ = 12 + (Math.random() - 0.5) * 6;
                        mesh.userData.targetPos.set(randX, 0, randZ);
                    } else {
                        const dir = new THREE.Vector3().subVectors(target, mesh.position).normalize();
                        mesh.position.add(dir.multiplyScalar(mesh.userData.speed * 0.4));
                        mesh.position.y = Math.abs(Math.sin(elapsedTime * 6 + index)) * 0.1;
                        const nextStep = target.clone().setY(mesh.position.y);
                        mesh.lookAt(nextStep);
                    }
                }
                else if (state === 'roam') {
                    const distToTarget = mesh.position.distanceTo(target);
                    if (distToTarget < 0.25) {
                        const randX = (Math.random() - 0.5) * 12;
                        const randZ = (Math.random() - 0.5) * 12;
                        mesh.userData.targetPos.set(randX, 0, randZ);
                    } else {
                        const dir = new THREE.Vector3().subVectors(target, mesh.position).normalize();
                        mesh.position.add(dir.multiplyScalar(mesh.userData.speed));
                        mesh.position.y = Math.abs(Math.sin(elapsedTime * 8 + index)) * 0.15;
                        const nextStep = target.clone().setY(mesh.position.y);
                        mesh.lookAt(nextStep);
                    }
                }
            });

            // Spin indicator ring
            if (selectedCharId !== null) {
                const selMesh = characterMeshes.find(m => m.userData.id === selectedCharId);
                if (selMesh && selMesh.userData.ring) {
                    selMesh.userData.ring.rotation.z += 0.02;
                }
            }

            renderer.render(scene, camera);
        }

        window.onload = () => {
            renderDirectoryTable();
            animate();
        };
    </script>
</body>
</html>
