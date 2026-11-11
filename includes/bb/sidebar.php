<?php
/**
 * ไฟล์: sidebar.php
 * หน้าที่: แสดงเมนูข้างซ้ายแบบ Dynamic (สไตล์ Notion + App Center สำหรับระบบใหญ่)
 */

function isActive($path) {
    return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
}

function isExpanded($paths) {
    foreach ($paths as $path) {
        if (strpos($_SERVER['PHP_SELF'], $path) !== false) return 'show';
    }
    return '';
}

$base_url = "/music-e-office"; 
// 1. รูปโปรไฟล์ - ใช้ตัวแปร $current_avatar ที่ประมวลผลมาจาก header.php แล้ว

// 2. ดึงสถานะการเปิด/ปิด Module (ใช้ Session Cache)
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
        $settings_query = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name LIKE 'module_%'");
        $settings_data = $settings_query->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($settings_data as $key => $val) {
            if (array_key_exists($key, $modules)) {
                $modules[$key] = ($val == '1' || $val == 1);
            }
        }
        $_SESSION['system_modules'] = $modules;
    } catch (PDOException $e) {}
}

// 💡 อัปเดต roles ให้ครอบคลุมงานพัฒนานิสิต และ โสตทัศนูปกรณ์ จะได้เห็นฝั่งผู้บริหารด้วย
$management_roles        = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'งานแผน', 'ผู้ช่วยคณบดี', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี', 'งานพัสดุ', 'งานอาคารสถานที่', 'งานพัฒนานิสิต', 'รองคณบดีฝ่ายพัฒนานิสิต', 'โสตทัศนูปกรณ์'];
$meeting_admin_roles     = ['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน'];
$executive_roles         = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$supply_admin_roles      = ['admin', 'งานพัสดุ'];
$supply_report_roles     = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี', 'งานพัสดุ'];
$procurement_roles       = ['admin', 'งานพัสดุ', 'งานแผน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$building_admin_roles    = ['admin', 'งานอาคารสถานที่', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'โสตทัศนูปกรณ์'];
$practice_admin_roles    = ['admin', 'หัวหน้าสำนักงาน', 'งานอาคารสถานที่', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];

// สิทธิ์สำหรับเมนู ชั่วโมง กยศ.
$klof_admin_roles        = ['admin', 'งานพัฒนานิสิต', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายพัฒนานิสิต', 'คณบดี'];
$edpex_roles             = ['admin', 'หัวหน้าสำนักงาน', 'งานแผน', 'ผู้ช่วยคณบดี', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];

// 4. จำนวนรายการรออนุมัติ (Badge Count)
// ลบการ Query ซ้ำทิ้งทั้งหมด เนื่องจากมีการคำนวณ $pending_approvals จาก header.php ไว้แล้ว
?>

<div class="sidebar col-md-3 col-lg-2 d-md-block bg-white shadow-sm collapse d-print-none border-end-0">
    <div class="position-sticky pt-4 sidebar-sticky pb-5 d-flex flex-column h-100">
        
        <div class="px-3 mb-4 text-center d-none d-md-block">
            <div class="avatar-circle mb-3 mx-auto overflow-hidden shadow-sm border border-3 border-white bg-primary-subtle text-primary">
                <?php if (!empty($current_avatar)): ?>
                    <img src="<?php echo $base_url; ?>/uploads/avatars/<?php echo htmlspecialchars($current_avatar); ?>" alt="Profile" class="w-100 h-100" style="object-fit: cover;">
                <?php else: ?>
                    <i class="fa-solid fa-user-tie"></i>
                <?php endif; ?>
            </div>
            <div class="fw-bold small text-dark mb-1"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            <div class="text-muted extra-small">
                <span class="badge bg-purple-subtle text-purple px-2 py-1 fw-normal rounded-pill">
                    <?php echo !empty($_SESSION['roles']) ? htmlspecialchars($_SESSION['roles'][0]) : 'บุคลากร'; ?>
                </span>
            </div>
        </div>

        <div class="accordion accordion-flush flex-grow-1" id="sidebarAccordion">
            
            <h6 class="sidebar-heading px-4 mt-2 mb-2 text-muted text-uppercase small fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">พื้นที่ส่วนตัว</h6>
            
            <div class="px-2 mb-3">
                <a class="nav-link-simple <?php echo isActive('index.php') && !strpos($_SERVER['PHP_SELF'], 'modules/') && !isActive('executive_dashboard.php') && !isActive('requests_list.php') ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/index.php">
                    <i class="fa-solid fa-house-chimney me-2 <?php echo isActive('index.php') && !strpos($_SERVER['PHP_SELF'], 'modules/') && !isActive('executive_dashboard.php') && !isActive('requests_list.php') ? '' : 'text-secondary '; ?>"></i> แดชบอร์ด
                </a>

                <a class="nav-link-simple <?php echo isActive('profile.php'); ?>" href="<?php echo $base_url; ?>/profile.php">
                    <i class="fa-solid fa-id-card me-2 <?php echo isActive('profile.php') ? '' : 'text-secondary'; ?>"></i> ข้อมูลส่วนตัว
                </a>
                
                <?php if($modules['module_cv']): ?>
                <a class="nav-link-simple <?php echo isActive('modules/cv/'); ?>" href="<?php echo $base_url; ?>/modules/cv/index.php">
                    <i class="fa-solid fa-file-lines me-2 <?php echo isActive('modules/cv/') ? '' : 'text-secondary '; ?>"></i> ประวัติและผลงาน (CV)
                </a>
                <?php endif; ?>
            </div>

            <?php if($modules['module_leave'] || $modules['module_worklog'] || $modules['module_training'] || $modules['module_supplies'] || $modules['module_finance'] || $modules['module_room_booking'] || $modules['module_repair_request']): ?>
            <h6 class="sidebar-heading px-4 mt-3 mb-2 text-muted text-uppercase small fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">บริการบุคลากร</h6>
            
            <div class="px-2 mb-4">
                <?php if($modules['module_worklog']): ?>
                <a class="nav-link-simple <?php echo isActive('modules/worklog/create.php'); ?>" href="<?php echo $base_url; ?>/modules/worklog/create.php">
                    <i class="fa-solid fa-calendar-check me-2 <?php echo isActive('modules/worklog/create.php') ? '' : ''; ?>"></i></i> Daily report
                </a>
                <?php endif; ?>

                <?php if($modules['module_leave']): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/leave/') !== false && !isActive('approve_list.php') && !isActive('dashboard_admin.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/leave/index.php">
                    <i class="fa-solid fa-envelope-open-text me-2 <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/leave/') !== false && !isActive('approve_list.php') && !isActive('dashboard_admin.php')) ? '' : ''; ?>"></i> ระบบการลา
                </a>
                <?php endif; ?>
                
                <?php if($modules['module_room_booking']): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/room_booking/') !== false && !isActive('approve_list.php') && !isActive('index.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/room_booking/my_bookings.php">
                    <i class="fa-solid fa-door-open me-2" style="color: <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/room_booking/') !== false && !isActive('approve_list.php') && !isActive('index.php')) ? 'inherit' : ''; ?>;"></i> จองห้อง/สถานที่
                </a>
                <?php endif; ?>
                
                <?php if($modules['module_repair_request']): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/repair_request/') !== false && !isActive('index.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/repair_request/my_repairs.php">
                    <i class="fa-solid fa-screwdriver-wrench me-2" style="color: <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/repair_request/') !== false && !isActive('index.php')) ? 'inherit' : ''; ?>;"></i> แจ้งซ่อมบำรุง
                </a>
                <?php endif; ?>

                <?php if($modules['module_training']): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/trainings/') !== false && strpos($_SERVER['PHP_SELF'], 'approve_list.php') === false && strpos($_SERVER['PHP_SELF'], 'dashboard_admin.php') === false) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/trainings/index.php">
                    <i class="fa-solid fa-plane-departure me-2 <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/trainings/') !== false && strpos($_SERVER['PHP_SELF'], 'approve_list.php') === false && strpos($_SERVER['PHP_SELF'], 'dashboard_admin.php') === false) ? '' : ''; ?>"></i> รายงานสัมมนา / อบรม
                </a>
                <?php endif; ?>

                <?php if($modules['module_supplies']): ?>
                <a class="nav-link-simple <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/supplies/') !== false && !isActive('head_approve.php') && !isActive('issue_supplies.php') && !isActive('manage_items.php') && !isActive('report.php')) ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/modules/supplies/index.php">
                    <i class="fa-solid fa-box-open me-2 <?php echo (strpos($_SERVER['PHP_SELF'], 'modules/supplies/') !== false && !isActive('head_approve.php') && !isActive('issue_supplies.php') && !isActive('manage_items.php') && !isActive('report.php')) ? '' : ''; ?>"></i> เบิกวัสดุสำนักงาน
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="px-3 mb-4 mt-2">
                <button type="button" class="btn btn-app-center w-100 rounded-3 py-2 shadow-sm fw-bold d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#appCenterModal" style="transition: transform 0.2s;">
                    <span><i class="fa-solid fa-layer-group me-2"></i> App Center</span>
                    <i class="fa-solid fa-arrow-up-right-from-square extra-small opacity-75"></i>
                </button>
            </div>
        </div>

        <hr class="mx-4 mt-auto mb-2 border-secondary border-opacity-10">
        <ul class="nav flex-column mb-3 px-2">
            <li class="nav-item">

            </li>
        </ul>
    </div>
</div>

<div class="modal fade" id="appCenterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px; background-color: #f8fafc;">
            
            <div class="modal-header border-0 pb-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-layer-group text-purple me-2"></i>แอปพลิเคชันและระบบงานหลัก (App Center)</h4>
                    <p class="text-muted small mb-0">เลือกบริการหรือระบบที่ต้องการใช้งานเพิ่มเติม</p>
                </div>
                <button type="button" class="btn-close bg-light rounded-circle shadow-sm" data-bs-dismiss="modal" aria-label="Close" style="padding: 10px;"></button>
            </div>
            
            <div class="modal-body p-4 pt-3">
                <div class="row g-4">
                    
                    <div class="col-lg-5">
                        <div class="card border-0 shadow-sm h-100 rounded-4">
                            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                                <h6 class="fw-bold text-purple mb-0">ระบบงานหลัก</h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <a href="https://music.msu.ac.th/workload/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-list-check"></i></div>
                                            <div class="fw-bold text-dark small">ระบบ TOR</div>
                                        </a>
                                    </div>

                                    <?php if($modules['module_meeting']): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/meetings/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-handshake"></i></div>
                                            <div class="fw-bold text-dark small">ระบบการประชุม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/research/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-book-journal-whills"></i></div>
                                            <div class="fw-bold text-dark small">ฐานข้อมูลวิจัย</div>
                                        </a>
                                    </div>

                                    <?php if($modules['module_projects']): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/projects/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-diagram-project"></i></div>
                                            <div class="fw-bold text-dark small">ระบบโครงการ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_km']): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/km/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-brain"></i></div>
                                            <div class="fw-bold text-dark small">คลังความรู้ (KM)</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6">
                                        <a href="https://music.msu.ac.th/exam/" target="_blank" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon me-3" style="color: #6366f1; background-color: #e0e7ff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%;"><i class="fa-solid fa-laptop-code"></i></div>
                                            <div class="fw-bold text-dark small">ระบบสอบออนไลน์</div>
                                        </a>
                                    </div>

                                    <?php if($modules['module_cv']): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/cv/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-secondary bg-opacity-10 text-secondary me-3"><i class="fa-solid fa-file-lines"></i></div>
                                            <div class="fw-bold text-dark small">สร้างประวัติ CV</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if($modules['module_finance']): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/finance/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-money-bill-transfer"></i></div>
                                            <div class="fw-bold text-dark small">สถานะการเงิน</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_procurement'] && hasRole($procurement_roles)): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/procurement/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-primary text-white me-3"><i class="fa-solid fa-truck-fast"></i></div>
                                            <div class="fw-bold text-dark small">ติดตามพัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_supplies'] && hasRole($supply_admin_roles)): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/supplies/manage_items.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-primary text-white me-3"><i class="fa-solid fa-boxes-stacked"></i></div>
                                            <div class="fw-bold text-dark small">จัดการคลังวัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (hasRole($klof_admin_roles)): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/klof/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-file-signature"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติชั่วโมง กยศ.</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

     <?php if($modules['module_cv']): ?>
    <div class="col-sm-6">
        <a href="https://music.msu.ac.th/TQF/" 
           target="_blank" 
           rel="noopener noreferrer"
           class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">

            <div class="app-icon bg-info-subtle text-info me-3">
                <i class="fa-solid fa-pen"></i>
            </div>

            <div class="fw-bold text-dark small">
                ระบบ มคอ.3/5
            </div>
        </a>
    </div>
<?php endif; ?>

                                    <div class="col-sm-6">
                                        <a href="https://music.msu.ac.th/music-cert/index.php"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-danger-subtle text-danger me-3">
                                                <i class="fa-solid fa-id-card"></i>
                                            </div>
                                            <div class="fw-bold text-dark small">ใบรับรองนิสิตจีน</div>
                                        </a>
                                    </div>

                                    <?php if($modules['module_room_booking'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/room_booking/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-calendar-check"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติจองห้อง</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_repair_request'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-sm-6">
                                        <a href="<?php echo $base_url; ?>/modules/repair_request/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-tools"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติแจ้งซ่อม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (hasRole($management_roles)): ?>
                    <div class="col-lg-7">
                        <div class="card border-0 shadow-sm h-100 rounded-4 bg-white">
                            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                                <h6 class="fw-bold text-purple mb-0">สำหรับผู้บริหารและหัวหน้างาน (ERP & Analytics)</h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-2">
                                    <?php if (hasRole($executive_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/executive_dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-chess-king"></i></div>
                                            <div class="fw-bold text-dark small">Executive Dashboard</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_rooms'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/rooms/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-building-circle-check"></i></div>
                                            <div class="fw-bold text-dark small">จัดการข้อมูลห้อง / สถานที่</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole($practice_admin_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/practice_rooms/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm" style="border-color: #ec4899 !important;">
                                            <div class="app-icon text-pink me-3" style="background-color: #fce7f3; color: #ec4899;"><i class="fa-solid fa-music"></i></div>
                                            <div class="fw-bold text-dark small">จัดการห้องซ้อมดนตรี</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_room_booking'] && hasRole($management_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/room_booking/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-chart-area"></i></div>
                                            <div class="fw-bold text-dark small">สถิติภาพรวมจองห้อง</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_repair_request'] && hasRole($management_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/repair_request/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-chart-column"></i></div>
                                            <div class="fw-bold text-dark small">สถิติภาพรวมแจ้งซ่อม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_leave']): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/leave/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-warning-subtle text-dark me-3"><i class="fa-solid fa-chart-line"></i></div>
                                            <div class="fw-bold text-dark small">สถิติระบบการลา</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_worklog']): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/worklog/admin_dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-clipboard-list"></i></div>
                                            <div class="fw-bold text-dark small">สถิติการทำงาน (Coda)</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_training']): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/trainings/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-plane-departure"></i></div>
                                            <div class="fw-bold text-dark small">สถิติการไปราชการ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_supplies'] && hasRole($supply_report_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/supplies/report.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-chart-bar"></i></div>
                                            <div class="fw-bold text-dark small">ภาพรวมเบิกพัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (hasRole($klof_admin_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/modules/klof/summary.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-chart-pie"></i></div>
                                            <div class="fw-bold text-dark small">ภาพรวมชั่วโมง กยศ.</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (hasRole($edpex_roles)): ?>
                                    <div class="col-md-6">
                                        <a href="<?php echo $base_url; ?>/EdPEx.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 border shadow-sm" style="border-color: #c5a028 !important;">
                                            <div class="app-icon bg-gold-subtle text-gold me-3"><i class="fa-solid fa-trophy"></i></div>
                                            <div class="fw-bold text-dark small">รายงานผล EdPEx หมวด 7</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (hasRole(['admin'])): ?>
                                    <div class="col-12 mt-3">
                                        <h6 class="fw-bold text-danger mb-2 small"><i class="fa-solid fa-screwdriver-wrench me-1"></i> ผู้ดูแลระบบ (Super Admin)</h6>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a href="<?php echo $base_url; ?>/admin/users.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 fw-bold text-secondary"><i class="fa-solid fa-users me-1"></i> จัดการผู้ใช้</a>
                                            <a href="<?php echo $base_url; ?>/admin/students.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 fw-bold text-secondary"><i class="fa-solid fa-user-graduate me-1"></i> จัดการนิสิต</a>
                                            <a href="<?php echo $base_url; ?>/admin/settings.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 fw-bold text-secondary"><i class="fa-solid fa-gear me-1"></i> ตั้งค่าทั่วไป</a>
                                            <a href="<?php echo $base_url; ?>/admin/login_logs.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 fw-bold text-secondary"><i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการใช้งาน</a>
											<a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 fw-bold text-secondary"><i class="fa-solid fa-bell me-1"></i> ส่งแจ้งเตือนนิสิต</a>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg logout-card" style="border-radius: 24px;">
            <div class="modal-body p-5 text-center">
                <div class="logout-icon-wrapper mb-4">
                    <i class="fa-solid fa-arrow-right-from-bracket fa-3x text-danger animate-out"></i>
                </div>
                <h4 class="fw-bold text-dark mb-2">ยืนยันการออกจากระบบ?</h4>
                <p class="text-muted mb-4">คุณต้องการสิ้นสุดการใช้งานในตอนนี้ใช่หรือไม่?<br>ตรวจสอบการบันทึกข้อมูลให้เรียบร้อยก่อนออกจากระบบ</p>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-light px-4 py-2 rounded-pill fw-bold text-secondary border" data-bs-dismiss="modal">ยกเลิก</button>
                    <a href="<?php echo $base_url; ?>/auth/logout.php" class="btn btn-danger px-4 py-2 rounded-pill fw-bold shadow-sm">ยืนยันออกจากระบบ</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* 💡 CSS ปรับปรุงใหม่ */
    .sidebar { border-right: 1px solid rgba(0,0,0,0.05); }
    .sidebar-heading { opacity: 0.5; }
    
    /* ลิงก์ธรรมดา */
    .nav-link-simple { 
        color: #4a5568; font-weight: 500; padding: 12px 16px; display: block; 
        text-decoration: none; font-size: 0.85rem; transition: all 0.2s; 
        border-radius: 12px; margin-bottom: 6px; 
    }
    .nav-link-simple:hover { background-color: #f8fafc; color: #4b0082; transform: translateX(3px); }
    .nav-link-simple.active { 
        background: linear-gradient(135deg, #4b0082 0%, #6a1b9a 100%) !important; 
        color: #fff !important; box-shadow: 0 4px 12px rgba(75,0,130,0.2); 
    }
    .nav-link-simple.active i { color: #fff !important; }
    
    /* 💡 สไตล์ปุ่ม App Center ใหม่ (แก้ปัญหาซ้ำกับปุ่ม Active) */
    .btn-app-center { 
        background-color: #f3e5f5; /* ม่วงอ่อนพาสเทล (bg-purple-subtle) */
        color: #4b0082; /* ตัวหนังสือม่วงเข้ม */
        border: 1px solid rgba(75,0,130,0.15); /* ขอบบางๆ */
        transition: all 0.2s ease; 
    }
    .btn-app-center:hover { 
        background-color: #e1bee7; /* เข้มขึ้นนิดนึงตอนชี้ */
        color: #4b0082; 
        transform: translateY(-2px); 
        box-shadow: 0 4px 12px rgba(75,0,130,0.15) !important; 
    }

    /* สไตล์การ์ดใน App Center Modal */
    .app-card { transition: all 0.2s ease; background-color: #f8fafc; border: 1px solid transparent; }
    .app-card:hover { background-color: #ffffff !important; border: 1px solid #e2e8f0 !important; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transform: translateY(-2px); }
    .app-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }

    /* ส่วนอื่นๆ คงเดิม */
    .accordion-button { font-size: 0.85rem !important; font-weight: 500; color: #4a5568; background-color: transparent !important; box-shadow: none !important; border-radius: 12px !important; transition: 0.2s; padding: 10px 16px !important; }
    .accordion-button:hover { background-color: #f8fafc !important; color: #4b0082; }
    .accordion-button:not(.collapsed) { color: #4b0082; background-color: #f8fafc !important; font-weight: 600; }
    .accordion-button::after { background-size: 0.75rem; width: 0.75rem; height: 0.75rem; }
    .accordion-body { padding: 4px 0 4px 12px !important; }
    .accordion-body .nav-link { color: #718096 !important; font-weight: 500; padding: 8px 16px !important; font-size: 0.8rem !important; border-radius: 10px !important; transition: 0.2s; margin-bottom: 4px; }
    .accordion-body .nav-link:hover { color: #4b0082 !important; background-color: rgba(75,0,130,0.05) !important; transform: translateX(3px); }
    .accordion-body .nav-link.active { background: linear-gradient(135deg, #4b0082 0%, #6a1b9a 100%) !important; color: #ffffff !important; font-weight: 600 !important; box-shadow: 0 4px 10px rgba(75,0,130,0.2) !important; }

    .avatar-circle { width: 65px; height: 65px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
    .text-purple { color: #4b0082; }
    .text-gold { color: #c5a028; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .bg-gold-subtle { background-color: #fcf4d9; }
    .cursor-pointer { cursor: pointer; }
    
    /* Logout Modal Styles */
    .logout-card { border-radius: 24px !important; overflow: hidden; }
    #logoutModal .modal-backdrop, #appCenterModal .modal-backdrop { background-color: rgba(30, 41, 59, 0.4); backdrop-filter: blur(4px); }
    .logout-icon-wrapper { width: 80px; height: 80px; background-color: #fee2e2; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
</style>