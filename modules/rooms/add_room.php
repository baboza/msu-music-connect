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
// จัดการเมื่อมีการกดปุ่ม "บันทึกข้อมูล" (POST Request)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_code = trim($_POST['room_code']);
    $room_name = trim($_POST['room_name']);
    $room_type = trim($_POST['room_type']);
    $capacity = intval($_POST['capacity']);
    $equipment_details = trim($_POST['equipment_details']);
    $status = trim($_POST['status']);

    try {
        // 1. เช็คว่ามีรหัสห้องนี้อยู่ในระบบแล้วหรือยัง
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code = ?");
        $stmt_check->execute([$room_code]);
        if ($stmt_check->fetchColumn() > 0) {
            $msg = "ไม่สามารถบันทึกได้! รหัสห้อง '{$room_code}' มีอยู่ในระบบแล้ว";
            $msg_type = "warning";
        } else {
            // 2. ถ้าไม่ซ้ำ ให้ Insert ลงตาราง rooms
            $stmt_insert = $pdo->prepare("
                INSERT INTO rooms (room_code, room_name, room_type, capacity, equipment_details, status) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->execute([$room_code, $room_name, $room_type, $capacity, $equipment_details, $status]);
            
            // 3. บันทึกสำเร็จ ให้ Redirect กลับไปหน้าแรกพร้อมข้อความ
            echo "<script>
                    alert('เพิ่มข้อมูลห้องใหม่เรียบร้อยแล้ว');
                    window.location.href = 'index.php';
                  </script>";
            exit();
        }
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// โหลด Layout
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-square-plus me-2"></i>เพิ่มข้อมูลห้องใหม่</h1>
            <p class="text-muted small mb-0">เพิ่มรายชื่อห้องเรียน ห้องซ้อม หรือสตูดิโอ เข้าสู่ระบบฐานข้อมูล</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm hover-lift">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก
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
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="fw-bold text-purple mb-0"><i class="fa-solid fa-list-check me-2"></i>แบบฟอร์มบันทึกข้อมูลห้อง</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <form action="" method="POST">
                        <div class="row g-4">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">รหัสห้อง (Room Code) <span class="text-danger">*</span></label>
                                <input type="text" name="room_code" class="form-control bg-light border-0" placeholder="เช่น MS-101" required autofocus value="<?php echo isset($_POST['room_code']) ? htmlspecialchars($_POST['room_code']) : ''; ?>">
                            </div>
                            
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-muted small">ชื่อห้อง (Room Name) <span class="text-danger">*</span></label>
                                <input type="text" name="room_name" class="form-control bg-light border-0" placeholder="เช่น ห้องซ้อมเปียโน 1, ห้องเรียนรวม" required value="<?php echo isset($_POST['room_name']) ? htmlspecialchars($_POST['room_name']) : ''; ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">ประเภทการใช้งาน <span class="text-danger">*</span></label>
                                <select name="room_type" class="form-select bg-light border-0" required>
                                    <option value="" disabled selected>-- เลือกประเภท --</option>
                                    <option value="practice" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'practice') ? 'selected' : ''; ?>>ห้องซ้อม (Practice)</option>
                                    <option value="lecture" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'lecture') ? 'selected' : ''; ?>>ห้องบรรยาย (Lecture)</option>
                                    <option value="meeting" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'meeting') ? 'selected' : ''; ?>>ห้องประชุม (Meeting)</option>
                                    <option value="studio" <?php echo (isset($_POST['room_type']) && $_POST['room_type'] == 'studio') ? 'selected' : ''; ?>>สตูดิโอ (Studio)</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">ความจุ (คน) <span class="text-danger">*</span></label>
                                <input type="number" name="capacity" class="form-control bg-light border-0" placeholder="เช่น 2, 50" min="1" required value="<?php echo isset($_POST['capacity']) ? htmlspecialchars($_POST['capacity']) : ''; ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-muted small">สถานะเริ่มต้น <span class="text-danger">*</span></label>
                                <select name="status" class="form-select bg-light border-0" required>
                                    <option value="active" selected>พร้อมใช้งาน (Active)</option>
                                    <option value="maintenance">กำลังซ่อมแซม (Maintenance)</option>
                                    <option value="inactive">ปิดใช้งานชั่วคราว (Inactive)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold text-muted small">รายการอุปกรณ์สิ่งอำนวยความสะดวก (Equipment Details)</label>
                                <textarea name="equipment_details" class="form-control bg-light border-0" rows="3" placeholder="เช่น เปียโน Yamaha 1 หลัง, แอมป์กีตาร์ Fender, โปรเจคเตอร์ (มีผลต่อการตัดสินใจเวลาจองหรือแจ้งซ่อม)"><?php echo isset($_POST['equipment_details']) ? htmlspecialchars($_POST['equipment_details']) : ''; ?></textarea>
                            </div>

                        </div>

                        <hr class="opacity-10 my-5">

                        <div class="text-center">
                            <button type="submit" class="btn btn-purple px-5 py-3 shadow-sm rounded-pill hover-lift fw-bold" style="font-size: 1.1rem;">
                                <i class="fa-solid fa-floppy-disk me-2"></i> บันทึกข้อมูลห้อง
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
    .btn-purple { background-color: var(--msu-purple); color: white; border: none; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .text-gold { color: var(--msu-gold); }
    
    .form-control:focus, .form-select:focus {
        box-shadow: 0 0 0 0.25rem rgba(57, 4, 143, 0.25);
        border: 1px solid var(--msu-purple) !important;
        background-color: #fff !important;
    }
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.1) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>