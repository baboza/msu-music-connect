<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config/db.php';
require_once '../../auth/session_check.php';

$research_admin_roles = ['admin', 'งานวิจัย', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
if (!hasRole($research_admin_roles)) {
    $_SESSION['error'] = "คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้";
    header("Location: ../../index.php");
    exit;
}

// ดึงปีที่ระบุใน URL หรือค่าเริ่มต้นจากตาราง fiscal_years
$stmt_active = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_year = $stmt_active->fetchColumn();
$selected_year = $_GET['year'] ?? $active_year;

// ดึงปีทั้งหมดที่มีในระบบเพื่อสร้าง Dropdown
$stmt_years = $pdo->query("SELECT DISTINCT funding_year FROM research_grants WHERE funding_year IS NOT NULL AND funding_year != '' ORDER BY funding_year DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($active_year, $available_years) && $active_year) {
    $available_years[] = $active_year;
    rsort($available_years);
}

// สร้างเงื่อนไข Query
if ($selected_year === 'all') {
    $where_clause = "1=1";
    $params = [];
} else {
    $where_clause = "funding_year = ?";
    $params = [$selected_year];
}

// Key metrics
$stmt = $pdo->prepare("SELECT COUNT(id) as total_projects, SUM(budget) as total_budget, SUM(installment_1 + installment_2 + installment_3) as total_disbursement FROM research_grants WHERE $where_clause");
$stmt->execute($params);
$metrics = $stmt->fetch(PDO::FETCH_ASSOC);

// Status distribution
$stmt_status = $pdo->prepare("SELECT status, COUNT(*) as count FROM research_grants WHERE $where_clause GROUP BY status");
$stmt_status->execute($params);
$statuses = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

// Budget by year (always show all years for trend chart)
$stmt_year = $pdo->query("SELECT funding_year, SUM(budget) as year_budget FROM research_grants GROUP BY funding_year ORDER BY funding_year ASC");
$years_data = $stmt_year->fetchAll(PDO::FETCH_ASSOC);

// Recent grants
$stmt_recent = $pdo->prepare("SELECT project_name, funding_year, status FROM research_grants WHERE $where_clause ORDER BY created_at DESC LIMIT 5");
$stmt_recent->execute($params);
$recent = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-pie text-info me-2"></i> แดชบอร์ดทุนวิจัย
            </h1>
            <p class="text-muted mb-0">ภาพรวมสถานะและงบประมาณโครงการวิจัยทั้งหมด</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 d-flex gap-2">
            <form method="GET" action="" class="d-flex me-2">
                <select name="year" class="form-select rounded-pill shadow-sm fw-bold border-secondary" onchange="this.form.submit()">
                    <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>-- ทุกปีงบประมาณ --</option>
                    <?php foreach($available_years as $y): ?>
                        <option value="<?= htmlspecialchars($y) ?>" <?= $selected_year == $y ? 'selected' : '' ?>>
                            ปีงบประมาณ <?= htmlspecialchars($y) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="index.php" class="btn btn-outline-primary rounded-pill shadow-sm px-4 fw-bold">
                <i class="fa-solid fa-list me-1"></i> ดูรายการทั้งหมด
            </a>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 text-center">
                    <i class="fa-solid fa-microscope fa-3x mb-3 text-primary opacity-75"></i>
                    <h5 class="fw-bold mb-1 text-dark">จำนวนโครงการทั้งหมด</h5>
                    <h2 class="fw-bold mb-0 text-primary"><?= number_format((int)($metrics['total_projects'] ?? 0)) ?> <small class="fs-6 fw-normal text-muted">โครงการ</small></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 text-center">
                    <i class="fa-solid fa-sack-dollar fa-3x mb-3 text-success opacity-75"></i>
                    <h5 class="fw-bold mb-1 text-dark">งบประมาณรวม</h5>
                    <h2 class="fw-bold mb-0 text-success"><?= number_format((float)($metrics['total_budget'] ?? 0), 2) ?> <small class="fs-6 fw-normal text-muted">บาท</small></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 text-center">
                    <i class="fa-solid fa-money-bill-transfer fa-3x mb-3 text-warning opacity-75"></i>
                    <h5 class="fw-bold mb-1 text-dark">ผลการเบิกจ่ายรวม</h5>
                    <h2 class="fw-bold mb-0 text-warning"><?= number_format((float)($metrics['total_disbursement'] ?? 0), 2) ?> <small class="fs-6 fw-normal text-muted">บาท</small></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-doughnut text-primary me-2"></i>สัดส่วนสถานะโครงการ</h5>
                </div>
                <div class="card-body p-4">
                    <div style="height: 300px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-column text-success me-2"></i>งบประมาณแยกตามปี</h5>
                </div>
                <div class="card-body p-4">
                    <div style="height: 300px;">
                        <canvas id="yearChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>โครงการอัปเดตล่าสุด</h5>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary small text-uppercase">
                                <tr>
                                    <th>ชื่อโครงการ</th>
                                    <th>ปีที่ขอทุน</th>
                                    <th>สถานะ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($recent)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีข้อมูล</td></tr>
                                <?php else: ?>
                                    <?php foreach($recent as $r): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($r['project_name']) ?></td>
                                        <td><?= htmlspecialchars($r['funding_year'] ?? '-') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['status']) ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusLabels = <?= json_encode(array_column($statuses, 'status')) ?>;
    const statusData = <?= json_encode(array_column($statuses, 'count')) ?>;
    
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusData,
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'
                ],
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Year Chart
    const yearCtx = document.getElementById('yearChart').getContext('2d');
    const yearLabels = <?= json_encode(array_column($years_data, 'funding_year')) ?>;
    const yearData = <?= json_encode(array_column($years_data, 'year_budget')) ?>;

    new Chart(yearCtx, {
        type: 'bar',
        data: {
            labels: yearLabels.map(y => y ? y : 'ไม่ระบุ'),
            datasets: [{
                label: 'งบประมาณ (บาท)',
                data: yearData,
                backgroundColor: '#1cc88a',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
