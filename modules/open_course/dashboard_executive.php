<?php
/**
 * Executive Academic Dashboard: Course Opening & Seat Reservation Tracking
 * แดชบอร์ดสรุปผลและติดตามคำร้องขอเปิดรายวิชา & ขอสำรองที่นั่ง สำหรับผู้บริหาร
 * วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ (ผู้บริหาร, งานวิชาการ, หัวหน้าภาควิชา, ประธานหลักสูตร, Admin)
// -----------------------------------------------------------------------------
$academic_exec_roles = [
    'admin', 'คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายบริหาร', 
    'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'หัวหน้าสำนักงาน', 'งานวิชาการ', 'งานบริการการศึกษา',
    'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'หัวหน้าภาควิชา'
];
$has_access = function_exists('hasRole') && hasRole($academic_exec_roles);

// เพิ่มการตรวจจับกรณีชื่อบทบาทมีคำว่า 'วิชาการ', 'บริการการศึกษา' หรือ 'รองคณบดี'
if (!$has_access && !empty($user_roles)) {
    foreach ($user_roles as $r) {
        if (
            mb_strpos($r, 'วิชาการ') !== false || 
            mb_strpos($r, 'บริการการศึกษา') !== false || 
            mb_strpos($r, 'รองคณบดี') !== false ||
            mb_strpos($r, 'หัวหน้าภาค') !== false ||
            mb_strpos($r, 'ประธานหลักสูตร') !== false
        ) {
            $has_access = true;
            break;
        }
    }
}

if (!$has_access) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: สำหรับผู้บริหารและงานวิชาการเท่านั้น'); window.location.href='../../index.php';</script>";
    exit();
}

// -----------------------------------------------------------------------------
// 1.1 ตรวจสอบสิทธิ์การลบคำร้อง (เฉพาะ งานวิชาการ / งานบริการการศึกษา / Admin)
// -----------------------------------------------------------------------------
$is_academic = count(array_intersect(['งานวิชาการ', 'งานบริการการศึกษา'], $user_roles)) > 0;
$is_admin = in_array('admin', $user_roles);
if (!$is_academic) {
    foreach ($user_roles as $r) {
        if (mb_strpos($r, 'วิชาการ') !== false || mb_strpos($r, 'บริการการศึกษา') !== false) {
            $is_academic = true;
            break;
        }
    }
}
$can_delete_request = $is_academic || $is_admin;

// -----------------------------------------------------------------------------
// 1.2 ประมวลผลการลบคำร้อง (เมื่อมี Action ส่งมา)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    if (!$can_delete_request) {
        $_SESSION['flash_msg'] = "ปฏิเสธการดำเนินการ: เฉพาะเจ้าหน้าที่งานวิชาการและผู้ดูแลระบบเท่านั้นที่มีสิทธิ์ลบคำร้อง";
        $_SESSION['flash_type'] = "danger";
    } else {
        $del_id = intval($_POST['request_id'] ?? 0);
        if ($del_id > 0) {
            try {
                $stmt_chk = $pdo->prepare("SELECT request_id, request_type, course_code, student_id FROM course_requests WHERE request_id = ?");
                $stmt_chk->execute([$del_id]);
                $req_del = $stmt_chk->fetch(PDO::FETCH_ASSOC);

                if ($req_del) {
                    $stmt_do_del = $pdo->prepare("DELETE FROM course_requests WHERE request_id = ?");
                    $stmt_do_del->execute([$del_id]);

                    $t_text = ($req_del['request_type'] === 'open_course') ? 'ขอเปิดรายวิชา' : 'สำรองที่นั่ง';
                    $_SESSION['flash_msg'] = "ลบคำร้อง{$t_text} รหัส {$req_del['course_code']} (ID: #{$del_id}) สำเร็จเรียบร้อยแล้ว";
                    $_SESSION['flash_type'] = "success";
                } else {
                    $_SESSION['flash_msg'] = "ไม่พบคำร้องที่ระบุ หรือรายการนี้อาจถูกลบไปแล้ว";
                    $_SESSION['flash_type'] = "warning";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_msg'] = "เกิดข้อผิดพลาดในการลบคำร้อง: " . $e->getMessage();
                $_SESSION['flash_type'] = "danger";
            }
        }
    }

    $query_str = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header("Location: dashboard_executive.php" . $query_str);
    exit();
}

// -----------------------------------------------------------------------------
// 2. ดึงตัวกรอง (Filters)
// -----------------------------------------------------------------------------
$semester_filter    = trim($_GET['semester'] ?? '');
$type_filter        = trim($_GET['type'] ?? 'all');        // 'all', 'open_course', 'reserve_seat'
$status_filter      = trim($_GET['status'] ?? 'all');      // 'all', 'pending', 'pending_instructor', etc., 'approved', 'rejected'
$major_filter       = trim($_GET['major'] ?? 'all');
$search             = trim($_GET['search'] ?? '');
$date_from          = trim($_GET['date_from'] ?? '');
$date_to            = trim($_GET['date_to'] ?? '');

// ดึงรายการภาคการศึกษาที่มีในระบบ
$semesters = $pdo->query("SELECT DISTINCT semester FROM course_requests WHERE semester IS NOT NULL AND semester != '' ORDER BY semester DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// ดึงรายการสาขาวิชาที่มีในระบบ
$majors = $pdo->query("SELECT DISTINCT major FROM students WHERE major IS NOT NULL AND major != '' ORDER BY major ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// -----------------------------------------------------------------------------
// 3. ประกอบเงื่อนไข WHERE สำหรับสรุปผล
// -----------------------------------------------------------------------------
$where = ["1=1"];
$params = [];

if ($semester_filter !== '' && $semester_filter !== 'all') {
    $where[] = "r.semester = ?";
    $params[] = $semester_filter;
}
if ($type_filter !== '' && $type_filter !== 'all') {
    $where[] = "r.request_type = ?";
    $params[] = $type_filter;
}
if ($status_filter !== '' && $status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where[] = "r.status LIKE 'pending%'";
    } else {
        $where[] = "r.status = ?";
        $params[] = $status_filter;
    }
}
if ($major_filter !== '' && $major_filter !== 'all') {
    $where[] = "s.major = ?";
    $params[] = $major_filter;
}
if ($date_from !== '') {
    $where[] = "DATE(r.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(r.created_at) <= ?";
    $params[] = $date_to;
}
if ($search !== '') {
    $where[] = "(r.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR r.course_code LIKE ? OR r.course_name LIKE ? OR inst.first_name LIKE ? OR inst.last_name LIKE ?)";
    $like_search = "%$search%";
    $params = array_merge($params, array_fill(0, 7, $like_search));
}

$where_sql = implode(" AND ", $where);

// -----------------------------------------------------------------------------
// 4. คำนวณ KPI ภาพรวม (Key Performance Indicators)
// -----------------------------------------------------------------------------
$sql_kpi = "
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN r.request_type = 'open_course' THEN 1 ELSE 0 END) AS count_open_course,
        SUM(CASE WHEN r.request_type = 'reserve_seat' THEN 1 ELSE 0 END) AS count_reserve_seat,
        SUM(CASE WHEN r.status LIKE 'pending%' THEN 1 ELSE 0 END) AS count_pending_total,
        SUM(CASE WHEN r.status = 'pending_instructor' THEN 1 ELSE 0 END) AS count_pending_instructor,
        SUM(CASE WHEN r.status = 'pending_advisor' THEN 1 ELSE 0 END) AS count_pending_advisor,
        SUM(CASE WHEN r.status = 'pending_head' THEN 1 ELSE 0 END) AS count_pending_head,
        SUM(CASE WHEN r.status = 'pending_deputy' THEN 1 ELSE 0 END) AS count_pending_deputy,
        SUM(CASE WHEN r.status = 'pending_academic' THEN 1 ELSE 0 END) AS count_pending_academic,
        SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS count_approved,
        SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
        SUM(CASE WHEN r.status LIKE 'pending%' AND r.created_at <= DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS count_stalled
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    WHERE $where_sql
";
$stmt_kpi = $pdo->prepare($sql_kpi);
$stmt_kpi->execute($params);
$kpi = $stmt_kpi->fetch(PDO::FETCH_ASSOC) ?: [];

$total_count        = (int)($kpi['total_count'] ?? 0);
$count_open         = (int)($kpi['count_open_course'] ?? 0);
$count_reserve      = (int)($kpi['count_reserve_seat'] ?? 0);
$count_pending      = (int)($kpi['count_pending_total'] ?? 0);
$count_approved     = (int)($kpi['count_approved'] ?? 0);
$count_rejected     = (int)($kpi['count_rejected'] ?? 0);
$count_stalled      = (int)($kpi['count_stalled'] ?? 0);

$approval_rate = ($total_count > 0) ? round(($count_approved / $total_count) * 100, 1) : 0;
$rejection_rate = ($total_count > 0) ? round(($count_rejected / $total_count) * 100, 1) : 0;

// -----------------------------------------------------------------------------
// 5. ดึงข้อมูลสำหรับกราฟวิเคราะห์ (Charts)
// -----------------------------------------------------------------------------
// 5.1 สถิติแยกตามสถานะ (Funnel / Doughnut)
$status_steps = [
    'pending_instructor' => ['label' => 'รออาจารย์ผู้สอน', 'color' => '#ffc107', 'count' => (int)($kpi['count_pending_instructor'] ?? 0)],
    'pending_advisor'    => ['label' => 'รออาจารย์ที่ปรึกษา', 'color' => '#0dcaf0', 'count' => (int)($kpi['count_pending_advisor'] ?? 0)],
    'pending_head'       => ['label' => 'รอหัวหน้าภาควิชา', 'color' => '#0d6efd', 'count' => (int)($kpi['count_pending_head'] ?? 0)],
    'pending_deputy'     => ['label' => 'รอรองคณบดีฝ่ายวิชาการ', 'color' => '#6f42c1', 'count' => (int)($kpi['count_pending_deputy'] ?? 0)],
    'pending_academic'   => ['label' => 'รอยืนยันงานบริการการศึกษา', 'color' => '#20c997', 'count' => (int)($kpi['count_pending_academic'] ?? 0)],
    'approved'           => ['label' => 'อนุมัติเรียบร้อย', 'color' => '#198754', 'count' => $count_approved],
    'rejected'           => ['label' => 'ไม่อนุมัติ / ปฏิเสธ', 'color' => '#dc3545', 'count' => $count_rejected]
];

// 5.2 อันดับรายวิชาที่ถูกยื่นคำร้องมากที่สุด (Top 5 Courses)
$sql_top_courses = "
    SELECT r.course_code, r.course_name, 
           COUNT(*) as total_req,
           SUM(CASE WHEN r.request_type = 'open_course' THEN 1 ELSE 0 END) as open_cnt,
           SUM(CASE WHEN r.request_type = 'reserve_seat' THEN 1 ELSE 0 END) as reserve_cnt
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    WHERE $where_sql
    GROUP BY r.course_code, r.course_name
    ORDER BY total_req DESC
    LIMIT 5
";
$stmt_top = $pdo->prepare($sql_top_courses);
$stmt_top->execute($params);
$top_courses = $stmt_top->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 5.3 จำนวนคำร้องแยกตามสาขาวิชา (Major Distribution)
$sql_majors = "
    SELECT COALESCE(s.major, 'ไม่ระบุสาขา') as major_name, COUNT(*) as cnt
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    WHERE $where_sql
    GROUP BY major_name
    ORDER BY cnt DESC
";
$stmt_majors = $pdo->prepare($sql_majors);
$stmt_majors->execute($params);
$major_stats = $stmt_majors->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -----------------------------------------------------------------------------
// 6. รายการที่ค้างพิจารณานานที่สุด (Urgent Follow-Up / Bottlenecks)
// -----------------------------------------------------------------------------
$sql_stalled_items = "
    SELECT r.*, 
           DATEDIFF(NOW(), r.created_at) AS days_pending,
           s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
           s.major AS std_major, s.year_level AS std_year,
           inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname,
           adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_lname
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    LEFT JOIN users adv ON r.advisor_id = adv.user_id
    WHERE r.status LIKE 'pending%' AND $where_sql
    ORDER BY r.created_at ASC
    LIMIT 5
";
$stmt_stalled = $pdo->prepare($sql_stalled_items);
$stmt_stalled->execute($params);
$stalled_items = $stmt_stalled->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -----------------------------------------------------------------------------
// 7. ดึงรายการคำร้องทั้งหมดตามตัวกรอง (Detailed Tracking Table)
// -----------------------------------------------------------------------------
$sql_list = "
    SELECT r.*,
           DATEDIFF(NOW(), r.created_at) AS days_pending,
           s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
           s.major AS std_major, s.year_level AS std_year, s.phone_number AS std_phone,
           inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname,
           adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_lname
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    LEFT JOIN users adv ON r.advisor_id = adv.user_id
    WHERE $where_sql
    ORDER BY 
        CASE WHEN r.status LIKE 'pending%' THEN 1 ELSE 2 END ASC,
        r.created_at DESC
    LIMIT 1000
";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params);
$requests = $stmt_list->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Helper Function: ป้ายสถานะ & คำอธิบาย
function getCourseRequestStatusInfo($status) {
    $map = [
        'pending_instructor' => ['label' => 'รออาจารย์ผู้สอน', 'badge' => 'bg-warning-subtle text-warning border border-warning-subtle', 'step' => 1, 'icon' => 'fa-chalkboard-user'],
        'pending_advisor'    => ['label' => 'รออาจารย์ที่ปรึกษา', 'badge' => 'bg-info-subtle text-info border border-info-subtle', 'step' => 2, 'icon' => 'fa-user-tie'],
        'pending_head'       => ['label' => 'รอหัวหน้าภาควิชา', 'badge' => 'bg-primary-subtle text-primary border border-primary-subtle', 'step' => 3, 'icon' => 'fa-user-gear'],
        'pending_deputy'     => ['label' => 'รอรองคณบดีฝ่ายวิชาการ', 'badge' => 'bg-purple-subtle text-purple border border-purple-subtle', 'step' => 4, 'icon' => 'fa-stamp'],
        'pending_academic'   => ['label' => 'รอยืนยันงานบริการการศึกษา', 'badge' => 'bg-teal-subtle text-teal border border-teal-subtle', 'step' => 5, 'icon' => 'fa-file-signature'],
        'approved'           => ['label' => 'อนุมัติเรียบร้อย', 'badge' => 'bg-success-subtle text-success border border-success-subtle', 'step' => 6, 'icon' => 'fa-circle-check'],
        'rejected'           => ['label' => 'ไม่อนุมัติ / ปฏิเสธ', 'badge' => 'bg-danger-subtle text-danger border border-danger-subtle', 'step' => 7, 'icon' => 'fa-circle-xmark']
    ];
    return $map[$status] ?? ['label' => $status, 'badge' => 'bg-secondary text-white', 'step' => 0, 'icon' => 'fa-question'];
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<!-- DataTables Bootstrap 5 CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<style>
    .bg-purple { background-color: #5a2e8f !important; }
    .bg-purple-subtle { background-color: #f3e8ff !important; }
    .text-purple { color: #5a2e8f !important; }
    .border-purple-subtle { border-color: #d8b4fe !important; }
    .bg-teal-subtle { background-color: #e6fffa !important; }
    .text-teal { color: #0d9488 !important; }
    .border-teal-subtle { border-color: #99f6e4 !important; }
    .shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .hover-card { transition: all 0.25s ease-in-out; }
    .hover-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; }
    .extra-small { font-size: 0.75rem; }
    .step-dot {
        width: 18px; height: 18px; border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center; font-size: 0.65rem; font-weight: bold;
    }
    .step-line {
        height: 2px; flex-grow: 1; background: #e2e8f0; margin: 0 2px;
    }
    .step-active { background: #5a2e8f; color: #fff; }
    .step-done { background: #198754; color: #fff; }
    .step-pending { background: #cbd5e1; color: #475569; }
    .step-rejected { background: #dc3545; color: #fff; }

    /* DataTables Custom Polish */
    .dataTables_wrapper .dataTables_length select {
        border-radius: 50rem;
        padding: 0.25rem 2rem 0.25rem 0.85rem;
        font-size: 0.85rem;
        border: 1px solid #dee2e6;
    }
    .dataTables_wrapper .dataTables_filter input {
        border-radius: 50rem;
        padding: 0.3rem 1rem;
        font-size: 0.85rem;
        border: 1px solid #dee2e6;
        outline: none;
    }
    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #5a2e8f;
        box-shadow: 0 0 0 0.2rem rgba(90, 46, 143, 0.15);
    }
    .dataTables_wrapper .dataTables_info {
        font-size: 0.82rem;
        color: #64748b;
        padding-top: 1rem;
    }
    .dataTables_wrapper .dataTables_paginate {
        padding-top: 0.75rem;
    }
    .dataTables_wrapper .dataTables_paginate .pagination .page-item .page-link {
        border-radius: 50rem;
        margin: 0 2px;
        font-size: 0.82rem;
        padding: 0.35rem 0.75rem;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    .dataTables_wrapper .dataTables_paginate .pagination .page-item.active .page-link {
        background-color: #5a2e8f;
        border-color: #5a2e8f;
        color: #ffffff;
        font-weight: bold;
    }

    @media print {
        .d-print-none { display: none !important; }
        main { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .card { border: 1px solid #ccc !important; box-shadow: none !important; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">

    <!-- Flash Message Notification -->
    <?php if (isset($_SESSION['flash_msg'])): ?>
        <div class="alert alert-<?= ($_SESSION['flash_type'] ?? 'info') === 'success' ? 'success' : (($_SESSION['flash_type'] ?? '') === 'danger' ? 'danger' : 'warning'); ?> alert-dismissible fade show rounded-4 border-0 shadow-xs mt-3 mb-0" role="alert">
            <i class="fa-solid <?= ($_SESSION['flash_type'] ?? '') === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger'; ?> me-2"></i>
            <strong><?= htmlspecialchars($_SESSION['flash_msg']); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Breadcrumb & Title Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom d-print-none">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1 fw-bold extra-small">
                    <i class="fa-solid fa-chess-king me-1"></i> ระบบสารสนเทศเพื่อการบริหาร (ERP ผู้บริหาร)
                </span>
                <span class="badge bg-light text-muted border rounded-pill px-3 py-1 extra-small">
                    งานวิชาการและพัฒนานิสิต
                </span>
            </div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-purple me-2"></i>แดชบอร์ดติดตามคำร้องขอเปิดรายวิชา & สำรองที่นั่ง
            </h1>
            <p class="text-muted small mb-0">สรุปผลภาพรวม ตัวชี้วัดสถานะการพิจารณา วิเคราะห์คอขวดขั้นตอน และติดตามคำร้องของนิสิตทุกสาขาวิชา</p>
        </div>
        
        <!-- Action Buttons -->
        <div class="d-flex flex-wrap gap-2 mt-2 mt-md-0">
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน
            </button>
            <button type="button" onclick="exportTableToCSV('academic_course_requests.csv')" class="btn btn-outline-success rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-file-excel me-1"></i> ส่งออก Excel
            </button>
            <div class="dropdown">
                <button class="btn btn-purple rounded-pill px-3 py-2 fw-bold shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-list-check me-1"></i> จัดการคำร้อง
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                    <li><a class="dropdown-item py-2" href="../open_course/index.php"><i class="fa-solid fa-book-open-reader text-primary me-2"></i> ระบบขอเปิดรายวิชา</a></li>
                    <li><a class="dropdown-item py-2" href="../reserve_seat/index.php"><i class="fa-solid fa-chair text-warning me-2"></i> ระบบขอสำรองที่นั่ง</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2" href="../../executive_dashboard.php"><i class="fa-solid fa-chess-king text-danger me-2"></i> Executive Dashboard รวม</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4 d-print-none">
        <div class="card-body p-3">
            <form method="GET" action="" id="filterForm">
                <div class="row g-2 align-items-center">
                    
                    <!-- ภาคการศึกษา -->
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label extra-small fw-bold text-muted mb-1">ภาคการศึกษา</label>
                        <select name="semester" class="form-select form-select-sm rounded-pill fw-semibold" onchange="this.form.submit()">
                            <option value="all">ทุกภาคเรียน</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= htmlspecialchars($sem); ?>" <?= $semester_filter === $sem ? 'selected' : ''; ?>>
                                    ภาคเรียน <?= htmlspecialchars($sem); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- ประเภทคำร้อง -->
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label extra-small fw-bold text-muted mb-1">ประเภทคำร้อง</label>
                        <select name="type" class="form-select form-select-sm rounded-pill fw-semibold" onchange="this.form.submit()">
                            <option value="all" <?= $type_filter === 'all' ? 'selected' : ''; ?>>ทั้งหมด (เปิดวิชา + สำรองที่)</option>
                            <option value="open_course" <?= $type_filter === 'open_course' ? 'selected' : ''; ?>>ขอเปิดรายวิชา</option>
                            <option value="reserve_seat" <?= $type_filter === 'reserve_seat' ? 'selected' : ''; ?>>ขอสำรองที่นั่ง</option>
                        </select>
                    </div>

                    <!-- สถานะ -->
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label extra-small fw-bold text-muted mb-1">สถานะคำร้อง</label>
                        <select name="status" class="form-select form-select-sm rounded-pill fw-semibold" onchange="this.form.submit()">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : ''; ?>>ทุกสถานะ</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : ''; ?>>อยู่ระหว่างพิจารณา (ทุกขั้นตอน)</option>
                            <option value="pending_instructor" <?= $status_filter === 'pending_instructor' ? 'selected' : ''; ?>>รออาจารย์ผู้สอน</option>
                            <option value="pending_advisor" <?= $status_filter === 'pending_advisor' ? 'selected' : ''; ?>>รออาจารย์ที่ปรึกษา</option>
                            <option value="pending_head" <?= $status_filter === 'pending_head' ? 'selected' : ''; ?>>รอหัวหน้าภาควิชา</option>
                            <option value="pending_deputy" <?= $status_filter === 'pending_deputy' ? 'selected' : ''; ?>>รอรองคณบดีฝ่ายวิชาการ</option>
                            <option value="pending_academic" <?= $status_filter === 'pending_academic' ? 'selected' : ''; ?>>รอยืนยันงานบริการการศึกษา</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : ''; ?>>อนุมัติเรียบร้อย</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : ''; ?>>ไม่อนุมัติ / ปฏิเสธ</option>
                        </select>
                    </div>

                    <!-- สาขาวิชา -->
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label extra-small fw-bold text-muted mb-1">สาขาวิชา</label>
                        <select name="major" class="form-select form-select-sm rounded-pill fw-semibold" onchange="this.form.submit()">
                            <option value="all">ทุกสาขาวิชา</option>
                            <?php foreach ($majors as $m): ?>
                                <option value="<?= htmlspecialchars($m); ?>" <?= $major_filter === $m ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($m); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- ค้นหาคำร้อง -->
                    <div class="col-12 col-lg-4">
                        <label class="form-label extra-small fw-bold text-muted mb-1">ค้นหา (รหัสนิสิต, ชื่อวิชา, รหัสวิชา, ผู้สอน)</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control rounded-start-pill ps-3" placeholder="พิมพ์คำค้นหา...">
                            <button type="submit" class="btn btn-purple rounded-end-pill px-3">
                                <i class="fa-solid fa-search"></i>
                            </button>
                            <?php if ($semester_filter || $type_filter !== 'all' || $status_filter !== 'all' || $major_filter !== 'all' || $search || $date_from || $date_to): ?>
                                <a href="dashboard_executive.php" class="btn btn-outline-secondary rounded-pill ms-2 px-2" title="ล้างค่าตัวกรอง">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- Executive KPI Bento Grid -->
    <div class="row g-3 mb-4">
        
        <!-- 1. คำร้องทั้งหมด -->
        <div class="col-6 col-xl-2 col-md-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted extra-small fw-bold text-uppercase">คำร้องทั้งหมด</span>
                    <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-layer-group fs-6"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-1"><?= number_format($total_count); ?></div>
                <div class="extra-small text-muted d-flex gap-1 flex-wrap">
                    <span class="badge bg-primary-subtle text-primary border-0 rounded-pill">เปิดวิชา: <?= number_format($count_open); ?></span>
                    <span class="badge bg-warning-subtle text-warning border-0 rounded-pill">สำรองที่: <?= number_format($count_reserve); ?></span>
                </div>
            </div>
        </div>

        <!-- 2. อยู่ระหว่างดำเนินการ (Pending) -->
        <div class="col-6 col-xl-3 col-md-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card border-start border-warning border-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted extra-small fw-bold text-uppercase">อยู่ระหว่างดำเนินการ</span>
                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-hourglass-half fs-6"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-warning mb-1"><?= number_format($count_pending); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></div>
                <div class="extra-small text-muted">
                    ผู้สอน (<?= $status_steps['pending_instructor']['count']; ?>) · ที่ปรึกษา (<?= $status_steps['pending_advisor']['count']; ?>) · หน.ภาค (<?= $status_steps['pending_head']['count']; ?>) · รองวิชาการ (<?= $status_steps['pending_deputy']['count']; ?>)
                </div>
            </div>
        </div>

        <!-- 3. อนุมัติสำเร็จ -->
        <div class="col-6 col-xl-2 col-md-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card border-start border-success border-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted extra-small fw-bold text-uppercase">อนุมัติเรียบร้อย</span>
                    <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-circle-check fs-6"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-success mb-1"><?= number_format($count_approved); ?> <small class="fs-6 text-muted fw-normal">(<?= $approval_rate; ?>%)</small></div>
                <div class="extra-small text-muted">ดำเนินการสำเร็จครบทุกขั้น</div>
            </div>
        </div>

        <!-- 4. ไม่อนุมัติ / ปฏิเสธ -->
        <div class="col-6 col-xl-2 col-md-6">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card border-start border-danger border-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted extra-small fw-bold text-uppercase">ไม่อนุมัติ / ปฏิเสธ</span>
                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-circle-xmark fs-6"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-danger mb-1"><?= number_format($count_rejected); ?> <small class="fs-6 text-muted fw-normal">(<?= $rejection_rate; ?>%)</small></div>
                <div class="extra-small text-muted">ไม่ผ่านการพิจารณา</div>
            </div>
        </div>

        <!-- 5. ค้างพิจารณาเกิน 3 วัน (Bottleneck Alert) -->
        <div class="col-12 col-xl-3 col-md-6">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card <?= $count_stalled > 0 ? 'bg-danger-subtle border border-danger' : ''; ?>">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="<?= $count_stalled > 0 ? 'text-danger fw-bold' : 'text-muted'; ?> extra-small text-uppercase">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>ค้างพิจารณา > 3 วัน
                    </span>
                    <div class="rounded-circle <?= $count_stalled > 0 ? 'bg-danger text-white' : 'bg-light text-muted'; ?> d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-clock-rotate-left fs-6"></i>
                    </div>
                </div>
                <div class="h3 fw-bold <?= $count_stalled > 0 ? 'text-danger' : 'text-dark'; ?> mb-1">
                    <?= number_format($count_stalled); ?> <small class="fs-6 fw-normal">รายการ</small>
                </div>
                <div class="extra-small <?= $count_stalled > 0 ? 'text-danger' : 'text-muted'; ?>">
                    <?= $count_stalled > 0 ? '⚠️ ต้องเร่งรัดติดตามผู้อนุมัติในคิว' : 'ไม่มีรายการล่าช้าในระบบ'; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Bottleneck / Urgent Follow-up Box (แสดงเมื่อมีรายการค้างนาน) -->
    <?php if (!empty($stalled_items)): ?>
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4 border-start border-danger border-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-danger mb-1">
                        <i class="fa-solid fa-fire text-danger me-2"></i>รายการคำร้องที่รอดำเนินการนานที่สุด (Urgent Bottlenecks)
                    </h5>
                    <p class="text-muted extra-small mb-0">รายการที่ยังไม่ได้รับการพิจารณาเกินระยะเวลาที่เหมาะสม เพื่อให้ผู้บริหารหรือเจ้าหน้าที่เร่งประสานงาน</p>
                </div>
                <span class="badge bg-danger text-white rounded-pill px-3 py-2 fw-semibold">
                    พบ <?= count($stalled_items); ?> รายการเร่งด่วน
                </span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light extra-small text-uppercase text-muted">
                        <tr>
                            <th>ประเภท</th>
                            <th>รหัสคำร้อง</th>
                            <th>นิสิตผู้ยื่น</th>
                            <th>รายวิชา</th>
                            <th>วันที่ยื่น / ค้างนาน</th>
                            <th>ขั้นตอนปัจจุบัน / ผู้มีอำนาจพิจารณา</th>
                            <th class="text-end">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php foreach ($stalled_items as $item): 
                            $status_info = getCourseRequestStatusInfo($item['status']);
                            $view_url = ($item['request_type'] === 'open_course') ? "../open_course/view.php?id={$item['request_id']}" : "../reserve_seat/view.php?id={$item['request_id']}";
                        ?>
                        <tr>
                            <td>
                                <?php if ($item['request_type'] === 'open_course'): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
                                        <i class="fa-solid fa-book-open me-1"></i>ขอเปิดวิชา
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">
                                        <i class="fa-solid fa-chair me-1"></i>ขอสำรองที่นั่ง
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold text-dark">#<?= $item['request_id']; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars(($item['std_prefix'] ?? '') . $item['std_fname'] . ' ' . $item['std_lname']); ?></div>
                                <div class="extra-small text-muted"><?= htmlspecialchars($item['student_id']); ?> · <?= htmlspecialchars($item['std_major'] ?? '-'); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($item['course_code']); ?> <?= htmlspecialchars($item['course_name']); ?></div>
                                <div class="extra-small text-muted">กลุ่มเรียน/Section: <?= htmlspecialchars($item['section'] ?: 'ไม่ระบุ'); ?></div>
                            </td>
                            <td>
                                <div><?= date('d/m/Y', strtotime($item['created_at'])); ?></div>
                                <span class="badge bg-danger text-white rounded-pill extra-small">
                                    <i class="fa-regular fa-clock me-1"></i>ค้าง <?= (int)$item['days_pending']; ?> วัน
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $status_info['badge']; ?> rounded-pill mb-1">
                                    <i class="fa-solid <?= $status_info['icon']; ?> me-1"></i><?= $status_info['label']; ?>
                                </span>
                                <div class="extra-small text-muted">
                                    <?php 
                                        if ($item['status'] === 'pending_instructor') echo "อ.ผู้สอน: " . htmlspecialchars($item['inst_fname'] . ' ' . $item['inst_lname']);
                                        elseif ($item['status'] === 'pending_advisor') echo "อ.ที่ปรึกษา: " . htmlspecialchars($item['adv_fname'] . ' ' . $item['adv_lname']);
                                        elseif ($item['status'] === 'pending_head') echo "หัวหน้าภาควิชา";
                                        elseif ($item['status'] === 'pending_deputy') echo "รองคณบดีฝ่ายวิชาการ";
                                        elseif ($item['status'] === 'pending_academic') echo "งานบริการการศึกษา";
                                    ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <a href="<?= $view_url; ?>" class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold">
                                    ตรวจสอบ <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Charts Section -->
    <div class="row g-4 mb-4">
        
        <!-- Chart 1: สัดส่วนสถานะการพิจารณา (Approval Stages Breakdown) -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-chart-pie text-purple me-2"></i>สัดส่วนสถานะคำร้อง (Funnel)
                    </h6>
                </div>
                <div style="position: relative; height: 260px;">
                    <canvas id="statusChart"></canvas>
                </div>
                <div class="mt-3 extra-small text-muted text-center">
                    วิเคราะห์คอขวดขั้นตอนการอนุมัติในระบบ
                </div>
            </div>
        </div>

        <!-- Chart 2: 5 อันดับวิชายอดฮิตที่มีการยื่นคำร้องมากที่สุด -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-chart-bar text-primary me-2"></i>5 อันดับวิชายอดนิยมที่ยื่นคำร้อง
                    </h6>
                </div>
                <div style="position: relative; height: 260px;">
                    <canvas id="topCoursesChart"></canvas>
                </div>
                <div class="mt-3 extra-small text-muted text-center">
                    แสดงวิชาที่มีความต้องการเปิดหมู่เรียนหรือสำรองที่นั่งสูงสุด
                </div>
            </div>
        </div>

        <!-- Chart 3: สัดส่วนตามสาขาวิชา -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-graduation-cap text-success me-2"></i>คำร้องแยกตามสาขาวิชา
                    </h6>
                </div>
                <div style="position: relative; height: 260px;">
                    <canvas id="majorChart"></canvas>
                </div>
                <div class="mt-3 extra-small text-muted text-center">
                    สัดส่วนนิสิตผู้ยื่นคำร้องตามหลักสูตร/สาขาวิชา
                </div>
            </div>
        </div>

    </div>

    <!-- Detailed Tracking Data Table -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-5">
        <div class="card-body p-4">
            
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="fa-solid fa-table-list text-purple me-2"></i>ตารางติดตามสถานะคำร้องแบบละเอียด (Tracking List)
                    </h5>
                    <p class="text-muted extra-small mb-0">
                        แสดงข้อมูลคำร้องทั้งหมดตามเงื่อนไขตัวกรอง (พบทั้งหมด <?= number_format(count($requests)); ?> รายการ)
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-2 extra-small">
                        <i class="fa-solid fa-filter me-1 text-purple"></i> กรอง: 
                        <?= $semester_filter ? "ภาคเรียน $semester_filter" : "ทุกภาคเรียน"; ?> · 
                        <?= $type_filter === 'open_course' ? 'ขอเปิดวิชา' : ($type_filter === 'reserve_seat' ? 'ขอสำรองที่นั่ง' : 'ทุกประเภท'); ?>
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="requestsDataTable">
                    <thead class="table-light extra-small text-uppercase text-muted">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>วันที่ยื่นคำร้อง</th>
                            <th>ภาคเรียน</th>
                            <th>ประเภทคำร้อง</th>
                            <th>นิสิตผู้ยื่น</th>
                            <th>รายวิชา & กลุ่มเรียน</th>
                            <th>อาจารย์ผู้สอน / ที่ปรึกษา</th>
                            <th>สถานะ / ขั้นตอน</th>
                            <th class="text-end">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fa-regular fa-folder-open fa-3x mb-3 text-secondary opacity-25"></i>
                                    <h6>ไม่พบข้อมูลคำร้องตามเงื่อนไขที่เลือก</h6>
                                    <p class="extra-small mb-0">ลองปรับเปลี่ยนตัวกรอง หรือล้างการค้นหา</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $idx => $r): 
                                $status_info = getCourseRequestStatusInfo($r['status']);
                                $view_url = ($r['request_type'] === 'open_course') ? "../open_course/view.php?id={$r['request_id']}" : "../reserve_seat/view.php?id={$r['request_id']}";
                                $is_pending = strpos($r['status'], 'pending') === 0;
                            ?>
                            <tr>
                                <td class="text-muted extra-small"><?= $idx + 1; ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= date('d/m/Y', strtotime($r['created_at'])); ?></div>
                                    <div class="extra-small text-muted"><?= date('H:i น.', strtotime($r['created_at'])); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border rounded-pill extra-small fw-normal">
                                        <?= htmlspecialchars($r['semester']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['request_type'] === 'open_course'): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
                                            <i class="fa-solid fa-book-open me-1"></i>ขอเปิดรายวิชา
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">
                                            <i class="fa-solid fa-chair me-1"></i>ขอสำรองที่นั่ง
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars(($r['std_prefix'] ?? '') . $r['std_fname'] . ' ' . $r['std_lname']); ?></div>
                                    <div class="extra-small text-muted">
                                        <?= htmlspecialchars($r['student_id']); ?> · <?= htmlspecialchars($r['std_major'] ?? '-'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($r['course_code']); ?></div>
                                    <div class="text-truncate text-muted extra-small" style="max-width: 220px;" title="<?= htmlspecialchars($r['course_name']); ?>">
                                        <?= htmlspecialchars($r['course_name']); ?>
                                    </div>
                                    <?php if ($r['section']): ?>
                                        <span class="badge bg-light text-secondary border extra-small mt-1">กลุ่ม <?= htmlspecialchars($r['section']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="extra-small text-dark">
                                        <i class="fa-solid fa-chalkboard-user text-muted me-1"></i>ผู้สอน: <?= htmlspecialchars(($r['inst_fname'] ?? '') ? $r['inst_fname'] . ' ' . $r['inst_lname'] : '-'); ?>
                                    </div>
                                    <div class="extra-small text-muted">
                                        <i class="fa-solid fa-user-tie text-muted me-1"></i>ที่ปรึกษา: <?= htmlspecialchars(($r['adv_fname'] ?? '') ? $r['adv_fname'] . ' ' . $r['adv_lname'] : '-'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= $status_info['badge']; ?> rounded-pill mb-1">
                                        <i class="fa-solid <?= $status_info['icon']; ?> me-1"></i><?= $status_info['label']; ?>
                                    </span>
                                    
                                    <!-- Mini Stepper Indicator -->
                                    <div class="d-flex align-items-center mt-1" style="width: 140px;" title="ขั้นตอน: <?= $status_info['step']; ?>/5">
                                        <?php for ($s = 1; $s <= 5; $s++): 
                                            $dot_class = 'step-pending';
                                            if ($r['status'] === 'rejected') {
                                                $dot_class = 'step-rejected';
                                            } elseif ($r['status'] === 'approved' || $status_info['step'] > $s) {
                                                $dot_class = 'step-done';
                                            } elseif ($status_info['step'] == $s) {
                                                $dot_class = 'step-active';
                                            }
                                        ?>
                                            <div class="step-dot <?= $dot_class; ?>"><?= $s; ?></div>
                                            <?php if ($s < 5): ?><div class="step-line"></div><?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end align-items-center">
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-muted" 
                                                title="ดูข้อความเหตุผลและความเห็น"
                                                onclick='openDetailModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'>
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <a href="<?= $view_url; ?>" class="btn btn-sm btn-purple rounded-pill px-3 py-1 fw-bold">
                                            เปิดดู <i class="fa-solid fa-arrow-right ms-1"></i>
                                        </a>
                                        <?php if ($can_delete_request): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 ms-1" 
                                                    title="ลบคำร้อง (เฉพาะงานวิชาการ)"
                                                    onclick="confirmDeleteRequest(<?= (int)$r['request_id']; ?>, '<?= htmlspecialchars(addslashes($r['course_code']), ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes(($r['std_prefix'] ?? '') . $r['std_fname'] . ' ' . $r['std_lname']), ENT_QUOTES); ?>')">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        <?php endif; ?>
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

    <!-- Hidden Form สำหรับส่งคำขอลบคำร้อง (งานวิชาการ) -->
    <form id="deleteRequestForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="delete_request">
        <input type="hidden" name="request_id" id="deleteRequestIdInput" value="">
    </form>

</main>

<!-- Quick View Detail Modal -->
<div class="modal fade" id="requestDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <span class="badge bg-purple-subtle text-purple rounded-pill px-3 py-1 fw-bold extra-small mb-1" id="modalReqType">
                        คำร้อง
                    </span>
                    <h5 class="modal-title fw-bold text-dark" id="modalReqTitle">รายละเอียดคำร้อง #</h5>
                </div>
                <button type="button" class="btn-close rounded-circle bg-light p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="bg-light p-3 rounded-4 mb-3">
                    <div class="row g-2 extra-small">
                        <div class="col-md-6">
                            <span class="text-muted">นิสิต:</span> <strong id="modalStdName" class="text-dark"></strong> (<span id="modalStdId"></span>)
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted">สาขาวิชา/ชั้นปี:</span> <strong id="modalStdMajor" class="text-dark"></strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted">รายวิชา:</span> <strong id="modalCourse" class="text-dark"></strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted">ภาคเรียน/กลุ่มเรียน:</span> <strong id="modalSemester" class="text-dark"></strong>
                        </div>
                    </div>
                </div>

                <!-- เหตุผลความจำเป็นของนิสิต -->
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted text-uppercase mb-1">
                        <i class="fa-solid fa-comment-dots text-primary me-1"></i>เหตุผลความจำเป็นของนิสิต
                    </label>
                    <div class="p-3 bg-white border rounded-3 small text-dark" id="modalReason" style="white-space: pre-wrap;"></div>
                </div>

                <!-- ไทม์ไลน์ความเห็นผู้พิจารณา -->
                <label class="form-label fw-bold small text-muted text-uppercase mb-2">
                    <i class="fa-solid fa-list-check text-success me-1"></i>บันทึกความเห็นของผู้พิจารณาตามลำดับขั้น
                </label>
                <div class="list-group list-group-flush rounded-3 border">
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold small text-dark"><i class="fa-solid fa-chalkboard-user text-warning me-1"></i> 1. อาจารย์ผู้สอน</span>
                            <span class="extra-small text-muted" id="modalInstName"></span>
                        </div>
                        <div class="extra-small text-muted" id="modalInstComment">ยังไม่มีความเห็น</div>
                    </div>
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold small text-dark"><i class="fa-solid fa-user-tie text-info me-1"></i> 2. อาจารย์ที่ปรึกษา</span>
                            <span class="extra-small text-muted" id="modalAdvName"></span>
                        </div>
                        <div class="extra-small text-muted" id="modalAdvComment">ยังไม่มีความเห็น</div>
                    </div>
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold small text-dark"><i class="fa-solid fa-user-gear text-primary me-1"></i> 3. หัวหน้าภาควิชา / ประธานหลักสูตร</span>
                        </div>
                        <div class="extra-small text-muted" id="modalHeadComment">ยังไม่มีความเห็น</div>
                    </div>
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold small text-dark"><i class="fa-solid fa-stamp text-purple me-1"></i> 4. รองคณบดีฝ่ายวิชาการ</span>
                        </div>
                        <div class="extra-small text-muted" id="modalDeputyComment">ยังไม่มีความเห็น</div>
                    </div>
                    <div class="list-group-item p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold small text-dark"><i class="fa-solid fa-file-signature text-teal me-1"></i> 5. งานบริการการศึกษา (วิชาการ)</span>
                        </div>
                        <div class="extra-small text-muted" id="modalAcademicComment">ยังไม่มีความเห็น</div>
                    </div>
                </div>

            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <?php if ($can_delete_request): ?>
                    <button type="button" class="btn btn-outline-danger rounded-pill px-3 py-2 fw-semibold me-auto" id="modalDeleteBtn" onclick="deleteFromModal()">
                        <i class="fa-solid fa-trash-can me-1"></i> ลบคำร้องนี้
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                <a href="#" id="modalDirectLink" class="btn btn-purple rounded-pill px-4 fw-bold">
                    เปิดหน้าพิจารณาเต็ม <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // -------------------------------------------------------------
    // Chart 1: สัดส่วนสถานะการพิจารณา (Status Doughnut Chart)
    // -------------------------------------------------------------
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusLabels = <?= json_encode(array_column($status_steps, 'label'), JSON_UNESCAPED_UNICODE); ?>;
    const statusCounts = <?= json_encode(array_column($status_steps, 'count')); ?>;
    const statusColors = <?= json_encode(array_column($status_steps, 'color')); ?>;

    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusCounts,
                backgroundColor: statusColors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 10, family: 'Prompt' } }
                }
            },
            cutout: '65%'
        }
    });

    // -------------------------------------------------------------
    // Chart 2: 5 อันดับวิชายอดฮิต (Top Requested Courses Bar Chart)
    // -------------------------------------------------------------
    const topCtx = document.getElementById('topCoursesChart').getContext('2d');
    const topLabels = <?= json_encode(array_map(function($c) { return $c['course_code']; }, $top_courses), JSON_UNESCAPED_UNICODE); ?>;
    const topOpenData = <?= json_encode(array_map(function($c) { return (int)$c['open_cnt']; }, $top_courses)); ?>;
    const topReserveData = <?= json_encode(array_map(function($c) { return (int)$c['reserve_cnt']; }, $top_courses)); ?>;

    new Chart(topCtx, {
        type: 'bar',
        data: {
            labels: topLabels.length > 0 ? topLabels : ['ยังไม่มีข้อมูล'],
            datasets: [
                {
                    label: 'ขอเปิดวิชา',
                    data: topOpenData.length > 0 ? topOpenData : [0],
                    backgroundColor: '#0d6efd',
                    borderRadius: 4
                },
                {
                    label: 'ขอสำรองที่นั่ง',
                    data: topReserveData.length > 0 ? topReserveData : [0],
                    backgroundColor: '#ffc107',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, ticks: { font: { family: 'Prompt', size: 10 } } },
                y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10, family: 'Prompt' } } }
            }
        }
    });

    // -------------------------------------------------------------
    // Chart 3: สัดส่วนคำร้องตามสาขาวิชา (Major Distribution Chart)
    // -------------------------------------------------------------
    const majorCtx = document.getElementById('majorChart').getContext('2d');
    const majorLabels = <?= json_encode(array_column($major_stats, 'major_name'), JSON_UNESCAPED_UNICODE); ?>;
    const majorCounts = <?= json_encode(array_column($major_stats, 'cnt')); ?>;

    new Chart(majorCtx, {
        type: 'polarArea',
        data: {
            labels: majorLabels.length > 0 ? majorLabels : ['ยังไม่มีข้อมูล'],
            datasets: [{
                data: majorCounts.length > 0 ? majorCounts : [0],
                backgroundColor: [
                    'rgba(90, 46, 143, 0.75)',
                    'rgba(13, 202, 240, 0.75)',
                    'rgba(25, 135, 84, 0.75)',
                    'rgba(255, 193, 7, 0.75)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10, family: 'Prompt' } } }
            }
        }
    });
});

