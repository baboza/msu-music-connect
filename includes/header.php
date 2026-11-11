<?php
// กำหนด Base Path ของโปรเจค
$base_url = "/music-e-office"; 

// 💡 คำนวณจำนวนรายการรออนุมัติ (Badge Count)
$pending_approvals = 0;
$current_avatar = ''; 
$current_qr_token = '';
try {
    if(isset($_SESSION['user_id']) && isset($pdo)) {
        $user_id = $_SESSION['user_id'];
        
        // 1. ดึงรูปโปรไฟล์ของผู้ใช้ปัจจุบัน (ดึงครั้งเดียวแล้ว sidebar นำไปใช้ต่อ)
        $stmt_avatar = $pdo->prepare("SELECT profile_picture, qr_token FROM users WHERE user_id = ?");
        $stmt_avatar->execute([$user_id]);
        $user_data = $stmt_avatar->fetch();
        $current_avatar = $user_data['profile_picture'] ?? '';
        $current_qr_token = $user_data['qr_token'] ?? '';

        // 2. ตรวจสอบสิทธิ์การเข้าถึงเพื่อคำนวณแจ้งเตือน
        $user_roles_array = $_SESSION['roles'] ?? [];
        $is_admin = in_array('admin', $user_roles_array) ? 1 : 0;
        
        // --- Cache Admin IDs เพื่อลดภาระ Sub-query ---
        $admin_ids_str = "''";
        if (isset($_SESSION['cached_admin_ids'])) {
            $admin_ids_str = $_SESSION['cached_admin_ids'];
        } else {
            $stmt_admins = $pdo->query("SELECT user_id FROM user_roles WHERE role_id = 1");
            $admin_ids = $stmt_admins->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($admin_ids)) {
                $admin_ids_str = "'" . implode("','", $admin_ids) . "'";
            }
            $_SESSION['cached_admin_ids'] = $admin_ids_str;
        }
        
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
        $is_building_staff = (in_array('งานอาคารสถานที่', $user_roles_array) || in_array('โสตทัศนูปกรณ์', $user_roles_array) || in_array('งานโสตทัศนศึกษา', $user_roles_array)) ? 1 : 0;

        // --- Flags สำหรับระบบไปราชการ/อบรม (5 ขั้นตอนจริง) และ ร้องเรียน ---
        $is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0) ? 1 : 0;
        $is_training_office_head = (count(array_intersect(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'], $user_roles_array)) > 0) ? 1 : 0;
        $is_training_head_12 = in_array('หัวหน้าภาควิชาดนตรีศึกษา', $user_roles_array) ? 1 : 0;
        $is_training_head_13 = in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $user_roles_array) ? 1 : 0;
        $is_training_finance = in_array('งานการเงิน', $user_roles_array) ? 1 : 0;
        $is_training_deputy_admin = in_array('รองคณบดีฝ่ายบริหาร', $user_roles_array) ? 1 : 0;
        $is_training_dean = $is_dean;
        $training_head_sql = !empty($my_head_dept_ids) ? "OR (tr.current_step IN (1, 3) AND u.dept_id IN (" . implode(',', $my_head_dept_ids) . "))" : "";

        // --- ตรวจสอบว่าเป็นผู้มีสิทธิ์พิจารณาอนุมัติหรือไม่ ---
        $is_header_approver = ($is_admin || $is_course_head || $is_course_deputy || $is_course_academic || !empty($my_head_dept_ids) || $is_leave_step2_staff || $is_leave_step3 || $is_leave_step4 || $is_supply_head || $is_supply_issuer || $is_building_staff || $is_training_office_head || $is_training_finance || $is_training_deputy_admin || $is_training_dean || in_array('อาจารย์ผู้สอน', $user_roles_array) || in_array('อาจารย์ที่ปรึกษา', $user_roles_array) || in_array('บุคลากรสายวิชาการ', $user_roles_array) || in_array('งานพัฒนานิสิต', $user_roles_array) || in_array('รองคณบดีฝ่ายพัฒนานิสิต', $user_roles_array));

        // 3. Query นับจำนวน Badge จากทุกระบบรวมกัน
        $badge_query = "
            SELECT SUM(cnt) FROM (
                -- 1. คำขอใบลา
                SELECT COUNT(*) as cnt FROM leave_requests l JOIN users u ON l.user_id = u.user_id WHERE l.status='pending' AND (
                    $is_admin = 1 OR 
                    (l.current_step = 1 AND $is_leave_step1 = 1) OR 
                    (l.current_step = 2 AND $is_leave_step2_staff = 1 AND (u.dept_id = 11 OR l.user_id NOT IN ($admin_ids_str))) 
                    $head_depts_sql OR 
                    (l.current_step = 3 AND $is_leave_step3 = 1) OR 
                    (l.current_step = 4 AND $is_leave_step4 = 1 AND (l.final_signer_id IS NULL OR l.final_signer_id = '')) OR 
                    (l.current_step = 4 AND l.final_signer_id = '$user_id')
                )
                
                UNION ALL
                
                -- 2. จองห้องสถานที่
                SELECT COUNT(*) as cnt FROM room_bookings WHERE status='pending' AND ($is_admin = 1 OR $is_building_staff = 1)
                
                UNION ALL
                
                -- 3. แจ้งซ่อม
                SELECT COUNT(*) as cnt FROM repair_requests WHERE status='pending' AND ($is_admin = 1 OR $is_building_staff = 1)
                
                UNION ALL
                
                -- 4. เบิกพัสดุ
                SELECT COUNT(*) as cnt FROM supplies_requests sr WHERE 
                    ($is_admin = 1 AND (sr.head_status='pending' OR (sr.head_status='approved' AND sr.supply_status='pending'))) 
                    OR (sr.head_status='pending' AND sr.head_approver_id='$user_id') 
                    OR ($is_supply_head = 1 AND sr.head_status='pending') 
                    OR ($is_supply_issuer = 1 AND sr.head_status='approved' AND sr.supply_status='pending')
                
                UNION ALL
                
                -- 5. คำร้องนิสิต
                SELECT COUNT(*) as cnt FROM course_requests r WHERE 
                    ($is_admin = 1 AND r.status IN ('pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic'))
                    OR (r.instructor_id = '$user_id' AND r.status = 'pending_instructor')
                    OR (r.advisor_id = '$user_id' AND r.status = 'pending_advisor')
                    OR (r.status = 'pending_head' AND $is_course_head = 1)
                    OR (r.status = 'pending_deputy' AND $is_course_deputy = 1)
                    OR (r.status = 'pending_academic' AND $is_course_academic = 1)
                    
                UNION ALL
                
                -- 6. อุทธรณ์คะแนน
                SELECT COUNT(*) as cnt FROM grade_appeals ga WHERE ga.status IN ('pending', 'in_progress') AND ($is_admin = 1 OR ga.instructor_id = '$user_id')
                
                UNION ALL
                
                -- 7. เรื่องร้องเรียน
                SELECT COUNT(*) as cnt FROM complaints c WHERE c.status IN ('pending', 'in_progress') AND ($is_admin = 1 OR $is_dean = 1)
                
                UNION ALL
                
                -- 8. ขอไปราชการ/อบรม (5 ขั้นตอนจริง)
                SELECT COUNT(*) as cnt FROM training_reports tr JOIN users u ON tr.user_id = u.user_id WHERE tr.status='pending' AND (
                    $is_admin = 1
                    OR (tr.current_step IN (1, 3) AND $is_training_office_head = 1 AND (u.dept_id IS NULL OR u.dept_id NOT IN (12, 13)))
                    $training_head_sql
                    OR (tr.current_step = 2 AND $is_training_finance = 1)
                    OR (tr.current_step = 4 AND $is_training_deputy_admin = 1)
                    OR (tr.current_step = 5 AND $is_training_dean = 1)
                )
            ) as t
        ";
        $pending_approvals = $pdo->query($badge_query)->fetchColumn() ?: 0;
    }
} catch (PDOException $e) {
    // ป้องกันการ Error หน้าขาว หาก Query มีปัญหา จะได้แสดงเลข 0 แทน
    $pending_approvals = 0; 
}

