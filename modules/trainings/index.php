<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ผู้พิจารณาอนุมัติ 5 ขั้นตอน (Approver Check)
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
$is_approver = hasRole($approve_roles);
$is_admin = hasRole(['admin']);

$cnt_pending_approvals = 0;
if ($is_approver) {
    $step_conds = [];
    if ($is_admin) {
        $step_conds[] = "tr.current_step IN (1, 2, 3, 4, 5)";
    } else {
        if (hasRole(['หัวหน้าสำนักงาน'])) {
            $step_conds[] = "(tr.current_step IN (1, 3) AND (u.dept_id = 11 OR u.dept_id IS NULL OR tr.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1)))";
        }
        if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) {
            $step_conds[] = "(tr.current_step IN (1, 3) AND u.dept_id = 12)";
        }
        if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) {
            $step_conds[] = "(tr.current_step IN (1, 3) AND u.dept_id = 13)";
        }
        if (hasRole(['งานการเงิน'])) {
            $step_conds[] = "tr.current_step = 2";
        }
        if (hasRole(['รองคณบดีฝ่ายบริหาร'])) {
            $step_conds[] = "tr.current_step = 4";
        }
        if (hasRole(['คณบดี', 'รักษาการคณบดี'])) {
            $step_conds[] = "tr.current_step = 5";
        }
    }
    
    if (!empty($step_conds)) {
        try {
            $cnt_sql = "SELECT COUNT(*) FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.status = 'pending' AND (" . implode(' OR ', $step_conds) . ")";
            $cnt_stmt = $pdo->query($cnt_sql);
            $cnt_pending_approvals = intval($cnt_stmt->fetchColumn());
        } catch (PDOException $e) { $cnt_pending_approvals = 0; }
    }
}

