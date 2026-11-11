<?php
/**
 * MSU Music Connect - Executive Command Center (ERP 2.0)
 * ศูนย์ควบคุมและบริหารจัดการกลาง วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'auth/session_check.php';
require_once 'config/db.php';

// 1. ตรวจสอบสิทธิ์ผู้บริหาร
$exec_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'คณบดี', 'รักษาการคณบดี', 'งานแผน', 'งานวิชาการ', 'งานบริการการศึกษา'];
if (!hasRole($exec_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: สำหรับผู้บริหารและผู้ได้รับมอบหมายเท่านั้น'); window.location.href='index.php?view=personal';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$current_month = date('Y-m');

$user_roles_array = $_SESSION['roles'] ?? [];

// --- Flags สำหรับระบบทั่วไป (สอดคล้องกับ requests_list.php 100%) ---
$is_admin = in_array('admin', $user_roles_array) ? 1 : 0;
$is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0) ? 1 : 0;

// --- Flags สำหรับระบบคำร้องนิสิต (Course Requests) ---
$is_course_head = (count(array_intersect(['หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'หัวหน้าภาควิชา'], $user_roles_array)) > 0) ? 1 : 0;
$is_course_deputy = in_array('รองคณบดีฝ่ายวิชาการ', $user_roles_array) ? 1 : 0;
$is_course_academic = (count(array_intersect(['งานบริการการศึกษา', 'งานวิชาการ'], $user_roles_array)) > 0) ? 1 : 0;

// --- Flags สำหรับระบบใบลา (Leave Requests) ---
$my_dept_id = 0;
try {
    $stmt_my_dept = $pdo->prepare("SELECT dept_id FROM users WHERE user_id = ?");
    $stmt_my_dept->execute([$user_id]);
    $my_dept_id = (int)$stmt_my_dept->fetchColumn();
} catch(Exception $e) {}

$is_leave_step1 = (count(array_intersect([
    'งานบุคคล', 'นักวิชาการพัสดุ', 'นักวิชาการศึกษา', 'บุคลากร', 
    'นักวิชาการเงินและบัญชี', 'เจ้าหน้าที่บริหารงานทั่วไป', 
    'นักวิเคราะห์นโยบายและแผน', 'นักวิชาการโสตทัศนศึกษา'
], $user_roles_array)) > 0) ? 1 : 0;

$my_head_dept_ids = [];
if (in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles_array)) $my_head_dept_ids[] = 12;
if (in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles_array)) $my_head_dept_ids[] = 13;
foreach ($user_roles_array as $r) {
    if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
        if ($my_dept_id > 0 && !in_array($my_dept_id, $my_head_dept_ids)) {
            $my_head_dept_ids[] = $my_dept_id;
        }
    }
}
$head_depts_sql = !empty($my_head_dept_ids) ? "OR (l.current_step = 2 AND u.dept_id IN (" . implode(',', $my_head_dept_ids) . "))" : "";

$is_leave_step2_staff = (count(array_intersect(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'], $user_roles_array)) > 0) ? 1 : 0;
$is_leave_step3 = in_array('รองคณบดีฝ่ายบริหาร', $user_roles_array) ? 1 : 0;
$is_leave_step4 = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0) ? 1 : 0;

// --- Flags สำหรับระบบเบิกพัสดุ (Supply Requests) ---
$is_supply_head = in_array('หัวหน้าสำนักงาน', $user_roles_array) ? 1 : 0;
$is_supply_issuer = in_array('งานพัสดุ', $user_roles_array) ? 1 : 0;

// --- Flags สำหรับระบบจองห้อง/ซ่อมบำรุง ---
$is_building_staff = (in_array('งานอาคารสถานที่', $user_roles_array) || in_array('โสตทัศนูปกรณ์', $user_roles_array)) ? 1 : 0;

// --- Flags สำหรับระบบไปราชการ/อบรม (5 ขั้นตอนจริง) ---
$is_training_office_head = (count(array_intersect(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'], $user_roles_array)) > 0) ? 1 : 0;
$is_training_finance = in_array('งานการเงิน', $user_roles_array) ? 1 : 0;
$is_training_deputy_admin = in_array('รองคณบดีฝ่ายบริหาร', $user_roles_array) ? 1 : 0;
$is_training_dean = $is_dean;
$training_head_sql = !empty($my_head_dept_ids) ? "OR (tr.current_step IN (1, 3) AND u.dept_id IN (" . implode(',', $my_head_dept_ids) . "))" : "";

// =========================================================
// 0. ฟังก์ชันตรวจสอบสิทธิ์ตาม Workflow จริงของแต่ละโมดูล & ประมวลผลคำสั่งด่วน
// =========================================================
if (!function_exists('canApproveTrainingStepExec')) {
    function canApproveTrainingStepExec($step, $req_dept_id = 0) {
        if (hasRole(['admin'])) return true;
        $step = (int)$step;
        $req_dept_id = (int)$req_dept_id;
        if ($step === 1 || $step === 3) {
            if ($req_dept_id === 12 && hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) return true;
            if ($req_dept_id === 13 && hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) return true;
            if (!in_array($req_dept_id, [12, 13], true) && hasRole(['หัวหน้าสำนักงาน'])) return true;
            // กรณีหัวหน้าภาคทั่วไป
            if (hasRole(['หัวหน้าภาควิชา', 'หัวหน้าสำนักงาน'])) return true;
            return false;
        }
        if ($step === 2) {
            return hasRole(['งานการเงิน', 'งานการเงินและพัสดุ']);
        }
        if ($step === 4) {
            return hasRole(['รองคณบดีฝ่ายบริหาร']);
        }
        if ($step === 5) {
            return hasRole(['คณบดี', 'รักษาการคณบดี']);
        }
        return false;
    }
}

if (!function_exists('canApproveLeaveStepExec')) {
    function canApproveLeaveStepExec($step, $req_dept_id = 0, $final_signer_id = 0, $current_user_id = 0) {
        if (hasRole(['admin'])) return true;
        $step = (int)$step;
        $req_dept_id = (int)$req_dept_id;
        if ($step === 1) {
            return hasRole(['งานบุคคล']);
        }
        if ($step === 2) {
            if ($req_dept_id === 12 && hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) return true;
            if ($req_dept_id === 13 && hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) return true;
            if (!in_array($req_dept_id, [12, 13], true) && hasRole(['หัวหน้าสำนักงาน'])) return true;
            if (hasRole(['หัวหน้าภาควิชา', 'หัวหน้าสำนักงาน'])) return true;
            return false;
        }
        if ($step === 3) {
            return hasRole(['รองคณบดีฝ่ายบริหาร']);
        }
        if ($step === 4) {
            if ($final_signer_id > 0 && (int)$final_signer_id === (int)$current_user_id) return true;
            return hasRole(['คณบดี', 'รักษาการคณบดี']);
        }
        return false;
    }
}

if (!function_exists('canApproveCourseStepExec')) {
    function canApproveCourseStepExec($status, $instructor_id = 0, $advisor_id = 0, $current_user_id = 0, $student_major = '') {
        if (hasRole(['admin'])) return true;
        if ($status === 'pending_instructor') {
            return ((int)$instructor_id === (int)$current_user_id);
        }
        if ($status === 'pending_advisor') {
            return ((int)$advisor_id === (int)$current_user_id);
        }
        if ($status === 'pending_head') {
            $is_duriyang = (mb_strpos($student_major, 'ดุริยางค') !== false);
            if ($is_duriyang) {
                return hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตรดุริยางคศาสตรบัณฑิต', 'หัวหน้าภาควิชา', 'ประธานหลักสูตร']) || ($current_user_id === '6b8fba36220e1');
            } else {
                return hasRole(['หัวหน้าภาควิชาดนตรีศึกษา', 'ประธานหลักสูตรการศึกษาบัณฑิต', 'หัวหน้าภาควิชา', 'ประธานหลักสูตร']) || ($current_user_id === '6b8f3458220e1');
            }
        }
        if ($status === 'pending_deputy') {
            return hasRole(['รองคณบดีฝ่ายวิชาการ']);
        }
        if ($status === 'pending_academic') {
            return hasRole(['งานวิชาการ', 'งานบริการการศึกษา']);
        }
        return false;
    }
}

if (!function_exists('canApproveSupplyHeadExec')) {
    function canApproveSupplyHeadExec($head_approver_id = 0, $current_user_id = 0) {
        if (hasRole(['admin', 'หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'])) return true;
        if ($head_approver_id > 0 && (int)$head_approver_id === (int)$current_user_id) return true;
        return false;
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

$drawer_flash_msg = '';
$drawer_flash_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exec_quick_action'])) {
    $q_action  = $_POST['exec_quick_action']; // 'approve' or 'return'
    $q_module  = $_POST['module_key'] ?? '';
    $q_id      = (int)($_POST['record_id'] ?? 0);
    $q_comment = trim($_POST['exec_comment'] ?? '');

    try {
        if ($q_id > 0) {
            if ($q_module === 'training') {
                // ไปราชการ/อบรม: Workflow 5 ขั้นตอน (1: หน.สนง/หน.ภาค -> 2: การเงิน -> 3: หน.สนง/หน.ภาค -> 4: รองบริหาร -> 5: คณบดี)
                $stmt_cur = $pdo->prepare("
                    SELECT tr.current_step, tr.status, u.dept_id 
                    FROM training_reports tr 
                    JOIN users u ON tr.user_id = u.user_id 
                    WHERE tr.report_id = ?
                ");
                $stmt_cur->execute([$q_id]);
                $tr_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
                if ($tr_cur && $tr_cur['status'] === 'pending') {
                    $c_step = max(1, min(5, (int)$tr_cur['current_step']));
                    if (canApproveTrainingStepExec($c_step, (int)$tr_cur['dept_id'])) {
                        if ($q_action === 'approve') {
                            $exec_is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $_SESSION['roles'] ?? [])) > 0 || !empty($is_admin));
                            if ($c_step >= 5 || ($exec_is_dean && $c_step >= 4)) {
                                $sup_id = ((int)$tr_cur['dept_id'] == 12 || (int)$tr_cur['dept_id'] == 13) ? '6b8f3458220e1' : '34ee8171';
                                $stmt_up = $pdo->prepare("UPDATE training_reports SET 
                                    status = 'approved', 
                                    current_step = 5, 
                                    return_comment = NULL, 
                                    step1_status = 'approved', step1_by = COALESCE(NULLIF(step1_by, ''), ?), step1_date = COALESCE(step1_date, NOW()),
                                    step2_status = 'approved', step2_by = COALESCE(NULLIF(step2_by, ''), '6b900dce220e1'), step2_date = COALESCE(step2_date, NOW()),
                                    step3_status = 'approved', step3_by = COALESCE(NULLIF(step3_by, ''), ?), step3_date = COALESCE(step3_date, NOW()),
                                    step4_status = 'approved', step4_by = COALESCE(NULLIF(step4_by, ''), '86580da6'), step4_date = COALESCE(step4_date, NOW()),
                                    step5_status = 'approved', step5_by = ?, step5_date = NOW() 
                                    WHERE report_id = ?
                                ");
                                $stmt_up->execute([$sup_id, $sup_id, $user_id, $q_id]);
                                $drawer_flash_msg = 'อนุมัติคำขอไปราชการ/อบรม (TR-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') อนุมัติสมบูรณ์เรียบร้อยแล้ว';
                            } else {
                                $next_step = $c_step + 1;
                                $stmt_up = $pdo->prepare("UPDATE training_reports SET current_step = ?, step{$c_step}_status = 'approved', step{$c_step}_by = ?, step{$c_step}_date = NOW(), return_comment = NULL WHERE report_id = ?");
                                $stmt_up->execute([$next_step, $user_id, $q_id]);
                                $drawer_flash_msg = 'เห็นชอบคำขอไปราชการ/อบรม (TR-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') ส่งต่อขั้นตอนที่ ' . $next_step . ' เรียบร้อยแล้ว';
                            }
                        } else {
                            if ($c_step === 1) {
                                $stmt_up = $pdo->prepare("UPDATE training_reports SET status = 'returned', return_comment = ?, return_step = 1 WHERE report_id = ?");
                                $stmt_up->execute([$q_comment ?: 'ส่งกลับแก้ไขให้กับผู้ยื่นคำขอ', $q_id]);
                            } else {
                                $prev_step = $c_step - 1;
                                $stmt_up = $pdo->prepare("UPDATE training_reports SET current_step = ?, step{$prev_step}_status = 'pending', return_comment = ?, return_step = ? WHERE report_id = ?");
                                $stmt_up->execute([$prev_step, $q_comment ?: 'ส่งกลับแก้ไขขั้นตอนก่อนหน้า', $c_step, $q_id]);
                            }
                            $drawer_flash_msg = 'ส่งกลับแก้ไขคำขอไปราชการ/อบรมเรียบร้อยแล้ว';
                            $drawer_flash_type = 'warning';
                        }
                    } else {
                        $drawer_flash_msg = 'ไม่สามารถดำเนินการได้: รายการนี้อยู่ในขั้นตอนที่ ' . $c_step . ' ซึ่งอยู่นอกเหนือสิทธิ์การอนุมัติของคุณ';
                        $drawer_flash_type = 'warning';
                    }
                }
            } elseif ($q_module === 'leave') {
                // การลาอิเล็กทรอนิกส์: Workflow 4 ขั้นตอน (1: งานบุคคล -> 2: หน.สนง/หน.ภาค -> 3: รองบริหาร -> 4: คณบดี)
                $stmt_cur = $pdo->prepare("
                    SELECT l.current_step, l.status, l.final_signer_id, u.dept_id 
                    FROM leave_requests l 
                    JOIN users u ON l.user_id = u.user_id 
                    WHERE l.leave_id = ?
                ");
                $stmt_cur->execute([$q_id]);
                $lv_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
                if ($lv_cur && $lv_cur['status'] === 'pending') {
                    $c_step = max(1, min(4, (int)$lv_cur['current_step']));
                    if (canApproveLeaveStepExec($c_step, (int)$lv_cur['dept_id'], (int)($lv_cur['final_signer_id'] ?? 0), $user_id)) {
                        if ($q_action === 'approve') {
                            // 1. บันทึกผลการพิจารณาลงใน leave_approvals สำหรับขั้นตอนนี้
                            $check_app = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = ?");
                            $check_app->execute([$q_id, $c_step]);
                            $existing_app_id = $check_app->fetchColumn();
                            if ($existing_app_id) {
                                $pdo->prepare("UPDATE leave_approvals SET approver_id = ?, approve_status = 'approved', comment = ?, updated_at = NOW() WHERE approval_id = ?")
                                    ->execute([$user_id, $q_comment ?: 'เห็นชอบผ่าน Executive Hub', $existing_app_id]);
                            } else {
                                $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, ?, 'approved', ?, NOW())")
                                    ->execute([$q_id, $user_id, $c_step, $q_comment ?: 'เห็นชอบผ่าน Executive Hub']);
                            }

                            // 2. ถ้าเป็นขั้นตอนที่ 4 หรือผู้มีสิทธิ์ระดับคณบดี/Admin อนุมัติขั้นที่ 3-4 -> อนุมัติสมบูรณ์
                            $exec_is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $_SESSION['roles'] ?? [])) > 0 || !empty($is_admin));

                            if ($c_step >= 4 || ($exec_is_dean && $c_step >= 3)) {
                                if ($c_step == 3 && $exec_is_dean) {
                                    $check_app4 = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = 4");
                                    $check_app4->execute([$q_id]);
                                    if (!$check_app4->fetchColumn()) {
                                        $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, 4, 'approved', ?, NOW())")
                                            ->execute([$q_id, $user_id, $q_comment ?: 'อนุมัติผ่าน Executive Hub (คณบดี/รักษาการ)']);
                                    }
                                }
                                $stmt_up = $pdo->prepare("UPDATE leave_requests SET status = 'approved', current_step = 4, updated_at = NOW() WHERE leave_id = ?");
                                $stmt_up->execute([$q_id]);
                                $drawer_flash_msg = 'อนุมัติใบลาอิเล็กทรอนิกส์ (LV-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') อนุมัติสมบูรณ์เรียบร้อยแล้ว';
                            } else {
                                $next_step = $c_step + 1;
                                $stmt_up = $pdo->prepare("UPDATE leave_requests SET current_step = ?, updated_at = NOW() WHERE leave_id = ?");
                                $stmt_up->execute([$next_step, $q_id]);
                                $drawer_flash_msg = 'ผ่านการพิจารณาใบลา (LV-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') ส่งต่อขั้นตอนที่ ' . $next_step . ' เรียบร้อยแล้ว';
                            }
                        } else {
                            $check_app = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = ?");
                            $check_app->execute([$q_id, $c_step]);
                            $existing_app_id = $check_app->fetchColumn();
                            if ($existing_app_id) {
                                $pdo->prepare("UPDATE leave_approvals SET approver_id = ?, approve_status = 'rejected', comment = ?, updated_at = NOW() WHERE approval_id = ?")
                                    ->execute([$user_id, $q_comment ?: 'ไม่อนุมัติผ่าน Executive Hub', $existing_app_id]);
                            } else {
                                $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, ?, 'rejected', ?, NOW())")
                                    ->execute([$q_id, $user_id, $c_step, $q_comment ?: 'ไม่อนุมัติผ่าน Executive Hub']);
                            }
                            $stmt_up = $pdo->prepare("UPDATE leave_requests SET status = 'rejected', updated_at = NOW() WHERE leave_id = ?");
                            $stmt_up->execute([$q_id]);
                            $drawer_flash_msg = 'ไม่อนุมัติ/ส่งกลับคำขอใบลาเรียบร้อยแล้ว';
                            $drawer_flash_type = 'warning';
                        }
                    } else {
                        $drawer_flash_msg = 'ไม่สามารถดำเนินการได้: ใบลานี้อยู่ในขั้นตอนที่ ' . $c_step . ' ซึ่งอยู่นอกเหนือสิทธิ์การพิจารณาของคุณ';
                        $drawer_flash_type = 'warning';
                    }
                }
            } elseif ($q_module === 'academic') {
                // คำร้องวิชาการ: Workflow 5 ขั้นตอนตามลำดับจริง (pending_instructor -> pending_advisor -> pending_head -> pending_deputy -> pending_academic -> approved)
                $stmt_cur = $pdo->prepare("SELECT cr.status, cr.instructor_id, cr.advisor_id, s.major FROM course_requests cr LEFT JOIN students s ON cr.student_id = s.student_id WHERE cr.request_id = ?");
                $stmt_cur->execute([$q_id]);
                $cr_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
                if ($cr_cur && strpos((string)$cr_cur['status'], 'pending_') === 0) {
                    $cur_st = (string)$cr_cur['status'];
                    if (canApproveCourseStepExec($cur_st, (int)$cr_cur['instructor_id'], (int)$cr_cur['advisor_id'], $user_id, $cr_cur['major'] ?? '')) {
                        $next_map = [
                            'pending_instructor' => ['next' => 'pending_advisor',  'col' => 'instructor_comment'],
                            'pending_advisor'    => ['next' => 'pending_head',     'col' => 'advisor_comment'],
                            'pending_head'       => ['next' => 'pending_deputy',   'col' => 'head_comment'],
                            'pending_deputy'     => ['next' => 'pending_academic', 'col' => 'deputy_comment'],
                            'pending_academic'   => ['next' => 'approved',         'col' => 'academic_comment']
                        ];
                        $step_cfg = $next_map[$cur_st] ?? ['next' => 'approved', 'col' => 'deputy_comment'];
                        $new_st = $step_cfg['next'];
                        $col_name = $step_cfg['col'];
                        $time_col_map = [
                            'pending_instructor' => 'instructor_approved_at',
                            'pending_advisor'    => 'advisor_approved_at',
                            'pending_head'       => 'head_approved_at',
                            'pending_deputy'     => 'deputy_approved_at',
                            'pending_academic'   => 'academic_approved_at'
                        ];
                        $valid_cols = ensureCourseRequestCols($pdo);
                        $target_time_col = $time_col_map[$cur_st] ?? '';
                        $time_col = in_array($target_time_col, $valid_cols, true) ? $target_time_col : '';
                        $time_sql = !empty($time_col) ? ", `{$time_col}` = NOW()" : "";

                        if ($q_action === 'approve') {
                            $stmt_up = $pdo->prepare("UPDATE course_requests SET status = ?, {$col_name} = COALESCE(NULLIF(?, ''), {$col_name}) {$time_sql}, updated_at = NOW() WHERE request_id = ?");
                            $stmt_up->execute([$new_st, $q_comment, $q_id]);
                            $drawer_flash_msg = ($new_st === 'approved')
                                ? 'ดำเนินการเปิดรายวิชา/สำรองที่นั่ง (REQ-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') เสร็จสมบูรณ์แล้ว'
                                : 'เห็นชอบคำร้องวิชาการ (REQ-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') ส่งต่อขั้นตอนถัดไปเรียบร้อยแล้ว';
                        } else {
                            $stmt_up = $pdo->prepare("UPDATE course_requests SET status = 'rejected', {$col_name} = COALESCE(NULLIF(?, ''), {$col_name}) {$time_sql}, updated_at = NOW() WHERE request_id = ?");
                            $stmt_up->execute([$q_comment ?: 'ไม่อนุมัติคำร้อง', $q_id]);
                            $drawer_flash_msg = 'ส่งกลับ/ไม่อนุมัติคำร้องวิชาการเรียบร้อยแล้ว';
                            $drawer_flash_type = 'warning';
                        }
                    } else {
                        $drawer_flash_msg = 'ไม่สามารถดำเนินการได้: คำร้องวิชาการนี้อยู่ระหว่างรอผู้เกี่ยวข้องในลำดับปัจจุบันพิจารณา';
                        $drawer_flash_type = 'warning';
                    }
                }
            } elseif ($q_module === 'supplies') {
                // เบิกวัสดุ/พัสดุกลาง: ขั้นที่ 1 หัวหน้าสำนักงานอนุมัติความจำเป็น -> ขั้นที่ 2 งานพัสดุจ่ายของ
                $stmt_cur = $pdo->prepare("SELECT head_status, head_approver_id FROM supplies_requests WHERE request_id = ?");
                $stmt_cur->execute([$q_id]);
                $sup_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
                if ($sup_cur && $sup_cur['head_status'] === 'pending') {
                    if (canApproveSupplyHeadExec((int)($sup_cur['head_approver_id'] ?? 0), $user_id)) {
                        if ($q_action === 'approve') {
                            $stmt_up = $pdo->prepare("UPDATE supplies_requests SET head_status = 'approved', head_approver_id = ?, head_approve_date = NOW(), head_remark = ? WHERE request_id = ?");
                            $stmt_up->execute([$user_id, $q_comment, $q_id]);
                            $drawer_flash_msg = 'อนุมัติใบเบิกวัสดุ (SUP-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') ส่งต่อให้งานพัสดุจ่ายของเรียบร้อยแล้ว';
                        } else {
                            $stmt_up = $pdo->prepare("UPDATE supplies_requests SET head_status = 'rejected', head_approver_id = ?, head_approve_date = NOW(), head_remark = ? WHERE request_id = ?");
                            $stmt_up->execute([$user_id, $q_comment ?: 'ไม่อนุมัติการเบิกวัสดุ', $q_id]);
                            $drawer_flash_msg = 'ไม่อนุมัติคำขอเบิกวัสดุเรียบร้อยแล้ว';
                            $drawer_flash_type = 'warning';
                        }
                    } else {
                        $drawer_flash_msg = 'ไม่สามารถดำเนินการได้: เฉพาะหัวหน้าสำนักงานหรือผู้อนุมัติที่ได้รับมอบหมาย';
                        $drawer_flash_type = 'warning';
                    }
                }
            } elseif ($q_module === 'room') {
                if ($is_admin || $is_building_staff) {
                    $new_st = ($q_action === 'approve') ? 'approved' : 'rejected';
                    $pdo->prepare("UPDATE room_bookings SET status = ?, approver_id = ?, reject_reason = ? WHERE booking_id = ?")
                        ->execute([$new_st, $user_id, ($q_action === 'approve' ? NULL : ($q_comment ?: 'ไม่อนุมัติ')), $q_id]);
                    $drawer_flash_msg = ($q_action === 'approve' ? 'อนุมัติการจองห้อง' : 'ไม่อนุมัติการจองห้อง') . ' (RB-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') เรียบร้อยแล้ว';
                    if ($q_action !== 'approve') $drawer_flash_type = 'warning';
                }
            } elseif ($q_module === 'repair') {
                if ($is_admin || $is_building_staff) {
                    $new_st = ($q_action === 'approve') ? 'completed' : 'cancelled';
                    $pdo->prepare("UPDATE repair_requests SET status = ?, technician_id = ?, completion_date = NOW(), technician_notes = ? WHERE repair_id = ?")
                        ->execute([$new_st, $user_id, $q_comment ?: 'รับเรื่อง/ดำเนินการซ่อมแล้ว', $q_id]);
                    $drawer_flash_msg = ($q_action === 'approve' ? 'บันทึกดำเนินการซ่อม' : 'ยกเลิกการแจ้งซ่อม') . ' (RP-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') เรียบร้อยแล้ว';
                    if ($q_action !== 'approve') $drawer_flash_type = 'warning';
                }
            } elseif ($q_module === 'complaint') {
                if ($is_admin || $is_dean) {
                    $new_st = ($q_action === 'approve') ? 'resolved' : 'rejected';
                    $pdo->prepare("UPDATE complaints SET status = ?, admin_comment = ?, updated_at = NOW() WHERE complaint_id = ?")
                        ->execute([$new_st, $q_comment ?: ($q_action === 'approve' ? 'ยุติเรื่อง/แก้ไขเรียบร้อยแล้ว' : 'ปฏิเสธเรื่องร้องเรียน'), $q_id]);
                    $drawer_flash_msg = ($q_action === 'approve' ? 'ยุติเรื่องร้องเรียน' : 'ปฏิเสธเรื่องร้องเรียน') . ' (CP-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') เรียบร้อยแล้ว';
                    if ($q_action !== 'approve') $drawer_flash_type = 'warning';
                }
            } elseif ($q_module === 'grade_appeal') {
                $new_st = ($q_action === 'approve') ? 'approved' : 'rejected';
                $pdo->prepare("UPDATE grade_appeals SET status = ?, instructor_comment = ?, updated_at = NOW() WHERE appeal_id = ? AND ($is_admin = 1 OR instructor_id = ?)")
                    ->execute([$new_st, $q_comment ?: ($q_action === 'approve' ? 'เห็นชอบการอุทธรณ์คะแนน' : 'ปฏิเสธการอุทธรณ์'), $q_id, $user_id]);
                $drawer_flash_msg = ($q_action === 'approve' ? 'อนุมัติการอุทธรณ์คะแนน' : 'ปฏิเสธการอุทธรณ์') . ' (GA-' . str_pad((string)$q_id, 4, '0', STR_PAD_LEFT) . ') เรียบร้อยแล้ว';
                if ($q_action !== 'approve') $drawer_flash_type = 'warning';
            }
        }
    } catch (PDOException $e) {
        $drawer_flash_msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
        $drawer_flash_type = 'danger';
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// =========================================================
// ข้อมูลปีงบประมาณ (Fiscal Year Management)
// =========================================================
$active_fy = '';
$available_fys = [];
try {
    $stmt_fy_list = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
    $fiscal_years_db = $stmt_fy_list->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fiscal_years_db as $fy_row) {
        if (!empty($fy_row['year_name'])) {
            $available_fys[] = (string)$fy_row['year_name'];
            if ($fy_row['is_active'] == 1 && empty($active_fy)) {
                $active_fy = (string)$fy_row['year_name'];
            }
        }
    }
} catch (PDOException $e) {}

if (empty($active_fy)) {
    $active_fy = !empty($available_fys) ? $available_fys[0] : (string)(date('Y') + 543);
}

$extra_tables = [
    ['procurement_tracking', 'fiscal_year'],
    ['projects', 'fiscal_year'],
    ['finance_requests', 'fiscal_year'],
    ['research_grants', 'funding_year'],
    ['training_reports', 'fiscal_year'],
    ['supplies_requests', 'fiscal_year']
];
foreach ($extra_tables as $et) {
    try {
        $stmt_extra = $pdo->query("SELECT DISTINCT {$et[1]} FROM {$et[0]} WHERE {$et[1]} IS NOT NULL AND {$et[1]} != ''");
        foreach ($stmt_extra->fetchAll(PDO::FETCH_COLUMN) as $ey) {
            $ey_str = trim((string)$ey);
            if ($ey_str !== '' && !in_array($ey_str, $available_fys)) {
                $available_fys[] = $ey_str;
            }
        }
    } catch (PDOException $e) {}
}
rsort($available_fys, SORT_STRING);

$selected_fy = isset($_GET['fiscal_year']) ? trim($_GET['fiscal_year']) : (isset($_GET['year']) ? trim($_GET['year']) : $active_fy);
if ($selected_fy !== 'all' && !in_array($selected_fy, $available_fys)) {
    $selected_fy = $active_fy;
}
$is_all_years = ($selected_fy === 'all');

$top_fys = array_slice($available_fys, 0, 2);
if (!in_array($active_fy, $top_fys)) {
    array_unshift($top_fys, $active_fy);
    $top_fys = array_slice(array_unique($top_fys), 0, 2);
}

if (!function_exists('formatThaiShortDateExec')) {
    function formatThaiShortDateExec($dateStr) {
        if (empty($dateStr) || $dateStr === '0000-00-00') return '-';
        $months = [1=>'ม.ค.', 2=>'ก.พ.', 3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.', 6=>'มิ.ย.', 7=>'ก.ค.', 8=>'ส.ค.', 9=>'ก.ย.', 10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.'];
        $ts = strtotime($dateStr);
        if (!$ts) return $dateStr;
        $d = date('d', $ts);
        $m = $months[(int)date('n', $ts)] ?? '';
        $y = (int)date('Y', $ts) + 543;
        return "$d $m $y";
    }
}

if (!function_exists('getThaiInitialsExec')) {
    function getThaiInitialsExec($firstName, $lastName = '') {
        $f = trim((string)$firstName);
        $l = trim((string)$lastName);
        $c1 = mb_substr($f, 0, 1, 'UTF-8');
        $c2 = $l !== '' ? mb_substr($l, 0, 1, 'UTF-8') : mb_substr($f, 1, 1, 'UTF-8');
        return ($c1 . $c2) ?: 'มม';
    }
}

// =========================================================
// 1. ข้อมูลระบบการลา & ไปราชการ (Leave & Training)
// =========================================================
$pending_leaves_count = 0;
$leaves_today = [];
try {
    $pending_sql = "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'";
    if (hasRole(['หัวหน้าสำนักงาน']) && !hasRole(['admin'])) { $pending_sql .= " AND current_step = 2"; } 
    elseif (hasRole(['รองคณบดีฝ่ายบริหาร']) && !hasRole(['admin'])) { $pending_sql .= " AND current_step = 3"; } 
    elseif (hasRole(['คณบดี', 'รักษาการคณบดี']) && !hasRole(['admin'])) { $pending_sql .= " AND current_step = 4"; }
    $pending_leaves_count = (int)$pdo->query($pending_sql)->fetchColumn();

    $stmt_today = $pdo->prepare("
        SELECT u.first_name, u.last_name, t.type_name, d.dept_name, l.status, l.current_step 
        FROM leave_requests l 
        JOIN users u ON l.user_id = u.user_id 
        JOIN leave_types t ON l.type_id = t.type_id 
        LEFT JOIN departments d ON u.dept_id = d.dept_id 
        WHERE l.status NOT IN ('rejected', 'canceled') AND :today BETWEEN l.start_date AND l.end_date
    ");
    $stmt_today->execute([':today' => $today]);
    $leaves_today = $stmt_today->fetchAll(PDO::FETCH_ASSOC);

    $stmt_train_today = $pdo->prepare("
        SELECT u.first_name, u.last_name, 'ไปราชการ' as type_name, d.dept_name, tr.status, tr.current_step 
        FROM training_reports tr 
        JOIN users u ON tr.user_id = u.user_id 
        LEFT JOIN departments d ON u.dept_id = d.dept_id 
        WHERE tr.status NOT IN ('rejected', 'returned') AND :today BETWEEN tr.start_date AND tr.end_date
    ");
    $stmt_train_today->execute([':today' => $today]);
    $trainings_today_rows = $stmt_train_today->fetchAll(PDO::FETCH_ASSOC);
    if ($trainings_today_rows) {
        $leaves_today = array_merge($leaves_today, $trainings_today_rows);
    }
} catch (PDOException $e) {}

$pending_trainings_count = 0;
$training_budget_stats = ['used' => 0, 'count' => 0];
try {
    $pending_train_sql = "SELECT COUNT(*) FROM training_reports WHERE status = 'pending'";
    if (hasRole(['หัวหน้าภาควิชา', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'หัวหน้าสำนักงาน']) && !hasRole(['admin'])) { $pending_train_sql .= " AND current_step IN (1, 3)"; } 
    elseif (hasRole(['งานการเงิน', 'งานการเงินและพัสดุ']) && !hasRole(['admin'])) { $pending_train_sql .= " AND current_step = 2"; } 
    elseif (hasRole(['รองคณบดีฝ่ายบริหาร']) && !hasRole(['admin'])) { $pending_train_sql .= " AND current_step = 4"; } 
    elseif (hasRole(['คณบดี', 'รักษาการคณบดี']) && !hasRole(['admin'])) { $pending_train_sql .= " AND current_step = 5"; }
    $pending_trainings_count = (int)$pdo->query($pending_train_sql)->fetchColumn();

    if ($is_all_years) {
        $stmt_tb = $pdo->query("SELECT COUNT(*) as cnt, SUM(CASE WHEN budget_used > 0 THEN budget_used ELSE (COALESCE(est_budget_travel,0)+COALESCE(est_budget_accom,0)+COALESCE(est_budget_allowance,0)+COALESCE(est_budget_regis,0)+COALESCE(est_budget_other,0)) END) as total_used FROM training_reports WHERE status IN ('approved', 'reported')");
    } else {
        $stmt_tb = $pdo->prepare("SELECT COUNT(*) as cnt, SUM(CASE WHEN budget_used > 0 THEN budget_used ELSE (COALESCE(est_budget_travel,0)+COALESCE(est_budget_accom,0)+COALESCE(est_budget_allowance,0)+COALESCE(est_budget_regis,0)+COALESCE(est_budget_other,0)) END) as total_used FROM training_reports WHERE status IN ('approved', 'reported') AND fiscal_year = ?");
        $stmt_tb->execute([$selected_fy]);
    }
    $tb_row = $stmt_tb->fetch(PDO::FETCH_ASSOC);
    if ($tb_row) {
        $training_budget_stats['used'] = (float)($tb_row['total_used'] ?: 0);
        $training_budget_stats['count'] = (int)($tb_row['cnt'] ?: 0);
    }
} catch (PDOException $e) {}

// =========================================================
// 2. ข้อมูลระบบโครงการและงบประมาณ (Projects & Budget)
// =========================================================
$proj_stats = ['total' => 0, 'delayed' => 0, 'completed' => 0, 'budget_total' => 0, 'budget_used' => 0];
try {
    if ($is_all_years) {
        $stmt_proj = $pdo->prepare("SELECT COUNT(project_id) as total, SUM(CASE WHEN status LIKE '%เรียบร้อย%' OR status LIKE '%เสร็จ%' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN (status NOT LIKE '%เรียบร้อย%' AND status NOT LIKE '%เสร็จ%' OR status IS NULL) AND deadline < :today AND deadline IS NOT NULL AND deadline != '0000-00-00' THEN 1 ELSE 0 END) as `delayed`, SUM(budget) as budget_total, SUM(actual_budget) as budget_used FROM projects");
        $stmt_proj->execute([':today' => $today]);
    } else {
        $stmt_proj = $pdo->prepare("SELECT COUNT(project_id) as total, SUM(CASE WHEN status LIKE '%เรียบร้อย%' OR status LIKE '%เสร็จ%' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN (status NOT LIKE '%เรียบร้อย%' AND status NOT LIKE '%เสร็จ%' OR status IS NULL) AND deadline < :today AND deadline IS NOT NULL AND deadline != '0000-00-00' THEN 1 ELSE 0 END) as `delayed`, SUM(budget) as budget_total, SUM(actual_budget) as budget_used FROM projects WHERE fiscal_year = :fy");
        $stmt_proj->execute([':today' => $today, ':fy' => $selected_fy]);
    }
    $res_proj = $stmt_proj->fetch(PDO::FETCH_ASSOC);
    if ($res_proj) {
        $proj_stats = array_merge($proj_stats, $res_proj);
    }
} catch (PDOException $e) {}

// =========================================================
// 3. ข้อมูลห้องซ้อมดนตรี (Practice Rooms Smart Terminal)
// =========================================================
$practice_telemetry = [
    'total' => 0,
    'checked_in' => 0,
    'no_show' => 0,
    'cancelled' => 0,
    'utilization_pct' => 0,
    'checkin_rate' => 0,
    'noshow_rate' => 0
];
try {
    $stmt_prac_tel = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('active', 'completed') OR check_in_at IS NOT NULL THEN 1 ELSE 0 END) as checked_in,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_wait,
            SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM practice_bookings
    ");
    $pt_row = $stmt_prac_tel->fetch(PDO::FETCH_ASSOC);
    if ($pt_row && $pt_row['total'] > 0) {
        $practice_telemetry['total'] = (int)$pt_row['total'];
        $practice_telemetry['checked_in'] = (int)$pt_row['checked_in'];
        $practice_telemetry['no_show'] = (int)$pt_row['no_show'];
        $practice_telemetry['cancelled'] = (int)$pt_row['cancelled'];
        $valid_pool = max(1, $practice_telemetry['total'] - $practice_telemetry['cancelled']);
        $practice_telemetry['checkin_rate'] = round((($practice_telemetry['checked_in'] + (int)$pt_row['approved_wait']) / $valid_pool) * 100, 1);
        $practice_telemetry['noshow_rate'] = round(($practice_telemetry['no_show'] / $valid_pool) * 100, 1);
        $practice_telemetry['utilization_pct'] = $practice_telemetry['checkin_rate'];
    }
} catch (PDOException $e) {}

// =========================================================
// 4. ข้อมูลคำร้องวิชาการ (Academic Course Requests)
// =========================================================
$academic_req_stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'open_course' => 0, 'reserve_seat' => 0];
try {
    $stmt_acad = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status LIKE 'pending%' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN request_type = 'open_course' THEN 1 ELSE 0 END) as open_course,
            SUM(CASE WHEN request_type = 'reserve_seat' THEN 1 ELSE 0 END) as reserve_seat
        FROM course_requests
    ");
    $academic_req_stats = $stmt_acad->fetch(PDO::FETCH_ASSOC) ?: $academic_req_stats;
} catch (PDOException $e) {}

// =========================================================
// 5. ข้อมูลส่วนปฏิบัติการอื่นๆ
// =========================================================
$meetings_today = [];
try {
    $stmt_meet = $pdo->prepare("SELECT meeting_id, title, meeting_no, start_time, location FROM meetings WHERE meeting_date = ? AND status IN ('upcoming', 'in_progress') ORDER BY start_time ASC");
    $stmt_meet->execute([$today]);
    $meetings_today = $stmt_meet->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$worklog_stats = ['avg_mood' => 0, 'urgent_tasks' => 0, 'issue_tasks' => 0];
try {
    $stmt_work = $pdo->prepare("SELECT AVG(harmony_score) as avg_mood, SUM(CASE WHEN urgency_level IN ('ด่วน', 'ด่วนมาก', 'ด่วนที่สุด', 'เร่งด่วน') THEN 1 ELSE 0 END) as urgent_tasks, SUM(CASE WHEN has_issue = 1 THEN 1 ELSE 0 END) as issue_tasks FROM work_logs WHERE DATE_FORMAT(log_date, '%Y-%m') = ?");
    $stmt_work->execute([$current_month]);
    $res_work = $stmt_work->fetch(PDO::FETCH_ASSOC);
    if ($res_work) {
        $worklog_stats['avg_mood'] = round($res_work['avg_mood'] ?: 0, 1);
        $worklog_stats['urgent_tasks'] = (int)($res_work['urgent_tasks'] ?: 0);
        $worklog_stats['issue_tasks'] = (int)($res_work['issue_tasks'] ?: 0);
    }
} catch (PDOException $e) {}

$pending_supplies_count = 0;
try {
    $pending_supplies_count = (int)$pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE head_status = 'pending' OR (head_status='approved' AND supply_status='pending')")->fetchColumn();
} catch (PDOException $e) {}

$procure_stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0];
try {
    if ($is_all_years) {
        $stmt_proc = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN step10_completed = 1 THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN step10_completed = 0 AND (step1_request=1 OR step2_tor=1 OR step3_approve_buy=1 OR step4_announce_winner=1 OR step5_system_approve=1 OR step6_contract=1 OR step7_delivery=1 OR step8_inspection=1 OR step9_disbursement=1) THEN 1 ELSE 0 END) as in_progress FROM procurement_tracking");
    } else {
        $stmt_proc = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN step10_completed = 1 THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN step10_completed = 0 AND (step1_request=1 OR step2_tor=1 OR step3_approve_buy=1 OR step4_announce_winner=1 OR step5_system_approve=1 OR step6_contract=1 OR step7_delivery=1 OR step8_inspection=1 OR step9_disbursement=1) THEN 1 ELSE 0 END) as in_progress FROM procurement_tracking WHERE fiscal_year = ?");
        $stmt_proc->execute([$selected_fy]);
    }
    $res_proc = $stmt_proc->fetch(PDO::FETCH_ASSOC);
    if ($res_proc) $procure_stats = $res_proc;
} catch (PDOException $e) {}

$finance_stats = ['pending_amount' => 0, 'advance_count' => 0];
try {
    if ($is_all_years) {
        $stmt_fin = $pdo->query("SELECT SUM(amount) as pending_amount, SUM(CASE WHEN req_type = 'advance' THEN 1 ELSE 0 END) as advance_count FROM finance_requests WHERE current_status != 'paid'");
    } else {
        $stmt_fin = $pdo->prepare("SELECT SUM(amount) as pending_amount, SUM(CASE WHEN req_type = 'advance' THEN 1 ELSE 0 END) as advance_count FROM finance_requests WHERE fiscal_year = ? AND current_status != 'paid'");
        $stmt_fin->execute([$selected_fy]);
    }
    $res_fin = $stmt_fin->fetch(PDO::FETCH_ASSOC);
    if ($res_fin) {
        $finance_stats['pending_amount'] = (float)($res_fin['pending_amount'] ?: 0);
        $finance_stats['advance_count'] = (int)($res_fin['advance_count'] ?: 0);
    }
} catch (PDOException $e) {}

$km_stats = ['total_posts' => 0, 'total_views' => 0];
try {
    $stmt_km = $pdo->query("SELECT COUNT(post_id) as total_posts, SUM(view_count) as total_views FROM km_posts WHERE status = 'published'");
    $res_km = $stmt_km->fetch(PDO::FETCH_ASSOC);
    if ($res_km) {
        $km_stats['total_posts'] = (int)($res_km['total_posts'] ?: 0);
        $km_stats['total_views'] = (int)($res_km['total_views'] ?: 0);
    }
} catch (PDOException $e) {}

$research_stats = ['total' => 0, 'this_year' => 0];
$research_year_ce = is_numeric($selected_fy) ? ((int)$selected_fy - 543) : (int)date('Y');
try {
    if ($is_all_years) {
        $stmt_rs = $pdo->query("SELECT COUNT(*) as total, COUNT(*) as this_year FROM cv_research");
    } else {
        $stmt_rs = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN publish_year = ? THEN 1 ELSE 0 END) as this_year FROM cv_research");
        $stmt_rs->execute([(string)$research_year_ce]);
    }
    $res_rs = $stmt_rs->fetch(PDO::FETCH_ASSOC);
    if ($res_rs) {
        $research_stats['total'] = (int)($res_rs['total'] ?: 0);
        $research_stats['this_year'] = (int)($res_rs['this_year'] ?: 0);
    }
} catch (PDOException $e) {}

$attendance_stats = ['present' => 0, 'late' => 0];
try {
    $stmt_att = $pdo->prepare("SELECT COUNT(DISTINCT user_id) as present, SUM(CASE WHEN scan_type = 'in' AND TIME(scan_time) > '09:00:00' THEN 1 ELSE 0 END) as late FROM attendance_logs WHERE DATE(scan_time) = ?");
    $stmt_att->execute([$today]);
    $res_att = $stmt_att->fetch(PDO::FETCH_ASSOC);
    if ($res_att) {
        $attendance_stats['present'] = (int)($res_att['present'] ?: 0);
        $attendance_stats['late'] = (int)($res_att['late'] ?: 0);
    }
} catch (PDOException $e) {}

$pending_room_bookings = 0;
$room_bookings_today = 0;
$practice_bookings_today = 0;
$repair_stats = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
try {
    $pending_room_bookings = (int)($pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'")->fetchColumn() ?: 0);
    $stmt_rep = $pdo->query("SELECT status, COUNT(*) as c FROM repair_requests GROUP BY status");
    while ($row = $stmt_rep->fetch(PDO::FETCH_ASSOC)) {
        if (isset($repair_stats[$row['status']])) {
            $repair_stats[$row['status']] = (int)$row['c'];
        }
    }
    $stmt_rb = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE status = 'approved' AND booking_date = ?");
    $stmt_rb->execute([$today]);
    $room_bookings_today = (int)($stmt_rb->fetchColumn() ?: 0);
    
    $stmt_pb = $pdo->prepare("SELECT COUNT(*) FROM practice_bookings WHERE status IN ('approved', 'active', 'completed') AND booking_date = ?");
    $stmt_pb->execute([$today]);
    $practice_bookings_today = (int)($stmt_pb->fetchColumn() ?: 0);
} catch (PDOException $e) {}

$pending_klof = 0;
$klof_approved_hours = 0;
$zoom_today = 0;
try {
    $stmt_klof = $pdo->query("SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count, SUM(CASE WHEN status = 'approved' THEN hours_count ELSE 0 END) as approved_hours FROM student_klof_logs");
    $klof_res = $stmt_klof->fetch(PDO::FETCH_ASSOC);
    if ($klof_res) {
        $pending_klof = (int)($klof_res['pending_count'] ?: 0);
        $klof_approved_hours = (float)($klof_res['approved_hours'] ?: 0);
    }
    $stmt_z = $pdo->prepare("SELECT COUNT(*) FROM zoom_bookings WHERE DATE(start_time) = ?");
    $stmt_z->execute([$today]);
    $zoom_today = (int)($stmt_z->fetchColumn() ?: 0);
} catch (PDOException $e) {}

// =========================================================
// 6. ดึงรายการพิจารณาอนุมัติสำหรับผู้บริหาร (Universal Approval Stream)
//    กรองตามสิทธิ์และบทบาทหน้าที่ของผู้ใช้แต่ละคน 100% สอดคล้องกับ requests_list.php
//    - คณบดี: ใบลา (ขั้น 4), ไปราชการ (ขั้น 5), เรื่องร้องเรียน
//    - รองคณบดีฝ่ายบริหาร: ใบลา (ขั้น 3), ไปราชการ (ขั้น 4)
//    - รองคณบดีฝ่ายวิชาการ: คำร้องนิสิต (pending_deputy)
//    - หัวหน้าสำนักงาน: พัสดุ (head_status = 'pending'), ใบลา/ไปราชการสายสนับสนุน
//    - เจ้าหน้าที่อาคาร: จองห้อง, แจ้งซ่อมบำรุง
//    - ผู้ดูแลระบบ (Admin): ทุกรายการ
// =========================================================
$unified_stream = [];

// 6.1 คำขอไปราชการ/อบรม (Trainings — กรองตามสิทธิ์พิจารณา 5 ขั้นตอนจริง เช่นเดียวกับ requests_list.php)
try {
    $stmt_u_tr = $pdo->query("
        SELECT tr.report_id, tr.project_name, tr.organizer, tr.location, tr.start_date, tr.end_date, tr.status, tr.current_step, tr.created_at, tr.summary, tr.return_comment,
               (CASE WHEN tr.budget_used > 0 THEN tr.budget_used ELSE (COALESCE(tr.est_budget_travel,0)+COALESCE(tr.est_budget_accom,0)+COALESCE(tr.est_budget_allowance,0)+COALESCE(tr.est_budget_regis,0)+COALESCE(tr.est_budget_other,0)) END) as budget_amt,
               u.dept_id, u.prefix_name, u.first_name, u.last_name, u.position, u.phone, u.email, u.profile_picture, d.dept_name
        FROM training_reports tr
        LEFT JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE tr.status = 'pending'
        AND (
            $is_admin = 1
            OR (tr.current_step IN (1, 3) AND $is_training_office_head = 1 AND (u.dept_id IS NULL OR u.dept_id NOT IN (12, 13)))
            $training_head_sql
            OR (tr.current_step = 2 AND $is_training_finance = 1)
            OR (tr.current_step = 4 AND $is_training_deputy_admin = 1)
            OR (tr.current_step = 5 AND $is_training_dean = 1)
        )
        ORDER BY tr.created_at DESC
    ");
    while ($r = $stmt_u_tr->fetch(PDO::FETCH_ASSOC)) {
        $days = max(1, (int)round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400) + 1);
        $head_role_label = in_array((int)$r['dept_id'], [12, 13], true) ? 'หัวหน้าภาควิชา' : 'หัวหน้าสำนักงาน';
        $step_names = [
            1 => 'รอ' . $head_role_label . 'ตรวจเบื้องต้น (ขั้น 1/5)',
            2 => 'รองานการเงินตรวจงบ (ขั้น 2/5)',
            3 => 'รอ' . $head_role_label . 'เห็นชอบงบ (ขั้น 3/5)',
            4 => 'รอรองคณบดีฝ่ายบริหาร (ขั้น 4/5)',
            5 => 'รอคณบดีอนุมัติเดินทาง (ขั้น 5/5)'
        ];
        $c_step = max(1, min(5, (int)$r['current_step']));
        $status_label = $step_names[$c_step] ?? ('รอขั้นตอนที่ ' . $c_step);
        $budget_val = (float)$r['budget_amt'];
        $budget_txt = $budget_val > 0 ? ' • งบขออนุมัติ ฿' . number_format($budget_val, 0) : '';
        $full_name_str = trim(($r['prefix_name'] ?? '') . $r['first_name'] . ' ' . $r['last_name']);

        $unified_stream[] = [
            'record_id'        => (int)$r['report_id'],
            'code'             => 'TR-' . str_pad((string)$r['report_id'], 4, '0', STR_PAD_LEFT),
            'drawer_header'    => 'คำขออนุมัติเดินทางไปราชการและอบรม',
            'requester'        => $full_name_str,
            'requester_sub'    => ($r['position'] ? $r['position'] . ' • ' : '') . ($r['dept_name'] ?: 'วิทยาลัยดุริยางคศิลป์'),
            'initials'         => getThaiInitialsExec($r['first_name'], $r['last_name']),
            'avatar'           => $r['profile_picture'] ?? '',
            'phone'            => $r['phone'] ?: '043-754-385',
            'email'            => $r['email'] ?: 'music@msu.ac.th',
            'module_key'       => 'training',
            'module_label'     => 'ไปราชการ/อบรม',
            'module_icon'      => 'fa-plane-departure',
            'module_badge'     => 'erp-cat-purple',
            'title'            => $r['project_name'],
            'subtitle'         => ($r['location'] ?: 'ไม่ระบุสถานที่') . $budget_txt,
            'subtitle_err'     => !empty($r['return_comment']) ? 'หมายเหตุ: ' . $r['return_comment'] : '',
            'date_main'        => formatThaiShortDateExec($r['start_date']),
            'date_sub'         => 'ระยะเวลา ' . $days . ' วัน',
            'status_group'     => 'pending',
            'status_label'     => $status_label,
            'status_pill_class'=> 'erp-status-pending',
            'action_label'     => 'พิจารณา',
            'action_url'       => 'modules/trainings/approve_list.php',
            'has_approval_wf'  => true,
            'can_approve'      => true,
            'approve_btn_text' => ($c_step === 5) ? 'อนุมัติเดินทาง (ขั้นสุดท้าย)' : 'เห็นชอบ (ส่งต่อขั้นที่ ' . min(5, $c_step + 1) . ')',
            'pending_step_note'=> '',
            'details'          => [
                ['label' => 'โครงการ / กิจกรรม', 'value' => $r['project_name'], 'highlight' => false],
                ['label' => 'สถานที่จัดงาน', 'value' => $r['location'] ?: '-', 'highlight' => false],
                ['label' => 'กำหนดวันเดินทาง', 'value' => formatThaiShortDateExec($r['start_date']) . ' - ' . formatThaiShortDateExec($r['end_date']) . ' (' . $days . ' วัน)', 'highlight' => false],
                ['label' => 'งบประมาณที่ขออนุมัติ', 'value' => $budget_val > 0 ? '฿ ' . number_format($budget_val, 2) . ' บาท' : 'ไม่เบิกงบประมาณคณะ', 'highlight' => true],
                ['label' => 'เหตุผล / บันทึกเพิ่มเติม', 'value' => !empty($r['organizer']) ? 'หน่วยงานผู้จัด: ' . $r['organizer'] : ($r['summary'] ?: 'เดินทางไปปฏิบัติราชการและพัฒนาศักยภาพบุคลากร'), 'highlight' => false]
            ],
            'workflow_title'   => 'สถานะขั้นตอนการพิจารณา 5 ลำดับ (TRAINING WORKFLOW)',
            'workflow_current' => $c_step,
            'workflow_steps'   => [
                ['step' => 1, 'title' => $head_role_label . ' (ตรวจสอบเบื้องต้น)', 'desc' => 'ตรวจสอบความเหมาะสมของภารกิจและหนังสือเชิญ'],
                ['step' => 2, 'title' => 'งานการเงินและพัสดุ', 'desc' => 'ตรวจสอบสิทธิการเบิกจ่ายและปรับปรุงงบประมาณตามระเบียบ'],
                ['step' => 3, 'title' => $head_role_label . ' (เห็นชอบงบประมาณ)', 'desc' => 'รับรองวงเงินงบประมาณที่ผ่านการตรวจสอบจากงานการเงินแล้ว'],
                ['step' => 4, 'title' => 'รองคณบดีฝ่ายบริหาร', 'desc' => 'พิจารณากลั่นกรองคำขอไปราชการก่อนเสนอคณบดี'],
                ['step' => 5, 'title' => 'คณบดีวิทยาลัยดุริยางคศิลป์', 'desc' => 'ลงนามอนุมัติคำขอเดินทางไปราชการขั้นสุดท้าย']
            ],
            'sort_ts'          => strtotime($r['created_at'] ?: $r['start_date'])
        ];
    }
} catch (PDOException $e) {}

// 6.2 คำขอใบลาบุคลากร (Leave Requests — กรองตามสิทธิ์พิจารณา 4 ขั้นตอนจริง เช่นเดียวกับ requests_list.php)
try {
    $stmt_u_lv = $pdo->query("
        SELECT l.leave_id, l.start_date, l.end_date, l.status, l.current_step, l.reason, l.created_at, l.final_signer_id,
               t.type_name, u.dept_id, u.prefix_name, u.first_name, u.last_name, u.position, u.phone, u.email, u.profile_picture, d.dept_name
        FROM leave_requests l
        JOIN users u ON l.user_id = u.user_id
        JOIN leave_types t ON l.type_id = t.type_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE l.status = 'pending'
        AND (
            $is_admin = 1
            OR (l.current_step = 1 AND $is_leave_step1 = 1)
            OR (l.current_step = 2 AND $is_leave_step2_staff = 1 AND (u.dept_id = 11 OR l.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1)))
            $head_depts_sql
            OR (l.current_step = 3 AND $is_leave_step3 = 1)
            OR (l.current_step = 4 AND $is_leave_step4 = 1 AND (l.final_signer_id IS NULL OR l.final_signer_id = ''))
            OR (l.current_step = 4 AND l.final_signer_id = '$user_id')
        )
        ORDER BY l.created_at DESC
    ");
    while ($r = $stmt_u_lv->fetch(PDO::FETCH_ASSOC)) {
        $days = max(1, (int)round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400) + 1);
        $head_role_label = in_array((int)$r['dept_id'], [12, 13], true) ? 'หัวหน้าภาควิชา' : 'หัวหน้าสำนักงาน';
        $step_names = [
            1 => 'รองานบุคคลตรวจวันลา (ขั้น 1/4)',
            2 => 'รอ' . $head_role_label . ' (ขั้น 2/4)',
            3 => 'รอรองคณบดีฝ่ายบริหาร (ขั้น 3/4)',
            4 => 'รอคณบดีอนุมัติใบลา (ขั้น 4/4)'
        ];
        $c_step = max(1, min(4, (int)$r['current_step']));
        $status_label = $step_names[$c_step] ?? ('รอขั้นตอนที่ ' . $c_step);
        $req_name = trim(($r['prefix_name'] ?? '') . $r['first_name'] . ' ' . $r['last_name']);

        $unified_stream[] = [
            'record_id'        => (int)$r['leave_id'],
            'code'             => 'LV-' . str_pad((string)$r['leave_id'], 4, '0', STR_PAD_LEFT),
            'drawer_header'    => 'คำขออนุมัติการลาบุคลากร (E-Leave)',
            'requester'        => $req_name,
            'requester_sub'    => ($r['position'] ? $r['position'] . ' • ' : '') . ($r['dept_name'] ?: 'บุคลากร'),
            'initials'         => getThaiInitialsExec($r['first_name'], $r['last_name']),
            'avatar'           => $r['profile_picture'] ?? '',
            'phone'            => $r['phone'] ?: '-',
            'email'            => $r['email'] ?: 'music@msu.ac.th',
            'module_key'       => 'leave',
            'module_label'     => 'ระบบการลา',
            'module_icon'      => 'fa-envelope-open-text',
            'module_badge'     => 'erp-cat-teal',
            'title'            => $r['type_name'] . ' (' . $days . ' วัน)',
            'subtitle'         => $r['reason'] ?: 'ยื่นใบลาผ่านระบบอิเล็กทรอนิกส์',
            'subtitle_err'     => '',
            'date_main'        => formatThaiShortDateExec($r['start_date']),
            'date_sub'         => 'ถึง ' . formatThaiShortDateExec($r['end_date']),
            'status_group'     => 'pending',
            'status_label'     => $status_label,
            'status_pill_class'=> 'erp-status-pending',
            'action_label'     => 'พิจารณา',
            'action_url'       => 'modules/leave/view.php?id=' . $r['leave_id'],
            'has_approval_wf'  => true,
            'can_approve'      => true,
            'approve_btn_text' => ($c_step === 4) ? 'อนุมัติใบลา (ขั้นสุดท้าย)' : 'ผ่านการตรวจ/เห็นชอบ (ส่งต่อขั้นที่ ' . min(4, $c_step + 1) . ')',
            'pending_step_note'=> '',
            'details'          => [
                ['label' => 'ประเภทการลา', 'value' => $r['type_name'], 'highlight' => false],
                ['label' => 'สังกัด / ภาควิชา', 'value' => $r['dept_name'] ?: 'วิทยาลัยดุริยางคศิลป์', 'highlight' => false],
                ['label' => 'ช่วงเวลาที่ขอลา', 'value' => formatThaiShortDateExec($r['start_date']) . ' ถึง ' . formatThaiShortDateExec($r['end_date']) . ' (รวม ' . $days . ' วัน)', 'highlight' => false],
                ['label' => 'สิทธิ์การลาในปีงบประมาณ', 'value' => 'อยู่ในเกณฑ์สิทธิ์การลาตามระเบียบมหาวิทยาลัย', 'highlight' => true],
                ['label' => 'เหตุผลการลา', 'value' => $r['reason'] ?: 'มีความจำเป็นต้องขอลาหยุดปฏิบัติราชการตามกำหนดการข้างต้น', 'highlight' => false]
            ],
            'workflow_title'   => 'สถานะขั้นตอนการพิจารณาใบลา 4 ลำดับ (E-LEAVE WORKFLOW)',
            'workflow_current' => $c_step,
            'workflow_steps'   => [
                ['step' => 1, 'title' => 'งานบุคคล (HR Verification)', 'desc' => 'ตรวจสอบสถิติวันลาสะสมและสิทธิ์การลาคงเหลือของบุคลากร'],
                ['step' => 2, 'title' => $head_role_label, 'desc' => 'พิจารณาอนุญาตการลาเบื้องต้นในระดับหน่วยงาน/ภาควิชา'],
                ['step' => 3, 'title' => 'รองคณบดีฝ่ายบริหาร', 'desc' => 'พิจารณากลั่นกรองคำขอลาหยุดราชการ'],
                ['step' => 4, 'title' => 'คณบดี / ผู้รักษาการแทนคณบดี', 'desc' => 'ลงนามอนุมัติใบลาอิเล็กทรอนิกส์ขั้นสุดท้าย']
            ],
            'sort_ts'          => strtotime($r['created_at'] ?: $r['start_date'])
        ];
    }
} catch (PDOException $e) {}

// 6.3 คำร้องขอเปิดรายวิชา & สำรองที่นั่ง (Course Requests — กรองตามสิทธิ์พิจารณา 5 ขั้นตอนจริง เช่นเดียวกับ requests_list.php)
try {
    $stmt_u_cr = $pdo->query("
        SELECT cr.request_id, cr.student_id, cr.request_type, cr.semester, cr.course_code, cr.course_name, cr.section, cr.reason, cr.status, cr.created_at,
               cr.instructor_id, cr.advisor_id,
               cr.instructor_comment, cr.advisor_comment, cr.head_comment, cr.deputy_comment, cr.academic_comment,
               s.prefix_name as s_pre, s.first_name as s_fn, s.last_name as s_ln, s.year_level, s.major, s.phone_number, s.email as s_email, s.profile_picture as s_pic,
               u.prefix_name as i_pre, u.first_name as i_fn, u.last_name as i_ln,
               adv.prefix_name as a_pre, adv.first_name as a_fn, adv.last_name as a_ln
        FROM course_requests cr
        LEFT JOIN students s ON cr.student_id = s.student_id
        LEFT JOIN users u ON cr.instructor_id = u.user_id
        LEFT JOIN users adv ON COALESCE(cr.advisor_id, s.advisor_id) = adv.user_id
        WHERE 
            ($is_admin = 1 AND cr.status IN ('pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic'))
            OR (cr.instructor_id = '$user_id' AND cr.status = 'pending_instructor')
            OR (cr.advisor_id = '$user_id' AND cr.status = 'pending_advisor')
            OR (cr.status = 'pending_head' AND $is_course_head = 1)
            OR (cr.status = 'pending_deputy' AND $is_course_deputy = 1)
            OR (cr.status = 'pending_academic' AND $is_course_academic = 1)
        ORDER BY cr.created_at DESC
    ");
    while ($r = $stmt_u_cr->fetch(PDO::FETCH_ASSOC)) {
        $status_map = [
            'pending_instructor' => 'รออาจารย์ผู้สอน (ขั้น 1/5)',
            'pending_advisor'    => 'รออาจารย์ที่ปรึกษา (ขั้น 2/5)',
            'pending_head'       => 'รอหัวหน้าภาควิชา (ขั้น 3/5)',
            'pending_deputy'     => 'รอรองคณบดีฝ่ายวิชาการ (ขั้น 4/5)',
            'pending_academic'   => 'รองานวิชาการดำเนินการ (ขั้น 5/5)'
        ];
        $step_idx_map = [
            'pending_instructor' => 1,
            'pending_advisor'    => 2,
            'pending_head'       => 3,
            'pending_deputy'     => 4,
            'pending_academic'   => 5
        ];
        $status_label = $status_map[$r['status']] ?? $r['status'];
        $c_step = $step_idx_map[$r['status']] ?? 1;

        $stu_name = !empty($r['s_fn']) ? trim(($r['s_pre'] ?? '') . $r['s_fn'] . ' ' . $r['s_ln']) . ' (นิสิต)' : 'นิสิต ' . $r['student_id'];
        $inst_full = !empty($r['i_fn']) ? trim(($r['i_pre'] ?? '') . $r['i_fn'] . ' ' . $r['i_ln']) : 'อาจารย์ผู้สอนประจำรายวิชา';
        $adv_full = !empty($r['a_fn']) ? trim(($r['a_pre'] ?? '') . $r['a_fn'] . ' ' . $r['a_ln']) : 'อาจารย์ที่ปรึกษา';
        $is_duriyang = (mb_strpos($r['major'] ?? '', 'ดุริยางค') !== false);
        $head_full = $is_duriyang
            ? 'ผู้ช่วยศาสตราจารย์อรรคพงศ์ ภูลายยาว (หัวหน้าภาควิชาดุริยางคศาสตร์)'
            : 'ดร.พิษณุ บุญศรีอนันต์ (หัวหน้าภาควิชาดนตรีศึกษา)';
        $head_step_title = $is_duriyang
            ? '3. หัวหน้าภาควิชาดุริยางคศาสตร์'
            : '3. หัวหน้าภาควิชาดนตรีศึกษา';
        $deputy_full = 'ผศ.ดร.วีรยุทธ สีคุณหลิ่ว (รองคณบดีฝ่ายวิชาการ)';
        $academic_full = 'นางสาวธรัญญา ปูธิรัตน์ (งานบริการการศึกษา)';
        $inst_name = !empty($r['i_fn']) ? 'อาจารย์ผู้สอน: ' . $inst_full : 'รหัสนิสิต ' . $r['student_id'];
        $is_open = ($r['request_type'] === 'open_course');
        $stu_sub_drawer = (!empty($r['year_level']) ? 'นิสิตชั้นปีที่ ' . $r['year_level'] . ' • ' : '') . (!empty($r['major']) ? $r['major'] : 'รหัสนิสิต ' . $r['student_id']);

        $unified_stream[] = [
            'record_id'        => (int)$r['request_id'],
            'code'             => 'REQ-' . str_pad((string)$r['request_id'], 4, '0', STR_PAD_LEFT),
            'drawer_header'    => $is_open ? 'คำร้องขอเปิดรายวิชาการศึกษา' : 'คำร้องขอสำรองที่นั่งรายวิชา',
            'requester'        => $stu_name,
            'requester_sub'    => $stu_sub_drawer,
            'initials'         => getThaiInitialsExec($r['s_fn'] ?: 'น', $r['s_ln'] ?: 'ส'),
            'avatar'           => $r['s_pic'] ?? '',
            'phone'            => $r['phone_number'] ?: '-',
            'email'            => $r['s_email'] ?: ($r['student_id'] . '@msu.ac.th'),
            'module_key'       => 'academic',
            'module_label'     => $is_open ? 'ขอเปิดรายวิชา' : 'สำรองที่นั่ง',
            'module_icon'      => 'fa-graduation-cap',
            'module_badge'     => 'erp-cat-blue',
            'title'            => trim($r['course_code'] . ' ' . $r['course_name']),
            'subtitle'         => 'กลุ่มเรียน ' . ($r['section'] ?: '-') . ' • ภาคเรียนที่ ' . ($r['semester'] ?: '-') . ' • ' . $inst_name,
            'subtitle_err'     => '',
            'date_main'        => formatThaiShortDateExec($r['created_at']),
            'date_sub'         => 'ส่งเมื่อ ' . date('H:i', strtotime($r['created_at'])) . ' น.',
            'status_group'     => 'pending',
            'status_label'     => $status_label,
            'status_pill_class'=> 'erp-status-pending',
            'action_label'     => 'พิจารณา',
            'action_url'       => 'requests_approve.php',
            'has_approval_wf'  => true,
            'can_approve'      => true,
            'approve_btn_text' => ($r['status'] === 'pending_academic') ? 'บันทึกเข้าระบบทะเบียน & ปิดคำร้อง' : 'เห็นชอบคำร้อง (ส่งต่อขั้นที่ ' . min(5, $c_step + 1) . ')',
            'pending_step_note'=> '',
            'details'          => [
                ['label' => 'รายวิชาที่ยื่นคำร้อง', 'value' => trim($r['course_code'] . ' ' . $r['course_name']) . ' (Sec ' . ($r['section'] ?: '-') . ')', 'highlight' => false],
                ['label' => 'อาจารย์ผู้สอนรายวิชา', 'value' => $inst_full, 'highlight' => false],
                ['label' => 'ภาคการศึกษา', 'value' => 'ภาคเรียนที่ ' . ($r['semester'] ?: '-'), 'highlight' => false],
                ['label' => 'ประเภทคำร้อง', 'value' => $is_open ? 'คำร้องขอเปิดรายวิชานอกแผน/เพิ่มกลุ่มเรียน' : 'คำร้องขอสำรองที่นั่งเรียนรายวิชา', 'highlight' => true],
                ['label' => 'เหตุผลความจำเป็นของนิสิต', 'value' => $r['reason'] ?: 'มีความจำเป็นต้องลงทะเบียนเรียนเพื่อสำเร็จการศึกษาตามแผนการเรียน', 'highlight' => false]
            ],
            'workflow_title'   => 'สถานะขั้นตอนคำร้องวิชาการ 5 ลำดับ (ACADEMIC WORKFLOW)',
            'workflow_current' => $c_step,
            'workflow_steps'   => [
                [
                    'step' => 1,
                    'title' => $is_open ? '1. อาจารย์ผู้สอน / ประสานงาน' : '1. อาจารย์ผู้สอนประจำกลุ่ม',
                    'name' => $inst_full,
                    'desc' => 'พิจารณาความพร้อมในการเปิดสอนและจำนวนรับนิสิตในกลุ่มเรียน',
                    'comment' => $r['instructor_comment'] ?? ''
                ],
                [
                    'step' => 2,
                    'title' => '2. อาจารย์ที่ปรึกษา',
                    'name' => $adv_full,
                    'desc' => 'ตรวจสอบแผนการเรียนและความจำเป็นในการลงทะเบียนของนิสิต',
                    'comment' => $r['advisor_comment'] ?? ''
                ],
                [
                    'step' => 3,
                    'title' => $head_step_title,
                    'name' => $head_full,
                    'desc' => 'พิจารณาให้ความเห็นชอบในระดับภาควิชาและหลักสูตร',
                    'comment' => $r['head_comment'] ?? ''
                ],
                [
                    'step' => 4,
                    'title' => '4. รองคณบดีฝ่ายวิชาการ',
                    'name' => $deputy_full,
                    'desc' => 'พิจารณาอนุมัติคำร้องในระดับคณะ',
                    'comment' => $r['deputy_comment'] ?? ''
                ],
                [
                    'step' => 5,
                    'title' => '5. งานวิชาการ / งานบริการการศึกษา',
                    'name' => $academic_full,
                    'desc' => $is_open ? 'ดำเนินการเปิดกลุ่มเรียนในระบบทะเบียนและปิดคำร้อง' : 'ดำเนินการสำรองที่นั่งในระบบทะเบียนและปิดคำร้อง',
                    'comment' => $r['academic_comment'] ?? ''
                ]
            ],
            'sort_ts'          => strtotime($r['created_at'])
        ];
    }
} catch (PDOException $e) {}

// 6.4 ขออนุมัติเบิกวัสดุ / พัสดุกลาง (Supplies Requests — กรองตามสิทธิ์พิจารณา 2 ขั้นตอนจริง เช่นเดียวกับ requests_list.php)
try {
    $stmt_u_sup = $pdo->query("
        SELECT sr.request_id, sr.request_date, sr.request_reason, sr.fiscal_year, sr.head_status, sr.head_approver_id, sr.head_remark, sr.supply_status,
               u.prefix_name, u.first_name, u.last_name, u.position, u.phone, u.email, u.profile_picture, d.dept_name,
               (SELECT GROUP_CONCAT(CONCAT(i.item_name, ' (', dtl.request_qty, ' ', i.unit_name, ')') SEPARATOR ', ')
                FROM supplies_request_details dtl
                JOIN supplies_items i ON dtl.item_id = i.item_id
                WHERE dtl.request_id = sr.request_id) as items_summary
        FROM supplies_requests sr
        JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE 
            ($is_admin = 1 AND (sr.head_status = 'pending' OR (sr.head_status = 'approved' AND sr.supply_status = 'pending')))
            OR (sr.head_status = 'pending' AND sr.head_approver_id = '$user_id')
            OR ($is_supply_head = 1 AND sr.head_status = 'pending')
            OR ($is_supply_issuer = 1 AND sr.head_status = 'approved' AND sr.supply_status = 'pending')
        ORDER BY sr.request_date DESC
    ");
    while ($r = $stmt_u_sup->fetch(PDO::FETCH_ASSOC)) {
        $c_step = ($r['head_status'] === 'approved' && $r['supply_status'] === 'pending') ? 2 : 1;
        $status_label = ($c_step === 2) ? 'รองานพัสดุจ่ายของ (ขั้น 2/2)' : 'รอหัวหน้าสำนักงานอนุมัติ (ขั้น 1/2)';
        $req_name = trim(($r['prefix_name'] ?? '') . $r['first_name'] . ' ' . $r['last_name']);
        $items_str = !empty($r['items_summary']) ? $r['items_summary'] : 'รายการเบิกวัสดุอุปกรณ์สำนักงาน';

        $unified_stream[] = [
            'record_id'        => (int)$r['request_id'],
            'code'             => 'SUP-' . str_pad((string)$r['request_id'], 4, '0', STR_PAD_LEFT),
            'drawer_header'    => 'คำขออนุมัติเบิกวัสดุและพัสดุกลาง',
            'requester'        => $req_name,
            'requester_sub'    => ($r['position'] ? $r['position'] . ' • ' : '') . ($r['dept_name'] ?: 'บุคลากร'),
            'initials'         => getThaiInitialsExec($r['first_name'], $r['last_name']),
            'avatar'           => $r['profile_picture'] ?? '',
            'phone'            => $r['phone'] ?: '043-754-385',
            'email'            => $r['email'] ?: 'music@msu.ac.th',
            'module_key'       => 'supplies',
            'module_label'     => 'เบิกวัสดุ/พัสดุ',
            'module_icon'      => 'fa-boxes-stacked',
            'module_badge'     => 'erp-cat-amber',
            'title'            => $r['request_reason'] ?: 'ขอเบิกวัสดุอุปกรณ์เพื่อใช้ในราชการ',
            'subtitle'         => $items_str,
            'subtitle_err'     => !empty($r['head_remark']) ? 'หมายเหตุ: ' . $r['head_remark'] : '',
            'date_main'        => formatThaiShortDateExec($r['request_date']),
            'date_sub'         => 'ปีงบ ' . ($r['fiscal_year'] ?: $selected_fy),
            'status_group'     => 'pending',
            'status_label'     => $status_label,
            'status_pill_class'=> 'erp-status-pending',
            'action_label'     => 'พิจารณา',
            'action_url'       => ($r['head_status'] === 'pending') ? 'modules/supplies/head_approve.php' : 'modules/supplies/issue_supplies.php',
            'has_approval_wf'  => true,
            'can_approve'      => true,
            'approve_btn_text' => ($c_step === 1) ? 'อนุมัติให้เบิกได้ (ส่งงานพัสดุ)' : 'ยืนยันจ่ายวัสดุอุปกรณ์',
            'pending_step_note'=> '',
            'details'          => [
                ['label' => 'เหตุผลความจำเป็น', 'value' => $r['request_reason'] ?: 'ใช้ในการปฏิบัติราชการและสนับสนุนการเรียนการสอน', 'highlight' => false],
                ['label' => 'รายการวัสดุที่ขอเบิก', 'value' => $items_str, 'highlight' => true],
                ['label' => 'หน่วยงานผู้ขอเบิก', 'value' => $r['dept_name'] ?: 'วิทยาลัยดุริยางคศิลป์', 'highlight' => false],
                ['label' => 'วันที่ยื่นใบเบิก', 'value' => formatThaiShortDateExec($r['request_date']) . ' เวลา ' . date('H:i', strtotime($r['request_date'])) . ' น.', 'highlight' => false],
                ['label' => 'สถานะปัจจุบัน', 'value' => $status_label, 'highlight' => false]
            ],
            'workflow_title'   => 'สถานะขั้นตอนการเบิกวัสดุ 2 ลำดับ (SUPPLIES WORKFLOW)',
            'workflow_current' => $c_step,
            'workflow_steps'   => [
                ['step' => 1, 'title' => 'หัวหน้าสำนักงานพิจารณาอนุมัติ (Step 1)', 'desc' => 'ตรวจสอบความจำเป็นในการขอเบิกวัสดุอุปกรณ์สำนักงาน'],
                ['step' => 2, 'title' => 'งานพัสดุตรวจสอบคลังและจ่ายวัสดุ (Step 2)', 'desc' => 'ตัดยอดสต็อกคงคลังและส่งมอบวัสดุให้ผู้ขอเบิก']
            ],
            'sort_ts'          => strtotime($r['request_date'])
        ];
    }
} catch (PDOException $e) {}

// 6.5 จองห้องและสถานที่ (Room Bookings — กรองเฉพาะผู้มีอำนาจ Admin / งานอาคารสถานที่ เช่นเดียวกับ requests_list.php)
if ($is_admin || $is_building_staff) {
    try {
        $stmt_u_rb = $pdo->query("
            SELECT rb.booking_id, rb.booking_date, rb.start_time, rb.end_time, rb.purpose, rb.created_at, rb.room_id,
                   rms.room_name, rms.room_code,
                   COALESCE(CONCAT(u.prefix_name, u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS req_fullname,
                   COALESCE(u.position, CONCAT('นิสิตสาขา ', s.major), 'ผู้ขอใช้ห้อง') AS req_sub,
                   COALESCE(u.first_name, s.first_name, 'ห') AS f_name,
                   COALESCE(u.last_name, s.last_name, 'อ') AS l_name,
                   COALESCE(u.phone, s.phone_number, '-') AS req_phone,
                   COALESCE(u.email, s.email, 'music@msu.ac.th') AS req_email,
                   COALESCE(u.profile_picture, s.profile_picture, '') AS req_avatar
            FROM room_bookings rb
            LEFT JOIN rooms rms ON rb.room_id = rms.room_id
            LEFT JOIN users u ON rb.user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN students s ON rb.user_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
            WHERE rb.status = 'pending'
            ORDER BY rb.created_at DESC
        ");
        while ($r = $stmt_u_rb->fetch(PDO::FETCH_ASSOC)) {
            $time_slot = substr($r['start_time'], 0, 5) . ' - ' . substr($r['end_time'], 0, 5) . ' น.';
            $unified_stream[] = [
                'record_id'        => (int)$r['booking_id'],
                'code'             => 'RB-' . str_pad((string)$r['booking_id'], 4, '0', STR_PAD_LEFT),
                'drawer_header'    => 'คำขออนุมัติจองห้องและสถานที่',
                'requester'        => $r['req_fullname'],
                'requester_sub'    => $r['req_sub'],
                'initials'         => getThaiInitialsExec($r['f_name'], $r['l_name']),
                'avatar'           => $r['req_avatar'] ?? '',
                'phone'            => $r['req_phone'],
                'email'            => $r['req_email'],
                'module_key'       => 'room',
                'module_label'     => 'จองห้อง/สถานที่',
                'module_icon'      => 'fa-door-open',
                'module_badge'     => 'erp-cat-blue',
                'title'            => ($r['room_name'] ?: 'ไม่ระบุห้อง') . ' (' . ($r['purpose'] ?: 'ขอใช้ห้อง') . ')',
                'subtitle'         => 'วันที่ ' . formatThaiShortDateExec($r['booking_date']) . ' เวลา ' . $time_slot,
                'subtitle_err'     => '',
                'date_main'        => formatThaiShortDateExec($r['booking_date']),
                'date_sub'         => $time_slot,
                'status_group'     => 'pending',
                'status_label'     => 'รออนุมัติจองห้อง',
                'status_pill_class'=> 'erp-status-pending',
                'action_label'     => 'พิจารณา',
                'action_url'       => 'modules/room_booking/view_booking.php?id=' . $r['booking_id'],
                'has_approval_wf'  => true,
                'can_approve'      => true,
                'approve_btn_text' => 'อนุมัติการจองห้อง',
                'pending_step_note'=> '',
                'details'          => [
                    ['label' => 'ห้อง / สถานที่', 'value' => ($r['room_name'] ?: '-') . (!empty($r['room_code']) ? ' (' . $r['room_code'] . ')' : ''), 'highlight' => true],
                    ['label' => 'วัน-เวลาที่ขอใช้', 'value' => formatThaiShortDateExec($r['booking_date']) . ' เวลา ' . $time_slot, 'highlight' => false],
                    ['label' => 'วัตถุประสงค์การใช้ห้อง', 'value' => $r['purpose'] ?: 'เพื่อการเรียนการสอน/กิจกรรม', 'highlight' => false],
                    ['label' => 'ผู้ขอใช้ห้อง', 'value' => $r['req_fullname'] . ' (' . $r['req_sub'] . ')', 'highlight' => false]
                ],
                'workflow_title'   => 'ขั้นตอนการพิจารณาจองห้อง (ROOM BOOKING)',
                'workflow_current' => 1,
                'workflow_steps'   => [
                    ['step' => 1, 'title' => 'เจ้าหน้าที่อาคารสถานที่ / ผู้ดูแลห้อง', 'desc' => 'ตรวจสอบตารางห้องว่างและความพร้อมของอุปกรณ์'],
                    ['step' => 2, 'title' => 'อนุมัติการเข้าใช้งาน', 'desc' => 'ยืนยันการจองในระบบและแจ้งผู้ขอใช้ห้อง']
                ],
                'sort_ts'          => strtotime($r['created_at'] ?: $r['booking_date'])
            ];
        }
    } catch (PDOException $e) {}
}

// 6.6 แจ้งซ่อมบำรุง (Repair Requests — กรองเฉพาะ Admin / งานอาคารสถานที่ เช่นเดียวกับ requests_list.php)
if ($is_admin || $is_building_staff) {
    try {
        $stmt_u_rp = $pdo->query("
            SELECT rr.repair_id, rr.equipment_name, rr.problem_desc, rr.created_at, rr.room_id,
                   rms.room_name,
                   COALESCE(CONCAT(u.prefix_name, u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS req_fullname,
                   COALESCE(u.position, CONCAT('นิสิตสาขา ', s.major), 'ผู้แจ้งซ่อม') AS req_sub,
                   COALESCE(u.first_name, s.first_name, 'ซ') AS f_name,
                   COALESCE(u.last_name, s.last_name, 'ม') AS l_name,
                   COALESCE(u.phone, s.phone_number, '-') AS req_phone,
                   COALESCE(u.email, s.email, 'music@msu.ac.th') AS req_email,
                   COALESCE(u.profile_picture, s.profile_picture, '') AS req_avatar
            FROM repair_requests rr
            LEFT JOIN rooms rms ON rr.room_id = rms.room_id
            LEFT JOIN users u ON COALESCE(rr.reporter_id, rr.user_id) COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN students s ON COALESCE(rr.reporter_id, rr.user_id) COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
            WHERE rr.status = 'pending'
            ORDER BY rr.created_at DESC
        ");
        while ($r = $stmt_u_rp->fetch(PDO::FETCH_ASSOC)) {
            $unified_stream[] = [
                'record_id'        => (int)$r['repair_id'],
                'code'             => 'RP-' . str_pad((string)$r['repair_id'], 4, '0', STR_PAD_LEFT),
                'drawer_header'    => 'คำขอแจ้งซ่อมบำรุงอุปกรณ์และอาคาร',
                'requester'        => $r['req_fullname'],
                'requester_sub'    => $r['req_sub'],
                'initials'         => getThaiInitialsExec($r['f_name'], $r['l_name']),
                'avatar'           => $r['req_avatar'] ?? '',
                'phone'            => $r['req_phone'],
                'email'            => $r['req_email'],
                'module_key'       => 'repair',
                'module_label'     => 'แจ้งซ่อมบำรุง',
                'module_icon'      => 'fa-wrench',
                'module_badge'     => 'erp-cat-amber',
                'title'            => ($r['equipment_name'] ?: 'อุปกรณ์/ครุภัณฑ์') . ' (' . ($r['room_name'] ?: 'ไม่ระบุสถานที่') . ')',
                'subtitle'         => $r['problem_desc'] ?: 'อาการชำรุดเสียหาย',
                'subtitle_err'     => '',
                'date_main'        => formatThaiShortDateExec($r['created_at']),
                'date_sub'         => 'แจ้งเมื่อ ' . date('H:i', strtotime($r['created_at'])) . ' น.',
                'status_group'     => 'pending',
                'status_label'     => 'รอดำเนินการซ่อม',
                'status_pill_class'=> 'erp-status-pending',
                'action_label'     => 'พิจารณา',
                'action_url'       => 'modules/repair_request/view_repair.php?id=' . $r['repair_id'],
                'has_approval_wf'  => true,
                'can_approve'      => true,
                'approve_btn_text' => 'รับเรื่อง & ดำเนินการซ่อม',
                'pending_step_note'=> '',
                'details'          => [
                    ['label' => 'อุปกรณ์ / ครุภัณฑ์ที่ชำรุด', 'value' => $r['equipment_name'] ?: '-', 'highlight' => true],
                    ['label' => 'สถานที่ / ห้อง', 'value' => $r['room_name'] ?: '-', 'highlight' => false],
                    ['label' => 'อาการชำรุด / ปัญหา', 'value' => $r['problem_desc'] ?: '-', 'highlight' => false],
                    ['label' => 'ผู้แจ้งซ่อม', 'value' => $r['req_fullname'] . ' (' . $r['req_sub'] . ')', 'highlight' => false]
                ],
                'workflow_title'   => 'ขั้นตอนการดำเนินงานซ่อมบำรุง (REPAIR WORKFLOW)',
                'workflow_current' => 1,
                'workflow_steps'   => [
                    ['step' => 1, 'title' => 'เจ้าหน้าที่รับเรื่องและตรวจสอบความเสียหาย', 'desc' => 'ประเมินสภาพความเสียหายและอะไหล่'],
                    ['step' => 2, 'title' => 'ดำเนินการซ่อมแซมและส่งมอบ', 'desc' => 'ซ่อมบำรุงและปิดงานแจ้งซ่อม']
                ],
                'sort_ts'          => strtotime($r['created_at'])
            ];
        }
    } catch (PDOException $e) {}
}

// 6.7 เรื่องร้องเรียน / ข้อเสนอแนะ (Complaints — กรองเฉพาะ คณบดี / Admin เช่นเดียวกับ requests_list.php)
if ($is_admin || $is_dean) {
    try {
        $stmt_u_cp = $pdo->query("
            SELECT c.complaint_id, c.subject, c.details, c.status, c.created_at,
                   s.prefix_name, s.first_name, s.last_name, s.phone_number, s.email, s.major, s.year_level, s.profile_picture
            FROM complaints c
            LEFT JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
            WHERE c.status IN ('pending', 'in_progress')
            ORDER BY c.created_at DESC
        ");
        while ($r = $stmt_u_cp->fetch(PDO::FETCH_ASSOC)) {
            $stu_name = !empty($r['first_name']) ? trim(($r['prefix_name'] ?? '') . $r['first_name'] . ' ' . $r['last_name']) . ' (นิสิต)' : 'นิสิต (ไม่ระบุตัวตน)';
            $stu_sub = (!empty($r['year_level']) ? 'ชั้นปีที่ ' . $r['year_level'] . ' • ' : '') . ($r['major'] ?: 'วิทยาลัยดุริยางคศิลป์');
            $unified_stream[] = [
                'record_id'        => (int)$r['complaint_id'],
                'code'             => 'CP-' . str_pad((string)$r['complaint_id'], 4, '0', STR_PAD_LEFT),
                'drawer_header'    => 'เรื่องร้องเรียนและข้อเสนอแนะสำหรับผู้บริหาร',
                'requester'        => $stu_name,
                'requester_sub'    => $stu_sub,
                'initials'         => getThaiInitialsExec($r['first_name'] ?: 'ร', $r['last_name'] ?: 'ร'),
                'avatar'           => $r['profile_picture'] ?? '',
                'phone'            => $r['phone_number'] ?: '-',
                'email'            => $r['email'] ?: 'music@msu.ac.th',
                'module_key'       => 'complaint',
                'module_label'     => 'เรื่องร้องเรียน',
                'module_icon'      => 'fa-bullhorn',
                'module_badge'     => 'erp-cat-rose',
                'title'            => $r['subject'] ?: 'เรื่องร้องเรียน/ข้อเสนอแนะทั่วไป',
                'subtitle'         => mb_substr(strip_tags($r['details'] ?: ''), 0, 70) . '...',
                'subtitle_err'     => '',
                'date_main'        => formatThaiShortDateExec($r['created_at']),
                'date_sub'         => 'แจ้งเมื่อ ' . date('H:i', strtotime($r['created_at'])) . ' น.',
                'status_group'     => 'pending',
                'status_label'     => ($r['status'] === 'in_progress') ? 'กำลังดำเนินการ' : 'รอคณบดีพิจารณา',
                'status_pill_class'=> 'erp-status-pending',
                'action_label'     => 'พิจารณา',
                'action_url'       => 'modules/complaint/view_complaint.php?id=' . $r['complaint_id'],
                'has_approval_wf'  => true,
                'can_approve'      => true,
                'approve_btn_text' => 'ยุติเรื่อง / แก้ไขเรียบร้อย',
                'pending_step_note'=> '',
                'details'          => [
                    ['label' => 'หัวข้อเรื่องร้องเรียน', 'value' => $r['subject'] ?: '-', 'highlight' => true],
                    ['label' => 'รายละเอียดข้อร้องเรียน', 'value' => $r['details'] ?: '-', 'highlight' => false],
                    ['label' => 'ผู้ร้องเรียน', 'value' => $stu_name . ' (' . $stu_sub . ')', 'highlight' => false],
                    ['label' => 'วันที่ส่งเรื่อง', 'value' => formatThaiShortDateExec($r['created_at']) . ' เวลา ' . date('H:i', strtotime($r['created_at'])) . ' น.', 'highlight' => false]
                ],
                'workflow_title'   => 'ขั้นตอนการจัดการเรื่องร้องเรียน (COMPLAINT WORKFLOW)',
                'workflow_current' => 1,
                'workflow_steps'   => [
                    ['step' => 1, 'title' => 'คณบดี / ผู้บริหารรับทราบและมอบหมาย', 'desc' => 'ตรวจสอบข้อเท็จจริงและมอบหมายหน่วยงานที่เกี่ยวข้อง'],
                    ['step' => 2, 'title' => 'แก้ไขปัญหาและรายงานผล (ปิดเรื่อง)', 'desc' => 'ดำเนินการแก้ไขและตอบกลับผู้ร้องเรียน']
                ],
                'sort_ts'          => strtotime($r['created_at'])
            ];
        }
    } catch (PDOException $e) {}
}

// 6.8 อุทธรณ์คะแนน (Grade Appeals — กรองเฉพาะ อาจารย์ผู้สอน / Admin เช่นเดียวกับ requests_list.php)
try {
    $stmt_u_ga = $pdo->query("
        SELECT ga.appeal_id, ga.course_code, ga.course_name, ga.appeal_reason, ga.status, ga.created_at, ga.semester, ga.academic_year,
               s.prefix_name, s.first_name, s.last_name, s.phone_number, s.email, s.major, s.year_level, s.profile_picture
        FROM grade_appeals ga
        LEFT JOIN students s ON ga.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
        WHERE ga.status IN ('pending', 'in_progress')
        AND ($is_admin = 1 OR ga.instructor_id = '$user_id')
        ORDER BY ga.created_at DESC
    ");
    while ($r = $stmt_u_ga->fetch(PDO::FETCH_ASSOC)) {
        $stu_name = !empty($r['first_name']) ? trim(($r['prefix_name'] ?? '') . $r['first_name'] . ' ' . $r['last_name']) . ' (นิสิต)' : 'นิสิต';
        $stu_sub = (!empty($r['year_level']) ? 'ชั้นปีที่ ' . $r['year_level'] . ' • ' : '') . ($r['major'] ?: 'วิทยาลัยดุริยางคศิลป์');
        $unified_stream[] = [
            'record_id'        => (int)$r['appeal_id'],
            'code'             => 'GA-' . str_pad((string)$r['appeal_id'], 4, '0', STR_PAD_LEFT),
            'drawer_header'    => 'คำร้องขออุทธรณ์ผลการเรียนและคะแนน',
            'requester'        => $stu_name,
            'requester_sub'    => $stu_sub,
            'initials'         => getThaiInitialsExec($r['first_name'] ?: 'อ', $r['last_name'] ?: 'ท'),
            'avatar'           => $r['profile_picture'] ?? '',
            'phone'            => $r['phone_number'] ?: '-',
            'email'            => $r['email'] ?: 'music@msu.ac.th',
            'module_key'       => 'grade_appeal',
            'module_label'     => 'อุทธรณ์คะแนน',
            'module_icon'      => 'fa-chart-line',
            'module_badge'     => 'erp-cat-indigo',
            'title'            => trim($r['course_code'] . ' ' . $r['course_name']),
            'subtitle'         => 'ภาคเรียน ' . ($r['semester'] ?: '-') . '/' . ($r['academic_year'] ?: '-') . ' • ' . ($r['appeal_reason'] ?: 'ขอตรวจสอบคะแนน'),
            'subtitle_err'     => '',
            'date_main'        => formatThaiShortDateExec($r['created_at']),
            'date_sub'         => 'ยื่นเมื่อ ' . date('H:i', strtotime($r['created_at'])) . ' น.',
            'status_group'     => 'pending',
            'status_label'     => ($r['status'] === 'in_progress') ? 'กำลังดำเนินการ' : 'รออาจารย์พิจารณา',
            'status_pill_class'=> 'erp-status-pending',
            'action_label'     => 'พิจารณา',
            'action_url'       => 'modules/grade_appeal/view_appeal.php?id=' . $r['appeal_id'],
            'has_approval_wf'  => true,
            'can_approve'      => true,
            'approve_btn_text' => 'เห็นชอบการอุทธรณ์คะแนน',
            'pending_step_note'=> '',
            'details'          => [
                ['label' => 'รายวิชาที่อุทธรณ์', 'value' => trim($r['course_code'] . ' ' . $r['course_name']), 'highlight' => true],
                ['label' => 'ภาคเรียน / ปีการศึกษา', 'value' => ($r['semester'] ?: '-') . '/' . ($r['academic_year'] ?: '-'), 'highlight' => false],
                ['label' => 'เหตุผลการขออุทธรณ์', 'value' => $r['appeal_reason'] ?: '-', 'highlight' => false],
                ['label' => 'นิสิตผู้อุทธรณ์', 'value' => $stu_name . ' (' . $stu_sub . ')', 'highlight' => false]
            ],
            'workflow_title'   => 'ขั้นตอนการพิจารณาอุทธรณ์ผลการเรียน (APPEAL WORKFLOW)',
            'workflow_current' => 1,
            'workflow_steps'   => [
                ['step' => 1, 'title' => 'อาจารย์ผู้สอนรายวิชา', 'desc' => 'ทบทวนคะแนนสอบและการตัดเกรด'],
                ['step' => 2, 'title' => 'กรรมการวิชาการ / สรุปผล', 'desc' => 'แจ้งผลการพิจารณาและแก้ไขคะแนนในระบบ']
            ],
            'sort_ts'          => strtotime($r['created_at'])
        ];
    }
} catch (PDOException $e) {}

// เรียงลำดับให้รายการที่รอพิจารณาอนุมัติขึ้นก่อน ตามด้วยเวลาล่าสุด
usort($unified_stream, function($a, $b) {
    $a_can = !empty($a['can_approve']) ? 2 : (($a['status_group'] ?? '') === 'pending' ? 1 : 0);
    $b_can = !empty($b['can_approve']) ? 2 : (($b['status_group'] ?? '') === 'pending' ? 1 : 0);
    if ($a_can !== $b_can) {
        return $b_can <=> $a_can;
    }
    return ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0);
});

$stream_counts = [
    'all'      => count($unified_stream),
    'pending'  => 0,
    'approved' => 0,
    'noshow'   => 0
];
$stream_module_counts = [];
foreach ($unified_stream as $item) {
    if (isset($stream_counts[$item['status_group']])) {
        $stream_counts[$item['status_group']]++;
    }
    $m_key = $item['module_key'] ?? 'other';
    $stream_module_counts[$m_key] = ($stream_module_counts[$m_key] ?? 0) + 1;
}

$universal_queue_total = count($unified_stream);

$queue_summary_parts = [];
if (!empty($stream_module_counts['leave'])) $queue_summary_parts[] = 'ใบลา ' . $stream_module_counts['leave'];
if (!empty($stream_module_counts['training'])) $queue_summary_parts[] = 'ไปราชการ ' . $stream_module_counts['training'];
if (!empty($stream_module_counts['academic'])) $queue_summary_parts[] = 'วิชาการ ' . $stream_module_counts['academic'];
if (!empty($stream_module_counts['supplies'])) $queue_summary_parts[] = 'พัสดุ ' . $stream_module_counts['supplies'];
if (!empty($stream_module_counts['room'])) $queue_summary_parts[] = 'จองห้อง ' . $stream_module_counts['room'];
if (!empty($stream_module_counts['repair'])) $queue_summary_parts[] = 'แจ้งซ่อม ' . $stream_module_counts['repair'];
if (!empty($stream_module_counts['complaint'])) $queue_summary_parts[] = 'ร้องเรียน ' . $stream_module_counts['complaint'];
if (!empty($stream_module_counts['grade_appeal'])) $queue_summary_parts[] = 'อุทธรณ์เกรด ' . $stream_module_counts['grade_appeal'];
$queue_summary_text = !empty($queue_summary_parts) ? implode(' • ', $queue_summary_parts) : 'ไม่มีรายการค้างพิจารณา';
$total_budget_allocated = (float)($proj_stats['budget_total'] ?: 0);
$total_budget_used = (float)($proj_stats['budget_used'] ?: 0) + $training_budget_stats['used'];
if ($total_budget_allocated <= 0 && $total_budget_used > 0) {
    $total_budget_allocated = max(600000, $total_budget_used * 1.5);
} elseif ($total_budget_allocated <= 0) {
    $total_budget_allocated = 600000;
}
$budget_used_pct = $total_budget_allocated > 0 ? min(100, round(($total_budget_used / $total_budget_allocated) * 100, 1)) : 0;
$budget_remaining = max(0, $total_budget_allocated - $total_budget_used);

// =========================================================
// 7. ข้อมูลปฏิทินบุคลากร (Calendar Events)
// =========================================================
$calendar_events = [];
try {
    $stmt_cal_leave = $pdo->query("
        SELECT l.start_date, l.end_date, l.status, l.current_step, u.first_name, u.last_name, t.type_name 
        FROM leave_requests l 
        JOIN users u ON l.user_id = u.user_id 
        JOIN leave_types t ON l.type_id = t.type_id 
        WHERE l.status NOT IN ('rejected', 'canceled')
    ");
    while ($row = $stmt_cal_leave->fetch(PDO::FETCH_ASSOC)) {
        $cal_color = ($row['status'] == 'approved') ? '#16a34a' : '#d97706';
        $status_text = ($row['status'] == 'approved') ? 'อนุมัติแล้ว' : 'รอการอนุมัติ (ขั้น ' . $row['current_step'] . ')';
        $calendar_events[] = [
            'title' => 'ลา: ' . $row['first_name'] . ' (' . $row['type_name'] . ')',
            'start' => $row['start_date'],
            'end' => date('Y-m-d', strtotime($row['end_date'] . ' +1 day')),
            'color' => $cal_color, 
            'textColor' => '#ffffff',
            'description' => "ประเภทการลา: " . $row['type_name'] . "\nผู้ลา: " . $row['first_name'] . ' ' . $row['last_name'] . "\nสถานะ: " . $status_text
        ];
    }

    $stmt_cal_train = $pdo->query("
        SELECT tr.start_date, tr.end_date, u.first_name, u.last_name, tr.location, tr.status
        FROM training_reports tr 
        JOIN users u ON tr.user_id = u.user_id 
        WHERE tr.status IN ('approved', 'reported')
    ");
    while ($row = $stmt_cal_train->fetch(PDO::FETCH_ASSOC)) {
        $cal_color = ($row['status'] == 'reported') ? '#2563eb' : '#4f46e5';
        $status_text = ($row['status'] == 'reported') ? 'เสร็จสิ้นแล้ว' : 'อนุมัติเดินทาง';
        $calendar_events[] = [
            'title' => 'ไปราชการ: ' . $row['first_name'],
            'start' => $row['start_date'],
            'end' => date('Y-m-d', strtotime($row['end_date'] . ' +1 day')),
            'color' => $cal_color, 
            'textColor' => '#ffffff',
            'description' => "สถานที่ไปราชการ/อบรม:\n" . $row['location'] . "\n\nผู้เดินทาง: " . $row['first_name'] . ' ' . $row['last_name'] . "\nสถานะ: " . $status_text
        ];
    }
} catch (PDOException $e) {}
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js'></script>

<style>
    /* =========================================================
       Executive Command Center UI System (ERP 2.0)
       ========================================================= */
    .exec-hub-wrapper {
        background-color: #f8fafc;
        min-height: 100vh;
    }

    .exec-breadcrumb {
        font-size: 0.76rem;
        font-weight: 600;
        color: #64748b;
        letter-spacing: 0.01em;
    }
    .exec-main-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.015em;
    }

    .exec-fy-segmented {
        display: inline-flex;
        align-items: center;
        background-color: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 3px;
        gap: 2px;
    }
    .exec-fy-pill {
        padding: 5px 13px;
        border-radius: 7px;
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        transition: all 0.15s ease;
        white-space: nowrap;
    }
    .exec-fy-pill:hover { color: #0f172a; }
    .exec-fy-pill.active {
        background-color: #ffffff;
        color: #0f172a;
        font-weight: 700;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }

    .btn-exec-outline {
        height: 36px;
        padding: 0 13px !important;
        border-radius: 9px !important;
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.78rem !important;
        font-weight: 600 !important;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-exec-outline:hover {
        background-color: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
    }
    .btn-exec-primary {
        height: 36px;
        padding: 0 16px !important;
        border-radius: 9px !important;
        background: #39048f !important;
        border: 1px solid #39048f !important;
        color: #ffffff !important;
        font-size: 0.8rem !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 2px 6px rgba(57, 4, 143, 0.18) !important;
    }
    .btn-exec-primary:hover {
        background: #4a06b8 !important;
        border-color: #4a06b8 !important;
        color: #ffffff !important;
    }

    /* 4 Top Executive Telemetry Cards */
    .exec-kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 1.25rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.18s ease;
        font-variant-numeric: tabular-nums;
    }
    .exec-kpi-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 10px 24px -6px rgba(15, 23, 42, 0.07);
        transform: translateY(-2px);
    }
    .exec-kpi-label { font-size: 0.78rem; font-weight: 600; color: #475569; line-height: 1.3; }
    .exec-kpi-icon {
        width: 34px; height: 34px; border-radius: 9px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.92rem; flex-shrink: 0;
    }
    .exec-kpi-value {
        font-size: 1.75rem; font-weight: 700; color: #0f172a;
        letter-spacing: -0.02em; line-height: 1.1;
    }
    .exec-kpi-unit { font-size: 0.82rem; font-weight: 500; color: #64748b; }
    .exec-kpi-footer {
        padding-top: 0.75rem; margin-top: 0.75rem; border-top: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; font-size: 0.75rem; color: #64748b;
    }
    .exec-kpi-link {
        color: #39048f; font-weight: 700; text-decoration: none;
        white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;
    }
    .exec-kpi-link:hover { color: #5b13df; text-decoration: underline; }

    /* Unified Operations Table Card */
    .exec-table-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        overflow: hidden;
    }
    .exec-filter-tabs {
        display: inline-flex; align-items: center; background-color: #f1f5f9;
        border: 1px solid #e2e8f0; border-radius: 10px; padding: 3px; gap: 2px; flex-wrap: wrap;
    }
    .exec-filter-tab {
        border: none; background: transparent; padding: 6px 14px; border-radius: 7px;
        font-size: 0.78rem; font-weight: 600; color: #475569; transition: all 0.15s ease; cursor: pointer;
    }
    .exec-filter-tab:hover { color: #0f172a; }
    .exec-filter-tab.active {
        background: #ffffff; color: #0f172a; font-weight: 700;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    }

    /* Category Pills inside Table */
    .erp-cat-pill {
        display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
        border-radius: 8px; font-size: 0.74rem; font-weight: 700; white-space: nowrap;
    }
    .erp-cat-purple { background-color: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }
    .erp-cat-rose   { background-color: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
    .erp-cat-blue   { background-color: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .erp-cat-amber  { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    .erp-cat-teal   { background-color: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4; }
    .erp-cat-indigo { background-color: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }

    /* Status Pills inside Table */
    .erp-status-pill {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        padding: 5px 12px; border-radius: 99px; font-size: 0.74rem; font-weight: 700; white-space: nowrap;
    }
    .erp-status-pending  { background-color: #fef9c3; color: #a16207; border: 1px solid #fde047; }
    .erp-status-approved { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    .erp-status-info     { background-color: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
    .erp-status-noshow   { background-color: #ffe4e6; color: #be123c; border: 1px solid #fda4af; }

    /* Action Button inside Table */
    .btn-table-action {
        padding: 5px 12px !important; font-size: 0.76rem !important; font-weight: 600 !important;
        border-radius: 8px !important; background-color: #ffffff; border: 1px solid #cbd5e1;
        color: #1e293b; text-decoration: none; display: inline-flex; align-items: center;
        justify-content: center; transition: all 0.15s ease; white-space: nowrap; cursor: pointer;
    }
    .btn-table-action:hover {
        background-color: #f4efff; border-color: #39048f; color: #39048f;
    }
    .exec-stream-row { cursor: pointer; }
    .exec-stream-row.selected-row > td { background-color: #f4efff !important; }

    /* Secondary Bento Cards */
    .exec-bento-card {
        background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03); height: 100%; display: flex; flex-direction: column; overflow: hidden;
    }
    .exec-bento-header {
        padding: 0.9rem 1.15rem; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between; background: #ffffff;
    }
    .exec-bento-title {
        font-size: 0.84rem; font-weight: 700; color: #0f172a;
        display: flex; align-items: center; gap: 8px; margin: 0;
    }
    .exec-bento-body { padding: 1.1rem 1.15rem; flex-grow: 1; }

    .exec-page-btn {
        border: 1px solid #e2e8f0; background: #ffffff; color: #475569;
        font-size: 0.76rem; font-weight: 600; padding: 4px 10px; border-radius: 7px; cursor: pointer; transition: all 0.15s ease;
    }
    .exec-page-btn:hover:not(:disabled) { background: #f1f5f9; color: #0f172a; }
    .exec-page-btn.active { background: #39048f; border-color: #39048f; color: #ffffff; }
    .exec-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }

    /* =========================================================
       SLIDE-OVER INSPECTION & APPROVAL DRAWER (Offcanvas Right)
       ========================================================= */
    #execInspectDrawer {
        width: 540px !important;
        max-width: 100vw;
        border-left: 1px solid #cbd5e1 !important;
        box-shadow: -16px 0 40px -8px rgba(15, 23, 42, 0.16) !important;
        z-index: 1060;
    }
    .offcanvas-backdrop.show {
        opacity: 0.35 !important;
        backdrop-filter: blur(2px);
    }
    .drawer-code-badge {
        background-color: #f3e8ff;
        color: #6b21a8;
        font-family: monospace;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 6px;
        border: 1px solid #e9d5ff;
    }
    .drawer-requester-card {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 16px;
    }
    .drawer-avatar-circle {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4c0582 0%, #5b21b6 100%);
        color: #ffffff;
        font-size: 1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(91, 33, 182, 0.2);
    }
    .drawer-kv-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 11px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.83rem;
    }
    .drawer-kv-row:last-child { border-bottom: none; }
    .drawer-kv-label {
        color: #64748b;
        font-weight: 500;
        min-width: 145px;
        flex-shrink: 0;
    }
    .drawer-kv-val {
        color: #0f172a;
        font-weight: 700;
        text-align: right;
        line-height: 1.45;
    }
    .drawer-workflow-box {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
    }
    .wf-step-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        position: relative;
        padding-bottom: 16px;
    }
    .wf-step-item:last-child { padding-bottom: 0; }
    .wf-step-item:not(:last-child)::before {
        content: "";
        position: absolute;
        left: 13px;
        top: 28px;
        bottom: 0;
        width: 2px;
        background-color: #e2e8f0;
    }
    .wf-step-item.done:not(:last-child)::before {
        background-color: #86efac;
    }
    .wf-step-circle {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.76rem;
        font-weight: 700;
        flex-shrink: 0;
        position: relative;
        z-index: 2;
    }
    .wf-step-item.done .wf-step-circle {
        background-color: #16a34a;
        color: #ffffff;
    }
    .wf-step-item.current .wf-step-circle {
        background-color: #f59e0b;
        color: #ffffff;
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.18);
    }
    .wf-step-item.waiting .wf-step-circle {
        background-color: #e2e8f0;
        color: #64748b;
    }
    .wf-badge-current {
        background-color: #fef3c7;
        color: #b45309;
        border: 1px solid #fde68a;
        font-size: 0.68rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 6px;
        white-space: nowrap;
    }
    .wf-badge-done {
        background-color: #dcfce7;
        color: #15803d;
        font-size: 0.68rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 6px;
        white-space: nowrap;
    }
    .wf-badge-wait {
        color: #94a3b8;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .btn-drawer-return {
        background-color: #fffbeb !important;
        border: 1px solid #fcd34d !important;
        color: #b45309 !important;
        font-weight: 700 !important;
        font-size: 0.82rem !important;
        padding: 8px 16px !important;
        border-radius: 8px !important;
    }
    .btn-drawer-return:hover {
        background-color: #fef3c7 !important;
        border-color: #f59e0b !important;
    }
    .btn-drawer-approve {
        background-color: #39048f !important;
        border: 1px solid #39048f !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 0.82rem !important;
        padding: 8px 18px !important;
        border-radius: 8px !important;
    }
    .btn-drawer-approve:hover {
        background-color: #4a06b8 !important;
    }
    /* Executive Perspective Tabs (Sub-Tabs Switcher) */
    .exec-perspective-container {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 8px;
        border-bottom: 1px solid #f1f5f9;
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .exec-perspective-container::-webkit-scrollbar {
        display: none;
    }
    .exec-perspective-tabs {
        display: inline-flex;
        background: #f1f5f9;
        padding: 5px;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        gap: 6px;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .exec-perspective-tab {
        border: none;
        background: transparent;
        padding: 8px 20px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #64748b;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .exec-perspective-tab:hover {
        color: #39048f;
        background: rgba(255, 255, 255, 0.7);
    }
    .exec-perspective-tab.active {
        background: #ffffff;
        color: #39048f;
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(57, 4, 143, 0.08);
    }
    .exec-perspective-tab .badge {
        font-size: 0.72rem;
        padding: 3px 8px;
        font-weight: 700;
    }
    .exec-pane-fade {
        animation: execFadeIn 0.22s ease-in-out;
    }
    @keyframes execFadeIn {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ---------------------------------------------------------
       Mobile & Tablet Responsive Optimizations (ERP 2.0)
       --------------------------------------------------------- */
    @media (max-width: 991.98px) {
        .exec-hub-wrapper {
            padding-left: 0.65rem !important;
            padding-right: 0.65rem !important;
            overflow-x: hidden !important;
            max-width: 100% !important;
            min-width: 0 !important;
            width: 100% !important;
        }
        .exec-main-title {
            font-size: 1.15rem;
            line-height: 1.3;
        }
        .exec-breadcrumb {
            font-size: 0.7rem;
        }
        .exec-header-actions {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            scrollbar-width: none !important;
            padding-bottom: 4px !important;
            margin-top: 2px !important;
            flex-wrap: nowrap !important;
        }
        .exec-header-actions::-webkit-scrollbar {
            display: none !important;
        }
        .exec-header-actions > * {
            flex-shrink: 0 !important;
        }
        .btn-exec-outline, .btn-exec-primary {
            height: 32px !important;
            padding: 0 10px !important;
            font-size: 0.74rem !important;
        }
        .exec-fy-pill {
            padding: 4px 10px;
            font-size: 0.74rem;
        }
        .exec-perspective-container {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin-bottom: 14px !important;
            padding-bottom: 6px !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            scrollbar-width: none !important;
        }
        .exec-perspective-container::-webkit-scrollbar {
            display: none !important;
        }
            padding-bottom: 4px;
        }
        .exec-perspective-tabs {
            padding: 3px;
            gap: 4px;
        }
        .exec-perspective-tab {
            padding: 6px 12px;
            font-size: 0.78rem;
            border-radius: 8px;
        }
        .exec-perspective-tab .badge {
            font-size: 0.68rem;
            padding: 2px 6px;
        }
        .exec-kpi-card {
            padding: 0.95rem;
            border-radius: 12px;
        }
        .exec-kpi-value {
            font-size: 1.45rem;
        }
        .exec-bento-header {
            padding: 0.75rem 0.95rem;
        }
        .exec-bento-body {
            padding: 0.85rem 0.95rem;
        }
        .exec-filter-tabs {
            display: flex !important;
            flex-wrap: nowrap !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            scrollbar-width: none !important;
            max-width: 100% !important;
            padding: 3px !important;
        }
        .exec-filter-tabs::-webkit-scrollbar {
            display: none !important;
        }
        .exec-filter-tab {
            flex-shrink: 0 !important;
            white-space: nowrap !important;
            padding: 5px 9px !important;
            font-size: 0.73rem !important;
        }
        #execInspectDrawer {
            width: 100% !important;
        }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pb-5 exec-hub-wrapper">
    
    <?php if (!empty($drawer_flash_msg)): ?>
        <div class="alert alert-<?php echo $drawer_flash_type; ?> alert-dismissible fade show mt-3 mb-0 border-0 shadow-sm rounded-3 py-2 px-3 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid <?php echo $drawer_flash_type === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-warning'; ?>"></i>
            <span class="small fw-bold"><?php echo htmlspecialchars($drawer_flash_msg); ?></span>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- =========================================================
         1. TOP COMMAND HEADER & FISCAL YEAR SWITCHER
         ========================================================= -->
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center pt-3 pb-3 mb-3 gap-3">
        <div>
            <div class="exec-breadcrumb mb-1">
                MSU ERP <span class="mx-1">›</span> Executive Dashboard & Operations Hub
            </div>
            <h1 class="exec-main-title mb-0">
                ศูนย์ควบคุมและบริหารจัดการกลาง
            </h1>
        </div>

        <div class="d-flex align-items-center gap-2 exec-header-actions">
            <div class="exec-fy-segmented">
                <?php foreach ($top_fys as $fy_pill): ?>
                    <a href="index.php?view=executive&fiscal_year=<?php echo urlencode($fy_pill); ?>" class="exec-fy-pill <?php echo (!$is_all_years && $selected_fy == $fy_pill) ? 'active' : ''; ?>">
                        ปีงบ <?php echo htmlspecialchars($fy_pill); ?>
                    </a>
                <?php endforeach; ?>
                <a href="index.php?view=executive&fiscal_year=all" class="exec-fy-pill <?php echo $is_all_years ? 'active' : ''; ?>">
                    ทั้งหมด
                </a>
            </div>

            <button class="btn-exec-outline d-none d-lg-inline-flex" type="button" onclick="toggleErpDensity()" title="สลับความหนาแน่นของตารางและหน้าจอ (Compact / Cozy)">
                <i class="fa-solid fa-bars text-secondary"></i>
                <span class="js-density-label">Density: Cozy</span>
            </button>

            <button class="btn-exec-outline d-none d-lg-inline-flex" type="button" data-bs-toggle="collapse" data-bs-target="#execCalendarCollapse" aria-expanded="false" title="เปิดปฏิทินการลา/ไปราชการบุคลากร">
                <i class="fa-solid fa-calendar-days text-purple"></i>
                <span>ปฏิทินบุคลากร</span>
            </button>

            <a href="index.php?view=personal" class="btn-exec-outline d-none d-lg-inline-flex" title="สลับไปยังพื้นที่ทำงานส่วนตัวของฉัน">
                <i class="fa-solid fa-user-check text-purple"></i>
                <span>งานส่วนตัว</span>
            </a>

            <!-- Mobile Extra Tools Menu (< 992px) -->
            <div class="dropdown d-lg-none">
                <button class="btn-exec-outline px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="เมนูเครื่องมือเพิ่มเติม">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" style="border-radius: 12px; min-width: 200px; border: 1px solid #e2e8f0 !important;">
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="index.php?view=personal"><i class="fa-solid fa-user-check text-purple me-2 w-20px"></i>งานส่วนตัวของฉัน</a></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="javascript:void(0)" data-bs-toggle="collapse" data-bs-target="#execCalendarCollapse"><i class="fa-solid fa-calendar-days text-purple me-2 w-20px"></i>เปิด/ปิดปฏิทินบุคลากร</a></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="javascript:void(0)" onclick="toggleErpDensity()"><i class="fa-solid fa-table-cells-large text-secondary me-2 w-20px"></i>สลับความหนาแน่น (Density)</a></li>
                </ul>
            </div>

            <div class="dropdown">
                <button class="btn btn-exec-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-plus"></i>
                    <span>สร้างรายการใหม่</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" style="border-radius: 12px; min-width: 220px; border: 1px solid #e2e8f0 !important;">
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/trainings/create.php"><i class="fa-solid fa-plane-departure text-purple me-2 w-20px"></i>ขออนุมัติไปราชการ / อบรม</a></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/leave/create.php"><i class="fa-solid fa-envelope-open-text text-danger me-2 w-20px"></i>ยื่นใบลาอิเล็กทรอนิกส์</a></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/worklog/create.php"><i class="fa-solid fa-calendar-check text-success me-2 w-20px"></i>บันทึกปฏิบัติงาน (Worklog)</a></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/room_booking/my_bookings.php"><i class="fa-solid fa-door-open text-warning me-2 w-20px"></i>จองห้องประชุม / สถานที่</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="requests_list.php"><i class="fa-solid fa-inbox text-primary me-2 w-20px"></i>เปิดกล่องงานรออนุมัติทั้งหมด</a></li>
                </ul>
            </div>
        </div>
    </div>

        <!-- =========================================================
         2. EXECUTIVE PERSPECTIVE TABS (SUB-TABS SWITCHER)
         ========================================================= -->
    <div class="exec-perspective-container">
        <div class="exec-perspective-tabs" id="execPerspectiveTabs">
            <button type="button" class="exec-perspective-tab active" data-tab-target="pane-approvals" onclick="switchExecPerspective('pane-approvals', this)">
                <i class="fa-solid fa-bolt-lightning text-warning me-2"></i>
                <span>การอนุมัติและคิวงานด่วน</span>
                <?php if ($universal_queue_total > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-2"><?php echo $universal_queue_total; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="exec-perspective-tab" data-tab-target="pane-daily" onclick="switchExecPerspective('pane-daily', this)">
                <i class="fa-solid fa-building-user text-primary me-2"></i>
                <span>สถานะปฏิบัติการรายวัน</span>
                <span class="badge bg-primary-subtle text-primary rounded-pill ms-2"><?php echo $attendance_stats['present']; ?> เข้างาน</span>
            </button>
            <button type="button" class="exec-perspective-tab" data-tab-target="pane-strategic" onclick="switchExecPerspective('pane-strategic', this)">
                <i class="fa-solid fa-chart-pie text-success me-2"></i>
                <span>ยุทธศาสตร์และการคลัง</span>
                <span class="badge bg-success-subtle text-success rounded-pill ms-2">ปีงบ <?php echo htmlspecialchars($selected_fy); ?></span>
            </button>
        </div>
    </div>

    <!-- =========================================================
         PANE 1: การอนุมัติและคิวงานด่วน (DEFAULT ACTIVE)
         ========================================================= -->
    <div class="exec-perspective-pane exec-pane-fade" id="pane-approvals">
        <!-- =========================================================
         3. 4 EXECUTIVE TELEMETRY KPI CARDS (UNIFORM GRID)
         ========================================================= -->
    <div class="row g-3 mb-4">
        
        <!-- Card 1: คำร้องรอพิจารณา (Universal Queue) -->
        <div class="col-xl-3 col-md-6">
            <div class="exec-kpi-card">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <span class="exec-kpi-label">คำร้องรอพิจารณา (Universal Queue)</span>
                        <span class="exec-kpi-icon" style="background-color: #fef3c7; color: #d97706;">
                            <i class="fa-solid fa-clock"></i>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline">
                        <div>
                            <span class="exec-kpi-value"><?php echo number_format($universal_queue_total); ?></span>
                            <span class="exec-kpi-unit ms-1">รายการ</span>
                        </div>
                        <?php if ($universal_queue_total > 0): ?>
                            <span class="fw-bold" style="font-size: 0.76rem; color: #e11d48;">
                                <i class="fa-solid fa-arrow-trend-up me-1"></i>+<?php echo $universal_queue_total; ?> รายการ
                            </span>
                        <?php else: ?>
                            <span class="fw-bold" style="font-size: 0.76rem; color: #16a34a;">
                                <i class="fa-solid fa-check me-1"></i>ครบถ้วน
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="exec-kpi-footer">
                    <span class="text-truncate" title="<?php echo htmlspecialchars($queue_summary_text); ?>"><?php echo htmlspecialchars($queue_summary_text); ?></span>
                    <a href="requests_list.php" class="exec-kpi-link">ตรวจสอบ <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <!-- Card 2: อัตราการใช้งานห้องซ้อมดนตรี (Smart Terminal) -->
        <div class="col-xl-3 col-md-6">
            <div class="exec-kpi-card">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <span class="exec-kpi-label">อัตราการใช้งานห้องซ้อมดนตรี (Smart Terminal)</span>
                        <span class="exec-kpi-icon" style="background-color: #f3e8ff; color: #7e22ce;">
                            <i class="fa-solid fa-guitar"></i>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline">
                        <div>
                            <span class="exec-kpi-value"><?php echo number_format($practice_telemetry['utilization_pct'], 1); ?>%</span>
                        </div>
                        <span class="fw-bold" style="font-size: 0.76rem; color: #16a34a;">
                            <i class="fa-solid fa-arrow-trend-up me-1"></i>วันนี้ <?php echo number_format($practice_bookings_today); ?> รอบ
                        </span>
                    </div>
                </div>
                <div class="exec-kpi-footer">
                    <span class="text-truncate">Check-in สำเร็จ: <?php echo $practice_telemetry['checkin_rate']; ?>% | ไม่มา: <?php echo $practice_telemetry['noshow_rate']; ?>%</span>
                    <a href="modules/practice_rooms/index.php" class="exec-kpi-link">จัดการห้อง <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <!-- Card 3: งบประมาณโครงการและพัฒนาบุคลากร -->
        <div class="col-xl-3 col-md-6">
            <div class="exec-kpi-card">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <span class="exec-kpi-label">งบประมาณพัฒนาบุคลากร (ไปราชการ)</span>
                        <span class="exec-kpi-icon" style="background-color: #dcfce7; color: #16a34a;">
                            <i class="fa-solid fa-wallet"></i>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                        <span class="exec-kpi-value" style="font-size: 1.55rem;">฿ <?php echo number_format($total_budget_used, 0); ?></span>
                        <span class="exec-kpi-unit" style="font-size: 0.74rem;">จาก ฿ <?php echo number_format($total_budget_allocated, 0); ?></span>
                    </div>
                    <div class="progress" style="height: 6px; background-color: #e2e8f0; border-radius: 99px;">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo $budget_used_pct; ?>%; background-color: #10b981; border-radius: 99px;"></div>
                    </div>
                </div>
                <div class="exec-kpi-footer mt-2">
                    <span>ใช้ไปแล้ว <?php echo $budget_used_pct; ?>%</span>
                    <span class="fw-semibold text-secondary">คงเหลือ ฿ <?php echo number_format($budget_remaining, 0); ?></span>
                </div>
            </div>
        </div>

        <!-- Card 4: คำร้องขอเปิดรายวิชา / สำรองที่นั่ง -->
        <div class="col-xl-3 col-md-6">
            <div class="exec-kpi-card">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <span class="exec-kpi-label">คำร้องขอเปิดรายวิชา / สำรองที่นั่ง</span>
                        <span class="exec-kpi-icon" style="background-color: #dbeafe; color: #2563eb;">
                            <i class="fa-solid fa-book-open"></i>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline">
                        <div>
                            <span class="exec-kpi-value"><?php echo number_format($academic_req_stats['total'] ?? 0); ?></span>
                            <span class="exec-kpi-unit ms-1">วิชา</span>
                        </div>
                        <span class="fw-bold" style="font-size: 0.76rem; color: #4f46e5;">
                            <i class="fa-solid fa-check me-1"></i>อนุมัติแล้ว <?php echo number_format($academic_req_stats['approved'] ?? 0); ?>
                        </span>
                    </div>
                </div>
                <div class="exec-kpi-footer">
                    <span class="text-truncate">รอพิจารณา: <?php echo number_format($academic_req_stats['pending'] ?? 0); ?> | เปิดวิชา: <?php echo number_format($academic_req_stats['open_course'] ?? 0); ?></span>
                    <a href="modules/open_course/dashboard_executive.php" class="exec-kpi-link">Tracking <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </div>

    </div>

        <!-- =========================================================
         4. UNIFIED LIVE OPERATIONS & APPROVAL QUEUE TABLE
         ========================================================= -->
    <div class="exec-table-card mb-4">
        
        <div class="p-3 border-bottom d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div class="exec-filter-tabs" id="execStreamTabs">
                <button type="button" class="exec-filter-tab active" data-status-filter="all">
                    ทั้งหมด (<?php echo $stream_counts['all']; ?>)
                </button>
                <button type="button" class="exec-filter-tab" data-status-filter="pending">
                    รอพิจารณา (<?php echo $stream_counts['pending']; ?>)
                </button>
                <button type="button" class="exec-filter-tab" data-status-filter="approved">
                    อนุมัติแล้ว (<?php echo $stream_counts['approved']; ?>)
                </button>
                <button type="button" class="exec-filter-tab" data-status-filter="noshow">
                    ส่งกลับ / ไม่อนุมัติ (<?php echo $stream_counts['noshow']; ?>)
                </button>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 exec-header-actions">
                <div class="position-relative" style="min-width: 230px;">
                    <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left: 11px; top: 50%; transform: translateY(-50%); font-size: 0.78rem;"></i>
                    <input type="text" id="execStreamSearch" class="form-control form-control-sm ps-4" style="padding-left: 30px !important; height: 34px; font-size: 0.78rem;" placeholder="กรองในตารางนี้...">
                </div>

                <select id="execModuleFilter" class="form-select form-select-sm" style="width: auto; height: 34px; font-size: 0.78rem; font-weight: 600; color: #334155;">
                    <option value="all">▼ ระบบอนุมัติหลักทั้งหมด</option>
                    <option value="leave">📄 ระบบการลาบุคลากร (E-Leave)</option>
                    <option value="training">✈️ ไปราชการ / ฝึกอบรม</option>
                    <option value="academic">🎓 ขอเปิดรายวิชา / สำรองที่นั่ง</option>
                    <option value="supplies">📦 เบิกวัสดุ / พัสดุกลาง</option>
                    <option value="room">🏢 จองห้อง / สถานที่</option>
                    <option value="repair">🔧 แจ้งซ่อมบำรุง</option>
                    <option value="complaint">📢 เรื่องร้องเรียน</option>
                    <option value="grade_appeal">📊 อุทธรณ์คะแนน</option>
                </select>

                <button type="button" class="btn-exec-outline" style="height: 34px;" onclick="exportExecStreamToCsv()">
                    <i class="fa-solid fa-file-excel text-success"></i>
                    <span>ส่งออก Excel</span>
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="execUnifiedTable">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="text-center">
                            <input type="checkbox" class="form-check-input" id="selectAllExecRows">
                        </th>
                        <th style="width: 120px;">รหัสรายการ</th>
                        <th style="min-width: 200px;">ผู้ยื่นคำขอ / ผู้ทำรายการ</th>
                        <th style="width: 150px;">หมวดหมู่ / ระบบ</th>
                        <th style="min-width: 260px;">หัวข้อ / รายละเอียด</th>
                        <th style="width: 145px;">วัน-เวลา / กำหนดการ</th>
                        <th style="width: 190px;" class="text-center">สถานะการอนุมัติ</th>
                        <th style="width: 115px;" class="text-center">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody id="execUnifiedTbody">
                    <?php if (empty($unified_stream)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-inbox fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0 small">ยังไม่มีรายการความเคลื่อนไหวในระบบ</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($unified_stream as $idx => $row): ?>
                            <tr class="exec-stream-row"
                                data-stream-index="<?php echo $idx; ?>"
                                data-status="<?php echo htmlspecialchars($row['status_group']); ?>"
                                data-module="<?php echo htmlspecialchars($row['module_key']); ?>"
                                onclick="openExecInspectDrawer(<?php echo $idx; ?>, event)">
                                <td class="text-center" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="form-check-input exec-row-check">
                                </td>
                                <td>
                                    <span class="fw-bold text-dark font-monospace" style="font-size: 0.82rem;">
                                        <?php echo htmlspecialchars($row['code']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 0.83rem; line-height: 1.3;">
                                        <?php echo htmlspecialchars($row['requester']); ?>
                                    </div>
                                    <div class="text-muted text-truncate" style="font-size: 0.73rem; max-width: 220px;">
                                        <?php echo htmlspecialchars($row['requester_sub']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="erp-cat-pill <?php echo htmlspecialchars($row['module_badge']); ?>">
                                        <i class="fa-solid <?php echo htmlspecialchars($row['module_icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($row['module_label']); ?></span>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 0.83rem; line-height: 1.35;">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                    </div>
                                    <?php if (!empty($row['subtitle_err'])): ?>
                                        <div class="fw-semibold" style="font-size: 0.73rem; color: #e11d48;">
                                            <?php echo htmlspecialchars($row['subtitle_err']); ?>
                                        </div>
                                    <?php elseif (!empty($row['subtitle'])): ?>
                                        <div class="text-muted text-truncate" style="font-size: 0.73rem; max-width: 340px;" title="<?php echo htmlspecialchars($row['subtitle']); ?>">
                                            <?php echo htmlspecialchars($row['subtitle']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 0.81rem;">
                                        <?php echo htmlspecialchars($row['date_main']); ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.72rem;">
                                        <?php echo htmlspecialchars($row['date_sub']); ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php $pill_cls = $row['status_pill_class'] ?? 'erp-status-approved'; ?>
                                    <?php if ($pill_cls === 'erp-status-pending'): ?>
                                        <span class="erp-status-pill erp-status-pending">
                                            <span style="width:6px;height:6px;border-radius:50%;background:#d97706;display:inline-block;"></span>
                                            <span><?php echo htmlspecialchars($row['status_label']); ?></span>
                                        </span>
                                    <?php elseif ($pill_cls === 'erp-status-noshow'): ?>
                                        <span class="erp-status-pill erp-status-noshow">
                                            <i class="fa-solid fa-user-xmark"></i>
                                            <span><?php echo htmlspecialchars($row['status_label']); ?></span>
                                        </span>
                                    <?php elseif ($pill_cls === 'erp-status-info'): ?>
                                        <span class="erp-status-pill erp-status-info">
                                            <i class="fa-regular fa-calendar-check"></i>
                                            <span><?php echo htmlspecialchars($row['status_label']); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="erp-status-pill erp-status-approved">
                                            <i class="fa-solid fa-check"></i>
                                            <span><?php echo htmlspecialchars($row['status_label']); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" onclick="event.stopPropagation();">
                                    <button type="button" class="btn-table-action <?php echo !empty($row['can_approve']) ? 'border-purple text-purple fw-bold' : ''; ?>" onclick="openExecInspectDrawer(<?php echo $idx; ?>, event)">
                                        <?php echo htmlspecialchars($row['action_label']); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-3 py-2 bg-light border-top d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <div class="text-muted" style="font-size: 0.76rem;" id="execTableInfo">
                กำลังแสดงรายการทั้งหมด
            </div>
            <div class="d-flex align-items-center gap-1" id="execTablePagination"></div>
        </div>
    </div>
    </div>

    <!-- =========================================================
         PANE 2: สถานะปฏิบัติการรายวัน (DAILY OPERATIONS)
         ========================================================= -->
    <div class="exec-perspective-pane d-none" id="pane-daily">
        <div class="p-3 mb-3 bg-white rounded-4 border shadow-2xs d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-building-user text-primary me-2"></i>สถานะประจำวันและทรัพยากรบุคคล (Daily Operations Pulse)</h6>
                <small class="text-muted">ข้อมูลอัปเดตแบบเรียลไทม์ ประจำวันที่ <?php echo date('d/m/Y'); ?> • ลงเวลาเข้างาน, ประชุม, การลา และงานอาคารสถานที่</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark border px-3 py-2">
                    <i class="fa-regular fa-calendar-check text-success me-1"></i>เข้างานปกติ <?php echo $attendance_stats['present'] - $attendance_stats['late']; ?> คน
                </span>
                <button class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#execCalendarCollapse">
                    <i class="fa-solid fa-calendar-days me-1"></i> เปิด/ปิดปฏิทินบุคลากร
                </button>
            </div>
        </div>

        <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-user-clock text-success"></i><span>การลงเวลาปฏิบัติงานวันนี้</span></h6>
                    <span class="badge bg-success-subtle text-success rounded-pill"><?php echo $attendance_stats['present']; ?> คน</span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-around text-center my-2">
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-dark mb-0"><?php echo $attendance_stats['present']; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">สแกนเข้างาน</div>
                        </div>
                        <div class="vr opacity-25" style="height: 38px;"></div>
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-danger mb-0"><?php echo $attendance_stats['late']; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">หลัง 09:00 น.</div>
                        </div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">Mood เฉลี่ย: <strong class="text-dark"><?php echo number_format($worklog_stats['avg_mood'], 1); ?>/5.0</strong></span>
                        <a href="modules/attendance/index.php" class="exec-kpi-link">ดูเวลาเข้างาน <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-handshake text-primary"></i><span>วาระการประชุมวันนี้</span></h6>
                    <span class="badge bg-primary-subtle text-primary rounded-pill"><?php echo count($meetings_today); ?> นัดหมาย</span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <?php if (empty($meetings_today)): ?>
                        <div class="text-center py-3 text-muted small">ไม่มีกำหนดการประชุมในวันนี้</div>
                    <?php else: ?>
                        <div style="max-height: 110px; overflow-y: auto;">
                            <?php foreach (array_slice($meetings_today, 0, 2) as $m): ?>
                                <div class="mb-2 pb-1 border-bottom last-no-border">
                                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.78rem;"><?php echo htmlspecialchars($m['title']); ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;">
                                        <i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($m['start_time'])); ?> น. • <?php echo htmlspecialchars($m['location'] ?: 'ออนไลน์'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">ห้อง Zoom วันนี้: <strong><?php echo $zoom_today; ?></strong></span>
                        <a href="modules/meetings/index.php" class="exec-kpi-link">ระบบประชุม <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-plane-departure text-warning"></i><span>ลาหยุด / ไปราชการวันนี้</span></h6>
                    <span class="badge bg-warning-subtle text-dark rounded-pill"><?php echo count($leaves_today); ?> คน</span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <?php if (empty($leaves_today)): ?>
                        <div class="text-center py-3 text-muted small">บุคลากรปฏิบัติงานครบทุกท่าน</div>
                    <?php else: ?>
                        <div style="max-height: 110px; overflow-y: auto;">
                            <?php foreach (array_slice($leaves_today, 0, 3) as $l): ?>
                                <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                                    <span class="fw-bold text-dark text-truncate" style="font-size: 0.76rem; max-width: 145px;">
                                        <?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']); ?>
                                    </span>
                                    <span class="badge bg-light text-dark border" style="font-size: 0.66rem;"><?php echo htmlspecialchars($l['type_name']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">ใบลารออนุมัติ: <strong class="text-danger"><?php echo $pending_leaves_count; ?></strong></span>
                        <a href="modules/leave/approve_list.php" class="exec-kpi-link">จัดการใบลา <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-screwdriver-wrench text-purple"></i><span>อาคารสถานที่ & แจ้งซ่อม</span></h6>
                    <span class="badge bg-purple-subtle text-purple rounded-pill">จองห้องวันนี้ <?php echo $room_bookings_today; ?></span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-around text-center my-2">
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-danger mb-0"><?php echo $repair_stats['pending']; ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">รอซ่อม</div>
                        </div>
                        <div class="vr opacity-25" style="height: 32px;"></div>
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-warning mb-0"><?php echo $repair_stats['in_progress']; ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">กำลังซ่อม</div>
                        </div>
                        <div class="vr opacity-25" style="height: 32px;"></div>
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-success mb-0"><?php echo $repair_stats['completed']; ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">เสร็จแล้ว</div>
                        </div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">จองห้องรออนุมัติ: <strong><?php echo $pending_room_bookings; ?></strong></span>
                        <a href="modules/repair_request/index.php" class="exec-kpi-link">รายการซ่อม <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <div class="collapse mb-4" id="execCalendarCollapse">
        <div class="exec-bento-card p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-calendar-days text-purple me-2"></i>ปฏิทินการลาและไปราชการของบุคลากร</h6>
                <div class="d-flex flex-wrap gap-3 small">
                    <span><i class="fa-solid fa-circle text-success me-1"></i>ลาหยุด (อนุมัติแล้ว)</span>
                    <span><i class="fa-solid fa-circle text-warning me-1"></i>ลาหยุด (รออนุมัติ)</span>
                    <span><i class="fa-solid fa-circle text-primary me-1"></i>ไปราชการ (อนุมัติแล้ว)</span>
                </div>
            </div>
            <div id="calendar" style="min-height: 520px;"></div>
        </div>
    </div>
    </div>

    <!-- =========================================================
         PANE 3: ยุทธศาสตร์และการคลัง (STRATEGIC & FINANCE)
         ========================================================= -->
    <div class="exec-perspective-pane d-none" id="pane-strategic">
        <div class="p-3 mb-3 bg-white rounded-4 border shadow-2xs d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-success me-2"></i>ภาพรวมยุทธศาสตร์ แผนงาน และการคลัง (Strategic & Enterprise Health)</h6>
                <small class="text-muted">ข้อมูลสรุปงบประมาณ จัดซื้อจัดจ้าง โครงการตามแผน และฐานข้อมูลวิจัย/KM <?php echo $is_all_years ? 'ทุกปีงบประมาณ' : 'ประจำปีงบประมาณ ' . htmlspecialchars($selected_fy); ?></small>
            </div>
            <span class="badge bg-purple text-white px-3 py-2 rounded-pill fw-bold">
                ปีงบประมาณ <?php echo htmlspecialchars($selected_fy); ?>
            </span>
        </div>

        <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-money-bill-transfer text-success"></i><span>สถานะการเงินรอโอน</span></h6>
                    <span class="badge bg-success-subtle text-success rounded-pill"><?php echo $is_all_years ? 'ทุกปี' : 'ปี ' . htmlspecialchars($selected_fy); ?></span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted" style="font-size: 0.72rem;">ยอดรอเบิกจ่ายรวม</div>
                        <div class="h4 fw-bold text-success mb-1">฿ <?php echo number_format($finance_stats['pending_amount'], 2); ?></div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">สัญญายืมค้างเคลียร์: <strong class="text-danger"><?php echo $finance_stats['advance_count']; ?></strong></span>
                        <a href="modules/finance/index.php" class="exec-kpi-link">ดูการเงิน <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-truck-fast text-primary"></i><span>ติดตามงานจัดซื้อ/จัดจ้าง</span></h6>
                    <span class="badge bg-primary-subtle text-primary rounded-pill"><?php echo $procure_stats['total'] ?? 0; ?> โครงการ</span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-around text-center my-2">
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $procure_stats['completed'] ?? 0; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">เสร็จสิ้นแล้ว</div>
                        </div>
                        <div class="vr opacity-25" style="height: 38px;"></div>
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-primary mb-0"><?php echo $procure_stats['in_progress'] ?? 0; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">กำลังดำเนินการ</div>
                        </div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">ใบเบิกวัสดุรอจ่าย: <strong><?php echo $pending_supplies_count; ?></strong></span>
                        <a href="modules/procurement/index.php" class="exec-kpi-link">ระบบพัสดุ <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-microscope text-purple"></i><span>วิจัย, KM และจิตอาสา กยศ.</span></h6>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-around text-center my-2">
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-dark mb-0"><?php echo number_format($research_stats['total']); ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">งานวิจัย</div>
                        </div>
                        <div class="vr opacity-25" style="height: 32px;"></div>
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-purple mb-0"><?php echo number_format($km_stats['total_posts']); ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">บทความ KM</div>
                        </div>
                        <div class="vr opacity-25" style="height: 32px;"></div>
                        <div class="flex-fill">
                            <div class="h5 fw-bold text-warning mb-0"><?php echo number_format($klof_approved_hours); ?></div>
                            <div class="text-muted" style="font-size: 0.68rem;">ชม. กยศ.</div>
                        </div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">กยศ. รออนุมัติ: <strong class="text-danger"><?php echo $pending_klof; ?></strong></span>
                        <a href="modules/research/index.php" class="exec-kpi-link">ฐานข้อมูลวิจัย <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="exec-bento-card">
                <div class="exec-bento-header">
                    <h6 class="exec-bento-title"><i class="fa-solid fa-diagram-project text-danger"></i><span>ติดตามโครงการตามแผน</span></h6>
                    <span class="badge <?php echo ($proj_stats['delayed'] > 0) ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?> rounded-pill">
                        ล่าช้า <?php echo $proj_stats['delayed'] ?? 0; ?>
                    </span>
                </div>
                <div class="exec-bento-body d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-around text-center my-2">
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-dark mb-0"><?php echo $proj_stats['total'] ?? 0; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">โครงการทั้งหมด</div>
                        </div>
                        <div class="vr opacity-25" style="height: 38px;"></div>
                        <div class="flex-fill">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $proj_stats['completed'] ?? 0; ?></div>
                            <div class="text-muted" style="font-size: 0.72rem;">สำเร็จแล้ว</div>
                        </div>
                    </div>
                    <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                        <span class="text-muted">งานด่วน/ติดปัญหา: <strong class="text-danger"><?php echo $worklog_stats['issue_tasks']; ?></strong></span>
                        <a href="modules/projects/index.php" class="exec-kpi-link">ดูโครงการ <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

</main>

<!-- =========================================================
     SLIDE-OVER INSPECTION & APPROVAL DRAWER (Enterprise Offcanvas)
     ========================================================= -->
<div class="offcanvas offcanvas-end bg-white" tabindex="-1" id="execInspectDrawer" aria-labelledby="execInspectDrawerLabel">
    
    <!-- Drawer Header -->
    <div class="offcanvas-header border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2 overflow-hidden">
            <span class="drawer-code-badge" id="drawerCodeBadge">REQ-0000</span>
            <h6 class="fw-bold text-dark mb-0 text-truncate" id="execInspectDrawerLabel">รายละเอียดคำขอ</h6>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <button type="button" class="btn btn-sm btn-light border text-secondary" onclick="window.print()" title="พิมพ์ใบคำขอ">
                <i class="fa-solid fa-print"></i>
            </button>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
    </div>

    <!-- Drawer Scrollable Body -->
    <div class="offcanvas-body p-4" style="overflow-y: auto;">
        
        <!-- 1. Requester Profile Card -->
        <div class="drawer-requester-card d-flex align-items-center gap-3 mb-4">
            <div class="drawer-avatar-circle position-relative overflow-hidden flex-shrink-0" id="drawerAvatarWrap">
                <img id="drawerAvatarImg" src="" referrerpolicy="no-referrer" class="w-100 h-100" style="object-fit: cover; display: none;" alt="Profile" onerror="this.style.display='none'; document.getElementById('drawerInitials').style.display='flex';">
                <span id="drawerInitials" style="display: flex;">กม</span>
            </div>
            <div class="overflow-hidden flex-grow-1">
                <div class="fw-bold text-dark" style="font-size: 0.95rem;" id="drawerReqName">-</div>
                <div class="text-muted" style="font-size: 0.78rem;" id="drawerReqSub">-</div>
                <div class="text-muted mt-1 d-flex flex-wrap align-items-center gap-2" style="font-size: 0.74rem;">
                    <span><i class="fa-solid fa-phone me-1 opacity-75"></i><span id="drawerReqPhone">-</span></span>
                    <span>•</span>
                    <span><i class="fa-regular fa-envelope me-1 opacity-75"></i><span id="drawerReqEmail">-</span></span>
                </div>
            </div>
        </div>

        <!-- 2. Request Details Section -->
        <h6 class="fw-bold text-dark mb-2" style="font-size: 0.86rem;" id="drawerDetailsHeading">รายละเอียดคำขอ</h6>
        <div class="mb-4" id="drawerDetailsList"></div>

        <!-- 3. Enterprise Workflow Section -->
        <div class="text-uppercase fw-bold text-muted mb-2" style="font-size: 0.7rem; letter-spacing: 0.05em;" id="drawerWorkflowTitle">
            สถานะขั้นตอนการพิจารณา (ENTERPRISE WORKFLOW)
        </div>
        <div class="drawer-workflow-box mb-4" id="drawerWorkflowBox"></div>

        <!-- 4. Approval Comment & Action Form -->
        <form method="POST" action="index.php?view=executive&fiscal_year=<?php echo urlencode($selected_fy); ?>" id="drawerActionForm">
            <input type="hidden" name="exec_quick_action" id="drawerQuickActionInput" value="approve">
            <input type="hidden" name="module_key" id="drawerModuleKeyInput" value="">
            <input type="hidden" name="record_id" id="drawerRecordIdInput" value="">

            <div id="drawerCommentSection">
                <label class="form-label fw-bold text-dark" style="font-size: 0.82rem;">
                    ความเห็น / ข้อเสนอแนะประกอบการพิจารณา
                </label>
                <textarea name="exec_comment" id="drawerCommentTextarea" class="form-control" rows="3" placeholder="ระบุข้อความคิดเห็นประกอบการอนุมัติ หรือเหตุผลกรณีส่งกลับ/ไม่อนุมัติ..." style="font-size: 0.82rem;"></textarea>
            </div>
        </form>

    </div>

    <!-- Drawer Sticky Footer -->
    <div class="border-top bg-white py-3 px-4 d-flex justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-light border fw-bold text-secondary px-3 py-2" style="font-size: 0.8rem;" data-bs-dismiss="offcanvas">
                ปิดหน้าต่าง
            </button>
            <a href="#" id="drawerFullPageLink" class="btn btn-light border text-purple fw-bold px-3 py-2" style="font-size: 0.8rem;" title="เปิดหน้าจัดการเต็ม">
                <i class="fa-solid fa-up-right-from-square"></i>
            </a>
        </div>

        <div id="drawerReadonlyStatusNote" class="text-end" style="display: none;">
            <span class="badge bg-light text-secondary border px-2 py-2 fw-semibold" style="font-size: 0.73rem;" id="drawerReadonlyStatusText"></span>
        </div>

        <div class="d-flex align-items-center gap-2" id="drawerApprovalButtons">
            <button type="button" class="btn btn-drawer-return" onclick="submitDrawerDecision('return')">
                <i class="fa-solid fa-rotate-left me-1"></i> ส่งกลับ/ไม่อนุมัติ
            </button>
            <button type="button" class="btn btn-drawer-approve" id="drawerApproveBtn" onclick="submitDrawerDecision('approve')">
                <i class="fa-solid fa-check me-1"></i> <span id="drawerApproveBtnLabel">อนุมัติคำขอนี้</span>
            </button>
        </div>
    </div>
</div>

<!-- Modal แสดงรายละเอียดปฏิทิน -->
<div class="modal fade" id="calendarEventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-4 text-center position-relative">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-3 d-flex align-items-center justify-content-center mx-auto" id="eventModalIconWrapper" style="width: 68px; height: 68px; border-radius: 16px;">
                    <i class="fa-solid fa-calendar-check fa-2x" id="eventModalIcon"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1" id="eventModalTitle">ชื่อเหตุการณ์</h5>
                <p class="text-muted small mb-3" id="eventModalDate"><i class="fa-regular fa-clock me-1"></i> วันที่</p>
                <div class="bg-light rounded-3 p-3 mb-3 text-start d-none border" id="eventModalDescBox">
                    <span class="small fw-bold text-dark"><i class="fa-solid fa-circle-info text-purple me-1"></i>รายละเอียด:</span>
                    <p class="text-muted small mb-0 mt-1" id="eventModalDesc" style="white-space: pre-line;"></p>
                </div>
                <button type="button" class="btn btn-light border px-4 py-2 fw-bold text-secondary" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<script>
// ข้อมูลรายการธุรกรรมทั้งหมดสำหรับแสดงใน Slide-Over Drawer
const execStreamData = <?php echo json_encode($unified_stream, JSON_UNESCAPED_UNICODE); ?>;

function escapeHtmlExec(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function openExecInspectDrawer(index, ev) {
    if (ev) ev.stopPropagation();
    const item = execStreamData[index];
    if (!item) return;

    // Highlight active row
    document.querySelectorAll('#execUnifiedTbody .exec-stream-row').forEach(tr => tr.classList.remove('selected-row'));
    const clickedRow = document.querySelector(`#execUnifiedTbody .exec-stream-row[data-stream-index="${index}"]`);
    if (clickedRow) clickedRow.classList.add('selected-row');

    // Populate Header & Profile
    document.getElementById('drawerCodeBadge').textContent = item.code || '';
    document.getElementById('execInspectDrawerLabel').textContent = item.drawer_header || 'รายละเอียดคำขอ';
    document.getElementById('drawerReqName').textContent = item.requester || '-';
    document.getElementById('drawerReqSub').textContent = item.requester_sub || '-';
    document.getElementById('drawerReqPhone').textContent = item.phone || '-';
    document.getElementById('drawerReqEmail').textContent = item.email || '-';

    // Google Profile Picture / Avatar Logic
    const drawerImgEl = document.getElementById('drawerAvatarImg');
    const drawerInitEl = document.getElementById('drawerInitials');
    let drawerAvatarUrl = '';
    if (item.avatar) {
        drawerAvatarUrl = /^https?:\/\//i.test(item.avatar) ? item.avatar : ('/music-e-office/uploads/avatars/' + item.avatar);
    }
    if (drawerAvatarUrl) {
        drawerImgEl.src = drawerAvatarUrl;
        drawerImgEl.style.display = 'block';
        drawerInitEl.style.display = 'none';
    } else {
        drawerImgEl.style.display = 'none';
        drawerInitEl.style.display = 'flex';
        drawerInitEl.textContent = item.initials || 'มม';
    }

    // Populate Details Heading & Key-Value List
    const detailsHeadingEl = document.getElementById('drawerDetailsHeading');
    if (detailsHeadingEl) {
        detailsHeadingEl.textContent = (item.module_key === 'practice') ? 'รายละเอียดการจองและการเข้าใช้งาน' : 'รายละเอียดคำขอ';
    }
    const detailsEl = document.getElementById('drawerDetailsList');
    let detailsHtml = '';
    (item.details || []).forEach(d => {
        let valStyle = '';
        if (d.highlight) valStyle = 'color: #16a34a;';
        if (d.is_error) valStyle = 'color: #e11d48;';
        detailsHtml += `
            <div class="drawer-kv-row">
                <div class="drawer-kv-label">${escapeHtmlExec(d.label)}</div>
                <div class="drawer-kv-val" style="${valStyle}">${escapeHtmlExec(d.value)}</div>
            </div>
        `;
    });
    detailsEl.innerHTML = detailsHtml;

    // Populate Dynamic Workflow / Usage Log Stepper
    const wfTitleEl = document.getElementById('drawerWorkflowTitle');
    if (wfTitleEl) {
        wfTitleEl.textContent = item.workflow_title || 'สถานะขั้นตอนการพิจารณา (ENTERPRISE WORKFLOW)';
    }

    const wfBox = document.getElementById('drawerWorkflowBox');
    const curStep = parseInt(item.workflow_current || 1, 10);
    let wfHtml = '';
    (item.workflow_steps || []).forEach(st => {
        const sNum = parseInt(st.step, 10);
        let stateClass = 'waiting';
        let circleHtml = sNum;
        const doneText = st.done_badge || 'เสร็จสิ้น';
        const waitText = st.wait_badge || 'รอคิว';
        let badgeHtml = `<span class="wf-badge-wait">${escapeHtmlExec(waitText)}</span>`;

        if (sNum < curStep || (item.status_group === 'approved' && item.has_approval_wf)) {
            stateClass = 'done';
            circleHtml = '<i class="fa-solid fa-check"></i>';
            badgeHtml = `<span class="wf-badge-done">${escapeHtmlExec(doneText)}</span>`;
        } else if (sNum === curStep) {
            if (item.status_group === 'noshow') {
                stateClass = 'current';
                circleHtml = '<i class="fa-solid fa-xmark"></i>';
                badgeHtml = `<span class="wf-badge-current" style="background:#ffe4e6;color:#be123c;border-color:#fda4af;">${escapeHtmlExec(item.status_label)}</span>`;
            } else if (!item.has_approval_wf) {
                stateClass = 'current';
                circleHtml = '<i class="fa-regular fa-clock"></i>';
                badgeHtml = `<span class="wf-badge-current" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;">${escapeHtmlExec(item.status_label)}</span>`;
            } else {
                stateClass = 'current';
                circleHtml = sNum;
                badgeHtml = '<span class="wf-badge-current">รอดำเนินการ</span>';
            }
        }

        const titleColor = (stateClass === 'waiting') ? 'text-muted' : 'text-dark fw-bold';
        wfHtml += `
            <div class="wf-step-item ${stateClass}">
                <div class="d-flex align-items-start gap-3 flex-grow-1">
                    <div class="wf-step-circle flex-shrink-0">${circleHtml}</div>
                    <div class="flex-grow-1">
                        <div class="${titleColor}" style="font-size: 0.82rem; line-height: 1.25;">${escapeHtmlExec(st.title)}</div>
                        ${st.name ? `<div class="fw-semibold text-dark mt-1" style="font-size: 0.74rem;"><i class="fa-solid fa-user-tie me-1 text-secondary"></i>${escapeHtmlExec(st.name)}</div>` : ''}
                        <div class="text-muted" style="font-size: 0.73rem; margin-top: 2px;">${escapeHtmlExec(st.desc)}</div>
                        ${st.comment ? `
                            <div class="mt-2 p-2 bg-white rounded-2 border text-dark shadow-xs" style="font-size: 0.74rem;">
                                <span class="text-muted fw-bold d-block mb-1"><i class="fa-regular fa-comment-dots me-1 text-purple"></i>ความเห็น:</span>
                                <div style="white-space: pre-line;">${escapeHtmlExec(st.comment)}</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="ms-2 flex-shrink-0">${badgeHtml}</div>
            </div>
        `;
    });
    wfBox.innerHTML = wfHtml;

    // Form hidden fields & action buttons
    document.getElementById('drawerModuleKeyInput').value = item.module_key || '';
    document.getElementById('drawerRecordIdInput').value = item.record_id || 0;
    document.getElementById('drawerCommentTextarea').value = '';
    document.getElementById('drawerFullPageLink').href = item.action_url || '#';

    const btnGroup = document.getElementById('drawerApprovalButtons');
    const commentSec = document.getElementById('drawerCommentSection');
    const approveBtnLabel = document.getElementById('drawerApproveBtnLabel');
    const readonlyNoteWrap = document.getElementById('drawerReadonlyStatusNote');
    const readonlyNoteText = document.getElementById('drawerReadonlyStatusText');

    if (item.can_approve) {
        btnGroup.style.display = 'flex';
        commentSec.style.display = 'block';
        if (approveBtnLabel) {
            approveBtnLabel.textContent = item.approve_btn_text || 'อนุมัติคำขอนี้';
        }
        if (readonlyNoteWrap) readonlyNoteWrap.style.display = 'none';
    } else {
        btnGroup.style.display = 'none';
        commentSec.style.display = 'none';
        if (readonlyNoteWrap && readonlyNoteText) {
            if (item.pending_step_note) {
                readonlyNoteText.innerHTML = `<i class="fa-solid fa-circle-info text-purple me-1"></i>${escapeHtmlExec(item.pending_step_note)}`;
                readonlyNoteWrap.style.display = 'block';
            } else {
                readonlyNoteWrap.style.display = 'none';
            }
        }
    }

    const drawerEl = document.getElementById('execInspectDrawer');
    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
    bsOffcanvas.show();
}

function submitDrawerDecision(decision) {
    const form = document.getElementById('drawerActionForm');
    const actionInput = document.getElementById('drawerQuickActionInput');
    const commentInput = document.getElementById('drawerCommentTextarea');
    if (!form || !actionInput) return;

    if (decision === 'return' && commentInput && commentInput.value.trim() === '') {
        commentInput.focus();
        commentInput.classList.add('border-danger');
        setTimeout(() => commentInput.classList.remove('border-danger'), 2000);
        return;
    }

    actionInput.value = decision;
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. ระบบกรอง ค้นหา และแบ่งหน้าตารางกลาง
    const allRows = Array.from(document.querySelectorAll('#execUnifiedTbody .exec-stream-row'));
    const tabBtns = document.querySelectorAll('#execStreamTabs .exec-filter-tab');
    const searchInput = document.getElementById('execStreamSearch');
    const moduleSelect = document.getElementById('execModuleFilter');
    const infoEl = document.getElementById('execTableInfo');
    const pageContainer = document.getElementById('execTablePagination');
    const selectAllChk = document.getElementById('selectAllExecRows');

    let currentStatus = 'all';
    let currentModule = 'all';
    let currentQuery = '';
    let currentPage = 1;
    const rowsPerPage = 10;

    function renderUnifiedTable() {
        const filtered = allRows.filter(row => {
            const st = row.getAttribute('data-status');
            const mod = row.getAttribute('data-module');
            const txt = row.innerText.toLowerCase();

            const matchStatus = (currentStatus === 'all') || (st === currentStatus);
            const matchModule = (currentModule === 'all') || (mod === currentModule);
            const matchSearch = (currentQuery === '') || txt.includes(currentQuery);

            return matchStatus && matchModule && matchSearch;
        });

        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
        if (currentPage > totalPages) currentPage = totalPages;

        const startIdx = (currentPage - 1) * rowsPerPage;
        const endIdx = Math.min(startIdx + rowsPerPage, total);

        allRows.forEach(r => r.style.display = 'none');
        filtered.slice(startIdx, endIdx).forEach(r => r.style.display = '');

        if (infoEl) {
            if (total === 0) {
                infoEl.innerHTML = 'ไม่พบรายการที่ตรงกับเงื่อนไขการค้นหา';
            } else {
                infoEl.innerHTML = `กำลังแสดง <strong>${startIdx + 1} - ${endIdx}</strong> จากทั้งหมด <strong>${total}</strong> รายการ (แบ่งหน้าละ ${rowsPerPage} แถว)`;
            }
        }

        if (pageContainer) {
            pageContainer.innerHTML = '';
            const prevBtn = document.createElement('button');
            prevBtn.className = 'exec-page-btn';
            prevBtn.innerHTML = '← ก่อนหน้า';
            prevBtn.disabled = (currentPage <= 1);
            prevBtn.onclick = () => { currentPage--; renderUnifiedTable(); };
            pageContainer.appendChild(prevBtn);

            for (let p = 1; p <= totalPages; p++) {
                const pBtn = document.createElement('button');
                pBtn.className = 'exec-page-btn' + (p === currentPage ? ' active' : '');
                pBtn.textContent = p;
                pBtn.onclick = () => { currentPage = p; renderUnifiedTable(); };
                pageContainer.appendChild(pBtn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.className = 'exec-page-btn';
            nextBtn.innerHTML = 'ถัดไป →';
            nextBtn.disabled = (currentPage >= totalPages);
            nextBtn.onclick = () => { currentPage++; renderUnifiedTable(); };
            pageContainer.appendChild(nextBtn);
        }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.getAttribute('data-status-filter') || 'all';
            currentPage = 1;
            renderUnifiedTable();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentQuery = this.value.toLowerCase().trim();
            currentPage = 1;
            renderUnifiedTable();
        });
    }

    if (moduleSelect) {
        moduleSelect.addEventListener('change', function() {
            currentModule = this.value;
            currentPage = 1;
            renderUnifiedTable();
        });
    }

    if (selectAllChk) {
        selectAllChk.addEventListener('change', function() {
            const visibleChks = allRows.filter(r => r.style.display !== 'none').map(r => r.querySelector('.exec-row-check'));
            visibleChks.forEach(chk => { if (chk) chk.checked = selectAllChk.checked; });
        });
    }

    renderUnifiedTable();

    // 2. ปฏิทินบุคลากร
    const calendarEl = document.getElementById('calendar');
    const calCollapseEl = document.getElementById('execCalendarCollapse');
    let execCalendar = null;

    if (calCollapseEl && calendarEl) {
        calCollapseEl.addEventListener('shown.bs.collapse', function() {
            if (!execCalendar) {
                execCalendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    locale: 'th',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek'
                    },
                    events: <?php echo json_encode($calendar_events, JSON_UNESCAPED_UNICODE); ?>,
                    eventClick: function(info) {
                        const title = info.event.title;
                        const desc = info.event.extendedProps.description;
                        const startDate = info.event.start.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
                        let dateText = startDate;
                        if (info.event.end) {
                            const endDateObj = new Date(info.event.end);
                            endDateObj.setDate(endDateObj.getDate() - 1);
                            if (info.event.start.getTime() !== endDateObj.getTime()) {
                                dateText += " ถึง " + endDateObj.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
                            }
                        }
                        document.getElementById('eventModalTitle').innerText = title;
                        document.getElementById('eventModalDate').innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + dateText;
                        const iconEl = document.getElementById('eventModalIcon');
                        iconEl.className = "fa-solid fa-2x " + (title.includes("ลา:") ? "fa-user-clock text-success" : "fa-plane-departure text-primary");
                        document.getElementById('eventModalIconWrapper').style.backgroundColor = title.includes("ลา:") ? "#dcfce7" : "#dbeafe";
                        const descBox = document.getElementById('eventModalDescBox');
                        if (desc) {
                            document.getElementById('eventModalDesc').innerText = desc;
                            descBox.classList.remove('d-none');
                        } else {
                            descBox.classList.add('d-none');
                        }
                        new bootstrap.Modal(document.getElementById('calendarEventModal')).show();
                    }
                });
                execCalendar.render();
            } else {
                execCalendar.updateSize();
            }
        });
    }
});

function exportExecStreamToCsv() {
    const rows = Array.from(document.querySelectorAll('#execUnifiedTbody .exec-stream-row')).filter(r => r.style.display !== 'none');
    let csv = '\uFEFF"รหัสรายการ","ผู้ยื่นคำขอ","หน่วยงาน/รายละเอียดผู้ยื่น","หมวดหมู่","หัวข้อรายการ","กำหนดการ","สถานะ"\n';
    rows.forEach(r => {
        const cols = r.querySelectorAll('td');
        if (cols.length >= 7) {
            const code = cols[1].innerText.trim().replace(/"/g, '""');
            const reqLines = cols[2].innerText.trim().split('\n');
            const reqName = (reqLines[0] || '').replace(/"/g, '""');
            const reqSub = (reqLines[1] || '').replace(/"/g, '""');
            const cat = cols[3].innerText.trim().replace(/"/g, '""');
            const title = cols[4].innerText.trim().replace(/\n+/g, ' - ').replace(/"/g, '""');
            const dateStr = cols[5].innerText.trim().replace(/\n+/g, ' ').replace(/"/g, '""');
            const status = cols[6].innerText.trim().replace(/"/g, '""');
            csv += `"${code}","${reqName}","${reqSub}","${cat}","${title}","${dateStr}","${status}"\n`;
        }
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'MSU_Music_ERP_Operations_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function switchExecPerspective(targetPaneId, btn) {
    document.querySelectorAll('.exec-perspective-tab').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');
    
    const panes = ['pane-approvals', 'pane-daily', 'pane-strategic'];
    panes.forEach(pid => {
        const p = document.getElementById(pid);
        if (p) {
            p.classList.add('d-none');
            p.classList.remove('exec-pane-fade');
        }
    });

    const target = document.getElementById(targetPaneId);
    if (target) {
        target.classList.remove('d-none');
        target.classList.add('exec-pane-fade');
    }

    try {
        localStorage.setItem('exec_dashboard_perspective', targetPaneId);
    } catch(e) {}

    // Auto-update calendar layout if daily tab is activated
    if (targetPaneId === 'pane-daily') {
        const calCollapseEl = document.getElementById('execCalendarCollapse');
        if (calCollapseEl && !calCollapseEl.classList.contains('show')) {
            const bsCol = bootstrap.Collapse.getInstance(calCollapseEl) || new bootstrap.Collapse(calCollapseEl, {toggle: false});
            bsCol.show();
        }
    }
}

// Restore saved perspective tab on page load
document.addEventListener('DOMContentLoaded', function() {
    try {
        const savedTab = localStorage.getItem('exec_dashboard_perspective');
        if (savedTab && ['pane-approvals', 'pane-daily', 'pane-strategic'].includes(savedTab)) {
            const btn = document.querySelector(`.exec-perspective-tab[data-tab-target="${savedTab}"]`);
            if (btn) {
                switchExecPerspective(savedTab, btn);
            }
        }
    } catch(e) {}
});
</script>

<?php require_once 'includes/footer.php'; ?>
