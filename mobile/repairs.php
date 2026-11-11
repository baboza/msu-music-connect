<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงประวัติการแจ้งซ่อมของตนเอง
$sql = "SELECT rr.*, r.room_name 
        FROM repair_requests rr
        LEFT JOIN rooms r ON rr.room_id = r.room_id
        WHERE rr.reporter_id = ?
        ORDER BY rr.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$repairs = $stmt->fetchAll();

function getRepairStatusBadge($status) {
    if($status == 'pending') return ['label' => 'รอรับเรื่อง', 'class' => 'warning', 'icon' => 'fa-clock'];
    if($status == 'in_progress') return ['label' => 'กำลังซ่อม', 'class' => 'primary', 'icon' => 'fa-screwdriver-wrench'];
    if($status == 'completed') return ['label' => 'เสร็จสิ้น', 'class' => 'success', 'icon' => 'fa-check-double'];
    if($status == 'cancelled' || $status == 'canceled') return ['label' => 'ยกเลิก', 'class' => 'secondary', 'icon' => 'fa-ban'];
    return ['label' => 'ไม่ทราบสถานะ', 'class' => 'secondary', 'icon' => 'fa-question'];
}

function getDamageLevelBadge($level) {
    if($level == 'critical') return '<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> วิกฤต</span>';
    if($level == 'medium') return '<span class="badge bg-warning-subtle text-warning-emphasis border px-2 py-1">ปานกลาง</span>';
    return '<span class="badge bg-slate-100 text-slate-600 border px-2 py-1">เล็กน้อย</span>';
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ระบบแจ้งซ่อมบำรุง</h5>
            <small class="text-muted">ประวัติการแจ้งซ่อมอุปกรณ์และอาคารสถานที่</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-screwdriver-wrench"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="repairs_form.php" class="mobile-fab" title="แจ้งซ่อมใหม่" hx-boost="true">
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
            foreach($repairs as $r):
                if($r['status'] == 'pending' || $r['status'] == 'in_progress'):
                    $has_pending = true;
                    $status = getRepairStatusBadge($r['status']);
            ?>
                <div class="bento-box mb-3 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div><?= getDamageLevelBadge($r['damage_level']) ?></div>
                        <span class="badge bg-<?= $status['class'] ?>-subtle text-<?= $status['class'] ?> border px-2 py-1">
                            <i class="fa-solid <?= $status['icon'] ?> me-1"></i><?= $status['label'] ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark fs-5 mb-1"><?= htmlspecialchars($r['equipment_name']) ?></div>
                    <div class="text-dark small mb-1"><i class="fa-solid fa-location-dot text-danger me-2"></i><?= htmlspecialchars($r['room_name'] ?? 'ไม่ระบุห้อง') ?></div>
                    <div class="small text-muted border-top pt-2 mt-2"><i class="fa-solid fa-circle-info me-2 opacity-50"></i><?= htmlspecialchars($r['problem_desc']) ?></div>
                    <div class="text-muted extra-small mt-2 text-end">แจ้งเมื่อ <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></div>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_pending): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-solid fa-toolbox text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีรายการแจ้งซ่อมที่กำลังดำเนินการ</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Tab -->
        <div class="tab-pane fade" id="pills-history" role="tabpanel">
            <?php 
            $has_history = false;
            foreach($repairs as $r):
                if($r['status'] == 'completed' || $r['status'] == 'cancelled' || $r['status'] == 'canceled'):
                    $has_history = true;
                    $status = getRepairStatusBadge($r['status']);
            ?>
                <div class="bento-box mb-3 p-3 opacity-85">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="fw-bold text-dark fs-6 text-truncate"><?= htmlspecialchars($r['equipment_name']) ?></span>
                        <span class="badge bg-<?= $status['class'] ?>-subtle text-<?= $status['class'] ?> border px-2 py-1" style="white-space: nowrap;">
                            <i class="fa-solid <?= $status['icon'] ?> me-1"></i><?= $status['label'] ?>
                        </span>
                    </div>
                    <div class="text-dark small mb-1"><i class="fa-solid fa-location-dot text-muted me-2"></i><?= htmlspecialchars($r['room_name'] ?? 'ไม่ระบุห้อง') ?></div>
                    <div class="text-muted extra-small mt-1">วันที่แจ้ง: <?= date('d/m/Y', strtotime($r['created_at'])) ?></div>
                    <?php if($r['status'] == 'completed' && !empty($r['repair_summary'])): ?>
                        <div class="small text-success border-top pt-2 mt-2"><i class="fa-solid fa-check me-2"></i><?= htmlspecialchars($r['repair_summary']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_history): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-solid fa-clock-rotate-left text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีประวัติการแจ้งซ่อม</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
