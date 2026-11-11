<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ (เฉพาะ Admin และ ผู้บริหาร / งานบุคคล)
// ---------------------------------------------------------
$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($management_roles)) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='../../index.php';</script>";
    exit();
}

// ---------------------------------------------------------
// 2. รับค่าตัวกรอง (Filters)
// ---------------------------------------------------------
$active_year = (date('n') >= 10) ? date('Y') + 544 : date('Y') + 543;

// ดึงปีงบประมาณทั้งหมดที่มีในระบบ
$available_years = [];
try {
    $available_years = $pdo->query("SELECT year_name FROM fiscal_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

try {
    $db_years = $pdo->query("SELECT DISTINCT CASE WHEN MONTH(created_at) >= 10 THEN YEAR(created_at) + 544 ELSE YEAR(created_at) + 543 END as fy FROM leave_requests ORDER BY fy DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($db_years as $dy) {
        if ($dy && !in_array((string)$dy, $available_years)) {
            $available_years[] = (string)$dy;
        }
    }
    rsort($available_years);
} catch (Exception $e) {}

if (empty($available_years)) {
    $available_years = [(string)$active_year];
}

$filter_year = $_GET['filter_year'] ?? (string)$active_year;
$filter_dept = $_GET['dept_id'] ?? '';
$filter_status = $_GET['status'] ?? 'approved'; // ค่าเริ่มต้น: เฉพาะที่อนุมัติแล้ว
$search = trim($_GET['search'] ?? '');

// คำนวณช่วงวันที่ตามปีงบประมาณ
$date_cond = "1=1";
$params_date = [];
$fy_title = "ทุกปีงบประมาณ";

if ($filter_year !== 'all') {
    $fy_num = (int)$filter_year;
    $fy_en = ($fy_num > 2500) ? ($fy_num - 543) : $fy_num;
    $fy_start = ($fy_en - 1) . "-10-01 00:00:00";
    $fy_end = $fy_en . "-09-30 23:59:59";
    $date_cond = "l.created_at BETWEEN ? AND ?";
    $params_date = [$fy_start, $fy_end];
    $fy_title = "ประจำปีงบประมาณ พ.ศ. " . $filter_year;
}

// เงื่อนไขสถานะการลา
$status_sql = "1=1";
if ($filter_status === 'approved') {
    $status_sql = "l.status = 'approved'";
} elseif ($filter_status === 'all_valid') {
    $status_sql = "l.status IN ('approved', 'pending')";
} elseif ($filter_status === 'pending') {
    $status_sql = "l.status = 'pending'";
}

// ดึงรายชื่อสังกัด/หน่วยงาน
$departments = $pdo->query("SELECT dept_id, dept_name FROM departments ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// 3. Query ข้อมูลสรุปวันลาของบุคลากรทุกคน
// ---------------------------------------------------------
$where_user = ["u.status = 'active'"];
$params_user = [];

if (!empty($filter_dept)) {
    $where_user[] = "u.dept_id = ?";
    $params_user[] = $filter_dept;
}

if (!empty($search)) {
    $where_user[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.user_id LIKE ?)";
    $params_user[] = "%$search%";
    $params_user[] = "%$search%";
    $params_user[] = "%$search%";
}

$where_user_str = implode(" AND ", $where_user);

// รวม parameters: วันที่ก่อน แล้วตามด้วยเงื่อนไข User
$full_params = array_merge($params_date, $params_user);

$sql = "SELECT 
            u.user_id, 
            u.prefix_name, 
            u.first_name, 
            u.last_name, 
            u.position, 
            d.dept_name,
            -- ลาป่วย (Sick)
            COUNT(CASE WHEN (t.type_id = 1 OR t.type_name LIKE '%ป่วย%') AND $status_sql THEN 1 END) as sick_count,
            COALESCE(SUM(CASE WHEN (t.type_id = 1 OR t.type_name LIKE '%ป่วย%') AND $status_sql THEN (DATEDIFF(l.end_date, l.start_date) + 1) END), 0) as sick_days,
            -- ลากิจ (Personal)
            COUNT(CASE WHEN (t.type_id = 2 OR t.type_name LIKE '%กิจ%') AND $status_sql THEN 1 END) as personal_count,
            COALESCE(SUM(CASE WHEN (t.type_id = 2 OR t.type_name LIKE '%กิจ%') AND $status_sql THEN (DATEDIFF(l.end_date, l.start_date) + 1) END), 0) as personal_days,
            -- ลาพักผ่อน (Vacation)
            COUNT(CASE WHEN (t.type_id = 3 OR t.type_name LIKE '%พักผ่อน%') AND $status_sql THEN 1 END) as vacation_count,
            COALESCE(SUM(CASE WHEN (t.type_id = 3 OR t.type_name LIKE '%พักผ่อน%') AND $status_sql THEN (DATEDIFF(l.end_date, l.start_date) + 1) END), 0) as vacation_days,
            -- ลาอุปสมบท (Ordination)
            COUNT(CASE WHEN (t.type_id = 4 OR t.type_name LIKE '%อุปสมบท%' OR t.type_name LIKE '%บวช%') AND $status_sql THEN 1 END) as ordination_count,
            COALESCE(SUM(CASE WHEN (t.type_id = 4 OR t.type_name LIKE '%อุปสมบท%' OR t.type_name LIKE '%บวช%') AND $status_sql THEN (DATEDIFF(l.end_date, l.start_date) + 1) END), 0) as ordination_days,
            -- ลาคลอด (Maternity)
            COUNT(CASE WHEN (t.type_id = 5 OR t.type_name LIKE '%คลอด%') AND $status_sql THEN 1 END) as maternity_count,
            COALESCE(SUM(CASE WHEN (t.type_id = 5 OR t.type_name LIKE '%คลอด%') AND $status_sql THEN (DATEDIFF(l.end_date, l.start_date) + 1) END), 0) as maternity_days
        FROM users u
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        LEFT JOIN leave_requests l ON u.user_id = l.user_id AND $date_cond
        LEFT JOIN leave_types t ON l.type_id = t.type_id
        WHERE $where_user_str
        GROUP BY u.user_id, u.prefix_name, u.first_name, u.last_name, u.position, d.dept_name
        ORDER BY u.first_name ASC, u.last_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($full_params);
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// คำนวณยอดรวมทั้งสิ้น (Grand Totals)
$totals = [
    'sick_count' => 0, 'sick_days' => 0,
    'personal_count' => 0, 'personal_days' => 0,
    'vacation_count' => 0, 'vacation_days' => 0,
    'ordination_count' => 0, 'ordination_days' => 0,
    'maternity_count' => 0, 'maternity_days' => 0,
    'sp_count' => 0, 'sp_days' => 0
];

foreach ($report_data as $row) {
    $sp_c = (int)$row['sick_count'] + (int)$row['personal_count'];
    $sp_d = (float)$row['sick_days'] + (float)$row['personal_days'];
    
    $totals['sick_count'] += (int)$row['sick_count'];
    $totals['sick_days'] += (float)$row['sick_days'];
    $totals['personal_count'] += (int)$row['personal_count'];
    $totals['personal_days'] += (float)$row['personal_days'];
    $totals['vacation_count'] += (int)$row['vacation_count'];
    $totals['vacation_days'] += (float)$row['vacation_days'];
    $totals['ordination_count'] += (int)$row['ordination_count'];
    $totals['ordination_days'] += (float)$row['ordination_days'];
    $totals['maternity_count'] += (int)$row['maternity_count'];
    $totals['maternity_days'] += (float)$row['maternity_days'];
    $totals['sp_count'] += $sp_c;
    $totals['sp_days'] += $sp_d;
}

// ---------------------------------------------------------
// 4. กรณีส่งออกเป็น Excel (.xls) หรือ CSV
// ---------------------------------------------------------
$export_mode = $_GET['export'] ?? '';

if ($export_mode === 'excel') {
    if (ob_get_length()) ob_end_clean();
    $filename = "leave_report_" . ($filter_year !== 'all' ? "FY$filter_year" : "ALL") . "_" . date('Ymd_His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>
    <body>
        <table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse; font-family:'Sarabun', 'Tahoma', sans-serif;">
            <thead>
                <tr>
                    <th colspan="14" style="font-size:14pt; font-weight:bold; text-align:center; height:35px; background-color:#e8eaf6;">
                        รายงานวันลาทุกประเภท วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
                    </th>
                </tr>
                <tr>
                    <th colspan="14" style="font-size:10pt; text-align:center; height:25px; background-color:#f5f5f5;">
                        <?php echo $fy_title; ?> (สถานะ: <?php echo ($filter_status==='approved'?'เฉพาะที่อนุมัติแล้ว':($filter_status==='pending'?'รออนุมัติ':'ทุกสถานะ')); ?>) | ข้อมูล ณ วันที่ <?php echo date('d/m/Y H:i'); ?>
                    </th>
                </tr>
                <tr style="background-color:#d7ccc8; text-align:center; font-weight:bold;">
                    <th rowspan="2" style="width:45px;">ที่</th>
                    <th rowspan="2" style="width:220px;">ชื่อ - นามสกุล</th>
                    <th colspan="2">ลาป่วย</th>
                    <th colspan="2">ลากิจ</th>
                    <th colspan="2">ลาพักผ่อน</th>
                    <th colspan="2">ลาอุปสมบท</th>
                    <th colspan="2">ลาคลอด</th>
                    <th colspan="2">ยอดรวม<br>ลาป่วย+ลากิจ</th>
                </tr>
                <tr style="background-color:#efebe9; text-align:center; font-weight:bold;">
                    <th style="width:55px;">ครั้ง</th>
                    <th style="width:55px;">วัน</th>
                    <th style="width:55px;">ครั้ง</th>
                    <th style="width:55px;">วัน</th>
                    <th style="width:55px;">ครั้ง</th>
                    <th style="width:55px;">วัน</th>
                    <th style="width:55px;">ครั้ง</th>
                    <th style="width:55px;">วัน</th>
                    <th style="width:55px;">ครั้ง</th>
                    <th style="width:55px;">วัน</th>
                    <th style="width:55px; background-color:#ffebee;">ครั้ง</th>
                    <th style="width:55px; background-color:#ffebee;">วัน</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($report_data as $row): 
                    $sp_c = (int)$row['sick_count'] + (int)$row['personal_count'];
                    $sp_d = (float)$row['sick_days'] + (float)$row['personal_days'];
                ?>
                <tr>
                    <td style="text-align:center;"><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($row['prefix_name'] . $row['first_name'] . ' ' . $row['last_name']); ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['sick_count']; ?></td>
                    <td style="text-align:center;"><?php echo (float)$row['sick_days']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['personal_count']; ?></td>
                    <td style="text-align:center;"><?php echo (float)$row['personal_days']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['vacation_count']; ?></td>
                    <td style="text-align:center;"><?php echo (float)$row['vacation_days']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['ordination_count']; ?></td>
                    <td style="text-align:center;"><?php echo (float)$row['ordination_days']; ?></td>
                    <td style="text-align:center;"><?php echo (int)$row['maternity_count']; ?></td>
                    <td style="text-align:center;"><?php echo (float)$row['maternity_days']; ?></td>
                    <td style="text-align:center; font-weight:bold; background-color:#ffebee;"><?php echo $sp_c; ?></td>
                    <td style="text-align:center; font-weight:bold; background-color:#ffebee;"><?php echo $sp_d; ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color:#fff9c4; font-weight:bold;">
                    <td colspan="2" style="text-align:center;">ยอดรวมทั้งสิ้น</td>
                    <td style="text-align:center;"><?php echo $totals['sick_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['sick_days']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['personal_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['personal_days']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['vacation_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['vacation_days']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['ordination_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['ordination_days']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['maternity_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['maternity_days']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['sp_count']; ?></td>
                    <td style="text-align:center;"><?php echo $totals['sp_days']; ?></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit();
}

if ($export_mode === 'csv') {
    if (ob_get_length()) ob_end_clean();
    $filename = "leave_report_" . ($filter_year !== 'all' ? "FY$filter_year" : "ALL") . "_" . date('Ymd_His') . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Header
    fputcsv($output, ['รายงานวันลาทุกประเภท วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม']);
    fputcsv($output, [$fy_title . " (ข้อมูล ณ " . date('d/m/Y H:i') . ")"]);
    fputcsv($output, []);
    fputcsv($output, [
        'ที่', 'ชื่อ - นามสกุล', 'สังกัด',
        'ลาป่วย(ครั้ง)', 'ลาป่วย(วัน)',
        'ลากิจ(ครั้ง)', 'ลากิจ(วัน)',
        'ลาพักผ่อน(ครั้ง)', 'ลาพักผ่อน(วัน)',
        'ลาอุปสมบท(ครั้ง)', 'ลาอุปสมบท(วัน)',
        'ลาคลอด(ครั้ง)', 'ลาคลอด(วัน)',
        'ยอดรวมลาป่วย+ลากิจ(ครั้ง)', 'ยอดรวมลาป่วย+ลากิจ(วัน)'
    ]);
    
    $i = 1;
    foreach ($report_data as $row) {
        $sp_c = (int)$row['sick_count'] + (int)$row['personal_count'];
        $sp_d = (float)$row['sick_days'] + (float)$row['personal_days'];
        fputcsv($output, [
            $i++,
            $row['prefix_name'] . $row['first_name'] . ' ' . $row['last_name'],
            $row['dept_name'] ?? '-',
            (int)$row['sick_count'], (float)$row['sick_days'],
            (int)$row['personal_count'], (float)$row['personal_days'],
            (int)$row['vacation_count'], (float)$row['vacation_days'],
            (int)$row['ordination_count'], (float)$row['ordination_days'],
            (int)$row['maternity_count'], (float)$row['maternity_days'],
            $sp_c, $sp_d
        ]);
    }
    
    // Total Row
    fputcsv($output, [
        '', 'ยอดรวมทั้งสิ้น', '',
        $totals['sick_count'], $totals['sick_days'],
        $totals['personal_count'], $totals['personal_days'],
        $totals['vacation_count'], $totals['vacation_days'],
        $totals['ordination_count'], $totals['ordination_days'],
        $totals['maternity_count'], $totals['maternity_days'],
        $totals['sp_count'], $totals['sp_days']
    ]);
    
    fclose($output);
    exit();
}

$page_title = "รายงานวันลาทุกประเภท - " . $fy_title;

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Top Action Bar (ซ่อนเมื่อสั่งพิมพ์) -->
    <div class="d-print-none pt-4 pb-3 mb-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-1">
                    <ol class="breadcrumb extra-small mb-0">
                        <li class="breadcrumb-item"><a href="dashboard_admin.php" class="text-decoration-none text-muted"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a></li>
                        <li class="breadcrumb-item active text-purple" aria-current="page">รายงานวันลาทุกประเภท</li>
                    </ol>
                </nav>
                <h2 class="h3 fw-bold text-dark mb-0">
                    <i class="fa-solid fa-table-list text-purple me-2"></i>รายงานวันลาทุกประเภท
                </h2>
                <div class="small text-muted mt-1">
                    <span class="badge bg-purple-subtle text-purple me-2"><i class="fa-solid fa-calendar me-1"></i><?php echo htmlspecialchars($fy_title); ?></span>
                    <span>บุคลากรทั้งหมดในรายงาน: <strong class="text-purple"><?php echo count($report_data); ?></strong> ท่าน</span>
                </div>
            </div>

            <!-- Toolbar Buttons -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="dashboard_admin.php<?php echo $filter_year ? ('?filter_year=' . urlencode($filter_year)) : ''; ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-xs small">
                    <i class="fa-solid fa-chevron-left me-1"></i> กลับหน้า Dashboard
                </a>
                
                <!-- ปุ่มส่งออก Excel -->
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success rounded-pill px-3 py-2 shadow-xs fw-bold small">
                    <i class="fa-solid fa-file-excel me-1"></i> ส่งออก Excel (.xls)
                </a>

                <!-- ปุ่มส่งออก CSV -->
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-success rounded-pill px-3 py-2 shadow-xs fw-bold small">
                    <i class="fa-solid fa-file-csv me-1"></i> CSV
                </a>

                <!-- ปุ่มสั่งพิมพ์ / บันทึก PDF แนวนอน -->
                <button type="button" onclick="window.print()" class="btn btn-purple rounded-pill px-4 py-2 shadow fw-bold">
                    <i class="fa-solid fa-print me-2 align-middle"></i> พิมพ์ / บันทึก PDF
                </button>
            </div>
        </div>

        <!-- Filter Card Bar -->
        <div class="card border-0 shadow-sm rounded-4 p-3 mt-3 bg-white">
            <form method="GET" class="row g-2 align-items-center">
                
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-regular fa-calendar me-1"></i> ปีงบ:</span>
                        <select name="filter_year" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <?php foreach($available_years as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>>
                                    ปีงบประมาณ <?php echo $y; ?><?php echo ($y == $active_year ? ' (ปัจจุบัน)' : ''); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="all" <?php echo ($filter_year === 'all') ? 'selected' : ''; ?>>-- ทุกปีงบประมาณ --</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-solid fa-building me-1"></i> สังกัด:</span>
                        <select name="dept_id" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="">-- ทุกสังกัด/สาขาวิชา --</option>
                            <?php foreach($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo ($filter_dept == $d['dept_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['dept_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-solid fa-filter me-1"></i> สถานะ:</span>
                        <select name="status" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="approved" <?php echo ($filter_status === 'approved') ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                            <option value="all_valid" <?php echo ($filter_status === 'all_valid') ? 'selected' : ''; ?>>อนุมัติ + รอผล</option>
                            <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>รออนุมัติ</option>
                            <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>ทุกสถานะ</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-solid fa-magnifying-glass me-1"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-0" placeholder="ค้นหาชื่อบุคลากร..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="col-md-1 text-end">
                    <button type="submit" class="btn btn-sm btn-purple rounded-pill px-3 w-100 fw-bold">กรอง</button>
                </div>
            </form>
        </div>
    </div>

    <!-- หัวรายงานสำหรับพิมพ์ (Print Only) -->
    <div class="print-header d-none d-print-block mb-3 text-center">
        <h4 class="fw-bold mb-1">รายงานสรุปวันลาทุกประเภทของบุคลากร</h4>
        <h5 class="fw-normal mb-1">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</h5>
        <div class="small text-muted">
            <?php echo htmlspecialchars($fy_title); ?> 
            (สถานะ: <?php echo ($filter_status==='approved'?'เฉพาะที่อนุมัติแล้ว':($filter_status==='pending'?'รออนุมัติ':'ทุกสถานะ')); ?>)
            | ข้อมูล ณ วันที่ <?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>

    <!-- ตารางรายงานตรงตามแบบฟอร์มที่ระบุ (Excel-like Grid) -->
    <div class="card border-0 shadow-sm bg-white rounded-4 overflow-hidden mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 report-grid">
                    <thead>
                        <tr class="header-main text-center">
                            <th rowspan="2" class="col-seq">ที่</th>
                            <th rowspan="2" class="col-name text-start ps-3">ชื่อ - นามสกุล</th>
                            <th colspan="2" class="col-leave bg-leave-sick">ลาป่วย</th>
                            <th colspan="2" class="col-leave bg-leave-personal">ลากิจ</th>
                            <th colspan="2" class="col-leave bg-leave-vacation">ลาพักผ่อน</th>
                            <th colspan="2" class="col-leave bg-leave-ord">ลาอุปสมบท</th>
                            <th colspan="2" class="col-leave bg-leave-mat">ลาคลอด</th>
                            <th colspan="2" class="col-leave bg-leave-total">ยอดรวม<br>ลาป่วย+ลากิจ</th>
                        </tr>
                        <tr class="header-sub text-center">
                            <!-- ลาป่วย -->
                            <th class="col-sub">ครั้ง</th>
                            <th class="col-sub">วัน</th>
                            <!-- ลากิจ -->
                            <th class="col-sub">ครั้ง</th>
                            <th class="col-sub">วัน</th>
                            <!-- ลาพักผ่อน -->
                            <th class="col-sub">ครั้ง</th>
                            <th class="col-sub">วัน</th>
                            <!-- ลาอุปสมบท -->
                            <th class="col-sub">ครั้ง</th>
                            <th class="col-sub">วัน</th>
                            <!-- ลาคลอด -->
                            <th class="col-sub">ครั้ง</th>
                            <th class="col-sub">วัน</th>
                            <!-- ยอดรวม ลาป่วย+ลากิจ -->
                            <th class="col-sub text-danger fw-bold bg-leave-total-sub">ครั้ง</th>
                            <th class="col-sub text-danger fw-bold bg-leave-total-sub">วัน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="14" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fs-2 mb-2 opacity-25"></i><br>
                                    ไม่พบข้อมูลตามเงื่อนไขที่เลือก
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $seq = 1;
                            foreach ($report_data as $row): 
                                $sp_c = (int)$row['sick_count'] + (int)$row['personal_count'];
                                $sp_d = (float)$row['sick_days'] + (float)$row['personal_days'];
                                $has_leave = ($sp_c > 0 || (int)$row['vacation_count'] > 0 || (int)$row['ordination_count'] > 0 || (int)$row['maternity_count'] > 0);
                            ?>
                            <tr class="<?php echo $has_leave ? 'row-has-leave' : ''; ?>">
                                <td class="text-center text-muted fw-bold"><?php echo $seq++; ?></td>
                                <td class="ps-3">
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($row['prefix_name'] . $row['first_name'] . ' ' . $row['last_name']); ?>
                                        <a href="view_user_leaves.php?user_id=<?php echo urlencode($row['user_id']); ?>&filter_year=<?php echo urlencode($filter_year); ?>" target="_blank" class="text-purple extra-small ms-1 d-print-none" title="เปิดดูแฟ้มใบลาทั้งหมดของท่านนี้">
                                            <i class="fa-solid fa-folder-open"></i>
                                        </a>
                                    </div>
                                    <div class="extra-small text-muted d-print-none">
                                        <?php echo htmlspecialchars($row['position'] ?? 'บุคลากร'); ?> | <?php echo htmlspecialchars($row['dept_name'] ?? '-'); ?>
                                    </div>
                                </td>
                                <!-- ลาป่วย -->
                                <td class="text-center <?php echo $row['sick_count'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (int)$row['sick_count']; ?>
                                </td>
                                <td class="text-center <?php echo $row['sick_days'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (float)$row['sick_days']; ?>
                                </td>
                                <!-- ลากิจ -->
                                <td class="text-center <?php echo $row['personal_count'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (int)$row['personal_count']; ?>
                                </td>
                                <td class="text-center <?php echo $row['personal_days'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (float)$row['personal_days']; ?>
                                </td>
                                <!-- ลาพักผ่อน -->
                                <td class="text-center <?php echo $row['vacation_count'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (int)$row['vacation_count']; ?>
                                </td>
                                <td class="text-center <?php echo $row['vacation_days'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (float)$row['vacation_days']; ?>
                                </td>
                                <!-- ลาอุปสมบท -->
                                <td class="text-center <?php echo $row['ordination_count'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (int)$row['ordination_count']; ?>
                                </td>
                                <td class="text-center <?php echo $row['ordination_days'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (float)$row['ordination_days']; ?>
                                </td>
                                <!-- ลาคลอด -->
                                <td class="text-center <?php echo $row['maternity_count'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (int)$row['maternity_count']; ?>
                                </td>
                                <td class="text-center <?php echo $row['maternity_days'] > 0 ? 'fw-bold text-dark' : 'text-muted'; ?>">
                                    <?php echo (float)$row['maternity_days']; ?>
                                </td>
                                <!-- ยอดรวม ลาป่วย+ลากิจ -->
                                <td class="text-center fw-bold bg-leave-total-col <?php echo $sp_c > 0 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo $sp_c; ?>
                                </td>
                                <td class="text-center fw-bold bg-leave-total-col <?php echo $sp_d > 0 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo $sp_d; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="footer-total text-center fw-bold">
                            <td colspan="2" class="text-center py-3 bg-total-label">
                                <span class="fs-6">ยอดรวมทั้งสิ้น (<?php echo count($report_data); ?> ท่าน)</span>
                            </td>
                            <!-- ลาป่วย -->
                            <td class="bg-total-val"><?php echo $totals['sick_count']; ?></td>
                            <td class="bg-total-val"><?php echo $totals['sick_days']; ?></td>
                            <!-- ลากิจ -->
                            <td class="bg-total-val"><?php echo $totals['personal_count']; ?></td>
                            <td class="bg-total-val"><?php echo $totals['personal_days']; ?></td>
                            <!-- ลาพักผ่อน -->
                            <td class="bg-total-val"><?php echo $totals['vacation_count']; ?></td>
                            <td class="bg-total-val"><?php echo $totals['vacation_days']; ?></td>
                            <!-- ลาอุปสมบท -->
                            <td class="bg-total-val"><?php echo $totals['ordination_count']; ?></td>
                            <td class="bg-total-val"><?php echo $totals['ordination_days']; ?></td>
                            <!-- ลาคลอด -->
                            <td class="bg-total-val"><?php echo $totals['maternity_count']; ?></td>
                            <td class="bg-total-val"><?php echo $totals['maternity_days']; ?></td>
                            <!-- ยอดรวม ลาป่วย+ลากิจ -->
                            <td class="bg-total-sp text-danger fs-6"><?php echo $totals['sp_count']; ?></td>
                            <td class="bg-total-sp text-danger fs-6"><?php echo $totals['sp_days']; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</main>

<style>
    .text-purple { color: #4b0082 !important; }
    .bg-purple { background-color: #4b0082 !important; color: white !important; }
    .bg-purple-subtle { background-color: rgba(75, 0, 130, 0.08) !important; }
    .btn-purple { background-color: #4b0082 !important; color: white !important; border: none; }
    .btn-purple:hover { background-color: #39048f !important; color: white !important; }
    .btn-outline-purple { color: #4b0082 !important; border-color: #4b0082 !important; }
    .btn-outline-purple:hover { background-color: #4b0082 !important; color: white !important; }
    .extra-small { font-size: 0.75rem; }
    .shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

    /* Report Grid Styling */
    .report-grid {
        font-family: 'Anuphan', sans-serif;
        border-color: #dee2e6;
        font-size: 0.9rem;
    }
    .report-grid th, .report-grid td {
        border-color: #d1d5db !important;
        padding: 8px 6px;
    }
    .header-main th {
        background-color: #f1f5f9;
        font-weight: 700;
        color: #1e293b;
        vertical-align: middle;
    }
    .header-sub th {
        background-color: #f8fafc;
        font-weight: 600;
        color: #475569;
        font-size: 0.82rem;
    }
    .col-seq { width: 50px; }
    .col-name { min-width: 220px; }
    .col-sub { width: 58px; }
    
    .bg-leave-sick { background-color: #fee2e2 !important; color: #991b1b !important; }
    .bg-leave-personal { background-color: #fef3c7 !important; color: #92400e !important; }
    .bg-leave-vacation { background-color: #dcfce7 !important; color: #166534 !important; }
    .bg-leave-ord { background-color: #e0e7ff !important; color: #3730a3 !important; }
    .bg-leave-mat { background-color: #fce7f3 !important; color: #9d174d !important; }
    .bg-leave-total { background-color: #f3e8ff !important; color: #581c87 !important; }
    .bg-leave-total-sub { background-color: #fbf5ff !important; }
    .bg-leave-total-col { background-color: rgba(243, 232, 255, 0.3) !important; }

    .row-has-leave {
        background-color: #ffffff;
    }
    .report-grid tbody tr:hover {
        background-color: #f8fafc !important;
    }

    .footer-total td {
        background-color: #fef08a !important;
        color: #713f12;
        border-top: 2px solid #ca8a04 !important;
        border-bottom: 2px solid #ca8a04 !important;
    }
    .bg-total-label { background-color: #fef9c3 !important; }
    .bg-total-val { background-color: #fef08a !important; font-weight: 700; }
    .bg-total-sp { background-color: #fed7aa !important; font-weight: 800; }

    /* CSS Print สำหรับพิมพ์แบบ A4 แนวนอน (Landscape) */
    @media print {
        @page {
            size: A4 landscape;
            margin: 8mm 8mm;
        }
        body {
            background-color: white !important;
            font-size: 8.5pt !important;
        }
        .sidebar, .header, .navbar, .d-print-none, footer, .footer, #footer, .site-footer {
            display: none !important;
        }
        .col-md-9, .col-lg-10 {
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            max-width: 100% !important;
            flex: 0 0 100% !important;
        }
        main {
            margin-top: 0 !important;
            padding: 0 !important;
            background: white !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .report-grid {
            font-size: 8pt !important;
            width: 100% !important;
        }
        .report-grid th, .report-grid td {
            padding: 3px 4px !important;
            border-color: #555 !important;
        }
        .col-sub { width: 45px !important; }
        .col-name { min-width: 160px !important; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>
