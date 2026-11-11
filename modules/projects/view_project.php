<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) { header("Location: index.php"); exit(); }
$project_id = $_GET['id'];
$msg = ""; $msg_type = "";

// 1. ดึงข้อมูลโครงการ
$query = "SELECT p.*, 
          u1.first_name as resp_fname, u1.last_name as resp_lname,
          u2.first_name as coord_fname, u2.last_name as coord_lname
          FROM projects p 
          LEFT JOIN users u1 ON p.responsible_id = u1.user_id 
          LEFT JOIN users u2 ON p.coordinator_id = u2.user_id 
          WHERE p.project_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) { echo "<script>alert('ไม่พบข้อมูลโครงการ'); window.location.href='index.php';</script>"; exit(); }

// [ระบบล็อคสิทธิ์]
$is_admin = hasRole(['admin', 'งานแผน', 'คณบดี', 'รองคณบดีฝ่ายบริหาร', 'หัวหน้าสำนักงาน']);
$is_owner = ($_SESSION['user_id'] == $project['responsible_id']);
$is_coord = ($_SESSION['user_id'] == $project['coordinator_id']);
$can_edit = ($is_admin || $is_owner || $is_coord);

// สิทธิ์ดูไฟล์
$can_view_file = hasRole(['admin', 'รองคณบดีฝ่ายบริหาร', 'หัวหน้าสำนักงาน', 'งานแผน']) || $is_owner || $is_coord;

// 2. จัดการ POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    // อัปเดต Checklist
    if ($action == 'update_tasks') {
        $t1 = isset($_POST['task_approve']) ? 1 : 0;
        $t2 = isset($_POST['task_borrow']) ? 1 : 0;
        $t3 = isset($_POST['task_execute']) ? 1 : 0;
        $t4 = isset($_POST['task_report']) ? 1 : 0;
        $t5 = isset($_POST['task_clear_money']) ? 1 : 0;

        $total_tasks = $t1 + $t2 + $t3 + $t4 + $t5;
        $auto_status = 'ยังไม่เริ่ม';
        if ($total_tasks == 5) { $auto_status = 'ดำเนินการเรียบร้อย'; } 
        elseif ($total_tasks > 0) { $auto_status = 'กำลังดำเนินการ'; }

        $sql = "UPDATE projects SET task_approve=?, task_borrow=?, task_execute=?, task_report=?, task_clear_money=?, status=? WHERE project_id=?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$t1, $t2, $t3, $t4, $t5, $auto_status, $project_id])) {
            $msg = "อัปเดตความคืบหน้าเรียบร้อย!"; $msg_type = "success";
            $stmt = $pdo->prepare($query); $stmt->execute([$project_id]); $project = $stmt->fetch();
        }
    } 
    // อัปเดตรายงาน ไฟล์ และ ค่าใช้จ่ายจริง
    elseif ($action == 'update_report') {
        $report_file = $project['report_file']; 
        
        if (isset($_FILES['report_file']) && $_FILES['report_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['pdf', 'doc', 'docx', 'zip', 'rar', 'jpg', 'png'];
                $ext = pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION);
                
                if (in_array(strtolower($ext), $allowed)) {
                    $upload_dir = '../../uploads/projects/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                    $new_filename = 'report_' . $project_id . '_' . time() . '.' . $ext;
                    
                    if (move_uploaded_file($_FILES['report_file']['tmp_name'], $upload_dir . $new_filename)) {
                        $report_file = $new_filename;
                    } else {
                        $msg = "อัปโหลดล้มเหลว: โฟลเดอร์ไม่มีสิทธิ์เขียน"; 
                        $msg_type = "danger";
                    }
                } else {
                    $msg = "อัปโหลดได้เฉพาะไฟล์ PDF, Word, รูปภาพ หรือ ZIP/RAR เท่านั้น"; 
                    $msg_type = "danger";
                }
            } else {
                $err_code = $_FILES['report_file']['error'];
                if ($err_code == 1 || $err_code == 2) {
                    $msg = "อัปโหลดไม่สำเร็จ: ไฟล์มีขนาดใหญ่เกินไป";
                } else {
                    $msg = "อัปโหลดไม่สำเร็จ (Error Code: {$err_code})";
                }
                $msg_type = "danger";
            }
        }

        if (empty($msg_type)) {
            $actual_budget_val = isset($_POST['actual_budget']) && $_POST['actual_budget'] !== '' ? $_POST['actual_budget'] : $project['actual_budget'];
            $actual_budget = is_numeric($actual_budget_val) ? floatval($actual_budget_val) : 0;
            $remaining_budget = floatval($project['budget']) - $actual_budget; 
            
            // --- NEW: Process Activity Images ---
            $activity_images = !empty($project['activity_images']) ? json_decode($project['activity_images'], true) : [];
            if (!is_array($activity_images)) $activity_images = [];
            
            if (isset($_FILES['activity_images'])) {
                $upload_dir_act = '../../uploads/projects/activities/';
                if (!is_dir($upload_dir_act)) { mkdir($upload_dir_act, 0777, true); }
                
                for ($i = 0; $i < count($_FILES['activity_images']['name']); $i++) {
                    if ($_FILES['activity_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['activity_images']['name'][$i], PATHINFO_EXTENSION);
                        $allowed_img = ['jpg', 'jpeg', 'png', 'webp'];
                        if (in_array(strtolower($ext), $allowed_img)) {
                            $new_name = 'act_' . $project_id . '_' . time() . '_' . $i . '.' . $ext;
                            if (move_uploaded_file($_FILES['activity_images']['tmp_name'][$i], $upload_dir_act . $new_name)) {
                                $activity_images[] = $new_name;
                            }
                        }
                    }
                }
                if (count($activity_images) > 5) {
                    $activity_images = array_slice($activity_images, -5);
                }
            }
            $activity_images_json = json_encode($activity_images);
            // ------------------------------------

            $sql = "UPDATE projects SET results=?, problems_suggestions=?, report_file=?, actual_budget=?, remaining_budget=?, activity_images=? WHERE project_id=?";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([trim($_POST['results'] ?? ''), trim($_POST['problems_suggestions'] ?? ''), $report_file, $actual_budget, $remaining_budget, $activity_images_json, $project_id])) {
                $msg = "บันทึกข้อมูลสรุปโครงการและอัปโหลดไฟล์เรียบร้อย!"; 
                $msg_type = "success";
                $stmt = $pdo->prepare($query); $stmt->execute([$project_id]); $project = $stmt->fetch();
            }
        }
    }
}

