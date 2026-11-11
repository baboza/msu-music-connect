<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงประวัติการบันทึกงาน
$sql = "SELECT l.*, t.task_name 
        FROM work_logs l
        JOIN work_tasks t ON l.task_id = t.task_id
        WHERE l.user_id = ?
        ORDER BY l.log_date DESC, l.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$worklogs = $stmt->fetchAll();

function getHarmonyDisplay($score) {
    switch ($score) {
        case 1: return '<i class="fa-solid fa-face-frown text-danger"></i>';
        case 2: return '<i class="fa-solid fa-face-meh text-warning"></i>';
        case 3: return '<i class="fa-solid fa-face-smile text-primary"></i>';
        case 4: return '<i class="fa-solid fa-face-smile-beam text-info"></i>';
        case 5: return '<i class="fa-solid fa-face-laugh-squint text-success"></i>';
        default: return '<i class="fa-solid fa-minus text-muted"></i>';
    }
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">Daily Report</h5>
            <small class="text-muted">ประวัติการบันทึกงานประจำวัน</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="worklog_form.php" class="mobile-fab" title="เขียน Daily Report" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    <?php if(empty($worklogs)): ?>
    <div class="bento-box text-center py-5">
        <i class="fa-solid fa-clipboard-list mb-3 text-muted" style="font-size: 48px; opacity: 0.3;"></i>
        <h6 class="text-muted fw-bold">ยังไม่มีประวัติการลงเวลา</h6>
        <p class="small text-muted mb-0">กดปุ่ม + ด้านล่างเพื่อเริ่มบันทึกงาน</p>
    </div>
    <?php else: ?>
        <?php 
        $current_date = '';
        foreach($worklogs as $log): 
            $log_date = date('d/m/Y', strtotime($log['log_date']));
            if($current_date != $log_date):
                $current_date = $log_date;
        ?>
            <div class="d-flex align-items-center gap-2 mt-4 mb-2 ms-1">
                <i class="fa-regular fa-calendar text-purple small"></i>
                <span class="fw-bold text-slate-700 small"><?= $current_date ?></span>
            </div>
        <?php endif; ?>
            
            <div class="bento-box mb-3 p-3 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="width: 80%;">
                        <div class="fw-bold text-dark text-truncate mb-1"><?= htmlspecialchars($log['task_name']) ?></div>
                        <p class="small text-muted mb-2 text-truncate"><?= htmlspecialchars($log['description']) ?></p>
                        
                        <div class="d-flex align-items-center flex-wrap gap-1 mt-2">
                            <span class="badge bg-light text-dark border"><i class="fa-regular fa-clock me-1 text-purple"></i><?= $log['hours_spent'] ?> นาที</span>
                            <?php if($log['pieces_done'] > 0): ?>
                            <span class="badge bg-light text-dark border"><?= $log['pieces_done'] ?> ชิ้น</span>
                            <?php endif; ?>
                            <?php if($log['urgency_level'] != 'ปกติ'): ?>
                                <span class="badge bg-danger-subtle text-danger border"><?= $log['urgency_level'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-center ps-2">
                        <div class="fs-4"><?= getHarmonyDisplay($log['harmony_score']) ?></div>
                        <?php if($log['has_issue'] == 1): ?>
                            <div class="mt-1"><i class="fa-solid fa-triangle-exclamation text-danger fs-6" title="มีปัญหาอุปสรรค"></i></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
