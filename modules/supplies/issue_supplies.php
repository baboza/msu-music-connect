<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (ให้เฉพาะ งานพัสดุ และ Admin เข้าถึงได้)
if (!hasRole(['admin', 'งานพัสดุ'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: ท่านไม่มีสิทธิ์ในส่วนของงานพัสดุ";
    $_SESSION['msg_type'] = "danger";
    header("Location: ../../index.php");
    exit();
}

$error = "";

// 2. จัดการเมื่อพัสดุกดปุ่ม จ่ายของ / ยกเลิกคำขอ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'issue' หรือ 'reject'
    $issuer_id = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        if ($action == 'issue' && isset($_POST['issued_qty'])) {
            $issued_items = $_POST['issued_qty']; // Array ของ [detail_id => จำนวนที่จ่ายจริง]

            // วนลูปอัปเดตยอดจ่ายจริง และตัดสต็อก
            foreach ($issued_items as $detail_id => $qty) {
                $qty = (int)$qty;
                
                // ดึงข้อมูลไอเทมเพื่อไปตัดสต็อก
                $stmt_get_item = $pdo->prepare("SELECT item_id FROM supplies_request_details WHERE detail_id = ?");
                $stmt_get_item->execute([$detail_id]);
                $item_id = $stmt_get_item->fetchColumn();

                if ($item_id && $qty > 0) {
                    // อัปเดตยอดที่จ่ายในบิล
                    $stmt_update_detail = $pdo->prepare("UPDATE supplies_request_details SET issued_qty = ? WHERE detail_id = ?");
                    $stmt_update_detail->execute([$qty, $detail_id]);

                    // *** ตัดยอดสต็อกในคลัง ***
                    $stmt_cut_stock = $pdo->prepare("UPDATE supplies_items SET stock_qty = stock_qty - ? WHERE item_id = ? AND stock_qty >= ?");
                    $stmt_cut_stock->execute([$qty, $item_id, $qty]);
                    
                    if ($stmt_cut_stock->rowCount() == 0) {
                        throw new Exception("สต็อกวัสดุบางรายการไม่เพียงพอสำหรับการจ่าย กรุณาตรวจสอบอีกครั้ง");
                    }
                } else {
                    // ถ้าจ่าย 0 ชิ้นให้อัปเดตเป็น 0
                    $stmt_update_detail = $pdo->prepare("UPDATE supplies_request_details SET issued_qty = 0 WHERE detail_id = ?");
                    $stmt_update_detail->execute([$detail_id]);
                }
            }

            // อัปเดตสถานะหัวบิลว่า จ่ายของแล้ว
            $stmt_update_head = $pdo->prepare("UPDATE supplies_requests SET supply_status = 'issued', supply_issuer_id = ?, issue_date = NOW() WHERE request_id = ?");
            $stmt_update_head->execute([$issuer_id, $request_id]);

            $_SESSION['msg'] = "บันทึกการจ่ายพัสดุและตัดสต็อกเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";

        } elseif ($action == 'reject') {
            $remark = trim($_POST['remark']);
            // ถ้ายกเลิกคำขอ ไม่อนุมัติจ่ายของ
            $stmt_reject = $pdo->prepare("UPDATE supplies_requests SET supply_status = 'rejected', supply_issuer_id = ?, issue_date = NOW(), supply_remark = ? WHERE request_id = ?");
            $stmt_reject->execute([$issuer_id, $remark, $request_id]);
            
            $_SESSION['msg'] = "ยกเลิกคำขอเบิกเรียบร้อยแล้ว (ไม่ตัดสต็อก)";
            $_SESSION['msg_type'] = "warning";
        }

        $pdo->commit();
        header("Location: issue_supplies.php");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// 3. ดึงรายการบิลที่ "หัวหน้าอนุมัติแล้ว" แต่ "พัสดุยังไม่ได้จ่ายของ"
// เพิ่มการดึง r.fiscal_year มาด้วย
$stmt_requests = $pdo->query("
    SELECT r.request_id, r.request_date, r.head_approve_date, r.fiscal_year, u.first_name, u.last_name, u.email 
    FROM supplies_requests r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.head_status = 'approved' AND r.supply_status = 'pending'
    ORDER BY r.head_approve_date ASC
");
$pending_issues = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-truck-ramp-box me-2"></i>จ่ายพัสดุและตัดสต็อก (งานพัสดุ)</h1>
        <p class="text-muted">รายการที่ผ่านการอนุมัติจากหัวหน้าสำนักงานแล้ว รอการจัดเตรียมและจ่ายของ (Step 2)</p>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm border-0 border-start border-<?php echo $_SESSION['msg_type']; ?> border-4">
            <?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (empty($pending_issues)): ?>
        <div class="text-center py-5 bg-light rounded-4 border border-dashed">
            <i class="fa-solid fa-box-open fa-3x text-muted mb-3 opacity-50"></i>
            <h5 class="fw-bold text-secondary">ไม่มีรายการรอจ่ายพัสดุ</h5>
            <p class="text-muted small">ขณะนี้ไม่มีคำขอเบิกพัสดุที่ต้องจัดเตรียม</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($pending_issues as $req): ?>
            <div class="col-12">
                <form action="" method="POST" class="card shadow-sm border-0 rounded-4 overflow-hidden" onsubmit="return confirm('ยืนยันการบันทึกจ่ายของ? ระบบจะทำการตัดสต็อกทันทีและไม่สามารถแก้ไขได้');">
                    <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                    <input type="hidden" name="action" value="issue">
                    
                    <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1 me-2"><i class="fa-solid fa-check me-1"></i>หัวหน้าอนุมัติแล้ว</span>
                            <strong class="text-dark">เลขที่คำขอ: #<?php echo str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?> / <?php echo $req['fiscal_year']; ?></strong>
                        </div>
                        <small class="text-muted"><i class="fa-solid fa-check-double me-1"></i> อนุมัติเมื่อ: <?php echo date('d/m/Y H:i', strtotime($req['head_approve_date'])); ?></small>
                    </div>
                    
                    <div class="card-body bg-light p-4">
                        <div class="row mb-3">
                            <div class="col-12">
                                <h6 class="fw-bold text-purple"><i class="fa-solid fa-user-circle me-2"></i> ข้อมูลผู้ขอเบิก: <span class="text-dark"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></span></h6>
                            </div>
                        </div>
                        
                        <div class="table-responsive bg-white rounded-3 border">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-muted">
                                    <tr>
                                        <th class="ps-3" width="40%">รายการวัสดุ</th>
                                        <th class="text-center" width="20%">ยอดคงคลัง</th>
                                        <th class="text-center" width="20%">ยอดที่ขอเบิก</th>
                                        <th class="pe-3" width="20%">ยอดที่จ่ายจริง (ปรับแก้ได้)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $stmt_details = $pdo->prepare("
                                        SELECT d.detail_id, d.request_qty, i.item_name, i.unit_name, i.stock_qty
                                        FROM supplies_request_details d
                                        JOIN supplies_items i ON d.item_id = i.item_id
                                        WHERE d.request_id = ?
                                    ");
                                    $stmt_details->execute([$req['request_id']]);
                                    $details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($details as $item):
                                        // ป้องกันไม่ให้จ่ายเกินสต็อกที่มี
                                        $max_issue = min($item['request_qty'], $item['stock_qty']);
                                    ?>
                                    <tr>
                                        <td class="ps-3 fw-bold small"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td class="text-center"><span class="badge bg-secondary"><?php echo $item['stock_qty']; ?> <?php echo htmlspecialchars($item['unit_name']); ?></span></td>
                                        <td class="text-center fw-bold text-primary"><?php echo $item['request_qty']; ?> <?php echo htmlspecialchars($item['unit_name']); ?></td>
                                        <td class="pe-3">
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="issued_qty[<?php echo $item['detail_id']; ?>]" class="form-control text-center fw-bold text-success border-success" value="<?php echo $max_issue; ?>" min="0" max="<?php echo $item['stock_qty']; ?>">
                                                <span class="input-group-text bg-success-subtle text-success border-success border-start-0 small"><?php echo htmlspecialchars($item['unit_name']); ?></span>
                                            </div>
                                            <?php if($item['stock_qty'] < $item['request_qty']): ?>
                                                <div class="text-danger extra-small mt-1"><i class="fa-solid fa-circle-exclamation"></i> ของไม่พอจ่าย</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="card-footer bg-white border-top-0 py-3 text-end">
                        <button type="button" class="btn btn-outline-danger rounded-pill px-4 fw-bold me-2" data-bs-toggle="modal" data-bs-target="#rejectSupplyModal<?php echo $req['request_id']; ?>">ยกเลิกการจ่ายของ</button>
                        <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm"><i class="fa-solid fa-box-open me-2"></i> ยืนยันจ่ายของ & ตัดสต็อก</button>
                    </div>
                </form>
            </div>

            <div class="modal fade" id="rejectSupplyModal<?php echo $req['request_id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <div class="modal-header bg-danger text-white border-0 py-3 rounded-top-4">
                            <h5 class="modal-title fw-bold"><i class="fa-solid fa-ban me-2"></i>ระบุเหตุผลที่ยกเลิกการจ่ายของ</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form action="" method="POST">
                            <div class="modal-body p-4">
                                <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <label class="form-label fw-bold small">เหตุผลประกอบการยกเลิก (เพื่อแจ้งให้ผู้ขอทราบ)</label>
                                <textarea name="remark" class="form-control" rows="3" required placeholder="เช่น ของหมดสต็อกชั่วคราว, รอการจัดซื้อใหม่ ฯลฯ"></textarea>
                            </div>
                            <div class="modal-footer border-0 bg-light rounded-bottom-4">
                                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">ยืนยันการยกเลิก</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<style>
    .text-purple { color: #39048f; }
    .border-dashed { border: 2px dashed #dee2e6 !important; }
    .extra-small { font-size: 0.7rem; }
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type="number"] { -moz-appearance: textfield; }
</style>

<?php require_once '../../includes/footer.php'; ?>