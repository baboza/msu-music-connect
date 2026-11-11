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
$filter_status = $_GET['status'] ?? 'all';

// ดึงรายการโครงการ
try {
    $sql = "
        SELECT p.*,
               resp.first_name as resp_fname, resp.last_name as resp_lname,
               coord.first_name as coord_fname, coord.last_name as coord_lname
        FROM projects p
        LEFT JOIN users resp ON p.responsible_id = resp.user_id
        LEFT JOIN users coord ON p.coordinator_id = coord.user_id
        WHERE p.fiscal_year = ?
    ";
    $params = [$filter_year];

    if ($filter_status !== 'all') {
        $sql .= " AND p.status = ? ";
        $params[] = $filter_status;
    }

    $sql .= " ORDER BY p.project_id DESC ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณภาพรวมงบประมาณ
    $total_budget = 0;
    $total_actual = 0;
    foreach ($projects as $pr) {
        $total_budget += floatval($pr['budget']);
        $total_actual += floatval($pr['actual_budget']);
    }
    $utilization_rate = ($total_budget > 0) ? round(($total_actual / $total_budget) * 100, 1) : 0;

} catch (PDOException $e) {
    $projects = [];
    $total_budget = 0;
    $total_actual = 0;
    $utilization_rate = 0;
}

