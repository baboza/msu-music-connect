<?php
// เปิด Error ชั่วคราว (เอาออกตอนใช้งานจริง)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// =========================================================
// 1. รับค่าการค้นหา
// =========================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// =========================================================
// 2. Query ดึงข้อมูลห้อง
// =========================================================
$sql = "SELECT * FROM rooms WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (room_code LIKE :search1 OR room_name LIKE :search2)";
    $search_term = "%$search%";
    $params[':search1'] = $search_term;
    $params[':search2'] = $search_term;
}

if ($filter_status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $filter_status;
}

$sql .= " ORDER BY room_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติภาพรวม
// =========================================================
$total_rooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$active_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='active'")->fetchColumn();
$maintenance_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn();

// =========================================================
// โหลด Layout
// =========================================================
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงประเภทและสถานะ
function getRoomTypeIcon($type) {
    if ($type == 'practice') return '<i class="fa-solid fa-music text-primary me-2"></i> ห้องซ้อม';
    if ($type == 'lecture') return '<i class="fa-solid fa-chalkboard-user text-success me-2"></i> ห้องบรรยาย';
    if ($type == 'meeting') return '<i class="fa-solid fa-people-group text-info me-2"></i> ห้องประชุม';
    if ($type == 'studio') return '<i class="fa-solid fa-headphones text-danger me-2"></i> สตูดิโอ';
    return '<i class="fa-solid fa-door-closed text-secondary me-2"></i> อื่นๆ';
}

function getStatusBadge($status) {
    if($status == 'active') return '<span class="badge bg-success shadow-sm px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i> ปกติ (ว่าง)</span>';
    if($status == 'maintenance') return '<span class="badge bg-warning text-dark border border-warning shadow-sm px-3 py-2"><i class="fa-solid fa-wrench me-1"></i> แจ้งซ่อม/ปิดปรับปรุง</span>';
    if($status == 'inactive') return '<span class="badge bg-danger shadow-sm px-3 py-2"><i class="fa-solid fa-ban me-1"></i> ปิดใช้งานชั่วคราว</span>';
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-door-open me-2"></i>จัดการข้อมูลห้อง (Rooms)</h1>
            <p class="text-muted small mb-0">ระบบฐานข้อมูลห้องเรียน ห้องซ้อม และสตูดิโอ เพื่อใช้ในการจองและแจ้งซ่อม</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="add_room.php" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm hover-lift">
                <i class="fa-solid fa-plus me-1"></i> เพิ่มห้องใหม่
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-primary border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-building fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">จำนวนห้องทั้งหมด</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($total_rooms); ?> <span class="fs-6 text-muted fw-normal">ห้อง</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-circle-check fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">ห้องที่พร้อมใช้งาน (Active)</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($active_rooms); ?> <span class="fs-6 text-muted fw-normal">ห้อง</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white" style="border-left: 4px solid var(--msu-gold) !important;">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-wrench fs-4 text-warning"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">ห้องที่กำลังแจ้งซ่อม</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($maintenance_rooms); ?> <span class="fs-6 text-muted fw-normal">ห้อง</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3 p-md-4">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-muted">ค้นหารหัส / ชื่อห้อง</label>
                    <div class="input-group shadow-sm rounded-3 overflow-hidden">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-0 bg-light" placeholder="เช่น MS-101, ห้องซ้อมเปียโน..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark fw-bold px-4 shadow-sm rounded-3">ค้นหา</button>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">ล้างค่า</a>
                </div>
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                <a href="?status=all&search=<?php echo urlencode($search); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">ทั้งหมด</a>
                <a href="?status=active&search=<?php echo urlencode($search); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'active' ? 'btn-success' : 'btn-outline-success'; ?>"><i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน</a>
                <a href="?status=maintenance&search=<?php echo urlencode($search); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'maintenance' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?>"><i class="fa-solid fa-wrench me-1"></i> แจ้งซ่อม</a>
                <a href="?status=inactive&search=<?php echo urlencode($search); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'inactive' ? 'btn-danger' : 'btn-outline-danger'; ?>"><i class="fa-solid fa-ban me-1"></i> ปิดใช้งาน</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">รหัสห้อง</th>
                            <th>ข้อมูลห้อง</th>
                            <th>ประเภท</th>
                            <th class="text-center">ความจุ</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rooms)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-muted mb-2"><i class="fa-solid fa-door-closed fa-3x"></i></div>
                                    <h5 class="fw-bold text-muted">ไม่พบข้อมูลห้อง</h5>
                                    <p class="small">คลิกที่ปุ่ม 'เพิ่มห้องใหม่' เพื่อเริ่มต้นระบบ</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($rooms as $room): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-purple"><?php echo htmlspecialchars($room['room_code']); ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($room['room_name']); ?></div>
                                    <?php if(!empty($room['equipment_details'])): ?>
                                        <div class="extra-small text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($room['equipment_details']); ?>">
                                            <i class="fa-solid fa-box-open me-1"></i> <?php echo htmlspecialchars($room['equipment_details']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo getRoomTypeIcon($room['room_type']); ?></td>
                                <td class="text-center fw-bold text-secondary"><?php echo htmlspecialchars($room['capacity']); ?> <span class="extra-small fw-normal">คน</span></td>
                                <td class="text-center"><?php echo getStatusBadge($room['status']); ?></td>
                                <td class="text-center pe-4">
                                    <a href="edit_room.php?id=<?php echo $room['room_id']; ?>" class="btn btn-sm btn-outline-purple hover-lift rounded-3" title="แก้ไขข้อมูลห้อง/เปลี่ยนสถานะ">
                                        <i class="fa-solid fa-pen-to-square"></i> แก้ไข
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
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .bg-purple { background-color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; border: none; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
    .btn-outline-purple:hover { background-color: var(--msu-purple); color: white; }
    .text-gold { color: var(--msu-gold); }
    .bg-primary-subtle { background-color: #cfe2ff; }
    .bg-warning-subtle { background-color: #fff3cd; }
    .bg-success-subtle { background-color: #d1e7dd; }
    .extra-small { font-size: 0.75rem; }
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>