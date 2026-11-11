<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("ไม่พบรหัสอ้างอิงเอกสาร");
}

$report_id = $_GET['id'];
$current_user_id = $_SESSION['user_id'];

// ดึงข้อมูลรายงาน พร้อมข้อมูลผู้ใช้งาน และแผนก
try {
    $stmt = $pdo->prepare("
        SELECT tr.*, u.first_name, u.last_name, u.prefix_name, u.position, u.signature_file, u.dept_id, d.dept_name
        FROM training_reports tr
        JOIN users u ON tr.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE tr.report_id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        die("ไม่พบข้อมูลรายงานในระบบ");
    }

    // เช็คสิทธิ์การเข้าถึง (ดูได้เฉพาะเจ้าของ หรือ แอดมิน/ผู้พิจารณา)
    $is_admin = hasRole(['admin', 'งานการเงิน', 'หัวหน้าสำนักงาน', 'หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี']);
    if (!$is_admin && $report['user_id'] != $current_user_id) {
        die("คุณไม่มีสิทธิ์เข้าถึงเอกสารฉบับนี้");
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// ฟังก์ชันแปลงวันที่เป็นภาษาไทยแบบเป็นทางการ
function thaiDateFull($date) {
    if (!$date || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '-';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $ts = strtotime($date);
    $d = date('j', $ts);
    $m = $thai_months[intval(date('n', $ts))];
    $y = date('Y', $ts) + 543;
    return "$d $m $y";
}

function thaiDateShort($date) {
    if (!$date || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '-';
    $thai_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    $d = date('j', $ts);
    $m = $thai_months[intval(date('n', $ts))];
    $y = date('Y', $ts) + 543;
    return "$d $m $y";
}

// คำนวณจำนวนวัน
$days_count = 1;
if (!empty($report['start_date']) && !empty($report['end_date'])) {
    $s = new DateTime($report['start_date']);
    $e = new DateTime($report['end_date']);
    $days_count = max(1, $s->diff($e)->days + 1);
}

// คำนวณงบประมาณ
$est_travel = floatval($report['est_budget_travel'] ?? 0);
$est_accom = floatval($report['est_budget_accom'] ?? 0);
$est_allowance = floatval($report['est_budget_allowance'] ?? 0);
$est_regis = floatval($report['est_budget_regis'] ?? 0);
$est_other = floatval($report['est_budget_other'] ?? 0);
$est_total = $est_travel + $est_accom + $est_allowance + $est_regis + $est_other;

$act_travel = floatval($report['act_budget_travel'] ?? 0);
$act_accom = floatval($report['act_budget_accom'] ?? 0);
$act_allowance = floatval($report['act_budget_allowance'] ?? 0);
$act_regis = floatval($report['act_budget_regis'] ?? 0);
$act_other = floatval($report['act_budget_other'] ?? 0);
$act_total = floatval($report['budget_used'] ?? ($act_travel + $act_accom + $act_allowance + $act_regis + $act_other));

// ดึงข้อมูลสายอนุมัติ 5 ขั้นตอน
$req_dept = intval($report['dept_id'] ?? 0);
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
    3 => $sup_title,
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

$report_signed_date = !empty($report['updated_at']) ? $report['updated_at'] : (!empty($report['created_at']) ? $report['created_at'] : date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แบบรายงานผลการไปราชการ/อบรม - <?= htmlspecialchars($report['project_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-purple: #59369a;
            --primary-dark: #39048f;
            --text-main: #1e293b;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #475569;
            color: var(--text-main);
            font-size: 12.5pt;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }

        /* Top Action Bar (ซ่อนตอนพิมพ์) */
        .top-action-bar {
            background-color: #1e293b;
            color: #fff;
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .btn-print {
            background-color: var(--primary-purple);
            color: #fff;
            font-weight: 600;
            border-radius: 50rem;
            padding: 6px 22px;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-print:hover {
            background-color: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        /* A4 Sheet Container */
        .a4-sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 13mm 16mm 13mm 16mm;
            margin: 20px auto 35px auto;
            background: #ffffff;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            border-radius: 2px;
            box-sizing: border-box;
            position: relative;
        }

        /* Official Document Header */
        .doc-header {
            border-bottom: 2px solid #334155;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .doc-logo {
            height: 60px;
            width: auto;
            object-fit: contain;
        }

        /* Section Title Styling */
        .sec-heading {
            font-size: 13pt;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin-top: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        .sec-num {
            display: inline-block;
            background-color: #f1f5f9;
            color: var(--primary-purple);
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 4px;
            margin-right: 7px;
            font-size: 11pt;
            border: 1px solid #e2e8f0;
        }

        /* Key-Value Information Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 5px 12px;
            font-size: 12pt;
        }
        .info-cell {
            line-height: 1.4;
        }
        .info-label {
            font-weight: 600;
            color: #334155;
            white-space: nowrap;
        }
        .info-value {
            color: #0f172a;
            font-weight: 500;
        }

        /* Formal Tables */
        .table-doc {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5pt;
            margin-top: 6px;
            margin-bottom: 10px;
        }
        .table-doc th, .table-doc td {
            border: 1px solid #94a3b8;
            padding: 4px 7px;
            vertical-align: middle;
        }
        .table-doc thead th {
            background-color: #f8fafc !important;
            color: #0f172a;
            font-weight: 700;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Text Boxes & Evaluations */
        .text-content-box {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 12pt;
            line-height: 1.55;
            text-align: justify;
            text-indent: 2.5em;
            white-space: pre-line;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .eval-box {
            display: flex;
            justify-content: center;
            gap: 24px;
            padding: 6px 10px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-top: 5px;
            font-size: 11.5pt;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .checkbox-symbol {
            font-size: 14pt;
            line-height: 1;
            vertical-align: middle;
            margin-right: 4px;
            font-weight: bold;
        }

        .status-stamp {
            position: absolute;
            right: 25mm;
            top: 18mm;
            border: 3.5px solid #15803d;
            color: #15803d;
            padding: 4px 14px;
            font-size: 13pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border-radius: 6px;
            transform: rotate(-9deg);
            opacity: 0.88;
            pointer-events: none;
            z-index: 10;
            box-shadow: 0 0 0 1px rgba(21, 128, 61, 0.2);
            background: rgba(255, 255, 255, 0.95);
        }
        .status-stamp.stamp-rejected {
            border-color: #b91c1c;
            color: #b91c1c;
            box-shadow: 0 0 0 1px rgba(185, 28, 28, 0.2);
        }

        /* Signature Elements */
        .sig-container {
            min-height: 45px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            border-bottom: 1px dotted #64748b;
            width: 160px;
            margin: 0 auto;
            padding-bottom: 2px;
        }
        .sig-img {
            max-height: 42px;
            max-width: 150px;
            object-fit: contain;
        }

        /* 5-Step Approval Table (แก้ปัญหาล้นกระดาษ) */
        .table-approval {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
            margin-top: 6px;
            margin-bottom: 6px;
        }
        .table-approval th {
            background-color: #f1f5f9 !important;
            border: 1px solid #94a3b8;
            padding: 5px 6px;
            font-weight: 700;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .table-approval td {
            border: 1px solid #94a3b8;
            padding: 4px 6px;
            vertical-align: middle;
        }

        .avoid-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .top-action-bar, .no-print {
                display: none !important;
            }
            .a4-sheet {
                width: 100% !important;
                min-height: auto !important;
                padding: 0 !important;
                margin: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            @page {
                size: A4 portrait;
                margin: 12mm 15mm 12mm 15mm;
            }
        }
    </style>
</head>
<body>

    <!-- แถบเมนูด้านบน (ซ่อนตอนพิมพ์) -->
    <div class="top-action-bar d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold fs-6">
                <i class="fa-solid fa-print me-2 text-warning"></i> พิมพ์แบบรายงานผลการไปราชการ / ฝึกอบรม
            </span>
            <span class="badge bg-purple-subtle text-white border border-light-subtle rounded-pill px-3 py-1 small">
                TR-<?= str_pad($report['report_id'], 5, '0', STR_PAD_LEFT) ?>
            </span>
            <span class="badge <?= ($report['status'] === 'reported') ? 'bg-success' : (($report['status'] === 'returned') ? 'bg-warning text-dark' : 'bg-warning text-dark') ?> rounded-pill px-3 py-1 small">
                <?= ($report['status'] === 'reported') ? '✓ รายงานผลสมบูรณ์แล้ว' : (($report['status'] === 'returned') ? '↩ ส่งกลับแก้ไข' : '⏳ อยู่ระหว่างดำเนินการ') ?>
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-print shadow-sm">
                <i class="fa-solid fa-print me-1"></i> สั่งพิมพ์เอกสาร
            </button>
            <button onclick="window.close()" class="btn btn-outline-light rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
            </button>
        </div>
    </div>

    <!-- กระดาษเอกสารขนาด A4 -->
    <div class="a4-sheet">

        <!-- Header ส่วนหัวเอกสารทางการ -->
        <div class="doc-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="../../uploads/logo.png" alt="Logo" class="doc-logo" onerror="this.src='../../includes/logo.png'">
                <div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-size: 16pt;">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</h5>
                    <div class="text-secondary" style="font-size: 11pt;">College of Music, Mahasarakham University</div>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-bold text-dark" style="font-size: 11pt;">เลขที่อ้างอิง: TR-<?= str_pad($report['report_id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div class="text-muted" style="font-size: 10pt;">ปีงบประมาณ: <?= htmlspecialchars($report['fiscal_year'] ?? '-') ?></div>
            </div>
        </div>

        <!-- ชื่อเอกสารหลัก -->
        <div class="text-center mb-3">
            <h4 class="fw-bold mb-1" style="font-size: 16pt;">แบบรายงานผลการไปราชการ / การฝึกอบรม / สัมมนา</h4>
            <div class="text-secondary" style="font-size: 11.5pt;">(ตามระเบียบการพัฒนาบุคลากรและไปปฏิบัติงานภายนอกสถาบัน)</div>
        </div>

        <!-- ๑. ข้อมูลทั่วไป -->
        <div class="sec-heading">
            <span class="sec-num">๑</span> ข้อมูลทั่วไปและโครงการ
        </div>
        <div class="info-grid ps-1 pe-1">
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">ชื่อผู้รายงาน:</span>
                <span class="info-value"><?= htmlspecialchars(($report['prefix_name'] ?? '') . $report['first_name'] . ' ' . $report['last_name']); ?></span>
            </div>
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">ตำแหน่ง:</span>
                <span class="info-value"><?= htmlspecialchars($report['position'] ?? 'บุคลากร'); ?></span>
            </div>
            
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">สังกัด / ภาควิชา:</span>
                <span class="info-value"><?= htmlspecialchars($report['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์'); ?></span>
            </div>
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">ประเภทกิจกรรม:</span>
                <span class="info-value"><?= htmlspecialchars($report['report_type'] ?? 'ไปราชการ'); ?></span>
            </div>

            <div class="info-cell" style="grid-column: span 12;">
                <span class="info-label">ชื่อโครงการ/หลักสูตร:</span>
                <span class="info-value fw-bold" style="color: #39048f;"><?= htmlspecialchars($report['project_name']); ?></span>
            </div>

            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">หน่วยงานผู้จัด:</span>
                <span class="info-value"><?= htmlspecialchars($report['organizer'] ?? '-'); ?></span>
            </div>
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">สถานที่จัด:</span>
                <span class="info-value"><?= htmlspecialchars($report['location'] ?? '-'); ?></span>
            </div>

            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">ช่วงวันจัดโครงการ:</span>
                <span class="info-value">
                    <?php 
                        if (!empty($report['start_date']) && !empty($report['end_date'])) {
                            if ($report['start_date'] == $report['end_date']) {
                                echo thaiDateFull($report['start_date']);
                            } else {
                                echo thaiDateShort($report['start_date']) . ' ถึง ' . thaiDateFull($report['end_date']);
                            }
                            echo " ({$days_count} วัน)";
                        } else {
                            echo '-';
                        }
                    ?>
                </span>
            </div>
            <div class="info-cell" style="grid-column: span 6;">
                <span class="info-label">กำหนดวันเดินทาง:</span>
                <span class="info-value">
                    <?php 
                        $tr_s = !empty($report['travel_start_date']) ? $report['travel_start_date'] : $report['start_date'];
                        $tr_e = !empty($report['travel_end_date']) ? $report['travel_end_date'] : $report['end_date'];
                        if (!empty($tr_s) && !empty($tr_e)) {
                            if ($tr_s == $tr_e) {
                                echo thaiDateFull($tr_s);
                            } else {
                                echo thaiDateShort($tr_s) . ' ถึง ' . thaiDateFull($tr_e);
                            }
                        } else {
                            echo '-';
                        }
                    ?>
                </span>
            </div>

            <div class="info-cell" style="grid-column: span 12;">
                <span class="info-label">จำนวนชั่วโมงสะสม:</span>
                <span class="info-value fw-bold"><?= htmlspecialchars($report['training_hours'] ?? ($days_count * 6)) ?> ชั่วโมง</span>
            </div>
        </div>

        <!-- ๒. สรุปผลการไปราชการ / ฝึกอบรม -->
        <div class="sec-heading">
            <span class="sec-num">๒</span> สาระสำคัญและผลสัมฤทธิ์ที่ได้รับ (โดยสรุป)
        </div>
        <div class="text-content-box">
            <?= htmlspecialchars($report['summary'] ?? 'ไม่มีข้อมูลสรุปผลการอบรม'); ?>
        </div>

        <!-- ๓. การประเมินผลภาพรวมของหลักสูตร -->
        <div class="sec-heading">
            <span class="sec-num">๓</span> การประเมินผลภาพรวมของหลักสูตร / โครงการ
        </div>
        <div class="eval-box">
            <div>
                <span class="checkbox-symbol"><?= (isset($report['evaluation']) && $report['evaluation'] == 'ดีมาก') ? '☑' : '☐'; ?></span>
                <strong>ดีมาก</strong> <small class="text-muted">(ได้รับความรู้/ทักษะคุ้มค่า เกินความคาดหมาย)</small>
            </div>
            <div>
                <span class="checkbox-symbol"><?= (isset($report['evaluation']) && $report['evaluation'] == 'ดี') ? '☑' : '☐'; ?></span>
                <strong>ดี</strong> <small class="text-muted">(เนื้อหาเหมาะสม เป็นไปตามวัตถุประสงค์)</small>
            </div>
            <div>
                <span class="checkbox-symbol"><?= (isset($report['evaluation']) && $report['evaluation'] == 'ไม่ดี') ? '☑' : '☐'; ?></span>
                <strong>ควรปรับปรุง</strong> <small class="text-muted">(เนื้อหาบางส่วนยังไม่ตรงเป้าหมาย)</small>
            </div>
        </div>

        <!-- ๔. แผนการนำความรู้ไปใช้ประโยชน์ -->
        <div class="sec-heading">
            <span class="sec-num">๔</span> แผนการนำความรู้และทักษะไปใช้ประโยชน์ในการปฏิบัติงาน
        </div>
        <table class="table-doc">
            <thead>
                <tr>
                    <th style="width: 45%;">แนวทางการนำไปประยุกต์ใช้ (Action Plan)</th>
                    <th style="width: 35%;">ผลหรือเป้าหมายที่คาดว่าจะได้รับ</th>
                    <th style="width: 20%;">กำหนดแล้วเสร็จ</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="white-space: pre-line; line-height: 1.5;">
                        <?= htmlspecialchars($report['apply_how'] ?? '-'); ?>
                    </td>
                    <td style="white-space: pre-line; line-height: 1.5;">
                        <?= htmlspecialchars($report['apply_expected_result'] ?? '-'); ?>
                    </td>
                    <td class="text-center font-monospace">
                        <?= thaiDateShort($report['apply_completion_date'] ?? null); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- ๕. รายละเอียดงบประมาณ -->
        <div class="sec-heading">
            <span class="sec-num">๕</span> รายละเอียดการเบิกจ่ายงบประมาณ
        </div>
        <table class="table-doc">
            <thead>
                <tr>
                    <th style="width: 40%;" class="text-start ps-3">รายการค่าใช้จ่าย</th>
                    <th style="width: 30%;" class="text-end pe-3">ประมาณการที่ขออนุมัติไว้ (บาท)</th>
                    <th style="width: 30%;" class="text-end pe-3">จ่ายจริงตามเอกสารการเงิน (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-start ps-3">๑. ค่าพาหนะ / เดินทาง</td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($est_travel, 2); ?></td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($act_travel, 2); ?></td>
                </tr>
                <tr>
                    <td class="text-start ps-3">๒. ค่าที่พัก</td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($est_accom, 2); ?></td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($act_accom, 2); ?></td>
                </tr>
                <tr>
                    <td class="text-start ps-3">๓. ค่าเบี้ยเลี้ยง</td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($est_allowance, 2); ?></td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($act_allowance, 2); ?></td>
                </tr>
                <tr>
                    <td class="text-start ps-3">๔. ค่าลงทะเบียน</td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($est_regis, 2); ?></td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($act_regis, 2); ?></td>
                </tr>
                <tr>
                    <td class="text-start ps-3">๕. ค่าใช้จ่ายอื่นๆ</td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($est_other, 2); ?></td>
                    <td class="text-end pe-3 font-monospace"><?= number_format($act_other, 2); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="background-color: #f1f5f9; font-weight: 700; -webkit-print-color-adjust: exact;">
                    <td class="text-start ps-3">รวมงบประมาณทั้งสิ้น</td>
                    <td class="text-end pe-3 font-monospace">฿<?= number_format($est_total, 2); ?></td>
                    <td class="text-end pe-3 font-monospace" style="color: #15803d;">฿<?= number_format($act_total, 2); ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if (!empty($report['finance_comment'])): ?>
            <div class="p-2 px-3 rounded-2 border border-warning bg-warning bg-opacity-10 small mb-3">
                <strong><i class="fa-solid fa-comment-dots text-warning me-1"></i> หมายเหตุ/ข้อความจากงานการเงิน:</strong>
                <span class="text-dark"><?= htmlspecialchars($report['finance_comment']); ?></span>
            </div>
        <?php endif; ?>

        <!-- ๖. การลงนามรับรองและขั้นตอนการพิจารณาอนุมัติ -->
        <div class="avoid-break mt-3">
            <div class="d-flex justify-content-between align-items-end mb-3 pb-2 border-bottom border-light-subtle">
                <div style="max-width: 55%;">
                    <p class="small text-muted mb-0 fst-italic" style="font-size: 10pt;">
                        * ข้าพเจ้าขอรับรองว่าข้อความ ผลการดำเนินงาน และเอกสารหลักฐานดังกล่าวข้างต้นเป็นความจริงทุกประการ
                    </p>
                </div>
                <div class="text-center" style="min-width: 220px;">
                    <div class="sig-container">
                        <?php if (!empty($report['signature_file']) && file_exists('../../uploads/signatures/' . $report['signature_file'])): ?>
                            <img src="../../uploads/signatures/<?= htmlspecialchars($report['signature_file']); ?>" class="sig-img" alt="ลายเซ็นผู้รายงาน">
                        <?php endif; ?>
                    </div>
                    <div class="fw-bold mt-1" style="font-size: 11pt;">
                        ( <?= htmlspecialchars(($report['prefix_name'] ?? '') . $report['first_name'] . ' ' . $report['last_name']); ?> )
                    </div>
                    <div class="text-secondary" style="font-size: 9.5pt;">
                        ผู้รายงาน &bull; <?= thaiDateFull($report_signed_date); ?>
                    </div>
                </div>
            </div>

            <!-- สายการพิจารณาอนุมัติ 5 ขั้นตอน (Approval Chain Table) -->
            <div class="sec-heading" style="font-size: 12.5pt; margin-top: 8px; margin-bottom: 6px;">
                <span class="sec-num" style="font-size: 10.5pt;">๖</span> ผลการพิจารณาอนุมัติตามลำดับขั้น (Approval Chain)
            </div>

            <table class="table-approval">
                <thead>
                    <tr>
                        <th style="width: 8%;" class="text-center">ขั้นที่</th>
                        <th style="width: 32%;" class="text-start ps-2">ผู้มีอำนาจพิจารณา / บทบาท</th>
                        <th style="width: 18%;" class="text-center">ผลการพิจารณา</th>
                        <th style="width: 18%;" class="text-center">ลายมือชื่อ</th>
                        <th style="width: 24%;" class="text-start ps-2">ชื่อ-นามสกุล / วันที่</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approval_steps as $step_idx => $app): ?>
                        <tr>
                            <td class="text-center fw-bold" style="background-color: #f8fafc;">
                                <?= $step_idx ?>
                            </td>
                            <td class="ps-2">
                                <div class="fw-bold text-dark" style="font-size: 10.5pt;"><?= htmlspecialchars($app['pos_name']) ?></div>
                                <?php if ($step_idx == 2 && !empty($report['finance_comment'])): ?>
                                    <div class="text-muted extra-small fst-italic" style="font-size: 8.5pt;">หมายเหตุ: <?= htmlspecialchars($report['finance_comment']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($report['return_comment']) && intval($report['return_step'] ?? 0) === $step_idx): ?>
                                    <div class="text-danger extra-small fst-italic" style="font-size: 8.5pt;">ส่งกลับแก้ไข: <?= htmlspecialchars($report['return_comment']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($app['status'] === 'approved'): ?>
                                    <span class="text-success fw-bold" style="font-size: 10pt;">
                                        <i class="fa-solid fa-circle-check me-1"></i><?= ($step_idx == 5) ? 'อนุมัติแล้ว' : 'เห็นชอบแล้ว' ?>
                                    </span>
                                <?php elseif ($app['status'] === 'rejected'): ?>
                                    <span class="text-danger fw-bold" style="font-size: 10pt;">
                                        <i class="fa-solid fa-circle-xmark me-1"></i>ไม่อนุมัติ
                                    </span>
                                <?php elseif ($app['status'] === 'returned'): ?>
                                    <span class="text-warning fw-bold" style="font-size: 10pt;">
                                        <i class="fa-solid fa-rotate-left me-1"></i>ส่งกลับแก้ไข
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary" style="font-size: 9.5pt;">
                                        <i class="fa-regular fa-clock me-1"></i>รอการพิจารณา
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center p-1">
                                <div class="d-flex align-items-center justify-content-center" style="height: 36px;">
                                    <?php if (!empty($app['signature_file']) && file_exists('../../uploads/signatures/' . $app['signature_file'])): ?>
                                        <img src="../../uploads/signatures/<?= htmlspecialchars($app['signature_file']); ?>" style="max-height: 34px; max-width: 100px; object-fit: contain;" alt="ลายเซ็น">
                                    <?php elseif ($app['status'] === 'approved'): ?>
                                        <span class="text-muted" style="font-size: 8pt;">(ลงนามอิเล็กทรอนิกส์)</span>
                                    <?php else: ?>
                                        <span class="text-muted opacity-50" style="font-size: 8pt;">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="ps-2">
                                <div class="fw-bold text-dark text-truncate" style="font-size: 10pt;" title="<?= htmlspecialchars($app['name']) ?>">
                                    ( <?= htmlspecialchars($app['name']) ?> )
                                </div>
                                <div class="text-muted" style="font-size: 8.5pt;">
                                    <?= (!empty($app['date']) && $app['date'] !== '0000-00-00 00:00:00') ? thaiDateShort($app['date']) : '<span class="opacity-50">รอดำเนินการ</span>' ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

</body>
</html>
