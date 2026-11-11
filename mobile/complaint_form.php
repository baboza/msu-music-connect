<?php
require_once 'auth_check.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $is_anonymous = isset($_POST['is_anonymous']);
    $student_id_val = $is_anonymous ? 'anonymous' : $user_id;

    if (empty($subject) || empty($details)) {
        $msg = "กรุณากรอกหัวข้อและรายละเอียดข้อเสนอแนะ";
        $msg_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO complaints (student_id, subject, details, status, created_at, updated_at) 
                VALUES (?, ?, ?, 'pending', NOW(), NOW())
            ");
            $stmt->execute([$student_id_val, $subject, $details]);
            $msg = "ส่งเรื่องร้องเรียน/ข้อเสนอแนะ เรียบร้อยแล้ว ขอบคุณสำหรับข้อมูลเพื่อการพัฒนาคณะ";
            $msg_type = "success";
            echo "<script>setTimeout(()=>window.location.href='complaint.php', 1200);</script>";
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

require_once 'includes/header.php';
?>

<div class="mt-2">
    <!-- Sub-page Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="complaint.php" class="mobile-back-btn me-2" hx-boost="true">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">แจ้งเรื่องร้องเรียน / ข้อเสนอแนะ</h5>
            <small class="text-muted">ส่งตรงถึงผู้บริหารและคณบดี</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= $msg_type==='success'?'fa-circle-check':'fa-triangle-exclamation' ?> me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="complaint_form.php">
        <div class="bento-box p-4 mb-3">
            <h6 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-circle-info text-purple me-2"></i>ข้อมูลเรื่องที่ต้องการแจ้ง
            </h6>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">หัวข้อเรื่อง / ปัญหาที่พบ <span class="text-danger">*</span></label>
                <input type="text" name="subject" class="form-control form-control-sm" required placeholder="เช่น ปัญหาแอร์ห้องซ้อม, ข้อเสนอแนะการให้บริการ...">
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">รายละเอียดโดยละเอียด <span class="text-danger">*</span></label>
                <textarea name="details" class="form-control form-control-sm" rows="5" required placeholder="อธิบายรายละเอียด วัน เวลา สถานที่ หรือเหตุการณ์ที่เกิดขึ้นอย่างชัดเจน..."></textarea>
            </div>

            <div class="form-check p-2 rounded-3 bg-light border mt-3">
                <input class="form-check-input ms-1 me-2" type="checkbox" name="is_anonymous" id="anonCheck">
                <label class="form-check-label small fw-bold text-dark cursor-pointer" for="anonCheck">
                    <i class="fa-solid fa-user-secret text-purple me-1"></i> ไม่ประสงค์เปิดเผยชื่อผู้แจ้ง (ส่งแบบนิรนาม)
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid fa-paper-plane me-2"></i> ส่งข้อเสนอแนะ / ร้องเรียน
        </button>
    </form>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
