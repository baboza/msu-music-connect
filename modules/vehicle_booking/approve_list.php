<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$current_user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles);
$is_supply = in_array('งานพัสดุ', $user_roles);
$is_head_office = in_array('หัวหน้าสำนักงาน', $user_roles) || $current_user_id === '34ee8171';
$is_deputy_admin = in_array('รองคณบดีฝ่ายบริหาร', $user_roles) || $current_user_id === '86580da6';

if (!$is_admin && !$is_supply && !$is_head_office && !$is_deputy_admin) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง: หน้านี้สำหรับงานพัสดุ หัวหน้าสำนักงาน รองคณบดีฝ่ายบริหาร และผู้ดูแลระบบเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$status_filter = $_GET['status'] ?? 'pending';
$action_msg = '';

// Handle POST actions (Approve / Reject / Restore)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($booking_id > 0) {
        $stmt_cur = $pdo->prepare("SELECT status FROM vehicle_bookings WHERE booking_id = ?");
        $stmt_cur->execute([$booking_id]);
        $curr_st = $stmt_cur->fetchColumn();

        if ($action === 'approve') {
            if ($curr_st === 'pending_head' && ($is_head_office || $is_admin || $is_supply)) {
                $stmt = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'pending_deputy',
                        head_comment = :comment,
                        head_approved_at = NOW(),
                        head_approved_by = :uid,
                        reject_reason = NULL,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt->execute([':comment' => $comment ?: 'เห็นควรจัดรถตามขอ (งานพัสดุ/หัวหน้าสำนักงานตรวจสอบความพร้อมแล้ว)', ':uid' => $current_user_id, ':id' => $booking_id]);
                $action_msg = 'approved_head';
            } elseif ($curr_st === 'pending_deputy' && ($is_deputy_admin || $is_admin || $is_supply)) {
                $stmt = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'approved',
                        deputy_comment = :comment,
                        deputy_approved_at = NOW(),
                        deputy_approved_by = :uid,
                        reject_reason = NULL,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt->execute([':comment' => $comment ?: 'อนุมัติการใช้รถยนต์ราชการ', ':uid' => $current_user_id, ':id' => $booking_id]);
                $action_msg = 'approved_deputy';
            } elseif ($curr_st === 'rejected' && ($is_admin || $is_supply || $is_deputy_admin || $is_head_office)) {
                // นำกลับมาใช้งาน (อนุมัติทันที)
                $stmt = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'approved',
                        deputy_comment = :comment,
                        deputy_approved_at = NOW(),
                        deputy_approved_by = :uid,
                        reject_reason = NULL,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt->execute([':comment' => $comment ?: 'นำกลับมาใช้งานและอนุมัติการเดินทาง (งานพัสดุ)', ':uid' => $current_user_id, ':id' => $booking_id]);
                $action_msg = 'restored_approved';
            }
        } elseif ($action === 'reject') {
            if ($is_admin || $is_supply || $is_head_office || $is_deputy_admin) {
                $reason = !empty($comment) ? $comment : 'ไม่ผ่านเกณฑ์การพิจารณาหรือติดภารกิจอื่น';
                $col_comment = ($curr_st === 'pending_head') ? 'head_comment' : 'deputy_comment';
                $col_time = ($curr_st === 'pending_head') ? 'head_approved_at' : 'deputy_approved_at';
                $col_by = ($curr_st === 'pending_head') ? 'head_approved_by' : 'deputy_approved_by';

                $stmt = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'rejected',
                        {$col_comment} = :comment,
                        {$col_time} = NOW(),
                        {$col_by} = :uid,
                        reject_reason = :reason,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt->execute([
                    ':comment' => $comment,
                    ':uid' => $current_user_id,
                    ':reason' => $reason,
                    ':id' => $booking_id
                ]);
                $action_msg = 'rejected';
            }
        } elseif ($action === 'restore_pending') {
            if ($is_admin || $is_supply || $is_head_office || $is_deputy_admin) {
                // ส่งกลับไปรอพิจารณาใหม่
                $stmt = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'pending_head',
                        reject_reason = NULL,
                        head_approved_at = NULL,
                        deputy_approved_at = NULL
                    WHERE booking_id = :id
                ");
                $stmt->execute([':id' => $booking_id]);
                $action_msg = 'restored_pending';
            }
        }
    }
}

// นับจำนวนตามสถานะ
$counts = [
    'pending' => $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status IN ('pending_head', 'pending_deputy')")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'approved'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'rejected'")->fetchColumn(),
    'all' => $pdo->query("SELECT COUNT(*) FROM vehicle_bookings")->fetchColumn()
];

// Query รายการตามแท็บ
$sql = "
    SELECT b.*, v.plate_number, v.model, v.brand, v.vehicle_type,
           u.prefix_name, u.first_name, u.last_name, u.position
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE 1=1
";
$params = [];

