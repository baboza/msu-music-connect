<?php
require_once 'auth/session_check.php'; 
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_roles_array = $_SESSION['roles'] ?? [];

// --- Flags สำหรับระบบทั่วไป ---
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
if (in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles_array)) {
    $my_head_dept_ids[] = 12;
}
if (in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles_array)) {
    $my_head_dept_ids[] = 13;
}
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

// =========================================================
// จัดการคำสั่งอนุมัติด่วนจาก Slide-Over Drawer (POST Handler)
// =========================================================
$inbox_flash_msg = '';
$inbox_flash_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inbox_drawer_action'])) {
    $d_action  = $_POST['inbox_drawer_action']; // 'approve' | 'return'
    $d_cat     = $_POST['inbox_category'] ?? '';
    $d_id      = (int)($_POST['inbox_record_id'] ?? 0);
    $d_comment = trim($_POST['inbox_comment'] ?? '');

    try {
        if ($d_cat === 'training_req' && $d_id > 0) {
            $stmt_chk = $pdo->prepare("SELECT tr.*, u.dept_id FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.report_id = ?");
            $stmt_chk->execute([$d_id]);
            $tr_row = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if ($tr_row && $tr_row['status'] === 'pending') {
                $step = (int)$tr_row['current_step'];
                $s_status = "step{$step}_status";
                $s_by = "step{$step}_by";
                $s_date = "step{$step}_date";
                $tr_is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0 || $is_admin);

                if ($d_action === 'approve') {
                    if ($step >= 5 || ($tr_is_dean && $step >= 4)) {
                        // อนุมัติสมบูรณ์ (ขั้นที่ 5 หรือคณบดี/Admin อนุมัติ): บันทึกลายเซ็นและผู้มีอำนาจครบทุกขั้นตอน
                        $sup_id = ($tr_row['dept_id'] == 12 || $tr_row['dept_id'] == 13) ? '6b8f3458220e1' : '34ee8171';
                        $step1_by = !empty($tr_row['step1_by']) ? $tr_row['step1_by'] : $sup_id;
                        $step2_by = !empty($tr_row['step2_by']) ? $tr_row['step2_by'] : '6b900dce220e1';
                        $step3_by = !empty($tr_row['step3_by']) ? $tr_row['step3_by'] : $sup_id;
                        $step4_by = !empty($tr_row['step4_by']) ? $tr_row['step4_by'] : '86580da6';
                        $step5_by = $user_id;

                        $pdo->prepare("UPDATE training_reports SET 
                            status = 'approved', 
                            current_step = 5, 
                            return_comment = NULL, 
                            step1_status = 'approved', step1_by = ?, step1_date = COALESCE(step1_date, NOW()),
                            step2_status = 'approved', step2_by = ?, step2_date = COALESCE(step2_date, NOW()),
                            step3_status = 'approved', step3_by = ?, step3_date = COALESCE(step3_date, NOW()),
                            step4_status = 'approved', step4_by = ?, step4_date = COALESCE(step4_date, NOW()),
                            step5_status = 'approved', step5_by = ?, step5_date = NOW()
                            WHERE report_id = ?
                        ")->execute([$step1_by, $step2_by, $step3_by, $step4_by, $step5_by, $d_id]);
                        $inbox_flash_msg = "อนุมัติคำขอไปราชการ #TR-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " (อนุมัติสมบูรณ์เรียบร้อยแล้ว)";
                    } else {
                        $next_step = $step + 1;
                        $pdo->prepare("UPDATE training_reports SET current_step = ?, return_comment = NULL, $s_status = 'approved', $s_by = ?, $s_date = NOW() WHERE report_id = ?")
                            ->execute([$next_step, $user_id, $d_id]);
                        $inbox_flash_msg = "เห็นชอบคำขอไปราชการ #TR-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " ส่งต่อขั้นตอนที่ {$next_step}/5 เรียบร้อยแล้ว";
                    }
                } else {
                    $pdo->prepare("UPDATE training_reports SET status = 'returned', return_comment = ?, return_step = ?, $s_status = 'returned', $s_by = ?, $s_date = NOW() WHERE report_id = ?")
                        ->execute([$d_comment ?: 'ส่งกลับแก้ไขจากผู้พิจารณา', $step, $user_id, $d_id]);
                    $inbox_flash_msg = "ส่งกลับแก้ไขคำขอไปราชการ #TR-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
                    $inbox_flash_type = 'warning';
                }
            }
        } elseif ($d_cat === 'leave_req' && $d_id > 0) {
            $stmt_chk = $pdo->prepare("SELECT l.current_step, l.status, l.user_id, u.dept_id FROM leave_requests l JOIN users u ON l.user_id = u.user_id WHERE l.leave_id = ?");
            $stmt_chk->execute([$d_id]);
            $lv_row = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if ($lv_row && $lv_row['status'] === 'pending') {
                $step = (int)$lv_row['current_step'];
                $user_is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0 || $is_admin);

                if ($d_action === 'approve') {
                    // บันทึกผลการพิจารณาลงใน leave_approvals สำหรับขั้นตอนนี้
                    $check_app = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = ?");
                    $check_app->execute([$d_id, $step]);
                    $existing_app_id = $check_app->fetchColumn();
                    if ($existing_app_id) {
                        $pdo->prepare("UPDATE leave_approvals SET approver_id = ?, approve_status = 'approved', comment = ?, updated_at = NOW() WHERE approval_id = ?")
                            ->execute([$user_id, $d_comment ?: 'เห็นชอบผ่านกล่องงาน Action Inbox', $existing_app_id]);
                    } else {
                        $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, ?, 'approved', ?, NOW())")
                            ->execute([$d_id, $user_id, $step, $d_comment ?: 'เห็นชอบผ่านกล่องงาน Action Inbox']);
                    }

                    // ถ้าเป็นขั้นตอนที่ 4 หรือผู้มีสิทธิ์ระดับคณบดี/Admin อนุมัติขั้นที่ 3-4 -> อนุมัติสมบูรณ์
                    if ($step >= 4 || ($user_is_dean && $step >= 3)) {
                        if ($step == 3 && $user_is_dean) {
                            $check_app4 = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = 4");
                            $check_app4->execute([$d_id]);
                            if (!$check_app4->fetchColumn()) {
                                $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, 4, 'approved', ?, NOW())")
                                    ->execute([$d_id, $user_id, $d_comment ?: 'อนุมัติผ่านกล่องงาน Action Inbox (คณบดี/รักษาการ)']);
                            }
                        }
                        $pdo->prepare("UPDATE leave_requests SET status = 'approved', current_step = 4 WHERE leave_id = ?")->execute([$d_id]);
                        $inbox_flash_msg = "อนุมัติใบลา #LV-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " (อนุมัติสมบูรณ์เรียบร้อยแล้ว)";
                    } else {
                        $next_step = $step + 1;
                        $pdo->prepare("UPDATE leave_requests SET current_step = ? WHERE leave_id = ?")->execute([$next_step, $d_id]);
                        $inbox_flash_msg = "พิจารณาเห็นชอบใบลา #LV-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " ลงนามและส่งต่อไปยังขั้นตอนที่ {$next_step}/4 เรียบร้อยแล้ว";
                    }
                } else {
                    $check_app = $pdo->prepare("SELECT approval_id FROM leave_approvals WHERE leave_id = ? AND step_order = ?");
                    $check_app->execute([$d_id, $step]);
                    $existing_app_id = $check_app->fetchColumn();
                    if ($existing_app_id) {
                        $pdo->prepare("UPDATE leave_approvals SET approver_id = ?, approve_status = 'rejected', comment = ?, updated_at = NOW() WHERE approval_id = ?")
                            ->execute([$user_id, $d_comment ?: 'ไม่อนุมัติผ่านกล่องงาน Action Inbox', $existing_app_id]);
                    } else {
                        $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) VALUES (?, ?, ?, 'rejected', ?, NOW())")
                            ->execute([$d_id, $user_id, $step, $d_comment ?: 'ไม่อนุมัติผ่านกล่องงาน Action Inbox']);
                    }
                    $pdo->prepare("UPDATE leave_requests SET status = 'rejected' WHERE leave_id = ?")->execute([$d_id]);
                    $inbox_flash_msg = "ไม่อนุมัติใบลา #LV-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
                    $inbox_flash_type = 'warning';
                }
            }
        } elseif ($d_cat === 'student_req' && $d_id > 0) {
            $stmt_chk = $pdo->prepare("SELECT status FROM course_requests WHERE request_id = ?");
            $stmt_chk->execute([$d_id]);
            $cr_row = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if ($cr_row) {
                $cur_st = (string)$cr_row['status'];
                $next_map = [
                    'pending_instructor' => ['status' => 'pending_advisor',  'step' => 2, 'col' => 'instructor_comment'],
                    'pending_advisor'    => ['status' => 'pending_head',     'step' => 3, 'col' => 'advisor_comment'],
                    'pending_head'       => ['status' => 'pending_deputy',   'step' => 4, 'col' => 'head_comment'],
                    'pending_deputy'     => ['status' => 'pending_academic', 'step' => 5, 'col' => 'deputy_comment'],
                    'pending_academic'   => ['status' => 'approved',         'step' => 5, 'col' => 'academic_comment'],
                ];
                $step_cfg = $next_map[$cur_st] ?? ['status' => 'approved', 'step' => 5, 'col' => 'deputy_comment'];
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

                if ($d_action === 'approve') {
                    $next_st = $step_cfg['status'];
                    $next_num = $step_cfg['step'];
                    $pdo->prepare("UPDATE course_requests SET status = ?, {$col_name} = COALESCE(NULLIF(?, ''), {$col_name}) {$time_sql}, updated_at = NOW() WHERE request_id = ?")
                        ->execute([$next_st, $d_comment, $d_id]);
                    $inbox_flash_msg = "พิจารณาคำร้องวิชาการ #REQ-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
                } else {
                    $pdo->prepare("UPDATE course_requests SET status = 'rejected', {$col_name} = COALESCE(NULLIF(?, ''), {$col_name}) {$time_sql}, updated_at = NOW() WHERE request_id = ?")
                        ->execute([$d_comment ?: 'ไม่อนุมัติคำร้องผ่านกล่องงาน', $d_id]);
                    $inbox_flash_msg = "ส่งกลับ/ไม่อนุมัติคำร้องวิชาการ #REQ-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
                    $inbox_flash_type = 'warning';
                }
            }
        } elseif ($d_cat === 'supply_req' && $d_id > 0 && ($is_supply_head || $is_admin)) {
            $stmt_chk = $pdo->prepare("SELECT head_status FROM supplies_requests WHERE request_id = ?");
            $stmt_chk->execute([$d_id]);
            $sp_status = $stmt_chk->fetchColumn();
            if ($sp_status === 'pending') {
                if ($d_action === 'approve') {
                    $pdo->prepare("UPDATE supplies_requests SET head_status = 'approved', head_approver_id = ?, head_approve_date = NOW(), head_remark = ? WHERE request_id = ?")
                        ->execute([$user_id, $d_comment ?: 'อนุมัติให้เบิกจ่ายได้', $d_id]);
                    $inbox_flash_msg = "อนุมัติใบเบิกวัสดุ #SP-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " ส่งต่องานพัสดุเรียบร้อยแล้ว";
                } else {
                    $pdo->prepare("UPDATE supplies_requests SET head_status = 'rejected', supply_status = 'rejected', head_approver_id = ?, head_approve_date = NOW(), head_remark = ? WHERE request_id = ?")
                        ->execute([$user_id, $d_comment ?: 'ไม่อนุมัติการเบิก', $d_id]);
                    $inbox_flash_msg = "ไม่อนุมัติใบเบิกวัสดุ #SP-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
                    $inbox_flash_type = 'warning';
                }
            }
        } elseif ($d_cat === 'room_req' && $d_id > 0 && ($is_building_staff || $is_admin)) {
            $new_st = ($d_action === 'approve') ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE room_bookings SET status = ?, approver_id = ?, reject_reason = ? WHERE booking_id = ?")
                ->execute([$new_st, $user_id, ($d_action === 'approve' ? NULL : ($d_comment ?: 'ไม่อนุมัติจากกล่องงาน')), $d_id]);
            $inbox_flash_msg = ($d_action === 'approve' ? "อนุมัติ" : "ไม่อนุมัติ") . "การจองห้อง #RB-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
            if ($d_action !== 'approve') $inbox_flash_type = 'warning';
        } elseif ($d_cat === 'repair_req' && $d_id > 0 && ($is_building_staff || $is_admin)) {
            $new_st = ($d_action === 'approve') ? 'in_progress' : 'cancelled';
            $pdo->prepare("UPDATE repair_requests SET status = ?, technician_id = ?, repair_note = COALESCE(NULLIF(?, ''), repair_note), updated_at = NOW() WHERE repair_id = ?")
                ->execute([$new_st, $user_id, $d_comment ?: ($d_action === 'approve' ? 'รับเรื่องดำเนินการซ่อมบำรุง' : 'ยกเลิกการแจ้งซ่อม'), $d_id]);
            $inbox_flash_msg = ($d_action === 'approve' ? "รับเรื่องดำเนินการแจ้งซ่อม" : "ยกเลิกคำขอแจ้งซ่อม") . " #RP-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
            if ($d_action !== 'approve') $inbox_flash_type = 'warning';
        } elseif ($d_cat === 'grade_appeal_req' && $d_id > 0) {
            $new_st = ($d_action === 'approve') ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE grade_appeals SET status = ?, updated_at = NOW() WHERE appeal_id = ?")->execute([$new_st, $d_id]);
            $inbox_flash_msg = ($d_action === 'approve' ? "อนุมัติ" : "ไม่อนุมัติ") . "คำขออุทธรณ์คะแนน #GA-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
            if ($d_action !== 'approve') $inbox_flash_type = 'warning';
        } elseif ($d_cat === 'complaint_req' && $d_id > 0 && ($is_dean || $is_admin)) {
            $new_st = ($d_action === 'approve') ? 'resolved' : 'rejected';
            $pdo->prepare("UPDATE complaints SET status = ?, updated_at = NOW() WHERE complaint_id = ?")->execute([$new_st, $d_id]);
            $inbox_flash_msg = ($d_action === 'approve' ? "บันทึกยุติเรื่อง/แก้ไขแล้วสำหรับ" : "ปฏิเสธ") . "เรื่องร้องเรียน #CP-" . str_pad((string)$d_id, 4, '0', STR_PAD_LEFT) . " เรียบร้อยแล้ว";
            if ($d_action !== 'approve') $inbox_flash_type = 'warning';
        }
    } catch (PDOException $e) {
        $inbox_flash_msg = "เกิดข้อผิดพลาดในการบันทึกผล: " . $e->getMessage();
        $inbox_flash_type = 'danger';
    }
}

