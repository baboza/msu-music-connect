<?php
// เปิด Error ชั่วคราว (เอาออกตอนใช้งานจริง)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// =========================================================
// 1. รับค่าการค้นหาและตัวกรอง
// =========================================================
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// =========================================================
// 2. Query ดึงข้อมูลการแจ้งซ่อม (เฉพาะของตัวเองเท่านั้น)
// =========================================================
$sql = "SELECT rr.*, r.room_code, r.room_name 
        FROM repair_requests rr
        LEFT JOIN rooms r ON rr.room_id = r.room_id
        WHERE rr.reporter_id = :user_id";
$params = [':user_id' => $user_id];

if ($filter_status !== 'all') {
    $sql .= " AND rr.status = :status";
    $params[':status'] = $filter_status;
}

$sql .= " ORDER BY rr.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$my_repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติของตัวเอง
// =========================================================
$count_all = $pdo->prepare("SELECT COUNT(*) FROM repair_requests WHERE reporter_id = ?");
$count_all->execute([$user_id]);
$count_all = $count_all->fetchColumn();

$count_pending = $pdo->prepare("SELECT COUNT(*) FROM repair_requests WHERE reporter_id = ? AND status = 'pending'");
$count_pending->execute([$user_id]);
$count_pending = $count_pending->fetchColumn();

$count_completed = $pdo->prepare("SELECT COUNT(*) FROM repair_requests WHERE reporter_id = ? AND status = 'completed'");
$count_completed->execute([$user_id]);
$count_completed = $count_completed->fetchColumn();

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงระดับความรุนแรง
function getDamageLevelBadge($level) {
    if($level == 'critical') return '<span class="badge bg-danger px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> วิกฤต</span>';
    if($level == 'medium') return '<span class="badge bg-warning text-dark px-2 py-1">ปานกลาง</span>';
    return '<span class="badge bg-info text-white px-2 py-1">เล็กน้อย</span>';
}

// ฟังก์ชันแปลงสถานะงานซ่อม
function getRepairStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge bg-secondary shadow-sm px-3 py-2"><i class="fa-regular fa-clock me-1"></i> รอรับเรื่อง</span>',
        'in_progress' => '<span class="badge bg-primary shadow-sm px-3 py-2"><i class="fa-solid fa-screwdriver-wrench me-1"></i> กำลังซ่อม</span>',
        'completed' => '<span class="badge bg-success shadow-sm px-3 py-2"><i class="fa-solid fa-check-double me-1"></i> เสร็จสิ้น</span>',
        'cancelled' => '<span class="badge bg-light text-muted border px-3 py-2">ยกเลิก</span>'
    ];
    return $badges[$status] ?? $status;
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-wrench me-2"></i>ประวัติการแจ้งซ่อมของฉัน</h1>
            <p class="text-muted small mb-0">ติดตามสถานะการซ่อมบำรุงอุปกรณ์และอาคารสถานที่ที่คุณได้แจ้งไว้</p>
        </div>
        
        <div class="btn-toolbar mb-2 mb-md-0 mt-3 mt-md-0">
            <a href="create.php" class="btn btn-orange px-4 py-2 rounded-pill fw-bold shadow-sm hover-lift d-flex align-items-center">
                <i class="fa-solid fa-plus-circle me-2 fs-5"></i> แจ้งซ่อมใหม่
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-primary border-4 h-100">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-list-ul fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">แจ้งซ่อมทั้งหมด</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_all); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-warning border-4 h-100">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning rounded-circle d-flex align-items-center justify-content-center me-3 position-relative" style="width: 50px; height: 50px;">
                        <i class="fa-regular fa-clock fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">อยู่ระหว่างดำเนินการ</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_pending); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-success border-4 h-100">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-check-double fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">ซ่อมเสร็จสิ้นแล้ว</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_completed); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3 d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-bold text-muted my-auto me-2 small"><i class="fa-solid fa-filter me-1"></i> กรองสถานะ:</span>
            <a href="?status=all" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">ทั้งหมด</a>
            <a href="?status=pending" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'pending' ? 'btn-secondary text-white' : 'btn-outline-secondary'; ?>">รอรับเรื่อง</a>
            <a href="?status=in_progress" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'in_progress' ? 'btn-primary' : 'btn-outline-primary'; ?>">กำลังซ่อม</a>
            <a href="?status=completed" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'completed' ? 'btn-success' : 'btn-outline-success'; ?>">เสร็จสิ้น</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">รหัสแจ้งซ่อม</th>
                            <th>อุปกรณ์ / สถานที่</th>
                            <th>วันที่แจ้ง</th>
                            <th class="text-center">ความรุนแรง</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_repairs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-muted mb-3"><i class="fa-solid fa-toolbox fa-3x opacity-50"></i></div>
                                    <h5 class="fw-bold text-muted">คุณยังไม่มีประวัติการแจ้งซ่อม</h5>
                                    <p class="small">คลิกปุ่ม "แจ้งซ่อมใหม่" ด้านบนหากพบอุปกรณ์ชำรุด</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($my_repairs as $rp): ?>
                            <tr>
                                <td class="ps-4 text-muted small fw-bold">#<?php echo $rp['repair_id']; ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($rp['equipment_name']); ?></div>
                                    <div class="extra-small text-purple fw-bold">
                                        <i class="fa-solid fa-location-dot me-1"></i> ห้อง: <?php echo htmlspecialchars($rp['room_code'] ?: 'ไม่ระบุห้อง'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small text-muted"><i class="fa-regular fa-calendar me-1"></i> <?php echo date('d/m/Y', strtotime($rp['created_at'])); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('H:i', strtotime($rp['created_at'])); ?> น.</div>
                                </td>
                                <td class="text-center"><?php echo getDamageLevelBadge($rp['damage_level']); ?></td>
                                <td class="text-center"><?php echo getRepairStatusBadge($rp['status']); ?></td>
                                <td class="text-center pe-4">
                                    <a href="view_repair.php?id=<?php echo $rp['repair_id']; ?>" class="btn btn-sm btn-outline-secondary hover-lift rounded-3 fw-bold">
                                        <i class="fa-solid fa-eye"></i> รายละเอียด
                                    </a>
                                    
                                    <?php if($rp['status'] == 'pending'): ?>
                                        <a href="cancel_repair.php?id=<?php echo $rp['repair_id']; ?>" class="btn btn-sm btn-outline-danger hover-lift rounded-3 fw-bold ms-1" onclick="return confirm('ต้องการยกเลิกการแจ้งซ่อมนี้ใช่หรือไม่?');">
                                            <i class="fa-solid fa-xmark"></i> ยกเลิก
                                        </a>
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

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    
    .btn-orange { background-color: #fd7e14; color: white; border: none; }
    .btn-orange:hover { background-color: #e86c0c; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3) !important; }
    
    .bg-primary-subtle { background-color: #cfe2ff; }
    .bg-warning-subtle { background-color: #fff3cd; }
    .bg-success-subtle { background-color: #d1e7dd; }
    .extra-small { font-size: 0.75rem; }
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>