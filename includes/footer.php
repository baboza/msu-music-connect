</div> </div>

<!-- Master Confirm Modal -->
<div class="modal fade" id="masterConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg master-modal-card">
            <div class="modal-body p-4 p-md-5 text-center">
                <div id="modalIconWrapper" class="modal-icon-wrapper mb-4">
                    <i id="modalIcon" class="fa-solid fa-trash-can fa-2x"></i>
                </div>
                
                <h5 id="modalTitle" class="fw-bold text-dark mb-2">ยืนยันการทำรายการ?</h5>
                <p id="modalMessage" class="text-muted small mb-4">คุณแน่ใจใช่หรือไม่ที่จะดำเนินการนี้?</p>
                
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-light px-4 py-2 fw-bold text-secondary border" data-bs-dismiss="modal">
                        ยกเลิก
                    </button>
                    <button type="button" id="confirmMasterBtn" class="btn btn-danger px-4 py-2 fw-bold shadow-sm">
                        ยืนยันการลบ
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Modal -->
<div class="modal fade" id="qrCodeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-qrcode text-purple me-2"></i>QR Code ประจำตัวบุคลากร</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <p class="text-muted small mb-3">ใช้สำหรับสแกนลงเวลาปฏิบัติงานหรือยืนยันตัวตนที่จุดบริการ</p>
                <div class="bg-light p-3 rounded-3 d-inline-block border">
                    <?php if(!empty($current_qr_token)): ?>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?php echo urlencode($current_qr_token); ?>" alt="My QR Code" class="img-fluid rounded" style="width: 220px; height: 220px;">
                    <?php else: ?>
                        <div class="text-danger p-4"><i class="fa-solid fa-triangle-exclamation mb-2 fa-2x"></i><br>ยังไม่มี QR Code กรุณาติดต่อผู้ดูแลระบบ</div>
                    <?php endif; ?>
                </div>
                <div class="mt-3 fw-bold text-dark"><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></div>
                <div class="text-muted extra-small"><?php echo !empty($_SESSION['roles']) ? htmlspecialchars($_SESSION['roles'][0]) : 'บุคลากร'; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Global Enterprise Command Palette (Ctrl + K) -->
