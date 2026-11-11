<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';

$user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. ดึงข้อมูลสถิติของตัวเองสำหรับ Dashboard (ภาพรวมทั้งหมด)
// ---------------------------------------------------------
$stmt_stats = $pdo->prepare("
    SELECT 
        SUM(hours_spent) as total_mins, 
        AVG(harmony_score) as avg_mood, 
        COUNT(*) as total_logs 
    FROM work_logs 
    WHERE user_id = ?
");
$stmt_stats->execute([$user_id]);
$my_stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$total_mins = floatval($my_stats['total_mins'] ?: 0);
$total_logs = $my_stats['total_logs'] ?: 0;
$avg_mood = round($my_stats['avg_mood'] ?: 0, 1);

// ---------------------------------------------------------
// 2. ดึงข้อมูลสำหรับสร้างกราฟ (15 วันล่าสุดที่มีการทำงาน)
// ---------------------------------------------------------
$stmt_chart = $pdo->prepare("
    SELECT log_date, SUM(hours_spent) as daily_mins 
    FROM work_logs 
    WHERE user_id = ? 
    GROUP BY log_date 
    ORDER BY log_date DESC 
    LIMIT 15
");
$stmt_chart->execute([$user_id]);
$trend_data = array_reverse($stmt_chart->fetchAll(PDO::FETCH_ASSOC)); // พลิกกลับให้วันที่เก่าสุดอยู่ซ้าย

$chart_labels = [];
$chart_data = [];
foreach($trend_data as $row) {
    $chart_labels[] = date('d/m', strtotime($row['log_date']));
    $chart_data[] = floatval($row['daily_mins']);
}

// ---------------------------------------------------------
// 3. ดึงประวัติการบันทึกงานทั้งหมดสำหรับตาราง
// ---------------------------------------------------------
$sql = "SELECT l.*, t.task_name 
        FROM work_logs l
        JOIN work_tasks t ON l.task_id = t.task_id
        WHERE l.user_id = ?
        ORDER BY l.log_date DESC, l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$worklogs = $stmt->fetchAll();

function getHarmonyDisplay($score) {
    switch ($score) {
        case 1: return '<i class="fa-solid fa-face-frown text-danger fs-5" title="เหนื่อยล้า"></i>';
        case 2: return '<i class="fa-solid fa-face-meh text-warning fs-5" title="ตึงเครียด"></i>';
        case 3: return '<i class="fa-solid fa-face-smile text-primary fs-5" title="ปกติ/เรื่อยๆ"></i>';
        case 4: return '<i class="fa-solid fa-face-smile-beam text-info fs-5" title="ดีมาก"></i>';
        case 5: return '<i class="fa-solid fa-face-laugh-squint text-success fs-5" title="ยอดเยี่ยม!"></i>';
        default: return '<i class="fa-solid fa-minus text-muted"></i>';
    }
}

// 💡 ฟังก์ชันสร้าง Badge สำหรับระดับความเร่งด่วน
function getUrgencyBadge($level) {
    switch ($level) {
        case 'ด่วน': 
            return '<span class="badge bg-warning text-dark shadow-sm px-2 py-1 fw-normal">ด่วน</span>';
        case 'ด่วนมาก': 
            return '<span class="badge shadow-sm px-2 py-1 fw-normal" style="background-color: #fd7e14; color: white;">ด่วนมาก</span>';
        case 'ด่วนที่สุด': 
            return '<span class="badge bg-danger shadow-sm px-2 py-1 fw-normal">ด่วนที่สุด</span>';
        default: 
            return '<span class="badge bg-secondary bg-opacity-25 text-dark shadow-sm px-2 py-1 fw-normal">ปกติ</span>';
    }
}

// ดึง Sidebar มาแสดง (ใส่ไว้ตรงนี้เพื่อไม่ให้กระทบ Tag Head ของ DataTables)
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-list-check me-2"></i>My Worklog Dashboard</h1>
        <a href="create.php" class="btn btn-purple shadow-sm rounded-pill px-4 fw-bold">
            <i class="fa-solid fa-plus me-1"></i> บันทึกงานวันนี้
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm border-start border-success border-4 h-100 bg-white">
                <div class="card-body">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1">เวลาทำงานรวม (ทั้งหมด)</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-success fw-bold mb-0">
                            <?php 
                                $h = floor($total_mins / 60);
                                $m = $total_mins % 60;
                                echo ($h > 0 ? "{$h} <small class='fs-6 fw-normal'>ชม.</small> " : "") . ($m > 0 ? "{$m} <small class='fs-6 fw-normal'>น.</small>" : ($h==0?"0 <small class='fs-6 fw-normal'>น.</small>":""));
                            ?>
                        </h3>
                        <div class="bg-success-subtle text-success rounded p-2"><i class="fa-solid fa-stopwatch fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm border-start border-purple border-4 h-100 bg-white">
                <div class="card-body">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1">จำนวนงานที่บันทึก (ทั้งหมด)</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-purple fw-bold mb-0"><?php echo $total_logs; ?> <small class="fs-6 fw-normal">รายการ</small></h3>
                        <div class="bg-purple-subtle text-purple rounded p-2"><i class="fa-solid fa-briefcase fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm border-start border-info border-4 h-100 bg-white">
                <div class="card-body">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1">ความสุขเฉลี่ยรวม</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-info fw-bold mb-0"><?php echo $avg_mood; ?> <small class="fs-6 fw-normal">/ 5</small></h3>
                        <div class="bg-info-subtle text-info rounded p-2"><i class="fa-solid fa-face-smile-beam fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 bg-white mb-4">
        <div class="card-header bg-white pt-3 pb-0 border-0">
            <h6 class="fw-bold text-dark"><i class="fa-solid fa-chart-column text-purple me-2"></i>สถิติเวลาทำงานรายวัน (15 วันล่าสุดที่ทำงาน)</h6>
        </div>
        <div class="card-body">
            <div style="position: relative; height: 250px; width: 100%;">
                <?php if (empty($chart_labels)): ?>
                    <div class="d-flex h-100 align-items-center justify-content-center text-muted small">ยังไม่มีข้อมูลการบันทึกงาน</div>
                <?php else: ?>
                    <canvas id="myWorklogChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white pt-3 pb-0 border-0">
            <h6 class="fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left text-purple me-2"></i>ประวัติการบันทึกงานทั้งหมด</h6>
        </div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table id="worklogTable" class="table table-hover align-middle w-100">
                    <thead class="bg-light text-purple small text-uppercase">
                        <tr>
                            <th class="ps-3" width="10%">วันที่</th>
                            <th width="30%">ชื่องาน / ภาระงาน</th>
                            <th width="12%" class="text-center">ความเร่งด่วน</th>
                            <th width="12%" class="text-center">เวลา (นาที)</th>
                            <th class="text-center" width="10%">Mood</th>
                            <th width="13%" class="text-center">สถานะ</th>
                            <th class="text-center" width="13%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($worklogs as $log): ?>
                        <tr>
                            <td class="ps-3" data-sort="<?php echo $log['log_date'] . ' ' . $log['created_at']; ?>">
                                <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($log['log_date'])); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-purple text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($log['task_name']); ?>">
                                    <?php echo htmlspecialchars($log['task_name']); ?>
                                </div>
                                <?php if ($log['has_issue'] == 1): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger extra-small mt-1 px-2">พบปัญหา</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php echo getUrgencyBadge($log['urgency_level'] ?? 'ปกติ'); ?>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold fs-6"><?php echo number_format($log['hours_spent']); ?></span> <span class="text-muted small">นาที</span>
                            </td>
                            <td class="text-center"><?php echo getHarmonyDisplay($log['harmony_score']); ?></td>
                            <td class="text-center">
                                <?php if ($log['submission_status'] == 'ล่าช้า'): ?>
                                    <span class="badge bg-danger shadow-sm px-2 py-1 fw-normal">ส่งล่าช้า</span>
                                <?php else: ?>
                                    <span class="badge bg-success shadow-sm px-2 py-1 fw-normal">ตรงเวลา</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="show.php?id=<?php echo $log['log_id']; ?>" class="btn btn-sm btn-outline-purple rounded-pill px-3 shadow-sm"><i class="fa-solid fa-eye"></i></a>
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
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(75, 0, 130, 0.15); }
    .btn-outline-purple { border-color: #4b0082; color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .extra-small { font-size: 0.75rem; }
    
    div.dataTables_wrapper div.dataTables_filter input { border-radius: 20px; padding: 4px 15px; border: 1px solid #dee2e6; outline: none; }
    div.dataTables_wrapper div.dataTables_length select { border-radius: 10px; padding: 4px 30px 4px 10px; }
    .page-item.active .page-link { background-color: #4b0082; border-color: #4b0082; }
    .page-link { color: #4b0082; border-radius: 8px !important; margin: 0 3px; }
</style>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// รับประกันว่า jQuery โหลดเสร็จก่อน
jQuery(document).ready(function($) {
    // 1. DataTables Config
    if ($.fn.DataTable.isDataTable('#worklogTable')) {
        $('#worklogTable').DataTable().destroy();
    }
    
    $('#worklogTable').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json" },
        "order": [[ 0, "desc" ]], // 💡 แก้ไข: เป็น "desc" เพื่อให้เรียงจากล่าสุดไปเก่าสุด
        "pageLength": 20,         
        "lengthMenu": [[10, 20, 50, -1], [10, 20, 50, "ทั้งหมด"]],
        "responsive": true
    });

    // 2. Chart.js Config
    const chartLabels = <?php echo json_encode($chart_labels); ?>;
    const chartData = <?php echo json_encode($chart_data); ?>;
    
    if (chartLabels.length > 0) {
        const ctx = document.getElementById('myWorklogChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'เวลาทำงาน (นาที)',
                    data: chartData,
                    backgroundColor: 'rgba(75, 0, 130, 0.7)',
                    borderColor: '#4b0082',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: '#4b0082'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(context) { return context.raw + ' นาที'; } } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { borderDash: [2, 4], color: '#e9ecef' }, title: { display: true, text: 'นาที', font: {family: 'Sarabun'} } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>