// Global variable สำหรับเก็บข้อมูล Modal ปัจจุบัน
window.currentModalRequest = null;

// Modal Detail Preview Handler
function openDetailModal(data) {
    window.currentModalRequest = data;
    const isModal = new bootstrap.Modal(document.getElementById('requestDetailModal'));
    
    document.getElementById('modalReqTitle').textContent = 'รายละเอียดคำร้อง #' + data.request_id;
    document.getElementById('modalReqType').textContent = (data.request_type === 'open_course') ? 'ขอเปิดรายวิชา' : 'ขอสำรองที่นั่ง';
    document.getElementById('modalStdName').textContent = (data.std_prefix || '') + data.std_fname + ' ' + data.std_lname;
    document.getElementById('modalStdId').textContent = data.student_id;
    document.getElementById('modalStdMajor').textContent = (data.std_major || '-') + ' (ชั้นปี ' + (data.std_year || '-') + ')';
    document.getElementById('modalCourse').textContent = data.course_code + ' ' + data.course_name;
    document.getElementById('modalSemester').textContent = 'ภาคเรียน ' + data.semester + (data.section ? ' (กลุ่ม ' + data.section + ')' : '');
    document.getElementById('modalReason').textContent = data.reason || 'ไม่ได้ระบุเหตุผล';

    document.getElementById('modalInstName').textContent = data.inst_fname ? (data.inst_fname + ' ' + data.inst_lname) : '-';
    document.getElementById('modalInstComment').textContent = data.instructor_comment || 'ยังไม่มีความเห็น';

    document.getElementById('modalAdvName').textContent = data.adv_fname ? (data.adv_fname + ' ' + data.adv_lname) : '-';
    document.getElementById('modalAdvComment').textContent = data.advisor_comment || 'ยังไม่มีความเห็น';

    document.getElementById('modalHeadComment').textContent = data.head_comment || 'ยังไม่มีความเห็น';
    document.getElementById('modalDeputyComment').textContent = data.deputy_comment || 'ยังไม่มีความเห็น';
    document.getElementById('modalAcademicComment').textContent = data.academic_comment || 'ยังไม่มีความเห็น';

    const linkUrl = (data.request_type === 'open_course') ? "../open_course/view.php?id=" + data.request_id : "../reserve_seat/view.php?id=" + data.request_id;
    document.getElementById('modalDirectLink').href = linkUrl;

    isModal.show();
}

