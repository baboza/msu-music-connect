<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ตรวจสอบสิทธิ์ผู้อนุมัติ: admin, งานโสตทัศนศึกษา, คณบดี
$pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($pr_approver_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง: หน้านี้สำหรับ Admin ฝ่ายโสตทัศนูปกรณ์ และคณบดีเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$current_user_id = $_SESSION['user_id'];
$status_filter = $_GET['status'] ?? 'pending';

// Helper: แปลง Relative Path (เช่น uploads/pr/...) ให้เป็น URL ที่หน้า modules/pr/ เรียกดูได้ถูกต้อง
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

// Handle Actions (Approve / Reject)
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($itemId > 0) {
        if ($action === 'approve') {
            $duration = max(5, (int)($_POST['duration_seconds'] ?? 10));
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $enable_sound = isset($_POST['enable_sound']) ? (int)$_POST['enable_sound'] : null;

            $sqlApprove = "
                UPDATE pr_signage_items 
                SET status = 'approved',
                    duration_seconds = :dur,
                    sort_order = :sort,
                    approved_by = :approver,
                    approved_at = NOW(),
                    reject_reason = NULL
            ";
            $paramsApprove = [
                ':dur' => $duration,
                ':sort' => $sort_order,
                ':approver' => $current_user_id,
                ':id' => $itemId
            ];
            if ($enable_sound !== null) {
                $sqlApprove .= ", enable_sound = :sound ";
                $paramsApprove[':sound'] = $enable_sound;
            }
            $sqlApprove .= " WHERE item_id = :id ";

            $stmt = $pdo->prepare($sqlApprove);
            $stmt->execute($paramsApprove);
            $action_msg = 'approved';
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if (empty($reason)) {
                $reason = 'ไม่ผ่านเกณฑ์การพิจารณาด้านรูปแบบหรือเนื้อหาประชาสัมพันธ์';
            }

            $stmt = $pdo->prepare("
                UPDATE pr_signage_items 
                SET status = 'rejected',
                    reject_reason = :reason,
                    approved_by = :approver,
                    approved_at = NOW()
                WHERE item_id = :id
            ");
            $stmt->execute([
                ':reason' => $reason,
                ':approver' => $current_user_id,
                ':id' => $itemId
            ]);
            $action_msg = 'rejected';
        }
    }
}

// นับจำนวนตามสถานะ
$counts = [];
$stmtC = $pdo->query("SELECT status, COUNT(*) as cnt FROM pr_signage_items GROUP BY status");
while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
    $counts[$r['status']] = (int)$r['cnt'];
}
$cnt_pending = $counts['pending'] ?? 0;
$cnt_approved = $counts['approved'] ?? 0;
$cnt_rejected = $counts['rejected'] ?? 0;
$cnt_all = array_sum($counts);

