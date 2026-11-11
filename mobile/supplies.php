<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงปีงบประมาณที่ Active จากระบบตั้งค่า
$stmt_active = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$default_fy = $stmt_active->fetchColumn() ?: (date('Y') + 543);

$filter_fy = isset($_GET['fy']) ? $_GET['fy'] : $default_fy;

// ดึงรายการปีงบประมาณทั้งหมดที่มีข้อมูล
$stmt_years = $pdo->query("SELECT DISTINCT fiscal_year FROM supplies_requests ORDER BY fiscal_year DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($default_fy, $available_years)) { array_unshift($available_years, $default_fy); }

// ดึงประวัติการขอเบิก
$stmt_requests = $pdo->prepare("
    SELECT r.*, 
           (SELECT COUNT(*) FROM supplies_request_details WHERE request_id = r.request_id) as item_count 
    FROM supplies_requests r 
    WHERE r.user_id = ? AND r.fiscal_year = ?
    ORDER BY r.request_date DESC
    LIMIT 20
");
$stmt_requests->execute([$user_id, $filter_fy]);
$requests = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);

function getStatusBadge($status, $step) {
    if ($status == 'pending') return ['label' => 'รออนุมัติ', 'class' => 'warning', 'icon' => 'fa-clock'];
    if ($status == 'approved' && $step == 'head') return ['label' => 'หน.อนุมัติแล้ว', 'class' => 'primary', 'icon' => 'fa-check'];
    if ($status == 'issued' && $step == 'supply') return ['label' => 'รับของแล้ว', 'class' => 'success', 'icon' => 'fa-box-open'];
    if ($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'danger', 'icon' => 'fa-xmark'];
    return ['label' => 'ไม่ทราบ', 'class' => 'secondary', 'icon' => 'fa-question'];
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ระบบเบิกพัสดุ</h5>
            <small class="text-muted">ปีงบประมาณ <?= htmlspecialchars($filter_fy) ?></small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
    </div>

    <!-- Dropdown เลือกปีงบประมาณ -->
    <form method="GET" class="mb-3 d-flex align-items-center">
        <select name="fy" class="form-select bg-white shadow-sm rounded-pill fw-bold text-center border-purple-subtle" onchange="this.form.submit()" style="color: var(--primary);">
            <?php foreach($available_years as $y): ?>
                <option value="<?= $y ?>" <?= ($filter_fy == $y) ? 'selected' : '' ?>>ปีงบประมาณ <?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Floating Action Button -->
    <a href="supplies_form.php" class="mobile-fab" title="เบิกพัสดุใหม่" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    <?php if(empty($requests)): ?>
        <div class="text-center py-5 bento-box">
            <i class="fa-solid fa-box-open text-muted mb-2" style="font-size: 40px; opacity: 0.3;"></i>
            <h6 class="text-muted fw-bold">ยังไม่มีประวัติการเบิกในปีงบประมาณนี้</h6>
            <p class="small text-muted mb-0">กดปุ่ม + ด้านล่างเพื่อขอเบิกพัสดุใหม่</p>
        </div>
    <?php else: ?>
        <?php foreach ($requests as $r): 
            $status = getStatusBadge($r['head_status'] == 'rejected' || $r['supply_status'] == 'rejected' ? 'rejected' : 
                      ($r['supply_status'] == 'issued' ? 'issued' : 
                       ($r['head_status'] == 'approved' ? 'approved' : 'pending')), 
                      $r['supply_status'] == 'issued' ? 'supply' : ($r['head_status'] == 'approved' ? 'head' : ''));
        ?>
            <div class="bento-box mb-3 p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge bg-light text-dark border px-2 py-1">เลขที่ <?= str_pad($r['request_id'], 4, '0', STR_PAD_LEFT) ?></span>
                    <span class="badge bg-<?= $status['class'] ?>-subtle text-<?= $status['class'] ?> border px-2 py-1">
                        <i class="fa-solid <?= $status['icon'] ?> me-1"></i><?= $status['label'] ?>
                    </span>
                </div>
                <div class="fw-bold text-dark fs-6 mb-1"><?= htmlspecialchars($r['request_reason']) ?></div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="text-muted small"><i class="fa-solid fa-calendar me-1"></i><?= date('d/m/Y', strtotime($r['request_date'])) ?></div>
                    <span class="badge bg-purple-subtle text-purple rounded-pill px-2 py-1 border border-purple-subtle"><?= $r['item_count'] ?> รายการ</span>
                </div>
                
                <div class="mt-3 pt-3 border-top text-center">
                    <a href="supplies_view.php?id=<?= $r['request_id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill px-4" hx-boost="true">
                        <i class="fa-solid fa-list-check me-1"></i> ดูรายการของ
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
