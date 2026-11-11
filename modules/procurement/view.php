<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์
$allowed_roles = ['admin', 'งานพัสดุ', 'งานแผน', 'รองคณบดีฝ่ายวิชาการ', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($allowed_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์เข้าใช้งานระบบติดตามงานพัสดุ'); window.location.href='../../index.php';</script>";
    exit();
}

$can_manage = hasRole(['admin', 'งานพัสดุ']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$project_id = $_GET['id'];

// 2. ดึงข้อมูลโครงการ พร้อมคณะกรรมการ และ COLLATED เพื่อป้องกัน Error 1267
$stmt = $pdo->prepare("
    SELECT p.*, 
            u.first_name, u.last_name, u.position,
            t1.first_name as tor1_fn, t1.last_name as tor1_ln,
            t2.first_name as tor2_fn, t2.last_name as tor2_ln,
            t3.first_name as tor3_fn, t3.last_name as tor3_ln,
            c1.first_name as con1_fn, c1.last_name as con1_ln,
            c2.first_name as con2_fn, c2.last_name as con2_ln,
            c3.first_name as con3_fn, c3.last_name as con3_ln,
            i1.first_name as ins1_fn, i1.last_name as ins1_ln,
            i2.first_name as ins2_fn, i2.last_name as ins2_ln,
            i3.first_name as ins3_fn, i3.last_name as ins3_ln
    FROM procurement_tracking p
    LEFT JOIN users u ON p.responsible_user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users t1 ON p.tor_cm_1 COLLATE utf8mb4_unicode_ci = t1.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users t2 ON p.tor_cm_2 COLLATE utf8mb4_unicode_ci = t2.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users t3 ON p.tor_cm_3 COLLATE utf8mb4_unicode_ci = t3.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users c1 ON p.con_cm_1 COLLATE utf8mb4_unicode_ci = c1.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users c2 ON p.con_cm_2 COLLATE utf8mb4_unicode_ci = c2.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users c3 ON p.con_cm_3 COLLATE utf8mb4_unicode_ci = c3.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users i1 ON p.ins_cm_1 COLLATE utf8mb4_unicode_ci = i1.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users i2 ON p.ins_cm_2 COLLATE utf8mb4_unicode_ci = i2.user_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN users i3 ON p.ins_cm_3 COLLATE utf8mb4_unicode_ci = i3.user_id COLLATE utf8mb4_unicode_ci
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    echo "<script>alert('ไม่พบข้อมูลรายการนี้'); window.location.href='index.php';</script>";
    exit();
}

// 3. คำนวณความคืบหน้า
$steps = [
    $project['step1_request'], $project['step2_tor'], $project['step3_approve_buy'], $project['step4_announce_winner'],
    $project['step5_system_approve'], $project['step6_contract'], $project['step7_delivery'], $project['step8_inspection'],
    $project['step9_disbursement'], $project['step10_completed']
];
$completed_count = array_sum($steps);
$progress = ($completed_count / 10) * 100;

// คำนวณส่วนต่างงบประมาณ
$balance = $project['budget'] - $project['actual_price'];

// 4. ตรวจสอบสถานะการแสดงผล
$today = strtotime(date('Y-m-d'));
if ($project['step10_completed'] == 1) {
    $display_status = 'เสร็จสิ้นกระบวนการ';
    $status_badge = 'bg-success';
    $progress_color = 'bg-success';
} elseif ($progress < 100 && !empty($project['end_date']) && strtotime($project['end_date']) < $today) {
    $display_status = 'ล่าช้า (เลยกำหนดส่งมอบ)';
    $status_badge = 'bg-danger';
    $progress_color = 'bg-danger';
} else {
    $display_status = 'กำลังดำเนินการ';
    $status_badge = 'bg-warning text-dark';
    $progress_color = 'bg-primary';
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-2 mb-4 border-bottom d-print-none">
        <div>
            <h1 class="h3 text-dark fw-bold"><i class="fa-solid fa-file-invoice-dollar me-2 text-primary"></i>รายละเอียดโครงการจัดซื้อจัดจ้าง</h1>
            <p class="text-muted small mb-0">ข้อมูลเลขที่รายการ: #<?php echo str_pad($project['id'], 5, '0', STR_PAD_LEFT); ?> | ปีงบประมาณ: <?php echo $project['fiscal_year']; ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-light rounded-pill px-3 fw-bold border shadow-sm"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</a>
            <?php if ($can_manage): ?>
                <a href="edit.php?id=<?php echo $project['id']; ?>" class="btn btn-outline-primary rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไข
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> พิมพ์เอกสาร
            </button>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4">
                        <h4 class="fw-bold text-dark lh-base mb-2"><?php echo htmlspecialchars($project['project_name']); ?></h4>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge <?php echo $status_badge; ?> px-3 py-2 fs-6 rounded-pill"><?php echo $display_status; ?></span>
                            <span class="text-muted small"><i class="fa-solid fa-clock me-1"></i> เริ่มโครงการ: <?php echo !empty($project['start_date']) ? date('d/m/Y', strtotime($project['start_date'])) : '-'; ?></span>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-4 border text-center h-100">
                                <small class="text-muted d-block mb-1 fw-bold">งบประมาณที่ตั้งไว้</small>
                                <h5 class="fw-bold text-dark mb-0">฿<?php echo number_format($project['budget'], 2); ?></h5>
                                <small class="extra-small text-muted"><?php echo htmlspecialchars($project['budget_type']); ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-primary-subtle rounded-4 border border-primary-subtle text-center h-100">
                                <small class="text-primary d-block mb-1 fw-bold">ผลการจัดซื้อจริง</small>
                                <h5 class="fw-bold text-primary mb-0">฿<?php echo number_format($project['actual_price'], 2); ?></h5>
                                <small class="extra-small text-primary opacity-75">จัดซื้อได้ ณ ราคาจริง</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 <?php echo $balance >= 0 ? 'bg-success-subtle' : 'bg-danger-subtle'; ?> rounded-4 border border-opacity-25 text-center h-100">
                                <small class="<?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?> d-block mb-1 fw-bold">คงเหลือ/ประหยัดได้</small>
                                <h5 class="fw-bold <?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?> mb-0">
                                    <?php echo ($balance >= 0 ? '+' : '') . number_format($balance, 2); ?>
                                </h5>
                                <small class="extra-small opacity-75">ผลต่างงบประมาณ</small>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-5 border-top pt-4">
                        <div class="col-md-6">
                            <label class="text-muted small d-block mb-1">ผู้รับผิดชอบโครงการ</label>
                            <h6 class="fw-bold"><i class="fa-solid fa-user-check me-2 text-primary"></i><?php echo htmlspecialchars($project['first_name'] . ' ' . $project['last_name']); ?></h6>
                            <p class="extra-small text-muted mb-0"><?php echo htmlspecialchars($project['position'] ?? '-'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small d-block mb-1">วิธีการจัดหา</label>
                            <h6 class="fw-bold"><i class="fa-solid fa-layer-group me-2 text-primary"></i><?php echo htmlspecialchars($project['procurement_method'] ?? '-'); ?></h6>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small d-block mb-1">กำหนดส่งมอบงาน</label>
                            <h6 class="fw-bold <?php echo ($display_status == 'ล่าช้า (เลยกำหนดส่งมอบ)') ? 'text-danger' : ''; ?>">
                                <i class="fa-regular fa-calendar-check me-2 text-primary"></i><?php echo !empty($project['end_date']) ? date('d/m/Y', strtotime($project['end_date'])) : '-'; ?>
                            </h6>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small d-block mb-1">ผลสรุปการจัดซื้อ</label>
                            <h6 class="fw-bold text-dark"><i class="fa-solid fa-award me-2 text-warning"></i><?php echo htmlspecialchars($project['procurement_result'] ?: 'ยังไม่ระบุผล'); ?></h6>
                        </div>
                    </div>

                    <div class="bg-light p-4 rounded-4 mb-4 border">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-users-gear me-2 text-warning"></i>รายชื่อคณะกรรมการดำเนินงาน</h6>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <p class="extra-small fw-bold text-muted text-uppercase mb-2">1. กรรมการร่าง TOR</p>
                                <ul class="list-unstyled small mb-0">
                                    <?php if($project['tor1_fn']): ?><li>• <?php echo htmlspecialchars($project['tor1_fn'].' '.$project['tor1_ln']); ?></li><?php endif; ?>
                                    <?php if($project['tor2_fn']): ?><li>• <?php echo htmlspecialchars($project['tor2_fn'].' '.$project['tor2_ln']); ?></li><?php endif; ?>
                                    <?php if($project['tor3_fn']): ?><li>• <?php echo htmlspecialchars($project['tor3_fn'].' '.$project['tor3_ln']); ?></li><?php endif; ?>
                                    <?php if(!$project['tor1_fn']): ?><li class="text-muted">-</li><?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-md-4 mb-3">
                                <p class="extra-small fw-bold text-muted text-uppercase mb-2">2. กรรมการจัดซื้อ/จ้าง</p>
                                <ul class="list-unstyled small mb-0">
                                    <?php if($project['con1_fn']): ?><li>• <?php echo htmlspecialchars($project['con1_fn'].' '.$project['con1_ln']); ?></li><?php endif; ?>
                                    <?php if($project['con2_fn']): ?><li>• <?php echo htmlspecialchars($project['con2_fn'].' '.$project['con2_ln']); ?></li><?php endif; ?>
                                    <?php if($project['con3_fn']): ?><li>• <?php echo htmlspecialchars($project['con3_fn'].' '.$project['con3_ln']); ?></li><?php endif; ?>
                                    <?php if(!$project['con1_fn']): ?><li class="text-muted">-</li><?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-md-4 mb-3">
                                <p class="extra-small fw-bold text-muted text-uppercase mb-2">3. กรรมการตรวจรับ</p>
                                <ul class="list-unstyled small mb-0">
                                    <?php if($project['ins1_fn']): ?><li>• <?php echo htmlspecialchars($project['ins1_fn'].' '.$project['ins1_ln']); ?></li><?php endif; ?>
                                    <?php if($project['ins2_fn']): ?><li>• <?php echo htmlspecialchars($project['ins2_fn'].' '.$project['ins2_ln']); ?></li><?php endif; ?>
                                    <?php if($project['ins3_fn']): ?><li>• <?php echo htmlspecialchars($project['ins3_fn'].' '.$project['ins3_ln']); ?></li><?php endif; ?>
                                    <?php if(!$project['ins1_fn']): ?><li class="text-muted">-</li><?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($project['remark'])): ?>
                    <div class="p-3 bg-warning-subtle bg-opacity-25 rounded-3 border border-warning-subtle">
                        <strong class="text-dark d-block mb-1 small"><i class="fa-solid fa-note-sticky me-1 text-warning"></i> หมายเหตุ:</strong>
                        <p class="text-muted small mb-0"><?php echo nl2br(htmlspecialchars($project['remark'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 20px;">
                <div class="card-header bg-white pt-4 pb-0 border-0">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list-check me-2 text-primary"></i>ลำดับความคืบหน้า</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-end mb-2">
                            <span class="small fw-bold text-muted">Progress</span>
                            <span class="fw-bold <?php echo $progress == 100 ? 'text-success' : 'text-primary'; ?> fs-4"><?php echo $progress; ?>%</span>
                        </div>
                        <div class="progress" style="height: 10px; border-radius: 10px;">
                            <div class="progress-bar <?php echo $progress_color; ?>" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </div>

                    <div class="timeline-steps">
                        <?php 
                        $step_names = [
                            'step1_request' => 'ขออนุมัติจัดหา', 'step2_tor' => 'จัดทำ TOR/สเปก', 'step3_approve_buy' => 'อนุมัติจัดซื้อ/จ้าง',
                            'step4_announce_winner' => 'ประกาศผลผู้ชนะ', 'step5_system_approve' => 'ขออนุมัติ PO ในระบบ',
                            'step6_contract' => 'ลงนามสัญญา/ใบสั่งซื้อ', 'step7_delivery' => 'ผู้ขายส่งมอบงาน/พัสดุ',
                            'step8_inspection' => 'คณะกรรมการตรวจรับ', 'step9_disbursement' => 'รวบรวมเอกสารเบิกจ่าย', 'step10_completed' => 'การดำเนินการเสร็จสิ้น'
                        ];
                        
                        foreach ($step_names as $key => $label): 
                            $is_done = $project[$key] == 1;
                        ?>
                        <div class="d-flex align-items-start mb-3">
                            <div class="step-dot me-3 <?php echo $is_done ? 'bg-success border-success' : 'bg-white border-secondary opacity-50'; ?>">
                                <?php if($is_done): ?><i class="fa-solid fa-check text-white small"></i><?php endif; ?>
                            </div>
                            <h6 class="mb-0 small fw-bold <?php echo $is_done ? 'text-success' : 'text-muted'; ?>"><?php echo $label; ?></h6>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                </div>
                <div class="card-footer bg-light border-0 py-3 text-center text-muted extra-small rounded-bottom-4">
                    อัปเดตล่าสุด: <?php echo date('d/m/Y H:i', strtotime($project['updated_at'])); ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .bg-primary-subtle { background-color: #cfe2ff !important; }
    .bg-success-subtle { background-color: #d1e7dd !important; }
    .bg-danger-subtle { background-color: #f8d7da !important; }
    .extra-small { font-size: 0.7rem; }
    
    .step-dot {
        width: 22px; height: 22px; border-radius: 50%; border: 2px solid; 
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }

    @media print {
        @page { size: A4; margin: 1.5cm; }
        .sidebar, .navbar, .d-print-none, .sticky-top { display: none !important; }
        .col-md-9, .ms-sm-auto, .col-lg-10 { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .row { display: block; }
        .col-lg-8, .col-lg-4 { width: 100% !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 20px !important; }
        .bg-light, .bg-primary-subtle, .bg-success-subtle { background: transparent !important; border: 1px solid #eee !important; }
        .timeline-steps { display: flex; flex-wrap: wrap; gap: 10px; }
        .timeline-steps > div { width: 48%; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>