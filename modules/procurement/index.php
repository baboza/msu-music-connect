<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เข้าดูได้เฉพาะแอดมิน, งานพัสดุ, งานแผน, รองบริหาร, คณบดี)
$allowed_roles = ['admin', 'งานพัสดุ', 'งานแผน', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($allowed_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์เข้าใช้งานระบบติดตามงานพัสดุ'); window.location.href='../../index.php';</script>";
    exit();
}

// แยกสิทธิ์ "จัดการ" (เพิ่ม/ลบ/แก้ไข) ให้เฉพาะพัสดุและ Admin
$can_manage = hasRole(['admin', 'งานพัสดุ']);

// ดึงรายการปีงบประมาณทั้งหมดจากระบบที่แอดมินตั้งค่าไว้ (ตาราง fiscal_years)
$stmt_fy_list = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years_db = $stmt_fy_list->fetchAll(PDO::FETCH_ASSOC);

// หาปีที่ Active เป็นค่าเริ่มต้น
$active_fy = '';
$available_years = [];
foreach ($fiscal_years_db as $fy_item) {
    $available_years[] = $fy_item['year_name'];
    if ($fy_item['is_active'] == 1 && empty($active_fy)) {
        $active_fy = $fy_item['year_name'];
    }
}

// Fallback หากไม่มีข้อมูลในตาราง fiscal_years
if (empty($active_fy)) {
    $active_fy = !empty($available_years) ? $available_years[0] : (date('Y') + 543);
}

// รวมปีที่มีข้อมูลใน procurement_tracking เข้ามาด้วยเพื่อความสมบูรณ์
$stmt_proc_years = $pdo->query("SELECT DISTINCT fiscal_year FROM procurement_tracking ORDER BY fiscal_year DESC");
$proc_years = $stmt_proc_years->fetchAll(PDO::FETCH_COLUMN);
foreach ($proc_years as $py) {
    if (!empty($py) && !in_array($py, $available_years)) {
        $available_years[] = $py;
    }
}
rsort($available_years);

if (empty($available_years)) {
    $available_years = [$active_fy];
}

// รับค่าจาก Form ค้นหา (ค่าเริ่มต้นเป็นปี Active ที่ตั้งค่าไว้)
$filter_fy = $_GET['fy'] ?? $active_fy;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 2. ดึงรายการติดตามงานพัสดุ พร้อมชื่อผู้รับผิดชอบและคำนวณส่วนต่าง
$sql = "SELECT p.*, u.first_name, u.last_name, 
               (p.budget - p.actual_price) AS balance
        FROM procurement_tracking p
        LEFT JOIN users u ON p.responsible_user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
        WHERE p.fiscal_year = :fy";
$params = [':fy' => $filter_fy];

if (!empty($search)) {
    $sql .= " AND (p.project_name LIKE :search1 OR p.procurement_method LIKE :search2 OR u.first_name LIKE :search3 OR u.last_name LIKE :search4)";
    $search_term = "%$search%";
    $params[':search1'] = $search_term;
    $params[':search2'] = $search_term;
    $params[':search3'] = $search_term;
    $params[':search4'] = $search_term;
}

$sql .= " ORDER BY p.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div class="mb-2 mb-md-0">
            <h1 class="h2 text-primary fw-bold"><i class="fa-solid fa-truck-fast me-2"></i>ระบบติดตามงานพัสดุ</h1>
            <p class="text-muted mb-0">บริหารจัดการงบประมาณและติดตามความคืบหน้าการจัดซื้อจัดจ้าง</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-primary rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-chart-pie me-1"></i> ภาพรวม
            </a>
            <?php if ($can_manage): ?>
            <a href="create.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> เพิ่มรายการใหม่
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type'] ?? 'info'; ?> shadow-sm border-start border-<?php echo $_SESSION['msg_type'] ?? 'info'; ?> border-4 alert-dismissible fade show">
            <i class="fa-solid <?php echo ($_SESSION['msg_type'] == 'success') ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> me-2"></i> 
            <?php echo $_SESSION['msg']; unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3">
            <form action="" method="GET" class="row g-2 align-items-center">
                <div class="col-auto">
                    <label class="fw-bold small text-muted"><i class="fa-solid fa-filter me-1"></i> ปีงบประมาณ:</label>
                    <select name="fy" class="form-select form-select-sm border-primary fw-bold text-primary" onchange="this.form.submit()">
                        <?php foreach ($available_years as $y): ?>
                            <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ($y == $filter_fy) ? 'selected' : ''; ?>>
                                ปี <?php echo htmlspecialchars($y); ?> <?php echo ($y == $active_fy) ? '(ปัจจุบัน)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto flex-grow-1 ms-md-3">
                    <div class="input-group input-group-sm shadow-sm">
                        <span class="input-group-text bg-white border-primary text-primary"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="search" class="form-control border-primary border-start-0" placeholder="ค้นหาชื่อรายการ, วิธีจัดซื้อ หรือชื่อผู้รับผิดชอบ..." value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary fw-bold px-3" type="submit">ค้นหา</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4" width="22%">ชื่อรายการ / วิธีจัดซื้อ</th>
                            <th width="15%">ผู้รับผิดชอบ</th>
                            <th class="text-end" width="12%">งบประมาณ</th>
                            <th class="text-end text-primary" width="12%">ผลจัดซื้อจริง</th>
                            <th class="text-end" width="12%">คงเหลือ</th>
                            <th width="12%">ความคืบหน้า</th>
                            <th class="text-center pe-4" width="15%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($projects)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">ไม่พบข้อมูลรายการ</td></tr>
                        <?php else: ?>
                            <?php foreach ($projects as $p): 
                                $completed_steps = $p['step1_request'] + $p['step2_tor'] + $p['step3_approve_buy'] + $p['step4_announce_winner'] + $p['step5_system_approve'] + $p['step6_contract'] + $p['step7_delivery'] + $p['step8_inspection'] + $p['step9_disbursement'] + $p['step10_completed'];
                                $progress = ($completed_steps / 10) * 100;
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($p['project_name']); ?>">
                                        <?php echo htmlspecialchars($p['project_name']); ?>
                                    </div>
                                    <div class="extra-small text-muted mt-1">วิธี: <?php echo htmlspecialchars($p['procurement_method'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <div class="small fw-bold text-secondary">
                                        <i class="fa-solid fa-user-check me-1"></i>
                                        <?php echo !empty($p['first_name']) ? htmlspecialchars($p['first_name'].' '.$p['last_name']) : '<span class="text-muted fw-normal">- ไม่ระบุ -</span>'; ?>
                                    </div>
                                </td>
                                <td class="text-end fw-bold">฿<?php echo number_format($p['budget'], 2); ?></td>
                                <td class="text-end fw-bold text-primary">฿<?php echo number_format($p['actual_price'], 2); ?></td>
                                <td class="text-end fw-bold <?php echo $p['balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo ($p['balance'] < 0 ? '' : '+') . number_format($p['balance'], 2); ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?php echo $progress == 100 ? 'bg-success' : 'bg-primary'; ?>" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <div class="extra-small mt-1 fw-bold"><?php echo $progress; ?>%</div>
                                </td>
                                <td class="text-center pe-4 text-nowrap">
                                    <a href="view.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-light border text-info rounded-circle me-1" title="ดูรายละเอียด"><i class="fa-solid fa-eye"></i></a>
                                    
                                    <?php if ($can_manage): ?>
                                    <button type="button" class="btn btn-sm btn-light border text-primary rounded-circle me-1 edit-price-btn" 
                                            data-id="<?php echo $p['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($p['project_name']); ?>"
                                            data-budget="<?php echo $p['budget']; ?>"
                                            data-actual="<?php echo $p['actual_price']; ?>"
                                            data-result="<?php echo htmlspecialchars($p['procurement_result']); ?>"
                                            data-bs-toggle="modal" data-bs-target="#updatePriceModal" title="ลงผลจัดซื้อจริง">
                                        <i class="fa-solid fa-coins"></i>
                                    </button>
                                    
                                    <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-light border text-secondary rounded-circle me-1" title="แก้ไขขั้นตอน"><i class="fa-solid fa-pen-to-square"></i></a>
                                    
                                    <a href="delete.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-light border text-danger rounded-circle" onclick="return confirm('ยืนยันการลบ?');"><i class="fa-solid fa-trash"></i></a>
                                    <?php endif; ?>
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

<div class="modal fade" id="updatePriceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-purple"><i class="fa-solid fa-file-invoice-dollar me-2"></i>บันทึกผลการจัดซื้อจริง</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="update_price.php" method="POST">
                <div class="modal-body py-3">
                    <input type="hidden" name="id" id="modal_id">
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <label class="small text-muted d-block fw-bold mb-1">ชื่อโครงการ:</label>
                        <span id="modal_project_name" class="fw-bold text-dark"></span>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">งบประมาณที่ตั้งไว้ (฿)</label>
                            <input type="text" id="modal_budget" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-primary">ราคาที่จัดซื้อได้จริง (฿)</label>
                            <input type="number" step="0.01" name="actual_price" id="modal_actual" class="form-control border-primary" required>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">รายละเอียดผลการจัดซื้อ (เช่น ชื่อผู้ชนะ)</label>
                        <input type="text" name="procurement_result" id="modal_result" class="form-control" placeholder="ระบุบริษัทผู้ชนะ หรือผลการจัดซื้อ...">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">บันทึกข้อมูลราคา</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// สคริปต์ส่งค่าเข้า Modal
document.querySelectorAll('.edit-price-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modal_id').value = this.dataset.id;
        document.getElementById('modal_project_name').innerText = this.dataset.name;
        document.getElementById('modal_budget').value = parseFloat(this.dataset.budget).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('modal_actual').value = this.dataset.actual;
        document.getElementById('modal_result').value = this.dataset.result;
    });
});
</script>

<style>
    .extra-small { font-size: 0.75rem; }
    .text-purple { color: #39048f; }
    .bg-success-subtle { background-color: #d1e7dd !important; }
    .bg-warning-subtle { background-color: #fff3cd !important; }
    .hover-lift:hover { transform: translateY(-2px); transition: 0.2s; }
</style>

<?php require_once '../../includes/footer.php'; ?>