// =========================================================
// SQL UNION รวมระบบรอพิจารณา
// =========================================================
$query = "
    /* --- 1. คำร้องจากนิสิต (5 ขั้นตอนจริง) --- */
    SELECT 
        r.request_id AS id, r.created_at AS date_time, 
        COALESCE(CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูลนิสิต') AS requester,
        CASE WHEN r.request_type = 'open_course' THEN 'ขอเปิดรายวิชา' ELSE 'ขอสำรองที่นั่ง' END AS type_label,
        CONCAT(r.course_code, ' ', r.course_name, ' (กลุ่ม ', COALESCE(r.section, '-'), ')') AS detail,
        CASE 
            WHEN r.status = 'pending_instructor' THEN 'รออาจารย์ผู้สอน (ขั้น 1/5)'
            WHEN r.status = 'pending_advisor' THEN 'รออาจารย์ที่ปรึกษา (ขั้น 2/5)'
            WHEN r.status = 'pending_head' THEN 'รอหัวหน้าภาควิชา (ขั้น 3/5)'
            WHEN r.status = 'pending_deputy' THEN 'รอรองคณบดีฝ่ายวิชาการ (ขั้น 4/5)'
            WHEN r.status = 'pending_academic' THEN 'รองานบริการการศึกษา (ขั้น 5/5)'
            WHEN r.status = 'approved' THEN 'อนุมัติแล้ว'
            WHEN r.status = 'rejected' THEN 'ไม่อนุมัติ'
            ELSE r.status 
        END AS current_status,
        CASE 
            WHEN r.status = 'pending_instructor' THEN 1
            WHEN r.status = 'pending_advisor' THEN 2
            WHEN r.status = 'pending_head' THEN 3
            WHEN r.status = 'pending_deputy' THEN 4
            WHEN r.status = 'pending_academic' THEN 5
            ELSE 1
        END AS step_num,
        'requests_approve.php' AS link_page, 
        'student_req' AS category,
        COALESCE(s.profile_picture, '') AS profile_picture
    FROM course_requests r
    LEFT JOIN students s ON r.student_id = s.student_id
    WHERE 
        ($is_admin = 1 AND r.status IN ('pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic'))
        OR (r.instructor_id = '$user_id' AND r.status = 'pending_instructor')
        OR (r.advisor_id = '$user_id' AND r.status = 'pending_advisor')
        OR (r.status = 'pending_head' AND $is_course_head = 1)
        OR (r.status = 'pending_deputy' AND $is_course_deputy = 1)
        OR (r.status = 'pending_academic' AND $is_course_academic = 1)

    UNION ALL

    /* --- 2. การเบิกพัสดุ (2 ขั้นตอนจริง) --- */
    SELECT 
        sr.request_id AS id, sr.request_date AS date_time, 
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'ไม่พบข้อมูลบุคลากร') AS requester,
        'เบิกวัสดุอุปกรณ์' AS type_label, sr.request_reason AS detail,
        CASE WHEN sr.head_status = 'pending' THEN 'รอหัวหน้าสำนักงานอนุมัติ (ขั้น 1/2)' ELSE 'รองานพัสดุจ่ายของ (ขั้น 2/2)' END AS current_status,
        CASE WHEN sr.head_status = 'pending' THEN 1 ELSE 2 END AS step_num,
        CASE 
            WHEN (sr.head_status = 'pending') THEN 'modules/supplies/head_approve.php'
            WHEN (sr.head_status = 'approved' AND sr.supply_status = 'pending') THEN 'modules/supplies/issue_supplies.php'
            ELSE 'modules/supplies/index.php'
        END AS link_page, 
        'supply_req' AS category,
        COALESCE(u.profile_picture, '') AS profile_picture
    FROM supplies_requests sr
    LEFT JOIN users u ON sr.user_id = u.user_id
    WHERE 
        ($is_admin = 1 AND (sr.head_status = 'pending' OR (sr.head_status = 'approved' AND sr.supply_status = 'pending')))
        OR (sr.head_status = 'pending' AND sr.head_approver_id = '$user_id')
        OR ($is_supply_head = 1 AND sr.head_status = 'pending')
        OR ($is_supply_issuer = 1 AND sr.head_status = 'approved' AND sr.supply_status = 'pending')

    UNION ALL

    /* --- 3. คำขอใบลา (4 ขั้นตอนจริง) --- */
    SELECT 
        l.leave_id AS id, l.created_at AS date_time, 
        COALESCE(CONCAT(u.prefix_name, u.first_name, ' ', u.last_name), 'ไม่พบข้อมูลบุคลากร') AS requester,
        COALESCE(t.type_name, 'ไม่ระบุประเภท') AS type_label,
        CONCAT(DATE_FORMAT(l.start_date, '%d/%m/%y'), ' - ', DATE_FORMAT(l.end_date, '%d/%m/%y'), ' • ', COALESCE(l.reason, '')) AS detail,
        CASE 
            WHEN l.current_step = 1 THEN 'รองานบุคคลตรวจสอบ (ขั้น 1/4)'
            WHEN l.current_step = 2 AND u.dept_id = 11 THEN 'รอหัวหน้าสำนักงานพิจารณา (ขั้น 2/4)'
            WHEN l.current_step = 2 AND u.dept_id = 12 THEN 'รอหัวหน้าภาควิชาดนตรีศึกษา (ขั้น 2/4)'
            WHEN l.current_step = 2 AND u.dept_id = 13 THEN 'รอหัวหน้าภาควิชาดุริยางคศาสตร์ (ขั้น 2/4)'
            WHEN l.current_step = 2 THEN 'รอหัวหน้าภาควิชาพิจารณา (ขั้น 2/4)'
            WHEN l.current_step = 3 THEN 'รอรองคณบดีฝ่ายบริหาร (ขั้น 3/4)'
            WHEN l.current_step = 4 THEN 'รอคณบดี/รักษาการ (ขั้น 4/4)'
            ELSE CONCAT('รอขั้นตอนที่ ', l.current_step)
        END AS current_status,
        l.current_step AS step_num,
        'modules/leave/view.php' AS link_page, 
        'leave_req' AS category,
        COALESCE(u.profile_picture, '') AS profile_picture
    FROM leave_requests l
    LEFT JOIN leave_types t ON l.type_id = t.type_id
    LEFT JOIN users u ON l.user_id = u.user_id
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

    UNION ALL

    /* --- 4. การจองห้อง --- */
    SELECT 
        rb.booking_id AS id, rb.created_at AS date_time, 
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS requester,
        'จองห้อง/สถานที่' AS type_label, 
        CONCAT(COALESCE(rms.room_name, 'ไม่ระบุห้อง'), ' [', DATE_FORMAT(rb.booking_date, '%d/%m/%y'), ' ', rb.start_time, '-', rb.end_time, '] ', rb.purpose) AS detail,
        'รออนุมัติจองห้อง' AS current_status,
        1 AS step_num,
        'modules/room_booking/view_booking.php' AS link_page,
        'room_req' AS category,
        COALESCE(u.profile_picture, s.profile_picture, '') AS profile_picture
    FROM room_bookings rb
    LEFT JOIN users u ON rb.user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN students s ON rb.user_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN rooms rms ON rb.room_id = rms.room_id
    WHERE rb.status = 'pending'
    AND ($is_admin = 1 OR $is_building_staff = 1)

    UNION ALL

    /* --- 5. แจ้งซ่อมบำรุง --- */
    SELECT 
        rr.repair_id AS id, rr.created_at AS date_time, 
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS requester,
        'แจ้งซ่อมบำรุง' AS type_label, 
        CONCAT(COALESCE(rms.room_name, 'ไม่ระบุห้อง'), ': ', rr.equipment_name, ' (', rr.problem_desc, ')') AS detail,
        'รอดำเนินการซ่อม' AS current_status,
        1 AS step_num,
        'modules/repair_request/view_repair.php' AS link_page,
        'repair_req' AS category,
        COALESCE(u.profile_picture, s.profile_picture, '') AS profile_picture
    FROM repair_requests rr
    LEFT JOIN users u ON rr.reporter_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN students s ON rr.reporter_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN rooms rms ON rr.room_id = rms.room_id
    WHERE rr.status = 'pending'
    AND ($is_admin = 1 OR $is_building_staff = 1)

    UNION ALL

    /* --- 6. อุทธรณ์คะแนน --- */
    SELECT 
        ga.appeal_id AS id, ga.created_at AS date_time, 
        COALESCE(CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูลนิสิต') AS requester,
        'อุทธรณ์คะแนน' AS type_label, 
        CONCAT(ga.course_code, ' ', ga.course_name) AS detail,
        CASE 
            WHEN ga.status = 'pending' THEN 'รอพิจารณา'
            WHEN ga.status = 'in_progress' THEN 'กำลังดำเนินการ'
            ELSE 'รอพิจารณา'
        END AS current_status,
        1 AS step_num,
        'modules/grade_appeal/view_appeal.php' AS link_page, 
        'grade_appeal_req' AS category,
        COALESCE(s.profile_picture, '') AS profile_picture
    FROM grade_appeals ga
    LEFT JOIN students s ON ga.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    WHERE ga.status IN ('pending', 'in_progress')
    AND ($is_admin = 1 OR ga.instructor_id = '$user_id')

    UNION ALL

    /* --- 7. เรื่องร้องเรียน --- */
    SELECT 
        c.complaint_id AS id, c.created_at AS date_time, 
        COALESCE(CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูลนิสิต') AS requester,
        'เรื่องร้องเรียน' AS type_label, 
        c.subject AS detail,
        CASE 
            WHEN c.status = 'pending' THEN 'รอพิจารณา'
            WHEN c.status = 'in_progress' THEN 'กำลังดำเนินการ'
            WHEN c.status = 'resolved' THEN 'ยุติเรื่อง/แก้ไขแล้ว'
            ELSE 'ปฏิเสธ'
        END AS current_status,
        1 AS step_num,
        'modules/complaint/view_complaint.php' AS link_page, 
        'complaint_req' AS category,
        COALESCE(s.profile_picture, '') AS profile_picture
    FROM complaints c
    LEFT JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    WHERE c.status IN ('pending', 'in_progress')
    AND ($is_admin = 1 OR $is_dean = 1)

    UNION ALL

    /* --- 8. ขอไปราชการ/อบรม (5 ขั้นตอนจริง) --- */
    SELECT 
        tr.report_id AS id, tr.created_at AS date_time, 
        COALESCE(CONCAT(u.prefix_name, u.first_name, ' ', u.last_name), 'ไม่พบข้อมูลบุคลากร') AS requester,
        'ขอไปราชการ/อบรม' AS type_label, 
        CONCAT(tr.project_name, ' (', COALESCE(tr.location, '-'), ')') AS detail,
        CASE 
            WHEN tr.current_step = 1 AND u.dept_id IN (12, 13) THEN 'รอหัวหน้าภาควิชาตรวจเบื้องต้น (ขั้น 1/5)'
            WHEN tr.current_step = 1 THEN 'รอหัวหน้าสำนักงานตรวจเบื้องต้น (ขั้น 1/5)'
            WHEN tr.current_step = 2 THEN 'รองานการเงินตรวจงบประมาณ (ขั้น 2/5)'
            WHEN tr.current_step = 3 AND u.dept_id IN (12, 13) THEN 'รอหัวหน้าภาควิชาเห็นชอบงบ (ขั้น 3/5)'
            WHEN tr.current_step = 3 THEN 'รอหัวหน้าสำนักงานเห็นชอบงบ (ขั้น 3/5)'
            WHEN tr.current_step = 4 THEN 'รอรองคณบดีฝ่ายบริหาร (ขั้น 4/5)'
            WHEN tr.current_step = 5 THEN 'รอคณบดีอนุมัติเดินทาง (ขั้น 5/5)'
            ELSE CONCAT('รอขั้นตอนที่ ', tr.current_step)
        END AS current_status,
        tr.current_step AS step_num,
        'modules/trainings/approve_list.php' AS link_page,
        'training_req' AS category,
        COALESCE(u.profile_picture, '') AS profile_picture
    FROM training_reports tr
    LEFT JOIN users u ON tr.user_id = u.user_id
    WHERE tr.status = 'pending'
    AND (
        $is_admin = 1
        OR (tr.current_step IN (1, 3) AND $is_training_office_head = 1 AND (u.dept_id IS NULL OR u.dept_id NOT IN (12, 13)))
        $training_head_sql
        OR (tr.current_step = 2 AND $is_training_finance = 1)
        OR (tr.current_step = 4 AND $is_training_deputy_admin = 1)
        OR (tr.current_step = 5 AND $is_training_dean = 1)
    )

    ORDER BY date_time DESC
";

$stmt = $pdo->query($query);
$all_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// นับยอด KPI
$count_std = 0; $count_sup = 0; $count_leave = 0; $count_room = 0; $count_repair = 0; $count_appeal = 0; $count_complaint = 0; $count_training = 0;
foreach($all_requests as $row) {
    if($row['category'] == 'student_req') $count_std++;
    if($row['category'] == 'supply_req') $count_sup++;
    if($row['category'] == 'leave_req') $count_leave++;
    if($row['category'] == 'room_req') $count_room++;
    if($row['category'] == 'repair_req') $count_repair++;
    if($row['category'] == 'grade_appeal_req') $count_appeal++;
    if($row['category'] == 'complaint_req') $count_complaint++;
    if($row['category'] == 'training_req') $count_training++;
}

// ดึงข้อมูลอาจารย์และความเห็นในแต่ละขั้นตอนของคำร้องวิชาการ (student_req)
$student_req_ids = [];
foreach ($all_requests as $r_item) {
    if ($r_item['category'] === 'student_req') {
        $student_req_ids[] = (int)$r_item['id'];
    }
}
$academic_details = [];
if (!empty($student_req_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($student_req_ids), '?'));
    $stmt_acad = $pdo->prepare("
        SELECT cr.request_id, cr.request_type, cr.status, s.major,
               cr.instructor_comment, cr.advisor_comment, cr.head_comment, cr.deputy_comment, cr.academic_comment,
               TRIM(CONCAT(COALESCE(inst.prefix_name, ''), inst.first_name, ' ', inst.last_name)) AS inst_name,
               TRIM(CONCAT(COALESCE(adv.prefix_name, ''), adv.first_name, ' ', adv.last_name)) AS adv_name
        FROM course_requests cr
        LEFT JOIN students s ON cr.student_id = s.student_id
        LEFT JOIN users inst ON cr.instructor_id = inst.user_id
        LEFT JOIN users adv ON COALESCE(cr.advisor_id, s.advisor_id) = adv.user_id
        WHERE cr.request_id IN ($in_placeholders)
    ");
    $stmt_acad->execute($student_req_ids);
    while ($ac = $stmt_acad->fetch(PDO::FETCH_ASSOC)) {
        $academic_details[(int)$ac['request_id']] = $ac;
    }
}

// สร้างข้อมูลสำหรับ Slide-Over Drawer ในหน้า Action Inbox
$inbox_drawer_items = [];
$cat_prefix_map = [
    'student_req'      => 'REQ',
    'supply_req'       => 'SP',
    'leave_req'        => 'LV',
    'room_req'         => 'RB',
    'repair_req'       => 'RP',
    'grade_appeal_req' => 'GA',
    'complaint_req'    => 'CP',
    'training_req'     => 'TR'
];
foreach ($all_requests as $idx => $r) {
    $cat = $r['category'];
    $prefix = $cat_prefix_map[$cat] ?? 'DOC';
    $code = $prefix . '-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
    $final_link = ($cat === 'supply_req' || $cat === 'training_req') ? $r['link_page'] : ($r['link_page'] . '?id=' . $r['id']);
    $step_num = max(1, (int)($r['step_num'] ?? 1));

    // กำหนดขั้นตอน Workflow จริงของแต่ละโมดูล
    $wf_title = 'สถานะขั้นตอนการพิจารณา (WORKFLOW)';
    $wf_steps = [];
    $can_quick_approve = in_array($cat, ['training_req', 'leave_req', 'student_req', 'room_req'], true) || ($cat === 'supply_req' && $step_num === 1);

    if ($cat === 'training_req') {
        $wf_title = 'สถานะขั้นตอนการพิจารณา 5 ลำดับ (TRAINING WORKFLOW)';
        $wf_steps = [
            ['step' => 1, 'title' => 'หัวหน้าสำนักงาน / หัวหน้าภาควิชา (ตรวจเบื้องต้น)', 'desc' => 'ตรวจสอบความเหมาะสมของภารกิจและหนังสือเชิญ'],
            ['step' => 2, 'title' => 'งานการเงินและพัสดุ', 'desc' => 'ตรวจสอบสิทธิการเบิกจ่ายและงบประมาณตามระเบียบ'],
            ['step' => 3, 'title' => 'หัวหน้าสำนักงาน / หัวหน้าภาควิชา (เห็นชอบงบ)', 'desc' => 'รับรองวงเงินงบประมาณที่ผ่านการตรวจสอบแล้ว'],
            ['step' => 4, 'title' => 'รองคณบดีฝ่ายบริหาร', 'desc' => 'พิจารณากลั่นกรองคำขอไปราชการ'],
            ['step' => 5, 'title' => 'คณบดีวิทยาลัยดุริยางคศิลป์', 'desc' => 'ลงนามอนุมัติเดินทางไปราชการขั้นสุดท้าย']
        ];
    } elseif ($cat === 'leave_req') {
        $wf_title = 'สถานะขั้นตอนการพิจารณาใบลา 4 ลำดับ (E-LEAVE WORKFLOW)';
        $wf_steps = [
            ['step' => 1, 'title' => 'งานบุคคล (HR Verification)', 'desc' => 'ตรวจสอบสถิติวันลาสะสมและสิทธิ์การลาคงเหลือ'],
            ['step' => 2, 'title' => 'หัวหน้าสำนักงาน / หัวหน้าภาควิชา', 'desc' => 'พิจารณาอนุญาตการลาในระดับหน่วยงาน'],
            ['step' => 3, 'title' => 'รองคณบดีฝ่ายบริหาร', 'desc' => 'พิจารณากลั่นกรองคำขอลาหยุดราชการ'],
            ['step' => 4, 'title' => 'คณบดี / ผู้รักษาการแทนคณบดี', 'desc' => 'ลงนามอนุมัติใบลาอิเล็กทรอนิกส์ขั้นสุดท้าย']
        ];
    } elseif ($cat === 'student_req') {
        $ac = $academic_details[(int)$r['id']] ?? [];
        $is_open = ($r['type_label'] === 'ขอเปิดรายวิชา' || ($ac['request_type'] ?? '') === 'open_course');
        $inst_name = !empty($ac['inst_name']) ? $ac['inst_name'] : 'อาจารย์ผู้สอนประจำรายวิชา';
        $adv_name = !empty($ac['adv_name']) ? $ac['adv_name'] : 'อาจารย์ที่ปรึกษา';
        $is_duriyang = (mb_strpos($ac['major'] ?? '', 'ดุริยางค') !== false);
        $head_name = $is_duriyang
            ? 'ผู้ช่วยศาสตราจารย์อรรคพงศ์ ภูลายยาว (หัวหน้าภาควิชาดุริยางคศาสตร์)'
            : 'ดร.พิษณุ บุญศรีอนันต์ (หัวหน้าภาควิชาดนตรีศึกษา)';
        $head_step_title = $is_duriyang
            ? '3. หัวหน้าภาควิชาดุริยางคศาสตร์'
            : '3. หัวหน้าภาควิชาดนตรีศึกษา';
        $deputy_name = 'ผศ.ดร.วีรยุทธ สีคุณหลิ่ว (รองคณบดีฝ่ายวิชาการ)';
        $academic_name = 'นางสาวธรัญญา ปูธิรัตน์ (งานบริการการศึกษา)';

        $wf_title = 'สถานะขั้นตอนคำร้องวิชาการ 5 ลำดับ (ACADEMIC WORKFLOW)';
        $wf_steps = [
            [
                'step' => 1,
                'title' => $is_open ? '1. อาจารย์ผู้สอน / ประสานงาน' : '1. อาจารย์ผู้สอนประจำกลุ่ม',
                'name' => $inst_name,
                'desc' => 'พิจารณาความพร้อมในการเปิดสอนและจำนวนรับนิสิตในกลุ่มเรียน',
                'comment' => $ac['instructor_comment'] ?? ''
            ],
            [
                'step' => 2,
                'title' => '2. อาจารย์ที่ปรึกษา',
                'name' => $adv_name,
                'desc' => 'ตรวจสอบแผนการเรียนและความจำเป็นในการลงทะเบียนของนิสิต',
                'comment' => $ac['advisor_comment'] ?? ''
            ],
            [
                'step' => 3,
                'title' => $head_step_title,
                'name' => $head_name,
                'desc' => 'พิจารณาให้ความเห็นชอบในระดับภาควิชาและหลักสูตร',
                'comment' => $ac['head_comment'] ?? ''
            ],
            [
                'step' => 4,
                'title' => '4. รองคณบดีฝ่ายวิชาการ',
                'name' => $deputy_name,
                'desc' => 'พิจารณาอนุมัติคำร้องในระดับคณะ',
                'comment' => $ac['deputy_comment'] ?? ''
            ],
            [
                'step' => 5,
                'title' => '5. งานบริการการศึกษา / งานวิชาการ',
                'name' => $academic_name,
                'desc' => $is_open ? 'ดำเนินการเปิดกลุ่มเรียนในระบบทะเบียนและปิดคำร้อง' : 'ดำเนินการสำรองที่นั่งในระบบทะเบียนและปิดคำร้อง',
                'comment' => $ac['academic_comment'] ?? ''
            ]
        ];
    } elseif ($cat === 'supply_req') {
        $wf_title = 'สถานะขั้นตอนการเบิกวัสดุ 2 ลำดับ (SUPPLIES WORKFLOW)';
        $wf_steps = [
            ['step' => 1, 'title' => 'หัวหน้าสำนักงานพิจารณาอนุมัติ (Step 1)', 'desc' => 'ตรวจสอบความจำเป็นในการขอเบิกวัสดุอุปกรณ์สำนักงาน'],
            ['step' => 2, 'title' => 'งานพัสดุตรวจสอบคลังและจ่ายวัสดุ (Step 2)', 'desc' => 'ตัดยอดสต็อกคงคลังและส่งมอบวัสดุให้ผู้ขอเบิก']
        ];
    } else {
        $wf_steps = [
            ['step' => 1, 'title' => 'เจ้าหน้าที่/ผู้รับผิดชอบพิจารณาคำขอ', 'desc' => $r['current_status']]
        ];
    }

    $inbox_drawer_items[] = [
        'record_id'         => (int)$r['id'],
        'code'              => $code,
        'category'          => $cat,
        'type_label'        => $r['type_label'],
        'requester'         => $r['requester'],
        'avatar'            => $r['profile_picture'] ?? '',
        'initials'          => mb_substr(trim($r['requester']), 0, 1),
        'detail'            => $r['detail'],
        'date_str'          => date('d/m/Y เวลา H:i น.', strtotime($r['date_time'])),
        'current_status'    => $r['current_status'],
        'step_num'          => $step_num,
        'wf_title'          => $wf_title,
        'wf_steps'          => $wf_steps,
        'can_quick_approve' => $can_quick_approve,
        'full_url'          => $final_link
    ];
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    
    <?php if (!empty($inbox_flash_msg)): ?>
        <div class="alert alert-<?php echo $inbox_flash_type; ?> alert-dismissible fade show mt-3 mb-0 border-0 shadow-sm rounded-3 py-2 px-3 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid <?php echo $inbox_flash_type === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-warning'; ?>"></i>
            <span class="small fw-bold"><?php echo htmlspecialchars($inbox_flash_msg); ?></span>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Enterprise Approval Queue Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 extra-small fw-bold">
                    <i class="fa-solid fa-bolt me-1"></i>UNIVERSAL APPROVAL INBOX
                </span>
                <span class="badge bg-warning-subtle text-dark border px-2 py-1 extra-small fw-bold">
                    รอดำเนินการ <?php echo count($all_requests); ?> รายการ
                </span>
            </div>
            <h1 class="h4 fw-bold text-dark mb-0"><i class="fa-solid fa-inbox text-purple me-2"></i>ศูนย์รวมรายการรอพิจารณาอนุมัติ (Approval Queue)</h1>
            <p class="text-muted small mb-0 mt-1">รวบรวมคำขอและเอกสารราชการที่รอการพิจารณาจากทุกส่วนงานในที่เดียว คลิกที่รายการเพื่อเปิดถาดพิจารณาด้านขวาได้ทันที</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3 mt-md-0">
            <button type="button" class="btn btn-light border text-secondary fw-semibold btn-sm px-3" style="height: 36px;" onclick="toggleErpDensity()">
                <i class="fa-solid fa-bars me-1"></i>
                <span class="js-density-label">Density: Cozy</span>
            </button>

            <div class="position-relative">
                <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 12px; transform: translateY(-50%); z-index: 4; font-size: 0.82rem;"></i>
                <input type="text" id="searchInput" class="form-control bg-white" placeholder="ค้นหาชื่อผู้ยื่น, รายละเอียด..." onkeyup="applyFilters()" style="padding-left: 34px !important; min-width: 220px;">
            </div>
            
            <div class="position-relative">
                <select class="form-select fw-bold text-dark bg-white" id="categoryFilter" onchange="applyFilters()" style="min-width: 185px; cursor: pointer;">
                    <option value="all">ทุกหมวดหมู่ (<?php echo count($all_requests); ?>)</option>
                    <option value="student_req">คำร้องนิสิต (<?php echo $count_std; ?>)</option>
                    <option value="leave_req">ระบบใบลา (<?php echo $count_leave; ?>)</option>
                    <option value="training_req">ไปราชการ/อบรม (<?php echo $count_training; ?>)</option>
                    <option value="supply_req">เบิกพัสดุ (<?php echo $count_sup; ?>)</option>
                    <option value="room_req">จองห้อง/สถานที่ (<?php echo $count_room; ?>)</option>
                    <option value="repair_req">แจ้งซ่อมบำรุง (<?php echo $count_repair; ?>)</option>
                    <option value="grade_appeal_req">อุทธรณ์คะแนน (<?php echo $count_appeal; ?>)</option>
                    <option value="complaint_req">เรื่องร้องเรียน (<?php echo $count_complaint; ?>)</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Interactive Category Filter Cards -->
    <div class="row g-2 mb-4">
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('leave_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">คำขอใบลาบุคลากร</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_leave); ?></h3>
                    </div>
                    <div class="stat-icon bg-danger-subtle text-danger"><i class="fa-solid fa-envelope-open-text"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('training_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">ขอไปราชการ / อบรม</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_training); ?></h3>
                    </div>
                    <div class="stat-icon bg-purple-subtle text-purple"><i class="fa-solid fa-plane-departure"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('supply_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">เบิกวัสดุสำนักงาน</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_sup); ?></h3>
                    </div>
                    <div class="stat-icon bg-success-subtle text-success"><i class="fa-solid fa-box-open"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('student_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">คำร้องวิชาการนิสิต</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_std); ?></h3>
                    </div>
                    <div class="stat-icon bg-primary-subtle text-primary"><i class="fa-solid fa-user-graduate"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('room_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">จองห้อง / อาคารสถานที่</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_room); ?></h3>
                    </div>
                    <div class="stat-icon bg-info-subtle text-info"><i class="fa-solid fa-door-open"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('repair_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">แจ้งซ่อมบำรุง / โสตฯ</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_repair); ?></h3>
                    </div>
                    <div class="stat-icon bg-warning-subtle text-warning"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('grade_appeal_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">อุทธรณ์ผลการเรียน</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_appeal); ?></h3>
                    </div>
                    <div class="stat-icon bg-light text-dark border"><i class="fa-solid fa-scale-balanced"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card h-100 p-3 erp-filter-card" onclick="selectCategoryCard('complaint_req', this)" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="extra-small text-muted fw-bold text-uppercase">สายตรงคณบดี / ร้องเรียน</div>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?php echo number_format($count_complaint); ?></h3>
                    </div>
                    <div class="stat-icon bg-light text-secondary border"><i class="fa-solid fa-bullhorn"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Universal Queue Data Grid -->
    <div class="card mb-5">
        <div class="card-header d-flex justify-content-between align-items-center py-3 px-4">
            <span class="fw-bold text-dark"><i class="fa-solid fa-list-check text-purple me-2"></i>รายการเอกสารรอพิจารณา</span>
            <button type="button" class="btn btn-sm btn-light border text-secondary fw-semibold" onclick="selectCategoryCard('all', null)">
                <i class="fa-solid fa-rotate-left me-1"></i> แสดงทั้งหมด
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="width: 13%;">วันที่ยื่นคำขอ</th>
                            <th style="width: 15%;">หมวดหมู่ระบบ</th>
                            <th style="width: 20%;">ผู้ยื่นเรื่อง</th>
                            <th style="width: 28%;">รายละเอียดคำขอ</th>
                            <th class="text-center" style="width: 14%;">สถานะปัจจุบัน</th>
                            <th class="text-center pe-4" style="width: 10%;">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_requests)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-success opacity-75 mb-2 mt-2">
                                        <i class="fa-solid fa-circle-check fa-3x"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark">ไม่มีรายการค้างพิจารณา</h6>
                                    <p class="small text-muted mb-2">คุณได้ดำเนินการพิจารณาคำขอในคิวงานครบถ้วนทั้งหมดแล้ว</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_requests as $idx => $row): ?>
                            <tr class="request-row" data-category="<?php echo htmlspecialchars($row['category']); ?>" style="cursor: pointer;" onclick="openInboxInspectDrawer(<?php echo $idx; ?>, event)">
                                <td class="ps-4">
                                    <div class="small fw-bold text-dark"><?php echo date('d/m/Y', strtotime($row['date_time'])); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('H:i', strtotime($row['date_time'])); ?> น.</div>
                                </td>
                                <td>
                                    <?php 
                                        $b_class = 'bg-primary-subtle text-primary border-primary-subtle';
                                        if($row['category'] == 'supply_req') $b_class = 'bg-success-subtle text-success border-success-subtle';
                                        if($row['category'] == 'leave_req') $b_class = 'bg-danger-subtle text-danger border-danger-subtle';
                                        if($row['category'] == 'room_req') $b_class = 'bg-info-subtle text-info border-info-subtle';
                                        if($row['category'] == 'repair_req') $b_class = 'bg-warning-subtle text-warning border-warning-subtle'; 
                                        if($row['category'] == 'training_req') $b_class = 'bg-purple-subtle text-purple border-purple-subtle';
                                    ?>
                                    <span class="badge border <?php echo $b_class; ?> px-2 py-1"><?php echo htmlspecialchars($row['type_label']); ?></span>
                                </td>
                                <td><div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['requester']); ?></div></td>
                                <td>
                                    <div class="text-truncate small text-dark" style="max-width: 340px;" title="<?php echo htmlspecialchars($row['detail']); ?>">
                                        <?php echo htmlspecialchars($row['detail']); ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1 extra-small"><i class="fa-regular fa-clock text-warning me-1"></i><?php echo htmlspecialchars($row['current_status']); ?></span>
                                </td>
                                <td class="text-center pe-4" onclick="event.stopPropagation();">
                                    <button type="button" class="btn btn-primary btn-sm px-3 fw-bold" onclick="openInboxInspectDrawer(<?php echo $idx; ?>, event)">
                                        พิจารณา <i class="fa-solid fa-arrow-right ms-1"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Slide-Over Inspection & Approval Drawer for Action Inbox -->
<div class="offcanvas offcanvas-end bg-white" tabindex="-1" id="inboxInspectDrawer" style="width: 520px !important; max-width: 100vw; border-left: 1px solid #cbd5e1 !important; box-shadow: -16px 0 40px -8px rgba(15, 23, 42, 0.16) !important;">
    <div class="offcanvas-header border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2 overflow-hidden">
            <span class="badge bg-purple-subtle text-purple border font-monospace px-2 py-1" id="inboxDrawerCode">DOC-0000</span>
            <h6 class="fw-bold text-dark mb-0 text-truncate" id="inboxDrawerTitle">รายละเอียดคำขอ</h6>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-4" style="overflow-y: auto;">
        <div class="p-3 rounded-3 border bg-light mb-4 d-flex align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3 overflow-hidden">
                <div class="inbox-drawer-avatar position-relative overflow-hidden flex-shrink-0" id="inboxDrawerAvatarWrap" style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #4c0582 0%, #5b21b6 100%); color: #fff; font-size: 1rem; font-weight: 700; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(91, 33, 182, 0.2);">
                    <img id="inboxDrawerAvatarImg" src="" referrerpolicy="no-referrer" class="w-100 h-100" style="object-fit: cover; display: none;" alt="Profile" onerror="this.style.display='none'; document.getElementById('inboxDrawerInitials').style.display='flex';">
                    <span id="inboxDrawerInitials" style="display: flex;">ผ</span>
                </div>
                <div class="overflow-hidden">
                    <div class="text-muted extra-small fw-bold text-uppercase">ผู้ยื่นคำขอ</div>
                    <div class="fw-bold text-dark text-truncate" id="inboxDrawerRequester">-</div>
                    <div class="text-muted small" id="inboxDrawerDate">-</div>
                </div>
            </div>
            <a href="#" id="inboxDrawerFullLink" class="btn btn-sm btn-white border bg-white fw-semibold text-purple text-nowrap flex-shrink-0">
                <i class="fa-solid fa-up-right-from-square me-1"></i>เปิดหน้าจัดการเต็ม
            </a>
        </div>

        <div class="mb-4">
            <div class="fw-bold text-dark mb-2 small">รายละเอียดคำขอ</div>
            <div class="p-3 rounded-3 border bg-white">
                <div class="d-flex justify-content-between py-2 border-bottom small">
                    <span class="text-muted">ประเภทคำขอ</span>
                    <span class="fw-bold text-dark" id="inboxDrawerType">-</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom small">
                    <span class="text-muted">สถานะปัจจุบัน</span>
                    <span class="fw-bold text-warning" id="inboxDrawerStatus">-</span>
                </div>
                <div class="pt-2 small">
                    <div class="text-muted mb-1">หัวข้อ / รายละเอียด</div>
                    <div class="fw-bold text-dark" id="inboxDrawerDetail">-</div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="fw-bold text-secondary mb-2" style="font-size: 0.72rem; letter-spacing: 0.04em;" id="inboxDrawerWfTitle">สถานะขั้นตอนการพิจารณา</div>
            <div class="p-3 rounded-3 border bg-light" id="inboxDrawerWfList"></div>
        </div>

        <form method="POST" action="requests_list.php" id="inboxApproveForm">
            <input type="hidden" name="inbox_drawer_action" id="inboxDrawerActionInput" value="approve">
            <input type="hidden" name="inbox_category" id="inboxDrawerCategoryInput" value="">
            <input type="hidden" name="inbox_record_id" id="inboxDrawerRecordIdInput" value="">
            <div class="mb-2" id="inboxCommentWrap">
                <label class="fw-bold text-dark mb-1 small">ความเห็น / ข้อเสนอแนะประกอบการพิจารณา</label>
                <textarea name="inbox_comment" class="form-control form-control-sm" rows="3" placeholder="ระบุความเห็นประกอบการอนุมัติ หรือเหตุผลกรณีส่งกลับ/ไม่อนุมัติ..."></textarea>
            </div>
        </form>
    </div>
    <div class="offcanvas-footer border-top py-3 px-4 bg-white d-flex justify-content-between align-items-center gap-2">
        <button type="button" class="btn btn-light border btn-sm px-3 fw-bold text-secondary" data-bs-dismiss="offcanvas">ปิดหน้าต่าง</button>
        <div class="d-flex align-items-center gap-2" id="inboxQuickActionBtns">
            <button type="button" class="btn btn-warning btn-sm px-3 fw-bold text-dark" onclick="submitInboxDrawerAction('return')">
                <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับ/ไม่อนุมัติ
            </button>
            <button type="button" class="btn btn-primary btn-sm px-3 fw-bold" onclick="submitInboxDrawerAction('approve')">
                <i class="fa-solid fa-check me-1"></i>อนุมัติคำขอนี้
            </button>
        </div>
        <a href="#" id="inboxGoFullBtn" class="btn btn-primary btn-sm px-3 fw-bold" style="display: none;">
            ไปยังหน้าดำเนินการ <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
    </div>
</div>

<style>
    .erp-filter-card.active-filter {
        border-color: #39048f !important;
        background-color: #f5f0ff !important;
        box-shadow: 0 0 0 2px rgba(57, 4, 143, 0.15) !important;
    }
    @media (max-width: 575.98px) {
        #inboxInspectDrawer {
            width: 100% !important;
        }
    }
</style>

<script>
const INBOX_DRAWER_DATA = <?php echo json_encode($inbox_drawer_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function escInboxHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openInboxInspectDrawer(idx, ev) {
    if (ev) ev.stopPropagation();
    const item = INBOX_DRAWER_DATA[idx];
    if (!item) return;

    document.getElementById('inboxDrawerCode').textContent = item.code;
    document.getElementById('inboxDrawerTitle').textContent = item.type_label;
    document.getElementById('inboxDrawerRequester').textContent = item.requester;
    document.getElementById('inboxDrawerDate').textContent = 'ยื่นเมื่อ ' + item.date_str;
    document.getElementById('inboxDrawerType').textContent = item.type_label;
    document.getElementById('inboxDrawerStatus').textContent = item.current_status;
    document.getElementById('inboxDrawerDetail').textContent = item.detail;
    document.getElementById('inboxDrawerFullLink').href = item.full_url;
    document.getElementById('inboxGoFullBtn').href = item.full_url;

    // Google Profile Picture / Avatar Logic
    const inboxImgEl = document.getElementById('inboxDrawerAvatarImg');
    const inboxInitEl = document.getElementById('inboxDrawerInitials');
    let inboxAvatarUrl = '';
    if (item.avatar) {
        inboxAvatarUrl = /^https?:\/\//i.test(item.avatar) ? item.avatar : ('/music-e-office/uploads/avatars/' + item.avatar);
    }
    if (inboxAvatarUrl) {
        inboxImgEl.src = inboxAvatarUrl;
        inboxImgEl.style.display = 'block';
        inboxInitEl.style.display = 'none';
    } else {
        inboxImgEl.style.display = 'none';
        inboxInitEl.style.display = 'flex';
        inboxInitEl.textContent = item.initials || 'ผ';
    }

    document.getElementById('inboxDrawerCategoryInput').value = item.category;
    document.getElementById('inboxDrawerRecordIdInput').value = item.record_id;
    document.getElementById('inboxDrawerWfTitle').textContent = item.wf_title;

    const wfHtml = (item.wf_steps || []).map(st => {
        const isDone = st.step < item.step_num;
        const isCurr = st.step == item.step_num;
        const isRejectedHere = (item.current_status && item.current_status.includes('ไม่อนุมัติ') && (isCurr || (!isDone && st.comment)));
        let badge = '<span class="text-muted extra-small">รอคิว</span>';
        if (isDone) {
            badge = '<span class="badge bg-success-subtle text-success">เสร็จสิ้น</span>';
        } else if (isRejectedHere) {
            badge = '<span class="badge bg-danger-subtle text-danger border">ไม่อนุมัติ</span>';
        } else if (isCurr) {
            badge = '<span class="badge bg-warning-subtle text-dark border">รอดำเนินการ</span>';
        }
        return `
            <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom last-no-border">
                <div class="d-flex align-items-start gap-2 flex-grow-1">
                    <span class="badge rounded-pill ${isDone ? 'bg-success' : (isCurr ? 'bg-warning text-dark' : 'bg-secondary-subtle text-secondary')}">${isDone ? '✓' : st.step}</span>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">${escInboxHtml(st.title)}</div>
                        ${st.name ? `<div class="fw-semibold text-dark extra-small mt-1"><i class="fa-solid fa-user-tie me-1 text-secondary"></i>${escInboxHtml(st.name)}</div>` : ''}
                        <div class="text-muted extra-small">${escInboxHtml(st.desc)}</div>
                        ${st.comment ? `
                            <div class="mt-2 p-2 bg-white rounded-2 extra-small border text-dark shadow-xs">
                                <span class="text-muted fw-bold d-block mb-1"><i class="fa-regular fa-comment-dots me-1 text-primary"></i>ความเห็น:</span>
                                <div style="white-space: pre-line;">${escInboxHtml(st.comment)}</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="ms-2 flex-shrink-0">${badge}</div>
            </div>
        `;
    }).join('');
    document.getElementById('inboxDrawerWfList').innerHTML = wfHtml;

    if (item.can_quick_approve) {
        document.getElementById('inboxQuickActionBtns').style.display = 'flex';
        document.getElementById('inboxCommentWrap').style.display = 'block';
        document.getElementById('inboxGoFullBtn').style.display = 'none';
    } else {
        document.getElementById('inboxQuickActionBtns').style.display = 'none';
        document.getElementById('inboxCommentWrap').style.display = 'none';
        document.getElementById('inboxGoFullBtn').style.display = 'inline-block';
    }

    const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('inboxInspectDrawer'));
    offcanvas.show();
}

function submitInboxDrawerAction(act) {
    document.getElementById('inboxDrawerActionInput').value = act;
    document.getElementById('inboxApproveForm').submit();
}

function selectCategoryCard(cat, cardEl) {
    const sel = document.getElementById('categoryFilter');
    if (sel) {
        sel.value = (sel.value === cat && cat !== 'all') ? 'all' : cat;
    }
    document.querySelectorAll('.erp-filter-card').forEach(el => el.classList.remove('active-filter'));
    if (cardEl && sel && sel.value !== 'all') {
        cardEl.classList.add('active-filter');
    }
    applyFilters();
}

function applyFilters() {
    const category = document.getElementById('categoryFilter').value;
    const searchText = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.request-row');
    let hasVisible = false;

    rows.forEach(row => {
        const rowText = row.innerText.toLowerCase();
        const matchCategory = (category === 'all' || row.dataset.category === category);
        const matchSearch = (searchText === '' || rowText.includes(searchText));

        if (matchCategory && matchSearch) {
            row.style.display = '';
            hasVisible = true;
        } else {
            row.style.display = 'none';
        }
    });

    let emptyRow = document.getElementById('empty-row-msg');
    if (!emptyRow && !hasVisible) {
        const tbody = document.querySelector('table tbody');
        if (tbody) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'empty-row-msg';
            emptyRow.innerHTML = `<td colspan="6" class="text-center py-5 text-muted"><i class="fa-solid fa-filter-circle-xmark fa-2x mb-2 opacity-50"></i><br>ไม่พบรายการในหมวดหมู่หรือคำค้นหานี้</td>`;
            tbody.appendChild(emptyRow);
        }
    } else if (emptyRow && hasVisible) {
        emptyRow.remove();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
