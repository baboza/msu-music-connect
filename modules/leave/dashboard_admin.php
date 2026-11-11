<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($management_roles)) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='../../index.php';</script>";
    exit();
}

// ---------------------------------------------------------
// 0. รับค่าการกรอง และคำนวณปีงบประมาณ
// ---------------------------------------------------------
$filter_user_id = $_GET['user_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// ดึงรายการปีงบประมาณจากตาราง fiscal_years
$available_years = [];
try {
    $available_years = $pdo->query("SELECT year_name FROM fiscal_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

// ดึงปีงบประมาณจากข้อมูลการลาจริงในระบบ (กันตกหล่น)
try {
    $db_years = $pdo->query("SELECT DISTINCT CASE WHEN MONTH(created_at) >= 10 THEN YEAR(created_at) + 544 ELSE YEAR(created_at) + 543 END as fy FROM leave_requests ORDER BY fy DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($db_years as $dy) {
        if ($dy && !in_array((string)$dy, $available_years)) {
            $available_years[] = (string)$dy;
        }
    }
    rsort($available_years);
} catch (Exception $e) {}

// หาปีงบประมาณที่กำหนดเป็น active ในระบบ
$active_year = '2569';
try {
    $act_res = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1")->fetchColumn();
    if ($act_res) $active_year = (string)$act_res;
} catch (Exception $e) {}

// ตรวจสอบค่าปีงบประมาณที่ส่งมา ถ้าไม่ส่งมาให้ใช้ active_year (หรือปีที่มีข้อมูล)
$filter_year = $_GET['filter_year'] ?? ($_GET['year'] ?? $active_year);

$where_clauses = [];
$params = [];

if ($filter_year !== 'all') {
    $fy_num = (int)$filter_year;
    $fy_en = ($fy_num > 2500) ? ($fy_num - 543) : $fy_num;
    $fy_start = ($fy_en - 1) . "-10-01 00:00:00";
    $fy_end = $fy_en . "-09-30 23:59:59";
    $fy_th = (string)($fy_en + 543);

    $where_clauses[] = "l.created_at BETWEEN ? AND ?";
    $params[] = $fy_start;
    $params[] = $fy_end;
} else {
    $fy_th = 'ทุกปีงบประมาณ';
    $fy_start = '2000-10-01 00:00:00';
    $fy_end = '2099-09-30 23:59:59';
}

if ($filter_user_id) { $where_clauses[] = "l.user_id = ?"; $params[] = $filter_user_id; }
if ($status_filter) { $where_clauses[] = "l.status = ?"; $params[] = $status_filter; }
if ($search) {
    $where_clauses[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.user_id LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$where_str = !empty($where_clauses) ? (" WHERE " . implode(" AND ", $where_clauses)) : "";
$extra_where = !empty($where_str) ? ($where_str . " AND ") : " WHERE ";
$join_user = " JOIN users u ON l.user_id = u.user_id ";

// ดึงข้อมูลผู้ใช้งานที่ถูกเลือกกรอง (ถ้ามี)
$selected_user = null;
if ($filter_user_id) {
    $stmt_su = $pdo->prepare("SELECT u.*, d.dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.user_id = ?");
    $stmt_su->execute([$filter_user_id]);
    $selected_user = $stmt_su->fetch(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------
// [CSV Export 1] รายการลาพร้อมยอดสะสมรายใบ (Running Total)
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    if (ob_get_length()) ob_end_clean();
    $fy_csv_cond = ($filter_year === 'all') ? "1=1" : "r.created_at BETWEEN '$fy_start' AND '$fy_end'";
    $sql_export = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, d.dept_name,
                   (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id 
                    WHERE r.user_id = l.user_id AND rt.type_name LIKE '%ป่วย%' AND r.status != 'rejected' 
                    AND (r.created_at < l.created_at OR (r.created_at = l.created_at AND r.leave_id <= l.leave_id))
                    AND $fy_csv_cond) as run_sick,
                   (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id 
                    WHERE r.user_id = l.user_id AND rt.type_name LIKE '%กิจ%' AND r.status != 'rejected' 
                    AND (r.created_at < l.created_at OR (r.created_at = l.created_at AND r.leave_id <= l.leave_id))
                    AND $fy_csv_cond) as run_personal,
                   (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id 
                    WHERE r.user_id = l.user_id AND rt.type_name LIKE '%พักผ่อน%' AND r.status != 'rejected' 
                    AND (r.created_at < l.created_at OR (r.created_at = l.created_at AND r.leave_id <= l.leave_id))
                    AND $fy_csv_cond) as run_vacation
                   FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id $join_user LEFT JOIN departments d ON u.dept_id = d.dept_id
                   $where_str ORDER BY l.created_at ASC, l.leave_id ASC";
    $stmt_export = $pdo->prepare($sql_export); $stmt_export->execute($params); $rows = $stmt_export->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_history_'.date('Ymd').'.csv"');
    $output = fopen('php://output', 'w'); fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, ['วันที่ยื่น', 'ชื่อ-นามสกุล', 'ประเภทการลา', 'เริ่มวันที่', 'ถึงวันที่', 'วัน(ครั้งนี้)', 'สถานะ', 'ป่วยสะสม', 'กิจสะสม', 'พักผ่อนสะสม'], ",", "\"", "\\");
    foreach ($rows as $r) {
        $status_text = ($r['status'] == 'approved') ? 'อนุมัติแล้ว' : (($r['status'] == 'rejected') ? 'ไม่อนุมัติ' : 'รอพิจารณา (ขั้นที่ '.$r['current_step'].')');
        fputcsv($output, [date('d/m/Y H:i', strtotime($r['created_at'])), $r['prefix_name'].$r['first_name'].' '.$r['last_name'], $r['type_name'], $r['start_date'], $r['end_date'], (strtotime($r['end_date'])-strtotime($r['start_date']))/86400+1, $status_text, $r['run_sick']?:0, $r['run_personal']?:0, $r['run_vacation']?:0], ",", "\"", "\\");
    }
    fclose($output); exit();
}

// ---------------------------------------------------------
// [CSV Export 2] สรุปยอดสะสมรวมของบุคลากร (1 คนต่อ 1 แถว)
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'summary') {
    if (ob_get_length()) ob_end_clean();
    $fy_csv_cond = ($filter_year === 'all') ? "1=1" : "r.created_at BETWEEN '$fy_start' AND '$fy_end'";
    $sql_summary = "SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, d.dept_name,
                    (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id WHERE r.user_id = u.user_id AND rt.type_name LIKE '%ป่วย%' AND r.status != 'rejected' AND $fy_csv_cond) as total_sick,
                    (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id WHERE r.user_id = u.user_id AND rt.type_name LIKE '%กิจ%' AND r.status != 'rejected' AND $fy_csv_cond) as total_personal,
                    (SELECT SUM(DATEDIFF(r.end_date, r.start_date) + 1) FROM leave_requests r JOIN leave_types rt ON r.type_id = rt.type_id WHERE r.user_id = u.user_id AND rt.type_name LIKE '%พักผ่อน%' AND r.status != 'rejected' AND $fy_csv_cond) as total_vacation
                    FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id
                    WHERE 1=1 " . ($filter_user_id ? " AND u.user_id = '$filter_user_id'" : "") . " ORDER BY u.first_name ASC";
    $rows = $pdo->query($sql_summary)->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_summary_'.date('Ymd').'.csv"');
    $output = fopen('php://output', 'w'); fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['รหัสพนักงาน', 'ชื่อ-นามสกุล', 'สังกัด', 'ลาป่วยรวม(วัน)', 'ลากิจรวม(วัน)', 'ลาพักผ่อนรวม(วัน)', 'รวมทุกประเภท'], ",", "\"", "\\");
    foreach ($rows as $r) {
        $total = ($r['total_sick'] ?: 0) + ($r['total_personal'] ?: 0) + ($r['total_vacation'] ?: 0);
        fputcsv($output, [$r['user_id'], $r['prefix_name'].$r['first_name'].' '.$r['last_name'], $r['dept_name'], $r['total_sick'] ?: 0, $r['total_personal'] ?: 0, $r['total_vacation'] ?: 0, $total], ",", "\"", "\\");
    }
    fclose($output); exit();
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ---------------------------------------------------------
// 1. ดึงข้อมูลสถิติ Card และวันลาสะสม
// ---------------------------------------------------------
$stat_stmt = $pdo->prepare("SELECT COUNT(*) as tr, SUM(DATEDIFF(l.end_date, l.start_date) + 1) as td, SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) as p, SUM(CASE WHEN l.status = 'approved' THEN 1 ELSE 0 END) as a FROM leave_requests l $join_user $where_str");
$stat_stmt->execute($params); $stat_data = $stat_stmt->fetch();

$accum_stmt = $pdo->prepare("SELECT CASE WHEN t.type_name LIKE '%ป่วย%' THEN 'ลาป่วย' WHEN t.type_name LIKE '%กิจ%' THEN 'ลากิจ' WHEN t.type_name LIKE '%พักผ่อน%' THEN 'ลาพักผ่อน' ELSE 'อื่นๆ' END as g, SUM(DATEDIFF(l.end_date, l.start_date) + 1) as s FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id $join_user {$extra_where} l.status != 'rejected' GROUP BY g");
$accum_stmt->execute($params); $accum_raw = $accum_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ---------------------------------------------------------
// 2. ข้อมูลกราฟ 
// ---------------------------------------------------------
$type_stats = $pdo->prepare("
    SELECT 
        CASE 
            WHEN t.type_name LIKE '%ป่วย%' THEN 'ลาป่วย'
            WHEN t.type_name LIKE '%กิจ%' THEN 'ลากิจ'
            WHEN t.type_name LIKE '%พักผ่อน%' THEN 'ลาพักผ่อน'
            ELSE 'อื่นๆ'
        END as group_name, 
        SUM(DATEDIFF(l.end_date, l.start_date) + 1) as days 
    FROM leave_requests l 
    JOIN leave_types t ON l.type_id = t.type_id 
    $join_user {$extra_where} l.status != 'rejected' 
    GROUP BY group_name
");
$type_stats->execute($params); $type_data = $type_stats->fetchAll(PDO::FETCH_KEY_PAIR);

$monthly_stats = $pdo->prepare("SELECT DATE_FORMAT(l.created_at, '%m') as m, COUNT(*) as c FROM leave_requests l $join_user $where_str GROUP BY m ORDER BY m ASC");
$monthly_stats->execute($params); $monthly_raw = $monthly_stats->fetchAll(PDO::FETCH_KEY_PAIR);
$months_th = ['10'=>'ต.ค.', '11'=>'พ.ย.', '12'=>'ธ.ค.', '01'=>'ม.ค.', '02'=>'ก.พ.', '03'=>'มี.ค.', '04'=>'เม.ย.', '05'=>'พ.ค.', '06'=>'มิ.ย.', '07'=>'ก.ค.', '08'=>'ส.ค.', '09'=>'ก.ย.'];
$chart_months = []; $chart_counts = []; foreach ($months_th as $m_n => $m_t) { $chart_months[] = $m_t; $chart_counts[] = $monthly_raw[$m_n] ?? 0; }

// ---------------------------------------------------------
// 💡 3. แก้ไข Query ปฏิทิน ให้ตรงกับ Dashboard ทุกประการ
// ---------------------------------------------------------
$sql_cal = "SELECT l.leave_id, l.start_date, l.end_date, u.first_name, t.type_name, l.status 
            FROM leave_requests l 
            $join_user 
            JOIN leave_types t ON l.type_id = t.type_id 
            {$extra_where} l.status != 'rejected'";
$stmt_cal = $pdo->prepare($sql_cal);
$stmt_cal->execute($params);

$calendar_events = [];
foreach ($stmt_cal->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // กำหนดสีตามประเภทการลา
    $color = '#fbbf24'; // ค่าเริ่มต้น สีส้ม (ลากิจ หรือ อื่นๆ)
    if (strpos($row['type_name'], 'ป่วย') !== false) {
        $color = '#f87171'; // สีแดง
    } elseif (strpos($row['type_name'], 'พักผ่อน') !== false) {
        $color = '#4ade80'; // สีเขียว
    }
    
    // ใส่คำว่า [รอผล] นำหน้าถ้ารายการนั้นยังไม่อนุมัติ
    $title_prefix = ($row['status'] == 'pending') ? '[รอผล] ' : '';
    
    $calendar_events[] = [
        'id' => $row['leave_id'], 
        'title' => $title_prefix . $row['first_name'], 
        'start' => $row['start_date'], 
        'end' => date('Y-m-d', strtotime($row['end_date'] . ' +1 day')), // เพื่อให้ปฏิทินคลุมเต็มวัน
        'color' => $color, 
        'url' => 'view.php?id=' . $row['leave_id']
    ];
}

$all_users = $pdo->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name ASC")->fetchAll();
$sql_table = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, d.dept_name FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id $join_user LEFT JOIN departments d ON u.dept_id = d.dept_id $where_str ORDER BY l.created_at DESC";
$stmt_table = $pdo->prepare($sql_table); $stmt_table->execute($params);
$requests_data = $stmt_table->fetchAll();
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<style>
    .btn-purple { background-color: #4b0082 !important; color: #ffffff !important; border: none; }
    .btn-purple:hover { background-color: #39048f !important; color: #ffffff !important; }
    .btn-outline-purple { color: #4b0082 !important; border-color: #4b0082 !important; background-color: transparent !important; }
    .btn-outline-purple:hover { background-color: #4b0082 !important; color: #ffffff !important; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-4 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-chart-line me-2"></i>Executive Dashboard</h1>
            <p class="text-muted small">ระบบรายงานและวิเคราะห์ข้อมูลการลา (Leave Analytics) <?php echo ($filter_year === 'all' ? 'ทุกปีงบประมาณ' : 'ประจำปีงบประมาณ ' . htmlspecialchars($fy_th)); ?></p>
        </div>
        <div class="btn-toolbar align-items-center flex-nowrap gap-2">
            <a href="report_all_leaves.php<?php echo $filter_year ? ('?filter_year=' . urlencode($filter_year)) : ''; ?>" target="_blank" class="btn btn-sm btn-outline-purple rounded-pill px-3 py-1 shadow-sm fw-bold text-nowrap" title="รายงานวันลาทุกประเภท">
                <i class="fa-solid fa-table-list me-1"></i> รายงานวันลา
            </a>
            <a href="view_user_leaves.php?user_id=<?php echo $filter_user_id ? urlencode($filter_user_id) : 'all'; ?>&filter_year=<?php echo urlencode($filter_year); ?><?php echo $status_filter ? ('&status=' . urlencode($status_filter)) : ''; ?>" target="_blank" class="btn btn-sm btn-purple rounded-pill px-3 py-1 shadow-sm fw-bold text-nowrap" title="ดูใบลาทั้งหมด & Export PDF">
                <i class="fa-solid fa-file-pdf me-1"></i> ใบลาทั้งหมด (PDF)
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-sm btn-success rounded-pill px-3 py-1 shadow-sm text-nowrap" title="ประวัติการลา (CSV)"><i class="fa-solid fa-file-csv me-1"></i> ประวัติลา (CSV)</a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'summary'])); ?>" class="btn btn-sm btn-info text-white rounded-pill px-3 py-1 shadow-sm text-nowrap" title="สรุปยอดสะสม (CSV)"><i class="fa-solid fa-file-invoice me-1"></i> สรุปยอด (CSV)</a>
            <button class="btn btn-sm btn-light border text-dark rounded-pill px-2 py-1 shadow-sm" onclick="window.print()" title="พิมพ์หน้าจอ"><i class="fa-solid fa-print"></i></button>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-white h-100">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary me-4">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">วันลาสะสมรวม<?php echo ($filter_year === 'all' ? ' (ทั้งหมด)' : ' (ปีงบ ' . htmlspecialchars($fy_th) . ')'); ?></h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $stat_data['td'] ?: 0; ?> <span class="fs-6 text-muted fw-normal">วัน</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-white h-100">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning me-4">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">คำร้องรออนุมัติ</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $stat_data['p'] ?: 0; ?> <span class="fs-6 text-muted fw-normal">ฉบับ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-white h-100">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success me-4">
                        <i class="fa-solid fa-check-double"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">ดำเนินการสำเร็จ</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $stat_data['a'] ?: 0; ?> <span class="fs-6 text-muted fw-normal">ฉบับ</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 bg-white d-print-none">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fa-regular fa-calendar me-1"></i>ปีงบประมาณ</label>
                    <select name="filter_year" class="form-select bg-light border-0 shadow-sm" onchange="this.form.submit()">
                        <?php foreach($available_years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>>
                                ปีงบประมาณ <?php echo $y; ?><?php echo ($y == $active_year ? ' (ปีงบปัจจุบัน)' : ''); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="all" <?php echo ($filter_year === 'all') ? 'selected' : ''; ?>>-- ทุกปีงบประมาณ (ทั้งหมด) --</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-muted mb-0"><i class="fa-regular fa-user me-1"></i>กรองตามบุคคล</label>
                        <a href="view_user_leaves.php?user_id=<?php echo $filter_user_id ? urlencode($filter_user_id) : 'all'; ?>&filter_year=<?php echo urlencode($filter_year); ?><?php echo $status_filter ? ('&status=' . urlencode($status_filter)) : ''; ?>" target="_blank" class="text-purple extra-small fw-bold text-decoration-none" title="คลิกเพื่อเปิดดูใบลาทั้งหมดและพิมพ์/Export PDF">
                            <i class="fa-solid fa-file-pdf me-1 text-purple"></i>ดูใบลาทั้งหมด & Export PDF
                        </a>
                    </div>
                    <select name="user_id" class="form-select bg-light border-0 shadow-sm" onchange="this.form.submit()">
                        <option value="">-- กรองรายบุคคล (ทั้งหมด) --</option>
                        <?php foreach($all_users as $u) echo "<option value='".$u['user_id']."' ".($filter_user_id==$u['user_id']?'selected':'').">".$u['first_name']." ".$u['last_name']."</option>"; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหาชื่อ</label>
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-0 bg-light shadow-none" placeholder="ค้นหาชื่อพนักงาน..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2 mt-md-4 pt-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm">ค้นหา</button>
                    <?php if($filter_year != $active_year || $filter_user_id || $search): ?>
                        <a href="dashboard_admin.php" class="btn btn-light border rounded-pill shadow-sm" title="รีเซ็ตค่าเริ่มต้น"><i class="fa-solid fa-rotate-left"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Featured User Banner เมื่อเลือกกรองรายบุคคล -->
    <?php if (!empty($filter_user_id) && !empty($selected_user)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white border-start border-purple border-4 d-print-none">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-circle bg-purple text-white d-flex align-items-center justify-content-center rounded-circle shadow-xs" style="width: 52px; height: 52px; font-size: 1.4rem;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="fw-bold text-dark mb-0">
                                <?php echo htmlspecialchars($selected_user['prefix_name'] . $selected_user['first_name'] . ' ' . $selected_user['last_name']); ?>
                            </h5>
                            <span class="badge bg-purple px-2 py-1 extra-small rounded-pill"><?php echo htmlspecialchars($selected_user['position'] ?? 'บุคลากร'); ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            <span><i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($selected_user['dept_name'] ?? 'ไม่ระบุสังกัด'); ?></span>
                            <span class="ms-3"><i class="fa-solid fa-file-invoice me-1 text-purple"></i>พบใบลาตามเงื่อนไข: <strong class="text-purple"><?php echo count($requests_data); ?></strong> ฉบับ</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="view_user_leaves.php?user_id=<?php echo urlencode($filter_user_id); ?>&filter_year=<?php echo urlencode($filter_year); ?>" target="_blank" class="btn btn-purple rounded-pill px-4 py-2 shadow-sm fw-bold">
                        <i class="fa-solid fa-file-pdf me-2"></i> ดูใบลาทั้งหมด & Export PDF
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php 
        $ac = [
            'ลาป่วย' => ['color' => 'danger', 'icon' => 'fa-briefcase-medical'],
            'ลากิจ' => ['color' => 'warning', 'icon' => 'fa-car-side'],
            'ลาพักผ่อน' => ['color' => 'success', 'icon' => 'fa-umbrella-beach']
        ]; 
        foreach($ac as $l => $c): 
        ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-4 bg-white h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted fw-bold extra-small text-uppercase"><?php echo $l; ?>สะสม</small>
                        <h3 class="mb-0 fw-bold text-<?php echo $c['color']; ?> mt-1"><?php echo $accum_raw[$l] ?? 0; ?> <span class="fs-6 text-muted fw-normal">วัน</span></h3>
                        <div class="extra-small text-muted mt-2">*นับรวมใบที่รออนุมัติ</div>
                    </div>
                    <div class="stat-icon bg-<?php echo $c['color']; ?>-subtle text-<?php echo $c['color']; ?>">
                        <i class="fa-solid <?php echo $c['icon']; ?>"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white fw-bold py-4 text-dark border-0">
                    <i class="fa-solid fa-chart-pie me-2 text-primary"></i>สัดส่วนการลา (สะสมตั้งแต่ยื่น)
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <div style="position: relative; height:250px; width:100%">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white fw-bold py-4 text-dark border-0">
                    <i class="fa-solid fa-chart-column me-2 text-primary"></i>แนวโน้มการลาประจำเดือน
                </div>
                <div class="card-body">
                    <div style="position: relative; height:250px; width:100%">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-5 bg-white">
        <div class="card-header bg-white fw-bold py-4 text-dark border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="fa-regular fa-calendar-days me-2 text-primary"></i>ปฏิทินการลา (Leave Calendar)
            </div>
            <div class="small fw-normal text-muted">
                <span class="badge bg-light text-dark border me-2"><i class="fa-solid fa-clock me-1 text-muted"></i>มีคำว่า [รอผล] = รออนุมัติ</span>
                <span class="badge" style="background-color:#f87171"><i class="fa-solid fa-briefcase-medical me-1"></i>ป่วย</span>
                <span class="badge" style="background-color:#fbbf24"><i class="fa-solid fa-car-side me-1"></i>กิจ</span>
                <span class="badge" style="background-color:#4ade80"><i class="fa-solid fa-umbrella-beach me-1"></i>พักผ่อน</span>
            </div>
        </div>
        <div class="card-body p-4 pt-0">
            <div id="leaveCalendar" style="min-height: 450px;"></div>
        </div>
    </div>

    <div class="card shadow-sm border-0 bg-white mb-5">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0 flex-wrap gap-2">
            <div>
                <span class="fw-bold text-dark"><i class="fa-solid fa-list me-2 text-primary"></i>รายละเอียดคำร้องการลา</span>
                <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill ms-2"><?php echo count($requests_data); ?> รายการ</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="report_all_leaves.php<?php echo $filter_year ? ('?filter_year=' . urlencode($filter_year)) : ''; ?>" target="_blank" class="btn btn-sm btn-outline-purple rounded-pill px-3 py-2 shadow-sm fw-bold">
                    <i class="fa-solid fa-table-list me-1"></i> รายงานวันลาทุกประเภท
                </a>
                <a href="view_user_leaves.php?user_id=<?php echo $filter_user_id ? urlencode($filter_user_id) : 'all'; ?>&filter_year=<?php echo urlencode($filter_year); ?><?php echo $status_filter ? ('&status=' . urlencode($status_filter)) : ''; ?>" target="_blank" class="btn btn-sm btn-purple rounded-pill px-3 py-2 shadow-sm fw-bold">
                    <i class="fa-solid fa-file-pdf me-1"></i> ดูใบลาทั้งหมด & Export PDF (<?php echo count($requests_data); ?> ฉบับ)
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light extra-small text-uppercase">
                        <tr>
                            <th class="ps-4">วันที่ยื่น</th>
                            <th>ผู้ขอลา</th>
                            <th>ประเภท</th>
                            <th>ระยะเวลา</th>
                            <th class="text-center">วัน</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests_data)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted small"><i class="fa-solid fa-folder-open fs-2 mb-2 opacity-25"></i><br>ไม่มีข้อมูลตามเงื่อนไขที่กรอง</td></tr>
                        <?php else: ?>
                            <?php foreach ($requests_data as $req): $d = (strtotime($req['end_date']) - strtotime($req['start_date'])) / 86400 + 1; ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark small"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($req['first_name'].' '.$req['last_name']); ?></span>
                                        <a href="view_user_leaves.php?user_id=<?php echo urlencode($req['user_id']); ?>&filter_year=<?php echo urlencode($filter_year); ?>" target="_blank" class="text-purple extra-small ms-1" title="เปิดดูแฟ้มใบลาทั้งหมดของท่านนี้">
                                            <i class="fa-solid fa-folder-open"></i>
                                        </a>
                                    </div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($req['dept_name'] ?? '-'); ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border px-2 py-1 fw-normal"><?php echo htmlspecialchars($req['type_name']); ?></span></td>
                                <td><small class="text-muted"><?php echo date('d/m/y', strtotime($req['start_date'])); ?> - <?php echo date('d/m/y', strtotime($req['end_date'])); ?></small></td>
                                <td class="text-center fw-bold text-dark small"><?php echo $d; ?></td>
                                <td class="text-center">
                                    <?php if($req['status']=='approved'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success rounded-pill px-3 fw-normal"><i class="fa-solid fa-check me-1"></i>อนุมัติแล้ว</span>
                                    <?php elseif($req['status']=='rejected'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger rounded-pill px-3 fw-normal"><i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning rounded-pill px-3 fw-normal"><i class="fa-solid fa-clock me-1"></i>รออนุมัติ (ขั้นที่ <?php echo $req['current_step']; ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4 text-nowrap">
                                    <div class="btn-group shadow-xs rounded-pill overflow-hidden border bg-white" role="group">
                                        <a href="view.php?id=<?php echo $req['leave_id']; ?>" class="btn btn-sm btn-white text-primary border-0 px-2 extra-small fw-bold" title="ดูรายละเอียดใบนี้">
                                            <i class="fa-solid fa-eye me-1"></i> ดูใบนี้
                                        </a>
                                        <a href="view_user_leaves.php?user_id=<?php echo urlencode($req['user_id']); ?>&filter_year=<?php echo urlencode($filter_year); ?>" target="_blank" class="btn btn-sm btn-light text-purple border-start px-2 extra-small fw-bold" title="ดูใบลาทั้งหมดของ <?php echo htmlspecialchars($req['first_name']); ?> พร้อมพิมพ์/Export PDF">
                                            <i class="fa-solid fa-file-pdf me-1 text-purple"></i> ใบลาทั้งหมด
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js'></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Calendar ---
        <?php 
        $cal_initial_date = '';
        if (!empty($calendar_events)) {
            $latest_cal_start = $calendar_events[0]['start'] ?? '';
            foreach ($calendar_events as $ce) {
                if ($ce['start'] > $latest_cal_start) $latest_cal_start = $ce['start'];
            }
            $curr_fy_calc = (date('m') >= 10) ? (string)(date('Y') + 544) : (string)(date('Y') + 543);
            if ($filter_year !== 'all' && $filter_year !== $curr_fy_calc && $latest_cal_start) {
                $cal_initial_date = $latest_cal_start;
            }
        }
        ?>
        var calendar = new FullCalendar.Calendar(document.getElementById('leaveCalendar'), {
            initialView: 'dayGridMonth', locale: 'th',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
            <?php if (!empty($cal_initial_date)): ?>
            initialDate: '<?php echo $cal_initial_date; ?>',
            <?php endif; ?>
            events: <?php echo json_encode($calendar_events); ?>,
            themeSystem: 'bootstrap5'
        });
        calendar.render();

        // --- Chart.js Colors (Soft UI palette) ---
        const softColors = ['#818cf8', '#fb923c', '#4ade80', '#f472b6'];

        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($type_data)); ?>,
                datasets: [{ 
                    data: <?php echo json_encode(array_values($type_data)); ?>, 
                    backgroundColor: softColors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: { 
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {family: 'Sarabun'} } } },
                cutout: '65%'
            }
        });

        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_months); ?>,
                datasets: [{ 
                    label: 'จำนวนใบลา (ฉบับ)', 
                    data: <?php echo json_encode($chart_counts); ?>, 
                    backgroundColor: '#818cf8',
                    borderRadius: 6
                }]
            },
            options: { 
                scales: { 
                    y: { beginAtZero: true, grid: { borderDash: [2, 4], color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }, 
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>