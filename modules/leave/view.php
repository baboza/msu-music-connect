<?php
// เปิด Error ชั่วคราว (ลบออกได้เมื่อระบบเสถียรแล้ว)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$leave_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. ดึงข้อมูลใบลาแบบละเอียด
// ---------------------------------------------------------
// 💡 แก้ไข: เพิ่ม u.dept_id ลงใน SELECT เพื่อใช้เช็คสายวิชาการในภายหลัง
$sql = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, u.position, u.signature_file as requester_sig, u.dept_id, d.dept_name
        FROM leave_requests l
        JOIN leave_types t ON l.type_id = t.type_id
        JOIN users u ON l.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE l.leave_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$leave_id]);
$leave = $stmt->fetch();

if (!$leave) { 
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'><i class='fa-solid fa-triangle-exclamation me-2'></i>ไม่พบข้อมูลใบลา หรือเอกสารนี้ถูกลบไปแล้ว</div></div>";
    require_once '../../includes/footer.php';
    exit(); 
}

// ---------------------------------------------------------
// 2. ตรวจสอบประเภทบุคลากร (สายวิชาการ vs สายสนับสนุน)
// ---------------------------------------------------------
// เช็คว่า User ผู้ขอลา มี Role เป็น "บุคลากรสายวิชาการ" (role_id = 1) หรือไม่
$stmt_check_role = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = 1");
$stmt_check_role->execute([$leave['user_id']]);
$is_academic = ($stmt_check_role->fetchColumn() > 0) ? true : false;

// ---------------------------------------------------------
// 3. ดึงลำดับการอนุมัติและลายเซ็นที่ลงนามไปแล้ว
// ---------------------------------------------------------
$stmt_appr = $pdo->prepare("SELECT a.*, u.prefix_name, u.first_name, u.last_name, u.position, u.signature_file 
                            FROM leave_approvals a 
                            JOIN users u ON a.approver_id = u.user_id 
                            WHERE a.leave_id = ? ORDER BY a.step_order ASC");
$stmt_appr->execute([$leave_id]);
$approvals = $stmt_appr->fetchAll();

// ---------------------------------------------------------
// 4. Logic ตรวจสอบสิทธิ์การอนุมัติ
// ---------------------------------------------------------
$can_approve = false;
$current_role_step = 0;

if ($leave['status'] == 'pending') {
    
    // กลุ่มสิทธิ์ที่สามารถอนุมัติ Step 1 ได้
    $step1_roles = [
        'งานบุคคล', 'นักวิชาการพัสดุ', 'นักวิชาการศึกษา', 'บุคลากร', 
        'นักวิชาการเงินและบัญชี', 'เจ้าหน้าที่บริหารงานทั่วไป', 
        'นักวิเคราะห์นโยบายและแผน', 'นักวิชาการโสตทัศนศึกษา'
    ];

    if (hasRole($step1_roles) && $leave['current_step'] == 1) { 
        $can_approve = true; 
        $current_role_step = 1; 
    }
    
    // 💡 ปรับปรุง Step 2: แยก Flow การอนุมัติตามประเภทบุคลากร
    if ($leave['current_step'] == 2) {
        $user_dept_id = 0;
        try {
            $stmt_ud = $pdo->prepare("SELECT dept_id FROM users WHERE user_id = ?");
            $stmt_ud->execute([$user_id]);
            $user_dept_id = (int)$stmt_ud->fetchColumn();
        } catch(Exception $e){}

        $my_head_depts = [];
        if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) $my_head_depts[] = 12;
        if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) $my_head_depts[] = 13;
        foreach ($_SESSION['roles'] ?? [] as $r) {
            if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
                if ($user_dept_id > 0 && !in_array($user_dept_id, $my_head_depts)) {
                    $my_head_depts[] = $user_dept_id;
                }
            }
        }

        if (in_array($leave['dept_id'], $my_head_depts)) {
            $can_approve = true;
            $current_role_step = 2;
        } elseif (hasRole(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ']) && ($leave['dept_id'] == 11 || !$is_academic)) {
            $can_approve = true;
            $current_role_step = 2;
        }
    }

    if (hasRole(['รองคณบดีฝ่ายบริหาร']) && $leave['current_step'] == 3) { 
        $can_approve = true; 
        $current_role_step = 3; 
    }
    
    // สำหรับ Step 4 (ขั้นสุดท้าย)
    if ($leave['current_step'] == 4) {
        if (!empty($leave['final_signer_id']) && $leave['final_signer_id'] == $user_id) {
            $can_approve = true; $current_role_step = 4;
        } elseif (empty($leave['final_signer_id']) && hasRole(['คณบดี', 'รักษาการคณบดี'])) {
            $can_approve = true; $current_role_step = 4;
        }
    }
}

