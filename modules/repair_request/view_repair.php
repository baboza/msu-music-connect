<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$admin_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// 1. ตรวจสอบ ID รายการแจ้งซ่อม
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$repair_id = intval($_GET['id']);

// =========================================================
// 2. จัดการการอัปเดตสถานะ (POST Request)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $repair_note = trim($_POST['repair_note'] ?? '');

    try {
        $stmt_update = $pdo->prepare("
            UPDATE repair_requests 
            SET status = ?, 
                repair_note = ?, 
                technician_id = ?, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE repair_id = ?
        ");
        $stmt_update->execute([$new_status, $repair_note, $admin_id, $repair_id]);
        
        $msg = "อัปเดตสถานะการแจ้งซ่อมเรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// =========================================================
// 3. ดึงข้อมูลรายละเอียด
// =========================================================
$sql = "SELECT rr.*, r.room_code, r.room_name,
               COALESCE(s.prefix_name, '') as s_prefix, s.first_name as s_first, s.last_name as s_last, s.phone_number as s_phone,
               COALESCE(u.prefix_name, '') as u_prefix, u.first_name as u_first, u.last_name as u_last,
               COALESCE(tech.prefix_name, '') as tech_prefix, tech.first_name as tech_first, tech.last_name as tech_last, tech.signature_file as tech_sig
        FROM repair_requests rr
        LEFT JOIN rooms r ON rr.room_id = r.room_id
        LEFT JOIN students s ON rr.reporter_id = s.student_id
        LEFT JOIN users u ON rr.reporter_id = u.user_id
        LEFT JOIN users tech ON rr.technician_id = tech.user_id
        WHERE rr.repair_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$repair_id]);
$rp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rp) {
    header("Location: index.php");
    exit();
}

// รวมชื่อผู้แจ้ง
$reporter_name = !empty($rp['s_first']) 
                 ? $rp['s_prefix'].$rp['s_first'].' '.$rp['s_last'] 
                 : $rp['u_prefix'].$rp['u_first'].' '.$rp['u_last'];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชัน Badge ระดับความรุนแรง
