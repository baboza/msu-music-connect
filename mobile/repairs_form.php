<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_id = $_POST['room_id'];
    $equipment_name = trim($_POST['equipment_name']);
    $problem_desc = trim($_POST['problem_desc']);
    $damage_level = $_POST['damage_level'];
    
    try {
        $sql = "INSERT INTO repair_requests (reporter_id, room_id, equipment_name, problem_desc, damage_level, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $room_id, $equipment_name, $problem_desc, $damage_level]);
        
        $msg = "ส่งข้อมูลการแจ้งซ่อมเรียบร้อยแล้ว";
        $msg_type = "success";
        
        echo "<script>setTimeout(()=>window.location.href='repairs.php', 1200);</script>";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงข้อมูลห้องทั้งหมดที่ active
$rooms = $pdo->query("SELECT * FROM rooms WHERE status != 'inactive' ORDER BY room_code ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="repairs.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าระบบแจ้งซ่อม">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">แจ้งซ่อมบำรุงใหม่</h5>
            <small class="text-muted">แจ้งเหตุอุปกรณ์หรืออาคารสถานที่ชำรุด</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-screwdriver-wrench"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="bento-box p-4" id="repairForm">
        
        <div class="mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">สถานที่ / ห้องที่เกิดปัญหา <span class="text-danger">*</span></label>
            <select name="room_id" class="form-select form-select-sm" required>
                <option value="" disabled selected>-- เลือกสถานที่ --</option>
                <?php foreach($rooms as $rm): ?>
                    <option value="<?= $rm['room_id'] ?>">
                        <?= htmlspecialchars("[".$rm['room_code']."] ".$rm['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">อุปกรณ์ที่ชำรุด <span class="text-danger">*</span></label>
            <input type="text" name="equipment_name" class="form-control form-control-sm" placeholder="เช่น แอร์, หลอดไฟ, ปลั๊กไฟ..." required>
        </div>

        <div class="mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">ระดับความรุนแรง <span class="text-danger">*</span></label>
            <div class="d-flex flex-column gap-2 mt-2">
                <div class="form-check custom-radio border p-2 rounded-3 bg-light">
                    <input class="form-check-input" type="radio" name="damage_level" id="levelLow" value="low">
                    <label class="form-check-label text-slate-700 fw-bold" for="levelLow">เล็กน้อย <span class="text-muted fw-normal small">- ใช้งานได้อยู่</span></label>
                </div>
                <div class="form-check custom-radio border p-2 rounded-3 bg-light">
                    <input class="form-check-input" type="radio" name="damage_level" id="levelMed" value="medium" checked>
                    <label class="form-check-label text-warning-emphasis fw-bold" for="levelMed">ปานกลาง <span class="text-muted fw-normal small">- ควรเร่งซ่อม</span></label>
                </div>
                <div class="form-check custom-radio border p-2 rounded-3 bg-danger-subtle border-danger">
                    <input class="form-check-input" type="radio" name="damage_level" id="levelCrit" value="critical">
                    <label class="form-check-label text-danger fw-bold" for="levelCrit">วิกฤต <span class="text-muted fw-normal small">- ใช้งานไม่ได้ / อาจเกิดอันตราย</span></label>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label small text-muted mb-1 fw-bold">รายละเอียดอาการเสีย <span class="text-danger">*</span></label>
            <textarea name="problem_desc" class="form-control form-control-sm" rows="3" placeholder="ระบุรายละเอียด เช่น เปิดไม่ติด, มีเสียงดัง, น้ำรั่วซึม..." required></textarea>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอแจ้งซ่อม
        </button>
    </form>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
