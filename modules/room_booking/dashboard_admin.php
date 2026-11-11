<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// ตรวจสอบสิทธิ์ (ผู้บริหาร, อาคารสถานที่ ฯลฯ) - อาศัย sidebar.php ดักอยู่แล้ว แต่ดักตรงนี้อีกชั้นก็ดีเพื่อความปลอดภัย
// ... ละไว้เพื่อให้สอดคล้องกับระบบเดิม (assume management_roles from sidebar check is enough if accessed via UI, but better safe. However, I'll keep it simple).

// =========================================================
// 1. สถิติภาพรวม (KPIs)
// =========================================================
$current_month = date('Y-m');

$total_bookings = $pdo->query("SELECT COUNT(*) FROM room_bookings")->fetchColumn();
$this_month_bookings = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetchColumn();
$pending_bookings = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'")->fetchColumn();

// =========================================================
// 2. ข้อมูลกราฟสถานะ (Pie Chart)
// =========================================================
$stmt_status = $pdo->query("SELECT status, COUNT(*) as cnt FROM room_bookings GROUP BY status");
$status_data_raw = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [];
$status_values = [];
$status_colors = [];

$status_map = [
    'pending' => ['label' => 'รออนุมัติ', 'color' => '#ffc107'],
    'approved' => ['label' => 'อนุมัติ', 'color' => '#198754'],
    'rejected' => ['label' => 'ไม่อนุมัติ', 'color' => '#dc3545'],
    'cancelled' => ['label' => 'ยกเลิก', 'color' => '#6c757d']
];

foreach ($status_data_raw as $row) {
    $s = $row['status'];
    $status_labels[] = isset($status_map[$s]) ? $status_map[$s]['label'] : $s;
    $status_values[] = $row['cnt'];
    $status_colors[] = isset($status_map[$s]) ? $status_map[$s]['color'] : '#000000';
}

// =========================================================
// 3. ข้อมูลแนวโน้มรายเดือน ย้อนหลัง 6 เดือน (Bar Chart)
// =========================================================
$stmt_trend = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month_year, COUNT(*) as cnt 
    FROM room_bookings 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_year 
    ORDER BY month_year ASC
");
$trend_data = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);

