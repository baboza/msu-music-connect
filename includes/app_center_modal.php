<?php
global $user_bookmarks, $management_roles, $modules;

if (!isset($user_bookmarks) || !is_array($user_bookmarks)) {
    $user_bookmarks = [];
}

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT app_id FROM user_bookmarks WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_bookmarks = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        $user_bookmarks = [];
    }
}

if (!isset($management_roles) || !is_array($management_roles)) {
    $management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'งานแผน', 'ผู้ช่วยคณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'คณบดี', 'รักษาการคณบดี', 'งานพัสดุ', 'งานอาคารสถานที่', 'งานพัฒนานิสิต', 'รองคณบดีฝ่ายพัฒนานิสิต', 'โสตทัศนูปกรณ์', 'งานวิชาการ', 'งานบริการการศึกษา'];
}

if (!isset($executive_roles) || !is_array($executive_roles)) {
    $executive_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'คณบดี', 'รักษาการคณบดี', 'งานวิชาการ', 'งานบริการการศึกษา'];
}

if (!isset($modules) || !is_array($modules)) {
    $modules = $_SESSION['system_modules'] ?? [];
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

if (!function_exists('isBookmarked')) {
    function isBookmarked($app_id) {
        global $user_bookmarks;
        return is_array($user_bookmarks) && in_array($app_id, $user_bookmarks);
    }
}

if (!function_exists('getStarIcon')) {
    function getStarIcon($app_id) {
        if (isBookmarked($app_id)) {
            return '<button class="btn btn-link p-0 position-absolute top-0 end-0 m-3 text-warning btn-bookmark" data-app-id="'.$app_id.'" style="z-index: 10;"><i class="fa-solid fa-star fs-5"></i></button>';
        } else {
            return '<button class="btn btn-link p-0 position-absolute top-0 end-0 m-3 text-secondary opacity-25 btn-bookmark hover-star" data-app-id="'.$app_id.'" style="z-index: 10;"><i class="fa-regular fa-star fs-5"></i></button>';
        }
    }
}
?>
<style>
.hover-star:hover { opacity: 1 !important; color: #f59e0b !important; }
.app-item { position: relative; }
.app-card {
    padding-right: 54px !important;
    min-height: 78px;
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    background-color: #ffffff;
    transition: all 0.18s ease;
}
.app-card:hover {
    border-color: #cbd5e1 !important;
    box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.07) !important;
    transform: translateY(-2px);
}
.w-20px { width: 22px; text-align: center; }

/* Enterprise App Launcher Vertical Tabs */
#appCenterTabs .nav-link {
    color: #475569 !important;
    border: 1px solid transparent !important;
    box-shadow: none !important;
    background-color: transparent !important;
    font-weight: 600 !important;
    font-size: 0.84rem !important;
    border-radius: 8px !important;
    padding: 9px 12px !important;
    transition: all 0.15s ease;
}
#appCenterTabs .nav-link:hover {
    background-color: #ffffff !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
}
#appCenterTabs .nav-link.active {
    background-color: #39048f !important;
    border-color: #39048f !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(57, 4, 143, 0.2) !important;
}
#appCenterTabs .nav-link.active i {
    color: #ffffff !important;
}
</style>

