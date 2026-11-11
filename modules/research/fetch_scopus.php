<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$default_api_key = 'f42f287419e8ca38348e7d4cb002df6a';
$api_key = $_POST['api_key'] ?? $_GET['api_key'] ?? $default_api_key;
$current_user_id = $_SESSION['user_id'] ?? '';

// ดึงรายชื่ออาจารย์และบุคลากรทั้งหมดสำหรับใช้จับคู่และเลือก Dropdown
$users_stmt = $pdo->query("SELECT user_id, prefix_name, first_name, last_name, first_name_en, last_name_en, username, dept_id FROM users ORDER BY first_name ASC");
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$import_msg = null;
$import_type = 'success';

// ==============================================================
// 1. จัดการการบันทึกข้อมูลผลงาน (POST Action: import_scopus)
// ==============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_scopus') {
    $selected_papers = $_POST['papers'] ?? [];
    $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] == '1';

    $imported_count = 0;
    $updated_count = 0;
    $skipped_count = 0;

    if (!empty($selected_papers) && is_array($selected_papers)) {
        try {
            $pdo->beginTransaction();

            $insert_stmt = $pdo->prepare("
                INSERT INTO cv_research (user_id, title, authors, publisher, citations, publish_year, url_link, db_source, doi, created_at)
                VALUES (:user_id, :title, :authors, :publisher, :citations, :publish_year, :url_link, 'Scopus', :doi, NOW())
            ");

            $update_stmt = $pdo->prepare("
                UPDATE cv_research 
                SET citations = :citations, 
                    url_link = :url_link, 
                    doi = :doi, 
                    db_source = 'Scopus',
                    authors = CASE WHEN authors IS NULL OR authors = '' THEN :authors ELSE authors END
                WHERE res_id = :res_id
            ");

            foreach ($selected_papers as $p) {
                if (!isset($p['selected']) || $p['selected'] != '1') {
                    continue;
                }

                $p_title = trim($p['title'] ?? '');
                $p_doi = trim($p['doi'] ?? '');
                $p_user_id = trim($p['user_id'] ?? $current_user_id);
                $p_authors = trim($p['authors'] ?? '');
                $p_publisher = trim($p['publisher'] ?? '');
                $p_year = trim($p['publish_year'] ?? '');
                $p_citations = intval($p['citations'] ?? 0);
                $p_url = trim($p['url_link'] ?? '');

                if (empty($p_title)) {
                    continue;
                }

                // ตรวจสอบความซ้ำซ้อนใน cv_research (โดยดู DOI หรือ Title)
                $existing_id = null;
                if (!empty($p_doi)) {
                    $chk = $pdo->prepare("SELECT res_id FROM cv_research WHERE doi = ? LIMIT 1");
                    $chk->execute([$p_doi]);
                    $existing_id = $chk->fetchColumn();
                }
                if (!$existing_id && !empty($p_title)) {
                    $chk = $pdo->prepare("SELECT res_id FROM cv_research WHERE title = ? LIMIT 1");
                    $chk->execute([$p_title]);
                    $existing_id = $chk->fetchColumn();
                }

                if ($existing_id) {
                    if ($update_existing) {
                        $update_stmt->execute([
                            ':citations' => $p_citations,
                            ':url_link' => $p_url,
                            ':doi' => $p_doi,
                            ':authors' => $p_authors,
                            ':res_id' => $existing_id
                        ]);
                        $updated_count++;
                    } else {
                        $skipped_count++;
                    }
                } else {
                    $insert_stmt->execute([
                        ':user_id' => $p_user_id,
                        ':title' => $p_title,
                        ':authors' => $p_authors,
                        ':publisher' => $p_publisher ?: 'Scopus Publication',
                        ':citations' => $p_citations,
                        ':publish_year' => $p_year ?: null,
                        ':url_link' => $p_url ?: null,
                        ':doi' => $p_doi ?: null
                    ]);
                    $imported_count++;
                }
            }

            $pdo->commit();

            $msg_parts = [];
            if ($imported_count > 0) $msg_parts[] = "นำเข้าผลงานใหม่สำเร็จ $imported_count รายการ";
            if ($updated_count > 0) $msg_parts[] = "อัปเดตข้อมูลที่มีอยู่เดิม $updated_count รายการ";
            if ($skipped_count > 0) $msg_parts[] = "ข้ามรายการซ้ำ $skipped_count รายการ";
            
            $import_msg = !empty($msg_parts) ? implode(', ', $msg_parts) : "ไม่มีรายการที่ถูกบันทึก";
            $import_type = 'success';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $import_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
            $import_type = 'danger';
        }
    } else {
        $import_msg = "กรุณาเลือกผลงานวิจัยที่ต้องการนำเข้าอย่างน้อย 1 รายการ";
        $import_type = 'warning';
    }
}

// ==============================================================
// 2. การค้นหาข้อมูลจาก Scopus API
// ==============================================================
$search_mode = $_GET['mode'] ?? 'affil_music';
$author_query = $_GET['author_query'] ?? '';
$custom_query = $_GET['custom_query'] ?? '';
$count_per_page = intval($_GET['count'] ?? 25);
$start_offset = intval($_GET['start'] ?? 0);

if ($count_per_page <= 0 || $count_per_page > 25) {
    $count_per_page = 25; // view=COMPLETE รองรับสูงสุด 25 รายการต่อ request
}

// สร้าง Scopus Search Query
$scopus_query = '';
switch ($search_mode) {
    case 'author':
        if (!empty($author_query)) {
            $scopus_query = 'AUTH("' . addslashes(trim($author_query)) . '")';
        } else {
            $scopus_query = 'AFFIL("College of Music") AND AFFIL("Mahasarakham University")';
        }
        break;

    case 'topic_music':
        $scopus_query = 'AFFIL("Mahasarakham University") AND TITLE-ABS-KEY("Music")';
        break;

    case 'custom':
        $scopus_query = trim($custom_query);
        break;

    case 'affil_music':
    default:
        $search_mode = 'affil_music';
        $scopus_query = 'AFFIL("College of Music") AND AFFIL("Mahasarakham University")';
        break;
}

$results = [];
$total_scopus_results = 0;
$api_error = null;
$api_http_code = null;

if (!empty($scopus_query) && !empty($api_key)) {
    $api_url = 'https://api.elsevier.com/content/search/scopus?' . http_build_query([
        'query' => $scopus_query,
        'count' => $count_per_page,
        'start' => $start_offset,
        'view' => 'COMPLETE'
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-ELS-APIKey: ' . trim($api_key),
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $raw_response = curl_exec($ch);
    $api_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        $api_error = "cURL Error: " . $curl_err;
    } elseif ($api_http_code !== 200) {
        $resp_json = json_decode($raw_response, true);
        $err_msg = $resp_json['service-error']['status']['statusText'] 
                   ?? $resp_json['error-response']['error-message'] 
                   ?? ("HTTP Error " . $api_http_code);
        $api_error = "Elsevier Scopus API แจ้งเตือน: " . $err_msg . " (Code: $api_http_code)";
    } else {
        $data = json_decode($raw_response, true);
        if (isset($data['search-results'])) {
            $total_scopus_results = intval($data['search-results']['opensearch:totalResults'] ?? 0);
            $entries = $data['search-results']['entry'] ?? [];

            // ดึงผลงานและ DOI ที่มีอยู่แล้วในระบบมาเช็คความซ้ำซ้อน
            $db_dois = $pdo->query("SELECT LOWER(TRIM(doi)) FROM cv_research WHERE doi IS NOT NULL AND doi != ''")->fetchAll(PDO::FETCH_COLUMN);
            $db_titles = $pdo->query("SELECT LOWER(TRIM(title)) FROM cv_research WHERE title IS NOT NULL AND title != ''")->fetchAll(PDO::FETCH_COLUMN);

            $doi_map = array_flip(array_map('trim', $db_dois));
            $title_map = array_flip(array_map('trim', $db_titles));

            foreach ($entries as $e) {
                $e_title = trim($e['dc:title'] ?? '');
                $e_doi = trim($e['prism:doi'] ?? '');
                $e_pub = trim($e['prism:publicationName'] ?? '');
                $e_date = trim($e['prism:coverDate'] ?? '');
                $e_year = !empty($e_date) ? substr($e_date, 0, 4) : '';
                $e_cites = intval($e['citedby-count'] ?? 0);
                
                // หา Scopus Link
                $e_url = '';
                if (!empty($e['link']) && is_array($e['link'])) {
                    foreach ($e['link'] as $lnk) {
                        if (isset($lnk['@ref']) && $lnk['@ref'] === 'scopus') {
                            $e_url = $lnk['@href'] ?? '';
                            break;
                        }
                    }
                }
                if (empty($e_url) && !empty($e_doi)) {
                    $e_url = "https://doi.org/" . $e_doi;
                }

                // รวมรายชื่อ Authors จาก Scopus view=COMPLETE
                $authors_arr = [];
                $author_names_for_match = [];

                if (!empty($e['author']) && is_array($e['author'])) {
                    foreach ($e['author'] as $au) {
                        $authname = trim($au['authname'] ?? '');
                        $surname = trim($au['surname'] ?? '');
                        $given = trim($au['given-name'] ?? '');
                        
                        if (!empty($authname)) {
                            $authors_arr[] = $authname;
                        } elseif (!empty($surname)) {
                            $authors_arr[] = $surname . ($given ? ' ' . $given : '');
                        }

                        if (!empty($surname)) $author_names_for_match[] = strtolower($surname);
                        if (!empty($given)) $author_names_for_match[] = strtolower($given);
                    }
                }

                if (empty($authors_arr)) {
                    $creator = trim($e['dc:creator'] ?? '');
                    if (!empty($creator)) {
                        $authors_arr[] = $creator;
                        $author_names_for_match[] = strtolower($creator);
                    }
                }

                $formatted_authors = implode(', ', $authors_arr);

                // ตรวจสอบว่าตรงกับอาจารย์ท่านใดในตาราง users
                $matched_user_id = null;
                $matched_user_name = '';

                foreach ($all_users as $u) {
                    $u_last_en = strtolower(trim($u['last_name_en'] ?? ''));
                    $u_first_en = strtolower(trim($u['first_name_en'] ?? ''));
                    $u_uname = strtolower(trim($u['username'] ?? ''));

                    $match_found = false;
                    if (!empty($u_last_en)) {
                        foreach ($author_names_for_match as $an) {
                            if (strpos($an, $u_last_en) !== false || strpos($u_last_en, $an) !== false) {
                                $match_found = true;
                                break;
                            }
                        }
                    }

                    if (!$match_found && !empty($u_first_en) && strlen($u_first_en) > 3) {
                        foreach ($author_names_for_match as $an) {
                            if (strpos($an, $u_first_en) !== false || strpos($u_first_en, $an) !== false) {
                                $match_found = true;
                                break;
                            }
                        }
                    }

                    if ($match_found) {
                        $matched_user_id = $u['user_id'];
                        $matched_user_name = ($u['prefix_name'] ?? '') . $u['first_name'] . ' ' . $u['last_name'];
                        break;
                    }
                }

                // ตรวจสอบว่ามีอยู่ในฐานข้อมูลแล้วหรือไม่
                $is_existing = false;
                if (!empty($e_doi) && isset($doi_map[strtolower($e_doi)])) {
                    $is_existing = true;
                } elseif (!empty($e_title) && isset($title_map[strtolower($e_title)])) {
                    $is_existing = true;
                }

                $results[] = [
                    'title' => $e_title,
                    'authors' => $formatted_authors,
                    'publisher' => $e_pub,
                    'publish_year' => $e_year,
                    'citations' => $e_cites,
                    'doi' => $e_doi,
                    'url_link' => $e_url,
                    'matched_user_id' => $matched_user_id,
                    'matched_user_name' => $matched_user_name,
                    'is_existing' => $is_existing
                ];
            }
        }
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fa; min-height: 100vh;">

    <!-- Top Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-cloud-arrow-down text-warning me-2"></i>Scopus Research Data Integration
            </h1>
            <p class="text-muted small mb-0">ดึงข้อมูลและซิงค์ผลงานวิชาการจากฐานข้อมูลระดับนานาชาติ <strong>Scopus (Elsevier)</strong> ผ่าน API</p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการผลงาน
            </a>
            <a href="map_source.php" class="btn btn-outline-purple rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-layer-group me-1"></i> จัดการฐานข้อมูล (Map Source)
            </a>
        </div>
    </div>

    <?php if ($import_msg): ?>
        <div class="alert alert-<?php echo $import_type; ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid <?php echo ($import_type === 'success') ? 'fa-circle-check fs-4 me-3 text-success' : 'fa-circle-exclamation fs-4 me-3 text-danger'; ?>"></i>
                <div>
                    <h6 class="fw-bold mb-0"><?php echo ($import_type === 'success') ? 'การนำเข้าข้อมูลเสร็จสมบูรณ์' : 'ข้อความแจ้งเตือน'; ?></h6>
                    <div class="small"><?php echo htmlspecialchars($import_msg); ?></div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- API Status & Search Form Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">
                        <i class="fa-solid fa-key me-1"></i> Elsevier Scopus API
                    </span>
                    <span class="badge <?php echo ($api_http_code === 200) ? 'bg-success' : 'bg-secondary'; ?> px-3 py-2 rounded-pill">
                        <i class="fa-solid fa-circle-dot me-1"></i> <?php echo ($api_http_code === 200) ? 'เชื่อมต่อ API สำเร็จ (HTTP 200)' : 'พร้อมใช้งาน (Key Active)'; ?>
                    </span>
                </div>
                <div class="extra-small text-muted font-monospace">
                    Key: <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(substr($api_key, 0, 8) . '...' . substr($api_key, -6)); ?></span>
                </div>
            </div>
        </div>

        <div class="card-body p-4">
            <form method="GET" action="fetch_scopus.php" id="scopusSearchForm">
                <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($api_key); ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="fa-solid fa-filter me-1 text-purple"></i>รูปแบบการค้นหา (Search Mode)
                        </label>
                        <select name="mode" id="modeSelect" class="form-select rounded-3 fw-bold bg-light border-0 shadow-sm" onchange="handleModeChange()">
                            <option value="affil_music" <?php echo ($search_mode === 'affil_music') ? 'selected' : ''; ?>>
                                🏛️ วิทยาลัยดุริยางคศิลป์ มมส (College of Music MSU)
                            </option>
                            <option value="author" <?php echo ($search_mode === 'author') ? 'selected' : ''; ?>>
                                👤 ค้นหาตามรายชื่ออาจารย์ (Faculty Author)
                            </option>
                            <option value="topic_music" <?php echo ($search_mode === 'topic_music') ? 'selected' : ''; ?>>
                                🎵 งานวิจัยหัวข้อดนตรี มมส ทั้งหมด (MSU Music Papers)
                            </option>
                            <option value="custom" <?php echo ($search_mode === 'custom') ? 'selected' : ''; ?>>
                                🔍 กำหนดคำค้นหาเอง (Custom Scopus Query / DOI)
                            </option>
                        </select>
                    </div>

                    <!-- Input สำหรับ Author Search -->
                    <div class="col-md-5" id="authorGroup" style="<?php echo ($search_mode === 'author') ? '' : 'display: none;'; ?>">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="fa-solid fa-user-graduate me-1 text-purple"></i>เลือกอาจารย์ / ระบุนามสกุลภาษาอังกฤษ
                        </label>
                        <div class="input-group">
                            <select id="facultyQuickSelect" class="form-select bg-light border-0 shadow-sm" onchange="selectFacultyName(this)">
                                <option value="">-- เลือกอาจารย์ในคณะเพื่อระบุชื่อ --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <?php 
                                        $en_name = trim(($u['last_name_en'] ?? '') ? $u['last_name_en'] : '');
                                        if (empty($en_name) && !empty($u['username'])) {
                                            $parts = explode('.', $u['username']);
                                            $en_name = ucfirst($parts[0]);
                                        }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($en_name); ?>" data-uid="<?php echo $u['user_id']; ?>">
                                        <?php echo htmlspecialchars(($u['prefix_name'] ?? '') . $u['first_name'] . ' ' . $u['last_name'] . ' (' . ($u['last_name_en'] ?: $u['username']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="author_query" id="authorQueryInput" class="form-control" placeholder="นามสกุลภาษาอังกฤษ เช่น Choatchamrat" value="<?php echo htmlspecialchars($author_query); ?>">
                        </div>
                    </div>

                    <!-- Input สำหรับ Custom Search -->
                    <div class="col-md-5" id="customGroup" style="<?php echo ($search_mode === 'custom') ? '' : 'display: none;'; ?>">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="fa-solid fa-terminal me-1 text-purple"></i>Scopus Query String / DOI
                        </label>
                        <input type="text" name="custom_query" class="form-control rounded-3" placeholder="เช่น AFFIL(Mahasarakham) AND TITLE(Music) หรือ DOI(10.xxx/xxx)" value="<?php echo htmlspecialchars($custom_query); ?>">
                    </div>

                    <!-- จำนวนรายการต่อครั้ง -->
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="fa-solid fa-list-ol me-1 text-purple"></i>จำนวนต่อหน้า (Count Limit)
                        </label>
                        <div class="d-flex gap-2">
                            <select name="count" class="form-select rounded-3 bg-light border-0 shadow-sm fw-bold">
                                <option value="10" <?php echo ($count_per_page == 10) ? 'selected' : ''; ?>>10 รายการ</option>
                                <option value="25" <?php echo ($count_per_page == 25) ? 'selected' : ''; ?>>25 รายการ (แนะนำ)</option>
                            </select>
                            <button type="submit" id="btnSearch" class="btn btn-purple rounded-3 px-4 fw-bold shadow-sm text-nowrap">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหา
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Query Preview & API key toggle -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top">
                    <div class="extra-small text-muted">
                        <strong class="text-secondary">Scopus Query ที่ส่งไปยัง API:</strong>
                        <code class="bg-light px-2 py-1 rounded text-purple border"><?php echo htmlspecialchars($scopus_query); ?></code>
                    </div>
                    <div>
                        <a class="extra-small text-decoration-none text-muted" data-bs-toggle="collapse" href="#collapseApiKey" role="button">
                            <i class="fa-solid fa-gear me-1"></i> ตั้งค่า API Key (ขั้นสูง)
                        </a>
                    </div>
                </div>

                <div class="collapse mt-3" id="collapseApiKey">
                    <div class="card card-body bg-light border-0 rounded-3 p-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-3">
                                <span class="small fw-bold">Elsevier API Key:</span>
                            </div>
                            <div class="col-md-7">
                                <input type="text" name="api_key" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($api_key); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-outline-secondary w-100 fw-bold">บันทึก & ใช้ Key นี้</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- API Error Notice -->
    <?php if ($api_error): ?>
        <div class="alert alert-danger rounded-4 shadow-sm border-0 mb-4 p-4">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-triangle-exclamation fs-2 me-3"></i>
                <div>
                    <h5 class="fw-bold mb-1">ไม่สามารถดึงข้อมูลจาก Scopus API ได้</h5>
                    <div><?php echo htmlspecialchars($api_error); ?></div>
                    <div class="extra-small mt-2 opacity-75">คำแนะนำ: ตรวจสอบการสะกด Query หรือโควต้าของ API Key</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Results Section -->
    <?php if (!empty($results)): ?>
        <?php
            $new_count = 0;
            $exist_count = 0;
            foreach ($results as $res_item) {
                if ($res_item['is_existing']) $exist_count++;
                else $new_count++;
            }
        ?>

        <form method="POST" action="fetch_scopus.php" id="importForm">
            <input type="hidden" name="action" value="import_scopus">
            <input type="hidden" name="api_key" value="<?php echo htmlspecialchars($api_key); ?>">

            <div class="card border-0 shadow-sm rounded-4 bg-white mb-5 overflow-hidden">
                <!-- Results Header & Action Bar -->
                <div class="card-header bg-white border-bottom p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h5 class="fw-bold text-dark mb-1">
                                <i class="fa-solid fa-database text-purple me-2"></i>ผลการค้นหาจาก Scopus
                            </h5>
                            <div class="small text-muted">
                                พบทั้งหมด <strong><?php echo number_format($total_scopus_results); ?></strong> รายการในฐานข้อมูล Scopus 
                                (แสดง <strong><?php echo count($results); ?></strong> รายการในหน้านี้: 
                                <span class="badge bg-success bg-opacity-10 text-success fw-bold px-2 py-1 rounded-pill">ผลงานใหม่ <?php echo $new_count; ?> รายการ</span>, 
                                <span class="badge bg-secondary bg-opacity-10 text-secondary fw-bold px-2 py-1 rounded-pill">มีในระบบแล้ว <?php echo $exist_count; ?> รายการ</span>)
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <div class="form-check form-switch me-2">
                                <input class="form-check-input" type="checkbox" name="update_existing" value="1" id="chkUpdateExisting" checked>
                                <label class="form-check-label small fw-bold text-muted cursor-pointer" for="chkUpdateExisting">
                                    อัปเดต Citations รายการเดิมด้วย
                                </label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold" onclick="selectNewOnly()">
                                <i class="fa-solid fa-filter me-1"></i> เลือกเฉพาะรายการใหม่
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold" onclick="toggleSelectAll()">
                                <i class="fa-solid fa-check-double me-1"></i> เลือกทั้งหมด
                            </button>
                            <button type="submit" id="btnImport" class="btn btn-sm btn-success rounded-pill px-4 fw-bold shadow-sm">
                                <i class="fa-solid fa-file-import me-1"></i> นำเข้าสู่ฐานข้อมูล (<span id="selectedCount">0</span>)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted extra-small text-uppercase">
                            <tr>
                                <th width="4%" class="text-center">
                                    <input type="checkbox" id="headerCheckbox" class="form-check-input fs-5" onchange="toggleAllCheckboxes(this)">
                                </th>
                                <th width="8%" class="text-center">สถานะ</th>
                                <th width="35%">ชื่องานวิจัย / DOI / ลิงก์</th>
                                <th width="20%">ผู้แต่งทั้งหมด (Scopus Authors)</th>
                                <th width="15%">แหล่งตีพิมพ์ / ปี</th>
                                <th width="6%" class="text-center">Citations</th>
                                <th width="12%">ผูกกับอาจารย์ในคณะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $idx => $item): ?>
                                <tr class="<?php echo $item['is_existing'] ? 'bg-light bg-opacity-50' : ''; ?>">
                                    <td class="text-center">
                                        <input type="checkbox" 
                                               class="form-check-input item-checkbox fs-5" 
                                               name="papers[<?php echo $idx; ?>][selected]" 
                                               value="1" 
                                               id="paper_chk_<?php echo $idx; ?>"
                                               data-existing="<?php echo $item['is_existing'] ? '1' : '0'; ?>"
                                               <?php echo (!$item['is_existing']) ? 'checked' : ''; ?>
                                               onchange="updateSelectedCount()">

                                        <input type="hidden" name="papers[<?php echo $idx; ?>][title]" value="<?php echo htmlspecialchars($item['title']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][authors]" value="<?php echo htmlspecialchars($item['authors']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][publisher]" value="<?php echo htmlspecialchars($item['publisher']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][publish_year]" value="<?php echo htmlspecialchars($item['publish_year']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][citations]" value="<?php echo htmlspecialchars($item['citations']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][doi]" value="<?php echo htmlspecialchars($item['doi']); ?>">
                                        <input type="hidden" name="papers[<?php echo $idx; ?>][url_link]" value="<?php echo htmlspecialchars($item['url_link']); ?>">
                                    </td>

                                    <td class="text-center">
                                        <?php if ($item['is_existing']): ?>
                                            <span class="badge bg-secondary bg-opacity-75 rounded-pill px-2 py-1 extra-small">
                                                <i class="fa-solid fa-check-double me-1"></i> มีในระบบแล้ว
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success rounded-pill px-2 py-1 extra-small">
                                                <i class="fa-solid fa-sparkles me-1"></i> ผลงานใหม่
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="fw-bold text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <?php if (!empty($item['doi'])): ?>
                                                <span class="badge bg-light text-muted border extra-small">
                                                    DOI: <?php echo htmlspecialchars($item['doi']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['url_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($item['url_link']); ?>" target="_blank" class="extra-small text-purple text-decoration-none fw-bold">
                                                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดดูบน Scopus
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="small text-secondary fw-semibold">
                                            <?php echo htmlspecialchars($item['authors'] ?: '-'); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="small fw-bold text-dark"><?php echo htmlspecialchars($item['publisher'] ?: '-'); ?></div>
                                        <div class="extra-small text-muted">
                                            ปี ค.ศ. <span class="badge bg-purple bg-opacity-10 text-purple fw-bold"><?php echo htmlspecialchars($item['publish_year'] ?: '-'); ?></span>
                                        </div>
                                    </td>

                                    <td class="text-center">
                                        <span class="badge bg-warning bg-opacity-25 text-dark fw-bold px-2 py-1">
                                            <i class="fa-solid fa-quote-right me-1 text-warning"></i><?php echo number_format($item['citations']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <select name="papers[<?php echo $idx; ?>][user_id]" class="form-select form-select-sm border-0 bg-light fw-bold" style="font-size: 0.8rem;">
                                            <?php foreach ($all_users as $u): ?>
                                                <?php 
                                                    $is_selected = false;
                                                    if ($item['matched_user_id'] && $item['matched_user_id'] === $u['user_id']) {
                                                        $is_selected = true;
                                                    } elseif (!$item['matched_user_id'] && $u['user_id'] === $current_user_id) {
                                                        $is_selected = true;
                                                    }
                                                ?>
                                                <option value="<?php echo $u['user_id']; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars(($u['prefix_name'] ?? '') . $u['first_name'] . ' ' . $u['last_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($item['matched_user_id']): ?>
                                            <div class="extra-small text-success mt-1">
                                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>จับคู่อัตโนมัติ: <?php echo htmlspecialchars($item['matched_user_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Import Button -->
                <div class="card-footer bg-white border-top p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($start_offset > 0): ?>
                            <a href="fetch_scopus.php?<?php echo http_build_query(array_merge($_GET, ['start' => max(0, $start_offset - $count_per_page)])); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                <i class="fa-solid fa-chevron-left me-1"></i> หน้าก่อนหน้า
                            </a>
                        <?php endif; ?>

                        <?php if (($start_offset + count($results)) < $total_scopus_results): ?>
                            <a href="fetch_scopus.php?<?php echo http_build_query(array_merge($_GET, ['start' => $start_offset + $count_per_page])); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                หน้าถัดไป (<?php echo ($start_offset + count($results) + 1); ?>-<?php echo min($total_scopus_results, $start_offset + $count_per_page * 2); ?>) <i class="fa-solid fa-chevron-right ms-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fa-solid fa-file-import me-1"></i> บันทึกผลงานที่เลือกลงระบบ (<span class="selectedCountText">0</span> รายการ)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    <?php elseif (!$api_error): ?>
        <div class="card border-0 shadow-sm rounded-4 bg-white p-5 text-center mb-5">
            <i class="fa-solid fa-file-circle-question fs-1 text-muted opacity-50 mb-3"></i>
            <h5 class="fw-bold text-dark">ไม่พบข้อมูลผลงานจากเงื่อนไขที่กำหนด</h5>
            <p class="text-muted small">ลองเปลี่ยนรูปแบบการค้นหา หรือปรับแก้คำค้นหาในช่อง Scopus Query ด้านบน</p>
        </div>
    <?php endif; ?>

</main>

<script>
function handleModeChange() {
    const mode = document.getElementById('modeSelect').value;
    const authorGroup = document.getElementById('authorGroup');
    const customGroup = document.getElementById('customGroup');

    authorGroup.style.display = (mode === 'author') ? 'block' : 'none';
    customGroup.style.display = (mode === 'custom') ? 'block' : 'none';
}

function selectFacultyName(selectElement) {
    const val = selectElement.value;
    if (val) {
        document.getElementById('authorQueryInput').value = val;
    }
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').innerText = count;
    document.querySelectorAll('.selectedCountText').forEach(el => el.innerText = count);
}

function toggleAllCheckboxes(headerCheckbox) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => cb.checked = headerCheckbox.checked);
    updateSelectedCount();
}

function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
    document.getElementById('headerCheckbox').checked = !allChecked;
    updateSelectedCount();
}

function selectNewOnly() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => {
        const isExisting = cb.getAttribute('data-existing') === '1';
        cb.checked = !isExisting;
    });
    updateSelectedCount();
}

document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});
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
</style>

<?php require_once '../../includes/footer.php'; ?>
