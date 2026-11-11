<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$leave_id = $_REQUEST['id'] ?? ($_POST['leave_id'] ?? '');

if (empty($leave_id)) {
    header("Location: index.php");
    exit();
}

// 1. ดึงข้อมูลใบลาเพื่อตรวจสอบ
$stmt = $pdo->prepare("
    SELECT l.*, t.type_name 
    FROM leave_requests l 
    JOIN leave_types t ON l.type_id = t.type_id 
    WHERE l.leave_id = ? AND l.user_id = ?
");
$stmt->execute([$leave_id, $user_id]);
$leave = $stmt->fetch();

if (!$leave) {
    echo "<script>alert('ไม่พบข้อมูลใบลา'); window.location.href='index.php';</script>";
    exit();
}

/**
 * เงื่อนไขการลบ:
 * - สถานะต้องเป็น 'pending'
 * - current_step ต้องเป็น 1 (แปลว่างานบุคคลยังไม่ได้เซ็นรับเรื่องหรือดำเนินการใดๆ)
 */
$is_deletable = ($leave['status'] == 'pending' && $leave['current_step'] == 1);

// 2. ประมวลผลการลบ (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'do_delete') {
    if ($is_deletable) {
        try {
            $pdo->beginTransaction();
            
            // ใช้คำสั่ง DELETE เพื่อลบออกจากฐานข้อมูลเลยตามต้องการ
            $stmt_delete = $pdo->prepare("DELETE FROM leave_requests WHERE leave_id = ? AND user_id = ?");
            $stmt_delete->execute([$leave_id, $user_id]);
            
            $pdo->commit();
            echo "<script>alert('ลบข้อมูลใบลาออกจากระบบเรียบร้อยแล้ว'); window.location.href='index.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<script>alert('เกิดข้อผิดพลาด: " . addslashes($e->getMessage()) . "');</script>";
        }
    } else {
        echo "<script>alert('ขออภัย ไม่สามารถลบได้เนื่องจากใบลาถูกดำเนินการไปแล้ว'); window.location.href='index.php';</script>";
        exit();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

function thaiDate($date) {
    if(!$date) return "-";
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $timestamp = strtotime($date);
    return date("j", $timestamp) . " " . $months[date("n", $timestamp)] . " " . (date("Y", $timestamp) + 543);
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-danger fw-bold"><i class="fa-solid fa-eraser me-2"></i>ลบรายการใบลา</h1>
        <p class="text-muted small">กรณีระบุข้อมูลผิดพลาดและต้องการลบทิ้งออกจากฐานข้อมูล</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 1.5rem;">
                <div class="card-header bg-dark text-white py-3 text-center border-0">
                    <h5 class="mb-0 fw-bold"><i class="fa-solid fa-circle-exclamation text-warning me-2"></i>ยืนยันการลบทิ้ง</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <div class="text-center mb-4">
                        <div class="extra-small text-muted text-uppercase mb-1">ประเภทการลา</div>
                        <div class="h3 fw-bold text-dark mb-1"><?php echo htmlspecialchars($leave['type_name']); ?></div>
                        <span class="badge bg-light text-muted border px-3 py-2 rounded-pill small">ID อ้างอิง: #<?php echo $leave['leave_id']; ?></span>
                    </div>

                    <div class="bg-light rounded-4 p-4 mb-4 border border-dashed text-center">
                        <div class="extra-small text-muted text-uppercase fw-bold mb-2">วันเริ่ม - สิ้นสุด</div>
                        <div class="fw-bold text-dark">
                            <?php echo thaiDate($leave['start_date']); ?> ถึง <?php echo thaiDate($leave['end_date']); ?>
                        </div>
                    </div>

                    <?php if ($is_deletable): ?>
                        <div class="alert alert-warning border-0 small mb-4">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i> 
                            <strong>คำเตือน:</strong> การกดปุ่มด้านล่างจะ <strong>ลบข้อมูลใบลาใบนี้ออกจากฐานข้อมูลถาวร</strong> ไม่สามารถกู้คืนได้
                        </div>

                        <form method="POST" action="cancel_leave.php?id=<?php echo $leave_id; ?>">
                            <input type="hidden" name="action" value="do_delete">
                            <input type="hidden" name="leave_id" value="<?php echo $leave_id; ?>">
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger btn-lg rounded-pill fw-bold shadow-sm py-3">
                                    <i class="fa-solid fa-trash-can me-2"></i> ลบข้อมูลออกจากฐานข้อมูล
                                </button>
                                <a href="index.php" class="btn btn-light rounded-pill py-2 text-muted">ยกเลิกและกลับหน้าเดิม</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-danger border-0 shadow-sm mb-4">
                            <i class="fa-solid fa-circle-xmark me-2"></i> 
                            <strong>ไม่สามารถลบได้:</strong> งานบุคลากรหรือฝ่ายบริหารได้ดำเนินการรับเรื่อง/เซ็นชื่อในใบลาใบนี้แล้ว 
                            กรุณาติดต่อเจ้าหน้าที่หากต้องการดำเนินการเพิ่มเติม
                        </div>
                        <div class="text-center">
                            <a href="index.php" class="btn btn-secondary rounded-pill px-5 fw-bold">กลับหน้าประวัติ</a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .extra-small { font-size: 0.75rem; }
    .border-dashed { border-style: dashed !important; border-width: 2px !important; }
    .rounded-4 { border-radius: 1.25rem !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>