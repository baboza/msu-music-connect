<?php
/**
 * ไฟล์: includes/sidebar.php
 * หน้าที่: แสดงเมนูข้างซ้ายแบบ Dynamic ตามสิทธิ์ (Role-Based Access Control - Enterprise ERP 2.0)
 * เมนูใดที่ผู้ใช้งานไม่มีสิทธิ์เข้าถึง ระบบจะซ่อนไว้โดยอัตโนมัติ
 */

if (!function_exists('isActive')) {
    function isActive($path) {
        return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
    }
}

if (!function_exists('isExpanded')) {
    function isExpanded($paths) {
        foreach ($paths as $path) {
            if (strpos($_SERVER['PHP_SELF'], $path) !== false) return 'show';
        }
        return '';
    }
}

if (!function_exists('hasRole')) {
    function hasRole($allowed_roles) {
        $roles = $_SESSION['roles'] ?? [];
        if (!is_array($roles)) return false;
        if (in_array('admin', $roles)) return true;
        if (!is_array($allowed_roles)) $allowed_roles = [$allowed_roles];
        return count(array_intersect($allowed_roles, $roles)) > 0;
    }
}

$base_url = "/music-e-office";
$user_roles_list = $_SESSION['roles'] ?? [];
if (!is_array($user_roles_list)) {
    $user_roles_list = [];
}

// ตรวจสอบว่าเป็นหัวหน้าภาควิชาหรือประธานหลักสูตรแบบ Dynamic หรือไม่
$is_dynamic_dept_head = false;
foreach ($user_roles_list as $r_name) {
    if (mb_strpos($r_name, 'หัวหน้าภาค') !== false || mb_strpos($r_name, 'ประธานหลักสูตร') !== false) {
        $is_dynamic_dept_head = true;
        break;
    }
}

// 1. ดึงสถานะการเปิด/ปิด Module (ใช้ Session Cache)
if (isset($_SESSION['system_modules'])) {
    $modules = $_SESSION['system_modules'];
} else {
    $modules = [
        'module_cv'             => true, 
        'module_leave'          => true, 
        'module_worklog'        => true, 
        'module_projects'       => true,
        'module_meeting'        => true,
        'module_training'       => true,
        'module_supplies'       => true, 
        'module_procurement'    => true, 
        'module_finance'        => true, 
        'module_km'             => true,
        'module_room_booking'   => true, 
        'module_repair_request' => true, 
        'module_rooms'          => true  
    ];
    try {
        if (isset($pdo)) {
            $settings_query = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name LIKE 'module_%'");
            $settings_data = $settings_query->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($settings_data as $key => $val) {
                if (array_key_exists($key, $modules)) {
                    $modules[$key] = ($val == '1' || $val == 1);
                }
            }
            $_SESSION['system_modules'] = $modules;
        }
    } catch (PDOException $e) {}
}

// =========================================================================
// 2. กำหนดสิทธิ์การเข้าถึงแต่ละกลุ่มงาน (Role Permission Matrix)
// =========================================================================

