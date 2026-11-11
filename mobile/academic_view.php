<?php
require_once 'auth_check.php';

$request_id = intval($_GET['id'] ?? 0);
if ($request_id <= 0) {
    header("Location: approvals.php#academic");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles) || ($role === 'admin');
$is_deputy = in_array('รองคณบดีฝ่ายวิชาการ', $user_roles);
$is_academic = count(array_intersect(['งานวิชาการ', 'งานบริการการศึกษา'], $user_roles)) > 0;
$is_head = false;
foreach ($user_roles as $r) {
    if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
        $is_head = true;
        break;
    }
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

// ดึงข้อมูลคำร้อง
try {
    $stmt = $pdo->prepare("
        SELECT r.*,
               s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
               s.major AS std_major, s.year_level AS std_year, s.email AS std_email, s.phone_number AS std_phone,
               s.profile_picture,
               inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname,
               adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_lname
        FROM course_requests r
        LEFT JOIN students s ON r.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users inst ON r.instructor_id COLLATE utf8mb4_unicode_ci = inst.user_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users adv ON r.advisor_id COLLATE utf8mb4_unicode_ci = adv.user_id COLLATE utf8mb4_unicode_ci
        WHERE r.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        header("Location: approvals.php#academic");
        exit();
    }
} catch (PDOException $e) {
    header("Location: approvals.php#academic");
    exit();
}

// ตรวจสอบสิทธิ์การอนุมัติใน Step ปัจจุบัน
$cur_status = $req['status'];
$can_approve = false;
$role_title = '';
$next_status = '';
$comment_col = '';
$time_col = '';

