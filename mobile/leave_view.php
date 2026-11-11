<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location.href='leave.php';</script>";
    exit();
}

$leave_id = $_GET['id'];

try {
    $sql = "SELECT l.*, t.type_name, u.first_name, u.last_name, u.position, d.dept_name
            FROM leave_requests l
            JOIN leave_types t ON l.type_id = t.type_id
            JOIN users u ON l.user_id = u.user_id
            LEFT JOIN departments d ON u.dept_id = d.dept_id
            WHERE l.leave_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leave_id]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) { 
        echo "<script>alert('ไม่พบข้อมูลใบลา'); window.location.href='leave.php';</script>";
        exit(); 
    }

    $stmt_appr = $pdo->prepare("SELECT a.*, u.first_name, u.last_name, u.position 
                                FROM leave_approvals a 
                                JOIN users u ON a.approver_id = u.user_id 
                                WHERE a.leave_id = ? ORDER BY a.step_order ASC");
    $stmt_appr->execute([$leave_id]);
    $approvals = $stmt_appr->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

function getStepDetails($step, $status) {
    if ($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'danger', 'icon' => 'fa-circle-xmark'];
    if ($status == 'approved') return ['label' => 'อนุมัติแล้ว', 'class' => 'success', 'icon' => 'fa-circle-check'];
    if ($status == 'canceled' || $status == 'cancelled') return ['label' => 'ยกเลิก', 'class' => 'secondary', 'icon' => 'fa-ban'];
    
    $steps = [1 => 'บุคคล', 2 => 'หัวหน้า', 3 => 'รองฯ', 4 => 'คณบดี'];
    return ['label' => 'รอ' . ($steps[$step] ?? 'ดำเนินการ'), 'class' => 'warning', 'icon' => 'fa-clock'];
}

$step_info = getStepDetails($leave['current_step'], $leave['status']);
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="leave.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าประวัติการลา">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">รายละเอียดใบลา</h5>
            <small class="text-muted">คำขอ #<?= str_pad($leave['leave_id'], 4, '0', STR_PAD_LEFT) ?></small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
    </div>

    <!-- สถานะ -->
    <div class="bento-box p-3 mb-3 text-center border-<?= $step_info['class'] ?> border">
        <div class="fs-4 text-<?= $step_info['class'] ?> mb-1"><i class="fa-solid <?= $step_info['icon'] ?>"></i></div>
        <h6 class="fw-bold text-<?= $step_info['class'] ?> mb-0"><?= $step_info['label'] ?></h6>
    </div>

    <!-- ข้อมูลใบลา -->
    <div class="bento-box p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-calendar-minus me-2 text-purple"></i>ข้อมูลการลา</h6>
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1"><?= htmlspecialchars($leave['type_name']) ?></span>
        </div>
        
        <p class="mb-1 fw-bold fs-6 text-dark"><?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']) ?></p>
        <p class="small text-muted mb-3"><?= htmlspecialchars($leave['position'] ?? 'บุคลากร') ?></p>
        
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <div class="fw-bold text-dark mb-1 small">เหตุผลการลา:</div>
            <p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($leave['reason'])) ?></p>
        </div>
        
        <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted"><i class="fa-solid fa-calendar me-1 text-purple"></i><?= date('d/m/Y', strtotime($leave['start_date'])) ?> - <?= date('d/m/Y', strtotime($leave['end_date'])) ?></small>
            <span class="badge bg-slate-100 text-slate-700 border rounded-pill"><?= (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / (60 * 60 * 24) + 1 ?> วัน</span>
        </div>
    </div>

    <!-- ลำดับการอนุมัติ -->
    <h6 class="fw-bold text-dark mb-3 px-2 mt-4"><i class="fa-solid fa-clipboard-check text-purple me-2"></i>ลำดับขั้นตอนการอนุมัติ</h6>
    <div class="bento-box p-4 mb-5">
        <div class="position-relative">
            <!-- เส้น Timeline -->
            <div class="position-absolute bg-light" style="width: 2px; height: 100%; left: 19px; top: 0; z-index: 1;"></div>
            
            <?php 
            $steps_def = [1 => 'งานบุคคล', 2 => 'หัวหน้างาน/ภาควิชา', 3 => 'รองคณบดีฝ่ายบริหาร', 4 => 'คณบดี'];
            for($i = 1; $i <= 4; $i++): 
                $appr = null;
                foreach($approvals as $a) { if($a['step_order'] == $i) { $appr = $a; break; } }
                
                $is_current = ($leave['current_step'] == $i && $leave['status'] == 'pending');
                $is_done = ($appr != null || ($leave['status'] == 'approved' && $i < $leave['current_step']));
                
                $circle_bg = $is_done ? 'bg-success text-white' : ($is_current ? 'bg-warning-subtle text-warning-emphasis border border-warning' : 'bg-white text-muted border');
                $icon = $is_done ? 'fa-check' : ($is_current ? 'fa-hourglass-half' : 'fa-circle');
            ?>
            <div class="d-flex position-relative mb-4" style="z-index: 2;">
                <div class="rounded-circle <?= $circle_bg ?> d-flex align-items-center justify-content-center shadow-sm" style="width:40px; height:40px; flex-shrink: 0;">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <div class="ms-3 pt-2">
                    <h6 class="fw-bold <?= $is_current ? 'text-dark' : 'text-muted' ?> mb-1 fs-6"><?= $steps_def[$i] ?></h6>
                    <?php if($appr): ?>
                        <p class="small text-muted mb-0"><?= htmlspecialchars($appr['first_name'] . ' ' . $appr['last_name']) ?></p>
                        <p class="extra-small text-muted mb-0"><i class="fa-regular fa-clock me-1"></i><?= (!empty($appr['updated_at']) && $appr['updated_at'] !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($appr['updated_at'])) : '-' ?></p>
                    <?php elseif($is_current): ?>
                        <p class="small text-warning-emphasis fw-bold mb-0">กำลังรอการพิจารณา</p>
                    <?php else: ?>
                        <p class="small text-muted mb-0 opacity-50">-</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