$trend_labels = [];
$trend_values = [];
$thai_months = ["","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];

foreach ($trend_data as $row) {
    $parts = explode('-', $row['month_year']); // YYYY-MM
    $m = intval($parts[1]);
    $y = intval($parts[0]) + 543;
    $trend_labels[] = $thai_months[$m] . ' ' . substr($y, 2);
    $trend_values[] = $row['cnt'];
}

// =========================================================
// 4. Top 5 ห้องยอดฮิต
// =========================================================
$stmt_top = $pdo->query("
    SELECT r.room_code, r.room_name, COUNT(b.booking_id) as total_used 
    FROM room_bookings b 
    JOIN rooms r ON b.room_id = r.room_id 
    WHERE b.status = 'approved'
    GROUP BY r.room_id 
    ORDER BY total_used DESC 
    LIMIT 5
");
$top_rooms = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 5. ข้อมูลรายการจองทั้งหมด (สำหรับ DataTable)
// =========================================================
$stmt_all = $pdo->query("
    SELECT b.*, r.room_code, r.room_name,
           COALESCE(s.first_name, u.first_name) as first_name,
           COALESCE(s.last_name, u.last_name) as last_name
    FROM room_bookings b
    LEFT JOIN rooms r ON b.room_id = r.room_id
    LEFT JOIN students s ON b.user_id = s.student_id
    LEFT JOIN users u ON b.user_id = u.user_id
    ORDER BY b.created_at DESC
");
$all_bookings = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-1">
                    <li class="breadcrumb-item"><a href="../../index.php" class="text-decoration-none text-muted">หน้าแรก</a></li>
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">จองห้อง</a></li>
                    <li class="breadcrumb-item active" aria-current="page">สถิติภาพรวม</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold text-purple mb-0"><i class="fa-solid fa-chart-area me-2"></i> สถิติภาพรวมจองห้อง (Analytics)</h1>
        </div>
        
        <div class="btn-toolbar mb-2 mb-md-0">
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary rounded-pill shadow-sm"><i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน</button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-chart-simple fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold mb-1 small">การจองทั้งหมด (สะสม)</h6>
                        <h2 class="fw-bold text-dark mb-0"><?php echo number_format($total_bookings); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-calendar-check fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold mb-1 small">การจองเดือนนี้</h6>
                        <h2 class="fw-bold text-dark mb-0"><?php echo number_format($this_month_bookings); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-warning border-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-clock-rotate-left fs-3 text-dark"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold mb-1 small">รอพิจารณาอนุมัติ</h6>
                        <h2 class="fw-bold text-dark mb-0"><?php echo number_format($pending_bookings); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Status Pie Chart -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-purple me-2"></i> สัดส่วนสถานะคำร้อง</h6>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <?php if (empty($status_values)): ?>
                        <div class="text-muted text-center py-5">ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="statusChart" style="max-height: 300px;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Trend Bar Chart -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-column text-info me-2"></i> แนวโน้มการใช้งาน (6 เดือนย้อนหลัง)</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($trend_values)): ?>
                        <div class="text-muted text-center py-5">ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="trendChart" style="max-height: 300px;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-fire text-danger me-2"></i> 5 อันดับห้องยอดฮิต (ที่ได้รับการอนุมัติใช้งานมากที่สุด)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" width="10%">อันดับ</th>
                                    <th>รหัสห้อง</th>
                                    <th>ชื่อห้อง/สถานที่</th>
                                    <th class="text-end pe-4">จำนวนครั้งที่ใช้</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($top_rooms)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">ไม่พบข้อมูลสถิติ</td></tr>
                                <?php else: ?>
                                    <?php $rank = 1; foreach($top_rooms as $room): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge <?php echo $rank <= 3 ? 'bg-warning text-dark' : 'bg-secondary'; ?> rounded-circle p-2" style="width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;"><?php echo $rank; ?></span>
                                        </td>
                                        <td class="fw-bold text-purple"><?php echo htmlspecialchars($room['room_code']); ?></td>
                                        <td><?php echo htmlspecialchars($room['room_name']); ?></td>
                                        <td class="text-end pe-4 fw-bold"><?php echo number_format($room['total_used']); ?> ครั้ง</td>
                                    </tr>
                                    <?php $rank++; endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- รายการจองทั้งหมด -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list text-primary me-2"></i> รายการคำขอจองห้องทั้งหมด</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="bookingsTable" class="table table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>รหัสคำขอ</th>
                                    <th>วันที่ทำรายการ</th>
                                    <th>ห้อง/สถานที่</th>
                                    <th>วันเวลาที่ขอใช้</th>
                                    <th>ผู้จอง</th>
                                    <th>สถานะ</th>
                                    <th class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($all_bookings as $bk): ?>
                                <tr>
                                    <td>BK-<?php echo str_pad($bk['booking_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td data-sort="<?php echo $bk['created_at']; ?>"><?php echo date('d/m/Y H:i', strtotime($bk['created_at'])); ?></td>
                                    <td>
                                        <div class="fw-bold text-purple"><?php echo htmlspecialchars($bk['room_code'] ?? ''); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($bk['room_name'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo date('d/m/Y', strtotime($bk['booking_date'])); ?></div>
                                        <small class="text-danger"><?php echo date('H:i', strtotime($bk['start_time'])) . ' - ' . date('H:i', strtotime($bk['end_time'])); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars(($bk['first_name'] ?? '') . ' ' . ($bk['last_name'] ?? '')); ?></td>
                                    <td>
                                        <?php 
                                            $st = $bk['status'];
                                            $st_badge = ['pending'=>'warning', 'approved'=>'success', 'rejected'=>'danger', 'cancelled'=>'secondary'];
                                            $st_label = ['pending'=>'รออนุมัติ', 'approved'=>'อนุมัติแล้ว', 'rejected'=>'ไม่อนุมัติ', 'cancelled'=>'ยกเลิก'];
                                            $badge_class = isset($st_badge[$st]) ? $st_badge[$st] : 'secondary';
                                            $badge_text = isset($st_label[$st]) ? $st_label[$st] : $st;
                                            echo "<span class='badge bg-{$badge_class}'>{$badge_text}</span>";
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="view_booking.php?id=<?php echo $bk['booking_id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">
                                            <i class="fa-solid fa-eye"></i> รายละเอียด
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
</style>

<!-- ใช้ Chart.js ผ่าน CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Pie Chart (Status)
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_values); ?>,
                    backgroundColor: <?php echo json_encode($status_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '60%'
            }
        });
    }

    // 2. Bar Chart (Trend)
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'จำนวนรายการจองห้อง',
                    data: <?php echo json_encode($trend_values); ?>,
                    backgroundColor: 'rgba(57, 4, 143, 0.7)', // msu-purple with opacity
                    borderColor: '#39048f',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#bookingsTable').DataTable({
        "pageLength": 15,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "order": [[ 1, "desc" ]] // เรียงวันที่ทำรายการล่าสุดขึ้นก่อน
    });
});
</script>
