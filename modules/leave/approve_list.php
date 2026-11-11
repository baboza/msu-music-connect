<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ---------------------------------------------------------
// 1. เช็คสิทธิ์การเข้าถึงหน้านี้ (ต้องเป็นผู้มีสิทธิ์อนุมัติเท่านั้น)
// ---------------------------------------------------------
$user_id = $_SESSION['user_id'];
$user_roles_array = $_SESSION['roles'] ?? [];

$my_dept_id = 0;
try {
    $stmt_my_dept = $pdo->prepare("SELECT dept_id FROM users WHERE user_id = ?");
    $stmt_my_dept->execute([$user_id]);
    $my_dept_id = (int)$stmt_my_dept->fetchColumn();
} catch(Exception $e) {}

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
$is_dept_head = !empty($my_head_dept_ids);

$allowed_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'หัวหน้าภาควิชา', 'หัวหน้าภาค', 'ประธานหลักสูตร', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($allowed_roles) && !$is_dept_head) {
    echo "
    <main class=\"col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5\" style=\"background-color: #f8fafc; min-height: 100vh;\">
        <div class='d-flex justify-content-center align-items-center' style='height: 60vh;'>
            <div class='text-center opacity-50'>
                <i class='fa-solid fa-lock fs-1 mb-3'></i>
                <h4>กำลังตรวจสอบสิทธิ์...</h4>
            </div>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: 'ปฏิเสธการเข้าถึง!',
                text: 'คุณไม่มีสิทธิ์ในส่วนของการตรวจสอบและอนุมัติการลา',
                icon: 'error',
                confirmButtonColor: '#39048f',
                confirmButtonText: 'กลับสู่หน้าหลัก',
                allowOutsideClick: false
            }).then((result) => {
                window.location.href = '../../index.php';
            });
        });
    </script>
    ";
    require_once '../../includes/footer.php';
    exit();
}

// ---------------------------------------------------------
// 2. สร้างเงื่อนไขในการดึงข้อมูล
// ---------------------------------------------------------
$conditions = [];

if (hasRole(['admin'])) {
    $conditions[] = "1=1";
}

if (hasRole(['งานบุคคล'])) {
    $conditions[] = "l.current_step = 1";
}

if (hasRole(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'])) {
    $conditions[] = "(l.current_step = 2 AND (u.dept_id = 11 OR l.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1)))";
}
if ($is_dept_head) {
    $conditions[] = "(l.current_step = 2 AND u.dept_id IN (" . implode(',', $my_head_dept_ids) . "))";
}

if (hasRole(['รองคณบดีฝ่ายบริหาร'])) {
    $conditions[] = "l.current_step = 3";
}

if (hasRole(['คณบดี', 'รักษาการคณบดี'])) {
    $conditions[] = "(l.current_step = 4 AND (l.final_signer_id IS NULL OR l.final_signer_id = ''))";
}

$conditions[] = "(l.current_step = 4 AND l.final_signer_id = :user_id)";

$pending_requests = [];

if (!empty($conditions)) {
    $where_sql = implode(' OR ', $conditions);
    
    $sql = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, u.position, d.dept_name 
            FROM leave_requests l 
            JOIN leave_types t ON l.type_id = t.type_id 
            JOIN users u ON l.user_id = u.user_id 
            LEFT JOIN departments d ON u.dept_id = d.dept_id
            WHERE l.status = 'pending' AND ($where_sql)
            ORDER BY l.created_at ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $user_id]);
    $pending_requests = $stmt->fetchAll();
}

