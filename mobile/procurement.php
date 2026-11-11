<?php
require_once 'auth_check.php';

// ดึงรายการปีงบประมาณ
try {
    $stmt_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
    $fiscal_years = $stmt_fy->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $fiscal_years = [];
}

$default_year = '';
foreach ($fiscal_years as $fy) {
    if ($fy['is_active'] == 1) {
        $default_year = $fy['year_name'];
        break;
    }
}
if (empty($default_year) && !empty($fiscal_years)) {
    $default_year = $fiscal_years[0]['year_name'];
}

$filter_year = $_GET['year'] ?? $default_year;

// ดึงรายการจัดซื้อจัดจ้าง
try {
    $stmt = $pdo->prepare("
        SELECT pt.*, u.first_name, u.last_name
        FROM procurement_tracking pt
        LEFT JOIN users u ON pt.responsible_user_id = u.user_id
        WHERE pt.fiscal_year = ?
        ORDER BY pt.id DESC
    ");
    $stmt->execute([$filter_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_budget = 0;
    $total_actual = 0;
    foreach ($items as $it) {
        $total_budget += floatval($it['budget']);
        $total_actual += floatval($it['actual_price']);
    }
    $savings = $total_budget - $total_actual;

} catch (PDOException $e) {
    $items = [];
    $total_budget = 0;
    $total_actual = 0;
    $savings = 0;
}

$steps_def = [
    1 => 'ขอซื้อ/จ้าง',
    2 => 'จัดทำ TOR',
    3 => 'อนุมัติซื้อ/จ้าง',
    4 => 'ประกาศผู้ชนะ',
    5 => 'ระบบอนุมัติ',
    6 => 'ลงนามสัญญา',
    7 => 'ส่งมอบพัสดุ',
    8 => 'ตรวจรับพัสดุ',
    9 => 'เบิกจ่ายเงิน',
    10 => 'เสร็จสิ้นสมบูรณ์'
];

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
                <h5 class="fw-bold mb-0 text-dark">ติดตามงานพัสดุ</h5>
                <small class="text-muted">สถานะจัดซื้อจัดจ้าง 10 ขั้นตอน</small>
            </div>
        </div>
        <!-- Year Select -->
        <form method="GET" action="procurement.php" class="d-inline">
            <select name="year" class="form-select form-select-sm rounded-pill fw-bold border-purple text-purple px-2 py-1 extra-small" onchange="this.form.submit()">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= htmlspecialchars($fy['year_name']) ?>" <?= $filter_year === $fy['year_name'] ? 'selected' : '' ?>>
                        ปี <?= htmlspecialchars($fy['year_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Procurement Summary Bento -->
    <div class="bento-box p-3 mb-3" style="background: linear-gradient(135deg, rgba(57, 4, 143, 0.08) 0%, rgba(57, 4, 143, 0.02) 100%); border-left: 4px solid var(--msu-primary);">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="extra-small fw-bold text-purple text-uppercase">
                <i class="fa-solid fa-coins me-1"></i> สรุปจัดซื้อจัดจ้าง ปีงบ <?= htmlspecialchars($filter_year) ?>
            </span>
            <span class="badge bg-purple-subtle text-purple px-2 py-1 rounded-pill">
                <?= count($items) ?> รายการ
            </span>
        </div>

        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="p-2 rounded-2 bg-white border">
                    <span class="extra-small text-muted d-block">วงเงินงบประมาณ</span>
                    <span class="fw-bold small text-dark"><?= number_format($total_budget) ?> ฿</span>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded-2 bg-white border">
                    <span class="extra-small text-muted d-block">ราคาจัดซื้อจริง</span>
                    <span class="fw-bold small text-primary"><?= number_format($total_actual) ?> ฿</span>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded-2 bg-white border">
                    <span class="extra-small text-success d-block">ประหยัดได้</span>
                    <span class="fw-bold small text-success"><?= number_format($savings) ?> ฿</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Items List -->
    <h6 class="fw-bold text-dark mb-2 px-1 d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-truck-ramp-box text-purple me-2"></i>รายการจัดซื้อ (<?= count($items) ?>)</span>
    </h6>

    <?php if (empty($items)): ?>
        <div class="bento-box text-center py-5">
            <div class="avatar-circle mx-auto mb-3" style="width: 60px; height: 60px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-solid fa-box-open fa-2x"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">ไม่พบรายการจัดซื้อในปีนี้</h6>
            <p class="text-muted small mb-0">ไม่มีข้อมูลการจัดซื้อจัดจ้างสำหรับปี <?= htmlspecialchars($filter_year) ?></p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($items as $it): 
                // คำนวณขั้นตอนที่เสร็จสิ้น
                $completed_steps = 0;
                for ($i = 1; $i <= 10; $i++) {
                    if (!empty($it['step' . $i . '_' . ['1'=>'request','2'=>'tor','3'=>'approve_buy','4'=>'announce_winner','5'=>'system_approve','6'=>'contract','7'=>'delivery','8'=>'inspection','9'=>'disbursement','10'=>'completed'][$i]])) {
                        $completed_steps++;
                    }
                }
                $is_all_done = ($completed_steps === 10);
            ?>
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small">
                            <?= htmlspecialchars($it['procurement_method'] ?: 'เฉพาะเจาะจง') ?>
                        </span>
                        <span class="badge <?= $is_all_done ? 'bg-success' : 'bg-warning text-dark' ?> extra-small px-2 py-1">
                            <?= $is_all_done ? 'เสร็จสิ้น 100%' : 'ขั้นตอนที่ ' . $completed_steps . '/10' ?>
                        </span>
                    </div>

                    <h6 class="fw-bold text-dark mb-1 line-clamp-2" style="line-height: 1.4;">
                        <?= htmlspecialchars($it['project_name']) ?>
                    </h6>

                    <div class="extra-small text-muted mb-2">
                        ประเภท: <?= htmlspecialchars($it['item_type'] ?: '-') ?> | 
                        คู่สัญญา: <strong class="text-dark"><?= htmlspecialchars($it['procurement_result'] ?: 'รอดำเนินการ') ?></strong>
                    </div>

                    <!-- 10-Step Mini Progress Indicator -->
                    <div class="mb-2">
                        <div class="d-flex justify-content-between extra-small text-muted mb-1">
                            <span>ความคืบหน้า</span>
                            <span class="fw-bold text-purple"><?= $completed_steps ?> จาก 10 ขั้นตอน</span>
                        </div>
                        <div class="progress" style="height: 6px; border-radius: 4px;">
                            <div class="progress-bar <?= $is_all_done ? 'bg-success' : 'bg-purple' ?>" style="width: <?= ($completed_steps * 10) ?>%;"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top extra-small">
                        <span class="text-muted">
                            งบ: <strong><?= number_format(floatval($it['budget'])) ?></strong> ฿ 
                            (จัดซื้อจริง: <strong class="text-success"><?= number_format(floatval($it['actual_price'])) ?></strong> ฿)
                        </span>
                        <button class="btn btn-sm btn-link text-purple p-0 text-decoration-none fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#stepBox_<?= $it['id'] ?>">
                            ดูขั้นตอน <i class="fa-solid fa-chevron-down ms-1"></i>
                        </button>
                    </div>

                    <!-- Collapsible 10 Steps Checklist -->
                    <div class="collapse mt-2 pt-2 border-top extra-small text-secondary" id="stepBox_<?= $it['id'] ?>">
                        <div class="row g-1">
                            <?php 
                            $step_keys = ['1'=>'request','2'=>'tor','3'=>'approve_buy','4'=>'announce_winner','5'=>'system_approve','6'=>'contract','7'=>'delivery','8'=>'inspection','9'=>'disbursement','10'=>'completed'];
                            foreach ($steps_def as $snum => $slabel): 
                                $is_done = !empty($it['step' . $snum . '_' . $step_keys[$snum]]);
                            ?>
                                <div class="col-6">
                                    <div class="p-1 rounded <?= $is_done ? 'bg-success bg-opacity-10 text-success fw-bold' : 'bg-light text-muted' ?>">
                                        <i class="fa-solid <?= $is_done ? 'fa-circle-check text-success' : 'fa-circle-minus text-secondary' ?> me-1"></i>
                                        <?= $snum ?>. <?= $slabel ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
