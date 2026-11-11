<?php
// เปิด Error สำหรับการตรวจสอบ
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. เช็คสิทธิ์ผู้พิจารณา 5 ขั้นตอน
// 1. หัวหน้าสำนักงาน (หัวหน้าภาค)
// 2. งานการเงิน
// 3. หัวหน้าสำนักงาน (หัวหน้าภาค)
// 4. รองบริหาร
// 5. คณบดี
// ---------------------------------------------------------
$approve_roles = [
    'admin', 
    'งานการเงิน', 
    'หัวหน้าสำนักงาน', 
    'หัวหน้าภาควิชาดนตรีศึกษา', 
    'หัวหน้าภาควิชาดุริยางคศาสตร์', 
    'รองคณบดีฝ่ายบริหาร', 
    'คณบดี', 
    'รักษาการคณบดี'
];
if (!hasRole($approve_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง: สำหรับผู้พิจารณาคำขอเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$current_user_id = $_SESSION['user_id'];
$is_admin = hasRole(['admin']);

// ระบุบทบาทปัจจุบันของผู้ใช้เพื่อแสดงผล
$user_roles_list = [];
if ($is_admin) $user_roles_list[] = 'ผู้ดูแลระบบ (Admin)';
if (hasRole(['หัวหน้าสำนักงาน'])) $user_roles_list[] = 'หัวหน้าสำนักงานเลขานุการ';
if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) $user_roles_list[] = 'หัวหน้าภาควิชาดนตรีศึกษา';
if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) $user_roles_list[] = 'หัวหน้าภาควิชาดุริยางคศาสตร์';
if (hasRole(['งานการเงิน'])) $user_roles_list[] = 'เจ้าหน้าที่งานการเงินและพัสดุ';
if (hasRole(['รองคณบดีฝ่ายบริหาร'])) $user_roles_list[] = 'รองคณบดีฝ่ายบริหาร';
if (hasRole(['คณบดี', 'รักษาการคณบดี'])) $user_roles_list[] = 'คณบดีวิทยาลัยดุริยางคศิลป์';

// ---------------------------------------------------------
// 2. กำหนดเงื่อนไข SQL สำหรับขั้นตอนที่รอการพิจารณา (Pending)
// ---------------------------------------------------------
$step_conditions = [];

if ($is_admin) {
    $step_conditions[] = "tr.current_step IN (1, 2, 3, 4, 5)";
} else {
    // Step 1 & 3: หัวหน้าสำนักงาน (หัวหน้าภาค)
    if (hasRole(['หัวหน้าสำนักงาน'])) {
        $step_conditions[] = "(tr.current_step IN (1, 3) AND (u.dept_id = 11 OR u.dept_id IS NULL OR tr.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1)))";
    }
    if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) {
        $step_conditions[] = "(tr.current_step IN (1, 3) AND u.dept_id = 12)";
    }
    if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) {
        $step_conditions[] = "(tr.current_step IN (1, 3) AND u.dept_id = 13)";
    }

    // Step 2: งานการเงิน
    if (hasRole(['งานการเงิน'])) {
        $step_conditions[] = "tr.current_step = 2";
    }

    // Step 4: รองบริหาร
    if (hasRole(['รองคณบดีฝ่ายบริหาร'])) {
        $step_conditions[] = "tr.current_step = 4";
    }

    // Step 5: คณบดี
    if (hasRole(['คณบดี', 'รักษาการคณบดี'])) {
        $step_conditions[] = "tr.current_step = 5";
    }
}

$pending_sql = !empty($step_conditions) ? "(" . implode(" OR ", $step_conditions) . ")" : "1=0";

// ดึงปีงบประมาณทั้งหมด และหาปีงบประมาณปัจจุบัน (is_active = 1)
$stmt_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years_all = $stmt_fy->fetchAll(PDO::FETCH_ASSOC);

$current_fiscal_year = '';
foreach ($fiscal_years_all as $fy) {
    if (!empty($fy['is_active'])) {
        $current_fiscal_year = $fy['year_name'];
        break;
    }
}
if (empty($current_fiscal_year) && !empty($fiscal_years_all)) {
    $current_fiscal_year = $fiscal_years_all[0]['year_name'];
}

// แท็บและตัวกรองปีงบประมาณ (ค่าเริ่มต้นให้เป็นปีงบประมาณปัจจุบัน เช่น 2570)
$active_tab = $_GET['tab'] ?? 'pending';

// หากไม่มีการระบุค่า $_GET['year'] ให้เริ่มต้นที่ปีงบประมาณปัจจุบัน
if (!isset($_GET['year'])) {
    $filter_year = $current_fiscal_year;
} else {
    $filter_year = trim($_GET['year']);
}

// เงื่อนไขตัวกรองปีงบประมาณสำหรับคำนวณสถิติและดึงข้อมูล
$fy_filter_sql = "";
if (!empty($filter_year) && $filter_year !== 'all') {
    $fy_filter_sql = " AND tr.fiscal_year = " . $pdo->quote($filter_year);
}

