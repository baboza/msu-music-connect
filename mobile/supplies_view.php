<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location.href='supplies.php';</script>";
    exit();
}

$request_id = $_GET['id'];

try {
    // ดึงข้อมูลหัวบิล
    $stmt = $pdo->prepare("
        SELECT r.*, 
               u.first_name as req_fname, u.last_name as req_lname
        FROM supplies_requests r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo "<script>alert('ไม่พบข้อมูลคำขอเบิกนี้'); window.location.href='supplies.php';</script>";
        exit();
    }

    // ดึงรายการของที่เบิก
    $stmt_details = $pdo->prepare("
        SELECT d.*, i.item_name, i.unit_name 
        FROM supplies_request_details d
        JOIN supplies_items i ON d.item_id = i.item_id
        WHERE d.request_id = ?
    ");
    $stmt_details->execute([$request_id]);
    $details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

function getStatusBadge($status, $step) {
    if ($status == 'pending') return ['label' => 'รออนุมัติ', 'class' => 'warning', 'icon' => 'fa-clock'];
    if ($status == 'approved' && $step == 'head') return ['label' => 'หน.อนุมัติแล้ว', 'class' => 'primary', 'icon' => 'fa-check'];
    if ($status == 'issued' && $step == 'supply') return ['label' => 'รับของแล้ว', 'class' => 'success', 'icon' => 'fa-box-open'];
    if ($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'danger', 'icon' => 'fa-xmark'];
    return ['label' => 'ไม่ทราบ', 'class' => 'secondary', 'icon' => 'fa-question'];
}

$head_status = getStatusBadge($req['head_status'], 'head');
$supply_status = getStatusBadge($req['supply_status'], 'supply');
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="supplies.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าระบบเบิกพัสดุ">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">รายละเอียดใบเบิก</h5>
            <small class="text-muted">คำขอ #<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT) ?></small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
    </div>

    <!-- ข้อมูลผู้ขอเบิก -->
    <div class="bento-box p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-user me-2 text-purple"></i>ผู้ขอเบิก</h6>
            <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($req['request_date'])) ?></span>
        </div>
        <p class="mb-1 fw-bold fs-6 text-dark"><?= htmlspecialchars($req['req_fname'] . ' ' . $req['req_lname']) ?></p>
        <div class="bg-light p-3 rounded-3 mt-2 border">
            <div class="small fw-bold text-dark mb-1">เหตุผลความจำเป็น:</div>
            <p class="small text-muted mb-0"><?= htmlspecialchars($req['request_reason']) ?></p>
        </div>
    </div>

    <!-- รายการของที่เบิก -->
    <h6 class="fw-bold text-dark mb-3 px-2 mt-4"><i class="fa-solid fa-box-open text-purple me-2"></i>รายการวัสดุ (<?= count($details) ?> รายการ)</h6>
    <div class="bento-box p-0 mb-4 overflow-hidden">
        <ul class="list-group list-group-flush">
            <?php foreach ($details as $d): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                    <div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($d['item_name']) ?></div>
                    </div>
                    <div class="badge bg-purple-subtle text-purple border border-purple-subtle px-3 py-2 fs-6 rounded-pill">
                        <?= $d['request_qty'] ?> <span class="fw-normal"><?= htmlspecialchars($d['unit_name']) ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- สถานะการอนุมัติ -->
    <h6 class="fw-bold text-dark mb-3 px-2"><i class="fa-solid fa-list-check text-purple me-2"></i>สถานะการดำเนินการ</h6>
    <div class="bento-box p-4 mb-5">
        
        <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
            <div class="me-3">
                <div class="app-icon-container" style="width:38px;height:38px;font-size:15px;">
                    <i class="fa-solid <?= $head_status['icon'] ?>"></i>
                </div>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">หัวหน้างานพิจารณา</h6>
                <small class="badge bg-<?= $head_status['class'] ?>-subtle text-<?= $head_status['class'] ?> border mt-1"><?= $head_status['label'] ?></small>
            </div>
        </div>

        <div class="d-flex align-items-center">
            <div class="me-3">
                <div class="app-icon-container" style="width:38px;height:38px;font-size:15px;">
                    <i class="fa-solid <?= $req['head_status']=='rejected'?'fa-minus':$supply_status['icon'] ?>"></i>
                </div>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">เจ้าหน้าที่งานพัสดุจ่ายของ</h6>
                <small class="badge bg-<?= $req['head_status']=='rejected'?'secondary':$supply_status['class'] ?>-subtle text-<?= $req['head_status']=='rejected'?'secondary':$supply_status['class'] ?> border mt-1">
                    <?= $req['head_status']=='rejected'?'ยกเลิกอัตโนมัติ':$supply_status['label'] ?>
                </small>
            </div>
        </div>

    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
