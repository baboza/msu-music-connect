<?php
// เปิด Error สำหรับการพัฒนา
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$req_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($req_id === 0) {
    echo "<script>alert('ไม่พบรหัสรายการ'); window.location.href='index.php';</script>";
    exit();
}

// 1. ดึงข้อมูลรายละเอียดคำขอ
$sql = "SELECT f.*, u.first_name, u.last_name, u.profile_picture, d.dept_name 
        FROM finance_requests f 
        JOIN users u ON f.user_id = u.user_id 
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE f.req_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$req_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo "<script>alert('ไม่พบข้อมูลรายการ'); window.location.href='index.php';</script>";
    exit();
}

// 2. เช็คสิทธิ์การเข้าชม (เจ้าของเรื่อง / งานการเงิน / ผู้บริหาร)
$is_owner = ($req['user_id'] == $user_id);
$is_privileged = hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองบริหาร', 'คณบดี', 'งานการเงิน']);

if (!$is_owner && !$is_privileged) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าชมรายการนี้'); window.location.href='index.php';</script>";
    exit();
}

// 3. ดึงประวัติการเปลี่ยนสถานะ (History Log)
$sql_history = "SELECT h.*, u.first_name, u.last_name 
                FROM finance_status_history h 
                LEFT JOIN users u ON h.updated_by = u.user_id 
                WHERE h.req_id = ? 
                ORDER BY h.updated_at DESC";
$stmt_hist = $pdo->prepare($sql_history);
$stmt_hist->execute([$req_id]);
$history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// Helper: การตั้งค่าสีและข้อความตามสถานะ
function getStatusDetails($status) {
    $map = [
        'received'  => ['label' => 'อยู่คณะ/รับเรื่องแล้ว', 'color' => 'secondary', 'icon' => 'fa-file-import', 'step' => 1],
        'verified'  => ['label' => 'ตรวจสอบเอกสารแล้ว', 'color' => 'primary', 'icon' => 'fa-file-circle-check', 'step' => 2],
        'forwarded' => ['label' => 'ส่งกองคลัง มหาวิทยาลัย', 'color' => 'warning', 'icon' => 'fa-paper-plane', 'step' => 3],
        'approved'  => ['label' => 'มหาลัยอนุมัติ/สั่งจ่าย', 'color' => 'info', 'icon' => 'fa-check-double', 'step' => 4],
        'paid'      => ['label' => 'โอนเงิน/เคลียร์ลูกหนี้สำเร็จ', 'color' => 'success', 'icon' => 'fa-circle-check', 'step' => 5],
    ];
    return $map[$status] ?? ['label' => 'N/A', 'color' => 'light', 'icon' => 'fa-question', 'step' => 0];
}

$curr = getStatusDetails($req['current_status']);

// 💡 คำนวณสถานะการส่งเอกสาร/ล้างหนี้ (เพื่อให้ตรงกับลอจิกหน้า index)
$is_overdue = false;
$overdue_days = 0;
$has_cleared_date = (!empty($req['cleared_date']) && $req['cleared_date'] != '0000-00-00');