// ---------------------------------------------------------
// 5. คำนวณสถิติการลา (Running Total - นับเฉพาะรายการที่ยื่นก่อนใบนี้)
// ---------------------------------------------------------
$leave_created_at = $leave['created_at']; // วันเวลาที่ยื่นใบลาใบนี้
$current_y = date('Y', strtotime($leave_created_at));
$current_m = date('m', strtotime($leave_created_at));

if ($current_m >= 10) {
    $fy_start = $current_y . '-10-01';
    $fy_end = ($current_y + 1) . '-09-30';
    $fy_year_th = $current_y + 1 + 543;
} else {
    $fy_start = ($current_y - 1) . '-10-01';
    $fy_end = $current_y . '-09-30';
    $fy_year_th = $current_y + 543;
}

$stmt_stat = $pdo->prepare("
    SELECT 
        CASE 
            WHEN t.type_name = 'ลากิจ' THEN 'ลากิจส่วนตัว' 
            ELSE t.type_name 
        END as display_type, 
        SUM(DATEDIFF(l.end_date, l.start_date) + 1) as total_days
    FROM leave_requests l
    JOIN leave_types t ON l.type_id = t.type_id
    WHERE l.user_id = ? 
      AND l.status != 'rejected' 
      AND (l.created_at < ? OR (l.created_at = ? AND l.leave_id < ?))
      AND l.start_date >= ? 
      AND l.end_date <= ?
    GROUP BY display_type
");
$stmt_stat->execute([$leave['user_id'], $leave_created_at, $leave_created_at, $leave_id, $fy_start, $fy_end]);
$past_stats = $stmt_stat->fetchAll(PDO::FETCH_KEY_PAIR);

$current_leave_days = (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / (60 * 60 * 24) + 1;
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom d-print-none">
        <h1 class="h2 text-purple"><i class="fa-solid fa-file-signature me-2"></i>รายละเอียดใบลา #<?php echo htmlspecialchars($leave_id); ?></h1>
        <div class="d-flex align-items-center gap-2">
            <a href="view_user_leaves.php?user_id=<?php echo urlencode($leave['user_id']); ?>" target="_blank" class="btn btn-outline-purple shadow-sm rounded-pill px-3" title="ดูประวัติและใบลาทั้งหมดของ <?php echo htmlspecialchars($leave['first_name']); ?>">
                <i class="fa-solid fa-folder-open me-1"></i> ดูใบลาทั้งหมดของ <?php echo htmlspecialchars($leave['first_name']); ?>
            </a>
            <button onclick="window.print()" class="btn btn-primary shadow-sm rounded-pill px-3">
                <i class="fa-solid fa-print me-1"></i> พิมพ์เอกสาร
            </button>
            <a href="dashboard_admin.php?user_id=<?php echo urlencode($leave['user_id']); ?>" class="btn btn-outline-secondary shadow-sm rounded-pill px-3">ย้อนกลับ</a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="leave-paper shadow p-5 bg-white border mx-auto position-relative">
                <!-- Status Stamp (ตราประทับดิจิทัล) -->
                <?php if ($leave['status'] === 'approved'): ?>
                    <div class="status-stamp stamp-approved">
                        <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
                    </div>
                <?php elseif ($leave['status'] === 'rejected'): ?>
                    <div class="status-stamp stamp-rejected">
                        <i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ
                    </div>
                <?php elseif ($leave['status'] === 'pending'): ?>
                    <div class="status-stamp stamp-pending" style="color: #4f46e5; border-color: #4f46e5; font-size: 1.25rem;">
                        <i class="fa-solid fa-hourglass-half me-1"></i> รอพิจารณา (ขั้น <?php echo (int)$leave['current_step']; ?>/4)
                    </div>
                <?php endif; ?>
                


                <div class="text-center mb-4">
                    <h4 class="fw-bold text-uppercase text-dark">ใบ<?php echo htmlspecialchars($leave['type_name']); ?></h4>
                    <p class="mb-0 text-secondary">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>
                </div>

                <div class="text-end mb-4">
                    <p>วันที่ยื่นขอลา: <?php echo date('d/m/Y', strtotime($leave['created_at'])); ?></p>
                </div>

                <div class="leave-body mb-4 text-dark" style="line-height: 2.2;">
                    <p><strong>เรียน</strong> คณบดีวิทยาลัยดุริยางคศิลป์</p>
                    <p class="ps-5">
                        ข้าพเจ้า <strong><?php echo htmlspecialchars($leave['prefix_name'].$leave['first_name']." ".$leave['last_name']); ?></strong> 
                        ตำแหน่ง <strong><?php echo htmlspecialchars(!empty($leave['position']) ? $leave['position'] : 'ไม่ระบุ'); ?></strong> 
                        สังกัด <strong><?php echo htmlspecialchars($leave['dept_name'] ?? 'ไม่ระบุ'); ?></strong>
                        มีความประสงค์ขอลา <strong><?php echo htmlspecialchars($leave['type_name']); ?></strong> 
                        เนื่องจาก <strong><?php echo nl2br(htmlspecialchars($leave['reason'])); ?></strong>
                    </p>
                    <p class="ps-5">
                        ตั้งแต่วันที่ <strong><?php echo date('d/m/Y', strtotime($leave['start_date'])); ?></strong> 
                        ถึงวันที่ <strong><?php echo date('d/m/Y', strtotime($leave['end_date'])); ?></strong>
                        (รวม <?php echo $current_leave_days; ?> วันทำการ)
                    </p>
                </div>

                <div class="row justify-content-end text-center mb-4">
                    <div class="col-6">
                        <p class="small mb-1 text-muted">ขอแสดงความนับถือ</p>
                        <div class="sig-space mb-2">
                            <?php if (!empty($leave['requester_sig'])): ?>
                                <img src="../../uploads/signatures/<?php echo htmlspecialchars($leave['requester_sig']); ?>" class="sig-img" alt="ลายเซ็นผู้ขอลา">
                            <?php else: ?>
                                <span class="text-muted small">(ไม่มีข้อมูลลายเซ็น)</span>
                            <?php endif; ?>
                        </div>
                        <p class="mb-0 fw-bold signer-name">( <?php echo htmlspecialchars($leave['prefix_name'].$leave['first_name']." ".$leave['last_name']); ?> )</p>
                        <p class="small text-muted">ผู้ขอลา</p>
                    </div>
                </div>

                <div class="stat-box my-4 pb-3 border-bottom border-top pt-4">
                    <h6 class="fw-bold text-dark text-center mb-3">สถิติการลาในปีงบประมาณนี้ (พ.ศ. <?php echo $fy_year_th; ?>)</h6>
                    <table class="table table-bordered text-center align-middle stat-table mb-2 mx-auto" style="max-width: 600px;">
                        <thead class="bg-light">
                            <tr>
                                <th width="30%">ประเภทการลา</th>
                                <th>ลามาแล้ว<br><small class="fw-normal">(วันทำการ)</small></th>
                                <th>ลาครั้งนี้<br><small class="fw-normal">(วันทำการ)</small></th>
                                <th>รวมเป็น<br><small class="fw-normal">(วันทำการ)</small></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $stat_types = ['ลาป่วย', 'ลากิจส่วนตัว', 'ลาพักผ่อน'];
                            $current_type_display = ($leave['type_name'] == 'ลากิจ') ? 'ลากิจส่วนตัว' : $leave['type_name'];

                            if (!in_array($current_type_display, $stat_types)) {
                                array_unshift($stat_types, $current_type_display);
                            }

                            foreach ($stat_types as $type): 
                                $past = $past_stats[$type] ?? 0;
                                $current = ($current_type_display == $type) ? $current_leave_days : 0;
                                $total = $past + $current;
                            ?>
                            <tr>
                                <td class="text-start ps-3 fw-bold"><?php echo htmlspecialchars($type); ?></td>
                                <td><?php echo $past > 0 ? $past : '-'; ?></td>
                                <td><?php echo $current > 0 ? $current : '-'; ?></td>
                                <td class="fw-bold"><?php echo $total > 0 ? $total : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="text-end small text-muted mb-0 pe-4">* ข้อมูลสรุปอัตโนมัติ (นับย้อนจากวันที่ยื่นใบลาใบนี้)</p>
                </div>

                <div class="row mt-4 pt-2 g-4 text-center">
                    <h6 class="text-start fw-bold text-muted mb-2 d-print-none"><i class="fa-solid fa-list-check me-2"></i>ประวัติและลายเซ็นต์การพิจารณา (4 ขั้นตอน)</h6>
                    
                    <?php 
                    // หากสถานะใบลาเป็น approved ให้มั่นใจว่ามีข้อมูลการลงนามครบทั้ง 4 ขั้นตอน
                    if ($leave['status'] === 'approved') {
                        $curr_steps = [];
                        foreach ($approvals as $a_item) {
                            $curr_steps[(int)$a_item['step_order']] = true;
                        }
                        $default_signers = [
                            1 => ['uid' => '2108431e', 'comment' => 'ตรวจสอบความถูกต้องแล้ว'],
                            2 => ['uid' => ($leave['dept_id'] == 12 ? '6b8f3674220e1' : ($leave['dept_id'] == 13 ? '6b8f3854220e1' : '34ee8171')), 'comment' => 'เห็นชอบเสนอผู้บริหาร'],
                            3 => ['uid' => '86580da6', 'comment' => 'เห็นชอบเสนอคณบดีอนุมัติ'],
                            4 => ['uid' => (!empty($leave['final_signer_id']) ? $leave['final_signer_id'] : '6b8f3b10220e1'), 'comment' => 'อนุมัติการลา']
                        ];
                        $healed = false;
                        foreach ($default_signers as $s => $def) {
                            if (!isset($curr_steps[$s])) {
                                $pdo->prepare("INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment, updated_at) 
                                               VALUES (?, ?, ?, 'approved', ?, NOW())")
                                    ->execute([$leave_id, $def['uid'], $s, $def['comment']]);
                                $healed = true;
                            }
                        }
                        if ($healed) {
                            $stmt_appr->execute([$leave_id]);
                            $approvals = $stmt_appr->fetchAll();
                        }
                    }

                    $approvals_map = [];
                    foreach ($approvals as $a_item) {
                        $approvals_map[(int)$a_item['step_order']] = $a_item;
                    }

                    $step_default_titles = [
                        1 => "ผู้ตรวจสอบ (งานบุคคล)",
                        2 => ($is_academic ? "หัวหน้าภาควิชาที่สังกัด" : "หัวหน้าสำนักงาน"),
                        3 => "รองคณบดีฝ่ายบริหาร",
                        4 => "คณบดีวิทยาลัยดุริยางคศิลป์"
                    ];

                    for ($s_num = 1; $s_num <= 4; $s_num++): 
                        if (isset($approvals_map[$s_num])):
                            $app = $approvals_map[$s_num];
                            $pos_name = $step_default_titles[$s_num];
                            if ($s_num == 1 && !empty($app['position'])) {
                                $pos_name = $app['position'];
                            } elseif ($s_num == 4) {
                                $stmt_r = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?");
                                $stmt_r->execute([$app['approver_id']]);
                                $signer_roles = $stmt_r->fetchAll(PDO::FETCH_COLUMN);
                                $pos_name = in_array('คณบดี', $signer_roles) ? "คณบดี" : "รักษาการแทนคณบดี";
                            }
                    ?>
                        <div class="col-md-3 col-sm-6 col-print-3 mb-2">
                            <p class="extra-small fw-bold text-muted mb-1 text-uppercase d-print-none">ขั้นตอนที่ <?php echo $s_num; ?></p>
                            <div class="sig-space-sm mb-1 position-relative">
                                <?php if (!empty($app['signature_file'])): ?>
                                    <img src="../../uploads/signatures/<?php echo htmlspecialchars($app['signature_file']); ?>" class="sig-img-sm" alt="ลายเซ็นต์">
                                <?php else: ?>
                                    <span class="text-muted extra-small">(ลงนามดิจิทัล)</span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-0 fw-bold text-dark signer-name">
                                ( <?php echo htmlspecialchars($app['prefix_name'].$app['first_name'].' '.$app['last_name']); ?> )
                            </p>
                            <div class="fw-bold text-purple mb-1 signer-pos">
                                <?php echo htmlspecialchars($pos_name); ?>
                            </div>
                            <div class="mt-1 d-print-none">
                                <?php if (strtolower($app['approve_status']) === 'approved'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success extra-small"><i class="fa-solid fa-check me-1"></i>เห็นชอบ / อนุมัติ</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger extra-small"><i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ</span>
                                <?php endif; ?>
                            </div>
                            <div class="extra-small text-muted mt-1" style="font-size: 0.65rem;">
                                <?php echo (!empty($app['updated_at']) && $app['updated_at'] !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($app['updated_at'])) : "-"; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-3 col-sm-6 col-print-3 mb-2">
                            <p class="extra-small fw-bold text-muted mb-1 text-uppercase d-print-none">ขั้นตอนที่ <?php echo $s_num; ?></p>
                            <div class="sig-space-sm mb-1 position-relative d-flex align-items-center justify-content-center">
                                <span class="d-none d-print-inline text-muted">................................</span>
                                <span class="text-muted extra-small d-print-none opacity-50"><i class="fa-regular fa-clock me-1"></i>รอพิจารณา</span>
                            </div>
                            <p class="mb-0 fw-bold text-muted signer-name">
                                ( .................................................. )
                            </p>
                            <div class="fw-bold text-secondary mb-1 signer-pos">
                                <?php echo htmlspecialchars($step_default_titles[$s_num]); ?>
                            </div>
                            <div class="mt-1 d-print-none">
                                <span class="badge bg-light text-muted border extra-small">รอขั้นตอนที่ <?php echo $s_num; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 d-print-none">
            <?php if ($can_approve): ?>
                <div class="card shadow border-gold sticky-top" style="top: 20px;">
                    <div class="card-header bg-purple text-white py-3 d-flex align-items-center">
                        <i class="fa-solid fa-pen-nib fs-5 me-2"></i>
                        <h5 class="mb-0 fw-bold">ส่วนการพิจารณา</h5>
                    </div>
                    <div class="card-body p-4 bg-light">
                        <form action="process_approve.php" method="POST" id="approveForm">
                            <input type="hidden" name="leave_id" value="<?php echo htmlspecialchars($leave_id); ?>">
                            <input type="hidden" name="current_step" value="<?php echo htmlspecialchars($current_role_step); ?>">

                            <?php if ($current_role_step == 2): ?>
                            <div class="mb-4 bg-white p-3 rounded border border-info border-2">
                                <label class="form-label fw-bold text-info small"><i class="fa-solid fa-code-branch me-1"></i> กรณีมีผู้รักษาการแทนคณบดี (เลือกได้):</label>
                                <select name="final_signer_id" id="final_signer_id" class="form-select shadow-sm border-info">
                                    <option value="">-- ไม่ระบุ (ส่งให้คณบดีตาม Flow ปกติ) --</option>
                                    <?php 
                                    $signers = $pdo->query("SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, r.role_name 
                                                          FROM users u JOIN user_roles ur ON u.user_id = ur.user_id
                                                          JOIN roles r ON ur.role_id = r.role_id
                                                          WHERE r.role_name IN ('รองคณบดีฝ่ายบริหาร', 'รักษาการคณบดี', 'คณบดี')
                                                          ORDER BY r.role_id ASC, u.first_name ASC")->fetchAll();
                                    foreach($signers as $s) {
                                        $full_name = htmlspecialchars($s['prefix_name'] . $s['first_name'] . " " . $s['last_name']);
                                        echo "<option value='".htmlspecialchars($s['user_id'])."'>{$full_name} (สิทธิ์: {$s['role_name']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="mb-4">
                                <label class="form-label fw-bold small text-dark"><i class="fa-regular fa-comment-dots me-1"></i> ความเห็นเพิ่มเติม (ถ้ามี):</label>
                                <textarea name="comment" class="form-control shadow-sm border-secondary" rows="3" placeholder="ระบุเหตุผล ข้อเสนอแนะ หรือหมายเหตุ..."></textarea>
                            </div>

                            <div class="d-grid gap-3 mt-4">
                                <button type="submit" name="status" value="approved" class="btn btn-purple py-2 shadow fw-bold" onclick="return confirm('ยืนยันการลงนามเห็นชอบ / อนุมัติ เอกสารฉบับนี้?');">
                                    <i class="fa-solid fa-file-signature me-2"></i> ประทับตราเห็นชอบ / อนุมัติ
                                </button>
                                <button type="submit" name="status" value="rejected" class="btn btn-outline-danger py-2 fw-bold bg-white" onclick="return confirm('ยืนยันการ ไม่อนุมัติ และตีกลับเอกสารนี้?');">
                                    <i class="fa-solid fa-ban me-2"></i> ไม่อนุมัติ / ตีกลับเอกสาร
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-5 sticky-top" style="top: 20px; background: linear-gradient(145deg, #ffffff, #f0f0f0);">
                    <div class="card-body">
                        <?php if ($leave['status'] == 'approved'): ?>
                            <div class="avatar-circle bg-success text-white mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;"><i class="fa-solid fa-check-double"></i></div>
                            <h5 class="fw-bold text-success">อนุมัติเสร็จสมบูรณ์</h5>
                        <?php elseif ($leave['status'] == 'rejected'): ?>
                            <div class="avatar-circle bg-danger text-white mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;"><i class="fa-solid fa-xmark"></i></div>
                            <h5 class="fw-bold text-danger">เอกสารไม่อนุมัติ</h5>
                        <?php else: ?>
                            <div class="avatar-circle bg-warning text-dark mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;"><i class="fa-solid fa-hourglass-half"></i></div>
                            <h5 class="fw-bold text-warning text-dark">อยู่ระหว่างดำเนินการ (ขั้นที่ <?php echo $leave['current_step']; ?>)</h5>
                            <p class="small text-muted px-3 mt-2">เอกสารฉบับนี้อยู่ระหว่างรอการพิจารณาในขั้นตอนอื่น</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .border-gold { border: 2px solid #c5a028; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #39048f; color: white; }
    .avatar-circle { border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .leave-paper { max-width: 800px; min-height: 900px; font-family: 'Anuphan', sans-serif; }
    .status-stamp {
        position: absolute; top: 50px; right: 50px; font-size: 2rem; font-weight: bold;
        text-transform: uppercase; border: 4px solid; padding: 5px 15px; border-radius: 10px;
        transform: rotate(-15deg); opacity: 0.8; z-index: 10; pointer-events: none;
    }
    .stamp-approved { color: #198754; border-color: #198754; }
    .stamp-rejected { color: #dc3545; border-color: #dc3545; }
    .sig-space { height: 70px; display: flex; align-items: end; justify-content: center; border-bottom: 1px dotted #ccc; width: 180px; margin: 0 auto; padding-bottom: 5px; }
    .sig-space-sm { height: 50px; display: flex; align-items: end; justify-content: center; border-bottom: 1px dotted #ddd; width: 130px; margin: 0 auto; padding-bottom: 5px;}
    .sig-img { max-height: 65px; object-fit: contain; }
    .sig-img-sm { max-height: 45px; object-fit: contain; }
    .extra-small { font-size: 0.75rem; }
    .stat-table th, .stat-table td { border-color: #333; }
    .stat-table th { font-weight: bold; }
    .signer-name, .signer-pos { white-space: nowrap !important; font-size: 0.75rem !important; letter-spacing: -0.3px !important; width: 100%; display: block; }

    @media print {
        @page { size: A4 portrait; margin: 10mm 10mm; }
        body { background-color: white !important; font-size: 11pt !important; }
        .sidebar, .header, .navbar, .d-print-none, footer, .footer, #footer, .site-footer { display: none !important; }
        .col-md-9 { width: 100% !important; margin: 0 !important; padding: 0 !important; max-width: 100% !important; flex: 0 0 100% !important; }
        main { margin-top: 0 !important; padding: 0 !important; }
        .leave-paper { border: none !important; box-shadow: none !important; margin: 0 !important; padding: 0 10px !important; min-height: auto !important; }
        .mb-5, .mb-4, .mt-5, .mt-4, .pt-4, .pb-3, .p-5 { padding: 0.5rem !important; margin-bottom: 0.5rem !important; margin-top: 0.5rem !important; }
        .leave-body p { margin-bottom: 0.2rem !important; line-height: 1.4 !important; }
        .stat-table { font-size: 9pt !important; margin-bottom: 0 !important; }
        .stat-table th, .stat-table td { padding: 2px 5px !important; }
        .sig-space { height: 50px !important; }
        .sig-space-sm { height: 35px !important; margin-bottom: 0 !important; }
        .sig-img { max-height: 45px !important; }
        .sig-img-sm { max-height: 30px !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
        .col-print-3 { flex: 0 0 25% !important; max-width: 25% !important; padding: 0 5px !important; }
        .signer-name, .signer-pos { font-size: 8pt !important; margin-bottom: 0 !important; }
        .status-stamp { opacity: 1 !important; transform: rotate(-10deg) !important; top: 10px !important; right: 10px !important; font-size: 1.2rem !important; }
        .leave-paper, .leave-body, .stat-box, .row { page-break-inside: avoid !important; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>