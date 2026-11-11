<?php
// เปิด Error สำหรับการพัฒนา
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// 1. เช็คสิทธิ์ (เฉพาะ Admin และ งานการเงิน เท่านั้น)
if (!hasRole(['admin', 'งานการเงิน'])) {
    echo "<script>alert('สิทธิ์ไม่เพียงพอ: หน้าจอนี้สำหรับเจ้าหน้าที่งานการเงินเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$req_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 2. ดึงข้อมูลปัจจุบัน
$stmt = $pdo->prepare("SELECT f.*, u.first_name, u.last_name FROM finance_requests f JOIN users u ON f.user_id = u.user_id WHERE f.req_id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo "<script>alert('ไม่พบข้อมูลรายการ'); window.location.href='index.php';</script>";
    exit();
}

// 3. จัดการเมื่อมีการอัปเดต (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $new_status = $_POST['status'];
        $note = trim($_POST['note']);
        
        // รับค่า due_date และ cleared_date เข้ามาด้วย
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $cleared_date = !empty($_POST['cleared_date']) ? $_POST['cleared_date'] : null;

        // A. อัปเดตสถานะ, วันครบกำหนด, และวันที่ส่งเอกสารล้างหนี้ ที่ตารางหลัก
        $stmt_upd = $pdo->prepare("UPDATE finance_requests SET current_status = ?, due_date = ?, cleared_date = ? WHERE req_id = ?");
        $stmt_upd->execute([$new_status, $due_date, $cleared_date, $req_id]);

        // B. บันทึกประวัติลงตาราง History
        $stmt_log = $pdo->prepare("INSERT INTO finance_status_history (req_id, status, note, updated_by) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$req_id, $new_status, $note, $user_id]);

        $pdo->commit();
        echo "<script>alert('อัปเดตสถานะและข้อมูลการเงินเรียบร้อยแล้ว'); window.location.href='view.php?id=$req_id';</script>";
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-purple mb-1"><i class="fa-solid fa-pen-to-square me-2"></i>อัปเดตสถานะงานการเงิน</h1>
            <p class="text-muted small mb-0">รหัสอ้างอิง: #FIN-<?php echo str_pad($req['req_id'], 5, '0', STR_PAD_LEFT); ?></p>
        </div>
        <a href="view.php?id=<?php echo $req_id; ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="fa-solid fa-arrow-left me-1"></i> ยกเลิก
        </a>
    </div>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo $error_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-xl-6">
            
            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-muted small text-uppercase mb-3">สรุปรายการ</h6>
                    <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($req['title']); ?></h5>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div>
                            <span class="text-muted small">โดย: <?php echo htmlspecialchars($req['first_name'].' '.$req['last_name']); ?></span>
                            <?php if($req['req_type'] == 'advance'): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning ms-2 px-2">เงินยืม</span>
                            <?php elseif($req['req_type'] == 'clear_advance'): ?>
                                <span class="badge bg-success-subtle text-success border border-success ms-2 px-2">หักล้างเงินยืม</span>
                            <?php endif; ?>
                        </div>
                        <span class="fs-5 fw-bold text-primary">฿<?php echo number_format($req['amount'], 2); ?></span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">เปลี่ยนสถานะเป็น <span class="text-danger">*</span></label>
                            <select name="status" id="req_status" class="form-select form-select-lg border-primary shadow-sm" required onchange="checkStatus()">
                                <option value="received" <?php echo $req['current_status'] == 'received' ? 'selected' : ''; ?>>1. อยู่คณะ / รับเรื่องแล้ว</option>
                                <option value="verified" <?php echo $req['current_status'] == 'verified' ? 'selected' : ''; ?>>2. ตรวจสอบเอกสารแล้ว</option>
                                <option value="forwarded" <?php echo $req['current_status'] == 'forwarded' ? 'selected' : ''; ?>>3. ส่งกองคลัง มหาวิทยาลัย</option>
                                <option value="approved" <?php echo $req['current_status'] == 'approved' ? 'selected' : ''; ?>>4. มหาลัยอนุมัติ / สั่งจ่าย</option>
                                <option value="paid" <?php echo $req['current_status'] == 'paid' ? 'selected' : ''; ?>>5. โอนเงิน / เคลียร์ลูกหนี้สำเร็จ</option>
                            </select>
                        </div>

                        <?php if($req['req_type'] == 'advance'): ?>
                            <div class="row g-3 mb-4" id="due_date_section">
                                <div class="col-md-6">
                                    <div class="p-3 bg-warning-subtle border border-warning rounded-3 h-100">
                                        <label class="form-label fw-bold text-dark small"><i class="fa-regular fa-calendar text-warning me-1"></i> วันกำหนดส่งใบสำคัญ</label>
                                        <input type="date" name="due_date" id="due_date_input" class="form-control border-warning shadow-sm" value="<?php echo htmlspecialchars($req['due_date'] ?? ''); ?>">
                                        <div class="form-text extra-small text-dark mt-1">กำหนดแจ้งเตือนเคลียร์เงิน</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-success-subtle border border-success rounded-3 h-100">
                                        <label class="form-label fw-bold text-dark small"><i class="fa-solid fa-calendar-check text-success me-1"></i> วันที่ส่งเอกสาร/ล้างหนี้</label>
                                        <input type="date" name="cleared_date" id="cleared_date_input" class="form-control border-success shadow-sm" value="<?php echo htmlspecialchars($req['cleared_date'] ?? ''); ?>">
                                        <div class="form-text extra-small text-dark mt-1">วันที่รับเอกสารเคลียร์จริง</div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">บันทึกหมายเหตุเพิ่มเติม (Note)</label>
                            <textarea name="note" class="form-control border-secondary shadow-sm" rows="4" placeholder="เช่น ส่งกองคลังแล้ว เลขที่รับ 0XXX/69, คาดว่าเงินเข้าบัญชีวันที่..., ตรวจพบเอกสารไม่ครบกรุณาติดต่องานการเงิน..."></textarea>
                            <div class="form-text extra-small mt-2">ข้อความนี้จะโชว์ให้เจ้าของเรื่องเห็นในหน้าประวัติการติดตาม</div>
                        </div>

                        <div class="alert alert-info border-0 rounded-4 p-3 mb-4">
                            <div class="d-flex">
                                <i class="fa-solid fa-circle-info fs-4 me-3"></i>
                                <p class="small mb-0"><strong>คำแนะนำ:</strong> เมื่อกดบันทึก ระบบจะส่งสถานะล่าสุดไปยังหน้า Dashboard ของบุคลากรทันที เพื่อความโปร่งใสและลดขั้นตอนการโทรสอบถามครับ</p>
                            </div>
                        </div>

                        <hr class="my-4 opacity-25">

                        <button type="submit" class="btn btn-purple btn-lg w-100 rounded-pill fw-bold shadow hover-lift">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> ยืนยันการอัปเดตสถานะ
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; }
    .btn-purple { background-color: var(--msu-purple); color: white; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .extra-small { font-size: 0.75rem; }
    .hover-lift { transition: transform 0.2s; }
    .hover-lift:hover { transform: translateY(-3px); }
</style>

<script>
    // ฟังก์ชันจัดการฟอร์มเมื่อเปลี่ยนสถานะ
    function checkStatus() {
        const status = document.getElementById('req_status').value;
        const dueInput = document.getElementById('due_date_input');
        const clearedInput = document.getElementById('cleared_date_input');
        
        // ถ้าต้องการบังคับให้ใส่วันล้างหนี้เมื่อกด paid สามารถเปิดใช้โค้ดส่วนนี้ได้
        /*
        if (clearedInput) {
            if (status === 'paid') {
                clearedInput.setAttribute('required', 'required');
            } else {
                clearedInput.removeAttribute('required');
            }
        }
        */
    }
    
    // รันตอนเปิดหน้าเว็บครั้งแรกเพื่อเช็คสถานะ
    window.onload = checkStatus;
</script>

<?php require_once '../../includes/footer.php'; ?>