$tasks = [$project['task_approve'], $project['task_borrow'], $project['task_execute'], $project['task_report'], $project['task_clear_money']];
$completed_tasks = array_sum($tasks);
$progress = ($completed_tasks / 5) * 100;

$today = strtotime(date('Y-m-d'));
$display_status = $project['status'];
if ($progress < 100 && strtotime($project['deadline']) < $today) $display_status = 'ล่าช้า';

$status_classes = ['ยังไม่เริ่ม'=>'bg-secondary', 'กำลังดำเนินการ'=>'bg-warning-subtle text-dark border border-warning', 'ดำเนินการเรียบร้อย'=>'bg-success-subtle text-success border border-success', 'ล่าช้า'=>'bg-danger-subtle text-danger border border-danger'];
$status_badge = isset($status_classes[$display_status]) ? $status_classes[$display_status] : 'bg-light text-dark';
$progress_color = $progress == 100 ? 'bg-success' : ($display_status == 'ล่าช้า' ? 'bg-danger' : 'bg-primary'); 
$disabled_attr = !$can_edit ? 'disabled' : '';

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h3 text-dark fw-bold mb-1"><span class="badge bg-secondary me-2 fs-6 fw-normal">ปี <?php echo htmlspecialchars($project['fiscal_year']); ?></span><?php echo htmlspecialchars($project['project_name']); ?></h1>
            <span class="badge <?php echo $status_badge; ?> rounded-pill px-3 py-2 mt-2"><i class="fa-solid fa-circle-info me-1"></i> สถานะ: <?php echo $display_status; ?></span>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 mt-3 mt-md-0">
            <?php if ($can_edit): ?>
            <a href="edit_project.php?id=<?php echo $project_id; ?>" class="btn btn-primary rounded-pill px-4 me-2 shadow-sm"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขข้อมูลหลัก</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-light border text-dark rounded-pill px-4 shadow-sm"><i class="fa-solid fa-arrow-left me-2"></i>ย้อนกลับ</a>
        </div>
    </div>

    <?php if ($msg) echo "<div class='alert alert-$msg_type alert-dismissible fade show shadow-sm rounded-4'><i class='fa-solid " . ($msg_type=='success'?'fa-check-circle':'fa-circle-exclamation') . " me-2'></i>$msg<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; ?>
    <?php if (!$can_edit): ?><div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4 fw-bold"><i class="fa-solid fa-lock me-2"></i> ดูข้อมูลได้อย่างเดียว (สิทธิ์อ่านเท่านั้น)</div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-chart-line me-2 text-primary"></i>ความคืบหน้า</h5>
                    <div class="d-flex align-items-center mb-2">
                        <h2 class="fw-bold mb-0 me-3 <?php echo $progress == 100 ? 'text-success' : 'text-primary'; ?>"><?php echo $progress; ?>%</h2>
                        <div class="progress flex-grow-1" style="height: 12px; border-radius: 10px; background-color: #f1f5f9;">
                            <div class="progress-bar <?php echo $progress_color; ?> <?php echo $progress < 100 ? 'progress-bar-striped progress-bar-animated' : ''; ?>" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white pt-4 pb-2 border-0"><h5 class="fw-bold text-dark"><i class="fa-solid fa-list-check me-2 text-primary"></i>อัปเดตงานย่อย (Checklist)</h5></div>
                <div class="card-body p-4 pt-0">
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="update_tasks">
                        <div class="list-group mb-4 gap-2">
                            <label class="list-group-item d-flex gap-3 py-3 border-0 bg-light rounded-4 custom-checkbox-card">
                                <input class="form-check-input flex-shrink-0 fs-4" type="checkbox" name="task_approve" value="1" <?php echo $project['task_approve'] ? 'checked' : ''; ?> <?php echo $disabled_attr; ?>>
                                <span class="pt-1 form-checked-content"><strong class="d-block text-dark">1. เสนอขออนุมัติโครงการ</strong><small class="text-muted">จัดทำบันทึกข้อความและเอกสาร</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 py-3 border-0 bg-light rounded-4 custom-checkbox-card">
                                <input class="form-check-input flex-shrink-0 fs-4" type="checkbox" name="task_borrow" value="1" <?php echo $project['task_borrow'] ? 'checked' : ''; ?> <?php echo $disabled_attr; ?>>
                                <span class="pt-1 form-checked-content"><strong class="d-block text-dark">2. ดำเนินการยืมเงินโครงการ</strong><small class="text-muted">ทำเรื่องยืมเงินทดรองจ่าย</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 py-3 border-0 bg-light rounded-4 custom-checkbox-card">
                                <input class="form-check-input flex-shrink-0 fs-4" type="checkbox" name="task_execute" value="1" <?php echo $project['task_execute'] ? 'checked' : ''; ?> <?php echo $disabled_attr; ?>>
                                <span class="pt-1 form-checked-content"><strong class="d-block text-dark">3. จัดโครงการตามกำหนด</strong><small class="text-muted">จัดกิจกรรมตามแผนงาน</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 py-3 border-0 bg-light rounded-4 custom-checkbox-card">
                                <input class="form-check-input flex-shrink-0 fs-4" type="checkbox" name="task_report" value="1" <?php echo $project['task_report'] ? 'checked' : ''; ?> <?php echo $disabled_attr; ?>>
                                <span class="pt-1 form-checked-content">
                                    <strong class="d-block text-dark <?php echo $project['task_report'] ? 'text-success' : ''; ?>">4. รายงานโครงการ</strong>
                                    <small class="text-muted">สรุปผลการดำเนินงานและแนบไฟล์รายงาน (ฟอร์มแนบไฟล์อยู่ด้านขวา)</small>
                                </span>
                            </label>
                            <label class="list-group-item d-flex gap-3 py-3 border-0 bg-light rounded-4 custom-checkbox-card">
                                <input class="form-check-input flex-shrink-0 fs-4" type="checkbox" name="task_clear_money" value="1" <?php echo $project['task_clear_money'] ? 'checked' : ''; ?> <?php echo $disabled_attr; ?>>
                                <span class="pt-1 form-checked-content">
                                    <strong class="d-block text-dark <?php echo $project['task_clear_money'] ? 'text-warning text-dark' : ''; ?>">5. ส่งเอกสารเคลียร์เงินยืม</strong>
                                    <small class="text-muted">รวบรวมใบเสร็จส่งการเงิน (ฟอร์มกรอกค่าใช้จ่ายจริงจะปรากฏด้านขวา)</small>
                                </span>
                            </label>
                        </div>
                        <?php if ($can_edit): ?>
                            <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 shadow-sm fw-bold"><i class="fa-solid fa-floppy-disk me-2"></i> อัปเดตความคืบหน้า</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4 bg-primary-subtle bg-opacity-25">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4 border-bottom border-light pb-2 text-primary"><i class="fa-solid fa-circle-info me-2"></i> ข้อมูลสรุป</h5>
                    <div class="d-flex mb-3"><div class="me-3 fs-3 opacity-50 text-primary"><i class="fa-solid fa-user-tie"></i></div><div><small class="opacity-75 d-block text-muted">ผู้รับผิดชอบ / ผู้ประสานงาน</small><span class="fw-bold fs-5 d-block text-dark"><?php echo htmlspecialchars($project['resp_fname'] . ' ' . $project['resp_lname']); ?></span><small class="text-primary"><i class="fa-solid fa-handshake me-1"></i> <?php echo htmlspecialchars($project['coord_fname'] ? $project['coord_fname'].' '.$project['coord_lname'] : '-'); ?></small></div></div>
                    <div class="d-flex mb-3"><div class="me-3 fs-3 opacity-50 text-success"><i class="fa-solid fa-sack-dollar"></i></div><div><small class="opacity-75 d-block text-muted">งบประมาณอนุมัติ</small><span class="fw-bold fs-5 text-success">฿<?php echo number_format($project['budget'], 2); ?></span></div></div>
                    <div class="d-flex mb-3"><div class="me-3 fs-3 opacity-50 text-info"><i class="fa-regular fa-calendar-check"></i></div><div><small class="opacity-75 d-block text-muted">ระยะเวลา</small><span class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($project['start_date'])) . ' - ' . date('d/m/Y', strtotime($project['end_date'])); ?></span></div></div>
                    <div class="d-flex"><div class="me-3 fs-3 opacity-50 text-danger"><i class="fa-solid fa-thumbtack"></i></div><div><small class="opacity-75 d-block text-muted">เดดไลน์</small><span class="fw-bold <?php echo ($display_status == 'ล่าช้า') ? 'text-danger bg-danger-subtle px-2 rounded' : 'text-dark'; ?>"><?php echo date('d/m/Y', strtotime($project['deadline'])); ?></span></div></div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white pt-4 pb-0 border-0"><h5 class="fw-bold text-dark"><i class="fa-solid fa-file-signature me-2 text-success"></i>สรุปผลเมื่อจบโครงการ</h5></div>
                <div class="card-body p-4">
                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_report">
                        
                        <div class="mb-3"><label class="form-label small fw-bold text-dark">วัตถุประสงค์ (อ่านอย่างเดียว)</label><div class="p-3 bg-light rounded-4 border text-muted small"><?php echo nl2br(htmlspecialchars($project['objectives'])); ?></div></div>
                        <div class="mb-3"><label class="form-label small fw-bold text-dark">ผลการดำเนินงาน</label><textarea name="results" class="form-control shadow-sm" rows="3" <?php echo $disabled_attr; ?>><?php echo htmlspecialchars($project['results'] ?? ''); ?></textarea></div>
                        <div class="mb-4"><label class="form-label small fw-bold text-danger">ปัญหาอุปสรรค</label><textarea name="problems_suggestions" class="form-control shadow-sm border-danger-subtle" rows="3" <?php echo $disabled_attr; ?>><?php echo htmlspecialchars($project['problems_suggestions'] ?? ''); ?></textarea></div>
                        
                        <!-- NEW IMAGE UPLOAD -->
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-dark"><i class="fa-solid fa-images me-1 text-primary"></i> ภาพถ่ายกิจกรรม (สูงสุด 5 ภาพ, ไฟล์จะถูกลดขนาดไม่เกิน 500KB ให้อัตโนมัติ)</label>
                            <?php if ($can_edit): ?>
                            <input type="file" name="activity_images[]" id="activity_images" accept="image/jpeg,image/png,image/webp" multiple class="form-control form-control-sm shadow-sm bg-white" <?php echo $disabled_attr; ?>>
                            <?php endif; ?>
                            <div id="image_preview_container" class="d-flex flex-wrap gap-2 mt-3">
                                <?php 
                                $saved_images = !empty($project['activity_images']) ? json_decode($project['activity_images'], true) : [];
                                if (is_array($saved_images) && count($saved_images) > 0) {
                                    foreach ($saved_images as $img) {
                                        echo '<div class="position-relative hover-zoom"><a href="../../uploads/projects/activities/'.htmlspecialchars($img).'" target="_blank"><img src="../../uploads/projects/activities/'.htmlspecialchars($img).'" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px;" class="shadow-sm border"></a></div>';
                                    }
                                }
                                ?>
                            </div>
                        </div>

                        <?php if ($project['task_report'] == 1): ?>
                            <div class="p-3 mb-4 rounded-4 border <?php echo !empty($project['report_file']) ? 'border-success bg-success-subtle bg-opacity-25' : 'border-primary bg-primary-subtle bg-opacity-25'; ?>">
                                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-file-pdf me-2 text-danger"></i>เอกสารสรุปโครงการ (PDF, ZIP, Word)</h6>
                                
                                <?php if (!empty($project['report_file']) && $can_view_file): ?>
                                    <div class="d-flex align-items-center justify-content-between bg-white p-2 rounded-3 shadow-sm mb-3">
                                        <div class="text-truncate px-2 small fw-bold text-muted"><i class="fa-solid fa-paperclip me-2"></i><?php echo htmlspecialchars($project['report_file']); ?></div>
                                        <a href="../../uploads/projects/<?php echo htmlspecialchars($project['report_file']); ?>" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm"><i class="fa-solid fa-download me-1"></i> โหลดไฟล์</a>
                                    </div>
                                <?php elseif (!empty($project['report_file']) && !$can_view_file): ?>
                                    <div class="small text-danger fw-bold mb-3"><i class="fa-solid fa-lock me-1"></i> มีไฟล์แนบ แต่สิทธิ์ของคุณไม่สามารถเข้าถึงได้</div>
                                <?php endif; ?>

                                <?php if ($can_edit): ?>
                                    <label class="form-label small fw-bold text-dark"><?php echo !empty($project['report_file']) ? 'อัปโหลดไฟล์ใหม่เพื่อแทนที่' : 'อัปโหลดไฟล์รายงาน'; ?></label>
                                    <input type="file" name="report_file" class="form-control form-control-sm shadow-sm bg-white" accept=".pdf,.doc,.docx,.zip,.rar,.jpg,.png">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($project['task_clear_money'] == 1): ?>
                            <div class="p-3 mb-4 rounded-4 border border-warning bg-warning-subtle bg-opacity-25">
                                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-money-bill-wave me-2 text-warning"></i>สรุปการใช้จ่ายงบประมาณ</h6>
                                <div class="row g-2">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label small fw-bold text-muted">งบประมาณที่อนุมัติ</label>
                                        <input type="text" class="form-control form-control-sm bg-white" value="฿<?php echo number_format($project['budget'], 2); ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label small fw-bold text-success">ใช้ดำเนินการจริง (บาท) <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-sm shadow-sm">
                                            <span class="input-group-text bg-success text-white border-success">฿</span>
                                            <input type="number" name="actual_budget" class="form-control border-success bg-white" step="0.01" value="<?php echo $project['actual_budget'] !== null ? htmlspecialchars($project['actual_budget']) : '0'; ?>" <?php echo $disabled_attr; ?> required>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <label class="form-label small fw-bold text-danger">งบคงเหลือ (เงินทอน)</label>
                                        <input type="text" class="form-control form-control-sm bg-danger-subtle text-danger fw-bold border-danger" value="฿<?php echo number_format($project['remaining_budget'], 2); ?>" readonly>
                                        <small class="text-muted" style="font-size: 0.7rem;">* ระบบคำนวณเงินทอนอัตโนมัติเมื่อกดบันทึก</small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($can_edit): ?>
                            <div class="text-end"><button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูลรายงาน</button></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .custom-checkbox-card { cursor: pointer; transition: all 0.2s; border: 2px solid transparent !important; }
    .form-check-input { width: 1.5em; height: 1.5em; margin-top: 0.2em; border-radius: 6px !important; }
    .form-check-input:checked { background-color: var(--primary-purple); border-color: var(--primary-purple); }
    .form-check-input:checked ~ .form-checked-content { opacity: 0.5; text-decoration: line-through; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('activity_images');
    if(fileInput) {
        fileInput.addEventListener('change', async function(e) {
            if (this.files.length > 5) {
                alert("อัปโหลดได้สูงสุด 5 ภาพต่อครั้ง");
                this.value = ''; 
                return;
            }
            
            const container = document.getElementById('image_preview_container');
            const originalContent = container.innerHTML;
            
            // Show loading message
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'w-100 text-primary small mt-2';
            loadingDiv.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังปรับขนาดรูปภาพให้ไม่เกิน 500KB กรุณารอสักครู่...';
            container.parentNode.insertBefore(loadingDiv, container.nextSibling);
            
            let dt = new DataTransfer();
            for (let i = 0; i < this.files.length; i++) {
                let file = this.files[i];
                if (!file.type.match(/image.*/)) {
                    dt.items.add(file);
                    continue;
                }
                
                try {
                    let resizedFile = await compressImage(file, 500 * 1024); // max 500kb
                    dt.items.add(resizedFile);
                } catch(err) {
                    console.error("Compression error", err);
                    dt.items.add(file); // fallback
                }
            }
            this.files = dt.files;
            
            // Remove loading message
            loadingDiv.remove();
        });
    }
});

function compressImage(file, maxBytes) {
    return new Promise((resolve, reject) => {
        if (file.size <= maxBytes) {
            resolve(file); return;
        }
        const img = new Image();
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; };
        reader.onerror = reject;
        img.onload = () => {
            const canvas = document.createElement('canvas');
            let width = img.width;
            let height = img.height;
            
            const maxDim = 1200;
            if (width > maxDim || height > maxDim) {
                if (width > height) {
                    height = Math.round((height *= maxDim / width));
                    width = maxDim;
                } else {
                    width = Math.round((width *= maxDim / height));
                    height = maxDim;
                }
            }
            
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);
            
            let quality = 0.9;
            let loop = () => {
                canvas.toBlob(blob => {
                    if(!blob) { resolve(file); return; }
                    if (blob.size <= maxBytes || quality <= 0.3) {
                        const newFile = new File([blob], file.name, {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        resolve(newFile);
                    } else {
                        quality -= 0.1;
                        loop();
                    }
                }, 'image/jpeg', quality);
            };
            loop();
        };
        img.onerror = reject;
        reader.readAsDataURL(file);
    });
}
</script>