// Query ข้อมูลตามตัวกรอง
$sql = "
    SELECT p.*, 
           u.prefix_name, u.first_name, u.last_name, u.position,
           app.first_name as app_fname, app.last_name as app_lname
    FROM pr_signage_items p
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN users app ON p.approved_by = app.user_id
";
$params = [];
if ($status_filter !== 'all') {
    $sql .= " WHERE p.status = :status ";
    $params[':status'] = $status_filter;
}
$sql .= " ORDER BY (p.status = 'pending') DESC, p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Header Section -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้าหลัก PR
                </a>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-clipboard-check text-purple me-2"></i>พิจารณาอนุมัติป้ายประชาสัมพันธ์
            </h3>
            <p class="text-muted small mb-0 ps-1">
                สำหรับ Admin, ฝ่ายโสตทัศนศึกษา และคณบดี ในการตรวจสอบเนื้อหา อนุมัติขึ้นจอ หรือยกเลิกพร้อมระบุเหตุผล
            </p>
        </div>
        <div>
            <a href="manage_signage.php" class="btn btn-outline-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-sliders me-1"></i> จัดการคิวจอที่ 1
            </a>
        </div>
    </div>

    <?php if ($action_msg === 'approved'): ?>
        <div class="alert badge-emerald-soft alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2 fs-5 text-success"></i>
            <strong>อนุมัติป้ายประชาสัมพันธ์เรียบร้อยแล้ว!</strong> ป้ายจะถูกนำขึ้นเล่นวนบนจอ LED ทันทีตามกำหนดเวลา
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($action_msg === 'rejected'): ?>
        <div class="alert badge-rose-soft alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2 fs-5 text-danger"></i>
            <strong>ยกเลิก/ปฏิเสธคำขอเรียบร้อยแล้ว</strong> ได้บันทึกเหตุผลแจ้งให้ผู้ส่งทราบแล้ว
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Status Tabs Segmented (Purple & White) -->
    <div class="card card-modern mb-4">
        <div class="card-body p-2">
            <ul class="nav nav-pills nav-pills-purple nav-fill gap-2">
                <li class="nav-item">
                    <a class="nav-link <?= $status_filter === 'pending' ? 'active' : '' ?>" href="?status=pending">
                        <i class="fa-solid fa-hourglass-half me-1"></i> รอพิจารณาอนุมัติ
                        <span class="badge rounded-pill bg-danger ms-1"><?= $cnt_pending ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status_filter === 'approved' ? 'active' : '' ?>" href="?status=approved">
                        <i class="fa-solid fa-circle-check me-1"></i> อนุมัติแล้ว
                        <span class="badge rounded-pill bg-light text-dark ms-1"><?= $cnt_approved ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status_filter === 'rejected' ? 'active' : '' ?>" href="?status=rejected">
                        <i class="fa-solid fa-circle-xmark me-1"></i> ปฏิเสธ / ยกเลิกแล้ว
                        <span class="badge rounded-pill bg-light text-dark ms-1"><?= $cnt_rejected ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status_filter === 'all' ? 'active' : '' ?>" href="?status=all">
                        <i class="fa-solid fa-layer-group me-1"></i> ทั้งหมด
                        <span class="badge rounded-pill bg-light text-dark ms-1"><?= $cnt_all ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Items List Table -->
    <div class="card card-modern overflow-hidden">
        <div class="card-body p-0">
            <?php if (empty($items)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-inbox text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ไม่มีรายการในหมวดนี้</h6>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted small">
                                <th class="ps-4" style="width: 85px;">พรีวิว</th>
                                <th>หัวข้อประชาสัมพันธ์ & ผู้ส่ง</th>
                                <th style="width: 140px;">ประเภทสื่อ</th>
                                <th style="width: 120px;">เป้าหมายจอ</th>
                                <th style="width: 170px;">ช่วงเวลาแสดงผล</th>
                                <th style="width: 130px;" class="text-center">สถานะ</th>
                                <th style="width: 220px;" class="text-end pe-4">การพิจารณา</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): 
                                $user_fullname = trim(($item['prefix_name'] ?? '') . ' ' . ($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                                if (empty($user_fullname)) $user_fullname = $item['user_id'];
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if ($item['media_type'] === 'image'): ?>
                                            <img src="<?= htmlspecialchars(resolveSignageUrl($item['media_url'])) ?>" alt="Thumbnail" class="rounded shadow-xs border cursor-pointer" style="width: 70px; height: 40px; object-fit: cover;" onclick="openPreviewModal('<?= htmlspecialchars($item['media_type']) ?>', '<?= htmlspecialchars(resolveSignageUrl($item['media_url'])) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                                        <?php elseif ($item['media_type'] === 'youtube'): ?>
                                            <div class="rounded bg-rose-soft text-danger d-flex align-items-center justify-content-center cursor-pointer" style="width: 70px; height: 40px;" onclick="openPreviewModal('youtube', '<?= htmlspecialchars($item['media_url']) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                                                <i class="fa-brands fa-youtube fs-5"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="rounded bg-purple-subtle text-purple d-flex align-items-center justify-content-center cursor-pointer" style="width: 70px; height: 40px;" onclick="openPreviewModal('canva', '<?= htmlspecialchars($item['media_url']) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                                                <i class="fa-solid fa-palette fs-6"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-user me-1 text-purple"></i><?= htmlspecialchars($user_fullname) ?> 
                                            (<?= htmlspecialchars($item['position'] ?? 'บุคลากร') ?>) • ส่งเมื่อ <?= date('d/m/Y H:i', strtotime($item['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item['media_type'] === 'image'): ?>
                                            <span class="badge bg-light text-dark border"><i class="fa-solid fa-image text-success me-1"></i> ภาพ 16:9</span>
                                        <?php elseif ($item['media_type'] === 'canva'): ?>
                                            <span class="badge badge-purple-soft"><i class="fa-solid fa-palette text-purple me-1"></i> Canva</span>
                                        <?php else: ?>
                                            <span class="badge badge-rose-soft"><i class="fa-brands fa-youtube text-danger me-1"></i> YouTube</span>
                                            <?php if (!empty($item['enable_sound'])): ?>
                                                <div class="mt-1"><span class="badge bg-success-subtle text-success border border-success extra-small"><i class="fa-solid fa-volume-high me-1"></i>เปิดเสียง</span></div>
                                            <?php else: ?>
                                                <div class="mt-1"><span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-volume-xmark me-1"></i>ปิดเสียง</span></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <div class="extra-small text-muted mt-1"><?= $item['duration_seconds'] ?> วินาที/รอบ</div>
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
                                        <?php if ($item['status'] === 'approved'): ?>
                                            <?php if ($item['start_date'] <= date('Y-m-d') && $item['end_date'] >= date('Y-m-d')): ?>
                                                <span class="badge badge-emerald-soft extra-small">● กำลังเล่นวนสด</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted extra-small">ไม่อยู่ในช่วงเวลา</span>
                                            <?php endif; ?>
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
                                            <span class="badge badge-rose-soft badge-pill-modern">
                                                <i class="fa-solid fa-circle-xmark me-1"></i> ถูกยกเลิก
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted rounded-pill px-3 py-1">หมดอายุ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group shadow-xs rounded-pill">
                                            <!-- Preview Button -->
                                            <button type="button" class="btn btn-sm btn-light border-0" onclick="openPreviewModal('<?= htmlspecialchars($item['media_type']) ?>', '<?= htmlspecialchars(resolveSignageUrl($item['media_url'])) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')" title="ดูพรีวิวแบบเต็ม">
                                                <i class="fa-regular fa-eye text-purple"></i>
                                            </button>

                                            <?php if ($item['status'] === 'pending'): ?>
                                                <!-- Approve Button -->
                                                <button type="button" class="btn btn-sm btn-success px-3 fw-semibold border-0" onclick="openApproveModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars(addslashes($item['title'])) ?>', <?= $item['duration_seconds'] ?>, <?= $item['sort_order'] ?>, '<?= $item['media_type'] ?>', <?= (int)($item['enable_sound'] ?? 0) ?>)">
                                                    <i class="fa-solid fa-check me-1"></i> อนุมัติ
                                                </button>
                                                <!-- Reject Button -->
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3 border-0" onclick="openRejectModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                                                    <i class="fa-solid fa-xmark me-1"></i> ยกเลิก
                                                </button>
                                            <?php elseif ($item['status'] === 'approved'): ?>
                                                <!-- สามารถกดยกเลิกป้ายที่เคยอนุมัติไปแล้วได้ -->
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3 border-0" onclick="openRejectModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                                                    <i class="fa-solid fa-ban me-1"></i> สั่งยกเลิก
                                                </button>
                                            <?php elseif ($item['status'] === 'rejected'): ?>
                                                <!-- ดูเหตุผลที่ปฏิเสธ -->
                                                <button type="button" class="btn btn-sm btn-light border-0 px-3 text-secondary" onclick="alert('เหตุผลการยกเลิก: <?= htmlspecialchars(addslashes($item['reject_reason'] ?? 'ไม่ระบุ')) ?>')">
                                                    <i class="fa-solid fa-comment-dots me-1 text-purple"></i> เหตุผล
                                                </button>
                                            <?php endif; ?>
                                        </div>
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

<!-- 1. Modal อนุมัติป้าย (ปรับ Duration / Sort ได้) -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="item_id" id="approve_item_id">

            <div class="modal-header border-0 bg-success bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-success">
                    <i class="fa-solid fa-circle-check me-2"></i>ยืนยันการอนุมัติป้ายประชาสัมพันธ์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label text-muted small mb-1">หัวข้อประชาสัมพันธ์</label>
                    <div class="fw-bold fs-6 text-dark" id="approve_item_title"></div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold small text-dark">ระยะเวลาแสดงผล (วินาที)</label>
                        <input type="number" name="duration_seconds" id="approve_duration" class="form-control rounded-3" min="5" max="120" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small text-dark">ลำดับคิว (Sort Order)</label>
                        <input type="number" name="sort_order" id="approve_sort" class="form-control rounded-3" min="0" value="0">
                        <div class="extra-small text-muted">ตัวเลขน้อยเล่นก่อน</div>
                    </div>
                </div>

                <!-- YouTube Sound Setting -->
                <div class="p-3 bg-light rounded-3 border mb-2" id="approve_sound_container" style="display: none;">
                    <label class="form-label fw-bold small text-dark d-flex align-items-center justify-content-between mb-2">
                        <span><i class="fa-solid fa-volume-high text-purple me-1"></i> เสียงวิดีโอ (YouTube)</span>
                        <span class="badge bg-purple-subtle text-purple border">ความต้องการผู้ส่งคำขอ</span>
                    </label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="enable_sound" id="app_sound_mute" value="0">
                            <label class="form-check-label small" for="app_sound_mute"><i class="fa-solid fa-volume-xmark text-muted me-1"></i> ปิดเสียง (Mute)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="enable_sound" id="app_sound_on" value="1">
                            <label class="form-check-label small fw-bold text-success" for="app_sound_on"><i class="fa-solid fa-volume-high text-success me-1"></i> เปิดเสียง (Enable Sound)</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
                    <i class="fa-solid fa-check me-1"></i> ยืนยันอนุมัติขึ้นจอ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal ปฏิเสธ/ยกเลิกป้าย (บังคับระบุเหตุผล) -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="item_id" id="reject_item_id">

            <div class="modal-header border-0 bg-danger bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-circle-xmark me-2"></i>ยกเลิก / ไม่อนุมัติป้ายประชาสัมพันธ์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label text-muted small mb-1">หัวข้อประชาสัมพันธ์</label>
                    <div class="fw-bold fs-6 text-dark" id="reject_item_title"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">ระบุเหตุผลการยกเลิก/ไม่อนุมัติ <span class="text-danger">* (จำเป็นต้องระบุ)</span></label>
                    <textarea name="reject_reason" id="reject_reason" class="form-control rounded-3" rows="4" placeholder="เช่น สัดส่วนภาพไม่ได้ 16:9, ข้อความตัวเล็กเกินไปสำหรับจอ LED, มีเนื้อหาไม่ผ่านระเบียบวิทยาลัย" required></textarea>
                    <div class="form-text text-muted extra-small">เหตุผลนี้จะถูกส่งแจ้งกลับไปยังผู้ส่ง เพื่อให้ทำการแก้ไขต่อไป</div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ย้อนกลับ</button>
                <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                    <i class="fa-solid fa-trash-can me-1"></i> ยืนยันยกเลิกป้ายนี้
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal ดูพรีวิวสื่อ 16:9 แบบเต็มตา -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow overflow-hidden bg-dark text-white">
            <div class="modal-header border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="modal-title fw-bold text-warning text-truncate me-2" id="previewTitle">พรีวิวสื่อ</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-black d-flex align-items-center justify-content-center" style="min-height: 400px; max-height: 70vh;">
                <div id="previewContent" class="w-100 text-center"></div>
            </div>
        </div>
    </div>
</div>

<script>
    function openApproveModal(id, title, duration, sort, mediaType, enableSound) {
        document.getElementById('approve_item_id').value = id;
        document.getElementById('approve_item_title').textContent = title;
        document.getElementById('approve_duration').value = duration || 10;
        document.getElementById('approve_sort').value = sort || 0;

        const soundBox = document.getElementById('approve_sound_container');
        if (mediaType === 'youtube') {
            soundBox.style.display = 'block';
            if (enableSound == 1) {
                document.getElementById('app_sound_on').checked = true;
            } else {
                document.getElementById('app_sound_mute').checked = true;
            }
        } else {
            soundBox.style.display = 'none';
        }

        new bootstrap.Modal(document.getElementById('approveModal')).show();
    }

    function openRejectModal(id, title) {
        document.getElementById('reject_item_id').value = id;
        document.getElementById('reject_item_title').textContent = title;
        document.getElementById('reject_reason').value = '';
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    }

    function resolveSignageUrl(url) {
        if (!url) return '';
        url = url.trim();
        if (url.match(/^(https?:|\/\/|\/|\.\.\/\.\.\/|data:)/i)) {
            return url;
        }
        return '../../' + url.replace(/^\.?\/?/, '');
    }

    function openPreviewModal(type, url, title) {
        document.getElementById('previewTitle').textContent = title;
        const container = document.getElementById('previewContent');
        container.innerHTML = '';
        const resolvedUrl = resolveSignageUrl(url);

        if (type === 'image') {
            container.innerHTML = `<img src="${resolvedUrl}" class="img-fluid rounded-3 shadow" style="max-height: 65vh; object-fit: contain;" onerror="this.onerror=null; this.src='../../assets/img/placeholder-image.png'; this.alt='ไม่พบไฟล์ภาพ';">`;
        } else if (type === 'youtube') {
            let vid = '';
            const match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i);
            if (match) vid = match[1];
            container.innerHTML = `
                <div class="ratio ratio-16x9">
                    <iframe src="https://www.youtube.com/embed/${vid}?autoplay=1&mute=1" allowfullscreen></iframe>
                </div>
            `;
        } else if (type === 'canva') {
            container.innerHTML = `
                <div class="ratio ratio-16x9">
                    <iframe src="${resolvedUrl}" allowfullscreen></iframe>
                </div>
            `;
        }

        new bootstrap.Modal(document.getElementById('previewModal')).show();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
