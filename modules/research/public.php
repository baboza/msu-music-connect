<?php
// ไฟล์นี้เปิดแบบ Public ไม่ต้องเช็ค Session
require_once '../../config/db.php';

// รับค่าตัวกรองจาก URL
$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : 'all';
$filter_year = isset($_GET['year']) ? $_GET['year'] : 'all';
$filter_source = isset($_GET['source']) ? $_GET['source'] : 'all';

// ดึงข้อมูลสำหรับทำ Dropdown Filters
$authors_list = $pdo->query("SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.prefix_name FROM users u JOIN cv_research r ON u.user_id = r.user_id ORDER BY u.first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$years_list = $pdo->query("SELECT DISTINCT publish_year FROM cv_research WHERE publish_year IS NOT NULL AND publish_year != '' ORDER BY publish_year DESC")->fetchAll(PDO::FETCH_ASSOC);
$sources_list = $pdo->query("SELECT DISTINCT publisher FROM cv_research WHERE publisher IS NOT NULL AND publisher != '' ORDER BY publisher ASC")->fetchAll(PDO::FETCH_ASSOC);

try {
    // ==========================================
    // 1. สร้างเงื่อนไข Query (Where Clause)
    // ==========================================
    $conditions = [];
    $params = [];

    if ($filter_user !== 'all') { $conditions[] = "r.user_id = :uid"; $params[':uid'] = $filter_user; }
    if ($filter_year !== 'all') { $conditions[] = "r.publish_year = :yr"; $params[':yr'] = $filter_year; }
    if ($filter_source !== 'all') { $conditions[] = "r.publisher = :src"; $params[':src'] = $filter_source; }

    $where_sql = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

    // ยอดรวมตามตัวกรอง
    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM cv_research r $where_sql");
    $stmt_total->execute($params);
    $total_filtered = $stmt_total->fetchColumn();

    // ==========================================
    // 2. คำนวณ Key Insights อัจฉริยะ (ป้องกัน Error ข้อมูลว่าง)
    // ==========================================
    $curr_yr = date('Y');
    $last_yr = $curr_yr - 1;
    $cnt_curr = $pdo->query("SELECT COUNT(*) FROM cv_research WHERE publish_year = '$curr_yr'")->fetchColumn();
    $cnt_last = $pdo->query("SELECT COUNT(*) FROM cv_research WHERE publish_year = '$last_yr'")->fetchColumn();
    $growth_rate = ($cnt_last > 0) ? round((($cnt_curr - $cnt_last) / $cnt_last) * 100, 1) : ($cnt_curr > 0 ? 100 : 0);
    
    // Most Prolific Author
    $stmt_top_author = $pdo->query("SELECT u.prefix_name, u.first_name, u.last_name, COUNT(*) as total FROM cv_research r JOIN users u ON r.user_id = u.user_id GROUP BY r.user_id ORDER BY total DESC LIMIT 1");
    $top_author = $stmt_top_author->fetch(PDO::FETCH_ASSOC);
    $top_author_name = $top_author ? ($top_author['prefix_name'] . $top_author['first_name'] . ' ' . $top_author['last_name']) : '-';
    $top_author_total = $top_author ? $top_author['total'] : 0;

    // Peak Publication Year
    $stmt_peak_year = $pdo->query("SELECT publish_year, COUNT(*) as total FROM cv_research WHERE publish_year IS NOT NULL AND publish_year != '' GROUP BY publish_year ORDER BY total DESC LIMIT 1");
    $peak_year = $stmt_peak_year->fetch(PDO::FETCH_ASSOC);
    $peak_year_val = $peak_year ? $peak_year['publish_year'] : '-';
    $peak_year_total = $peak_year ? $peak_year['total'] : 0;

    // Top Publication Source
    $stmt_top_source = $pdo->query("SELECT publisher, COUNT(*) as total FROM cv_research WHERE publisher IS NOT NULL AND publisher != '' GROUP BY publisher ORDER BY total DESC LIMIT 1");
    $top_source = $stmt_top_source->fetch(PDO::FETCH_ASSOC);
    $top_source_val = $top_source ? $top_source['publisher'] : '-';
    $top_source_total = $top_source ? $top_source['total'] : 0;

    // ==========================================
    // 3. ข้อมูลสำหรับสร้างกราฟ (Chart Data)
    // ==========================================
    $trend_conditions = $conditions;
    $trend_conditions[] = "r.publish_year IS NOT NULL AND r.publish_year != ''";
    $trend_where_sql = "WHERE " . implode(" AND ", $trend_conditions);

    // กราฟ 1: Papers by Year
    $stmt_trend = $pdo->prepare("SELECT publish_year, COUNT(*) as count FROM cv_research r $trend_where_sql GROUP BY r.publish_year ORDER BY r.publish_year DESC LIMIT 6");
    $stmt_trend->execute($params);
    $trend_data = array_reverse($stmt_trend->fetchAll(PDO::FETCH_ASSOC));
    
    $chart1_labels = []; $chart1_counts = [];
    foreach($trend_data as $td) { 
        $chart1_labels[] = $td['publish_year']; 
        $chart1_counts[] = $td['count']; 
    }

    // กราฟ 2: Top 10 Authors
    $chart2_labels = []; $chart2_counts = [];
    if($filter_user == 'all') {
        $stmt_top10 = $pdo->prepare("SELECT u.first_name, COUNT(*) as count FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_sql GROUP BY r.user_id ORDER BY count DESC LIMIT 10");
        $stmt_top10->execute($params);
        $top10_data = $stmt_top10->fetchAll(PDO::FETCH_ASSOC);
        foreach($top10_data as $td) { 
            $chart2_labels[] = $td['first_name']; 
            $chart2_counts[] = $td['count']; 
        }
    }

    // ==========================================
    // 4. ดึงรายการผลงานสำหรับตาราง
    // ==========================================
    $sql_list = "SELECT r.*, u.first_name, u.last_name, u.prefix_name, u.profile_picture 
                 FROM cv_research r 
                 JOIN users u ON r.user_id = u.user_id 
                 $where_sql 
                 ORDER BY r.publish_year DESC, r.created_at DESC";
    $stmt_list = $pdo->prepare($sql_list);
    $stmt_list->execute($params);
    $researches = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("เกิดข้อผิดพลาด: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Analytics - Public</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Anuphan', sans-serif; background-color: #f4f6f9; color: #2d3748; }
        .text-purple { color: #4b0082; }
        .bg-purple-subtle { background-color: #f3e5f5; }
        .extra-small { font-size: 0.75rem; }
        .insight-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .insight-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; }
        
        div.dataTables_wrapper div.dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #dee2e6; outline: none; }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 10px; padding: 4px 30px 4px 10px; border: 1px solid #dee2e6; outline: none; }
        .page-item.active .page-link { background-color: #4b0082; border-color: #4b0082; }
        .page-link { color: #4b0082; border-radius: 50% !important; margin: 0 3px; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; }
        
        .card { border-radius: 16px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.04); }
    </style>
</head>
<body>

<main class="container py-4 mb-5" style="min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-end pt-4 pb-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-microscope text-purple me-2"></i>Research Analytics</h1>
            <p class="text-muted small mb-0">ข้อมูลผลงานวิชาการ (Papers, Trends & Insights) วิทยาลัยดุริยางคศิลป์</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-3">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Author</label>
                    <select name="user_id" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกคน (All Authors)</option>
                        <?php foreach($authors_list as $author): ?>
                            <option value="<?php echo $author['user_id']; ?>" <?php echo $filter_user == $author['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($author['prefix_name'].$author['first_name'].' '.$author['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Year</label>
                    <select name="year" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกปี (All Years)</option>
                        <?php foreach($years_list as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr['publish_year']); ?>" <?php echo $filter_year == $yr['publish_year'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr['publish_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Source / Publisher</label>
                    <select name="source" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกแหล่งตีพิมพ์ (All Sources)</option>
                        <?php foreach($sources_list as $src): ?>
                            <option value="<?php echo htmlspecialchars($src['publisher']); ?>" <?php echo $filter_source == htmlspecialchars($src['publisher']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($src['publisher']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 pt-4">
                    <a href="public.php" class="btn btn-outline-secondary w-100 rounded-pill fw-bold">ล้างค่าตัวกรอง</a>
                </div>
            </form>
        </div>
    </div>

    <?php if($filter_user == 'all' && $filter_year == 'all' && $filter_source == 'all'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-primary border-4 insight-card">
                <div class="card-body p-3">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-arrow-trend-up me-1 text-primary"></i> Research Growth</div>
                    <h4 class="fw-bold text-dark mb-0">
                        <?php echo $growth_rate > 0 ? '+'.$growth_rate : $growth_rate; ?>%
                    </h4>
                    <div class="extra-small text-muted mt-1">เทียบผลงานปี <?php echo $curr_yr; ?> กับ <?php echo $last_yr; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4 insight-card">
                <div class="card-body p-3">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-crown me-1 text-success"></i> Most Prolific Author</div>
                    <h5 class="fw-bold text-dark mb-0 text-truncate" title="<?php echo htmlspecialchars($top_author_name); ?>">
                        <?php echo htmlspecialchars($top_author_name); ?>
                    </h5>
                    <div class="extra-small text-muted mt-1">ผลงานสูงสุด <b><?php echo $top_author_total; ?></b> เรื่อง</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-warning border-4 insight-card">
                <div class="card-body p-3">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-fire me-1 text-warning"></i> Peak Publication Year</div>
                    <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($peak_year_val); ?></h4>
                    <div class="extra-small text-muted mt-1">ปีที่ตีพิมพ์สูงสุด <b><?php echo $peak_year_total; ?></b> เรื่อง</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-info border-4 insight-card">
                <div class="card-body p-3">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-newspaper me-1 text-info"></i> Top Source</div>
                    <h6 class="fw-bold text-dark mb-0 text-truncate" title="<?php echo htmlspecialchars($top_source_val); ?>">
                        <?php echo htmlspecialchars($top_source_val); ?>
                    </h6>
                    <div class="extra-small text-muted mt-1">ตีพิมพ์ <b><?php echo $top_source_total; ?></b> เรื่อง</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="<?php echo ($filter_user == 'all') ? 'col-lg-6' : 'col-12'; ?>">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-area text-purple me-2"></i>Papers by Year (ผลงานตามปี)</h6>
                    <span class="badge bg-purple-subtle text-purple rounded-pill">รวม: <?php echo $total_filtered; ?></span>
                </div>
                <div class="card-body p-4">
                    <div style="position: relative; height: 250px; width: 100%;">
                        <?php if(empty($chart1_labels)): ?>
                            <div class="d-flex h-100 align-items-center justify-content-center text-muted small">ไม่พบข้อมูลปีตีพิมพ์สำหรับนำมาแสดงกราฟ</div>
                        <?php else: ?>
                            <canvas id="papersByYearChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if($filter_user == 'all'): ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-ranking-star text-warning me-2"></i>Top Authors (ผู้แต่งยอดเยี่ยม)</h6>
                </div>
                <div class="card-body p-4">
                    <div style="position: relative; height: 250px; width: 100%;">
                        <?php if(empty($chart2_labels)): ?>
                            <div class="d-flex h-100 align-items-center justify-content-center text-muted small">ยังไม่มีข้อมูลผลงานวิจัย</div>
                        <?php else: ?>
                            <canvas id="topAuthorsChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-5 overflow-hidden">
        <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list me-2 text-secondary"></i>รายการผลงานวิชาการ</h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="researchTable" class="table table-hover align-middle w-100">
                    <thead class="bg-light text-muted extra-small text-uppercase">
                        <tr>
                            <th width="8%">ปีที่พิมพ์</th>
                            <th width="45%">ชื่องานวิจัย / บทความ</th>
                            <th width="20%">บุคลากร / ผู้แต่ง</th>
                            <th width="17%">แหล่งตีพิมพ์ / วารสาร</th>
                            <th width="10%" class="text-center">อ้างอิง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($researches as $r): 
                            $authors = !empty($r['authors']) ? $r['authors'] : $r['prefix_name'] . $r['first_name'] . ' ' . $r['last_name'];
                        ?>
                            <tr>
                                <td class="fw-bold text-purple text-center">
                                    <?php echo htmlspecialchars($r['publish_year'] ?: '-'); ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;"><?php echo htmlspecialchars($r['title']); ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm me-2">
                                            <?php if($r['profile_picture']): ?>
                                                <img src="../../uploads/avatars/<?php echo $r['profile_picture']; ?>" class="rounded-circle border" width="30" height="30" style="object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted" style="width: 30px; height: 30px; font-size: 10px;"><i class="fa-solid fa-user"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="small fw-bold text-dark text-truncate" style="max-width: 140px;"><?php echo htmlspecialchars($authors); ?></div>
                                            <a href="../cv/public_cv.php?u=<?php echo $r['user_id']; ?>" target="_blank" class="extra-small text-decoration-none text-primary">ดู CV <i class="fa-solid fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($r['publisher']); ?>"><?php echo htmlspecialchars($r['publisher'] ?: '-'); ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if(!empty($r['url_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($r['url_link']); ?>" target="_blank" class="btn btn-sm btn-light border text-primary rounded-pill shadow-sm" title="ไปยังลิงก์ต้นฉบับ"><i class="fa-solid fa-link me-1"></i> Link</a>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

<script>
    $(document).ready(function() {
        if ($.fn.DataTable.isDataTable('#researchTable')) {
            $('#researchTable').DataTable().destroy();
        }
        
        $('#researchTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json" },
            "order": [[ 0, "desc" ]],
            "pageLength": 20,
            "lengthMenu": [10, 20, 50, 100],
            "responsive": true
        });

        // ======================================
        // 1. Chart: Papers by Year
        // ======================================
        const labels1 = <?php echo json_encode(empty($chart1_labels) ? [] : $chart1_labels); ?>;
        const data1 = <?php echo json_encode(empty($chart1_counts) ? [] : $chart1_counts); ?>;
        
        if (document.getElementById('papersByYearChart') && labels1.length > 0) {
            const ctx1 = document.getElementById('papersByYearChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: labels1,
                    datasets: [{
                        label: 'Papers Published',
                        data: data1,
                        borderColor: '#4b0082',
                        backgroundColor: 'rgba(75, 0, 130, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#4b0082',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }

        // ======================================
        // 2. Chart: Top 10 Authors
        // ======================================
        const labels2 = <?php echo json_encode(empty($chart2_labels) ? [] : $chart2_labels); ?>;
        const data2 = <?php echo json_encode(empty($chart2_counts) ? [] : $chart2_counts); ?>;

        if (document.getElementById('topAuthorsChart') && labels2.length > 0) {
            const ctx2 = document.getElementById('topAuthorsChart').getContext('2d');
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: labels2,
                    datasets: [{
                        label: 'Total Papers',
                        data: data2,
                        backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        borderColor: '#198754',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y', 
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } }, y: { grid: { display: false } } }
                }
            });
        }
    });
</script>

</body>
</html>
