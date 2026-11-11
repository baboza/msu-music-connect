<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$current_user_id = $_SESSION['user_id'];

// ตรวจสอบสิทธิ์ผู้อนุมัติ (Admin, งานโสตทัศนศึกษา, คณบดี)
$pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
$is_approver = hasRole($pr_approver_roles);
$is_admin = hasRole(['admin']);

if (!function_exists('resolveSignageUrl')) {
    function resolveSignageUrl($url) {
        $url = trim($url ?? '');
        if (empty($url)) return '';
        if (preg_match('/^(https?:|\/\/|data:)/i', $url)) {
            return $url;
        }
        if (strpos($url, '/') === 0) {
            return $url;
        }
        if (strpos($url, '../../') === 0) {
            return $url;
        }
        return '../../' . ltrim($url, './');
    }
}

// ดึงจำนวนรายการที่รอการพิจารณาอนุมัติ
$cnt_pending = 0;
if ($is_approver) {
    $stmtPending = $pdo->query("SELECT COUNT(*) FROM pr_signage_items WHERE status = 'pending'");
    $cnt_pending = (int)$stmtPending->fetchColumn();
}

// ดึงสถิติจอที่ 1 และ 2
$stmtS1Count = $pdo->query("SELECT COUNT(*) FROM pr_signage_items WHERE status = 'approved' AND start_date <= CURDATE() AND end_date >= CURDATE()");
$active_slides_count = (int)$stmtS1Count->fetchColumn();

$stmtS2Count = $pdo->query("SELECT COUNT(*) FROM pr_kiosk_contents WHERE is_active = 1");
$active_kiosk_count = (int)$stmtS2Count->fetchColumn();

