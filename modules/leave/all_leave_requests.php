<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ (เฉพาะ Admin และ ผู้บริหาร)
// ---------------------------------------------------------
$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($management_roles)) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='../../index.php';</script>";
    exit();
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ---------------------------------------------------------
// 2. รับค่าการค้นหาและตัวกรอง (Filters)
// ---------------------------------------------------------
$search = $_GET['search'] ?? '';
$dept_filter = $_GET['dept_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// ---------------------------------------------------------
// 3. เตรียม Query ดึงข้อมูล
// ---------------------------------------------------------
$sql = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, d.dept_name 
        FROM leave_requests l
        JOIN leave_types t ON l.type_id = t.type_id
        JOIN users u ON l.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.user_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($dept_filter) {
    $sql .= " AND u.dept_id = ?";
    $params[] = $dept_filter;
}

if ($status_filter) {
    $sql .= " AND l.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ดึงรายชื่อแผนกเพื่อใช้ในตัวกรอง
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll();
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-file-invoice me-2"></i>ประวัติการลาทั้งหมด</h1>
            <p class="text-muted small">รวบรวมรายการใบลาของบุคลากรทุกคนในระบบ</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="dashboard_admin.php" class="btn btn-outline-purple shadow-sm rounded-pill px-3 me-2">
                <i class="fa-solid fa-chart-line me-1"></i> กลับไป Dashboard
            </a>
            <button onclick="window.print()" class="btn btn-light border shadow-sm rounded-pill px-3">
                <i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน
            </div>
    </div>

    <div class="card shadow-sm border-0 bg-white mb-4 d-print-none">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">ค้นหาชื่อ หรือ รหัสพนักงาน</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 shadow-none" placeholder="ระบุคำค้น..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">กรองตามสังกัด/แผนก</label>
                    <select name="dept_id" class="form-select shadow-none">
                        <option value="">-- ทุกแผนก --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['dept_id']; ?>" <?php echo ($dept_filter == $d['dept_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">กรองตามสถานะ</label>
                    <select name="status" class="form-select shadow-none">
                        <option value="">-- ทุกสถานะ --</option>
                        <option value="pending" <?php echo ($status_filter == 'pending') ? 'selected' : ''; ?>>อยู่ระหว่างดำเนินการ (Pending)</option>
                        <option value="approved" <?php echo ($status_filter == 'approved') ? 'selected' : ''; ?>>อนุมัติแล้ว (Approved)</option>
                        <option value="rejected" <?php echo ($status_filter == 'rejected') ? 'selected' : ''; ?>>ไม่อนุมัติ/ยกเลิก (Rejected)</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-purple w-100 rounded-pill shadow-sm fw-bold">
                        ค้นหา
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 border-top border-purple border-4 bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light extra-small text-uppercase">
                        <tr>
                            <th class="ps-4">วันที่ยื่น</th>
                            <th>ผู้ขอลา / สังกัด</th>
                            <th>ประเภทการลา</th>
                            <th>ระยะเวลา</th>
                            <th class="text-center">จำนวนวัน</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fs-1 mb-3 opacity-25"></i>
                                    <h6 class="fw-bold mb-0">ไม่พบรายการใบลาที่ค้นหา</h6>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $req): 
                                $days = (strtotime($req['end_date']) - strtotime($req['start_date'])) / 86400 + 1;
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('H:i', strtotime($req['created_at'])); ?> น.</div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo $req['prefix_name'].$req['first_name'].' '.$req['last_name']; ?></div>
                                    <div class="extra-small text-purple"><i class="fa-solid fa-building me-1"></i> <?php echo htmlspecialchars($req['dept_name'] ?? 'ไม่ระบุ'); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-purple-subtle text-purple fw-normal px-2"><?php echo htmlspecialchars($req['type_name']); ?></span>
                                </td>
                                <td>
                                    <div class="small fw-bold">
                                        <?php echo date('d/m/y', strtotime($req['start_date'])); ?> - <?php echo date('d/m/y', strtotime($req['end_date'])); ?>
                                    </div>
                                </td>
                                <td class="text-center fw-bold"><?php echo $days; ?></td>
                                <td class="text-center">
                                    <?php if($req['status'] == 'approved'): ?>
                                        <span class="badge bg-success shadow-sm rounded-pill px-3"><i class="fa-solid fa-circle-check me-1"></i>อนุมัติ</span>
                                    <?php elseif($req['status'] == 'rejected'): ?>
                                        <span class="badge bg-danger shadow-sm rounded-pill px-3"><i class="fa-solid fa-circle-xmark me-1"></i>ไม่อนุมัติ</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark shadow-sm rounded-pill px-3"><i class="fa-solid fa-hourglass-half me-1"></i>รอขั้นที่ <?php echo $req['current_step']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <a href="view.php?id=<?php echo $req['leave_id']; ?>" class="btn btn-sm btn-outline-purple rounded-circle" title="ดูรายละเอียด">
                                        <i class="fa-solid fa-eye"></i>
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
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); }
    .btn-outline-purple { color: #4b0082; border-color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .extra-small { font-size: 0.75rem; }
    
    @media print {
        .d-print-none, .sidebar, .navbar, .header { display: none !important; }
        main { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; }
        .table thead { background-color: #f8f9fa !important; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>