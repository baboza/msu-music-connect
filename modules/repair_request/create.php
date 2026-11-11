<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// ดึงข้อมูลห้องทั้งหมด
$rooms = $pdo->query("SELECT * FROM rooms WHERE status != 'inactive' ORDER BY room_code ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $room_id = $_POST['room_id'];
    $equipment_name = trim($_POST['equipment_name']);
    $problem_desc = trim($_POST['problem_desc']);
    $damage_level = $_POST['damage_level'];
    
    // เอาการอัปโหลดรูปภาพออก
    $image_name = NULL;

    try {
        $sql = "INSERT INTO repair_requests (reporter_id, room_id, equipment_name, problem_desc, damage_level, image_file, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $room_id, $equipment_name, $problem_desc, $damage_level, $image_name]);
        
        echo "<script>alert('ส่งข้อมูลการแจ้งซ่อมเรียบร้อยแล้ว'); window.location.href='index.php';</script>";
        exit();
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-tools me-2"></i>แจ้งซ่อมอุปกรณ์/สถานที่</h1>
            <p class="text-muted small mb-0">สำหรับบุคลากรและอาจารย์ รายงานความเสียหายเพื่อดำเนินการซ่อมบำรุง</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-list me-1"></i> รายการแจ้งซ่อมทั้งหมด
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">สถานที่ / ห้องที่เกิดปัญหา <span class="text-danger">*</span></label>
                                <select name="room_id" class="form-select bg-light border-0 py-2" required>
                                    <option value="" disabled selected>-- กรุณาเลือกห้อง --</option>
                                    <?php foreach($rooms as $rm): ?>
                                        <option value="<?php echo $rm['room_id']; ?>"><?php echo "[".$rm['room_code']."] ".$rm['room_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">อุปกรณ์ที่ชำรุด <span class="text-danger">*</span></label>
                                <input type="text" name="equipment_name" class="form-control bg-light border-0 py-2" placeholder="เช่น แอร์, หลอดไฟ, ปลั๊กไฟ, ขาตั้งโน้ต" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">ระดับความรุนแรง <span class="text-danger">*</span></label>
                                <select name="damage_level" class="form-select bg-light border-0 py-2" required>
                                    <option value="low">เล็กน้อย (Low) - ใช้งานได้อยู่</option>
                                    <option value="medium" selected>ปานกลาง (Medium) - ควรเร่งซ่อม</option>
                                    <option value="critical">วิกฤต (Critical) - ใช้งานไม่ได้/อันตราย</option>
                                </select>
                            </div>



                            <div class="col-12">
                                <label class="form-label fw-bold text-dark">รายละเอียดอาการเสีย <span class="text-danger">*</span></label>
                                <textarea name="problem_desc" class="form-control bg-light border-0 py-2" rows="4" placeholder="ระบุรายละเอียดเพิ่มเติม เช่น เปิดไม่ติด, มีเสียงดัง, น้ำรั่ว..." required></textarea>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <button type="submit" class="btn btn-purple px-5 py-3 rounded-pill fw-bold shadow-sm hover-lift">
                                <i class="fa-solid fa-paper-plane me-2"></i> ส่งข้อมูลแจ้งซ่อม
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once '../../includes/footer.php'; ?>