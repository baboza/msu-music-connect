<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// =========================================================
// 1. สถิติภาพรวม (KPIs)
// =========================================================
$total_repairs = $pdo->query("SELECT COUNT(*) FROM repair_requests")->fetchColumn();
$completed_repairs = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'completed'")->fetchColumn();
$critical_pending = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE damage_level = 'critical' AND status != 'completed'")->fetchColumn();

// =========================================================
// 2. ข้อมูลระดับความรุนแรง (Doughnut Chart)
// =========================================================
$stmt_level = $pdo->query("SELECT damage_level, COUNT(*) as cnt FROM repair_requests GROUP BY damage_level");
$level_data_raw = $stmt_level->fetchAll(PDO::FETCH_ASSOC);

$level_labels = [];
$level_values = [];
$level_colors = [];

$level_map = [
    'low' => ['label' => 'เล็กน้อย (Low)', 'color' => '#0dcaf0'],
    'medium' => ['label' => 'ปานกลาง (Medium)', 'color' => '#ffc107'],
    'critical' => ['label' => 'วิกฤต (Critical)', 'color' => '#dc3545']
];

foreach ($level_data_raw as $row) {
    $lvl = $row['damage_level'];
    $level_labels[] = isset($level_map[$lvl]) ? $level_map[$lvl]['label'] : $lvl;
    $level_values[] = $row['cnt'];
    $level_colors[] = isset($level_map[$lvl]) ? $level_map[$lvl]['color'] : '#000000';
}

// =========================================================
// 3. ข้อมูลสถานะการซ่อม (Pie Chart)
// =========================================================
$stmt_status = $pdo->query("SELECT status, COUNT(*) as cnt FROM repair_requests GROUP BY status");
$status_data_raw = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [];
$status_values = [];
$status_colors = [];

$status_map = [
    'pending' => ['label' => 'รอรับเรื่อง', 'color' => '#6c757d'],
    'in_progress' => ['label' => 'กำลังซ่อม', 'color' => '#0d6efd'],
    'completed' => ['label' => 'เสร็จสิ้น', 'color' => '#198754'],
    'cancelled' => ['label' => 'ยกเลิก', 'color' => '#adb5bd']
];

foreach ($status_data_raw as $row) {
    $s = $row['status'];
    $status_labels[] = isset($status_map[$s]) ? $status_map[$s]['label'] : $s;
    $status_values[] = $row['cnt'];
    $status_colors[] = isset($status_map[$s]) ? $status_map[$s]['color'] : '#000000';
}

