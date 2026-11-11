<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงประวัติการลา
$sql = "SELECT l.*, t.type_name 
        FROM leave_requests l
        JOIN leave_types t ON l.type_id = t.type_id
        WHERE l.user_id = ?
        ORDER BY l.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$leaves = $stmt->fetchAll();

function getStepDetails($step, $status) {
    if ($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'danger', 'icon' => 'fa-circle-xmark'];
    if ($status == 'approved') return ['label' => 'อนุมัติแล้ว', 'class' => 'success', 'icon' => 'fa-circle-check'];
    if ($status == 'canceled' || $status == 'cancelled') return ['label' => 'ยกเลิก', 'class' => 'secondary', 'icon' => 'fa-ban'];
    
    $steps = [
        1 => 'บุคคลตรวจสอบ',
        2 => 'หัวหน้าพิจารณา',
        3 => 'รองฯพิจารณา',
        4 => 'คณบดีพิจารณา',
    ];
    return [
        'label' => 'รอ' . ($steps[$step] ?? 'ดำเนินการ'),
        'class' => 'warning',
        'icon' => 'fa-clock'
    ];
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ระบบการลา</h5>
            <small class="text-muted">ประวัติการยื่นใบลาของคุณ</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-calendar-minus"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="leave_form.php" class="mobile-fab" title="เขียนใบลาใหม่" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    <!-- Nav Tabs -->
    <ul class="nav nav-pills nav-fill mb-3 bg-white p-1 rounded-pill shadow-sm" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill fw-bold" id="pills-pending-tab" data-bs-toggle="pill" data-bs-target="#pills-pending" type="button" role="tab">กำลังดำเนินการ</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button" role="tab">ประวัติย้อนหลัง</button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
        <!-- Pending Tab -->
        <div class="tab-pane fade show active" id="pills-pending" role="tabpanel">
            <?php 
            $has_pending = false;
            foreach($leaves as $l):
                if($l['status'] == 'pending'):
                    $has_pending = true;
                    $step = getStepDetails($l['current_step'], $l['status']);
            ?>
                <div class="bento-box mb-3 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1"><?= htmlspecialchars($l['type_name']) ?></span>
                        <span class="badge bg-<?= $step['class'] ?>-subtle text-<?= $step['class'] ?> border px-2 py-1">
                            <i class="fa-solid <?= $step['icon'] ?> me-1"></i><?= $step['label'] ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark fs-6 mb-1">ลาตั้งแต่วันที่ <?= date('d/m/Y', strtotime($l['start_date'])) ?> ถึง <?= date('d/m/Y', strtotime($l['end_date'])) ?></div>
                    <div class="small text-muted mb-2"><i class="fa-solid fa-note-sticky me-1"></i><?= htmlspecialchars($l['reason']) ?></div>
                    
                    <!-- Progress Bar for Leave -->
                    <div class="progress mt-3" style="height: 6px; background: var(--slate-100); border-radius: 99px;">
                        <div class="progress-bar bg-warning progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= ($l['current_step'] * 25) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1 extra-small text-muted">
                        <span>ส่งเรื่อง</span>
                        <span>บุคคล</span>
                        <span>หัวหน้า</span>
                        <span>คณบดี</span>
                    </div>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_pending): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-regular fa-face-smile text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีใบลาที่อยู่ระหว่างดำเนินการ</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Tab -->
        <div class="tab-pane fade" id="pills-history" role="tabpanel">
            <?php 
            $has_history = false;
            foreach($leaves as $l):
                if($l['status'] != 'pending'):
                    $has_history = true;
                    $step = getStepDetails($l['current_step'], $l['status']);
            ?>
                <div class="bento-box mb-3 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($l['type_name']) ?></span>
                        <span class="badge bg-<?= $step['class'] ?>-subtle text-<?= $step['class'] ?> border px-2 py-1">
                            <i class="fa-solid <?= $step['icon'] ?> me-1"></i><?= $step['label'] ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark fs-6 mb-1">ลาตั้งแต่วันที่ <?= date('d/m/Y', strtotime($l['start_date'])) ?> ถึง <?= date('d/m/Y', strtotime($l['end_date'])) ?></div>
                    <div class="small text-muted"><i class="fa-solid fa-note-sticky me-1"></i><?= htmlspecialchars($l['reason']) ?></div>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_history): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-solid fa-clock-rotate-left text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีประวัติการยื่นใบลา</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
