<?php
// =========================================================
// MSU Music Connect - Main Dashboard (Personal Workspace & Portal)
// วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth/session_check.php';
require_once 'config/db.php';

// ---------------------------------------------------------
// ตรวจจับอุปกรณ์: หากเข้าจาก PC ให้อยู่หน้า PC เสมอ, เข้าจากมือถือจริงจึงสลับไปหน้า Mobile
// ---------------------------------------------------------
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_real_mobile = (bool)preg_match('/(android|iphone|ipod|blackberry|windows phone|mobile)/i', $user_agent);

if (isset($_GET['pc'])) {
    // ผู้ใช้ต้องการเข้าใช้งาน PC Desktop
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');
} elseif (isset($_GET['mobile'])) {
    // ผู้ใช้ต้องการสลับไป Mobile App
    $_SESSION['prefer_desktop'] = false;
    $_SESSION['app_mode'] = 'mobile';
    setcookie('app_mode', 'mobile', time() + 86400 * 30, '/');
    header("Location: mobile/index.php?mobile=1");
    exit();
} elseif (!$is_real_mobile) {
    // หากเข้าจากเครื่องคอมพิวเตอร์ PC ให้อยู่หน้า Desktop เสมอ
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');
}

// สลับไปหน้า Mobile อัตโนมัติ เฉพาะเมื่อเป็นอุปกรณ์มือถือจริงๆ (ไม่ใช่ Desktop PC)
if ($is_real_mobile && empty($_SESSION['prefer_desktop']) && !isset($_GET['pc'])) {
    header("Location: mobile/index.php");
    exit();
}

