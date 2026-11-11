<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-white py-3 border-bottom-0">
        <h5 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-toggle-on me-2"></i>จัดการโมดูล (Module Management)</h5>
        <p class="text-muted small mb-0 mt-1">เลือกเปิด/ปิดการทำงานของแต่ละระบบ หากปิดระบบ เมนูที่เกี่ยวข้องจะหายไปจาก Sidebar ทันที</p>
    </div>
    <div class="card-body p-4 bg-light">
        
        <form action="settings_action.php" method="POST">
            
            <input type="hidden" name="action" value="update_modules">
            
            <div class="row g-3">
                
                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-file-lines text-primary me-2"></i>ประวัติและผลงาน (CV)</div>
                            <div class="extra-small text-muted mt-1">ระบบสำหรับจัดเก็บประวัติการศึกษาและผลงานของบุคลากร</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_cv]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_cv]" value="1" <?php echo (!isset($modules['module_cv']) || $modules['module_cv']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-file-pen text-success me-2"></i>ระบบการลา (Leave)</div>
                            <div class="extra-small text-muted mt-1">ระบบยื่นใบลาออนไลน์ และอนุมัติตามลำดับขั้น</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_leave]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_leave]" value="1" <?php echo (!isset($modules['module_leave']) || $modules['module_leave']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>ระบบบันทึกงาน (Work Log)</div>
                            <div class="extra-small text-muted mt-1">ระบบรายงานผลการปฏิบัติงานประจำวัน (Daily Report)</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_worklog]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_worklog]" value="1" <?php echo (!isset($modules['module_worklog']) || $modules['module_worklog']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-handshake text-info me-2"></i>ระบบการประชุม (Meeting)</div>
                            <div class="extra-small text-muted mt-1">ระบบจองห้องประชุมและศูนย์ส่งเอกสารวาระ</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_meeting]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_meeting]" value="1" <?php echo (!isset($modules['module_meeting']) || $modules['module_meeting']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-diagram-project text-purple me-2"></i>ระบบติดตามโครงการ (Projects)</div>
                            <div class="extra-small text-muted mt-1">ระบบติดตามความคืบหน้าและการเบิกจ่ายงบประมาณ</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_projects]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_projects]" value="1" <?php echo (!isset($modules['module_projects']) || $modules['module_projects']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-car-side text-danger me-2"></i>ระบบรายงานอบรม/ไปราชการ</div>
                            <div class="extra-small text-muted mt-1">ระบบส่งแบบรายงานการพัฒนาตนเองและไปราชการ</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_training]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_training]" value="1" <?php echo (!isset($modules['module_training']) || $modules['module_training']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-box-open text-success me-2"></i>ระบบเบิกจ่ายวัสดุ (Supplies)</div>
                            <div class="extra-small text-muted mt-1">ระบบเบิกวัสดุสำนักงาน, อนุมัติ และตัดสต็อกคลังพัสดุ</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_supplies]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_supplies]" value="1" <?php echo (!isset($modules['module_supplies']) || $modules['module_supplies'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-truck-fast text-primary me-2"></i>ระบบติดตามงานพัสดุ (Procurement)</div>
                            <div class="extra-small text-muted mt-1">ระบบติดตาม 10 ขั้นตอนและรายชื่อกรรมการจัดซื้อจัดจ้าง</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_procurement]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_procurement]" value="1" <?php echo (!isset($modules['module_procurement']) || $modules['module_procurement'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-money-bill-transfer text-danger me-2"></i>ติดตามสถานะการเงิน (Finance)</div>
                            <div class="extra-small text-muted mt-1">ระบบตรวจสอบสถานะเบิกจ่าย ค่าสอน และลูกหนี้เงินยืม</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_finance]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_finance]" value="1" <?php echo (!isset($modules['module_finance']) || $modules['module_finance'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-brain text-warning me-2"></i>คลังความรู้ (KM)</div>
                            <div class="extra-small text-muted mt-1">ระบบรวบรวมและแบ่งปันองค์ความรู้ตามมาตรฐาน EdPEx</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[module_km]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[module_km]" value="1" <?php echo (!isset($modules['module_km']) || $modules['module_km'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-12 mt-4 mb-2">
                    <h6 class="fw-bold text-secondary border-bottom pb-2"><i class="fa-solid fa-graduation-cap me-2"></i> ระบบบริการนิสิต (Student E-Service)</h6>
                </div>
                
                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-book-open-reader text-info me-2"></i>ระบบขอเปิดรายวิชา</div>
                            <div class="extra-small text-muted mt-1">เปิด/ปิด ให้นิสิตส่งคำร้องขอเปิดรายวิชา</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[eservice_open_course]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[eservice_open_course]" value="1" <?php echo (!isset($modules['eservice_open_course']) || $modules['eservice_open_course'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-3 bg-white border rounded-3 shadow-sm d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-chair text-warning me-2"></i>ระบบขอสำรองที่นั่ง</div>
                            <div class="extra-small text-muted mt-1">เปิด/ปิด ให้นิสิตส่งคำร้องขอสำรองที่นั่ง</div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input type="hidden" name="modules[eservice_reserve_seat]" value="0">
                            <input class="form-check-input cursor-pointer" type="checkbox" name="modules[eservice_reserve_seat]" value="1" <?php echo (!isset($modules['eservice_reserve_seat']) || $modules['eservice_reserve_seat'] == '1') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>

            </div>

            <div class="text-end mt-4 border-top pt-4">
                <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 shadow-sm fw-bold hover-lift">
                    <i class="fa-solid fa-save me-2"></i> บันทึกการตั้งค่า
                </button>
            </div>
        </form>

    </div>
</div>

<style>
    .cursor-pointer { cursor: pointer; }
    /* 💡 เอา border-color ออก */
    .form-check-input:checked { background-color: #4b0082; }
    .extra-small { font-size: 0.75rem; }
    
    .bg-white.border { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .bg-white.border:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important; }
    .hover-lift { transition: transform 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); }
</style>