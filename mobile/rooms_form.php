<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_id = intval($_POST['room_id']);
    $booking_date = $_POST['booking_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $purpose = trim($_POST['purpose']);

    try {
        if ($start_time >= $end_time) {
            $msg = "เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด";
            $msg_type = "danger";
        } else {
            // เช็คว่าห้องทับกันไหม
            $sql_check = "SELECT COUNT(*) FROM room_bookings 
                          WHERE room_id = ? AND booking_date = ? 
                          AND status IN ('pending', 'approved')
                          AND (
                              (start_time <= ? AND end_time > ?) OR 
                              (start_time < ? AND end_time >= ?) OR 
                              (? <= start_time AND ? > start_time)
                          )";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([$room_id, $booking_date, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
            
            if ($stmt_check->fetchColumn() > 0) {
                $msg = "ห้องนี้ถูกจองในช่วงเวลาดังกล่าวแล้ว";
                $msg_type = "warning";
            } else {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO room_bookings (user_id, room_id, booking_date, start_time, end_time, purpose, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt_insert->execute([$user_id, $room_id, $booking_date, $start_time, $end_time, $purpose]);
                
                $msg = "ส่งคำขอจองห้องเรียบร้อยแล้ว";
                $msg_type = "success";
                
                echo "<script>setTimeout(()=>window.location.href='rooms.php', 1200);</script>";
            }
        }
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงห้องทั้งหมดที่ active
$rooms = $pdo->query("SELECT * FROM rooms WHERE status = 'active' ORDER BY room_code ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="rooms.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าจองห้อง">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">จองห้อง/สถานที่ใหม่</h5>
            <small class="text-muted">ยื่นขอใช้ห้องเรียน ห้องประชุม หรือสถานที่</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-calendar-plus"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="bento-box p-4" id="roomForm">
        
        <div class="mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">เลือกห้อง/สถานที่ <span class="text-danger">*</span></label>
            <select name="room_id" class="form-select form-select-sm" required>
                <option value="" disabled selected>-- เลือกห้อง --</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['room_id'] ?>">
                        <?= htmlspecialchars($r['room_code'] . ' - ' . $r['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">วันที่ต้องการใช้ <span class="text-danger">*</span></label>
            <input type="date" name="booking_date" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label small text-muted mb-1 fw-bold">ตั้งแต่เวลา <span class="text-danger">*</span></label>
                <input type="time" name="start_time" class="form-control form-control-sm" required>
            </div>
            <div class="col-6">
                <label class="form-label small text-muted mb-1 fw-bold">ถึงเวลา <span class="text-danger">*</span></label>
                <input type="time" name="end_time" class="form-control form-control-sm" required>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label small text-muted mb-1 fw-bold">วัตถุประสงค์การใช้งาน <span class="text-danger">*</span></label>
            <textarea name="purpose" class="form-control form-control-sm" rows="3" required placeholder="เช่น การเรียนการสอน, ประชุมคณะกรรมการ..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid fa-paper-plane me-2"></i> ยืนยันคำขอจองห้อง
        </button>
    </form>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