// ---------------------------------------------------------
// 0. ตรวจสอบสิทธิ์ผู้บริหาร และการสลับมุมมอง (View Switcher)
// ---------------------------------------------------------
$exec_roles = ['admin', 'คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'หัวหน้าสำนักงาน', 'งานแผน', 'งานวิชาการ', 'งานบริการการศึกษา'];
$is_executive = function_exists('hasRole') && hasRole($exec_roles);

// รับค่ามุมมอง หรือจำค่าจาก Session
if (isset($_GET['view'])) {
    $view_mode = ($_GET['view'] === 'personal') ? 'personal' : 'executive';
    $_SESSION['dashboard_view_preference'] = $view_mode;
} elseif (isset($_SESSION['dashboard_view_preference'])) {
    $view_mode = $_SESSION['dashboard_view_preference'];
} else {
    $view_mode = $is_executive ? 'executive' : 'personal';
}

// ถ้าเป็นผู้บริหาร และเลือกมุมมองภาพรวมผู้บริหาร ให้ดึงหน้า executive_dashboard.php
if ($is_executive && $view_mode === 'executive') {
    require 'executive_dashboard.php';
    exit();
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$user_id = $_SESSION['user_id'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'ผู้ใช้งานระบบ';
$today_date = date('Y-m-d');

// ---------------------------------------------------------
// 1. ตรวจสอบการเปิด-ปิด Module
// ---------------------------------------------------------
$modules = [
    'module_cv'             => true, 
    'module_leave'          => true, 
    'module_worklog'        => true, 
    'module_projects'       => true,
    'module_meeting'        => true,
    'module_training'       => true,
    'module_supplies'       => true,
    'module_room_booking'   => true,
    'module_repair_request' => true,
    'module_attendance'     => true
];

try {
    $settings_query = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name LIKE 'module_%'");
    $settings_data = $settings_query->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($settings_data as $key => $val) {
        if (array_key_exists($key, $modules)) {
            $modules[$key] = ($val == '1' || $val == 1);
        }
    }
} catch (PDOException $e) {}

// ดึงปีงบประมาณปัจจุบัน
$active_fy = date('Y') + 543;
try {
    $stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
    $fetched_fy = $stmt_fy->fetchColumn();
    if (!empty($fetched_fy)) {
        $active_fy = intval($fetched_fy);
    }
} catch (PDOException $e) {}

// ---------------------------------------------------------
// 2. ดึงข้อมูลส่วนตัวของผู้ใช้ (Profile & Department)
// ---------------------------------------------------------
$user_info = [
    'dept_name' => 'วิทยาลัยดุริยางคศิลป์',
    'position'  => 'บุคลากร',
    'avatar'    => $current_avatar ?? ''
];
try {
    $stmt_u = $pdo->prepare("SELECT u.*, d.dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.user_id = ?");
    $stmt_u->execute([$user_id]);
    $u_data = $stmt_u->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        if (!empty($u_data['dept_name'])) $user_info['dept_name'] = $u_data['dept_name'];
        if (!empty($u_data['position'])) $user_info['position'] = $u_data['position'];
        if (!empty($u_data['profile_picture'])) $user_info['avatar'] = $u_data['profile_picture'];
    }
} catch (PDOException $e) {}

// ---------------------------------------------------------
// 3. ดึงข้อมูลสถิติแต่ละโมดูล (Data Queries)
// ---------------------------------------------------------

// A. ระบบการลา (Leave)
$leave_stats = ['total_days' => 0, 'pending_approval_count' => 0];
$leave_by_type = [];
if ($modules['module_leave']) {
    try {
        $fy_num = (int)$active_fy;
        $fy_en = ($fy_num > 2500) ? ($fy_num - 543) : $fy_num;
        $fy_start = ($fy_en - 1) . "-10-01";
        $fy_end = $fy_en . "-09-30";

        // วันลาสะสมที่อนุมัติแล้ว ในปีงบประมาณปัจจุบัน
        $stmt_l = $pdo->prepare("SELECT t.type_name, SUM(DATEDIFF(l.end_date, l.start_date) + 1) as total_days 
                                  FROM leave_requests l 
                                  JOIN leave_types t ON l.type_id = t.type_id 
                                  WHERE l.user_id = ? AND l.status = 'approved' 
                                    AND ((l.start_date >= ? AND l.start_date <= ?) OR (l.created_at BETWEEN ? AND ?))
                                  GROUP BY l.type_id");
        $stmt_l->execute([$user_id, $fy_start, $fy_end, $fy_start . ' 00:00:00', $fy_end . ' 23:59:59']);
        $leave_by_type = $stmt_l->fetchAll(PDO::FETCH_KEY_PAIR);
        $leave_stats['total_days'] = !empty($leave_by_type) ? array_sum($leave_by_type) : 0;

        // รายการรอพิจารณาอนุมัติ (ถ้ามีสิทธิ์พิจารณา)
        $leave_conditions = [];
        if (function_exists('hasRole')) {
            if (hasRole(['งานบุคคล'])) $leave_conditions[] = "l.current_step = 1";
            if (hasRole(['หัวหน้าสำนักงาน'])) $leave_conditions[] = "(l.current_step = 2 AND l.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1))";
            if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) $leave_conditions[] = "(l.current_step = 2 AND u.dept_id = 12 AND l.user_id IN (SELECT user_id FROM user_roles WHERE role_id = 1))";
            if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) $leave_conditions[] = "(l.current_step = 2 AND u.dept_id = 13 AND l.user_id IN (SELECT user_id FROM user_roles WHERE role_id = 1))";
            if (hasRole(['รองคณบดีฝ่ายบริหาร'])) $leave_conditions[] = "l.current_step = 3";
            if (hasRole(['คณบดี', 'รักษาการคณบดี'])) $leave_conditions[] = "(l.current_step = 4 AND (l.final_signer_id IS NULL OR l.final_signer_id = ''))";
        }
        $leave_conditions[] = "(l.current_step = 4 AND l.final_signer_id = :uid)";

        if (!empty($leave_conditions)) {
            $stmt_lp = $pdo->prepare("SELECT COUNT(*) FROM leave_requests l JOIN users u ON l.user_id = u.user_id WHERE l.status = 'pending' AND (" . implode(' OR ', $leave_conditions) . ")");
            $stmt_lp->execute([':uid' => $user_id]);
            $leave_stats['pending_approval_count'] = intval($stmt_lp->fetchColumn() ?: 0);
        }
    } catch (PDOException $e) {}
}

// B. ระบบรายงานการอบรม / ไปราชการ (Trainings)
$training_stats = ['total_hours' => 0, 'total_count' => 0, 'pending_approval_count' => 0];
$recent_trainings = [];
if ($modules['module_training']) {
    try {
        // รวมชั่วโมงอบรมในปีงบประมาณปัจจุบัน
        $stmt_tr_sum = $pdo->prepare("SELECT SUM(training_hours) as hrs, COUNT(*) as cnt FROM training_reports WHERE user_id = ? AND status IN ('approved', 'reported') AND (fiscal_year = ? OR fiscal_year = '')");
        $stmt_tr_sum->execute([$user_id, (string)$active_fy]);
        $tr_res = $stmt_tr_sum->fetch(PDO::FETCH_ASSOC);
        if ($tr_res) {
            $training_stats['total_hours'] = floatval($tr_res['hrs'] ?: 0);
            $training_stats['total_count'] = intval($tr_res['cnt'] ?: 0);
        }

        // รายการอบรมล่าสุด 4 รายการ
        $stmt_tr_recent = $pdo->prepare("SELECT report_id, project_name, location, start_date, end_date, training_hours, status FROM training_reports WHERE user_id = ? ORDER BY start_date DESC LIMIT 4");
        $stmt_tr_recent->execute([$user_id]);
        $recent_trainings = $stmt_tr_recent->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // รายการรออนุมัติอบรม (ถ้ามีสิทธิ์)
        $tr_steps = [];
        if (hasRole(['admin', 'งานบุคคล'])) $tr_steps[] = 1;
        if (hasRole(['admin', 'หัวหน้าสำนักงาน'])) $tr_steps[] = 2;
        if (hasRole(['admin', 'รองคณบดีฝ่ายบริหาร'])) $tr_steps[] = 3;
        if (hasRole(['admin', 'คณบดี', 'รักษาการคณบดี'])) $tr_steps[] = 4;
        if (!empty($tr_steps)) {
            $stmt_trp = $pdo->query("SELECT COUNT(*) FROM training_reports WHERE status = 'pending' AND current_step IN (" . implode(',', $tr_steps) . ")");
            $training_stats['pending_approval_count'] = intval($stmt_trp->fetchColumn() ?: 0);
        }
    } catch (PDOException $e) {}
}

// D. ระบบบันทึกงานประจำวัน (Work Log)
$worklog_stats = ['total_mins' => 0, 'avg_mood' => 0, 'log_count' => 0];
$recent_worklogs = [];
$action_plans = [];
$urgent_tasks = [];
if ($modules['module_worklog']) {
    try {
        $current_month = date('Y-m');
        $stmt_work_stats = $pdo->prepare("SELECT SUM(hours_spent) as total_mins, AVG(harmony_score) as avg_mood, COUNT(*) as log_count FROM work_logs WHERE user_id = ? AND DATE_FORMAT(log_date, '%Y-%m') = ?");
        $stmt_work_stats->execute([$user_id, $current_month]);
        $w_row = $stmt_work_stats->fetch(PDO::FETCH_ASSOC);
        if ($w_row) {
            $worklog_stats['total_mins'] = floatval($w_row['total_mins'] ?: 0);
            $worklog_stats['avg_mood'] = round(floatval($w_row['avg_mood'] ?: 0), 1);
            $worklog_stats['log_count'] = intval($w_row['log_count'] ?: 0);
        }

        // บันทึกงานล่าสุด 5 รายการ
        $stmt_recent_work = $pdo->prepare("SELECT l.*, t.task_name FROM work_logs l JOIN work_tasks t ON l.task_id = t.task_id WHERE l.user_id = ? ORDER BY l.log_date DESC, l.created_at DESC LIMIT 5");
        $stmt_recent_work->execute([$user_id]);
        $recent_worklogs = $stmt_recent_work->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // แผนต่อเนื่องจากเมื่อวาน (Next movement)
        $stmt_plan = $pdo->prepare("SELECT l.log_id, t.task_name, l.next_movement, l.log_date FROM work_logs l JOIN work_tasks t ON l.task_id = t.task_id WHERE l.user_id = ? AND l.has_issue = 1 AND l.next_movement IS NOT NULL AND l.next_movement != '' AND l.log_date = DATE_SUB(?, INTERVAL 1 DAY) ORDER BY l.log_id DESC LIMIT 3");
        $stmt_plan->execute([$user_id, $today_date]);
        $action_plans = $stmt_plan->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // งานเร่งด่วนที่ยังไม่เสร็จ
        $stmt_urgent = $pdo->prepare("SELECT l.log_id, t.task_name, l.urgency_level, l.log_date FROM work_logs l JOIN work_tasks t ON l.task_id = t.task_id WHERE l.user_id = ? AND l.urgency_level IN ('ด่วน', 'ด่วนมาก', 'ด่วนที่สุด') AND l.is_completed = 0 ORDER BY l.log_date DESC LIMIT 4");
        $stmt_urgent->execute([$user_id]);
        $urgent_tasks = $stmt_urgent->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {}
}

// E. ระบบการประชุม (Meetings)
$upcoming_meetings = [];
$live_meeting = null;
if ($modules['module_meeting']) {
    try {
        $can_manage_meeting = function_exists('hasRole') && hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน']);
        $where_meet = $can_manage_meeting ? "" : "AND (a.user_id IS NOT NULL OR m.created_by = :user_id)";
        
        $sql = "SELECT DISTINCT m.meeting_id, m.title, m.meeting_no, m.meeting_date, m.start_time, m.end_time, m.location, m.status, 
                                COALESCE(a.attendance_status, 'attending') AS attendance_status 
                FROM meetings m 
                LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id AND a.user_id = :curr_user 
                WHERE m.status IN ('upcoming', 'in_progress') $where_meet 
                ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), 
                         (CASE WHEN m.meeting_date >= :today_date THEN 0 ELSE 1 END), 
                         m.meeting_date ASC, m.start_time ASC LIMIT 4";
        
        $params = ['curr_user' => $user_id, 'today_date' => $today_date];
        if (!$can_manage_meeting) {
            $params['user_id'] = $user_id;
        }
        
        $stmt_meet = $pdo->prepare($sql);
        $stmt_meet->execute($params);
        $all_meets = $stmt_meet->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($all_meets as $m) {
            if ($m['status'] === 'in_progress' && !$live_meeting) {
                $live_meeting = $m;
            }
            $upcoming_meetings[] = $m;
        }
    } catch (PDOException $e) {}
}

// F. ระบบโครงการ (Projects)
$my_projects = [];
if ($modules['module_projects']) {
    try {
        $stmt_proj = $pdo->prepare("SELECT project_id, project_name, deadline, status, task_approve, task_borrow, task_execute, task_report, task_clear_money FROM projects WHERE (responsible_id = ? OR coordinator_id = ?) AND (task_approve + task_borrow + task_execute + task_report + task_clear_money) < 5 ORDER BY deadline ASC LIMIT 3");
        $stmt_proj->execute([$user_id, $user_id]);
        $raw_projs = $stmt_proj->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $today_time = strtotime($today_date);
        foreach ($raw_projs as $p) {
            $completed = $p['task_approve'] + $p['task_borrow'] + $p['task_execute'] + $p['task_report'] + $p['task_clear_money'];
            $progress = round(($completed / 5) * 100);
            $p['progress'] = $progress;
            $p['is_delayed'] = ($progress < 100 && strtotime($p['deadline']) < $today_time);
            $my_projects[] = $p;
        }
    } catch (PDOException $e) {}
}

// G. ระบบเบิกวัสดุ (Supplies)
$total_supplies = 0;
if ($modules['module_supplies']) {
    try {
        $stmt_sup = $pdo->prepare("SELECT COUNT(*) FROM supplies_requests WHERE user_id = ?");
        $stmt_sup->execute([$user_id]);
        $total_supplies = intval($stmt_sup->fetchColumn() ?: 0);
    } catch (PDOException $e) {}
}

// G2. ระบบบันทึกเวลาเข้า-ออกงานของฉัน (Attendance Today)
$my_attendance_today = [
    'has_in'       => false,
    'has_out'      => false,
    'in_time'      => null,
    'out_time'     => null,
    'is_late'      => false,
    'status_badge' => 'bg-secondary-subtle text-secondary',
    'status_label' => 'ยังไม่ลงเวลา'
];
try {
    $stmt_att_my = $pdo->prepare("SELECT scan_type, scan_time FROM attendance_logs WHERE user_id = ? AND DATE(scan_time) = CURDATE() ORDER BY scan_time ASC");
    $stmt_att_my->execute([$user_id]);
    $my_att_logs = $stmt_att_my->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($my_att_logs as $log) {
        if ($log['scan_type'] === 'in' && !$my_attendance_today['has_in']) {
            $my_attendance_today['has_in'] = true;
            $my_attendance_today['in_time'] = $log['scan_time'];
            $time_only = date('H:i:s', strtotime($log['scan_time']));
            if ($time_only > '09:00:00') {
                $my_attendance_today['is_late'] = true;
                $my_attendance_today['status_badge'] = 'bg-danger-subtle text-danger border border-danger-subtle';
                $my_attendance_today['status_label'] = 'เข้าสาย (> 09:00 น.)';
            } else {
                $my_attendance_today['is_late'] = false;
                $my_attendance_today['status_badge'] = 'bg-success-subtle text-success border border-success-subtle';
                $my_attendance_today['status_label'] = 'เข้างานตรงเวลา';
            }
        } elseif ($log['scan_type'] === 'out') {
            $my_attendance_today['has_out'] = true;
            $my_attendance_today['out_time'] = $log['scan_time'];
        }
    }
} catch (PDOException $e) {}

// H. เตรียมข้อมูลปฏิทินส่วนตัว (Calendar Events)
$calendar_events = [];
try {
    // วันลาของฉัน
    if ($modules['module_leave']) {
        $stmt_cal_l = $pdo->prepare("SELECT l.start_date, l.end_date, l.status, t.type_name FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id WHERE l.user_id = ? AND l.status != 'rejected'");
        $stmt_cal_l->execute([$user_id]);
        while ($cl = $stmt_cal_l->fetch(PDO::FETCH_ASSOC)) {
            $calendar_events[] = [
                'title' => 'ลา: ' . $cl['type_name'],
                'start' => $cl['start_date'],
                'end'   => date('Y-m-d', strtotime($cl['end_date'] . ' +1 day')),
                'backgroundColor' => ($cl['status'] === 'approved') ? '#198754' : '#ffc107',
                'borderColor'     => ($cl['status'] === 'approved') ? '#198754' : '#ffc107',
                'textColor'       => ($cl['status'] === 'approved') ? '#ffffff' : '#000000',
                'url'   => 'modules/leave/index.php'
            ];
        }
    }
    // อบรม/ไปราชการของฉัน
    if ($modules['module_training']) {
        $stmt_cal_tr = $pdo->prepare("SELECT report_id, project_name, start_date, end_date, status FROM training_reports WHERE user_id = ? AND status != 'rejected'");
        $stmt_cal_tr->execute([$user_id]);
        while ($ctr = $stmt_cal_tr->fetch(PDO::FETCH_ASSOC)) {
            $calendar_events[] = [
                'title' => 'อบรม: ' . $ctr['project_name'],
                'start' => $ctr['start_date'],
                'end'   => date('Y-m-d', strtotime($ctr['end_date'] . ' +1 day')),
                'backgroundColor' => '#0d6efd',
                'borderColor'     => '#0d6efd',
                'textColor'       => '#ffffff',
                'url'   => 'modules/trainings/view.php?id=' . $ctr['report_id']
            ];
        }
    }
    // การประชุมสำหรับปฏิทินส่วนตัว
    if ($modules['module_meeting']) {
        try {
            $can_manage_meeting = function_exists('hasRole') && hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน']);
            $cal_meet_where = $can_manage_meeting ? "" : "AND (a.user_id IS NOT NULL OR m.created_by = :cal_uid)";
            $stmt_cal_meet = $pdo->prepare("
                SELECT DISTINCT m.meeting_id, m.title, m.meeting_no, m.meeting_date, m.start_time, m.end_time, m.status 
                FROM meetings m 
                LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id AND a.user_id = :cal_user 
                WHERE m.status != 'draft' $cal_meet_where 
                ORDER BY m.meeting_date ASC
            ");
            $cal_params = ['cal_user' => $user_id];
            if (!$can_manage_meeting) $cal_params['cal_uid'] = $user_id;
            $stmt_cal_meet->execute($cal_params);
            $cal_meets = $stmt_cal_meet->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($cal_meets as $cm) {
                $c_color = ($cm['status'] === 'in_progress') ? '#198754' : (($cm['status'] === 'completed') ? '#6c757d' : '#6f42c1');
                $calendar_events[] = [
                    'title' => 'ประชุม: ' . $cm['title'],
                    'start' => $cm['meeting_date'] . ($cm['start_time'] ? 'T' . $cm['start_time'] : ''),
                    'end'   => $cm['meeting_date'] . ($cm['end_time'] ? 'T' . $cm['end_time'] : ''),
                    'backgroundColor' => $c_color,
                    'borderColor'     => $c_color,
                    'textColor'       => '#ffffff',
                    'url'   => 'modules/meetings/view.php?id=' . $cm['meeting_id']
                ];
            }
        } catch (PDOException $e) {}
    }
} catch (PDOException $e) {}

// ฟังก์ชันแปลง Mood
function getDashboardMoodBadge($score) {
    if ($score >= 4.5) return ['color' => 'text-success', 'bg' => 'bg-success-subtle', 'icon' => 'fa-face-laugh-squint', 'label' => 'ยอดเยี่ยม'];
    if ($score >= 3.5) return ['color' => 'text-primary', 'bg' => 'bg-primary-subtle', 'icon' => 'fa-face-smile-beam', 'label' => 'ดีมาก'];
    if ($score >= 2.5) return ['color' => 'text-info', 'bg' => 'bg-info-subtle', 'icon' => 'fa-face-smile', 'label' => 'ปกติ'];
    if ($score >= 1.5) return ['color' => 'text-warning', 'bg' => 'bg-warning-subtle', 'icon' => 'fa-face-meh', 'label' => 'ตึงเครียด'];
    if ($score > 0) return ['color' => 'text-danger', 'bg' => 'bg-danger-subtle', 'icon' => 'fa-face-frown', 'label' => 'ต้องดูแล'];
    return ['color' => 'text-muted', 'bg' => 'bg-light', 'icon' => 'fa-minus', 'label' => 'ไม่มีข้อมูล'];
}
$mood_data = getDashboardMoodBadge($worklog_stats['avg_mood']);

// คำนวณยอดงานรออนุมัติรวมทั้งหมดของผู้ใช้
$total_pending_action = $leave_stats['pending_approval_count'] + $training_stats['pending_approval_count'];

// วันที่ภาษาไทยแบบเต็ม
$thai_day_names = [
    'Sunday' => 'วันอาทิตย์',
    'Monday' => 'วันจันทร์',
    'Tuesday' => 'วันอังคาร',
    'Wednesday' => 'วันพุธ',
    'Thursday' => 'วันพฤหัสบดี',
    'Friday' => 'วันศุกร์',
    'Saturday' => 'วันเสาร์'
];
$thai_full_months = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$day_of_week_en = date('l');
$thai_day_title = $thai_day_names[$day_of_week_en] ?? 'วันนี้';
$thai_full_date = $thai_day_title . 'ที่ ' . date('j') . ' ' . ($thai_full_months[intval(date('n'))] ?? '') . ' ' . (date('Y') + 543);
$thai_short_date = date('j') . ' ' . ($thai_full_months[intval(date('n'))] ?? '') . ' ' . (date('Y') + 543);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

<style>
    :root {
        --msu-purple-dark: #230259;
        --msu-purple: #39048f;
        --msu-purple-light: #5b13df;
        --msu-gold: #b48a1e;
    }

    /* Enterprise ERP Hero Command Header */
    .dashboard-hero-clean {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        position: relative;
        overflow: hidden;
    }
    .dashboard-hero-clean::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #39048f 0%, #5b13df 60%, #b48a1e 100%);
    }

    /* Enterprise Quick Action Buttons */
    .clean-pill-btn {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #1e293b;
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .clean-pill-btn i {
        color: #39048f;
        transition: color 0.15s ease;
    }
    .clean-pill-btn:hover {
        background: #f4efff;
        border-color: #39048f;
        color: #39048f;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(57, 4, 143, 0.08);
    }

    /* Enterprise Telemetry Clock Chip */
    .clean-clock-chip {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 6px 14px;
        font-variant-numeric: tabular-nums;
    }
    .colon-pulse {
        animation: colonFade 1s infinite;
    }
    @keyframes colonFade {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }

    .btn-outline-purple {
        color: #39048f;
        border-color: #39048f;
        background-color: #ffffff;
    }
    .btn-outline-purple:hover {
        background-color: #39048f;
        color: #ffffff;
    }

    /* Enterprise Metric Cards */
    .metric-card {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        transition: all 0.18s ease;
        text-decoration: none;
        color: inherit;
        display: block;
        height: 100%;
        position: relative;
        overflow: hidden;
        font-variant-numeric: tabular-nums;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.08);
        border-color: #cbd5e1;
        color: inherit;
    }
    .metric-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    /* Quick Action Pill */
    .quick-action-pill {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.25);
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .quick-action-pill:hover {
        background: #ffffff;
        color: var(--msu-purple);
        transform: translateY(-1px);
    }

    /* Segmented Enterprise Nav Tabs */
    .nav-pills-modern {
        background: #f1f5f9;
        padding: 4px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        display: inline-flex;
    }
    .nav-pills-modern .nav-link {
        border-radius: 7px !important;
        padding: 6px 14px;
        font-weight: 600;
        font-size: 0.8rem;
        color: #475569;
        background-color: transparent;
        transition: all 0.15s ease;
        border: none;
    }
    .nav-pills-modern .nav-link.active {
        background-color: #ffffff;
        color: #0f172a;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }
    .nav-pills-modern .nav-link:hover:not(.active) {
        color: #0f172a;
    }

    /* Clean Enterprise Card */
    .clean-card {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }

    /* Pulsing Badge */
    .pulse-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #10b981;
        display: inline-block;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulseGreen 1.6s infinite;
    }
    @keyframes pulseGreen {
        0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
        100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    /* Enterprise Telemetry KPI Cards (Matching Executive Overview) */
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
        text-decoration: none;
        color: inherit;
        font-variant-numeric: tabular-nums;
    }
    .exec-kpi-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 10px 24px -6px rgba(15, 23, 42, 0.07);
        transform: translateY(-2px);
        color: inherit;
    }
    .exec-kpi-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        line-height: 1.3;
    }
    .exec-kpi-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.92rem;
        flex-shrink: 0;
    }
    .exec-kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.02em;
        line-height: 1.1;
    }
    .exec-kpi-unit {
        font-size: 0.82rem;
        font-weight: 500;
        color: #64748b;
    }
    .exec-kpi-footer {
        padding-top: 0.75rem;
        margin-top: 0.75rem;
        border-top: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        font-size: 0.75rem;
        color: #64748b;
    }
    .exec-kpi-link {
        color: #39048f;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* FullCalendar styling */
    .fc { font-family: inherit; font-size: 0.82rem; }
    .fc .fc-toolbar-title { font-size: 1rem; font-weight: 700; color: #0f172a; }
    .fc .fc-button { border-radius: 8px !important; padding: 4px 12px; font-weight: 600; font-size: 0.78rem; }
    .fc .fc-button-primary { background-color: var(--msu-purple); border-color: var(--msu-purple); }
    .fc .fc-button-primary:hover { background-color: #230259; border-color: #230259; }
    .fc-event { border-radius: 5px; padding: 2px 5px; font-size: 0.74rem; cursor: pointer; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    
    <?php if (!empty($_SESSION['flash_msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> alert-dismissible fade show rounded-4 border-0 shadow-sm py-2 px-3 mt-3 mb-2 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid <?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-warning'; ?>"></i>
            <span class="small fw-bold"><?php echo htmlspecialchars($_SESSION['flash_msg']); ?></span>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- 1. Enterprise Command Header (Matching Executive Overview) -->
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center pt-3 pb-2 mb-3 gap-3">
        <div>
            <div style="font-size: 0.76rem; font-weight: 600; color: #64748b;" class="mb-1">
                MSU ERP <span class="mx-1">›</span> Personal Workspace & Self-Service Hub
            </div>
            <h1 style="font-size: 1.3rem; font-weight: 700; color: #0f172a; letter-spacing: -0.015em;" class="mb-0">
                สวัสดี, <?php echo htmlspecialchars($full_name); ?> <span class="text-muted fw-normal" style="font-size: 0.9rem;">• <?php echo htmlspecialchars($user_info['dept_name']); ?></span>
            </h1>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Live Attendance Status Chip -->
            <a href="modules/attendance/my_attendance.php" class="clean-clock-chip text-decoration-none d-flex align-items-center gap-2 py-1 px-3" style="height: 36px;" title="คลิกเพื่อดูบันทึกเวลาของฉัน">
                <i class="fa-solid fa-fingerprint <?php echo $my_attendance_today['has_in'] ? ($my_attendance_today['is_late'] ? 'text-warning' : 'text-success') : 'text-danger'; ?>" style="font-size: 0.9rem;"></i>
                <span class="extra-small text-muted fw-semibold">เข้างานวันนี้:</span>
                <?php if ($my_attendance_today['has_in']): ?>
                    <span class="fw-bold font-monospace text-dark" style="font-size: 0.85rem;"><?php echo date('H:i', strtotime($my_attendance_today['in_time'])); ?> น.</span>
                    <span class="badge <?php echo $my_attendance_today['status_badge']; ?> rounded-pill extra-small"><?php echo $my_attendance_today['is_late'] ? 'สาย' : 'ตรงเวลา'; ?></span>
                <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger rounded-pill extra-small">ยังไม่ลงเวลา</span>
                <?php endif; ?>
            </a>

            <div class="clean-clock-chip d-flex align-items-center gap-2 py-1 px-3" style="height: 36px;">
                <i class="fa-regular fa-clock text-purple" style="font-size: 0.8rem;"></i>
                <span class="extra-small text-muted fw-semibold"><?php echo $thai_short_date; ?></span>
                <span class="fw-bold text-purple font-monospace" style="font-size: 0.85rem;" id="heroLiveTime">--:--:-- น.</span>
            </div>

            <span class="badge bg-white text-dark border px-3 d-inline-flex align-items-center gap-1" style="height: 36px; border-radius: 8px; font-size: 0.78rem; border-color: #e2e8f0 !important;">
                <i class="fa-solid fa-calendar-check text-warning"></i> ปีงบ <?php echo $active_fy; ?>
            </span>

            <?php if ($is_executive): ?>
                <a href="index.php?view=executive" class="clean-pill-btn bg-white" style="height: 36px; border-color: #cbd5e1;">
                    <i class="fa-solid fa-chess-king text-purple"></i>
                    <span>Executive Overview</span>
                </a>
            <?php endif; ?>

            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle d-inline-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="height: 36px; padding: 0 14px !important; font-size: 0.8rem;">
                    <i class="fa-solid fa-plus"></i> สร้างรายการใหม่
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" style="border-radius: 12px; min-width: 210px; border: 1px solid #e2e8f0 !important;">
                    <?php if ($modules['module_worklog']): ?>
                        <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/worklog/create.php"><i class="fa-solid fa-pen-to-square text-purple me-2"></i>บันทึกภาระงานประจำวัน</a></li>
                    <?php endif; ?>
                    <?php if ($modules['module_training']): ?>
                        <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/trainings/create.php"><i class="fa-solid fa-plane-departure text-primary me-2"></i>ขอไปราชการ / อบรม</a></li>
                    <?php endif; ?>
                    <?php if ($modules['module_leave']): ?>
                        <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/leave/create.php"><i class="fa-solid fa-envelope-open-text text-danger me-2"></i>ยื่นใบลาอิเล็กทรอนิกส์</a></li>
                    <?php endif; ?>
                    <?php if ($modules['module_room_booking']): ?>
                        <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/room_booking/my_bookings.php"><i class="fa-solid fa-door-open text-warning me-2"></i>จองห้องและสถานที่</a></li>
                    <?php endif; ?>
                    <?php if ($modules['module_supplies']): ?>
                        <li><a class="dropdown-item rounded-2 py-2 small fw-semibold" href="modules/supplies/index.php"><i class="fa-solid fa-box-open text-success me-2"></i>เบิกวัสดุสำนักงาน</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Quick Action Bar -->
    <div class="clean-card px-3 py-2 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="extra-small fw-bold text-uppercase text-muted me-1">
                <i class="fa-solid fa-bolt text-warning me-1"></i> ทางลัดบริการด่วน:
            </span>
            <?php if ($modules['module_worklog']): ?>
                <a href="modules/worklog/create.php" class="clean-pill-btn"><i class="fa-solid fa-pen-to-square"></i> บันทึกภาระงาน</a>
            <?php endif; ?>
            <?php if ($modules['module_training']): ?>
                <a href="modules/trainings/create.php" class="clean-pill-btn"><i class="fa-solid fa-plane-departure"></i> ขอไปราชการ/อบรม</a>
            <?php endif; ?>
            <?php if ($modules['module_leave']): ?>
                <a href="modules/leave/create.php" class="clean-pill-btn"><i class="fa-solid fa-envelope-open-text"></i> ยื่นใบลา</a>
            <?php endif; ?>
            <?php if ($modules['module_meeting']): ?>
                <a href="modules/meetings/index.php" class="clean-pill-btn"><i class="fa-solid fa-handshake"></i> วาระการประชุม</a>
            <?php endif; ?>
            <?php if ($modules['module_room_booking']): ?>
                <a href="modules/room_booking/my_bookings.php" class="clean-pill-btn"><i class="fa-solid fa-door-open"></i> จองห้อง</a>
            <?php endif; ?>
            <?php if ($modules['module_supplies']): ?>
                <a href="modules/supplies/index.php" class="clean-pill-btn"><i class="fa-solid fa-box-open"></i> เบิกพัสดุ</a>
            <?php endif; ?>
            <?php if ($modules['module_attendance']): ?>
                <a href="modules/attendance/my_attendance.php" class="clean-pill-btn"><i class="fa-solid fa-fingerprint"></i> บันทึกเวลาเข้างาน</a>
            <?php endif; ?>
        </div>
        <a href="profile.php" class="text-muted text-decoration-none extra-small fw-semibold">
            <i class="fa-solid fa-id-badge me-1 text-purple"></i><?php echo htmlspecialchars($user_info['position']); ?> →
        </a>
    </div>

    <!-- 2. Live Meeting Alert Banner (ถ้ามีประชุม Live) -->
    <?php if ($live_meeting): ?>
        <div class="clean-card p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3" style="background: #f0fdf4; border-color: #86efac !important;">
            <div class="d-flex align-items-center gap-3">
                <span class="pulse-dot"></span>
                <div>
                    <div class="fw-bold text-success fs-6 mb-0">
                        <i class="fa-solid fa-broadcast-tower me-2"></i> มีการประชุมที่กำลังดำเนินการในขณะนี้!
                    </div>
                    <div class="small text-dark fw-bold"><?php echo htmlspecialchars($live_meeting['title']); ?></div>
                    <div class="extra-small text-muted">
                        <i class="fa-regular fa-clock me-1"></i> เริ่มเวลา <?php echo date('H:i', strtotime($live_meeting['start_time'])); ?> น. | 
                        <i class="fa-solid fa-location-dot me-1"></i> <?php echo htmlspecialchars($live_meeting['location'] ?: 'ห้องประชุมออนไลน์'); ?>
                    </div>
                </div>
            </div>
            <div>
                <a href="modules/meetings/meeting_room.php?id=<?php echo $live_meeting['meeting_id']; ?>" class="btn btn-success px-4 py-2 fw-bold shadow-sm d-flex align-items-center gap-2">
                    <i class="fa-solid fa-door-open"></i> เข้าห้องประชุมทันที
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. Action Required Alert (ถ้ารอการอนุมัติ) -->
    <?php if ($total_pending_action > 0): ?>
        <div class="clean-card p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3" style="background: #fffbeb; border-color: #fde68a !important;">
            <div class="d-flex align-items-center gap-3">
                <div class="exec-kpi-icon" style="background: #fef3c7; color: #d97706; width: 40px; height: 40px;"><i class="fa-solid fa-bell"></i></div>
                <div>
                    <div class="fw-bold text-dark small mb-0">คุณมีรายการเอกสารที่รอการพิจารณาอนุมัติ</div>
                    <div class="extra-small text-muted">
                        <?php if ($leave_stats['pending_approval_count'] > 0): ?>
                            ใบลา: <span class="fw-bold text-danger"><?php echo $leave_stats['pending_approval_count']; ?></span> รายการ 
                        <?php endif; ?>
                        <?php if ($training_stats['pending_approval_count'] > 0): ?>
                            • ขอไปราชการ/อบรม: <span class="fw-bold text-danger"><?php echo $training_stats['pending_approval_count']; ?></span> รายการ
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php if ($leave_stats['pending_approval_count'] > 0): ?>
                    <a href="modules/leave/approve_list.php" class="btn btn-sm btn-white border px-3">พิจารณาใบลา</a>
                <?php endif; ?>
                <?php if ($training_stats['pending_approval_count'] > 0): ?>
                    <a href="modules/trainings/approve_list.php" class="btn btn-sm btn-white border px-3">พิจารณาไปราชการ</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4. 4 Core Personal Telemetry KPI Cards -->
    <div class="row g-3 mb-4">
        
        <!-- Card 1: วันลาสะสม -->
        <?php if ($modules['module_leave']): ?>
            <div class="col-xl-3 col-md-6">
                <a href="modules/leave/index.php" class="exec-kpi-card">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <span class="exec-kpi-label">วันลาสะสมของฉัน (ปีงบ <?php echo $active_fy; ?>)</span>
                            <span class="exec-kpi-icon" style="background-color: #fef3c7; color: #d97706;">
                                <i class="fa-solid fa-calendar-check"></i>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div>
                                <span class="exec-kpi-value"><?php echo $leave_stats['total_days']; ?></span>
                                <span class="exec-kpi-unit ms-1">วัน</span>
                            </div>
                            <span class="fw-bold" style="font-size: 0.75rem; color: #16a34a;">
                                <i class="fa-solid fa-check me-1"></i>อนุมัติแล้ว
                            </span>
                        </div>
                    </div>
                    <div class="exec-kpi-footer">
                        <span>ปีงบประมาณ <?php echo $active_fy; ?></span>
                        <span class="exec-kpi-link">ระบบการลา <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            </div>
        <?php endif; ?>

        <!-- Card 2: อบรม/ไปราชการ -->
        <?php if ($modules['module_training']): ?>
            <div class="col-xl-3 col-md-6">
                <a href="modules/trainings/index.php" class="exec-kpi-card">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <span class="exec-kpi-label">ชั่วโมงพัฒนาตนเอง / ไปราชการ</span>
                            <span class="exec-kpi-icon" style="background-color: #f3e8ff; color: #7e22ce;">
                                <i class="fa-solid fa-plane-departure"></i>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div>
                                <span class="exec-kpi-value"><?php echo number_format($training_stats['total_hours'], 1); ?></span>
                                <span class="exec-kpi-unit ms-1">ชั่วโมง</span>
                            </div>
                            <span class="fw-bold" style="font-size: 0.75rem; color: #7e22ce;">
                                <?php echo $training_stats['total_count']; ?> โครงการ
                            </span>
                        </div>
                    </div>
                    <div class="exec-kpi-footer">
                        <span>สะสมในปีงบ <?php echo $active_fy; ?></span>
                        <span class="exec-kpi-link">ดูประวัติ <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            </div>
        <?php endif; ?>

        <!-- Card 3: เวลาทำงานเดือนนี้ & Mood -->
        <?php if ($modules['module_worklog']): ?>
            <div class="col-xl-3 col-md-6">
                <a href="modules/worklog/index.php" class="exec-kpi-card">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <span class="exec-kpi-label">เวลาปฏิบัติงานสะสม (เดือน<?php echo $thai_full_months[intval(date('n'))] ?? ''; ?>)</span>
                            <span class="exec-kpi-icon" style="background-color: #dcfce7; color: #16a34a;">
                                <i class="fa-solid <?php echo $mood_data['icon']; ?>"></i>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div>
                                <span class="exec-kpi-value"><?php echo number_format($worklog_stats['total_mins'] / 60, 1); ?></span>
                                <span class="exec-kpi-unit ms-1">ชั่วโมง</span>
                            </div>
                            <span class="fw-bold <?php echo $mood_data['color']; ?>" style="font-size: 0.75rem;">
                                Mood: <?php echo $mood_data['label']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="exec-kpi-footer">
                        <span>บันทึกแล้ว <?php echo $worklog_stats['log_count']; ?> รายการ</span>
                        <span class="exec-kpi-link">บันทึกงาน <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            </div>
        <?php endif; ?>

        <!-- Card 4: การประชุมเร็วๆ นี้ -->
        <?php if ($modules['module_meeting']): ?>
            <div class="col-xl-3 col-md-6">
                <a href="modules/meetings/index.php" class="exec-kpi-card">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <span class="exec-kpi-label">ตารางนัดหมายการประชุม</span>
                            <span class="exec-kpi-icon" style="background-color: #dbeafe; color: #2563eb;">
                                <i class="fa-solid fa-handshake"></i>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <div>
                                <span class="exec-kpi-value"><?php echo count($upcoming_meetings); ?></span>
                                <span class="exec-kpi-unit ms-1">นัดหมาย</span>
                            </div>
                            <span class="fw-bold" style="font-size: 0.75rem; color: #2563eb;">
                                เร็วๆ นี้
                            </span>
                        </div>
                    </div>
                    <div class="exec-kpi-footer">
                        <span>วาระและรายงานการประชุม</span>
                        <span class="exec-kpi-link">เปิดดูวาระ <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            </div>
        <?php endif; ?>

    </div>

    <!-- 5. Main Content Grid (Two Columns) -->
    <div class="row g-4 mb-4">
        
        <!-- Left Main Deck (8 cols) -->
        <div class="col-lg-8">
            <div class="clean-card p-4 mb-4">
                
                <!-- Nav Tabs Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 border-bottom pb-3">
                    <ul class="nav nav-pills-modern gap-1" id="dashTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-today-btn" data-bs-toggle="pill" data-bs-target="#tab-today" type="button" role="tab">
                                <i class="fa-solid fa-list-check me-1"></i> งานประจำวัน & To-Do
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-dev-btn" data-bs-toggle="pill" data-bs-target="#tab-dev" type="button" role="tab">
                                <i class="fa-solid fa-plane-departure me-1"></i> ไปราชการ & อบรม
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-worklog-btn" data-bs-toggle="pill" data-bs-target="#tab-worklog" type="button" role="tab">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติภาระงาน
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-cal-btn" data-bs-toggle="pill" data-bs-target="#tab-cal" type="button" role="tab">
                                <i class="fa-solid fa-calendar me-1"></i> ปฏิทินของฉัน
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Tab Content -->
                <div class="tab-content" id="dashTabsContent">
                    
                    <!-- TAB 1: งานประจำวัน & To-Do -->
                    <div class="tab-pane fade show active" id="tab-today" role="tabpanel">
                        
                        <!-- A. แผนต่อเนื่องจากเมื่อวาน -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-forward-step text-purple me-2"></i> แผนงานต่อเนื่องจากเมื่อวาน (Next Movement)
                                </h6>
                                <a href="modules/worklog/create.php" class="text-purple text-decoration-none extra-small fw-bold">+ บันทึกงาน</a>
                            </div>

                            <?php if (empty($action_plans)): ?>
                                <div class="p-3 bg-light rounded-4 text-center text-muted small">
                                    <i class="fa-solid fa-circle-check text-success me-1"></i> ยอดเยี่ยม! ไม่มีแผนงานที่ค้างจากเมื่อวาน
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($action_plans as $p): ?>
                                        <div class="p-3 rounded-4 bg-light border-start border-4 border-purple shadow-sm d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold text-dark small mb-1"><?php echo htmlspecialchars($p['next_movement']); ?></div>
                                                <div class="extra-small text-muted">
                                                    <i class="fa-solid fa-tag me-1"></i> <?php echo htmlspecialchars($p['task_name']); ?>
                                                </div>
                                            </div>
                                            <a href="modules/worklog/show.php?id=<?php echo $p['log_id']; ?>" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                                                ดูงาน
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- B. งานด่วนที่ยังไม่เสร็จ -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-dark mb-2">
                                <i class="fa-solid fa-fire text-danger me-2"></i> งานเร่งด่วนที่ยังดำเนินการไม่เสร็จ
                            </h6>
                            <?php if (empty($urgent_tasks)): ?>
                                <div class="p-3 bg-light rounded-4 text-center text-muted small">
                                    <i class="fa-regular fa-face-smile text-success me-1"></i> ไม่มีงานด่วนค้างคาในขณะนี้
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($urgent_tasks as $urg): ?>
                                        <div class="p-3 rounded-4 bg-light d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center gap-2">
                                                <?php 
                                                    $bg_badge = ($urg['urgency_level'] === 'ด่วนที่สุด') ? 'bg-danger' : (($urg['urgency_level'] === 'ด่วนมาก') ? 'bg-warning text-dark' : 'bg-primary');
                                                ?>
                                                <span class="badge <?php echo $bg_badge; ?> rounded-pill px-2 py-1 extra-small"><?php echo htmlspecialchars($urg['urgency_level']); ?></span>
                                                <span class="fw-bold small text-dark"><?php echo htmlspecialchars($urg['task_name']); ?></span>
                                            </div>
                                            <a href="modules/worklog/show.php?id=<?php echo $urg['log_id']; ?>" class="btn btn-sm btn-light border rounded-pill px-3 extra-small">
                                                ดูงาน
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- C. โครงการที่รับผิดชอบ -->
                        <?php if ($modules['module_projects']): ?>
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold text-dark mb-0">
                                        <i class="fa-solid fa-diagram-project text-purple me-2"></i> โครงการที่รับผิดชอบ
                                    </h6>
                                    <a href="modules/projects/index.php" class="text-purple text-decoration-none extra-small fw-bold">ดูทั้งหมด <i class="fa-solid fa-arrow-right"></i></a>
                                </div>
                                <?php if (empty($my_projects)): ?>
                                    <div class="p-3 bg-light rounded-4 text-center text-muted small">
                                        ไม่มีโครงการค้างดำเนินการ
                                    </div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <?php foreach ($my_projects as $prj): ?>
                                            <div class="col-md-6">
                                                <div class="p-3 rounded-4 border <?php echo $prj['is_delayed'] ? 'border-danger bg-danger-subtle bg-opacity-10' : 'bg-light'; ?> h-100">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div class="fw-bold text-dark small text-truncate pe-2" style="max-width: 75%;" title="<?php echo htmlspecialchars($prj['project_name']); ?>">
                                                            <?php echo htmlspecialchars($prj['project_name']); ?>
                                                        </div>
                                                        <span class="badge <?php echo $prj['progress'] == 100 ? 'bg-success' : ($prj['is_delayed'] ? 'bg-danger' : 'bg-purple'); ?>">
                                                            <?php echo $prj['progress']; ?>%
                                                        </span>
                                                    </div>
                                                    <div class="progress mb-2" style="height: 6px;">
                                                        <div class="progress-bar bg-purple" role="progressbar" style="width: <?php echo $prj['progress']; ?>%"></div>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center extra-small text-muted">
                                                        <span class="<?php echo $prj['is_delayed'] ? 'text-danger fw-bold' : ''; ?>">
                                                            <i class="fa-regular fa-calendar-check me-1"></i> <?php echo date('d/m/Y', strtotime($prj['deadline'])); ?>
                                                        </span>
                                                        <a href="modules/projects/view_project.php?id=<?php echo $prj['project_id']; ?>" class="text-purple text-decoration-none fw-bold">อัปเดต</a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- TAB 2: ไปราชการ & อบรม -->
                    <div class="tab-pane fade" id="tab-dev" role="tabpanel">
                        <!-- รายการอบรมล่าสุด -->
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-plane-departure text-primary me-2"></i> ประวัติการขอไปราชการ / รายงานการอบรมพัฒนาตนเอง
                                </h6>
                                <a href="modules/trainings/create.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                    <i class="fa-solid fa-plus me-1"></i> ขอไปราชการ/อบรม
                                </a>
                            </div>

                            <?php if (empty($recent_trainings)): ?>
                                <div class="p-4 bg-light rounded-4 text-center text-muted">
                                    <i class="fa-regular fa-clipboard fs-2 mb-2 opacity-50"></i>
                                    <p class="mb-0 small">ยังไม่มีประวัติการส่งรายงานอบรม</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>โครงการ / กิจกรรม</th>
                                                <th>สถานที่</th>
                                                <th>วันที่จัด</th>
                                                <th class="text-center">ชั่วโมง</th>
                                                <th class="text-center">สถานะ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_trainings as $tr): ?>
                                                <tr>
                                                    <td>
                                                        <a href="modules/trainings/view.php?id=<?php echo $tr['report_id']; ?>" class="fw-bold text-dark text-decoration-none hover-purple small">
                                                            <?php echo htmlspecialchars($tr['project_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td class="small text-muted"><?php echo htmlspecialchars($tr['location'] ?: '-'); ?></td>
                                                    <td class="extra-small text-muted"><?php echo date('d/m/Y', strtotime($tr['start_date'])); ?></td>
                                                    <td class="text-center fw-bold small"><?php echo number_format($tr['training_hours'] ?? 0, 1); ?></td>
                                                    <td class="text-center">
                                                        <?php 
                                                            if ($tr['status'] === 'approved' || $tr['status'] === 'reported') {
                                                                echo '<span class="badge bg-success-subtle text-success px-2 py-1">อนุมัติ</span>';
                                                            } elseif ($tr['status'] === 'pending') {
                                                                echo '<span class="badge bg-warning-subtle text-warning-emphasis px-2 py-1">รออนุมัติ</span>';
                                                            } else {
                                                                echo '<span class="badge bg-light text-dark px-2 py-1">' . htmlspecialchars($tr['status']) . '</span>';
                                                            }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TAB 3: ประวัติบันทึกงาน -->
                    <div class="tab-pane fade" id="tab-worklog" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-clipboard-list text-purple me-2"></i> บันทึกงานประจำวันล่าสุด (Work Logs)
                            </h6>
                            <a href="modules/worklog/create.php" class="btn btn-sm btn-purple rounded-pill px-3 text-white">
                                <i class="fa-solid fa-plus me-1"></i> บันทึกเพิ่ม
                            </a>
                        </div>

                        <?php if (empty($recent_worklogs)): ?>
                            <div class="p-4 bg-light rounded-4 text-center text-muted">
                                <i class="fa-regular fa-calendar-xmark fs-2 mb-2 opacity-50"></i>
                                <p class="mb-0 small">ยังไม่มีบันทึกงานในเดือนนี้</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>วันที่</th>
                                            <th>หัวข้องาน</th>
                                            <th class="text-center">เวลา (นาที)</th>
                                            <th class="text-center">Mood</th>
                                            <th class="text-center">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_worklogs as $log): ?>
                                            <tr>
                                                <td class="small fw-bold text-muted"><?php echo date('d M Y', strtotime($log['log_date'])); ?></td>
                                                <td>
                                                    <a href="modules/worklog/show.php?id=<?php echo $log['log_id']; ?>" class="fw-bold text-dark text-decoration-none small hover-purple">
                                                        <?php echo htmlspecialchars($log['task_name']); ?>
                                                    </a>
                                                    <?php if ($log['has_issue'] == 1): ?>
                                                        <span class="badge bg-danger-subtle text-danger extra-small ms-1"><i class="fa-solid fa-triangle-exclamation"></i> มีปัญหา</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center small fw-bold"><?php echo number_format($log['hours_spent']); ?></td>
                                                <td class="text-center">
                                                    <?php 
                                                        $lm = getDashboardMoodBadge($log['harmony_score']);
                                                        echo "<i class='fa-solid {$lm['icon']} {$lm['color']} fs-6' title='Score: {$log['harmony_score']}'></i>";
                                                    ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($log['submission_status'] === 'ล่าช้า'): ?>
                                                        <span class="badge bg-danger-subtle text-danger extra-small px-2 py-1">ล่าช้า</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success extra-small px-2 py-1">ตรงเวลา</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 4: ปฏิทินของฉัน -->
                    <div class="tab-pane fade" id="tab-cal" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="fa-solid fa-calendar-days text-purple me-2"></i> ปฏิทินกำหนดการของฉัน
                            </h6>
                            <div class="d-flex gap-2 extra-small text-muted align-items-center">
                                <span class="badge bg-success">วันลา</span>
                                <span class="badge bg-primary">ไปราชการ/อบรม</span>
                                <span class="badge" style="background-color: #6f42c1;">การประชุม</span>
                            </div>
                        </div>

                        <?php if (!empty($upcoming_meetings)): ?>
                            <div class="alert alert-purple bg-purple-subtle border border-purple border-opacity-25 rounded-3 py-2 px-3 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="small text-purple">
                                    <i class="fa-solid fa-calendar-check me-1"></i> นัดหมายการประชุม: <strong><?php echo htmlspecialchars($upcoming_meetings[0]['title']); ?></strong> 
                                    (วันที่ <?php echo date('d/m/Y', strtotime($upcoming_meetings[0]['meeting_date'])); ?> เวลา <?php echo date('H:i', strtotime($upcoming_meetings[0]['start_time'])); ?> น.)
                                </div>
                                <button type="button" class="btn btn-sm btn-purple rounded-pill px-3 py-1 extra-small fw-bold shadow-xs" onclick="jumpToMeetingDate('<?php echo $upcoming_meetings[0]['meeting_date']; ?>')">
                                    <i class="fa-solid fa-arrow-pointer me-1"></i> เลื่อนปฏิทินไปดูวันที่นี้
                                </button>
                            </div>
                        <?php endif; ?>

                        <div id="personalCalendar" style="min-height: 480px;"></div>
                    </div>

                </div>

            </div>
        </div>

        <!-- Right Sidebar Deck (4 cols) -->
        <div class="col-lg-4">
            
            <!-- Widget 0: Today's Attendance Pulse Card -->
            <div class="clean-card p-4 mb-4 border-start border-4 <?php echo $my_attendance_today['has_in'] ? ($my_attendance_today['is_late'] ? 'border-warning' : 'border-success') : 'border-danger'; ?>">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-fingerprint text-purple me-2"></i> เวลาเข้างานของวันนี้
                    </h6>
                    <a href="modules/attendance/my_attendance.php" class="text-purple text-decoration-none extra-small fw-bold">ประวัติเวลา <i class="fa-solid fa-arrow-right"></i></a>
                </div>

                <div class="row g-2 text-center mb-3">
                    <div class="col-6">
                        <div class="p-2 bg-light rounded-3 h-100 d-flex flex-column justify-content-center">
                            <span class="text-muted extra-small d-block mb-1">
                                <i class="fa-solid fa-arrow-right-to-bracket text-success me-1"></i>เข้างาน (In)
                            </span>
                            <?php if ($my_attendance_today['has_in']): ?>
                                <div class="fw-bold fs-5 text-success font-monospace mb-1">
                                    <?php echo date('H:i', strtotime($my_attendance_today['in_time'])); ?> <span class="fs-6 fw-normal text-muted">น.</span>
                                </div>
                                <div>
                                    <span class="badge <?php echo $my_attendance_today['status_badge']; ?> extra-small rounded-pill">
                                        <?php echo $my_attendance_today['is_late'] ? 'เข้าสาย (> 09:00)' : 'ตรงเวลา'; ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="fw-bold fs-6 text-danger mb-1">ยังไม่ลงเวลา</div>
                                <div>
                                    <span class="badge bg-danger-subtle text-danger extra-small rounded-pill">รอลงเวลา</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 bg-light rounded-3 h-100 d-flex flex-column justify-content-center">
                            <span class="text-muted extra-small d-block mb-1">
                                <i class="fa-solid fa-arrow-right-from-bracket text-primary me-1"></i>ออกงาน (Out)
                            </span>
                            <?php if ($my_attendance_today['has_out']): ?>
                                <div class="fw-bold fs-5 text-primary font-monospace mb-1">
                                    <?php echo date('H:i', strtotime($my_attendance_today['out_time'])); ?> <span class="fs-6 fw-normal text-muted">น.</span>
                                </div>
                                <div>
                                    <span class="badge bg-primary-subtle text-primary extra-small rounded-pill">บันทึกแล้ว</span>
                                </div>
                            <?php else: ?>
                                <div class="fw-bold fs-6 text-muted mb-1">--:--</div>
                                <div>
                                    <span class="badge bg-secondary-subtle text-secondary extra-small rounded-pill">รอลงเวลาออก</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="pt-2 border-top text-center">
                    <span class="text-muted extra-small">
                        <i class="fa-solid fa-desktop text-purple me-1"></i>ลงเวลาเข้างานและออกงานที่จุดสแกนเครื่องกลางเท่านั้น
                    </span>
                </div>
            </div>
            
            <!-- Widget 1: Upcoming Meetings -->
            <?php if ($modules['module_meeting']): ?>
                <div class="clean-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-handshake text-purple me-2"></i> การประชุมเร็วๆ นี้
                        </h6>
                        <a href="modules/meetings/index.php" class="text-purple text-decoration-none extra-small fw-bold">ทั้งหมด <i class="fa-solid fa-arrow-right"></i></a>
                    </div>

                    <?php if (empty($upcoming_meetings)): ?>
                        <div class="text-center py-4 bg-light rounded-4 text-muted small">
                            <i class="fa-solid fa-mug-hot fs-2 mb-2 opacity-50"></i>
                            <p class="mb-0">ไม่มีกำหนดการประชุมเร็วๆ นี้</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($upcoming_meetings as $m): 
                                $is_live = ($m['status'] === 'in_progress');
                            ?>
                                <div class="p-3 rounded-4 border <?php echo $is_live ? 'border-success bg-success-subtle bg-opacity-10' : 'bg-light'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div class="fw-bold text-dark small text-truncate pe-2" style="max-width: 75%;" title="<?php echo htmlspecialchars($m['title']); ?>">
                                            <?php echo htmlspecialchars($m['title']); ?>
                                        </div>
                                        <?php if ($is_live): ?>
                                            <span class="badge bg-success fa-fade"><i class="fa-solid fa-circle text-white extra-small me-1"></i> Live</span>
                                        <?php elseif ($m['attendance_status'] === 'attending'): ?>
                                            <span class="badge bg-primary-subtle text-primary extra-small">ตอบรับแล้ว</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary extra-small">รอยืนยัน</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="extra-small text-muted mb-2">
                                        <i class="fa-regular fa-clock text-warning me-1"></i> <?php echo date('d/m/Y', strtotime($m['meeting_date'])) . ' ' . date('H:i', strtotime($m['start_time'])) . ' น.'; ?>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center extra-small border-top pt-2">
                                        <span class="text-muted text-truncate" style="max-width: 150px;">
                                            <i class="fa-solid fa-location-dot me-1"></i> <?php echo htmlspecialchars($m['location'] ?: 'ออนไลน์'); ?>
                                        </span>
                                        <?php if ($is_live): ?>
                                            <a href="modules/meetings/meeting_room.php?id=<?php echo $m['meeting_id']; ?>" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold extra-small">
                                                เข้าห้องประชุม
                                            </a>
                                        <?php else: ?>
                                            <a href="modules/meetings/view.php?id=<?php echo $m['meeting_id']; ?>" class="text-purple text-decoration-none fw-bold">
                                                รายละเอียด <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Widget 2: Quick Services Menu -->
            <div class="clean-card p-4 mb-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-grid-2 text-gold me-2"></i> บริการยอดนิยม
                </h6>
                <div class="row g-2">
                    <?php if ($modules['module_supplies']): ?>
                        <div class="col-6">
                            <a href="modules/supplies/index.php" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                                <div class="metric-icon bg-purple-subtle text-purple mb-2" style="width: 40px; height: 40px; font-size: 1.1rem;"><i class="fa-solid fa-box-open"></i></div>
                                <span class="extra-small fw-bold">เบิกพัสดุ</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($modules['module_room_booking']): ?>
                        <div class="col-6">
                            <a href="modules/room_booking/index.php" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                                <div class="metric-icon text-primary mb-2" style="width: 40px; height: 40px; font-size: 1.1rem; background-color: #e3f2fd;"><i class="fa-solid fa-door-open"></i></div>
                                <span class="extra-small fw-bold">จองห้อง/สถานที่</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="col-6">
                        <a href="modules/zoom_booking/" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                            <div class="metric-icon text-info mb-2" style="width: 40px; height: 40px; font-size: 1.1rem; background-color: #e0f7fa;"><i class="fa-solid fa-video"></i></div>
                            <span class="extra-small fw-bold">จอง Zoom</span>
                        </a>
                    </div>

                    <?php if ($modules['module_repair_request']): ?>
                        <div class="col-6">
                            <a href="modules/repair_request/my_repairs.php" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                                <div class="metric-icon text-warning mb-2" style="width: 40px; height: 40px; font-size: 1.1rem; background-color: #fff8e1;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                                <span class="extra-small fw-bold">แจ้งซ่อมบำรุง</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="col-6">
                        <a href="modules/qrcode/" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                            <div class="metric-icon text-secondary mb-2" style="width: 40px; height: 40px; font-size: 1.1rem; background-color: #f5f5f5;"><i class="fa-solid fa-qrcode"></i></div>
                            <span class="extra-small fw-bold">สร้าง QR Code</span>
                        </a>
                    </div>

                    <div class="col-6">
                        <a href="modules/km/index.php" class="p-3 bg-light rounded-4 text-decoration-none text-dark d-flex flex-column align-items-center text-center h-100 hover-zoom">
                            <div class="metric-icon text-success mb-2" style="width: 40px; height: 40px; font-size: 1.1rem; background-color: #e8f5e9;"><i class="fa-solid fa-brain"></i></div>
                            <span class="extra-small fw-bold">คลังความรู้ KM</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Widget 3: Work Motivation & Tips -->
            <div class="clean-card p-4 text-center position-relative overflow-hidden" style="background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);">
                <i class="fa-solid fa-quote-left fs-3 text-purple opacity-25 mb-2"></i>
                <p class="small text-muted fst-italic mb-2">
                    "การพัฒนาตนเองอย่างต่อเนื่อง คือกุญแจสำคัญสู่ความสำเร็จและความสุขในการทำงานร่วมกัน"
                </p>
                <span class="extra-small fw-bold text-purple">— วิทยาลัยดุริยางคศิลป์ มมส</span>
            </div>

        </div>

    </div>

</main>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Initialize Tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Initialize FullCalendar when Calendar Tab is shown
        var calendarEl = document.getElementById('personalCalendar');
        window.calendarInstance = null;

        window.jumpToMeetingDate = function(dateStr) {
            if (window.calendarInstance) {
                window.calendarInstance.gotoDate(dateStr);
            }
        };

        var calTabBtn = document.getElementById('tab-cal-btn');
        if (calTabBtn) {
            calTabBtn.addEventListener('shown.bs.tab', function() {
                if (!window.calendarInstance && calendarEl) {
                    window.calendarInstance = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        locale: 'th',
                        eventDisplay: 'block',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,listMonth'
                        },
                        events: <?php echo json_encode($calendar_events, JSON_UNESCAPED_UNICODE); ?>,
                        eventClick: function(info) {
                            if (info.event.url) {
                                window.location.href = info.event.url;
                                info.jsEvent.preventDefault();
                            }
                        }
                    });
                    window.calendarInstance.render();
                } else if (window.calendarInstance) {
                    window.calendarInstance.updateSize();
                }
            });
        }

        // Live Clock in Hero Banner
        function updateHeroLiveClock() {
            var timeEl = document.getElementById('heroLiveTime');
            if (!timeEl) return;
            var now = new Date();
            var h = String(now.getHours()).padStart(2, '0');
            var m = String(now.getMinutes()).padStart(2, '0');
            var s = String(now.getSeconds()).padStart(2, '0');
            timeEl.innerHTML = h + '<span class="colon-pulse">:</span>' + m + '<span class="colon-pulse">:</span>' + s + ' น.';
        }
        updateHeroLiveClock();
        setInterval(updateHeroLiveClock, 1000);
    });
</script>

<?php require_once 'includes/footer.php'; ?>