// =========================================================
// 4. Top 5 ห้องที่มีการซ่อมบ่อยที่สุด
// =========================================================
$stmt_top = $pdo->query("
    SELECT r.room_code, r.room_name, COUNT(rr.repair_id) as total_repairs 
    FROM repair_requests rr 
    JOIN rooms r ON rr.room_id = r.room_id 
    GROUP BY r.room_id 
    ORDER BY total_repairs DESC 
    LIMIT 5
");
$top_rooms = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 5. ข้อมูลรายการแจ้งซ่อมทั้งหมด (สำหรับ DataTable)
// =========================================================
$stmt_all = $pdo->query("
    SELECT rr.*, r.room_code, r.room_name,
           COALESCE(s.first_name, u.first_name) as first_name,
           COALESCE(s.last_name, u.last_name) as last_name
    FROM repair_requests rr
    LEFT JOIN rooms r ON rr.room_id = r.room_id
    LEFT JOIN students s ON rr.user_id = s.student_id
    LEFT JOIN users u ON rr.user_id = u.user_id
    ORDER BY rr.created_at DESC
");
$all_repairs = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

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
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">แจ้งซ่อม</a></li>
                    <li class="breadcrumb-item active" aria-current="page">สถิติภาพรวม</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold text-purple mb-0"><i class="fa-solid fa-chart-column me-2"></i> สถิติภาพรวมแจ้งซ่อมบำรุง (Analytics)</h1>
        </div>
        
        <div class="btn-toolbar mb-2 mb-md-0">
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary rounded-pill shadow-sm"><i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน</button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-primary border-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-toolbox fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold mb-1 small">รายการแจ้งซ่อมทั้งหมด</h6>
                        <h2 class="fw-bold text-dark mb-0"><?php echo number_format($total_repairs); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-success-subtle text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-check-double fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold mb-1 small">ซ่อมเสร็จสิ้นแล้ว</h6>
                        <h2 class="fw-bold text-dark mb-0"><?php echo number_format($completed_repairs); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-danger border-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-flex align-items-center justify-content-center me-3 position-relative" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-radiation fs-3"></i>
                        <?php if($critical_pending > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle p-2 bg-danger border border-light rounded-circle animate__animated animate__flash animate__infinite"></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 class="text-danger fw-bold mb-1 small">งานวิกฤต (รอซ่อม)</h6>
                        <h2 class="fw-bold text-danger mb-0"><?php echo number_format($critical_pending); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Level Doughnut Chart -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i> สัดส่วนความรุนแรง</h6>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <?php if (empty($level_values)): ?>
                        <div class="text-muted text-center py-5">ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="levelChart" style="max-height: 250px;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Status Pie Chart -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-primary me-2"></i> สัดส่วนสถานะงานซ่อม</h6>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <?php if (empty($status_values)): ?>
                        <div class="text-muted text-center py-5">ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="statusChart" style="max-height: 250px;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-building-circle-exclamation text-danger me-2"></i> 5 อันดับห้องที่มีการแจ้งซ่อมบ่อยที่สุด</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" width="10%">อันดับ</th>
                                    <th>รหัสห้อง</th>
                                    <th>ชื่อห้อง/สถานที่</th>
                                    <th class="text-end pe-4">จำนวนครั้งที่แจ้งซ่อม</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($top_rooms)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">ไม่พบข้อมูลสถิติ</td></tr>
                                <?php else: ?>
                                    <?php $rank = 1; foreach($top_rooms as $room): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge <?php echo $rank <= 2 ? 'bg-danger' : 'bg-secondary'; ?> rounded-circle p-2" style="width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;"><?php echo $rank; ?></span>
                                        </td>
                                        <td class="fw-bold text-purple"><?php echo htmlspecialchars($room['room_code']); ?></td>
                                        <td><?php echo htmlspecialchars($room['room_name']); ?></td>
                                        <td class="text-end pe-4 fw-bold text-danger"><?php echo number_format($room['total_repairs']); ?> ครั้ง</td>
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

    <!-- รายการแจ้งซ่อมทั้งหมด -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list text-primary me-2"></i> รายการแจ้งซ่อมทั้งหมด</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="repairsTable" class="table table-hover align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>รหัสแจ้งซ่อม</th>
                                    <th>วันที่แจ้ง</th>
                                    <th>ห้อง</th>
                                    <th>ปัญหาที่พบ</th>
                                    <th>ความรุนแรง</th>
                                    <th>สถานะ</th>
                                    <th>ผู้แจ้ง</th>
                                    <th class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($all_repairs as $repair): ?>
                                <tr>
                                    <td>REP-<?php echo str_pad($repair['repair_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($repair['created_at'])); ?></td>
                                    <td>
                                        <div class="fw-bold text-purple"><?php echo htmlspecialchars($repair['room_code'] ?? ''); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($repair['room_name'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo mb_strimwidth(htmlspecialchars($repair['problem_desc'] ?? ''), 0, 50, '...'); ?></td>
                                    <td>
                                        <?php 
                                            $lvl = $repair['damage_level'];
                                            $lvl_badge = ['low'=>'info', 'medium'=>'warning', 'critical'=>'danger'];
                                            $lvl_label = ['low'=>'เล็กน้อย', 'medium'=>'ปานกลาง', 'critical'=>'วิกฤต'];
                                            $badge_class = isset($lvl_badge[$lvl]) ? $lvl_badge[$lvl] : 'secondary';
                                            $badge_text = isset($lvl_label[$lvl]) ? $lvl_label[$lvl] : $lvl;
                                            echo "<span class='badge bg-{$badge_class}'>{$badge_text}</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $st = $repair['status'];
                                            $st_badge = ['pending'=>'secondary', 'in_progress'=>'primary', 'completed'=>'success', 'cancelled'=>'light text-dark'];
                                            $st_label = ['pending'=>'รอรับเรื่อง', 'in_progress'=>'กำลังซ่อม', 'completed'=>'เสร็จสิ้น', 'cancelled'=>'ยกเลิก'];
                                            $st_class = isset($st_badge[$st]) ? $st_badge[$st] : 'secondary';
                                            $st_text = isset($st_label[$st]) ? $st_label[$st] : $st;
                                            echo "<span class='badge bg-{$st_class} border'>{$st_text}</span>";
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(($repair['first_name'] ?? '') . ' ' . ($repair['last_name'] ?? '')); ?></td>
                                    <td class="text-center">
                                        <a href="view_repair.php?id=<?php echo $repair['repair_id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">
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
    // 1. Doughnut Chart (Damage Level)
    const levelCtx = document.getElementById('levelChart');
    if (levelCtx) {
        new Chart(levelCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($level_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($level_values); ?>,
                    backgroundColor: <?php echo json_encode($level_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                },
                cutout: '60%'
            }
        });
    }

    // 2. Pie Chart (Status)
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'pie',
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
                    legend: { position: 'right' }
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
    $('#repairsTable').DataTable({
        "pageLength": 10,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "order": [[ 1, "desc" ]]
    });
});
</script>
