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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending'; // ค่าเริ่มต้นให้แสดง "รออนุมัติ" ก่อน
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';

// =========================================================
// 2. Query ดึงข้อมูลการจอง
// =========================================================
// ใช้ COALESCE เพื่อดึงชื่อจากตาราง students หรือ users (เผื่อทั้งอาจารย์และนิสิตจองได้)
$sql = "SELECT b.*, r.room_code, r.room_name, 
               COALESCE(s.first_name, u.first_name) as first_name,
               COALESCE(s.last_name, u.last_name) as last_name
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        LEFT JOIN students s ON b.user_id = s.student_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (b.user_id LIKE :search1 OR r.room_name LIKE :search2 OR r.room_code LIKE :search3)";
    $search_term = "%$search%";
    $params[':search1'] = $search_term;
    $params[':search2'] = $search_term;
    $params[':search3'] = $search_term;
}

if ($filter_status !== 'all') {
    $sql .= " AND b.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_date)) {
    $sql .= " AND b.booking_date = :bdate";
    $params[':bdate'] = $filter_date;
}

// เรียงลำดับ: ถ้าเป็น pending ให้เอาอันที่เพิ่งจองขึ้นก่อน, ถ้าเป็นสถานะอื่นให้เรียงตามวันที่จองใช้งาน
if ($filter_status == 'pending') {
    $sql .= " ORDER BY b.created_at ASC"; 
} else {
    $sql .= " ORDER BY b.booking_date DESC, b.start_time DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติภาพรวม (KPIs)
// =========================================================
$today = date('Y-m-d');
$count_pending = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status='pending'")->fetchColumn();
$count_today = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE booking_date='$today' AND status='approved'")->fetchColumn();
$count_all = $pdo->query("SELECT COUNT(*) FROM room_bookings")->fetchColumn();

// =========================================================
// โหลด Layout
// =========================================================
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงสถานะการจองเป็น Badge
function getBookingStatusBadge($status) {
    if($status == 'pending') return '<span class="badge bg-warning text-dark shadow-sm px-3 py-2"><i class="fa-regular fa-clock me-1"></i> รออนุมัติ</span>';
    if($status == 'approved') return '<span class="badge bg-success shadow-sm px-3 py-2"><i class="fa-solid fa-check-circle me-1"></i> อนุมัติแล้ว</span>';
    if($status == 'rejected') return '<span class="badge bg-danger shadow-sm px-3 py-2"><i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ</span>';
    if($status == 'cancelled') return '<span class="badge bg-secondary shadow-sm px-3 py-2"><i class="fa-solid fa-ban me-1"></i> ยกเลิก</span>';
}

// ฟังก์ชันแปลงวันที่ภาษาไทย
function thaiDateShort($date) {
    $thai_months = ["","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
    $time = strtotime($date);
    return date('d', $time) . ' ' . $thai_months[date('n', $time)] . ' ' . (date('Y', $time) + 543);
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-calendar-check me-2"></i>ระบบจัดการการจองห้อง</h1>
            <p class="text-muted small mb-0">ตรวจสอบและพิจารณาอนุมัติคำร้องขอใช้ห้องเรียน ห้องซ้อม และสตูดิโอ</p>
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
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white" style="border-left: 4px solid var(--msu-gold) !important;">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning rounded-circle d-flex align-items-center justify-content-center me-3 position-relative" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-bell fs-4 text-warning"></i>
                        <?php if($count_pending > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle p-2 bg-danger border border-light rounded-circle"></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">คำร้องรอพิจารณา</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_pending); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-calendar-day fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">คิวใช้งานห้อง "วันนี้"</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_today); ?> <span class="fs-6 text-muted fw-normal">คิว</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-primary border-4">
                <div class="card-body p-3 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-chart-simple fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 small">การจองสะสมทั้งหมด</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($count_all); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3 p-md-4">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-muted">ค้นหารหัสผู้จอง / ชื่อห้อง</label>
                    <div class="input-group shadow-sm rounded-3 overflow-hidden">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-0 bg-light" placeholder="ระบุคำค้นหา..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">เลือกวันที่ใช้งาน</label>
                    <input type="date" name="date" class="form-control bg-light border-0 shadow-sm" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                <div class="col-md-5">
                    <button type="submit" class="btn btn-dark fw-bold px-4 shadow-sm rounded-3">ค้นหา</button>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">ล้างค่า</a>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                <a href="?status=all&search=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'all' ? 'btn-dark' : 'btn-outline-secondary'; ?>">ทั้งหมด</a>
                <a href="?status=pending&search=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'pending' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?>"><i class="fa-regular fa-clock me-1"></i> รอพิจารณา</a>
                <a href="?status=approved&search=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'approved' ? 'btn-success' : 'btn-outline-success'; ?>"><i class="fa-solid fa-check-circle me-1"></i> อนุมัติแล้ว</a>
                <a href="?status=rejected&search=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_status == 'rejected' ? 'btn-danger' : 'btn-outline-danger'; ?>"><i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="roomBookingTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">รหัสคำขอ / วันที่ยื่น</th>
                            <th>ผู้ขอจอง</th>
                            <th>ห้องที่ต้องการใช้</th>
                            <th>วันที่ / เวลา</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($bookings as $index => $bk): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark small">BK-<?php echo str_pad($bk['booking_id'], 4, '0', STR_PAD_LEFT); ?></div>
                                <div class="extra-small text-muted mt-1" title="วันที่ยื่นคำขอ"><i class="fa-regular fa-clock me-1"></i><?php echo date('d/m/y H:i', strtotime($bk['created_at'])); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($bk['first_name'].' '.$bk['last_name'] ?: 'ไม่ระบุชื่อ'); ?></div>
                                <div class="extra-small text-muted">ID: <?php echo htmlspecialchars($bk['user_id']); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-purple"><?php echo htmlspecialchars($bk['room_code']); ?></div>
                                <div class="extra-small text-muted text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($bk['room_name']); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><i class="fa-regular fa-calendar me-1 text-primary"></i> <?php echo thaiDateShort($bk['booking_date']); ?></div>
                                <div class="small text-danger fw-bold"><i class="fa-regular fa-clock me-1"></i> <?php echo date('H:i', strtotime($bk['start_time'])); ?> - <?php echo date('H:i', strtotime($bk['end_time'])); ?> น.</div>
                            </td>
                            <td class="text-center"><?php echo getBookingStatusBadge($bk['status']); ?></td>
                            <td class="text-center pe-4">
                                <a href="view_booking.php?id=<?php echo $bk['booking_id']; ?>" class="btn btn-sm <?php echo $bk['status'] == 'pending' ? 'btn-primary-custom' : 'btn-outline-secondary'; ?> hover-lift rounded-3">
                                    <?php if($bk['status'] == 'pending'): ?>
                                        <i class="fa-solid fa-clipboard-check"></i> พิจารณา
                                    <?php else: ?>
                                        <i class="fa-solid fa-eye"></i> ดูรายละเอียด
                                    <?php endif; ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
    .btn-primary-custom { background-color: var(--msu-purple); color: white; border: none; }
    .btn-primary-custom:hover { background-color: var(--msu-gold); color: white; }
    
    /* 💡 CSS สำหรับปุ่มจองห้องสีส้ม */
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

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#roomBookingTable').DataTable({
        "pageLength": 15,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json",
            "emptyTable": "<div class='text-center py-5'><div class='text-muted mb-3'><i class='fa-regular fa-calendar-xmark fa-3x'></i></div><h5 class='fw-bold text-muted'>ไม่พบข้อมูลการจอง</h5><p class='small'>ไม่มีรายการคำร้องในสถานะหรือเงื่อนไขที่ค้นหา</p></div>"
        },
        "order": [], // ปิดการเรียงลำดับอัตโนมัติของ DataTables เพื่อให้ยึดตามที่ PHP Query มา
        "searching": true // เปิดช่องค้นหาของ DataTables ไว้เผื่อกรองข้อมูลเพิ่มเติมในหน้านั้นๆ
    });
});
</script>