// ดึงรายการป้ายของผู้ใช้ปัจจุบัน
$stmtMy = $pdo->prepare("
    SELECT * FROM pr_signage_items 
    WHERE user_id = :uid 
    ORDER BY created_at DESC
");
$stmtMy->execute([':uid' => $current_user_id]);
$my_items = $stmtMy->fetchAll(PDO::FETCH_ASSOC);

// สร้าง Full Public URLs
$baseHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$publicScreen1Url = $baseHost . "/music-e-office/modules/pr/public_screen1.php";
$publicScreen2Url = $baseHost . "/music-e-office/modules/pr/public_screen2.php";

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Top Header Banner -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge badge-purple-soft rounded-pill px-3 py-1">
                    <i class="fa-solid fa-bullhorn me-1"></i> Public Relations & Smart Signage
                </span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-tv text-purple me-2"></i>ระบบจอประชาสัมพันธ์และคีออสก์
            </h3>
            <p class="text-muted small mb-0">
                วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม • จัดการจอ LED เล่นวน (16:9, Canva, YouTube) และจอสัมผัส Interactive 3 ภาษา
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="submit.php" class="btn btn-purple rounded-pill px-4 py-2 shadow-sm d-flex align-items-center gap-2">
                <i class="fa-solid fa-circle-plus"></i>
                <span>ส่งคำขอขึ้นป้ายใหม่</span>
            </a>
            <?php if ($is_approver): ?>
                <a href="approve_list.php" class="btn btn-outline-purple rounded-pill px-4 py-2 position-relative d-flex align-items-center gap-2">
                    <i class="fa-solid fa-clipboard-check"></i>
                    <span>ตรวจอนุมัติป้าย</span>
                    <?php if ($cnt_pending > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                            <?= $cnt_pending ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Public Displays (2 จอ LED ขนาดใหญ่ - Modern White & Purple Style) -->
    <div class="row g-4 mb-4">
        <!-- จอที่ 1: Digital Signage Auto-Loop -->
        <div class="col-lg-6">
            <div class="screen-card-white p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge badge-purple-soft px-3 py-2 rounded-pill fw-bold">
                            <i class="fa-solid fa-play-circle me-1 text-purple"></i> จอที่ 1: LED Signage Player
                        </span>
                        <span class="badge badge-emerald-soft px-3 py-2 rounded-pill">
                            <i class="fa-solid fa-circle-dot me-1 text-success"></i> เล่นวน <?= $active_slides_count ?> ป้าย
                        </span>
                    </div>

                    <h4 class="fw-bold text-dark mb-2">จอเล่นวนป้ายประชาสัมพันธ์ (Auto-Loop)</h4>
                    <p class="text-muted small mb-3">
                        เล่นวนสื่อภาพสัดส่วน 16:9, ลิงก์ Canva และ YouTube อัตโนมัติ ป้ายที่เพิ่งอนุมัติจะแทรกเข้าคิวทันทีโดยไม่ต้องรีเฟรชหน้าจอ
                    </p>

                    <div class="bg-light p-2 rounded-3 border d-flex align-items-center justify-content-between mb-4">
                        <code class="text-purple small text-truncate me-2 fw-semibold"><?= htmlspecialchars($publicScreen1Url) ?></code>
                        <button type="button" class="btn btn-sm btn-outline-purple rounded-pill px-3 copy-btn text-nowrap" data-url="<?= htmlspecialchars($publicScreen1Url) ?>">
                            <i class="fa-regular fa-copy me-1"></i> คัดลอกลิงก์
                        </button>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="public_screen1.php" target="_blank" class="btn btn-purple rounded-pill px-4 flex-grow-1 text-center">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดหน้าจอที่ 1 (Fullscreen)
                    </a>
                    <?php if ($is_approver): ?>
                        <a href="manage_signage.php" class="btn btn-outline-purple rounded-pill px-3" title="จัดลำดับคิวการเล่น">
                            <i class="fa-solid fa-sliders"></i> จัดคิว
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- จอที่ 2: Interactive Kiosk (3 ภาษา) -->
        <div class="col-lg-6">
            <div class="screen-card-white p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge badge-purple-soft px-3 py-2 rounded-pill fw-bold">
                            <i class="fa-solid fa-hand-pointer me-1 text-purple"></i> จอที่ 2: Interactive Touch Kiosk
                        </span>
                        <span class="badge badge-purple-soft px-3 py-2 rounded-pill">
                            8 หมวดหมู่ • 3 ภาษา (TH • EN • CN)
                        </span>
                    </div>

                    <h4 class="fw-bold text-dark mb-2">จอสัมผัสค้นหาข้อมูลอัจฉริยะ (Touch Kiosk)</h4>
                    <p class="text-muted small mb-3">
                        สำหรับนิสิตและผู้มาเยือนแตะดูข้อมูล 8 หมวดหมู่ พร้อม QR Code สแกนอ่านต่อบนมือถือ ตัดกลับหน้าแรกอัตโนมัติเมื่อไม่มีการแตะใน 1 นาที
                    </p>

                    <div class="bg-light p-2 rounded-3 border d-flex align-items-center justify-content-between mb-4">
                        <code class="text-purple small text-truncate me-2 fw-semibold"><?= htmlspecialchars($publicScreen2Url) ?></code>
                        <button type="button" class="btn btn-sm btn-outline-purple rounded-pill px-3 copy-btn text-nowrap" data-url="<?= htmlspecialchars($publicScreen2Url) ?>">
                            <i class="fa-regular fa-copy me-1"></i> คัดลอกลิงก์
                        </button>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="public_screen2.php" target="_blank" class="btn btn-purple rounded-pill px-4 flex-grow-1 text-center">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดหน้าจอที่ 2 (Kiosk Touch)
                    </a>
                    <?php if ($is_approver): ?>
                        <a href="manage_kiosk.php" class="btn btn-outline-purple rounded-pill px-3" title="จัดการเนื้อหา 8 หมวดหมู่">
                            <i class="fa-solid fa-grid-2"></i> จัดการเนื้อหา
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Approver & Admin Quick Tool Strip (Clean White) -->
    <?php if ($is_approver): ?>
    <div class="card card-modern mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-purple-soft px-3 py-2 rounded-pill fw-bold">
                        <i class="fa-solid fa-shield-halved text-purple me-1"></i> เมนูผู้บริหารและงานโสตฯ
                    </span>
                    <span class="text-muted small">เครื่องมือจัดการสำหรับผู้ดูแลระบบและฝ่ายโสตทัศนูปกรณ์</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="approve_list.php" class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold">
                        <i class="fa-solid fa-clipboard-check me-1"></i> ตรวจสอบคำขอ (<?= $cnt_pending ?>)
                    </a>
                    <a href="manage_signage.php" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary">
                        <i class="fa-solid fa-list-ol text-purple me-1"></i> คิวจอที่ 1 (Signage)
                    </a>
                    <a href="manage_kiosk.php" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary">
                        <i class="fa-solid fa-layer-group text-purple me-1"></i> เนื้อหาจอที่ 2 (Kiosk 3 ภาษา)
                    </a>
                    <a href="settings.php" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary">
                        <i class="fa-solid fa-gear text-purple me-1"></i> ตั้งค่าตัววิ่ง/จอ
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- User's Submissions List (Clean White Table) -->
    <div class="card card-modern overflow-hidden">
        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                    <i class="fa-solid fa-clock-rotate-left text-purple"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-dark">รายการป้ายประชาสัมพันธ์ของฉัน</h5>
                    <div class="text-muted extra-small">My Signage Submissions History</div>
                </div>
            </div>
            <span class="badge badge-purple-soft rounded-pill px-3 py-2">
                ทั้งหมด <?= count($my_items) ?> รายการ
            </span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($my_items)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-photo-film text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ท่านยังไม่มีรายการส่งป้ายประชาสัมพันธ์</h6>
                    <p class="text-muted small mb-3">กดปุ่มด้านล่างเพื่อเริ่มส่งป้ายขึ้นจอ LED ขนาดใหญ่ของวิทยาลัยได้ทันที</p>
                    <a href="submit.php" class="btn btn-purple rounded-pill px-4">
                        <i class="fa-solid fa-circle-plus me-1"></i> ส่งคำขอขึ้นป้ายใหม่
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted small">
                                <th class="ps-4" style="width: 75px;">พรีวิว</th>
                                <th>หัวข้อประชาสัมพันธ์</th>
                                <th style="width: 140px;">ประเภทสื่อ</th>
                                <th style="width: 130px;">เป้าหมายจอ</th>
                                <th style="width: 180px;">ช่วงเวลาแสดงผล</th>
                                <th style="width: 140px;" class="text-center">สถานะ</th>
                                <th style="width: 100px;" class="text-end pe-4">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_items as $item): 
                                $is_active = ($item['status'] === 'approved' && $item['start_date'] <= date('Y-m-d') && $item['end_date'] >= date('Y-m-d'));
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if ($item['media_type'] === 'image'): ?>
                                            <img src="<?= htmlspecialchars(resolveSignageUrl($item['media_url'])) ?>" alt="Preview" class="rounded shadow-xs border" style="width: 60px; height: 36px; object-fit: cover;">
                                        <?php elseif ($item['media_type'] === 'youtube'): ?>
                                            <div class="rounded bg-rose-soft d-flex align-items-center justify-content-center text-danger" style="width: 60px; height: 36px;">
                                                <i class="fa-brands fa-youtube fs-5"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="rounded bg-purple-subtle d-flex align-items-center justify-content-center text-purple" style="width: 60px; height: 36px;">
                                                <i class="fa-solid fa-palette"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="extra-small text-muted">
                                            ส่งเมื่อ: <?= date('d/m/Y H:i', strtotime($item['created_at'])) ?> • เล่น <?= $item['duration_seconds'] ?> วินาที/รอบ
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item['media_type'] === 'image'): ?>
                                            <span class="badge bg-light text-dark border"><i class="fa-solid fa-image text-success me-1"></i> ภาพ 16:9</span>
                                        <?php elseif ($item['media_type'] === 'canva'): ?>
                                            <span class="badge badge-purple-soft"><i class="fa-solid fa-palette text-purple me-1"></i> Canva</span>
                                        <?php else: ?>
                                            <span class="badge badge-rose-soft"><i class="fa-brands fa-youtube text-danger me-1"></i> YouTube</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['target_screen'] === 'both'): ?>
                                            <span class="badge badge-purple-soft">ทั้ง 2 จอ</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">จอที่ 1</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark">
                                            <?= date('d/m/Y', strtotime($item['start_date'])) ?> - <?= date('d/m/Y', strtotime($item['end_date'])) ?>
                                        </div>
                                        <?php if ($is_active): ?>
                                            <span class="badge badge-emerald-soft extra-small">● กำลังเล่นวนสด</span>
                                        <?php elseif ($item['status'] === 'approved' && $item['end_date'] < date('Y-m-d')): ?>
                                            <span class="badge bg-light text-muted extra-small">หมดอายุแล้ว</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($item['status'] === 'pending'): ?>
                                            <span class="badge badge-amber-soft badge-pill-modern">
                                                <i class="fa-solid fa-hourglass-half me-1"></i> รอพิจารณา
                                            </span>
                                        <?php elseif ($item['status'] === 'approved'): ?>
                                            <span class="badge badge-emerald-soft badge-pill-modern">
                                                <i class="fa-solid fa-circle-check me-1"></i> อนุมัติแล้ว
                                            </span>
                                        <?php elseif ($item['status'] === 'rejected'): ?>
                                            <button type="button" class="btn btn-sm badge-rose-soft rounded-pill px-3 py-1 border-0" onclick="showRejectReason('<?= htmlspecialchars(addslashes($item['reject_reason'] ?? 'ไม่ผ่านเกณฑ์การพิจารณา')) ?>')">
                                                <i class="fa-solid fa-circle-xmark me-1 text-danger"></i> ถูกยกเลิก (ดูเหตุผล)
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted rounded-pill px-3 py-1">หมดอายุ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($item['status'] === 'pending'): ?>
                                            <a href="delete.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-danger rounded-circle" onclick="return confirm('ยืนยันที่จะยกเลิกคำขอนี้หรือไม่?')" title="ยกเลิกคำขอ">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted extra-small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Modal แสดงเหตุผลกรณีถูกยกเลิก/ปฏิเสธ (Modern White & Rose Accent) -->
<div class="modal fade" id="reasonModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 bg-danger bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>เหตุผลการยกเลิก / ไม่อนุมัติป้าย
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted mb-2 small">ผู้อนุมัติ (Admin / ฝ่ายโสตฯ / คณบดี) ได้ระบุเหตุผลดังนี้:</p>
                <div class="p-3 bg-light rounded-3 border text-dark fw-semibold" id="reasonText"></div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Copy URL function
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = this.dataset.url;
            navigator.clipboard.writeText(url).then(() => {
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="fa-solid fa-check text-purple"></i> คัดลอกแล้ว!';
                this.classList.add('bg-purple-subtle');
                setTimeout(() => {
                    this.innerHTML = originalHtml;
                    this.classList.remove('bg-purple-subtle');
                }, 2000);
            });
        });
    });

    function showRejectReason(reason) {
        document.getElementById('reasonText').textContent = reason || 'ไม่มีข้อความระบุเหตุผล';
        new bootstrap.Modal(document.getElementById('reasonModal')).show();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
