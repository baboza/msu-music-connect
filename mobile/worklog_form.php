<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$current_date = date('Y-m-d');
$target_date = isset($_GET['date']) ? $_GET['date'] : $current_date;

// เช็คว่าเคยมีประวัติการส่ง report ในวันนี้แล้วหรือยัง (Lock logic)
$sql_check = "SELECT submission_status FROM work_logs WHERE user_id = ? AND log_date = ? LIMIT 1";
$stmt_check = $pdo->prepare($sql_check);
$stmt_check->execute([$user_id, $target_date]);
$existing_log = $stmt_check->fetch();

$is_locked = false;
$lock_reason = "";
if (strtotime($target_date) <= strtotime($current_date)) {
    if ($existing_log && !empty($existing_log['submission_status'])) {
        $status_badge = ($existing_log['submission_status'] === 'ล่าช้า') ? 'ล่าช้า' : 'ปกติ';
    } else {
        $status_badge = (strtotime($target_date) == strtotime($current_date)) ? "ปกติ" : "ล่าช้า";
    }
} else {
    $is_locked = true;
    $lock_reason = "ไม่อนุญาตให้บันทึกงานล่วงหน้า";
}

// Fetch tasks
$my_tasks = [];
$is_admin = hasRole(['admin', 'หัวหน้าสำนักงาน']);
if ($is_admin) {
    $sql = "SELECT t.task_id, t.task_name, COALESCE(r.role_name, 'งานทั้งหมด (สิทธิ์แอดมิน)') as role_name 
            FROM work_tasks t 
            LEFT JOIN roles r ON t.role_id = r.role_id
            WHERE t.status = 'active'
            ORDER BY t.task_name ASC";
    $stmt_tasks = $pdo->prepare($sql);
    $stmt_tasks->execute();
} else {
    $sql = "SELECT t.task_id, t.task_name, r.role_name 
            FROM work_tasks t 
            JOIN user_roles ur ON t.role_id = ur.role_id 
            JOIN roles r ON ur.role_id = r.role_id
            WHERE ur.user_id = ? AND t.status = 'active'
            ORDER BY r.role_level DESC, r.role_name ASC, t.task_name ASC";
    $stmt_tasks = $pdo->prepare($sql);
    $stmt_tasks->execute([$user_id]);
}
$raw_tasks = $stmt_tasks->fetchAll(PDO::FETCH_ASSOC);
foreach ($raw_tasks as $row) {
    $group_name = $row['role_name'] ?? 'งานทั่วไป';
    $my_tasks[$group_name][] = ['task_id' => $row['task_id'], 'task_name' => $row['task_name']];
}

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_locked) {
    $task_id = $_POST['task_id'];
    $urgency_level = $_POST['urgency_level'] ?? 'ปกติ';
    $minutes = !empty($_POST['minutes_spent']) ? floatval($_POST['minutes_spent']) : 0;
    $pieces = !empty($_POST['pieces_done']) ? intval($_POST['pieces_done']) : 0;
    $description = trim($_POST['description']);
    $has_issue = isset($_POST['has_issue']) ? intval($_POST['has_issue']) : 0;
    $issue_detail = $has_issue ? trim($_POST['issue_detail']) : null;
    $next_movement = $has_issue ? trim($_POST['next_movement']) : null;
    $harmony_score = isset($_POST['harmony_score']) ? intval($_POST['harmony_score']) : 3;

    try {
        $stmt_ins = $pdo->prepare("INSERT INTO work_logs 
            (user_id, log_date, task_id, urgency_level, hours_spent, pieces_done, description, has_issue, issue_detail, next_movement, harmony_score, submission_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->execute([
            $user_id, $target_date, $task_id, $urgency_level, $minutes, $pieces, $description, $has_issue, $issue_detail, $next_movement, $harmony_score, $status_badge
        ]);

        $msg = "บันทึกข้อมูลเรียบร้อยแล้ว!";
        $msg_type = "success";
        echo "<script>setTimeout(()=>window.location.href='worklog.php', 1200);</script>";
    } catch(PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="worklog.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าบันทึกงาน">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">บันทึก Daily Report</h5>
            <small class="text-muted">บันทึกภาระงานประจำวัน</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-pen-to-square"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <?php if($is_locked): ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid fa-lock me-2"></i><?= $lock_reason ?>
        </div>
    <?php else: ?>
        <form method="POST" class="bento-box p-4" id="worklogForm">
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">วันที่ปฏิบัติงาน</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($target_date) ?>" max="<?= $current_date ?>" onchange="window.location.href='?date='+this.value">
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">หัวข้องาน <span class="text-danger">*</span></label>
                <select name="task_id" class="form-select form-select-sm" required>
                    <option value="" disabled selected>-- เลือกหัวข้องาน --</option>
                    <?php foreach ($my_tasks as $group => $tasks): ?>
                        <optgroup label="<?= htmlspecialchars($group) ?>">
                            <?php foreach ($tasks as $t): ?>
                                <option value="<?= $t['task_id'] ?>"><?= htmlspecialchars($t['task_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">รายละเอียดงาน (สรุปสั้นๆ) <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control form-control-sm" rows="3" required placeholder="อธิบายกิจกรรมและผลลัพธ์ที่ทำ..."></textarea>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">ใช้เวลา (นาที)</label>
                    <input type="number" name="minutes_spent" class="form-control form-control-sm" min="0" placeholder="เช่น 60">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">จำนวนชิ้นงาน</label>
                    <input type="number" name="pieces_done" class="form-control form-control-sm" min="0" placeholder="0">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">ระดับความเร่งด่วน</label>
                <select name="urgency_level" class="form-select form-select-sm">
                    <option value="ปกติ">ปกติ (Normal)</option>
                    <option value="ด่วน">ด่วน (Urgent)</option>
                    <option value="ด่วนมาก">ด่วนมาก (Very Urgent)</option>
                    <option value="ด่วนที่สุด">ด่วนที่สุด (Top Urgent)</option>
                </select>
            </div>

            <hr class="my-4 text-muted opacity-25">

            <div class="mb-3">
                <label class="form-label fw-bold small text-muted d-block">พบปัญหาอุปสรรคหรือไม่?</label>
                <div class="d-flex gap-3 mt-2">
                    <div class="form-check custom-radio">
                        <input class="form-check-input" type="radio" name="has_issue" id="issueNo" value="0" checked onchange="toggleIssueBox()">
                        <label class="form-check-label fw-semibold" for="issueNo">ไม่มี</label>
                    </div>
                    <div class="form-check custom-radio text-danger">
                        <input class="form-check-input" type="radio" name="has_issue" id="issueYes" value="1" onchange="toggleIssueBox()">
                        <label class="form-check-label fw-semibold" for="issueYes">มีปัญหาอุปสรรค</label>
                    </div>
                </div>
            </div>

            <div id="issueBox" style="display:none;" class="bg-danger-subtle p-3 rounded-4 mb-3 border">
                <div class="mb-2">
                    <label class="form-label fw-bold small text-danger">รายละเอียดปัญหา</label>
                    <textarea name="issue_detail" class="form-control" rows="2" placeholder="ระบุปัญหาที่พบ..."></textarea>
                </div>
                <div>
                    <label class="form-label fw-bold small text-danger">แนวทางแก้ไข</label>
                    <textarea name="next_movement" class="form-control" rows="2" placeholder="แนวทางหรือผู้ที่ต้องประสานงานต่อ..."></textarea>
                </div>
            </div>

            <div class="mb-4 text-center">
                <label class="form-label fw-bold small text-muted d-block text-start">ระดับความพึงพอใจในการทำงาน (Harmony Score) <span class="text-danger">*</span></label>
                <div class="d-flex justify-content-between px-2 mt-3 fs-3">
                    <label style="cursor:pointer;"><input type="radio" name="harmony_score" value="1" required class="d-none peer"><span class="harmony-icon text-muted" data-val="1"><i class="fa-solid fa-face-frown"></i></span></label>
                    <label style="cursor:pointer;"><input type="radio" name="harmony_score" value="2" required class="d-none peer"><span class="harmony-icon text-muted" data-val="2"><i class="fa-solid fa-face-meh"></i></span></label>
                    <label style="cursor:pointer;"><input type="radio" name="harmony_score" value="3" required class="d-none peer"><span class="harmony-icon text-muted" data-val="3"><i class="fa-solid fa-face-smile"></i></span></label>
                    <label style="cursor:pointer;"><input type="radio" name="harmony_score" value="4" required class="d-none peer"><span class="harmony-icon text-muted" data-val="4"><i class="fa-solid fa-face-smile-beam"></i></span></label>
                    <label style="cursor:pointer;"><input type="radio" name="harmony_score" value="5" required class="d-none peer"><span class="harmony-icon text-muted" data-val="5"><i class="fa-solid fa-face-laugh-squint"></i></span></label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
                <i class="fa-solid fa-floppy-disk me-2"></i> บันทึก Daily Report
            </button>
        </form>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<style>
/* CSS for Harmony Score selection */
.harmony-icon { transition: all 0.25s ease; opacity: 0.35; display: inline-block; }
input[type="radio"]:checked + .harmony-icon { opacity: 1; transform: scale(1.3); }
input[type="radio"]:checked + .harmony-icon[data-val="1"] { color: #dc3545 !important; }
input[type="radio"]:checked + .harmony-icon[data-val="2"] { color: #f59e0b !important; }
input[type="radio"]:checked + .harmony-icon[data-val="3"] { color: #39048f !important; }
input[type="radio"]:checked + .harmony-icon[data-val="4"] { color: #0284c7 !important; }
input[type="radio"]:checked + .harmony-icon[data-val="5"] { color: #10b981 !important; }
</style>

<script>
function toggleIssueBox() {
    const isYes = document.getElementById('issueYes').checked;
    document.getElementById('issueBox').style.display = isYes ? 'block' : 'none';
}
</script>

<?php require_once 'includes/footer.php'; ?>
