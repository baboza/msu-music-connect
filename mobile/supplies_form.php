<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy = $stmt_fy->fetchColumn() ?: (date('Y') + 543);

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_qty'])) {
    $requested_items = $_POST['request_qty']; 
    $request_reason = trim($_POST['request_reason']); 
    
    $has_items = false;
    foreach ($requested_items as $qty) {
        if ((int)$qty > 0) { $has_items = true; break; }
    }

    if (!$has_items) {
        $msg = "ระบุจำนวนอย่างน้อย 1 รายการ";
        $msg_type = "danger";
    } elseif (empty($request_reason)) {
        $msg = "กรุณาระบุเหตุผลการเบิก";
        $msg_type = "danger";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt_head = $pdo->prepare("INSERT INTO supplies_requests (user_id, request_reason, fiscal_year) VALUES (?, ?, ?)");
            $stmt_head->execute([$user_id, $request_reason, $active_fy]);
            $request_id = $pdo->lastInsertId();

            $stmt_detail = $pdo->prepare("INSERT INTO supplies_request_details (request_id, item_id, request_qty) VALUES (?, ?, ?)");
            
            foreach ($requested_items as $item_id => $qty) {
                if ((int)$qty > 0) {
                    $stmt_detail->execute([$request_id, $item_id, $qty]);
                }
            }

            $pdo->commit();
            $msg = "ส่งใบขอเบิกพัสดุเรียบร้อยแล้ว";
            $msg_type = "success";
            
            echo "<script>setTimeout(()=>window.location.href='supplies.php', 1200);</script>";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// ดึงของในคลัง
$stmt_items = $pdo->query("SELECT category, item_id, item_name, stock_qty, unit_name FROM supplies_items WHERE is_active = 1 AND stock_qty > 0 ORDER BY category, item_name");
$supplies_grouped = $stmt_items->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="supplies.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าระบบเบิกพัสดุ">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">เขียนใบขอเบิกพัสดุ</h5>
            <small class="text-muted">ปีงบประมาณ <?= htmlspecialchars($active_fy) ?></small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-box-open"></i>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= ($msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation') ?> me-2"></i><?= $msg ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="supplyForm">
        
        <div class="bento-box p-4 mb-3">
            <label class="form-label small text-muted mb-1 fw-bold">เหตุผลความจำเป็น / นำไปใช้ในงานใด <span class="text-danger">*</span></label>
            <textarea name="request_reason" class="form-control form-control-sm" rows="3" required placeholder="เช่น ใช้ในงานการเรียนการสอน, ปฏิบัติงานสำนักงาน..."></textarea>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2 px-1">
            <h6 class="fw-bold text-dark mb-0">เลือกรายการพัสดุ</h6>
            <span class="text-muted extra-small">กรอกจำนวนที่ต้องการ</span>
        </div>
        
        <?php if(empty($supplies_grouped)): ?>
            <div class="text-center py-5 bento-box opacity-75">
                <i class="fa-solid fa-box-open fa-3x text-muted mb-2"></i>
                <h6 class="text-secondary fw-bold">ไม่มีวัสดุคงคลังที่พร้อมเบิก</h6>
            </div>
        <?php else: ?>
            <div class="accordion mb-4" id="suppliesAccordion">
                <?php $i=0; foreach ($supplies_grouped as $category => $items): $i++; ?>
                    <div class="accordion-item border-0 mb-2 rounded-4 shadow-sm overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold text-dark bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $i ?>">
                                <i class="fa-solid fa-tags text-purple me-2"></i> หมวด: <?= htmlspecialchars($category) ?>
                                <span class="badge bg-light text-dark ms-auto me-2 border"><?= count($items) ?></span>
                            </button>
                        </h2>
                        <div id="collapse<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#suppliesAccordion">
                            <div class="accordion-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($items as $item): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center p-3 border-light">
                                            <div style="width: 65%;">
                                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($item['item_name']) ?></div>
                                                <small class="text-muted">คงเหลือ: <?= $item['stock_qty'] ?> <?= $item['unit_name'] ?></small>
                                            </div>
                                            <div style="width: 30%;">
                                                <input type="number" name="request_qty[<?= $item['item_id'] ?>]" 
                                                       class="form-control text-center fw-bold text-purple" 
                                                       min="0" max="<?= $item['stock_qty'] ?>" 
                                                       placeholder="0">
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอเบิกพัสดุ
        </button>
    </form>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
