<?php
// modules/qrcode/index.php - User QR Code Management
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$current_user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['roles'] ?? [];
$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'งานแผน', 'ผู้ช่วยคณบดี', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี', 'งานพัสดุ', 'งานอาคารสถานที่', 'งานพัฒนานิสิต', 'รองคณบดีฝ่ายพัฒนานิสิต', 'โสตทัศนูปกรณ์'];
$is_manager = hasRole($management_roles);

// Get Selected Category Filter
$selected_category = trim($_GET['category'] ?? '');
$search_query = trim($_GET['search'] ?? '');

// Fetch User's QR Codes
$where_clauses = ["user_id = ?"];
$params = [$current_user_id];

if (!empty($selected_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $selected_category;
}
if (!empty($search_query)) {
    $where_clauses[] = "(title LIKE ? OR description LIKE ? OR target_url LIKE ?)";
    $like = "%{$search_query}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(" AND ", $where_clauses);

try {
    $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE {$where_sql} ORDER BY id DESC");
    $stmt->execute($params);
    $my_qrcodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get User's Total Stats
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_qr,
            COALESCE(SUM(scan_count), 0) as total_scans,
            MAX(scan_count) as max_scans
        FROM qr_codes 
        WHERE user_id = ?
    ");
    $statsStmt->execute([$current_user_id]);
    $user_stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get category counts for this user
    $catStmt = $pdo->prepare("
        SELECT category, COUNT(*) as cnt 
        FROM qr_codes 
        WHERE user_id = ? 
        GROUP BY category
    ");
    $catStmt->execute([$current_user_id]);
    $user_categories = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    $my_qrcodes = [];
    $user_stats = ['total_qr' => 0, 'total_scans' => 0, 'max_scans' => 0];
    $user_categories = [];
}

// Preset standard categories
$standard_categories = [
    'ประชาสัมพันธ์/ข่าวสาร' => 'fa-bullhorn text-primary',
    'เอกสาร/คู่มือ/ประกาศ'   => 'fa-file-lines text-warning',
    'แบบสอบถาม/แบบฟอร์ม'  => 'fa-clipboard-list text-success',
    'สื่อการเรียนการสอน'     => 'fa-graduation-cap text-info',
    'กิจกรรม/โครงการ'       => 'fa-calendar-check text-danger',
    'เว็บไซต์/ระบบงาน'     => 'fa-globe text-purple',
    'ทั่วไป/อื่นๆ'           => 'fa-tags text-secondary'
];

// Protocol and Host for Absolute Tracking URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base_tracking_url = $host_url . "/music-e-office/modules/qrcode/r.php?c=";

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Top Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-qrcode text-purple me-2"></i>ระบบสร้างและจัดเก็บ QR Code
            </h1>
            <p class="text-muted small mb-0">สร้าง QR Code ลิงก์ต่างๆ จัดเก็บเป็นคลังส่วนตัว และติดตามสถิติการสแกน</p>
        </div>
        <div class="mt-3 mt-md-0 d-flex gap-2">
            <?php if ($is_manager): ?>
            <a href="dashboard.php" class="btn btn-outline-purple rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดผู้บริหาร
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-purple rounded-pill px-4 shadow-sm fw-bold" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> สร้าง QR Code ใหม่
            </button>
        </div>
    </div>

    <!-- User KPI Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-3 border-start border-purple border-4">
                <div class="d-flex align-items-center">
                    <div class="rounded-4 bg-purple-subtle text-purple p-3 me-3">
                        <i class="fa-solid fa-qrcode fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted extra-small fw-bold text-uppercase">QR Code ของฉัน</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($user_stats['total_qr'] ?? 0); ?> <small class="fs-6 fw-normal text-muted">รายการ</small></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-3 border-start border-success border-4">
                <div class="d-flex align-items-center">
                    <div class="rounded-4 bg-success-subtle text-success p-3 me-3">
                        <i class="fa-solid fa-chart-line fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted extra-small fw-bold text-uppercase">ยอดสแกนรวมทั้งหมด</div>
                        <h3 class="fw-bold text-success mb-0"><?php echo number_format($user_stats['total_scans'] ?? 0); ?> <small class="fs-6 fw-normal text-muted">ครั้ง</small></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-3 border-start border-info border-4">
                <div class="d-flex align-items-center">
                    <div class="rounded-4 bg-info-subtle text-info p-3 me-3">
                        <i class="fa-solid fa-fire fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted extra-small fw-bold text-uppercase">ยอดสแกนสูงสุด</div>
                        <h3 class="fw-bold text-info mb-0"><?php echo number_format($user_stats['max_scans'] ?? 0); ?> <small class="fs-6 fw-normal text-muted">ครั้ง</small></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 p-3 border-start border-warning border-4">
                <div class="d-flex align-items-center">
                    <div class="rounded-4 bg-warning-subtle text-warning p-3 me-3">
                        <i class="fa-solid fa-layer-group fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted extra-small fw-bold text-uppercase">หมวดหมู่ที่ใช้งาน</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo count($user_categories); ?> <small class="fs-6 fw-normal text-muted">หมวด</small></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Filter Pills & Search -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <!-- Category Pills -->
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="index.php" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo empty($selected_category) ? 'btn-purple' : 'btn-light text-secondary border'; ?>">
                        ทั้งหมด <span class="badge bg-white text-purple rounded-pill ms-1"><?php echo $user_stats['total_qr'] ?? 0; ?></span>
                    </a>
                    <?php foreach ($standard_categories as $cat => $iconClass): 
                        $count = $user_categories[$cat] ?? 0;
                        if ($count > 0 || empty($user_categories)):
                    ?>
                        <a href="index.php?category=<?php echo urlencode($cat); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo ($selected_category === $cat) ? 'btn-purple' : 'btn-light text-secondary border'; ?>">
                            <?php echo htmlspecialchars($cat); ?> 
                            <?php if ($count > 0): ?>
                                <span class="badge <?php echo ($selected_category === $cat) ? 'bg-white text-purple' : 'bg-secondary-subtle text-dark'; ?> rounded-pill ms-1"><?php echo $count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php 
                        endif;
                    endforeach; ?>
                </div>

                <!-- Search Input -->
                <form method="GET" action="index.php" class="d-flex gap-2" style="max-width: 300px;">
                    <?php if (!empty($selected_category)): ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($selected_category); ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control rounded-start-pill border-end-0 ps-3" placeholder="ค้นหาชื่อ QR Code..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button class="btn btn-purple rounded-end-pill px-3" type="submit">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>
                    <?php if (!empty($search_query)): ?>
                        <a href="index.php<?php echo !empty($selected_category) ? '?category='.urlencode($selected_category) : ''; ?>" class="btn btn-sm btn-light border rounded-pill" title="ล้างการค้นหา">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- QR Codes Cards Grid -->
    <?php if (empty($my_qrcodes)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white my-4">
            <div class="mb-3 text-secondary opacity-50">
                <i class="fa-solid fa-qrcode fa-4x"></i>
            </div>
            <h4 class="fw-bold text-dark mb-2">ยังไม่มี QR Code ในหมวดนี้</h4>
            <p class="text-muted small mb-4">คุณสามารถสร้าง QR Code จากลิงก์เว็บไซต์ เอกสาร หรือแบบฟอร์ม เพื่อนำไปใช้งานได้สะดวกรวดเร็ว</p>
            <div>
                <button type="button" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm" onclick="openCreateModal()">
                    <i class="fa-solid fa-plus me-1"></i> สร้าง QR Code แรกของคุณ
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4" id="qrcodeGrid">
            <?php foreach ($my_qrcodes as $item): 
                $track_url = $base_tracking_url . $item['code_key'];
                $final_url = ($item['is_trackable'] == 1) ? $track_url : $item['target_url'];
                $color = !empty($item['qr_color']) ? $item['qr_color'] : '#000000';
                $bg_color = !empty($item['qr_bg_color']) ? $item['qr_bg_color'] : '#ffffff';
            ?>
            <div class="col-md-6 col-xl-4 qrcode-card-item">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white overflow-hidden hover-card transition-all" style="border-top: 4px solid <?php echo htmlspecialchars($color); ?> !important;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <!-- Top Badge & Actions -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge bg-purple-subtle text-purple rounded-pill px-3 py-1 extra-small fw-bold">
                                    <i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($item['category']); ?>
                                </span>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border-0 rounded-circle text-muted" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 32px; height: 32px;">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                        <li>
                                            <a class="dropdown-item small" href="javascript:void(0)" onclick='viewQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo $final_url; ?>")'>
                                                <i class="fa-solid fa-eye text-primary me-2"></i>ดู / ขยาย QR Code
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item small" href="javascript:void(0)" onclick='editQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                <i class="fa-solid fa-pen-to-square text-warning me-2"></i>แก้ไขข้อมูล
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item small" href="javascript:void(0)" onclick='copyToClipboard("<?php echo $final_url; ?>", "คัดลอกลิงก์สำเร็จ")'>
                                                <i class="fa-solid fa-copy text-info me-2"></i>คัดลอกลิงก์
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item small text-danger" href="javascript:void(0)" onclick="deleteQr(<?php echo $item['id']; ?>, '<?php echo addslashes(htmlspecialchars($item['title'])); ?>')">
                                                <i class="fa-solid fa-trash me-2"></i>ลบ QR Code
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- QR Code Visual Box & Details -->
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="qr-thumbnail-box p-2 bg-light rounded-3 shadow-inner d-flex align-items-center justify-content-center cursor-pointer flex-shrink-0" 
                                     style="width: 100px; height: 100px; cursor: pointer;"
                                     onclick='viewQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo $final_url; ?>")'>
                                    <div id="thumb_qr_<?php echo $item['id']; ?>" class="qr-code-canvas"></div>
                                </div>
                                <div class="overflow-hidden flex-grow-1">
                                    <h5 class="fw-bold text-dark mb-1 text-truncate" title="<?php echo htmlspecialchars($item['title']); ?>">
                                        <?php echo htmlspecialchars($item['title']); ?>
                                    </h5>
                                    <?php if (!empty($item['description'])): ?>
                                        <p class="text-muted extra-small mb-1 text-truncate" title="<?php echo htmlspecialchars($item['description']); ?>">
                                            <?php echo htmlspecialchars($item['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="extra-small text-truncate">
                                        <a href="<?php echo htmlspecialchars($item['target_url']); ?>" target="_blank" class="text-decoration-none text-secondary">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><?php echo htmlspecialchars($item['target_url']); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer: Stats & Actions -->
                        <div class="pt-3 border-top border-light d-flex justify-content-between align-items-center">
                            <div class="d-flex gap-2 align-items-center">
                                <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1 extra-small" title="จำนวนครั้งที่มีการสแกน">
                                    <i class="fa-solid fa-qrcode me-1"></i>สแกน <strong><?php echo number_format($item['scan_count']); ?></strong> ครั้ง
                                </span>
                                <?php if ($item['is_trackable'] == 1): ?>
                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1 extra-small" title="เปิดนับสถิติอัตโนมัติ">
                                        <i class="fa-solid fa-chart-simple text-purple me-1"></i>Tracked
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-light text-purple rounded-pill px-3 fw-bold" onclick='viewQrModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo $final_url; ?>")'>
                                    <i class="fa-solid fa-download me-1"></i> ดาวน์โหลด
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Create / Edit QR Code Modal -->
<div class="modal fade" id="qrModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="fw-bold mb-1 text-dark" id="qrModalTitle">
                        <i class="fa-solid fa-qrcode text-purple me-2"></i>สร้าง QR Code ใหม่
                    </h5>
                    <p class="text-muted small mb-0">กรอกลิงก์และข้อมูลเพื่อสร้าง QR Code พร้อมปรับแต่งสีตามต้องการ</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <form id="qrForm" onsubmit="handleQrSubmit(event)">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formQrId" value="">

                <div class="modal-body p-4">
                    <div class="row g-4">
                        <!-- Form Inputs Left Column -->
                        <div class="col-md-7">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">ชื่อ QR Code / เรื่อง <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="inputTitle" class="form-control rounded-3 shadow-sm" placeholder="เช่น เอกสารคู่มือการใช้งาน, ลิงก์แบบประเมิน" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">ลิงก์ปลายทาง (Target URL) <span class="text-danger">*</span></label>
                                <div class="input-group shadow-sm rounded-3">
                                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-link"></i></span>
                                    <input type="url" name="target_url" id="inputUrl" class="form-control" placeholder="https://example.com/form..." required oninput="updateLivePreview()">
                                </div>
                                <div class="form-text extra-small text-muted">ใส่ลิงก์ปลายทางที่ต้องการให้ผู้สแกนเปิด เช่น ลิงก์ Google Forms, ลิงก์เว็บ หรือไฟล์เอกสาร</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">หมวดหมู่ <span class="text-danger">*</span></label>
                                <select name="category" id="inputCategory" class="form-select rounded-3 shadow-sm">
                                    <?php foreach ($standard_categories as $c => $icon): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">คำอธิบายเพิ่มเติม (ถ้ามี)</label>
                                <textarea name="description" id="inputDescription" rows="2" class="form-control rounded-3 shadow-sm" placeholder="ระบุรายละเอียดเพื่อช่วยจำ..."></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark">เลือกโทนสีของ QR Code</label>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <label class="color-preset-btn active" data-color="#000000" style="background-color: #000000;" title="ดำคลาสสิก"></label>
                                    <label class="color-preset-btn" data-color="#4b0082" style="background-color: #4b0082;" title="ม่วง MSU Music"></label>
                                    <label class="color-preset-btn" data-color="#1d4ed8" style="background-color: #1d4ed8;" title="น้ำเงินเข้ม"></label>
                                    <label class="color-preset-btn" data-color="#059669" style="background-color: #059669;" title="เขียวมรกต"></label>
                                    <label class="color-preset-btn" data-color="#dc2626" style="background-color: #dc2626;" title="แดงทับทิม"></label>
                                    <label class="color-preset-btn" data-color="#d97706" style="background-color: #d97706;" title="ส้มอำพัน"></label>
                                    <input type="color" name="qr_color" id="inputQrColor" value="#000000" class="form-control form-control-color border-0 p-0 rounded-circle" style="width: 32px; height: 32px; cursor: pointer;" title="เลือกสีอื่นๆ" onchange="updateLivePreview()">
                                </div>
                            </div>

                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="inputIsTrackable" name="is_trackable" value="1" checked>
                                <label class="form-check-label small fw-bold text-dark" for="inputIsTrackable">
                                    เปิดใช้งานการนับสถิติการสแกน (Track Scan Counts)
                                </label>
                                <div class="form-text extra-small text-muted">ระบบจะนับจำนวนครั้งที่ผู้ใช้งานสแกน QR Code นี้ให้อัตโนมัติ</div>
                            </div>
                        </div>

                        <!-- Live Preview Right Column -->
                        <div class="col-md-5 d-flex flex-column align-items-center justify-content-center border-start ps-md-4">
                            <div class="text-center w-100">
                                <span class="badge bg-light text-secondary border rounded-pill px-3 py-1 extra-small text-uppercase mb-3">
                                    <i class="fa-solid fa-eye me-1"></i>ตัวอย่าง QR Code ทันที
                                </span>
                                
                                <div class="p-3 bg-white rounded-4 shadow-sm border mx-auto d-flex align-items-center justify-content-center" style="width: 220px; height: 220px;">
                                    <div id="liveQrPreview"></div>
                                </div>

                                <div class="mt-3 text-muted extra-small text-truncate px-2" id="previewUrlText">
                                    กรุณากรอกลิงก์เพื่อสร้าง QR
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-0 px-4 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-purple rounded-pill px-5 fw-bold shadow-sm" id="btnSubmitForm">
                        <i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
                    <button type="button" class="btn btn-outline-dark rounded-pill px-3 fw-bold" onclick="printQrCard()">
                        <i class="fa-solid fa-print me-1"></i> สั่งพิมพ์
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
    .btn-outline-purple { border-color: #4b0082; color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .extra-small { font-size: 0.75rem; }
    .hover-card:hover { transform: translateY(-4px); box-shadow: 0 12px 25px rgba(75, 0, 130, 0.12) !important; }
    .transition-all { transition: all 0.25s ease; }
    .cursor-pointer { cursor: pointer; }
    .color-preset-btn {
        width: 30px; height: 30px; border-radius: 50%; display: inline-block; cursor: pointer;
        border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: 0.2s;
    }
    .color-preset-btn.active { transform: scale(1.15); border-color: #000; }
</style>

<!-- Load local QRCode library with CDN fallback -->
<script src="../../assets/js/qrcode.min.js"></script>
<script>
    if (typeof QRCode === 'undefined') {
        document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
    }
</script>

<script>
    let currentActiveUrl = "";
    let currentActiveTitle = "";
    let activeQrItem = null;

    // Render all QR thumbnails on the page
    document.addEventListener("DOMContentLoaded", function() {
        const qrItems = <?php echo json_encode($my_qrcodes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        qrItems.forEach(item => {
            const container = document.getElementById("thumb_qr_" + item.id);
            if (container) {
                const finalUrl = (item.is_trackable == 1) 
                    ? "<?php echo $base_tracking_url; ?>" + item.code_key 
                    : item.target_url;
                
                new QRCode(container, {
                    text: finalUrl,
                    width: 84,
                    height: 84,
                    colorDark: item.qr_color || "#000000",
                    colorLight: item.qr_bg_color || "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        });

        // Color preset click events
        document.querySelectorAll('.color-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.color-preset-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const col = this.getAttribute('data-color');
                document.getElementById('inputQrColor').value = col;
                updateLivePreview();
            });
        });
    });

    // Open Create Modal
    function openCreateModal() {
        document.getElementById('qrForm').reset();
        document.getElementById('formAction').value = 'create';
        document.getElementById('formQrId').value = '';
        document.getElementById('qrModalTitle').innerHTML = '<i class="fa-solid fa-qrcode text-purple me-2"></i>สร้าง QR Code ใหม่';
        document.getElementById('inputQrColor').value = '#000000';
        document.getElementById('inputIsTrackable').checked = true;
        
        document.querySelectorAll('.color-preset-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-color') === '#000000');
        });

        updateLivePreview();
        new bootstrap.Modal(document.getElementById('qrModal')).show();
    }

    // Open Edit Modal
    function editQrModal(item) {
        document.getElementById('formAction').value = 'update';
        document.getElementById('formQrId').value = item.id;
        document.getElementById('qrModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-warning me-2"></i>แก้ไขข้อมูล QR Code';
        
        document.getElementById('inputTitle').value = item.title;
        document.getElementById('inputUrl').value = item.target_url;
        document.getElementById('inputCategory').value = item.category;
        document.getElementById('inputDescription').value = item.description || '';
        document.getElementById('inputQrColor').value = item.qr_color || '#000000';
        document.getElementById('inputIsTrackable').checked = (item.is_trackable == 1);

        document.querySelectorAll('.color-preset-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-color') === (item.qr_color || '#000000'));
        });

        updateLivePreview();
        new bootstrap.Modal(document.getElementById('qrModal')).show();
    }

    // Update Live Preview in Modal
    let liveQrInstance = null;
    function updateLivePreview() {
        const urlInput = document.getElementById('inputUrl').value.trim();
        const colorInput = document.getElementById('inputQrColor').value;
        const previewContainer = document.getElementById('liveQrPreview');
        const urlText = document.getElementById('previewUrlText');

        previewContainer.innerHTML = '';

        const renderUrl = urlInput || "https://music.msu.ac.th";
        urlText.textContent = urlInput ? renderUrl : "ตัวอย่างลิงก์เริ่มต้น (กรุณากรอกลิงก์ของคุณ)";

        try {
            new QRCode(previewContainer, {
                text: renderUrl,
                width: 190,
                height: 190,
                colorDark: colorInput || "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        } catch (e) {
            console.error(e);
        }
    }

    // Handle Create/Edit Submit via AJAX
    function handleQrSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitForm');
        btn.disabled = true;

        const formData = new FormData(document.getElementById('qrForm'));

        fetch('action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message);
                window.location.reload();
            } else {
                alert('เกิดข้อผิดพลาด: ' + data.message);
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
            btn.disabled = false;
        });
    }

    // Delete QR Code
    function deleteQr(id, title) {
        if (!confirm(`คุณต้องการลบ QR Code "${title}" ใช่หรือไม่?`)) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch('action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message);
                window.location.reload();
            } else {
                alert('เกิดข้อผิดพลาด: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
        });
    }

    // View / Enlarge Modal
    function viewQrModal(item, finalUrl) {
        activeQrItem = item;
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
            colorDark: item.qr_color || "#4b0082",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });

        new bootstrap.Modal(document.getElementById('viewQrModal')).show();
    }

    // Download QR as PNG Image
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

    // Copy Current QR Link
    function copyCurrentQrLink() {
        copyToClipboard(currentActiveUrl, "คัดลอกลิงก์สำเร็จ: " + currentActiveUrl);
    }

    // Generic Clipboard Helper
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

    // Print QR Card
    function printQrCard() {
        const printContents = document.getElementById('printArea').innerHTML;
        const originalContents = document.body.innerHTML;

        const printWindow = window.open('', '', 'width=600,height=600');
        printWindow.document.write(`
            <html>
                <head>
                    <title>พิมพ์ QR Code - MSU Music Connect</title>
                    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;600;700&display=swap" rel="stylesheet">
                    <style>
                        body { font-family: 'Anuphan', sans-serif; text-align: center; padding: 40px; }
                        #printBox { max-width: 400px; margin: 0 auto; border: 2px solid #ddd; border-radius: 16px; padding: 30px; }
                        img { max-width: 100%; }
                    </style>
                </head>
                <body>
                    <div id="printBox">${printContents}</div>
                    <script>
                        window.onload = function() { window.print(); window.close(); }
                    <\/script>
                </body>
            </html>
        `);
        printWindow.document.close();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