// ---------------------------------------------------------
// 2. ดึงรายการปีงบประมาณที่มีข้อมูล
// ---------------------------------------------------------
try {
    $stmt_fy = $pdo->prepare("
        SELECT DISTINCT year_name FROM fiscal_years 
        UNION 
        SELECT DISTINCT fiscal_year FROM training_reports WHERE user_id = ? AND fiscal_year IS NOT NULL AND fiscal_year != ''
        ORDER BY year_name DESC
    ");
    $stmt_fy->execute([$user_id]);
    $available_years = array_filter($stmt_fy->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    $available_years = [];
}

// ปีงบประมาณปัจจุบัน (is_active = 1)
$current_fiscal_year = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1")->fetchColumn() ?: (date('Y') + 543);

// ---------------------------------------------------------
// 3. รับค่าตัวกรอง (Filters) - กำหนดค่าเริ่มต้นเป็นปีงบประมาณปัจจุบัน
// ---------------------------------------------------------
$filter_year = isset($_GET['year']) ? trim($_GET['year']) : $current_fiscal_year;
$filter_status = trim($_GET['status'] ?? '');
$filter_type = trim($_GET['type'] ?? '');
$search_query = trim($_GET['search'] ?? '');
$active_tab = trim($_GET['tab'] ?? 'list');

// ---------------------------------------------------------
// 4. สถิติภาพรวมของผู้ใช้ (KPI Summary)
// ---------------------------------------------------------
$where_all = ["tr.user_id = ?"];
$params_all = [$user_id];
if (!empty($filter_year) && $filter_year !== 'all') {
    $where_all[] = "tr.fiscal_year = ?";
    $params_all[] = $filter_year;
}
$where_all_sql = implode(" AND ", $where_all);

$all_user_reports = [];
try {
    $stmt_all = $pdo->prepare("SELECT * FROM training_reports tr WHERE {$where_all_sql} ORDER BY tr.created_at DESC");
    $stmt_all->execute($params_all);
    $all_user_reports = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_user_reports = [];
}

$total_trips = count($all_user_reports);
$total_budget = 0;
$total_est_budget = 0;
$total_hours = 0;
$total_days = 0;
$needs_report_count = 0;
$reported_count = 0;
$pending_count = 0;
$returned_count = 0;

$type_counts = ['สัมมนา' => 0, 'อบรม' => 0, 'ดูงาน' => 0, 'ไปราชการ' => 0, 'อื่นๆ' => 0];
$eval_counts = ['ดีมาก' => 0, 'ดี' => 0, 'ไม่ดี' => 0];
$visited_locations = [];

foreach ($all_user_reports as $r) {
    $total_budget += (float)($r['budget_used'] ?? 0);
    $est_total = (float)($r['est_budget_travel'] ?? 0) + 
                 (float)($r['est_budget_accom'] ?? 0) + 
                 (float)($r['est_budget_allowance'] ?? 0) + 
                 (float)($r['est_budget_regis'] ?? 0) + 
                 (float)($r['est_budget_other'] ?? 0);
    $total_est_budget += $est_total;

    // คำนวณชั่วโมงอบรม
    $hrs = intval($r['training_hours'] ?? 0);
    $days = 1;
    if (!empty($r['start_date']) && !empty($r['end_date'])) {
        $s = new DateTime($r['start_date']);
        $e = new DateTime($r['end_date']);
        $days = max(1, $s->diff($e)->days + 1);
    }
    $total_days += $days;
    if ($hrs <= 0) {
        $hrs = max(6, $days * 6);
    }
    $total_hours += $hrs;

    if ($r['status'] === 'approved') $needs_report_count++;
    if ($r['status'] === 'reported') $reported_count++;
    if ($r['status'] === 'pending') $pending_count++;
    if ($r['status'] === 'returned') $returned_count++;

    // นับประเภท
    $t = trim($r['report_type'] ?? 'ไปราชการ');
    if (isset($type_counts[$t])) {
        $type_counts[$t]++;
    } else {
        $type_counts['อื่นๆ']++;
    }

    // นับผลประเมิน
    if (!empty($r['evaluation']) && isset($eval_counts[$r['evaluation']])) {
        $eval_counts[$r['evaluation']]++;
    }

    // เก็บสถานที่
    if (!empty($r['location'])) {
        $loc = trim($r['location']);
        if (!in_array($loc, $visited_locations)) {
            $visited_locations[] = $loc;
        }
    }
}

$reported_pct = $total_trips > 0 ? round(($reported_count / $total_trips) * 100, 1) : 0;
$locations_json = json_encode($visited_locations, JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------
// 5. ดึงข้อมูลรายงานที่ตรงตามเงื่อนไขการค้นหา (Filtered List)
// ---------------------------------------------------------
$where = ["tr.user_id = ?"];
$params = [$user_id];

if (!empty($filter_year) && $filter_year !== 'all') {
    $where[] = "tr.fiscal_year = ?";
    $params[] = $filter_year;
}
if (!empty($filter_status) && $filter_status !== 'all') {
    $where[] = "tr.status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_type) && $filter_type !== 'all') {
    $where[] = "tr.report_type = ?";
    $params[] = $filter_type;
}
if (!empty($search_query)) {
    $where[] = "(tr.project_name LIKE ? OR tr.location LIKE ? OR tr.organizer LIKE ?)";
    $like = "%{$search_query}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(" AND ", $where);

try {
    $stmt = $pdo->prepare("
        SELECT tr.* 
        FROM training_reports tr 
        WHERE {$where_sql} 
        ORDER BY tr.start_date DESC, tr.created_at DESC
    ");
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reports = [];
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$msg = $_SESSION['msg'] ?? '';
$msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-graduation-cap text-purple me-2"></i> ระบบรายงานสัมมนา / อบรม / ดูงาน
            </h1>
            <p class="text-muted small mb-0">ประวัติการไปราชการ พัฒนาศักยภาพตนเอง และการจัดการงบประมาณ</p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap align-items-center">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-2 fw-bold shadow-2xs">
                <i class="fa-solid fa-calendar-check text-purple me-1"></i> ปีงบประมาณปัจจุบัน: <strong>พ.ศ. <?= htmlspecialchars($current_fiscal_year) ?></strong>
            </span>
            <?php if ($is_admin): ?>
                <a href="dashboard_admin.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                    <i class="fa-solid fa-chart-pie text-purple me-1"></i> แดชบอร์ดภาพรวม
                </a>
            <?php endif; ?>
            <?php if ($is_approver): ?>
                <a href="approve_list.php" class="btn btn-outline-purple rounded-pill px-3 py-2 fw-bold position-relative shadow-xs">
                    <i class="fa-solid fa-user-check me-1"></i> พิจารณาอนุมัติ
                    <?php if ($cnt_pending_approvals > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-1"><?= $cnt_pending_approvals ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <a href="create.php" class="btn btn-purple rounded-pill px-4 py-2 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> ขออนุมัติไปราชการใหม่
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type); ?> shadow-xs border-0 border-start border-<?= htmlspecialchars($msg_type); ?> border-4 alert-dismissible fade show rounded-3 mb-3">
            <i class="fa-solid fa-circle-info me-2"></i> <?= htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Returned Action Banner: ถ้ามีรายการที่ถูกส่งกลับแก้ไข -->
    <?php if ($returned_count > 0): ?>
        <div class="card border-0 shadow-xs rounded-4 mb-4 bg-warning bg-opacity-10 border-start border-warning border-4">
            <div class="card-body p-3 p-md-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning text-dark rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.2rem;">
                        <i class="fa-solid fa-rotate-left"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">มีคำขอไปราชการที่ถูกส่งกลับให้แก้ไข</h6>
                        <p class="text-muted small mb-0">
                            คุณมีรายการคำขอที่ผู้พิจารณาส่งกลับเพื่อให้ปรับปรุงข้อมูลหรือเอกสารแนบจำนวน <strong class="text-danger"><?= $returned_count ?> รายการ</strong>
                        </p>
                    </div>
                </div>
                <a href="?status=returned" class="btn btn-warning text-dark rounded-pill px-4 py-2 fw-bold shadow-xs">
                    <i class="fa-solid fa-pen-to-square me-1"></i> ดูและแก้ไขทันที
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Pending Action Banner: ถ้ามีรายการที่อนุมัติแล้ว แต่ยังไม่ได้เขียนรายงานหลังกลับ -->
    <?php if ($needs_report_count > 0): ?>
        <div class="card border-0 shadow-xs rounded-4 mb-4 bg-primary bg-opacity-10 border-start border-primary border-4">
            <div class="card-body p-3 p-md-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary text-white rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.2rem;">
                        <i class="fa-solid fa-file-pen"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-primary">มีรายการที่ต้องบันทึกรายงานผลการไปราชการ</h6>
                        <p class="text-muted small mb-0">
                            คุณมีโครงการที่ได้รับอนุมัติให้ไปราชการแล้ว แต่ยังไม่ได้บันทึกรายงานผลหลังกลับจำนวน <strong class="text-primary"><?= $needs_report_count ?> รายการ</strong>
                        </p>
                    </div>
                </div>
                <a href="?status=approved" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-xs">
                    <i class="fa-solid fa-pen-to-square me-1"></i> เขียนรายงานทันที
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Compact Modern KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. การไปราชการทั้งหมด -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-plane-departure"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">การไปราชการทั้งหมด</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($total_trips) ?></h3>
                            <span class="text-muted small">ครั้ง</span>
                        </div>
                        <small class="text-muted extra-small">
                            รายงานแล้ว <span class="text-success fw-bold"><?= $reported_count ?></span> / รออนุมัติ <span class="text-warning fw-bold"><?= $pending_count ?></span><?= ($returned_count > 0) ? ' / แก้ไข <span class="text-danger fw-bold">' . $returned_count . '</span>' : '' ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. ชั่วโมงอบรมสะสม -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info-subtle text-info rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-regular fa-clock"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">ชั่วโมงอบรมรวมสะสม</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($total_hours) ?></h3>
                            <span class="text-muted small">ชม.</span>
                        </div>
                        <small class="text-muted extra-small">
                            ระยะเวลารวมประมาณ <strong class="text-dark"><?= number_format($total_days) ?></strong> วัน
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. งบประมาณที่ใช้จริง -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">งบประมาณจ่ายจริง</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-success mb-0">฿<?= number_format($total_budget) ?></h3>
                        </div>
                        <small class="text-muted extra-small">
                            จากประมาณการ ฿<?= number_format($total_est_budget) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. ส่งรายงานผลแล้ว -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3" style="border-right: 4px solid #198754 !important;">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-file-circle-check"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">ส่งรายงานผลแล้ว</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-success mb-0"><?= $reported_pct ?>%</h3>
                        </div>
                        <small class="text-muted extra-small">
                            เสร็จสิ้น <strong class="text-success"><?= $reported_count ?></strong> จาก <?= $total_trips ?> รายการ
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Segmented Navigation Tabs -->
    <ul class="nav nav-pills bg-white p-2 rounded-4 shadow-xs mb-3 gap-2 border" id="mainTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold active" id="tab-list-btn" data-bs-toggle="pill" data-bs-target="#tab-list-pane" type="button" role="tab">
                <i class="fa-solid fa-list-check me-2"></i> รายการทั้งหมด <span class="badge bg-purple-subtle text-purple rounded-pill ms-1"><?= count($reports) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-analytics-btn" data-bs-toggle="pill" data-bs-target="#tab-analytics-pane" type="button" role="tab">
                <i class="fa-solid fa-chart-pie me-2"></i> สถิติ & บทวิเคราะห์
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-map-btn" data-bs-toggle="pill" data-bs-target="#tab-map-pane" type="button" role="tab" onclick="initOrResizeMap()">
                <i class="fa-solid fa-map-location-dot me-2"></i> แผนที่จุดหมาย (<?= count($visited_locations) ?> แห่ง)
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="mainTabContent">
        <!-- ========================================== -->
        <!-- TAB 1: ประวัติรายการทั้งหมด (Data Table) -->
        <!-- ========================================== -->
        <div class="tab-pane fade show active" id="tab-list-pane" role="tabpanel">
            <!-- Modern Filter Bar -->
            <div class="card border-0 shadow-xs rounded-4 mb-3 bg-white">
                <div class="card-body p-3">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-lg-4 col-md-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-0 bg-light" placeholder="ค้นหาชื่อหลักสูตร, สถานที่, หน่วยงานจัด..." value="<?= htmlspecialchars($search_query) ?>">
                            </div>
                        </div>

                        <div class="col-lg-2 col-md-3 col-6">
                            <select name="year" class="form-select form-select-sm border-0 bg-light fw-semibold" onchange="this.form.submit()">
                                <option value="all" <?= ($filter_year === 'all') ? 'selected' : '' ?>>ทุกปีงบประมาณ</option>
                                <?php foreach ($available_years as $yr): ?>
                                    <option value="<?= htmlspecialchars($yr) ?>" <?= ($filter_year == $yr) ? 'selected' : '' ?>>
                                        ปีงบประมาณ <?= htmlspecialchars($yr) ?><?= ($yr == $current_fiscal_year) ? ' (ปัจจุบัน)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-3 col-6">
                            <select name="status" class="form-select form-select-sm border-0 bg-light" onchange="this.form.submit()">
                                <option value="">ทุกสถานะ</option>
                                <option value="pending" <?= ($filter_status === 'pending') ? 'selected' : '' ?>>รออนุมัติ</option>
                                <option value="returned" <?= ($filter_status === 'returned') ? 'selected' : '' ?>>ส่งกลับแก้ไข</option>
                                <option value="approved" <?= ($filter_status === 'approved') ? 'selected' : '' ?>>อนุมัติแล้ว (รอรายงาน)</option>
                                <option value="reported" <?= ($filter_status === 'reported') ? 'selected' : '' ?>>บันทึกรายงานแล้ว</option>
                                <option value="rejected" <?= ($filter_status === 'rejected') ? 'selected' : '' ?>>ไม่อนุมัติ</option>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-4 col-6">
                            <select name="type" class="form-select form-select-sm border-0 bg-light" onchange="this.form.submit()">
                                <option value="">ทุกประเภท</option>
                                <option value="สัมมนา" <?= ($filter_type === 'สัมมนา') ? 'selected' : '' ?>>สัมมนา</option>
                                <option value="อบรม" <?= ($filter_type === 'อบรม') ? 'selected' : '' ?>>อบรม</option>
                                <option value="ดูงาน" <?= ($filter_type === 'ดูงาน') ? 'selected' : '' ?>>ดูงาน</option>
                                <option value="ไปราชการ" <?= ($filter_type === 'ไปราชการ') ? 'selected' : '' ?>>ไปราชการ</option>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-2 col-6 text-end">
                            <?php if (!empty($filter_year) || !empty($filter_status) || !empty($filter_type) || !empty($search_query)): ?>
                                <a href="index.php" class="btn btn-sm btn-outline-secondary w-100 rounded-3" title="ล้างตัวกรอง">
                                    <i class="fa-solid fa-rotate-left me-1"></i> ล้าง
                                </a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-purple w-100 rounded-3">
                                    <i class="fa-solid fa-filter me-1"></i> กรอง
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Clean Table Card -->
            <div class="card border-0 shadow-xs rounded-4 overflow-hidden bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted small">
                            <tr>
                                <th class="ps-4" style="width: 32%;">หลักสูตร / โครงการ</th>
                                <th style="width: 12%;">ประเภท</th>
                                <th style="width: 15%;">ช่วงเวลาจัด</th>
                                <th style="width: 10%;">ชั่วโมง</th>
                                <th style="width: 12%;">งบประมาณ</th>
                                <th style="width: 11%;">สถานะ</th>
                                <th class="text-end pe-4" style="width: 8%;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fa-solid fa-inbox fa-3x mb-3 text-secondary opacity-50"></i>
                                            <h6 class="fw-bold mb-1">ยังไม่พบรายการตามเงื่อนไขที่เลือก</h6>
                                            <p class="small mb-3">คุณสามารถขออนุมัติไปราชการ/อบรมใหม่ได้ทุกเมื่อ</p>
                                            <a href="create.php" class="btn btn-sm btn-purple rounded-pill px-3 fw-bold">
                                                <i class="fa-solid fa-plus me-1"></i> ขออนุมัติไปราชการใหม่
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($reports as $r): 
                                $hrs_row = intval($r['training_hours'] ?? 0);
                                if ($hrs_row <= 0 && !empty($r['start_date']) && !empty($r['end_date'])) {
                                    $s_dt = new DateTime($r['start_date']);
                                    $e_dt = new DateTime($r['end_date']);
                                    $d_diff = max(1, $s_dt->diff($e_dt)->days + 1);
                                    $hrs_row = max(6, $d_diff * 6);
                                }
                            ?>
                                <tr>
                                    <!-- 1. โครงการ / สถานที่ -->
                                    <td class="ps-4">
                                        <a href="view.php?id=<?= $r['report_id'] ?>" class="fw-bold text-dark text-decoration-none d-block small mb-1 hover-purple text-truncate" style="max-width: 340px;" title="<?= htmlspecialchars($r['project_name']) ?>">
                                            <?= htmlspecialchars($r['project_name']) ?>
                                        </a>
                                        <div class="d-flex align-items-center flex-wrap gap-2">
                                            <?php if (!empty($r['location'])): ?>
                                                <span class="extra-small text-muted text-truncate" style="max-width: 200px;">
                                                    <i class="fa-solid fa-location-dot me-1 text-danger"></i><?= htmlspecialchars($r['location']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 2. ประเภท -->
                                    <td>
                                        <?php 
                                            $t = $r['report_type'] ?? 'ไปราชการ';
                                            $bg_badge = 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                            if ($t === 'สัมมนา') $bg_badge = 'bg-primary-subtle text-primary border border-primary-subtle';
                                            else if ($t === 'อบรม') $bg_badge = 'bg-success-subtle text-success border border-success-subtle';
                                            else if ($t === 'ดูงาน') $bg_badge = 'bg-info-subtle text-info border border-info-subtle';
                                            else if ($t === 'ไปราชการ') $bg_badge = 'bg-warning-subtle text-dark border border-warning-subtle';
                                        ?>
                                        <span class="badge <?= $bg_badge ?> px-2 py-1 fw-normal rounded-pill extra-small">
                                            <?= htmlspecialchars($t) ?>
                                        </span>
                                    </td>

                                    <!-- 3. ระยะเวลาจัด -->
                                    <td>
                                        <div class="small fw-bold text-dark">
                                            <?php 
                                                if ($r['start_date'] === $r['end_date']) echo date('d/m/Y', strtotime($r['start_date']));
                                                else echo date('d/m', strtotime($r['start_date'])) . ' - ' . date('d/m/Y', strtotime($r['end_date']));
                                            ?>
                                        </div>
                                        <div class="extra-small text-muted">
                                            ปีงบฯ <?= htmlspecialchars($r['fiscal_year'] ?? '-') ?>
                                        </div>
                                    </td>

                                    <!-- 4. ชั่วโมง -->
                                    <td>
                                        <span class="badge bg-light text-dark border extra-small rounded-pill px-2 py-1">
                                            <i class="fa-regular fa-clock me-1 text-muted"></i><?= $hrs_row ?> ชม.
                                        </span>
                                    </td>

                                    <!-- 5. งบประมาณ -->
                                    <td>
                                        <div class="small fw-bold <?= ($r['budget_used'] > 0) ? 'text-success' : 'text-dark' ?>">
                                            ฿<?= number_format($r['budget_used']) ?>
                                        </div>
                                        <?php 
                                            $est = (float)($r['est_budget_travel'] ?? 0) + 
                                                   (float)($r['est_budget_accom'] ?? 0) + 
                                                   (float)($r['est_budget_allowance'] ?? 0) + 
                                                   (float)($r['est_budget_regis'] ?? 0) + 
                                                   (float)($r['est_budget_other'] ?? 0);
                                        ?>
                                        <div class="extra-small text-muted" title="ประมาณการงบประมาณ">
                                            ป: ฿<?= number_format($est) ?>
                                        </div>
                                    </td>

                                    <!-- 6. สถานะ -->
                                    <td>
                                        <?php if ($r['status'] === 'reported'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill extra-small">
                                                <i class="fa-solid fa-check-circle me-1"></i>รายงานแล้ว
                                            </span>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill extra-small">
                                                <i class="fa-solid fa-file-pen me-1"></i>รอรายงานผล
                                            </span>
                                        <?php elseif ($r['status'] === 'pending'): ?>
                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1 rounded-pill extra-small">
                                                <i class="fa-solid fa-hourglass-half me-1"></i>รออนุมัติขั้น <?= $r['current_step'] ?>
                                            </span>
                                        <?php elseif ($r['status'] === 'returned'): ?>
                                            <span class="badge bg-warning text-dark border border-warning px-2 py-1 rounded-pill extra-small fw-bold">
                                                <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับแก้ไข
                                            </span>
                                        <?php elseif ($r['status'] === 'rejected'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill extra-small">
                                                <i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 7. จัดการ (Actions) -->
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end align-items-center gap-1">
                                            <?php if ($r['status'] === 'returned'): ?>
                                                <a href="edit.php?id=<?= $r['report_id'] ?>" class="btn btn-sm btn-warning text-dark rounded-pill px-3 fw-bold shadow-xs text-nowrap" title="แก้ไขตามที่ส่งกลับ">
                                                    <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขคำขอ
                                                </a>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                                <a href="write_report.php?id=<?= $r['report_id'] ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-xs text-nowrap" title="บันทึกรายงานหลังเดินทางกลับ">
                                                    <i class="fa-solid fa-pen-nib me-1"></i> รายงาน
                                                </a>
                                            <?php else: ?>
                                                <a href="view.php?id=<?= $r['report_id'] ?>" class="btn btn-sm btn-light border text-primary rounded-pill px-3 shadow-xs text-nowrap" title="ดูรายละเอียด">
                                                    ดูข้อมูล
                                                </a>
                                            <?php endif; ?>

                                            <!-- Dropdown menu สำหรับคำสั่งเพิ่มเติม -->
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 32px; height: 32px; padding: 0;">
                                                    <i class="fa-solid fa-ellipsis-vertical text-muted"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 p-1">
                                                    <li>
                                                        <a class="dropdown-item small rounded-2" href="view.php?id=<?= $r['report_id'] ?>">
                                                            <i class="fa-solid fa-eye me-2 text-primary"></i> ดูรายละเอียด
                                                        </a>
                                                    </li>
                                                    <?php if (in_array($r['status'], ['reported', 'returned', 'pending'])): ?>
                                                        <li>
                                                            <a class="dropdown-item small rounded-2" href="edit.php?id=<?= $r['report_id'] ?>">
                                                                <i class="fa-solid fa-pen-to-square me-2 text-warning"></i> <?= ($r['status'] === 'returned') ? 'แก้ไขตามที่ส่งกลับ' : 'แก้ไขคำขอ' ?>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <a class="dropdown-item small rounded-2" href="print.php?id=<?= $r['report_id'] ?>" target="_blank">
                                                            <i class="fa-solid fa-print me-2 text-secondary"></i> พิมพ์เอกสาร
                                                        </a>
                                                    </li>
                                                    <?php if ($r['status'] !== 'reported'): ?>
                                                        <li><hr class="dropdown-divider my-1"></li>
                                                        <li>
                                                            <a class="dropdown-item small rounded-2 text-danger" href="delete.php?id=<?= $r['report_id'] ?>" onclick="return confirm('ยืนยันยกเลิก/ลบคำขอไปราชการนี้?');">
                                                                <i class="fa-solid fa-trash me-2"></i> ยกเลิกคำขอ
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: สถิติ & บทวิเคราะห์ (Analytics) -->
        <!-- ========================================== -->
        <div class="tab-pane fade" id="tab-analytics-pane" role="tabpanel">
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

            <div class="row g-3 mb-4">
                <!-- สัดส่วนประเภทการเดินทาง (Doughnut Chart) -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-4">
                        <h6 class="fw-bold text-dark mb-3">
                            <i class="fa-solid fa-chart-pie text-purple me-2"></i> สัดส่วนประเภทการเดินทาง
                        </h6>
                        <div style="height: 240px; position: relative;">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- สรุปความพึงพอใจโดยรวม -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-4">
                        <h6 class="fw-bold text-dark mb-3">
                            <i class="fa-solid fa-star text-warning me-2"></i> สรุปผลการประเมินความพึงพอใจ
                        </h6>
                        <?php 
                            $total_evals = array_sum($eval_counts);
                            $pct_ex = $total_evals > 0 ? round(($eval_counts['ดีมาก'] / $total_evals) * 100, 1) : 0;
                            $pct_gd = $total_evals > 0 ? round(($eval_counts['ดี'] / $total_evals) * 100, 1) : 0;
                            $pct_bd = $total_evals > 0 ? round(($eval_counts['ไม่ดี'] / $total_evals) * 100, 1) : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-bold text-success"><i class="fa-solid fa-face-grin-stars me-1"></i>ดีมาก</span>
                                <span class="text-muted"><?= $eval_counts['ดีมาก'] ?> ครั้ง (<?= $pct_ex ?>%)</span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 6px;">
                                <div class="progress-bar bg-success" style="width: <?= $pct_ex ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-bold text-primary"><i class="fa-solid fa-face-smile me-1"></i>ดี</span>
                                <span class="text-muted"><?= $eval_counts['ดี'] ?> ครั้ง (<?= $pct_gd ?>%)</span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 6px;">
                                <div class="progress-bar bg-primary" style="width: <?= $pct_gd ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-bold text-danger"><i class="fa-solid fa-face-frown me-1"></i>ไม่ดี</span>
                                <span class="text-muted"><?= $eval_counts['ไม่ดี'] ?> ครั้ง (<?= $pct_bd ?>%)</span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 6px;">
                                <div class="progress-bar bg-danger" style="width: <?= $pct_bd ?>%"></div>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded-3 text-muted small">
                            <i class="fa-solid fa-circle-info text-primary me-1"></i> ข้อมูลจากการประเมินตนเองของผู้เข้าร่วมในแบบรายงานผลหลังกลับจากการไปราชการ
                        </div>
                    </div>
                </div>
            </div>

            <!-- ภาพรวมงบประมาณเปรียบเทียบ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-wallet text-success me-2"></i> ภาพรวมเปรียบเทียบประมาณการและจ่ายจริง
                </h6>
                <div class="row g-4 align-items-center">
                    <div class="col-md-6 border-end-md">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">ประมาณการงบประมาณรวม</span>
                            <span class="fw-bold text-dark">฿<?= number_format($total_est_budget) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">งบประมาณที่ใช้จริง</span>
                            <span class="fw-bold text-success">฿<?= number_format($total_budget) ?></span>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-2">
                            <span class="text-muted">ส่วนต่างคงเหลือ</span>
                            <span class="fw-bold <?= ($total_est_budget - $total_budget >= 0) ? 'text-primary' : 'text-danger' ?>">
                                ฿<?= number_format($total_est_budget - $total_budget) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-2">
                            <div class="stat-icon bg-success-subtle text-success rounded-3 me-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                <i class="fa-solid fa-file-circle-check"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">อัตราการส่งรายงานผลหลังไปราชการ</h6>
                                <small class="text-muted">ส่งรายงานผลเรียบร้อยแล้ว <?= $reported_count ?> จาก <?= $total_trips ?> รายการ (<?= $reported_pct ?>%)</small>
                            </div>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 4px;">
                            <div class="progress-bar" style="width: <?= min(100, $reported_pct) ?>%; background-color: #198754 !important;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const ctx = document.getElementById('typeChart')?.getContext('2d');
                    if (ctx && typeof Chart !== 'undefined') {
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: <?= json_encode(array_keys($type_counts), JSON_UNESCAPED_UNICODE) ?>,
                                datasets: [{
                                    data: <?= json_encode(array_values($type_counts)) ?>,
                                    backgroundColor: ['#6f42c1', '#198754', '#0dcaf0', '#ffc107', '#6c757d'],
                                    borderWidth: 0
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 } } }
                                }
                            }
                        });
                    }
                });
            </script>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: แผนที่จุดหมายปลายทาง (Travel Map) -->
        <!-- ========================================== -->
        <div class="tab-pane fade" id="tab-map-pane" role="tabpanel">
            <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
                <div class="card-header bg-white border-0 pt-3 px-4 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-map-location-dot text-danger me-2"></i> แผนที่จุดหมายปลายทางที่เคยไป
                        </h6>
                        <small class="text-muted">แสดงตำแหน่งสถานที่ที่เคยเดินทางไปราชการ/สัมมนา/อบรม</small>
                    </div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach (array_slice($visited_locations, 0, 8) as $vl): ?>
                            <span class="badge bg-light text-secondary border extra-small rounded-pill"><i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($vl) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($visited_locations) > 8): ?>
                            <span class="badge bg-light text-muted border extra-small rounded-pill">+<?= count($visited_locations) - 8 ?> อื่นๆ</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0 position-relative">
                    <!-- Leaflet CSS & JS -->
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
                    
                    <div id="travelMap" style="height: 480px; width: 100%; z-index: 1;"></div>
                </div>
            </div>

            <script>
            let travelMapInstance = null;
            let mapMarkersLoaded = false;

            function initOrResizeMap() {
                setTimeout(function() {
                    if (!travelMapInstance) {
                        initLeafletMap();
                    } else {
                        travelMapInstance.invalidateSize();
                    }
                }, 200);
            }

            function initLeafletMap() {
                if (typeof L === 'undefined') return;

                delete L.Icon.Default.prototype._getIconUrl;
                L.Icon.Default.mergeOptions({
                    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                });

                travelMapInstance = L.map('travelMap').setView([15.0, 101.5], 6); // ศูนย์กลางประเทศไทย

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap contributors'
                }).addTo(travelMapInstance);

                if (!mapMarkersLoaded) {
                    loadMapMarkers();
                }
            }

            async function loadMapMarkers() {
                const locations = <?= $locations_json; ?>;
                if (!locations || locations.length === 0) return;
                mapMarkersLoaded = true;

                const sleep = ms => new Promise(r => setTimeout(r, ms));
                let bounds = [];

                for (let i = 0; i < locations.length; i++) {
                    const loc = locations[i];
                    try {
                        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(loc + ' ประเทศไทย')}&limit=1`, {
                            headers: { 'Accept-Language': 'th,en;q=0.9' }
                        });
                        const data = await res.json();
                        if (data && data.length > 0) {
                            const lat = parseFloat(data[0].lat);
                            const lon = parseFloat(data[0].lon);
                            L.marker([lat, lon]).addTo(travelMapInstance).bindPopup(`<div class="fw-bold text-dark small"><i class="fa-solid fa-location-dot text-danger me-1"></i>${loc}</div>`);
                            bounds.push([lat, lon]);
                        }
                    } catch(e) {}
                    
                    if (bounds.length > 0 && i === locations.length - 1 && travelMapInstance) {
                        travelMapInstance.fitBounds(bounds, { padding: [50, 50], maxZoom: 13 });
                    }
                    await sleep(600);
                }
            }
            </script>
        </div>
    </div>
</main>

<style>
    /* ธีมคณะดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม */
    .text-purple { color: #6f42c1 !important; }
    .bg-purple { background-color: #6f42c1 !important; color: #ffffff !important; }
    .bg-purple-subtle { background-color: rgba(111, 66, 193, 0.12) !important; }
    .border-purple-subtle { border-color: rgba(111, 66, 193, 0.25) !important; }
    
    .btn-purple { 
        background-color: #6f42c1; 
        color: #ffffff; 
        border: none;
        transition: all 0.2s ease-in-out; 
    }
    .btn-purple:hover { 
        background-color: #59359a; 
        color: #ffffff; 
        transform: translateY(-1px); 
        box-shadow: 0 4px 12px rgba(111, 66, 193, 0.25); 
    }

    .btn-outline-purple { 
        color: #6f42c1; 
        border-color: #6f42c1; 
        transition: all 0.2s ease-in-out; 
    }
    .btn-outline-purple:hover { 
        background-color: #6f42c1; 
        color: #ffffff; 
    }

    .hover-purple:hover {
        color: #6f42c1 !important;
    }

    .shadow-xs {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .extra-small {
        font-size: 0.75rem;
    }

    /* สไตล์แท็บ Segmented Pills */
    .nav-pills .nav-link {
        color: #6c757d;
        transition: all 0.2s ease;
    }
    .nav-pills .nav-link.active {
        background-color: #6f42c1 !important;
        color: #ffffff !important;
    }
    .nav-pills .nav-link.active .badge {
        background-color: #ffffff !important;
        color: #6f42c1 !important;
    }
</style>

<?php require_once '../../includes/footer.php'; ?>