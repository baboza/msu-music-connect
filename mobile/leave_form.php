<?php
require_once 'auth_check.php';
require_once 'includes/header.php';
require_once '../includes/functions_noti.php'; // สำหรับ Telegram Noti (ถ้ามี)

// 1. ดึงข้อมูลผู้ใช้งานเพื่อตรวจสอบลายเซ็น
$stmt_user = $pdo->prepare("SELECT signature_file FROM users WHERE user_id = ?");
$stmt_user->execute([$user_id]);
$user_data = $stmt_user->fetch();

// 2. ดึงประเภทการลาทั้งหมด
$leave_types = $pdo->query("SELECT * FROM leave_types ORDER BY type_id ASC")->fetchAll();

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type_id = $_POST['type_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];

    // 1. จัดการไฟล์แนบ
    $attachment_name = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $upload_dir = '../uploads/leaves/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $file_info = pathinfo($_FILES['attachment']['name']);
        $file_ext = strtolower($file_info['extension']);
        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_ext)) {
            $attachment_name = "leave_" . $user_id . "_" . date('YmdHis') . "." . $file_ext;
            $upload_path = $upload_dir . $attachment_name;
            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $msg = "ไม่สามารถอัปโหลดไฟล์ได้";
                $msg_type = "danger";
            }
        }
    }

    if(empty($msg)) {
        try {
            $pdo->beginTransaction();
            
            // 2. บันทึกลงฐานข้อมูล
            $sql = "INSERT INTO leave_requests (user_id, type_id, start_date, end_date, reason, attachment, current_step, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, 'pending')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $type_id, $start_date, $end_date, $reason, $attachment_name]);
            
            $new_leave_id = $pdo->lastInsertId();
            $pdo->commit();

            // 3. แจ้งเตือน Telegram
            if (function_exists('sendTelegramWithButtons')) {
                $stmt_type = $pdo->prepare("SELECT type_name FROM leave_types WHERE type_id = ?");
                $stmt_type->execute([$type_id]);
                $type_name = $stmt_type->fetchColumn();

                $target_roles = ['งานบุคคล', 'บุคลากร', 'admin']; 
                $placeholders = implode(',', array_fill(0, count($target_roles), '?'));

                $stmt_noti = $pdo->prepare("SELECT DISTINCT u.telegram_chat_id 
                    FROM users u 
                    JOIN user_roles ur ON u.user_id = ur.user_id 
                    JOIN roles r ON ur.role_id = r.role_id 
                    WHERE r.role_name IN ($placeholders) AND u.telegram_chat_id IS NOT NULL");
                $stmt_noti->execute($target_roles);
                $notified_users = $stmt_noti->fetchAll();

                $msg_text = "🔔 <b>มีการยื่นใบลาใหม่รอการตรวจสอบ (ผ่านระบบ Mobile)</b>\n";
                $msg_text .= "--------------------------------\n";
                $msg_text .= "👤 <b>ผู้ขอลา:</b> " . $_SESSION['full_name'] . "\n";
                $msg_text .= "📋 <b>ประเภท:</b> " . $type_name . "\n";
                $msg_text .= "📅 <b>วันที่:</b> " . date('d/m/Y', strtotime($start_date)) . " ถึง " . date('d/m/Y', strtotime($end_date)) . "\n";
                $msg_text .= "📂 <b>ไฟล์แนบ:</b> " . ($attachment_name ? "✅ มีใบรับรองแพทย์" : "❌ ไม่มี") . "\n";
                $msg_text .= "--------------------------------\n";
                $msg_text .= "🔗 <a href='http://localhost/music-e-office/modules/leave/view.php?id=" . $new_leave_id . "'>เปิดดูรายละเอียดฉบับเต็ม</a>";

                foreach ($notified_users as $u) {
                    sendTelegramWithButtons($u['telegram_chat_id'], $msg_text, $new_leave_id);
                }
            }

            $msg = "ส่งใบลาสำเร็จ!";
            $msg_type = "success";
            
            echo "<script>setTimeout(()=>window.location.href='leave.php', 1500);</script>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="leave.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าระบบการลา">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">เขียนใบลาใหม่</h5>
            <small class="text-muted">ยื่นคำขออนุมัติการลา</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-pen-to-square"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <?php if (empty($user_data['signature_file'])): ?>
        <div class="bento-box bg-warning-subtle border p-4 text-center">
            <i class="fa-solid fa-triangle-exclamation text-warning fs-1 mb-3"></i>
            <h5 class="fw-bold text-dark">ยังไม่ได้อัปโหลดลายเซ็น!</h5>
            <p class="text-muted small">คุณต้องอัปโหลดลายเซ็นดิจิทัลก่อนจึงจะสามารถส่งใบลาได้</p>
            <a href="profile.php" class="btn btn-warning rounded-pill mt-2 fw-bold" hx-boost="true">ไปอัปโหลดลายเซ็น</a>
        </div>
    <?php else: ?>
        <form method="POST" enctype="multipart/form-data" class="bento-box p-4" id="leaveForm">
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">ประเภทการลา <span class="text-danger">*</span></label>
                <select name="type_id" id="type_id" class="form-select form-select-sm" required onchange="checkLeaveType()">
                    <option value="" disabled selected>-- เลือกประเภทการลา --</option>
                    <?php foreach ($leave_types as $type): ?>
                        <option value="<?= $type['type_id'] ?>"><?= htmlspecialchars($type['type_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">เริ่มวันที่ <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" required onchange="calculateDays()">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">ถึงวันที่ <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" required onchange="calculateDays()">
                </div>
            </div>

            <div class="mb-3 text-center d-none" id="daysCountBox">
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-3 py-2 fs-6 rounded-pill" id="daysCountText">รวม 0 วัน</span>
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">เหตุผลการลา / สถานที่ติดต่อ <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control form-control-sm" rows="3" required placeholder="ระบุเหตุผลและสถานที่ติดต่อระหว่างลา..."></textarea>
            </div>

            <!-- กล่องอัปโหลดไฟล์ (ซ่อนตามค่าเริ่มต้น) -->
            <div id="attachmentBox" class="mb-3 bg-danger-subtle p-3 rounded-4 border d-none">
                <label class="form-label fw-bold text-sm text-danger"><i class="fa-solid fa-paperclip me-1"></i>ใบรับรองแพทย์ <span class="text-danger">*</span></label>
                <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <small class="text-danger extra-small mt-1 d-block">* จำเป็นสำหรับการลาป่วยตั้งแต่ 3 วันขึ้นไป</small>
            </div>

            <div class="mb-4 text-center">
                <p class="small text-muted mb-2">ลายเซ็นดิจิทัลที่จะประทับ:</p>
                <div class="p-2 bg-light rounded-3 d-inline-block border">
                    <img src="../uploads/signatures/<?= htmlspecialchars($user_data['signature_file']) ?>" alt="Signature" class="img-fluid" style="max-height: 50px;">
                </div>
                <div class="mt-1 extra-small text-purple fw-bold"><i class="fa-solid fa-shield-halved me-1"></i>ประทับตราอัตโนมัติเมื่อส่ง</div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
                <i class="fa-solid fa-paper-plane me-2"></i> ยืนยันส่งใบลา
            </button>
        </form>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<script>
function calculateDays() {
    const start = document.getElementById('start_date').value;
    const end = document.getElementById('end_date').value;
    const daysBox = document.getElementById('daysCountBox');
    
    if(start && end) {
        const d1 = new Date(start);
        const d2 = new Date(end);
        if(d2 >= d1) {
            const diffTime = Math.abs(d2 - d1);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            document.getElementById('daysCountText').innerText = `รวม ${diffDays} วัน`;
            daysBox.classList.remove('d-none');
            checkLeaveType(diffDays);
        } else {
            daysBox.classList.add('d-none');
        }
    }
}

function checkLeaveType(days = 0) {
    const typeSelect = document.getElementById('type_id');
    const attachmentBox = document.getElementById('attachmentBox');
    const attachmentInput = document.getElementById('attachment');
    
    if(typeSelect.options[typeSelect.selectedIndex] && typeSelect.options[typeSelect.selectedIndex].text.includes('ป่วย') && days >= 3) {
        attachmentBox.classList.remove('d-none');
        attachmentInput.required = true;
    } else {
        attachmentBox.classList.add('d-none');
        attachmentInput.required = false;
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
