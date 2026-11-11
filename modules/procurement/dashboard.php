<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เข้าได้เฉพาะ admin, งานพัสดุ, รองบริหาร, คณบดี, รักษาการคณบดี)
$allowed_roles = ['admin', 'งานพัสดุ', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($allowed_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์เข้าใช้งานหน้าภาพรวมสถิติพัสดุ'); window.location.href='index.php';</script>";
    exit();
}

// 2. ดึงรายการปีงบประมาณทั้งหมดจากระบบที่แอดมินตั้งค่าไว้ (ตาราง fiscal_years)
$stmt_fy_list = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years_db = $stmt_fy_list->fetchAll(PDO::FETCH_ASSOC);

// หาปีที่ Active เป็นค่าเริ่มต้น
$active_fy = '';
$available_years = [];
foreach ($fiscal_years_db as $fy_item) {
    $available_years[] = $fy_item['year_name'];
    if ($fy_item['is_active'] == 1 && empty($active_fy)) {
        $active_fy = $fy_item['year_name'];
    }
}
if (empty($active_fy)) {
    $active_fy = !empty($available_years) ? $available_years[0] : (date('Y') + 543);
}

// รวมปีที่มีข้อมูลใน procurement_tracking เข้ามาด้วยเพื่อความสมบูรณ์
$stmt_proc_years = $pdo->query("SELECT DISTINCT fiscal_year FROM procurement_tracking ORDER BY fiscal_year DESC");
$proc_years = $stmt_proc_years->fetchAll(PDO::FETCH_COLUMN);
foreach ($proc_years as $py) {
    if (!empty($py) && !in_array($py, $available_years)) {
        $available_years[] = $py;
    }
}
rsort($available_years);

if (empty($available_years)) {
    $available_years = [$active_fy];
}

$filter_fy = $_GET['fy'] ?? (in_array($active_fy, $available_years) ? $active_fy : ($available_years[0] ?? $active_fy));

// 3. Query ข้อมูลสถิติภาพรวม
$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_projects,
        SUM(budget) as total_budget,
        SUM(penalty_fee) as total_penalty,
        SUM(CASE WHEN step10_completed = 1 THEN 1 ELSE 0 END) as completed_projects,
        SUM(CASE WHEN step10_completed = 0 AND end_date IS NOT NULL AND end_date < CURDATE() THEN 1 ELSE 0 END) as delayed_projects,
        SUM(CASE WHEN step10_completed = 0 AND (step1_request=1 OR step2_tor=1 OR step3_approve_buy=1 OR step4_announce_winner=1 OR step5_system_approve=1 OR step6_contract=1 OR step7_delivery=1 OR step8_inspection=1 OR step9_disbursement=1) THEN 1 ELSE 0 END) as in_progress_projects
    FROM procurement_tracking
    WHERE fiscal_year = ?
");
$stmt_stats->execute([$filter_fy]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// จัดการค่า Null ให้เป็น 0
$total_projects = $stats['total_projects'] ?: 0;
$total_budget = $stats['total_budget'] ?: 0;
$total_penalty = $stats['total_penalty'] ?: 0;
$completed_projects = $stats['completed_projects'] ?: 0;
$delayed_projects = $stats['delayed_projects'] ?: 0;
$in_progress_projects = $stats['in_progress_projects'] ?: 0;
$pending_projects = $total_projects - $completed_projects - $in_progress_projects;

// 4. Query สถิติ วิธีการจัดซื้อ (สำหรับกราฟ)
$stmt_methods = $pdo->prepare("
    SELECT procurement_method, COUNT(*) as count, SUM(budget) as total_budget
    FROM procurement_tracking 
    WHERE fiscal_year = ? AND procurement_method IS NOT NULL AND procurement_method != ''
    GROUP BY procurement_method
    ORDER BY count DESC
");
$stmt_methods->execute([$filter_fy]);
$methods_data = $stmt_methods->fetchAll(PDO::FETCH_ASSOC);

$method_labels = [];
$method_counts = [];
$method_budgets = []; 
foreach ($methods_data as $m) {
    $method_labels[] = $m['procurement_method'];
    $method_counts[] = $m['count'];
    $method_budgets[] = $m['total_budget'];
}

// 5. Query สถิติ ประเภทพัสดุ (งบตั้งต้น vs ซื้อจริง vs คงเหลือ)
$stmt_types = $pdo->prepare("
    SELECT item_type, 
           COUNT(*) as count, 
           SUM(budget) as total_budget,
           /* 💡 แก้ไขชื่อคอลัมน์ actual_price ให้ตรงกับชื่อในฐานข้อมูลของอาจารย์ที่เก็บยอดจัดซื้อจริง */
           SUM(COALESCE(actual_price, 0)) as actual_spend 
    FROM procurement_tracking 
    WHERE fiscal_year = ? AND item_type IS NOT NULL AND item_type != ''
    GROUP BY item_type
    ORDER BY total_budget DESC
");
$stmt_types->execute([$filter_fy]);
$types_data = $stmt_types->fetchAll(PDO::FETCH_ASSOC);

$type_labels = [];
$type_budgets = []; 
$type_actuals = [];
$type_balances = [];

// ตัวแปรสำหรับยอดรวมในตาราง
$sum_count = 0;
$sum_budget = 0;
$sum_actual = 0;
$sum_balance = 0;

foreach ($types_data as $t) {
    $type_labels[] = $t['item_type'];
    
    $bg = (float)$t['total_budget'];
    $ac = (float)$t['actual_spend'];
    $bl = $bg - $ac;
    
    $type_budgets[] = $bg;
    $type_actuals[] = $ac;
    $type_balances[] = $bl;

    // คำนวณยอดรวมสำหรับตาราง
    $sum_count += $t['count'];
    $sum_budget += $bg;
    $sum_actual += $ac;
    $sum_balance += $bl;
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom d-print-none">
        <div>
            <h1 class="h2 text-primary fw-bold"><i class="fa-solid fa-chart-pie me-2"></i>ภาพรวมงานพัสดุ (Dashboard)</h1>
            <p class="text-muted mb-0">สรุปสถิติโครงการและการเบิกจ่าย ประจำปีงบประมาณ</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form action="" method="GET" class="d-flex align-items-center">
                <label class="me-2 fw-bold small text-muted">ปีงบประมาณ:</label>
                <select name="fy" class="form-select form-select-sm border-primary fw-bold text-primary shadow-sm" onchange="this.form.submit()" style="width: auto;">
                    <?php foreach ($available_years as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $y == $filter_fy ? 'selected' : ''; ?>>
                            ปี <?php echo htmlspecialchars($y); ?> <?php echo ($y == $active_fy) ? '(ปัจจุบัน)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-list me-1"></i> ดูหน้ารายการ
            </a>
        </div>
    </div>

    <!-- 4 Cards Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm border-start border-primary border-4 bg-white hover-lift">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">โครงการทั้งหมด</p>
                            <h3 class="text-primary fw-bold mb-0"><?php echo number_format($total_projects); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                        </div>
                        <div class="stat-icon bg-primary-subtle text-primary"><i class="fa-solid fa-folder-open"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm border-start border-success border-4 bg-white hover-lift">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">เสร็จสิ้นแล้ว</p>
                            <h3 class="text-success fw-bold mb-0"><?php echo number_format($completed_projects); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                        </div>
                        <div class="stat-icon bg-success-subtle text-success"><i class="fa-solid fa-check-double"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm border-start border-warning border-4 bg-white hover-lift">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">งบประมาณรวม (อนุมัติ)</p>
                            <h4 class="text-warning fw-bold mb-0 text-dark">฿<?php echo number_format($total_budget); ?></h4>
                        </div>
                        <div class="stat-icon bg-warning-subtle text-warning"><i class="fa-solid fa-sack-dollar text-dark"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm border-start border-danger border-4 bg-white hover-lift">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="mb-1 text-danger fw-bold extra-small text-uppercase">ค่าปรับ (ส่งมอบล่าช้า)</p>
                            <h4 class="text-danger fw-bold mb-0">฿<?php echo number_format($total_penalty); ?></h4>
                            <div class="extra-small text-muted mt-1"><i class="fa-solid fa-clock-rotate-left me-1"></i> มีงานล่าช้า <?php echo $delayed_projects; ?> รายการ</div>
                        </div>
                        <div class="stat-icon bg-danger-subtle text-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- กราฟงบประมาณ 2 รูปแบบ -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white pt-4 pb-0 border-0">
                    <h6 class="fw-bold text-dark"><i class="fa-solid fa-chart-column me-2 text-warning"></i>งบประมาณแยกตามวิธีการจัดซื้อ (บาท)</h6>
                </div>
                <div class="card-body p-4 pt-2">
                    <?php if (empty($method_budgets)): ?>
                        <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-simple fs-1 opacity-25 mb-2"></i><br>ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="budgetMethodChart" height="280"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white pt-4 pb-0 border-0">
                    <h6 class="fw-bold text-dark"><i class="fa-solid fa-chart-bar me-2 text-success"></i>สถานะงบประมาณแยกตามประเภทพัสดุ (บาท)</h6>
                </div>
                <div class="card-body p-4 pt-2">
                    <?php if (empty($type_budgets)): ?>
                        <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-simple fs-1 opacity-25 mb-2"></i><br>ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="budgetTypeChart" height="280"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ส่วนตารางและกราฟเดิม -->
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white pt-4 pb-0 border-0">
                    <h6 class="fw-bold text-dark"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>สัดส่วนโครงการแยกตามวิธีจัดซื้อ</h6>
                </div>
                <div class="card-body p-4 pt-2">
                    <?php if (empty($methods_data)): ?>
                        <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-simple fs-1 opacity-25 mb-2"></i><br>ไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="methodsChart" height="250"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 bg-primary text-white rounded-4">
                    <h6 class="fw-bold border-bottom border-light pb-2 mb-3"><i class="fa-solid fa-battery-half me-2"></i>สถานะการดำเนินงานทั้งหมด</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>รอดำเนินการ (ยังไม่เริ่ม)</span>
                        <span class="fw-bold fs-5"><?php echo $pending_projects; ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-warning">กำลังดำเนินการ</span>
                        <span class="fw-bold fs-5 text-warning"><?php echo $in_progress_projects; ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-info">เสร็จสิ้น (ปิดโครงการ)</span>
                        <span class="fw-bold fs-5 text-info"><?php echo $completed_projects; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white pt-4 pb-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open me-2 text-success"></i>สรุปสถานะงบประมาณแยกตามประเภทพัสดุ</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-muted text-uppercase">
                                <tr>
                                    <th class="ps-4" width="25%">ประเภทพัสดุ</th>
                                    <th class="text-center" width="15%">รายการ</th>
                                    <th class="text-end" width="20%">งบตั้งต้น (บาท)</th>
                                    <th class="text-end" width="20%">จัดซื้อจริง (บาท)</th>
                                    <th class="text-end pe-4" width="20%">คงเหลือ (บาท)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($types_data)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">ไม่มีข้อมูล</td></tr>
                                <?php else: ?>
                                    <?php foreach ($types_data as $index => $t): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark"><?php echo htmlspecialchars($t['item_type']); ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border px-2"><?php echo number_format($t['count']); ?></span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">
                                            <?php echo number_format($type_budgets[$index], 2); ?>
                                        </td>
                                        <td class="text-end fw-bold text-success">
                                            <?php echo number_format($type_actuals[$index], 2); ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold <?php echo $type_balances[$index] < 0 ? 'text-danger' : 'text-warning text-dark'; ?>">
                                            <?php echo number_format($type_balances[$index], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light">
                                        <td class="ps-4 fw-bold text-dark">รวมทั้งสิ้น</td>
                                        <td class="text-center fw-bold text-dark"><?php echo number_format($sum_count); ?></td>
                                        <td class="text-end fw-bold text-primary fs-6"><?php echo number_format($sum_budget, 2); ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?php echo number_format($sum_actual, 2); ?></td>
                                        <td class="text-end pe-4 fw-bold <?php echo $sum_balance < 0 ? 'text-danger' : 'text-warning text-dark'; ?> fs-6">
                                            <?php echo number_format($sum_balance, 2); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .text-primary { color: #0d6efd !important; }
    .bg-primary { background-color: #0d6efd !important; }
    .bg-primary-subtle { background-color: #cfe2ff; }
    .bg-success-subtle { background-color: #d1e7dd; }
    .bg-warning-subtle { background-color: #fff3cd; }
    .bg-danger-subtle { background-color: #f8d7da; }
    
    .extra-small { font-size: 0.75rem; }
    .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; } 
    .hover-lift:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // ตั้งค่า Default Font ให้ Chart.js
    Chart.defaults.font.family = "'Sarabun', sans-serif";

    // 1. กราฟวงกลม: สัดส่วนจำนวนโครงการ
    <?php if (!empty($methods_data)): ?>
    new Chart(document.getElementById('methodsChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($method_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($method_counts); ?>,
                backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#6c757d', '#dc3545'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
            },
            cutout: '65%'
        }
    });
    <?php endif; ?>

    // 2. กราฟแท่ง: งบประมาณแยกตามวิธีจัดซื้อ
    <?php if (!empty($method_budgets)): ?>
    new Chart(document.getElementById('budgetMethodChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($method_labels); ?>,
            datasets: [{
                label: 'งบประมาณ (บาท)',
                data: <?php echo json_encode($method_budgets); ?>,
                backgroundColor: 'rgba(255, 193, 7, 0.8)', // สี Warning อ่อนๆ
                borderColor: '#ffc107',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { 
                    beginAtZero: true, 
                    ticks: { callback: function(value) { return '฿' + value.toLocaleString(); } }
                }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });
    <?php endif; ?>

    // 3. กราฟแท่งแบบกลุ่ม (Grouped Bar): สถานะงบประมาณตามประเภท
    <?php if (!empty($type_budgets)): ?>
    new Chart(document.getElementById('budgetTypeChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($type_labels); ?>,
            datasets: [
                {
                    label: 'งบตั้งต้น',
                    data: <?php echo json_encode($type_budgets); ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.8)', // Primary
                    borderColor: '#0d6efd',
                    borderWidth: 1, borderRadius: 4
                },
                {
                    label: 'จัดซื้อจริง',
                    data: <?php echo json_encode($type_actuals); ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.8)', // Success
                    borderColor: '#198754',
                    borderWidth: 1, borderRadius: 4
                },
                {
                    label: 'คงเหลือ',
                    data: <?php echo json_encode($type_balances); ?>,
                    backgroundColor: 'rgba(255, 193, 7, 0.8)', // Warning
                    borderColor: '#ffc107',
                    borderWidth: 1, borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { 
                legend: { display: true, position: 'bottom', labels: { boxWidth: 12 } } 
            },
            scales: {
                y: { 
                    beginAtZero: true, 
                    ticks: { callback: function(value) { return '฿' + value.toLocaleString(); } }
                }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });
    <?php endif; ?>

});
</script>

<?php require_once '../../includes/footer.php'; ?>