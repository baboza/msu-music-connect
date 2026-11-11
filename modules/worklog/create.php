<?php
// เปิด Error ชั่วคราว (ลบออกได้เมื่อระบบนิ่งแล้ว)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$user_id = $_SESSION['user_id'];
$msg = "";
$msg_type = "";

// ---------------------------------------------------------
// Time Logic: ดึงเวลาเปิด-ปิด จากตาราง system_settings
// ---------------------------------------------------------
date_default_timezone_set('Asia/Bangkok');
$current_time = date('H:i');
$current_date = date('Y-m-d');

$sys_open_time = '08:30'; 
$sys_late_time = '11:59'; 

try {
    $settings_query = $pdo->query("SELECT setting_name, setting_value FROM system_settings");
    if ($settings_query) {
        $settings_data = $settings_query->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($settings_data)) {
            $sys_open_time = $settings_data['worklog_open_time'] ?? '08:30';
            $sys_late_time = $settings_data['worklog_late_time'] ?? '11:59';
        }
    }
} catch (PDOException $e) {}

$target_date = isset($_GET['date']) ? $_GET['date'] : $current_date;

$is_locked = false;
$lock_reason = "";
$status_badge = "ปกติ";

if ($target_date == $current_date) {
    if ($current_time < $sys_open_time) {
        $is_locked = true;
        $lock_reason = "ระบบจะเปิดให้บันทึกงานของวันนี้ ตั้งแต่เวลา $sys_open_time น. เป็นต้นไป";
    }
} elseif ($target_date < $current_date) {
    $next_day_after_target = date('Y-m-d', strtotime($target_date . ' +1 day'));
    if ($current_date > $next_day_after_target || ($current_date == $next_day_after_target && $current_time > $sys_late_time)) {
        $status_badge = "ล่าช้า";
    }
} else {
    $is_locked = true;
    $lock_reason = "ไม่อนุญาตให้บันทึกงานล่วงหน้า";
}

// ---------------------------------------------------------
// ดึงรายการหัวข้องาน
// ---------------------------------------------------------
$my_tasks = [];
try {
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
} catch (PDOException $e) {
    $msg = "Database Error: " . $e->getMessage();
    $msg_type = "danger";
}

