<?php
require_once 'auth_check.php';

$filter_month = $_GET['month'] ?? date('Y-m');

// บันทึกหมายเหตุการมาสาย
$msg = '';
$msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_remark') {
    $log_id = intval($_POST['log_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    
    try {
        $stmt_check = $pdo->prepare("SELECT id FROM attendance_logs WHERE id = ? AND user_id = ? AND scan_type = 'in'");
        $stmt_check->execute([$log_id, $user_id]);
        if ($stmt_check->fetch()) {
            $stmt_update = $pdo->prepare("UPDATE attendance_logs SET remark = ? WHERE id = ?");
            $stmt_update->execute([$remark, $log_id]);
            $msg = "บันทึกหมายเหตุชี้แจงเรียบร้อยแล้ว";
            $msg_type = "success";
        } else {
            $msg = "ไม่พบรายการที่ต้องการบันทึก";
            $msg_type = "danger";
        }
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงประวัติการลงเวลาในเดือนที่เลือก
try {
    $stmt_logs = $pdo->prepare("
        SELECT * FROM attendance_logs 
        WHERE user_id = ? AND DATE_FORMAT(scan_time, '%Y-%m') = ? 
        ORDER BY scan_time DESC
    ");
    $stmt_logs->execute([$user_id, $filter_month]);
    $raw_logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

    // จัดกลุ่มตามวันที่
    $grouped_logs = [];
    foreach ($raw_logs as $log) {
        $day = date('Y-m-d', strtotime($log['scan_time']));
        if (!isset($grouped_logs[$day])) {
            $grouped_logs[$day] = ['in' => null, 'out' => null, 'all' => []];
        }
        $grouped_logs[$day]['all'][] = $log;
        if ($log['scan_type'] === 'in' && !$grouped_logs[$day]['in']) {
            $grouped_logs[$day]['in'] = $log;
        } elseif ($log['scan_type'] === 'out' && !$grouped_logs[$day]['out']) {
            $grouped_logs[$day]['out'] = $log;
        }
    }

    // คำนวณสรุปรายเดือน
    $total_days = count($grouped_logs);
    $on_time_days = 0;
    $late_days = 0;
    foreach ($grouped_logs as $day => $dinfo) {
        if ($dinfo['in']) {
            $in_time = date('H:i:s', strtotime($dinfo['in']['scan_time']));
            if ($in_time > '09:00:00') {
                $late_days++;
            } else {
                $on_time_days++;
            }
        }
    }

} catch (PDOException $e) {
    $raw_logs = [];
    $grouped_logs = [];
    $total_days = 0;
    $on_time_days = 0;
    $late_days = 0;
}

require_once 'includes/header.php';
?>

<div class="page-container pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3 pt-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">ประวัติการลงเวลา</h5>
                <small class="text-muted">บันทึกเวลาเข้า-ออกงานประจำวัน</small>
            </div>
        </div>
        <!-- Month Filter Selector -->
        <form method="GET" action="attendance_history.php" class="d-inline">
            <input type="month" name="month" class="form-control form-control-sm rounded-pill fw-bold border-purple text-purple px-2 py-1 extra-small" value="<?= htmlspecialchars($filter_month) ?>" onchange="this.form.submit()">
        </form>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Monthly Summary Bento Grid -->
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="bento-box p-3 text-center">
                <span class="extra-small text-muted d-block mb-1">เข้างานทั้งหมด</span>
                <h4 class="fw-bold text-dark mb-0"><?= $total_days ?></h4>
                <small class="extra-small text-muted">วัน</small>
            </div>
        </div>
        <div class="col-4">
            <div class="bento-box p-3 text-center">
                <span class="extra-small text-success d-block mb-1">ตรงเวลา</span>
                <h4 class="fw-bold text-success mb-0"><?= $on_time_days ?></h4>
                <small class="extra-small text-muted">วัน</small>
            </div>
        </div>
        <div class="col-4">
            <div class="bento-box p-3 text-center">
                <span class="extra-small text-danger d-block mb-1">มาสาย (>09:00)</span>
                <h4 class="fw-bold text-danger mb-0"><?= $late_days ?></h4>
                <small class="extra-small text-muted">วัน</small>
            </div>
        </div>
    </div>

    <!-- Kiosk Notice Box -->
    <div class="p-3 rounded-4 mb-3 d-flex align-items-center gap-3" style="background: rgba(57, 4, 143, 0.05); border: 1px dashed rgba(57, 4, 143, 0.25);">
        <div class="avatar-circle" style="width: 42px; height: 42px; min-width: 42px; background: rgba(57, 4, 143, 0.12); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
            <i class="fa-solid fa-desktop"></i>
        </div>
        <div class="extra-small text-secondary">
            <strong class="text-purple">จุดลงเวลาปฏิบัติงานส่วนกลาง:</strong> บันทึกเวลาเข้า-ออกงานผ่านระบบสแกน ณ จุดคอมพิวเตอร์ส่วนกลางของคณะ
        </div>
    </div>

    <!-- Daily Attendance List -->
    <h6 class="fw-bold text-dark mb-2 px-1">
        <i class="fa-solid fa-calendar-days text-purple me-2"></i>บันทึกประจำวัน (<?= date('F Y', strtotime($filter_month . '-01')) ?>)
    </h6>

    <?php if (empty($grouped_logs)): ?>
        <div class="bento-box text-center py-5">
            <div class="avatar-circle mx-auto mb-3" style="width: 60px; height: 60px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-regular fa-clock fa-2x"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">ไม่พบข้อมูลการลงเวลา</h6>
            <p class="text-muted small mb-0">ไม่มีประวัติการสแกนในเดือนที่เลือก</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
            <?php 
            $thai_days = ['Sunday' => 'อาทิตย์', 'Monday' => 'จันทร์', 'Tuesday' => 'อังคาร', 'Wednesday' => 'พุธ', 'Thursday' => 'พฤหัสบดี', 'Friday' => 'ศุกร์', 'Saturday' => 'เสาร์'];
            foreach ($grouped_logs as $day => $info): 
                $dt = strtotime($day);
                $day_name = $thai_days[date('l', $dt)] ?? date('l', $dt);
                $in_log = $info['in'];
                $out_log = $info['out'];
                
                $is_late = false;
                if ($in_log) {
                    $is_late = (date('H:i:s', strtotime($in_log['scan_time'])) > '09:00:00');
                }
            ?>
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small fw-bold px-2 py-1">
                                วัน<?= $day_name ?>
                            </span>
                            <span class="fw-bold small text-dark"><?= date('d/m/Y', $dt) ?></span>
                        </div>
                        <?php if ($in_log): ?>
                            <?php if ($is_late): ?>
                                <span class="badge bg-danger-subtle text-danger extra-small px-2 py-1">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> มาสาย
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success extra-small px-2 py-1">
                                    <i class="fa-solid fa-circle-check me-1"></i> ตรงเวลา
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary extra-small px-2 py-1">ยังไม่ลงเวลา</span>
                        <?php endif; ?>
                    </div>

                    <div class="row g-2 align-items-center">
                        <!-- เข้างาน (IN) -->
                        <div class="col-6">
                            <div class="p-2 rounded-3 bg-light border">
                                <div class="d-flex align-items-center gap-1 extra-small text-muted mb-1">
                                    <i class="fa-solid fa-arrow-right-to-bracket text-success"></i> เข้างาน
                                </div>
                                <div class="fw-bold fs-6 text-dark">
                                    <?= $in_log ? date('H:i:s', strtotime($in_log['scan_time'])) : '<span class="text-muted fs-6">--:--:--</span>' ?>
                                </div>
                            </div>
                        </div>

                        <!-- ออกงาน (OUT) -->
                        <div class="col-6">
                            <div class="p-2 rounded-3 bg-light border">
                                <div class="d-flex align-items-center gap-1 extra-small text-muted mb-1">
                                    <i class="fa-solid fa-arrow-right-from-bracket text-warning"></i> ออกงาน
                                </div>
                                <div class="fw-bold fs-6 text-dark">
                                    <?= $out_log ? date('H:i:s', strtotime($out_log['scan_time'])) : '<span class="text-muted fs-6">--:--:--</span>' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- หมายเหตุชี้แจง (ถ้ามาสาย) -->
                    <?php if ($is_late && $in_log): ?>
                        <div class="mt-2 pt-2 border-top">
                            <?php if (!empty($in_log['remark'])): ?>
                                <div class="p-2 rounded bg-light extra-small text-secondary">
                                    <strong class="text-dark"><i class="fa-solid fa-comment-dots text-purple me-1"></i> หมายเหตุชี้แจง:</strong> 
                                    <?= htmlspecialchars($in_log['remark']) ?>
                                </div>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-warning w-100 rounded-pill extra-small fw-bold py-1" type="button" data-bs-toggle="collapse" data-bs-target="#remarkBox_<?= $in_log['id'] ?>">
                                    <i class="fa-solid fa-pen me-1"></i> บันทึกเหตุผลการมาสาย
                                </button>
                                <div class="collapse mt-2" id="remarkBox_<?= $in_log['id'] ?>">
                                    <form method="POST" action="attendance_history.php?month=<?= htmlspecialchars($filter_month) ?>">
                                        <input type="hidden" name="action" value="add_remark">
                                        <input type="hidden" name="log_id" value="<?= $in_log['id'] ?>">
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="remark" class="form-control rounded-start-pill" placeholder="ระบุเหตุผล เช่น ฝนตกหนัก, ติดภารกิจด่วน" required>
                                            <button type="submit" class="btn btn-warning rounded-end-pill fw-bold">บันทึก</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
