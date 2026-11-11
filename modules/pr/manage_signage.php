<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($pr_approver_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง'); window.location.href='index.php';</script>";
    exit();
}

$current_user_id = $_SESSION['user_id'];
$msg = '';
$err = '';

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

// Handle Actions (Save queue, Edit item, or Cancel/Reject item)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_queue'])) {
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $stmtUpdate = $pdo->prepare("UPDATE pr_signage_items SET sort_order = :sort, duration_seconds = :dur WHERE item_id = :id");
            foreach ($_POST['items'] as $id => $data) {
                $stmtUpdate->execute([
                    ':sort' => (int)($data['sort'] ?? 0),
                    ':dur' => max(5, (int)($data['dur'] ?? 10)),
                    ':id' => (int)$id
                ]);
            }
            $msg = 'บันทึกการจัดคิวและระยะเวลาเรียบร้อยแล้ว!';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_signage') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $media_type = $_POST['media_type'] ?? 'image';
        $duration_seconds = max(5, min(180, (int)($_POST['duration_seconds'] ?? 10)));
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        $target_screen = $_POST['target_screen'] ?? 'screen1_only';
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $media_url = trim($_POST['existing_media_url'] ?? '');

        if (empty($title)) {
            $err = 'กรุณาระบุชื่อเรื่อง/หัวข้อประชาสัมพันธ์';
        } else {
            if ($media_type === 'image') {
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $newName = 'pr_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                        $uploadDir = __DIR__ . '/../../uploads/pr/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $newName)) {
                            $media_url = 'uploads/pr/' . $newName;
                        }
                    }
                } elseif (!empty($_POST['image_url_input'])) {
                    $media_url = trim($_POST['image_url_input']);
                }
            } elseif ($media_type === 'canva') {
                $canva_input = trim($_POST['canva_url'] ?? '');
                if (!empty($canva_input)) {
                    $media_url = $canva_input;
                }
            } elseif ($media_type === 'youtube') {
                $youtube_input = trim($_POST['youtube_url'] ?? '');
                if (!empty($youtube_input)) {
                    $media_url = $youtube_input;
                }
            }

            $enable_sound = isset($_POST['enable_sound']) && $_POST['enable_sound'] === '1' ? 1 : 0;

            if ($itemId > 0 && !empty($media_url)) {
                $stmt = $pdo->prepare("
                    UPDATE pr_signage_items SET 
                        title = :title,
                        media_type = :type,
                        media_url = :url,
                        duration_seconds = :dur,
                        enable_sound = :sound,
                        start_date = :start,
                        end_date = :end,
                        target_screen = :target,
                        sort_order = :sort
                    WHERE item_id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':type' => $media_type,
                    ':url' => $media_url,
                    ':dur' => $duration_seconds,
                    ':sound' => $enable_sound,
                    ':start' => $start_date,
                    ':end' => $end_date,
                    ':target' => $target_screen,
                    ':sort' => $sort_order,
                    ':id' => $itemId
                ]);
                $msg = 'แก้ไขข้อมูลป้ายประชาสัมพันธ์เรียบร้อยแล้ว!';
            } else {
                $err = 'กรุณาระบุที่อยู่ไฟล์ภาพ หรือลิงก์สื่อประชาสัมพันธ์';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'cancel_signage') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        if (empty($reason)) {
            $reason = 'ผู้ดูแลระบบยกเลิกการแสดงผลบนจอประชาสัมพันธ์';
        }

        if ($itemId > 0) {
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
            $msg = 'ยกเลิกการแสดงผลป้ายนี้เรียบร้อยแล้ว (นำออกจากจอ LED ทันที)!';
        }
    }
}