// ฟังก์ชันสำหรับเช็คสถานะเมนูตรงกลาง (Center Nav)
if (!function_exists('isCenterActive')) {
    function isCenterActive($path) {
        return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>Music MSU Connect - วิทยาลัยดุริยางคศิลป์</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@300;400;500;600;700&family=Anuphan:wght@300;400;500;600;700&family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/google-sans" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <style>
        :root {
            --primary-purple: #39048f;
            --primary-purple-hover: #4a06b8;
            --light-purple: #5b13df;
            --purple-subtle: #f4efff;
            --premium-gold: #b48a1e;
            --light-gold: #fef9c3;
            --white-surface: #ffffff;
            --bg-light: #f8fafc;
            --slate-950: #090d16;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-subtle: #e2e8f0;
            --border-strong: #cbd5e1;
            --shadow-soft: 0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.02);
            --shadow-hover: 0 10px 25px -5px rgba(15, 23, 42, 0.07), 0 8px 10px -6px rgba(15, 23, 42, 0.03);
        }

        * {
            font-family: 'Google Sans', sans-serif;
        }

        body, button, input, select, textarea, h1, h2, h3, h4, h5, h6, .navbar-brand, .nav-link, .btn, .card-header, table { 
            font-family: 'Google Sans', sans-serif !important; 
        }

        /* รักษา Font Awesome ไอคอน */
        .fa, .fas, .far, .fal, .fad, .fab, .fa-solid, .fa-regular, .fa-brands, [class^="fa-"], [class*=" fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands" !important;
        }

        body { 
            font-family: 'Google Sans', sans-serif !important; 
            background-color: var(--bg-light) !important; 
            color: var(--text-dark) !important;
            overflow-x: hidden;
            font-size: 0.9rem;
            letter-spacing: -0.005em;
            -webkit-font-smoothing: antialiased;
        }

        /* --- Enterprise Top Command Bar (ERP 2.0) --- */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-subtle);
            padding: 0 1.25rem;
            height: 56px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
            z-index: 1030;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: nowrap !important;
        }

        /* โซน 1: ซ้าย (แบรนด์องค์กร) */
        .header-left {
            display: flex; align-items: center; min-width: 240px;
        }
        .navbar-brand {
            font-family: 'Google Sans', sans-serif !important; font-weight: 700;
            letter-spacing: -0.01em; color: var(--slate-900) !important;
            display: flex; align-items: center; text-decoration: none;
            white-space: nowrap; gap: 10px;
        }
        .header-logo { max-height: 34px; width: auto; filter: drop-shadow(0 1px 2px rgba(15,23,42,0.12)); }
        .erp-brand-stack { display: flex; flex-direction: column; line-height: 1.15; }
        .erp-brand-title { font-size: 0.92rem; font-weight: 700; color: var(--slate-900); display: flex; align-items: center; gap: 6px; }
        .erp-version-tag {
            font-size: 0.62rem; font-weight: 700; padding: 1px 6px; border-radius: 4px;
            background-color: var(--purple-subtle); color: var(--primary-purple);
            border: 1px solid rgba(57, 4, 143, 0.15); letter-spacing: 0.04em;
        }
        .erp-brand-sub { font-size: 0.68rem; font-weight: 500; color: var(--slate-500); }

        /* โซน 2: กลาง (Global Command Palette Trigger - Ctrl+K) */
        .header-center {
            display: flex; justify-content: center; align-items: center;
            flex: 1; max-width: 540px;
        }
        .erp-cmd-trigger {
            width: 100%; height: 36px;
            background-color: var(--slate-100);
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            padding: 0 12px;
            display: flex; align-items: center; justify-content: space-between;
            color: var(--slate-500); font-size: 0.82rem; font-weight: 500;
            cursor: pointer; transition: all 0.15s ease;
        }
        .erp-cmd-trigger:hover {
            background-color: #ffffff;
            border-color: var(--border-strong);
            color: var(--slate-800);
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        }
        .erp-kbd-group { display: inline-flex; align-items: center; gap: 3px; }
        .erp-kbd {
            background: #ffffff; border: 1px solid var(--border-strong);
            border-radius: 4px; padding: 1px 6px; font-size: 0.68rem;
            font-weight: 700; color: var(--slate-600);
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.08);
        }

        /* โซน 3: ขวา (Enterprise Action Bar) */
        .header-right {
            display: flex; align-items: center; justify-content: flex-end; gap: 8px;
        }
        .erp-density-btn {
            height: 34px; padding: 0 10px; border-radius: 8px;
            background-color: #ffffff; border: 1px solid var(--border-subtle);
            color: var(--slate-600); font-size: 0.76rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px;
            cursor: pointer; transition: all 0.15s ease;
        }
        .erp-density-btn:hover {
            background-color: var(--slate-100); color: var(--slate-900); border-color: var(--border-strong);
        }
        .erp-queue-pill {
            height: 34px; padding: 0 12px; border-radius: 8px;
            background-color: #fffbeb; border: 1px solid #fde68a;
            color: #b45309 !important; font-size: 0.78rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; transition: all 0.15s ease;
        }
        .erp-queue-pill:hover {
            background-color: #fef3c7; border-color: #fcd34d; transform: translateY(-1px);
        }
        .erp-queue-pill.empty {
            background-color: #ffffff; border-color: var(--border-subtle); color: var(--slate-600) !important;
        }
        .erp-queue-count {
            background-color: #d97706; color: #ffffff;
            font-size: 0.68rem; font-weight: 700; padding: 1px 6px;
            border-radius: 99px; font-variant-numeric: tabular-nums;
        }
        .erp-queue-pill.empty .erp-queue-count {
            background-color: var(--slate-200); color: var(--slate-600);
        }

        .header-icon-btn {
            position: relative; display: flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 8px !important;
            background-color: #ffffff; color: var(--slate-600) !important;
            border: 1px solid var(--border-subtle);
            transition: all 0.15s ease; text-decoration: none; padding: 0;
        }
        .header-icon-btn:hover, .header-icon-btn:focus {
            background-color: var(--slate-100); color: var(--primary-purple) !important;
            border-color: var(--border-strong);
        }
        .header-icon-btn i { font-size: 0.95rem; }
        
        .header-badge {
            position: absolute; top: -4px; right: -4px;
            background-color: #e11d48; color: white;
            font-size: 0.62rem; font-weight: 700; padding: 1px 5px; 
            min-width: 17px; height: 17px; display: flex; align-items: center; justify-content: center;
            border: 2px solid #ffffff; border-radius: 99px !important;
            line-height: 1; font-variant-numeric: tabular-nums; pointer-events: none;
        }

        .header-profile-btn {
            position: relative; display: inline-flex; align-items: center; gap: 8px;
            height: 36px; padding: 3px 10px 3px 4px;
            border-radius: 8px; background-color: #ffffff;
            border: 1px solid var(--border-subtle);
            text-decoration: none; transition: all 0.15s ease;
        }
        .header-profile-btn:hover {
            background-color: var(--slate-100); border-color: var(--border-strong);
        }
        .header-avatar {
            width: 28px; height: 28px; border-radius: 6px !important; 
            object-fit: cover; background-color: var(--purple-subtle); color: var(--primary-purple);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; font-weight: 700; border: none !important;
        }
        .header-profile-meta {
            display: flex; flex-direction: column; line-height: 1.1; text-align: left;
        }
        .header-profile-name {
            font-size: 0.76rem; font-weight: 700; color: var(--slate-900);
            max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-profile-role {
            font-size: 0.64rem; font-weight: 500; color: var(--slate-500);
            max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .profile-dropdown-menu {
            border: 1px solid var(--border-subtle); border-radius: 12px;
            box-shadow: 0 12px 32px -4px rgba(15, 23, 42, 0.12);
            padding: 6px; margin-top: 8px !important; min-width: 250px; z-index: 1050;
        }
        .dropdown-item { border-radius: 8px; padding: 8px 12px; font-size: 0.84rem; font-weight: 500; color: var(--slate-800); transition: 0.15s; display: flex; align-items: center; }
        .dropdown-item:hover { background-color: var(--slate-100); color: var(--primary-purple); }
        .dropdown-item-icon { width: 28px; height: 28px; border-radius: 6px; background-color: var(--slate-100); display: flex; align-items: center; justify-content: center; margin-right: 10px; color: var(--slate-600); transition: 0.15s; font-size: 0.82rem; }
        .dropdown-item:hover .dropdown-item-icon { background-color: var(--purple-subtle); color: var(--primary-purple); }
        .dropdown-item.text-danger:hover .dropdown-item-icon { background-color: #fee2e2; color: #dc2626; }

        /* --- Sidebar Shell --- */
        .sidebar { background-color: var(--white-surface); border-right: 1px solid var(--border-subtle) !important; }
        .sidebar-sticky { height: calc(100vh - 56px); overflow-x: hidden; overflow-y: auto; padding-top: 0.75rem; }

        /* ---------------------------------------------------------
           Mobile & Tablet Responsive (Enterprise ERP 2.0)
           --------------------------------------------------------- */
        @media (max-width: 1199.98px) {
            .header-center { max-width: 240px; }
            .header-profile-meta { display: none !important; }
            .header-profile-btn { padding: 3px; width: 34px; justify-content: center; }
            .header-left { min-width: auto; }
            .erp-brand-sub { display: none !important; }

            .navbar-toggler { 
                width: 34px !important; height: 34px !important;
                border-radius: 8px !important; border: 1px solid var(--border-subtle) !important;
                background-color: #ffffff !important;
                display: flex !important; align-items: center !important; justify-content: center !important;
                padding: 0 !important; color: var(--slate-700) !important; box-shadow: none !important;
            }
            .navbar-toggler-icon { display: none !important; }
            
            .sidebar {
                position: fixed !important; top: 0 !important; left: 0 !important;
                z-index: 1050 !important; width: 270px !important; height: 100vh !important;
                transform: translateX(-100%); transition: transform 0.25s ease-in-out;
                background-color: #fff !important; display: block !important; box-shadow: 10px 0 30px rgba(15,23,42,0.12);
            }
            .sidebar.show { transform: translateX(0); }
            main, .col-md-9.ms-sm-auto, .col-lg-10 {
                width: 100% !important; margin-left: 0 !important;
                padding-left: 15px !important; padding-right: 15px !important;
                min-width: 0 !important;
                max-width: 100% !important;
            }
            .container-fluid {
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            .container-fluid > .row {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
        }
        
        @media (max-width: 991.98px) {
            .navbar-custom {
                padding: 0 0.5rem !important;
                flex-wrap: nowrap !important;
                height: 56px !important;
                gap: 0.35rem !important;
                overflow: visible !important;
            }
            .header-center { display: none !important; }
            .erp-density-btn { display: none !important; }
            .erp-brand-sub { display: none !important; }
            .erp-version-tag { display: none !important; }
            
            .header-left {
                min-width: 0 !important;
                flex: 0 1 auto !important;
                max-width: calc(100% - 150px) !important;
                overflow: hidden !important;
            }
            .navbar-brand {
                gap: 6px !important;
                overflow: hidden !important;
                max-width: 100% !important;
            }
            .header-logo { max-height: 26px !important; flex-shrink: 0 !important; }
            .erp-brand-title {
                font-size: 0.82rem !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            .erp-brand-title span:first-child {
                max-width: 110px !important;
                display: inline-block;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .header-right {
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                gap: 4px !important;
            }
            .erp-queue-pill {
                padding: 0 6px !important;
                height: 30px !important;
                font-size: 0.72rem !important;
                gap: 4px !important;
            }
            .erp-queue-pill span:not(.erp-queue-count) { display: none !important; }
            .header-icon-btn, .header-profile-btn, .navbar-toggler {
                width: 30px !important;
                height: 30px !important;
                flex-shrink: 0 !important;
            }
            .header-avatar {
                width: 24px !important;
                height: 24px !important;
            }
            main {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
            }
        }

        @media (max-width: 480px) {
            .navbar-custom {
                padding: 0 0.4rem !important;
                gap: 0.35rem !important;
            }
            .header-right {
                gap: 3px !important;
            }
            .erp-brand-title span:first-child {
                max-width: 110px !important;
                display: inline-block;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .erp-queue-pill {
                padding: 0 5px !important;
                gap: 4px !important;
            }
        }

        /* --- Enterprise ERP Cards, Tables, Buttons & Inputs --- */
        .card {
            border: 1px solid var(--border-subtle) !important;
            border-radius: 12px !important;
            box-shadow: var(--shadow-soft) !important;
            background-color: var(--white-surface) !important;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            overflow: hidden;
        }
        .card:hover {
            border-color: var(--border-strong) !important;
            box-shadow: var(--shadow-hover) !important;
        }
        .card-header {
            background-color: #ffffff !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--slate-900);
            padding: 0.9rem 1.25rem !important;
        }
        .table {
            font-variant-numeric: tabular-nums;
            margin-bottom: 0;
        }
        .table>thead>tr>th {
            border-bottom: 1px solid var(--border-subtle) !important;
            background-color: #f8fafc !important;
            color: var(--slate-600) !important;
            font-weight: 700 !important;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            padding: 11px 14px !important;
        }
        .table>tbody>tr>td {
            border-bottom: 1px solid #f1f5f9 !important;
            padding: 13px 14px !important;
            vertical-align: middle;
            color: var(--slate-800);
            font-size: 0.86rem;
            transition: background-color 0.12s ease;
        }
        .table-hover>tbody>tr:hover>td {
            background-color: #f8fafc !important;
        }

        /* Compact Mode Support (ERP High-Density Toggle) */
        body.erp-compact .table>thead>tr>th { padding: 7px 10px !important; font-size: 0.68rem !important; }
        body.erp-compact .table>tbody>tr>td { padding: 7px 10px !important; font-size: 0.81rem !important; }
        body.erp-compact .card-body { padding: 0.9rem 1.1rem !important; }
        body.erp-compact .p-4 { padding: 1.1rem !important; }
        body.erp-compact .py-4 { padding-top: 1rem !important; padding-bottom: 1rem !important; }

        .btn {
            border-radius: 8px !important;
            padding: 7px 16px !important;
            font-size: 0.84rem;
            font-weight: 600 !important;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
            transition: all 0.15s ease;
        }
        .btn-sm {
            padding: 4px 10px !important;
            font-size: 0.76rem !important;
            border-radius: 6px !important;
        }
        .btn-lg {
            padding: 10px 22px !important;
            font-size: 0.95rem !important;
            border-radius: 10px !important;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08) !important;
        }
        .btn-primary {
            background-color: var(--primary-purple) !important;
            border-color: var(--primary-purple) !important;
        }
        .btn-primary:hover {
            background-color: var(--primary-purple-hover) !important;
            border-color: var(--primary-purple-hover) !important;
        }
        .form-control, .form-select {
            border-radius: 8px !important;
            border: 1px solid var(--border-strong) !important;
            padding: 8px 12px !important;
            font-size: 0.86rem;
            background-color: #ffffff !important;
            color: var(--slate-900) !important;
            transition: all 0.15s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff !important;
            border-color: var(--primary-purple) !important;
            box-shadow: 0 0 0 3px rgba(57, 4, 143, 0.12) !important;
        }
        .text-purple { color: var(--primary-purple) !important; }
        .bg-purple { background-color: var(--primary-purple) !important; color: #ffffff !important; }
        .border-purple { border-color: var(--primary-purple) !important; }
        .btn-purple { background-color: var(--primary-purple) !important; border-color: var(--primary-purple) !important; color: #ffffff !important; }
        .btn-purple:hover { background-color: var(--primary-purple-hover) !important; border-color: var(--primary-purple-hover) !important; color: #ffffff !important; }
        .btn-outline-purple { color: var(--primary-purple) !important; border-color: var(--primary-purple) !important; background-color: transparent !important; }
        .btn-outline-purple:hover { background-color: var(--primary-purple) !important; border-color: var(--primary-purple) !important; color: #ffffff !important; }
        .bg-purple-subtle { background-color: var(--purple-subtle) !important; color: var(--primary-purple) !important; }
        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 10px !important;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .badge {
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        html { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .sidebar-sticky::-webkit-scrollbar { width: 4px; }
        .sidebar-sticky:hover::-webkit-scrollbar-thumb { background: #cbd5e1; }
        .sidebar-sticky::-webkit-scrollbar-thumb { background: transparent; }
        @media print { .d-print-none { display: none !important; } }
    </style>
</head>
<body>
<script>
    if (localStorage.getItem('erp_density_mode') === 'compact') {
        document.body.classList.add('erp-compact');
    }
</script>

<header class="navbar navbar-dark sticky-top navbar-custom d-print-none">
    
    <div class="header-left">
        <a class="navbar-brand" href="<?php echo $base_url; ?>/index.php">
            <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="Logo" class="header-logo">
            <div class="erp-brand-stack">
                <div class="erp-brand-title">
                    <span class="d-none d-sm-inline">Music MSU Connect</span>
                    <span class="d-inline d-sm-none">Music MSU Connect</span>
                    <span class="erp-version-tag">ERP 2.0</span>
                </div>
                <div class="erp-brand-sub">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
            </div>
        </a>
    </div>

    <div class="header-center">
        <button type="button" class="erp-cmd-trigger" onclick="openErpCommandPalette()" title="ค้นหาด่วนและสั่งการระบบ (Ctrl + K)">
            <span class="d-flex align-items-center gap-2 text-truncate">
                <i class="fa-solid fa-magnifying-glass text-secondary" style="font-size: 0.8rem;"></i>
                <span class="text-truncate">ค้นหาคำขอ, นิสิต, บุคลากร, ห้องซ้อม หรือพิมพ์คำสั่ง...</span>
            </span>
            <span class="erp-kbd-group ms-2 flex-shrink-0">
                <span class="erp-kbd">Ctrl</span>
                <span class="erp-kbd">K</span>
            </span>
        </button>
    </div>
    
    <div class="header-right">

        <button type="button" class="erp-density-btn d-none d-md-inline-flex" id="erpDensityToggleBtn" onclick="toggleErpDensity()" title="สลับความหนาแน่นของตารางข้อมูล (Cozy / Compact)">
            <i class="fa-solid fa-table-cells-large" id="erpDensityIcon"></i>
            <span id="erpDensityLabel">Cozy</span>
        </button>

        <?php if (!empty($is_header_approver) || $pending_approvals > 0): ?>
        <a href="<?php echo $base_url; ?>/requests_list.php" class="erp-queue-pill <?php echo ($pending_approvals > 0) ? '' : 'empty'; ?>" title="ศูนย์รวมรายการรอพิจารณาและอนุมัติ">
            <i class="fa-solid fa-inbox"></i>
            <span>รอพิจารณา</span>
            <span class="erp-queue-count"><?php echo $pending_approvals > 99 ? '99+' : (int)$pending_approvals; ?></span>
        </a>
        <?php endif; ?>
        
        <button class="header-icon-btn" data-bs-toggle="modal" data-bs-target="#appCenterModal" title="ศูนย์รวมระบบงาน (App Center)">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M6,8c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S4.9,8 6,8z M6,14c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S4.9,14 6,14z M6,20c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S4.9,20 6,20z M12,8c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S10.9,8 12,8z M12,14c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S10.9,14 12,14z M12,20c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S10.9,20 12,20z M18,8c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S16.9,8 18,8z M18,14c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S16.9,14 18,14z M18,20c1.1,0 2-0.9 2-2s-0.9-2 -2-2s-2,0.9 -2,2S16.9,20 18,20z"></path></svg>
        </button>

        <div class="dropdown">
            <a href="#" class="header-profile-btn" data-bs-toggle="dropdown" aria-expanded="false" data-bs-offset="0,10">
                <?php if (!empty($current_avatar)): ?>
                    <img src="<?php echo $base_url; ?>/uploads/avatars/<?php echo htmlspecialchars($current_avatar); ?>" alt="Profile" class="header-avatar">
                <?php else: ?>
                    <div class="header-avatar"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="header-profile-meta">
                    <span class="header-profile-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้งาน'); ?></span>
                    <span class="header-profile-role"><?php echo !empty($_SESSION['roles']) ? htmlspecialchars($_SESSION['roles'][0]) : 'บุคลากร'; ?></span>
                </div>
                <i class="fa-solid fa-chevron-down text-muted d-none d-xl-inline-block" style="font-size: 0.65rem;"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end profile-dropdown-menu animate__animated animate__fadeIn animate__faster">
                <li class="px-3 py-2 border-bottom bg-light rounded-top" style="margin-top:-6px">
                    <div class="fw-bold text-dark small text-truncate" style="max-width:210px;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้งาน'); ?></div>
                    <div class="extra-small text-muted text-truncate" style="max-width:210px;"><?php echo !empty($_SESSION['roles']) ? htmlspecialchars($_SESSION['roles'][0]) : 'บุคลากร'; ?></div>
                </li>
                <li class="mt-1"><a class="dropdown-item" href="<?php echo $base_url; ?>/profile.php"><div class="dropdown-item-icon"><i class="fa-solid fa-user-gear"></i></div> ข้อมูลส่วนตัว</a></li>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#qrCodeModal"><div class="dropdown-item-icon"><i class="fa-solid fa-qrcode"></i></div> QR Code ของฉัน</a></li>
                <?php if(isset($modules['module_cv']) && $modules['module_cv']): ?>
                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/modules/cv/index.php"><div class="dropdown-item-icon"><i class="fa-solid fa-file-invoice"></i></div> ประวัติและผลงาน (CV)</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider border-light my-1"></li>
                <li><a class="dropdown-item text-danger fw-bold" href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#logoutModal"><div class="dropdown-item-icon bg-danger-subtle"><i class="fa-solid fa-power-off text-danger"></i></div> ออกจากระบบ</a></li>
            </ul>
        </div>

        <button class="navbar-toggler d-xl-none header-icon-btn" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fa-solid fa-bars-staggered"></i>
        </button>
        
    </div>
</header>

<div class="container-fluid">
    <div class="row">