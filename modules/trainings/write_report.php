<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$report_id = $_GET['id'] ?? '';

if (empty($report_id)) {
    echo "<script>alert('ไม่พบรหัสอ้างอิง'); window.location.href='index.php';</script>";
    exit();
}

// 1. ดึงข้อมูลโครงการที่เคยขออนุมัติไว้ (และต้องเป็นของตนเอง + สถานะ approved หรือ reported สำหรับแก้ไข)
try {
    $stmt = $pdo->prepare("SELECT * FROM training_reports WHERE report_id = ? AND user_id = ?");
    $stmt->execute([$report_id, $user_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo "<script>alert('ไม่พบข้อมูล หรือคุณไม่มีสิทธิ์เข้าถึง'); window.location.href='index.php';</script>";
        exit();
    }

    if (!in_array($report['status'], ['approved', 'reported'])) {
        echo "<script>alert('รายการนี้ไม่อยู่ในสถานะที่สามารถรายงานผลได้'); window.location.href='index.php';</script>";
        exit();
    }

    $default_days = 1;
    if (!empty($report['start_date']) && !empty($report['end_date'])) {
        $s = new DateTime($report['start_date']);
        $e = new DateTime($report['end_date']);
        $default_days = max(1, $s->diff($e)->days + 1);
    }
    $default_hours = $default_days * 6;
    $training_hours_val = !empty($report['training_hours']) ? intval($report['training_hours']) : $default_hours;

    // คำนวณงบประมาณประมาณการเดิม
    $est_travel = floatval($report['est_budget_travel'] ?? 0);
    $est_accom = floatval($report['est_budget_accom'] ?? 0);
    $est_allowance = floatval($report['est_budget_allowance'] ?? 0);
    $est_regis = floatval($report['est_budget_regis'] ?? 0);
    $est_other = floatval($report['est_budget_other'] ?? 0);
    $est_total = $est_travel + $est_accom + $est_allowance + $est_regis + $est_other;

    // ยอดจ่ายจริงที่มีอยู่เดิม
    $act_travel = isset($report['act_budget_travel']) ? floatval($report['act_budget_travel']) : $est_travel;
    $act_accom = isset($report['act_budget_accom']) ? floatval($report['act_budget_accom']) : $est_accom;
    $act_allowance = isset($report['act_budget_allowance']) ? floatval($report['act_budget_allowance']) : $est_allowance;
    $act_regis = isset($report['act_budget_regis']) ? floatval($report['act_budget_regis']) : $est_regis;
    $act_other = isset($report['act_budget_other']) ? floatval($report['act_budget_other']) : $est_other;
    $act_total = isset($report['budget_used']) && $report['budget_used'] > 0 
        ? floatval($report['budget_used']) 
        : ($act_travel + $act_accom + $act_allowance + $act_regis + $act_other);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// 2. จัดการเมื่อกดปุ่ม "บันทึกรายงานผล"
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // จัดการอัปโหลดไฟล์เกียรติบัตร / เอกสารการอบรม
        $cert_filename = $report['certificate_file'] ?? null;
        if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
            $up_dir = __DIR__ . '/../../uploads/trainings/';
            if (!is_dir($up_dir)) {
                mkdir($up_dir, 0777, true);
            }
            $orig = $_FILES['certificate_file']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'zip', 'doc', 'docx'];
            if (in_array($ext, $allowed)) {
                $cert_filename = 'cert_' . $report_id . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['certificate_file']['tmp_name'], $up_dir . $cert_filename);
            }
        }

        $training_hours = !empty($_POST['training_hours']) ? intval($_POST['training_hours']) : $default_hours;

        $act_budget_travel = floatval($_POST['act_budget_travel'] ?? 0);
        $act_budget_accom = floatval($_POST['act_budget_accom'] ?? 0);
        $act_budget_allowance = floatval($_POST['act_budget_allowance'] ?? 0);
        $act_budget_regis = floatval($_POST['act_budget_regis'] ?? 0);
        $act_budget_other = floatval($_POST['act_budget_other'] ?? 0);
        $budget_used = floatval($_POST['budget_used'] ?? ($act_budget_travel + $act_budget_accom + $act_budget_allowance + $act_budget_regis + $act_budget_other));

        $update_stmt = $pdo->prepare("
            UPDATE training_reports 
            SET 
                summary = ?, 
                evaluation = ?, 
                apply_how = ?, 
                apply_expected_result = ?, 
                apply_completion_date = ?, 
                budget_used = ?,
                act_budget_travel = ?,
                act_budget_accom = ?,
                act_budget_allowance = ?,
                act_budget_regis = ?,
                act_budget_other = ?,
                training_hours = ?,
                certificate_file = ?,
                status = 'reported' 
            WHERE report_id = ? AND user_id = ?
        ");
        
        $update_stmt->execute([
            trim($_POST['summary'] ?? ''),
            $_POST['evaluation'] ?? 'ดีมาก',
            trim($_POST['apply_how'] ?? ''),
            trim($_POST['apply_expected_result'] ?? ''),
            !empty($_POST['apply_completion_date']) ? $_POST['apply_completion_date'] : null,
            $budget_used,
            $act_budget_travel,
            $act_budget_accom,
            $act_budget_allowance,
            $act_budget_regis,
            $act_budget_other,
            $training_hours,
            $cert_filename,
            $report_id,
            $user_id
        ]);

        $_SESSION['msg'] = "ส่งรายงานผลการไปราชการ/อบรม เรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
        header("Location: index.php");
        exit();

    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Page Header & Breadcrumb -->
    <div class="d-flex justify-content-between align-items-center flex-wrap pt-4 pb-3 mb-4 border-bottom gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small text-muted mb-1">
                    <li class="breadcrumb-item"><a href="../../index.php" class="text-decoration-none text-muted">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">การไปราชการ/อบรม</a></li>
                    <li class="breadcrumb-item active text-purple fw-semibold" aria-current="page">เขียนรายงานผล</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-file-signature text-purple me-2"></i>รายงานผลการไปราชการ / ฝึกอบรม
            </h1>
            <p class="text-muted small mb-0">กรุณาสรุปผลสัมฤทธิ์ การนำไปใช้ประโยชน์ และงบประมาณจ่ายจริงหลังสิ้นสุดการเดินทาง</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-purple-subtle text-purple border border-purple px-3 py-2 rounded-pill small">
                <i class="fa-solid fa-hashtag me-1"></i> TR-<?= str_pad($report['report_id'], 5, '0', STR_PAD_LEFT) ?>
            </span>
            <span class="badge <?= ($report['status'] === 'reported') ? 'bg-success-subtle text-success border border-success' : 'bg-warning-subtle text-warning-emphasis border border-warning' ?> px-3 py-2 rounded-pill small">
                <i class="fa-solid <?= ($report['status'] === 'reported') ? 'fa-check-circle' : 'fa-hourglass-half' ?> me-1"></i>
                <?= ($report['status'] === 'reported') ? 'รายงานผลแล้ว (แก้ไขข้อมูล)' : 'รอรายงานผล' ?>
            </span>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 py-1 shadow-xs small">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger shadow-xs border-start border-danger border-4 rounded-3 mb-4">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error; ?>
        </div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data" id="reportForm">
        <div class="row g-4">
            
            <!-- คอลัมน์ซ้าย: ข้อมูลโครงการเดิมที่ขออนุมัติไว้ (Reference Card) -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-xs rounded-4 bg-white sticky-top" style="top: 20px; z-index: 10;">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 pb-2 px-4 d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-purple small">
                            <i class="fa-solid fa-circle-info me-1"></i> ข้อมูลโครงการที่ได้รับอนุมัติ
                        </span>
                        <span class="badge bg-light text-dark border px-2 py-1 small">
                            ปีงบฯ <?= htmlspecialchars($report['fiscal_year'] ?? '-') ?>
                        </span>
                    </div>
                    <div class="card-body p-4">
                        <!-- Project Title -->
                        <div class="mb-3">
                            <span class="badge bg-purple text-white px-2 py-1 rounded-pill small mb-2">
                                <?= htmlspecialchars($report['report_type'] ?? 'ไปราชการ') ?>
                            </span>
                            <h6 class="fw-bold text-dark lh-base mb-1">
                                <?= htmlspecialchars($report['project_name']) ?>
                            </h6>
                        </div>

                        <hr class="border-light-subtle my-3">

                        <!-- Organizer & Location -->
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">หน่วยงานผู้จัด</small>
                            <div class="small fw-semibold text-dark">
                                <i class="fa-solid fa-building-columns text-purple opacity-75 me-1"></i>
                                <?= htmlspecialchars($report['organizer'] ?? '-') ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">สถานที่จัดโครงการ</small>
                            <div class="small fw-semibold text-dark">
                                <i class="fa-solid fa-location-dot text-danger opacity-75 me-1"></i>
                                <?= htmlspecialchars($report['location'] ?? '-') ?>
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="mb-3 p-3 bg-light rounded-3 border">
                            <div class="mb-2">
                                <small class="text-muted d-block" style="font-size: 0.75rem;">วันจัดโครงการ</small>
                                <div class="small fw-bold text-dark">
                                    <i class="fa-solid fa-calendar-check text-primary me-1"></i>
                                    <?php 
                                        if (!empty($report['start_date']) && !empty($report['end_date'])) {
                                            if ($report['start_date'] == $report['end_date']) {
                                                echo date('d/m/Y', strtotime($report['start_date']));
                                            } else {
                                                echo date('d/m/Y', strtotime($report['start_date'])) . ' - ' . date('d/m/Y', strtotime($report['end_date']));
                                            }
                                            echo " <span class='text-muted fw-normal'>({$default_days} วัน)</span>";
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </div>
                            </div>
                            <div>
                                <small class="text-muted d-block" style="font-size: 0.75rem;">กำหนดวันเดินทาง</small>
                                <div class="small fw-bold text-purple">
                                    <i class="fa-solid fa-car-side me-1"></i>
                                    <?php 
                                        $tr_s = !empty($report['travel_start_date']) ? $report['travel_start_date'] : $report['start_date'];
                                        $tr_e = !empty($report['travel_end_date']) ? $report['travel_end_date'] : $report['end_date'];
                                        if (!empty($tr_s) && !empty($tr_e)) {
                                            if ($tr_s == $tr_e) {
                                                echo date('d/m/Y', strtotime($tr_s));
                                            } else {
                                                echo date('d/m/Y', strtotime($tr_s)) . ' - ' . date('d/m/Y', strtotime($tr_e));
                                            }
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Budget Box -->
                        <div class="p-3 bg-purple-subtle rounded-3 border border-purple mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-purple fw-bold">งบประมาณที่ขออนุมัติไว้</small>
                                <span class="fs-6 fw-bold text-purple">฿<?= number_format($est_total, 2) ?></span>
                            </div>
                            <div class="small text-muted" style="font-size: 0.75rem;">
                                รวม 5 หมวด (เดินทาง, ที่พัก, เบี้ยเลี้ยง, ทะเบียน, อื่นๆ)
                            </div>
                        </div>

                        <!-- Previous Attachments -->
                        <?php if (!empty($report['invitation_file']) || !empty($report['highway_map_file'])): ?>
                            <div>
                                <small class="text-muted d-block mb-2 fw-semibold" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-paperclip me-1"></i> เอกสารแนบคำขอเดิม:
                                </small>
                                <div class="d-flex flex-column gap-1">
                                    <?php if (!empty($report['invitation_file'])): ?>
                                        <a href="../../uploads/trainings/<?= htmlspecialchars($report['invitation_file']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary text-start rounded-pill px-3 py-1 text-truncate" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-envelope-open-text text-danger me-1"></i> หนังสือเชิญ/กำหนดการ
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($report['highway_map_file'])): ?>
                                        <a href="../../uploads/trainings/<?= htmlspecialchars($report['highway_map_file']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary text-start rounded-pill px-3 py-1 text-truncate" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-map-location-dot text-success me-1"></i> แผนที่กรมทางหลวง
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- คอลัมน์ขวา: ฟอร์มกรอกรายงานผล 5 ส่วน -->
            <div class="col-lg-8">
                
                <!-- ส่วนที่ 1: ผลการประชุม / ฝึกอบรม (โดยสรุป) -->
                <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 px-4 pb-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-pen-nib text-purple me-2"></i>1. ผลการประชุม / ฝึกอบรม (สาระสำคัญโดยสรุป) <span class="text-danger">*</span>
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-2">
                            สรุปองค์ความรู้ สาระสำคัญ ประเด็นสำคัญในการสัมมนา/อบรม หรือสิ่งที่ได้รับจากการปฏิบัติงาน:
                        </p>
                        <textarea name="summary" class="form-control border-2 rounded-3 p-3" rows="5" placeholder="อธิบายสาระสำคัญ ทฤษฎี เครื่องมือ หรือแนวปฏิบัติใหม่ๆ ที่ได้รับจากการเข้าร่วมโครงการ..." required><?= htmlspecialchars($report['summary'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- ส่วนที่ 2: การประเมินภาพรวมหลักสูตร/งาน -->
                <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 px-4 pb-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-star-half-stroke text-warning me-2"></i>2. การประเมินภาพรวมหลักสูตร / โครงการ <span class="text-danger">*</span>
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">
                            ประเมินความคุ้มค่า ความพร้อมของวิทยากร และความเหมาะสมของเนื้อหาที่ได้รับ:
                        </p>
                        <?php $cur_eval = $report['evaluation'] ?? 'ดีมาก'; ?>
                        <div class="row g-3">
                            <!-- Option 1: ดีมาก -->
                            <div class="col-md-4">
                                <label class="eval-card d-block h-100 p-3 rounded-4 border border-2 text-center position-relative <?= ($cur_eval === 'ดีมาก') ? 'selected border-success bg-success-subtle' : 'bg-light' ?>" style="cursor: pointer;">
                                    <input type="radio" name="evaluation" value="ดีมาก" class="form-check-input visually-hidden" <?= ($cur_eval === 'ดีมาก') ? 'checked' : '' ?> required>
                                    <div class="fs-2 text-success mb-1"><i class="fa-solid fa-face-laugh-beam"></i></div>
                                    <div class="fw-bold text-success fs-6">ดีมาก</div>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">เนื้อหามีประโยชน์อย่างยิ่ง วิทยากรเชี่ยวชาญ คุ้มค่า</small>
                                </label>
                            </div>

                            <!-- Option 2: ดี -->
                            <div class="col-md-4">
                                <label class="eval-card d-block h-100 p-3 rounded-4 border border-2 text-center position-relative <?= ($cur_eval === 'ดี') ? 'selected border-primary bg-primary-subtle' : 'bg-light' ?>" style="cursor: pointer;">
                                    <input type="radio" name="evaluation" value="ดี" class="form-check-input visually-hidden" <?= ($cur_eval === 'ดี') ? 'checked' : '' ?>>
                                    <div class="fs-2 text-primary mb-1"><i class="fa-solid fa-face-smile"></i></div>
                                    <div class="fw-bold text-primary fs-6">ดี</div>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">เนื้อหาเหมาะสม บรรลุตามวัตถุประสงค์โครงการ</small>
                                </label>
                            </div>

                            <!-- Option 3: ไม่ดี / ควรปรับปรุง -->
                            <div class="col-md-4">
                                <label class="eval-card d-block h-100 p-3 rounded-4 border border-2 text-center position-relative <?= ($cur_eval === 'ไม่ดี') ? 'selected border-danger bg-danger-subtle' : 'bg-light' ?>" style="cursor: pointer;">
                                    <input type="radio" name="evaluation" value="ไม่ดี" class="form-check-input visually-hidden" <?= ($cur_eval === 'ไม่ดี') ? 'checked' : '' ?>>
                                    <div class="fs-2 text-danger mb-1"><i class="fa-solid fa-face-meh"></i></div>
                                    <div class="fw-bold text-danger fs-6">ควรปรับปรุง</div>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">เนื้อหาไม่ตรงตามที่คาดหวัง หรือการจัดงานไม่พร้อม</small>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนที่ 3: การนำความรู้ไปใช้ประโยชน์ (Application Plan) -->
                <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 px-4 pb-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-lightbulb text-warning me-2"></i>3. การนำความรู้ไปใช้ประโยชน์ (Knowledge Application)
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-dark">
                                จะนำไปใช้ในการปฏิบัติงานอย่างไร? (How) <span class="text-danger">*</span>
                            </label>
                            <textarea name="apply_how" class="form-control border-2 rounded-3" rows="3" placeholder="ตัวอย่าง: นำมาปรับปรุงการจัดการเรียนการสอนในรายวิชา..., นำมาจัดทำคู่มือการปฏิบัติงาน หรือ ถ่ายทอดความรู้ (KM) ให้บุคลากรในหน่วยงาน..." required><?= htmlspecialchars($report['apply_how'] ?? '') ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold small text-dark">
                                    ผลหรือเป้าหมายที่คาดว่าจะได้รับ (Expected Outcome) <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="apply_expected_result" class="form-control border-2" placeholder="เช่น นิสิตมีผลสัมฤทธิ์ดีขึ้น, ลดเวลาการทำงานได้ 30%" value="<?= htmlspecialchars($report['apply_expected_result'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-dark">
                                    กำหนดแล้วเสร็จ (ถ้ามี)
                                </label>
                                <input type="date" name="apply_completion_date" class="form-control border-2" value="<?= htmlspecialchars($report['apply_completion_date'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนที่ 4: งบประมาณที่ใช้จ่ายจริง (Actual Expenses) -->
                <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 px-4 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-coins text-success me-2"></i>4. รายงานงบประมาณที่ใช้จ่ายจริง (Actual Expenses)
                        </h6>
                        <button type="button" class="btn btn-xs btn-outline-purple rounded-pill px-3 py-1" onclick="copyEstToAct()">
                            <i class="fa-solid fa-copy me-1"></i> ใช้ตามยอดประมาณการเดิม
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">
                            กรอกจำนวนเงินที่เบิกจ่ายจริงตามใบเสร็จรับเงินหรือเอกสารหลักฐานทางการเงิน:
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle table-hover border">
                                <thead class="table-light text-secondary small">
                                    <tr>
                                        <th style="width: 45%;">รายการค่าใช้จ่าย</th>
                                        <th class="text-end" style="width: 25%;">ยอดประมาณการ (บาท)</th>
                                        <th class="text-end" style="width: 30%;">จ่ายจริง (บาท)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="fw-semibold text-dark">
                                            <i class="fa-solid fa-bus text-muted me-2"></i>1. ค่าพาหนะ / เดินทาง
                                        </td>
                                        <td class="text-end text-muted font-monospace" id="cell_est_travel" data-val="<?= $est_travel ?>">
                                            <?= number_format($est_travel, 2) ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="act_budget_travel" id="act_travel" class="form-control form-control-sm text-end fw-bold border-2 actual-budget" value="<?= htmlspecialchars($act_travel) ?>" oninput="recalcBudget()">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-dark">
                                            <i class="fa-solid fa-hotel text-muted me-2"></i>2. ค่าที่พัก
                                        </td>
                                        <td class="text-end text-muted font-monospace" id="cell_est_accom" data-val="<?= $est_accom ?>">
                                            <?= number_format($est_accom, 2) ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="act_budget_accom" id="act_accom" class="form-control form-control-sm text-end fw-bold border-2 actual-budget" value="<?= htmlspecialchars($act_accom) ?>" oninput="recalcBudget()">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-dark">
                                            <i class="fa-solid fa-utensils text-muted me-2"></i>3. ค่าเบี้ยเลี้ยง
                                        </td>
                                        <td class="text-end text-muted font-monospace" id="cell_est_allowance" data-val="<?= $est_allowance ?>">
                                            <?= number_format($est_allowance, 2) ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="act_budget_allowance" id="act_allowance" class="form-control form-control-sm text-end fw-bold border-2 actual-budget" value="<?= htmlspecialchars($act_allowance) ?>" oninput="recalcBudget()">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-dark">
                                            <i class="fa-solid fa-ticket text-muted me-2"></i>4. ค่าลงทะเบียน
                                        </td>
                                        <td class="text-end text-muted font-monospace" id="cell_est_regis" data-val="<?= $est_regis ?>">
                                            <?= number_format($est_regis, 2) ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="act_budget_regis" id="act_regis" class="form-control form-control-sm text-end fw-bold border-2 actual-budget" value="<?= htmlspecialchars($act_regis) ?>" oninput="recalcBudget()">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-dark">
                                            <i class="fa-solid fa-ellipsis text-muted me-2"></i>5. ค่าใช้จ่ายอื่นๆ
                                        </td>
                                        <td class="text-end text-muted font-monospace" id="cell_est_other" data-val="<?= $est_other ?>">
                                            <?= number_format($est_other, 2) ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="act_budget_other" id="act_other" class="form-control form-control-sm text-end fw-bold border-2 actual-budget" value="<?= htmlspecialchars($act_other) ?>" oninput="recalcBudget()">
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="align-middle">
                                        <th class="fw-bold text-dark">
                                            รวมทั้งสิ้น (Total Budget Used)
                                        </th>
                                        <th class="text-end fw-bold text-muted font-monospace">
                                            ฿<?= number_format($est_total, 2) ?>
                                        </th>
                                        <th class="text-end">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text bg-white fw-bold">฿</span>
                                                <input type="number" step="0.01" min="0" name="budget_used" id="total_actual_budget" class="form-control form-control-sm text-end fw-bold text-success border-2" value="<?= htmlspecialchars($act_total) ?>" readonly>
                                            </div>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Budget Variance Callout -->
                        <div id="budget_variance_alert" class="p-2 px-3 rounded-3 mt-2 d-flex justify-content-between align-items-center small">
                            <span id="variance_text">กำลังคำนวณการเปรียบเทียบงบประมาณ...</span>
                            <span id="variance_badge" class="badge rounded-pill"></span>
                        </div>
                    </div>
                </div>

                <!-- ส่วนที่ 5: จำนวนชั่วโมงอบรม & เอกสารหลักฐาน/เกียรติบัตร -->
                <div class="card border-0 shadow-xs rounded-4 mb-4 bg-white">
                    <div class="card-header bg-white border-bottom border-light-subtle pt-3 px-4 pb-2">
                        <h6 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-file-circle-check text-success me-2"></i>5. จำนวนชั่วโมงอบรม & เอกสารหลักฐาน
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <!-- Training Hours -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-dark">
                                    จำนวนชั่วโมงอบรมจริง <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="training_hours" id="training_hours_input" class="form-control border-2 text-center fw-bold text-purple" value="<?= htmlspecialchars($training_hours_val) ?>" min="1" required>
                                    <span class="input-group-text bg-white fw-bold">ชม.</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted" style="font-size: 0.72rem;">จาก <?= $default_days ?> วัน x 6 ชม.</small>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-purple text-decoration-none" style="font-size: 0.72rem;" onclick="resetHours(<?= $default_hours ?>)">
                                        <i class="fa-solid fa-rotate-left"></i> ตั้งค่าเดิม
                                    </button>
                                </div>
                            </div>

                            <!-- Certificate File Upload -->
                            <div class="col-12 mt-3">
                                <label class="form-label fw-bold small text-dark">
                                    <i class="fa-solid fa-award text-warning me-1"></i> ไฟล์แนบเกียรติบัตร / วุฒิบัตร / ภาพถ่ายกิจกรรม / เอกสารประกอบการอบรม
                                </label>
                                <input type="file" name="certificate_file" class="form-control border-2" accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.zip">
                                <div class="form-text extra-small text-muted" style="font-size: 0.75rem;">
                                    รองรับไฟล์ประเภท PDF, JPG, PNG, DOCX หรือ ZIP (ขนาดไม่เกิน 20MB)
                                </div>

                                <?php if (!empty($report['certificate_file'])): ?>
                                    <div class="p-2 bg-light rounded-3 border mt-2 d-flex justify-content-between align-items-center">
                                        <div class="small text-truncate">
                                            <i class="fa-solid fa-paperclip text-primary me-2"></i>
                                            <span class="text-muted">ไฟล์ที่แนบไว้ปัจจุบัน:</span>
                                            <strong><?= htmlspecialchars($report['certificate_file']) ?></strong>
                                        </div>
                                        <a href="../../uploads/trainings/<?= htmlspecialchars($report['certificate_file']) ?>" target="_blank" class="btn btn-xs btn-outline-primary rounded-pill px-3">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดดูไฟล์
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Action Buttons -->
                <div class="card border-0 shadow-xs rounded-4 bg-white p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 py-2">
                            <i class="fa-solid fa-xmark me-1"></i> ยกเลิก / กลับ
                        </a>
                        <button type="submit" class="btn btn-success rounded-pill px-5 py-2 fw-bold shadow-sm hover-lift" id="submitBtn">
                            <i class="fa-solid fa-check-circle me-2"></i> บันทึกรายงานผลและซิงค์ข้อมูล
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </form>
</main>

<style>
.text-purple { color: #59369a !important; }
.bg-purple { background-color: #59369a !important; }
.bg-purple-subtle { background-color: #f3e8ff !important; }
.border-purple { border-color: #d8b4fe !important; }
.btn-outline-purple { color: #59369a; border-color: #59369a; }
.btn-outline-purple:hover { background-color: #59369a; color: #fff; }
.shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

/* Interactive Evaluation Cards */
.eval-card {
    transition: all 0.2s ease-in-out;
}
.eval-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
}
.eval-card.selected {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.hover-lift {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(25, 135, 84, 0.25) !important;
}
.btn-xs {
    padding: 0.25rem 0.6rem;
    font-size: 0.78rem;
}
</style>

<script>
// Recalculate Actual Budget Total and Variance
function recalcBudget() {
    const travel = parseFloat(document.getElementById('act_travel').value || 0);
    const accom = parseFloat(document.getElementById('act_accom').value || 0);
    const allowance = parseFloat(document.getElementById('act_allowance').value || 0);
    const regis = parseFloat(document.getElementById('act_regis').value || 0);
    const other = parseFloat(document.getElementById('act_other').value || 0);

    const totalAct = travel + accom + allowance + regis + other;
    document.getElementById('total_actual_budget').value = totalAct.toFixed(2);

    const estTotal = <?= json_encode($est_total) ?>;
    const diff = estTotal - totalAct;

    const alertBox = document.getElementById('budget_variance_alert');
    const varText = document.getElementById('variance_text');
    const varBadge = document.getElementById('variance_badge');

    if (Math.abs(diff) < 0.01) {
        alertBox.className = 'p-2 px-3 rounded-3 mt-2 d-flex justify-content-between align-items-center small bg-primary-subtle border border-primary text-primary-emphasis';
        varText.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> จ่ายจริงตรงกับยอดประมาณการที่ขออนุมัติไว้พอดี';
        varBadge.className = 'badge bg-primary text-white rounded-pill';
        varBadge.innerText = 'พอดีงบ ฿' + estTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
    } else if (diff > 0) {
        alertBox.className = 'p-2 px-3 rounded-3 mt-2 d-flex justify-content-between align-items-center small bg-success-subtle border border-success text-success-emphasis';
        varText.innerHTML = '<i class="fa-solid fa-piggy-bank me-1"></i> ใช้จ่ายประหยัดกว่าประมาณการเดิม';
        varBadge.className = 'badge bg-success text-white rounded-pill';
        varBadge.innerText = 'ประหยัดงบได้ ฿' + diff.toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        alertBox.className = 'p-2 px-3 rounded-3 mt-2 d-flex justify-content-between align-items-center small bg-warning-subtle border border-warning text-warning-emphasis';
        varText.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ใช้จ่ายเกินกว่างบประมาณที่ขออนุมัติไว้';
        varBadge.className = 'badge bg-warning text-dark rounded-pill';
        varBadge.innerText = 'เกินงบ ฿' + Math.abs(diff).toLocaleString('en-US', {minimumFractionDigits: 2});
    }
}

// Copy Estimated to Actual Budget
function copyEstToAct() {
    document.getElementById('act_travel').value = parseFloat(document.getElementById('cell_est_travel').getAttribute('data-val') || 0);
    document.getElementById('act_accom').value = parseFloat(document.getElementById('cell_est_accom').getAttribute('data-val') || 0);
    document.getElementById('act_allowance').value = parseFloat(document.getElementById('cell_est_allowance').getAttribute('data-val') || 0);
    document.getElementById('act_regis').value = parseFloat(document.getElementById('cell_est_regis').getAttribute('data-val') || 0);
    document.getElementById('act_other').value = parseFloat(document.getElementById('cell_est_other').getAttribute('data-val') || 0);
    recalcBudget();
}

function resetHours(defaultHours) {
    document.getElementById('training_hours_input').value = defaultHours;
}

// Evaluation radio buttons styling
document.addEventListener('DOMContentLoaded', function() {
    recalcBudget();

    const evalCards = document.querySelectorAll('.eval-card');
    evalCards.forEach(card => {
        card.addEventListener('click', function() {
            evalCards.forEach(c => {
                c.classList.remove('selected', 'border-success', 'border-primary', 'border-danger', 'bg-success-subtle', 'bg-primary-subtle', 'bg-danger-subtle');
                c.classList.add('bg-light');
            });
            const radio = this.querySelector('input[type="radio"]');
            radio.checked = true;
            this.classList.remove('bg-light');
            this.classList.add('selected');
            if (radio.value === 'ดีมาก') {
                this.classList.add('border-success', 'bg-success-subtle');
            } else if (radio.value === 'ดี') {
                this.classList.add('border-primary', 'bg-primary-subtle');
            } else {
                this.classList.add('border-danger', 'bg-danger-subtle');
            }
        });
    });

    // Form submit state
    const form = document.getElementById('reportForm');
    form.addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> กำลังบันทึกข้อมูล...';
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
