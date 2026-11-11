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

$request_id = intval($_GET['id'] ?? 0);
if ($request_id <= 0) {
    header("Location: index.php");
    exit();
}

// -------------------------------------------------------------------------
// 1. ดึงข้อมูลคำร้อง
// -------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.*,
           s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
           s.major AS std_major, s.year_level AS std_year, s.email AS std_email, s.phone_number AS std_phone,
           s.profile_picture,
           inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname, inst.position AS inst_pos, inst.signature_file AS inst_sig,
           adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_lname, adv.position AS adv_pos, adv.signature_file AS adv_sig
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    LEFT JOIN users adv ON r.advisor_id = adv.user_id
    WHERE r.request_id = ? AND r.request_type = 'reserve_seat'
");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo "<script>alert('ไม่พบข้อมูลคำร้องนี้ หรือไม่ใช่คำร้องขอสำรองที่นั่ง'); window.location.href='index.php';</script>";
    exit();
}

// -------------------------------------------------------------------------
// 2. วิเคราะห์สิทธิ์การพิจารณาในขั้นตอนปัจจุบัน (Authorization & Next Step)
// -------------------------------------------------------------------------
$cur_status = $req['status'];
$can_approve = false;
$role_title = '';
$next_status = '';
$comment_col = '';
$time_col = '';

if ($cur_status === 'pending_instructor' && ($req['instructor_id'] === $user_id || $is_admin)) {
    $can_approve = true;
    $role_title = 'อาจารย์ผู้สอนประจำกลุ่มเรียน';
    $next_status = 'pending_advisor';
    $comment_col = 'instructor_comment';
    $time_col = 'instructor_approved_at';
} elseif ($cur_status === 'pending_advisor' && ($req['advisor_id'] === $user_id || $is_admin)) {
    $can_approve = true;
    $role_title = 'อาจารย์ที่ปรึกษา';
    $next_status = 'pending_head';
    $comment_col = 'advisor_comment';
    $time_col = 'advisor_approved_at';
} elseif ($cur_status === 'pending_head') {
    $is_duriyang = (mb_strpos($req['std_major'] ?? '', 'ดุริยางค') !== false);
    $can_approve_head = false;
    if ($is_admin) {
        $can_approve_head = true;
    } elseif ($is_duriyang && (in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles) || $user_id === '6b8fba36220e1')) {
        $can_approve_head = true;
    } elseif (!$is_duriyang && (in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles) || $user_id === '6b8f3458220e1')) {
        $can_approve_head = true;
    } elseif ($is_head) {
        $can_approve_head = true;
    }
    if ($can_approve_head) {
        $can_approve = true;
        $role_title = $is_duriyang ? 'หัวหน้าภาควิชาดุริยางคศาสตร์' : 'หัวหน้าภาควิชาดนตรีศึกษา';
        $next_status = 'pending_deputy';
        $comment_col = 'head_comment';
        $time_col = 'head_approved_at';
    }
} elseif ($cur_status === 'pending_deputy' && ($is_deputy || $is_admin)) {
    $can_approve = true;
    $role_title = 'รองคณบดีฝ่ายวิชาการ';
    $next_status = 'pending_academic';
    $comment_col = 'deputy_comment';
    $time_col = 'deputy_approved_at';
} elseif ($cur_status === 'pending_academic' && ($is_academic || $is_admin)) {
    $can_approve = true;
    $role_title = 'งานบริการการศึกษา';
    $next_status = 'approved';
    $comment_col = 'academic_comment';
    $time_col = 'academic_approved_at';
} elseif ($is_admin && !in_array($cur_status, ['approved', 'rejected'])) {
    $can_approve = true;
    $role_title = 'ผู้ดูแลระบบ (Admin Override)';
    $next_status = 'approved';
    $comment_col = 'academic_comment';
    $time_col = 'academic_approved_at';
}