// กลุ่มผู้บริหารและยุทธศาสตร์
$executive_roles         = ['admin', 'คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'หัวหน้าสำนักงาน', 'งานแผน', 'งานวิชาการ', 'งานบริการการศึกษา'];
$edpex_roles             = ['admin', 'คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร', 'ผู้ช่วยคณบดี', 'หัวหน้าสำนักงาน', 'งานแผน'];

// กลุ่มพิจารณาคำขอและงานบุคคล (HR & Workflow Approvers)
$leave_approver_roles    = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'หัวหน้าภาควิชา', 'ประธานหลักสูตร', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$training_approver_roles = ['admin', 'งานบุคคล', 'งานการเงิน', 'หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'หัวหน้าภาควิชา', 'ประธานหลักสูตร', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$hr_dashboard_roles      = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$attendance_admin_roles  = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];

// กลุ่มงานแผน การเงิน และพัสดุ
$project_plan_roles      = ['admin', 'งานแผน', 'งานการเงิน', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'คณบดี', 'รักษาการคณบดี'];
$finance_roles           = ['admin', 'งานการเงิน', 'งานแผน', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$supply_head_roles       = ['admin', 'หัวหน้าสำนักงาน'];
$supply_admin_roles      = ['admin', 'งานพัสดุ'];
$supply_report_roles     = ['admin', 'งานพัสดุ', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$procurement_roles       = ['admin', 'งานพัสดุ', 'งานแผน', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];

// กลุ่มงานอาคารสถานที่ โสตฯ และห้องซ้อมดนตรี
$building_admin_roles    = ['admin', 'งานอาคารสถานที่', 'โสตทัศนูปกรณ์', 'งานโสตทัศนศึกษา', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร'];
$practice_admin_roles    = ['admin', 'หัวหน้าสำนักงาน', 'งานอาคารสถานที่', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];

// กลุ่มงานวิชาการ วิจัย และพัฒนานิสิต
$academic_course_roles   = ['admin', 'บุคลากรสายวิชาการ', 'อาจารย์ผู้สอน', 'อาจารย์ที่ปรึกษา', 'อาจารย์', 'งานวิชาการ', 'งานบริการการศึกษา', 'รองคณบดีฝ่ายวิชาการ', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'คณบดี', 'รักษาการคณบดี'];
$academic_exec_roles     = ['admin', 'งานวิชาการ', 'งานบริการการศึกษา', 'รองคณบดีฝ่ายวิชาการ', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'คณบดี', 'รักษาการคณบดี'];
$klof_admin_roles        = ['admin', 'งานพัฒนานิสิต', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายพัฒนานิสิต', 'คณบดี', 'รักษาการคณบดี'];
$research_admin_roles    = ['admin', 'งานวิจัย', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'คณบดี', 'รักษาการคณบดี'];
$research_member_roles   = ['admin', 'บุคลากรสายวิชาการ', 'อาจารย์ผู้สอน', 'อาจารย์ที่ปรึกษา', 'อาจารย์', 'งานวิจัย', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายบริหาร', 'ผู้ช่วยคณบดี', 'คณบดี', 'รักษาการคณบดี'];

// รวมสิทธิ์สำหรับ App Center
$management_roles        = array_unique(array_merge(
    $executive_roles,
    $leave_approver_roles,
    $supply_admin_roles,
    $building_admin_roles,
    $practice_admin_roles,
    $klof_admin_roles,
    $research_admin_roles,
    ['งานการเงิน', 'งานธุรการ']
));
$meeting_admin_roles     = ['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน'];

// ตรวจสอบว่าผู้ใช้มีสิทธิ์อนุมัติรายการใดๆ หรือไม่ (สำหรับแสดงเมนู "รายการรออนุมัติ")
$is_any_approver = $is_dynamic_dept_head || hasRole(array_unique(array_merge(
    $leave_approver_roles,
    $training_approver_roles,
    $supply_head_roles,
    $supply_admin_roles,
    $building_admin_roles,
    $academic_course_roles,
    $klof_admin_roles
)));

// ตรวจสอบสิทธิ์การแสดงผลแต่ละหมวดใน Sidebar
$can_view_exec_section     = hasRole($executive_roles) || hasRole($edpex_roles);
$can_view_hr_section       = $is_dynamic_dept_head || hasRole($leave_approver_roles) || hasRole($training_approver_roles) || hasRole($hr_dashboard_roles) || hasRole($attendance_admin_roles);
$can_view_finance_section  = hasRole($project_plan_roles) || hasRole($finance_roles) || hasRole($supply_head_roles) || hasRole($supply_admin_roles) || hasRole($supply_report_roles) || hasRole($procurement_roles);
$can_view_facility_section = hasRole($practice_admin_roles) || hasRole($building_admin_roles);
$can_view_academic_section = $is_dynamic_dept_head || hasRole($academic_course_roles) || hasRole($klof_admin_roles) || hasRole($research_admin_roles);
$can_view_admin_section    = hasRole(['admin']);
?>

<div class="sidebar col-md-3 col-lg-2 d-md-block bg-white collapse d-print-none" id="sidebarMenu">
    <div class="position-sticky pt-3 sidebar-sticky pb-4 d-flex flex-column h-100">
        
        <!-- ข้อมูลผู้ใช้งานและสิทธิ์ (Role Badges) -->
        <div class="px-3 mb-3 d-none d-md-block">
            <div class="erp-sidebar-user-card p-2 rounded-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-circle flex-shrink-0 overflow-hidden bg-purple-subtle text-purple">
                        <?php if (!empty($current_avatar)): ?>
                            <img src="<?php echo $base_url; ?>/uploads/avatars/<?php echo htmlspecialchars($current_avatar); ?>" alt="Profile" class="w-100 h-100" style="object-fit: cover;">
                        <?php else: ?>
                            <i class="fa-solid fa-user-tie"></i>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-hidden flex-grow-1">
                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.8rem; line-height: 1.25;">
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้งาน'); ?>
                        </div>
                        <div class="text-muted" style="font-size: 0.66rem;">
                            สิทธิ์การใช้งาน (<?php echo count($user_roles_list) ?: 1; ?>)
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2 pt-1 border-top" style="border-color: #e2e8f0 !important;">
                    <?php if (!empty($user_roles_list)): ?>
                        <?php foreach ($user_roles_list as $r_badge): ?>
                            <span class="erp-role-chip"><?php echo htmlspecialchars($r_badge); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="erp-role-chip">บุคลากรทั่วไป</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
        $current_dash_view = $_GET['view'] ?? ($_SESSION['dashboard_view_preference'] ?? (hasRole($executive_roles) ? 'executive' : 'personal'));
        $is_on_root_index = ((isActive('index.php') || isActive('executive_dashboard.php')) && !strpos($_SERVER['PHP_SELF'], 'modules/') && !isActive('requests_list.php'));
        $is_exec_hub_active = ($is_on_root_index && hasRole($executive_roles) && ($current_dash_view === 'executive' || isActive('executive_dashboard.php')));
        $is_personal_dash_active = ($is_on_root_index && !$is_exec_hub_active);
        ?>

        <div class="accordion accordion-flush flex-grow-1" id="sidebarAccordion">
            
            <!-- =========================================================
                 หมวดที่ 1: ภาพรวมระบบ (แสดงตามสิทธิ์ผู้บริหาร / ผู้อนุมัติ)
                 ========================================================= -->
            <?php if (hasRole($executive_roles) || $is_any_approver || (!empty($pending_approvals) && $pending_approvals > 0)): ?>
            <div class="sidebar-section-label px-3 mt-1 mb-1">ภาพรวมระบบ</div>
            
            <div class="px-2 mb-3">
                <?php if (hasRole($executive_roles)): ?>
                <a class="nav-link-simple <?php echo $is_exec_hub_active ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/index.php?view=executive">
                    <span class="nav-icon-box"><i class="fa-solid fa-compass"></i></span>
                    <span class="flex-grow-1">Executive Hub</span>
                    <span class="erp-nav-pill pill-purple">HQ</span>
                </a>
                <?php endif; ?>

                <?php if ($is_any_approver || (!empty($pending_approvals) && $pending_approvals > 0)): ?>
                <a class="nav-link-simple <?php echo isActive('requests_list.php'); ?>" href="<?php echo $base_url; ?>/requests_list.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-inbox"></i></span>
                    <span class="flex-grow-1">Action Inbox (กล่องงาน)</span>
                    <?php if (!empty($pending_approvals) && $pending_approvals > 0): ?>
                        <span class="erp-nav-pill pill-amber"><?php echo $pending_approvals > 99 ? '99+' : (int)$pending_approvals; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- =========================================================
                 หมวดที่ 2: พื้นที่ส่วนตัว
                 ========================================================= -->
            <div class="sidebar-section-label px-3 mt-1 mb-1">พื้นที่ส่วนตัว</div>
            
            <div class="px-2 mb-3">
                <a class="nav-link-simple <?php echo $is_personal_dash_active ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/index.php?view=personal">
                    <span class="nav-icon-box"><i class="fa-solid fa-house"></i></span>
                    <span>แดชบอร์ด</span>
                </a>

                <a class="nav-link-simple <?php echo isActive('profile.php'); ?>" href="<?php echo $base_url; ?>/profile.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-id-card"></i></span>
                    <span>ข้อมูลส่วนตัว</span>
                </a>
                
                <?php if (!empty($modules['module_cv'])): ?>
                <a class="nav-link-simple <?php echo isActive('modules/cv/'); ?>" href="<?php echo $base_url; ?>/modules/cv/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-file-lines"></i></span>
                    <span>ประวัติและผลงาน (CV)</span>
                </a>
                <?php endif; ?>

                <?php if (hasRole($research_member_roles)): ?>
                <a class="nav-link-simple <?php echo isActive('modules/research_grants/my_grants.php'); ?>" href="<?php echo $base_url; ?>/modules/research_grants/my_grants.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-microscope"></i></span>
                    <span>ทุนวิจัย</span>
                </a>
                <?php endif; ?>
            </div>

            <!-- =========================================================
                 หมวดที่ 3: บริการบุคลากร
                 ========================================================= -->
            <?php if(!empty($modules['module_leave']) || !empty($modules['module_worklog']) || !empty($modules['module_meeting']) || !empty($modules['module_training']) || !empty($modules['module_supplies']) || !empty($modules['module_room_booking']) || !empty($modules['module_repair_request'])): ?>
            <div class="sidebar-section-label px-3 mt-1 mb-1">บริการบุคลากร</div>
            
            <div class="px-2 mb-3">
                <?php if(!empty($modules['module_worklog'])): ?>
                <a class="nav-link-simple <?php echo isActive('modules/worklog/create.php'); ?>" href="<?php echo $base_url; ?>/modules/worklog/create.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-calendar-check"></i></span>
                    <span>Daily report</span>
                </a>
                <?php endif; ?>

                <a class="nav-link-simple <?php echo isActive('modules/attendance/my_attendance.php'); ?>" href="<?php echo $base_url; ?>/modules/attendance/my_attendance.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <span>บันทึกเวลาของฉัน</span>
                </a>

                <?php if(!empty($modules['module_leave'])): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/leave/') !== false && !isActive('approve_list.php') && !isActive('dashboard_admin.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/leave/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-envelope-open-text"></i></span>
                    <span>ระบบการลา</span>
                </a>
                <?php endif; ?>
                
                <?php if(!empty($modules['module_meeting'])): ?>
                <a class="nav-link-simple <?php echo strpos($_SERVER['PHP_SELF'], 'modules/meetings/') !== false ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/meetings/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-handshake"></i></span>
                    <span>ระบบการประชุม</span>
                </a>
                <?php endif; ?>
                
                <?php if(!empty($modules['module_room_booking'])): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/room_booking/') !== false && !isActive('approve_list.php') && !isActive('index.php') && !isActive('dashboard_admin.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/room_booking/my_bookings.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-door-open"></i></span>
                    <span>จองห้อง/สถานที่</span>
                </a>
                <?php endif; ?>
                
                <a class="nav-link-simple <?php echo strpos($_SERVER['PHP_SELF'], 'modules/zoom_booking/') !== false ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/zoom_booking/">
                    <span class="nav-icon-box"><i class="fa-solid fa-video"></i></span>
                    <span>จอง Zoom</span>
                </a>

                <a class="nav-link-simple <?php echo strpos($_SERVER['PHP_SELF'], 'modules/vehicle_booking/') !== false ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/vehicle_booking/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-car-side"></i></span>
                    <span>จองรถวิทยาลัย</span>
                </a>

                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/qrcode/') !== false && !isActive('dashboard.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/qrcode/">
                    <span class="nav-icon-box"><i class="fa-solid fa-qrcode"></i></span>
                    <span>สร้าง QR Code</span>
                </a>
                
                <?php if(!empty($modules['module_repair_request'])): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/repair_request/') !== false && !isActive('index.php') && !isActive('dashboard_admin.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/repair_request/my_repairs.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                    <span>แจ้งซ่อมบำรุง</span>
                </a>
                <?php endif; ?>

                <?php if(!empty($modules['module_training'])): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/trainings/') !== false && strpos($_SERVER['PHP_SELF'], 'approve_list.php') === false && strpos($_SERVER['PHP_SELF'], 'dashboard_admin.php') === false) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/trainings/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-plane-departure"></i></span>
                    <span>รายงานสัมมนา / อบรม</span>
                </a>
                <?php endif; ?>

                <a class="nav-link-simple <?php echo strpos($_SERVER['PHP_SELF'], 'modules/pr/') !== false ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/pr/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-tv"></i></span>
                    <span>จอประชาสัมพันธ์</span>
                </a>

                <?php if(!empty($modules['module_supplies'])): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/supplies/') !== false && !isActive('head_approve.php') && !isActive('issue_supplies.php') && !isActive('manage_items.php') && !isActive('report.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/supplies/index.php">
                    <span class="nav-icon-box"><i class="fa-solid fa-box-open"></i></span>
                    <span>เบิกวัสดุสำนักงาน</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ปุ่มเปิด App Center (รวมทุกระบบงานของคณะ) -->
            <div class="px-3 mb-2 mt-1">
                <button type="button" class="btn btn-app-center w-100 py-2 fw-bold d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#appCenterModal">
                    <span class="d-flex align-items-center gap-2"><i class="fa-solid fa-grip"></i> ระบบงานทั้งหมด (App Center)</span>
                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.7rem;"></i>
                </button>
            </div>
            <!-- ปุ่มสลับไปเวอร์ชัน Mobile -->
            <div class="px-3 mb-3">
                <a href="<?php echo $base_url; ?>/mobile/index.php?mobile=1" class="btn btn-outline-primary w-100 py-1.5 fw-bold d-flex align-items-center justify-content-center gap-2" style="font-size: 0.8rem; border-radius: 8px;">
                    <i class="fa-solid fa-mobile-screen"></i> สลับไปเวอร์ชัน Mobile
                </a>
            </div>
        </div>

        <div class="px-3 mt-auto pt-2 border-top" style="border-color: #f1f5f9 !important;">
            <div class="d-flex align-items-center justify-content-between text-muted" style="font-size: 0.7rem;">
                <span class="d-flex align-items-center gap-1">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background-color: #10b981; display: inline-block;"></span>
                    <span>สิทธิ์ตามบทบาท (RBAC)</span>
                </span>
                <span class="fw-semibold text-secondary">ERP 2.0</span>
            </div>
        </div>
    </div>
</div>

<?php include_once file_exists(__DIR__ . '/app_center_modal.php') ? __DIR__ . '/app_center_modal.php' : __DIR__ . '/includes/app_center_modal.php'; ?>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg logout-card">
            <div class="modal-body p-4 text-center">
                <div class="logout-icon-wrapper mb-3">
                    <i class="fa-solid fa-arrow-right-from-bracket fa-2x text-danger animate-out"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">ยืนยันการออกจากระบบ?</h5>
                <p class="text-muted small mb-4">คุณต้องการสิ้นสุดเซสชันการใช้งานในตอนนี้ใช่หรือไม่?<br>โปรดตรวจสอบการบันทึกข้อมูลก่อนออกจากระบบ</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light px-4 py-2 fw-bold text-secondary border" data-bs-dismiss="modal">ยกเลิก</button>
                    <a href="<?php echo $base_url; ?>/auth/logout.php" class="btn btn-danger px-4 py-2 fw-bold shadow-sm">ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* --- Enterprise Navigation Rail (ERP 2.0 Role-Based) --- */
    .sidebar { border-right: 1px solid #e2e8f0 !important; }
    .erp-sidebar-user-card {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    .erp-role-chip {
        display: inline-block;
        font-size: 0.63rem;
        font-weight: 600;
        padding: 1px 6px;
        border-radius: 4px;
        background-color: #f4efff;
        color: #39048f;
        border: 1px solid rgba(57, 4, 143, 0.14);
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .sidebar-section-label {
        font-size: 0.65rem;
        font-weight: 700;
        color: #94a3b8;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .sidebar-role-header {
        color: #475569;
    }
    
    .nav-link-simple { 
        color: #475569;
        font-weight: 500;
        padding: 7px 10px;
        display: flex;
        align-items: center;
        gap: 9px;
        text-decoration: none;
        font-size: 0.81rem;
        transition: all 0.15s ease; 
        border-radius: 8px;
        margin-bottom: 2px;
        position: relative;
    }
    .nav-icon-box {
        width: 24px;
        height: 24px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        font-size: 0.82rem;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }
    .nav-link-simple:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }
    .nav-link-simple:hover .nav-icon-box {
        color: #39048f;
    }
    .nav-link-simple.active { 
        background-color: #f4efff !important; 
        color: #39048f !important;
        font-weight: 700;
        box-shadow: inset 3px 0 0 #39048f;
    }
    .nav-link-simple.active .nav-icon-box,
    .nav-link-simple.active i {
        color: #39048f !important;
    }
    .erp-nav-badge,
    .erp-nav-pill {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 6px;
        font-variant-numeric: tabular-nums;
        line-height: 1.2;
    }
    .erp-nav-badge {
        background-color: #f59e0b;
        color: #ffffff;
    }
    .pill-purple { background-color: #ede9fe; color: #5b13df; }
    .pill-amber  { background-color: #fef3c7; color: #b45309; }
    .pill-emerald { background-color: #d1fae5; color: #047857; }
    .pill-rose   { background-color: #ffe4e6; color: #be123c; }
    
    .btn-app-center { 
        background-color: #0f172a;
        color: #ffffff !important;
        border: 1px solid #0f172a;
        border-radius: 8px !important;
        font-size: 0.78rem !important;
        transition: all 0.15s ease; 
    }
    .btn-app-center:hover { 
        background-color: #39048f;
        border-color: #39048f;
        color: #ffffff !important; 
        transform: translateY(-1px); 
        box-shadow: 0 4px 12px rgba(57, 4, 143, 0.2) !important; 
    }

    /* สไตล์การ์ดใน App Center Modal */
    .app-card { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px !important; }
    .app-card:hover { background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06); transform: translateY(-2px); z-index: 10; }
    .app-icon { width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }

    /* ส่วนอื่นๆ คงเดิม */
    .accordion-button { font-size: 0.82rem !important; font-weight: 600; color: #334155; background-color: transparent !important; box-shadow: none !important; border-radius: 8px !important; transition: 0.15s; padding: 8px 12px !important; }
    .accordion-button:hover { background-color: #f1f5f9 !important; color: #39048f; }
    .accordion-button:not(.collapsed) { color: #39048f; background-color: #f8fafc !important; font-weight: 700; }
    .accordion-button::after { background-size: 0.7rem; width: 0.7rem; height: 0.7rem; }
    .accordion-body { padding: 4px 0 4px 10px !important; }
    .accordion-body .nav-link { color: #64748b !important; font-weight: 500; padding: 6px 12px !important; font-size: 0.78rem !important; border-radius: 6px !important; transition: 0.15s; margin-bottom: 2px; }
    .accordion-body .nav-link:hover { color: #39048f !important; background-color: #f4efff !important; }
    .accordion-body .nav-link.active { background-color: #39048f !important; color: #ffffff !important; font-weight: 600 !important; }

    .avatar-circle { width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .text-purple { color: #39048f; }
    .text-gold { color: #b48a1e; }
    .bg-purple-subtle { background-color: #f4efff; }
    .bg-gold-subtle { background-color: #fef9c3; }
    .cursor-pointer { cursor: pointer; }
    
    /* Logout Modal Styles */
    .logout-card { border-radius: 16px !important; overflow: hidden; border: 1px solid #e2e8f0 !important; }
    #logoutModal .modal-backdrop, #appCenterModal .modal-backdrop { background-color: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); }
    .logout-icon-wrapper { width: 64px; height: 64px; background-color: #fee2e2; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('appCenterSearch');
    const appModal = document.getElementById('appCenterModal');

    if(searchInput && appModal) {
        // Filter functionality
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase().trim();
            const originalCards = appModal.querySelectorAll('.tab-pane:not(#fav-pane) .app-item');
            const favCards = appModal.querySelectorAll('#fav-pane .app-item');
            const tabPanes = appModal.querySelectorAll('.tab-pane');
            const navLinks = appModal.querySelectorAll('.nav-link');
            const favPane = document.getElementById('fav-pane');
            
            if(filter.length > 0) {
                tabPanes.forEach(pane => {
                    if (pane.id !== 'fav-pane') {
                        pane.classList.add('show', 'active');
                        pane.style.display = 'block';
                    } else {
                        pane.classList.remove('show', 'active');
                        pane.style.display = 'none';
                    }
                });
                
                navLinks.forEach(link => link.classList.add('opacity-50'));
                favCards.forEach(card => card.style.display = 'none');

                originalCards.forEach(function(cardWrapper) {
                    const text = cardWrapper.innerText.toLowerCase();
                    if(text.includes(filter)) {
                        cardWrapper.style.display = '';
                    } else {
                        cardWrapper.style.display = 'none';
                    }
                });

                tabPanes.forEach(pane => {
                    if (pane.id !== 'fav-pane') {
                        const hasVisible = Array.from(pane.querySelectorAll('.app-item')).some(item => item.style.display !== 'none');
                        pane.style.display = hasVisible ? 'block' : 'none';
                    }
                });

            } else {
                tabPanes.forEach(pane => {
                    pane.classList.remove('show', 'active');
                    pane.style.display = '';
                });
                navLinks.forEach(link => {
                    link.classList.remove('opacity-50', 'active');
                    link.setAttribute('aria-selected', 'false');
                });
                
                originalCards.forEach(card => card.style.display = '');
                favCards.forEach(card => card.style.display = '');
                
                const firstTab = document.getElementById('fav-tab');
                if(firstTab && favPane) {
                    firstTab.classList.add('active');
                    firstTab.setAttribute('aria-selected', 'true');
                    favPane.classList.add('show', 'active');
                }
            }
        });

        appModal.addEventListener('shown.bs.modal', function () {
            setTimeout(() => searchInput.focus(), 100);
        });

        appModal.addEventListener('hidden.bs.modal', function () {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        });
    }
});
</script>
