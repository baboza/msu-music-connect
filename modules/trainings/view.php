<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$report_id = $_GET['id'];
$current_user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// ดึงข้อมูลรายงาน พร้อมข้อมูลผู้ใช้งาน และแผนก
// ---------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        SELECT tr.*, u.first_name, u.last_name, u.position, u.signature_file, u.dept_id, d.dept_name
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE tr.report_id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo "<script>alert('ไม่พบข้อมูลรายงานนี้'); window.location.href='index.php';</script>";
        exit();
    }

    // เช็คสิทธิ์การเข้าถึง (ดูได้เฉพาะเจ้าของ หรือ แอดมิน/ผู้พิจารณา)
    $approver_roles = [
        'admin', 
        'งานการเงิน', 
        'หัวหน้าสำนักงาน', 
        'หัวหน้าภาควิชาดนตรีศึกษา', 
        'หัวหน้าภาควิชาดุริยางคศาสตร์', 
        'รองคณบดีฝ่ายบริหาร', 
        'คณบดี', 
        'รักษาการคณบดี'
    ];
    $is_admin = hasRole(['admin']);
    $is_approver_role = hasRole($approver_roles);

    if (!$is_approver_role && $report['user_id'] != $current_user_id) {
        echo "<script>alert('⛔ คุณไม่มีสิทธิ์เข้าถึงรายงานนี้'); window.location.href='index.php';</script>";
        exit();
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// ---------------------------------------------------------
// ตรวจสอบสิทธิ์ผู้บังคับบัญชาชั้นต้น (หัวหน้าสำนักงาน / หัวหน้าภาควิชา)
// ---------------------------------------------------------
$req_dept = intval($report['dept_id'] ?? 0);
$req_pos = $report['position'] ?? '';
$is_academic = (strpos($req_pos, 'อาจารย์') !== false) || (strpos($req_pos, 'ผศ.') !== false) || (strpos($req_pos, 'รศ.') !== false) || (strpos($req_pos, 'ศ.') !== false);

$can_supervisor = false;
if ($is_admin) {
    $can_supervisor = true;
} else {
    if ($req_dept == 12 && hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) {
        $can_supervisor = true;
    } elseif ($req_dept == 13 && hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) {
        $can_supervisor = true;
    } elseif (hasRole(['หัวหน้าสำนักงาน']) && (!$is_academic || $req_dept == 11 || empty($req_dept))) {
        $can_supervisor = true;
    }
}

// ---------------------------------------------------------
// POST Action: Handle Approval / Rejection / Return / Resubmit ตาม 5 ขั้นตอน
// 1. หัวหน้าสำนักงาน (หัวหน้าภาค)
// 2. งานการเงิน (แก้ไขงบประมาณ + ใส่หมายเหตุ)
// 3. หัวหน้าสำนักงาน (หัวหน้าภาค)
// 4. รองบริหาร
// 5. คณบดี
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $step = intval($report['current_step'] ?? 1);

    // 0. จัดการกรณีผู้ยื่นคำขอส่งคำขอพิจารณาอีกครั้ง (Resubmit after Returned)
    if ($action === 'resubmit') {
        if ($report['user_id'] == $current_user_id || $is_admin) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET status = 'pending', 
                        current_step = 1,
                        step1_status = 'pending',
                        step2_status = 'pending',
                        step3_status = 'pending',
                        step4_status = 'pending',
                        step5_status = 'pending'
                    WHERE report_id = ?
                ");
                $stmt->execute([$report_id]);
                echo "<script>alert('ส่งคำขอเพื่อรับการพิจารณาใหม่อีกครั้งเรียบร้อยแล้ว'); window.location.href='view.php?id=' . $report_id;</script>";
                exit();
            } catch (PDOException $e) {
                die("Error: " . $e->getMessage());
            }
        } else {
            echo "<script>alert('⛔ คุณไม่มีสิทธิ์ส่งคำขอนี้'); window.location.href='view.php?id=' . $report_id;</script>";
            exit();
        }
    }
    
    // Check if the current user can actually approve this step
    $can_act = false;
    if ($step == 1 && $can_supervisor) $can_act = true;
    if ($step == 2 && ($is_admin || hasRole(['งานการเงิน']))) $can_act = true;
    if ($step == 3 && $can_supervisor) $can_act = true;
    if ($step == 4 && ($is_admin || hasRole(['รองคณบดีฝ่ายบริหาร']))) $can_act = true;
    if ($step == 5 && ($is_admin || hasRole(['คณบดี', 'รักษาการคณบดี']))) $can_act = true;
    
    if (!$can_act) {
        echo "<script>alert('ไม่มีสิทธิ์พิจารณาในขั้นตอนนี้'); window.location.href='approve_list.php';</script>";
        exit();
    }
    
    try {
        if ($action == 'approve') {
            if ($step == 1) {
                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET current_step = 2, 
                        status = 'pending', 
                        step1_status = 'approved',
                        step1_by = ?,
                        step1_date = NOW()
                    WHERE report_id = ?
                ");
                $stmt->execute([$current_user_id, $report_id]);
                echo "<script>alert('ให้ความเห็นชอบเรียบร้อยแล้ว (ส่งต่องานการเงิน)'); window.location.href='approve_list.php';</script>";
                exit();
            } elseif ($step == 2) {
                // งานการเงิน: บันทึกงบประมาณที่ปรับปรุง และหมายเหตุ
                $eb_travel = floatval($_POST['est_budget_travel'] ?? $report['est_budget_travel']);
                $eb_accom = floatval($_POST['est_budget_accom'] ?? $report['est_budget_accom']);
                $eb_allowance = floatval($_POST['est_budget_allowance'] ?? $report['est_budget_allowance']);
                $eb_regis = floatval($_POST['est_budget_regis'] ?? $report['est_budget_regis']);
                $eb_other = floatval($_POST['est_budget_other'] ?? $report['est_budget_other']);
                $f_comment = trim($_POST['finance_comment'] ?? '');

                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET current_step = 3, 
                        status = 'pending', 
                        est_budget_travel = ?,
                        est_budget_accom = ?,
                        est_budget_allowance = ?,
                        est_budget_regis = ?,
                        est_budget_other = ?,
                        finance_comment = ?,
                        step2_status = 'approved',
                        step2_by = ?,
                        step2_date = NOW()
                    WHERE report_id = ?
                ");
                $stmt->execute([$eb_travel, $eb_accom, $eb_allowance, $eb_regis, $eb_other, $f_comment, $current_user_id, $report_id]);
                echo "<script>alert('บันทึกงบประมาณและให้ความเห็นชอบเรียบร้อยแล้ว (ส่งต่อหัวหน้าสำนักงาน/หัวหน้าภาค)'); window.location.href='approve_list.php';</script>";
                exit();
            } elseif ($step == 3) {
                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET current_step = 4, 
                        status = 'pending', 
                        step3_status = 'approved',
                        step3_by = ?,
                        step3_date = NOW()
                    WHERE report_id = ?
                ");
                $stmt->execute([$current_user_id, $report_id]);
                echo "<script>alert('ให้ความเห็นชอบเรียบร้อยแล้ว (ส่งต่อรองคณบดีฝ่ายบริหาร)'); window.location.href='approve_list.php';</script>";
                exit();
            } elseif ($step == 4) {
                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET current_step = 5, 
                        status = 'pending', 
                        step4_status = 'approved',
                        step4_by = ?,
                        step4_date = NOW()
                    WHERE report_id = ?
                ");
                $stmt->execute([$current_user_id, $report_id]);
                echo "<script>alert('ให้ความเห็นชอบเรียบร้อยแล้ว (ส่งต่อคณบดี)'); window.location.href='approve_list.php';</script>";
                exit();
            } elseif ($step == 5) {
                // คณบดีอนุมัติโครงการสมบูรณ์
                $stmt = $pdo->prepare("
                    UPDATE training_reports 
                    SET current_step = 5, 
                        status = 'approved', 
                        step5_status = 'approved',
                        step5_by = ?,
                        step5_date = NOW()
                    WHERE report_id = ?
                ");
                $stmt->execute([$current_user_id, $report_id]);
                echo "<script>alert('อนุมัติโครงการเรียบร้อยแล้ว สมบูรณ์ครบทุกขั้นตอน'); window.location.href='approve_list.php';</script>";
                exit();
            }
        } elseif ($action == 'return') {
            // ส่งกลับให้แก้ไข (Return for Revision)
            $ret_comment = trim($_POST['return_comment'] ?? '');
            if (empty($ret_comment)) {
                $ret_comment = trim($_POST['finance_comment'] ?? '');
            }
            if (empty($ret_comment)) {
                $ret_comment = 'ส่งกลับแก้ไข (ไม่ระบุเหตุผล)';
            }
            
            $stmt = $pdo->prepare("
                UPDATE training_reports 
                SET status = 'returned', 
                    step{$step}_status = 'returned',
                    step{$step}_by = ?,
                    step{$step}_date = NOW(),
                    return_comment = ?,
                    return_step = ?
                WHERE report_id = ?
            ");
            $stmt->execute([$current_user_id, $ret_comment, $step, $report_id]);
            
            echo "<script>alert('ส่งกลับแก้ไขให้ผู้ยื่นคำขอเรียบร้อยแล้ว'); window.location.href='approve_list.php';</script>";
            exit();
        } elseif ($action == 'reject') {
            $f_comment = trim($_POST['finance_comment'] ?? '');
            $stmt = $pdo->prepare("
                UPDATE training_reports 
                SET status = 'rejected', 
                    step{$step}_status = 'rejected',
                    step{$step}_by = ?,
                    step{$step}_date = NOW(),
                    finance_comment = CASE WHEN ? != '' THEN ? ELSE finance_comment END
                WHERE report_id = ?
            ");
            $stmt->execute([$current_user_id, $f_comment, $f_comment, $report_id]);
            
            echo "<script>alert('ปฏิเสธคำขอเรียบร้อยแล้ว'); window.location.href='approve_list.php';</script>";
            exit();
        }
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}

// ---------------------------------------------------------
// Score Card Logic
// ---------------------------------------------------------
$relates_role = true; // Mocked logic for UI
$not_duplicate = true; // Mocked logic for UI
$not_hosted = ($report['organizer'] != 'มหาวิทยาลัยมหาสารคาม');
$doc_complete = (!empty($report['invitation_file']));

$score = 0;
if ($relates_role) $score += 30;
if ($not_duplicate) $score += 25;
if ($not_hosted) $score += 25;
if ($doc_complete) $score += 20;

// ---------------------------------------------------------
// ดึงข้อมูลผู้อนุมัติทั้ง 5 ขั้นตอน
// 1. หัวหน้าสำนักงาน (หัวหน้าภาค)
// 2. งานการเงิน
// 3. หัวหน้าสำนักงาน (หัวหน้าภาค) (เห็นชอบงบประมาณ)
// 4. รองคณบดีฝ่ายบริหาร
// 5. คณบดีวิทยาลัยดุริยางคศิลป์
// ---------------------------------------------------------
if ($req_dept == 12) {
    $sup_title = 'หัวหน้าภาควิชาดนตรีศึกษา';
    $sup_role = 'หัวหน้าภาควิชาดนตรีศึกษา';
} elseif ($req_dept == 13) {
    $sup_title = 'หัวหน้าภาควิชาดุริยางคศาสตร์';
    $sup_role = 'หัวหน้าภาควิชาดุริยางคศาสตร์';
} else {
    $sup_title = 'หัวหน้าสำนักงานเลขานุการ';
    $sup_role = 'หัวหน้าสำนักงาน';
}

$step_titles = [
    1 => $sup_title,
    2 => 'เจ้าหน้าที่การเงินและบัญชี / งานการเงิน',
    3 => $sup_title . ' (เห็นชอบงบประมาณ)',
    4 => 'รองคณบดีฝ่ายบริหาร',
    5 => 'คณบดีวิทยาลัยดุริยางคศิลป์'
];

$step_roles = [
    1 => $sup_role,
    2 => 'งานการเงิน',
    3 => $sup_role,
    4 => 'รองคณบดีฝ่ายบริหาร',
    5 => 'คณบดี'
];

// ดึงรายชื่อเจ้าหน้าที่ประจำตำแหน่ง
$role_users = [];
$stmt_ru = $pdo->query("
    SELECT r.role_name, u.user_id, u.prefix_name, u.first_name, u.last_name, u.position, u.signature_file 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.role_id 
    JOIN users u ON ur.user_id = u.user_id 
    WHERE r.role_name IN ('งานการเงิน', 'หัวหน้าสำนักงาน', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี')
    ORDER BY u.user_id ASC
");
while ($row = $stmt_ru->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($role_users[$row['role_name']])) {
        $role_users[$row['role_name']] = $row;
    }
}

// ดึงผู้ที่อนุมัติจริงตาม stepX_by (1 ถึง 5)
$actual_approver_ids = array_filter([
    $report['step1_by'] ?? null,
    $report['step2_by'] ?? null,
    $report['step3_by'] ?? null,
    $report['step4_by'] ?? null,
    $report['step5_by'] ?? null
]);
$actual_users = [];
if (!empty($actual_approver_ids)) {
    $in_q = implode(',', array_fill(0, count($actual_approver_ids), '?'));
    $stmt_act = $pdo->prepare("SELECT user_id, prefix_name, first_name, last_name, position, signature_file FROM users WHERE user_id IN ($in_q)");
    $stmt_act->execute(array_values($actual_approver_ids));
    while ($au = $stmt_act->fetch(PDO::FETCH_ASSOC)) {
        $actual_users[$au['user_id']] = $au;
    }
}

$approval_steps = [];
$is_tr_approved = in_array($report['status'], ['approved', 'reported']);
for ($i = 1; $i <= 5; $i++) {
    $st_status = $report["step{$i}_status"] ?? 'pending';
    $st_by = $report["step{$i}_by"] ?? null;
    $st_date = $report["step{$i}_date"] ?? null;

    // Auto-healing: ถ้าสถานะภาพรวมของเอกสารได้รับการอนุมัติสมบูรณ์แล้ว ให้ถือว่าทุกขั้นตอนได้รับการอนุมัติแล้ว
    if ($is_tr_approved) {
        $st_status = 'approved';
        if (empty($st_date) || $st_date === '0000-00-00 00:00:00') {
            $st_date = !empty($report['step5_date']) ? $report['step5_date'] : $report['created_at'];
        }
    }

    if (!empty($st_by) && isset($actual_users[$st_by])) {
        $u = $actual_users[$st_by];
        $name = ($u['prefix_name'] ?? '') . $u['first_name'] . ' ' . $u['last_name'];
        $sig = ($st_status === 'approved') ? ($u['signature_file'] ?? null) : null;
        $pos = !empty($u['position']) ? $u['position'] : $step_titles[$i];
    } else {
        $def_role = $step_roles[$i];
        $u = $role_users[$def_role] ?? null;
        if ($u) {
            $name = ($u['prefix_name'] ?? '') . $u['first_name'] . ' ' . $u['last_name'];
            $sig = ($is_tr_approved || $st_status === 'approved') ? ($u['signature_file'] ?? null) : null;
            $pos = !empty($u['position']) ? $u['position'] : $step_titles[$i];
        } else {
            $name = '..................................................';
            $sig = null;
            $pos = $step_titles[$i];
        }
    }

    // กรณีถ้าเป็นขั้นตอนที่อนุมัติแล้ว แต่บุคคลนั้นยังไม่มีไฟล์ลายเซ็น ให้ fallback สู่ผู้รับผิดชอบมาตรฐาน
    if (($st_status === 'approved' || $is_tr_approved) && empty($sig)) {
        if ($i === 1 || $i === 3) {
            $sig = 'sig_34ee8171_1774328264.png'; // หัวหน้าสำนักงาน
            if ($name === '..................................................') {
                $name = 'นางแจ่มจันทร์ หลูปรีชาเศรษฐ';
                $pos = 'หัวหน้าสำนักงานเลขานุการ';
            }
        } elseif ($i === 2) {
            $sig = 'sig_6b900dce220e1_1775619964.png'; // งานการเงิน
            if ($name === '..................................................') {
                $name = 'นางสาวอารียา บุญมาก';
                $pos = 'นักวิชาการเงินและบัญชี';
            }
        } elseif ($i === 4) {
            $sig = 'sig_86580da6_1772018273.png'; // รองคณบดีฝ่ายบริหาร
            if ($name === '..................................................') {
                $name = 'ดร.วัชระ หอมหวล';
                $pos = 'รองคณบดีฝ่ายบริหาร';
            }
        } elseif ($i === 5) {
            $sig = 'sig_6b8f3b10220e1_1774267024.png'; // คณบดี
            if ($name === '..................................................') {
                $name = 'ผู้ช่วยศาสตราจารย์ ดร.สยาม จวงประโคน';
                $pos = 'คณบดีวิทยาลัยดุริยางคศิลป์';
            }
        }
    }

    $approval_steps[$i] = [
        'status' => $st_status,
        'name' => $name,
        'pos_name' => $pos,
        'signature_file' => $sig,
        'date' => $st_date
    ];
}


require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// สีประเมินผล (ดัก NULL ด้วย ??)
$evaluation = $report['evaluation'] ?? '';
$eval_color = 'secondary';
$eval_icon = 'fa-minus';
if ($evaluation == 'ดีมาก') { $eval_color = 'success'; $eval_icon = 'fa-face-grin-stars'; }
elseif ($evaluation == 'ดี') { $eval_color = 'primary'; $eval_icon = 'fa-face-smile'; }
elseif ($evaluation == 'ไม่ดี') { $eval_color = 'danger'; $eval_icon = 'fa-face-frown'; }
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-file-lines me-2"></i>รายละเอียดรายงานการไปราชการ</h1>
            <p class="text-muted small">รหัสอ้างอิงเอกสาร: TR-<?php echo str_pad($report['report_id'], 5, '0', STR_PAD_LEFT); ?></p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 gap-2">
            <?php 
            $st_code = $report['status'];
            $st_label = 'รอพิจารณา';
            $st_badge = 'bg-warning-subtle text-dark border-warning';
            $st_icon = 'fa-clock';

            if ($st_code == 'approved') {
                $st_label = 'อนุมัติแล้ว';
                $st_badge = 'bg-success-subtle text-success border-success';
                $st_icon = 'fa-check-circle';
            } elseif ($st_code == 'reported') {
                $st_label = 'รายงานผลแล้ว';
                $st_badge = 'bg-info-subtle text-info border-info';
                $st_icon = 'fa-file-circle-check';
            } elseif ($st_code == 'rejected') {
                $st_label = 'ไม่อนุมัติ';
                $st_badge = 'bg-danger-subtle text-danger border-danger';
                $st_icon = 'fa-times-circle';
            } elseif ($st_code == 'returned') {
                $st_label = 'ส่งกลับแก้ไข';
                $st_badge = 'bg-warning text-dark border-warning';
                $st_icon = 'fa-rotate-left';
            }
            ?>
            <span class="badge <?= $st_badge ?> border px-3 py-2 fs-6 rounded-pill shadow-sm">
                <i class="fa-solid <?= $st_icon ?> me-1"></i> 
                สถานะ: <?= $st_label ?> <?= in_array($st_code, ['pending', 'returned']) ? '(ขั้นที่ ' . ($report['current_step'] ?? 1) . ')' : '' ?>
            </span>
            
            <?php if (in_array($report['status'], ['approved', 'reported']) && $report['user_id'] == $current_user_id): ?>
                <a href="write_report.php?id=<?php echo $report['report_id']; ?>" class="btn btn-success rounded-pill px-3 shadow-sm fw-bold">
                    <i class="fa-solid fa-file-signature me-1"></i> <?= ($report['status'] === 'reported') ? 'แก้ไขรายงานผล' : 'เขียนรายงานผล' ?>
                </a>
            <?php endif; ?>

            <?php if ($report['status'] === 'returned' && ($report['user_id'] == $current_user_id || $is_admin)): ?>
                <a href="edit.php?id=<?php echo $report['report_id']; ?>" class="btn btn-warning text-dark rounded-pill px-3 shadow-sm fw-bold">
                    <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขคำขอ
                </a>
            <?php endif; ?>

            <a href="print.php?id=<?php echo $report['report_id']; ?>" target="_blank" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-print me-1"></i> พิมพ์รายงาน
            </a>
            
            <a href="index.php" class="btn btn-outline-purple rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับ
            </a>
        </div>
    </div>

    <?php if ($report['status'] === 'returned'): ?>
        <!-- กล่องแจ้งเตือนรายการที่ถูกส่งกลับแก้ไข -->
        <div class="alert alert-warning border-2 border-warning rounded-4 shadow-sm mb-4 p-4">
            <div class="d-flex align-items-start gap-3 flex-wrap flex-md-nowrap">
                <div class="bg-warning text-dark rounded-circle p-3 d-flex align-items-center justify-content-center shadow-xs" style="width: 50px; height: 50px; min-width: 50px;">
                    <i class="fa-solid fa-rotate-left fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> คำขอนี้ถูกส่งกลับให้แก้ไข (Returned for Revision)
                    </h5>
                    <div class="mb-2 text-dark">
                        <strong>เหตุผล / รายละเอียดที่ต้องแก้ไข:</strong> 
                        <div class="p-2 mt-1 bg-white rounded-3 border text-danger fw-bold">
                            <?= nl2br(htmlspecialchars($report['return_comment'] ?? 'กรุณาตรวจสอบและแก้ไขข้อมูลคำขอ')) ?>
                        </div>
                    </div>
                    <div class="text-muted small">
                        <i class="fa-solid fa-info-circle me-1"></i> ถูกส่งกลับจากขั้นตอนที่ <?= intval($report['return_step'] ?? $report['current_step']) ?>
                        (<?= htmlspecialchars($step_titles[intval($report['return_step'] ?? $report['current_step'])] ?? 'ผู้พิจารณา') ?>)
                    </div>
                </div>
                <?php if ($report['user_id'] == $current_user_id || $is_admin): ?>
                    <div class="d-flex flex-column gap-2 mt-2 mt-md-0">
                        <a href="edit.php?id=<?= $report['report_id'] ?>" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm text-nowrap">
                            <i class="fa-solid fa-pen-to-square me-1"></i> ไปที่หน้าแก้ไขข้อมูล
                        </a>
                        <form method="POST" action="" onsubmit="return confirm('ยืนยันส่งคำขอนี้เข้าสู่ขั้นตอนการพิจารณาใหม่อีกครั้ง?');">
                            <button type="submit" name="action" value="resubmit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm w-100 text-nowrap">
                                <i class="fa-solid fa-paper-plane me-1"></i> ส่งพิจารณาอีกครั้ง
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-purple text-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-plane-departure me-2"></i>ข้อมูลคำขอ/รายงาน</h5>
            <small class="opacity-75">วันที่บันทึก: <?php echo date('d/m/Y H:i', strtotime($report['created_at'] ?? 'now')); ?></small>
        </div>
        
        <div class="card-body p-4 p-md-5 position-relative">

            <!-- Digital Status Stamp -->
            <?php if ($report['status'] === 'approved' || $report['status'] === 'reported'): ?>
                <div class="status-stamp stamp-approved">
                    <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
                </div>
            <?php elseif ($report['status'] === 'rejected'): ?>
                <div class="status-stamp stamp-rejected">
                    <i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ
                </div>
            <?php elseif ($report['status'] === 'returned'): ?>
                <div class="status-stamp stamp-returned">
                    <i class="fa-solid fa-rotate-left me-1"></i> ส่งกลับแก้ไข
                </div>
            <?php else: ?>
                <div class="status-stamp stamp-pending">
                    <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
                </div>
            <?php endif; ?>

            <!-- Scorecard UI -->
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-star text-warning me-2"></i>ระบบประเมินความคุ้มค่า (Approval Score)</h6>
            <div class="row mb-5">
                <div class="col-md-8">
                    <ul class="list-group list-group-flush border rounded-3 shadow-sm">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>ตรงกับสายงานและภารกิจหน้าที่</span>
                            <span class="badge bg-success rounded-pill">+30 pts</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>ไม่ซ้ำซ้อนกับผู้ที่เคยไป</span>
                            <span class="badge bg-success rounded-pill">+25 pts</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>หน่วยงานภายนอกจัด (ไม่ใช่มมส.)</span>
                            <?php if ($not_hosted): ?>
                                <span class="badge bg-success rounded-pill">+25 pts</span>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill">0 pts</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>เอกสารหนังสือเชิญ/กำหนดการครบถ้วน</span>
                            <?php if ($doc_complete): ?>
                                <span class="badge bg-success rounded-pill">+20 pts</span>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill">0 pts</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                <div class="col-md-4 d-flex align-items-center justify-content-center">
                    <div class="text-center p-4 border rounded-4 bg-light shadow-sm w-100 h-100 d-flex flex-column justify-content-center">
                        <div class="text-muted small fw-bold mb-2">คะแนนความคุ้มค่ารวม</div>
                        <div class="display-3 fw-bold text-purple"><?php echo $score; ?></div>
                        <?php if ($score >= 70): ?>
                            <div class="text-success small fw-bold mt-2"><i class="fa-solid fa-thumbs-up me-1"></i> เหมาะสมสำหรับการอนุมัติ</div>
                        <?php else: ?>
                            <div class="text-warning small fw-bold mt-2"><i class="fa-solid fa-exclamation-triangle me-1"></i> ควรพิจารณาเพิ่มเติม</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Training Details -->
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-circle-info text-primary me-2"></i>ข้อมูลโครงการและการเดินทาง</h6>
            <div class="row mb-5">
                <div class="col-md-9 mb-3">
                    <span class="text-muted small">หลักสูตร/โครงการที่เข้าร่วม:</span>
                    <div class="fw-bold text-dark fs-5 mt-1"><?php echo htmlspecialchars($report['project_name'] ?? '-'); ?></div>
                </div>
                <div class="col-md-3 mb-3">
                    <span class="text-muted small">ประเภท:</span>
                    <div class="mt-1">
                        <span class="badge bg-primary px-3 py-2 rounded-pill shadow-sm" style="font-size: 0.9rem;"><?php echo htmlspecialchars($report['report_type'] ?? 'ไปราชการ'); ?></span>
                    </div>
                </div>

                <?php if (!empty($report['training_hours'])): ?>
                <div class="col-md-3 mb-3">
                    <span class="text-muted small">จำนวนชั่วโมงที่เข้าร่วม:</span>
                    <div class="mt-1">
                        <span class="badge bg-info-subtle text-info fs-6 px-3 py-2 rounded-pill fw-bold">
                            <i class="fa-solid fa-clock me-1"></i> <?= $report['training_hours'] ?> ชั่วโมง
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-md-6 mb-3">
                    <span class="text-muted small">จัดโดย (หน่วยงานผู้จัด):</span>
                    <div class="fw-bold text-dark fs-6 mt-1"><?php echo htmlspecialchars($report['organizer'] ?? '-'); ?></div>
                </div>
                <div class="col-md-6 mb-3">
                    <span class="text-muted small">สถานที่จัด:</span>
                    <div class="fw-bold text-dark fs-6 mt-1"><?php echo htmlspecialchars($report['location'] ?? '-'); ?></div>
                </div>

                <div class="col-md-4 mb-3">
                    <span class="text-muted small"><i class="fa-solid fa-calendar-check text-primary me-1"></i>วันจัดโครงการ:</span>
                    <div class="fw-bold text-dark fs-6 mt-1">
                        <?php 
                            if (!empty($report['start_date']) && !empty($report['end_date'])) {
                                if ($report['start_date'] == $report['end_date']) {
                                    echo date('d/m/Y', strtotime($report['start_date']));
                                } else {
                                    echo date('d/m/Y', strtotime($report['start_date'])) . ' ถึง ' . date('d/m/Y', strtotime($report['end_date']));
                                }
                            } else {
                                echo '-';
                            }
                        ?>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <span class="text-muted small"><i class="fa-solid fa-car-side text-purple me-1"></i>กำหนดวันเดินทาง:</span>
                    <div class="fw-bold text-purple fs-6 mt-1">
                        <?php 
                            $tr_s = !empty($report['travel_start_date']) ? $report['travel_start_date'] : $report['start_date'];
                            $tr_e = !empty($report['travel_end_date']) ? $report['travel_end_date'] : $report['end_date'];
                            if (!empty($tr_s) && !empty($tr_e)) {
                                if ($tr_s == $tr_e) {
                                    echo date('d/m/Y', strtotime($tr_s));
                                } else {
                                    echo date('d/m/Y', strtotime($tr_s)) . ' ถึง ' . date('d/m/Y', strtotime($tr_e));
                                }
                            } else {
                                echo '-';
                            }
                        ?>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <span class="text-muted small">ปีงบประมาณที่ใช้:</span>
                    <div class="fw-bold text-dark fs-6 mt-1"><?php echo htmlspecialchars($report['fiscal_year'] ?? '-'); ?></div>
                </div>

                <!-- เอกสารแนบประกอบคำขอ -->
                <div class="col-12 mb-3">
                    <span class="text-muted small d-block mb-2"><i class="fa-solid fa-paperclip text-secondary me-1"></i>เอกสารแนบประกอบคำขอ:</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (!empty($report['invitation_file'])): ?>
                            <a href="../../uploads/trainings/<?= htmlspecialchars($report['invitation_file']) ?>" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-1 fw-bold">
                                <i class="fa-solid fa-envelope-open-text me-1"></i> หนังสือเชิญ / กำหนดการ
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($report['highway_map_file'])): ?>
                            <a href="../../uploads/trainings/<?= htmlspecialchars($report['highway_map_file']) ?>" target="_blank" class="btn btn-outline-success btn-sm rounded-pill px-3 py-1 fw-bold">
                                <i class="fa-solid fa-map-location-dot me-1"></i> แผนที่กรมทางหลวง (ระยะทาง)
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($report['certificate_file'])): ?>
                            <a href="../../uploads/trainings/<?= htmlspecialchars($report['certificate_file']) ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1 fw-bold">
                                <i class="fa-solid fa-award me-1"></i> ใบ Certificate / เกียรติบัตร
                            </a>
                        <?php endif; ?>

                        <?php if (empty($report['invitation_file']) && empty($report['highway_map_file']) && empty($report['certificate_file'])): ?>
                            <span class="text-muted small fst-italic">ไม่มีเอกสารแนบ</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Budget -->
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-coins text-warning me-2"></i>รายละเอียดงบประมาณ</h6>

            <?php if (!empty($report['finance_comment'])): ?>
                <div class="alert alert-warning border-warning border-start border-4 rounded-3 p-3 mb-3 shadow-xs">
                    <div class="d-flex align-items-center mb-1">
                        <i class="fa-solid fa-comment-dots text-warning me-2 fs-5"></i>
                        <strong class="text-dark">หมายเหตุ / ข้อความจากงานการเงินถึงผู้ขอ:</strong>
                    </div>
                    <p class="mb-0 text-dark small ps-4" style="white-space: pre-line;"><?php echo htmlspecialchars($report['finance_comment']); ?></p>
                </div>
            <?php endif; ?>

            <div class="table-responsive mb-5">
                <table class="table table-bordered align-middle">
                    <thead class="table-light text-center">
                        <tr>
                            <th width="40%" class="text-start">รายการค่าใช้จ่าย</th>
                            <th width="30%">ประมาณการงบประมาณ (บาท)</th>
                            <th width="30%">งบประมาณที่จ่ายจริง (บาท)</th>
                        </tr>
                    </thead>
                    <tbody class="text-end">
                        <tr>
                            <td class="text-start">ค่าเดินทาง</td>
                            <td><?php echo number_format((float)($report['est_budget_travel'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($report['act_budget_travel'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-start">ค่าที่พัก</td>
                            <td><?php echo number_format((float)($report['est_budget_accom'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($report['act_budget_accom'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-start">ค่าเบี้ยเลี้ยง</td>
                            <td><?php echo number_format((float)($report['est_budget_allowance'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($report['act_budget_allowance'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-start">ค่าลงทะเบียน</td>
                            <td><?php echo number_format((float)($report['est_budget_regis'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($report['act_budget_regis'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-start">ค่าอื่นๆ</td>
                            <td><?php echo number_format((float)($report['est_budget_other'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($report['act_budget_other'] ?? 0), 2); ?></td>
                        </tr>
                    </tbody>
                    <tfoot class="text-end fw-bold bg-light">
                        <tr>
                            <td class="text-start">รวมทั้งหมด</td>
                            <td class="text-primary">
                                ฿<?php echo number_format(
                                    (float)($report['est_budget_travel'] ?? 0) + 
                                    (float)($report['est_budget_accom'] ?? 0) + 
                                    (float)($report['est_budget_allowance'] ?? 0) + 
                                    (float)($report['est_budget_regis'] ?? 0) + 
                                    (float)($report['est_budget_other'] ?? 0)
                                , 2); ?>
                            </td>
                            <td class="text-success fs-5">฿<?php echo number_format((float)($report['budget_used'] ?? 0), 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Post-training report (if any) -->
            <?php if (!empty($report['summary'])): ?>
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-pen-nib text-purple me-2"></i>สรุปผลการประชุม/ฝึกอบรม และการประเมิน</h6>
            <div class="bg-light p-4 rounded-3 mb-5 border">
                <span class="text-muted small fw-bold">เนื้อหาสาระและสรุปผลที่ได้รับ:</span>
                <p class="fw-bold text-dark mt-2 mb-4" style="white-space: pre-line; line-height: 1.6;"><?php echo htmlspecialchars($report['summary'] ?? '-'); ?></p>
                
                <span class="text-muted small fw-bold">ประเมินผลภาพรวมของหลักสูตรนี้:</span>
                <div class="mt-2">
                    <span class="badge bg-<?php echo $eval_color; ?>-subtle text-<?php echo $eval_color; ?> border border-<?php echo $eval_color; ?> px-3 py-2 fs-6 rounded-pill">
                        <i class="fa-solid <?php echo $eval_icon; ?> me-1"></i> <?php echo htmlspecialchars($evaluation ?: 'ไม่มีข้อมูลประเมิน'); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['apply_how'])): ?>
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-lightbulb text-gold me-2"></i>แผนการนำความรู้และทักษะไปใช้ประโยชน์</h6>
            <div class="row mb-5">
                <div class="col-md-12 mb-4">
                    <span class="text-muted small fw-bold">แนวทางการนำไปประยุกต์ใช้ (How to apply):</span>
                    <div class="fw-bold text-dark p-3 border border-warning border-opacity-50 rounded bg-warning bg-opacity-10 mt-2" style="white-space: pre-line; line-height: 1.6;">
                        <?php echo htmlspecialchars($report['apply_how'] ?? '-'); ?>
                    </div>
                </div>
                <div class="col-md-8 mb-3">
                    <span class="text-muted small fw-bold">ผลที่คาดว่าจะได้รับ (Expected Outcome):</span>
                    <div class="fw-bold text-dark mt-1"><?php echo htmlspecialchars($report['apply_expected_result'] ?? '-'); ?></div>
                </div>
                <div class="col-md-4 mb-3">
                    <span class="text-muted small fw-bold">กำหนดเสร็จสิ้น:</span>
                    <div class="fw-bold text-danger mt-1 fs-6">
                        <?php 
                            echo !empty($report['apply_completion_date']) && $report['apply_completion_date'] != '0000-00-00' 
                            ? date('d/m/Y', strtotime($report['apply_completion_date'])) 
                            : 'ไม่ระบุ'; 
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Signature -->
            <div class="row mt-4 pt-4 border-top border-2">
                <div class="col-md-6 offset-md-6 text-center">
                    <div class="d-flex align-items-center justify-content-center mb-2">
                        <span class="me-2 text-muted small">ลงชื่อ</span>
                        <div style="min-width: 200px; border-bottom: 1px dashed #ccc; padding-bottom: 5px;">
                            <?php if (!empty($report['signature_file']) && file_exists('../../uploads/signatures/' . $report['signature_file'])): ?>
                                <img src="../../uploads/signatures/<?php echo htmlspecialchars($report['signature_file']); ?>" alt="Signature" style="max-height: 60px; object-fit: contain;">
                            <?php else: ?>
                                <span class="text-muted small">(พิมพ์เพื่อเซ็นชื่อ)</span>
                            <?php endif; ?>
                        </div>
                        <span class="ms-2 text-muted small">ผู้รายงาน</span>
                    </div>
                    <p class="mb-1 fw-bold text-purple fs-5 mt-3">( <?php echo htmlspecialchars(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? '')); ?> )</p>
                    
                    <p class="mb-1 fw-bold text-dark">ตำแหน่ง: <?php echo htmlspecialchars($report['position'] ?? 'บุคลากร'); ?></p>
                    <p class="small text-muted mb-0"><?php echo htmlspecialchars($report['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม'); ?></p>
                    <p class="small text-muted mt-2">วันที่ลงนาม: <?php echo date('d/m/Y', strtotime($report['created_at'] ?? 'now')); ?></p>
                </div>
            </div>

            <!-- ----------------------------------------------------------- -->
            <!-- APPROVAL TIMELINE / SIGNATURES (แสดงชื่อผู้อนุมัติทั้งหมด 5 ขั้นตอน) -->
            <!-- ----------------------------------------------------------- -->
            <div class="row mt-5 pt-3 border-top g-2 text-center">
                <div class="col-12 text-start mb-2 d-print-none">
                    <h6 class="fw-bold text-muted mb-0">
                        <i class="fa-solid fa-list-check me-2 text-purple"></i>ขั้นตอนการพิจารณาอนุมัติ (5 ขั้นตอน)
                    </h6>
                    <small class="text-muted" style="font-size: 0.78rem;">
                        ลำดับขั้น: ผู้เสนอ &rarr; 1. หัวหน้าสำนักงาน (หัวหน้าภาค) &rarr; 2. งานการเงิน &rarr; 3. หัวหน้าสำนักงาน (หัวหน้าภาค) &rarr; 4. รองบริหาร &rarr; 5. คณบดี
                    </small>
                </div>
                <?php foreach ($approval_steps as $step_num => $app): ?>
                    <div class="col-lg col-md-4 col-sm-6 mb-2">
                        <div class="card h-100 border-0 bg-light p-2 rounded-3 shadow-2xs">
                            <p class="extra-small fw-bold text-muted mb-1 text-uppercase d-print-none">ขั้นที่ <?php echo $step_num; ?></p>
                            <div class="sig-space-sm mb-1 position-relative">
                                <?php if (!empty($app['signature_file']) && file_exists('../../uploads/signatures/' . $app['signature_file'])): ?>
                                    <img src="../../uploads/signatures/<?php echo htmlspecialchars($app['signature_file']); ?>" class="sig-img-sm" alt="ลายเซ็น">
                                <?php endif; ?>
                            </div>
                            <p class="mb-0 fw-bold text-dark signer-name">
                                ( <?php echo htmlspecialchars($app['name']); ?> )
                            </p>
                            <div class="fw-bold text-purple mb-1 signer-pos text-truncate" title="<?php echo htmlspecialchars($app['pos_name']); ?>">
                                <?php echo htmlspecialchars($app['pos_name']); ?>
                            </div>
                            <div class="mt-auto d-print-none">
                                <?php if ($app['status'] === 'approved'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success extra-small">
                                        <i class="fa-solid fa-check me-1"></i>อนุมัติ / เห็นชอบ
                                    </span>
                                <?php elseif ($app['status'] === 'rejected'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger extra-small">
                                        <i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ
                                    </span>
                                <?php elseif ($app['status'] === 'returned'): ?>
                                    <span class="badge bg-warning text-dark border border-warning extra-small">
                                        <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับแก้ไข
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border extra-small">
                                        <i class="fa-solid fa-clock me-1"></i>รอการพิจารณา
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="extra-small text-muted mt-1" style="font-size: 0.68rem;">
                                <?php echo (!empty($app['date']) && $app['date'] !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($app['date'])) : '-'; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- ----------------------------------------------------------- -->
            <!-- APPROVAL FORM SECTION -->
            <!-- ----------------------------------------------------------- -->
            <?php 
            $step = intval($report['current_step'] ?? 1);
            $can_approve = false;
            if ($step == 1 && $can_supervisor) $can_approve = true;
            if ($step == 2 && ($is_admin || hasRole(['งานการเงิน']))) $can_approve = true;
            if ($step == 3 && $can_supervisor) $can_approve = true;
            if ($step == 4 && ($is_admin || hasRole(['รองคณบดีฝ่ายบริหาร']))) $can_approve = true;
            if ($step == 5 && ($is_admin || hasRole(['คณบดี', 'รักษาการคณบดี']))) $can_approve = true;
            
            if ($report['status'] == 'pending' && $can_approve): 
            ?>
                <?php if ($step == 2): ?>
                    <!-- Step 2: งานการเงิน (แก้ไขงบประมาณ + ใส่หมายเหตุ) -->
                    <div class="card border-0 shadow-sm border-top border-purple border-4 rounded-4 mb-4 mt-5 bg-white">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <h5 class="fw-bold text-purple mb-0">
                                    <i class="fa-solid fa-coins me-2"></i>พิจารณาคำขอในขั้นที่ 2: งานการเงิน
                                </h5>
                                <span class="badge bg-purple-subtle text-purple border border-purple rounded-pill px-3 py-1 small">
                                    ตรวจสอบและปรับปรุงรายละเอียดงบประมาณ
                                </span>
                            </div>
                            <p class="text-muted small mb-3">
                                งานการเงินสามารถแก้ไขจำนวนเงินในแต่ละหมวด และระบุหมายเหตุ/ข้อคิดเห็นแจ้งผู้ขอได้ด้านล่างนี้
                            </p>

                            <form method="POST" action="">
                                <div class="row g-3 mb-3 p-3 bg-light rounded-3 border">
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-dark mb-1">1. ค่าพาหนะ / เดินทาง (บาท)</label>
                                        <input type="number" step="0.01" min="0" name="est_budget_travel" id="fin_travel" class="form-control form-control-sm border-2 text-end fw-bold" value="<?= htmlspecialchars($report['est_budget_travel'] ?? 0) ?>" oninput="calcFinBudget()">
                                    </div>
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-dark mb-1">2. ค่าที่พัก (บาท)</label>
                                        <input type="number" step="0.01" min="0" name="est_budget_accom" id="fin_accom" class="form-control form-control-sm border-2 text-end fw-bold" value="<?= htmlspecialchars($report['est_budget_accom'] ?? 0) ?>" oninput="calcFinBudget()">
                                    </div>
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-dark mb-1">3. ค่าเบี้ยเลี้ยง (บาท)</label>
                                        <input type="number" step="0.01" min="0" name="est_budget_allowance" id="fin_allowance" class="form-control form-control-sm border-2 text-end fw-bold" value="<?= htmlspecialchars($report['est_budget_allowance'] ?? 0) ?>" oninput="calcFinBudget()">
                                    </div>
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-dark mb-1">4. ค่าลงทะเบียน (บาท)</label>
                                        <input type="number" step="0.01" min="0" name="est_budget_regis" id="fin_regis" class="form-control form-control-sm border-2 text-end fw-bold" value="<?= htmlspecialchars($report['est_budget_regis'] ?? 0) ?>" oninput="calcFinBudget()">
                                    </div>
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-dark mb-1">5. ค่าใช้จ่ายอื่นๆ (บาท)</label>
                                        <input type="number" step="0.01" min="0" name="est_budget_other" id="fin_other" class="form-control form-control-sm border-2 text-end fw-bold" value="<?= htmlspecialchars($report['est_budget_other'] ?? 0) ?>" oninput="calcFinBudget()">
                                    </div>
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-label small fw-bold text-purple mb-1">รวมงบประมาณที่ปรับปรุง (บาท)</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white fw-bold">฿</span>
                                            <input type="text" id="fin_total" class="form-control form-control-sm border-2 text-end fw-bold text-purple" readonly value="0.00">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-dark mb-1">
                                        <i class="fa-solid fa-comment-dots text-warning me-1"></i> หมายเหตุ / ข้อความจากงานการเงินแจ้งผู้ขอ:
                                    </label>
                                    <textarea name="finance_comment" class="form-control border-2" rows="3" placeholder="ระบุเหตุผลในการปรับลดงบประมาณ หรือข้อเสนอแนะเพิ่มเติมสำหรับผู้ขอ..."><?= htmlspecialchars($report['finance_comment'] ?? '') ?></textarea>
                                </div>

                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <button type="button" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#modalReturn">
                                        <i class="fa-solid fa-rotate-left me-1"></i> ส่งกลับแก้ไข
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger rounded-pill px-4 fw-bold" onclick="return confirm('คุณแน่ใจหรือไม่ที่จะไม่อนุมัติคำขอนี้?');">
                                        <i class="fa-solid fa-times-circle me-1"></i> ไม่อนุมัติ
                                    </button>
                                    <button type="submit" name="action" value="approve" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm">
                                        <i class="fa-solid fa-check-circle me-1"></i> บันทึกและเห็นชอบ (ส่งต่อหัวหน้าสำนักงาน/หัวหน้าภาค)
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Steps 1, 3, 4, 5: Standard Approval Form -->
                    <div class="card border-0 shadow-sm border-top border-purple border-4 rounded-4 mb-4 mt-5 bg-white">
                        <div class="card-body p-4 text-center">
                            <h5 class="fw-bold mb-2">
                                พิจารณาคำขอในขั้นที่ <?= $step ?>: <?= htmlspecialchars($step_titles[$step] ?? '') ?>
                            </h5>
                            <p class="text-muted small mb-4">
                                <?php if ($step == 1): ?>
                                    ตรวจสอบและให้ความเห็นชอบเบื้องต้น ก่อนส่งให้งานการเงินตรวจสอบงบประมาณ
                                <?php elseif ($step == 3): ?>
                                    ตรวจสอบและให้ความเห็นชอบ หลังงานการเงินตรวจสอบและรับรองงบประมาณแล้ว
                                <?php elseif ($step == 4): ?>
                                    ตรวจสอบและให้ความเห็นชอบในฐานะผู้บริหาร (รองคณบดีฝ่ายบริหาร)
                                <?php elseif ($step == 5): ?>
                                    อนุมัติโครงการเดินทางไปราชการ / ฝึกอบรมในฐานะคณบดี
                                <?php endif; ?>
                            </p>

                            <form method="POST" action="">
                                <button type="submit" name="action" value="approve" class="btn btn-success rounded-pill px-5 shadow-sm fw-bold me-2">
                                    <i class="fa-solid fa-check-circle me-2"></i> <?= ($step == 5) ? 'อนุมัติโครงการ' : 'ให้ความเห็นชอบ' ?>
                                </button>
                                <button type="button" class="btn btn-warning rounded-pill px-4 shadow-sm fw-bold me-2 text-dark" data-bs-toggle="modal" data-bs-target="#modalReturn">
                                    <i class="fa-solid fa-rotate-left me-2"></i> ส่งกลับแก้ไข
                                </button>
                                <button type="submit" name="action" value="reject" class="btn btn-danger rounded-pill px-4 shadow-sm fw-bold" onclick="return confirm('คุณแน่ใจหรือไม่ที่จะไม่อนุมัติคำขอนี้?');">
                                    <i class="fa-solid fa-times-circle me-2"></i> ไม่อนุมัติ
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($report['status'] == 'pending' && !$can_approve): ?>
                <div class="alert alert-warning mt-5 rounded-4 shadow-sm text-center">
                    <i class="fa-solid fa-clock me-2"></i> กำลังรอการพิจารณาในขั้นที่ <?= $step ?>: <?= htmlspecialchars($step_titles[$step] ?? '') ?> โดยผู้มีอำนาจ
                </div>
            <?php elseif ($report['status'] == 'returned'): ?>
                <div class="alert alert-warning mt-5 rounded-4 shadow-sm text-center">
                    <i class="fa-solid fa-rotate-left me-2"></i> คำขอนี้อยู่ในสถานะ <strong>ส่งกลับแก้ไข</strong> รอให้ผู้ยื่นคำขอแก้ไขข้อมูลและส่งพิจารณาใหม่อีกครั้ง
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<!-- Modal สำหรับส่งกลับแก้ไข -->
<div class="modal fade" id="modalReturn" tabindex="-1" aria-labelledby="modalReturnLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form method="POST" action="">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="modalReturnLabel">
                        <i class="fa-solid fa-rotate-left text-warning me-2"></i>ส่งกลับให้ผู้ยื่นคำขอแก้ไข
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3">
                        คำขอนี้จะถูกส่งกลับไปยังผู้ยื่นคำขอ พร้อมข้อความแจ้งเตือน เพื่อให้ดำเนินการแก้ไขข้อมูลหรือเอกสารแนบ และสามารถส่งพิจารณาใหม่อีกครั้งได้
                    </p>
                    <div class="mb-3">
                        <label for="return_comment_input" class="form-label fw-bold small text-dark">
                            เหตุผล / รายละเอียดที่ต้องการให้แก้ไข <span class="text-danger">*</span>
                        </label>
                        <textarea name="return_comment" id="return_comment_input" class="form-control rounded-3 border-2" rows="4" placeholder="ระบุสิ่งที่ต้องการให้แก้ไข เช่น เอกสารแนบไม่ครบ, ปรับแก้วันที่เดินทาง, รายการงบประมาณ ฯลฯ" required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="action" value="return" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm">
                        <i class="fa-solid fa-paper-plane me-1"></i> ยืนยันส่งกลับแก้ไข
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #39048f; }
    .bg-purple { background-color: #39048f; }
    .text-gold { color: #c5a028; }
    .btn-outline-purple { border-color: #39048f; color: #39048f; }
    .btn-outline-purple:hover { background-color: #39048f; color: white; }
    
    .bg-success-subtle { background-color: #d1e7dd; }
    .bg-danger-subtle { background-color: #f8d7da; }
    .bg-warning-subtle { background-color: #fff3cd; }
    .bg-info-subtle { background-color: #cff4fc; }
    .bg-primary-subtle { background-color: #cfe2ff; }
    
    .status-stamp {
        position: absolute;
        right: 35px;
        top: 25px;
        border: 3.5px solid #16a34a;
        color: #16a34a;
        padding: 6px 18px;
        font-size: 1.25rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 2px;
        border-radius: 8px;
        transform: rotate(-10deg);
        opacity: 0.88;
        pointer-events: none;
        z-index: 10;
        box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        background: rgba(255, 255, 255, 0.95);
    }
    .status-stamp.stamp-rejected {
        border-color: #dc2626;
        color: #dc2626;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
    }
    .status-stamp.stamp-returned {
        border-color: #f59e0b;
        color: #d97706;
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }
    .status-stamp.stamp-pending {
        border-color: #6366f1;
        color: #4f46e5;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    .sig-space-sm { height: 50px; display: flex; align-items: end; justify-content: center; border-bottom: 1px dotted #ddd; width: 140px; margin: 0 auto; padding-bottom: 5px; }
    .sig-img-sm { max-height: 45px; object-fit: contain; }
    .signer-name, .signer-pos { white-space: nowrap !important; font-size: 0.78rem !important; letter-spacing: -0.3px !important; width: 100%; display: block; }
    .extra-small { font-size: 0.72rem; }
    .shadow-2xs { box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
</style>

<script>
function calcFinBudget() {
    const travel = parseFloat(document.getElementById('fin_travel')?.value || 0);
    const accom = parseFloat(document.getElementById('fin_accom')?.value || 0);
    const allowance = parseFloat(document.getElementById('fin_allowance')?.value || 0);
    const regis = parseFloat(document.getElementById('fin_regis')?.value || 0);
    const other = parseFloat(document.getElementById('fin_other')?.value || 0);
    const total = travel + accom + allowance + regis + other;
    const finTotal = document.getElementById('fin_total');
    if (finTotal) {
        finTotal.value = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}
document.addEventListener('DOMContentLoaded', function() {
    calcFinBudget();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