// ---------------------------------------------------------
// จัดการการบันทึกข้อมูล (POST)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_locked) {
    $task_id = $_POST['task_id'];
    
    // รับค่าระดับความเร่งด่วน
    $urgency_level = $_POST['urgency_level'] ?? 'ปกติ';
    
    $minutes = !empty($_POST['minutes_spent']) ? floatval($_POST['minutes_spent']) : 0;
    $pieces = isset($_POST['pieces_done']) ? intval($_POST['pieces_done']) : 0;
    $desc = $_POST['description'];
    $has_issue = $_POST['has_issue'];
    $issue_detail = ($has_issue == 1) ? $_POST['issue_detail'] : NULL;
    $next_move = ($has_issue == 1) ? $_POST['next_movement'] : NULL;
    $harmony = $_POST['harmony_score'];

    try {
        // 💡 อัปเดต SQL INSERT ให้รองรับคอลัมน์ urgency_level
        $sql = "INSERT INTO work_logs (user_id, log_date, task_id, hours_spent, pieces_done, description, has_issue, issue_detail, next_movement, harmony_score, submission_status, urgency_level) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $target_date, $task_id, $minutes, $pieces, $desc, $has_issue, $issue_detail, $next_move, $harmony, $status_badge, $urgency_level]);
        
        $msg = "บันทึกผลการปฏิบัติงานเรียบร้อยแล้ว! (สถานะ: $status_badge)";
        $msg_type = ($status_badge == 'ปกติ') ? "success" : "warning";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-clipboard-user me-2"></i>บันทึกงานประจำวัน (Work Log)</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm border-0 border-start border-<?php echo $msg_type; ?> border-4">
            <i class="fa-solid <?php echo ($msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'); ?> me-2"></i> 
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-purple text-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fa-solid fa-pen-to-square me-2"></i> แบบฟอร์มบันทึกงาน</h5>
                    <span class="badge bg-light text-dark fs-6"><?php echo date('d / m / Y', strtotime($target_date)); ?></span>
                </div>
                <div class="card-body p-4 bg-light">
                    
                    <form method="GET" class="mb-4 d-flex align-items-center bg-white p-3 rounded shadow-sm border">
                        <label class="fw-bold me-3 text-nowrap">วันที่บันทึก:</label>
                        <input type="date" name="date" class="form-control me-2 border-purple" value="<?php echo htmlspecialchars($target_date); ?>" max="<?php echo $current_date; ?>" onchange="this.form.submit()">
                        <?php if ($status_badge == 'ล่าช้า' && !$is_locked): ?>
                            <span class="badge bg-danger ms-2 px-3 py-2 animate__animated animate__flash">ส่งล่าช้า</span>
                        <?php endif; ?>
                    </form>

                    <?php if ($is_locked): ?>
                        <div class="alert alert-warning text-center py-5 shadow-sm border-warning">
                            <i class="fa-solid fa-lock fs-1 mb-3 text-warning"></i>
                            <h5 class="fw-bold text-dark">ระบบยังไม่เปิดให้บันทึก</h5>
                            <p class="mb-0"><?php echo htmlspecialchars($lock_reason); ?></p>
                            <p class="text-muted small mt-2">เวลาปัจจุบัน: <?php echo $current_time; ?> น.</p>
                        </div>
                    <?php else: ?>
                        
                        <form method="POST" class="bg-white p-4 rounded shadow-sm border">
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold text-purple">1. หัวข้องาน (ตามสิทธิ์ของคุณ) <span class="text-danger">*</span></label>
                                <?php if (empty($my_tasks)): ?>
                                    <div class="alert alert-danger small py-3 border-danger shadow-sm text-center">
                                        <i class="fa-solid fa-file-circle-xmark fs-3 mb-2"></i><br>
                                        ไม่พบรายการงานที่กำหนดให้คุณ กรุณาติดต่อ Admin
                                    </div>
                                <?php else: ?>
                                    <select name="task_id" class="form-select border-purple shadow-sm" required>
                                        <option value="">-- เลือกงานที่ปฏิบัติ --</option>
                                        <?php 
                                        foreach ($my_tasks as $role_name => $tasks) {
                                            echo "<optgroup label='สิทธิ์: " . htmlspecialchars($role_name) . "'>";
                                            foreach ($tasks as $t) {
                                                echo "<option value='" . $t['task_id'] . "'>" . htmlspecialchars($t['task_name']) . "</option>";
                                            }
                                            echo "</optgroup>";
                                        }
                                        ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small text-danger"><i class="fa-solid fa-bolt"></i> ระดับความเร่งด่วนของงาน <span class="text-danger">*</span></label>
                                <select name="urgency_level" class="form-select border-secondary shadow-sm" required>
                                    <option value="ปกติ">🟢 ปกติ (ทำตามแผนงาน)</option>
                                    <option value="ด่วน">🟡 ด่วน (ต้องการภายในสัปดาห์นี้)</option>
                                    <option value="ด่วนมาก">🟠 ด่วนมาก (ต้องการภายใน 1-2 วัน)</option>
                                    <option value="ด่วนที่สุด">🔴 ด่วนที่สุด (แทรกคิว/ต้องการทันที)</option>
                                </select>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">ใช้เวลา (นาที)</label>
                                    <input type="number" min="0" name="minutes_spent" class="form-control border-secondary" placeholder="เช่น 30, 45, 120">
                                    <div class="extra-small text-muted mt-1">* หากไม่ทราบแน่ชัดสามารถเว้นว่างได้</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">จำนวนชิ้นงาน (ถ้ามี)</label>
                                    <input type="number" step="1" min="0" name="pieces_done" class="form-control border-secondary" placeholder="ระบุเป็นจำนวนเต็ม เช่น 1, 2">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small">รายละเอียดงานสั้นๆ <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control border-secondary" rows="2" placeholder="ระบุสิ่งที่ทำสำเร็จในวันนี้" required></textarea>
                            </div>

                            <div class="mb-4 p-3 bg-light rounded border border-warning shadow-sm">
                                <label class="form-label fw-bold text-dark mb-2"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> มีปัญหาติดขัดหรือไม่?</label>
                                <div class="d-flex gap-4 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input border-secondary" type="radio" name="has_issue" id="issue_no" value="0" checked onchange="toggleIssueField()">
                                        <label class="form-check-label" for="issue_no">ไม่มีปัญหา</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input border-danger" type="radio" name="has_issue" id="issue_yes" value="1" onchange="toggleIssueField()">
                                        <label class="form-check-label text-danger fw-bold" for="issue_yes">มีปัญหา/ติดขัด</label>
                                    </div>
                                </div>
                                
                                <div id="issue_fields_group" class="d-none">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-danger">รายละเอียดปัญหาที่พบ <span class="text-danger">*</span></label>
                                        <textarea name="issue_detail" id="issue_detail_box" class="form-control border-danger shadow-sm" rows="2"></textarea>
                                    </div>
                                    <div>
                                        <label class="form-label fw-bold small text-primary"><i class="fa-solid fa-forward-step me-1"></i> แผนงานวันพรุ่งนี้ <span class="text-danger">*</span></label>
                                        <textarea name="next_movement" id="next_movement_box" class="form-control border-primary shadow-sm" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4 border-2 border-purple opacity-25">
                            <div class="text-center mb-4">
                                <label class="form-label fw-bold text-gold fs-5 mb-3"><i class="fa-solid fa-face-smile-beam me-2"></i> ความสุขในการทำงานวันนี้</label>
                                <div class="rating-box d-flex justify-content-center gap-2 gap-sm-4">
                                    <label class="rating-item"><input type="radio" name="harmony_score" value="1" required><i class="fa-solid fa-face-frown text-danger fs-1"></i><div class="extra-small mt-1 text-muted fw-bold">เหนื่อยล้า</div></label>
                                    <label class="rating-item"><input type="radio" name="harmony_score" value="2"><i class="fa-solid fa-face-meh text-warning fs-1"></i><div class="extra-small mt-1 text-muted fw-bold">ตึงเครียด</div></label>
                                    <label class="rating-item"><input type="radio" name="harmony_score" value="3" checked><i class="fa-solid fa-face-smile text-primary fs-1"></i><div class="extra-small mt-1 text-muted fw-bold">ปกติ</div></label>
                                    <label class="rating-item"><input type="radio" name="harmony_score" value="4"><i class="fa-solid fa-face-smile-beam text-info fs-1"></i><div class="extra-small mt-1 text-muted fw-bold">ดีมาก</div></label>
                                    <label class="rating-item"><input type="radio" name="harmony_score" value="5"><i class="fa-solid fa-face-laugh-squint text-success fs-1"></i><div class="extra-small mt-1 text-muted fw-bold">ยอดเยี่ยม</div></label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-purple w-100 py-3 fw-bold rounded-pill shadow" <?php if(empty($my_tasks)) echo 'disabled'; ?>>
                                <i class="fa-solid fa-paper-plane me-2"></i> บันทึกผลการปฏิบัติงาน
                            </button>
                        </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .border-purple { border-color: #4b0082 !important; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.3s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(75, 0, 130, 0.2); }
    .rating-item input[type="radio"] { display: none; }
    .rating-item { cursor: pointer; text-align: center; transition: 0.2s; opacity: 0.4; filter: grayscale(100%); width: 60px; }
    .rating-item:hover { opacity: 0.8; filter: grayscale(50%); transform: scale(1.1); }
    .rating-item:has(input[type="radio"]:checked) { opacity: 1; filter: grayscale(0%); color: #333; }
    optgroup { font-weight: bold; color: #4b0082; background-color: #f8f9fa; }
    .extra-small { font-size: 0.7rem; }
</style>

<script>
function toggleIssueField() {
    const isYes = document.getElementById('issue_yes').checked;
    const group = document.getElementById('issue_fields_group');
    const issueBox = document.getElementById('issue_detail_box');
    const nextBox = document.getElementById('next_movement_box');
    
    if (isYes) {
        group.classList.remove('d-none');
        issueBox.setAttribute('required', 'required');
        nextBox.setAttribute('required', 'required');
        issueBox.focus();
    } else {
        group.classList.add('d-none');
        issueBox.removeAttribute('required');
        nextBox.removeAttribute('required');
        issueBox.value = '';
        nextBox.value = '';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>