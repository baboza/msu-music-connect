<?php
// เปิด Error Reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'auth/session_check.php'; 
require_once 'config/db.php';
require_once 'includes/mailer.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id === 0) {
    header("Location: requests_list.php");
    exit();
}

// -------------------------------------------------------------------------
// 1. ดึงสิทธิ์ของผู้ใช้ปัจจุบัน (ทั้ง Role Name จาก Session และ Role ID จาก DB)
// -------------------------------------------------------------------------
$user_roles = $_SESSION['roles'] ?? [];

$my_role_ids = [];
try {
    $stmt_my_roles = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
    $stmt_my_roles->execute([$user_id]);
    $my_role_ids = $stmt_my_roles->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$is_admin = in_array('admin', $user_roles) || in_array(25, $my_role_ids);
$is_course_deputy = in_array('รองคณบดีฝ่ายวิชาการ', $user_roles) || in_array(7, $my_role_ids);
$is_course_academic = (count(array_intersect(['งานบริการการศึกษา', 'งานวิชาการ'], $user_roles)) > 0) || in_array(12, $my_role_ids);

$is_course_head = in_array(4, $my_role_ids) || in_array(50, $my_role_ids) || in_array(75, $my_role_ids);
if (!$is_course_head) {
    foreach ($user_roles as $r) {
        if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
            $is_course_head = true;
            break;
        }
    }
}

// -------------------------------------------------------------------------
// 2. ดึงข้อมูลคำร้องและข้อมูลนิสิต
// -------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT r.*, 
           s.student_id AS std_code, s.prefix_name, s.first_name, s.last_name, s.major, s.year_level, s.profile_picture,
           s.phone_number AS std_phone, s.email AS std_email,
           inst.first_name AS inst_first, inst.last_name AS inst_last, inst.prefix_name AS inst_prefix, inst.email AS inst_email, inst.position AS inst_pos,
           adv.first_name AS adv_first, adv.last_name AS adv_last, adv.prefix_name AS adv_prefix, adv.email AS adv_email, adv.position AS adv_pos
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN users inst ON r.instructor_id = inst.user_id
    LEFT JOIN users adv ON r.advisor_id = adv.user_id
    WHERE r.request_id = ?
");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo "<script>alert('ไม่พบข้อมูลคำร้อง'); window.location.href='requests_list.php';</script>";
    exit();
}

// -------------------------------------------------------------------------
// 3. ตรวจสอบสิทธิ์การพิจารณา (Authorization Logic & Next Step)
// -------------------------------------------------------------------------
$can_approve = false;
$next_status = '';
$comment_column = '';
$step_name = '';
$is_override = false;

$current_status = $req['status'];

