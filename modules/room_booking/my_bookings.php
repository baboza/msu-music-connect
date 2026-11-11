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
// 2. Query ดึงข้อมูลการจอง (เฉพาะของตัวเองเท่านั้น)
// =========================================================
$sql = "SELECT b.*, r.room_code, r.room_name 
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        WHERE b.user_id = :user_id";
$params = [':user_id' => $user_id];

if ($filter_status !== 'all') {
    $sql .= " AND b.status = :status";
    $params[':status'] = $filter_status;
}

$sql .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$my_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติของตัวเอง
// =========================================================
$count_all = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE user_id = ?");
$count_all->execute([$user_id]);
$count_all = $count_all->fetchColumn();

$count_approved = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE user_id = ? AND status = 'approved'");
$count_approved->execute([$user_id]);
$count_approved = $count_approved->fetchColumn();

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงสถานะการจองเป็น Badge
function getBookingStatusBadge($status) {
    if($status == 'pending') return '<span class="badge bg-warning text-dark shadow-sm px-3 py-2"><i class="fa-regular fa-clock me-1"></i> รอพิจารณา</span>';
    if($status == 'approved') return '<span class="badge bg-success shadow-sm px-3 py-2"><i class="fa-solid fa-check-circle me-1"></i> อนุมัติแล้ว</span>';
    if($status == 'rejected') return '<span class="badge bg-danger shadow-sm px-3 py-2"><i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ</span>';
    if($status == 'cancelled') return '<span class="badge bg-secondary shadow-sm px-3 py-2"><i class="fa-solid fa-ban me-1"></i> ยกเลิก</span>';
}

function thaiDateShort($date) {
    $thai_months = ["","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
    $time = strtotime($date);
    return date('d', $time) . ' ' . $thai_months[date('n', $time)] . ' ' . (date('Y', $time) + 543);
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-calendar-check me-2"></i>ประวัติการจองห้องของฉัน</h1>
            <p class="text-muted small mb-0">ตรวจสอบสถานะการจองห้องเรียน ห้องซ้อม และสตูดิโอของคุณ</p>
        </div>
        
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="calendar.php" class="btn btn-outline-purple rounded-pill px-4 fw-bold shadow-sm hover-lift d-flex align-items-center">
                <i class="fa-regular fa-calendar-days me-2 fs-5"></i> ผังปฏิทิน
            </a>
            <a href="create.php" class="btn btn-orange px-4 py-2 rounded-pill fw-bold shadow-sm hover-lift d-flex align-items-center">
                <i class="fa-solid fa-plus-circle me-2 fs-5"></i> จองห้องใหม่
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-primary border-4 h-100">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-list-ul fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">รายการจองทั้งหมด</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_all); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-success border-4 h-100">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-check-double fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">อนุมัติแล้ว</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_approved); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3 d-flex flex-wrap gap-2">
            <span class="fw-bold text-muted my-auto me-2 small">กรองสถานะ:</span>
            <a href="?status=all" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">ทั้งหมด</a>
            <a href="?status=pending" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'pending' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?>">รอพิจารณา</a>
            <a href="?status=approved" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">อนุมัติแล้ว</a>
            <a href="?status=rejected" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'rejected' ? 'btn-danger' : 'btn-outline-danger'; ?>">ไม่อนุมัติ</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">รหัสจอง</th>
                            <th>ห้องที่ต้องการใช้</th>
                            <th>วันที่ / เวลา</th>
                            <th>วัตถุประสงค์</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_bookings)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-muted mb-3"><i class="fa-regular fa-folder-open fa-3x"></i></div>
                                    <h5 class="fw-bold text-muted">คุณยังไม่มีประวัติการจองห้อง</h5>
                                    <p class="small">คลิกปุ่ม "จองห้องใหม่" ด้านบนเพื่อสร้างคำร้อง</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($my_bookings as $bk): ?>
                            <tr>
                                <td class="ps-4 text-muted small fw-bold">#<?php echo $bk['booking_id']; ?></td>
                                <td>
                                    <div class="fw-bold text-purple"><?php echo htmlspecialchars($bk['room_code']); ?></div>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($bk['room_name']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><i class="fa-regular fa-calendar me-1 text-primary"></i> <?php echo thaiDateShort($bk['booking_date']); ?></div>
                                    <div class="small text-danger fw-bold"><i class="fa-regular fa-clock me-1"></i> <?php echo date('H:i', strtotime($bk['start_time'])); ?> - <?php echo date('H:i', strtotime($bk['end_time'])); ?> น.</div>
                                </td>
                                <td>
                                    <div class="small text-muted text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($bk['purpose']); ?></div>
                                </td>
                                <td class="text-center"><?php echo getBookingStatusBadge($bk['status']); ?></td>
                                <td class="text-center pe-4">
                                    <a href="view_booking.php?id=<?php echo $bk['booking_id']; ?>" class="btn btn-sm btn-outline-secondary hover-lift rounded-3 fw-bold">
                                        <i class="fa-solid fa-eye"></i> รายละเอียด
                                    </a>
                                    
                                    <?php if($bk['status'] == 'pending'): ?>
                                        <a href="cancel_booking.php?id=<?php echo $bk['booking_id']; ?>" class="btn btn-sm btn-outline-danger hover-lift rounded-3 fw-bold ms-1" onclick="return confirm('ต้องการยกเลิกคำร้องจองห้องนี้ใช่หรือไม่?');">
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
    .btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
    .btn-outline-purple:hover { background-color: var(--msu-purple); color: white; }
    
    .btn-orange { background-color: #fd7e14; color: white; border: none; }
    .btn-orange:hover { background-color: #e86c0c; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3) !important; }
    
    .bg-primary-subtle { background-color: #cfe2ff; }
    .bg-success-subtle { background-color: #d1e7dd; }
    .extra-small { font-size: 0.75rem; }
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>