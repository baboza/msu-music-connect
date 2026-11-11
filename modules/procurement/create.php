<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เข้าได้เฉพาะ admin และ งานพัสดุ)
if (!hasRole(['admin', 'งานพัสดุ'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: เฉพาะเจ้าหน้าที่พัสดุเท่านั้นที่สามารถเพิ่มรายการได้'); window.location.href='index.php';</script>";
    exit();
}

$error_msg = "";

// 2. ดึงรายการปีงบประมาณจากระบบที่แอดมินตั้งค่าไว้ (ตาราง fiscal_years)
$stmt_fy_list = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years_db = $stmt_fy_list->fetchAll(PDO::FETCH_ASSOC);

$active_fy = '';
$available_years = [];
foreach ($fiscal_years_db as $fy_item) {
    $available_years[] = $fy_item['year_name'];
    if ($fy_item['is_active'] == 1 && empty($active_fy)) {
        $active_fy = $fy_item['year_name'];
    }
}
if (empty($active_fy)) {
    $active_fy = !empty($available_years) ? $available_years[0] : (date('Y') + 543);
}
if (empty($available_years)) {
    $available_years = [$active_fy];
}

// 3. ดึงรายชื่อบุคลากรที่ Active เพื่อนำมาใส่ใน Dropdown ผู้รับผิดชอบ
$stmt_users = $pdo->query("SELECT user_id, prefix_name, first_name, last_name, position FROM users WHERE status = 'active' ORDER BY first_name ASC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// 4. จัดการเมื่อมีการกดปุ่มบันทึก (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $fiscal_year = !empty($_POST['fiscal_year']) ? trim($_POST['fiscal_year']) : $active_fy;
        $project_name = trim($_POST['project_name']);
        $procurement_method = trim($_POST['procurement_method']);
        $responsible_user_id = trim($_POST['responsible_user_id']);
        $budget = floatval($_POST['budget']);
        $item_type = trim($_POST['item_type']);
        $budget_type = trim($_POST['budget_type']);
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : NULL;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : NULL;
        $remark = trim($_POST['remark']);

        // ตรวจสอบความถูกต้องพื้นฐาน
        if (empty($project_name) || empty($responsible_user_id) || $budget <= 0) {
            throw new Exception("กรุณากรอกข้อมูลที่จำเป็น (ชื่อรายการ, ผู้รับผิดชอบ, งบประมาณ) ให้ครบถ้วนและถูกต้อง");
        }

        // เพิ่มข้อมูลลงฐานข้อมูล
        $sql = "INSERT INTO procurement_tracking 
                (fiscal_year, project_name, procurement_method, responsible_user_id, budget, item_type, budget_type, start_date, end_date, remark) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $fiscal_year, $project_name, $procurement_method, $responsible_user_id, 
            $budget, $item_type, $budget_type, $start_date, $end_date, $remark
        ]);

        $_SESSION['msg'] = "เพิ่มรายการติดตามงานพัสดุเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
        
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-primary fw-bold"><i class="fa-solid fa-file-circle-plus me-2"></i>เพิ่มรายการจัดซื้อจัดจ้าง</h1>
            <p class="text-muted mb-0">บันทึกข้อมูลตั้งต้นเพื่อเริ่มติดตามสถานะงานพัสดุ</p>
        </div>
        <a href="index.php" class="btn btn-light border rounded-pill px-4 shadow-sm fw-bold">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom-0 fw-bold text-primary">
            <i class="fa-solid fa-clipboard-list me-2"></i> ฟอร์มข้อมูลโครงการ/การจัดซื้อ
        </div>
        <div class="card-body p-4 bg-light">
            <form action="" method="POST">
                
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. ข้อมูลหลัก</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">ปีงบประมาณ <span class="text-danger">*</span></label>
                        <select name="fiscal_year" class="form-select shadow-sm border-primary" required>
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ($y == $active_fy) ? 'selected' : ''; ?>>
                                    ปี <?php echo htmlspecialchars($y); ?> <?php echo ($y == $active_fy) ? '(ปัจจุบัน)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small fw-bold">ชื่อรายการจัดซื้อ/จัดจ้าง <span class="text-danger">*</span></label>
                        <input type="text" name="project_name" class="form-control shadow-sm border-primary" required placeholder="เช่น ซื้อคอมพิวเตอร์ประจำสำนักงาน จำนวน 5 เครื่อง">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold">งบประมาณตั้งต้น (บาท) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm shadow-sm">
                            <span class="input-group-text bg-white">฿</span>
                            <input type="number" name="budget" class="form-control border-primary" step="0.01" min="1" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ผู้รับผิดชอบโครงการ <span class="text-danger">*</span></label>
                        <select name="responsible_user_id" class="form-select shadow-sm border-primary" required>
                            <option value="">-- เลือกผู้รับผิดชอบ --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo htmlspecialchars($u['user_id']); ?>">
                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . ($u['position'] ?: 'ไม่ระบุตำแหน่ง') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">วิธีการจัดซื้อจัดจ้าง</label>
                        <select name="procurement_method" class="form-select shadow-sm">
                            <option value="เฉพาะเจาะจง">เฉพาะเจาะจง</option>
                            <option value="e-bidding">e-bidding (ประกวดราคาอิเล็กทรอนิกส์)</option>
                            <option value="คัดเลือก">คัดเลือก</option>
                            <option value="ประกาศเชิญชวนทั่วไป">ประกาศเชิญชวนทั่วไป</option>
                            <option value="จ้างที่ปรึกษา">จ้างที่ปรึกษา</option>
                            <option value="อื่นๆ">อื่นๆ</option>
                        </select>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. ประเภทและระยะเวลา</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ประเภทพัสดุ/ครุภัณฑ์</label>
                        <select name="item_type" class="form-select shadow-sm">
                            <option value="วัสดุสำนักงาน">วัสดุสำนักงาน</option>
                            <option value="วัสดุคอมพิวเตอร์">วัสดุคอมพิวเตอร์</option>
                            <option value="ครุภัณฑ์คอมพิวเตอร์">ครุภัณฑ์คอมพิวเตอร์</option>
                            <option value="ครุภัณฑ์สำนักงาน">ครุภัณฑ์สำนักงาน</option>
                            <option value="ครุภัณฑ์การศึกษา">ครุภัณฑ์การศึกษา</option>
                            <option value="จ้างเหมาบริการ">จ้างเหมาบริการ</option>
                            <option value="งานก่อสร้าง/ปรับปรุง">งานก่อสร้าง/ปรับปรุง</option>
                            <option value="ครุภัณฑ์ดนตรีและนาฏศิลป์">ครุภัณฑ์ดนตรีและนาฏศิลป์</option>
                            <option value="รายการปรับปรุง">รายการปรับปรุง</option>
                            <option value="สิ่งก่อสร้าง 1 ปี">สิ่งก่อสร้าง 1 ปี</option>
                            <option value="ครุภัณฑ์ยานพาหนะ">ครุภัณฑ์ยานพาหนะ</option>
                            <option value="ครุภัณฑ์ไฟฟ้าและวิทยุ">ครุภัณฑ์ไฟฟ้าและวิทยุ</option>
                            <option value="ครุภัณฑ์โฆษณาและเผยแพร่">ครุภัณฑ์โฆษณาและเผยแพร่</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ประเภทแหล่งเงิน</label>
                        <select name="budget_type" class="form-select shadow-sm">
                            <option value="เงินงบประมาณแผ่นดิน">เงินงบประมาณแผ่นดิน</option>
                            <option value="เงินรายได้มหาวิทยาลัย">เงินรายได้มหาวิทยาลัย</option>
                            <option value="เงินบริจาค/เงินอุดหนุน">เงินบริจาค/เงินอุดหนุน</option>
                            <option value="อื่นๆ">อื่นๆ</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">วันที่เริ่มดำเนินการ</label>
                        <input type="date" name="start_date" class="form-control shadow-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">วันที่สิ้นสุด (กำหนดส่ง)</label>
                        <input type="date" name="end_date" class="form-control shadow-sm">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">หมายเหตุเพิ่มเติม</label>
                        <textarea name="remark" class="form-control shadow-sm" rows="2" placeholder="รายละเอียดเพิ่มเติมที่ต้องการบันทึกไว้..."></textarea>
                    </div>
                </div>

                <div class="text-end pt-3 border-top">
                    <button type="reset" class="btn btn-light border rounded-pill px-4 me-2">ล้างค่า</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                        <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูลและเริ่มติดตาม
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
    .border-primary { border-color: #0d6efd !important; }
    .form-control:focus, .form-select:focus { 
        border-color: #0d6efd; 
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); 
    }
</style>

<?php require_once '../../includes/footer.php'; ?>