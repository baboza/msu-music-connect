<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// 1. ดึงปีงบประมาณที่ Active จากระบบตั้งค่า
$stmt_active = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$default_fy = $stmt_active->fetchColumn() ?: (date('Y') + 543);

// รับค่าการกรองปีงบประมาณ (ถ้าไม่มีให้ใช้ปีปัจจุบันที่ Active)
$filter_fy = isset($_GET['fy']) ? $_GET['fy'] : $default_fy;

// 2. ดึงรายการปีงบประมาณทั้งหมดที่มีข้อมูลการเบิก (เพื่อทำ Dropdown)
$stmt_years = $pdo->query("SELECT DISTINCT fiscal_year FROM supplies_requests ORDER BY fiscal_year DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($default_fy, $available_years)) { array_unshift($available_years, $default_fy); }

// 3. ดึงประวัติการขอเบิก กรองตาม User และ ปีงบประมาณ
$stmt_requests = $pdo->prepare("
    SELECT r.*, 
           (SELECT COUNT(*) FROM supplies_request_details WHERE request_id = r.request_id) as item_count 
    FROM supplies_requests r 
    WHERE r.user_id = ? AND r.fiscal_year = ?
    ORDER BY r.request_date DESC
");
$stmt_requests->execute([$user_id, $filter_fy]);
$requests = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันช่วยแสดงป้ายสถานะ (Badge)
function getStatusBadge($status, $step) {
    if ($status == 'pending') {
        return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-regular fa-clock me-1"></i>รอพิจารณา</span>';
    } elseif ($status == 'approved' && $step == 'head') {
        return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-check me-1"></i>อนุมัติแล้ว</span>';
    } elseif ($status == 'issued' && $step == 'supply') {
        return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-box-open me-1"></i>รับของแล้ว</span>';
    } elseif ($status == 'rejected') {
        return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ</span>';
    }
    return '-';
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-boxes-stacked me-2"></i>ระบบเบิกจ่ายวัสดุสำนักงาน</h1>
            <p class="text-muted mb-0">ประวัติการเบิกวัสดุอุปกรณ์ ประจำปีงบประมาณ <strong><?php echo htmlspecialchars($filter_fy); ?></strong></p>
        </div>
        <div class="d-flex gap-2">
            <form action="" method="GET" class="d-flex align-items-center bg-light border rounded-pill px-3 py-1">
                <label class="text-muted small fw-bold text-nowrap me-2">ปีงบประมาณ:</label>
                <select name="fy" class="form-select form-select-sm border-0 bg-transparent fw-bold text-purple" onchange="this.form.submit()" style="box-shadow: none; cursor: pointer;">
                    <?php foreach($available_years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php if($filter_fy == $y) echo 'selected'; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="create.php" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-2"></i> เขียนใบเบิกใหม่
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm border-0 border-start border-<?php echo $_SESSION['msg_type']; ?> border-4">
            <?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4 py-3">เลขที่คำขอ</th>
                            <th class="py-3">วันที่ขอเบิก</th>
                            <th class="py-3 text-center">จำนวนรายการ</th>
                            <th class="py-3 text-center">สถานะ (หัวหน้า)</th>
                            <th class="py-3 text-center">สถานะ (พัสดุ)</th>
                            <th class="pe-4 py-3 text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                                    <p class="mb-0">ยังไม่มีประวัติการเบิกวัสดุในปีงบประมาณนี้</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-dark">
                                        #<?php echo str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?> / <?php echo $req['fiscal_year']; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($req['request_date'])); ?></td>
                                    <td class="text-center fw-bold">
                                        <?php echo $req['item_count']; ?> <span class="text-muted fw-normal small">รายการ</span>
                                    </td>
                                    <td class="text-center"><?php echo getStatusBadge($req['head_status'], 'head'); ?></td>
                                    <td class="text-center">
                                        <?php 
                                            if ($req['head_status'] == 'pending') {
                                                echo '<span class="text-muted small">-</span>';
                                            } elseif ($req['head_status'] == 'rejected') {
                                                echo '<span class="text-muted small">ยกเลิกอัตโนมัติ</span>';
                                            } else {
                                                echo getStatusBadge($req['supply_status'], 'supply'); 
                                            }
                                        ?>
                                    </td>
                                    <td class="pe-4 text-center">
                                        <a href="view.php?id=<?php echo $req['request_id']; ?>" class="btn btn-sm btn-light border rounded-pill px-3">
                                            <i class="fa-solid fa-file-invoice me-1"></i> ดูใบเบิก
                                        </a>
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

<style>
    .text-purple { color: #39048f; }
    .btn-purple { background-color: #39048f; color: white; transition: all 0.2s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); shadow: 0 4px 8px rgba(75,0,130,0.2); }
    .form-select-sm { font-size: 0.875rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>