<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. เช็คสิทธิ์การเข้าถึง (สำหรับผู้บริหาร งานบุคคล งานแผน และ Admin)
// ---------------------------------------------------------
$view_roles = ['admin', 'หัวหน้าสำนักงาน', 'งานบุคคล', 'งานแผน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($view_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง: สำหรับผู้บริหาร งานบุคคล และงานแผนเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

// ---------------------------------------------------------
// 2. จัดการตัวกรองปีงบประมาณและบุคลากร
// ---------------------------------------------------------
try {
    $stmt_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
    $fiscal_years = $stmt_fy->fetchAll();
    
    $default_year = '';
    foreach ($fiscal_years as $fy) {
        if ($fy['is_active'] == 1) { $default_year = $fy['year_name']; break; }
    }
    if (empty($default_year) && !empty($fiscal_years)) {
        $default_year = $fiscal_years[0]['year_name'];
    }
} catch (PDOException $e) { $fiscal_years = []; $default_year = 'all'; }

$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : $default_year;
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : 'all';

// ดึงรายชื่อบุคลากรทั้งหมดที่มีรายงาน
try {
    $stmt_users = $pdo->query("
        SELECT DISTINCT u.user_id, u.first_name, u.last_name, d.dept_name
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        ORDER BY u.first_name, u.last_name
    ");
    $all_users = $stmt_users->fetchAll();
} catch (PDOException $e) { $all_users = []; }

// ---------------------------------------------------------
// 3. เตรียมเงื่อนไข Query
// ---------------------------------------------------------
$where_clauses = ["1=1"];
$params = [];
if ($filter_year !== 'all') {
    $where_clauses[] = "tr.fiscal_year = :filter_year";
    $params['filter_year'] = $filter_year;
}
if ($filter_user !== 'all') {
    $where_clauses[] = "tr.user_id = :filter_user";
    $params['filter_user'] = $filter_user;
}
$where_sql = implode(" AND ", $where_clauses);

// ---------------------------------------------------------
// 4. ดึงข้อมูลสถิติภาพรวม (KPI Metrics)
// ---------------------------------------------------------
$stats = [
    'total_reports' => 0, 
    'total_budget' => 0, 
    'total_users' => 0, 
    'total_days' => 0,
    'idp_aligned_reports' => 0,
    'idp_budget_used' => 0,
    'total_training_hours' => 0,
    'reported_count' => 0,
    'pending_count' => 0,
    'approved_count' => 0,
    'est_travel' => 0,
    'est_accom' => 0,
    'est_allowance' => 0,
    'est_regis' => 0,
    'est_other' => 0,
    'act_travel' => 0,
    'act_accom' => 0,
    'act_allowance' => 0,
    'act_regis' => 0,
    'act_other' => 0,
    'total_est_budget' => 0
];

try {
    $stmt_stats = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reports,
            COUNT(CASE WHEN tr.status = 'reported' THEN 1 END) as reported_count,
            COUNT(CASE WHEN tr.status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN tr.status = 'returned' THEN 1 END) as returned_count,
            COUNT(CASE WHEN tr.status = 'approved' THEN 1 END) as approved_count,
            SUM(IFNULL(tr.training_hours, 0)) as total_training_hours,
            SUM(tr.budget_used) as total_budget,
            SUM(IFNULL(tr.est_budget_travel, 0) + IFNULL(tr.est_budget_accom, 0) + IFNULL(tr.est_budget_allowance, 0) + IFNULL(tr.est_budget_regis, 0) + IFNULL(tr.est_budget_other, 0)) as total_est_budget,
            SUM(IFNULL(tr.est_budget_travel, 0)) as est_travel,
            SUM(IFNULL(tr.est_budget_accom, 0)) as est_accom,
            SUM(IFNULL(tr.est_budget_allowance, 0)) as est_allowance,
            SUM(IFNULL(tr.est_budget_regis, 0)) as est_regis,
            SUM(IFNULL(tr.est_budget_other, 0)) as est_other,
            SUM(IFNULL(tr.act_budget_travel, 0)) as act_travel,
            SUM(IFNULL(tr.act_budget_accom, 0)) as act_accom,
            SUM(IFNULL(tr.act_budget_allowance, 0)) as act_allowance,
            SUM(IFNULL(tr.act_budget_regis, 0)) as act_regis,
            SUM(IFNULL(tr.act_budget_other, 0)) as act_other,
            COUNT(DISTINCT tr.user_id) as total_users,
            SUM(DATEDIFF(tr.end_date, tr.start_date) + 1) as total_days
        FROM training_reports tr
        WHERE tr.status != 'rejected' AND $where_sql
    ");
    $stmt_stats->execute($params);
    $res_stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    if ($res_stats) { 
        $stats['total_reports'] = $res_stats['total_reports'] ?: 0;
        $stats['reported_count'] = $res_stats['reported_count'] ?: 0;
        $stats['pending_count'] = $res_stats['pending_count'] ?: 0;
        $stats['approved_count'] = $res_stats['approved_count'] ?: 0;
        $stats['idp_aligned_reports'] = $res_stats['idp_aligned_reports'] ?? 0;
        $stats['idp_budget_used'] = $res_stats['idp_budget_used'] ?? 0;
        $stats['total_training_hours'] = $res_stats['total_training_hours'] ?: 0;
        $stats['total_budget'] = $res_stats['total_budget'] ?: 0;
        $stats['total_est_budget'] = $res_stats['total_est_budget'] ?: 0;
        
        $stats['est_travel'] = $res_stats['est_travel'] ?: 0;
        $stats['est_accom'] = $res_stats['est_accom'] ?: 0;
        $stats['est_allowance'] = $res_stats['est_allowance'] ?: 0;
        $stats['est_regis'] = $res_stats['est_regis'] ?: 0;
        $stats['est_other'] = $res_stats['est_other'] ?: 0;
        
        $stats['act_travel'] = $res_stats['act_travel'] ?: 0;
        $stats['act_accom'] = $res_stats['act_accom'] ?: 0;
        $stats['act_allowance'] = $res_stats['act_allowance'] ?: 0;
        $stats['act_regis'] = $res_stats['act_regis'] ?: 0;
        $stats['act_other'] = $res_stats['act_other'] ?: 0;
        $stats['total_users'] = $res_stats['total_users'] ?: 0;
        $stats['total_days'] = $res_stats['total_days'] ?: 0;
    }

    // 5. ดึงข้อมูลสถิติผลการประเมิน
    $eval_stats = ['ดีมาก' => 0, 'ดี' => 0, 'ไม่ดี' => 0];
    $stmt_eval = $pdo->prepare("SELECT tr.evaluation, COUNT(*) as count FROM training_reports tr WHERE tr.evaluation IS NOT NULL AND tr.evaluation != '' AND tr.status != 'rejected' AND $where_sql GROUP BY tr.evaluation");
    $stmt_eval->execute($params);
    while ($row = $stmt_eval->fetch(PDO::FETCH_ASSOC)) {
        $eval_stats[$row['evaluation']] = $row['count'];
    }

    // 5.5 ดึงข้อมูลสถิติประเภทการไปราชการ
    $type_stats = ['สัมมนา' => 0, 'อบรม' => 0, 'ดูงาน' => 0, 'ไปราชการ' => 0, 'อื่นๆ' => 0];
    $stmt_type = $pdo->prepare("SELECT tr.report_type, COUNT(*) as count FROM training_reports tr WHERE tr.status != 'rejected' AND $where_sql GROUP BY tr.report_type");
    $stmt_type->execute($params);
    while ($row = $stmt_type->fetch(PDO::FETCH_ASSOC)) {
        $rtype = !empty($row['report_type']) ? trim($row['report_type']) : 'ไปราชการ';
        if (isset($type_stats[$rtype])) {
            $type_stats[$rtype] += $row['count'];
        } else {
            $type_stats['อื่นๆ'] += $row['count'];
        }
    }

    // 6. ดึงข้อมูลรายการทั้งหมดสำหรับ Data Table
    $stmt_list = $pdo->prepare("
        SELECT tr.report_id, tr.project_name, tr.start_date, tr.end_date, tr.budget_used, tr.status, tr.current_step, tr.created_at, tr.location, tr.report_type,
               tr.training_hours,
               (IFNULL(tr.est_budget_travel, 0) + IFNULL(tr.est_budget_accom, 0) + IFNULL(tr.est_budget_allowance, 0) + IFNULL(tr.est_budget_regis, 0) + IFNULL(tr.est_budget_other, 0)) as est_budget_total,
               u.first_name, u.last_name, d.dept_name
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE $where_sql
        ORDER BY tr.created_at DESC
    ");
    $stmt_list->execute($params);
    $reports_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

    // 7. ดึงข้อมูลปฏิทิน
    $calendar_events = [];
    $stmt_cal = $pdo->prepare("
        SELECT tr.report_id, tr.project_name, tr.start_date, tr.end_date, tr.location, u.first_name, u.last_name, tr.status, tr.report_type
        FROM training_reports tr 
        JOIN users u ON tr.user_id = u.user_id 
        WHERE tr.status != 'rejected' AND $where_sql
    ");
    $stmt_cal->execute($params);
    while ($row = $stmt_cal->fetch(PDO::FETCH_ASSOC)) {
        $type = !empty($row['report_type']) ? trim($row['report_type']) : 'ไปราชการ';
        
        $cal_color = '#6c757d'; // Default (อื่นๆ)
        if ($type == 'สัมมนา') $cal_color = '#6f42c1';
        else if ($type == 'อบรม') $cal_color = '#198754';
        else if ($type == 'ดูงาน') $cal_color = '#0dcaf0';
        else if ($type == 'ไปราชการ') $cal_color = '#ffc107';
        
        $calendar_events[] = [
            'id' => $row['report_id'],
            'title' => $type . ': ' . $row['first_name'] . ' (' . mb_strimwidth($row['project_name'], 0, 20, '...') . ')',
            'start' => $row['start_date'],
            'end' => date('Y-m-d', strtotime($row['end_date'] . ' +1 day')),
            'color' => $cal_color,
            'textColor' => ($type == 'ไปราชการ') ? '#000' : '#fff',
            'description' => "โครงการ: " . $row['project_name'] . "\nประเภท: " . $type . "\nสถานที่: " . $row['location'] . "\nบุคลากร: " . $row['first_name'] . " " . $row['last_name'] . "\nสถานะ: " . ($row['status'] == 'reported' ? 'บันทึกรายงานแล้ว' : 'รอรายงานผล')
        ];
    }

} catch (PDOException $e) { die("Error: " . $e->getMessage()); }

$total_eval = array_sum($eval_stats);
$pct_excellent = $total_eval > 0 ? round(($eval_stats['ดีมาก'] / $total_eval) * 100) : 0;
$pct_good = $total_eval > 0 ? round(($eval_stats['ดี'] / $total_eval) * 100) : 0;
$pct_poor = $total_eval > 0 ? round(($eval_stats['ไม่ดี'] / $total_eval) * 100) : 0;

$budget_saving = $stats['total_est_budget'] - $stats['total_budget'];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-purple me-2"></i> แดชบอร์ดภาพรวมการไปราชการ & ฝึกอบรม
            </h1>
            <p class="text-muted small mb-0">ระบบติดตามสถิติการพัฒนาบุคลากร และการใช้งบประมาณ คณะดุริยางคศิลป์</p>
        </div>
        <div class="d-flex gap-2 align-items-center mt-3 mt-md-0 flex-wrap justify-content-md-end">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-list me-1"></i> หน้ารายการ
            </a>
            <a href="export_csv.php?filter_year=<?= urlencode($filter_year); ?>&filter_user=<?= urlencode($filter_user); ?>" class="btn btn-success rounded-pill fw-bold shadow-xs px-3 py-2">
                <i class="fa-solid fa-file-csv me-1"></i> ส่งออก CSV
            </a>
        </div>
    </div>

    <!-- Executive Filter Toolbar -->
    <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form action="" method="GET" class="row g-2 align-items-center">
                <div class="col-md-5 col-12">
                    <label class="form-label extra-small text-muted fw-bold mb-1">กรองตามบุคลากร</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-user text-muted"></i></span>
                        <select name="filter_user" class="form-select border-0 bg-light" onchange="this.form.submit()">
                            <option value="all" <?= $filter_user === 'all' ? 'selected' : ''; ?>>บุคลากรทั้งหมด (ทุกคน)</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= htmlspecialchars($u['user_id']); ?>" <?= $filter_user == $u['user_id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . (!empty($u['dept_name']) ? ' (' . $u['dept_name'] . ')' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-4 col-8">
                    <label class="form-label extra-small text-muted fw-bold mb-1">ปีงบประมาณ</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-calendar-days text-muted"></i></span>
                        <select name="filter_year" class="form-select border-0 bg-light" onchange="this.form.submit()">
                            <option value="all" <?= $filter_year === 'all' ? 'selected' : ''; ?>>ทุกปีงบประมาณ</option>
                            <?php foreach ($fiscal_years as $fy): ?>
                                <option value="<?= htmlspecialchars($fy['year_name']); ?>" <?= $filter_year == $fy['year_name'] ? 'selected' : ''; ?>>
                                    ปีงบประมาณ <?= htmlspecialchars($fy['year_name']); ?><?= $fy['is_active'] ? ' (ปัจจุบัน)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3 col-4 text-end d-flex align-items-end justify-content-end gap-2" style="height: 100%;">
                    <div class="w-100">
                        <label class="form-label extra-small text-muted fw-bold mb-1 d-none d-md-block">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <?php if ($filter_year !== $default_year || $filter_user !== 'all'): ?>
                                <a href="dashboard_admin.php" class="btn btn-sm btn-outline-secondary w-50 rounded-3" title="รีเซ็ตตัวกรอง">
                                    <i class="fa-solid fa-rotate-left"></i> รีเซ็ต
                                </a>
                            <?php endif; ?>
                            <button type="button" onclick="window.location.reload();" class="btn btn-sm btn-light border text-dark <?= ($filter_year !== $default_year || $filter_user !== 'all') ? 'w-50' : 'w-100'; ?> rounded-3">
                                <i class="fa-solid fa-rotate-right me-1"></i> รีเฟรช
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 4 Key Metric Cards (Row 1) -->
    <div class="row g-3 mb-4">
        <!-- 1. รายงานโครงการทั้งหมด -->
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100 border-0 shadow-xs rounded-4 bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">รายงานโครงการทั้งหมด</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['total_reports']); ?></h3>
                            <span class="text-muted small">ครั้ง</span>
                        </div>
                        <small class="text-muted extra-small">
                            ส่งรายงานแล้ว <span class="text-success fw-bold"><?= $stats['reported_count']; ?></span> / รอรายงาน <span class="text-info fw-bold"><?= $stats['approved_count']; ?></span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 2. งบประมาณที่ใช้จริง -->
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100 border-0 shadow-xs rounded-4 bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">งบประมาณจ่ายจริง</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-success mb-0">฿<?= number_format($stats['total_budget']); ?></h3>
                        </div>
                        <small class="text-muted extra-small">
                            จากประมาณการ ฿<?= number_format($stats['total_est_budget']); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. บุคลากรที่เข้าร่วม -->
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100 border-0 shadow-xs rounded-4 bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">บุคลากรที่เข้าร่วม</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['total_users']); ?></h3>
                            <span class="text-muted small">คน</span>
                        </div>
                        <small class="text-muted extra-small">
                            ระยะเวลาอบรมรวม <strong class="text-dark"><?= number_format($stats['total_days']); ?></strong> วัน
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. ชั่วโมงอบรมสะสม -->
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100 border-0 shadow-xs rounded-4 bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info-subtle text-info rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-regular fa-clock"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">ชั่วโมงอบรมรวมสะสม</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['total_training_hours']); ?></h3>
                            <span class="text-muted small">ชม.</span>
                        </div>
                        <small class="text-muted extra-small">
                            เฉลี่ยประมาณ <?= $stats['total_reports'] > 0 ? round($stats['total_training_hours'] / $stats['total_reports'], 1) : 0; ?> ชม./โครงการ
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ภาพรวมงบประมาณแบบละเอียด (เปรียบเทียบตารางหมวดค่าใช้จ่าย) -->
    <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
        <div class="card-header bg-white p-3 p-md-4 border-bottom-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0 fw-bold text-dark">
                    <i class="fa-solid fa-money-check-dollar text-success me-2"></i> ภาพรวมงบประมาณแบบละเอียด (เปรียบเทียบประมาณการ vs จ่ายจริง)
                </h5>
                <small class="text-muted">ตรวจสอบความคุ้มค่าและการประหยัดงบประมาณตามหมวดค่าใช้จ่าย</small>
            </div>
            <?php if ($budget_saving > 0): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 small">
                    <i class="fa-solid fa-arrow-down me-1"></i> ประหยัดงบได้ ฿<?= number_format($budget_saving); ?>
                </span>
            <?php elseif ($budget_saving < 0): ?>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-2 small">
                    <i class="fa-solid fa-arrow-up me-1"></i> เกินประมาณการ ฿<?= number_format(abs($budget_saving)); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body px-3 px-md-4 pb-4 pt-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle table-hover mb-0">
                    <thead class="table-light text-muted small">
                        <tr>
                            <th class="ps-3" style="width: 28%;">หมวดค่าใช้จ่าย</th>
                            <th class="text-end" style="width: 22%;">ประมาณการ</th>
                            <th class="text-end" style="width: 22%;">จ่ายจริง</th>
                            <th class="text-end" style="width: 18%;">ส่วนต่าง</th>
                            <th class="text-center" style="width: 10%;">สัดส่วนจ่าย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $budget_items = [
                            ['label' => 'ค่าเดินทาง', 'icon' => 'fa-car-side', 'est' => $stats['est_travel'], 'act' => $stats['act_travel']],
                            ['label' => 'ค่าที่พัก', 'icon' => 'fa-hotel', 'est' => $stats['est_accom'], 'act' => $stats['act_accom']],
                            ['label' => 'ค่าเบี้ยเลี้ยง', 'icon' => 'fa-utensils', 'est' => $stats['est_allowance'], 'act' => $stats['act_allowance']],
                            ['label' => 'ค่าลงทะเบียน', 'icon' => 'fa-ticket', 'est' => $stats['est_regis'], 'act' => $stats['act_regis']],
                            ['label' => 'ค่าอื่นๆ', 'icon' => 'fa-folder-open', 'est' => $stats['est_other'], 'act' => $stats['act_other']],
                        ];
                        foreach ($budget_items as $item):
                            $diff = $item['est'] - $item['act'];
                            $rate = $item['est'] > 0 ? min(100, round(($item['act'] / $item['est']) * 100)) : ($item['act'] > 0 ? 100 : 0);
                        ?>
                        <tr>
                            <td class="ps-3">
                                <span class="fw-semibold text-dark small"><i class="fa-solid <?= $item['icon']; ?> text-secondary me-2"></i><?= $item['label']; ?></span>
                            </td>
                            <td class="text-end small text-muted">
                                ฿<?= number_format($item['est']); ?>
                            </td>
                            <td class="text-end small fw-bold <?= ($item['act'] > 0) ? 'text-success' : 'text-dark'; ?>">
                                ฿<?= number_format($item['act']); ?>
                            </td>
                            <td class="text-end small <?= ($diff >= 0) ? 'text-primary' : 'text-danger'; ?>">
                                <?= ($diff >= 0 ? '+' : '') . number_format($diff); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border extra-small rounded-pill"><?= $rate; ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-light fw-bold border-top border-2">
                            <td class="ps-3 text-dark">รวมงบประมาณทั้งสิ้น</td>
                            <td class="text-end text-primary fs-6">฿<?= number_format($stats['total_est_budget']); ?></td>
                            <td class="text-end text-success fs-6">฿<?= number_format($stats['total_budget']); ?></td>
                            <td class="text-end <?= ($budget_saving >= 0) ? 'text-primary' : 'text-danger'; ?> fs-6">
                                <?= ($budget_saving >= 0 ? '+' : '') . number_format($budget_saving); ?>
                            </td>
                            <td class="text-center">
                                <?php $total_rate = $stats['total_est_budget'] > 0 ? round(($stats['total_budget'] / $stats['total_est_budget']) * 100) : 0; ?>
                                <span class="badge bg-purple text-white rounded-pill extra-small"><?= $total_rate; ?>%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Analytics & Calendar Row -->
    <div class="row g-4 mb-4">
        <!-- ฝั่งซ้าย: ผลประเมิน & กราฟประเภท (Col 4) -->
        <div class="col-xl-4 col-lg-5">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-star text-warning me-2"></i> ผลการประเมินความพึงพอใจ
                </h6>
                
                <?php if ($total_eval == 0): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-chart-pie fs-1 mb-2 opacity-25"></i><br>ยังไม่มีข้อมูลประเมินผลในปีนี้
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="fw-bold text-success"><i class="fa-solid fa-face-grin-stars me-1"></i> ดีมาก</span>
                            <span class="fw-bold text-dark"><?= $pct_excellent; ?>% <span class="text-muted fw-normal">(<?= $eval_stats['ดีมาก']; ?>)</span></span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 4px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pct_excellent; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="fw-bold text-primary"><i class="fa-solid fa-face-smile me-1"></i> ดี</span>
                            <span class="fw-bold text-dark"><?= $pct_good; ?>% <span class="text-muted fw-normal">(<?= $eval_stats['ดี']; ?>)</span></span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 4px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct_good; ?>%"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="fw-bold text-danger"><i class="fa-solid fa-face-frown me-1"></i> ไม่ดี</span>
                            <span class="fw-bold text-dark"><?= $pct_poor; ?>% <span class="text-muted fw-normal">(<?= $eval_stats['ไม่ดี']; ?>)</span></span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 4px;">
                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $pct_poor; ?>%"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <hr class="my-4">
                
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-chart-pie text-purple me-2"></i> สัดส่วนประเภทการไปราชการ
                </h6>
                <div style="position: relative; height: 210px; width: 100%;">
                    <canvas id="typePieChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ฝั่งขวา: ปฏิทินปฏิบัติราชการ (Col 8) -->
        <div class="col-xl-8 col-lg-7">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-calendar-days text-success me-2"></i> ปฏิทินปฏิบัติราชการและการอบรม
                        </h6>
                        <small class="text-muted">คลิกที่รายการในปฏิทินเพื่อดูรายละเอียดเพิ่มเติม</small>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <span class="badge rounded-pill fw-normal" style="background-color: #6f42c1; color: white;">สัมมนา</span>
                        <span class="badge rounded-pill fw-normal" style="background-color: #198754; color: white;">อบรม</span>
                        <span class="badge rounded-pill fw-normal" style="background-color: #0dcaf0; color: white;">ดูงาน</span>
                        <span class="badge rounded-pill fw-normal" style="background-color: #ffc107; color: black;">ไปราชการ</span>
                    </div>
                </div>
                <div id="trainingCalendar" style="min-height: 380px;"></div>
            </div>
        </div>
    </div>

    <!-- Data Table Card (ฐานข้อมูลการไปราชการ/อบรม) -->
    <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h5 class="mb-0 fw-bold text-dark">
                    <i class="fa-solid fa-table-list text-primary me-2"></i> ฐานข้อมูลการไปราชการ & ฝึกอบรม
                </h5>
                <small class="text-muted">รายการคำขอและรายงานผลการไปราชการทั้งหมดตามเงื่อนไขที่เลือก (<?= count($reports_list); ?> รายการ)</small>
            </div>
        </div>
        
        <div class="table-responsive">
            <table id="trainingTable" class="table table-hover align-middle border-top" style="width:100%">
                <thead class="table-light text-muted small">
                    <tr>
                        <th width="18%" class="ps-3">ผู้รายงาน / สังกัด</th>
                        <th width="26%">หลักสูตร / โครงการ</th>
                        <th width="10%">ประเภท</th>
                        <th width="12%">วันที่จัด</th>
                        <th width="12%">งบประมาณ</th>
                        <th width="12%">สถานะ</th>
                        <th width="10%" class="text-center pe-3">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports_list as $r): ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-bold text-dark small"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></div>
                            <div class="extra-small text-muted"><?= htmlspecialchars($r['dept_name'] ?? '-'); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark small text-truncate" style="max-width: 270px;" title="<?= htmlspecialchars($r['project_name']); ?>">
                                <?= htmlspecialchars($r['project_name']); ?>
                            </div>
                            <div class="extra-small text-muted text-truncate" style="max-width: 270px;">
                                <i class="fa-solid fa-location-dot me-1 text-danger"></i> <?= htmlspecialchars($r['location']); ?>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php if (!empty($r['training_hours']) && $r['training_hours'] > 0): ?>
                                    <span class="badge bg-light text-muted border rounded-pill extra-small">
                                        <i class="fa-regular fa-clock me-1"></i><?= $r['training_hours']; ?> ชม.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $t = $r['report_type'] ?? 'ไปราชการ';
                                $bg = 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                if($t == 'สัมมนา') $bg = 'bg-primary-subtle text-primary border border-primary-subtle';
                                else if($t == 'อบรม') $bg = 'bg-success-subtle text-success border border-success-subtle';
                                else if($t == 'ดูงาน') $bg = 'bg-info-subtle text-info border border-info-subtle';
                                else if($t == 'ไปราชการ') $bg = 'bg-warning-subtle text-dark border border-warning-subtle';
                            ?>
                            <span class="badge <?= $bg; ?> px-2 py-1 fw-normal rounded-pill extra-small"><?= htmlspecialchars($t); ?></span>
                        </td>
                        <td data-sort="<?= strtotime($r['start_date']); ?>">
                            <div class="small fw-bold text-dark">
                                <?php 
                                    if ($r['start_date'] == $r['end_date']) echo date('d/m/Y', strtotime($r['start_date']));
                                    else echo date('d/m', strtotime($r['start_date'])) . '-' . date('d/m/Y', strtotime($r['end_date']));
                                ?>
                            </div>
                        </td>
                        <td data-sort="<?= $r['budget_used']; ?>">
                            <div class="small fw-bold <?= $r['budget_used'] > 0 ? 'text-success' : 'text-dark'; ?>">
                                ฿<?= number_format($r['budget_used']); ?>
                            </div>
                            <div class="extra-small text-muted" title="ประมาณการงบประมาณ">
                                ป: ฿<?= number_format($r['est_budget_total']); ?>
                            </div>
                        </td>
                        <td data-sort="<?= $r['status']; ?>">
                            <?php if ($r['status'] == 'pending'): ?>
                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1 rounded-pill extra-small">
                                    <i class="fa-solid fa-hourglass-half me-1"></i>รออนุมัติขั้น <?= $r['current_step'] ?? 1; ?>
                                </span>
                            <?php elseif ($r['status'] == 'returned'): ?>
                                <span class="badge bg-warning text-dark border border-warning px-2 py-1 rounded-pill extra-small fw-bold">
                                    <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับแก้ไข
                                </span>
                            <?php elseif ($r['status'] == 'approved'): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill extra-small">
                                    <i class="fa-solid fa-file-pen me-1"></i>อนุมัติแล้ว (รอรายงาน)
                                </span>
                            <?php elseif ($r['status'] == 'reported'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill extra-small">
                                    <i class="fa-solid fa-check-circle me-1"></i>รายงานผลแล้ว
                                </span>
                            <?php elseif ($r['status'] == 'rejected'): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill extra-small">
                                    <i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center pe-3">
                            <a href="view.php?id=<?= $r['report_id']; ?>" class="btn btn-sm btn-light border text-primary rounded-pill px-3 shadow-xs">
                                ดูข้อมูล
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Calendar Event Modal -->
<div class="modal fade" id="calModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 text-center position-relative">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-3 d-flex align-items-center justify-content-center mx-auto bg-purple-subtle text-purple rounded-circle" style="width: 70px; height: 70px;">
                    <i class="fa-solid fa-car-side fa-2x"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1" id="calModalTitle">ชื่อเหตุการณ์</h5>
                <p class="text-muted small mb-3" id="calModalDate"><i class="fa-regular fa-clock me-1"></i> วันที่</p>
                <div class="bg-light rounded-3 p-3 mb-3 text-start border">
                    <span class="small fw-bold text-dark"><i class="fa-solid fa-circle-info text-primary me-2"></i>รายละเอียดเพิ่มเติม:</span>
                    <p class="text-muted small mb-0 mt-2" id="calModalDesc" style="white-space: pre-line; line-height: 1.6;"></p>
                </div>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light px-4 py-2 rounded-pill fw-bold text-secondary shadow-xs border" data-bs-dismiss="modal">ปิด</button>
                    <a href="#" id="calModalLink" class="btn btn-purple px-4 py-2 rounded-pill fw-bold shadow-xs">ดูรายงานเต็ม</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
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

    .shadow-xs {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .extra-small {
        font-size: 0.75rem;
    }

    /* FullCalendar Style Overrides */
    .fc-theme-standard th { border: none; padding: 8px 0; color: #718096; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;}
    .fc-daygrid-day-number { color: #4a5568; text-decoration: none; padding: 4px !important; font-weight: 500; }
    .fc-event { border: none !important; border-radius: 6px; padding: 3px 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .fc-button-primary { background-color: #6f42c1 !important; border-color: #6f42c1 !important; }
    .fc-button-primary:hover { background-color: #59359a !important; border-color: #59359a !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script>
    if (typeof jQuery == 'undefined') {
        document.write('<script src="https://code.jquery.com/jquery-3.7.0.min.js"><\/script>');
    }
</script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // --- 1. DataTables ---
    try {
        if (typeof jQuery !== 'undefined') {
            jQuery('#trainingTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json"
                },
                "pageLength": 10,
                "ordering": true,
                "order": [[ 3, "desc" ]], // เรียงตามวันที่จัด
                "columnDefs": [
                    { "orderable": false, "targets": 6 } 
                ]
            });
        }
    } catch(e) { console.error("DataTables Error: ", e); }

    // --- 2. FullCalendar ---
    try {
        var calendarEl = document.getElementById('trainingCalendar');
        if (calendarEl) {
            var eventsData = <?= json_encode($calendar_events, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'th',
                height: 'auto',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listMonth'
                },
                events: eventsData,
                eventClick: function(info) {
                    let title = info.event.title;
                    let desc = info.event.extendedProps.description;
                    let reportId = info.event.id;
                    
                    let startDate = info.event.start.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
                    let dateText = startDate;
                    
                    if (info.event.end) {
                        let endDateObj = new Date(info.event.end);
                        endDateObj.setDate(endDateObj.getDate() - 1);
                        if (info.event.start.getTime() !== endDateObj.getTime()) {
                            dateText += " ถึง " + endDateObj.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
                        }
                    }

                    document.getElementById('calModalTitle').innerText = title;
                    document.getElementById('calModalDate').innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateText;
                    document.getElementById('calModalDesc').innerText = desc || '-';
                    document.getElementById('calModalLink').href = 'view.php?id=' + reportId;

                    if (typeof bootstrap !== 'undefined') {
                        var calModal = new bootstrap.Modal(document.getElementById('calModal'));
                        calModal.show();
                    } else {
                        alert(title + "\n" + dateText + "\n" + desc);
                    }
                }
            });
            calendar.render();
        }
    } catch(e) { console.error("Calendar Error: ", e); }

    // --- 3. Doughnut Chart สัดส่วนประเภทการไปราชการ ---
    try {
        var pieCtx = document.getElementById('typePieChart');
        if (pieCtx) {
            var typeData = <?= json_encode(array_values($type_stats)); ?>;
            var typeLabels = <?= json_encode(array_keys($type_stats)); ?>;
            var hasData = typeData.some(function(val) { return val > 0; });
            
            if (hasData) {
                new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            data: typeData,
                            backgroundColor: [
                                '#6f42c1', // สัมมนา (Purple)
                                '#198754', // อบรม (Success)
                                '#0dcaf0', // ดูงาน (Info)
                                '#ffc107', // ไปราชการ (Warning)
                                '#6c757d'  // อื่นๆ (Secondary)
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    font: { size: 11 }
                                }
                            }
                        }
                    }
                });
            } else {
                pieCtx.style.display = 'none';
                pieCtx.parentElement.innerHTML += '<div class="text-center text-muted py-4"><i class="fa-solid fa-folder-open fs-2 mb-2 opacity-25"></i><br>ยังไม่มีข้อมูลการไปราชการ</div>';
            }
        }
    } catch(e) { console.error("Chart Error: ", e); }

});
</script>