<?php
require_once 'auth_check.php';

// ดึงปีงบประมาณที่ Active ปัจจุบัน
$stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy = $stmt_fy->fetchColumn() ?: (date('Y') + 543);

$edit_id = intval($_GET['id'] ?? 0);
$is_edit = ($edit_id > 0);
$report = null;

if ($is_edit) {
    $stmt_rep = $pdo->prepare("SELECT * FROM training_reports WHERE report_id = ? AND (user_id = ? OR ?)");
    $is_admin = hasRole(['admin']);
    $stmt_rep->execute([$edit_id, $user_id, $is_admin ? 1 : 0]);
    $report = $stmt_rep->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        header("Location: seminars.php");
        exit();
    }
}

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if ($is_edit) {
            $stmt = $pdo->prepare("
                UPDATE training_reports SET
                    project_name = ?, organizer = ?, location = ?,
                    start_date = ?, end_date = ?, budget_used = ?,
                    summary = ?, evaluation = ?, apply_how = ?,
                    apply_expected_result = ?, apply_completion_date = ?
                WHERE report_id = ? AND (user_id = ? OR ?)
            ");
            $is_admin = hasRole(['admin']);
            $stmt->execute([
                $_POST['project_name'],
                $_POST['organizer'],
                $_POST['location'],
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['budget_used'] ?: 0,
                $_POST['summary'],
                $_POST['evaluation'],
                $_POST['apply_how'],
                $_POST['apply_expected_result'],
                !empty($_POST['apply_completion_date']) ? $_POST['apply_completion_date'] : null,
                $edit_id,
                $user_id,
                $is_admin ? 1 : 0
            ]);
            $msg = "แก้ไขรายงานผลการไปราชการ/อบรม เรียบร้อยแล้ว";
            $msg_type = "success";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO training_reports (
                    user_id, project_name, organizer, location, 
                    start_date, end_date, budget_used, fiscal_year, status,
                    summary, evaluation, apply_how, apply_expected_result, apply_completion_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reported', ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id,
                $_POST['project_name'],
                $_POST['organizer'],
                $_POST['location'],
                $_POST['start_date'],
                $_POST['end_date'],
                $_POST['budget_used'] ?: 0,
                $active_fy,
                $_POST['summary'],
                $_POST['evaluation'],
                $_POST['apply_how'],
                $_POST['apply_expected_result'],
                !empty($_POST['apply_completion_date']) ? $_POST['apply_completion_date'] : null
            ]);
            
            $msg = "บันทึกรายงานผลการไปราชการ/อบรม เรียบร้อยแล้ว";
            $msg_type = "success";
        }
        
        echo "<script>setTimeout(()=>window.location.href='seminars.php', 1200);</script>";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

