<div class="modal fade" id="appCenterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px; background-color: #f8fafc; overflow: hidden;">
            
            <div class="modal-header border-0 pb-3 pt-4 px-4 bg-white d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-layer-group text-purple me-2"></i>แอปพลิเคชันและระบบงานหลัก</h4>
                    <p class="text-muted small mb-0">ค้นหาและเลือกบริการหรือระบบที่ต้องการใช้งาน</p>
                </div>
                <div class="d-flex align-items-center gap-3 w-100" style="max-width: 400px;">
                    <div class="position-relative w-100">
                        <i class="fa-solid fa-search position-absolute text-muted" style="top: 50%; left: 15px; transform: translateY(-50%);"></i>
                        <input type="text" id="appSearchInput" class="form-control rounded-pill border bg-light shadow-sm ps-5 py-2" placeholder="ค้นหาแอปพลิเคชัน..." autocomplete="off">
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle shadow-sm flex-shrink-0" data-bs-dismiss="modal" aria-label="Close" style="padding: 10px;"></button>
                </div>
            </div>
            
            <div class="modal-body p-0 d-flex flex-column flex-lg-row" style="height: 65vh; min-height: 500px;">
                
                <!-- Left Sidebar (Categories) -->
                <div class="bg-white border-end px-3 py-4" style="min-width: 250px; overflow-y: auto;">
                    <div class="nav flex-column nav-pills custom-app-tabs gap-2" id="app-category-tabs">
                        <button class="nav-link active text-start rounded-4 fw-bold p-3" data-filter="all">
                            <i class="fa-solid fa-border-all me-2 w-20px text-center"></i> ทั้งหมด
                        </button>
                        <button class="nav-link text-start rounded-4 fw-bold p-3" data-filter="core">
                            <i class="fa-solid fa-briefcase me-2 w-20px text-center"></i> ระบบงานหลัก
                        </button>
                        <?php if (hasRole($management_roles)): ?>
                        <button class="nav-link text-start rounded-4 fw-bold p-3" data-filter="erp">
                            <i class="fa-solid fa-chart-pie me-2 w-20px text-center"></i> สำหรับผู้บริหาร
                        </button>
                        <?php endif; ?>
                        <?php if (hasRole(['admin'])): ?>
                        <button class="nav-link text-start rounded-4 fw-bold p-3" data-filter="admin">
                            <i class="fa-solid fa-screwdriver-wrench me-2 w-20px text-center"></i> ผู้ดูแลระบบ
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right Content (Grid) -->
                <div class="flex-grow-1 p-4 bg-light overflow-auto" style="height: 100%;">
                    <!-- App Grid -->
                    <div class="row g-3" id="app-grid-container">
                        
                        <!-- ========================= CORE ========================= -->
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ระบบ tor">
                            <a href="https://music.msu.ac.th/workload/" target="_blank" rel="noopener noreferrer" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-primary-subtle text-primary mb-3"><i class="fa-solid fa-list-check"></i></div>
                                <div class="fw-bold text-dark text-center small">ระบบ TOR</div>
                            </a>
                        </div>
                        
                        <?php if($modules['module_meeting']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ระบบการประชุม">
                            <a href="<?php echo $base_url; ?>/modules/meetings/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-success-subtle text-success mb-3"><i class="fa-solid fa-handshake"></i></div>
                                <div class="fw-bold text-dark text-center small">ระบบการประชุม</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ฐานข้อมูลวิจัย">
                            <a href="<?php echo $base_url; ?>/modules/research/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-info-subtle text-info mb-3"><i class="fa-solid fa-book-journal-whills"></i></div>
                                <div class="fw-bold text-dark text-center small">ฐานข้อมูลวิจัย</div>
                            </a>
                        </div>

                        <?php if($modules['module_projects']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ระบบโครงการ">
                            <a href="<?php echo $base_url; ?>/modules/projects/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-danger-subtle text-danger mb-3"><i class="fa-solid fa-diagram-project"></i></div>
                                <div class="fw-bold text-dark text-center small">ระบบโครงการ</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if($modules['module_km']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="คลังความรู้ (km)">
                            <a href="<?php echo $base_url; ?>/modules/km/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-warning-subtle text-warning mb-3"><i class="fa-solid fa-brain"></i></div>
                                <div class="fw-bold text-dark text-center small">คลังความรู้ (KM)</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ระบบสอบออนไลน์">
                            <a href="https://music.msu.ac.th/exam/" target="_blank" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg mb-3" style="color: #6366f1; background-color: #e0e7ff;"><i class="fa-solid fa-laptop-code"></i></div>
                                <div class="fw-bold text-dark text-center small">ระบบสอบออนไลน์</div>
                            </a>
                        </div>

                        <?php if($modules['module_cv']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="สร้างประวัติ cv">
                            <a href="<?php echo $base_url; ?>/modules/cv/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-secondary bg-opacity-10 text-secondary mb-3"><i class="fa-solid fa-file-lines"></i></div>
                                <div class="fw-bold text-dark text-center small">สร้างประวัติ CV</div>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if($modules['module_finance']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="สถานะการเงิน">
                            <a href="<?php echo $base_url; ?>/modules/finance/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-danger-subtle text-danger mb-3"><i class="fa-solid fa-money-bill-transfer"></i></div>
                                <div class="fw-bold text-dark text-center small">สถานะการเงิน</div>
                            </a>
                        </div>
                        <?php endif; ?>



                        <?php if($modules['module_supplies'] && hasRole($supply_admin_roles)): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="จัดการคลังวัสดุ">
                            <a href="<?php echo $base_url; ?>/modules/supplies/manage_items.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-primary text-white mb-3"><i class="fa-solid fa-boxes-stacked"></i></div>
                                <div class="fw-bold text-dark text-center small">จัดการคลังวัสดุ</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (hasRole($klof_admin_roles)): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="อนุมัติชั่วโมง กยศ.">
                            <a href="<?php echo $base_url; ?>/modules/klof/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-purple-subtle text-purple mb-3"><i class="fa-solid fa-file-signature"></i></div>
                                <div class="fw-bold text-dark text-center small">อนุมัติชั่วโมง กยศ.</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if($modules['module_cv']): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ระบบ มคอ.3/5">
                            <a href="https://music.msu.ac.th/TQF/" target="_blank" rel="noopener noreferrer" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-info-subtle text-info mb-3"><i class="fa-solid fa-pen"></i></div>
                                <div class="fw-bold text-dark text-center small">ระบบ มคอ.3/5</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="ใบรับรองนิสิตจีน">
                            <a href="https://music.msu.ac.th/music-cert/index.php" target="_blank" rel="noopener noreferrer" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-danger-subtle text-danger mb-3"><i class="fa-solid fa-id-card"></i></div>
                                <div class="fw-bold text-dark text-center small">ใบรับรองนิสิตจีน</div>
                            </a>
                        </div>

                        <?php if($modules['module_room_booking'] && hasRole($building_admin_roles)): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="อนุมัติจองห้อง">
                            <a href="<?php echo $base_url; ?>/modules/room_booking/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-info-subtle text-info mb-3"><i class="fa-solid fa-calendar-check"></i></div>
                                <div class="fw-bold text-dark text-center small">อนุมัติจองห้อง</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if($modules['module_repair_request'] && hasRole($building_admin_roles)): ?>
                        <div class="col-6 col-md-4 col-xl-3 app-item" data-category="core" data-name="อนุมัติแจ้งซ่อม">
                            <a href="<?php echo $base_url; ?>/modules/repair_request/index.php" class="app-square-card text-decoration-none">
                                <div class="app-icon-lg bg-warning-subtle text-warning mb-3"><i class="fa-solid fa-tools"></i></div>
                                <div class="fw-bold text-dark text-center small">อนุมัติแจ้งซ่อม</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <!-- ========================= ERP / MANAGEMENT ========================= -->
                        <?php if (hasRole($management_roles)): ?>
                            <?php if (hasRole($executive_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="executive dashboard">
                                <a href="<?php echo $base_url; ?>/executive_dashboard.php" class="app-square-card text-decoration-none border-dark">
                                    <div class="app-icon-lg bg-danger-subtle text-danger mb-3"><i class="fa-solid fa-chess-king"></i></div>
                                    <div class="fw-bold text-dark text-center small">Executive Dash</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_rooms'] && hasRole($building_admin_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="จัดการข้อมูลห้อง สถานที่">
                                <a href="<?php echo $base_url; ?>/modules/rooms/index.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-info-subtle text-info mb-3"><i class="fa-solid fa-building-circle-check"></i></div>
                                    <div class="fw-bold text-dark text-center small">จัดการข้อมูลห้อง</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if(hasRole($practice_admin_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="จัดการห้องซ้อมดนตรี">
                                <a href="<?php echo $base_url; ?>/modules/practice_rooms/index.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg mb-3" style="background-color: #fce7f3; color: #ec4899;"><i class="fa-solid fa-music"></i></div>
                                    <div class="fw-bold text-dark text-center small">จัดการห้องซ้อมดนตรี</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_room_booking'] && hasRole($management_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="สถิติภาพรวมจองห้อง">
                                <a href="<?php echo $base_url; ?>/modules/room_booking/dashboard_admin.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-success-subtle text-success mb-3"><i class="fa-solid fa-chart-area"></i></div>
                                    <div class="fw-bold text-dark text-center small">สถิติจองห้อง</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_repair_request'] && hasRole($management_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="สถิติภาพรวมแจ้งซ่อม">
                                <a href="<?php echo $base_url; ?>/modules/repair_request/dashboard_admin.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-warning-subtle text-warning mb-3"><i class="fa-solid fa-chart-column"></i></div>
                                    <div class="fw-bold text-dark text-center small">สถิติแจ้งซ่อม</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_leave']): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="สถิติระบบการลา">
                                <a href="<?php echo $base_url; ?>/modules/leave/dashboard_admin.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-warning-subtle text-dark mb-3"><i class="fa-solid fa-chart-line"></i></div>
                                    <div class="fw-bold text-dark text-center small">สถิติระบบการลา</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_worklog']): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="สถิติการทำงาน coda">
                                <a href="<?php echo $base_url; ?>/modules/worklog/admin_dashboard.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-purple-subtle text-purple mb-3"><i class="fa-solid fa-clipboard-list"></i></div>
                                    <div class="fw-bold text-dark text-center small">สถิติการทำงาน</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_training']): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="สถิติการไปราชการ">
                                <a href="<?php echo $base_url; ?>/modules/trainings/dashboard_admin.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-primary-subtle text-primary mb-3"><i class="fa-solid fa-plane-departure"></i></div>
                                    <div class="fw-bold text-dark text-center small">สถิติไปราชการ</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_supplies'] && hasRole($supply_report_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="ภาพรวมเบิกพัสดุ">
                                <a href="<?php echo $base_url; ?>/modules/supplies/report.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-success-subtle text-success mb-3"><i class="fa-solid fa-chart-bar"></i></div>
                                    <div class="fw-bold text-dark text-center small">ภาพรวมเบิกพัสดุ</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($modules['module_procurement'] && hasRole($procurement_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="ระบบติดตามงานพัสดุ ติดตามพัสดุ">
                                <a href="<?php echo $base_url; ?>/modules/procurement/index.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-info-subtle text-info mb-3"><i class="fa-solid fa-truck-fast"></i></div>
                                    <div class="fw-bold text-dark text-center small">ระบบติดตามงานพัสดุ</div>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (hasRole($klof_admin_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="ภาพรวมชั่วโมง กยศ.">
                                <a href="<?php echo $base_url; ?>/modules/klof/summary.php" class="app-square-card text-decoration-none">
                                    <div class="app-icon-lg bg-purple-subtle text-purple mb-3"><i class="fa-solid fa-chart-pie"></i></div>
                                    <div class="fw-bold text-dark text-center small">ภาพรวมชั่วโมง กยศ.</div>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (hasRole($edpex_roles)): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="erp" data-name="รายงานผล edpex หมวด 7">
                                <a href="<?php echo $base_url; ?>/EdPEx.php" class="app-square-card text-decoration-none border-warning">
                                    <div class="app-icon-lg bg-gold-subtle text-gold mb-3"><i class="fa-solid fa-trophy"></i></div>
                                    <div class="fw-bold text-dark text-center small">ผล EdPEx หมวด 7</div>
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- ========================= ADMIN ========================= -->
                        <?php if (hasRole(['admin'])): ?>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="admin" data-name="จัดการผู้ใช้">
                                <a href="<?php echo $base_url; ?>/admin/users.php" class="app-square-card text-decoration-none border-danger">
                                    <div class="app-icon-lg bg-danger text-white mb-3"><i class="fa-solid fa-users"></i></div>
                                    <div class="fw-bold text-dark text-center small">จัดการผู้ใช้</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="admin" data-name="จัดการนิสิต">
                                <a href="<?php echo $base_url; ?>/admin/students.php" class="app-square-card text-decoration-none border-danger">
                                    <div class="app-icon-lg bg-danger text-white mb-3"><i class="fa-solid fa-user-graduate"></i></div>
                                    <div class="fw-bold text-dark text-center small">จัดการนิสิต</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="admin" data-name="ตั้งค่าทั่วไป">
                                <a href="<?php echo $base_url; ?>/admin/settings.php" class="app-square-card text-decoration-none border-danger">
                                    <div class="app-icon-lg bg-danger text-white mb-3"><i class="fa-solid fa-gear"></i></div>
                                    <div class="fw-bold text-dark text-center small">ตั้งค่าทั่วไป</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-xl-3 app-item" data-category="admin" data-name="ประวัติการใช้งาน">
                                <a href="<?php echo $base_url; ?>/admin/login_logs.php" class="app-square-card text-decoration-none border-danger">
                                    <div class="app-icon-lg bg-danger text-white mb-3"><i class="fa-solid fa-clock-rotate-left"></i></div>
                                    <div class="fw-bold text-dark text-center small">ประวัติการใช้งาน</div>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Empty State (Hidden by default) -->
                        <div id="app-empty-state" class="col-12 text-center py-5 d-none">
                            <i class="fa-solid fa-search fs-1 text-muted opacity-25 mb-3"></i>
                            <h5 class="text-muted fw-bold">ไม่พบแอปพลิเคชันที่ค้นหา</h5>
                            <p class="small text-muted">ลองใช้คำค้นหาอื่นดูครับ</p>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('#app-category-tabs .nav-link');
        const items = document.querySelectorAll('.app-item');
        const searchInput = document.getElementById('appSearchInput');
        const emptyState = document.getElementById('app-empty-state');
        
        let currentFilter = 'all';
        let searchQuery = '';
        
        function filterApps() {
            let visibleCount = 0;
            
            items.forEach(item => {
                const category = item.getAttribute('data-category');
                const name = item.getAttribute('data-name');
                
                const matchCategory = (currentFilter === 'all' || currentFilter === category);
                const matchSearch = name.includes(searchQuery);
                
                if (matchCategory && matchSearch) {
                    item.classList.remove('d-none');
                    visibleCount++;
                } else {
                    item.classList.add('d-none');
                }
            });
            
            if (visibleCount === 0) {
                emptyState.classList.remove('d-none');
            } else {
                emptyState.classList.add('d-none');
            }
        }
        
        // Tab Click
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                currentFilter = this.getAttribute('data-filter');
                filterApps();
            });
        });
        
        // Search Input
        if(searchInput) {
            searchInput.addEventListener('input', function(e) {
                searchQuery = e.target.value.toLowerCase().trim();
                filterApps();
            });
            
            // Clear input on modal hide
            const myModalEl = document.getElementById('appCenterModal');
            myModalEl.addEventListener('hidden.bs.modal', function () {
                searchInput.value = '';
                searchQuery = '';
                currentFilter = 'all';
                tabs.forEach(t => t.classList.remove('active'));
                document.querySelector('#app-category-tabs .nav-link[data-filter="all"]').classList.add('active');
                filterApps();
            });
        }
    });
</script>
