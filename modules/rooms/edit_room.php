<?php
// เปิด Error ชั่วคราว (เอาออกตอนใช้งานจริง)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// =========================================================
// 1. ตรวจสอบว่ามีการส่ง ID ห้องมาหรือไม่
// =========================================================
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ไม่พบรหัสอ้างอิงห้อง'); window.location.href='index.php';</script>";
    exit();
}

$room_id = intval($_GET['id']);

// =========================================================
// 2. จัดการเมื่อมีการกดปุ่ม "บันทึกการเปลี่ยนแปลง" (POST Request)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_code = trim($_POST['room_code']);
    $room_name = trim($_POST['room_name']);
    $room_type = trim($_POST['room_type']);
    $capacity = intval($_POST['capacity']);
    $equipment_details = trim($_POST['equipment_details']);
    $status = trim($_POST['status']);

    try {
        // เช็คว่าแก้ไขรหัสห้องไปซ้ำกับห้อง "อื่น" ในระบบหรือไม่
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code = ? AND room_id != ?");
        $stmt_check->execute([$room_code, $room_id]);
        
        if ($stmt_check->fetchColumn() > 0) {
            $msg = "ไม่สามารถบันทึกได้! รหัสห้อง '{$room_code}' มีใช้งานอยู่แล้วในระบบ";
            $msg_type = "warning";
        } else {
            // อัปเดตข้อมูลลง Database
            $stmt_update = $pdo->prepare("
                UPDATE rooms 
                SET room_code = ?, room_name = ?, room_type = ?, capacity = ?, equipment_details = ?, status = ?
                WHERE room_id = ?
            ");
            $stmt_update->execute([$room_code, $room_name, $room_type, $capacity, $equipment_details, $status, $room_id]);
            
            echo "<script>
                    alert('อัปเดตข้อมูลห้องเรียบร้อยแล้ว');
                    window.location.href = 'index.php';
                  </script>";
            exit();
        }
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาดในการอัปเดตข้อมูล: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// =========================================================
// 3. ดึงข้อมูลห้องปัจจุบันมาแสดงในฟอร์ม
// =========================================================
$stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
$stmt->execute([$room_id]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    echo "<script>alert('ไม่พบข้อมูลห้องในระบบ'); window.location.href='index.php';</script>";
    exit();
}

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขข้อมูลห้อง</h1>
            <p class="text-muted small mb-0">แก้ไขรายละเอียด, อุปกรณ์ หรือเปลี่ยนสถานะการใช้งานของห้อง</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm hover-lift">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>

    <?php if(!empty($msg)): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0 rounded-4 d-flex align-items-center p-3 animate__animated animate__fadeInDown mb-4">
            <i class="fa-solid <?php echo $msg_type == 'warning' ? 'fa-triangle-exclamation text-warning' : 'fa-circle-xmark text-danger'; ?> fa-2x me-3"></i>
            <div class="fw-bold"><?php echo $msg; ?></div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-purple mb-0"><i class="fa-solid fa-sliders me-2"></i>ปรับปรุงข้อมูลห้อง</h5>
                    <span class="badge bg-light text-muted border px-3 py-2 rounded-pill">Room ID: <?php echo $room_id; ?></span>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <form action="" method="POST">
                        <div class="row g-4">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">รหัสห้อง (Room Code) <span class="text-danger">*</span></label>
                                <input type="text" name="room_code" class="form-control bg-light border-0 fw-bold text-primary" required 
                                       value="<?php echo htmlspecialchars(isset($_POST['room_code']) ? $_POST['room_code'] : $room['room_code']); ?>">
                            </div>
                            
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-muted small">ชื่อห้อง (Room Name) <span class="text-danger">*</span></label>
                                <input type="text" name="room_name" class="form-control bg-light border-0 fw-bold" required 
                                       value="<?php echo htmlspecialchars(isset($_POST['room_name']) ? $_POST['room_name'] : $room['room_name']); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">ประเภทการใช้งาน <span class="text-danger">*</span></label>
                                <?php $current_type = isset($_POST['room_type']) ? $_POST['room_type'] : $room['room_type']; ?>
                                <select name="room_type" class="form-select bg-light border-0" required>
                                    <option value="practice" <?php echo $current_type == 'practice' ? 'selected' : ''; ?>>ห้องซ้อม (Practice)</option>
                                    <option value="lecture" <?php echo $current_type == 'lecture' ? 'selected' : ''; ?>>ห้องบรรยาย (Lecture)</option>
                                    <option value="meeting" <?php echo $current_type == 'meeting' ? 'selected' : ''; ?>>ห้องประชุม (Meeting)</option>
                                    <option value="studio" <?php echo $current_type == 'studio' ? 'selected' : ''; ?>>สตูดิโอ (Studio)</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">ความจุ (คน) <span class="text-danger">*</span></label>
                                <input type="number" name="capacity" class="form-control bg-light border-0" min="1" required 
                                       value="<?php echo htmlspecialchars(isset($_POST['capacity']) ? $_POST['capacity'] : $room['capacity']); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark small">สถานะปัจจุบัน <span class="text-danger">*</span></label>
                                <?php $current_status = isset($_POST['status']) ? $_POST['status'] : $room['status']; ?>
                                <select name="status" class="form-select border-warning shadow-sm fw-bold" required>
                                    <option value="active" class="text-success" <?php echo $current_status == 'active' ? 'selected' : ''; ?>>✅ พร้อมใช้งาน (Active)</option>
                                    <option value="maintenance" class="text-warning" <?php echo $current_status == 'maintenance' ? 'selected' : ''; ?>>🔧 กำลังซ่อมแซม (Maintenance)</option>
                                    <option value="inactive" class="text-danger" <?php echo $current_status == 'inactive' ? 'selected' : ''; ?>>🚫 ปิดใช้งานชั่วคราว (Inactive)</option>
                                </select>
                                <div class="form-text mt-2 text-muted extra-small">
                                    <i class="fa-solid fa-circle-info me-1"></i> หากเปลี่ยนเป็นสถานะอื่นที่ไม่ใช่ Active นิสิตจะไม่สามารถจองห้องนี้ได้
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold text-muted small">รายการอุปกรณ์สิ่งอำนวยความสะดวก (Equipment Details)</label>
                                <textarea name="equipment_details" class="form-control bg-light border-0" rows="4"><?php echo htmlspecialchars(isset($_POST['equipment_details']) ? $_POST['equipment_details'] : $room['equipment_details']); ?></textarea>
                            </div>

                        </div>

                        <hr class="opacity-10 my-5">

                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted extra-small">
                                อัปเดตล่าสุด: <?php echo date('d/m/Y H:i', strtotime($room['updated_at'])); ?>
                            </div>
                            <button type="submit" class="btn btn-warning-custom px-5 py-3 shadow-sm rounded-pill hover-lift fw-bold text-dark" style="font-size: 1.1rem; background-color: var(--msu-gold); border: none;">
                                <i class="fa-solid fa-floppy-disk me-2"></i> บันทึกการเปลี่ยนแปลง
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
    .bg-purple { background-color: var(--msu-purple); }
    .btn-warning-custom:hover { background-color: #967a3f !important; color: white !important; }
    
    .form-control:focus, .form-select:focus {
        box-shadow: 0 0 0 0.25rem rgba(176, 145, 79, 0.25);
        border: 1px solid var(--msu-gold) !important;
        background-color: #fff !important;
    }
    
    .extra-small { font-size: 0.8rem; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>