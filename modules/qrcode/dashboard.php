<?php
// modules/qrcode/dashboard.php - Executive QR Code Dashboard
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'งานแผน', 'ผู้ช่วยคณบดี', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี', 'งานพัสดุ', 'งานอาคารสถานที่', 'งานพัฒนานิสิต', 'รองคณบดีฝ่ายพัฒนานิสิต', 'โสตทัศนูปกรณ์'];

if (!hasRole($management_roles)) {
    header("Location: index.php");
    exit();
}

// Filter parameters
$cat_filter = trim($_GET['category'] ?? '');
$user_filter = trim($_GET['user_id'] ?? '');

try {
    // 1. Overall System KPI Metrics
    $kpi_query = $pdo->query("
        SELECT 
            COUNT(q.id) as total_qr,
            COALESCE(SUM(q.scan_count), 0) as total_scans,
            COUNT(DISTINCT q.user_id) as total_creators
        FROM qr_codes q
    ");
    $kpi = $kpi_query->fetch(PDO::FETCH_ASSOC);

    // 2. Category Statistics
    $cat_stat_query = $pdo->query("
        SELECT 
            category,
            COUNT(*) as qr_count,
            COALESCE(SUM(scan_count), 0) as scan_count
        FROM qr_codes
        GROUP BY category
        ORDER BY qr_count DESC
    ");
    $category_stats = $cat_stat_query->fetchAll(PDO::FETCH_ASSOC);

    // Top Category
    $top_category_name = !empty($category_stats) ? $category_stats[0]['category'] : 'ยังไม่มีข้อมูล';

    // 3. Top Creators
    $creators_query = $pdo->query("
        SELECT 
            u.user_id,
            CONCAT(u.prefix_name, u.first_name, ' ', u.last_name) as creator_name,
            u.position,
            d.dept_name,
            u.profile_picture,
            COUNT(q.id) as qr_count,
            COALESCE(SUM(q.scan_count), 0) as total_scans
        FROM qr_codes q
        JOIN users u ON q.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        GROUP BY q.user_id
        ORDER BY qr_count DESC
        LIMIT 6
    ");
    $top_creators = $creators_query->fetchAll(PDO::FETCH_ASSOC);

    // 4. All QR Codes List for Data Table with Filters
    $where = [];
    $params = [];
    if (!empty($cat_filter)) {
        $where[] = "q.category = ?";
        $params[] = $cat_filter;
    }
    if (!empty($user_filter)) {
        $where[] = "q.user_id = ?";
        $params[] = $user_filter;
    }
    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $list_stmt = $pdo->prepare("
        SELECT 
            q.*,
            CONCAT(u.prefix_name, u.first_name, ' ', u.last_name) as creator_name,
            u.position as creator_position,
            d.dept_name as creator_dept,
            u.profile_picture as creator_avatar
        FROM qr_codes q
        JOIN users u ON q.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        {$where_sql}
        ORDER BY q.id DESC
    ");
    $list_stmt->execute($params);
    $all_qrcodes = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $kpi = ['total_qr' => 0, 'total_scans' => 0, 'total_creators' => 0];
    $category_stats = [];
    $top_creators = [];
    $all_qrcodes = [];
    $top_category_name = 'ยังไม่มีข้อมูล';
}

// Protocol and Host for Absolute Tracking URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base_tracking_url = $host_url . "/music-e-office/modules/qrcode/r.php?c=";

// Chart Data Preparation
$chart_cat_labels = [];
$chart_cat_counts = [];
$chart_cat_scans = [];
foreach ($category_stats as $c) {
    $chart_cat_labels[] = $c['category'];
    $chart_cat_counts[] = intval($c['qr_count']);
    $chart_cat_scans[] = intval($c['scan_count']);
}

$chart_creator_labels = [];
$chart_creator_counts = [];
foreach ($top_creators as $tc) {
    $chart_creator_labels[] = $tc['creator_name'];
    $chart_creator_counts[] = intval($tc['qr_count']);
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="../../assets/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Top Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-pie text-purple me-2"></i>แดชบอร์ดภาพรวม QR Code (Executive Dashboard)
            </h1>
            <p class="text-muted small mb-0">รายงานสถิติการสร้างและการใช้งาน QR Code ของบุคลากรทุกคนในระบบ</p>
        </div>
        <div class="mt-3 mt-md-0 d-flex gap-2">
            <a href="index.php" class="btn btn-purple rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-qrcode me-1"></i> QR Code ของฉัน
            </a>
        </div>
    </div>

    <!-- Executive KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4 border-start border-purple border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted extra-small fw-bold text-uppercase">QR Code ทั้งหมดในระบบ</span>
                        <h2 class="fw-bold text-dark mb-0 mt-1"><?php echo number_format($kpi['total_qr']); ?></h2>
                        <span class="extra-small text-muted">จากบุคลากรทุกคน</span>
                    </div>
                    <div class="rounded-4 bg-purple-subtle text-purple p-3">
                        <i class="fa-solid fa-qrcode fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4 border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted extra-small fw-bold text-uppercase">ยอดการสแกนรวมทั้งหมด</span>
                        <h2 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($kpi['total_scans']); ?></h2>
                        <span class="extra-small text-muted">ครั้งที่มีการเปิด/เข้าชม</span>
                    </div>
                    <div class="rounded-4 bg-success-subtle text-success p-3">
                        <i class="fa-solid fa-chart-line fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4 border-start border-info border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted extra-small fw-bold text-uppercase">ผู้สร้าง QR Code</span>
                        <h2 class="fw-bold text-info mb-0 mt-1"><?php echo number_format($kpi['total_creators']); ?></h2>
                        <span class="extra-small text-muted">คนที่มีการสร้างในระบบ</span>
                    </div>
                    <div class="rounded-4 bg-info-subtle text-info p-3">
                        <i class="fa-solid fa-users fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4 border-start border-warning border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted extra-small fw-bold text-uppercase">หมวดหมู่ยอดนิยม</span>
                        <h4 class="fw-bold text-dark mb-0 mt-1 text-truncate" style="max-width: 170px;" title="<?php echo htmlspecialchars($top_category_name); ?>">
                            <?php echo htmlspecialchars($top_category_name); ?>
                        </h4>
                        <span class="extra-small text-muted">มีการสร้างมากที่สุด</span>
                    </div>
                    <div class="rounded-4 bg-warning-subtle text-warning p-3">
                        <i class="fa-solid fa-trophy fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <!-- Category Breakdown Doughnut Chart -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-purple me-2"></i>สัดส่วนตามหมวดหมู่</h5>
                    <span class="badge bg-light text-secondary rounded-pill border px-2 py-1 extra-small">จำแนกตามประเภท</span>
                </div>
                <div class="chart-container position-relative" style="height: 280px; width: 100%;">
                    <?php if (empty($category_stats)): ?>
                        <div class="text-muted extra-small d-flex align-items-center justify-content-center h-100">ยังไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="categoryDoughnutChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Creators Horizontal Bar Chart -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-ranking-star text-warning me-2"></i>บุคลากรที่มีการสร้าง QR Code สูงสุด</h5>
                    <span class="badge bg-light text-secondary rounded-pill border px-2 py-1 extra-small">Top Creators</span>
                </div>
                <div class="chart-container position-relative" style="height: 280px; width: 100%;">
                    <?php if (empty($top_creators)): ?>
                        <div class="text-muted extra-small text-center pt-5">ยังไม่มีข้อมูลสถิติ</div>
                    <?php else: ?>
                        <canvas id="creatorsBarChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Statistics Summary Cards -->
    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-list-check text-purple me-2"></i>สรุปสถิติตามหมวดหมู่งาน</h5>
            <p class="text-muted small mb-0">คลิกที่หมวดหมู่เพื่อกรองรายการในตารางด้านล่าง</p>
        </div>
        <div class="card-body p-4 pt-2">
            <div class="row g-3">
                <?php foreach ($category_stats as $cat): 
                    $percent = ($kpi['total_qr'] > 0) ? round(($cat['qr_count'] / $kpi['total_qr']) * 100, 1) : 0;
                    $is_active_cat = ($cat_filter === $cat['category']);
                ?>
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <a href="dashboard.php?category=<?php echo urlencode($cat['category']); ?>" class="text-decoration-none">
                        <div class="p-3 rounded-4 border <?php echo $is_active_cat ? 'border-purple bg-purple-subtle' : 'border-light bg-light'; ?> hover-card transition-all h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="fw-bold text-dark small text-truncate" title="<?php echo htmlspecialchars($cat['category']); ?>">
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </span>
                                <span class="badge bg-white text-purple border rounded-pill extra-small"><?php echo $percent; ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-end">
                                <div>
                                    <div class="fs-4 fw-bold text-dark"><?php echo number_format($cat['qr_count']); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></div>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-success-subtle text-success rounded-pill extra-small">
                                        <i class="fa-solid fa-qrcode me-1"></i><?php echo number_format($cat['scan_count']); ?> สแกน
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($cat_filter)): ?>
                <div class="mt-3">
                    <a href="dashboard.php" class="btn btn-sm btn-light border rounded-pill px-3">
                        <i class="fa-solid fa-xmark me-1"></i> ล้างตัวกรองหมวดหมู่ (แสดงทั้งหมด)
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Master Data Table of All QR Codes -->
    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-table-list text-purple me-2"></i>รายการ QR Code ทั้งหมดในระบบ</h5>
                <p class="text-muted small mb-0">ค้นหา ตรวจสอบลิงก์ หรือดูสถิติรายบุคคล</p>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="allQrTable" class="table table-hover align-middle">
                    <thead class="table-light extra-small text-uppercase text-muted">
                        <tr>
                            <th width="70" class="text-center">QR Code</th>
                            <th>ชื่อ QR Code / รายละเอียด</th>
                            <th>หมวดหมู่</th>
                            <th>ลิงก์ปลายทาง</th>
                            <th>ผู้สร้าง / สังกัด</th>
                            <th class="text-center">ยอดสแกน</th>
                            <th>วันที่สร้าง</th>
                            <th class="text-center" width="100">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_qrcodes as $item): 
                            $track_url = $base_tracking_url . $item['code_key'];
                            $final_url = ($item['is_trackable'] == 1) ? $track_url : $item['target_url'];
                            $color = !empty($item['qr_color']) ? $item['qr_color'] : '#000000';
                        ?>
                        <tr>
                            <!-- Thumbnail Canvas -->
                            <td class="text-center" width="70">
                                <div class="p-1 bg-white border rounded-3 shadow-sm d-inline-flex align-items-center justify-content-center cursor-pointer" 
                                     style="width: 54px; height: 54px;" 
                                     onclick='viewQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo $final_url; ?>")'>
                                    <div id="dash_thumb_<?php echo $item['id']; ?>" class="qr-code-canvas d-flex align-items-center justify-content-center"></div>
                                </div>
                            </td>

                            <!-- Title & Description -->
                            <td>
                                <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($item['title']); ?></div>
                                <?php if (!empty($item['description'])): ?>
                                    <div class="text-muted extra-small text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($item['description']); ?>">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Category Badge -->
                            <td>
                                <span class="badge bg-purple-subtle text-purple rounded-pill px-3 py-1 extra-small fw-bold">
                                    <?php echo htmlspecialchars($item['category']); ?>
                                </span>
                            </td>

                            <!-- Target URL -->
                            <td>
                                <div class="extra-small text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($item['target_url']); ?>">
                                    <a href="<?php echo htmlspecialchars($item['target_url']); ?>" target="_blank" class="text-decoration-none text-primary">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><?php echo htmlspecialchars($item['target_url']); ?>
                                    </a>
                                </div>
                            </td>

                            <!-- Creator Info -->
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm me-2 bg-light rounded-circle overflow-hidden flex-shrink-0" style="width: 32px; height: 32px;">
                                        <?php if (!empty($item['creator_avatar'])): ?>
                                            <img src="../../uploads/avatars/<?php echo htmlspecialchars($item['creator_avatar']); ?>" class="w-100 h-100" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted extra-small fw-bold">
                                                <?php echo mb_substr($item['creator_name'], 0, 1, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark extra-small"><?php echo htmlspecialchars($item['creator_name']); ?></div>
                                        <div class="text-muted extra-small" style="font-size: 0.7rem;"><?php echo htmlspecialchars($item['creator_dept'] ?? '-'); ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Scan Count -->
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1 fw-bold extra-small">
                                    <i class="fa-solid fa-qrcode me-1"></i><?php echo number_format($item['scan_count']); ?>
                                </span>
                            </td>

                            <!-- Created Date -->
                            <td class="extra-small text-muted">
                                <?php echo date('d/m/Y', strtotime($item['created_at'])); ?>
                            </td>

                            <!-- Actions -->
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-light text-purple rounded-circle me-1" title="ดู/ขยาย QR Code" 
                                        onclick='viewQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo $final_url; ?>")'>
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-light text-info rounded-circle" title="คัดลอกลิงก์"
                                        onclick='copyToClipboard("<?php echo $final_url; ?>", "คัดลอกลิงก์สำเร็จ")'>
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Enlarge / Download QR Modal -->
<div class="modal fade" id="viewQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="fw-bold mb-1 text-dark" id="viewQrTitle">ชื่อ QR Code</h5>
                    <span class="badge bg-purple-subtle text-purple rounded-pill px-3 py-1 extra-small fw-bold" id="viewQrCategory">หมวดหมู่</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <!-- QR Print Container -->
                <div id="printArea" class="p-4 bg-white rounded-4 border mx-auto mb-3 shadow-sm text-center" style="max-width: 360px;">
                    <div class="d-flex align-items-center justify-content-center mb-2">
                        <img src="../../uploads/logo.png" alt="Logo" height="36" class="me-2" onerror="this.style.display='none'">
                        <span class="fw-bold text-purple small">วิทยาลัยดุริยางคศิลป์ มมส</span>
                    </div>
                    <h6 class="fw-bold text-dark mb-3" id="printQrTitle">หัวข้อ QR Code</h6>
                    <div id="enlargedQrContainer" class="d-flex align-items-center justify-content-center mx-auto my-2" style="width: 240px; height: 240px;"></div>
                    <div class="extra-small text-muted mt-2">สแกนเพื่อเปิดลิงก์</div>
                </div>

                <!-- Link & Stats Info -->
                <div class="bg-light p-3 rounded-3 text-start small mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted extra-small">ลิงก์เป้าหมาย:</span>
                        <a href="#" target="_blank" id="viewQrTargetLink" class="text-decoration-none fw-bold extra-small text-truncate" style="max-width: 250px;">-</a>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted extra-small">ยอดการสแกน:</span>
                        <span class="fw-bold text-success extra-small" id="viewQrScanCount">0 ครั้ง</span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <button type="button" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm" onclick="downloadQrImage()">
                        <i class="fa-solid fa-download me-1"></i> ดาวน์โหลดภาพ (PNG)
                    </button>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 fw-bold" onclick="copyCurrentQrLink()">
                        <i class="fa-solid fa-copy me-1"></i> คัดลอกลิงก์
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #4b0082 !important; }
    .bg-purple { background-color: #4b0082 !important; color: white !important; }
    .bg-purple-subtle { background-color: #f3e5f5 !important; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #380062; color: white; }
    .extra-small { font-size: 0.75rem; }
    .hover-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .transition-all { transition: all 0.2s ease; }
    .cursor-pointer { cursor: pointer; }
</style>

<!-- Local Libraries with CDN fallback -->
<script src="../../assets/js/chart.min.js"></script>
<script>
    if (typeof Chart === 'undefined') {
        document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script>');
    }
</script>
<script src="../../assets/js/jquery.dataTables.min.js"></script>
<script src="../../assets/js/dataTables.bootstrap5.min.js"></script>
<script>
    if (typeof $.fn.DataTable === 'undefined') {
        document.write('<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"><\/script>');
        document.write('<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"><\/script>');
    }
</script>
<script src="../../assets/js/qrcode.min.js"></script>
<script>
    if (typeof QRCode === 'undefined') {
        document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
    }
</script>

<script>
    let currentActiveUrl = "";
    let currentActiveTitle = "";

    function generateQrBase64(text, color) {
        try {
            const tempDiv = document.createElement("div");
            new QRCode(tempDiv, {
                text: text,
                width: 92,
                height: 92,
                colorDark: color || "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
            const canvas = tempDiv.querySelector("canvas");
            if (canvas) return canvas.toDataURL("image/png");
            const img = tempDiv.querySelector("img");
            if (img && img.src) return img.src;
        } catch (e) {
            console.error("QR Gen error:", e);
        }
        return "";
    }

    document.addEventListener("DOMContentLoaded", function() {
        // 1. Render all table thumbnails FIRST into DOM before DataTables caches table rows
        const qrItems = <?php echo json_encode($all_qrcodes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        qrItems.forEach(item => {
            const container = document.getElementById("dash_thumb_" + item.id);
            if (container) {
                const finalUrl = (item.is_trackable == 1) 
                    ? "<?php echo $base_tracking_url; ?>" + item.code_key 
                    : item.target_url;
                
                const dataUrl = generateQrBase64(finalUrl, item.qr_color || "#000000");
                if (dataUrl) {
                    container.innerHTML = `<img src="${dataUrl}" width="46" height="46" class="rounded" style="object-fit: contain;" alt="QR">`;
                }
            }
        });

        // 2. Initialize DataTable after thumbnails are populated
        $('#allQrTable').DataTable({
            language: {
                search: "ค้นหา:",
                lengthMenu: "แสดง _MENU_ รายการ",
                info: "แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ",
                infoEmpty: "ไม่มีข้อมูล",
                zeroRecords: "ไม่พบข้อมูลที่ตรงกัน",
                paginate: {
                    first: "แรก",
                    last: "ท้าย",
                    next: "ถัดไป",
                    previous: "ก่อนหน้า"
                }
            },
            order: [[6, "desc"]],
            pageLength: 10
        });

        // 3. Category Doughnut Chart
        if (typeof Chart !== 'undefined') {
            try {
                const catLabels = <?php echo json_encode($chart_cat_labels); ?>;
                const catCounts = <?php echo json_encode($chart_cat_counts); ?>;
                const catCtx = document.getElementById('categoryDoughnutChart');
                if (catCtx && catLabels.length > 0) {
                    new Chart(catCtx, {
                        type: 'doughnut',
                        data: {
                            labels: catLabels,
                            datasets: [{
                                data: catCounts,
                                backgroundColor: [
                                    '#000000', '#4b0082', '#0284c7', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'
                                ],
                                borderWidth: 2,
                                hoverOffset: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { boxWidth: 12, font: { family: 'Anuphan', size: 11 } }
                                }
                            },
                            cutout: '65%'
                        }
                    });
                }
            } catch (err) {
                console.error("Error creating category chart:", err);
            }

            // 4. Creators Bar Chart
            try {
                const creatorLabels = <?php echo json_encode($chart_creator_labels); ?>;
                const creatorCounts = <?php echo json_encode($chart_creator_counts); ?>;
                const creatorCtx = document.getElementById('creatorsBarChart');
                if (creatorCtx && creatorLabels.length > 0) {
                    new Chart(creatorCtx, {
                        type: 'bar',
                        data: {
                            labels: creatorLabels,
                            datasets: [{
                                label: 'จำนวน QR Code ที่สร้าง',
                                data: creatorCounts,
                                backgroundColor: 'rgba(75, 0, 130, 0.85)',
                                borderRadius: 8
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: { precision: 0, font: { family: 'Anuphan' } }
                                },
                                y: {
                                    ticks: { font: { family: 'Anuphan', size: 11 } }
                                }
                            }
                        }
                    });
                }
            } catch (err) {
                console.error("Error creating creators chart:", err);
            }
        } else {
            console.error("Chart.js library is not available.");
        }
    });

    // View Modal
    function viewQrModal(item, finalUrl) {
        currentActiveUrl = finalUrl;
        currentActiveTitle = item.title;

        document.getElementById('viewQrTitle').textContent = item.title;
        document.getElementById('printQrTitle').textContent = item.title;
        document.getElementById('viewQrCategory').textContent = item.category;
        
        const linkEl = document.getElementById('viewQrTargetLink');
        linkEl.textContent = item.target_url;
        linkEl.href = item.target_url;

        document.getElementById('viewQrScanCount').textContent = (item.scan_count || 0) + ' ครั้ง';

        const enlargedContainer = document.getElementById('enlargedQrContainer');
        enlargedContainer.innerHTML = '';

        new QRCode(enlargedContainer, {
            text: finalUrl,
            width: 230,
            height: 230,
            colorDark: item.qr_color || "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });

        new bootstrap.Modal(document.getElementById('viewQrModal')).show();
    }

    // Download QR
    function downloadQrImage() {
        const container = document.getElementById('enlargedQrContainer');
        const img = container.querySelector('img');
        const canvas = container.querySelector('canvas');

        let dataUrl = "";
        if (canvas) {
            dataUrl = canvas.toDataURL('image/png');
        } else if (img && img.src) {
            dataUrl = img.src;
        }

        if (dataUrl) {
            const a = document.createElement('a');
            a.href = dataUrl;
            a.download = 'QRCode_' + (currentActiveTitle.replace(/[^a-zA-Z0-9ก-๙]/g, '_') || 'MSU_Music') + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        } else {
            alert('กำลังประมวลผลภาพ กรุณาลองใหม่อีกครั้ง');
        }
    }

    function copyCurrentQrLink() {
        copyToClipboard(currentActiveUrl, "คัดลอกลิงก์สำเร็จ: " + currentActiveUrl);
    }

    function copyToClipboard(text, successMsg) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                alert(successMsg || "คัดลอกเรียบร้อยแล้ว");
            }).catch(() => {
                prompt("กด Ctrl+C เพื่อคัดลอกลิงก์:", text);
            });
        } else {
            prompt("กด Ctrl+C เพื่อคัดลอกลิงก์:", text);
        }
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
