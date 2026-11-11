<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงรายการการเงินเฉพาะของตัวเอง (View Only)
$sql = "SELECT * FROM finance_requests 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 30";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// คำนวณยอดรวมที่รอเบิก
$total_pending_amount = 0;
foreach($requests as $r) {
    if($r['current_status'] != 'paid') {
        $total_pending_amount += floatval($r['amount']);
    }
}

function getStatusDetails($status) {
    $configs = [
        'received'  => ['label' => 'อยู่คณะ', 'class' => 'secondary', 'icon' => 'fa-inbox'],
        'verified'  => ['label' => 'ตรวจสอบแล้ว', 'class' => 'primary', 'icon' => 'fa-check'],
        'forwarded' => ['label' => 'ส่งมหาลัย', 'class' => 'warning', 'icon' => 'fa-paper-plane'],
        'approved'  => ['label' => 'มหาลัยอนุมัติ', 'class' => 'info', 'icon' => 'fa-thumbs-up'],
        'paid'      => ['label' => 'จ่ายเงินแล้ว', 'class' => 'success', 'icon' => 'fa-check-double']
    ];
    return $configs[$status] ?? ['label' => 'ไม่ทราบสถานะ', 'class' => 'secondary', 'icon' => 'fa-question'];
}

function getTypeDetails($type) {
    $configs = [
        'advance' => 'เงินทดรองจ่าย',
        'reimburse' => 'ขอคืนเงิน (ใบเสร็จ)',
        'project' => 'โครงการ/กิจกรรม',
        'other' => 'อื่นๆ'
    ];
    return $configs[$type] ?? $type;
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">รายการการเงิน</h5>
            <small class="text-muted">ตรวจสอบสถานะการเบิกจ่ายและเงินสวัสดิการ</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-receipt"></i>
        </div>
    </div>

    <!-- Summary Box (Executive Purple & Gold Theme) -->
    <div class="bento-box bento-hero text-white position-relative mb-4 overflow-hidden" style="background: var(--primary-gradient) !important; border: 1px solid rgba(255, 255, 255, 0.15) !important;">
        <div class="position-relative z-1">
            <p class="mb-1 text-white-50 extra-small fw-semibold">ยอดรวมที่อยู่ระหว่างเบิกจ่าย</p>
            <h2 class="fw-bold mb-0 text-white">฿<?= number_format($total_pending_amount, 2) ?></h2>
        </div>
        <i class="fa-solid fa-sack-dollar position-absolute text-white opacity-10" style="font-size: 80px; right: -10px; bottom: -10px;"></i>
    </div>

    <!-- Requests List -->
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <h6 class="fw-bold text-dark mb-0">ประวัติคำร้องของคุณ</h6>
        <span class="text-muted extra-small">โหมดดูอย่างเดียว (View Only)</span>
    </div>
    
    <?php if (empty($requests)): ?>
        <div class="bento-box p-5 text-center">
            <i class="fa-regular fa-folder-open text-muted mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
            <h6 class="fw-bold text-dark mb-1">ยังไม่มีรายการ</h6>
            <p class="text-muted small mb-0">คุณยังไม่มีรายการขอเบิกเงินในระบบ</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3 mb-5 pb-4">
            <?php foreach ($requests as $r): 
                $st = getStatusDetails($r['current_status']);
                $type_name = getTypeDetails($r['req_type']);
            ?>
            <div class="bento-box p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="badge bg-<?= $st['class'] ?>-subtle text-<?= $st['class'] ?> rounded-pill px-2 py-1 mb-2">
                            <i class="fa-solid <?= $st['icon'] ?> me-1"></i><?= $st['label'] ?>
                        </span>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;">
                            <?= htmlspecialchars($r['title']) ?>
                        </h6>
                        <div class="text-muted extra-small">
                            <i class="fa-regular fa-calendar me-1"></i> <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                        </div>
                    </div>
                    <div class="text-end ps-2">
                        <div class="fw-bold text-purple fs-5">฿<?= number_format($r['amount'], 2) ?></div>
                        <div class="text-muted extra-small"><?= htmlspecialchars($type_name) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
