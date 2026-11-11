<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$log_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// 💡 1. [เพิ่ม] ระบบประมวลผลเมื่อกดปุ่ม "จบงาน"
$success_msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'mark_done') {
    try {
        $stmt_done = $pdo->prepare("UPDATE work_logs SET is_completed = 1 WHERE log_id = ? AND user_id = ?");
        $stmt_done->execute([$log_id, $user_id]);
        $success_msg = "บันทึกการดำเนินงานเร่งด่วนเรียบร้อยแล้ว (งานนี้จะถูกนำออกจากหน้า Dashboard)";
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// 2. เช็คสิทธิ์การเข้าถึง (ใครคือผู้บริหารที่สามารถดูของทุกคนได้)
$exec_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
$is_executive = hasRole($exec_roles);

// 3. ดึงข้อมูลการบันทึกงาน (เพิ่มฟิลด์ is_completed และ urgency_level เข้ามาตรวจสอบ)
if ($is_executive) {
    $sql = "SELECT l.*, t.task_name, u.first_name, u.last_name 
            FROM work_logs l
            JOIN work_tasks t ON l.task_id = t.task_id
            LEFT JOIN users u ON l.user_id = u.user_id
            WHERE l.log_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$log_id]);
} else {
    $sql = "SELECT l.*, t.task_name, u.first_name, u.last_name 
            FROM work_logs l
            JOIN work_tasks t ON l.task_id = t.task_id
            LEFT JOIN users u ON l.user_id = u.user_id
            WHERE l.log_id = ? AND l.user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$log_id, $user_id]);
}

$log = $stmt->fetch();

if (!$log) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm border-0 rounded-4'><i class='fa-solid fa-triangle-exclamation me-2'></i>ไม่พบข้อมูลบันทึกงาน หรือคุณไม่มีสิทธิ์เข้าถึง</div></div>";
    exit();
}