$leave_step_names = [
    1 => 'ขั้นที่ 1: งานบุคคลตรวจสอบวันลา',
    2 => 'ขั้นที่ 2: หัวหน้าสำนักงาน / หัวหน้าภาควิชาพิจารณา',
    3 => 'ขั้นที่ 3: รองคณบดีฝ่ายบริหารกลั่นกรอง',
    4 => 'ขั้นที่ 4: คณบดี / ผู้รักษาการแทนคณบดีอนุมัติ'
];
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 extra-small fw-bold">
                    <i class="fa-solid fa-envelope-open-text me-1"></i>E-LEAVE APPROVAL WORKFLOW
                </span>
            </div>
            <h1 class="h4 text-dark fw-bold mb-0"><i class="fa-solid fa-user-check text-purple me-2"></i>ตรวจสอบและพิจารณาอนุมัติใบลา</h1>
            <p class="text-muted small mb-0 mt-1">รายการเอกสารใบลาบุคลากรที่รอการพิจารณาตามลำดับขั้นสายบังคับบัญชา</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-3 py-2 fw-bold">
                รอพิจารณา <?php echo count($pending_requests); ?> รายการ
            </span>
            <a href="index.php" class="btn btn-light border fw-bold text-dark">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าการลาของฉัน
            </a>
        </div>
    </div>

    <div class="card bg-white mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4" width="15%">วันที่ยื่นคำขอ</th>
                            <th width="25%">ผู้ขอลา / สังกัด</th>
                            <th width="20%">ประเภทการลา / ขั้นตอน</th>
                            <th width="22%">ช่วงเวลาที่ขอลา</th>
                            <th class="text-center pe-4" width="18%">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pending_requests)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <div class="empty-state-icon mb-2">
                                        <i class="fa-solid fa-circle-check text-success opacity-75" style="font-size: 2.75rem;"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">ไม่มีรายการใบลาที่รอการอนุมัติ</h6>
                                    <p class="small mb-0 text-muted">คุณได้พิจารณาใบลาในคิวงานครบถ้วนแล้ว</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($pending_requests as $req):
                                $l_step = (int)($req['current_step'] ?? 1);
                                $l_payload = [
                                    'id' => (int)$req['leave_id'],
                                    'doc_no' => 'LV-' . str_pad($req['leave_id'], 5, '0', STR_PAD_LEFT),
                                    'applicant' => ($req['prefix_name'] ?? '') . $req['first_name'] . ' ' . $req['last_name'],
                                    'position' => $req['position'] ?? 'บุคลากร',
                                    'dept' => $req['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์',
                                    'type_name' => $req['type_name'] ?? '-',
                                    'start_date' => date('d/m/Y', strtotime($req['start_date'])),
                                    'end_date' => date('d/m/Y', strtotime($req['end_date'])),
                                    'total_days' => $req['total_days'] ?? '-',
                                    'reason' => $req['reason'] ?? '-',
                                    'contact_info' => $req['contact_address'] ?? ($req['contact_phone'] ?? '-'),
                                    'step' => $l_step,
                                    'step_desc' => $leave_step_names[$l_step] ?? ('ขั้นที่ ' . $l_step),
                                    'medical_cert' => $req['medical_cert_path'] ?? ($req['attachment'] ?? '')
                                ];
                            ?>
                            <tr class="request-row">
                                <td class="ps-4">
                                    <div class="fw-bold text-dark small"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></div>
                                    <div class="extra-small text-muted">เวลา <?php echo date('H:i', strtotime($req['created_at'])); ?> น.</div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark small"><?php echo htmlspecialchars($req['prefix_name'].$req['first_name']." ".$req['last_name']); ?></div>
                                    <div class="extra-small text-purple fw-semibold"><i class="fa-solid fa-sitemap me-1"></i><?php echo htmlspecialchars($req['dept_name'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <span class="fw-bold text-purple small"><?php echo htmlspecialchars($req['type_name']); ?></span>
                                    <div class="extra-small mt-1">
                                        <span class="badge bg-warning-subtle text-dark border px-2 py-1">รอขั้นตอนที่ <?php echo $l_step; ?> / 4</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="small fw-bold text-dark">
                                        <?php echo date('d/m/Y', strtotime($req['start_date'])); ?> 
                                        <i class="fa-solid fa-arrow-right mx-1 text-muted extra-small"></i> 
                                        <?php echo date('d/m/Y', strtotime($req['end_date'])); ?>
                                    </div>
                                    <?php if (!empty($req['total_days'])): ?>
                                        <div class="extra-small text-muted">รวม <?php echo htmlspecialchars($req['total_days']); ?> วันทำการ</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-light border px-2 fw-bold" title="ดูรายละเอียดด่วน (Quick Inspect)" onclick='openLeaveInspectDrawer(<?php echo htmlspecialchars(json_encode($l_payload, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fa-solid fa-eye text-purple"></i>
                                        </button>
                                        <a href="view.php?id=<?php echo $req['leave_id']; ?>" class="btn btn-sm btn-purple px-3 fw-bold">
                                            <i class="fa-solid fa-pen-nib me-1"></i> พิจารณา
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Enterprise Slide-Over Quick Inspector for Leave Requests -->
    <div class="offcanvas offcanvas-end border-start shadow-lg" tabindex="-1" id="leaveInspectDrawer" style="width: 440px;">
        <div class="offcanvas-header border-bottom bg-light py-3 px-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-purple text-white px-2 py-1 extra-small fw-bold" id="lvDrwDocNo">LV-00000</span>
                    <span class="badge bg-danger-subtle text-danger border px-2 py-1 extra-small fw-bold" id="lvDrwType">ใบลา</span>
                </div>
                <h6 class="offcanvas-title fw-bold text-dark mb-0">ตรวจสอบข้อมูลใบลา (Quick Inspector)</h6>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-4">
            <div class="p-3 rounded-3 bg-light border mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="extra-small fw-bold text-uppercase text-muted">ความคืบหน้าการอนุมัติ</span>
                    <span class="badge bg-purple-subtle text-purple extra-small fw-bold" id="lvDrwStepBadge">ขั้นที่ 1/4</span>
                </div>
                <div class="progress" style="height: 7px; border-radius: 99px;">
                    <div class="progress-bar bg-purple" id="lvDrwStepBar" role="progressbar" style="width: 25%;"></div>
                </div>
                <div class="extra-small text-dark fw-semibold mt-2" id="lvDrwStepDesc">-</div>
            </div>

            <div class="mb-3">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">ผู้ยื่นใบลา</div>
                <div class="p-3 rounded-3 border bg-white">
                    <div class="fw-bold text-dark" id="lvDrwApplicant">-</div>
                    <div class="small text-muted" id="lvDrwPosition">-</div>
                    <div class="extra-small text-purple fw-semibold mt-1"><i class="fa-solid fa-sitemap me-1"></i><span id="lvDrwDept">-</span></div>
                </div>
            </div>

            <div class="mb-3">
                <div class="extra-small fw-bold text-uppercase text-muted mb-1">ช่วงเวลาและเหตุผลการลา</div>
                <div class="p-3 rounded-3 border bg-white">
                    <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
                        <span class="text-muted small">ตั้งแต่วันที่ - ถึงวันที่</span>
                        <span class="fw-bold text-dark small" id="lvDrwDates">-</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
                        <span class="text-muted small">จำนวนวันลา</span>
                        <span class="badge bg-purple-subtle text-purple fw-bold" id="lvDrwDays">-</span>
                    </div>
                    <div class="mt-2">
                        <span class="text-muted extra-small d-block mb-1">เหตุผลการลา:</span>
                        <div class="p-2 rounded bg-light small text-dark" id="lvDrwReason">-</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer border-top bg-light p-3 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="offcanvas">ปิดหน้าต่าง</button>
            <a href="#" id="lvDrwApproveBtn" class="btn btn-purple fw-bold flex-grow-1 text-center">
                <i class="fa-solid fa-pen-nib me-1"></i> เปิดหน้าพิจารณาและลงนามใบลา
            </a>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #39048f; }
    .bg-purple { background-color: #39048f; }
    .bg-purple-subtle { background-color: #f5f0ff; color: #39048f; }
    .btn-purple { background-color: #39048f; color: white; border: none; transition: all 0.15s ease; }
    .btn-purple:hover { background-color: #4c0582; color: white; transform: translateY(-1px); }
    .extra-small { font-size: 0.75rem; }
</style>

<script>
function openLeaveInspectDrawer(data) {
    if (!data) return;
    document.getElementById('lvDrwDocNo').textContent = data.doc_no || '-';
    document.getElementById('lvDrwType').textContent = data.type_name || 'ใบลา';
    document.getElementById('lvDrwApplicant').textContent = data.applicant || '-';
    document.getElementById('lvDrwPosition').textContent = data.position || '-';
    document.getElementById('lvDrwDept').textContent = data.dept || '-';
    document.getElementById('lvDrwDates').textContent = (data.start_date || '-') + ' - ' + (data.end_date || '-');
    document.getElementById('lvDrwDays').textContent = (data.total_days || '-') + ' วันทำการ';
    document.getElementById('lvDrwReason').textContent = data.reason || '-';

    const step = parseInt(data.step || 1, 10);
    document.getElementById('lvDrwStepBadge').textContent = 'ขั้นที่ ' + step + ' / 4';
    document.getElementById('lvDrwStepBar').style.width = Math.min(100, Math.max(25, step * 25)) + '%';
    document.getElementById('lvDrwStepDesc').textContent = data.step_desc || '-';
    document.getElementById('lvDrwApproveBtn').href = 'view.php?id=' + encodeURIComponent(data.id);

    const drawerEl = document.getElementById('leaveInspectDrawer');
    bootstrap.Offcanvas.getOrCreateInstance(drawerEl).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
