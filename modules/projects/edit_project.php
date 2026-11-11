<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) { header("Location: index.php"); exit(); }
$project_id = $_GET['id'];
$msg = ""; $msg_type = "";

$stmt_project = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
$stmt_project->execute([$project_id]);
$project = $stmt_project->fetch();

if (!$project) { echo "<script>alert('ไม่พบข้อมูลโครงการนี้'); window.location.href='index.php';</script>"; exit(); }

$is_admin = hasRole(['admin', 'งานแผน', 'คณบดี', 'รองคณบดีฝ่ายบริหาร']);
$is_owner = ($_SESSION['user_id'] == $project['responsible_id']);
$is_coord = ($_SESSION['user_id'] == $project['coordinator_id']);

if (!$is_admin && !$is_owner && !$is_coord) {
    echo "<script>alert('⛔ คุณไม่มีสิทธิ์แก้ไขโครงการนี้'); window.location.href='index.php';</script>"; exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $resp_id = !empty($_POST['responsible_id']) ? $_POST['responsible_id'] : null;
        $coord_id = !empty($_POST['coordinator_id']) ? $_POST['coordinator_id'] : null;

        // เอา status ออกจาก SQL เพราะสถานะคุมด้วย Checklist เท่านั้น
        $sql = "UPDATE projects SET 
                project_name = ?, fiscal_year = ?, responsible_id = ?, coordinator_id = ?, 
                start_date = ?, end_date = ?, deadline = ?, budget = ?, 
                objectives = ?, results = ?, problems_suggestions = ?
                WHERE project_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['project_name'], $_POST['fiscal_year'], $resp_id, $coord_id,
            $_POST['start_date'], $_POST['end_date'], $_POST['deadline'], $_POST['budget'],
            $_POST['objectives'], $_POST['results'] ?? '', $_POST['problems_suggestions'] ?? '',
            $project_id
        ]);

        $msg = "อัปเดตข้อมูลโครงการเรียบร้อยแล้ว!"; $msg_type = "success";
        echo "<script>setTimeout(() => { window.location.href = 'index.php'; }, 1500);</script>";
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage(); $msg_type = "danger";
    }
}

// 1. ดึงรายชื่อผู้ใช้ทั้งหมด
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE status = 'active' ORDER BY first_name ASC");
$users = $stmt_users->fetchAll();

// 2. ดึงรายการปีงบประมาณที่กำหนดไว้ใน Settings
$stmt_fiscal = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years = $stmt_fiscal->fetchAll();

// ป้องกันกรณีไม่พบปีงบประมาณในระบบเลย
if (empty($fiscal_years)) {
    $current_year_th = date('Y') + 543;
    $fiscal_years[] = ['year_name' => $current_year_th, 'is_active' => 1];
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขข้อมูลโครงการ</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="view_project.php?id=<?php echo $project_id; ?>" class="btn btn-outline-purple rounded-pill px-3 me-2"><i class="fa-solid fa-list-check me-1"></i> ดู Checklist</a>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก</a>
        </div>
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
                                <option value="<?php echo htmlspecialchars($fy['year_name']); ?>" <?php echo ($fy['year_name'] == $project['fiscal_year']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($fy['year_name']); ?> <?php echo ($fy['is_active'] == 1) ? '(ปีปัจจุบัน)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-9"><label class="form-label small fw-bold">ชื่อโครงการ</label><input type="text" name="project_name" class="form-control shadow-sm fw-bold" value="<?php echo htmlspecialchars($project['project_name']); ?>" required></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">ผู้รับผิดชอบโครงการ</label>
                        <select name="responsible_id" class="form-select shadow-sm" required>
                            <?php foreach ($users as $u) echo "<option value='{$u['user_id']}' " . ($u['user_id'] == $project['responsible_id'] ? 'selected' : '') . ">" . htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) . "</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">ผู้ประสานงานโครงการ</label>
                        <select name="coordinator_id" class="form-select shadow-sm">
                            <option value="">-- เลือก (ถ้ามี) --</option>
                            <?php foreach ($users as $u) echo "<option value='{$u['user_id']}' " . ($u['user_id'] == $project['coordinator_id'] ? 'selected' : '') . ">" . htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) . "</option>"; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-4 mb-5 bg-light p-4 rounded-4 mx-0">
                    <div class="col-12"><h5 class="fw-bold text-dark border-bottom pb-2 mb-4"><i class="fa-solid fa-calendar-days me-2"></i>ระยะเวลาและงบประมาณ</h5></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">วันที่เริ่มโครงการ</label><input type="date" name="start_date" class="form-control" value="<?php echo $project['start_date']; ?>" required></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">วันที่สิ้นสุด</label><input type="date" name="end_date" class="form-control" value="<?php echo $project['end_date']; ?>" required></div>
                    <div class="col-md-4"><label class="form-label small fw-bold text-danger">เดดไลน์</label><input type="date" name="deadline" class="form-control border-danger-subtle fw-bold text-danger" value="<?php echo $project['deadline']; ?>" required></div>
                    <div class="col-md-4 mt-3">
                        <label class="form-label small fw-bold text-purple">งบประมาณ</label>
                        <div class="input-group"><span class="input-group-text bg-purple text-white border-0">฿</span><input type="number" name="budget" class="form-control" step="0.01" value="<?php echo $project['budget']; ?>" required></div>
                    </div>
                    <div class="col-md-8 mt-3">
                        <label class="form-label small fw-bold text-muted">สถานะโครงการ</label>
                        <div>
                            <span class="badge bg-primary px-3 py-2 rounded-pill fs-6 fw-normal"><i class="fa-solid fa-circle-info me-2 small"></i><?php echo $project['status']; ?></span>
                            <small class="text-muted ms-2">(อัปเดตอัตโนมัติจาก Checklist)</small>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-12"><h5 class="fw-bold text-purple border-bottom pb-2 mb-4"><i class="fa-solid fa-bullseye me-2"></i>เนื้อหาและผลการดำเนินงาน</h5></div>
                    <div class="col-12"><label class="form-label small fw-bold">วัตถุประสงค์โครงการ</label><textarea name="objectives" class="form-control" rows="3" required><?php echo htmlspecialchars($project['objectives']); ?></textarea></div>
                    <div class="col-md-6 mt-4"><label class="form-label small fw-bold text-muted">ผลการดำเนินโครงการ</label><textarea name="results" class="form-control bg-light" rows="4"><?php echo htmlspecialchars($project['results'] ?? ''); ?></textarea></div>
                    <div class="col-md-6 mt-4"><label class="form-label small fw-bold text-muted">ปัญหาอุปสรรค / ข้อเสนอแนะ</label><textarea name="problems_suggestions" class="form-control bg-light" rows="4"><?php echo htmlspecialchars($project['problems_suggestions'] ?? ''); ?></textarea></div>
                </div>

                <div class="text-end mt-4 border-top pt-4">
                    <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 fw-bold"><i class="fa-solid fa-save me-2"></i>บันทึกการเปลี่ยนแปลง</button>
                </div>
            </div>
        </form>
    </div>
</main>
<style>.text-purple { color: #39048f; } .bg-purple { background-color: #39048f; } .btn-purple { background-color: #39048f; color: white; border: none; } .btn-purple:hover { background-color: #4b0082; color: white; } .btn-outline-purple { color: #39048f; border-color: #39048f;} .btn-outline-purple:hover { background-color: #39048f; color: white;} </style>
<?php require_once '../../includes/footer.php'; ?>