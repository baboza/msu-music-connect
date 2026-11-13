<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$booking_id = (int)($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    die("ไม่พบรหัสอ้างอิงเอกสารคำขอ");
}

// ดึงข้อมูลคำขอใช้รถ
$stmt = $pdo->prepare("
    SELECT b.*,
           v.plate_number, v.model, v.brand, v.year_made, v.vehicle_type, v.seating_capacity, v.fuel_type,
           u.prefix_name AS req_prefix, u.first_name AS req_first, u.last_name AS req_last, u.position AS req_pos, u.signature_file AS req_sig,
           head.prefix_name AS head_prefix, head.first_name AS head_first, head.last_name AS head_last, head.signature_file AS head_sig_file,
           dep.prefix_name AS dep_prefix, dep.first_name AS dep_first, dep.last_name AS dep_last, dep.signature_file AS dep_sig_file
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN users head ON b.head_approved_by = head.user_id
    LEFT JOIN users dep ON b.deputy_approved_by = dep.user_id
    WHERE b.booking_id = ?
");
$stmt->execute([$booking_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    die("ไม่พบข้อมูลคำขอใช้รถในระบบ");
}

// ผู้ขอ
$req_name = trim(($b['req_prefix'] ?? '') . $b['req_first'] . ' ' . $b['req_last']);
$req_sig = $b['req_sig'] ?? null;

// หัวหน้าสำนักงาน
$head_name = !empty($b['head_first']) 
    ? trim(($b['head_prefix'] ?? '') . $b['head_first'] . ' ' . $b['head_last'])
    : 'นางแจ่มจันทร์ หลูปรีชาเศรษฐ';
$head_sig = !empty($b['head_sig_file']) ? $b['head_sig_file'] : 'sig_34ee8171_1774328264.png';

// รองบริหาร
$dep_name = !empty($b['dep_first']) 
    ? trim(($b['dep_prefix'] ?? '') . $b['dep_first'] . ' ' . $b['dep_last'])
    : 'ดร.วัชระ หอมหวล';
$dep_sig = !empty($b['dep_sig_file']) ? $b['dep_sig_file'] : 'sig_86580da6_1772018273.png';

$is_c2 = ($b['vehicle_type'] === 'cargo_instruments');
$is_approved = ($b['status'] === 'approved' || $b['status'] === 'completed');
$is_head_done = !empty($b['head_approved_at']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบขออนุญาตใช้รถยนต์ราชการ - <?= htmlspecialchars($b['booking_code']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 14pt;
            line-height: 1.5;
            color: #000;
            background-color: #f1f5f9;
            margin: 0;
            padding: 20px;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 15mm 20mm;
            margin: 0 auto 20px auto;
            background: #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            box-sizing: border-box;
            position: relative;
        }
        .header-title {
            text-align: center;
            font-size: 18pt;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .header-subtitle {
            text-align: center;
            font-size: 14pt;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .doc-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 13pt;
        }
        .content-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .content-table td {
            padding: 6px 4px;
            vertical-align: top;
        }
        .dots {
            border-bottom: 1px dotted #555;
            padding-bottom: 2px;
            display: inline-block;
        }
        .section-box {
            border: 1px solid #333;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 15px;
            background: #fafafa;
        }
        .sign-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
            text-align: center;
            font-size: 12pt;
        }
        .sign-box {
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px;
            background: #fff;
            min-height: 170px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sig-img {
            max-height: 48px;
            max-width: 130px;
            object-fit: contain;
            margin: 2px auto;
            display: block;
        }
        .stamp-badge {
            display: inline-block;
            border: 2px solid #10b981;
            color: #10b981;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10pt;
            font-weight: bold;
            margin: 4px auto;
        }
        .print-btn-bar {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: #7e22ce;
            color: #fff;
            border: none;
            padding: 10px 24px;
            font-size: 14pt;
            border-radius: 30px;
            cursor: pointer;
            font-family: 'Sarabun', sans-serif;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(126, 34, 206, 0.3);
        }
        .btn-print:hover {
            background: #6b21a8;
        }
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: auto;
                padding: 15mm 15mm 10mm 15mm;
            }
            .print-btn-bar {
                display: none;
            }
        }
    </style>
</head>
<body>

    <div class="print-btn-bar">
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> พิมพ์ใบขอใช้รถยนต์ราชการ (Print)
        </button>
    </div>

    <div class="page">

        <!-- Logo & Header -->
        <div style="text-align: center; margin-bottom: 10px;">
            <img src="../../includes/logo.png" style="height: 60px; object-fit: contain;" alt="MSU Music Logo" onerror="this.style.display='none'">
        </div>

        <div class="header-title">ใบขออนุญาตใช้รถยนต์ส่วนกลาง / ราชการ</div>
        <div class="header-subtitle">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>

        <div class="doc-meta">
            <div><strong>เลขที่คำขอ:</strong> <?= htmlspecialchars($b['booking_code']) ?></div>
            <div><strong>วันที่ยื่นคำขอ:</strong> <?= formatThaiDateOnly($b['created_at']) ?></div>
        </div>

        <table class="content-table">
            <tr>
                <td style="width: 25%;"><strong>เรียน:</strong> คณบดีวิทยาลัยดุริยางคศิลป์</td>
                <td style="width: 75%;">
                    ข้าพเจ้า <span class="dots" style="width: 70%;"><?= htmlspecialchars($req_name) ?></span>
                </td>
            </tr>
            <tr>
                <td><strong>ตำแหน่ง:</strong></td>
                <td>
                    <span class="dots" style="width: 90%;"><?= htmlspecialchars($b['req_pos'] ?: 'อาจารย์ / บุคลากร วิทยาลัยดุริยางคศิลป์') ?></span>
                </td>
            </tr>
            <tr>
                <td><strong>มีความประสงค์ขอใช้:</strong></td>
                <td>
                    <strong><?= htmlspecialchars($b['model']) ?></strong>
                    (หมายเลขทะเบียน <strong><?= htmlspecialchars($b['plate_number']) ?></strong>)
                    <span style="font-size: 11pt; color: #555;">[<?= $is_c2 ? 'คันบรรทุกเครื่องดนตรี' : 'คันรับส่งบุคคล' ?>]</span>
                </td>
            </tr>
            <tr>
                <td><strong>เพื่อปฏิบัติภารกิจ:</strong></td>
                <td>
                    <span class="dots" style="width: 95%;"><?= htmlspecialchars($b['title']) ?></span>
                </td>
            </tr>
            <tr>
                <td><strong>สถานที่ปลายทาง:</strong></td>
                <td>
                    <span class="dots" style="width: 95%;"><?= htmlspecialchars($b['destination']) ?></span>
                </td>
            </tr>
            <?php if (!empty($b['route_detail'])): ?>
            <tr>
                <td><strong>เส้นทาง/จุดแวะ:</strong></td>
                <td>
                    <span class="dots" style="width: 95%;"><?= htmlspecialchars($b['route_detail']) ?></span>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><strong>กำหนดการเดินทาง:</strong></td>
                <td>
                    ตั้งแต่วันที่ <strong><?= formatThaiDT($b['start_datetime']) ?></strong> 
                    ถึงวันที่ <strong><?= formatThaiDT($b['end_datetime']) ?></strong>
                </td>
            </tr>
            <tr>
                <td><strong>จำนวนผู้โดยสาร:</strong></td>
                <td>
                    <strong><?= $b['passenger_count'] ?></strong> คน
                    (รายชื่อ: <?= htmlspecialchars($b['passenger_names'] ?: 'ตามรายชื่อที่แนบ') ?>)
                </td>
            </tr>
            <tr>
                <td><strong>พนักงานขับรถ:</strong></td>
                <td>
                    <strong><?= htmlspecialchars($b['driver_name'] ?: 'พนักงานขับรถประจำวิทยาลัย') ?></strong>
                    (<?= $b['driver_type'] === 'self_drive' ? 'ผู้ขอขับเอง' : 'พนักงานขับรถประจำ' ?>)
                </td>
            </tr>
        </table>

        <!-- รายการเครื่องดนตรี (ถ้ามี) -->
        <?php if (!empty($b['instruments_detail']) || $is_c2): ?>
            <div class="section-box">
                <strong style="color: #b91c1c;"><i class="fa-solid fa-guitar"></i> รายการเครื่องดนตรีและอุปกรณ์ที่บรรทุก:</strong>
                <div style="margin-top: 4px; font-size: 12pt; line-height: 1.6;">
                    <?= nl2br(htmlspecialchars($b['instruments_detail'] ?: 'เครื่องดนตรีและอุปกรณ์การแสดงประจำวงดุริยางค์')) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 3-Step Signature Sign-off Grid -->
        <div class="sign-grid">

            <!-- 1. ผู้ขอใช้รถ -->
            <div class="sign-box">
                <div>
                    <strong>(1) ผู้ขอใช้รถยนต์</strong>
                </div>
                <div>
                    <?php if (!empty($req_sig) && file_exists(__DIR__ . '/../../uploads/signatures/' . $req_sig)): ?>
                        <img src="/music-e-office/uploads/signatures/<?= htmlspecialchars($req_sig) ?>" class="sig-img" alt="ลายเซ็น">
                    <?php else: ?>
                        <div class="stamp-badge">อนุมัติทางอิเล็กทรอนิกส์</div>
                    <?php endif; ?>
                    <div>(<?= htmlspecialchars($req_name) ?>)</div>
                    <div style="font-size: 10pt; color: #666;"><?= htmlspecialchars($b['req_pos'] ?: 'ผู้ขอใช้รถ') ?></div>
                </div>
                <div style="font-size: 10pt; color: #555;">
                    วันที่ <?= formatThaiDateOnly($b['created_at']) ?>
                </div>
            </div>

            <!-- 2. หัวหน้าสำนักงานเลขานุการ -->
            <div class="sign-box">
                <div>
                    <strong>(2) หัวหน้าสำนักงาน</strong>
                    <div style="font-size: 10pt; color: #444; margin-top: 2px;">
                        <?= !empty($b['head_comment']) ? 'ความเห็น: ' . htmlspecialchars($b['head_comment']) : 'เห็นควรอนุญาตให้ใช้รถได้' ?>
                    </div>
                </div>
                <div>
                    <?php if ($is_head_done): ?>
                        <?php if (!empty($head_sig) && file_exists(__DIR__ . '/../../uploads/signatures/' . $head_sig)): ?>
                            <img src="/music-e-office/uploads/signatures/<?= htmlspecialchars($head_sig) ?>" class="sig-img" alt="ลายเซ็น">
                        <?php else: ?>
                            <div class="stamp-badge">เห็นชอบทางอิเล็กทรอนิกส์</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="height: 48px; border-bottom: 1px dotted #999; margin: 10px 20px;"></div>
                    <?php endif; ?>
                    <div>(<?= htmlspecialchars($head_name) ?>)</div>
                    <div style="font-size: 10pt; color: #666;">หัวหน้าสำนักงานเลขานุการ</div>
                </div>
                <div style="font-size: 10pt; color: #555;">
                    วันที่ <?= !empty($b['head_approved_at']) ? formatThaiDateOnly($b['head_approved_at']) : '......../......../........' ?>
                </div>
            </div>

            <!-- 3. รองคณบดีฝ่ายบริหาร -->
            <div class="sign-box">
                <div>
                    <strong>(3) รองคณบดีฝ่ายบริหาร</strong>
                    <div style="font-size: 10pt; color: #444; margin-top: 2px;">
                        <?= !empty($b['deputy_comment']) ? 'คำสั่ง: ' . htmlspecialchars($b['deputy_comment']) : ($is_approved ? 'อนุมัติให้ใช้รถยนต์ราชการได้' : 'รอการพิจารณาอนุมัติ') ?>
                    </div>
                </div>
                <div>
                    <?php if ($is_approved): ?>
                        <?php if (!empty($dep_sig) && file_exists(__DIR__ . '/../../uploads/signatures/' . $dep_sig)): ?>
                            <img src="/music-e-office/uploads/signatures/<?= htmlspecialchars($dep_sig) ?>" class="sig-img" alt="ลายเซ็น">
                        <?php else: ?>
                            <div class="stamp-badge">อนุมัติทางอิเล็กทรอนิกส์</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="height: 48px; border-bottom: 1px dotted #999; margin: 10px 20px;"></div>
                    <?php endif; ?>
                    <div>(<?= htmlspecialchars($dep_name) ?>)</div>
                    <div style="font-size: 10pt; color: #666;">รองคณบดีฝ่ายบริหาร ปฏิบัติราชการแทนคณบดี</div>
                </div>
                <div style="font-size: 10pt; color: #555;">
                    วันที่ <?= !empty($b['deputy_approved_at']) ? formatThaiDateOnly($b['deputy_approved_at']) : '......../......../........' ?>
                </div>
            </div>

        </div>

    </div>

</body>
</html>