// Fetch all approved items
$stmt = $pdo->query("
    SELECT * FROM pr_signage_items 
    WHERE status = 'approved'
    ORDER BY sort_order ASC, item_id ASC
");
$slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Header Section (Modern Clean White) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้าหลัก PR
                </a>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-sliders text-purple me-2"></i>จัดการคิวและลำดับการเล่นวน (Screen 1 Playlist)
            </h3>
            <p class="text-muted small mb-0 ps-1">
                ปรับแต่งลำดับการเล่น (Sort Order) แก้ไขข้อมูล และความยาวในการแสดงภาพนิ่ง/วิดีโอ (วินาที) บนจอ LED
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="public_screen1.php" target="_blank" class="btn btn-purple rounded-pill px-4 shadow-sm">
                <i class="fa-solid fa-circle-play me-1"></i> เปิดดูจอที่ 1 สด
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert badge-emerald-soft alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5 text-success"></i> <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert badge-rose-soft alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-exclamation me-2 fs-5 text-danger"></i> <?= htmlspecialchars($err) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="save_queue" value="1">

        <div class="card card-modern overflow-hidden mb-4">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                        <i class="fa-solid fa-list-ol text-purple"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">
                        รายการสไลด์ในคิวการเล่น (<?= count($slides) ?> รายการ)
                    </h5>
                </div>
                <button type="submit" class="btn btn-purple rounded-pill px-4">
                    <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกลำดับคิวทั้งหมด
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($slides)): ?>
                    <div class="text-center py-5">
                        <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                            <i class="fa-solid fa-photo-film text-purple fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark">ยังไม่มีป้ายที่อนุมัติในคิว</h6>
                        <p class="text-muted small">เมื่อมีป้ายได้รับการอนุมัติ ป้ายจะปรากฏในรายการนี้โดยอัตโนมัติ</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="text-muted small">
                                    <th style="width: 95px;" class="text-center">ลำดับคิว</th>
                                    <th style="width: 100px;">พรีวิว</th>
                                    <th>หัวข้อป้ายประชาสัมพันธ์</th>
                                    <th style="width: 140px;">ประเภทสื่อ</th>
                                    <th style="width: 160px;">เวลาเล่น (วินาที)</th>
                                    <th style="width: 200px;">ช่วงเวลาแสดงผล</th>
                                    <th style="width: 130px;" class="text-center">สถานะสด</th>
                                    <th style="width: 130px;" class="text-end pe-4">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($slides as $idx => $s): 
                                    $is_live = ($s['start_date'] <= date('Y-m-d') && $s['end_date'] >= date('Y-m-d'));
                                ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="number" name="items[<?= $s['item_id'] ?>][sort]" value="<?= $s['sort_order'] ?>" class="form-control form-control-sm text-center fw-bold rounded-3 mx-auto border-purple-subtle" style="width: 65px;" min="0">
                                        </td>
                                        <td>
                                            <?php if ($s['media_type'] === 'image'): ?>
                                                <img src="<?= htmlspecialchars(resolveSignageUrl($s['media_url'])) ?>" alt="Thumb" class="rounded shadow-xs border cursor-pointer" style="width: 75px; height: 42px; object-fit: cover;" onclick="openPreviewModal('<?= htmlspecialchars($s['media_type']) ?>', '<?= htmlspecialchars(resolveSignageUrl($s['media_url'])) ?>', '<?= htmlspecialchars(addslashes($s['title'])) ?>')">
                                            <?php elseif ($s['media_type'] === 'youtube'): ?>
                                                <div class="rounded bg-rose-soft text-danger d-flex align-items-center justify-content-center cursor-pointer" style="width: 75px; height: 42px;" onclick="openPreviewModal('youtube', '<?= htmlspecialchars($s['media_url']) ?>', '<?= htmlspecialchars(addslashes($s['title'])) ?>')">
                                                    <i class="fa-brands fa-youtube fs-5"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="rounded bg-purple-subtle text-purple d-flex align-items-center justify-content-center cursor-pointer" style="width: 75px; height: 42px;" onclick="openPreviewModal('canva', '<?= htmlspecialchars($s['media_url']) ?>', '<?= htmlspecialchars(addslashes($s['title'])) ?>')">
                                                    <i class="fa-solid fa-palette fs-6"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($s['title']) ?></div>
                                            <small class="text-muted">ID: #<?= $s['item_id'] ?> • ผู้ส่ง: <?= htmlspecialchars($s['user_id']) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($s['media_type'] === 'image'): ?>
                                                <span class="badge bg-light text-dark border"><i class="fa-solid fa-image text-success me-1"></i> ภาพ 16:9</span>
                                            <?php elseif ($s['media_type'] === 'canva'): ?>
                                                <span class="badge badge-purple-soft"><i class="fa-solid fa-palette text-purple me-1"></i> Canva</span>
                                            <?php else: ?>
                                                <span class="badge badge-rose-soft"><i class="fa-brands fa-youtube text-danger me-1"></i> YouTube</span>
                                                <?php if (!empty($s['enable_sound'])): ?>
                                                    <div class="mt-1"><span class="badge bg-success-subtle text-success border border-success extra-small"><i class="fa-solid fa-volume-high me-1"></i>เปิดเสียง</span></div>
                                                <?php else: ?>
                                                    <div class="mt-1"><span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-volume-xmark me-1"></i>ปิดเสียง</span></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm" style="width: 130px;">
                                                <input type="number" name="items[<?= $s['item_id'] ?>][dur]" value="<?= $s['duration_seconds'] ?>" class="form-control rounded-start-3 text-center fw-bold" min="5" max="180">
                                                <span class="input-group-text rounded-end-3 bg-white text-muted">วินาที</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark">
                                                <?= date('d/m/Y', strtotime($s['start_date'])) ?> - <?= date('d/m/Y', strtotime($s['end_date'])) ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($is_live): ?>
                                                <span class="badge badge-emerald-soft badge-pill-modern"><i class="fa-solid fa-circle-play me-1 text-success"></i> กำลังเล่นวน</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted rounded-pill px-3 py-1">รอช่วงเวลา</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group shadow-xs rounded-pill">
                                                <!-- ปุ่มแก้ไข (Edit) -->
                                                <button type="button" class="btn btn-sm btn-outline-purple border-0" onclick='openEditModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="แก้ไขข้อมูลป้ายนี้">
                                                    <i class="fa-solid fa-pencil"></i>
                                                </button>
                                                <!-- ปุ่มพรีวิว (Preview) -->
                                                <button type="button" class="btn btn-sm btn-light border-0" onclick="openPreviewModal(<?= json_encode($s['media_type']) ?>, <?= json_encode(resolveSignageUrl($s['media_url'])) ?>, <?= json_encode($s['title'], JSON_UNESCAPED_UNICODE) ?>)" title="ดูพรีวิว">
                                                    <i class="fa-regular fa-eye text-purple"></i>
                                                </button>
                                                <!-- ปุ่มยกเลิก (Cancel) -->
                                                <button type="button" class="btn btn-sm btn-outline-danger border-0 btn-cancel-signage" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#cancelSignageModal" 
                                                    data-id="<?= (int)$s['item_id'] ?>" 
                                                    data-title="<?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                    onclick="openCancelModal(<?= (int)$s['item_id'] ?>, <?= json_encode($s['title'], JSON_UNESCAPED_UNICODE) ?>)" 
                                                    title="ยกเลิกการแสดงผลป้ายนี้">
                                                    <i class="fa-solid fa-ban"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($slides)): ?>
                <div class="card-footer bg-light py-3 px-4 border-0 text-end">
                    <button type="submit" class="btn btn-purple rounded-pill px-5">
                        <i class="fa-solid fa-check-double me-1"></i> บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>

