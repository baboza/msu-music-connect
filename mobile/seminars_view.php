<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location.href='seminars.php';</script>";
    exit();
}

$report_id = $_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT tr.*, u.first_name, u.last_name, u.position
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        WHERE tr.report_id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo "<script>alert('ไม่พบข้อมูลรายงานนี้'); window.location.href='seminars.php';</script>";
        exit();
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

$evaluation = $report['evaluation'] ?? '';
$eval_color = 'secondary';
$eval_icon = 'fa-minus';
if ($evaluation == 'ดีมาก') { $eval_color = 'success'; $eval_icon = 'fa-face-grin-stars'; }
elseif ($evaluation == 'ดี') { $eval_color = 'primary'; $eval_icon = 'fa-face-smile'; }
elseif ($evaluation == 'ไม่ดี') { $eval_color = 'danger'; $eval_icon = 'fa-face-frown'; }
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="seminars.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้ารายงานสัมมนา">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">รายละเอียดรายงานสัมมนา</h5>
            <small class="text-muted">รหัสอ้างอิง TR-<?= str_pad($report['report_id'], 5, '0', STR_PAD_LEFT) ?></small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-plane-departure"></i>
        </div>
    </div>

    <!-- ข้อมูลโครงการ -->
    <div class="bento-box p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-circle-info me-2 text-purple"></i>ข้อมูลโครงการ</h6>
            <span class="badge bg-light text-dark border"><?= date('d/m/Y', strtotime($report['created_at'])) ?></span>
        </div>
        
        <p class="mb-1 fw-bold fs-6 text-dark"><?= htmlspecialchars($report['project_name']) ?></p>
        <div class="small text-muted mb-3">
            <div><strong>จัดโดย:</strong> <?= htmlspecialchars($report['organizer']) ?></div>
            <div><strong>สถานที่:</strong> <?= htmlspecialchars($report['location']) ?></div>
        </div>
        
        <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
            <small class="text-muted"><i class="fa-solid fa-calendar me-1 text-purple"></i><?= date('d/m/Y', strtotime($report['start_date'])) ?> - <?= date('d/m/Y', strtotime($report['end_date'])) ?></small>
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle">
                งบประมาณ: ฿<?= number_format($report['budget_used'], 2) ?>
            </span>
        </div>
    </div>

    <!-- สรุปผลประเมิน -->
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-pen-nib me-2 text-purple"></i>สรุปและประเมินผล</h6>
        <p class="small text-dark mb-3" style="line-height: 1.6;">
            <?= nl2br(htmlspecialchars($report['summary'])) ?>
        </p>
        
        <div class="d-flex align-items-center pt-3 border-top">
            <div class="app-icon-container me-3" style="width:38px;height:38px;font-size:16px;">
                <i class="fa-solid <?= $eval_icon ?>"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">ระดับการประเมิน</h6>
                <small class="badge bg-<?= $eval_color ?>-subtle text-<?= $eval_color ?> border mt-1"><?= htmlspecialchars($evaluation) ?></small>
            </div>
        </div>
    </div>

    <!-- แผนนำไปใช้ -->
    <div class="bento-box p-4 mb-5">
        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-lightbulb me-2 text-purple"></i>การนำไปใช้ประโยชน์</h6>
        
        <div class="mb-3">
            <div class="fw-bold text-dark small mb-1">แนวทางการนำไปใช้:</div>
            <p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($report['apply_how'])) ?></p>
        </div>
        <div class="mb-3">
            <div class="fw-bold text-dark small mb-1">ผลที่คาดว่าจะได้รับ:</div>
            <p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($report['apply_expected_result'])) ?></p>
        </div>
        <?php if(!empty($report['apply_completion_date'])): ?>
            <div class="text-muted small">
                <i class="fa-regular fa-clock me-1 text-purple"></i> กำหนดเสร็จสิ้น: <?= date('d/m/Y', strtotime($report['apply_completion_date'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