if ($cur_status === 'pending_instructor' && ($req['instructor_id'] === $user_id || $is_admin)) {
    $can_approve = true;
    $role_title = 'อาจารย์ผู้สอน';
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
    } elseif ($is_duriyang && in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles)) {
        $can_approve_head = true;
    } elseif (!$is_duriyang && in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles)) {
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

$msg = '';
$msg_type = '';

// บันทึกการอนุมัติ / ปฏิเสธ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $can_approve) {
    $action = $_POST['action'];
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

        $msg = ($action === 'approve') ? "อนุมัติคำร้องเรียบร้อยแล้ว" : "ปฏิเสธคำร้องเรียบร้อยแล้ว";
        $msg_type = ($action === 'approve') ? "success" : "danger";
        
        // Refresh request data
        $stmt->execute([$request_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        $cur_status = $req['status'];
        $can_approve = false;
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// แปลงสถานะเป็น Badge
function getStepStatus($step_status) {
    switch ($step_status) {
        case 'pending_instructor': return ['label' => 'รออาจารย์ผู้สอน', 'color' => 'warning'];
        case 'pending_advisor': return ['label' => 'รออาจารย์ที่ปรึกษา', 'color' => 'warning'];
        case 'pending_head': return ['label' => 'รอหัวหน้าภาควิชา', 'color' => 'warning'];
        case 'pending_deputy': return ['label' => 'รอรองคณบดีฝ่ายวิชาการ', 'color' => 'warning'];
        case 'pending_academic': return ['label' => 'รอยืนยันเปิดวิชา (งานบริการการศึกษา)', 'color' => 'info'];
        case 'approved': return ['label' => 'อนุมัติเรียบร้อยแล้ว', 'color' => 'success'];
        case 'rejected': return ['label' => 'ไม่อนุมัติ / ยกเลิก', 'color' => 'danger'];
        default: return ['label' => 'รอดำเนินการ', 'color' => 'secondary'];
    }
}

$step_info = getStepStatus($cur_status);
$is_open = ($req['request_type'] === 'open_course');

require_once 'includes/header.php';
?>

<div class="page-container pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3 pt-2">
        <div class="d-flex align-items-center gap-2">
            <a href="approvals.php#academic" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark"><?= $is_open ? 'คำร้องขอเปิดรายวิชา' : 'คำร้องขอสำรองที่นั่ง' ?></h5>
                <small class="text-muted">รหัสคำร้อง #CR-<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT) ?></small>
            </div>
        </div>
        <span class="badge bg-<?= $step_info['color'] ?> px-2 py-1 rounded-pill">
            <?= $step_info['label'] ?>
        </span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Course Info Card -->
    <div class="bento-box p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1">
                <?= $is_open ? 'ขอเปิดรายวิชา' : 'ขอสำรองที่นั่ง' ?>
            </span>
            <span class="extra-small text-muted">ภาคเรียน: <?= htmlspecialchars($req['semester'] ?: '-') ?></span>
        </div>

        <h5 class="fw-bold text-dark mb-1">
            <span class="badge bg-dark bg-opacity-10 text-dark me-1"><?= htmlspecialchars($req['course_code']) ?></span>
            <?= htmlspecialchars($req['course_name']) ?>
        </h5>

        <?php if (!empty($req['section'])): ?>
            <div class="text-muted small mb-2">
                <i class="fa-solid fa-layer-group me-1 text-purple"></i> กลุ่มเรียน (Section): <strong><?= htmlspecialchars($req['section']) ?></strong>
            </div>
        <?php endif; ?>

        <div class="p-3 bg-light rounded-3 border mt-3">
            <label class="extra-small fw-bold text-purple text-uppercase mb-1 d-block">
                <i class="fa-solid fa-circle-info me-1"></i> เหตุผลความจำเป็นของนิสิต
            </label>
            <p class="small text-dark mb-0" style="line-height: 1.5; white-space: pre-wrap;">
                <?= htmlspecialchars($req['reason'] ?: 'ไม่ระบุเหตุผล') ?>
            </p>
        </div>
    </div>

    <!-- Student Info Card -->
    <div class="bento-box p-3 mb-3">
        <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
            <i class="fa-solid fa-graduation-cap text-purple"></i> ข้อมูลผู้ยื่นคำร้อง (นิสิต)
        </h6>
        <div class="d-flex align-items-center gap-3 p-2 rounded-3 bg-light border">
            <div class="avatar-circle" style="width: 48px; height: 48px; background: rgba(57, 4, 143, 0.1); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-solid fa-user-graduate fa-lg"></i>
            </div>
            <div>
                <h6 class="fw-bold text-dark mb-0">
                    <?= htmlspecialchars(($req['std_prefix'] ?? '') . ($req['std_fname'] ?? '') . ' ' . ($req['std_lname'] ?? '')) ?>
                </h6>
                <div class="extra-small text-muted">
                    รหัสนิสิต: <?= htmlspecialchars($req['student_id']) ?> | ชั้นปี: <?= htmlspecialchars($req['std_year'] ?: '-') ?>
                </div>
                <div class="extra-small text-muted">
                    สาขา: <?= htmlspecialchars($req['std_major'] ?: '-') ?>
                </div>
                <?php if (!empty($req['std_phone'])): ?>
                    <div class="extra-small text-purple mt-1">
                        <i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($req['std_phone']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Step Progress Timeline -->
    <div class="bento-box p-3 mb-3">
        <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-timeline text-purple"></i> ขั้นตอนการพิจารณาอนุมัติ
        </h6>

        <div class="d-flex flex-column gap-3">
            <!-- Step 1: Instructor -->
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: <?= !empty($req['instructor_approved_at']) ? '#10b981' : ($cur_status === 'pending_instructor' ? '#f59e0b' : '#cbd5e1') ?>;">
                    <i class="fa-solid <?= !empty($req['instructor_approved_at']) ? 'fa-check' : 'fa-chalkboard-user' ?> extra-small"></i>
                </div>
                <div class="flex-grow-1 border-bottom pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">1. อาจารย์ผู้สอน</span>
                        <span class="extra-small text-muted"><?= !empty($req['instructor_approved_at']) ? date('d/m/Y H:i', strtotime($req['instructor_approved_at'])) : ($cur_status === 'pending_instructor' ? 'กำลังรอพิจารณา' : 'รอดำเนินการ') ?></span>
                    </div>
                    <div class="extra-small text-muted">
                        <?= htmlspecialchars(($req['inst_prefix'] ?? '') . ($req['inst_fname'] ?? '') . ' ' . ($req['inst_lname'] ?? '')) ?: 'ระบุในคำร้อง' ?>
                    </div>
                    <?php if (!empty($req['instructor_comment'])): ?>
                        <div class="mt-1 p-2 rounded bg-light extra-small text-secondary">
                            ความเห็น: <?= htmlspecialchars($req['instructor_comment']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 2: Advisor -->
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: <?= !empty($req['advisor_approved_at']) ? '#10b981' : ($cur_status === 'pending_advisor' ? '#f59e0b' : '#cbd5e1') ?>;">
                    <i class="fa-solid <?= !empty($req['advisor_approved_at']) ? 'fa-check' : 'fa-user-tie' ?> extra-small"></i>
                </div>
                <div class="flex-grow-1 border-bottom pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">2. อาจารย์ที่ปรึกษา</span>
                        <span class="extra-small text-muted"><?= !empty($req['advisor_approved_at']) ? date('d/m/Y H:i', strtotime($req['advisor_approved_at'])) : ($cur_status === 'pending_advisor' ? 'กำลังรอพิจารณา' : 'รอดำเนินการ') ?></span>
                    </div>
                    <div class="extra-small text-muted">
                        <?= htmlspecialchars(($req['adv_prefix'] ?? '') . ($req['adv_fname'] ?? '') . ' ' . ($req['adv_lname'] ?? '')) ?: 'ระบุในคำร้อง' ?>
                    </div>
                    <?php if (!empty($req['advisor_comment'])): ?>
                        <div class="mt-1 p-2 rounded bg-light extra-small text-secondary">
                            ความเห็น: <?= htmlspecialchars($req['advisor_comment']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 3: Department Head -->
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: <?= !empty($req['head_approved_at']) ? '#10b981' : ($cur_status === 'pending_head' ? '#f59e0b' : '#cbd5e1') ?>;">
                    <i class="fa-solid <?= !empty($req['head_approved_at']) ? 'fa-check' : 'fa-user-shield' ?> extra-small"></i>
                </div>
                <div class="flex-grow-1 border-bottom pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">3. หัวหน้าภาควิชา</span>
                        <span class="extra-small text-muted"><?= !empty($req['head_approved_at']) ? date('d/m/Y H:i', strtotime($req['head_approved_at'])) : ($cur_status === 'pending_head' ? 'กำลังรอพิจารณา' : 'รอดำเนินการ') ?></span>
                    </div>
                    <?php if (!empty($req['head_comment'])): ?>
                        <div class="mt-1 p-2 rounded bg-light extra-small text-secondary">
                            ความเห็น: <?= htmlspecialchars($req['head_comment']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 4: Deputy Dean -->
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: <?= !empty($req['deputy_approved_at']) ? '#10b981' : ($cur_status === 'pending_deputy' ? '#f59e0b' : '#cbd5e1') ?>;">
                    <i class="fa-solid <?= !empty($req['deputy_approved_at']) ? 'fa-check' : 'fa-award' ?> extra-small"></i>
                </div>
                <div class="flex-grow-1 border-bottom pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">4. รองคณบดีฝ่ายวิชาการ</span>
                        <span class="extra-small text-muted"><?= !empty($req['deputy_approved_at']) ? date('d/m/Y H:i', strtotime($req['deputy_approved_at'])) : ($cur_status === 'pending_deputy' ? 'กำลังรอพิจารณา' : 'รอดำเนินการ') ?></span>
                    </div>
                    <?php if (!empty($req['deputy_comment'])): ?>
                        <div class="mt-1 p-2 rounded bg-light extra-small text-secondary">
                            ความเห็น: <?= htmlspecialchars($req['deputy_comment']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 5: Academic Services -->
            <div class="d-flex gap-3 align-items-start">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: <?= !empty($req['academic_approved_at']) ? '#10b981' : ($cur_status === 'pending_academic' ? '#f59e0b' : '#cbd5e1') ?>;">
                    <i class="fa-solid <?= !empty($req['academic_approved_at']) ? 'fa-check' : 'fa-building-columns' ?> extra-small"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">5. งานบริการการศึกษา (เปิดระบบ)</span>
                        <span class="extra-small text-muted"><?= !empty($req['academic_approved_at']) ? date('d/m/Y H:i', strtotime($req['academic_approved_at'])) : ($cur_status === 'pending_academic' ? 'กำลังรอพิจารณา' : 'รอดำเนินการ') ?></span>
                    </div>
                    <?php if (!empty($req['academic_comment'])): ?>
                        <div class="mt-1 p-2 rounded bg-light extra-small text-secondary">
                            ความเห็น: <?= htmlspecialchars($req['academic_comment']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Action Panel (Only if user has authority to approve this step) -->
    <?php if ($can_approve): ?>
        <div class="bento-box p-3 mb-3 border-purple shadow-sm" style="background: rgba(57, 4, 143, 0.03);">
            <h6 class="fw-bold text-purple mb-2">
                <i class="fa-solid fa-signature me-1"></i> บันทึกผลการพิจารณา (ในฐานะ <?= htmlspecialchars($role_title) ?>)
            </h6>
            <form method="POST" action="academic_view.php?id=<?= $request_id ?>">
                <div class="mb-3">
                    <label class="form-label extra-small fw-bold text-muted mb-1">ความเห็นหรือหมายเหตุ (ถ้ามี)</label>
                    <textarea name="comment" class="form-control rounded-3" rows="2" placeholder="ระบุข้อคิดเห็นหรือเหตุผลประกอบการพิจารณา..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="approve" class="btn btn-primary flex-fill rounded-pill py-2 fw-bold" onclick="return confirm('ยืนยันการอนุมัติคำร้องนี้?');">
                        <i class="fa-solid fa-circle-check me-1"></i> อนุมัติคำร้อง
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger flex-fill rounded-pill py-2 fw-bold" onclick="return confirm('ยืนยันการไม่อนุมัติ / ตีกลับคำร้องนี้?');">
                        <i class="fa-solid fa-circle-xmark me-1"></i> ไม่อนุมัติ
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
