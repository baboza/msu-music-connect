<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// =========================================================
// 1. รับค่าการค้นหาและตัวกรอง
// =========================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending'; 
$filter_level = isset($_GET['level']) ? $_GET['level'] : 'all';

// =========================================================
// 2. Query ดึงข้อมูลการแจ้งซ่อม (ปรับให้ตรงตามโครงสร้างตารางจริง)
// =========================================================
$sql = "SELECT rr.*, r.room_code, r.room_name,
               COALESCE(s.first_name, u.first_name) as fname,
               COALESCE(s.last_name, u.last_name) as lname
        FROM repair_requests rr
        LEFT JOIN rooms r ON rr.room_id = r.room_id
        LEFT JOIN students s ON rr.reporter_id = s.student_id
        LEFT JOIN users u ON rr.reporter_id = u.user_id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (rr.equipment_name LIKE :search1 OR r.room_code LIKE :search2 OR rr.problem_desc LIKE :search3)";
    $search_term = "%$search%";
    $params[':search1'] = $search_term;
    $params[':search2'] = $search_term;
    $params[':search3'] = $search_term;
}

if ($filter_status !== 'all') {
    $sql .= " AND rr.status = :status";
    $params[':status'] = $filter_status;
}

if ($filter_level !== 'all') {
    $sql .= " AND rr.damage_level = :level";
    $params[':level'] = $filter_level;
}

// เรียงลำดับความสำคัญ: Critical ขึ้นก่อน ตามด้วยวันที่แจ้งล่าสุด
$sql .= " ORDER BY CASE 
            WHEN rr.damage_level = 'critical' THEN 1 
            WHEN rr.damage_level = 'medium' THEN 2 
            ELSE 3 END, rr.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติภาพรวม
// =========================================================
$count_pending = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status='pending'")->fetchColumn();
$count_critical = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE damage_level='critical' AND status!='completed'")->fetchColumn();

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงระดับความรุนแรง
function getDamageLevelBadge($level) {
    if($level == 'critical') return '<span class="badge bg-danger animate__animated animate__flash animate__infinite px-2 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> วิกฤต (ปิดห้อง)</span>';
    if($level == 'medium') return '<span class="badge bg-warning text-dark px-2 py-2">ปานกลาง</span>';
    return '<span class="badge bg-info text-white px-2 py-2">เล็กน้อย</span>';
}

// ฟังก์ชันแปลงสถานะงานซ่อม
function getRepairStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge bg-secondary shadow-sm px-3 py-2"><i class="fa-regular fa-clock me-1"></i> รอรับเรื่อง</span>',
        'in_progress' => '<span class="badge bg-primary shadow-sm px-3 py-2"><i class="fa-solid fa-screwdriver-wrench me-1"></i> กำลังซ่อม</span>',
        'completed' => '<span class="badge bg-success shadow-sm px-3 py-2"><i class="fa-solid fa-check-double me-1"></i> ซ่อมเสร็จสิ้น</span>',
        'cancelled' => '<span class="badge bg-light text-muted border px-3 py-2">ยกเลิก</span>'
    ];
    return $badges[$status] ?? $status;
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-toolbox me-2"></i>จัดการระบบแจ้งซ่อม</h1>
            <p class="text-muted small mb-0">ติดตามและอัปเดตสถานะการซ่อมบำรุงวัสดุ อุปกรณ์ และอาคารสถานที่</p>
        </div>
        
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="create.php" class="btn btn-orange px-4 py-2 rounded-pill fw-bold shadow-sm hover-lift d-flex align-items-center">
                <i class="fa-solid fa-plus-circle me-2 fs-5"></i> แจ้งซ่อมใหม่
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-danger border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-radiation fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">งานวิกฤต (Critical)</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_critical); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-warning border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-regular fa-bell fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">รายการรอรับเรื่อง</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_pending); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3 p-md-4">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold small text-muted">ค้นหาอุปกรณ์ / รหัสห้อง / อาการ</label>
                    <input type="text" name="search" class="form-control border-0 bg-light shadow-sm" placeholder="ระบุคำค้นหา..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">ระดับความรุนแรง</label>
                    <select name="level" class="form-select border-0 bg-light shadow-sm">
                        <option value="all">-- ทุกระดับ --</option>
                        <option value="low" <?php echo $filter_level == 'low' ? 'selected' : ''; ?>>เล็กน้อย (Low)</option>
                        <option value="medium" <?php echo $filter_level == 'medium' ? 'selected' : ''; ?>>ปานกลาง (Medium)</option>
                        <option value="critical" <?php echo $filter_level == 'critical' ? 'selected' : ''; ?>>วิกฤต (Critical)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark fw-bold w-100 shadow-sm">ค้นหาและกรอง</button>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                <a href="?status=all&level=<?php echo $filter_level; ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">ทั้งหมด</a>
                <a href="?status=pending&level=<?php echo $filter_level; ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'pending' ? 'btn-secondary text-white' : 'btn-outline-secondary'; ?>">รอรับเรื่อง</a>
                <a href="?status=in_progress&level=<?php echo $filter_level; ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'in_progress' ? 'btn-primary' : 'btn-outline-primary'; ?>">กำลังดำเนินการ</a>
                <a href="?status=completed&level=<?php echo $filter_level; ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'completed' ? 'btn-success' : 'btn-outline-success'; ?>">เสร็จสิ้น</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">No.</th>
                        <th>อุปกรณ์ / สถานที่</th>
                        <th>ผู้แจ้ง / วันที่</th>
                        <th class="text-center">ระดับความรุนแรง</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center pe-4">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($repairs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">ไม่พบข้อมูลรายการแจ้งซ่อม</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($repairs as $rp): ?>
                        <tr>
                            <td class="ps-4 text-muted small">#<?php echo $rp['repair_id']; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($rp['equipment_name']); ?></div>
                                <div class="extra-small text-purple fw-bold">
                                    <i class="fa-solid fa-location-dot me-1"></i> ห้อง: <?php echo htmlspecialchars($rp['room_code'] ?: 'ไม่ระบุห้อง'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark small"><?php echo htmlspecialchars(($rp['fname'] ?? 'ไม่ระบุ').' '.$rp['lname']); ?></div>
                                <div class="extra-small text-muted"><?php echo date('d/m/Y H:i', strtotime($rp['created_at'])); ?></div>
                            </td>
                            <td class="text-center"><?php echo getDamageLevelBadge($rp['damage_level']); ?></td>
                            <td class="text-center"><?php echo getRepairStatusBadge($rp['status']); ?></td>
                            <td class="text-center pe-4">
                                <a href="view_repair.php?id=<?php echo $rp['repair_id']; ?>" class="btn btn-sm btn-outline-purple rounded-3 hover-lift">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i> รายละเอียด
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
    .btn-outline-purple:hover { background-color: var(--msu-purple); color: white; }
    
    /* 💡 CSS สำหรับปุ่มแจ้งซ่อมสีส้ม */
    .btn-orange { background-color: #fd7e14; color: white; border: none; }
    .btn-orange:hover { background-color: #e86c0c; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3) !important; }

    .bg-danger-subtle { background-color: #f8d7da; }
    .bg-warning-subtle { background-color: #fff3cd; }
    .extra-small { font-size: 0.75rem; }
    .hover-lift { transition: all 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>