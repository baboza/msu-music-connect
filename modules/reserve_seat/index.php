<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles);
$is_deputy = in_array('รองคณบดีฝ่ายวิชาการ', $user_roles);
$is_academic = count(array_intersect(['งานวิชาการ', 'งานบริการการศึกษา'], $user_roles)) > 0;
$is_head = false;
foreach ($user_roles as $r) {
    if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
        $is_head = true;
        break;
    }
}

// -------------------------------------------------------------------------
// 1. ตัวกรองข้อมูล (Filters)
// -------------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'my_pending'; // 'my_pending', 'my_all', 'all'
$status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$semester_filter = trim($_GET['semester'] ?? '');

// ดึงรายการภาคการศึกษาที่มีในระบบ
$semesters = $pdo->query("SELECT DISTINCT semester FROM course_requests WHERE request_type = 'reserve_seat' ORDER BY semester DESC")->fetchAll(PDO::FETCH_COLUMN);

// -------------------------------------------------------------------------
// 2. ดึงสถิติตัวเลข (Stats)
// -------------------------------------------------------------------------
$stmt_stat = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status IN ('pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN (
            (status = 'pending_instructor' AND instructor_id = ?)
            OR (status = 'pending_advisor' AND advisor_id = ?)
            OR (status = 'pending_head' AND ? = 1)
            OR (status = 'pending_deputy' AND ? = 1)
            OR (status = 'pending_academic' AND ? = 1)
        ) THEN 1 ELSE 0 END) AS my_action_count
    FROM course_requests
    WHERE request_type = 'reserve_seat'
");
$stmt_stat->execute([
    $user_id,
    $user_id,
    $is_head ? 1 : 0,
    $is_deputy ? 1 : 0,
    $is_academic ? 1 : 0
]);
$stats = $stmt_stat->fetch(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// 3. ประกอบ Query ตามแท็บและเงื่อนไขค้นหา
// -------------------------------------------------------------------------
$where_clauses = ["r.request_type = 'reserve_seat'"];
$params = [];

// เงื่อนไขแท็บ
if ($tab === 'my_pending') {
    if ($is_admin) {
        $where_clauses[] = "r.status LIKE 'pending%'";
    } else {
        $pending_sub = [];
        $pending_sub[] = "(r.status = 'pending_instructor' AND r.instructor_id = ?)";
        $params[] = $user_id;

        $pending_sub[] = "(r.status = 'pending_advisor' AND r.advisor_id = ?)";
        $params[] = $user_id;

        if ($is_head) {
            $is_head_duriyang = in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles) || ($user_id === '6b8fba36220e1');
            $is_head_music_ed = in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles) || ($user_id === '6b8f3458220e1');
            if ($is_head_duriyang && !$is_head_music_ed) {
                $pending_sub[] = "(r.status = 'pending_head' AND s.major LIKE '%ดุริยางค%')";
            } elseif ($is_head_music_ed && !$is_head_duriyang) {
                $pending_sub[] = "(r.status = 'pending_head' AND (s.major NOT LIKE '%ดุริยางค%' OR s.major IS NULL))";
            } else {
                $pending_sub[] = "r.status = 'pending_head'";
            }
        }
        if ($is_deputy) {
            $pending_sub[] = "r.status = 'pending_deputy'";
        }
        if ($is_academic) {
            $pending_sub[] = "r.status = 'pending_academic'";
        }
        $where_clauses[] = "(" . implode(" OR ", $pending_sub) . ")";
    }
} elseif ($tab === 'my_all') {
    $where_clauses[] = "(r.instructor_id = ? OR r.advisor_id = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
} else {
    if (!$is_admin && !$is_deputy && !$is_academic && !$is_head) {
        $where_clauses[] = "(r.instructor_id = ? OR r.advisor_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
    }
}

// กรองสถานะ
if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where_clauses[] = "r.status LIKE 'pending%'";
    } else {
        $where_clauses[] = "r.status = ?";
        $params[] = $status_filter;
    }
}

// กรองภาคเรียน
if (!empty($semester_filter)) {
    $where_clauses[] = "r.semester = ?";
    $params[] = $semester_filter;
}

// คำค้นหา
if (!empty($search)) {
    $where_clauses[] = "(
        r.course_code LIKE ? 
        OR r.course_name LIKE ? 
        OR r.section LIKE ?
        OR s.student_id LIKE ? 
        OR s.first_name LIKE ? 
        OR s.last_name LIKE ?
    )";
    $kw = "%{$search}%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$where_sql = implode(" AND ", $where_clauses);