function getProjectStatusBadge($st) {
    switch ($st) {
        case 'ดำเนินการเรียบร้อย': return ['class' => 'bg-success text-white', 'icon' => 'fa-check'];
        case 'กำลังดำเนินการ': return ['class' => 'bg-info text-white animate-pulse', 'icon' => 'fa-spinner fa-spin'];
        case 'ล่าช้า': return ['class' => 'bg-danger text-white', 'icon' => 'fa-triangle-exclamation'];
        default: return ['class' => 'bg-secondary text-white', 'icon' => 'fa-clock'];
    }
}

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
                <h5 class="fw-bold mb-0 text-dark">ระบบติดตามโครงการ</h5>
                <small class="text-muted">งบประมาณและแผนยุทธศาสตร์</small>
            </div>
        </div>
        <!-- Year Select -->
        <form method="GET" action="projects.php" class="d-inline">
            <select name="year" class="form-select form-select-sm rounded-pill fw-bold border-purple text-purple px-2 py-1 extra-small" onchange="this.form.submit()">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= htmlspecialchars($fy['year_name']) ?>" <?= $filter_year === $fy['year_name'] ? 'selected' : '' ?>>
                        ปี <?= htmlspecialchars($fy['year_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Strategic Budget Summary Bento -->
    <div class="bento-box p-3 mb-3" style="background: linear-gradient(135deg, rgba(57, 4, 143, 0.08) 0%, rgba(57, 4, 143, 0.02) 100%); border-left: 4px solid var(--msu-primary);">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="extra-small fw-bold text-purple text-uppercase">
                <i class="fa-solid fa-chart-pie me-1"></i> สรุปการเบิกจ่ายปีงบ <?= htmlspecialchars($filter_year) ?>
            </span>
            <span class="badge bg-purple text-white px-2 py-1 rounded-pill">
                เบิกจ่ายแล้ว <?= $utilization_rate ?>%
            </span>
        </div>

        <div class="row g-2 mb-2 text-center">
            <div class="col-6">
                <div class="p-2 rounded-2 bg-white border">
                    <span class="extra-small text-muted d-block">งบประมาณจัดสรร</span>
                    <span class="fw-bold small text-dark"><?= number_format($total_budget, 2) ?> ฿</span>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded-2 bg-white border">
                    <span class="extra-small text-muted d-block">เบิกจ่ายจริง</span>
                    <span class="fw-bold small text-success"><?= number_format($total_actual, 2) ?> ฿</span>
                </div>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="progress" style="height: 7px; border-radius: 4px;">
            <div class="progress-bar bg-purple" role="progressbar" style="width: <?= min($utilization_rate, 100) ?>%;"></div>
        </div>
    </div>

    <!-- Projects List Header -->
    <h6 class="fw-bold text-dark mb-2 px-1 d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-diagram-project text-purple me-2"></i>รายการโครงการ (<?= count($projects) ?>)</span>
    </h6>

    <?php if (empty($projects)): ?>
        <div class="bento-box text-center py-5">
            <div class="avatar-circle mx-auto mb-3" style="width: 60px; height: 60px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-solid fa-folder-open fa-2x"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">ไม่พบโครงการในปีงบประมาณนี้</h6>
            <p class="text-muted small mb-0">ไม่มีข้อมูลโครงการสำหรับปี <?= htmlspecialchars($filter_year) ?></p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($projects as $pr): 
                $st_badge = getProjectStatusBadge($pr['status']);
                $b_total = floatval($pr['budget']);
                $b_act = floatval($pr['actual_budget']);
                $pct = ($b_total > 0) ? round(($b_act / $b_total) * 100) : 0;
            ?>
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small">
                            ปีงบ <?= htmlspecialchars($pr['fiscal_year']) ?>
                        </span>
                        <span class="badge <?= $st_badge['class'] ?> extra-small px-2 py-1">
                            <i class="fa-solid <?= $st_badge['icon'] ?> me-1"></i><?= htmlspecialchars($pr['status']) ?>
                        </span>
                    </div>

                    <h6 class="fw-bold text-dark mb-2 line-clamp-2" style="line-height: 1.4;">
                        <?= htmlspecialchars($pr['project_name']) ?>
                    </h6>

                    <!-- 5-Step Milestones Checklist -->
                    <div class="d-flex justify-content-between gap-1 mb-3 pt-1">
                        <div class="milestone-pill <?= $pr['task_approve'] ? 'done' : '' ?>" title="ขออนุมัติโครงการ">
                            <i class="fa-solid <?= $pr['task_approve'] ? 'fa-check' : 'fa-circle' ?>"></i> อนุมัติ
                        </div>
                        <div class="milestone-pill <?= $pr['task_borrow'] ? 'done' : '' ?>" title="ยืมเงินทดรอง">
                            <i class="fa-solid <?= $pr['task_borrow'] ? 'fa-check' : 'fa-circle' ?>"></i> ยืมเงิน
                        </div>
                        <div class="milestone-pill <?= $pr['task_execute'] ? 'done' : '' ?>" title="ดำเนินโครงการ">
                            <i class="fa-solid <?= $pr['task_execute'] ? 'fa-check' : 'fa-circle' ?>"></i> จัดกิจกรรม
                        </div>
                        <div class="milestone-pill <?= $pr['task_report'] ? 'done' : '' ?>" title="รายงานผล">
                            <i class="fa-solid <?= $pr['task_report'] ? 'fa-check' : 'fa-circle' ?>"></i> รายงาน
                        </div>
                        <div class="milestone-pill <?= $pr['task_clear_money'] ? 'done' : '' ?>" title="เคลียร์เงินยืม">
                            <i class="fa-solid <?= $pr['task_clear_money'] ? 'fa-check' : 'fa-circle' ?>"></i> เคลียร์เงิน
                        </div>
                    </div>

                    <!-- Budget Progress Bar -->
                    <div class="bg-light p-2 rounded-3 border mb-2">
                        <div class="d-flex justify-content-between align-items-center extra-small mb-1">
                            <span class="text-muted">เบิกจ่าย: <strong><?= number_format($b_act) ?> ฿</strong></span>
                            <span class="text-dark">งบ: <strong><?= number_format($b_total) ?> ฿</strong> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-success" style="width: <?= min($pct, 100) ?>%;"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top extra-small text-muted">
                        <div>
                            <i class="fa-solid fa-user-tie me-1 text-purple"></i>
                            <?= htmlspecialchars(($pr['resp_fname'] ?? '') . ' ' . ($pr['resp_lname'] ?? '')) ?: 'ผู้รับผิดชอบโครงการ' ?>
                        </div>
                        <button class="btn btn-sm btn-link text-purple p-0 text-decoration-none fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#proj_<?= $pr['project_id'] ?>">
                            รายละเอียด <i class="fa-solid fa-chevron-down ms-1"></i>
                        </button>
                    </div>

                    <!-- Collapsible Details -->
                    <div class="collapse mt-2 pt-2 border-top extra-small text-secondary" id="proj_<?= $pr['project_id'] ?>">
                        <?php if (!empty($pr['objectives'])): ?>
                            <div class="mb-2">
                                <strong class="text-dark d-block">วัตถุประสงค์:</strong>
                                <?= nl2br(htmlspecialchars($pr['objectives'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($pr['results'])): ?>
                            <div class="mb-2">
                                <strong class="text-success d-block">ผลการดำเนินงาน:</strong>
                                <?= nl2br(htmlspecialchars($pr['results'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($pr['problems_suggestions'])): ?>
                            <div>
                                <strong class="text-warning d-block">ปัญหา / ข้อเสนอแนะ:</strong>
                                <?= nl2br(htmlspecialchars($pr['problems_suggestions'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<style>
.milestone-pill {
    flex: 1;
    font-size: 0.65rem;
    font-weight: 600;
    text-align: center;
    padding: 3px 2px;
    border-radius: 6px;
    background: var(--slate-100);
    color: var(--slate-500);
    white-space: nowrap;
}
.milestone-pill.done {
    background: #d1fae5;
    color: #065f46;
}
.milestone-pill i {
    font-size: 0.6rem;
}
</style>

<?php require_once 'includes/footer.php'; ?>