// ลบคำร้องจาก Modal
function deleteFromModal() {
    if (!window.currentModalRequest) return;
    const data = window.currentModalRequest;
    const stdName = (data.std_prefix || '') + data.std_fname + ' ' + data.std_lname;
    
    // ซ่อน Modal ก่อนแสดงกล่องยืนยัน
    const modalEl = document.getElementById('requestDetailModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) {
        modalInstance.hide();
    }
    
    confirmDeleteRequest(data.request_id, data.course_code, stdName);
}

// ฟังก์ชันยืนยันการลบคำร้อง (สำหรับงานวิชาการ)
function confirmDeleteRequest(requestId, courseCode, studentName) {
    const confirmMsg = 'ยืนยันการลบคำร้อง #' + requestId + '\n' +
                       'วิชา: ' + courseCode + '\n' +
                       'นิสิต: ' + studentName + '\n\n' +
                       '⚠️ คำเตือน: ข้อมูลคำร้องนี้จะถูกลบออกจากระบบอย่างถาวร';

    if (typeof showConfirmModal === 'function') {
        showConfirmModal({
            title: 'ยืนยันการลบคำร้อง #' + requestId,
            message: 'คุณต้องการลบคำร้องรายวิชา <strong>' + courseCode + '</strong> ของ <strong>' + studentName + '</strong> ใช่หรือไม่?<br><span class="text-danger small mt-2 d-block">⚠️ คำเตือน: ข้อมูลคำร้องนี้จะถูกลบออกจากฐานข้อมูลอย่างถาวร</span>',
            icon: 'fa-trash-can',
            btnText: 'ยืนยันการลบ',
            btnClass: 'btn-danger',
            callback: function() {
                document.getElementById('deleteRequestIdInput').value = requestId;
                document.getElementById('deleteRequestForm').submit();
            }
        });
    } else if (confirm(confirmMsg)) {
        document.getElementById('deleteRequestIdInput').value = requestId;
        document.getElementById('deleteRequestForm').submit();
    }
}

