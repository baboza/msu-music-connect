<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงประวัติการจองห้องซ้อม
$sql = "SELECT pb.*, pr.room_type 
        FROM practice_bookings pb
        LEFT JOIN practice_rooms pr ON pb.room_id = pr.room_id
        WHERE pb.student_id = ?
        ORDER BY pb.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();

function getPracticeBookingBadge($status) {
    if($status == 'approved') return ['label' => 'อนุมัติแล้ว', 'class' => 'success', 'icon' => 'fa-check-circle'];
    if($status == 'active') return ['label' => 'กำลังใช้งาน', 'class' => 'primary', 'icon' => 'fa-play-circle'];
    if($status == 'completed') return ['label' => 'เสร็จสิ้น', 'class' => 'secondary', 'icon' => 'fa-flag-checkered'];
    if($status == 'cancelled' || $status == 'canceled') return ['label' => 'ยกเลิก', 'class' => 'danger', 'icon' => 'fa-ban'];
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
            <h5 class="fw-bold mb-0 text-dark">จองห้องซ้อมดนตรี</h5>
            <small class="text-muted">ประวัติการขอใช้ห้องฝึกซ้อมดนตรี</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-music"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="practice_rooms_form.php" class="mobile-fab" title="จองห้องซ้อมใหม่" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-check-circle me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($bookings)): ?>
        <div class="text-center py-5 bento-box">
            <div class="app-icon-container mx-auto mb-3" style="width: 64px; height: 64px; font-size: 1.8rem;">
                <i class="fa-solid fa-music"></i>
            </div>
            <h6 class="fw-bold text-dark">ยังไม่มีประวัติการจองห้องซ้อม</h6>
            <p class="text-muted small">คุณสามารถกดปุ่ม + ด้านล่างเพื่อเริ่มจองห้องซ้อมได้เลย</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($bookings as $b): 
                $badge = getPracticeBookingBadge($b['status']);
            ?>
            <div class="col-12">
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-<?= $badge['class'] ?>-subtle text-<?= $badge['class'] ?> rounded-pill mb-1">
                                <i class="fa-solid <?= $badge['icon'] ?> me-1"></i><?= $badge['label'] ?>
                            </span>
                            <h6 class="fw-bold text-dark mb-0">รหัสห้อง: <?= htmlspecialchars($b['room_id']) ?></h6>
                            <small class="text-muted"><?= htmlspecialchars($b['room_type']) ?></small>
                        </div>
                        <div class="text-end">
                            <h6 class="fw-bold text-purple mb-0"><?= date('H:i', strtotime($b['start_time'])) ?> - <?= date('H:i', strtotime($b['end_time'])) ?></h6>
                            <small class="text-muted"><?= date('d/m/Y', strtotime($b['booking_date'])) ?></small>
                        </div>
                    </div>
                    
                    <?php if (!empty($b['purpose'])): ?>
                    <div class="bg-light p-2 rounded-3 mt-2 small text-muted">
                        <i class="fa-solid fa-comment-dots me-1"></i> <?= htmlspecialchars($b['purpose']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
