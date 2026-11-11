<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ==========================================
// 1. ตรวจสอบสิทธิ์ (Access Control)
// ==========================================
$management_roles = ['admin', 'งานแผน', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
$has_access = false;
foreach ($management_roles as $role) {
    if (in_array($role, $_SESSION['roles'] ?? [])) {
        $has_access = true; break;
    }
}

if (!$has_access) {
    echo "<script>alert('⛔ เฉพาะผู้บริหารและงานแผนเท่านั้นที่มีสิทธิ์เข้าดูหน้านี้'); window.location.href='index.php';</script>";
    exit();
}

// ==========================================
// 2. จัดการปีงบประมาณ (Fiscal Year Filter)
// ==========================================
$stmt_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years = $stmt_fy->fetchAll();

$default_year = '';
foreach ($fiscal_years as $fy) {
    if ($fy['is_active'] == 1) { $default_year = $fy['year_name']; break; }
}
if (empty($default_year) && !empty($fiscal_years)) {
    $default_year = $fiscal_years[0]['year_name'];
}

// รับค่าปีที่ต้องการดูจาก URL
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : $default_year;

// ==========================================
// 3. ดึงข้อมูลและคำนวณสถิติตามปีงบประมาณ
// ==========================================
// ปรับ SQL ให้ดึงเฉพาะปีงบประมาณที่เลือก
$sql_projects = "SELECT * FROM projects";
$params = [];

if ($filter_year !== 'all') {
    $sql_projects .= " WHERE fiscal_year = :filter_year";
    $params['filter_year'] = $filter_year;
}
$sql_projects .= " ORDER BY deadline ASC";

$stmt = $pdo->prepare($sql_projects);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$status_counts = ['ยังไม่เริ่ม' => 0, 'กำลังดำเนินการ' => 0, 'ดำเนินการเรียบร้อย' => 0, 'ล่าช้า' => 0];
$total_budget = 0;
$actual_budget_total = 0;

$nearing_deadline = [];
$delayed_projects = [];
$today = strtotime(date('Y-m-d'));
$seven_days_from_now = strtotime('+7 days', $today);

// เตรียมข้อมูลสำหรับกราฟแท่ง (Bar Chart)
$chart_labels = [];
$chart_budget = [];
$chart_actual = [];
$chart_remaining = [];

foreach ($projects as $p) {
    // คำนวณความคืบหน้า
    $tasks = [$p['task_approve'], $p['task_borrow'], $p['task_execute'], $p['task_report'], $p['task_clear_money']];
    $progress = (array_sum($tasks) / 5) * 100;
    $deadline = strtotime($p['deadline']);

    // ประเมินสถานะจริง
    $current_status = $p['status'];
    if ($progress < 100 && $deadline < $today) {
        $current_status = 'ล่าช้า';
    }
    
    // นับจำนวนสถานะ
    if(isset($status_counts[$current_status])) {
        $status_counts[$current_status]++;
    }

    // คำนวณงบประมาณ
    $total_budget += $p['budget'];
    $actual_budget_total += $p['actual_budget'];
    
    // ดึงข้อมูลเตรียมลงกราฟแท่ง (จำกัดชื่อให้สั้นลงไม่เกิน 15 ตัวอักษร)
    $short_name = mb_substr($p['project_name'], 0, 15) . (mb_strlen($p['project_name']) > 15 ? '...' : '');
    $chart_labels[] = $short_name;
    $chart_budget[] = $p['budget'];
    $chart_actual[] = $p['actual_budget'];
    $chart_remaining[] = $p['budget'] - $p['actual_budget']; // คงเหลือรายโครงการ

    // จัดกลุ่มโครงการใกล้เดดไลน์ (ภายใน 7 วัน และยังไม่เสร็จ)
    if ($progress < 100 && $deadline >= $today && $deadline <= $seven_days_from_now) {
        $days_left = floor(($deadline - $today) / 86400);
        $p['days_left'] = $days_left;
        $p['progress'] = $progress;
        $nearing_deadline[] = $p;
    }

    // จัดกลุ่มโครงการล่าช้า (เลยเดดไลน์ และยังไม่เสร็จ)
    if ($current_status == 'ล่าช้า') {
        $days_late = floor(($today - $deadline) / 86400);
        $p['days_late'] = $days_late;
        $p['progress'] = $progress;
        $delayed_projects[] = $p;
    }
}

$pending_budget = $total_budget - $actual_budget_total; // เงินคงเหลือรวมทั้งหมด

// เรียงลำดับตาราง
usort($nearing_deadline, function($a, $b) { return $a['days_left'] <=> $b['days_left']; });
usort($delayed_projects, function($a, $b) { return $b['days_late'] <=> $a['days_late']; }); // ล่าช้ามากขึ้นก่อน

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1">
                <i class="fa-solid fa-chart-pie me-2"></i>Executive Dashboard
            </h1>
            <p class="text-muted small mb-0">ระบบติดตามและประเมินผลโครงการประจำปีงบประมาณ</p>
        </div>
        <div class="d-flex gap-3 align-items-center">
            
            <form action="" method="GET" class="shadow-sm rounded-pill overflow-hidden bg-white border">
                <div class="input-group">
                    <span class="input-group-text bg-white border-0 text-purple ps-3"><i class="fa-solid fa-filter"></i></span>
                    <select name="filter_year" class="form-select border-0 text-purple fw-bold shadow-none" style="width: auto; background-color: #f8f9fa; cursor: pointer;" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_year == 'all' ? 'selected' : ''; ?>>ทุกปีงบประมาณรวมกัน</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?php echo htmlspecialchars($fy['year_name']); ?>" <?php echo $filter_year == $fy['year_name'] ? 'selected' : ''; ?>>
                                ปีงบประมาณ <?php echo htmlspecialchars($fy['year_name']); ?> <?php echo $fy['is_active'] == 1 ? '(ปัจจุบัน)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <a href="index.php" class="btn btn-outline-purple rounded-pill px-4 bg-white shadow-sm fw-bold text-nowrap">
                <i class="fa-solid fa-list me-2"></i>ดูรายการทั้งหมด
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-5 hover-lift">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary fs-2 me-4">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-bold mb-1 d-block">ยอดรวมงบประมาณที่ตั้งไว้</small>
                        <h3 class="fw-bold mb-0 text-dark">฿<?php echo number_format($total_budget, 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-5 hover-lift">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success fs-2 me-4">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-bold mb-1 d-block">ยอดรวมเงินที่ใช้ไป (เคลียร์แล้ว)</small>
                        <h3 class="fw-bold mb-0 text-success">฿<?php echo number_format($actual_budget_total, 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-5 hover-lift">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning fs-2 me-4">
                        <i class="fa-solid fa-piggy-bank"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-bold mb-1 d-block">ยอดรวมงบที่เหลือ / เงินทอน</small>
                        <h3 class="fw-bold mb-0 text-warning">฿<?php echo number_format($pending_budget, 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white pt-4 pb-0 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-donut me-2 text-purple"></i>สถานะโครงการ</h5>
                    <span class="badge bg-light text-dark border"><?php echo count($projects); ?> โครงการ</span>
                </div>
                <div class="card-body d-flex justify-content-center align-items-center" style="position: relative; height:280px;">
                    <?php if (empty($projects)): ?>
                        <div class="text-muted small">ไม่มีข้อมูลในปีงบประมาณนี้</div>
                    <?php else: ?>
                        <canvas id="statusChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white pt-4 pb-0 border-0">
                    <h5 class="fw-bold text-dark"><i class="fa-solid fa-chart-column me-2 text-purple"></i>แดชบอร์ดการใช้งบประมาณรายโครงการ</h5>
                </div>
                <div class="card-body" style="position: relative; height:300px; padding-bottom: 30px;">
                    <?php if (empty($projects)): ?>
                        <div class="h-100 d-flex justify-content-center align-items-center text-muted small">ไม่มีข้อมูลในปีงบประมาณนี้</div>
                    <?php else: ?>
                        <canvas id="budgetChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-top border-warning border-4">
                <div class="card-header bg-white pt-3 pb-2 border-0">
                    <h5 class="fw-bold text-dark"><i class="fa-solid fa-bell me-2 text-warning"></i>โครงการใกล้ครบกำหนด (ใน 7 วัน)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="small text-muted">
                                    <th class="ps-4">ชื่อโครงการ</th>
                                    <th>เดดไลน์</th>
                                    <th class="pe-4">ความคืบหน้า</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($nearing_deadline)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">ไม่มีโครงการที่ใกล้ครบกำหนด</td></tr>
                                <?php endif; ?>
                                <?php foreach($nearing_deadline as $p): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold">
                                            <a href="view_project.php?id=<?php echo $p['project_id']; ?>" class="text-decoration-none text-dark"><?php echo htmlspecialchars($p['project_name']); ?></a>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark">เหลืออีก <?php echo $p['days_left']; ?> วัน</span>
                                        </td>
                                        <td class="pe-4">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-info" style="width: <?php echo $p['progress']; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $p['progress']; ?>%</small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-top border-danger border-4">
                <div class="card-header bg-white pt-3 pb-2 border-0">
                    <h5 class="fw-bold text-dark"><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>โครงการที่ล่าช้า (เลยกำหนด)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="small text-muted">
                                    <th class="ps-4">ชื่อโครงการ</th>
                                    <th>สถานะ</th>
                                    <th class="pe-4">ความคืบหน้า</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($delayed_projects)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i> ยอดเยี่ยม! ไม่มีโครงการล่าช้า</td></tr>
                                <?php endif; ?>
                                <?php foreach($delayed_projects as $p): ?>
                                    <tr class="bg-danger-subtle bg-opacity-10">
                                        <td class="ps-4 fw-bold">
                                            <a href="view_project.php?id=<?php echo $p['project_id']; ?>" class="text-decoration-none text-danger"><?php echo htmlspecialchars($p['project_name']); ?></a>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger">ล่าช้า <?php echo $p['days_late']; ?> วัน</span>
                                        </td>
                                        <td class="pe-4">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-danger" style="width: <?php echo $p['progress']; ?>%"></div>
                                            </div>
                                            <small class="text-danger fw-bold"><?php echo $p['progress']; ?>%</small>
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
    .text-purple { color: #39048f; } .bg-purple { background-color: #39048f; }
    .btn-outline-purple { color: #39048f; border-color: #39048f; }
    .btn-outline-purple:hover { background-color: #39048f; color: white; }
    body.bg-light { background-color: #f8f9fa !important; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    .form-select:focus { box-shadow: none !important; border-color: transparent !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    
    <?php if (!empty($projects)): ?>
    
    // --- 1. กราฟวงกลม (Donut Chart) ---
    const ctxStatus = document.getElementById('statusChart').getContext('2d');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['เสร็จสิ้น', 'กำลังดำเนินการ', 'ยังไม่เริ่ม', 'ล่าช้า'],
            datasets: [{
                data: [
                    <?php echo $status_counts['ดำเนินการเรียบร้อย']; ?>, 
                    <?php echo $status_counts['กำลังดำเนินการ']; ?>, 
                    <?php echo $status_counts['ยังไม่เริ่ม']; ?>, 
                    <?php echo $status_counts['ล่าช้า']; ?>
                ],
                backgroundColor: ['#198754', '#0dcaf0', '#6c757d', '#dc3545'], // อิงสีตามคอนเซปต์
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
            },
            cutout: '65%'
        }
    });

    // --- 2. กราฟแท่ง (Bar Chart - งบประมาณ) ---
    const ctxBudget = document.getElementById('budgetChart').getContext('2d');
    new Chart(ctxBudget, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>, // รายชื่อโครงการ
            datasets: [
                {
                    label: 'งบที่ตั้งไว้',
                    data: <?php echo json_encode($chart_budget); ?>,
                    backgroundColor: '#0d6efd', // น้ำเงิน
                    borderRadius: 3
                },
                {
                    label: 'ใช้ไปแล้ว',
                    data: <?php echo json_encode($chart_actual); ?>,
                    backgroundColor: '#198754', // เขียว
                    borderRadius: 3
                },
                {
                    label: 'คงเหลือ',
                    data: <?php echo json_encode($chart_remaining); ?>,
                    backgroundColor: '#fd7e14', // ส้ม
                    borderRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false, },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { boxWidth: 12, usePointStyle: true } }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxRotation: 45, minRotation: 45, font: {size: 10} } // เอียงชื่อ
                },
                y: {
                    beginAtZero: true,
                    ticks: { callback: function(value) { return '฿' + value.toLocaleString(); } }
                }
            }
        }
    });

    <?php endif; ?>
});
</script>

<?php require_once '../../includes/footer.php'; ?>