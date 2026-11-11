<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงข้อมูลรายการของตัวเอง
$stmt = $pdo->prepare("SELECT * FROM training_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$user_id]);
$reports = $stmt->fetchAll();
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">รายงานสัมมนา/อบรม</h5>
            <small class="text-muted">ประวัติการขอไปราชการและรายงานผล</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-plane-departure"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="seminars_form.php" class="mobile-fab" title="เขียนรายงานใหม่" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    <?php if (empty($reports)): ?>
        <div class="text-center py-5 bento-box">
            <i class="fa-solid fa-book-journal-whills text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
            <h6 class="text-muted fw-bold">ยังไม่มีประวัติการส่งรายงาน</h6>
            <p class="small text-muted mb-0">กดปุ่ม + ด้านล่างเพื่อเพิ่มรายงานใหม่</p>
        </div>
    <?php else: ?>
        <?php foreach ($reports as $r): ?>
            <div class="bento-box mb-3 p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <?php if ($r['status'] == 'reported'): ?>
                        <span class="badge bg-success-subtle text-success border px-2 py-1"><i class="fa-solid fa-check-circle me-1"></i>บันทึกแล้ว</span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis border px-2 py-1"><i class="fa-solid fa-pen-to-square me-1"></i>รอเติมข้อมูล</span>
                    <?php endif; ?>
                    <small class="text-muted"><i class="fa-solid fa-calendar me-1"></i><?= date('d/m/Y', strtotime($r['created_at'])) ?></small>
                </div>
                <div class="fw-bold text-dark fs-5 mb-1"><?= htmlspecialchars($r['project_name']) ?></div>
                <div class="text-muted small mb-2"><i class="fa-solid fa-location-dot text-danger me-2"></i><?= htmlspecialchars($r['location']) ?></div>
                <div class="small fw-bold text-secondary mb-2">
                    <i class="fa-regular fa-clock me-1 text-purple"></i>
                    <?php 
                        if($r['start_date'] == $r['end_date']) echo date('d/m/Y', strtotime($r['start_date']));
                        else echo date('d/m/Y', strtotime($r['start_date'])) . ' - ' . date('d/m/Y', strtotime($r['end_date']));
                    ?>
                </div>
                
                <div class="d-flex gap-2 mt-3 pt-3 border-top">
                    <?php if($r['status'] != 'reported'): ?>
                        <a href="seminars_form.php?id=<?= $r['report_id'] ?>" class="btn btn-outline-warning btn-sm flex-fill rounded-pill" hx-boost="true"><i class="fa-solid fa-pen me-1"></i> แก้ไข</a>
                    <?php endif; ?>
                    <a href="seminars_view.php?id=<?= $r['report_id'] ?>" class="btn btn-outline-primary btn-sm flex-fill rounded-pill" hx-boost="true"><i class="fa-solid fa-eye me-1"></i> ดูข้อมูล</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
