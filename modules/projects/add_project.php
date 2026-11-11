<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$msg = ""; $msg_type = "";

// 1. ดึงรายชื่อผู้ใช้
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE status = 'active' ORDER BY first_name ASC");
$users = $stmt_users->fetchAll();

// 2. ดึงรายการปีงบประมาณที่กำหนดไว้ใน Settings (เรียงจากล่าสุดไปเก่าสุด)
$stmt_fiscal = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years = $stmt_fiscal->fetchAll();

// (กรณีตารางปีงบประมาณยังว่างเปล่า ให้สร้างปีปัจจุบันเผื่อไว้ 1 ปี)
if (empty($fiscal_years)) {
    $current_year_th = date('Y') + 543;
    $fiscal_years[] = ['year_name' => $current_year_th, 'is_active' => 1];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $resp_id = !empty($_POST['responsible_id']) ? $_POST['responsible_id'] : null;
        $coord_id = !empty($_POST['coordinator_id']) ? $_POST['coordinator_id'] : null;

        $sql = "INSERT INTO projects (
                    project_name, fiscal_year, responsible_id, coordinator_id, 
                    start_date, end_date, deadline, budget, status,
                    objectives, results, problems_suggestions
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['project_name'],
            $_POST['fiscal_year'],
            $resp_id,           
            $coord_id,          
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['deadline'],
            $_POST['budget'],
            'ยังไม่เริ่ม', // <--- บังคับสถานะเริ่มต้นอัตโนมัติ
            $_POST['objectives'],
            $_POST['results'] ?? '',
            $_POST['problems_suggestions'] ?? ''
        ]);

        $msg = "เพิ่มโครงการใหม่เรียบร้อยแล้ว!"; $msg_type = "success";
        echo "<script>setTimeout(() => { window.location.href = 'index.php'; }, 1500);</script>";
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage(); $msg_type = "danger";
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-folder-plus me-2"></i>สร้างโครงการใหม่</h1>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm"><i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก</a>
    </div>

    <?php if ($msg) echo "<div class='alert alert-$msg_type shadow-sm'><i class='fa-solid fa-check-circle me-2'></i>$msg</div>"; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <form action="" method="POST">
            <div class="card-body p-4 p-md-5">
                <div class="row g-4 mb-5">
                    <div class="col-12"><h5 class="fw-bold text-purple border-bottom pb-2 mb-4"><i class="fa-solid fa-circle-info me-2"></i>ข้อมูลโครงการทั่วไป</h5></div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-danger">ปีงบประมาณ <span class="text-danger">*</span></label>
                        <select name="fiscal_year" class="form-select shadow-sm border-danger-subtle fw-bold text-danger" required>
                            <?php foreach ($fiscal_years as $fy): ?>
                                <option value="<?php echo htmlspecialchars($fy['year_name']); ?>" <?php echo ($fy['is_active'] == 1) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($fy['year_name']); ?> <?php echo ($fy['is_active'] == 1) ? '(ปีปัจจุบัน)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-9"><label class="form-label small fw-bold">ชื่อโครงการ <span class="text-danger">*</span></label><input type="text" name="project_name" class="form-control shadow-sm border-purple-light" required></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">ผู้รับผิดชอบโครงการ <span class="text-danger">*</span></label>
                        <select name="responsible_id" class="form-select shadow-sm" required>
                            <option value="">-- เลือกผู้รับผิดชอบ --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>" <?php echo ($u['user_id'] == $_SESSION['user_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">ผู้ประสานงานโครงการ</label>
                        <select name="coordinator_id" class="form-select shadow-sm">
                            <option value="">-- เลือกผู้ประสานงาน (ถ้ามี) --</option>
                            <?php foreach ($users as $u) echo "<option value='{$u['user_id']}'>" . htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) . "</option>"; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-4 mb-5 bg-light p-4 rounded-4 mx-0">
                    <div class="col-12"><h5 class="fw-bold text-dark border-bottom pb-2 mb-4"><i class="fa-solid fa-calendar-days me-2"></i>ระยะเวลาและงบประมาณ</h5></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">วันที่เริ่มโครงการ <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control shadow-sm" required></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">วันที่สิ้นสุดโครงการ <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control shadow-sm" required></div>
                    <div class="col-md-4"><label class="form-label small fw-bold text-danger">เดดไลน์ส่งงาน <span class="text-danger">*</span></label><input type="date" name="deadline" class="form-control shadow-sm border-danger-subtle" required></div>
                    <div class="col-md-4 mt-3">
                        <label class="form-label small fw-bold text-purple">งบประมาณ (บาท) <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text bg-purple text-white border-0">฿</span><input type="number" name="budget" class="form-control shadow-sm" step="0.01" required></div>
                    </div>
                    <div class="col-md-8 mt-3">
                        <label class="form-label small fw-bold text-muted">สถานะโครงการ</label>
                        <div><span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill"><i class="fa-solid fa-circle me-2 small"></i>ตั้งค่าอัตโนมัติจาก Checklist (เริ่มต้นที่: ยังไม่เริ่ม)</span></div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-12"><h5 class="fw-bold text-purple border-bottom pb-2 mb-4"><i class="fa-solid fa-bullseye me-2"></i>เนื้อหาและผลการดำเนินงาน</h5></div>
                    <div class="col-12"><label class="form-label small fw-bold">วัตถุประสงค์โครงการ <span class="text-danger">*</span></label><textarea name="objectives" class="form-control shadow-sm" rows="3" required></textarea></div>
                    <div class="col-md-6 mt-4"><label class="form-label small fw-bold text-muted">ผลการดำเนินโครงการ (ถ้ามี)</label><textarea name="results" class="form-control shadow-sm bg-light" rows="3"></textarea></div>
                    <div class="col-md-6 mt-4"><label class="form-label small fw-bold text-muted">ปัญหาอุปสรรค / ข้อเสนอแนะ (ถ้ามี)</label><textarea name="problems_suggestions" class="form-control shadow-sm bg-light" rows="3"></textarea></div>
                </div>

                <div class="text-end mt-5 border-top pt-4">
                    <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 shadow fw-bold"><i class="fa-solid fa-save me-2"></i>บันทึกและสร้างโครงการ</button>
                </div>
            </div>
        </form>
    </div>
</main>
<style>.text-purple { color: #39048f; } .bg-purple { background-color: #39048f; } .btn-purple { background-color: #39048f; color: white; border: none; } .btn-purple:hover { background-color: #4b0082; color: white; } </style>
<?php require_once '../../includes/footer.php'; ?>