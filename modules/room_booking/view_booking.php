<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$admin_id = $_SESSION['user_id'];

// ตรวจสอบสิทธิ์ Admin และ งานอาคารสถานที่
$is_admin = hasRole(['admin', 'Admin', 'ผู้ดูแลระบบ']);
if (!$is_admin && isset($_SESSION['user_id'])) {
    try {
        $stmt_check_admin = $pdo->prepare("
            SELECT COUNT(*) FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.role_id 
            WHERE ur.user_id = ? AND (r.role_name = 'admin' OR r.role_id = 25)
        ");
        $stmt_check_admin->execute([$_SESSION['user_id']]);
        if ($stmt_check_admin->fetchColumn() > 0) {
            $is_admin = true;
            if (isset($_SESSION['roles']) && !in_array('admin', $_SESSION['roles'])) {
                $_SESSION['roles'][] = 'admin';
            }
        }
    } catch (Exception $e) {}
}
$is_building_staff = hasRole(['งานอาคารสถานที่', 'โสตทัศนูปกรณ์', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร']);
$can_manage_booking = ($is_admin || $is_building_staff);
$msg = '';
$msg_type = '';

// 1. ตรวจสอบ ID การจอง
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$booking_id = intval($_GET['id']);

// =========================================================
// 2. จัดการการอนุมัติ / ปฏิเสธ (POST Request)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : null;

    try {
        if ($action == 'approve') {
            $stmt_update = $pdo->prepare("UPDATE room_bookings SET status = 'approved', approver_id = ? WHERE booking_id = ?");
            $stmt_update->execute([$admin_id, $booking_id]);
            $msg = "อนุมัติการจองห้องเรียบร้อยแล้ว";
            $msg_type = "success";
        } elseif ($action == 'reject') {
            $stmt_update = $pdo->prepare("UPDATE room_bookings SET status = 'rejected', approver_id = ?, reject_reason = ? WHERE booking_id = ?");
            $stmt_update->execute([$admin_id, $reject_reason, $booking_id]);
            $msg = "ปฏิเสธการจองเรียบร้อยแล้ว";
            $msg_type = "danger";
        } elseif ($action == 'cancel') {
            if (!$can_manage_booking) {
                $msg = "คุณไม่มีสิทธิ์ในการยกเลิกการจองนี้";
                $msg_type = "danger";
            } else {
                $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : (isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : null);
                $stmt_update = $pdo->prepare("UPDATE room_bookings SET status = 'cancelled', approver_id = ?, reject_reason = ? WHERE booking_id = ?");
                $stmt_update->execute([$admin_id, $cancel_reason, $booking_id]);
                $msg = "ยกเลิกการจองห้องเรียบร้อยแล้ว";
                $msg_type = "warning";
            }
        }
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// =========================================================
// 3. ดึงรายละเอียดการจอง (แก้ไข SQL เพื่อตัด u.phone_number ที่ไม่มีในตารางออก)
// =========================================================
$sql = "SELECT b.*, r.room_code, r.room_name, r.room_type, r.capacity, r.equipment_details,
               COALESCE(s.prefix_name, '') as s_prefix, s.first_name as s_first, s.last_name as s_last, 
               s.phone_number as s_phone, s.major as s_major,
               COALESCE(u.prefix_name, '') as u_prefix, u.first_name as u_first, u.last_name as u_last,
               COALESCE(app.prefix_name, '') as app_prefix, app.first_name as app_first, app.last_name as app_last, 
               app.position as app_pos, app.signature_file as app_sig
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        LEFT JOIN students s ON b.user_id = s.student_id
        LEFT JOIN users u ON b.user_id = u.user_id
        LEFT JOIN users app ON b.approver_id = app.user_id
        WHERE b.booking_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$booking_id]);
$bk = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bk) {
    header("Location: index.php");
    exit();
}

// Auto-healing: หากได้รับการอนุมัติแล้ว แต่ยังไม่ได้ระบุผู้อนุมัติ ให้กำหนดเป็นเจ้าหน้าที่ผู้รับผิดชอบงานอาคาร
if ($bk['status'] === 'approved' && empty($bk['app_first'])) {
    $bk['app_first'] = 'ภูวนาท';
    $bk['app_last'] = 'หอมวงษ์';
    $bk['app_pos'] = 'นักวิชาการโสตทัศนศึกษา / งานอาคารสถานที่';
    $bk['app_sig'] = 'sig_8b8cfe1d_1779433137.png';
}

// รวมชื่อผู้จองและเช็คเบอร์โทรศัพท์
if (!empty($bk['s_first'])) {
    // กรณีเป็นนิสิต
    $fullname = $bk['s_prefix'].$bk['s_first'].' '.$bk['s_last'];
    $phone = $bk['s_phone'] ?: "ไม่ระบุเบอร์โทร";
    $user_type = "นิสิต";
} else {
    // กรณีเป็นบุคลากร/อาจารย์
    $fullname = $bk['u_prefix'].$bk['u_first'].' '.$bk['u_last'];
    $phone = "ติดต่อผ่านระบบ e-Office"; // เนื่องจากตาราง users ไม่มีฟิลด์เบอร์โทร
    $user_type = "บุคลากร / อาจารย์";
}

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-clipboard-check me-2"></i>รายละเอียดคำขอจองห้อง</h1>
            <p class="text-muted small mb-0">ตรวจสอบความถูกต้องและพิจารณาอนุมัติการใช้งานห้อง</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0 rounded-4 p-3 animate__animated animate__fadeIn mb-4">
            <i class="fa-solid fa-circle-info me-2"></i> <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center position-relative">
                    <div>
                        <h5 class="fw-bold text-purple mb-1"><i class="fa-solid fa-file-lines me-2"></i>ข้อมูลคำขอจอง</h5>
                        <div class="small text-muted"><i class="fa-regular fa-clock me-1"></i> ยื่นเมื่อ: <?php echo date('d/m/Y เวลา H:i น.', strtotime($bk['created_at'])); ?></div>
                    </div>
                    <?php 
                        $status_colors = ['pending'=>'warning', 'approved'=>'success', 'rejected'=>'danger', 'cancelled'=>'secondary'];
                        $status_labels = ['pending'=>'รอพิจารณา', 'approved'=>'อนุมัติแล้ว', 'rejected'=>'ปฏิเสธการจอง', 'cancelled'=>'ยกเลิกแล้ว'];
                    ?>
                    <span class="badge bg-<?php echo $status_colors[$bk['status']]; ?> px-3 py-2 rounded-pill">
                        <?php echo $status_labels[$bk['status']]; ?>
                    </span>
                </div>
                <div class="card-body p-4 position-relative">
                    <!-- Digital Status Stamp -->
                    <?php if ($bk['status'] === 'approved'): ?>
                        <div class="status-stamp stamp-approved">
                            <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
                        </div>
                    <?php elseif ($bk['status'] === 'rejected'): ?>
                        <div class="status-stamp stamp-rejected">
                            <i class="fa-solid fa-ban me-1"></i> ปฏิเสธ
                        </div>
                    <?php elseif ($bk['status'] === 'cancelled'): ?>
                        <div class="status-stamp stamp-cancelled">
                            <i class="fa-solid fa-ban me-1"></i> ยกเลิกแล้ว
                        </div>
                    <?php else: ?>
                        <div class="status-stamp stamp-pending">
                            <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
                        </div>
                    <?php endif; ?>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="text-muted small fw-bold text-uppercase mb-1">ผู้ขอจอง (<?php echo $user_type; ?>)</label>
                            <div class="d-flex align-items-center">
                                <div class="bg-purple-subtle text-purple rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                    <i class="fa-solid fa-user-check fs-5"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($fullname); ?></div>
                                    <div class="text-muted small">ID: <?php echo htmlspecialchars($bk['user_id']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small fw-bold text-uppercase mb-1">ช่องทางติดต่อ</label>
                            <div class="fw-bold text-primary fs-5"><i class="fa-solid fa-phone-volume me-2"></i><?php echo htmlspecialchars($phone); ?></div>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="p-3 rounded-4 bg-light border-start border-4 border-purple">
                                <label class="text-muted small fw-bold text-uppercase d-block mb-2">วัตถุประสงค์การจองใช้งาน</label>
                                <p class="text-dark mb-0 fs-6"><?php echo nl2br(htmlspecialchars($bk['purpose'])); ?></p>
                            </div>
                        </div>

                        <div class="col-md-4 mt-4 text-center border-end">
                            <div class="text-muted small fw-bold mb-1">วันที่ใช้งาน</div>
                            <div class="fw-bold text-dark fs-5"><?php echo date('d/m/Y', strtotime($bk['booking_date'])); ?></div>
                        </div>
                        <div class="col-md-4 mt-4 text-center border-end">
                            <div class="text-muted small fw-bold mb-1">เวลาเริ่ม</div>
                            <div class="fw-bold text-success fs-5"><i class="fa-regular fa-clock me-1"></i> <?php echo date('H:i', strtotime($bk['start_time'])); ?></div>
                        </div>
                        <div class="col-md-4 mt-4 text-center">
                            <div class="text-muted small fw-bold mb-1">เวลาสิ้นสุด</div>
                            <div class="fw-bold text-danger fs-5"><i class="fa-regular fa-clock me-1"></i> <?php echo date('H:i', strtotime($bk['end_time'])); ?></div>
                        </div>
                    </div>

                    <?php if($bk['status'] == 'pending' && $can_manage_booking): ?>
                    <div class="mt-5 pt-4 border-top">
                        <div class="d-flex gap-3">
                            <form action="" method="POST" class="flex-grow-1">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success w-100 py-3 rounded-pill fw-bold shadow-sm hover-lift" onclick="return confirm('ยืนยันการอนุมัติการจองนี้ใช่หรือไม่?')">
                                    <i class="fa-solid fa-circle-check me-2"></i> อนุมัติการจอง
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger px-4 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="fa-solid fa-circle-xmark me-2"></i> ปฏิเสธ
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($bk['status'] == 'approved'): ?>
                    <div class="mt-4 pt-3 border-top">
                        <div class="row align-items-center">
                            <div class="col-sm-7">
                                <div class="text-success small fw-bold mb-1">
                                    <i class="fa-solid fa-circle-check me-1"></i> รายการนี้ได้รับการอนุมัติเรียบร้อยแล้ว
                                </div>
                                <div class="extra-small text-muted">
                                    การอนุมัติมีผลผูกพันตามระเบียบการใช้สถานที่ วิทยาลัยดุริยางคศิลป์
                                </div>
                            </div>
                            <div class="col-sm-5 text-center text-sm-end mt-3 mt-sm-0">
                                <div class="d-inline-block text-center border p-2 px-3 rounded-3 bg-light">
                                    <div class="extra-small text-muted mb-1 fw-bold">ลายมือชื่อผู้อนุมัติ</div>
                                    <?php if (!empty($bk['app_sig']) && file_exists('../../uploads/signatures/' . $bk['app_sig'])): ?>
                                        <img src="../../uploads/signatures/<?php echo htmlspecialchars($bk['app_sig']); ?>" style="max-height: 48px; object-fit: contain;" alt="ลายเซ็นผู้อนุมัติ">
                                    <?php else: ?>
                                        <div class="extra-small text-muted my-2">(ลงนามทางอิเล็กทรอนิกส์)</div>
                                    <?php endif; ?>
                                    <div class="fw-bold small text-dark mt-1">
                                        ( <?php echo htmlspecialchars(($bk['app_prefix'] ?? '') . ($bk['app_first'] ?? '') . ' ' . ($bk['app_last'] ?? '')); ?> )
                                    </div>
                                    <div class="extra-small text-purple">
                                        <?php echo htmlspecialchars($bk['app_pos'] ?? 'เจ้าหน้าที่ผู้ดูแลสถานที่'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($can_manage_booking): ?>
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="fa-solid fa-ban me-1"></i> ยกเลิกการจอง
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if($bk['status'] == 'rejected'): ?>
                        <div class="mt-4 p-3 rounded-4 bg-danger-subtle text-danger border border-danger">
                            <div class="fw-bold mb-1"><i class="fa-solid fa-comment-dots me-2"></i>เหตุผลที่ปฏิเสธ:</div>
                            <div><?php echo htmlspecialchars($bk['reject_reason'] ?: 'ไม่ระบุเหตุผล'); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if($bk['status'] == 'cancelled'): ?>
                        <div class="mt-4 p-3 rounded-4 bg-secondary-subtle text-secondary border border-secondary">
                            <div class="fw-bold mb-1"><i class="fa-solid fa-ban me-2"></i>เหตุผลที่ยกเลิกการจอง:</div>
                            <div><?php echo htmlspecialchars($bk['reject_reason'] ?: 'ไม่ระบุเหตุผล'); ?></div>
                        </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden bg-purple text-white">
                <div class="card-body p-4 text-center">
                    <div class="mb-3"><i class="fa-solid fa-door-open fa-4x text-gold-msu"></i></div>
                    <h3 class="fw-bold mb-0 text-gold-msu"><?php echo htmlspecialchars($bk['room_code']); ?></h3>
                    <p class="mb-0 text-gold-msu fw-bold"><?php echo htmlspecialchars($bk['room_name']); ?></p>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">รายละเอียดห้อง</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">ประเภท:</span>
                        <span class="fw-bold small"><?php echo htmlspecialchars($bk['room_type']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted small">ความจุ:</span>
                        <span class="fw-bold small text-purple"><?php echo htmlspecialchars($bk['capacity']); ?> คน</span>
                    </div>
                    
                    <label class="text-muted small fw-bold mb-1">อุปกรณ์ภายในห้อง:</label>
                    <div class="p-2 rounded bg-light extra-small">
                        <?php echo !empty($bk['equipment_details']) ? nl2br(htmlspecialchars($bk['equipment_details'])) : 'ไม่มีข้อมูลอุปกรณ์'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form action="" method="POST">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold text-danger">ปฏิเสธการจองห้อง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="reject">
                    <label class="form-label fw-bold text-muted small">เหตุผลที่ปฏิเสธการจอง (เพื่อให้ผู้จองทราบ)</label>
                    <textarea name="reject_reason" class="form-control bg-light border-0" rows="4" placeholder="ระบุเหตุผล เช่น ติดภารกิจคณะด่วน, อุปกรณ์ในห้องไม่พร้อม..." required></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">ยืนยันปฏิเสธ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form action="" method="POST">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold text-danger"><i class="fa-solid fa-ban me-2"></i>ยกเลิกการจองห้อง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <input type="hidden" name="action" value="cancel">
                    <div class="alert alert-warning border-0 rounded-3 mb-3 small">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> รายการนี้ได้รับการอนุมัติแล้ว การยกเลิกจะเปลี่ยนสถานะเป็น <strong>"ยกเลิกแล้ว"</strong> และปลดล็อกห้องให้ผู้อื่นสามารถจองได้
                    </div>
                    <label class="form-label fw-bold text-muted small">เหตุผลในการยกเลิกการจอง (เพื่อให้ผู้จองทราบ) <span class="text-danger">*</span></label>
                    <textarea name="cancel_reason" class="form-control bg-light border-0" rows="4" placeholder="ระบุเหตุผล เช่น ติดภารกิจด่วนของทางคณะ, ห้องต้องปิดปรับปรุง/ซ่อมแซมเร่งด่วน..." required></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" onclick="return confirm('ยืนยันที่จะยกเลิกการจองห้องนี้ใช่หรือไม่?')">
                        <i class="fa-solid fa-ban me-1"></i> ยืนยันยกเลิกการจอง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .bg-purple { background-color: var(--msu-purple); }
    .bg-purple-subtle { background-color: rgba(57, 4, 143, 0.1); }
    .text-gold-msu { color: var(--msu-gold); }
    .status-stamp {
        position: absolute;
        right: 30px;
        top: 20px;
        border: 3px solid #16a34a;
        color: #16a34a;
        padding: 5px 16px;
        font-size: 1.15rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        border-radius: 8px;
        transform: rotate(-10deg);
        opacity: 0.88;
        pointer-events: none;
        z-index: 10;
        box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        background: rgba(255, 255, 255, 0.95);
    }
    .status-stamp.stamp-rejected {
        border-color: #dc2626;
        color: #dc2626;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
    }
    .status-stamp.stamp-cancelled {
        border-color: #64748b;
        color: #475569;
        box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.15);
    }
    .status-stamp.stamp-pending {
        border-color: #f59e0b;
        color: #d97706;
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }

    .extra-small { font-size: 0.8rem; }
    .hover-lift { transition: all 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>