// นับจำนวนสำหรับแต่ละแท็บ (Badges)
$cnt_pending = $pdo->query("SELECT COUNT(*) FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.status = 'pending' AND {$pending_sql} {$fy_filter_sql}")->fetchColumn();

// รายการที่ส่งกลับแก้ไข (Returned) ที่เกี่ยวข้องกับสายงานของผู้พิจารณา
$cnt_returned = $pdo->query("
    SELECT COUNT(*) 
    FROM training_reports tr 
    JOIN users u ON tr.user_id = u.user_id 
    WHERE tr.status = 'returned' 
    AND (" . ($is_admin ? "1=1" : "tr.step1_by = '$current_user_id' OR tr.step2_by = '$current_user_id' OR tr.step3_by = '$current_user_id' OR tr.step4_by = '$current_user_id' OR tr.step5_by = '$current_user_id' OR {$pending_sql}") . ")
    {$fy_filter_sql}
")->fetchColumn();

// รายการที่ได้รับอนุมัติ/เสร็จสิ้นแล้ว (History)
$cnt_approved = $pdo->query("
    SELECT COUNT(*) 
    FROM training_reports tr 
    JOIN users u ON tr.user_id = u.user_id 
    WHERE tr.status IN ('approved', 'reported')
    AND (" . ($is_admin || hasRole(['คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร']) ? "1=1" : "tr.step1_by = '$current_user_id' OR tr.step2_by = '$current_user_id' OR tr.step3_by = '$current_user_id' OR tr.step4_by = '$current_user_id' OR tr.step5_by = '$current_user_id'") . ")
    {$fy_filter_sql}
")->fetchColumn();

// คำนวณยอดงบประมาณรวมที่รอการพิจารณาในขั้นของคุณ
$total_pending_budget = $pdo->query("
    SELECT SUM(IFNULL(tr.est_budget_travel, 0) + IFNULL(tr.est_budget_accom, 0) + IFNULL(tr.est_budget_allowance, 0) + IFNULL(tr.est_budget_regis, 0) + IFNULL(tr.est_budget_other, 0)) 
    FROM training_reports tr 
    JOIN users u ON tr.user_id = u.user_id 
    WHERE tr.status = 'pending' AND {$pending_sql} {$fy_filter_sql}
")->fetchColumn() ?: 0;

// ตรวจสอบว่ามีคำขอรอการพิจารณาในปีก่อนหน้าหรือไม่ (เพื่อแจ้งเตือนไม่ให้ตกหล่น)
$cnt_pending_other_years = 0;
if (!empty($filter_year) && $filter_year !== 'all') {
    $cnt_pending_other_years = $pdo->query("SELECT COUNT(*) FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.status = 'pending' AND {$pending_sql} AND tr.fiscal_year != " . $pdo->quote($filter_year))->fetchColumn();
}

// ---------------------------------------------------------
// 3. ดึงรายการข้อมูลตามแท็บที่เลือก
// ---------------------------------------------------------
$where = [];
$params = [];

if (!empty($filter_year) && $filter_year !== 'all') {
    $where[] = "tr.fiscal_year = ?";
    $params[] = $filter_year;
}

if ($active_tab === 'pending') {
    $where[] = "tr.status = 'pending'";
    $where[] = $pending_sql;
    $order_sql = "tr.created_at ASC";
} elseif ($active_tab === 'returned') {
    $where[] = "tr.status = 'returned'";
    if (!$is_admin) {
        $where[] = "(tr.step1_by = ? OR tr.step2_by = ? OR tr.step3_by = ? OR tr.step4_by = ? OR tr.step5_by = ? OR {$pending_sql})";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }
    $order_sql = "tr.created_at DESC";
} elseif ($active_tab === 'history') {
    $where[] = "tr.status IN ('approved', 'reported')";
    if (!$is_admin && !hasRole(['คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร'])) {
        $where[] = "(tr.step1_by = ? OR tr.step2_by = ? OR tr.step3_by = ? OR tr.step4_by = ? OR tr.step5_by = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }
    $order_sql = "tr.created_at DESC";
} else { // 'all'
    $where[] = "1=1";
    $order_sql = "tr.created_at DESC";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

try {
    $stmt = $pdo->prepare("
        SELECT tr.report_id, tr.project_name, tr.organizer, tr.location, tr.report_type,
               tr.start_date, tr.end_date, tr.travel_start_date, tr.travel_end_date,
               tr.created_at, tr.status, tr.current_step, tr.fiscal_year,
               tr.est_budget_travel, tr.est_budget_accom, tr.est_budget_allowance, tr.est_budget_regis, tr.est_budget_other,
               (IFNULL(tr.est_budget_travel, 0) + IFNULL(tr.est_budget_accom, 0) + IFNULL(tr.est_budget_allowance, 0) + IFNULL(tr.est_budget_regis, 0) + IFNULL(tr.est_budget_other, 0)) as total_est_budget,
               tr.budget_used,
               tr.return_comment, tr.return_step, tr.finance_comment,
               tr.invitation_file, tr.highway_map_file,
               u.first_name, u.last_name, u.position,
               d.dept_name
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        {$where_sql}
        ORDER BY {$order_sql}
    ");
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

$step_name_badge = [
    1 => ['name' => '1. หัวหน้าสำนักงาน / หัวหน้าภาค', 'short' => 'หัวหน้าฯ', 'color' => 'info', 'desc' => 'ตรวจสอบคำขอเบื้องต้น'],
    2 => ['name' => '2. งานการเงินและพัสดุ', 'short' => 'การเงิน', 'color' => 'purple', 'desc' => 'ตรวจสอบและปรับปรุงงบ'],
    3 => ['name' => '3. หัวหน้าสำนักงาน / หัวหน้าภาค', 'short' => 'เห็นชอบงบ', 'color' => 'info', 'desc' => 'เห็นชอบงบประมาณที่ปรับแล้ว'],
    4 => ['name' => '4. รองคณบดีฝ่ายบริหาร', 'short' => 'รองบริหาร', 'color' => 'warning', 'desc' => 'กลั่นกรองคำขอ'],
    5 => ['name' => '5. คณบดีวิทยาลัยดุริยางคศิลป์', 'short' => 'คณบดี', 'color' => 'success', 'desc' => 'อนุมัติโครงการ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Top Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1">
                <i class="fa-solid fa-clipboard-check me-2"></i>พิจารณาอนุมัติคำขอไปราชการ / ฝึกอบรม
            </h1>
            <p class="text-muted small mb-0">
                ระบบการพิจารณาอนุมัติตามลำดับขั้น 5 ขั้นตอน (ผู้เสนอ &rarr; 1. หัวหน้าสำนักงาน/หัวหน้าภาค &rarr; 2. งานการเงิน &rarr; 3. หัวหน้าสำนักงาน/หัวหน้าภาค &rarr; 4. รองบริหาร &rarr; 5. คณบดี)
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3 mt-md-0 flex-wrap">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-2 fw-bold shadow-2xs">
                <i class="fa-solid fa-calendar-check text-purple me-1"></i> ปีงบประมาณปัจจุบัน: <strong>พ.ศ. <?= htmlspecialchars($current_fiscal_year) ?></strong>
            </span>
            <a href="dashboard_admin.php" class="btn btn-outline-purple rounded-pill px-3 shadow-xs fw-semibold">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดภาพรวม
            </a>
            <a href="index.php" class="btn btn-light border rounded-pill px-3 shadow-xs text-dark fw-bold">
                <i class="fa-solid fa-arrow-left me-1"></i> หน้ารายการของฉัน
            </a>
        </div>
    </div>

    <!-- Context Pill: บทบาทการพิจารณาของผู้ใช้ปัจจุบัน และปีงบประมาณ -->
    <div class="alert alert-light border rounded-4 shadow-2xs mb-4 py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-purple text-white rounded-pill px-3 py-1 small">
                <i class="fa-solid fa-user-shield me-1"></i> บทบาทของคุณในระบบ
            </span>
            <span class="small fw-bold text-dark me-2">
                <?= implode(' &bull; ', $user_roles_list) ?>
            </span>
            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 small fw-bold">
                <i class="fa-solid fa-calendar-check me-1"></i> ปีงบประมาณปัจจุบัน: พ.ศ. <?= htmlspecialchars($current_fiscal_year) ?>
            </span>
            <?php if (!empty($filter_year)): ?>
                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1 small fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> กำลังแสดงข้อมูลปี พ.ศ. <?= htmlspecialchars($filter_year) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="text-muted extra-small">
            <i class="fa-solid fa-circle-info text-primary me-1"></i> ระบบจะคัดกรองเฉพาะเอกสารที่เกี่ยวข้องกับสายงานและขั้นตอนที่คุณมีอำนาจพิจารณา
        </div>
    </div>

    <!-- Modern KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. รอพิจารณาในขั้นของคุณ -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white border-start border-4 border-warning hover-lift">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-dark rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        <i class="fa-solid fa-hourglass-half text-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">รอการพิจารณาในขั้นของคุณ</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($cnt_pending) ?></h3>
                            <span class="text-muted small">รายการ</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. ส่งกลับแก้ไข -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white border-start border-4 border-danger hover-lift">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        <i class="fa-solid fa-rotate-left"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">ส่งกลับให้แก้ไข</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-danger mb-0"><?= number_format($cnt_returned) ?></h3>
                            <span class="text-muted small">รายการ</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. อนุมัติแล้ว / เสร็จสิ้น -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white border-start border-4 border-success hover-lift">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">พิจารณาเห็นชอบ/อนุมัติแล้ว</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-success mb-0"><?= number_format($cnt_approved) ?></h3>
                            <span class="text-muted small">รายการ</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. งบประมาณที่รอการพิจารณา -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white border-start border-4 border-purple hover-lift">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-purple bg-opacity-10 text-purple rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">งบประมาณเสนอขอที่รอพิจารณา</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h4 class="fw-bold text-purple mb-0">฿<?= number_format($total_pending_budget, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs & Fiscal Year Filter -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <ul class="nav nav-pills bg-white p-1 rounded-pill shadow-xs border">
            <li class="nav-item">
                <a class="nav-link rounded-pill px-3 py-1 small <?= ($active_tab === 'pending') ? 'active bg-purple text-white fw-bold shadow-xs' : 'text-muted' ?>" href="approve_list.php?tab=pending&year=<?= urlencode($filter_year) ?>">
                    <i class="fa-solid fa-hourglass-half me-1"></i> รอการพิจารณาจากคุณ
                    <?php if ($cnt_pending > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-1"><?= $cnt_pending ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill px-3 py-1 small <?= ($active_tab === 'returned') ? 'active bg-purple text-white fw-bold shadow-xs' : 'text-muted' ?>" href="approve_list.php?tab=returned&year=<?= urlencode($filter_year) ?>">
                    <i class="fa-solid fa-rotate-left me-1"></i> ส่งกลับแก้ไข
                    <?php if ($cnt_returned > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill ms-1"><?= $cnt_returned ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill px-3 py-1 small <?= ($active_tab === 'history') ? 'active bg-purple text-white fw-bold shadow-xs' : 'text-muted' ?>" href="approve_list.php?tab=history&year=<?= urlencode($filter_year) ?>">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติที่พิจารณาแล้ว
                </a>
            </li>
            <?php if ($is_admin || hasRole(['คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร'])): ?>
                <li class="nav-item">
                    <a class="nav-link rounded-pill px-3 py-1 small <?= ($active_tab === 'all') ? 'active bg-purple text-white fw-bold shadow-xs' : 'text-muted' ?>" href="approve_list.php?tab=all&year=<?= urlencode($filter_year) ?>">
                        <i class="fa-solid fa-list me-1"></i> คำขอทั้งหมดในระบบ
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white border-0 shadow-xs rounded-start-pill text-muted small">
                    <i class="fa-solid fa-calendar-days text-purple"></i>
                </span>
                <select name="year" class="form-select form-select-sm border-0 bg-white shadow-xs rounded-end-pill fw-semibold" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_year === 'all') ? 'selected' : '' ?>>ทุกปีงบประมาณ</option>
                    <?php foreach ($fiscal_years_all as $fy): ?>
                        <option value="<?= htmlspecialchars($fy['year_name']) ?>" <?= ($filter_year == $fy['year_name']) ? 'selected' : '' ?>>
                            ปีงบประมาณ <?= htmlspecialchars($fy['year_name']) ?><?= ($fy['year_name'] == $current_fiscal_year) ? ' (ปัจจุบัน)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($filter_year) && $filter_year !== 'all'): ?>
                <a href="approve_list.php?tab=<?= htmlspecialchars($active_tab) ?>&year=all" class="btn btn-sm btn-light border rounded-pill shadow-xs" title="ดูทุกปีงบประมาณ">
                    <i class="fa-solid fa-rotate-left me-1"></i> ดูทุกปี
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- แจ้งเตือนเมื่อมีคำขอค้างในปีก่อนหน้า -->
    <?php if ($cnt_pending_other_years > 0 && $active_tab === 'pending'): ?>
        <div class="alert alert-warning border-0 border-start border-4 border-warning shadow-xs rounded-4 mb-4 py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
                <div>
                    <span class="fw-bold text-dark">มีคำขอรอการพิจารณาในปีก่อนหน้า (นอกเหนือจากปี <?= htmlspecialchars($filter_year) ?>)</span>
                    <span class="text-muted small ms-1">พบจำนวน <strong class="text-danger"><?= $cnt_pending_other_years ?> รายการ</strong></span>
                </div>
            </div>
            <a href="approve_list.php?tab=pending&year=all" class="btn btn-sm btn-dark rounded-pill px-3 py-1 shadow-xs fw-bold">
                <i class="fa-solid fa-list me-1"></i> ดูคำขอทุกปีงบประมาณ
            </a>
        </div>
    <?php endif; ?>

    <!-- Main Table Card -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5 bg-white">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table id="approveTable" class="table table-hover align-middle mb-0" style="width: 100%;">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-3" style="width: 20%;">ผู้ส่งคำขอ</th>
                            <th style="width: 28%;">โครงการ / วัตถุประสงค์</th>
                            <th style="width: 15%;">กำหนดวันเดินทาง</th>
                            <th style="width: 13%;">งบประมาณขอ</th>
                            <th style="width: 14%;">ขั้นตอนปัจจุบัน</th>
                            <th class="text-center" style="width: 10%;">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($reports)): ?>
                            <?php foreach ($reports as $r): 
                                $step_num = intval($r['current_step'] ?? 1);
                                $st_info = $step_name_badge[$step_num] ?? ['name' => 'ขั้นที่ ' . $step_num, 'short' => 'ขั้น ' . $step_num, 'color' => 'secondary', 'desc' => ''];
                                
                                // คำนวณจำนวนวันเดินทาง
                                $d_start = !empty($r['travel_start_date']) ? $r['travel_start_date'] : $r['start_date'];
                                $d_end = !empty($r['travel_end_date']) ? $r['travel_end_date'] : $r['end_date'];
                                $days_count = 1;
                                if (!empty($d_start) && !empty($d_end)) {
                                    $s = new DateTime($d_start);
                                    $e = new DateTime($d_end);
                                    $days_count = max(1, $s->diff($e)->days + 1);
                                }
                            ?>
                                <tr>
                                    <!-- 1. ผู้ส่งคำขอ -->
                                    <td class="ps-3">
                                        <div class="small fw-bold text-dark"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                        <div class="extra-small text-muted text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($r['position'] ?? '') ?>">
                                            <?= htmlspecialchars($r['position'] ?? 'บุคลากร') ?>
                                        </div>
                                        <div class="extra-small text-purple fw-semibold">
                                            <i class="fa-solid fa-sitemap me-1"></i><?= htmlspecialchars($r['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์') ?>
                                        </div>
                                    </td>

                                    <!-- 2. โครงการ / วัตถุประสงค์ -->
                                    <td>
                                        <div class="d-flex align-items-center gap-1 mb-1 flex-wrap">
                                            <span class="badge bg-light text-dark border extra-small rounded-pill px-2">
                                                TR-<?= str_pad($r['report_id'], 5, '0', STR_PAD_LEFT) ?>
                                            </span>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle extra-small rounded-pill px-2">
                                                <?= htmlspecialchars($r['report_type'] ?? 'ไปราชการ') ?>
                                            </span>
                                            <span class="badge <?= ($r['fiscal_year'] == $current_fiscal_year) ? 'bg-success-subtle text-success border border-success-subtle fw-bold' : 'bg-light text-muted border' ?> extra-small rounded-pill px-2" title="ปีงบประมาณ">
                                                ปี <?= htmlspecialchars($r['fiscal_year'] ?: '-') ?><?= ($r['fiscal_year'] == $current_fiscal_year) ? ' (ปัจจุบัน)' : '' ?>
                                            </span>
                                        </div>
                                        <div class="fw-bold text-purple mb-1" style="line-height: 1.3;" title="<?= htmlspecialchars($r['project_name']) ?>">
                                            <a href="view.php?id=<?= $r['report_id'] ?>" class="text-purple text-decoration-none hover-underline">
                                                <?= htmlspecialchars($r['project_name']) ?>
                                            </a>
                                        </div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-building-user me-1"></i> <?= htmlspecialchars($r['organizer'] ?: '-') ?>
                                            <?php if (!empty($r['location'])): ?>
                                                &bull; <i class="fa-solid fa-location-dot text-danger me-1"></i> <?= htmlspecialchars($r['location']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($active_tab === 'returned' && !empty($r['return_comment'])): ?>
                                            <div class="p-2 mt-2 bg-warning bg-opacity-10 border border-warning rounded-3 text-danger extra-small fw-bold">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> ส่งกลับแก้ไข: <?= htmlspecialchars($r['return_comment']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. กำหนดวันเดินทาง -->
                                    <td data-sort="<?= strtotime($d_start) ?>">
                                        <div class="small fw-bold text-dark">
                                            <?php 
                                            if ($d_start == $d_end) {
                                                echo date('d/m/Y', strtotime($d_start));
                                            } else {
                                                echo date('d/m', strtotime($d_start)) . ' - ' . date('d/m/Y', strtotime($d_end));
                                            }
                                            ?>
                                        </div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-calendar-day me-1"></i> รวม <?= $days_count ?> วัน
                                        </div>
                                        <?php if (!empty($r['travel_start_date']) && $r['travel_start_date'] != $r['start_date']): ?>
                                            <div class="extra-small text-info" title="วันจัดงาน: <?= date('d/m/Y', strtotime($r['start_date'])) ?> - <?= date('d/m/Y', strtotime($r['end_date'])) ?>">
                                                <i class="fa-solid fa-route me-1"></i> มีวันเดินทางแยก
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 4. งบประมาณเสนอขอ -->
                                    <td data-sort="<?= floatval($r['total_est_budget']) ?>">
                                        <div class="small fw-bold text-dark">
                                            ฿<?= number_format($r['total_est_budget'], 2) ?>
                                        </div>
                                        <div class="extra-small text-muted" title="รายละเอียดงบประมาณ: พาหนะ <?= number_format($r['est_budget_travel'] ?? 0) ?>, ที่พัก <?= number_format($r['est_budget_accom'] ?? 0) ?>, เบี้ยเลี้ยง <?= number_format($r['est_budget_allowance'] ?? 0) ?>, ทะเบียน <?= number_format($r['est_budget_regis'] ?? 0) ?>">
                                            <?php if ($step_num == 2 && hasRole(['งานการเงิน', 'admin'])): ?>
                                                <span class="badge bg-purple-subtle text-purple extra-small rounded-pill">
                                                    <i class="fa-solid fa-pen-nib me-1"></i> รอตรวจงบ
                                                </span>
                                            <?php else: ?>
                                                <span>งบประมาณการ</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 5. ขั้นตอนปัจจุบัน -->
                                    <td>
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <span class="badge bg-<?= $st_info['color'] ?>-subtle text-<?= $st_info['color'] ?> border border-<?= $st_info['color'] ?> rounded-pill px-2 py-1 extra-small fw-bold d-inline-block">
                                                ขั้น <?= $step_num ?>: <?= $st_info['short'] ?>
                                            </span>
                                            <div class="extra-small text-muted mt-1" style="font-size: 0.7rem;">
                                                <?= $st_info['desc'] ?>
                                            </div>
                                        <?php elseif ($r['status'] === 'returned'): ?>
                                            <span class="badge bg-warning text-dark border border-warning rounded-pill px-2 py-1 extra-small fw-bold">
                                                <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับแก้ไข (ขั้น <?= $r['return_step'] ?: $step_num ?>)
                                            </span>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success extra-small rounded-pill px-2 py-1">
                                                <i class="fa-solid fa-circle-check me-1"></i>อนุมัติเรียบร้อย
                                            </span>
                                        <?php elseif ($r['status'] === 'reported'): ?>
                                            <span class="badge bg-info-subtle text-info border border-info extra-small rounded-pill px-2 py-1">
                                                <i class="fa-solid fa-file-circle-check me-1"></i>รายงานผลแล้ว
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 6. การดำเนินการ -->
                                    <td class="text-center">
                                        <?php
                                            $drawer_payload = [
                                                'id' => (int)$r['report_id'],
                                                'doc_no' => 'TR-' . str_pad($r['report_id'], 5, '0', STR_PAD_LEFT),
                                                'type' => $r['report_type'] ?? 'ไปราชการ',
                                                'fy' => $r['fiscal_year'] ?: '-',
                                                'applicant' => $r['first_name'] . ' ' . $r['last_name'],
                                                'position' => $r['position'] ?? 'บุคลากร',
                                                'dept' => $r['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์',
                                                'project' => $r['project_name'] ?? '-',
                                                'organizer' => $r['organizer'] ?: '-',
                                                'location' => $r['location'] ?: '-',
                                                'date_range' => ($d_start == $d_end) ? date('d/m/Y', strtotime($d_start)) : (date('d/m/Y', strtotime($d_start)) . ' - ' . date('d/m/Y', strtotime($d_end))),
                                                'days' => $days_count,
                                                'step' => $step_num,
                                                'step_name' => $st_info['name'],
                                                'status' => $r['status'],
                                                'total_budget' => number_format((float)$r['total_est_budget'], 2),
                                                'b_travel' => number_format((float)($r['est_budget_travel'] ?? 0), 2),
                                                'b_accom' => number_format((float)($r['est_budget_accom'] ?? 0), 2),
                                                'b_allow' => number_format((float)($r['est_budget_allowance'] ?? 0), 2),
                                                'b_regis' => number_format((float)($r['est_budget_regis'] ?? 0), 2),
                                                'b_other' => number_format((float)($r['est_budget_other'] ?? 0), 2),
                                                'return_comment' => $r['return_comment'] ?? '',
                                                'finance_comment' => $r['finance_comment'] ?? '',
                                                'invitation_file' => $r['invitation_file'] ?? '',
                                                'highway_map_file' => $r['highway_map_file'] ?? ''
                                            ];
                                        ?>
                                        <div class="d-flex flex-column align-items-center gap-1">
                                            <div class="d-flex gap-1 w-100">
                                                <button type="button" class="btn btn-sm btn-light border px-2 fw-bold text-dark" title="เปิดหน้าต่างตรวจสอบด่วน (Quick Inspect Drawer)" onclick='openErpInspectDrawer(<?= htmlspecialchars(json_encode($drawer_payload, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'>
                                                    <i class="fa-solid fa-eye text-purple"></i>
                                                </button>
                                                <a href="view.php?id=<?= $r['report_id'] ?>" class="btn btn-sm btn-purple px-2 shadow-xs fw-bold flex-grow-1 text-nowrap">
                                                    <i class="fa-solid fa-signature me-1"></i> พิจารณา
                                                </a>
                                            </div>
                                            <div class="d-flex gap-1 mt-1">
                                                <a href="print.php?id=<?= $r['report_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm p-0 d-flex align-items-center justify-content-center" style="width: 26px; height: 26px; border-radius: 6px !important;" title="พิมพ์เอกสาร">
                                                    <i class="fa-solid fa-print extra-small"></i>
                                                </a>
                                                <?php if (!empty($r['invitation_file'])): ?>
                                                    <a href="../../uploads/trainings/<?= htmlspecialchars($r['invitation_file']) ?>" target="_blank" class="btn btn-outline-primary btn-sm p-0 d-flex align-items-center justify-content-center" style="width: 26px; height: 26px; border-radius: 6px !important;" title="ดูหนังสือเชิญ">
                                                        <i class="fa-solid fa-envelope-open-text extra-small"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($r['highway_map_file'])): ?>
                                                    <a href="../../uploads/trainings/<?= htmlspecialchars($r['highway_map_file']) ?>" target="_blank" class="btn btn-outline-info btn-sm p-0 d-flex align-items-center justify-content-center" style="width: 26px; height: 26px; border-radius: 6px !important;" title="ดูแผนที่ระยะทาง">
                                                        <i class="fa-solid fa-map-location-dot extra-small"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
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

    <!-- Enterprise Master-Detail Slide-Over Inspection Drawer -->
    <div class="offcanvas offcanvas-end border-start shadow-lg" tabindex="-1" id="erpInspectDrawer" aria-labelledby="erpInspectDrawerLabel" style="width: 480px;">
        <div class="offcanvas-header border-bottom bg-light py-3 px-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-purple text-white px-2 py-1 extra-small fw-bold" id="drwDocNo">TR-00000</span>
                    <span class="badge bg-white text-dark border px-2 py-1 extra-small fw-semibold" id="drwType">ไปราชการ</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 extra-small fw-bold" id="drwFy">ปี 2569</span>
                </div>
                <h6 class="offcanvas-title fw-bold text-dark mb-0" id="erpInspectDrawerLabel">หน้าต่างตรวจสอบคำขอ (Quick Inspector)</h6>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-4">
            <!-- Workflow Stepper -->
            <div class="p-3 rounded-3 bg-light border mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="extra-small fw-bold text-uppercase text-muted">สถานะสายงานอนุมัติ (Workflow Progress)</span>
                    <span class="badge bg-purple-subtle text-purple extra-small fw-bold" id="drwStepBadge">ขั้นที่ 1/5</span>
                </div>
                <div class="progress" style="height: 7px; border-radius: 99px;">
                    <div class="progress-bar bg-purple" id="drwStepBar" role="progressbar" style="width: 20%;"></div>
                </div>
                <div class="extra-small text-dark fw-semibold mt-2" id="drwStepName">-</div>
            </div>

            <!-- Applicant Info -->
            <div class="mb-3">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">ข้อมูลผู้ยื่นคำขอ (Applicant)</div>
                <div class="p-3 rounded-3 border bg-white">
                    <div class="fw-bold text-dark" id="drwApplicant">-</div>
                    <div class="small text-muted" id="drwPosition">-</div>
                    <div class="extra-small text-purple fw-semibold mt-1"><i class="fa-solid fa-sitemap me-1"></i><span id="drwDept">-</span></div>
                </div>
            </div>

            <!-- Project & Schedule -->
            <div class="mb-3">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">รายละเอียดโครงการและกำหนดการ</div>
                <div class="p-3 rounded-3 border bg-white">
                    <div class="fw-bold text-purple mb-2" id="drwProject">-</div>
                    <div class="row g-2 small">
                        <div class="col-12">
                            <span class="text-muted">หน่วยงานผู้จัด:</span>
                            <span class="fw-semibold text-dark ms-1" id="drwOrganizer">-</span>
                        </div>
                        <div class="col-12">
                            <span class="text-muted">สถานที่:</span>
                            <span class="fw-semibold text-dark ms-1" id="drwLocation">-</span>
                        </div>
                        <div class="col-12">
                            <span class="text-muted">วันเดินทาง:</span>
                            <span class="fw-bold text-dark ms-1" id="drwDates">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budget Breakdown Table -->
            <div class="mb-3">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">ตารางประมาณการงบประมาณ (Budget Breakdown)</div>
                <div class="border rounded-3 overflow-hidden">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <td class="text-muted small ps-3">1. ค่าพาหนะเดินทาง</td>
                                <td class="text-end fw-semibold small pe-3">฿<span id="drwBTravel">0.00</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted small ps-3">2. ค่าที่พัก</td>
                                <td class="text-end fw-semibold small pe-3">฿<span id="drwBAccom">0.00</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted small ps-3">3. ค่าเบี้ยเลี้ยง</td>
                                <td class="text-end fw-semibold small pe-3">฿<span id="drwBAllow">0.00</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted small ps-3">4. ค่าลงทะเบียน</td>
                                <td class="text-end fw-semibold small pe-3">฿<span id="drwBRegis">0.00</span></td>
                            </tr>
                            <tr>
                                <td class="text-muted small ps-3">5. ค่าใช้จ่ายอื่นๆ</td>
                                <td class="text-end fw-semibold small pe-3">฿<span id="drwBOther">0.00</span></td>
                            </tr>
                            <tr class="table-light">
                                <td class="fw-bold text-dark ps-3">รวมงบประมาณเสนอขอทั้งสิ้น</td>
                                <td class="text-end fw-bold text-purple pe-3">฿<span id="drwTotalBudget">0.00</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Attachments -->
            <div class="mb-3" id="drwFilesBox">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">เอกสารแนบประกอบการพิจารณา</div>
                <div class="d-flex flex-wrap gap-2" id="drwFilesList"></div>
            </div>
        </div>
        <div class="offcanvas-footer border-top bg-light p-3 d-flex justify-content-between align-items-center gap-2">
            <a href="#" id="drwPrintLink" target="_blank" class="btn btn-light border fw-bold text-dark">
                <i class="fa-solid fa-print me-1"></i> พิมพ์แบบฟอร์ม
            </a>
            <a href="#" id="drwApproveLink" class="btn btn-purple fw-bold flex-grow-1 text-center">
                <i class="fa-solid fa-signature me-1"></i> เปิดหน้าพิจารณาและลงนามอนุมัติ
            </a>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #39048f !important; }
    .bg-purple { background-color: #39048f !important; }
    .btn-purple { background-color: #39048f; color: white; transition: all 0.2s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(75,0,130,0.2); }
    .btn-outline-purple { border-color: #39048f; color: #39048f; }
    .btn-outline-purple:hover { background-color: #39048f; color: white; }
    
    .bg-purple-subtle { background-color: #f3e8ff !important; color: #6b21a8 !important; }
    .border-purple-subtle { border-color: #e9d5ff !important; }
    
    .shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .shadow-2xs { box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .hover-lift { transition: transform 0.2s, box-shadow 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.08) !important; }
    .hover-underline:hover { text-decoration: underline !important; }
    
    .extra-small { font-size: 0.75rem; }

    /* DataTables Style */
    .dataTables_wrapper .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #dee2e6; outline: none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color: #39048f; box-shadow: 0 0 5px rgba(57, 4, 143,0.2); }
    .dataTables_wrapper .dataTables_length select { border-radius: 10px; padding: 5px 30px 5px 10px; }
    .page-item.active .page-link { background-color: #39048f; border-color: #39048f; }
    .page-link { color: #39048f; border-radius: 50% !important; margin: 0 3px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script>
    if (typeof jQuery == 'undefined') {
        document.write('<script src="https://code.jquery.com/jquery-3.7.0.min.js"><\/script>');
    }
</script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
function openErpInspectDrawer(data) {
    if (!data) return;
    document.getElementById('drwDocNo').textContent = data.doc_no || '-';
    document.getElementById('drwType').textContent = data.type || 'ไปราชการ';
    document.getElementById('drwFy').textContent = 'ปีงบ ' + (data.fy || '-');
    document.getElementById('drwApplicant').textContent = data.applicant || '-';
    document.getElementById('drwPosition').textContent = data.position || '-';
    document.getElementById('drwDept').textContent = data.dept || '-';
    document.getElementById('drwProject').textContent = data.project || '-';
    document.getElementById('drwOrganizer').textContent = data.organizer || '-';
    document.getElementById('drwLocation').textContent = data.location || '-';
    document.getElementById('drwDates').textContent = (data.date_range || '-') + ' (รวม ' + (data.days || 1) + ' วัน)';

    const step = parseInt(data.step || 1, 10);
    const pct = data.status === 'approved' || data.status === 'reported' ? 100 : Math.min(100, Math.max(20, step * 20));
    document.getElementById('drwStepBadge').textContent = (data.status === 'approved' || data.status === 'reported') ? 'อนุมัติครบถ้วน (5/5)' : ('ขั้นที่ ' + step + ' / 5');
    document.getElementById('drwStepBar').style.width = pct + '%';
    document.getElementById('drwStepName').textContent = data.step_name || '-';

    document.getElementById('drwBTravel').textContent = data.b_travel || '0.00';
    document.getElementById('drwBAccom').textContent = data.b_accom || '0.00';
    document.getElementById('drwBAllow').textContent = data.b_allow || '0.00';
    document.getElementById('drwBRegis').textContent = data.b_regis || '0.00';
    document.getElementById('drwBOther').textContent = data.b_other || '0.00';
    document.getElementById('drwTotalBudget').textContent = data.total_budget || '0.00';

    const filesList = document.getElementById('drwFilesList');
    filesList.innerHTML = '';
    if (data.invitation_file) {
        filesList.innerHTML += `<a href="../../uploads/trainings/${encodeURIComponent(data.invitation_file)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-file-pdf me-1"></i> หนังสือเชิญ / โครงการ</a>`;
    }
    if (data.highway_map_file) {
        filesList.innerHTML += `<a href="../../uploads/trainings/${encodeURIComponent(data.highway_map_file)}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-map-location-dot me-1"></i> แผนที่ระยะทางกรมทางหลวง</a>`;
    }
    if (!data.invitation_file && !data.highway_map_file) {
        filesList.innerHTML = `<span class="text-muted small">ไม่มีไฟล์แนบเพิ่มเติม</span>`;
    }

    document.getElementById('drwPrintLink').href = 'print.php?id=' + encodeURIComponent(data.id);
    document.getElementById('drwApproveLink').href = 'view.php?id=' + encodeURIComponent(data.id);

    const drawerEl = document.getElementById('erpInspectDrawer');
    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
    bsOffcanvas.show();
}

$(document).ready(function() {
    $('#approveTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json",
            "emptyTable": "<div class='text-center py-5 text-muted'><i class='fa-solid fa-circle-check fs-1 mb-3 text-success opacity-50'></i><p class='mb-0 fw-bold'>ยอดเยี่ยม! ไม่มีรายการค้างในหมวดนี้</p></div>"
        },
        "pageLength": 10,
        "ordering": true,
        "order": [[ 2, "asc" ]], 
        "columnDefs": [
            { "orderable": false, "targets": [5] } 
        ]
    });
});
</script>