function getLevelBadge($level) {
    if($level == 'critical') return '<span class="badge bg-danger px-3 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> วิกฤต / เร่งด่วน</span>';
    if($level == 'medium') return '<span class="badge bg-warning text-dark px-3 py-2">ปานกลาง</span>';
    return '<span class="badge bg-info text-white px-3 py-2">เล็กน้อย</span>';
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>รายละเอียดงานแจ้งซ่อม</h1>
            <p class="text-muted small mb-0">ตรวจสอบความเสียหายและดำเนินการบันทึกการซ่อม</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารวม
        </a>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0 rounded-4 p-3 mb-4 animate__animated animate__fadeIn">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center position-relative">
                    <h5 class="fw-bold text-purple mb-0"><i class="fa-solid fa-circle-info me-2"></i>ข้อมูลการแจ้งเสีย</h5>
                    <span class="text-muted small">ID: #<?php echo $repair_id; ?></span>
                </div>
                <div class="card-body p-4 position-relative">
                    <!-- Digital Status Stamp -->
                    <?php if ($rp['status'] === 'completed'): ?>
                        <div class="status-stamp stamp-approved">
                            <i class="fa-solid fa-stamp me-1"></i> ซ่อมเสร็จสิ้น
                        </div>
                    <?php elseif ($rp['status'] === 'in_progress'): ?>
                        <div class="status-stamp stamp-inprogress">
                            <i class="fa-solid fa-tools me-1"></i> กำลังดำเนินการ
                        </div>
                    <?php elseif ($rp['status'] === 'cancelled'): ?>
                        <div class="status-stamp stamp-rejected">
                            <i class="fa-solid fa-ban me-1"></i> ยกเลิก
                        </div>
                    <?php else: ?>
                        <div class="status-stamp stamp-pending">
                            <i class="fa-solid fa-clock me-1"></i> รอรับเรื่อง
                        </div>
                    <?php endif; ?>
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="text-muted small fw-bold text-uppercase mb-1">อุปกรณ์ / สถานที่</label>
                            <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($rp['equipment_name']); ?></h4>
                            <div class="text-primary fw-bold mt-1">
                                <i class="fa-solid fa-location-dot me-1"></i> 
                                ห้อง: <?php echo htmlspecialchars($rp['room_code'] . ' - ' . $rp['room_name']); ?>
                            </div>
                        </div>

                        <div class="col-12 mt-2">
                            <div class="p-3 rounded-4 bg-light border-start border-4 border-danger">
                                <label class="text-muted small fw-bold text-uppercase d-block mb-2">รายละเอียดอาการปัญหา</label>
                                <p class="text-dark mb-0 fs-6"><?php echo nl2br(htmlspecialchars($rp['problem_desc'])); ?></p>
                            </div>
                        </div>

                        <div class="col-md-6 mt-4">
                            <label class="text-muted small fw-bold text-uppercase mb-1">ระดับความรุนแรง</label>
                            <div><?php echo getLevelBadge($rp['damage_level']); ?></div>
                        </div>
                        <div class="col-md-6 mt-4 text-md-end">
                            <label class="text-muted small fw-bold text-uppercase mb-1">วันที่แจ้ง</label>
                            <div class="fw-bold text-dark small"><?php echo date('d/m/Y H:i', strtotime($rp['created_at'])); ?> น.</div>
                        </div>
                    </div>

                    <hr class="my-5 opacity-10">

                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-clipboard-check me-2 text-success"></i>การดำเนินการของเจ้าหน้าที่ / ช่าง</h5>
                    <form action="" method="POST" id="repairForm">
                        <input type="hidden" name="update_status" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">อัปเดตสถานะงาน <span class="text-danger">*</span></label>
                                <select name="status" id="statusSelect" class="form-select border-0 bg-light py-2 shadow-sm" required>
                                    <option value="pending" <?php echo $rp['status'] == 'pending' ? 'selected' : ''; ?>>รอรับเรื่อง (Pending)</option>
                                    <option value="in_progress" <?php echo $rp['status'] == 'in_progress' ? 'selected' : ''; ?>>กำลังดำเนินการ/ส่งต่อ (In Progress)</option>
                                    <option value="completed" <?php echo $rp['status'] == 'completed' ? 'selected' : ''; ?>>ซ่อมเสร็จสิ้น (Completed)</option>
                                    <option value="cancelled" <?php echo $rp['status'] == 'cancelled' ? 'selected' : ''; ?>>ยกเลิก (Cancelled)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small d-flex justify-content-between">
                                    <span>บันทึกการซ่อม / หมายเหตุ</span>
                                    <span class="text-muted extra-small">เลือกข้อความด่วนด้านล่างเพื่อความรวดเร็ว</span>
                                </label>
                                
                                <div class="mb-2 d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-xs btn-outline-success rounded-pill" onclick="setQuickNote('✅ ดำเนินการซ่อมโดยช่างคณะเรียบร้อยแล้ว', 'completed')">
                                        <i class="fa-solid fa-check me-1"></i> คณะซ่อมเสร็จแล้ว
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-primary rounded-pill" onclick="setQuickNote('🏢 ประสานงานส่งเรื่องต่อกองอาคารสถานที่ มหาวิทยาลัยแล้ว', 'in_progress')">
                                        <i class="fa-solid fa-building-circle-arrow-right me-1"></i> ส่งต่อมหาวิทยาลัย
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-warning rounded-pill text-dark" onclick="setQuickNote('🔍 ช่างเข้าตรวจสอบหน้างานแล้ว อยู่ระหว่างประเมินแนวทางแก้ไข', 'in_progress')">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i> เข้าตรวจหน้างานแล้ว
                                    </button>
                                </div>

                                <textarea name="repair_note" class="form-control border-0 bg-light" rows="4" placeholder="พิมพ์รายละเอียดเพิ่มเติมที่นี่..."><?php echo htmlspecialchars($rp['repair_note'] ?? ''); ?></textarea>
                            </div>

                            <?php if ($rp['status'] === 'completed'): ?>
                                <div class="col-12 mt-4 p-3 bg-light rounded-4 border text-center">
                                    <div class="extra-small text-muted fw-bold mb-1">เจ้าหน้าที่ผู้รับผิดชอบดำเนินการ</div>
                                    <?php 
                                    $sig_file = !empty($rp['tech_sig']) ? $rp['tech_sig'] : 'sig_8b8cfe1d_1779433137.png';
                                    $tech_name = (!empty($rp['tech_first'])) ? ($rp['tech_prefix'] . $rp['tech_first'] . ' ' . $rp['tech_last']) : 'นายภูวนาท หอมวงษ์';
                                    ?>
                                    <?php if (file_exists('../../uploads/signatures/' . $sig_file)): ?>
                                        <img src="../../uploads/signatures/<?php echo htmlspecialchars($sig_file); ?>" style="max-height: 48px; object-fit: contain;" alt="ลายเซ็นผู้ดำเนินการ">
                                    <?php endif; ?>
                                    <div class="fw-bold small text-dark mt-1">( <?php echo htmlspecialchars($tech_name); ?> )</div>
                                    <div class="extra-small text-purple">ผู้ดูแลงานอาคารสถานที่ / โสตทัศนูปกรณ์</div>
                                    <div class="extra-small text-muted mt-1">วันที่แล้วเสร็จ: <?php echo date('d/m/Y H:i', strtotime($rp['updated_at'] ?? 'now')); ?> น.</div>
                                </div>
                            <?php endif; ?>

                            <div class="col-12 mt-4 text-center">
                                <button type="submit" class="btn btn-purple px-5 py-3 rounded-pill fw-bold shadow-sm hover-lift">
                                    <i class="fa-solid fa-floppy-disk me-2"></i> บันทึกผลการดำเนินงาน
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-image me-2"></i>รูปภาพประกอบ</h6>
                </div>
                <div class="card-body p-3 text-center bg-light-subtle">
                    <?php if (!empty($rp['image_file'])): ?>
                        <a href="../../uploads/repair/<?php echo htmlspecialchars($rp['image_file']); ?>" target="_blank">
                            <img src="../../uploads/repair/<?php echo htmlspecialchars($rp['image_file']); ?>" class="img-fluid rounded-3 shadow-sm border" style="max-height: 250px; width: 100%; object-fit: cover;">
                        </a>
                    <?php else: ?>
                        <div class="py-5 text-muted opacity-25">
                            <i class="fa-regular fa-image fa-3x mb-2"></i><br>ไม่มีรูปแนบ
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 text-center">
                    <div class="bg-purple text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 55px; height: 55px;">
                        <i class="fa-solid fa-user-tag fs-4"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($reporter_name); ?></h6>
                    <p class="text-muted extra-small mb-3">ID: <?php echo htmlspecialchars($rp['reporter_id']); ?></p>
                    
                    <?php if(!empty($rp['s_phone'])): ?>
                        <a href="tel:<?php echo $rp['s_phone']; ?>" class="btn btn-light btn-sm w-100 rounded-pill border">
                            <i class="fa-solid fa-phone me-1"></i> โทร: <?php echo htmlspecialchars($rp['s_phone']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function setQuickNote(text, status) {
    const textarea = document.getElementsByName('repair_note')[0];
    const select = document.getElementById('statusSelect');
    
    // ใส่ข้อความในช่อง Note
    textarea.value = text;
    
    // เปลี่ยนค่าใน Select ให้สอดคล้องกัน
    if(status) {
        select.value = status;
    }
}
</script>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; border: none; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .btn-xs { padding: 0.25rem 0.6rem; font-size: 0.75rem; font-weight: 600; }
    .extra-small { font-size: 0.75rem; }
    .hover-lift { transition: transform 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); }
</style>

<?php require_once '../../includes/footer.php'; ?>