if ($req['req_type'] == 'advance') {
    if (!empty($req['due_date']) && $req['due_date'] != '0000-00-00') {
        $due_date = strtotime($req['due_date']);
        $calc_date = $has_cleared_date ? strtotime($req['cleared_date']) : strtotime(date('Y-m-d'));
        
        if ($calc_date > $due_date) {
            $is_overdue = true;
            $overdue_days = floor(($calc_date - $due_date) / (60 * 60 * 24));
        }
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-center pt-4 pb-3 mb-4 d-print-none">
        <a href="index.php" class="btn btn-white border shadow-sm rounded-pill px-4 fw-bold">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-white border shadow-sm rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-print me-1"></i> พิมพ์
            </button>
            <?php if(hasRole(['admin', 'งานการเงิน'])): ?>
                <a href="edit_status.php?id=<?php echo $req_id; ?>" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-pen-to-square me-1"></i> อัปเดตสถานะ
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge bg-<?php echo $curr['color']; ?>-subtle text-<?php echo $curr['color']; ?> border border-<?php echo $curr['color']; ?> px-3 py-2 rounded-pill mb-2">
                            <i class="fa-solid <?php echo $curr['icon']; ?> me-1"></i> <?php echo $curr['label']; ?>
                        </span>
                        <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($req['title']); ?></h3>
                        <p class="text-muted small">รหัสรายการ: #FIN-<?php echo str_pad($req['req_id'], 5, '0', STR_PAD_LEFT); ?> | ปีงบประมาณ <?php echo $req['fiscal_year']; ?></p>
                    </div>
                </div>

                <div class="card-body p-4 pt-0">
                    <div class="bg-light rounded-4 p-4 mb-4 text-center border">
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1">ยอดเงินเบิกจ่าย/ยืมรวม</span>
                        <h1 class="fw-bold text-primary mb-0" style="font-size: 3rem;">฿<?php echo number_format($req['amount'], 2); ?></h1>
                    </div>

                    <?php if($is_overdue && !$has_cleared_date && $req['current_status'] != 'paid'): ?>
                        <div class="alert alert-danger border-0 shadow-sm rounded-4 d-flex align-items-center mb-4">
                            <i class="fa-solid fa-triangle-exclamation fs-3 me-3"></i>
                            <div>
                                <h6 class="fw-bold mb-0">เกินกำหนดส่งใบสำคัญ (เลยกำหนดมาแล้ว <?php echo $overdue_days; ?> วัน)!</h6>
                                <p class="small mb-0">รายการนี้ครบกำหนดตั้งแต่วันที่ <?php echo date('d/m/Y', strtotime($req['due_date'])); ?> กรุณาติดต่อฝ่ายงานการเงินด่วน</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4">
                        <div class="col-sm-6">
                            <label class="text-muted extra-small fw-bold text-uppercase">ผู้ขอเบิก/ผู้ยืม</label>
                            <div class="d-flex align-items-center mt-1">
                                <?php if($req['profile_picture']): ?>
                                    <img src="../../uploads/avatars/<?php echo $req['profile_picture']; ?>" class="rounded-circle me-2" width="32" height="32" style="object-fit: cover;">
                                <?php endif; ?>
                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($req['first_name'].' '.$req['last_name']); ?></span>
                            </div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="text-muted extra-small fw-bold text-uppercase">ประเภทรายการ</label>
                            <div class="mt-1">
                                <?php if($req['req_type'] == 'advance'): ?>
                                    <span class="text-warning fw-bold"><i class="fa-solid fa-hand-holding-dollar me-1"></i> ลูกหนี้เงินยืม</span>
                                <?php elseif($req['req_type'] == 'clear_advance'): ?>
                                    <span class="text-success fw-bold"><i class="fa-solid fa-file-circle-check me-1"></i> หักล้างเงินยืม</span>
                                <?php elseif($req['req_type'] == 'teaching'): ?>
                                    <span class="text-info fw-bold"><i class="fa-solid fa-chalkboard-user me-1"></i> ค่าสอนอาจารย์พิเศษ</span>
                                <?php else: ?>
                                    <span class="text-purple fw-bold"><i class="fa-solid fa-receipt me-1"></i> เบิกค่าใช้จ่ายทั่วไป</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="text-muted extra-small fw-bold text-uppercase">ชื่อผู้รับเงินปลายทาง (Payee)</label>
                            <div class="fw-bold text-dark mt-1"><?php echo htmlspecialchars($req['payee_name'] ?: '-'); ?></div>
                        </div>
                        
                        <div class="col-sm-6">
                            <div class="p-3 bg-light border rounded-3 h-100">
                                <?php if($req['req_type'] == 'advance'): ?>
                                    <label class="text-muted extra-small fw-bold text-uppercase mb-1 d-block"><i class="fa-regular fa-calendar text-secondary me-1"></i>กำหนดเคลียร์เงินยืม</label>
                                    <div class="fw-bold <?php echo $is_overdue && !$has_cleared_date ? 'text-danger' : 'text-dark'; ?>">
                                        <?php echo !empty($req['due_date']) && $req['due_date'] != '0000-00-00' ? date('d M Y', strtotime($req['due_date'])) : 'ยังไม่กำหนด'; ?>
                                    </div>
                                    
                                    <hr class="my-2 opacity-25">
                                    
                                    <label class="text-muted extra-small fw-bold text-uppercase mb-1 d-block"><i class="fa-solid fa-calendar-check text-success me-1"></i>วันที่ส่งใบสำคัญ (ล้างหนี้)</label>
                                    <div class="fw-bold <?php echo $has_cleared_date ? 'text-success' : 'text-muted'; ?>">
                                        <?php 
                                            if ($has_cleared_date) {
                                                echo date('d M Y', strtotime($req['cleared_date']));
                                                if ($is_overdue) {
                                                    echo ' <span class="badge bg-danger ms-1 px-1 py-0 fw-normal" style="font-size: 0.65rem;">(ส่งช้า '.$overdue_days.' วัน)</span>';
                                                } else {
                                                    echo ' <span class="badge bg-success ms-1 px-1 py-0 fw-normal" style="font-size: 0.65rem;">(ตรงเวลา)</span>';
                                                }
                                            } else {
                                                echo 'ยังไม่ได้รับเอกสาร';
                                            }
                                        ?>
                                    </div>
                                <?php else: ?>
                                    <label class="text-muted extra-small fw-bold text-uppercase mb-1 d-block"><i class="fa-solid fa-info-circle text-secondary me-1"></i>ข้อมูลเพิ่มเติม</label>
                                    <div class="fw-bold text-muted mt-2">ไม่มีการกำหนดวันเคลียร์เงินสำหรับประเภทรายการนี้</div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-4">ขั้นตอนการดำเนินการ</h6>
                    <div class="tracker-steps-horizontal">
                        <?php 
                        $steps = [
                            ['label' => 'รับเรื่อง', 'icon' => 'fa-inbox'],
                            ['label' => 'ตรวจสอบ', 'icon' => 'fa-magnifying-glass'],
                            ['label' => 'ส่งมหาลัย', 'icon' => 'fa-university'],
                            ['label' => 'อนุมัติ', 'icon' => 'fa-stamp'],
                            ['label' => 'สำเร็จ', 'icon' => 'fa-check-double']
                        ];
                        foreach($steps as $idx => $s):
                            $active_class = ($idx + 1 <= $curr['step']) ? 'active' : '';
                        ?>
                            <div class="t-step <?php echo $active_class; ?>">
                                <div class="t-icon"><i class="fa-solid <?php echo $s['icon']; ?>"></i></div>
                                <div class="t-label"><?php echo $s['label']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>ประวัติการติดตาม</h5>
                </div>
                <div class="card-body p-4">
                    <div class="vertical-timeline">
                        <?php if(empty($history)): ?>
                            <p class="text-center text-muted py-5 small">ยังไม่มีบันทึกประวัติการเปลี่ยนแปลง</p>
                        <?php else: ?>
                            <?php foreach($history as $h): 
                                $h_config = getStatusDetails($h['status']);
                            ?>
                                <div class="timeline-item pb-4">
                                    <div class="timeline-marker bg-<?php echo $h_config['color']; ?>">
                                        <i class="fa-solid <?php echo $h_config['icon']; ?>"></i>
                                    </div>
                                    <div class="timeline-content ps-4">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <h6 class="fw-bold text-<?php echo $h_config['color']; ?> mb-1"><?php echo $h_config['label']; ?></h6>
                                            <span class="extra-small text-muted fw-bold"><?php echo date('d/m/y H:i', strtotime($h['updated_at'])); ?></span>
                                        </div>
                                        <p class="text-dark small mb-1 bg-light p-2 rounded-3 border-start border-3 border-<?php echo $h_config['color']; ?>">
                                            <?php echo htmlspecialchars($h['note'] ?: 'ไม่มีบันทึกเพิ่มเติม'); ?>
                                        </p>
                                        <div class="extra-small text-muted">
                                            <i class="fa-regular fa-user-circle me-1"></i> อัปเดตโดย: <?php echo htmlspecialchars($h['first_name'] . ' ' . $h['last_name']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .extra-small { font-size: 0.7rem; }
    
    /* 🛤️ Horizontal Tracker CSS */
    .tracker-steps-horizontal { display: flex; justify-content: space-between; position: relative; padding: 20px 0; }
    .tracker-steps-horizontal::before { content: ''; position: absolute; top: 38px; left: 10%; right: 10%; height: 2px; background: #eee; z-index: 1; }
    .t-step { position: relative; z-index: 2; text-align: center; flex: 1; }
    .t-icon { width: 40px; height: 40px; background: #fff; border: 2px solid #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; color: #bbb; transition: all 0.3s; }
    .t-label { font-size: 0.65rem; font-weight: bold; color: #999; }
    .t-step.active .t-icon { background: #39048f; color: #fff; border-color: #39048f; transform: scale(1.1); box-shadow: 0 5px 15px rgba(57, 4, 143,0.3); }
    .t-step.active .t-label { color: #39048f; }

    /* ⏳ Vertical Timeline CSS */
    .vertical-timeline { position: relative; padding-left: 20px; border-left: 2px solid #f1f1f1; margin-left: 10px; }
    .timeline-item { position: relative; }
    .timeline-marker { position: absolute; left: -31px; top: 0; width: 22px; height: 22px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; border: 3px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    
    @media print {
        .d-print-none { display: none !important; }
        body { background: white !important; }
        .card { border: none !important; box-shadow: none !important; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>