$stmt_list = $pdo->prepare("
    SELECT r.*,
           s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
           s.major AS std_major, s.year_level AS std_year, s.profile_picture,
           inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname,
           adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_lname
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    LEFT JOIN users adv ON r.advisor_id = adv.user_id
    WHERE {$where_sql}
    ORDER BY 
        CASE 
            WHEN r.status LIKE 'pending%' THEN 1 
            ELSE 2 
        END ASC,
        r.created_at DESC
");
$stmt_list->execute($params);
$requests = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3 py-1 fw-bold extra-small">
                    <i class="fa-solid fa-graduation-cap me-1"></i> งานวิชาการและพัฒนานิสิต
                </span>
                <span class="badge bg-light text-muted border rounded-pill px-3 py-1 extra-small">
                    ระบบคำร้อง e-Service
                </span>
            </div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chair text-warning me-2"></i>การจัดการคำร้องขอสำรองที่นั่ง
            </h1>
            <p class="text-muted small mb-0">ตรวจสอบ พิจารณา อนุมัติ/ไม่อนุมัติ คำร้องขอสำรองที่นั่ง/เพิ่มที่นั่งในกลุ่มเรียน จากนิสิต</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard_executive.php" class="btn btn-purple rounded-pill px-3 py-2 fw-bold shadow-xs">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดผู้บริหาร
            </a>
            <a href="/e-service/request_reserve_seat.php" target="_blank" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs" title="ดูหน้าฟอร์มที่นิสิตใช้ยื่น">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> หน้าแบบฟอร์มนิสิต
            </a>
            <a href="../../index.php" class="btn btn-outline-dark rounded-pill px-3 py-2 fw-bold shadow-xs">
                <i class="fa-solid fa-house me-1"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($_SESSION['msg'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info'); ?> alert-dismissible fade show rounded-4 shadow-xs border-0 mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($_SESSION['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
    <?php endif; ?>

    <!-- Bento Stats Grid -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold">รอฉันพิจารณา</span>
                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-bell fs-5"></i>
                    </div>
                </div>
                <div class="h2 fw-bold text-warning mb-0"><?= number_format($stats['my_action_count'] ?? 0); ?></div>
                <div class="extra-small text-muted mt-1">คำร้องที่อยู่ในคิวท่านขณะนี้</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold">กำลังดำเนินการ</span>
                    <div class="rounded-circle bg-info-subtle text-info d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-spinner fs-5"></i>
                    </div>
                </div>
                <div class="h2 fw-bold text-info mb-0"><?= number_format($stats['pending_count'] ?? 0); ?></div>
                <div class="extra-small text-muted mt-1">อยู่ระหว่างกระบวนการอนุมัติ</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold">อนุมัติแล้ว</span>
                    <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-circle-check fs-5"></i>
                    </div>
                </div>
                <div class="h2 fw-bold text-success mb-0"><?= number_format($stats['approved_count'] ?? 0); ?></div>
                <div class="extra-small text-muted mt-1">สำรองที่นั่งเรียบร้อย</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100 hover-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold">คำร้องทั้งหมด</span>
                    <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-chair fs-5"></i>
                    </div>
                </div>
                <div class="h2 fw-bold text-dark mb-0"><?= number_format($stats['total_count'] ?? 0); ?></div>
                <div class="extra-small text-muted mt-1">ไม่อนุมัติ: <?= number_format($stats['rejected_count'] ?? 0); ?> รายการ</div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs & Search Toolbar -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <!-- Tabs -->
                <ul class="nav nav-pills gap-2" id="reserveReqTab">
                    <li class="nav-item">
                        <a href="?tab=my_pending" class="nav-link rounded-pill px-4 fw-bold <?= $tab === 'my_pending' ? 'active bg-purple text-white' : 'text-secondary bg-light'; ?>">
                            <i class="fa-solid fa-bell me-1"></i> รอฉันพิจารณา 
                            <?php if (!empty($stats['my_action_count']) && $stats['my_action_count'] > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill ms-1"><?= $stats['my_action_count']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="?tab=my_all" class="nav-link rounded-pill px-4 fw-bold <?= $tab === 'my_all' ? 'active bg-purple text-white' : 'text-secondary bg-light'; ?>">
                            <i class="fa-solid fa-user-tie me-1"></i> รายการของฉัน
                        </a>
                    </li>
                    <?php if ($is_admin || $is_deputy || $is_academic || $is_head): ?>
                    <li class="nav-item">
                        <a href="?tab=all" class="nav-link rounded-pill px-4 fw-bold <?= $tab === 'all' ? 'active bg-purple text-white' : 'text-secondary bg-light'; ?>">
                            <i class="fa-solid fa-layer-group me-1"></i> รายการทั้งหมดในระบบ
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Filter Controls -->
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-center ms-auto">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab); ?>">
                    
                    <!-- Semester Filter -->
                    <select name="semester" class="form-select form-select-sm rounded-pill px-3" style="width: auto;" onchange="this.form.submit()">
                        <option value="">ทุกภาคเรียน</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?= htmlspecialchars($sem); ?>" <?= $semester_filter === $sem ? 'selected' : ''; ?>>
                                ภาคเรียน <?= htmlspecialchars($sem); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Status Filter -->
                    <select name="status" class="form-select form-select-sm rounded-pill px-3" style="width: auto;" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : ''; ?>>ทุกสถานะ</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : ''; ?>>อยู่ระหว่างรอพิจารณา</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                    </select>

                    <!-- Search Input -->
                    <div class="input-group input-group-sm" style="max-width: 250px;">
                        <input type="text" name="search" class="form-control rounded-start-pill ps-3" placeholder="ค้นหารหัส/ชื่อวิชา/นิสิต/กลุ่ม..." value="<?= htmlspecialchars($search); ?>">
                        <button class="btn btn-outline-secondary rounded-end-pill px-3" type="submit">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>

                    <?php if (!empty($search) || !empty($semester_filter) || $status_filter !== 'all'): ?>
                        <a href="?tab=<?= htmlspecialchars($tab); ?>" class="btn btn-sm btn-link text-muted" title="ล้างตัวกรอง">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small border-bottom">
                    <tr>
                        <th class="ps-4" style="width: 140px;">วันที่ยื่น</th>
                        <th style="min-width: 200px;">นิสิตผู้ยื่นคำร้อง</th>
                        <th style="min-width: 240px;">รายวิชาและกลุ่มเรียน</th>
                        <th style="min-width: 180px;">อาจารย์ที่เกี่ยวข้อง</th>
                        <th style="min-width: 170px;">สถานะปัจจุบัน</th>
                        <th class="text-end pe-4" style="width: 160px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <div class="py-4">
                                    <i class="fa-solid fa-folder-open fa-3x mb-3 text-secondary opacity-25"></i>
                                    <h5 class="fw-bold text-dark mb-1">ไม่พบรายการคำร้อง</h5>
                                    <p class="small text-muted mb-0">ไม่มีคำร้องขอสำรองที่นั่งในเงื่อนไขที่ท่านเลือก</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                            <?php
                            $can_approve = false;
                            $cur_st = $req['status'];
                            if ($cur_st === 'pending_instructor' && ($req['instructor_id'] === $user_id || $is_admin)) {
                                $can_approve = true;
                            } elseif ($cur_st === 'pending_advisor' && ($req['advisor_id'] === $user_id || $is_admin)) {
                                $can_approve = true;
                            } elseif ($cur_st === 'pending_head' && ($is_head || $is_admin)) {
                                $can_approve = true;
                            } elseif ($cur_st === 'pending_deputy' && ($is_deputy || $is_admin)) {
                                $can_approve = true;
                            } elseif ($cur_st === 'pending_academic' && ($is_academic || $is_admin)) {
                                $can_approve = true;
                            }

                            // กำหนดสีและป้ายสถานะ
                            $status_badge = '';
                            if ($cur_st === 'pending_instructor') {
                                $status_badge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-clock me-1"></i> รอ อ.ผู้สอนประจำกลุ่ม</span>';
                            } elseif ($cur_st === 'pending_advisor') {
                                $status_badge = '<span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-clock me-1"></i> รอ อ.ที่ปรึกษา</span>';
                            } elseif ($cur_st === 'pending_head') {
                                $status_badge = '<span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-clock me-1"></i> รอหัวหน้าภาคฯ/ประธาน</span>';
                            } elseif ($cur_st === 'pending_deputy') {
                                $status_badge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-clock me-1"></i> รอรอบฝ่ายวิชาการ</span>';
                            } elseif ($cur_st === 'pending_academic') {
                                $status_badge = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-clock me-1"></i> รองานบริการการศึกษา</span>';
                            } elseif ($cur_st === 'approved') {
                                $status_badge = '<span class="badge bg-success text-white rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-circle-check me-1"></i> อนุมัติเรียบร้อย</span>';
                            } elseif ($cur_st === 'rejected') {
                                $status_badge = '<span class="badge bg-danger text-white rounded-pill px-3 py-1 fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i> ไม่อนุมัติ</span>';
                            }
                            ?>
                            <tr class="<?= $can_approve ? 'bg-warning-subtle bg-opacity-10' : ''; ?>">
                                <!-- วันที่ยื่น -->
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= date('d/m/Y', strtotime($req['created_at'])); ?></div>
                                    <div class="extra-small text-muted"><?= date('H:i', strtotime($req['created_at'])); ?> น.</div>
                                    <div class="extra-small text-secondary mt-1">#REQ-<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?></div>
                                </td>

                                <!-- นิสิตผู้ยื่นคำร้อง -->
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2 flex-shrink-0">
                                            <?php if (!empty($req['profile_picture'])): ?>
                                                <?php $avatar_url = (strpos($req['profile_picture'], 'http') === 0) ? $req['profile_picture'] : '/e-service/uploads/' . $req['profile_picture']; ?>
                                                <img src="<?= htmlspecialchars($avatar_url); ?>" class="rounded-circle object-fit-cover shadow-2xs border" width="40" height="40" alt="Avatar">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center shadow-2xs" style="width: 40px; height: 40px;">
                                                    <i class="fa-solid fa-user-graduate"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?= htmlspecialchars(($req['std_prefix'] ?? '') . ($req['std_fname'] ?? '') . ' ' . ($req['std_lname'] ?? '')); ?>
                                            </div>
                                            <div class="extra-small text-muted">
                                                <span><i class="fa-solid fa-id-card me-1"></i><?= htmlspecialchars($req['student_id']); ?></span>
                                                <span class="ms-1">(ชั้นปี <?= htmlspecialchars($req['std_year'] ?? '-'); ?>)</span>
                                            </div>
                                            <div class="extra-small text-secondary"><?= htmlspecialchars($req['std_major'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- รายวิชาและกลุ่มเรียน -->
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-warning text-dark fw-bold rounded px-2 py-1 extra-small"><?= htmlspecialchars($req['course_code']); ?></span>
                                        <?php if (!empty($req['section'])): ?>
                                            <span class="badge bg-dark text-white rounded px-2 py-1 extra-small">กลุ่ม (Sec): <?= htmlspecialchars($req['section']); ?></span>
                                        <?php endif; ?>
                                        <span class="badge bg-light text-dark border rounded px-2 py-1 extra-small">ภาค <?= htmlspecialchars($req['semester']); ?></span>
                                    </div>
                                    <div class="fw-bold text-dark mt-1"><?= htmlspecialchars($req['course_name']); ?></div>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($req['reason']); ?>">
                                        <i class="fa-solid fa-comment-dots me-1 text-secondary"></i>เหตุผล: <?= htmlspecialchars($req['reason']); ?>
                                    </div>
                                </td>

                                <!-- อาจารย์ที่เกี่ยวข้อง -->
                                <td>
                                    <div class="extra-small text-dark mb-1">
                                        <i class="fa-solid fa-chalkboard-user me-1 text-primary"></i><strong>อ.ผู้สอน:</strong> 
                                        <?= htmlspecialchars(($req['inst_prefix'] ?? '') . ($req['inst_fname'] ?? '') . ' ' . ($req['inst_lname'] ?? '')); ?>
                                    </div>
                                    <div class="extra-small text-dark">
                                        <i class="fa-solid fa-user-tie me-1 text-info"></i><strong>อ.ที่ปรึกษา:</strong> 
                                        <?= htmlspecialchars(($req['adv_prefix'] ?? '') . ($req['adv_fname'] ?? '') . ' ' . ($req['adv_lname'] ?? '')); ?>
                                    </div>
                                </td>

                                <!-- สถานะปัจจุบัน -->
                                <td>
                                    <?= $status_badge; ?>
                                    <?php if ($can_approve): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-warning text-dark border border-warning rounded-pill px-2 py-1 extra-small animate__animated animate__pulse animate__infinite">
                                                ⚡ ถึงคิวท่านพิจารณา
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- การจัดการ -->
                                <td class="text-end pe-4">
                                    <?php if ($can_approve): ?>
                                        <a href="view.php?id=<?= $req['request_id']; ?>" class="btn btn-sm btn-success rounded-pill px-3 shadow-xs fw-bold">
                                            <i class="fa-solid fa-signature me-1"></i> พิจารณา
                                        </a>
                                    <?php else: ?>
                                        <a href="view.php?id=<?= $req['request_id']; ?>" class="btn btn-sm btn-outline-purple rounded-pill px-3 shadow-xs fw-semibold">
                                            <i class="fa-solid fa-eye me-1"></i> รายละเอียด
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<style>
.text-purple { color: #5a2e8f !important; }
.bg-purple { background-color: #5a2e8f !important; }
.bg-purple-subtle { background-color: #f3e8ff !important; }
.btn-purple { background-color: #5a2e8f !important; color: white !important; }
.btn-purple:hover { background-color: #4a2476 !important; color: white !important; }
.btn-outline-purple { color: #5a2e8f !important; border-color: #5a2e8f !important; }
.btn-outline-purple:hover { background-color: #5a2e8f !important; color: white !important; }
.extra-small { font-size: 0.75rem; }
.shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.shadow-2xs { box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
.hover-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
.hover-card:hover { transform: translateY(-3px); box-shadow: 0 8px 16px rgba(0,0,0,0.08) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>