if ($current_status == 'pending_instructor' && ($req['instructor_id'] == $user_id || $is_admin)) {
    $can_approve = true; 
    $next_status = 'pending_advisor'; 
    $comment_column = 'instructor_comment'; 
    $step_name = 'อาจารย์ผู้สอน / อาจารย์ผู้ประสานงาน';
    if ($is_admin && $req['instructor_id'] != $user_id) $is_override = true;
} 
elseif ($current_status == 'pending_advisor' && ($req['advisor_id'] == $user_id || $is_admin)) {
    $can_approve = true; 
    $next_status = 'pending_head'; 
    $comment_column = 'advisor_comment'; 
    $step_name = 'อาจารย์ที่ปรึกษา';
    if ($is_admin && $req['advisor_id'] != $user_id) $is_override = true;
} 
elseif ($current_status == 'pending_head' && ($is_course_head || $is_admin)) {
    $can_approve = true; 
    $next_status = 'pending_deputy'; 
    $comment_column = 'head_comment'; 
    $step_name = 'หัวหน้าภาควิชา / ประธานหลักสูตร';
    if ($is_admin && !$is_course_head) $is_override = true;
} 
elseif ($current_status == 'pending_deputy' && ($is_course_deputy || $is_admin)) {
    $can_approve = true; 
    $next_status = 'pending_academic'; 
    $comment_column = 'deputy_comment'; 
    $step_name = 'รองคณบดีฝ่ายวิชาการ';
    if ($is_admin && !$is_course_deputy) $is_override = true;
} 
elseif ($current_status == 'pending_academic' && ($is_course_academic || $is_admin)) {
    $can_approve = true; 
    $next_status = 'approved'; 
    $comment_column = 'academic_comment'; 
    $step_name = 'งานบริการการศึกษา / งานวิชาการ';
    if ($is_admin && !$is_course_academic) $is_override = true;
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
// 4. จัดการฟอร์มเมื่อกด อนุมัติ / ไม่อนุมัติ
// -------------------------------------------------------------------------
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $can_approve) {
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    
    $final_status = ($action == 'approve') ? $next_status : 'rejected';
    
    $time_col_map = [
        'instructor_comment' => 'instructor_approved_at',
        'advisor_comment'    => 'advisor_approved_at',
        'head_comment'       => 'head_approved_at',
        'deputy_comment'     => 'deputy_approved_at',
        'academic_comment'   => 'academic_approved_at'
    ];
    $valid_cols = ensureCourseRequestCols($pdo);
    $target_time_col = $time_col_map[$comment_column] ?? '';
    $time_column = in_array($target_time_col, $valid_cols, true) ? $target_time_col : '';
    $time_sql = !empty($time_column) ? ", `{$time_column}` = NOW()" : "";

    $stmt_update = $pdo->prepare("UPDATE course_requests SET status = ?, {$comment_column} = ? {$time_sql}, updated_at = NOW() WHERE request_id = ?");
    
    if ($stmt_update->execute([$final_status, $comment, $request_id])) {
        // ส่งอีเมลแจ้งเตือนขั้นตอนถัดไปหรือแจ้งผลแก่นิสิต
        if (function_exists('sendCourseRequestNextStepNotification')) {
            sendCourseRequestNextStepNotification($request_id, $final_status, $comment, $step_name, $pdo);
        }
        $msg = ($action == 'approve') ? 'บันทึกผลการอนุมัติคำร้องเรียบร้อยแล้ว' : 'บันทึกผลการไม่อนุมัติคำร้องเรียบร้อยแล้ว';
        echo "<script>alert('{$msg}'); window.location.href='requests_approve.php?id={$request_id}';</script>";
        exit();
    } else {
        $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง";
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// ฟังก์ชันดึงสถานะสำหรับ Timeline
function getStatusClass($status_check, $current) {
    $flow = ['pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic', 'approved'];
    if ($current == 'rejected') return 'text-muted opacity-50';
    $check_idx = array_search($status_check, $flow);
    $curr_idx = array_search($current, $flow);
    
    if ($curr_idx === false) return 'text-muted opacity-50';
    if ($check_idx < $curr_idx) return 'text-success fw-bold';
    if ($check_idx == $curr_idx) return 'text-warning fw-bold';
    return 'text-muted opacity-50';
}

// ลิงก์ไปยังโมดูลเฉพาะทาง
$module_view_url = ($req['request_type'] === 'open_course') 
    ? "modules/open_course/view.php?id={$request_id}" 
    : "modules/reserve_seat/view.php?id={$request_id}";
$module_title = ($req['request_type'] === 'open_course') ? 'ระบบขอเปิดรายวิชา' : 'ระบบขอสำรองที่นั่ง';

// รูปโปรไฟล์นิสิต
$avatar_url = '';
if (!empty($req['profile_picture'])) {
    if (strpos($req['profile_picture'], 'http://') === 0 || strpos($req['profile_picture'], 'https://') === 0) {
        $avatar_url = $req['profile_picture'];
    } else {
        $avatar_url = '../e-service/uploads/' . $req['profile_picture'];
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="requests_list.php" class="text-decoration-none text-muted small">
                    <i class="fa-solid fa-arrow-left me-1"></i> ศูนย์อนุมัติรวม (Approvals)
                </a>
                <span class="text-muted">/</span>
                <span class="badge bg-purple text-white rounded-pill px-2 py-1 extra-small">#REQ-<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?></span>
            </div>
            <h1 class="h2 fw-bold text-purple mb-0">
                <i class="fa-solid fa-file-signature me-2"></i>พิจารณาคำร้องนิสิต
            </h1>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
            <a href="<?= $module_view_url; ?>" class="btn btn-outline-purple btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-up-right-from-square me-1"></i> เปิดใน<?= $module_title; ?>
            </a>
            <a href="requests_list.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger shadow-sm rounded-4"><i class="fa-solid fa-circle-exclamation me-2"></i><?= $error; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ข้อมูลคำร้องและนิสิต -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 position-relative overflow-hidden">
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

                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-purple mb-0"><i class="fa-solid fa-user-graduate me-2"></i>ข้อมูลผู้ยื่นคำร้อง</h5>
                    <span class="badge <?= ($req['request_type'] == 'open_course') ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'; ?> rounded-pill px-3 py-1">
                        <i class="fa-solid <?= ($req['request_type'] == 'open_course') ? 'fa-book-medical' : 'fa-chair'; ?> me-1"></i>
                        <?= ($req['request_type'] == 'open_course') ? 'คำร้องขอเปิดรายวิชา' : 'คำร้องขอสำรองที่นั่ง'; ?>
                    </span>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-4">
                        <div class="me-3">
                            <?php if (!empty($avatar_url)): ?>
                                <img src="<?= htmlspecialchars($avatar_url); ?>" class="rounded-circle object-fit-cover shadow-sm border" width="70" height="70" alt="Profile">
                            <?php else: ?>
                                <div class="rounded-circle bg-purple text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 70px; height: 70px; font-size: 24px;">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars(($req['prefix_name'] ?? '') . ($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? '')); ?></h5>
                            <div class="text-muted small">
                                <span class="me-3"><i class="fa-solid fa-id-badge me-1"></i><?= htmlspecialchars($req['std_code'] ?? '-'); ?></span>
                                <span><i class="fa-solid fa-graduation-cap me-1"></i><?= htmlspecialchars($req['major'] ?? '-'); ?> (ชั้นปี <?= htmlspecialchars($req['year_level'] ?? '-'); ?>)</span>
                            </div>
                            <?php if (!empty($req['std_phone']) || !empty($req['std_email'])): ?>
                                <div class="text-muted extra-small mt-1">
                                    <?php if (!empty($req['std_phone'])): ?>
                                        <span class="me-3"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($req['std_phone']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($req['std_email'])): ?>
                                        <span><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($req['std_email']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded-3 mb-4 border">
                        <div class="row g-3">
                            <div class="col-sm-4">
                                <label class="extra-small text-muted text-uppercase fw-bold">ภาคเรียน</label>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($req['semester'] ?? '-'); ?></div>
                            </div>
                            <div class="col-sm-4">
                                <label class="extra-small text-muted text-uppercase fw-bold">รหัสวิชา</label>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($req['course_code'] ?? '-'); ?></div>
                            </div>
                            <div class="col-sm-4">
                                <label class="extra-small text-muted text-uppercase fw-bold">กลุ่มเรียน (Sec)</label>
                                <div class="fw-bold text-dark"><?= !empty($req['section']) ? htmlspecialchars($req['section']) : '-'; ?></div>
                            </div>
                            <div class="col-12">
                                <label class="extra-small text-muted text-uppercase fw-bold">ชื่อรายวิชา</label>
                                <div class="fw-bold text-primary fs-6"><?= htmlspecialchars($req['course_name'] ?? '-'); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="extra-small text-muted text-uppercase fw-bold">อาจารย์ผู้สอน / ประสานงาน</label>
                                <div class="small fw-semibold text-dark">
                                    <?= htmlspecialchars(($req['inst_prefix'] ?? '') . ($req['inst_first'] ?? '') . ' ' . ($req['inst_last'] ?? '-')); ?>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label class="extra-small text-muted text-uppercase fw-bold">อาจารย์ที่ปรึกษา</label>
                                <div class="small fw-semibold text-dark">
                                    <?= htmlspecialchars(($req['adv_prefix'] ?? '') . ($req['adv_first'] ?? '') . ' ' . ($req['adv_last'] ?? '-')); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="fw-bold text-dark small mb-2"><i class="fa-solid fa-circle-info text-warning me-1"></i> เหตุผลความจำเป็น:</label>
                    <div class="p-3 bg-warning-subtle text-dark rounded-3 small border border-warning-subtle" style="white-space: pre-wrap; line-height: 1.6;"><?= htmlspecialchars($req['reason'] ?? '-'); ?></div>
                </div>
            </div>

            <?php if (!$can_approve && $current_status != 'approved' && $current_status != 'rejected'): ?>
                <div class="alert alert-info shadow-sm rounded-4 border-0 d-flex align-items-center p-4">
                    <i class="fa-solid fa-clock-rotate-left fa-2x me-3 opacity-50"></i>
                    <div>
                        <h6 class="fw-bold mb-1">คำร้องนี้กำลังรอการพิจารณา</h6>
                        <p class="mb-0 small">ปัจจุบันคำร้องอยู่ที่ขั้นตอน: <strong><?php 
                            $stage_names = [
                                'pending_instructor' => 'รออาจารย์ผู้สอนพิจารณา',
                                'pending_advisor' => 'รออาจารย์ที่ปรึกษาพิจารณา',
                                'pending_head' => 'รอหัวหน้าภาคฯ / ประธานหลักสูตรพิจารณา',
                                'pending_deputy' => 'รอรองคณบดีฝ่ายวิชาการพิจารณา',
                                'pending_academic' => 'รองานบริการการศึกษาพิจารณา'
                            ];
                            echo $stage_names[$current_status] ?? $current_status;
                        ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ฟอร์มพิจารณา และ เส้นทางอนุมัติ -->
        <div class="col-lg-5">
            
            <?php if ($can_approve): ?>
            <div class="card border-0 shadow-lg rounded-4 bg-white mb-4 border-top border-4 border-warning">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-pen-to-square text-warning me-2"></i>บันทึกผลการพิจารณา</h5>
                    <p class="small text-muted mt-1 mb-0">
                        พิจารณาในฐานะ: <span class="badge bg-purple"><?= $step_name; ?></span>
                        <?php if ($is_override): ?>
                            <span class="badge bg-danger ms-1"><i class="fa-solid fa-shield-halved me-1"></i>Admin Override</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ความเห็น / ข้อเสนอแนะ <span class="text-danger">*</span></label>
                            <textarea name="comment" class="form-control bg-light" rows="4" placeholder="ระบุเหตุผลที่อนุมัติ หรือเหตุผลที่ไม่อนุมัติ..." required></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="submit" name="action" value="approve" class="btn btn-success w-100 py-2 rounded-pill fw-bold shadow-sm" onclick="return confirm('ยืนยันการ [อนุมัติ] คำร้องนี้?');">
                                    <i class="fa-solid fa-check-circle me-1"></i> อนุมัติ / ส่งต่อ
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="submit" name="action" value="reject" class="btn btn-outline-danger w-100 py-2 rounded-pill fw-bold" onclick="return confirm('ยืนยันการ [ไม่อนุมัติ] คำร้องนี้? คำร้องจะถูกตีกลับทันที');">
                                    <i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- เส้นทางการอนุมัติ -->
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white border-bottom py-3 px-4">
                    <h6 class="fw-bold text-purple mb-0"><i class="fa-solid fa-list-ul me-2"></i>เส้นทางการอนุมัติ</h6>
                </div>
                <div class="card-body p-4">
                    <div class="timeline-simple">
                        
                        <?php 
                        $inst_full = trim(($req['inst_prefix'] ?? '') . ($req['inst_first'] ?? '') . ' ' . ($req['inst_last'] ?? ''));
                        $adv_full = trim(($req['adv_prefix'] ?? '') . ($req['adv_first'] ?? '') . ' ' . ($req['adv_last'] ?? ''));
                        $is_duriyang = (mb_strpos($req['major'] ?? '', 'ดุริยางค') !== false);
                        $head_name = $is_duriyang ? 'ผู้ช่วยศาสตราจารย์อรรคพงศ์ ภูลายยาว (หัวหน้าภาควิชาดุริยางคศาสตร์)' : 'ดร.พิษณุ บุญศรีอนันต์ (หัวหน้าภาควิชาดนตรีศึกษา)';
                        $head_title = $is_duriyang ? '3. หัวหน้าภาควิชาดุริยางคศาสตร์' : '3. หัวหน้าภาควิชาดนตรีศึกษา';

                        $steps = [
                            ['status' => 'pending_instructor', 'label' => '1. อาจารย์ผู้สอน / ประสานงาน', 'name' => !empty($inst_full) ? $inst_full : '-', 'comment' => $req['instructor_comment'], 'approved_at' => $req['instructor_approved_at'] ?? null],
                            ['status' => 'pending_advisor', 'label' => '2. อาจารย์ที่ปรึกษา', 'name' => !empty($adv_full) ? $adv_full : '-', 'comment' => $req['advisor_comment'], 'approved_at' => $req['advisor_approved_at'] ?? null],
                            ['status' => 'pending_head', 'label' => $head_title, 'name' => $head_name, 'comment' => $req['head_comment'], 'approved_at' => $req['head_approved_at'] ?? null],
                            ['status' => 'pending_deputy', 'label' => '4. รองคณบดีฝ่ายวิชาการ', 'name' => 'ผศ.ดร.วีรยุทธ สีคุณหลิ่ว', 'comment' => $req['deputy_comment'], 'approved_at' => $req['deputy_approved_at'] ?? null],
                            ['status' => 'pending_academic', 'label' => '5. งานบริการการศึกษา', 'name' => 'นางสาวธรัญญา ปูธิรัตน์', 'comment' => $req['academic_comment'], 'approved_at' => $req['academic_approved_at'] ?? null]
                        ];

                        foreach ($steps as $idx => $step): 
                            $class = getStatusClass($step['status'], $current_status);
                            $icon = strpos($class, 'text-success') !== false ? 'fa-check-circle text-success' : (strpos($class, 'text-warning') !== false ? 'fa-spinner fa-spin text-warning' : 'fa-circle text-muted');
                            if ($current_status == 'rejected' && !empty($step['comment'])) {
                                $icon = 'fa-times-circle text-danger';
                                $class = 'text-danger fw-bold';
                            }
                        ?>
                        <div class="d-flex mb-3 position-relative">
                            <div class="me-3 text-center" style="width: 24px;">
                                <i class="fa-solid <?= $icon; ?> fs-5 bg-white"></i>
                                <?php if($idx < 4): ?><div class="bg-light mx-auto" style="width: 2px; height: 100%; position: absolute; top: 20px; left: 11px; z-index: 0;"></div><?php endif; ?>
                            </div>
                            <div class="pb-3 w-100">
                                <h6 class="mb-0 small <?= $class; ?>"><?= $step['label']; ?></h6>
                                <?php if($step['name'] != '-'): ?><div class="extra-small text-muted mb-1"><?= htmlspecialchars($step['name']); ?></div><?php endif; ?>
                                
                                <?php if(!empty($step['approved_at'])): ?>
                                    <div class="extra-small text-muted mb-1">
                                        <i class="fa-regular fa-clock me-1 text-secondary"></i>
                                        <?php 
                                        $ts_step = strtotime($step['approved_at']);
                                        $y_step = (int)date('Y', $ts_step);
                                        if ($y_step < 2500) $y_step += 543;
                                        echo ($current_status == 'rejected' && !empty($step['comment'])) ? 'ไม่อนุมัติเมื่อ: ' : 'พิจารณาเมื่อ: ';
                                        echo date('d/m/', $ts_step) . $y_step . ' ' . date('H:i', $ts_step) . ' น.';
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <?php if(!empty($step['comment'])): ?>
                                    <div class="bg-light p-2 rounded small text-dark mt-1 border" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-comment-dots text-purple me-1"></i> <?= nl2br(htmlspecialchars($step['comment'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                    </div>

                    <?php if ($current_status == 'rejected'): ?>
                        <div class="alert alert-danger mt-3 mb-0 small rounded-3 text-center border-0">
                            <i class="fa-solid fa-circle-xmark me-1"></i> คำร้องนี้ไม่ได้รับการอนุมัติ (Rejected)
                        </div>
                    <?php elseif ($current_status == 'approved'): ?>
                        <div class="alert alert-success mt-3 mb-0 small rounded-3 text-center border-0">
                            <i class="fa-solid fa-check-double me-1"></i> คำร้องนี้ได้รับการอนุมัติเสร็จสมบูรณ์แล้ว (Approved)
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple) !important; }
    .bg-purple { background-color: var(--msu-purple) !important; color: white !important; }
    .btn-purple { background-color: var(--msu-purple) !important; color: white !important; border: none; }
    .btn-purple:hover { background-color: #2a004d !important; color: white !important; }
    .btn-outline-purple { color: var(--msu-purple) !important; border-color: var(--msu-purple) !important; }
    .btn-outline-purple:hover { background-color: var(--msu-purple) !important; color: white !important; }
    .status-stamp {
        position: absolute;
        right: 35px;
        top: 20px;
        border: 3.5px solid #16a34a;
        color: #16a34a;
        padding: 6px 18px;
        font-size: 1.2rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 2px;
        border-radius: 8px;
        transform: rotate(-10deg);
        opacity: 0.88;
        pointer-events: none;
        z-index: 10;
        box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        background: rgba(255, 255, 255, 0.95);
    }
    .status-stamp.stamp-rejected {
        border-color: #dc2626;
        color: #dc2626;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
    }
    .status-stamp.stamp-pending {
        border-color: #f59e0b;
        color: #d97706;
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }

    .extra-small { font-size: 0.75rem; }
    .timeline-simple i { position: relative; z-index: 1; }
</style>

<?php require_once 'includes/footer.php'; ?>