</main>

<!-- Modal แก้ไขข้อมูลป้ายประชาสัมพันธ์ (Edit Signage Modal) -->
<div class="modal fade" id="editSignageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="edit_signage">
            <input type="hidden" name="item_id" id="edit_item_id" value="0">
            <input type="hidden" name="existing_media_url" id="edit_existing_media_url" value="">

            <div class="modal-header border-0 bg-purple-subtle py-3">
                <h5 class="modal-title fw-bold text-dark" id="editModalTitle">
                    <i class="fa-solid fa-pen-to-square text-purple me-2"></i>แก้ไขป้ายประชาสัมพันธ์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                <!-- 1. Title -->
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">หัวข้อประชาสัมพันธ์ <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="edit_title" class="form-control rounded-3" required placeholder="เช่น ขอเชิญชมคอนเสิร์ต...">
                </div>

                <!-- 2. Media Type Selector -->
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">ประเภทสื่อ</label>
                        <select name="media_type" id="edit_media_type" class="form-select rounded-3" onchange="toggleMediaTypeInputs(this.value)">
                            <option value="image">รูปภาพ 16:9 (Image)</option>
                            <option value="canva">ลิงก์ Canva</option>
                            <option value="youtube">วิดีโอ YouTube</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">ระยะเวลาแสดงผล (วินาที)</label>
                        <input type="number" name="duration_seconds" id="edit_duration" class="form-control rounded-3" min="5" max="180" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">ลำดับคิว (Sort Order)</label>
                        <input type="number" name="sort_order" id="edit_sort_order" class="form-control rounded-3" min="0" value="0">
                    </div>
                </div>

                <!-- 3. Dynamic Media Input Section -->
                <div class="p-3 bg-light rounded-4 border mb-3">
                    <!-- Image Input -->
                    <div id="edit_section_image">
                        <label class="form-label fw-bold text-dark">
                            <i class="fa-solid fa-image text-purple me-1"></i> ไฟล์รูปภาพ (16:9)
                        </label>
                        <div id="edit_image_current_preview" class="mb-2 p-2 bg-white rounded-3 border text-center d-none">
                            <img id="edit_current_img" src="" alt="Current Image" class="img-fluid rounded-2 shadow-xs" style="max-height: 140px; object-fit: contain;">
                            <div class="extra-small text-muted mt-1"><i class="fa-solid fa-circle-check text-success me-1"></i>รูปภาพปัจจุบันในระบบ</div>
                        </div>
                        <input type="file" name="image_file" class="form-control rounded-3 mb-2 bg-white" accept="image/*" onchange="previewEditNewImage(this)">
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" name="image_url_input" id="edit_image_url_input" class="form-control form-control-sm rounded-3 bg-white" placeholder="หรือพาธ/URL รูปภาพเดิม...">
                            <span class="text-muted extra-small text-nowrap">หากไม่อัปโหลดใหม่จะใช้ภาพเดิม</span>
                        </div>
                    </div>

                    <!-- Canva Input -->
                    <div id="edit_section_canva" class="d-none">
                        <label class="form-label fw-bold text-dark">
                            <i class="fa-solid fa-palette text-purple me-1"></i> ลิงก์ Canva (Public View/Embed Link)
                        </label>
                        <input type="url" name="canva_url" id="edit_canva_url" class="form-control rounded-3 bg-white" placeholder="https://www.canva.com/design/...">
                    </div>

                    <!-- YouTube Input -->
                    <div id="edit_section_youtube" class="d-none">
                        <label class="form-label fw-bold text-dark">
                            <i class="fa-brands fa-youtube text-danger me-1"></i> ลิงก์วิดีโอ YouTube
                        </label>
                        <input type="url" name="youtube_url" id="edit_youtube_url" class="form-control rounded-3 bg-white mb-2" placeholder="https://www.youtube.com/watch?v=...">
                        <div class="p-2 bg-white rounded-3 border">
                            <label class="form-label fw-bold small text-dark d-flex align-items-center justify-content-between mb-1">
                                <span><i class="fa-solid fa-volume-high text-purple me-1"></i> เสียงวิดีโอบนจอ LED</span>
                            </label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="enable_sound" id="edit_sound_mute" value="0">
                                    <label class="form-check-label small" for="edit_sound_mute"><i class="fa-solid fa-volume-xmark text-muted me-1"></i> ปิดเสียง</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="enable_sound" id="edit_sound_on" value="1">
                                    <label class="form-check-label small fw-bold text-success" for="edit_sound_on"><i class="fa-solid fa-volume-high text-success me-1"></i> เปิดเสียง</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Schedule & Target Screen -->
                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">วันที่เริ่มต้นแสดงผล</label>
                        <input type="date" name="start_date" id="edit_start_date" class="form-control rounded-3" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">วันสิ้นสุดการแสดงผล</label>
                        <input type="date" name="end_date" id="edit_end_date" class="form-control rounded-3" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">เป้าหมายจอ</label>
                        <select name="target_screen" id="edit_target_screen" class="form-select rounded-3">
                            <option value="screen1_only">จอที่ 1 เท่านั้น</option>
                            <option value="both">ทั้ง 2 จอ</option>
                        </select>
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4 text-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-5 shadow-sm">
                    <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกการแก้ไข
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal ยกเลิกการแสดงผลป้าย (Cancel Signage Modal) -->
<div class="modal fade" id="cancelSignageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="cancel_signage">
            <input type="hidden" name="item_id" id="cancel_item_id" value="0">

            <div class="modal-header border-0 bg-danger bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-circle-xmark me-2"></i>ยกเลิกการแสดงผลป้ายประชาสัมพันธ์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label text-muted small mb-1">หัวข้อประชาสัมพันธ์</label>
                    <div class="fw-bold fs-6 text-dark" id="cancel_item_title"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">ระบุเหตุผลการยกเลิก / ถอดป้ายออก <span class="text-danger">*</span></label>
                    <textarea name="reject_reason" id="cancel_reason" class="form-control rounded-3" rows="3" placeholder="เช่น หมดความจำเป็นในการแสดงผล หรือข้อมูลมีการเปลี่ยนแปลง" required></textarea>
                    <div class="form-text text-muted extra-small">เมื่อกดยกเลิก ป้ายนี้จะถูกนำออกจากคิวเล่นวนของจอ LED ทันที และบันทึกเหตุผลให้ผู้ส่งทราบ</div>
                </div>
            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4 text-secondary" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                    <i class="fa-solid fa-ban me-1"></i> ยืนยันยกเลิกป้าย
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal ดูพรีวิวสื่อ 16:9 แบบเต็มตา -->
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
    // Helper JS: ปรับ URL สื่อให้แสดงผลได้ถูกต้อง
    function resolveSignageUrl(url) {
        if (!url) return '';
        url = url.trim();
        if (url.match(/^(https?:|\/\/|\/|\.\.\/\.\.\/|data:)/i)) {
            return url;
        }
        return '../../' + url.replace(/^\.?\/?/, '');
    }

    function toggleMediaTypeInputs(type) {
        document.getElementById('edit_section_image').classList.toggle('d-none', type !== 'image');
        document.getElementById('edit_section_canva').classList.toggle('d-none', type !== 'canva');
        document.getElementById('edit_section_youtube').classList.toggle('d-none', type !== 'youtube');
    }

    function previewEditNewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewBox = document.getElementById('edit_image_current_preview');
                const previewImg = document.getElementById('edit_current_img');
                if (previewBox && previewImg) {
                    previewImg.src = e.target.result;
                    previewBox.classList.remove('d-none');
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function openEditModal(item) {
        document.getElementById('editModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-purple me-2"></i>แก้ไขป้ายประชาสัมพันธ์ #' + item.item_id;
        document.getElementById('edit_item_id').value = item.item_id;
        document.getElementById('edit_title').value = item.title || '';
        document.getElementById('edit_media_type').value = item.media_type;
        document.getElementById('edit_duration').value = item.duration_seconds || 10;
        document.getElementById('edit_sort_order').value = item.sort_order || 0;
        document.getElementById('edit_start_date').value = item.start_date || '';
        document.getElementById('edit_end_date').value = item.end_date || '';
        document.getElementById('edit_target_screen').value = item.target_screen || 'screen1_only';
        document.getElementById('edit_existing_media_url').value = item.media_url || '';

        // Reset & Populate media inputs
        document.getElementById('edit_image_url_input').value = (item.media_type === 'image') ? item.media_url : '';
        document.getElementById('edit_canva_url').value = (item.media_type === 'canva') ? item.media_url : '';
        document.getElementById('edit_youtube_url').value = (item.media_type === 'youtube') ? item.media_url : '';

        // แสดงรูปภาพเดิม (ถ้ามี)
        const previewBox = document.getElementById('edit_image_current_preview');
        const previewImg = document.getElementById('edit_current_img');
        if (item.media_type === 'image' && item.media_url) {
            if (previewBox && previewImg) {
                previewImg.src = resolveSignageUrl(item.media_url);
                previewBox.classList.remove('d-none');
            }
        } else {
            if (previewBox) previewBox.classList.add('d-none');
        }

        if (document.getElementById('edit_sound_on')) {
            const isSound = (item.enable_sound == 1);
            document.getElementById('edit_sound_on').checked = isSound;
            document.getElementById('edit_sound_mute').checked = !isSound;
        }

        toggleMediaTypeInputs(item.media_type);

        const modalEl = document.getElementById('editSignageModal');
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else if (window.$) {
            $('#editSignageModal').modal('show');
        }
    }

    function openCancelModal(id, title) {
        document.getElementById('cancel_item_id').value = id;
        document.getElementById('cancel_item_title').textContent = title;
        document.getElementById('cancel_reason').value = '';
        const modalEl = document.getElementById('cancelSignageModal');
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else if (window.$) {
            $('#cancelSignageModal').modal('show');
        }
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

        const modalEl = document.getElementById('previewModal');
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else if (window.$) {
            $('#previewModal').modal('show');
        }
    }

    // Event listener for cancel modal trigger via data-bs-toggle
    document.addEventListener('DOMContentLoaded', function() {
        const cancelModalEl = document.getElementById('cancelSignageModal');
        if (cancelModalEl) {
            cancelModalEl.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                if (button && button.hasAttribute('data-id')) {
                    document.getElementById('cancel_item_id').value = button.getAttribute('data-id') || 0;
                    document.getElementById('cancel_item_title').textContent = button.getAttribute('data-title') || '';
                    document.getElementById('cancel_reason').value = '';
                }
            });
        }
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