if ($status_filter === 'pending') {
    if ($is_head_office && !$is_admin && !$is_deputy_admin && !$is_supply) {
        $sql .= " AND b.status = 'pending_head' ";
    } elseif ($is_deputy_admin && !$is_admin && !$is_head_office && !$is_supply) {
        $sql .= " AND b.status = 'pending_deputy' ";
    } else {
        $sql .= " AND b.status IN ('pending_head', 'pending_deputy') ";
    }
} elseif ($status_filter === 'approved') {
    $sql .= " AND b.status = 'approved' ";
} elseif ($status_filter === 'rejected') {
    $sql .= " AND b.status = 'rejected' ";
}

$sql .= " ORDER BY (b.status IN ('pending_head', 'pending_deputy')) DESC, b.start_datetime DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Header Section -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้ารวมการจองรถ
                </a>
                <span class="badge bg-warning text-dark px-2 py-1 rounded-pill small">ศูนย์พิจารณาอนุมัติ</span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-stamp text-purple me-2"></i>ศูนย์พิจารณาอนุมัติคำขอใช้รถยนต์ราชการ
            </h3>
            <p class="text-muted small mb-0">
                สำหรับหัวหน้าสำนักงานเลขานุการ และรองคณบดีฝ่ายบริหาร ในการตรวจสอบความพร้อม พิจารณาเห็นชอบ และอนุมัติการเดินทาง
            </p>
        </div>
        <div>
            <a href="create.php" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> ยื่นคำขอใหม่
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($action_msg === 'approved_head'): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5"></i>
            <strong>หัวหน้าสำนักงานให้ความเห็นชอบเรียบร้อยแล้ว!</strong> คำขอถูกส่งต่อให้รองคณบดีฝ่ายบริหารพิจารณาเป็นลำดับถัดไป
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($action_msg === 'approved_deputy' || $action_msg === 'restored_approved'): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5"></i>
            <strong>อนุมัติการใช้รถยนต์ราชการสมบูรณ์แล้ว!</strong> ผู้ขอและพนักงานขับรถสามารถออกปฏิบัติภารกิจได้ตามกำหนดเวลา
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($action_msg === 'rejected'): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-xmark me-2 fs-5"></i>
            <strong>บันทึกผลการพิจารณา "ไม่อนุมัติ" เรียบร้อยแล้ว</strong> ได้บันทึกเหตุผลแจ้งให้ผู้ยื่นคำขอทราบ
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($action_msg === 'restored_pending'): ?>
        <div class="alert alert-purple alert-dismissible fade show rounded-4 shadow-sm mb-4" style="background:#f3e8ff; color:#6b21a8;">
            <i class="fa-solid fa-rotate-left me-2 fs-5"></i>
            <strong>ส่งคำขอกลับไปสถานะ "รอพิจารณาอนุมัติ" เรียบร้อยแล้ว!</strong> สามารถพิจารณาใหม่ได้
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Segmented Status Tabs -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4 p-2">
        <ul class="nav nav-pills nav-fill gap-2">
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'pending' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=pending">
                    <i class="fa-solid fa-hourglass-half me-1"></i> รอพิจารณาอนุมัติ
                    <span class="badge rounded-pill <?= $status_filter === 'pending' ? 'bg-white text-purple' : 'bg-danger text-white' ?> ms-1"><?= $counts['pending'] ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'approved' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=approved">
                    <i class="fa-solid fa-circle-check me-1"></i> อนุมัติแล้ว
                    <span class="badge rounded-pill bg-light text-dark ms-1"><?= $counts['approved'] ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'rejected' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=rejected">
                    <i class="fa-solid fa-circle-xmark me-1"></i> ไม่อนุมัติ / ยกเลิก
                    <span class="badge rounded-pill bg-light text-dark ms-1"><?= $counts['rejected'] ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'all' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=all">
                    <i class="fa-solid fa-layer-group me-1"></i> ทั้งหมด
                    <span class="badge rounded-pill bg-light text-dark ms-1"><?= $counts['all'] ?></span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Items List Table -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="card-body p-0">
            <?php if (empty($items)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-inbox text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ไม่มีรายการในหมวดนี้</h6>
                    <p class="text-muted small mb-0">ไม่มีคำขอใช้รถยนต์ราชการที่ตรงกับตัวกรองนี้</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted extra-small">
                                <th class="ps-4" style="width: 140px;">รหัสคำขอ</th>
                                <th>รถที่ขอใช้</th>
                                <th>ภารกิจ & ปลายทาง</th>
                                <th style="width: 190px;">วันเวลาเดินทาง</th>
                                <th>ผู้ขอใช้รถ</th>
                                <th style="width: 160px;" class="text-center">ขั้นตอน / สถานะ</th>
                                <th style="width: 250px;" class="text-end pe-4">การพิจารณา</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $b): 
                                $badge = getVehicleBookingStatusBadge($b['status']);
                                $user_fullname = trim(($b['prefix_name'] ?? '') . $b['first_name'] . ' ' . $b['last_name']);
                                $is_c2 = ($b['vehicle_type'] === 'cargo_instruments');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <a href="view.php?id=<?= $b['booking_id'] ?>" class="fw-bold text-purple text-decoration-none">
                                            <?= htmlspecialchars($b['booking_code']) ?>
                                        </a>
                                        <div class="extra-small text-muted"><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark small">
                                            <i class="fa-solid <?= $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple' ?> me-1"></i>
                                            <?= htmlspecialchars($b['model']) ?>
                                        </div>
                                        <div class="badge bg-light text-dark border extra-small mt-1">
                                            <?= htmlspecialchars($b['plate_number']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($b['title']) ?></div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($b['destination']) ?>
                                            • <?= $b['passenger_count'] ?> คน
                                            <?php if (!empty($b['instruments_detail'])): ?>
                                                <span class="badge bg-warning text-dark ms-1 extra-small"><i class="fa-solid fa-guitar me-1"></i>มีเครื่องดนตรี</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= formatThaiDT($b['start_datetime']) ?></div>
                                        <div class="extra-small text-muted">ถึง <?= formatThaiDT($b['end_datetime']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= htmlspecialchars($user_fullname) ?></div>
                                        <div class="extra-small text-muted"><?= htmlspecialchars($b['position'] ?? 'บุคลากร') ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badge['class'] ?> rounded-pill px-3 py-1 extra-small">
                                            <i class="<?= $badge['icon'] ?> me-1"></i><?= $badge['label'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group shadow-2xs rounded-pill">
                                            <!-- Preview / View Details -->
                                            <a href="view.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-light border-0" title="ดูรายละเอียดฉบับเต็ม">
                                                <i class="fa-regular fa-eye text-purple"></i>
                                            </a>

                                            <?php if (in_array($b['status'], ['pending_head', 'pending_deputy'])): ?>
                                                <!-- ปุ่มอนุมัติ -->
                                                <button type="button" class="btn btn-sm btn-success px-3 fw-semibold border-0" onclick="openActionModal('approve', <?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['model'])) ?>', '<?= $b['status'] ?>')">
                                                    <i class="fa-solid fa-check me-1"></i> อนุมัติ
                                                </button>
                                                <!-- ปุ่มไม่อนุมัติ -->
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3 border-0" onclick="openActionModal('reject', <?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['model'])) ?>', '<?= $b['status'] ?>')">
                                                    <i class="fa-solid fa-xmark me-1"></i> ไม่อนุมัติ
                                                </button>
                                            <?php elseif ($b['status'] === 'approved'): ?>
                                                <!-- ปุ่มสั่งยกเลิกป้ายที่อนุมัติไปแล้ว -->
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3 border-0" onclick="openActionModal('reject', <?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['model'])) ?>', '<?= $b['status'] ?>')">
                                                    <i class="fa-solid fa-ban me-1"></i> ยกเลิก
                                                </button>
                                            <?php elseif ($b['status'] === 'rejected'): ?>
                                                <!-- ปุ่มนำกลับมาใช้งานได้ (Re-activate) ตามคำขอของผู้ใช้! -->
                                                <button type="button" class="btn btn-sm btn-success px-3 fw-semibold border-0" onclick="openActionModal('restore', <?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['model'])) ?>', '<?= $b['status'] ?>')" title="นำกลับมาใช้งาน / อนุมัติให้ใช้รถ">
                                                    <i class="fa-solid fa-rotate-left me-1"></i> นำกลับมาใช้งาน
                                                </button>
                                                <!-- ส่งกลับไปรอพิจารณาใหม่ -->
                                                <form method="POST" class="d-inline" onsubmit="return confirm('ต้องการส่งคำขอนี้กลับไปที่แท็บ \'รอพิจารณาอนุมัติ\' ใช่หรือไม่?');">
                                                    <input type="hidden" name="action" value="restore_pending">
                                                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-purple px-2 border-0" title="ส่งกลับไปรอพิจารณาใหม่">
                                                        <i class="fa-solid fa-hourglass-half"></i>
                                                    </button>
                                                </form>
                                                <!-- ดูเหตุผลที่ปฏิเสธ -->
                                                <button type="button" class="btn btn-sm btn-light border-0 px-2 text-secondary" onclick="alert('เหตุผลการไม่อนุมัติ: <?= htmlspecialchars(addslashes($b['reject_reason'] ?? 'ไม่ระบุเหตุผล')) ?>')" title="ดูเหตุผล">
                                                    <i class="fa-solid fa-comment-dots text-purple"></i>
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

<!-- Action Modal (อนุมัติ / ไม่อนุมัติ / นำกลับมาใช้งาน) -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" id="modal_action" value="approve">
            <input type="hidden" name="booking_id" id="modal_booking_id">

            <div class="modal-header border-0 py-3" id="modal_header">
                <h5 class="modal-title fw-bold" id="modal_title">การพิจารณาคำขอ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label text-muted extra-small mb-1">หัวข้อภารกิจ / รถที่ขอใช้</label>
                    <div class="fw-bold fs-6 text-dark" id="modal_booking_title"></div>
                    <div class="extra-small text-muted" id="modal_vehicle_model"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark" id="modal_comment_label">ความเห็นประกอบการพิจารณา</label>
                    <textarea name="comment" id="modal_comment" rows="3" class="form-control rounded-3" placeholder="ระบุข้อความหรือเหตุผล..."></textarea>
                    <div class="form-text text-muted extra-small" id="modal_comment_help"></div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn fw-bold rounded-pill px-4 shadow-sm" id="modal_submit_btn">
                    ยืนยัน
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openActionModal(action, bookingId, title, model, currentStatus) {
    document.getElementById('modal_action').value = (action === 'restore') ? 'approve' : action;
    document.getElementById('modal_booking_id').value = bookingId;
    document.getElementById('modal_booking_title').textContent = title;
    document.getElementById('modal_vehicle_model').textContent = 'รถ: ' + model;

    const modalHeader = document.getElementById('modal_header');
    const modalTitle = document.getElementById('modal_title');
    const commentLabel = document.getElementById('modal_comment_label');
    const commentHelp = document.getElementById('modal_comment_help');
    const submitBtn = document.getElementById('modal_submit_btn');
    const commentInput = document.getElementById('modal_comment');
    commentInput.value = '';

    if (action === 'approve') {
        modalHeader.className = 'modal-header border-0 bg-success bg-opacity-10 py-3';
        modalTitle.className = 'modal-title fw-bold text-success';
        modalTitle.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>ยืนยันการอนุมัติ / ให้ความเห็นชอบ';
        commentLabel.textContent = 'ความเห็นประกอบการอนุมัติ (ถ้ามี)';
        commentInput.placeholder = 'เช่น เห็นควรอนุมัติให้ใช้รถยนต์ราชการได้ตามกำหนดเวลา';
        commentInput.required = false;
        commentHelp.textContent = 'คำขอจะถูกส่งต่อไปยังขั้นตอนถัดไป หรืออนุมัติการใช้รถสมบูรณ์';
        submitBtn.className = 'btn btn-success fw-bold rounded-pill px-4 shadow-sm';
        submitBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i> ยืนยันอนุมัติ';
    } else if (action === 'restore') {
        modalHeader.className = 'modal-header border-0 bg-success bg-opacity-10 py-3';
        modalTitle.className = 'modal-title fw-bold text-success';
        modalTitle.innerHTML = '<i class="fa-solid fa-rotate-left me-2"></i>ยืนยันนำคำขอใช้รถกลับมาใช้งาน';
        commentLabel.textContent = 'บันทึกการนำกลับมาใช้งาน (ถ้ามี)';
        commentInput.placeholder = 'เช่น แก้ไขข้อติดขัดเรียบร้อย อนุมัติให้ออกปฏิบัติภารกิจได้';
        commentInput.required = false;
        commentHelp.textContent = 'คำขอจะเปลี่ยนสถานะเป็น "อนุมัติแล้ว" และนำกลับมาใช้รถได้ทันที';
        submitBtn.className = 'btn btn-success fw-bold rounded-pill px-4 shadow-sm';
        submitBtn.innerHTML = '<i class="fa-solid fa-rotate-left me-1"></i> ยืนยันนำกลับมาใช้งาน';
    } else {
        modalHeader.className = 'modal-header border-0 bg-danger bg-opacity-10 py-3';
        modalTitle.className = 'modal-title fw-bold text-danger';
        modalTitle.innerHTML = '<i class="fa-solid fa-circle-xmark me-2"></i>ไม่อนุมัติ / ยกเลิกคำขอใช้รถ';
        commentLabel.innerHTML = 'เหตุผลการไม่อนุมัติ <span class="text-danger">* (จำเป็นต้องระบุ)</span>';
        commentInput.placeholder = 'เช่น รถติดภารกิจเร่งด่วนของวิทยาลัย, มีการจองซ้อนทับ, คนขับไม่พร้อม';
        commentInput.required = true;
        commentHelp.textContent = 'เหตุผลนี้จะถูกบันทึกและแสดงให้ผู้ยื่นคำขอทราบ';
        submitBtn.className = 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm';
        submitBtn.innerHTML = '<i class="fa-solid fa-xmark me-1"></i> ยืนยันไม่อนุมัติ';
    }

    new bootstrap.Modal(document.getElementById('actionModal')).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
