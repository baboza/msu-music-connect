<?php
session_start();
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ดึงข้อมูล Account สำหรับ Dropdown
$stmt_acc = $pdo->query("SELECT * FROM zoom_accounts ORDER BY id ASC");
$accounts = $stmt_acc->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $account_id = $_POST['zoom_account_id'];
    $title = trim($_POST['title']);
    $date = $_POST['booking_date'];
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];
    $user_id = $_SESSION['user_id'];

    $start_datetime = $date . ' ' . $start;
    $end_datetime = $date . ' ' . $end;

    // ตรวจสอบความถูกต้องเบื้องต้น
    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $_SESSION['msg'] = "เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น";
        $_SESSION['msg_type'] = "danger";
    } elseif (strtotime($start_datetime) < time()) {
        $_SESSION['msg'] = "ไม่สามารถจองเวลาย้อนหลังได้";
        $_SESSION['msg_type'] = "danger";
    } else {
        // ตรวจสอบการทับซ้อนของเวลา (Overlap Check)
        // new_start < existing_end AND new_end > existing_start
        $stmt_check = $pdo->prepare("
            SELECT COUNT(*) FROM zoom_bookings 
            WHERE zoom_account_id = ? 
            AND start_time < ? 
            AND end_time > ?
        ");
        $stmt_check->execute([$account_id, $end_datetime, $start_datetime]);
        $overlap_count = $stmt_check->fetchColumn();

        if ($overlap_count > 0) {
            $_SESSION['msg'] = "บัญชี Zoom นี้ถูกจองในช่วงเวลาดังกล่าวไปแล้ว กรุณาเลือกเวลาอื่น";
            $_SESSION['msg_type'] = "danger";
        } else {
            // บันทึกการจอง (Auto-Approve / First-come, First-served)
            $stmt_insert = $pdo->prepare("
                INSERT INTO zoom_bookings (zoom_account_id, user_id, title, start_time, end_time) 
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($stmt_insert->execute([$account_id, $user_id, $title, $start_datetime, $end_datetime])) {
                $_SESSION['msg'] = "จองคิวใช้งาน Zoom สำเร็จแล้ว!";
                $_SESSION['msg_type'] = "success";
                header("Location: index.php");
                exit();
            } else {
                $_SESSION['msg'] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
                $_SESSION['msg_type'] = "danger";
            }
        }
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-4 pb-2 mb-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-calendar-plus me-2 text-primary"></i> 
                จองเวลาใช้งาน Zoom
            </h1>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <?php if(isset($_SESSION['msg'])): 
        $alert_type = isset($_SESSION['msg_type']) && $_SESSION['msg_type'] == 'danger' ? 'danger' : 'success';
    ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <i class="fa-solid <?= $alert_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <strong><?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST">
                        
                        <div class="mb-4 pb-2 border-bottom">
                            <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-pen-to-square me-2"></i>รายละเอียดการจอง</h5>
                        </div>

                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label fw-bold text-dark">หัวข้อการประชุม <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control rounded-3" placeholder="ระบุหัวข้อหรือวิชาที่จะใช้สอน" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold text-dark">เลือกบัญชี Zoom <span class="text-danger">*</span></label>
                                <select name="zoom_account_id" class="form-select rounded-3" required>
                                    <option value="" disabled selected>-- กรุณาเลือกบัญชี --</option>
                                    <?php foreach($accounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">วันที่ใช้งาน <span class="text-danger">*</span></label>
                                <input type="date" name="booking_date" class="form-control rounded-3" required min="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">เวลาเริ่ม <span class="text-danger">*</span></label>
                                <input type="time" name="start_time" class="form-control rounded-3" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark">เวลาสิ้นสุด <span class="text-danger">*</span></label>
                                <input type="time" name="end_time" class="form-control rounded-3" required>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <button type="submit" class="btn btn-primary px-5 py-3 rounded-pill fw-bold shadow-sm w-100 w-md-auto">
                                <i class="fa-solid fa-cloud-arrow-up me-2"></i> ยืนยันการจองสิทธิ์
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>
