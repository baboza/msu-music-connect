<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$error = "";

// -------------------------------------------------------------
// ดึง "ปีงบประมาณปัจจุบัน" ตามโครงสร้างตารางของอาจารย์ (year_name, is_active)
// -------------------------------------------------------------
$stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy = $stmt_fy->fetchColumn();

// ถ้าใน DB ไม่มีการตั้งค่า Active ไว้ ให้ดึงปีปัจจุบัน (พ.ศ.) มาแก้ขัดก่อน
if (!$active_fy) { 
    $active_fy = date('Y') + 543; 
} 

// เมื่อมีการกดปุ่ม "ส่งคำขอเบิก"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_qty'])) {
    $requested_items = $_POST['request_qty']; 
    $request_reason = trim($_POST['request_reason']); 
    
    $has_items = false;
    foreach ($requested_items as $qty) {
        if ((int)$qty > 0) { $has_items = true; break; }
    }

    if (!$has_items) {
        $error = "กรุณาระบุจำนวนวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ";
    } elseif (empty($request_reason)) {
        $error = "กรุณาระบุเหตุผลหรือรายละเอียดการนำไปใช้งาน";
    } else {
        try {
            $pdo->beginTransaction();

            // บันทึกคำขอเบิกโดยใช้ปีงบประมาณจากระบบตั้งค่า ($active_fy)
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
            
            $_SESSION['msg'] = "ส่งใบขอเบิกพัสดุ (ปีงบประมาณ $active_fy) เรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
            
            header("Location: index.php"); 
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// ดึงรายการของในคลัง
$stmt_items = $pdo->query("SELECT category, item_id, item_name, stock_qty, unit_name FROM supplies_items WHERE is_active = 1 AND stock_qty > 0 ORDER BY category, item_name");
$supplies_grouped = $stmt_items->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-box-open me-2"></i>เบิกวัสดุสำนักงาน</h1>
        <p class="text-muted">เลือกรายการวัสดุ ประจำปีงบประมาณ <span class="badge bg-purple-light text-purple fs-6"><?php echo htmlspecialchars($active_fy); ?></span></p>
    </div>

    <?php if($error): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form action="" method="POST">
        <div class="row g-4">
            <div class="col-lg-8">
                <?php if (empty($supplies_grouped)): ?>
                    <div class="text-center py-5 bg-light rounded-4 border border-dashed">
                        <i class="fa-solid fa-box-open fa-3x text-muted mb-3 opacity-50"></i>
                        <h5 class="fw-bold text-secondary">ไม่มีวัสดุคงคลัง</h5>
                        <p class="text-muted small">ขณะนี้ไม่มีวัสดุสำนักงานที่สามารถเบิกได้</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($supplies_grouped as $category => $items): ?>
                    <div class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-bottom-0 fw-bold text-purple">
                            <i class="fa-solid fa-tags me-2 text-warning"></i> หมวดหมู่: <?php echo htmlspecialchars($category); ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light small text-muted">
                                        <tr>
                                            <th class="ps-4" width="50%">รายการวัสดุ</th>
                                            <th class="text-center" width="20%">คงเหลือ</th>
                                            <th width="30%">ระบุจำนวนที่เบิก</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <?php echo $item['stock_qty']; ?> <?php echo htmlspecialchars($item['unit_name']); ?>
                                                </span>
                                            </td>
                                            <td class="pe-4">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="request_qty[<?php echo $item['item_id']; ?>]" class="form-control text-center fw-bold border-purple" value="0" min="0" max="<?php echo $item['stock_qty']; ?>">
                                                    <span class="input-group-text bg-light text-muted border-purple border-start-0"><?php echo htmlspecialchars($item['unit_name']); ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-0 border-top border-purple border-4 sticky-top" style="top: 20px;">
                    <div class="card-body p-4 bg-light rounded-bottom-4">
                        <h6 class="fw-bold text-purple mb-3"><i class="fa-solid fa-clipboard-list me-2"></i>ยืนยันการเบิกพัสดุ</h6>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold small text-dark">เหตุผล / ความจำเป็น <span class="text-danger">*</span></label>
                            <textarea name="request_reason" class="form-control border-purple" rows="3" required placeholder="ระบุวัตถุประสงค์การนำไปใช้งาน..."></textarea>
                        </div>

                        <p class="small text-muted mb-4 border-top pt-3">
                            ใบเบิกนี้จะถูกส่งไปยัง <strong>หัวหน้าสำนักงาน</strong> เพื่อพิจารณาอนุมัติในลำดับถัดไป
                        </p>
                        
                        <button type="submit" class="btn btn-purple w-100 shadow rounded-pill fw-bold py-2 mb-2" <?php echo empty($supplies_grouped) ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอเบิกวัสดุ
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary w-100 rounded-pill fw-bold py-2">ยกเลิก</a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<style>
    .text-purple { color: #39048f; }
    .bg-purple-light { background-color: #f3e5f5; }
    .btn-purple { background-color: #39048f; color: white; transition: all 0.2s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); }
    .border-purple { border-color: #e0d4eb !important; }
    .form-control:focus { border-color: #39048f; box-shadow: 0 0 0 0.25rem rgba(57, 4, 143,0.25); }
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type="number"] { -moz-appearance: textfield; }
</style>

<?php require_once '../../includes/footer.php'; ?>