<div class="modal fade" id="erpCommandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable" style="max-width: 620px; margin-top: 7vh;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden; border: 1px solid #cbd5e1 !important;">
            <div class="p-3 border-bottom bg-white d-flex align-items-center gap-2">
                <i class="fa-solid fa-magnifying-glass text-purple ms-2"></i>
                <input type="text" id="erpCommandInput" class="form-control border-0 shadow-none bg-transparent py-1 px-2" placeholder="พิมพ์ชื่อเมนู, คำสั่งด่วน, การอนุมัติ, ห้องซ้อม หรือชื่อระบบ..." autocomplete="off" style="font-size: 0.95rem;">
                <span class="erp-kbd me-1" style="cursor: pointer;" data-bs-dismiss="modal">ESC</span>
            </div>
            <div class="modal-body p-2 bg-white" style="max-height: 420px; overflow-y: auto;" id="erpCommandList">
                <div class="erp-cmd-group-title px-3 py-1 text-uppercase text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 0.06em;">คำสั่งด่วนและศูนย์บัญชาการ (Quick Commands)</div>
                
                <?php
                $cmd_roles = $_SESSION['roles'] ?? [];
                $cmd_is_admin = in_array('admin', $cmd_roles);
                $cmd_is_exec = $cmd_is_admin || count(array_intersect(['คณบดี', 'รักษาการคณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'หัวหน้าสำนักงาน', 'งานแผน'], $cmd_roles)) > 0;
                $cmd_is_practice = $cmd_is_admin || count(array_intersect(['หัวหน้าสำนักงาน', 'งานอาคารสถานที่', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'], $cmd_roles)) > 0;
                $cmd_is_training_app = $cmd_is_admin || count(array_intersect(['งานบุคคล', 'งานการเงิน', 'หัวหน้าสำนักงาน', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'], $cmd_roles)) > 0;
                $cmd_is_project = $cmd_is_admin || count(array_intersect(['งานแผน', 'งานการเงิน', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายพัฒนานิสิต', 'ผู้ช่วยคณบดี', 'คณบดี', 'รักษาการคณบดี'], $cmd_roles)) > 0;
                ?>

                <?php if ($cmd_is_exec): ?>
                <a href="/music-e-office/index.php?view=executive" class="erp-cmd-item" data-keywords="executive dashboard ผู้บริหาร แดชบอร์ด ภาพรวม กลยุทธ์">
                    <div class="erp-cmd-icon bg-purple-subtle text-purple"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">Executive Command Center (แดชบอร์ดผู้บริหาร)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ภาพรวมองค์กร งบประมาณ บุคลากร และโครงการ</div>
                    </div>
                    <span class="erp-cmd-shortcut">Go</span>
                </a>
                <?php endif; ?>

                <a href="/music-e-office/index.php?view=personal" class="erp-cmd-item" data-keywords="personal workspace พื้นที่ส่วนตัว หน้าแรก งานของฉัน">
                    <div class="erp-cmd-icon bg-light text-secondary"><i class="fa-solid fa-house-laptop"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">Personal Workspace (พื้นที่ทำงานส่วนตัว)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ตารางงาน วันลาคงเหลือ และสถานะคำขอของฉัน</div>
                    </div>
                    <span class="erp-cmd-shortcut">Go</span>
                </a>

                <?php if (!empty($is_any_approver) || !empty($is_header_approver) || (!empty($pending_approvals) && $pending_approvals > 0)): ?>
                <a href="/music-e-office/requests_list.php" class="erp-cmd-item" data-keywords="approve pending รออนุมัติ พิจารณา คำขอ แจ้งเตือน">
                    <div class="erp-cmd-icon" style="background:#fffbeb; color:#d97706;"><i class="fa-solid fa-inbox"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">Universal Approval Queue (รายการรอพิจารณาทั้งหมด)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ตรวจสอบและอนุมัติคำขอลา ไปราชการ จองห้อง และเบิกพัสดุ</div>
                    </div>
                    <span class="erp-cmd-shortcut">Queue</span>
                </a>
                <?php endif; ?>

                <div class="erp-cmd-group-title px-3 py-1 mt-2 text-uppercase text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 0.06em;">ระบบปฏิบัติการหลัก (Core ERP Modules)</div>

                <a href="/music-e-office/modules/trainings/create.php" class="erp-cmd-item" data-keywords="training create ขอไปราชการ อบรม สัมมนา สร้างคำขอ">
                    <div class="erp-cmd-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa-solid fa-plane-departure"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ยื่นขออนุมัติเดินทางไปราชการ / อบรม / สัมมนา</div>
                        <div class="text-muted" style="font-size: 0.72rem;">สร้างบันทึกข้อความขออนุมัติเดินทางและงบประมาณ</div>
                    </div>
                    <span class="erp-cmd-shortcut">Action</span>
                </a>

                <?php if ($cmd_is_training_app): ?>
                <a href="/music-e-office/modules/trainings/approve_list.php" class="erp-cmd-item" data-keywords="training approve อนุมัติไปราชการ พิจารณาไปราชการ">
                    <div class="erp-cmd-icon" style="background:#eef2ff; color:#4f46e5;"><i class="fa-solid fa-file-signature"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">จัดการและอนุมัติคำขอไปราชการ (Training Approvals)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ตรวจสอบเอกสารคำขอไปราชการและลงนามอนุมัติ</div>
                    </div>
                    <span class="erp-cmd-shortcut">Admin</span>
                </a>
                <?php endif; ?>

                <?php if ($cmd_is_practice): ?>
                <a href="/music-e-office/modules/practice_rooms/index.php" class="erp-cmd-item" data-keywords="practice room ห้องซ้อมดนตรี จองห้องซ้อม เปียโน">
                    <div class="erp-cmd-icon" style="background:#f0fdf4; color:#16a34a;"><i class="fa-solid fa-music"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ศูนย์ควบคุมห้องซ้อมดนตรี (Practice Room Telemetry)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ตรวจสอบสถานะห้องซ้อมดนตรี Check-in/Out และสถิติการใช้งาน</div>
                    </div>
                    <span class="erp-cmd-shortcut">Live</span>
                </a>
                <?php endif; ?>

                <a href="/music-e-office/modules/leave/index.php" class="erp-cmd-item" data-keywords="leave ลาป่วย ลากิจ ลาพักผ่อน ระบบการลา">
                    <div class="erp-cmd-icon" style="background:#fef2f2; color:#dc2626;"><i class="fa-solid fa-envelope-open-text"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ระบบการลาอิเล็กทรอนิกส์ (E-Leave System)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ยื่นใบลา ตรวจสอบสถิติวันลา และอนุมัติใบลา</div>
                    </div>
                    <span class="erp-cmd-shortcut">Module</span>
                </a>

                <a href="/music-e-office/modules/worklog/create.php" class="erp-cmd-item" data-keywords="worklog daily report บันทึกงานประจำวัน ปฏิบัติงาน">
                    <div class="erp-cmd-icon" style="background:#f0fdfa; color:#0d9488;"><i class="fa-solid fa-calendar-check"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">บันทึกการปฏิบัติงานประจำวัน (Daily Worklog)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">รายงานผลการปฏิบัติงานรายวันของบุคลากร</div>
                    </div>
                    <span class="erp-cmd-shortcut">Action</span>
                </a>

                <a href="/music-e-office/modules/room_booking/my_bookings.php" class="erp-cmd-item" data-keywords="room booking จองห้องเรียน ห้องประชุม สถานที่">
                    <div class="erp-cmd-icon" style="background:#fff7ed; color:#ea580c;"><i class="fa-solid fa-door-open"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ระบบจองห้องและอาคารสถานที่ (Facility Booking)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">จองห้องเรียน ห้องประชุม และหอแสดงดนตรี</div>
                    </div>
                    <span class="erp-cmd-shortcut">Module</span>
                </a>

                <a href="/music-e-office/modules/supplies/index.php" class="erp-cmd-item" data-keywords="supplies เบิกวัสดุ พัสดุ สำนักงาน">
                    <div class="erp-cmd-icon" style="background:#faf5ff; color:#7e22ce;"><i class="fa-solid fa-box-open"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ระบบเบิกวัสดุสำนักงานและคลังพัสดุ (Inventory & Supplies)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">เบิกจ่ายวัสดุอุปกรณ์สำนักงานและตรวจสอบสต็อก</div>
                    </div>
                    <span class="erp-cmd-shortcut">Module</span>
                </a>

                <?php if ($cmd_is_project): ?>
                <a href="/music-e-office/modules/projects/index.php" class="erp-cmd-item" data-keywords="projects budget โครงการ งบประมาณ แผนงาน">
                    <div class="erp-cmd-icon" style="background:#ecfeff; color:#0891b2;"><i class="fa-solid fa-diagram-project"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">ระบบบริหารโครงการและงบประมาณ (Project & Budget ERP)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ติดตามการเบิกจ่ายงบประมาณและผลสัมฤทธิ์โครงการ</div>
                    </div>
                    <span class="erp-cmd-shortcut">Module</span>
                </a>
                <?php endif; ?>

                <a href="javascript:void(0)" onclick="toggleErpDensity(); bootstrap.Modal.getInstance(document.getElementById('erpCommandModal')).hide();" class="erp-cmd-item" data-keywords="density compact cozy ปรับขนาดตาราง ความหนาแน่น">
                    <div class="erp-cmd-icon bg-light text-dark"><i class="fa-solid fa-table-cells"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small">สลับโหมดความหนาแน่นของข้อมูล (Toggle Compact / Cozy Mode)</div>
                        <div class="text-muted" style="font-size: 0.72rem;">ปรับระยะห่างตารางและการ์ดให้เหมาะกับหน้าจอ</div>
                    </div>
                    <span class="erp-cmd-shortcut">UI</span>
                </a>
            </div>
            <div class="px-3 py-2 bg-light border-top d-flex justify-content-between align-items-center text-muted" style="font-size: 0.72rem;">
                <div>
                    <span class="me-2"><kbd class="bg-white text-dark border px-1">↑</kbd> <kbd class="bg-white text-dark border px-1">↓</kbd> เลือกรายการ</span>
                    <span><kbd class="bg-white text-dark border px-1">Enter</kbd> เปิดหน้า</span>
                </div>
                <span class="fw-semibold text-purple">Music MSU Enterprise ERP 2.0</span>
            </div>
        </div>
    </div>
</div>

<footer class="footer mt-auto py-3 bg-white border-top mt-5 d-print-none" style="border-color: #e2e8f0 !important;">
    <div class="container-fluid px-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted" style="font-size: 0.78rem;">
            &copy; 2026 <strong>วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</strong> (College of Music, Mahasarakham University)
        </div>
        <div class="text-muted d-flex align-items-center gap-3" style="font-size: 0.76rem;">
            <span><i class="fa-solid fa-shield-halved text-purple me-1"></i>Enterprise ERP 2.0 Architecture</span>
            <span class="d-none d-sm-inline">|</span>
            <span class="d-none d-sm-inline">พัฒนาโดย <?php echo htmlspecialchars("จิรวัฒน์ สุราสา"); ?> - งานเทคโนโลยีสารสนเทศ</span>
        </div>
    </div>
</footer>

<style>
    .master-modal-card { border-radius: 16px !important; overflow: hidden; border: 1px solid #e2e8f0 !important; }
    #masterConfirmModal .modal-backdrop, #erpCommandModal .modal-backdrop { backdrop-filter: blur(4px); background-color: rgba(15, 23, 42, 0.45); }

    .modal-icon-wrapper {
        width: 72px; height: 72px;
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto;
    }

    .icon-delete { background-color: #fef2f2; color: #dc2626; }
    .icon-confirm { background-color: #f0fdf4; color: #16a34a; }
    .icon-warning { background-color: #fffbeb; color: #d97706; }

    /* Command Palette Items */
    .erp-cmd-item {
        display: flex; align-items: center; gap: 12px;
        padding: 9px 12px; border-radius: 8px;
        text-decoration: none; transition: all 0.12s ease;
        margin-bottom: 2px;
    }
    .erp-cmd-item:hover, .erp-cmd-item.active-cmd {
        background-color: #f4efff;
    }
    .erp-cmd-icon {
        width: 34px; height: 34px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem; flex-shrink: 0;
    }
    .erp-cmd-shortcut {
        font-size: 0.68rem; font-weight: 700; color: #64748b;
        background-color: #f1f5f9; border: 1px solid #e2e8f0;
        padding: 2px 7px; border-radius: 5px;
    }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/music-e-office/assets/js/main.js"></script>

<script>
/**
 * ฟังก์ชันกลางสำหรับเรียกใช้ Confirm Modal ทั่วทั้งระบบ
 */
let masterCallback = null;

function showConfirmModal(options) {
    const { title, message, icon, btnText, btnClass, callback } = options;
    
    document.getElementById('modalTitle').innerText = title || 'ยืนยันการทำรายการ?';
    document.getElementById('modalMessage').innerHTML = message || 'คุณแน่ใจใช่หรือไม่ที่จะดำเนินการนี้';
    
    const iconEl = document.getElementById('modalIcon');
    const wrapper = document.getElementById('modalIconWrapper');
    iconEl.className = `fa-solid ${icon || 'fa-trash-can'} fa-2x`;
    
    if (btnClass === 'btn-danger') wrapper.className = 'modal-icon-wrapper mb-3 icon-delete';
    else if (btnClass === 'btn-success') wrapper.className = 'modal-icon-wrapper mb-3 icon-confirm';
    else wrapper.className = 'modal-icon-wrapper mb-3 icon-warning';
    
    const confirmBtn = document.getElementById('confirmMasterBtn');
    confirmBtn.innerText = btnText || 'ยืนยัน';
    confirmBtn.className = `btn ${btnClass || 'btn-danger'} px-4 py-2 fw-bold shadow-sm`;
    
    masterCallback = callback;
    
    const myModal = new bootstrap.Modal(document.getElementById('masterConfirmModal'));
    myModal.show();
}

document.getElementById('confirmMasterBtn').addEventListener('click', function() {
    if (typeof masterCallback === 'function') {
        masterCallback();
    }
    bootstrap.Modal.getInstance(document.getElementById('masterConfirmModal')).hide();
});

/**
 * ระบบควบคุมความหนาแน่นข้อมูล (ERP Density Controller: Cozy / Compact)
 */
function syncErpDensityUI() {
    const isCompact = document.body.classList.contains('erp-compact');
    const label = document.getElementById('erpDensityLabel');
    const icon = document.getElementById('erpDensityIcon');
    if (label && icon) {
        label.textContent = isCompact ? 'Compact' : 'Cozy';
        icon.className = isCompact ? 'fa-solid fa-table-cells' : 'fa-solid fa-table-cells-large';
    }
    document.querySelectorAll('.js-density-label').forEach(el => {
        el.textContent = isCompact ? 'Density: Compact' : 'Density: Cozy';
    });
}

function toggleErpDensity() {
    document.body.classList.toggle('erp-compact');
    const isCompact = document.body.classList.contains('erp-compact');
    localStorage.setItem('erp_density_mode', isCompact ? 'compact' : 'cozy');
    syncErpDensityUI();
}

/**
 * ระบบ Global Command Palette (Ctrl + K)
 */
function openErpCommandPalette() {
    const modalEl = document.getElementById('erpCommandModal');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    syncErpDensityUI();

    const cmdModalEl = document.getElementById('erpCommandModal');
    const cmdInput = document.getElementById('erpCommandInput');
    const cmdItems = Array.from(document.querySelectorAll('#erpCommandList .erp-cmd-item'));
    let activeCmdIndex = 0;

    function highlightCmdItem(index) {
        const visibleItems = cmdItems.filter(item => item.style.display !== 'none');
        cmdItems.forEach(item => item.classList.remove('active-cmd'));
        if (visibleItems.length === 0) return;
        activeCmdIndex = (index + visibleItems.length) % visibleItems.length;
        visibleItems[activeCmdIndex].classList.add('active-cmd');
        visibleItems[activeCmdIndex].scrollIntoView({ block: 'nearest' });
    }

    if (cmdModalEl && cmdInput) {
        cmdModalEl.addEventListener('shown.bs.modal', function() {
            cmdInput.value = '';
            cmdItems.forEach(item => item.style.display = '');
            highlightCmdItem(0);
            setTimeout(() => cmdInput.focus(), 80);
        });

        cmdInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            cmdItems.forEach(item => {
                const text = (item.innerText + ' ' + (item.getAttribute('data-keywords') || '')).toLowerCase();
                item.style.display = text.includes(q) ? '' : 'none';
            });
            highlightCmdItem(0);
        });

        cmdInput.addEventListener('keydown', function(e) {
            const visibleItems = cmdItems.filter(item => item.style.display !== 'none');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightCmdItem(activeCmdIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightCmdItem(activeCmdIndex - 1);
            } else if (e.key === 'Enter' && visibleItems[activeCmdIndex]) {
                e.preventDefault();
                visibleItems[activeCmdIndex].click();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openErpCommandPalette();
        }
    });
});
</script>

</body>
</html>