// ฟังก์ชันแปลงคะแนนความสุขเป็น Icon พร้อมข้อความ
function getHarmonyDisplay($score) {
    switch ($score) {
        case 1: return '<i class="fa-solid fa-face-frown text-danger fs-3 me-2"></i><span class="fw-bold text-danger">เหนื่อยล้า</span>';
        case 2: return '<i class="fa-solid fa-face-meh text-warning fs-3 me-2"></i><span class="fw-bold text-warning">ตึงเครียด</span>';
        case 3: return '<i class="fa-solid fa-face-smile text-primary fs-3 me-2"></i><span class="fw-bold text-primary">ปกติ/เรื่อยๆ</span>';
        case 4: return '<i class="fa-solid fa-face-smile-beam text-info fs-3 me-2"></i><span class="fw-bold text-info">ดีมาก</span>';
        case 5: return '<i class="fa-solid fa-face-laugh-squint text-success fs-3 me-2"></i><span class="fw-bold text-success">ยอดเยี่ยม!</span>';
        default: return '<i class="fa-solid fa-minus text-muted"></i>';
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom d-print-none">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-clipboard-list me-2"></i>รายละเอียดการปฏิบัติงาน</h1>
        <div>
            <button onclick="window.print()" class="btn btn-light border text-dark shadow-sm rounded-pill px-3 me-2">
                <i class="fa-solid fa-print me-1"></i> พิมพ์
            </button>
            <?php
            $back_url = 'index.php';
            if (isset($_GET['from']) && $_GET['from'] == 'admin_dashboard') {
                $back_url = 'admin_dashboard.php';
            }
            ?>
            <button onclick="location.href='<?php echo $back_url; ?>'" class="btn btn-primary shadow-sm rounded-pill px-4">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4 animate__animated animate__fadeInDown">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 bg-white overflow-hidden">
                
                <div class="bg-light p-4 border-bottom border-light d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1"><i class="fa-regular fa-calendar-check me-1"></i> วันที่ปฏิบัติงาน</span>
                        <span class="fs-4 fw-bold text-dark"><?php echo date('d / m / Y', strtotime($log['log_date'])); ?></span>
                        
                        <?php if ($is_executive && $log['user_id'] != $user_id): ?>
                            <div class="mt-2 pt-2 border-top border-light">
                                <span class="badge bg-purple-subtle text-purple px-3 py-2 rounded-pill"><i class="fa-solid fa-user me-1"></i> พนักงาน: <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1"><i class="fa-regular fa-clock me-1"></i> เวลาทำงาน</span>
                        <span class="fs-3 fw-bold text-primary">
                            <?php 
                                $val = $log['hours_spent'];
                                $lh = floor($val / 60);
                                $lm = $val % 60;
                                echo ($lh > 0 ? "{$lh} <span class='fs-6 text-muted fw-normal'>ชม.</span> " : "") . ($lm > 0 ? "{$lm} <span class='fs-6 text-muted fw-normal'>น.</span>" : ($lh == 0 ? "0 <span class='fs-6 text-muted fw-normal'>น.</span>" : ""));
                            ?>
                        </span>
                    </div>
                </div>

                <div class="card-body p-4 p-md-5">
                    
                    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3 p-3 rounded-4 <?php echo in_array($log['urgency_level'], ['ด่วน', 'ด่วนมาก', 'ด่วนที่สุด']) ? 'bg-danger-subtle bg-opacity-10 border border-danger border-opacity-25' : 'bg-light'; ?>">
                        <div>
                            <span class="text-muted extra-small fw-bold text-uppercase d-block mb-1">ระดับความเร่งด่วน</span>
                            <?php 
                                if($log['urgency_level'] == 'ด่วนที่สุด') echo '<span class="badge bg-danger px-3 py-2 fs-6 shadow-sm"><i class="fa-solid fa-fire-flame-curved me-1"></i> ด่วนที่สุด</span>';
                                elseif($log['urgency_level'] == 'ด่วนมาก') echo '<span class="badge bg-warning text-dark px-3 py-2 fs-6 shadow-sm"><i class="fa-solid fa-bolt me-1"></i> ด่วนมาก</span>';
                                elseif($log['urgency_level'] == 'ด่วน') echo '<span class="badge bg-info px-3 py-2 fs-6 shadow-sm"><i class="fa-solid fa-circle-exclamation me-1"></i> ด่วน</span>';
                                else echo '<span class="badge bg-secondary px-3 py-2 fs-6 fw-normal">ปกติ</span>';
                            ?>
                        </div>

                        <?php if ($log['user_id'] == $user_id && in_array($log['urgency_level'], ['ด่วน', 'ด่วนมาก', 'ด่วนที่สุด'])): ?>
                            <?php if ($log['is_completed'] == 0): ?>
                                <form action="" method="POST" onsubmit="return confirm('ยืนยันว่าดำเนินการเรียบร้อยแล้ว?');">
                                    <input type="hidden" name="action" value="mark_done">
                                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow-sm fw-bold animate__animated animate__pulse animate__infinite">
                                        <i class="fa-solid fa-check-circle me-2"></i> ดำเนินการเรียบร้อยแล้ว
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i> ดำเนินการเสร็จสิ้นแล้ว</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4 pb-3 border-bottom border-light">
                        <span class="fw-bold text-primary small text-uppercase mb-2 d-block"><i class="fa-solid fa-briefcase me-1"></i> หัวข้องาน</span>
                        <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($log['task_name']); ?></h4>
                    </div>

                    <div class="mb-4">
                        <span class="fw-bold text-secondary small text-uppercase d-block mb-2"><i class="fa-solid fa-align-left me-1"></i> รายละเอียดงานที่ทำสำเร็จ</span>
                        <div class="bg-light p-4 rounded-4 text-dark shadow-sm" style="line-height: 1.8;">
                            <?php echo nl2br(htmlspecialchars($log['description'])); ?>
                        </div>
                    </div>

                    <?php if ($log['has_issue'] == 1): ?>
                    <div class="mb-4">
                        <span class="fw-bold text-danger small text-uppercase d-block mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> ปัญหาและอุปสรรค</span>
                        <div class="bg-danger-subtle bg-opacity-25 p-4 rounded-4 border border-danger text-danger fw-medium shadow-sm" style="line-height: 1.8;">
                            <?php echo nl2br(htmlspecialchars($log['issue_detail'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <span class="fw-bold text-primary small text-uppercase d-block mb-2"><i class="fa-solid fa-forward-step me-1"></i> แผนงานวันพรุ่งนี้ (Next Movement)</span>
                        <div class="bg-primary-subtle bg-opacity-25 p-4 rounded-4 border border-primary text-dark shadow-sm" style="line-height: 1.8;">
                            <?php echo nl2br(htmlspecialchars($log['next_movement'])); ?>
                        </div>
                    </div>
                </div>
                
                <div class="card-footer bg-white d-flex justify-content-between align-items-center border-top border-light p-4">
                    <div class="d-flex align-items-center">
                        <span class="text-muted small fw-bold me-3 text-uppercase">Mood:</span>
                        <div class="harmony-badge bg-light border border-light rounded-pill px-4 py-2 d-flex align-items-center shadow-sm">
                            <?php echo getHarmonyDisplay($log['harmony_score']); ?>
                        </div>
                    </div>
                    <div>
                        <?php if ($log['submission_status'] == 'ล่าช้า'): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger px-4 py-2 rounded-pill shadow-sm">ส่งล่าช้า</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success px-4 py-2 rounded-pill shadow-sm">ส่งตรงเวลา</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="text-end mt-3 d-print-none text-muted small">
                บันทึกข้อมูลเข้าระบบเมื่อ: <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?> น.
            </div>
            
        </div>
    </div>
</main>

<style>
    .harmony-badge { transition: 0.3s; }
    .harmony-badge:hover { background-color: #f8fafc !important; transform: scale(1.05); }
    
    @media print {
        .sidebar, .header, .d-print-none, .navbar { display: none !important; }
        main, .col-md-9 { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        body { background-color: white !important; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>