if (!function_exists('ensureCourseRequestCols')) {
    function ensureCourseRequestCols($pdo) {
        static $cols = null;
        if ($cols !== null) return $cols;
        try {
            $stmt = $pdo->query("DESCRIBE course_requests");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $needed = [
                'instructor_approved_at' => "ALTER TABLE `course_requests` ADD COLUMN `instructor_approved_at` DATETIME NULL AFTER `instructor_comment`",
                'advisor_approved_at'    => "ALTER TABLE `course_requests` ADD COLUMN `advisor_approved_at` DATETIME NULL AFTER `advisor_comment`",
                'head_approved_at'       => "ALTER TABLE `course_requests` ADD COLUMN `head_approved_at` DATETIME NULL AFTER `head_comment`",
                'deputy_approved_at'     => "ALTER TABLE `course_requests` ADD COLUMN `deputy_approved_at` DATETIME NULL AFTER `deputy_comment`",
                'academic_approved_at'   => "ALTER TABLE `course_requests` ADD COLUMN `academic_approved_at` DATETIME NULL AFTER `academic_comment`"
            ];

            $did_alter = false;
            foreach ($needed as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    try {
                        $pdo->exec($sql);
                        $did_alter = true;
                    } catch (\Exception $e) {}
                }
            }

            if ($did_alter) {
                $stmt = $pdo->query("DESCRIBE course_requests");
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (\Exception $e) {
            $cols = [];
        }
        return $cols;
    }
}

// -------------------------------------------------------------------------
// 3. จัดการ Action: บันทึกผลการพิจารณา (POST)
// -------------------------------------------------------------------------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $can_approve) {
    $action = $_POST['action']; // 'approve' หรือ 'reject'
    $comment = trim($_POST['comment'] ?? '');

    $final_status = ($action === 'approve') ? $next_status : 'rejected';

    try {
        $valid_cols = ensureCourseRequestCols($pdo);
        $time_col_safe = in_array($time_col, $valid_cols, true) ? $time_col : '';
        $time_sql = !empty($time_col_safe) ? ", `{$time_col_safe}` = NOW()" : "";
        $stmt_up = $pdo->prepare("
            UPDATE course_requests 
            SET status = ?, {$comment_col} = ? {$time_sql}, updated_at = NOW() 
            WHERE request_id = ?
        ");
        $stmt_up->execute([$final_status, $comment, $request_id]);

        $_SESSION['msg'] = ($action === 'approve') 
            ? "บันทึกผลการพิจารณา \"อนุมัติ\" คำร้องขอสำรองที่นั่งเรียบร้อยแล้ว" 
            : "บันทึกผลการพิจารณา \"ไม่อนุมัติ\" คำร้องขอสำรองที่นั่งเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = ($action === 'approve') ? "success" : "warning";

        header("Location: view.php?id=" . $request_id);
        exit();
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="text-decoration-none text-muted small">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการขอสำรองที่นั่ง
                </a>
                <span class="text-muted">/</span>
                <span class="badge bg-warning text-dark rounded-pill px-2 py-1 extra-small">#REQ-<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?></span>
            </div>
            <h1 class="h2 fw-bold text-dark mb-0">
                พิจารณาคำร้องขอสำรองที่นั่ง: <span class="text-warning"><?= htmlspecialchars($req['course_code']); ?> <?= htmlspecialchars($req['course_name']); ?></span>
            </h1>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-xs">
                <i class="fa-solid fa-list me-1"></i> หน้ารวมคำร้อง
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

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger rounded-4 shadow-xs border-0 mb-4">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Status Header Banner -->
    <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 position-relative overflow-hidden">
        <!-- Digital Status Stamp -->
        <?php if ($req['status'] === 'approved'): ?>
            <div class="status-stamp stamp-approved">
                <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
            </div>
        <?php elseif ($req['status'] === 'rejected'): ?>
            <div class="status-stamp stamp-rejected">
                <i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ
            </div>
        <?php else: ?>
            <div class="status-stamp stamp-pending">
                <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="text-muted extra-small d-block mb-1">สถานะปัจจุบันของคำร้อง</span>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($req['status'] === 'approved'): ?>
                        <span class="badge bg-success fs-6 rounded-pill px-4 py-2">
                            <i class="fa-solid fa-circle-check me-2"></i> อนุมัติการสำรองที่นั่งสมบูรณ์แล้ว
                        </span>
                    <?php elseif ($req['status'] === 'rejected'): ?>
                        <span class="badge bg-danger fs-6 rounded-pill px-4 py-2">
                            <i class="fa-solid fa-circle-xmark me-2"></i> คำร้องนี้ไม่ได้รับการอนุมัติ (ยุติเรื่อง)
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6 rounded-pill px-4 py-2">
                            <i class="fa-solid fa-spinner fa-spin me-2"></i> กำลังอยู่ระหว่างกระบวนการพิจารณา
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-md-end text-muted small">
                <?php
                if (!function_exists('formatThaiDT')) {
                    function formatThaiDT($dt) {
                        if (empty($dt)) return '-';
                        $ts = strtotime($dt);
                        if (!$ts) return '-';
                        $y = (int)date('Y', $ts);
                        if ($y < 2500) $y += 543;
                        return date('d/m/', $ts) . $y . ' ' . date('H:i', $ts) . ' น.';
                    }
                }
                ?>
                <div><i class="fa-regular fa-clock me-1"></i> ยื่นคำร้องเมื่อ: <strong><?= formatThaiDT($req['created_at']); ?></strong></div>
                <div><i class="fa-solid fa-rotate me-1"></i> อัปเดตล่าสุด: <strong><?= formatThaiDT($req['updated_at']); ?></strong></div>
            </div>
        </div>
    </div>

    <!-- 5-Step Flow Timeline -->
    <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
        <h6 class="fw-bold text-dark mb-4"><i class="fa-solid fa-timeline me-2 text-warning"></i>ขั้นตอนการพิจารณาคำร้องตามลำดับ (Workflow Steps)</h6>
        
        <?php
        $is_duriyang = (mb_strpos($req['std_major'] ?? '', 'ดุริยางค') !== false);
        $head_name = $is_duriyang ? 'ผู้ช่วยศาสตราจารย์อรรคพงศ์ ภูลายยาว' : 'ดร.พิษณุ บุญศรีอนันต์';
        $head_title = $is_duriyang ? '3. หัวหน้าภาควิชาดุริยางคศาสตร์' : '3. หัวหน้าภาควิชาดนตรีศึกษา';
        $head_sig = $is_duriyang ? 'sig_6b8fba36220e1_1777452753.png' : 'sig_34ee8171_1774328264.png';

        $flow_steps = [
            1 => [
                'key' => 'pending_instructor',
                'title' => '1. อาจารย์ผู้สอนประจำกลุ่ม',
                'name' => ($req['inst_prefix'] ?? '') . ($req['inst_fname'] ?? '') . ' ' . ($req['inst_lname'] ?? ''),
                'comment' => $req['instructor_comment'],
                'sig' => $req['inst_sig'] ?? 'sig_6b8eb1d6220e1_1775535261.png',
                'approved_at' => $req['instructor_approved_at'] ?? null
            ],
            2 => [
                'key' => 'pending_advisor',
                'title' => '2. อาจารย์ที่ปรึกษา',
                'name' => ($req['adv_prefix'] ?? '') . ($req['adv_fname'] ?? '') . ' ' . ($req['adv_lname'] ?? ''),
                'comment' => $req['advisor_comment'],
                'sig' => $req['adv_sig'] ?? 'sig_6b8f3854220e1_1777092753.png',
                'approved_at' => $req['advisor_approved_at'] ?? null
            ],
            3 => [
                'key' => 'pending_head',
                'title' => $head_title,
                'name' => $head_name,
                'comment' => $req['head_comment'],
                'sig' => $head_sig,
                'approved_at' => $req['head_approved_at'] ?? null
            ],
            4 => [
                'key' => 'pending_deputy',
                'title' => '4. รองคณบดีฝ่ายวิชาการ',
                'name' => 'ผศ.ดร.วีรยุทธ สีคุณหลิ่ว',
                'comment' => $req['deputy_comment'],
                'sig' => 'sig_6b8f36d8220e1_1774242276.png',
                'approved_at' => $req['deputy_approved_at'] ?? null
            ],
            5 => [
                'key' => 'pending_academic',
                'title' => '5. งานบริการการศึกษา',
                'name' => 'นางสาวธรัญญา ปูธิรัตน์',
                'comment' => $req['academic_comment'],
                'sig' => 'sig_6b8fbc8e220e1_1774252548.png',
                'approved_at' => $req['academic_approved_at'] ?? null
            ]
        ];

        $status_order = [
            'pending_instructor' => 1,
            'pending_advisor' => 2,
            'pending_head' => 3,
            'pending_deputy' => 4,
            'pending_academic' => 5,
            'approved' => 6,
            'rejected' => -1
        ];
        $cur_num = $status_order[$req['status']] ?? 1;
        ?>

        <div class="row g-2 text-center position-relative">
            <?php foreach ($flow_steps as $step_no => $step): ?>
                <?php
                $is_passed = ($cur_num > $step_no);
                $is_current = ($cur_num === $step_no);
                $is_rejected_here = ($req['status'] === 'rejected' && !empty($step['comment']));

                $box_bg = 'bg-light text-muted border';
                $icon_html = '<i class="fa-regular fa-circle text-muted"></i>';

                if ($is_passed) {
                    $box_bg = 'bg-success-subtle text-success border border-success-subtle';
                    $icon_html = '<i class="fa-solid fa-circle-check text-success fs-5"></i>';
                } elseif ($is_current) {
                    $box_bg = 'bg-warning-subtle text-dark border border-warning shadow-2xs';
                    $icon_html = '<i class="fa-solid fa-spinner fa-spin text-warning fs-5"></i>';
                } elseif ($is_rejected_here) {
                    $box_bg = 'bg-danger-subtle text-danger border border-danger-subtle';
                    $icon_html = '<i class="fa-solid fa-circle-xmark text-danger fs-5"></i>';
                }
                ?>
                <div class="col-12 col-md">
                    <div class="p-3 rounded-4 <?= $box_bg; ?> h-100 text-start">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-bold extra-small text-uppercase"><?= $step['title']; ?></span>
                            <?= $icon_html; ?>
                        </div>
                        <div class="fw-semibold small text-dark"><?= htmlspecialchars($step['name']); ?></div>

                        <?php if (($is_passed || $req['status'] === 'approved' || $is_rejected_here) && !empty($step['sig'])): ?>
                            <div class="my-2 py-1 px-1 text-center bg-white rounded-3 border shadow-2xs">
                                <img src="/music-e-office/uploads/signatures/<?= htmlspecialchars($step['sig']); ?>" style="max-height: 38px; max-width: 110px; object-fit: contain;" alt="ลายเซ็น">
                                <div class="text-muted extra-small mt-1 border-top pt-1" style="font-size: 0.68rem; line-height: 1.2;">
                                    <?php if ($is_rejected_here): ?>
                                        <i class="fa-solid fa-circle-xmark text-danger me-1"></i>ไม่อนุมัติ: <?= formatThaiDT(!empty($step['approved_at']) ? $step['approved_at'] : $req['updated_at']); ?>
                                    <?php else: ?>
                                        <i class="fa-regular fa-clock text-secondary me-1"></i>อนุมัติ: <?= formatThaiDT(!empty($step['approved_at']) ? $step['approved_at'] : $req['updated_at']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($step['comment'])): ?>
                            <div class="mt-2 p-2 bg-white rounded-3 extra-small border">
                                <span class="text-muted d-block fw-bold mb-1">ความเห็น:</span>
                                <?= nl2br(htmlspecialchars($step['comment'])); ?>
                            </div>
                        <?php elseif ($is_current): ?>
                            <span class="badge bg-warning text-dark mt-2 extra-small">รอพิจารณา</span>
                        <?php else: ?>
                            <span class="text-muted extra-small d-block mt-2">- ยังไม่ถึงคิว -</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- ข้อมูลคำร้องและนิสิต -->
        <div class="col-lg-7">
            
            <!-- ข้อมูลรายวิชาและกลุ่มเรียน -->
            <div class="card border-0 shadow-xs rounded-4 bg-white mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-chair text-warning me-2"></i>ข้อมูลรายวิชาและกลุ่มเรียนที่ขอสำรอง</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <div class="extra-small text-muted mb-1">รหัสวิชา</div>
                            <div class="h5 fw-bold text-dark mb-0"><?= htmlspecialchars($req['course_code']); ?></div>
                        </div>
                        <div class="col-sm-8">
                            <div class="extra-small text-muted mb-1">ชื่อรายวิชา</div>
                            <div class="h5 fw-bold text-dark mb-0"><?= htmlspecialchars($req['course_name']); ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="extra-small text-muted mb-1">กลุ่มเรียน (Section)</div>
                            <div class="h5 fw-bold text-primary mb-0">กลุ่ม Sec <?= htmlspecialchars($req['section'] ?: '1'); ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="extra-small text-muted mb-1">ภาคการศึกษา</div>
                            <div class="fw-bold text-dark">ภาคเรียน <?= htmlspecialchars($req['semester']); ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="extra-small text-muted mb-1">ประเภทคำร้อง</div>
                            <div class="badge bg-warning-subtle text-dark border border-warning-subtle rounded-pill px-3 py-1 fw-bold">
                                คำร้องขอสำรองที่นั่ง
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="extra-small text-muted mb-1">เหตุผลความจำเป็นของนิสิต</div>
                            <div class="p-3 bg-light rounded-4 text-dark border">
                                <?= nl2br(htmlspecialchars($req['reason'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ข้อมูลนิสิตผู้ยื่นคำร้อง -->
            <div class="card border-0 shadow-xs rounded-4 bg-white mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-graduate text-purple me-2"></i>ข้อมูลผู้ยื่นคำร้อง</h5>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="me-3 flex-shrink-0">
                            <?php if (!empty($req['profile_picture'])): ?>
                                <?php $avatar_url = (strpos($req['profile_picture'], 'http') === 0) ? $req['profile_picture'] : '/e-service/uploads/' . $req['profile_picture']; ?>
                                <img src="<?= htmlspecialchars($avatar_url); ?>" class="rounded-circle object-fit-cover shadow-xs border" width="65" height="65" alt="Avatar">
                            <?php else: ?>
                                <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center shadow-xs" style="width: 65px; height: 65px; font-size: 28px;">
                                    <i class="fa-solid fa-user-graduate"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-1">
                                <?= htmlspecialchars(($req['std_prefix'] ?? '') . ($req['std_fname'] ?? '') . ' ' . ($req['std_lname'] ?? '')); ?>
                            </h5>
                            <div class="text-muted small">
                                <span class="me-3"><i class="fa-solid fa-id-card me-1"></i>รหัสนิสิต: <strong><?= htmlspecialchars($req['student_id']); ?></strong></span>
                                <span><i class="fa-solid fa-layer-group me-1"></i>ชั้นปี: <?= htmlspecialchars($req['std_year'] ?? '-'); ?></span>
                            </div>
                            <div class="text-secondary small mt-1">
                                <i class="fa-solid fa-graduation-cap me-1"></i><?= htmlspecialchars($req['std_major'] ?? 'วิทยาลัยดุริยางคศิลป์'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 pt-2 border-top">
                        <div class="col-sm-6">
                            <span class="extra-small text-muted d-block">อีเมลติดต่อ:</span>
                            <span class="small fw-semibold text-dark"><?= htmlspecialchars($req['std_email'] ?: '-'); ?></span>
                        </div>
                        <div class="col-sm-6">
                            <span class="extra-small text-muted d-block">เบอร์โทรศัพท์:</span>
                            <span class="small fw-semibold text-dark"><?= htmlspecialchars($req['std_phone'] ?: '-'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- กล่องพิจารณา / ฟอร์มการอนุมัติ (Approval Box) -->
        <div class="col-lg-5">
            <?php if ($can_approve): ?>
                <div class="card border-0 shadow-sm rounded-4 bg-white border-top border-warning border-4 mb-4">
                    <div class="card-header bg-white py-3 px-4 border-bottom">
                        <div class="d-flex align-items-center justify-content-between">
                            <h5 class="fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-pen-to-square text-warning me-2"></i>พิจารณาคำร้อง
                            </h5>
                            <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold extra-small">
                                คิวของท่าน
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-warning-subtle text-dark border border-warning-subtle rounded-3 small mb-3">
                            <i class="fa-solid fa-circle-info me-1 text-warning"></i> ท่านกำลังพิจารณาในบทบาท: <strong><?= htmlspecialchars($role_title); ?></strong>
                        </div>

                        <form method="POST" onsubmit="return confirm('ยืนยันผลการพิจารณาคำร้องนี้หรือไม่?');">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">ผลการพิจารณา <span class="text-danger">*</span></label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="action" id="action_approve" value="approve" checked>
                                        <label class="btn btn-outline-success w-100 rounded-4 py-3 fw-bold d-flex flex-column align-items-center gap-1" for="action_approve">
                                            <i class="fa-solid fa-circle-check fs-4"></i>
                                            <span>อนุมัติคำร้อง</span>
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="action" id="action_reject" value="reject">
                                        <label class="btn btn-outline-danger w-100 rounded-4 py-3 fw-bold d-flex flex-column align-items-center gap-1" for="action_reject">
                                            <i class="fa-solid fa-circle-xmark fs-4"></i>
                                            <span>ไม่อนุมัติ</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="comment" class="form-label fw-bold small text-dark">
                                    ความเห็น / ข้อเสนอแนะ / หมายเหตุ 
                                    <span class="text-muted fw-normal extra-small">(จะแสดงให้นิสิตและผู้พิจารณาขั้นถัดไปเห็น)</span>
                                </label>
                                <textarea name="comment" id="comment" rows="4" class="form-control rounded-3" placeholder="ระบุความเห็น ข้อคิดเห็น หรือเหตุผลประกอบ..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-purple w-100 py-3 rounded-pill fw-bold shadow-sm">
                                <i class="fa-solid fa-signature me-1"></i> บันทึกผลการพิจารณา
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- แสดงข้อมูลเมื่อไม่ใช่คิวของผู้ใช้ปัจจุบัน -->
                <div class="card border-0 shadow-xs rounded-4 bg-white mb-4">
                    <div class="card-body p-4 text-center">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <?php if ($req['status'] === 'approved'): ?>
                                <i class="fa-solid fa-circle-check fa-3x text-success"></i>
                            <?php elseif ($req['status'] === 'rejected'): ?>
                                <i class="fa-solid fa-circle-xmark fa-3x text-danger"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-lock fa-2x text-muted opacity-50"></i>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold text-dark mb-1">
                            <?php if ($req['status'] === 'approved'): ?>
                                ดำเนินการอนุมัติสมบูรณ์แล้ว
                            <?php elseif ($req['status'] === 'rejected'): ?>
                                คำร้องนี้ไม่ได้รับการอนุมัติ
                            <?php else: ?>
                                ยังไม่ถึงคิวของท่าน
                            <?php endif; ?>
                        </h5>
                        <p class="text-muted small mb-0">
                            <?php if ($req['status'] === 'approved'): ?>
                                คำร้องนี้ได้รับการอนุมัติสำรองที่นั่งเรียบร้อยแล้ว
                            <?php elseif ($req['status'] === 'rejected'): ?>
                                คำร้องสิ้นสุดกระบวนการแล้ว
                            <?php else: ?>
                                ขณะนี้คำร้องอยู่ระหว่างรอการพิจารณาในขั้นตอนก่อนหน้า เมื่อถึงคิวของท่าน ระบบจะเปิดให้ท่านบันทึกผลการพิจารณาในหน้านี้
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- รายชื่อผู้มีส่วนเกี่ยวข้อง -->
            <div class="card border-0 shadow-xs rounded-4 bg-white">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-users me-2 text-warning"></i>อาจารย์ที่นิสิตเลือกในคำร้อง</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="extra-small text-muted mb-1">1. อาจารย์ผู้สอนประจำกลุ่มเรียน (Sec <?= htmlspecialchars($req['section'] ?: '1'); ?>)</div>
                        <div class="fw-bold text-dark">
                            <?= htmlspecialchars(($req['inst_prefix'] ?? '') . ($req['inst_fname'] ?? '') . ' ' . ($req['inst_lname'] ?? '')); ?>
                        </div>
                        <div class="extra-small text-secondary"><?= htmlspecialchars($req['inst_pos'] ?? 'อาจารย์'); ?></div>
                    </div>
                    <div>
                        <div class="extra-small text-muted mb-1">2. อาจารย์ที่ปรึกษา</div>
                        <div class="fw-bold text-dark">
                            <?= htmlspecialchars(($req['adv_prefix'] ?? '') . ($req['adv_fname'] ?? '') . ' ' . ($req['adv_lname'] ?? '')); ?>
                        </div>
                        <div class="extra-small text-secondary"><?= htmlspecialchars($req['adv_pos'] ?? 'อาจารย์'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<style>
.text-purple { color: #5a2e8f !important; }
.bg-purple { background-color: #5a2e8f !important; }
.bg-purple-subtle { background-color: #f3e8ff !important; }
.btn-purple { background-color: #5a2e8f !important; color: white !important; }
.btn-purple:hover { background-color: #4a2476 !important; color: white !important; }
.extra-small { font-size: 0.75rem; }
.shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.shadow-2xs { box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
</style>

<?php require_once '../../includes/footer.php'; ?>
