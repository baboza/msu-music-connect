<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// 1. ตรวจสอบว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['msg'] = "ไม่พบรหัสอ้างอิงของรายงาน";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$report_id = intval($_GET['id']);

// 2. ดึงข้อมูลเดิมจากฐานข้อมูล (ต้องเป็นของตัวเอง หรือเป็น Admin)
$is_admin = hasRole(['admin', 'การเงิน', 'หัวหน้าสำนักงาน']);
$sql_check = "SELECT * FROM training_reports WHERE report_id = ?";
$params_check = [$report_id];

if (!$is_admin) {
    $sql_check .= " AND user_id = ?";
    $params_check[] = $user_id;
}

$stmt_check = $pdo->prepare($sql_check);
$stmt_check->execute($params_check);
$report = $stmt_check->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['msg'] = "ไม่พบข้อมูล หรือคุณไม่มีสิทธิ์แก้ไขรายงานนี้";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

// 3. จัดการเมื่อกดปุ่ม "บันทึกการแก้ไข" (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $training_hours = !empty($_POST['training_hours']) ? intval($_POST['training_hours']) : null;

        // จัดการอัปโหลดไฟล์แนบ
        $up_dir = __DIR__ . '/../../uploads/trainings/';
        if (!is_dir($up_dir)) {
            mkdir($up_dir, 0777, true);
        }
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'];

        $invitation_file = $report['invitation_file'] ?? null;
        if (isset($_FILES['invitation_file']) && $_FILES['invitation_file']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['invitation_file']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $invitation_file = 'invite_' . $report['user_id'] . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['invitation_file']['tmp_name'], $up_dir . $invitation_file);
            }
        }

        $highway_map_file = $report['highway_map_file'] ?? null;
        if (isset($_FILES['highway_map_file']) && $_FILES['highway_map_file']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['highway_map_file']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $highway_map_file = 'map_' . $report['user_id'] . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['highway_map_file']['tmp_name'], $up_dir . $highway_map_file);
            }
        }

        $travel_start_date = !empty($_POST['travel_start_date']) ? $_POST['travel_start_date'] : $_POST['start_date'];
        $travel_end_date = !empty($_POST['travel_end_date']) ? $_POST['travel_end_date'] : $_POST['end_date'];

        $is_resubmit = (isset($_POST['submit_action']) && $_POST['submit_action'] === 'resubmit');
        $sql_resubmit = "";
        if ($is_resubmit) {
            $sql_resubmit = ", status = 'pending', current_step = 1, step1_status = 'pending', step2_status = 'pending', step3_status = 'pending', step4_status = 'pending', step5_status = 'pending' ";
        }

        $stmt_update = $pdo->prepare("
            UPDATE training_reports 
            SET project_name = ?, organizer = ?, location = ?, report_type = ?,
                start_date = ?, end_date = ?, travel_start_date = ?, travel_end_date = ?,
                invitation_file = ?, highway_map_file = ?,
                budget_used = ?, summary = ?, evaluation = ?, apply_how = ?, 
                apply_expected_result = ?, apply_completion_date = ?, 
                est_budget_travel = ?, est_budget_accom = ?, est_budget_allowance = ?, est_budget_regis = ?, est_budget_other = ?,
                act_budget_travel = ?, act_budget_accom = ?, act_budget_allowance = ?, act_budget_regis = ?, act_budget_other = ?,
                training_hours = ?
                {$sql_resubmit}
            WHERE report_id = ?
        ");
        
        $stmt_update->execute([
            $_POST['project_name'],
            $_POST['organizer'],
            $_POST['location'],
            $_POST['report_type'] ?? 'ไปราชการ',
            $_POST['start_date'],
            $_POST['end_date'],
            $travel_start_date,
            $travel_end_date,
            $invitation_file,
            $highway_map_file,
            $_POST['budget_used'] ?: 0,
            $_POST['summary'],
            $_POST['evaluation'],
            $_POST['apply_how'],
            $_POST['apply_expected_result'],
            !empty($_POST['apply_completion_date']) ? $_POST['apply_completion_date'] : null,
            $_POST['est_budget_travel'] ?: 0,
            $_POST['est_budget_accom'] ?: 0,
            $_POST['est_budget_allowance'] ?: 0,
            $_POST['est_budget_regis'] ?: 0,
            $_POST['est_budget_other'] ?: 0,
            $_POST['act_budget_travel'] ?: 0,
            $_POST['act_budget_accom'] ?: 0,
            $_POST['act_budget_allowance'] ?: 0,
            $_POST['act_budget_regis'] ?: 0,
            $_POST['act_budget_other'] ?: 0,
            $training_hours,
            $report_id
        ]);

        if ($is_resubmit) {
            $_SESSION['msg'] = "บันทึกข้อมูลและส่งคำขอเข้าสู่การพิจารณาใหม่อีกครั้งเรียบร้อยแล้ว";
        } else {
            $_SESSION['msg'] = "บันทึก/อัปเดตข้อมูลการไปราชการเรียบร้อยแล้ว";
        }
        $_SESSION['msg_type'] = "success";
        header("Location: view.php?id=" . $report_id);
        exit();
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ดึงค่าเดิมมาใช้งานเพื่อความสะอาดของโค้ด
$eval = $report['evaluation'] ?? '';
$tr_s = !empty($report['travel_start_date']) ? $report['travel_start_date'] : $report['start_date'];
$tr_e = !empty($report['travel_end_date']) ? $report['travel_end_date'] : $report['end_date'];
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-4 pb-3 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไข/เพิ่มเติมรายงานผลไปราชการ</h1>
        <p class="text-muted mb-0">รหัสเอกสาร: TR-<?php echo str_pad($report_id, 5, '0', STR_PAD_LEFT); ?> | สำหรับกรอกข้อมูลรายงานให้สมบูรณ์</p>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger shadow-sm rounded-4"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($report['status'] === 'returned'): ?>
        <div class="alert alert-warning border-2 border-warning rounded-4 shadow-sm mb-4 p-4">
            <div class="d-flex align-items-start gap-3">
                <div class="bg-warning text-dark rounded-circle p-2 d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
                    <i class="fa-solid fa-rotate-left fs-5"></i>
                </div>
                <div>
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> คำขอนี้ถูกส่งกลับให้แก้ไข (Returned for Revision)
                    </h5>
                    <div class="text-dark mb-2">
                        <strong>ข้อความ/เหตุผลที่ต้องแก้ไข:</strong> 
                        <div class="p-2 mt-1 bg-white rounded-3 border text-danger fw-bold">
                            <?= nl2br(htmlspecialchars($report['return_comment'] ?? 'กรุณาตรวจสอบและแก้ไขข้อมูล')) ?>
                        </div>
                    </div>
                    <small class="text-muted">
                        เมื่อคุณแก้ไขข้อมูลหรือแนบเอกสารเพิ่มเติมเรียบร้อยแล้ว สามารถกดปุ่ม <strong>"บันทึกและส่งพิจารณาอีกครั้ง"</strong> ด้านล่าง เพื่อส่งกลับเข้าสู่ขั้นตอนการพิจารณาได้ทันที
                    </small>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
        
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-circle-info text-primary me-2"></i>ส่วนที่ 1: ข้อมูลโครงการและการเดินทาง</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-3">
                        <label class="form-label fw-bold text-dark small text-uppercase">ประเภท <span class="text-danger">*</span></label>
                        <select name="report_type" class="form-select form-control-lg border-secondary border-opacity-25 bg-light" required>
                            <option value="สัมมนา" <?php echo ($report['report_type'] ?? '') == 'สัมมนา' ? 'selected' : ''; ?>>สัมมนา</option>
                            <option value="อบรม" <?php echo ($report['report_type'] ?? '') == 'อบรม' ? 'selected' : ''; ?>>อบรม</option>
                            <option value="ดูงาน" <?php echo ($report['report_type'] ?? '') == 'ดูงาน' ? 'selected' : ''; ?>>ดูงาน</option>
                            <option value="ไปราชการ" <?php echo ($report['report_type'] ?? 'ไปราชการ') == 'ไปราชการ' ? 'selected' : ''; ?>>ไปราชการ</option>
                            <option value="อื่นๆ" <?php echo ($report['report_type'] ?? '') == 'อื่นๆ' ? 'selected' : ''; ?>>อื่นๆ</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">ชื่อหลักสูตร / โครงการ <span class="text-danger">*</span></label>
                        <input type="text" name="project_name" class="form-control form-control-lg border-secondary border-opacity-25 bg-light" required value="<?php echo htmlspecialchars($report['project_name']); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold text-dark small text-uppercase">จำนวนชั่วโมงอบรมจริง (ชม.)</label>
                        <input type="number" name="training_hours" class="form-control form-control-lg border-secondary border-opacity-25 bg-light fw-bold text-purple" value="<?= htmlspecialchars($report['training_hours'] ?? '') ?>" placeholder="เช่น 6, 12, 18">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">หน่วยงานที่จัด <span class="text-danger">*</span></label>
                        <input type="text" name="organizer" class="form-control border-secondary border-opacity-25 bg-light" required value="<?php echo htmlspecialchars($report['organizer']); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">สถานที่จัด <span class="text-danger">*</span></label>
                        <input type="text" name="location" class="form-control border-secondary border-opacity-25 bg-light" required value="<?php echo htmlspecialchars($report['location']); ?>">
                    </div>

                    <!-- วันจัดโครงการ -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase"><i class="fa-solid fa-calendar-check text-primary me-1"></i>วันจัดโครงการ (เริ่มต้น) <span class="text-danger">*</span></label>
                        <input type="date" name="start_date" id="start_date" class="form-control border-secondary border-opacity-25 bg-light" required value="<?php echo $report['start_date']; ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase"><i class="fa-solid fa-calendar-xmark text-primary me-1"></i>วันจัดโครงการ (สิ้นสุด) <span class="text-danger">*</span></label>
                        <input type="date" name="end_date" id="end_date" class="form-control border-secondary border-opacity-25 bg-light" required value="<?php echo $report['end_date']; ?>">
                    </div>

                    <!-- กำหนดวันเดินทาง -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-purple small text-uppercase"><i class="fa-solid fa-car-side me-1"></i>กำหนดวันเดินทาง (วันออกเดินทาง) <span class="text-danger">*</span></label>
                        <input type="date" name="travel_start_date" id="travel_start_date" class="form-control border-purple border-opacity-25 bg-light" required value="<?php echo $tr_s; ?>">
                        <small class="text-muted" style="font-size: 0.75rem;">วันเริ่มต้นออกเดินทางไปปฏิบัติงาน</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-purple small text-uppercase"><i class="fa-solid fa-house-chimney me-1"></i>กำหนดวันเดินทาง (วันเดินทางกลับ) <span class="text-danger">*</span></label>
                        <input type="date" name="travel_end_date" id="travel_end_date" class="form-control border-purple border-opacity-25 bg-light" required value="<?php echo $tr_e; ?>">
                        <small class="text-muted" style="font-size: 0.75rem;">วันเดินทางกลับถึงหน่วยงาน/ที่พัก</small>
                    </div>

                    <!-- เอกสารแนบ -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase"><i class="fa-solid fa-envelope-open-text text-danger me-1"></i>แนบไฟล์หนังสือเชิญ / กำหนดการ</label>
                        <input type="file" name="invitation_file" class="form-control border-secondary border-opacity-25 bg-light" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <?php if (!empty($report['invitation_file'])): ?>
                            <div class="mt-1">
                                <a href="../../uploads/trainings/<?= htmlspecialchars($report['invitation_file']) ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none">
                                    <i class="fa-solid fa-file-pdf me-1"></i> ดูไฟล์เดิม: <?= htmlspecialchars($report['invitation_file']) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark small text-uppercase"><i class="fa-solid fa-map-location-dot text-success me-1"></i>แนบไฟล์แผนที่กรมทางหลวง (ระยะทาง)</label>
                        <input type="file" name="highway_map_file" class="form-control border-secondary border-opacity-25 bg-light" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <?php if (!empty($report['highway_map_file'])): ?>
                            <div class="mt-1">
                                <a href="../../uploads/trainings/<?= htmlspecialchars($report['highway_map_file']) ?>" target="_blank" class="badge bg-light text-success border text-decoration-none">
                                    <i class="fa-solid fa-map me-1"></i> ดูไฟล์เดิม: <?= htmlspecialchars($report['highway_map_file']) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-12">
                        <label class="form-label fw-bold text-dark small text-uppercase">ปีงบประมาณ</label>
                        <input type="text" class="form-control border-0 bg-transparent text-muted fw-bold p-0" value="<?php echo htmlspecialchars($report['fiscal_year']); ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 1.5: รายละเอียดงบประมาณ -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-coins text-warning me-2"></i>ส่วนที่ 2: รายละเอียดงบประมาณ (บาท)</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- ประมาณการงบประมาณ -->
                    <div class="col-md-6 border-end">
                        <h6 class="fw-bold text-primary mb-3">1. ประมาณการงบประมาณ</h6>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าเดินทาง</label>
                                <input type="number" step="0.01" name="est_budget_travel" class="form-control bg-light" value="<?php echo $report['est_budget_travel'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าที่พัก</label>
                                <input type="number" step="0.01" name="est_budget_accom" class="form-control bg-light" value="<?php echo $report['est_budget_accom'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าเบี้ยเลี้ยง</label>
                                <input type="number" step="0.01" name="est_budget_allowance" class="form-control bg-light" value="<?php echo $report['est_budget_allowance'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าลงทะเบียน</label>
                                <input type="number" step="0.01" name="est_budget_regis" class="form-control bg-light" value="<?php echo $report['est_budget_regis'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-12">
                                <label class="form-label small text-muted">ค่าอื่นๆ</label>
                                <input type="number" step="0.01" name="est_budget_other" class="form-control bg-light" value="<?php echo $report['est_budget_other'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>

                    <!-- ค่าใช้จ่ายจริง -->
                    <div class="col-md-6">
                        <h6 class="fw-bold text-success mb-3">2. งบประมาณที่จ่ายจริง (ระบุตอนรายงานผล)</h6>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าเดินทาง</label>
                                <input type="number" step="0.01" name="act_budget_travel" class="form-control bg-light act-budget" value="<?php echo $report['act_budget_travel'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าที่พัก</label>
                                <input type="number" step="0.01" name="act_budget_accom" class="form-control bg-light act-budget" value="<?php echo $report['act_budget_accom'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าเบี้ยเลี้ยง</label>
                                <input type="number" step="0.01" name="act_budget_allowance" class="form-control bg-light act-budget" value="<?php echo $report['act_budget_allowance'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">ค่าลงทะเบียน</label>
                                <input type="number" step="0.01" name="act_budget_regis" class="form-control bg-light act-budget" value="<?php echo $report['act_budget_regis'] ?? 0; ?>">
                            </div>
                            <div class="col-sm-12">
                                <label class="form-label small text-muted">ค่าอื่นๆ</label>
                                <input type="number" step="0.01" name="act_budget_other" class="form-control bg-light act-budget" value="<?php echo $report['act_budget_other'] ?? 0; ?>">
                            </div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark">รวมจ่ายจริงทั้งหมด</span>
                            <span class="fw-bold text-success fs-5">฿<span id="act_total_display">0.00</span></span>
                            <input type="hidden" name="budget_used" id="budget_used" value="<?php echo $report['budget_used'] ?? 0; ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-pen-nib text-purple me-2"></i>ส่วนที่ 3: สรุปผลการประชุม/ฝึกอบรม และการประเมิน</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-12">
                        <label class="form-label fw-bold text-dark small text-uppercase">เนื้อหาสาระและสรุปผลที่ได้รับ <span class="text-danger">*</span></label>
                        <textarea name="summary" class="form-control border-secondary border-opacity-25 bg-light" rows="5" required placeholder="อธิบายเนื้อหาโดยสรุป หรือความรู้ที่ได้รับ..."><?php echo htmlspecialchars($report['summary'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-bold text-dark small text-uppercase d-block mb-3">ประเมินผลภาพรวมของหลักสูตรนี้ <span class="text-danger">*</span></label>
                        
                        <div class="d-flex flex-wrap gap-4">
                            <div class="form-check form-check-inline custom-radio eval-excellent">
                                <input class="form-check-input" type="radio" name="evaluation" id="eval_1" value="ดีมาก" required <?php echo ($eval == 'ดีมาก') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="eval_1"><i class="fa-solid fa-face-grin-stars me-1"></i> ดีมาก</label>
                            </div>
                            <div class="form-check form-check-inline custom-radio eval-good">
                                <input class="form-check-input" type="radio" name="evaluation" id="eval_2" value="ดี" required <?php echo ($eval == 'ดี') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="eval_2"><i class="fa-solid fa-face-smile me-1"></i> ดี</label>
                            </div>
                            <div class="form-check form-check-inline custom-radio eval-bad">
                                <input class="form-check-input" type="radio" name="evaluation" id="eval_3" value="ไม่ดี" required <?php echo ($eval == 'ไม่ดี') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="eval_3"><i class="fa-solid fa-face-frown me-1"></i> ไม่ดี</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-lightbulb text-warning me-2"></i>ส่วนที่ 3: แผนการนำความรู้และทักษะไปใช้ประโยชน์</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-12">
                        <label class="form-label fw-bold text-dark small text-uppercase">แนวทางการนำไปประยุกต์ใช้ (How to apply) <span class="text-danger">*</span></label>
                        <textarea name="apply_how" class="form-control border-secondary border-opacity-25 bg-light" rows="3" required placeholder="อธิบายว่าจะนำความรู้ที่ได้ไปใช้ในการทำงานอย่างไร..."><?php echo htmlspecialchars($report['apply_how'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-bold text-dark small text-uppercase">ผลที่คาดว่าจะได้รับ (Expected Outcome) <span class="text-danger">*</span></label>
                        <input type="text" name="apply_expected_result" class="form-control border-secondary border-opacity-25 bg-light" required value="<?php echo htmlspecialchars($report['apply_expected_result'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark small text-uppercase">กำหนดเสร็จสิ้น (ถ้ามี)</label>
                        <input type="date" name="apply_completion_date" class="form-control border-secondary border-opacity-25 bg-light" value="<?php echo (!empty($report['apply_completion_date']) && $report['apply_completion_date'] != '0000-00-00') ? $report['apply_completion_date'] : ''; ?>">
                        <small class="text-muted extra-small mt-1 d-block">เว้นว่างได้ หากไม่สามารถระบุวันที่ชัดเจน</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-5 bg-white p-4 rounded-4 shadow-sm border flex-wrap gap-3">
            <a href="view.php?id=<?= $report_id ?>" class="btn btn-light border rounded-pill px-4 fw-bold shadow-sm">ยกเลิก</a>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" name="submit_action" value="save" class="btn btn-outline-purple rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไข
                </button>
                <?php if ($report['status'] === 'returned'): ?>
                    <button type="submit" name="submit_action" value="resubmit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow-sm fs-5 hover-lift">
                        <i class="fa-solid fa-paper-plane me-2"></i> บันทึกและส่งพิจารณาอีกครั้ง
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>

<script>
// คำนวณรวมยอดงบประมาณที่จ่ายจริง
document.addEventListener('DOMContentLoaded', function() {
    const actInputs = document.querySelectorAll('.act-budget');
    const totalDisplay = document.getElementById('act_total_display');
    const budgetUsed = document.getElementById('budget_used');
    
    function calculateTotal() {
        let total = 0;
        actInputs.forEach(input => {
            let val = parseFloat(input.value);
            if (!isNaN(val)) {
                total += val;
            }
        });
        
        // Update display
        totalDisplay.innerText = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        // Update hidden field for submission
        budgetUsed.value = total;
    }
    
    actInputs.forEach(input => {
        input.addEventListener('input', calculateTotal);
        // ถ้าระบุค่าว่างให้เป็น 0 เมื่อเสียโฟกัส
        input.addEventListener('blur', function() {
            if (this.value === '') this.value = '0';
            calculateTotal();
        });
    });
    
    // Initial calculation
    calculateTotal();
});
</script>

<style>
    .text-purple { color: #39048f; }
    .bg-purple { background-color: #39048f; }
    .btn-purple { background-color: #39048f; color: white; transition: all 0.2s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(75,0,130,0.2); }
    
    .form-control:focus, .form-select:focus { border-color: #39048f; box-shadow: 0 0 0 0.25rem rgba(57, 4, 143,0.1); background-color: #fff !important; }
    .extra-small { font-size: 0.75rem; }
    .hover-lift { transition: transform 0.2s; }
    .hover-lift:hover { transform: translateY(-3px); }

    /* Custom Radio Buttons Styling */
    .custom-radio { padding: 10px 20px; border-radius: 30px; border: 2px solid #eee; cursor: pointer; transition: 0.2s; background: #fff; }
    .custom-radio:hover { background: #f8f9fa; }
    
    .eval-excellent input:checked ~ label { color: #198754; }
    .eval-excellent:has(input:checked) { border-color: #198754; background-color: #d1e7dd; }
    
    .eval-good input:checked ~ label { color: #0d6efd; }
    .eval-good:has(input:checked) { border-color: #0d6efd; background-color: #cfe2ff; }
    
    .eval-bad input:checked ~ label { color: #dc3545; }
    .eval-bad:has(input:checked) { border-color: #dc3545; background-color: #f8d7da; }
    
    .form-check-input { display: none; }
</style>

<script>
    // Validation Script
    (function () {
        'use strict'
        var forms = document.querySelectorAll('.needs-validation')
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                        alert('กรุณากรอกข้อมูลในช่องที่มีเครื่องหมายดอกจัน (*) สีแดงให้ครบถ้วน');
                    }
                    form.classList.add('was-validated')
                }, false)
            })
    })()
</script>

<?php require_once '../../includes/footer.php'; ?>
