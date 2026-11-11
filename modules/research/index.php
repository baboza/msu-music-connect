<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// รับค่าตัวกรองจาก URL
$filter_dept = isset($_GET['dept_id']) ? $_GET['dept_id'] : 'all';
$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : 'all';
$filter_year = isset($_GET['year']) ? $_GET['year'] : 'all';
$filter_source = isset($_GET['source']) ? $_GET['source'] : 'all';
$filter_db = isset($_GET['db_source']) ? $_GET['db_source'] : 'all';

// ฟังก์ชันสร้าง URL สำหรับสลับตัวกรองฐานข้อมูลด่วน
if (!function_exists('getDbFilterUrl')) {
    function getDbFilterUrl($db_name) {
        $params = $_GET;
        if ($db_name === 'all') {
            unset($params['db_source']);
        } else {
            $params['db_source'] = $db_name;
        }
        return 'index.php?' . http_build_query($params);
    }
}

// ดึงข้อมูลสำหรับทำ Dropdown Filters
$depts_list = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$authors_list = $pdo->query("SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.prefix_name FROM users u JOIN cv_research r ON u.user_id = r.user_id ORDER BY u.first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$years_list = $pdo->query("SELECT DISTINCT publish_year FROM cv_research WHERE publish_year IS NOT NULL AND publish_year != '' AND publish_year != '2099' ORDER BY publish_year DESC")->fetchAll(PDO::FETCH_ASSOC);
$sources_list = $pdo->query("SELECT DISTINCT publisher FROM cv_research WHERE publisher IS NOT NULL AND publisher != '' ORDER BY publisher ASC")->fetchAll(PDO::FETCH_ASSOC);

try {
    // ==========================================
    // 1. สร้างเงื่อนไข Query (Where Clause)
    // ==========================================
    $conditions = [];
    $params = [];

    if ($filter_user !== 'all') { $conditions[] = "r.user_id = :uid"; $params[':uid'] = $filter_user; }
    if ($filter_dept !== 'all') { $conditions[] = "u.dept_id = :dept"; $params[':dept'] = $filter_dept; }
    if ($filter_year !== 'all') { $conditions[] = "r.publish_year = :yr"; $params[':yr'] = $filter_year; }
    if ($filter_source !== 'all') { $conditions[] = "r.publisher = :src"; $params[':src'] = $filter_source; }
    if ($filter_db !== 'all') {
        if ($filter_db === 'other') {
            $conditions[] = "(r.db_source IS NULL OR r.db_source = '' OR r.db_source NOT IN ('Scopus', 'ERIC', 'TCI'))";
        } else {
            $conditions[] = "r.db_source = :dbsrc";
            $params[':dbsrc'] = $filter_db;
        }
    }

    $where_sql = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

    // ยอดรวมตามตัวกรอง
    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_sql");
    $stmt_total->execute($params);
    $total_filtered = $stmt_total->fetchColumn();

    // ยอดรวม Citations
    $stmt_cites = $pdo->prepare("SELECT SUM(citations) FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_sql");
    $stmt_cites->execute($params);
    $total_citations = $stmt_cites->fetchColumn() ?: 0;

    // คำนวณยอดรวมแต่ละฐานข้อมูล (อิงตามตัวกรองอื่นๆ โดยไม่ล็อกด้วยฐานข้อมูลที่เลือก เพื่อให้แสดงจำนวนในบัตรสรุปได้ครบถ้วน)
    $stat_conditions = [];
    $stat_params = [];
    if ($filter_user !== 'all') { $stat_conditions[] = "r.user_id = :uid_s"; $stat_params[':uid_s'] = $filter_user; }
    if ($filter_dept !== 'all') { $stat_conditions[] = "u.dept_id = :dept_s"; $stat_params[':dept_s'] = $filter_dept; }
    if ($filter_year !== 'all') { $stat_conditions[] = "r.publish_year = :yr_s"; $stat_params[':yr_s'] = $filter_year; }
    if ($filter_source !== 'all') { $stat_conditions[] = "r.publisher = :src_s"; $stat_params[':src_s'] = $filter_source; }
    $where_stat_sql = count($stat_conditions) > 0 ? "WHERE " . implode(" AND ", $stat_conditions) : "";

    $stmt_db_stats = $pdo->prepare("
        SELECT 
            COUNT(r.res_id) as total_all,
            SUM(CASE WHEN r.db_source = 'Scopus' THEN 1 ELSE 0 END) as total_scopus,
            SUM(CASE WHEN r.db_source = 'ERIC' THEN 1 ELSE 0 END) as total_eric,
            SUM(CASE WHEN r.db_source = 'TCI' THEN 1 ELSE 0 END) as total_tci,
            SUM(CASE WHEN r.db_source IS NULL OR r.db_source = '' OR r.db_source NOT IN ('Scopus', 'ERIC', 'TCI') THEN 1 ELSE 0 END) as total_other
        FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_stat_sql
    ");
    $stmt_db_stats->execute($stat_params);
    $db_stats = $stmt_db_stats->fetch(PDO::FETCH_ASSOC);

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
    $stmt_peak_year = $pdo->query("SELECT publish_year, COUNT(*) as total FROM cv_research WHERE publish_year IS NOT NULL AND publish_year != '' AND publish_year != '2099' GROUP BY publish_year ORDER BY total DESC LIMIT 1");
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
    $trend_conditions[] = "r.publish_year IS NOT NULL AND r.publish_year != '' AND r.publish_year != '2099'";
    $trend_where_sql = "WHERE " . implode(" AND ", $trend_conditions);

    // กราฟ 1: Papers by Year
    $stmt_trend = $pdo->prepare("SELECT r.publish_year, COUNT(*) as count, SUM(r.citations) as total_cites FROM cv_research r JOIN users u ON r.user_id = u.user_id $trend_where_sql GROUP BY r.publish_year ORDER BY r.publish_year DESC LIMIT 6");
    $stmt_trend->execute($params);
    $trend_data = array_reverse($stmt_trend->fetchAll(PDO::FETCH_ASSOC));
    
    $chart1_labels = []; $chart1_counts = []; $chart1_cites = [];
    foreach($trend_data as $td) { 
        $chart1_labels[] = $td['publish_year']; 
        $chart1_counts[] = $td['count']; 
        $chart1_cites[] = $td['total_cites'] ?: 0;
    }

    // กราฟ 2: Top 10 Authors (by paper count)
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

    // กราฟ 3: Top 10 Cited Papers
    $stmt_top_cited = $pdo->prepare("SELECT r.title, r.citations FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_sql ORDER BY r.citations DESC LIMIT 10");
    $stmt_top_cited->execute($params);
    $top_cited_data = $stmt_top_cited->fetchAll(PDO::FETCH_ASSOC);
    $chart3_labels = []; $chart3_counts = [];
    foreach($top_cited_data as $td) { 
        // ตัดคำให้กราฟไม่ยาวเกินไป
        $chart3_labels[] = mb_strlen($td['title'], 'UTF-8') > 30 ? mb_substr($td['title'], 0, 30, 'UTF-8') . '...' : $td['title'];
        $chart3_counts[] = (int)$td['citations']; 
    }

    // กราฟ 4: Top 10 Authors (by citations)
    $chart4_labels = []; $chart4_counts = [];
    if($filter_user == 'all') {
        $stmt_top10_cites = $pdo->prepare("SELECT u.first_name, SUM(r.citations) as total_cites FROM cv_research r JOIN users u ON r.user_id = u.user_id $where_sql GROUP BY r.user_id ORDER BY total_cites DESC LIMIT 10");
        $stmt_top10_cites->execute($params);
        $top10_cites_data = $stmt_top10_cites->fetchAll(PDO::FETCH_ASSOC);
        foreach($top10_cites_data as $td) { 
            $chart4_labels[] = $td['first_name']; 
            $chart4_counts[] = (int)$td['total_cites']; 
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

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f4f6f9; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-end pt-4 pb-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-microscope text-purple me-2"></i>Research Analytics</h1>
            <p class="text-muted small mb-0">วิเคราะห์ข้อมูลผลงานวิชาการ (Papers, Trends & Insights)</p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="fetch_scopus.php" class="btn btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึงข้อมูล Scopus API
            </a>
            <?php if(hasRole(['admin', 'staff'])): ?>
            <a href="map_source.php" class="btn btn-outline-purple rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-layer-group me-1"></i> Map Source
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-2">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Database (ฐานข้อมูล)</label>
                    <select name="db_source" class="form-select border-0 bg-light fw-bold text-purple" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_db === 'all') ? 'selected' : ''; ?>>ทุกฐานข้อมูล (All)</option>
                        <option value="Scopus" <?php echo ($filter_db === 'Scopus') ? 'selected' : ''; ?>>Scopus</option>
                        <option value="ERIC" <?php echo ($filter_db === 'ERIC') ? 'selected' : ''; ?>>ERIC</option>
                        <option value="TCI" <?php echo ($filter_db === 'TCI') ? 'selected' : ''; ?>>TCI</option>
                        <option value="other" <?php echo ($filter_db === 'other') ? 'selected' : ''; ?>>อื่นๆ / ยังไม่ระบุ</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Department</label>
                    <select name="dept_id" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกภาควิชา</option>
                        <?php foreach($depts_list as $d): ?>
                            <option value="<?php echo $d['dept_id']; ?>" <?php echo $filter_dept == $d['dept_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                <div class="col-md-1 col-sm-6">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Year</label>
                    <select name="year" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกปี</option>
                        <?php foreach($years_list as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr['publish_year']); ?>" <?php echo $filter_year == $yr['publish_year'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr['publish_year']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="extra-small text-muted fw-bold text-uppercase mb-1">Source / Journal</label>
                    <select name="source" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                        <option value="all">ทุกแหล่ง (All)</option>
                        <?php foreach($sources_list as $src): ?>
                            <option value="<?php echo htmlspecialchars($src['publisher']); ?>" <?php echo $filter_source == htmlspecialchars($src['publisher']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($src['publisher']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 pt-md-4">
                    <a href="index.php" class="btn btn-outline-secondary w-100 rounded-pill fw-bold">ล้างค่าตัวกรอง</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Database Stats Cards (Clickable Quick Filters) -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-sm-6">
            <a href="<?php echo getDbFilterUrl('all'); ?>" class="text-decoration-none d-block h-100">
                <div class="card border-0 shadow-sm rounded-4 text-center h-100 transition-hover <?php echo ($filter_db === 'all') ? 'border border-3 border-dark shadow' : 'opacity-85'; ?>" style="background: linear-gradient(135deg, #6a1b9a 0%, #4b0082 100%); color: white;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-1 opacity-75">บทความทั้งหมด</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($db_stats['total_all'] ?? 0); ?></h2>
                        <?php if($filter_db === 'all'): ?>
                            <span class="badge bg-white text-dark extra-small rounded-pill mt-1">แสดงทั้งหมด</span>
                        <?php else: ?>
                            <span class="extra-small opacity-75 d-block mt-1">แสดงทั้งหมด</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2 col-sm-6">
            <a href="<?php echo getDbFilterUrl('Scopus'); ?>" class="text-decoration-none d-block h-100">
                <div class="card border-0 shadow-sm rounded-4 text-center h-100 transition-hover <?php echo ($filter_db === 'Scopus') ? 'border border-3 border-white shadow' : 'opacity-85'; ?>" style="background: linear-gradient(135deg, #f8981d 0%, #d87c0b 100%); color: white;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-1 opacity-75">Scopus</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($db_stats['total_scopus'] ?? 0); ?></h2>
                        <?php if($filter_db === 'Scopus'): ?>
                            <span class="badge bg-white text-dark extra-small rounded-pill mt-1"><i class="fa-solid fa-check me-1"></i>กำลังกรอง</span>
                        <?php else: ?>
                            <span class="extra-small opacity-75 d-block mt-1"><i class="fa-solid fa-filter me-1"></i>คลิกเพื่อกรอง</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2 col-sm-6">
            <a href="<?php echo getDbFilterUrl('ERIC'); ?>" class="text-decoration-none d-block h-100">
                <div class="card border-0 shadow-sm rounded-4 text-center h-100 transition-hover <?php echo ($filter_db === 'ERIC') ? 'border border-3 border-white shadow' : 'opacity-85'; ?>" style="background: linear-gradient(135deg, #005a9c 0%, #003d6a 100%); color: white;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-1 opacity-75">ERIC</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($db_stats['total_eric'] ?? 0); ?></h2>
                        <?php if($filter_db === 'ERIC'): ?>
                            <span class="badge bg-white text-dark extra-small rounded-pill mt-1"><i class="fa-solid fa-check me-1"></i>กำลังกรอง</span>
                        <?php else: ?>
                            <span class="extra-small opacity-75 d-block mt-1"><i class="fa-solid fa-filter me-1"></i>คลิกเพื่อกรอง</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2 col-sm-6">
            <a href="<?php echo getDbFilterUrl('TCI'); ?>" class="text-decoration-none d-block h-100">
                <div class="card border-0 shadow-sm rounded-4 text-center h-100 transition-hover <?php echo ($filter_db === 'TCI') ? 'border border-3 border-white shadow' : 'opacity-85'; ?>" style="background: linear-gradient(135deg, #e41e3f 0%, #b3102c 100%); color: white;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-1 opacity-75">TCI</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($db_stats['total_tci'] ?? 0); ?></h2>
                        <?php if($filter_db === 'TCI'): ?>
                            <span class="badge bg-white text-dark extra-small rounded-pill mt-1"><i class="fa-solid fa-check me-1"></i>กำลังกรอง</span>
                        <?php else: ?>
                            <span class="extra-small opacity-75 d-block mt-1"><i class="fa-solid fa-filter me-1"></i>คลิกเพื่อกรอง</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4 col-sm-12">
            <a href="<?php echo getDbFilterUrl('other'); ?>" class="text-decoration-none d-block h-100">
                <div class="card border-0 shadow-sm rounded-4 text-center h-100 transition-hover <?php echo ($filter_db === 'other') ? 'border border-3 border-white shadow' : 'opacity-85'; ?>" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white;">
                    <div class="card-body py-3">
                        <h6 class="fw-bold mb-1 opacity-75">อื่นๆ / ยังไม่ระบุฐานข้อมูล</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($db_stats['total_other'] ?? 0); ?></h2>
                        <?php if($filter_db === 'other'): ?>
                            <span class="badge bg-white text-dark extra-small rounded-pill mt-1"><i class="fa-solid fa-check me-1"></i>กำลังกรอง</span>
                        <?php else: ?>
                            <span class="extra-small opacity-75 d-block mt-1"><i class="fa-solid fa-filter me-1"></i>คลิกเพื่อกรอง</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <?php if($filter_user == 'all' && $filter_dept == 'all' && $filter_year == 'all' && $filter_source == 'all'): ?>
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
                    <div>
                        <span class="badge bg-success-subtle text-success rounded-pill me-2">Citations: <?php echo number_format((float)$total_citations); ?></span>
                        <span class="badge bg-purple-subtle text-purple rounded-pill">ผลงานรวม: <?php echo number_format((float)$total_filtered); ?></span>
                    </div>
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
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-ranking-star text-warning me-2"></i>Top Authors (ผู้แต่งยอดเยี่ยมตามจำนวนผลงาน)</h6>
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

    <!-- แถวสำหรับกราฟ Citations -->
    <div class="row g-4 mb-4">
        <div class="<?php echo ($filter_user == 'all') ? 'col-lg-6' : 'col-12'; ?>">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-fire-flame-curved text-danger me-2"></i>Top 10 Cited Papers (ผลงานที่ถูกอ้างอิงสูงสุด)</h6>
                </div>
                <div class="card-body p-4">
                    <div style="position: relative; height: 250px; width: 100%;">
                        <?php if(empty($chart3_labels) || array_sum($chart3_counts) == 0): ?>
                            <div class="d-flex h-100 align-items-center justify-content-center text-muted small">ยังไม่มีข้อมูลการอ้างอิง (Citations)</div>
                        <?php else: ?>
                            <canvas id="topCitedPapersChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if($filter_user == 'all'): ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-medal text-success me-2"></i>Top Authors by Citations (ผู้แต่งที่ถูกอ้างอิงสูงสุด)</h6>
                </div>
                <div class="card-body p-4">
                    <div style="position: relative; height: 250px; width: 100%;">
                        <?php if(empty($chart4_labels) || array_sum($chart4_counts) == 0): ?>
                            <div class="d-flex h-100 align-items-center justify-content-center text-muted small">ยังไม่มีข้อมูลการอ้างอิง (Citations)</div>
                        <?php else: ?>
                            <canvas id="topCitationsAuthorChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-5 overflow-hidden">
        <div class="card-header bg-white border-bottom pt-4 px-4 pb-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list me-2 text-secondary"></i>รายการผลงานวิชาการ</h5>
            <div>
                <a href="fetch_scopus.php" class="btn btn-sm btn-warning text-dark rounded-pill px-3 shadow-sm fw-bold me-2">
                    <i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึงข้อมูล Scopus API
                </a>
                <?php if(hasRole(['admin', 'staff'])): ?>
                <a href="map_source.php" class="btn btn-sm btn-purple rounded-pill px-3 shadow-sm fw-bold me-2">
                    <i class="fa-solid fa-layer-group me-1"></i> จัดการฐานข้อมูล (Map Source)
                </a>
                <?php endif; ?>
                <a href="export_excel.php?<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-bold">
                    <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="researchTable" class="table table-hover align-middle w-100">
                    <thead class="bg-light text-muted extra-small text-uppercase">
                        <tr>
                            <th width="8%">ปี (ค.ศ.)</th>
                            <th width="35%">ชื่องานวิจัย / บทความ</th>
                            <th width="18%">บุคลากร / ผู้แต่ง</th>
                            <th width="15%">แหล่งตีพิมพ์ / วารสาร</th>
                            <th width="7%" class="text-center">ฐานข้อมูล</th>
                            <th width="7%" class="text-center">Citations</th>
                            <th width="10%" class="text-center">ลิงก์ต้นฉบับ</th>
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
                                    <?php 
                                        if(!empty($r['db_source'])): 
                                            $db_lower = strtolower(trim($r['db_source']));
                                            $badge_bg = '#6c757d'; // default
                                            if (strpos($db_lower, 'scopus') !== false) {
                                                $badge_bg = '#ff7300'; // Scopus Orange
                                            } elseif (strpos($db_lower, 'isi') !== false || strpos($db_lower, 'web of science') !== false) {
                                                $badge_bg = '#5e2750'; // ISI Purple
                                            } elseif (strpos($db_lower, 'tci') !== false) {
                                                $badge_bg = '#0066cc'; // TCI Blue
                                            } elseif (strpos($db_lower, 'eric') !== false) {
                                                $badge_bg = '#28a745'; // ERIC Green
                                            } else {
                                                $badge_bg = '#0d6efd'; // Other DB Blue
                                            }
                                    ?>
                                        <span class="badge rounded-pill shadow-sm text-white" style="background-color: <?php echo $badge_bg; ?>; font-size: 0.75rem;"><i class="fa-solid fa-database me-1"></i><?php echo htmlspecialchars($r['db_source']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold text-success">
                                    <?php echo number_format((float)($r['citations'] ?? 0)); ?>
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

<style>
    .text-purple { color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .extra-small { font-size: 0.75rem; }
    .insight-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .insight-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; }
    .transition-hover { transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease; cursor: pointer; }
    .transition-hover:hover { transform: translateY(-3px); filter: brightness(1.04); }
    .opacity-85 { opacity: 0.9; }
    
    /* 💡 ปรับสไตล์ของ DataTables เล็กน้อย */
    div.dataTables_wrapper div.dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #dee2e6; outline: none; }
    div.dataTables_wrapper div.dataTables_length select { border-radius: 10px; padding: 4px 30px 4px 10px; border: 1px solid #dee2e6; outline: none; }
    .page-item.active .page-link { background-color: #4b0082; border-color: #4b0082; }
    .page-link { color: #4b0082; border-radius: 50% !important; margin: 0 3px; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

<script>
    // 💡 ป้องกัน Script ทำงานก่อนเว็บโหลดเสร็จ
    $(document).ready(function() {
        
        // Init DataTables (บังคับให้ทำงานและตัดหน้า)
        if ($.fn.DataTable.isDataTable('#researchTable')) {
            $('#researchTable').DataTable().destroy();
        }
        
        $('#researchTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json" },
            "order": [[ 0, "desc" ]],
            "pageLength": 20,         // ค่าตั้งต้น 20 แถว
            "lengthMenu": [10, 20, 50, 100], // ให้ผู้ใช้เลือกแสดงได้ 10, 20, 50 หรือ 100 แถว
            "responsive": true
        });

        // ======================================
        // 1. Chart: Papers by Year
        // ======================================
        const labels1 = <?php echo json_encode(empty($chart1_labels) ? [] : $chart1_labels); ?>;
        const data1 = <?php echo json_encode(empty($chart1_counts) ? [] : $chart1_counts); ?>;
        const data1_cites = <?php echo json_encode(empty($chart1_cites) ? [] : $chart1_cites); ?>;
        
        if (document.getElementById('papersByYearChart') && labels1.length > 0) {
            const ctx1 = document.getElementById('papersByYearChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: labels1,
                    datasets: [
                        {
                            label: 'Papers Published',
                            data: data1,
                            borderColor: '#4b0082',
                            backgroundColor: 'rgba(75, 0, 130, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#4b0082',
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Citations',
                            data: data1_cites,
                            borderColor: '#f8981d', // Orange color for citations
                            backgroundColor: 'rgba(248, 152, 29, 0.1)',
                            borderWidth: 3,
                            borderDash: [5, 5],
                            tension: 0.4,
                            fill: false,
                            pointBackgroundColor: '#f8981d',
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                    scales: { 
                        x: { grid: { display: false } }, 
                        y: { type: 'linear', display: true, position: 'left', beginAtZero: true, ticks: { stepSize: 1 } },
                        y1: { type: 'linear', display: true, position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
                    }
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

        // ======================================
        // 3. Chart: Top 10 Cited Papers
        // ======================================
        const labels3 = <?php echo json_encode(empty($chart3_labels) ? [] : $chart3_labels); ?>;
        const data3 = <?php echo json_encode(empty($chart3_counts) ? [] : $chart3_counts); ?>;

        if (document.getElementById('topCitedPapersChart') && labels3.length > 0) {
            const ctx3 = document.getElementById('topCitedPapersChart').getContext('2d');
            new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: labels3,
                    datasets: [{
                        label: 'Citations',
                        data: data3,
                        backgroundColor: 'rgba(106, 27, 154, 0.7)',
                        borderColor: '#6a1b9a',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y', 
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    return context[0].label;
                                }
                            }
                        }
                    },
                    scales: { x: { beginAtZero: true }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } }
                }
            });
        }

        // ======================================
        // 4. Chart: Top 10 Authors by Citations
        // ======================================
        const labels4 = <?php echo json_encode(empty($chart4_labels) ? [] : $chart4_labels); ?>;
        const data4 = <?php echo json_encode(empty($chart4_counts) ? [] : $chart4_counts); ?>;

        if (document.getElementById('topCitationsAuthorChart') && labels4.length > 0) {
            const ctx4 = document.getElementById('topCitationsAuthorChart').getContext('2d');
            new Chart(ctx4, {
                type: 'bar',
                data: {
                    labels: labels4,
                    datasets: [{
                        label: 'Total Citations',
                        data: data4,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: '#0d6efd',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'x', 
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true }, x: { grid: { display: false } } }
                }
            });
        }
    });
</script>