// Client-side Export to CSV (Thai UTF-8 with BOM for Excel - รองรับ DataTables)
function exportTableToCSV(filename) {
    let csv = [];
    csv.push('\uFEFF'); // BOM สำหรับ Excel ภาษาไทย
    
    if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable('#requestsDataTable')) {
        const dt = $('#requestsDataTable').DataTable();
        
        // หัวตาราง (ตัดคอลัมน์การจัดการออก)
        let headerRow = [];
        $('#requestsDataTable thead th').each(function(i) {
            if (i < 8) {
                headerRow.push('"' + $(this).text().trim().replace(/"/g, '""') + '"');
            }
        });
        csv.push(headerRow.join(','));

        // แถวข้อมูลทั้งหมดตามเงื่อนไขค้นหา/กรอง (ทุกหน้า)
        dt.rows({ search: 'applied' }).every(function() {
            const rowNode = this.node();
            let rowCols = [];
            $(rowNode).find('td').each(function(i) {
                if (i < 8) {
                    let text = $(this).text().replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
                    rowCols.push('"' + text + '"');
                }
            });
            if (rowCols.length > 0) csv.push(rowCols.join(','));
        });
    } else {
        const table = document.getElementById('requestsDataTable');
        const rows = table.querySelectorAll('tr');
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            for (let j = 0; j < cols.length - 1; j++) {
                let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
                row.push('"' + text + '"');
            }
            if (row.length > 0) csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const downloadLink = document.createElement('a');
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once '../../includes/footer.php'; ?>

<!-- DataTables JS & Pagination Setup -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    const $table = $('#requestsDataTable');
    const hasDataRows = $table.find('tbody tr').length > 0 && !$table.find('tbody tr td[colspan]').length;

    if (hasDataRows) {
        $table.DataTable({
            pageLength: 20, // แสดงจำนวนรายการ 20 แถวต่อ 1 หน้าตามที่ผู้ใช้กำหนด
            lengthMenu: [[10, 20, 50, 100, -1], [10, 20, 50, 100, "ทั้งหมด"]],
            language: {
                emptyTable: "ไม่พบข้อมูลคำร้องตามเงื่อนไขที่เลือก",
                info: "แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ",
                infoEmpty: "แสดง 0 ถึง 0 จาก 0 รายการ",
                infoFiltered: "(กรองจากทั้งหมด _MAX_ รายการ)",
                lengthMenu: "แสดง _MENU_ แถวต่อหน้า",
                loadingRecords: "กำลังโหลด...",
                processing: "กำลังประมวลผล...",
                search: "ค้นหาด่วน:",
                searchPlaceholder: "พิมพ์เพื่อค้นหา...",
                zeroRecords: "ไม่พบรายการที่ตรงกับคำค้นหา",
                paginate: {
                    first: '<i class="fa-solid fa-angles-left"></i>',
                    last: '<i class="fa-solid fa-angles-right"></i>',
                    next: '<i class="fa-solid fa-angle-right"></i>',
                    previous: '<i class="fa-solid fa-angle-left"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [8] } // ปิด Sort คอลัมน์ที่ 8 (การจัดการ)
            ],
            order: [] // ไม่เปลี่ยนลำดับจาก SQL Query
        });
    }
});
</script>
