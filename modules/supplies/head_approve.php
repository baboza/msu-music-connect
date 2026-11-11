<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (ให้เฉพาะหัวหน้าสำนักงาน และ Admin เข้าถึงได้)
if (!hasRole(['admin', 'หัวหน้าสำนักงาน'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: ท่านไม่มีสิทธิ์อนุมัติใบเบิกพัสดุ";
    $_SESSION['msg_type'] = "danger";
    header("Location: ../../index.php");
    exit();
}

$error = "";

// 2. จัดการเมื่อหัวหน้ากดปุ่ม อนุมัติ / ไม่อนุมัติ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' หรือ 'reject'
    $remark = trim($_POST['remark'] ?? '');
    $approver_id = $_SESSION['user_id'];

    $status = ($action == 'approve') ? 'approved' : 'rejected';

    try {
        $stmt = $pdo->prepare("UPDATE supplies_requests 
                               SET head_status = ?, head_approver_id = ?, head_approve_date = NOW(), head_remark = ? 
                               WHERE request_id = ?");
        $stmt->execute([$status, $approver_id, $remark, $request_id]);

        if ($status == 'approved') {
            $_SESSION['msg'] = "อนุมัติคำขอเบิกเรียบร้อยแล้ว (บิลถูกส่งต่อให้งานพัสดุ)";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg'] = "ปฏิเสธคำขอเบิกเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "warning";
        }
        
        header("Location: head_approve.php");
        exit();
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// 3. ดึงรายการบิลที่ "รอหัวหน้าอนุมัติ" (head_status = 'pending')
// เพิ่มการดึง fiscal_year และ request_reason เข้ามาด้วย
$stmt_requests = $pdo->query("
    SELECT r.request_id, r.request_date, r.request_reason, r.fiscal_year, u.first_name, u.last_name, u.email 
    FROM supplies_requests r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.head_status = 'pending'
    ORDER BY r.request_date ASC
");
$pending_requests = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-clipboard-check me-2"></i>อนุมัติการเบิกวัสดุ (หัวหน้าสำนักงาน)</h1>
        <p class="text-muted">รายการคำขอเบิกวัสดุอุปกรณ์สำนักงานที่รอการพิจารณาความจำเป็น (Step 1)</p>
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

    <?php if (empty($pending_requests)): ?>
        <div class="text-center py-5 bg-light rounded-4 border border-dashed">
            <i class="fa-solid fa-inbox fa-3x text-muted mb-3 opacity-50"></i>
            <h5 class="fw-bold text-secondary">ไม่มีรายการรออนุมัติ</h5>
            <p class="text-muted small">ขณะนี้ไม่มีคำขอเบิกพัสดุที่ต้องดำเนินการ</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($pending_requests as $req): ?>
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1 me-2">รออนุมัติ</span>
                            <strong class="text-dark">เลขที่คำขอ: #<?php echo str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?> / <?php echo $req['fiscal_year']; ?></strong>
                        </div>
                        <small class="text-muted"><i class="fa-regular fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($req['request_date'])); ?></small>
                    </div>
                    <div class="card-body bg-light p-4">
                        <div class="row">
                            <div class="col-md-5 border-end pe-4">
                                <h6 class="fw-bold text-purple mb-3"><i class="fa-solid fa-user-circle me-2"></i> ข้อมูลผู้ขอเบิก</h6>
                                <p class="mb-1 fw-bold"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></p>
                                <p class="mb-4 small text-muted"><i class="fa-solid fa-envelope me-2"></i> <?php echo htmlspecialchars($req['email']); ?></p>
                                
                                <div class="bg-white p-3 rounded-3 border shadow-sm">
                                    <h6 class="fw-bold text-purple small mb-2"><i class="fa-solid fa-circle-info me-1"></i> เหตุผล/ความจำเป็นในการขอเบิก</h6>
                                    <p class="mb-0 small text-dark lh-base">
                                        <?php echo nl2br(htmlspecialchars($req['request_reason'] ?? '-')); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="col-md-7 ps-4">
                                <h6 class="fw-bold text-purple mb-3"><i class="fa-solid fa-list-ul me-2"></i> รายการที่ขอเบิก</h6>
                                <ul class="list-group list-group-flush border rounded-3 overflow-hidden shadow-sm">
                                    <?php 
                                    // ดึงรายการของในบิลนี้
                                    $stmt_details = $pdo->prepare("
                                        SELECT d.request_qty, i.item_name, i.unit_name, i.stock_qty
                                        FROM supplies_request_details d
                                        JOIN supplies_items i ON d.item_id = i.item_id
                                        WHERE d.request_id = ?
                                    ");
                                    $stmt_details->execute([$req['request_id']]);
                                    $details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($details as $item):
                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-white">
                                        <span class="small"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                        <div>
                                            <span class="fw-bold text-dark"><?php echo $item['request_qty']; ?></span> 
                                            <small class="text-muted"><?php echo htmlspecialchars($item['unit_name']); ?></small>
                                            <small class="ms-2 badge bg-light text-secondary border fw-normal">คลัง: <?php echo $item['stock_qty']; ?></small>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0 py-3 text-end">
                        <button type="button" class="btn btn-outline-danger rounded-pill px-4 fw-bold me-2" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $req['request_id']; ?>">ไม่อนุมัติ</button>
                        
                        <form action="" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการอนุมัติใบเบิกพัสดุรายการนี้ เพื่อส่งต่อให้งานพัสดุ?');">
                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm"><i class="fa-solid fa-check me-2"></i>อนุมัติให้เบิกได้</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="rejectModal<?php echo $req['request_id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <div class="modal-header bg-danger text-white border-0 py-3 rounded-top-4">
                            <h5 class="modal-title fw-bold"><i class="fa-solid fa-ban me-2"></i>ระบุเหตุผลที่ไม่อนุมัติ</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form action="" method="POST">
                            <div class="modal-body p-4">
                                <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <label class="form-label fw-bold small">เหตุผลประกอบการปฏิเสธ (เพื่อแจ้งให้ผู้ขอทราบ)</label>
                                <textarea name="remark" class="form-control" rows="3" required placeholder="เช่น ไม่พบความจำเป็น, ขอเบิกซ้ำซ้อน ฯลฯ"></textarea>
                            </div>
                            <div class="modal-footer border-0 bg-light rounded-bottom-4">
                                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">ยืนยันการปฏิเสธ</button>
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
    .lh-base { line-height: 1.6; }
</style>

<?php require_once '../../includes/footer.php'; ?>