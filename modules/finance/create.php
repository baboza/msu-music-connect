<?php
// เปิด Error สำหรับการพัฒนา
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. เช็คสิทธิ์เฉพาะ Admin และ งานการเงิน
$can_create = hasRole(['admin', 'งานการเงิน']);
if (!$can_create) {
    echo "<script>alert('สิทธิ์ไม่เพียงพอ'); window.location.href='index.php';</script>";
    exit();
}

// 2. ดึงปีงบประมาณที่ Admin ตั้งค่า Active ไว้
$stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy = $stmt_fy->fetchColumn();

// ถ้าไม่ได้ตั้งค่าปีงบไว้ ให้ใช้ปีปัจจุบัน + 543 (กันระบบพัง)
if (!$active_fy) {
    $active_fy = date('Y') + 543;
}

// 3. ดึงรายชื่อบุคลากร
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name ASC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// 4. จัดการเมื่อมีการกดบันทึก
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $owner_id = $_POST['user_id'];
        $fiscal_year = $_POST['fiscal_year']; // รับจากค่าที่ดึงมา
        $req_type = $_POST['req_type'];
        $title = trim($_POST['title']);
        $amount = $_POST['amount'];
        $payee_name = trim($_POST['payee_name']);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $initial_status = 'received';

        // บันทึกลงตารางหลัก (เพิ่ม fiscal_year)
        $stmt = $pdo->prepare("INSERT INTO finance_requests (user_id, fiscal_year, req_type, title, amount, payee_name, due_date, current_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$owner_id, $fiscal_year, $req_type, $title, $amount, $payee_name, $due_date, $initial_status]);
        
        $req_id = $pdo->lastInsertId();

        // บันทึก Log
        $stmt_log = $pdo->prepare("INSERT INTO finance_status_history (req_id, status, note, updated_by) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$req_id, $initial_status, "ลงรับเรื่องในระบบปีงบประมาณ $fiscal_year", $_SESSION['user_id']]);

        $pdo->commit();
        echo "<script>alert('เพิ่มรายการปีงบประมาณ $fiscal_year เรียบร้อยแล้ว'); window.location.href='index.php';</script>";
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
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-file-invoice-dollar me-2"></i>สร้างรายการงานการเงิน</h1>
            <p class="text-muted small mb-0">บันทึกข้อมูลและติดตามสถานะการเบิกจ่ายประจำปีงบประมาณ</p>
        </div>
        <div>
            <span class="badge bg-dark rounded-pill px-3 py-2 fs-6 shadow-sm">
                <i class="fa-solid fa-calendar-check me-1 text-warning"></i> ปีงบประมาณ: <?php echo $active_fy; ?>
            </span>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST" id="financeForm">
                        <input type="hidden" name="fiscal_year" value="<?php echo $active_fy; ?>">

                        <div class="row g-4">
                            <div class="col-md-12">
                                <label class="form-label fw-bold text-purple">บุคลากรผู้เจ้าของเรื่อง <span class="text-danger">*</span></label>
                                <select name="user_id" class="form-select border-secondary shadow-sm" required>
                                    <option value="">-- ค้นหารายชื่อ --</option>
                                    <?php foreach($users as $u): ?>
                                        <option value="<?php echo $u['user_id']; ?>">
                                            <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">ประเภทรายการ <span class="text-danger">*</span></label>
                                <select name="req_type" id="req_type" class="form-select border-secondary shadow-sm" required onchange="toggleDueDate()">
                                    <option value="reimbursement">เบิกค่าใช้จ่ายทั่วไป</option>
                                    <option value="advance">ลูกหนี้เงินยืม (Cash Advance)</option>
                                    <option value="clear_advance">หักล้างเงินยืม</option>
                                    <option value="teaching">ค่าสอนอาจารย์พิเศษ</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">จำนวนเงิน (บาท) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control border-secondary shadow-sm" step="0.01" placeholder="0.00" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-bold text-dark small">รายการ / โครงการ <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control border-secondary shadow-sm" placeholder="เช่น ค่าสอนวิชา..., ยืมเงินโครงการ..." required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">ชื่อผู้รับเงิน (ถ้ามี)</label>
                                <input type="text" name="payee_name" class="form-control border-secondary shadow-sm" placeholder="ระบุชื่อบุคคลภายนอก/บริษัท">
                            </div>

                            <div class="col-md-6" id="due_date_section" style="display: none;">
                                <label class="form-label fw-bold text-danger small">กำหนดส่งใบสำคัญ (เคลียร์เงิน)</label>
                                <input type="date" name="due_date" class="form-control border-danger shadow-sm">
                            </div>

                            <div class="col-md-12 mt-5 border-top pt-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="index.php" class="btn btn-light px-4 rounded-pill">ยกเลิก</a>
                                    <button type="submit" class="btn btn-purple px-5 rounded-pill fw-bold shadow-sm hover-lift">
                                        <i class="fa-solid fa-save me-1"></i> บันทึกรายการ
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    function toggleDueDate() {
        const type = document.getElementById('req_type').value;
        const section = document.getElementById('due_date_section');
        if (type === 'advance') {
            section.style.display = 'block';
            const date = new Date();
            date.setDate(date.getDate() + 30);
            document.getElementsByName('due_date')[0].valueAsDate = date;
        } else {
            section.style.display = 'none';
        }
    }
    window.onload = toggleDueDate;
</script>

<?php require_once '../../includes/footer.php'; ?>