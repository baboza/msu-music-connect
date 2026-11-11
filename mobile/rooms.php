<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงประวัติการจองห้อง
$sql = "SELECT b.*, r.room_code, r.room_name 
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();

function getBookingStatusBadge($status) {
    if($status == 'pending') return ['label' => 'รออนุมัติ', 'class' => 'warning', 'icon' => 'fa-clock'];
    if($status == 'approved') return ['label' => 'อนุมัติแล้ว', 'class' => 'success', 'icon' => 'fa-check-circle'];
    if($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'danger', 'icon' => 'fa-times-circle'];
    if($status == 'cancelled' || $status == 'canceled') return ['label' => 'ยกเลิก', 'class' => 'secondary', 'icon' => 'fa-ban'];
    return ['label' => 'ไม่ทราบสถานะ', 'class' => 'secondary', 'icon' => 'fa-circle-question'];
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ระบบจองห้อง/สถานที่</h5>
            <small class="text-muted">ประวัติการขอใช้ห้องประชุมและสถานที่</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-door-open"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="rooms_form.php" class="mobile-fab" title="จองห้องใหม่" hx-boost="true">
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
            foreach($bookings as $b):
                if($b['status'] == 'pending'):
                    $has_pending = true;
                    $status = getBookingStatusBadge($b['status']);
            ?>
                <div class="bento-box mb-3 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1">
                            <i class="fa-solid fa-hashtag me-1"></i><?= htmlspecialchars($b['room_code']) ?>
                        </span>
                        <span class="badge bg-<?= $status['class'] ?>-subtle text-<?= $status['class'] ?> border px-2 py-1">
                            <i class="fa-solid <?= $status['icon'] ?> me-1"></i><?= $status['label'] ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark fs-5 mb-1"><?= htmlspecialchars($b['room_name']) ?></div>
                    <div class="text-dark small mb-1"><i class="fa-regular fa-calendar text-muted me-2"></i><?= date('d/m/Y', strtotime($b['booking_date'])) ?></div>
                    <div class="text-dark small mb-2"><i class="fa-regular fa-clock text-muted me-2"></i><?= date('H:i', strtotime($b['start_time'])) ?> - <?= date('H:i', strtotime($b['end_time'])) ?> น.</div>
                    
                    <div class="small text-muted border-top pt-2 mt-2"><i class="fa-solid fa-quote-left me-2 opacity-50"></i><?= htmlspecialchars($b['purpose']) ?></div>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_pending): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-regular fa-calendar-check text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีคำขอจองห้องที่กำลังดำเนินการ</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Tab -->
        <div class="tab-pane fade" id="pills-history" role="tabpanel">
            <?php 
            $has_history = false;
            foreach($bookings as $b):
                if($b['status'] != 'pending'):
                    $has_history = true;
                    $status = getBookingStatusBadge($b['status']);
            ?>
                <div class="bento-box mb-3 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($b['room_code']) ?></span>
                        <span class="badge bg-<?= $status['class'] ?>-subtle text-<?= $status['class'] ?> border px-2 py-1">
                            <i class="fa-solid <?= $status['icon'] ?> me-1"></i><?= $status['label'] ?>
                        </span>
                    </div>
                    <div class="fw-bold text-dark fs-5 mb-1"><?= htmlspecialchars($b['room_name']) ?></div>
                    <div class="text-dark small mb-1"><i class="fa-regular fa-calendar text-muted me-2"></i><?= date('d/m/Y', strtotime($b['booking_date'])) ?></div>
                    <div class="text-dark small"><i class="fa-regular fa-clock text-muted me-2"></i><?= date('H:i', strtotime($b['start_time'])) ?> - <?= date('H:i', strtotime($b['end_time'])) ?> น.</div>
                </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$has_history): ?>
                <div class="text-center py-5 bento-box">
                    <i class="fa-solid fa-clock-rotate-left text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
                    <p class="text-muted mb-0">ไม่มีประวัติการจองห้อง</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
