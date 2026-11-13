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
$can_manage_all = $is_admin || $is_supply;

$booking_id = (int)($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    header("Location: index.php");
    exit();
}

// ดึงข้อมูลคำขอใช้รถแบบเต็ม
$stmt = $pdo->prepare("
    SELECT b.*,
           v.plate_number, v.model, v.brand, v.year_made, v.vehicle_type, v.seating_capacity, v.fuel_type, v.current_odometer,
           u.prefix_name AS req_prefix, u.first_name AS req_first, u.last_name AS req_last, u.position AS req_pos, u.email AS req_email, u.phone AS req_phone, u.signature_file AS req_sig,
           head.prefix_name AS head_prefix, head.first_name AS head_first, head.last_name AS head_last, head.signature_file AS head_sig_file,
           dep.prefix_name AS dep_prefix, dep.first_name AS dep_first, dep.last_name AS dep_last, dep.signature_file AS dep_sig_file
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN users head ON b.head_approved_by = head.user_id
    LEFT JOIN users dep ON b.deputy_approved_by = dep.user_id
    WHERE b.booking_id = ?
");
$stmt->execute([$booking_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    echo "<script>alert('ไม่พบข้อมูลคำขอใช้รถนี้'); window.location.href='index.php';</script>";
    exit();
}

$is_owner = ($b['user_id'] === $current_user_id);
$cur_status = $b['status'];

// วิเคราะห์สิทธิ์การพิจารณาในขั้นปัจจุบัน
$can_approve = false;
$step_role_title = '';
$next_status = '';

if ($cur_status === 'pending_head' && ($is_head_office || $is_admin || $is_supply)) {
    $can_approve = true;
    $step_role_title = $is_supply ? 'งานพัสดุ / หัวหน้าสำนักงาน' : 'หัวหน้าสำนักงานเลขานุการ';
    $next_status = 'pending_deputy';
} elseif ($cur_status === 'pending_deputy' && ($is_deputy_admin || $is_admin || $is_supply)) {
    $can_approve = true;
    $step_role_title = $is_supply ? 'งานพัสดุ / รองคณบดีฝ่ายบริหาร' : 'รองคณบดีฝ่ายบริหาร';
    $next_status = 'approved';
}

// จัดการบันทึกการพิจารณา และการจัดการจัดรถ (POST)
$msg = '';
$msg_type = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $comment = trim($_POST['comment'] ?? '');

    if ($action === 'manage_dispatch' && $can_manage_all) {
        $new_veh_id = (int)($_POST['vehicle_id'] ?? 0);
        $new_driver_type = $_POST['driver_type'] ?? 'driver_assigned';
        $new_driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
        $new_driver_name = trim($_POST['driver_name'] ?? '');
        $new_status = $_POST['status'] ?? $cur_status;

        $stmt_disp = $pdo->prepare("UPDATE vehicle_bookings SET vehicle_id = ?, driver_type = ?, driver_id = ?, driver_name = ?, status = ?, updated_at = NOW() WHERE booking_id = ?");
        $stmt_disp->execute([$new_veh_id, $new_driver_type, $new_driver_id, $new_driver_name, $new_status, $booking_id]);
        $_SESSION['msg'] = 'ปรับปรุงการจัดรถ คนขับ และสถานะคำขอเรียบร้อยแล้ว (งานพัสดุ)';
        $_SESSION['msg_type'] = 'success';
        header("Location: view.php?id=" . $booking_id);
        exit();
    } elseif ($action === 'delete_booking' && $can_manage_all) {
        $stmt_del = $pdo->prepare("DELETE FROM vehicle_bookings WHERE booking_id = ?");
        $stmt_del->execute([$booking_id]);
        $_SESSION['msg'] = 'ลบคำขอใช้รถเรียบร้อยแล้ว';
        $_SESSION['msg_type'] = 'warning';
        header("Location: index.php");
        exit();
    } elseif ($action === 'restore_approved' && ($is_admin || $is_supply || $is_deputy_admin || $is_head_office)) {
        $stmt_rst = $pdo->prepare("
            UPDATE vehicle_bookings 
            SET status = 'approved',
                deputy_comment = :comment,
                deputy_approved_at = NOW(),
                deputy_approved_by = :uid,
                reject_reason = NULL,
                updated_at = NOW()
            WHERE booking_id = :id
        ");
        $stmt_rst->execute([
            ':comment' => $comment ?: 'นำกลับมาใช้งานและอนุมัติการเดินทาง (งานพัสดุ)',
            ':uid' => $current_user_id,
            ':id' => $booking_id
        ]);
        $_SESSION['msg'] = 'นำคำขอกลับมาใช้งานและอนุมัติเรียบร้อยแล้ว';
        $_SESSION['msg_type'] = 'success';
        header("Location: view.php?id=" . $booking_id);
        exit();
    } elseif ($action === 'cancel_by_user' && ($is_owner || $can_manage_all) && in_array($cur_status, ['pending_head', 'pending_deputy', 'approved'])) {
        $stmt_cancel = $pdo->prepare("UPDATE vehicle_bookings SET status = 'cancelled', reject_reason = :reason, updated_at = NOW() WHERE booking_id = :id");
        $stmt_cancel->execute([
            ':reason' => $can_manage_all && !$is_owner ? 'งานพัสดุ/ผู้ดูแลระบบยกเลิกคำขอ' : 'ผู้ขอยกเลิกคำขอเอง',
            ':id' => $booking_id
        ]);
        $_SESSION['msg'] = 'ยกเลิกคำขอใช้รถเรียบร้อยแล้ว';
        $_SESSION['msg_type'] = 'warning';
        header("Location: view.php?id=" . $booking_id);
        exit();
    } elseif ($can_approve) {
        if ($action === 'approve') {
            if ($cur_status === 'pending_head') {
                $stmt_up = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'pending_deputy',
                        head_comment = :comment,
                        head_approved_at = NOW(),
                        head_approved_by = :uid,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt_up->execute([':comment' => $comment ?: 'เห็นชอบการขอใช้รถ (งานพัสดุ/หัวหน้าสำนักงาน)', ':uid' => $current_user_id, ':id' => $booking_id]);
                $_SESSION['msg'] = 'บันทึกความเห็นชอบเรียบร้อยแล้ว ส่งต่อให้รองคณบดีฝ่ายบริหารพิจารณา';
                $_SESSION['msg_type'] = 'success';
            } elseif ($cur_status === 'pending_deputy') {
                $stmt_up = $pdo->prepare("
                    UPDATE vehicle_bookings 
                    SET status = 'approved',
                        deputy_comment = :comment,
                        deputy_approved_at = NOW(),
                        deputy_approved_by = :uid,
                        updated_at = NOW()
                    WHERE booking_id = :id
                ");
                $stmt_up->execute([':comment' => $comment ?: 'อนุมัติการใช้รถยนต์ราชการ', ':uid' => $current_user_id, ':id' => $booking_id]);
                $_SESSION['msg'] = 'อนุมัติการใช้รถยนต์ราชการสมบูรณ์แล้ว ผู้ขอสามารถนำรถออกปฏิบัติภารกิจได้ตามกำหนดเวลา';
                $_SESSION['msg_type'] = 'success';
            }
            header("Location: view.php?id=" . $booking_id);
            exit();
        } elseif ($action === 'reject') {
            $reason = !empty($comment) ? $comment : 'ไม่ผ่านเกณฑ์การพิจารณาหรือติดภารกิจเร่งด่วน';
            $col_comment = ($cur_status === 'pending_head') ? 'head_comment' : 'deputy_comment';
            $col_time = ($cur_status === 'pending_head') ? 'head_approved_at' : 'deputy_approved_at';
            $col_by = ($cur_status === 'pending_head') ? 'head_approved_by' : 'deputy_approved_by';

            $stmt_up = $pdo->prepare("
                UPDATE vehicle_bookings 
                SET status = 'rejected',
                    {$col_comment} = :comment,
                    {$col_time} = NOW(),
                    {$col_by} = :uid,
                    reject_reason = :reason,
                    updated_at = NOW()
                WHERE booking_id = :id
            ");
            $stmt_up->execute([
                ':comment' => $comment,
                ':uid' => $current_user_id,
                ':reason' => $reason,
                ':id' => $booking_id
            ]);
            $_SESSION['msg'] = 'บันทึกผลการพิจารณา "ไม่อนุมัติ" คำขอใช้รถเรียบร้อยแล้ว';
            $_SESSION['msg_type'] = 'danger';
            header("Location: view.php?id=" . $booking_id);
            exit();
        }
    }
}

// เตรียมข้อมูล Workflow ลายเซ็น 3 ขั้นตอน
// 1. ผู้ขอ
$req_name = trim(($b['req_prefix'] ?? '') . $b['req_first'] . ' ' . $b['req_last']);
$req_sig = $b['req_sig'] ?? null;

// 2. หัวหน้าสำนักงาน
$head_name = !empty($b['head_first']) 
    ? trim(($b['head_prefix'] ?? '') . $b['head_first'] . ' ' . $b['head_last'])
    : 'นางแจ่มจันทร์ หลูปรีชาเศรษฐ';
$head_sig = !empty($b['head_sig_file']) ? $b['head_sig_file'] : 'sig_34ee8171_1774328264.png';

// 3. รองคณบดีฝ่ายบริหาร
$dep_name = !empty($b['dep_first']) 
    ? trim(($b['dep_prefix'] ?? '') . $b['dep_first'] . ' ' . $b['dep_last'])
    : 'ดร.วัชระ หอมหวล';
$dep_sig = !empty($b['dep_sig_file']) ? $b['dep_sig_file'] : 'sig_86580da6_1772018273.png';

// ตรวจสอบ log การเดินทางที่เคยบันทึกไว้ (ถ้ามี)
$stmt_log = $pdo->prepare("SELECT * FROM vehicle_trip_logs WHERE booking_id = ?");
$stmt_log->execute([$booking_id]);
$trip_log = $stmt_log->fetch(PDO::FETCH_ASSOC);

// ตรวจสอบผลการตรวจสภาพรถ (ถ้ามี)
$stmt_insp = $pdo->prepare("SELECT * FROM vehicle_inspections WHERE booking_id = ? ORDER BY inspection_id DESC");
$stmt_insp->execute([$booking_id]);
$inspections = $stmt_insp->fetchAll(PDO::FETCH_ASSOC);

$status_info = getVehicleBookingStatusBadge($b['status']);

// ดึงข้อมูลรถและคนขับสำหรับใช้ใน Dispatch Modal (งานพัสดุ)
$all_vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$stmt_drivers = $pdo->query("
    SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, u.phone 
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    WHERE r.role_name = 'พนักงานขับรถ' OR r.role_id = 34
    ORDER BY u.first_name ASC
");
$all_drivers = $stmt_drivers->fetchAll(PDO::FETCH_ASSOC);
$is_c2 = ($b['vehicle_type'] === 'cargo_instruments');

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
.status-stamp {
    position: absolute;
    right: 20px;
    top: 20px;
    border: 3px solid;
    padding: 6px 16px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 1.1rem;
    text-transform: uppercase;
    transform: rotate(-8deg);
    opacity: 0.85;
    pointer-events: none;
    z-index: 5;
}
.stamp-approved {
    color: #10b981;
    border-color: #10b981;
}
.stamp-pending {
    color: #f59e0b;
    border-color: #f59e0b;
}
.stamp-rejected {
    color: #ef4444;
    border-color: #ef4444;
}
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Top Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="text-decoration-none text-muted small">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้ารวมการจองรถ
                </a>
                <span class="text-muted">/</span>
                <span class="badge bg-purple text-white rounded-pill px-2 py-1 extra-small"><?= htmlspecialchars($b['booking_code']) ?></span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                คำขอใช้รถยนต์ราชการ: <span class="text-purple"><?= htmlspecialchars($b['title']) ?></span>
            </h3>
            <div class="text-muted small">
                ยื่นเมื่อ: <strong><?= formatThaiDT($b['created_at']) ?></strong> • ปลายทาง: <strong><?= htmlspecialchars($b['destination']) ?></strong>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_manage_all): ?>
                <button type="button" class="btn btn-purple rounded-pill px-3 fw-semibold shadow-2xs" data-bs-toggle="modal" data-bs-target="#dispatchModal">
                    <i class="fa-solid fa-sliders me-1"></i> จัดการคำขอ / สลับรถ (งานพัสดุ)
                </button>
            <?php endif; ?>
            <a href="print.php?id=<?= $b['booking_id'] ?>" target="_blank" class="btn btn-outline-purple rounded-pill px-3 fw-semibold shadow-2xs">
                <i class="fa-solid fa-print me-1"></i> พิมพ์ใบขอใช้รถ
            </a>
            <?php if (in_array($b['status'], ['approved', 'completed'])): ?>
                <a href="trip_logs.php?booking_id=<?= $b['booking_id'] ?>" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-gas-pump me-1"></i> บันทึกค่าใช้จ่าย & น้ำมัน
                </a>
                <a href="inspection.php?booking_id=<?= $b['booking_id'] ?>" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-clipboard-check me-1"></i> ตรวจสภาพรถ
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($_SESSION['msg'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info') ?> alert-dismissible fade show rounded-4 border-0 shadow-xs mb-4">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($_SESSION['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
    <?php endif; ?>

    <!-- Status Header Banner with Digital Stamp -->
    <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 position-relative overflow-hidden">
        <?php if ($b['status'] === 'approved'): ?>
            <div class="status-stamp stamp-approved"><i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว</div>
        <?php elseif ($b['status'] === 'rejected'): ?>
            <div class="status-stamp stamp-rejected"><i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ</div>
        <?php else: ?>
            <div class="status-stamp stamp-pending"><i class="fa-solid fa-hourglass-half me-1"></i> รอพิจารณา</div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="text-muted extra-small d-block mb-1">สถานะปัจจุบันของคำขอ</span>
                <span class="badge <?= $status_info['class'] ?> fs-6 rounded-pill px-4 py-2">
                    <i class="<?= $status_info['icon'] ?> me-2"></i><?= $status_info['label'] ?>
                </span>
            </div>
            <?php if ($is_owner && in_array($b['status'], ['pending_head', 'pending_deputy'])): ?>
                <form method="POST" onsubmit="return confirm('ยืนยันที่จะยกเลิกคำขอใช้รถยนต์ราชการนี้หรือไม่?');">
                    <input type="hidden" name="action" value="cancel_by_user">
                    <button type="submit" class="btn btn-outline-danger rounded-pill px-4 btn-sm">
                        <i class="fa-solid fa-trash-can me-1"></i> ขอยกเลิกคำขอ
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3-Step Approval Workflow Progress Timeline -->
    <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
        <h6 class="fw-bold text-dark mb-4">
            <i class="fa-solid fa-timeline text-purple me-2"></i>ขั้นตอนการพิจารณาอนุมัติ 3 ลำดับ (Approval Workflow)
        </h6>

        <?php
        $flow_steps = [
            1 => [
                'title' => '1. ผู้ขอใช้รถยนต์ราชการ',
                'name' => $req_name,
                'status' => 'submitted',
                'date' => $b['created_at'],
                'comment' => $b['notes'],
                'sig' => $req_sig,
                'is_done' => true
            ],
            2 => [
                'title' => '2. หัวหน้าสำนักงานเลขานุการ',
                'name' => $head_name,
                'status' => !empty($b['head_approved_at']) ? 'approved' : ($b['status'] === 'pending_head' ? 'pending' : ($b['status'] === 'rejected' && empty($b['head_approved_at']) ? 'rejected' : 'waiting')),
                'date' => $b['head_approved_at'],
                'comment' => $b['head_comment'],
                'sig' => $head_sig,
                'is_done' => !empty($b['head_approved_at'])
            ],
            3 => [
                'title' => '3. รองคณบดีฝ่ายบริหาร',
                'name' => $dep_name,
                'status' => !empty($b['deputy_approved_at']) ? ($b['status'] === 'approved' ? 'approved' : 'rejected') : ($b['status'] === 'pending_deputy' ? 'pending' : 'waiting'),
                'date' => $b['deputy_approved_at'],
                'comment' => $b['deputy_comment'],
                'sig' => $dep_sig,
                'is_done' => ($b['status'] === 'approved')
            ]
        ];
        ?>

        <div class="row g-3 text-start">
            <?php foreach ($flow_steps as $snum => $st): 
                $is_passed = $st['is_done'];
                $is_cur = ($st['status'] === 'pending');
                $is_rej = ($b['status'] === 'rejected' && !empty($st['comment']) && empty($st['is_done']));

                $card_bg = 'bg-light text-muted border';
                $icon_h = '<i class="fa-regular fa-circle text-muted"></i>';

                if ($is_passed) {
                    $card_bg = 'bg-success-subtle text-success border border-success-subtle';
                    $icon_h = '<i class="fa-solid fa-circle-check text-success fs-5"></i>';
                } elseif ($is_cur) {
                    $card_bg = 'bg-warning-subtle text-dark border border-warning shadow-2xs';
                    $icon_h = '<i class="fa-solid fa-spinner fa-spin text-warning fs-5"></i>';
                } elseif ($is_rej) {
                    $card_bg = 'bg-danger-subtle text-danger border border-danger-subtle';
                    $icon_h = '<i class="fa-solid fa-circle-xmark text-danger fs-5"></i>';
                }
            ?>
                <div class="col-md-4">
                    <div class="p-3 rounded-4 <?= $card_bg ?> h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-bold extra-small text-uppercase"><?= $st['title'] ?></span>
                            <?= $icon_h ?>
                        </div>
                        <div class="fw-semibold small text-dark mb-2"><?= htmlspecialchars($st['name']) ?></div>

                        <!-- ลายเซ็น -->
                        <?php 
                        $sig_file_path = !empty($st['sig']) ? (__DIR__ . '/../../uploads/signatures/' . $st['sig']) : '';
                        $has_sig = !empty($sig_file_path) && file_exists($sig_file_path);
                        if ($is_passed): 
                        ?>
                            <div class="my-2 py-1 px-2 text-center bg-white rounded-3 border shadow-2xs">
                                <?php if ($has_sig): ?>
                                    <img src="/music-e-office/uploads/signatures/<?= htmlspecialchars($st['sig']) ?>" style="max-height: 40px; max-width: 120px; object-fit: contain;" alt="ลายเซ็น">
                                <?php else: ?>
                                    <div class="text-success fw-bold extra-small py-1">
                                        <i class="fa-solid fa-certificate me-1"></i>อนุมัติทางอิเล็กทรอนิกส์
                                    </div>
                                <?php endif; ?>
                                <div class="text-muted extra-small mt-1 border-top pt-1" style="font-size: 0.68rem;">
                                    <i class="fa-regular fa-clock me-1"></i><?= formatThaiDT($st['date']) ?>
                                </div>
                            </div>
                        <?php elseif ($is_cur): ?>
                            <div class="p-2 bg-white rounded-3 extra-small border text-center text-warning fw-semibold">
                                <i class="fa-solid fa-hourglass-half me-1"></i> กำลังรอการพิจารณาในขั้นนี้
                            </div>
                        <?php elseif ($is_rej): ?>
                            <div class="p-2 bg-white rounded-3 extra-small border text-center text-danger fw-semibold">
                                <i class="fa-solid fa-circle-xmark me-1"></i> ไม่อนุมัติในขั้นตอนนี้
                            </div>
                        <?php else: ?>
                            <div class="p-2 bg-white rounded-3 extra-small border text-center text-muted">
                                - รอดำเนินการตามลำดับ -
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($st['comment'])): ?>
                            <div class="mt-2 p-2 bg-white rounded-3 extra-small border text-dark">
                                <span class="fw-bold text-muted d-block">ความเห็น:</span>
                                <?= nl2br(htmlspecialchars($st['comment'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Approval Action Box (เฉพาะผู้มีอำนาจในขั้นตอนนั้น) -->
    <?php if ($can_approve): ?>
        <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 border-start border-4 border-warning">
            <h5 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-stamp text-warning me-2"></i>กล่องพิจารณาอนุมัติคำขอ (สำหรับ <?= htmlspecialchars($step_role_title) ?>)
            </h5>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">ความเห็นประกอบการพิจารณา</label>
                    <textarea name="comment" rows="3" class="form-control rounded-3" placeholder="ระบุข้อเสนอแนะ ข้อคิดเห็น หรือเหตุผลประกอบการพิจารณา"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger rounded-pill px-4" onclick="return confirm('ยืนยันที่จะไม่อนุมัติคำขอใช้รถยนต์ราชการนี้หรือไม่?');">
                        <i class="fa-solid fa-xmark me-1"></i> ไม่อนุมัติ
                    </button>
                    <button type="submit" name="action" value="approve" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm" onclick="return confirm('ยืนยันการอนุมัติ / ให้ความเห็นชอบคำขอใช้รถยนต์ราชการนี้?');">
                        <i class="fa-solid fa-check me-1"></i> ยืนยันให้ความเห็นชอบ / อนุมัติ
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Restore Box for Cancelled or Rejected (สำหรับงานพัสดุ & แอดมิน) -->
    <?php if (in_array($cur_status, ['rejected', 'cancelled']) && $can_manage_all): ?>
        <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 border-start border-4 border-success">
            <h5 class="fw-bold text-dark mb-2">
                <i class="fa-solid fa-rotate-left text-success me-2"></i>นำคำขอกลับมาใช้งานและอนุมัติ (งานพัสดุ / แอดมิน)
            </h5>
            <p class="text-muted small mb-3">คำขอนี้มีสถานะ "<?= $cur_status === 'rejected' ? 'ไม่อนุมัติ' : 'ยกเลิก' ?>" ท่านสามารถนำกลับมาอนุมัติจัดรถและเปิดให้ใช้งานได้</p>
            <form method="POST">
                <input type="hidden" name="action" value="restore_approved">
                <div class="mb-3">
                    <input type="text" name="comment" class="form-control rounded-3" placeholder="ระบุเหตุผลการนำกลับมาใช้งาน เช่น แก้ไขเวลา/คิวพร้อมเดินทางแล้ว">
                </div>
                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="return confirm('ยืนยันนำคำขอนี้กลับมาใช้งานและอนุมัติทันที?');">
                    <i class="fa-solid fa-check me-1"></i> นำกลับมาใช้งานและอนุมัติทันที
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Left Details -->
        <div class="col-lg-7">

            <!-- ข้อมูลการเดินทาง & ภารกิจ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-route text-purple me-2"></i>รายละเอียดภารกิจและการเดินทาง</h5>
                
                <table class="table table-bordered table-sm extra-small mb-0">
                    <tbody>
                        <tr>
                            <th class="bg-light text-muted" style="width: 150px;">ภารกิจ/วัตถุประสงค์</th>
                            <td class="fw-bold text-dark fs-6"><?= htmlspecialchars($b['title']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">สถานที่ปลายทาง</th>
                            <td class="fw-bold text-danger"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($b['destination']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">เส้นทางการเดินทาง</th>
                            <td><?= nl2br(htmlspecialchars($b['route_detail'] ?: '-')) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">วันเวลาออกเดินทาง</th>
                            <td class="fw-bold text-dark"><?= formatThaiDT($b['start_datetime']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">วันเวลาเดินทางกลับ</th>
                            <td class="fw-bold text-dark"><?= formatThaiDT($b['end_datetime']) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">จำนวนผู้โดยสาร</th>
                            <td><strong><?= $b['passenger_count'] ?></strong> คน</td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">รายชื่อผู้ร่วมเดินทาง</th>
                            <td><?= nl2br(htmlspecialchars($b['passenger_names'] ?: '-')) ?></td>
                        </tr>
                        <tr>
                            <th class="bg-light text-muted">พนักงานขับรถ</th>
                            <td>
                                <strong><?= htmlspecialchars($b['driver_name'] ?: 'ยังไม่ได้ระบุ') ?></strong>
                                <span class="badge bg-light text-dark border ms-1"><?= $b['driver_type'] === 'self_drive' ? 'ผู้ขอขับเอง' : 'พนักงานขับรถประจำ' ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- กล่องรายการเครื่องดนตรี / สัมภาระ -->
            <?php if (!empty($b['instruments_detail']) || $is_c2): ?>
                <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 border-start border-4 border-danger">
                    <h5 class="fw-bold text-dark mb-2">
                        <i class="fa-solid fa-guitar text-danger me-2"></i>รายการเครื่องดนตรีและสัมภาระที่บรรทุก
                    </h5>
                    <div class="p-3 bg-light rounded-3 text-dark small" style="line-height: 1.8; white-space: pre-wrap;">
                        <?= htmlspecialchars($b['instruments_detail'] ?: 'ไม่ได้ระบุรายการเครื่องดนตรี') ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ข้อมูลค่าใช้จ่ายและอัตราสิ้นเปลือง (ถ้ามีบันทึกแล้ว) -->
            <?php if ($trip_log): ?>
                <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4 border-start border-4 border-success">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-gas-pump text-success me-2"></i>บันทึกผลการเดินทางและอัตราสิ้นเปลือง
                        </h5>
                        <a href="trip_logs.php?booking_id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขข้อมูลทริป
                        </a>
                    </div>

                    <div class="row g-2 text-center mb-3">
                        <div class="col-3 p-2 bg-light rounded-3">
                            <div class="extra-small text-muted">ระยะทางรวม</div>
                            <div class="fw-bold text-dark fs-6"><?= number_format($trip_log['total_distance'], 1) ?> กม.</div>
                        </div>
                        <div class="col-3 p-2 bg-light rounded-3">
                            <div class="extra-small text-muted">น้ำมันที่เติม</div>
                            <div class="fw-bold text-dark fs-6"><?= number_format($trip_log['fuel_liters'], 1) ?> ลิตร</div>
                        </div>
                        <div class="col-3 p-2 bg-light rounded-3">
                            <div class="extra-small text-muted">อัตราสิ้นเปลือง</div>
                            <div class="fw-bold text-success fs-6"><?= number_format($trip_log['fuel_consumption_rate'], 2) ?> กม./ลิตร</div>
                        </div>
                        <div class="col-3 p-2 bg-light rounded-3">
                            <div class="extra-small text-muted">ค่าใช้จ่ายรวม</div>
                            <div class="fw-bold text-danger fs-6"><?= number_format($trip_log['total_expenses'], 2) ?> บาท</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- Right Details: Vehicle & Requester Cards -->
        <div class="col-lg-5">

            <!-- Vehicle Info Card -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-car text-purple me-2"></i>ข้อมูลยานพาหนะที่ขอใช้</h6>
                    <span class="badge <?= $is_c2 ? 'bg-danger text-white' : 'bg-purple text-white' ?> rounded-pill extra-small">
                        <?= $is_c2 ? 'คันที่ 2: บรรทุกเครื่องดนตรี' : 'คันที่ 1: รับส่งบุคคล' ?>
                    </span>
                </div>

                <div class="p-3 bg-light rounded-4 mb-3">
                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($b['model']) ?></h5>
                    <div class="text-muted small mb-2">
                        <span class="badge bg-white text-dark border fw-bold"><?= htmlspecialchars($b['plate_number']) ?></span>
                        <span class="ms-1">ปี <?= $b['year_made'] ?></span>
                    </div>
                    <div class="row g-2 extra-small text-muted">
                        <div class="col-6"><i class="fa-solid fa-users me-1 text-purple"></i>ที่นั่ง: <?= $b['seating_capacity'] ?> ที่นั่ง</div>
                        <div class="col-6"><i class="fa-solid fa-gas-pump me-1 text-purple"></i>น้ำมัน: <?= htmlspecialchars($b['fuel_type']) ?></div>
                        <div class="col-12"><i class="fa-solid fa-gauge me-1 text-purple"></i>เลขไมล์ล่าสุด: <?= number_format($b['current_odometer']) ?> กม.</div>
                    </div>
                </div>
            </div>

            <!-- Requester Card -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-tie text-purple me-2"></i>ผู้ขอใช้รถยนต์ราชการ</h6>
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-user fa-lg"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($req_name) ?></div>
                        <div class="extra-small text-muted"><?= htmlspecialchars($b['req_pos'] ?? 'อาจารย์ / บุคลากร') ?></div>
                    </div>
                </div>
                <div class="extra-small text-muted border-top pt-2 mt-3">
                    <div><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($b['req_email'] ?? '-') ?></div>
                    <div><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($b['req_phone'] ?? '-') ?></div>
                </div>
            </div>

        </div>

    </div>

</main>

<?php if ($can_manage_all): ?>
<!-- Modal จัดการคำขอ / สลับรถ / ปรับคนขับ (สำหรับงานพัสดุ & แอดมิน) -->
<div class="modal fade" id="dispatchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 bg-purple bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-purple">
                    <i class="fa-solid fa-sliders me-2"></i>จัดการคำขอ / จัดรถและคนขับ (งานพัสดุ)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="manage_dispatch">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">สลับรถยนต์ที่จัดให้ <span class="text-danger">*</span></label>
                        <select name="vehicle_id" class="form-select rounded-3" required>
                            <?php foreach ($all_vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>" <?= $b['vehicle_id'] == $v['vehicle_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['model']) ?> (ทะเบียน <?= htmlspecialchars($v['plate_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">ปรับสถานะคำขอโดยตรง <span class="text-danger">*</span></label>
                        <select name="status" class="form-select rounded-3" required>
                            <option value="pending_head" <?= $b['status'] === 'pending_head' ? 'selected' : '' ?>>รอหัวหน้าสำนักงานพิจารณา</option>
                            <option value="pending_deputy" <?= $b['status'] === 'pending_deputy' ? 'selected' : '' ?>>รอรองคณบดีฝ่ายบริหารพิจารณา</option>
                            <option value="approved" <?= $b['status'] === 'approved' ? 'selected' : '' ?>>✓ อนุมัติจัดรถเรียบร้อย (Approved)</option>
                            <option value="completed" <?= $b['status'] === 'completed' ? 'selected' : '' ?>>✓ เสร็จสิ้นภารกิจ (Completed)</option>
                            <option value="cancelled" <?= $b['status'] === 'cancelled' ? 'selected' : '' ?>>✗ ยกเลิกคำขอ (Cancelled)</option>
                            <option value="rejected" <?= $b['status'] === 'rejected' ? 'selected' : '' ?>>✗ ไม่อนุมัติ (Rejected)</option>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">ลักษณะคนขับ</label>
                            <select name="driver_type" id="disp_driver_type" class="form-select rounded-3" onchange="toggleDispDriver(this.value)">
                                <option value="driver_assigned" <?= $b['driver_type'] === 'driver_assigned' ? 'selected' : '' ?>>พนักงานขับรถประจำ</option>
                                <option value="self_drive" <?= $b['driver_type'] === 'self_drive' ? 'selected' : '' ?>>ผู้ขอขับเอง / อาจารย์ขับเอง</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="disp_driver_select_box">
                            <label class="form-label fw-bold small text-dark">เลือกคนขับประจำ</label>
                            <select name="driver_id" class="form-select rounded-3" onchange="onDispDriverChange(this)">
                                <option value="">-- ระบุชื่อคนขับ --</option>
                                <?php foreach ($all_drivers as $d): 
                                    $dname = trim(($d['prefix_name'] ?? '') . $d['first_name'] . ' ' . $d['last_name']);
                                ?>
                                    <option value="<?= $d['user_id'] ?>" data-name="<?= htmlspecialchars($dname) ?>" <?= $b['driver_id'] === $d['user_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">ชื่อผู้ขับขี่ (แสดงในใบขอใช้รถ)</label>
                        <input type="text" name="driver_name" id="disp_driver_name" class="form-control rounded-3" value="<?= htmlspecialchars($b['driver_name']) ?>" placeholder="เช่น นายเนตร เนื่องวรรณะ">
                    </div>
                </div>

                <div class="modal-footer border-0 bg-light py-3 px-4 d-flex justify-content-between">
                    <button type="submit" name="action" value="delete_booking" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('คำเตือน: คุณต้องการลบคำขอใช้รถนี้ออกจากระบบอย่างถาวรใช่หรือไม่?');">
                        <i class="fa-solid fa-trash me-1"></i> ลบคำขอนี้
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold">
                            <i class="fa-solid fa-save me-1"></i> บันทึกการจัดรถ
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDispDriver(val) {
    var box = document.getElementById('disp_driver_select_box');
    var nameInput = document.getElementById('disp_driver_name');
    if (val === 'self_drive') {
        box.style.display = 'none';
        if (!nameInput.value || nameInput.value === 'นายเนตร เนื่องวรรณะ') {
            nameInput.value = '<?= htmlspecialchars(addslashes($req_name)) ?> (ผู้ขอขับเอง)';
        }
    } else {
        box.style.display = 'block';
    }
}

function onDispDriverChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.name) {
        document.getElementById('disp_driver_name').value = opt.dataset.name;
    }
}
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
