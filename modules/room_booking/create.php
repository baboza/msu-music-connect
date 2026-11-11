<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id']; // รหัสบุคลากร/อาจารย์ที่ล็อกอิน
$msg = '';
$msg_type = '';

// =========================================================
// 1. จัดการการบันทึกข้อมูล (POST Request)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_id = intval($_POST['room_id']);
    $booking_date = $_POST['booking_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $purpose = trim($_POST['purpose']);

    try {
        // [A] ตรวจสอบเวลา: เวลาเริ่มต้องก่อนเวลาสิ้นสุด
        if ($start_time >= $end_time) {
            $msg = "ช่วงเวลาไม่ถูกต้อง: เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด";
            $msg_type = "danger";
        } else {
            // [B] ตรวจสอบการจองซ้ำ (Overlap Check)
            // เช็คว่าในห้องนั้น วันนั้น มีการจองที่อนุมัติแล้วหรือรอพิจารณาที่เวลาทับกันหรือไม่
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
                $msg = "ไม่สามารถจองได้: ห้องนี้ถูกจองในช่วงเวลาดังกล่าวแล้ว";
                $msg_type = "warning";
            } else {
                // [C] บันทึกข้อมูล (สถานะบุคลากรจอง ให้เป็น pending รอเจ้าหน้าที่อาคารอนุมัติ)
                $stmt_insert = $pdo->prepare("
                    INSERT INTO room_bookings (user_id, room_id, booking_date, start_time, end_time, purpose, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt_insert->execute([$user_id, $room_id, $booking_date, $start_time, $end_time, $purpose]);
                
                echo "<script>
                        alert('ส่งคำขอจองห้องเรียบร้อยแล้ว กรุณารอการอนุมัติ');
                        window.location.href = 'index.php';
                      </script>";
                exit();
            }
        }
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// =========================================================
// 2. ดึงรายชื่อห้องที่ "พร้อมใช้งาน (Active)" เท่านั้น
// =========================================================
$rooms = $pdo->query("SELECT * FROM rooms WHERE status = 'active' ORDER BY room_code ASC")->fetchAll(PDO::FETCH_ASSOC);

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-calendar-plus me-2"></i>จองห้องใช้งาน (บุคลากร/อาจารย์)</h1>
            <p class="text-muted small mb-0">บันทึกคำขอจองห้องเรียน ห้องซ้อม หรือห้องประชุม</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-list me-1"></i> รายรายการจองทั้งหมด
        </a>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0 rounded-4 d-flex align-items-center p-3 animate__animated animate__shakeX mb-4">
            <i class="fa-solid fa-circle-exclamation fa-2x me-3"></i>
            <div class="fw-bold"><?php echo $msg; ?></div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="fw-bold text-purple mb-0"><i class="fa-solid fa-file-pen me-2"></i>กรอกรายละเอียดการจอง</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST">
                        <div class="row g-4">
                            
                            <div class="col-md-12">
                                <label class="form-label fw-bold text-dark small">เลือกห้องที่ต้องการ <span class="text-danger">*</span></label>
                                <select name="room_id" class="form-select bg-light border-0 py-3 shadow-sm select2" required>
                                    <option value="" disabled selected>-- ค้นหาชื่อห้องหรือรหัสห้อง --</option>
                                    <?php foreach($rooms as $rm): ?>
                                        <option value="<?php echo $rm['room_id']; ?>">
                                            <?php echo "[".$rm['room_code']."] ".$rm['room_name']." (ความจุ ".$rm['capacity']." คน)"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark small">วันที่ต้องการใช้งาน <span class="text-danger">*</span></label>
                                <input type="date" name="booking_date" class="form-control bg-light border-0 py-3 shadow-sm" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark small">เวลาเริ่ม <span class="text-danger">*</span></label>
                                <input type="time" name="start_time" class="form-control bg-light border-0 py-3 shadow-sm" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark small">เวลาสิ้นสุด <span class="text-danger">*</span></label>
                                <input type="time" name="end_time" class="form-control bg-light border-0 py-3 shadow-sm" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold text-dark small">วัตถุประสงค์ / กิจกรรมที่ใช้งาน <span class="text-danger">*</span></label>
                                <textarea name="purpose" class="form-control bg-light border-0 py-3 shadow-sm" rows="3" placeholder="ระบุเหตุผล เช่น ใช้สอนวิชา... / ประชุมคณะกรรมการ..." required></textarea>
                            </div>

                        </div>

                        <div class="mt-5 p-4 rounded-4" style="background-color: #fff9e6; border-left: 5px solid var(--msu-gold);">
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-circle-info me-2 text-warning"></i>ระเบียบการจองห้อง</h6>
                            <ul class="small text-muted mb-0">
                                <li>การจองจะสมบูรณ์เมื่อได้รับการอนุมัติจากงานอาคารสถานที่</li>
                                <li>กรุณาจองล่วงหน้าอย่างน้อย 1 วันทำการ</li>
                                <li>หากต้องการยกเลิก กรุณาดำเนินการในระบบก่อนเวลาใช้งานอย่างน้อย 2 ชั่วโมง</li>
                            </ul>
                        </div>

                        <div class="text-center mt-5">
                            <button type="submit" class="btn btn-purple px-5 py-3 shadow-sm rounded-pill hover-lift fw-bold">
                                <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอจองห้อง
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; border: none; transition: all 0.3s; }
    .btn-purple:hover { background-color: #2a004d; color: white; transform: translateY(-2px); }
    
    .form-control:focus, .form-select:focus {
        box-shadow: 0 0 0 0.25rem rgba(57, 4, 143, 0.15);
        border: 1px solid var(--msu-purple) !important;
        background-color: #fff !important;
    }
    
    .hover-lift { transition: all 0.2s; }
    .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(57, 4, 143, 0.15) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>