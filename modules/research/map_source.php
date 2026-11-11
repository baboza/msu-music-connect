<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

// อนุญาตเฉพาะ Admin หรือ Staff
checkAccess(['admin', 'staff']);

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

$scopus_api_key = 'f42f287419e8ca38348e7d4cb002df6a';

// ==============================================================
// 1. AJAX Endpoints
// ==============================================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax_action = $_GET['ajax_action'];

    // 1.1 ดึงรายการบทความตาม Publisher (สำหรับ Modal)
    if ($ajax_action === 'get_papers_by_publisher') {
        $publisher = $_GET['publisher'] ?? '';
        $stmt = $pdo->prepare("
            SELECT r.res_id, r.title, r.authors, r.publish_year, r.db_source, r.doi, r.url_link,
                   u.first_name, u.last_name, u.prefix_name
            FROM cv_research r
            LEFT JOIN users u ON r.user_id = u.user_id
            WHERE r.publisher = ? OR (? = '' AND (r.publisher IS NULL OR r.publisher = ''))
            ORDER BY r.publish_year DESC, r.res_id DESC
        ");
        $stmt->execute([$publisher, $publisher]);
        $papers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'papers' => $papers]);
        exit;
    }

    // 1.2 ตรวจสอบบทความกับ Scopus API (เดี่ยว)
    if ($ajax_action === 'check_scopus') {
        $res_id = intval($_GET['res_id'] ?? 0);
        $title = trim($_GET['title'] ?? '');
        $doi = trim($_GET['doi'] ?? '');

        if (empty($title) && empty($doi)) {
            echo json_encode(['success' => false, 'message' => 'ไม่มีชื่อบทความหรือ DOI']);
            exit;
        }

        $query = '';
        if (!empty($doi)) {
            $query = 'DOI("' . $doi . '")';
        } else {
            $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title);
            $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));
            $words = explode(' ', $cleanTitle);
            $queryTitle = implode(' ', array_slice($words, 0, 8));
            $query = 'TITLE("' . $queryTitle . '")';
        }

        $url = 'https://api.elsevier.com/content/search/scopus?' . http_build_query([
            'query' => $query,
            'count' => 1,
            'view' => 'STANDARD'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-ELS-APIKey: ' . $scopus_api_key,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($res, true);
            $total = intval($data['search-results']['opensearch:totalResults'] ?? 0);
            if ($total > 0 && !empty($data['search-results']['entry'])) {
                $entry = $data['search-results']['entry'][0];
                $scopus_title = $entry['dc:title'] ?? '';
                $scopus_doi = $entry['prism:doi'] ?? '';
                $scopus_cites = intval($entry['citedby-count'] ?? 0);
                
                // อัปเดตลง DB ทันที
                if ($res_id > 0) {
                    $up_sql = "UPDATE cv_research SET db_source = 'Scopus'";
                    $params = [];
                    if (!empty($scopus_doi)) {
                        $up_sql .= ", doi = COALESCE(NULLIF(doi, ''), ?)";
                        $params[] = $scopus_doi;
                    }
                    if ($scopus_cites > 0) {
                        $up_sql .= ", citations = GREATEST(COALESCE(citations, 0), ?)";
                        $params[] = $scopus_cites;
                    }
                    $up_sql .= " WHERE res_id = ?";
                    $params[] = $res_id;

                    $stmt_up = $pdo->prepare($up_sql);
                    $stmt_up->execute($params);
                }

                echo json_encode([
                    'success' => true,
                    'found' => true,
                    'scopus_title' => $scopus_title,
                    'scopus_doi' => $scopus_doi,
                    'scopus_cites' => $scopus_cites
                ]);
                exit;
            } else {
                echo json_encode(['success' => true, 'found' => false]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Scopus API HTTP Error: ' . $http_code]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ==============================================================
// 2. จัดการ POST Actions
// ==============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 2.1 บันทึกตามกลุ่ม Publisher (ฟังก์ชันเดิม)
    if ($action === 'map_source') {
        if (isset($_POST['mappings']) && is_array($_POST['mappings'])) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE cv_research SET db_source = ? WHERE publisher = ?");
                $updated_count = 0;
                foreach ($_POST['mappings'] as $publisher_b64 => $db) {
                    if (!empty($db)) {
                        $pub_name = base64_decode($publisher_b64);
                        $stmt->execute([$db, $pub_name]);
                        $updated_count += $stmt->rowCount();
                    }
                }
                $pdo->commit();
                $success_msg = "อัปเดตฐานข้อมูลตามกลุ่มวารสารสำเร็จ (ปรับปรุงแล้ว $updated_count รายการ)";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    }

    // 2.2 บันทึกรายบทความเดี่ยว (Single Paper)
    elseif ($action === 'update_single_paper') {
        $res_id = intval($_POST['res_id'] ?? 0);
        $db_source = trim($_POST['db_source'] ?? '');
        if ($res_id > 0) {
            $stmt = $pdo->prepare("UPDATE cv_research SET db_source = ? WHERE res_id = ?");
            $stmt->execute([$db_source, $res_id]);
            $success_msg = "อัปเดตฐานข้อมูลของบทความ #$res_id เรียบร้อยแล้ว";
        }
    }

    // 2.3 บันทึกแบบกลุ่มหลายบทความ (Bulk Map Papers)
    elseif ($action === 'bulk_map_papers') {
        $selected_res_ids = $_POST['res_ids'] ?? [];
        $bulk_db_source = trim($_POST['bulk_db_source'] ?? '');

        if (!empty($selected_res_ids) && is_array($selected_res_ids) && !empty($bulk_db_source)) {
            $in_clause = implode(',', array_map('intval', $selected_res_ids));
            $stmt = $pdo->prepare("UPDATE cv_research SET db_source = ? WHERE res_id IN ($in_clause)");
            $stmt->execute([$bulk_db_source]);
            $count = $stmt->rowCount();
            $success_msg = "อัปเดตฐานข้อมูลเป็น '$bulk_db_source' จำนวน $count บทความเรียบร้อยแล้ว";
        } else {
            $error_msg = "กรุณาเลือกบทความและฐานข้อมูลที่ต้องการอัปเดต";
        }
    }

    // 2.4 Auto-Map ด้วยระบบกฎอัจฉริยะ (Smart Rule Auto-Map)
    elseif ($action === 'auto_map_rules') {
        $pdo->beginTransaction();
        try {
            $auto_count = 0;

            // 1. Scopus
            $q_scopus = $pdo->exec("
                UPDATE cv_research 
                SET db_source = 'Scopus' 
                WHERE (db_source IS NULL OR db_source = '' OR db_source = 'Other')
                  AND (
                    LOWER(publisher) LIKE '%scopus%'
                    OR LOWER(publisher) LIKE '%multidisciplinary science journal%'
                    OR LOWER(title) LIKE '%multidisciplinary science journal%'
                    OR LOWER(publisher) LIKE '%interdisciplinary cultural studies%'
                    OR LOWER(publisher) LIKE '%critical cultural studies%'
                    OR LOWER(publisher) LIKE '%sage open%'
                    OR LOWER(publisher) LIKE '%bioresources%'
                    OR LOWER(publisher) LIKE '%pakistan journal%'
                    OR LOWER(publisher) LIKE '%manusya: journal of humanities%'
                  )
            ");
            $auto_count += $q_scopus;

            // 2. TCI
            $q_tci = $pdo->exec("
                UPDATE cv_research 
                SET db_source = 'TCI' 
                WHERE (db_source IS NULL OR db_source = '' OR db_source = 'Other')
                  AND (
                    LOWER(publisher) LIKE '%tci%'
                    OR LOWER(publisher) LIKE '%thaijo%'
                    OR LOWER(url_link) LIKE '%tci-thaijo%'
                    OR LOWER(publisher) LIKE '%วารสาร%'
                    OR LOWER(publisher) LIKE '%nimitmai review journal%'
                    OR LOWER(publisher) LIKE '%mekong-salween civilization%'
                    OR LOWER(publisher) LIKE '%humanities and social sciences%'
                    OR LOWER(publisher) LIKE '%journal of cultural approach%'
                  )
                  AND LOWER(publisher) NOT LIKE '%scopus%'
            ");
            $auto_count += $q_tci;

            // 3. ERIC
            $q_eric = $pdo->exec("
                UPDATE cv_research 
                SET db_source = 'ERIC' 
                WHERE (db_source IS NULL OR db_source = '' OR db_source = 'Other')
                  AND (
                    LOWER(publisher) LIKE '%eric%'
                    OR LOWER(publisher) LIKE '%education and literacy studies%'
                    OR LOWER(publisher) LIKE '%education & literacy studies%'
                    OR LOWER(publisher) LIKE '%african educational research%'
                  )
            ");
            $auto_count += $q_eric;

            // 4. Proceeding / การประชุมวิชาการ
            $q_proc = $pdo->exec("
                UPDATE cv_research 
                SET db_source = 'Proceeding' 
                WHERE (db_source IS NULL OR db_source = '' OR db_source = 'Other')
                  AND (
                    LOWER(publisher) LIKE '%conference%'
                    OR LOWER(publisher) LIKE '%symposium%'
                    OR LOWER(publisher) LIKE '%iscfa%'
                    OR LOWER(publisher) LIKE '%far8%'
                    OR LOWER(publisher) LIKE '%ncpac%'
                    OR LOWER(publisher) LIKE '%ประชุมวิชาการ%'
                    OR LOWER(publisher) LIKE '%ผลงานสร้างสรรค์%'
                  )
            ");
            $auto_count += $q_proc;

            // 5. วิทยานิพนธ์ / สถาบัน
            $q_thesis = $pdo->exec("
                UPDATE cv_research 
                SET db_source = 'Thesis' 
                WHERE (db_source IS NULL OR db_source = '' OR db_source = 'Other')
                  AND (
                    LOWER(publisher) LIKE '%mahasarakham university, 20%'
                    OR LOWER(publisher) LIKE '%mahidol university, 20%'
                    OR LOWER(url_link) LIKE '%thailis%'
                    OR LOWER(url_link) LIKE '%digital.library%'
                  )
            ");
            $auto_count += $q_thesis;

            $pdo->commit();
            $success_msg = "ระบบตรวจจับและจัดกลุ่มฐานข้อมูลอัตโนมัติสำเร็จทั้งหมด $auto_count บทความ! (Scopus: $q_scopus, TCI: $q_tci, ERIC: $q_eric, Proceeding: $q_proc, Thesis: $q_thesis)";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "เกิดข้อผิดพลาดในการ Auto-Map: " . $e->getMessage();
        }
    }
}

// ==============================================================
// 3. ดึงสถิติภาพรวม (Statistics)
// ==============================================================
$stmt_stats = $pdo->query("
    SELECT 
        COUNT(res_id) as total_all,
        SUM(CASE WHEN db_source = 'Scopus' THEN 1 ELSE 0 END) as total_scopus,
        SUM(CASE WHEN db_source = 'ERIC' THEN 1 ELSE 0 END) as total_eric,
        SUM(CASE WHEN db_source = 'TCI' THEN 1 ELSE 0 END) as total_tci,
        SUM(CASE WHEN db_source = 'Proceeding' THEN 1 ELSE 0 END) as total_proc,
        SUM(CASE WHEN db_source = 'Thesis' THEN 1 ELSE 0 END) as total_thesis,
        SUM(CASE WHEN db_source IS NULL OR db_source = '' OR db_source = 'Other' THEN 1 ELSE 0 END) as total_unmapped
    FROM cv_research
");
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// ==============================================================
// 4. ข้อมูลสำหรับ Tab 1: กลุ่มตาม Publisher
// ==============================================================
$sources_stmt = $pdo->query("
    SELECT 
        COALESCE(NULLIF(TRIM(publisher), ''), 'ไม่ระบุแหล่งตีพิมพ์') as publisher_name,
        publisher,
        COUNT(res_id) as total_papers,
        MAX(db_source) as current_db,
        SUM(CASE WHEN db_source IS NULL OR db_source = '' THEN 1 ELSE 0 END) as unmapped_in_group
    FROM cv_research 
    GROUP BY COALESCE(NULLIF(TRIM(publisher), ''), 'ไม่ระบุแหล่งตีพิมพ์')
    ORDER BY total_papers DESC
");
$sources = $sources_stmt->fetchAll(PDO::FETCH_ASSOC);

// ==============================================================
// 5. ข้อมูลสำหรับ Tab 2: รายการรายบทความ (Filterable)
// ==============================================================
$filter_paper_db = $_GET['paper_db'] ?? 'unmapped';
$paper_search = trim($_GET['paper_search'] ?? '');

$p_conditions = [];
$p_params = [];

if ($filter_paper_db === 'unmapped') {
    $p_conditions[] = "(r.db_source IS NULL OR r.db_source = '' OR r.db_source = 'Other')";
} elseif ($filter_paper_db !== 'all') {
    $p_conditions[] = "r.db_source = :db_source";
    $p_params[':db_source'] = $filter_paper_db;
}

if (!empty($paper_search)) {
    $p_conditions[] = "(r.title LIKE :s OR r.publisher LIKE :s OR r.authors LIKE :s OR u.first_name LIKE :s OR u.last_name LIKE :s)";
    $p_params[':s'] = "%$paper_search%";
}

$paper_where = !empty($p_conditions) ? "WHERE " . implode(" AND ", $p_conditions) : "";

$individual_papers_stmt = $pdo->prepare("
    SELECT r.res_id, r.title, r.authors, r.publisher, r.publish_year, r.db_source, r.doi, r.url_link, r.citations,
           u.first_name, u.last_name, u.prefix_name
    FROM cv_research r
    LEFT JOIN users u ON r.user_id = u.user_id
    $paper_where
    ORDER BY (r.db_source IS NULL OR r.db_source = '') DESC, r.publish_year DESC, r.res_id DESC
    LIMIT 300
");
$individual_papers_stmt->execute($p_params);
$individual_papers = $individual_papers_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fa; min-height: 100vh;">

    <!-- Header Section -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-layer-group text-purple me-2"></i>จัดการและจับคู่ฐานข้อมูลงานวิจัย (Database Mapping)
            </h1>
            <p class="text-muted small mb-0">
                แก้ไขปัญหาบทความที่ไม่ระบุฐานข้อมูล จัดกลุ่มวารสาร และตรวจสอบฐานข้อมูลระดับนานาชาติอัตโนมัติ
            </p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="fetch_scopus.php" class="btn btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึง Scopus API
            </a>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-arrow-left me-1"></i> ภาพรวมผลงาน
            </a>
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 bg-white">
                <div class="card-body py-3">
                    <div class="extra-small text-muted fw-bold text-uppercase mb-1">บทความทั้งหมด</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($stats['total_all'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 text-white" style="background: linear-gradient(135deg, #f8981d 0%, #d87c0b 100%);">
                <div class="card-body py-3">
                    <div class="extra-small opacity-75 fw-bold text-uppercase mb-1">Scopus</div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($stats['total_scopus'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 text-white" style="background: linear-gradient(135deg, #e41e3f 0%, #b3102c 100%);">
                <div class="card-body py-3">
                    <div class="extra-small opacity-75 fw-bold text-uppercase mb-1">TCI</div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($stats['total_tci'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 text-white" style="background: linear-gradient(135deg, #005a9c 0%, #003d6a 100%);">
                <div class="card-body py-3">
                    <div class="extra-small opacity-75 fw-bold text-uppercase mb-1">ERIC</div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($stats['total_eric'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 text-white" style="background: linear-gradient(135deg, #198754 0%, #0d5935 100%);">
                <div class="card-body py-3">
                    <div class="extra-small opacity-75 fw-bold text-uppercase mb-1">Proceeding / Thesis</div>
                    <h3 class="fw-bold mb-0"><?php echo number_format(($stats['total_proc'] ?? 0) + ($stats['total_thesis'] ?? 0)); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 col-6">
            <div class="card border-0 shadow-sm rounded-4 text-center h-100 text-white position-relative" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                <div class="card-body py-3">
                    <div class="extra-small opacity-75 fw-bold text-uppercase mb-1">ยังไม่ระบุฐาน</div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($stats['total_unmapped'] ?? 0); ?></h3>
                </div>
                <?php if(($stats['total_unmapped'] ?? 0) > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle p-2 bg-danger border border-light rounded-circle"></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-circle-check fs-4 me-3 text-success"></i>
                <div>
                    <h6 class="fw-bold mb-0">สำเร็จ!</h6>
                    <div class="small"><?php echo htmlspecialchars($success_msg); ?></div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-circle-exclamation fs-4 me-3 text-danger"></i>
                <div>
                    <h6 class="fw-bold mb-0">แจ้งเตือน</h6>
                    <div class="small"><?php echo htmlspecialchars($error_msg); ?></div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Smart Auto-Detect & Guidance Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 overflow-hidden border-start border-4 border-warning">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="fa-solid fa-wand-magic-sparkles text-warning me-2"></i>ระบบช่วยตรวจสอบและจัดกลุ่มฐานข้อมูลอัตโนมัติ
                    </h5>
                    <p class="text-muted small mb-0">
                        ระบบจะวิเคราะห์ชื่อแหล่งตีพิมพ์ (Publisher), ThaiJO URLs, วารสารไทย, Scopus Journals, Proceeding และเชื่อมต่อ Scopus API ตรวจสอบให้อัตโนมัติ
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-warning text-dark fw-bold rounded-pill px-4 shadow-sm" onclick="startBatchScopusCheck()">
                        <i class="fa-solid fa-microscope text-warning me-1"></i> เช็ค Scopus อัตโนมัติ (1 คลิก)
                    </button>
                    <form method="POST" onsubmit="return confirm('ต้องการให้ระบบสแกนและจัดกลุ่มฐานข้อมูลอัตโนมัติสำหรับบทความที่ยังไม่ระบุฐานหรือไม่?');">
                        <input type="hidden" name="action" value="auto_map_rules">
                        <button type="submit" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm">
                            <i class="fa-solid fa-bolt-lightning me-1"></i> Auto-Map กฎอัจฉริยะ (1 คลิก)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Management Mode Tabs -->
    <ul class="nav nav-pills mb-3 gap-2" id="mappingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill fw-bold px-4 py-2 shadow-sm" id="tab-individual-btn" data-bs-toggle="pill" data-bs-target="#tab-individual" type="button" role="tab">
                <i class="fa-solid fa-file-lines me-1"></i> จัดการรายบทความ (Individual Papers)
                <span class="badge bg-danger ms-1"><?php echo number_format($stats['total_unmapped'] ?? 0); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold px-4 py-2 shadow-sm" id="tab-publishers-btn" data-bs-toggle="pill" data-bs-target="#tab-publishers" type="button" role="tab">
                <i class="fa-solid fa-book-bookmark me-1"></i> จัดกลุ่มตามวารสาร / แหล่งตีพิมพ์ (By Journal/Publisher)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="mappingTabsContent">

        <!-- ============================================================== -->
        <!-- TAB 1: จัดการรายบทความ (Individual Papers) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade show active" id="tab-individual" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
                <div class="card-header bg-white border-bottom p-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-6">
                            <h5 class="fw-bold text-dark mb-1">
                                <i class="fa-solid fa-list-check text-purple me-2"></i>รายการบทความวิจัย
                            </h5>
                            <div class="small text-muted">
                                สามารถตรวจสอบรายชื่อบทความ ค้นหา และกดเลือกฐานข้อมูลรายบทความ หรืออัปเดตพร้อมกันได้ทันที
                            </div>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" class="d-flex gap-2 justify-content-md-end">
                                <select name="paper_db" class="form-select form-select-sm rounded-pill bg-light border-0 fw-bold" style="max-width: 220px;" onchange="this.form.submit()">
                                    <option value="unmapped" <?php echo ($filter_paper_db === 'unmapped') ? 'selected' : ''; ?>>
                                        ⚠️ ยังไม่ระบุฐานข้อมูล (Unmapped)
                                    </option>
                                    <option value="all" <?php echo ($filter_paper_db === 'all') ? 'selected' : ''; ?>>
                                        บทความทั้งหมด (All Papers)
                                    </option>
                                    <option value="Scopus" <?php echo ($filter_paper_db === 'Scopus') ? 'selected' : ''; ?>>Scopus</option>
                                    <option value="TCI" <?php echo ($filter_paper_db === 'TCI') ? 'selected' : ''; ?>>TCI</option>
                                    <option value="ERIC" <?php echo ($filter_paper_db === 'ERIC') ? 'selected' : ''; ?>>ERIC</option>
                                    <option value="Proceeding" <?php echo ($filter_paper_db === 'Proceeding') ? 'selected' : ''; ?>>Proceeding</option>
                                    <option value="Thesis" <?php echo ($filter_paper_db === 'Thesis') ? 'selected' : ''; ?>>Thesis</option>
                                </select>
                                <input type="text" name="paper_search" class="form-control form-control-sm rounded-pill" placeholder="ค้นหาชื่อเรื่อง/ผู้แต่ง..." value="<?php echo htmlspecialchars($paper_search); ?>" style="max-width: 200px;">
                                <button type="submit" class="btn btn-sm btn-purple rounded-pill px-3 fw-bold">ค้นหา</button>
                            </form>
                        </div>
                    </div>
                </div>

                <form method="POST" action="map_source.php" id="bulkPaperForm">
                    <input type="hidden" name="action" value="bulk_map_papers">

                    <!-- Bulk Action Bar -->
                    <div class="bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="checkbox" id="selectAllPapers" class="form-check-input fs-5" onchange="toggleSelectAllPapers(this)">
                            <label for="selectAllPapers" class="small fw-bold text-dark cursor-pointer mb-0">เลือกทั้งหมด</label>
                            <span class="badge bg-secondary rounded-pill ms-2" id="selectedPaperCount">เลือก 0 รายการ</span>
                            <button type="button" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm ms-2" onclick="startBatchScopusCheck()">
                                <i class="fa-solid fa-microscope me-1"></i> เช็ค Scopus อัตโนมัติ (Batch Check)
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small fw-bold text-muted text-nowrap mb-0">กำหนดฐานข้อมูลที่เลือกเป็น:</label>
                            <select name="bulk_db_source" class="form-select form-select-sm rounded-pill border-0 bg-white shadow-sm fw-bold" style="width: 150px;">
                                <option value="">-- เลือกฐานข้อมูล --</option>
                                <option value="Scopus">Scopus</option>
                                <option value="TCI">TCI</option>
                                <option value="ERIC">ERIC</option>
                                <option value="ISI">ISI / WoS</option>
                                <option value="Proceeding">Proceeding</option>
                                <option value="Thesis">Thesis</option>
                                <option value="Other">อื่นๆ</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" onclick="return confirm('ยืนยันการอัปเดตบทความที่เลือกทั้งหมดหรือไม่?');">
                                <i class="fa-solid fa-check me-1"></i> อัปเดตพร้อมกัน
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted extra-small text-uppercase">
                                <tr>
                                    <th width="4%" class="text-center">#</th>
                                    <th width="36%">ชื่องานวิจัย / ผู้แต่ง</th>
                                    <th width="18%">แหล่งตีพิมพ์ / ปี</th>
                                    <th width="12%" class="text-center">ฐานข้อมูลปัจจุบัน</th>
                                    <th width="14%">ปรับฐานข้อมูล</th>
                                    <th width="16%" class="text-center">เครื่องมือสืบค้น</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($individual_papers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-circle-check fs-1 text-success opacity-50 mb-3"></i>
                                            <h6 class="fw-bold">ไม่พบบทความตามเงื่อนไขที่เลือก</h6>
                                            <div class="small">บทความทั้งหมดได้รับการระบุฐานข้อมูลเรียบร้อยแล้ว หรือไม่มีรายการที่ค้นหา</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($individual_papers as $p): ?>
                                        <tr id="paper_row_<?php echo $p['res_id']; ?>" 
                                            class="paper-row-item <?php echo (empty($p['db_source']) || $p['db_source'] === 'Other') ? 'bg-light bg-opacity-25' : ''; ?>"
                                            data-id="<?php echo $p['res_id']; ?>"
                                            data-title="<?php echo htmlspecialchars($p['title']); ?>"
                                            data-doi="<?php echo htmlspecialchars($p['doi'] ?? ''); ?>"
                                            data-dbsource="<?php echo htmlspecialchars($p['db_source'] ?? ''); ?>">
                                            <td class="text-center">
                                                <input type="checkbox" name="res_ids[]" value="<?php echo $p['res_id']; ?>" class="form-check-input paper-chk fs-5" onchange="updatePaperSelectionCount()">
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark mb-1" style="font-size: 0.92rem; line-height: 1.4;">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                </div>
                                                <div class="extra-small text-muted">
                                                    <i class="fa-solid fa-user me-1 text-purple"></i>
                                                    <?php echo htmlspecialchars($p['authors'] ?: (($p['prefix_name'] ?? '') . $p['first_name'] . ' ' . $p['last_name'])); ?>
                                                </div>
                                                <?php if(!empty($p['doi'])): ?>
                                                    <div class="extra-small text-secondary mt-1">
                                                        <span class="badge bg-light text-muted border">DOI: <?php echo htmlspecialchars($p['doi']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold text-secondary">
                                                    <?php echo htmlspecialchars($p['publisher'] ?: 'ไม่ระบุแหล่งตีพิมพ์'); ?>
                                                </div>
                                                <div class="extra-small text-muted mt-1">
                                                    ปี ค.ศ. <strong><?php echo htmlspecialchars($p['publish_year'] ?: '-'); ?></strong>
                                                    <?php if($p['citations'] > 0): ?>
                                                        | <i class="fa-solid fa-quote-right text-warning"></i> <?php echo $p['citations']; ?> citations
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if(empty($p['db_source']) || $p['db_source'] === 'Other'): ?>
                                                    <span class="badge bg-secondary bg-opacity-75 rounded-pill px-2 py-1 extra-small" id="badge_<?php echo $p['res_id']; ?>">
                                                        <i class="fa-solid fa-question me-1"></i> ยังไม่ระบุ
                                                    </span>
                                                <?php elseif($p['db_source'] === 'Scopus'): ?>
                                                    <span class="badge bg-warning text-dark rounded-pill px-2 py-1 extra-small" id="badge_<?php echo $p['res_id']; ?>">Scopus</span>
                                                <?php elseif($p['db_source'] === 'TCI'): ?>
                                                    <span class="badge bg-danger rounded-pill px-2 py-1 extra-small" id="badge_<?php echo $p['res_id']; ?>">TCI</span>
                                                <?php elseif($p['db_source'] === 'ERIC'): ?>
                                                    <span class="badge bg-primary rounded-pill px-2 py-1 extra-small" id="badge_<?php echo $p['res_id']; ?>">ERIC</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success rounded-pill px-2 py-1 extra-small" id="badge_<?php echo $p['res_id']; ?>"><?php echo htmlspecialchars($p['db_source']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm border-secondary shadow-none fw-bold" style="font-size: 0.8rem;" onchange="saveSinglePaperDb(<?php echo $p['res_id']; ?>, this.value)">
                                                    <option value="">-- ไม่ระบุ --</option>
                                                    <option value="Scopus" <?php echo ($p['db_source'] === 'Scopus') ? 'selected' : ''; ?>>Scopus</option>
                                                    <option value="TCI" <?php echo ($p['db_source'] === 'TCI') ? 'selected' : ''; ?>>TCI</option>
                                                    <option value="ERIC" <?php echo ($p['db_source'] === 'ERIC') ? 'selected' : ''; ?>>ERIC</option>
                                                    <option value="ISI" <?php echo ($p['db_source'] === 'ISI') ? 'selected' : ''; ?>>ISI / WoS</option>
                                                    <option value="Proceeding" <?php echo ($p['db_source'] === 'Proceeding') ? 'selected' : ''; ?>>Proceeding</option>
                                                    <option value="Thesis" <?php echo ($p['db_source'] === 'Thesis') ? 'selected' : ''; ?>>Thesis</option>
                                                    <option value="Other" <?php echo ($p['db_source'] === 'Other') ? 'selected' : ''; ?>>อื่นๆ</option>
                                                </select>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm text-nowrap">
                                                    <!-- ตรวจสอบ Scopus API -->
                                                    <button type="button" class="btn btn-outline-warning text-dark px-2 fw-semibold" title="ตรวจกับ Scopus API" onclick="checkScopusSingle(<?php echo $p['res_id']; ?>, '<?php echo htmlspecialchars(addslashes($p['title'])); ?>', '<?php echo htmlspecialchars(addslashes($p['doi'] ?? '')); ?>', this)">
                                                        <i class="fa-solid fa-microscope text-warning me-1"></i> Scopus
                                                    </button>
                                                    <!-- Google Search -->
                                                    <a href="https://www.google.com/search?q=<?php echo urlencode($p['title']); ?>" target="_blank" class="btn btn-outline-secondary" title="ค้นหาบน Google">
                                                        <i class="fa-brands fa-google"></i>
                                                    </a>
                                                    <!-- ThaiJO Search -->
                                                    <a href="https://tci-thaijo.org/?q=<?php echo urlencode($p['title']); ?>" target="_blank" class="btn btn-outline-danger" title="ค้นหาบน ThaiJO / TCI">
                                                        <i class="fa-solid fa-magnifying-glass"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB 2: จัดกลุ่มตามวารสาร / แหล่งตีพิมพ์ (By Publisher) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="tab-publishers" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden bg-white">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="fa-solid fa-book-bookmark text-purple me-2"></i>จัดกลุ่มฐานข้อมูลตามวารสาร / แหล่งตีพิมพ์ (Publisher Grouping)
                    </h5>
                    <div class="small text-muted">
                        เลือกฐานข้อมูลให้กับวารสารทางด้านซ้าย การเลือกครั้งเดียวจะอัปเดตทุกบทความในวารสารนั้นโดยอัตโนมัติ
                    </div>
                </div>

                <form action="map_source.php" method="POST" class="p-4">
                    <input type="hidden" name="action" value="map_source">
                    <div class="table-responsive" style="max-height: 65vh; overflow-y: auto;">
                        <table class="table table-hover align-middle border">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th width="45%" class="px-3">แหล่งตีพิมพ์ / วารสาร (Publisher)</th>
                                    <th width="15%" class="text-center">จำนวนบทความ</th>
                                    <th width="25%" class="px-3">ฐานข้อมูล (Database)</th>
                                    <th width="15%" class="text-center">เครื่องมือสืบค้น</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sources as $src): ?>
                                <tr>
                                    <td class="px-3">
                                        <div class="fw-bold text-dark" style="font-size: 0.95rem;">
                                            <?php echo htmlspecialchars($src['publisher_name']); ?>
                                        </div>
                                        <?php if($src['unmapped_in_group'] > 0): ?>
                                            <span class="badge bg-warning text-dark extra-small rounded-pill">
                                                ยังไม่ระบุ <?php echo $src['unmapped_in_group']; ?> เรื่อง
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold" onclick="openPapersModal('<?php echo htmlspecialchars(addslashes($src['publisher'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($src['publisher_name'])); ?>')">
                                            <i class="fa-solid fa-eye me-1"></i> <?php echo $src['total_papers']; ?> รายการ
                                        </button>
                                    </td>
                                    <td class="px-3">
                                        <select name="mappings[<?php echo base64_encode($src['publisher'] ?? ''); ?>]" class="form-select form-select-sm border-secondary shadow-none fw-bold">
                                            <option value="">-- ไม่ระบุ --</option>
                                            <option value="Scopus" <?php echo ($src['current_db'] == 'Scopus') ? 'selected' : ''; ?>>Scopus</option>
                                            <option value="TCI" <?php echo ($src['current_db'] == 'TCI') ? 'selected' : ''; ?>>TCI</option>
                                            <option value="ERIC" <?php echo ($src['current_db'] == 'ERIC') ? 'selected' : ''; ?>>ERIC</option>
                                            <option value="ISI" <?php echo ($src['current_db'] == 'ISI') ? 'selected' : ''; ?>>ISI / Web of Science</option>
                                            <option value="Proceeding" <?php echo ($src['current_db'] == 'Proceeding') ? 'selected' : ''; ?>>Proceeding / การประชุม</option>
                                            <option value="Thesis" <?php echo ($src['current_db'] == 'Thesis') ? 'selected' : ''; ?>>Thesis / วิทยานิพนธ์</option>
                                            <option value="Other" <?php echo ($src['current_db'] == 'Other') ? 'selected' : ''; ?>>อื่นๆ</option>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="https://www.google.com/search?q=<?php echo urlencode($src['publisher_name']); ?>" target="_blank" class="btn btn-outline-secondary" title="ค้นหาใน Google">
                                                <i class="fa-brands fa-google"></i>
                                            </a>
                                            <a href="https://tci-thaijo.org/?q=<?php echo urlencode($src['publisher_name']); ?>" target="_blank" class="btn btn-outline-danger" title="ค้นหาใน TCI / ThaiJO">
                                                <i class="fa-solid fa-magnifying-glass"></i>
                                            </a>
                                            <a href="https://www.scopus.com/sources.uri" target="_blank" class="btn btn-outline-warning text-dark" title="ค้นหา Scopus Sources">
                                                <i class="fa-solid fa-square-arrow-up-right"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-purple btn-lg rounded-pill px-5 fw-bold shadow">
                            <i class="fa-solid fa-save me-2"></i> บันทึกการจับคู่วารสารทั้งหมด
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</main>

<!-- Modal: ดูบทความภายใต้วารสาร -->
<div class="modal fade" id="papersModal" tabindex="-1" aria-labelledby="papersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark" id="papersModalLabel">
                        <i class="fa-solid fa-book-open text-purple me-2"></i>รายการบทความในวารสาร
                    </h5>
                    <div class="small text-muted" id="modalPublisherName"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="modalPapersBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-purple" role="status"></div>
                    <div class="small text-muted mt-2">กำลังโหลดบทความ...</div>
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ตรวจสอบ Scopus อัตโนมัติแบบกลุ่ม (Batch Verifier) -->
<div class="modal fade" id="scopusBatchModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="scopusBatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 pt-4 pb-3 bg-light">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-warning bg-opacity-25 p-3 me-3 text-warning">
                        <i class="fa-solid fa-microscope fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="scopusBatchModalLabel">
                            ตรวจสอบบทความกับ Scopus API อัตโนมัติ
                        </h5>
                        <div class="small text-muted">ระบบกำลังส่งคำค้นหาไปยัง Elsevier Scopus API และอัปเดตฐานข้อมูลให้ทันที</div>
                    </div>
                </div>
            </div>
            <div class="modal-body p-4">
                <!-- Progress Bar Section -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-dark" id="batchStatusText">กำลังเตรียมการตรวจสอบ...</span>
                        <span class="badge bg-purple rounded-pill px-3 py-2 fw-bold" id="batchProgressPercent">0%</span>
                    </div>
                    <div class="progress rounded-pill shadow-inner" style="height: 18px; background-color: #e9ecef;">
                        <div id="batchProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning text-dark fw-bold" role="progressbar" style="width: 0%;">
                        </div>
                    </div>
                    <div class="extra-small text-muted mt-2 text-truncate" id="batchCurrentItemText">
                        กำลังตรวจสอบ: -
                    </div>
                </div>

                <!-- Stats Counters -->
                <div class="row g-3 text-center mb-4">
                    <div class="col-4">
                        <div class="card border-0 bg-light rounded-3 p-3 h-100">
                            <div class="extra-small text-muted fw-bold text-uppercase">ตรวจแล้ว</div>
                            <h4 class="fw-bold mb-0 text-dark" id="counterChecked">0 / 0</h4>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 bg-success bg-opacity-10 rounded-3 p-3 h-100">
                            <div class="extra-small text-success fw-bold text-uppercase">🎉 พบใน Scopus</div>
                            <h4 class="fw-bold mb-0 text-success" id="counterFound">0</h4>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 bg-secondary bg-opacity-10 rounded-3 p-3 h-100">
                            <div class="extra-small text-secondary fw-bold text-uppercase">ไม่พบใน Scopus</div>
                            <h4 class="fw-bold mb-0 text-muted" id="counterNotFound">0</h4>
                        </div>
                    </div>
                </div>

                <!-- Live Results Feed / Log -->
                <div>
                    <h6 class="small fw-bold text-dark mb-2">
                        <i class="fa-solid fa-square-poll-horizontal text-purple me-1"></i> รายการที่พบใน Scopus (อัปเดตแล้ว):
                    </h6>
                    <div id="batchFoundList" class="border rounded-3 p-3 bg-light overflow-auto" style="max-height: 200px; font-size: 0.85rem;">
                        <div class="text-center text-muted py-3 empty-feed">กำลังเริ่มตรวจสอบบทความ...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3 bg-light d-flex justify-content-between">
                <div class="extra-small text-muted">
                    <i class="fa-solid fa-shield-halved text-success me-1"></i> อัปเดตตาราง `cv_research` อัตโนมัติแบบเรียลไทม์
                </div>
                <div>
                    <button type="button" id="btnBatchStop" class="btn btn-outline-danger rounded-pill px-4 fw-bold me-2" onclick="stopBatchCheck()">
                        <i class="fa-solid fa-stop me-1"></i> หยุดชั่วคราว
                    </button>
                    <button type="button" id="btnBatchDone" class="btn btn-secondary rounded-pill px-4 fw-bold" onclick="finishBatchModal()" disabled>
                        ปิด
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Checkbox selection in Individual papers tab
function toggleSelectAllPapers(headerCheckbox) {
    const checkboxes = document.querySelectorAll('.paper-chk');
    checkboxes.forEach(cb => cb.checked = headerCheckbox.checked);
    updatePaperSelectionCount();
}

function updatePaperSelectionCount() {
    const checked = document.querySelectorAll('.paper-chk:checked').length;
    document.getElementById('selectedPaperCount').innerText = `เลือก ${checked} รายการ`;
}

// อัปเดตฐานข้อมูลรายบทความแบบทันทีผ่าน AJAX
function saveSinglePaperDb(resId, dbSource) {
    const badge = document.getElementById(`badge_${resId}`);
    if (badge) {
        badge.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> บันทึก...`;
    }

    const formData = new FormData();
    formData.append('action', 'update_single_paper');
    formData.append('res_id', resId);
    formData.append('db_source', dbSource);

    fetch('map_source.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(() => {
        if (badge) {
            if (!dbSource || dbSource === 'Other') {
                badge.className = 'badge bg-secondary bg-opacity-75 rounded-pill px-2 py-1 extra-small';
                badge.innerText = 'ยังไม่ระบุ';
            } else if (dbSource === 'Scopus') {
                badge.className = 'badge bg-warning text-dark rounded-pill px-2 py-1 extra-small';
                badge.innerText = 'Scopus';
            } else if (dbSource === 'TCI') {
                badge.className = 'badge bg-danger rounded-pill px-2 py-1 extra-small';
                badge.innerText = 'TCI';
            } else if (dbSource === 'ERIC') {
                badge.className = 'badge bg-primary rounded-pill px-2 py-1 extra-small';
                badge.innerText = 'ERIC';
            } else {
                badge.className = 'badge bg-success rounded-pill px-2 py-1 extra-small';
                badge.innerText = dbSource;
            }
        }
    })
    .catch(err => {
        alert('เกิดข้อผิดพลาดในการบันทึก: ' + err);
    });
}

// ตรวจสอบบทความกับ Scopus API (รายข้อ)
function checkScopusSingle(resId, title, doi, btn) {
    const originalHtml = btn.innerHTML;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i>`;
    btn.disabled = true;

    fetch(`map_source.php?ajax_action=check_scopus&res_id=${resId}&title=${encodeURIComponent(title)}&doi=${encodeURIComponent(doi)}`)
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;

        if (data.success && data.found) {
            alert(`🎉 พบใน Scopus!\nชื่องานวิจัย: ${data.scopus_title}\nDOI: ${data.scopus_doi || '-'}\nCitations: ${data.scopus_cites}\n\nระบบได้อัปเดตเป็นฐานข้อมูล Scopus เรียบร้อยแล้ว`);
            location.reload();
        } else if (data.success && !data.found) {
            alert('ไม่พบข้อมูลบทความนี้ใน Scopus (อาจเป็น TCI, Proceeding, หรือวิทยานิพนธ์)');
        } else {
            alert('ข้อผิดพลาด: ' + (data.message || 'ไม่สามารถติดต่อ Scopus ได้'));
        }
    })
    .catch(err => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ Scopus: ' + err);
    });
}

// เปิดดูบทความใน Modal
function openPapersModal(publisherRaw, publisherName) {
    document.getElementById('modalPublisherName').innerText = publisherName;
    const modalBody = document.getElementById('modalPapersBody');
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-purple" role="status"></div>
            <div class="small text-muted mt-2">กำลังโหลดบทความในกลุ่มนี้...</div>
        </div>
    `;

    const myModal = new bootstrap.Modal(document.getElementById('papersModal'));
    myModal.show();

    fetch(`map_source.php?ajax_action=get_papers_by_publisher&publisher=${encodeURIComponent(publisherRaw)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.papers.length > 0) {
            let html = '<div class="list-group list-group-flush">';
            data.papers.forEach((p, idx) => {
                const author = p.authors || ((p.prefix_name || '') + p.first_name + ' ' + p.last_name);
                html += `
                    <div class="list-group-item px-0 py-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <span class="badge bg-purple bg-opacity-10 text-purple extra-small me-1">#${idx + 1}</span>
                                <strong class="text-dark" style="font-size: 0.93rem;">${p.title}</strong>
                                <div class="extra-small text-muted mt-1">
                                    <i class="fa-solid fa-user me-1 text-purple"></i> ${author || 'ไม่ระบุผู้แต่ง'}
                                    <span class="mx-1">•</span> ปี ค.ศ. ${p.publish_year || '-'}
                                    ${p.doi ? `<span class="mx-1">•</span> DOI: ${p.doi}` : ''}
                                </div>
                            </div>
                            <div class="text-end text-nowrap">
                                <span class="badge ${p.db_source ? 'bg-success' : 'bg-secondary'} rounded-pill extra-small">
                                    ${p.db_source || 'ยังไม่ระบุ'}
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            modalBody.innerHTML = html;
        } else {
            modalBody.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-folder-open fs-1 mb-2 opacity-50"></i>
                    <div>ไม่พบบทความในกลุ่มนี้</div>
                </div>
            `;
        }
    })
    .catch(err => {
        modalBody.innerHTML = `<div class="alert alert-danger">โหลดข้อมูลไม่สำเร็จ: ${err}</div>`;
    });
}

// ==========================================
// Batch Scopus Verification Logic
// ==========================================
let isBatchRunning = false;
let shouldStopBatch = false;
let batchFoundTotal = 0;

function startBatchScopusCheck() {
    const checkedBoxes = document.querySelectorAll('.paper-chk:checked');
    let items = [];

    if (checkedBoxes.length > 0) {
        checkedBoxes.forEach(chk => {
            const tr = chk.closest('tr');
            if (tr) {
                items.push({
                    id: tr.getAttribute('data-id'),
                    title: tr.getAttribute('data-title'),
                    doi: tr.getAttribute('data-doi'),
                    db_source: tr.getAttribute('data-dbsource'),
                    tr: tr
                });
            }
        });
    } else {
        const allRows = document.querySelectorAll('.paper-row-item');
        allRows.forEach(tr => {
            const dbs = tr.getAttribute('data-dbsource');
            if (dbs !== 'Scopus') {
                items.push({
                    id: tr.getAttribute('data-id'),
                    title: tr.getAttribute('data-title'),
                    doi: tr.getAttribute('data-doi'),
                    db_source: dbs,
                    tr: tr
                });
            }
        });
    }

    if (items.length === 0) {
        alert('ไม่พบบทความที่ต้องตรวจสอบ (บทความทั้งหมดในตารางเป็น Scopus แล้ว หรือไม่มีรายการ)');
        return;
    }

    const confirmMsg = `ต้องการเริ่มตรวจสอบบทความกับ Scopus API ทั้งหมด ${items.length} รายการ ใน 1 คลิก หรือไม่?\n\n(ระบบจะค้นหาชื่อเรื่องและ DOI จาก Elsevier Scopus และอัปเดตฐานข้อมูลให้อัตโนมัติ)`;
    if (!confirm(confirmMsg)) {
        return;
    }

    // Reset UI
    batchFoundTotal = 0;
    document.getElementById('batchStatusText').innerText = `กำลังตรวจสอบบทความกับ Scopus API (0 / ${items.length})...`;
    document.getElementById('batchProgressPercent').innerText = '0%';
    document.getElementById('batchProgressBar').style.width = '0%';
    document.getElementById('batchProgressBar').className = 'progress-bar progress-bar-striped progress-bar-animated bg-warning text-dark fw-bold';
    document.getElementById('batchCurrentItemText').innerText = 'กำลังเตรียมส่งคำค้นหา...';
    document.getElementById('counterChecked').innerText = `0 / ${items.length}`;
    document.getElementById('counterFound').innerText = '0';
    document.getElementById('counterNotFound').innerText = '0';
    document.getElementById('batchFoundList').innerHTML = '<div class="text-muted text-center py-2 empty-feed">กำลังเริ่มส่งคำค้นหา...</div>';
    
    document.getElementById('btnBatchStop').disabled = false;
    document.getElementById('btnBatchStop').innerHTML = '<i class="fa-solid fa-stop me-1"></i> หยุดชั่วคราว';
    document.getElementById('btnBatchDone').disabled = true;
    document.getElementById('btnBatchDone').className = 'btn btn-secondary rounded-pill px-4 fw-bold';
    document.getElementById('btnBatchDone').innerText = 'ปิด';

    const modalEl = document.getElementById('scopusBatchModal');
    const batchModal = new bootstrap.Modal(modalEl);
    batchModal.show();

    runBatchProcess(items);
}

async function runBatchProcess(items) {
    isBatchRunning = true;
    shouldStopBatch = false;

    let total = items.length;
    let checked = 0;
    let found = 0;
    let notFound = 0;
    const foundList = document.getElementById('batchFoundList');

    for (let i = 0; i < total; i++) {
        if (shouldStopBatch) {
            document.getElementById('batchStatusText').innerText = `หยุดการตรวจสอบแล้ว (ตรวจไปแล้ว ${checked} / ${total} รายการ)`;
            document.getElementById('btnBatchDone').disabled = false;
            document.getElementById('btnBatchDone').className = 'btn btn-success rounded-pill px-4 fw-bold';
            document.getElementById('btnBatchDone').innerText = 'เสร็จสิ้น / ปิดหน้าต่าง';
            document.getElementById('btnBatchStop').disabled = true;
            isBatchRunning = false;
            return;
        }

        const item = items[i];
        checked++;

        // Update progress
        const percent = Math.round((checked / total) * 100);
        document.getElementById('batchProgressBar').style.width = `${percent}%`;
        document.getElementById('batchProgressPercent').innerText = `${percent}%`;
        document.getElementById('batchStatusText').innerText = `กำลังตรวจสอบ (${checked} / ${total})...`;
        document.getElementById('batchCurrentItemText').innerText = `กำลังตรวจสอบ: ${item.title}`;
        document.getElementById('counterChecked').innerText = `${checked} / ${total}`;

        try {
            const url = `map_source.php?ajax_action=check_scopus&res_id=${item.id}&title=${encodeURIComponent(item.title)}&doi=${encodeURIComponent(item.doi || '')}`;
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.success && data.found) {
                found++;
                batchFoundTotal++;
                document.getElementById('counterFound').innerText = found;

                // Update row in table
                if (item.tr) {
                    item.tr.setAttribute('data-dbsource', 'Scopus');
                    item.tr.classList.remove('bg-light', 'bg-opacity-25');
                    item.tr.classList.add('table-warning');
                    
                    const badge = item.tr.querySelector(`#badge_${item.id}`);
                    if (badge) {
                        badge.className = 'badge bg-warning text-dark rounded-pill px-2 py-1 extra-small';
                        badge.innerText = 'Scopus';
                    }
                    const select = item.tr.querySelector('select');
                    if (select) {
                        select.value = 'Scopus';
                    }
                }

                // Append to found list in modal
                const emptyFeed = foundList.querySelector('.empty-feed');
                if (emptyFeed) emptyFeed.remove();

                const div = document.createElement('div');
                div.className = 'mb-2 pb-2 border-bottom';
                div.innerHTML = `
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <span class="badge bg-warning text-dark me-1">Scopus</span>
                            <strong class="text-dark">${data.scopus_title || item.title}</strong>
                            <div class="extra-small text-muted mt-1">
                                ${data.scopus_doi ? `<span class="badge bg-light text-muted border me-1">DOI: ${data.scopus_doi}</span>` : ''}
                                ${data.scopus_cites ? `<span class="badge bg-light text-dark border">Citations: ${data.scopus_cites}</span>` : ''}
                            </div>
                        </div>
                        <span class="text-success small fw-bold text-nowrap"><i class="fa-solid fa-circle-check"></i> บันทึกแล้ว</span>
                    </div>
                `;
                foundList.prepend(div);
            } else {
                notFound++;
                document.getElementById('counterNotFound').innerText = notFound;
            }
        } catch (e) {
            console.error('Error checking item:', item.title, e);
            notFound++;
            document.getElementById('counterNotFound').innerText = notFound;
        }

        // Small delay to prevent API flooding and keep UI smooth
        await new Promise(resolve => setTimeout(resolve, 150));
    }

    isBatchRunning = false;
    document.getElementById('batchProgressBar').className = 'progress-bar bg-success fw-bold';
    document.getElementById('batchStatusText').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i> ตรวจสอบเสร็จสมบูรณ์ทั้ง ${total} รายการ!</span>`;
    document.getElementById('batchCurrentItemText').innerText = `พบใน Scopus ทั้งหมด ${found} รายการ และได้บันทึกเข้าสู่ระบบเรียบร้อยแล้ว`;
    document.getElementById('btnBatchDone').disabled = false;
    document.getElementById('btnBatchDone').className = 'btn btn-success rounded-pill px-4 fw-bold';
    document.getElementById('btnBatchDone').innerText = 'เสร็จสิ้น / โหลดข้อมูลใหม่';
    document.getElementById('btnBatchStop').disabled = true;
}

function stopBatchCheck() {
    shouldStopBatch = true;
    document.getElementById('btnBatchStop').innerText = 'กำลังหยุด...';
}

function finishBatchModal() {
    if (batchFoundTotal > 0) {
        location.reload();
    } else {
        const modalEl = document.getElementById('scopusBatchModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }
}
</script>

<style>
:root {
    --msu-purple: #39048f;
    --msu-gold: #b0914f;
}
.text-purple { color: var(--msu-purple) !important; }
.btn-purple { background-color: var(--msu-purple); color: #fff; border: none; }
.btn-purple:hover { background-color: #270045; color: #fff; }
.btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
.btn-outline-purple:hover { background-color: var(--msu-purple); color: #fff; }
.bg-purple { background-color: var(--msu-purple) !important; }
.extra-small { font-size: 0.75rem; }
.cursor-pointer { cursor: pointer; }

/* Custom Tab styling */
.nav-pills .nav-link {
    color: #495057;
    background-color: #e9ecef;
}
.nav-pills .nav-link.active {
    background-color: var(--msu-purple);
    color: #fff;
}
</style>

<?php require_once '../../includes/footer.php'; ?>