require_once 'includes/header.php';
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="seminars.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้ารายงานสัมมนา">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark"><?= $is_edit ? 'แก้ไขรายงานอบรม' : 'บันทึกรายงานใหม่' ?></h5>
            <small class="text-muted">รายงานผลการไปราชการ พัฒนาตนเอง หรืออบรม</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid <?= $is_edit ? 'fa-pen-to-square' : 'fa-pen-nib' ?>"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="seminarForm">
        
        <!-- ส่วนที่ 1: ข้อมูลโครงการและการเดินทาง -->
        <div class="bento-box p-4 mb-3">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-info text-purple me-2"></i>ส่วนที่ 1: ข้อมูลโครงการ</h6>
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">ชื่อหลักสูตร / โครงการ <span class="text-danger">*</span></label>
                <input type="text" name="project_name" class="form-control form-control-sm" required placeholder="ระบุชื่อโครงการ" value="<?= htmlspecialchars($report['project_name'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">หน่วยงานที่จัด <span class="text-danger">*</span></label>
                <input type="text" name="organizer" class="form-control form-control-sm" required placeholder="เช่น มหาวิทยาลัย..." value="<?= htmlspecialchars($report['organizer'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">สถานที่จัด <span class="text-danger">*</span></label>
                <input type="text" name="location" class="form-control form-control-sm" required placeholder="เช่น โรงแรม..., จังหวัด..." value="<?= htmlspecialchars($report['location'] ?? '') ?>">
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">วันที่เริ่ม <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" class="form-control form-control-sm" required value="<?= htmlspecialchars($report['start_date'] ?? '') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">ถึงวันที่ <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" class="form-control form-control-sm" required value="<?= htmlspecialchars($report['end_date'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label small text-muted mb-1 fw-bold">งบประมาณที่ใช้ (บาท)</label>
                <input type="number" step="0.01" name="budget_used" class="form-control fw-bold text-purple" value="<?= htmlspecialchars($report['budget_used'] ?? '0') ?>">
                <small class="text-muted extra-small mt-1 d-block">* หากไม่ใช้งบประมาณของคณะ ให้ระบุ 0</small>
            </div>
        </div>

        <!-- ส่วนที่ 2: สรุปผลการประชุม -->
        <div class="bento-box p-4 mb-3">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-pen-nib text-purple me-2"></i>ส่วนที่ 2: สรุปและประเมินผล</h6>
            
            <div class="mb-4">
                <label class="form-label small text-muted mb-1 fw-bold">เนื้อหาสาระและสรุปผล <span class="text-danger">*</span></label>
                <textarea name="summary" class="form-control form-control-sm" rows="4" required placeholder="อธิบายเนื้อหาหรือความรู้ที่ได้รับ..."><?= htmlspecialchars($report['summary'] ?? '') ?></textarea>
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold text-sm d-block mb-3">ประเมินผลภาพรวมของหลักสูตร <span class="text-danger">*</span></label>
                <?php $current_eval = $report['evaluation'] ?? 'ดีมาก'; ?>
                <div class="d-flex flex-column gap-2">
                    <div class="form-check custom-radio border p-2 rounded-3 bg-light eval-excellent">
                        <input class="form-check-input" type="radio" name="evaluation" id="eval_1" value="ดีมาก" <?= $current_eval === 'ดีมาก' ? 'checked' : '' ?> required>
                        <label class="form-check-label fw-bold" for="eval_1"><i class="fa-solid fa-face-grin-stars me-2 text-success fs-5"></i>ดีมาก</label>
                    </div>
                    <div class="form-check custom-radio border p-2 rounded-3 bg-light eval-good">
                        <input class="form-check-input" type="radio" name="evaluation" id="eval_2" value="ดี" <?= $current_eval === 'ดี' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="eval_2"><i class="fa-solid fa-face-smile me-2 text-primary fs-5"></i>ดี</label>
                    </div>
                    <div class="form-check custom-radio border p-2 rounded-3 bg-light eval-bad">
                        <input class="form-check-input" type="radio" name="evaluation" id="eval_3" value="ไม่ดี" <?= $current_eval === 'ไม่ดี' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="eval_3"><i class="fa-solid fa-face-frown me-2 text-danger fs-5"></i>ไม่ดี</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ส่วนที่ 3: แผนการนำไปใช้ -->
        <div class="bento-box p-4 mb-4">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-lightbulb text-purple me-2"></i>ส่วนที่ 3: การนำไปใช้ประโยชน์</h6>
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">แนวทางการประยุกต์ใช้ <span class="text-danger">*</span></label>
                <textarea name="apply_how" class="form-control form-control-sm" rows="3" required placeholder="อธิบายว่าจะนำความรู้ที่ได้ไปใช้ทำงานหรือต่อยอดอย่างไร..."><?= htmlspecialchars($report['apply_how'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">ผลที่คาดว่าจะได้รับ <span class="text-danger">*</span></label>
                <input type="text" name="apply_expected_result" class="form-control form-control-sm" required placeholder="ผลลัพธ์ที่เป็นรูปธรรม" value="<?= htmlspecialchars($report['apply_expected_result'] ?? '') ?>">
            </div>

            <div class="mb-2">
                <label class="form-label small text-muted mb-1 fw-bold">กำหนดเสร็จสิ้น (ถ้ามี)</label>
                <input type="date" name="apply_completion_date" class="form-control form-control-sm" value="<?= htmlspecialchars($report['apply_completion_date'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid <?= $is_edit ? 'fa-floppy-disk' : 'fa-paper-plane' ?> me-2"></i> <?= $is_edit ? 'บันทึกการแก้ไข' : 'บันทึกรายงานผล' ?>
        </button>
    </form>

    <div style="height: 60px;"></div>
</div>

<style>
.custom-radio { cursor: pointer; transition: 0.2s; }
.custom-radio:hover { background-color: var(--slate-100) !important; }
.form-check-input { display: none; }

.eval-excellent:has(input:checked) { background-color: #d1e7dd !important; border-color: #198754 !important; }
.eval-good:has(input:checked) { background-color: var(--primary-light) !important; border-color: var(--primary) !important; }
.eval-bad:has(input:checked) { background-color: #f8d7da !important; border-color: #dc3545 !important; }
</style>

<?php require_once 'includes/footer.php'; ?>