<!-- แอปพลิเคชันและระบบงานหลัก (Enterprise App Center) -->
<div class="modal fade" id="appCenterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-lg-down" style="max-width: 1280px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; background-color: #f8fafc; border: 1px solid #cbd5e1 !important; overflow: hidden;">
            
            <div class="modal-header border-bottom bg-white py-3 px-4 d-flex flex-column align-items-start">
                <div class="d-flex justify-content-between align-items-center w-100 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-2 bg-purple-subtle text-purple" style="width: 36px; height: 36px;">
                            <i class="fa-solid fa-grip"></i>
                        </span>
                        <div>
                            <h5 class="fw-bold text-dark mb-0">Enterprise Application Launcher (ศูนย์รวมระบบงานทั้งหมด)</h5>
                            <p class="text-muted extra-small mb-0">เลือกโมดูลปฏิบัติการตามสายงาน หรือปักหมุดระบบที่ใช้ประจำไว้ในรายการโปรด</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="w-100 position-relative mt-2">
                    <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left: 14px; top: 50%; transform: translateY(-50%); z-index: 10; font-size: 0.85rem;"></i>
                    <input type="text" id="appCenterSearch" class="form-control bg-light ps-5" style="padding-left: 38px !important; border-radius: 8px !important;" placeholder="พิมพ์ค้นหาชื่อระบบงาน... (เช่น ลางาน, ไปราชการ, แจ้งซ่อม, เบิกพัสดุ, ห้องซ้อม, โครงการ)" autocomplete="off">
                </div>
            </div>
            
            <div class="modal-body p-0 bg-white">
                <div class="row g-0 h-100">
                    <!-- Tabs Navigation Sidebar -->
                    <div class="col-md-4 col-lg-3 bg-light p-3 border-end" style="border-color: #e2e8f0 !important;">
                        <div class="text-muted fw-bold mb-2 px-2 text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.06em;">หมวดหมู่ระบบงาน (MODULES)</div>
                        <ul class="nav nav-pills flex-column gap-1" id="appCenterTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active text-start w-100" id="fav-tab" data-bs-toggle="tab" data-bs-target="#fav-pane" type="button" role="tab" aria-controls="fav-pane" aria-selected="true"><i class="fa-solid fa-star me-2 text-warning w-20px text-center"></i>รายการโปรด (Pinned)</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-start w-100" id="hr-tab" data-bs-toggle="tab" data-bs-target="#hr-pane" type="button" role="tab" aria-controls="hr-pane" aria-selected="false"><i class="fa-solid fa-users me-2 text-primary w-20px text-center"></i>บริหารงานบุคคล (HRM)</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-start w-100" id="policy-tab" data-bs-toggle="tab" data-bs-target="#policy-pane" type="button" role="tab" aria-controls="policy-pane" aria-selected="false"><i class="fa-solid fa-chart-pie me-2 text-success w-20px text-center"></i>นโยบาย แผน และคลัง</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-start w-100" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic-pane" type="button" role="tab" aria-controls="academic-pane" aria-selected="false"><i class="fa-solid fa-graduation-cap me-2 text-info w-20px text-center"></i>วิชาการและพัฒนานิสิต</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-start w-100" id="facility-tab" data-bs-toggle="tab" data-bs-target="#facility-pane" type="button" role="tab" aria-controls="facility-pane" aria-selected="false"><i class="fa-solid fa-building me-2 text-secondary w-20px text-center"></i>โสตฯ และอาคารสถานที่</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-start w-100" id="culture-tab" data-bs-toggle="tab" data-bs-target="#culture-pane" type="button" role="tab" aria-controls="culture-pane" aria-selected="false"><i class="fa-solid fa-masks-theater me-2 text-danger w-20px text-center"></i>ทำนุและบริการวิชาการ</button>
                            </li>
                            <?php if (hasRole($management_roles)): ?>
                            <li class="nav-item mt-2 pt-2 border-top" role="presentation">
                                <button class="nav-link text-purple bg-purple-subtle text-start w-100" id="erp-tab" data-bs-toggle="tab" data-bs-target="#erp-pane" type="button" role="tab" aria-controls="erp-pane" aria-selected="false"><i class="fa-solid fa-chess-king me-2 w-20px text-center"></i>สำหรับผู้บริหารและแอดมิน</button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Tabs Content -->
                    <div class="col-md-8 col-lg-9 p-4" style="max-height: 65vh; overflow-y: auto;">
                        <div class="tab-content" id="appCenterTabsContent">
                            
                            <!-- TAB 0: รายการโปรด (Bookmarks) -->
                            <div class="tab-pane fade show active" id="fav-pane" role="tabpanel" aria-labelledby="fav-tab" tabindex="0">
                                <div class="row g-3" id="bookmarksContainer">
                                    <!-- Bookmarked items will be cloned here via JS -->
                                    <div class="col-12 text-center py-5 text-muted" id="noBookmarksMsg">
                                        <i class="fa-regular fa-star fa-3x mb-3 text-light"></i>
                                        <h5>ยังไม่มีรายการโปรด</h5>
                                        <p class="small mb-0">คลิกที่ไอคอนดาวที่มุมของการ์ดเพื่อเพิ่มระบบงานที่ใช้บ่อยไว้ที่นี่</p>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 1: งานบริหารงานบุคคล -->
                            <div class="tab-pane fade" id="hr-pane" role="tabpanel" aria-labelledby="hr-tab" tabindex="0">
                                <div class="row g-3">
                                    <?php if($modules['module_cv']): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_cv'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/cv/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-file-lines"></i></div>
                                            <div class="fw-bold text-dark small">สร้างประวัติ CV</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('tor'); ?>
                                        <a href="https://music.msu.ac.th/workload/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-list-check"></i></div>
                                            <div class="fw-bold text-dark small">ระบบ TOR</div>
                                        </a>
                                    </div>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_meeting'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/meetings/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-handshake"></i></div>
                                            <div class="fw-bold text-dark small">ระบบการประชุม</div>
                                        </a>
                                    </div>

                                    <?php if(!empty($modules['module_leave'])): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_leave'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/leave/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-clock"></i></div>
                                            <div class="fw-bold text-dark small">ระบบลา</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(!empty($modules['module_training'])): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_training'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/trainings/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-plane-departure"></i></div>
                                            <div class="fw-bold text-dark small">รายงานสัมมนา/อบรม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <a href="<?php echo $base_url; ?>/modules/pr/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-tv"></i></div>
                                            <div>
                                                <div class="fw-bold text-dark small">จอประชาสัมพันธ์</div>
                                                <div class="text-muted extra-small">Digital Signage & Kiosk 3 ภาษา</div>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 2: งานนโยบายแผนและคลัง -->
                            <div class="tab-pane fade" id="policy-pane" role="tabpanel" aria-labelledby="policy-tab" tabindex="0">
                                <div class="row g-3">
                                    <?php if(!empty($modules['module_projects']) && (!isset($project_plan_roles) || hasRole($project_plan_roles))): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_projects'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/projects/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-diagram-project"></i></div>
                                            <div class="fw-bold text-dark small">ระบบโครงการ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(!empty($modules['module_km'])): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_km'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/km/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-brain"></i></div>
                                            <div class="fw-bold text-dark small">คลังความรู้ (KM)</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(!empty($modules['module_finance']) && (!isset($finance_roles) || hasRole($finance_roles))): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_finance'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/finance/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-money-bill-transfer"></i></div>
                                            <div class="fw-bold text-dark small">สถานะการเงิน</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(!empty($modules['module_supplies']) && (!isset($supply_head_roles) || hasRole($supply_head_roles))): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('supply_head_approve'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/supplies/head_approve.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-stamp"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติใบเบิกพัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_supplies'] && hasRole($supply_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('supply_issue_items'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/supplies/issue_supplies.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-dolly"></i></div>
                                            <div class="fw-bold text-dark small">จ่ายพัสดุ / ตัดสต็อก</div>
                                        </a>
                                    </div>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_supplies'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/supplies/manage_items.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-boxes-stacked"></i></div>
                                            <div class="fw-bold text-dark small">จัดการคลังวัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- TAB 3: งานวิชาการและงานพัฒนานิสิต -->
                            <div class="tab-pane fade" id="academic-pane" role="tabpanel" aria-labelledby="academic-tab" tabindex="0">
                                <div class="row g-3">
                                    <?php if (!empty($is_dynamic_dept_head) || !isset($academic_course_roles) || hasRole($academic_course_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('manage_open_course'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/open_course/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-book-open-reader"></i></div>
                                            <div class="fw-bold text-dark small">การจัดการขอเปิดรายวิชา</div>
                                        </a>
                                    </div>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('manage_reserve_seat'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/reserve_seat/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-chair"></i></div>
                                            <div class="fw-bold text-dark small">การจัดการคำร้องสำรองที่นั่ง</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($is_dynamic_dept_head) || !isset($academic_exec_roles) || hasRole($academic_exec_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_course_requests'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/open_course/dashboard_executive.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-chart-pie"></i></div>
                                            <div class="fw-bold text-dark small">แดชบอร์ดสำรองที่นั่ง & ขอเปิดวิชา</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($is_dynamic_dept_head) || !isset($academic_course_roles) || hasRole($academic_course_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('grade_appeal'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/grade_appeal/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-scale-balanced"></i></div>
                                            <div class="fw-bold text-dark small">การอุทธรณ์คะแนน</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('research'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/research/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-book-journal-whills"></i></div>
                                            <div class="fw-bold text-dark small">ฐานข้อมูลวิจัย</div>
                                        </a>
                                    </div>

                                    <?php if (hasRole($research_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('research_grants'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/research_grants/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-list-check"></i></div>
                                            <div class="fw-bold text-dark small">จัดการทุนวิจัยทั้งหมด</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('exam_online'); ?>
                                        <a href="https://music.msu.ac.th/exam/" target="_blank" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-laptop-code"></i></div>
                                            <div class="fw-bold text-dark small">ระบบสอบออนไลน์</div>
                                        </a>
                                    </div>


                                    <?php if (hasRole($klof_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('klof'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/klof/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-file-signature"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติชั่วโมง กยศ.</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_cv']): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('tqf'); ?>
                                        <a href="https://music.msu.ac.th/TQF/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-pen"></i></div>
                                            <div class="fw-bold text-dark small">ระบบ มคอ.3/5</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('grad'); ?>
                                        <a href="https://music.msu.ac.th/grad" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-user-graduate"></i></div>
                                            <div class="fw-bold text-dark small">งานบัณฑิต</div>
                                        </a>
                                    </div>
                                    
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('chinese_cert'); ?>
                                        <a href="https://music.msu.ac.th/music-cert/index.php" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-id-card"></i></div>
                                            <div class="fw-bold text-dark small">ใบรับรองนิสิตจีน</div>
                                        </a>
                                    </div>
                                    
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('coop'); ?>
                                        <a href="https://music.msu.ac.th/COOP" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-handshake"></i></div>
                                            <div class="fw-bold text-dark small">MSU Music COOP</div>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 4: งานโสตและอาคารสถานที่ -->
                            <div class="tab-pane fade" id="facility-pane" role="tabpanel" aria-labelledby="facility-tab" tabindex="0">
                                <div class="row g-3">
                                    <?php if($modules['module_room_booking'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_room_booking'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/room_booking/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-calendar-check"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติจองห้อง</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_repair_request'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_repair_request'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/repair_request/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-tools"></i></div>
                                            <div class="fw-bold text-dark small">อนุมัติแจ้งซ่อม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_rooms'] && hasRole($building_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_rooms'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/rooms/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-building-circle-check"></i></div>
                                            <div class="fw-bold text-dark small">จัดการข้อมูลห้อง / สถานที่</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole($practice_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('practice_rooms'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/practice_rooms/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-music"></i></div>
                                            <div class="fw-bold text-dark small">จัดการห้องซ้อมดนตรี</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- TAB: งานทำนุและบริการวิชาการ -->
                            <div class="tab-pane fade" id="culture-pane" role="tabpanel" aria-labelledby="culture-tab" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('music_rights'); ?>
                                        <a href="https://music.msu.ac.th/Rights/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-copyright"></i></div>
                                            <div class="fw-bold text-dark small">Music Rights</div>
                                        </a>
                                    </div>
                                    
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('msas'); ?>
                                        <a href="https://music.msu.ac.th/MSAS" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-microphone"></i></div>
                                            <div class="fw-bold text-dark small">ทดสอบทักษะทางดนตรี</div>
                                        </a>
                                    </div>
                                    
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('mimc'); ?>
                                        <a href="https://music.msu.ac.th/MIMC2026/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-trophy"></i></div>
                                            <div class="fw-bold text-dark small">MIMC</div>
                                        </a>
                                    </div>
                                    
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('conference'); ?>
                                        <a href="https://music.msu.ac.th/conf/" target="_blank" rel="noopener noreferrer" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-chalkboard-user"></i></div>
                                            <div class="fw-bold text-dark small">Conference</div>
                                        </a>
                                    </div>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_qrcode'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/qrcode/index.php" class="app-card text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-qrcode"></i></div>
                                            <div class="fw-bold text-dark small">สร้างและจัดเก็บ QR Code</div>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 5: สำหรับผู้บริหารและหัวหน้างาน (ERP & Analytics) -->
                            <?php if (hasRole($management_roles)): ?>
                            <div class="tab-pane fade" id="erp-pane" role="tabpanel" aria-labelledby="erp-tab" tabindex="0">
                                <div class="row g-3">
                                    <?php if (hasRole($executive_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('executive_dashboard'); ?>
                                        <a href="<?php echo $base_url; ?>/executive_dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm h-100" style="border: 1px solid #dc3545 !important;">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-chess-king"></i></div>
                                            <div class="fw-bold text-dark small">Executive Dashboard</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_course_requests'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/open_course/dashboard_executive.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-chart-pie"></i></div>
                                            <div class="fw-bold text-dark small">ติดตามสำรองที่นั่ง & ขอเปิดวิชา</div>
                                        </a>
                                    </div>

                                    <?php if(!empty($modules['module_leave']) && (!empty($is_dynamic_dept_head) || !isset($leave_approver_roles) || hasRole($leave_approver_roles))): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_leave_approve'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/leave/approve_list.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-file-circle-check"></i></div>
                                            <div class="fw-bold text-dark small">พิจารณาอนุมัติใบลา</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(!empty($is_dynamic_dept_head) || !isset($training_approver_roles) || hasRole($training_approver_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_training_approve'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/trainings/approve_list.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-clipboard-check"></i></div>
                                            <div class="fw-bold text-dark small">พิจารณาไปราชการ/อบรม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole(['admin', 'คณบดี', 'รักษาการคณบดี', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายพัฒนานิสิต'])): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_complaint'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/complaint/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-bullhorn"></i></div>
                                            <div class="fw-bold text-dark small">จัดการเรื่องร้องเรียน</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_room_booking'] && hasRole($management_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_room_booking'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/room_booking/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-chart-area"></i></div>
                                            <div class="fw-bold text-dark small">สถิติภาพรวมจองห้อง</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_repair_request'] && hasRole($management_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_repair_request'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/repair_request/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-chart-column"></i></div>
                                            <div class="fw-bold text-dark small">สถิติภาพรวมแจ้งซ่อม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_leave']): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_leave'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/leave/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-chart-line"></i></div>
                                            <div class="fw-bold text-dark small">สถิติระบบการลา</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_worklog']): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_worklog'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/worklog/admin_dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-clipboard-list"></i></div>
                                            <div class="fw-bold text-dark small">สถิติการทำงาน (Coda)</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_training']): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_training'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/trainings/dashboard_admin.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-plane-departure"></i></div>
                                            <div class="fw-bold text-dark small">สถิติการไปราชการ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_supplies'] && hasRole($supply_report_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('report_supplies'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/supplies/report.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-chart-bar"></i></div>
                                            <div class="fw-bold text-dark small">ภาพรวมเบิกพัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if($modules['module_procurement'] && hasRole($procurement_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('module_procurement'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/procurement/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-truck-fast"></i></div>
                                            <div class="fw-bold text-dark small">ระบบติดตามงานพัสดุ</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (hasRole($klof_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('report_klof'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/klof/summary.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-warning-subtle text-warning me-3"><i class="fa-solid fa-chart-pie"></i></div>
                                            <div class="fw-bold text-dark small">ภาพรวมชั่วโมง กยศ.</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (hasRole($research_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_research_grants'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/research_grants/dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-chart-pie"></i></div>
                                            <div class="fw-bold text-dark small">แดชบอร์ดทุนวิจัย</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole($practice_admin_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_practice_rooms'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/practice_rooms/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-music"></i></div>
                                            <div class="fw-bold text-dark small">ภาพรวมห้องซ้อม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'])): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_attendance'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/attendance/index.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-info-subtle text-info me-3"><i class="fa-solid fa-clock-rotate-left"></i></div>
                                            <div class="fw-bold text-dark small">แดชบอร์ดการเข้างาน</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if(hasRole($management_roles)): ?>
                                    <div class="col-sm-6 col-lg-4 app-item">
                                        <?php echo getStarIcon('dashboard_qrcode'); ?>
                                        <a href="<?php echo $base_url; ?>/modules/qrcode/dashboard.php" class="app-card bg-white text-decoration-none d-flex align-items-center p-3 rounded-4 shadow-sm border bg-white h-100">
                                            <div class="app-icon bg-purple-subtle text-purple me-3"><i class="fa-solid fa-qrcode"></i></div>
                                            <div class="fw-bold text-dark small">แดชบอร์ด QR Code รวม</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (hasRole(['admin'])): ?>
                                    <div class="col-12 mt-4 app-item admin-section">
                                        <h6 class="fw-bold text-danger mb-3 small"><i class="fa-solid fa-screwdriver-wrench me-1"></i> ผู้ดูแลระบบ (Super Admin)</h6>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a href="<?php echo $base_url; ?>/admin/users.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 py-2 fw-bold text-secondary"><i class="fa-solid fa-users me-1 text-danger"></i> จัดการผู้ใช้</a>
                                            <a href="<?php echo $base_url; ?>/admin/students.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 py-2 fw-bold text-secondary"><i class="fa-solid fa-user-graduate me-1 text-danger"></i> จัดการนิสิต</a>
                                            <a href="<?php echo $base_url; ?>/admin/settings.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 py-2 fw-bold text-secondary"><i class="fa-solid fa-gear me-1 text-danger"></i> ตั้งค่าทั่วไป</a>
                                            <a href="<?php echo $base_url; ?>/admin/login_logs.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 py-2 fw-bold text-secondary"><i class="fa-solid fa-clock-rotate-left me-1 text-danger"></i> ประวัติการใช้งาน</a>
                                            <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="btn btn-sm btn-white border shadow-sm rounded-pill px-3 py-2 fw-bold text-secondary"><i class="fa-solid fa-bell me-1 text-danger"></i> ส่งแจ้งเตือนนิสิต</a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const appModal = document.getElementById('appCenterModal');
    if (!appModal) return;

    // --- Bookmarks Logic ---
    const bookmarksContainer = document.getElementById('bookmarksContainer');
    const noBookmarksMsg = document.getElementById('noBookmarksMsg');
    
    function refreshBookmarksTab() {
        // Clear existing cloned items
        const existingClones = bookmarksContainer.querySelectorAll('.app-item.cloned-bookmark');
        existingClones.forEach(clone => clone.remove());
        
        // Find all starred items across all OTHER tabs
        let found = false;
        const allItems = appModal.querySelectorAll('.tab-pane:not(#fav-pane) .app-item');
        
        allItems.forEach(item => {
            const starBtn = item.querySelector('.btn-bookmark');
            if (starBtn && starBtn.classList.contains('text-warning')) {
                // Clone the item
                const clone = item.cloneNode(true);
                clone.classList.add('cloned-bookmark');
                
                // Add event listener to the clone's star button
                const cloneStar = clone.querySelector('.btn-bookmark');
                cloneStar.addEventListener('click', function(e) {
                    toggleBookmark(this.getAttribute('data-app-id'));
                });
                
                bookmarksContainer.appendChild(clone);
                found = true;
            }
        });
        
        if (found) {
            noBookmarksMsg.style.display = 'none';
        } else {
            noBookmarksMsg.style.display = 'block';
        }
    }

    function toggleBookmark(appId) {
        // Find all star buttons for this appId (both in normal tabs and in the fav-pane)
        const buttons = document.querySelectorAll(`.btn-bookmark[data-app-id="${appId}"]`);
        
        if (buttons.length === 0) return;
        
        // Determine current state based on the first button found
        const isCurrentlyBookmarked = buttons[0].classList.contains('text-warning');
        const action = isCurrentlyBookmarked ? 'remove' : 'add';
        
        // Update UI immediately for snappiness
        buttons.forEach(btn => {
            const icon = btn.querySelector('i');
            if (isCurrentlyBookmarked) {
                // Remove bookmark
                btn.classList.remove('text-warning');
                btn.classList.add('text-secondary', 'opacity-25', 'hover-star');
                icon.classList.remove('fa-solid');
                icon.classList.add('fa-regular');
            } else {
                // Add bookmark
                btn.classList.add('text-warning');
                btn.classList.remove('text-secondary', 'opacity-25', 'hover-star');
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
            }
        });
        
        // Refresh the Bookmarks tab content
        refreshBookmarksTab();
        
        // Send AJAX request
        const formData = new FormData();
        formData.append('app_id', appId);
        formData.append('action', action);
        
        fetch('<?php echo $base_url; ?>/api/toggle_bookmark.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                console.error('Bookmark error:', data.message);
                // Optionally revert UI here on failure
            }
        })
        .catch(err => console.error('Fetch error:', err));
    }

    // Attach click events to all original star buttons
    const starButtons = appModal.querySelectorAll('.tab-pane:not(#fav-pane) .btn-bookmark');
    starButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleBookmark(this.getAttribute('data-app-id'));
        });
    });

    // Initial population of Bookmarks tab
    appModal.addEventListener('show.bs.modal', function () {
        refreshBookmarksTab();
    });
});
</script>

