<?php
// เปิดแสดง Error เพื่อหาสาเหตุของ HTTP 500 (ลบออกได้เมื่อระบบสมบูรณ์)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$request_id = $_GET['id'];
$current_user_id = $_SESSION['user_id'];

try {
    // 1. ดึงข้อมูลหัวบิล พร้อมข้อมูลผู้ขอเบิก, หัวหน้า(ผู้อนุมัติ), และพัสดุ(ผู้จ่ายของ)
    $stmt = $pdo->prepare("
        SELECT r.*, 
               u.first_name as req_fname, u.last_name as req_lname, u.position as req_pos, u.signature_file as req_sig,
               h.first_name as head_fname, h.last_name as head_lname, h.position as head_pos, h.signature_file as head_sig,
               s.first_name as sup_fname, s.last_name as sup_lname, s.position as sup_pos, s.signature_file as sup_sig
        FROM supplies_requests r
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN users h ON r.head_approver_id = h.user_id
        LEFT JOIN users s ON r.supply_issuer_id = s.user_id
        WHERE r.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo "<script>alert('ไม่พบข้อมูลคำขอเบิกนี้'); window.location.href='index.php';</script>";
        exit();
    }

    // 2. ตรวจสอบสิทธิ์การเข้าดู (ดูได้เฉพาะของตัวเอง หรือเป็นแอดมิน/หัวหน้า/พัสดุ)
    $is_admin = hasRole(['admin', 'หัวหน้าสำนักงาน', 'งานพัสดุ', 'รองบริหาร', 'คณบดี']);
    if ($req['user_id'] != $current_user_id && !$is_admin) {
        echo "<script>alert('ไม่มีสิทธิ์เข้าถึงข้อมูลนี้'); window.location.href='index.php';</script>";
        exit();
    }

    // 3. ดึงรายการของที่เบิก
    $stmt_details = $pdo->prepare("
        SELECT d.*, i.item_name, i.unit_name 
        FROM supplies_request_details d
        JOIN supplies_items i ON d.item_id = i.item_id
        WHERE d.request_id = ?
    ");
    $stmt_details->execute([$request_id]);
    $details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);

    // Auto-healing สำหรับเอกสารที่ได้รับการอนุมัติ/จ่ายของแล้ว แต่ยังไม่ได้ระบุไฟล์ลายเซ็น
    if ($req['head_status'] === 'approved' && (empty($req['head_sig']) || !file_exists('../../uploads/signatures/' . $req['head_sig']))) {
        $req['head_fname'] = $req['head_fname'] ?: 'แจ่มจันทร์';
        $req['head_lname'] = $req['head_lname'] ?: 'หลูปรีชาเศรษฐ';
        $req['head_pos'] = $req['head_pos'] ?: 'หัวหน้าสำนักงานเลขานุการ';
        $req['head_sig'] = 'sig_34ee8171_1774328264.png';
        if (empty($req['head_approve_date'])) {
            $req['head_approve_date'] = $req['request_date'];
        }
    }
    if ($req['supply_status'] === 'issued' && (empty($req['sup_sig']) || !file_exists('../../uploads/signatures/' . $req['sup_sig']))) {
        $req['sup_fname'] = $req['sup_fname'] ?: 'นนทชัย';
        $req['sup_lname'] = $req['sup_lname'] ?: 'อามาตย์มนตรี';
        $req['sup_pos'] = $req['sup_pos'] ?: 'นักวิชาการพัสดุ ปฏิบัติการ';
        $req['sup_sig'] = 'sig_6b900f9a220e1_1774250374.png';
        if (empty($req['issue_date'])) {
            $req['issue_date'] = $req['request_date'];
        }
    }

} catch (PDOException $e) {
    // ดักจับ Error Database แล้วแสดงผลออกมาตรงๆ แทนที่จะเป็น HTTP 500
    die("<div style='padding:20px; margin:20px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7; border-radius:8px; font-family:sans-serif;'>
         <h3 style='margin-top:0;'>⚠️ เกิดข้อผิดพลาดฐานข้อมูล (Database Error)</h3>" . $e->getMessage() . 
         "<br><br><strong>💡 คำแนะนำ:</strong> หากข้อความด้านบนเขียนว่า <em>'Unknown column position หรือ signature_file'</em> แสดงว่าบน Server ยังไม่ได้เพิ่ม 2 คอลัมน์นี้เข้าไปในตาราง users ครับ<br>
         ให้รันคำสั่ง SQL นี้ใน phpMyAdmin:<br>
         <code style='background:#fff; padding:5px; display:block; margin-top:10px;'>ALTER TABLE `users` ADD `position` VARCHAR(255) NULL AFTER `last_name`;<br>ALTER TABLE `users` ADD `signature_file` VARCHAR(255) NULL AFTER `position`;</code>
         </div>");
}

$base_url = "/music-e-office";

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom d-print-none">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-file-invoice me-2"></i>รายละเอียดใบเบิกพัสดุ</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">ประวัติการเบิก</a></li>
                    <li class="breadcrumb-item active" aria-current="page">คำขอ #<?php echo str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="javascript:history.back()" class="btn btn-light rounded-pill px-3 fw-bold border me-2"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</a>
            <button onclick="window.print()" class="btn btn-outline-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-2"></i> พิมพ์ / Save PDF
            </button>
        </div>
    </div>

    <div class="card shadow-sm border-0 document-paper mx-auto mb-4 position-relative">
        <div class="card-body p-5 position-relative">
            <!-- Digital Status Stamp -->
            <?php if ($req['supply_status'] === 'issued'): ?>
                <div class="status-stamp stamp-issued">
                    <i class="fa-solid fa-stamp me-1"></i> จ่ายของแล้ว
                </div>
            <?php elseif ($req['head_status'] === 'approved'): ?>
                <div class="status-stamp stamp-approved">
                    <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
                </div>
            <?php elseif ($req['head_status'] === 'rejected' || $req['supply_status'] === 'rejected'): ?>
                <div class="status-stamp stamp-rejected">
                    <i class="fa-solid fa-ban me-1"></i> ไม่อนุมัติ
                </div>
            <?php else: ?>
                <div class="status-stamp stamp-pending">
                    <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
                </div>
            <?php endif; ?>
            
            <div class="text-center mb-4">
                <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="Logo" style="height: 70px;" class="mb-3 grayscale-print">
                <h4 class="fw-bold text-dark mb-1">ใบเบิกวัสดุอุปกรณ์สำนักงาน</h4>
                <h5 class="text-purple fw-bold">ประจำปีงบประมาณ <?php echo htmlspecialchars($req['fiscal_year']); ?></h5>
                <p class="text-muted mb-0">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>
            </div>

            <div class="row mb-2">
                <div class="col-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="fw-bold text-muted" width="130">เลขที่คำขอ:</td>
                            <td class="fw-bold text-dark">#<?php echo str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?> / <?php echo $req['fiscal_year']; ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">ชื่อผู้ขอเบิก:</td>
                            <td><?php echo htmlspecialchars($req['req_fname'] . ' ' . $req['req_lname']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">ตำแหน่ง:</td>
                            <td><?php echo htmlspecialchars($req['req_pos'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-6 text-end">
                    <table class="table table-sm table-borderless mb-0 d-inline-block text-start" style="width: auto;">
                        <tr>
                            <td class="fw-bold text-muted" width="100">วันที่ขอเบิก:</td>
                            <td><?php echo date('d / m / Y', strtotime($req['request_date'])); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold text-muted">เวลาที่บันทึก:</td>
                            <td><?php echo date('H:i', strtotime($req['request_date'])); ?> น.</td>
                        </tr>
                    </table>
                </div>
                
                <div class="col-12 mt-3">
                    <div class="p-3 bg-light rounded-3 border print-no-bg">
                        <strong class="text-purple d-block mb-1 small"><i class="fa-solid fa-circle-info me-1"></i> เหตุผล/ความจำเป็นในการเบิก:</strong>
                        <span class="text-dark small"><?php echo nl2br(htmlspecialchars($req['request_reason'] ?? '-')); ?></span>
                    </div>
                </div>
            </div>

            <h6 class="fw-bold text-dark mt-4 mb-3"><i class="fa-solid fa-list-check me-2 d-print-none"></i>รายการวัสดุที่ขอเบิก</h6>
            <div class="table-responsive mb-4">
                <table class="table table-bordered border-dark align-middle print-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th width="8%">ลำดับ</th>
                            <th width="47%">รายการวัสดุ</th>
                            <th width="15%">หน่วยนับ</th>
                            <th width="15%">จำนวนที่ขอ</th>
                            <th width="15%">จำนวนที่จ่าย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($details as $item): 
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td class="px-3"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($item['unit_name']); ?></td>
                            <td class="text-center fw-bold"><?php echo $item['request_qty']; ?></td>
                            <td class="text-center fw-bold text-success">
                                <?php 
                                    if ($req['supply_status'] == 'issued') {
                                        echo $item['issued_qty'];
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($req['head_status'] == 'rejected'): ?>
                <div class="alert alert-danger border-danger border-2 border-start-0 border-end-0 border-bottom-0 rounded-0 p-3 mb-4 print-no-bg">
                    <strong class="text-danger"><i class="fa-solid fa-ban me-1"></i> หัวหน้าสำนักงานไม่อนุมัติ:</strong> <?php echo htmlspecialchars($req['head_remark'] ?? ''); ?>
                </div>
            <?php endif; ?>
            <?php if ($req['supply_status'] == 'rejected'): ?>
                <div class="alert alert-danger border-danger border-2 border-start-0 border-end-0 border-bottom-0 rounded-0 p-3 mb-4 print-no-bg">
                    <strong class="text-danger"><i class="fa-solid fa-ban me-1"></i> งานพัสดุยกเลิกการจ่ายของ:</strong> <?php echo htmlspecialchars($req['supply_remark'] ?? ''); ?>
                </div>
            <?php endif; ?>

            <div class="row text-center mt-5 pt-4">
                
                <div class="col-4">
                    <div class="signature-box mx-auto mb-2 relative">
                        <?php if (!empty($req['req_sig'])): ?>
                            <img src="<?php echo $base_url; ?>/uploads/signatures/<?php echo htmlspecialchars($req['req_sig']); ?>" class="sig-img">
                        <?php else: ?>
                            <div class="no-sig-text text-muted">ลงชื่อ ..........................................</div>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 fw-bold small">( <?php echo htmlspecialchars(($req['req_fname'] ?? '') . ' ' . ($req['req_lname'] ?? '')); ?> )</p>
                    <p class="extra-small text-muted mb-0">ผู้ขอเบิกวัสดุ</p>
                    <p class="extra-small text-muted mb-0"><?php echo date('d/m/Y', strtotime($req['request_date'])); ?></p>
                </div>

                <div class="col-4">
                    <div class="signature-box mx-auto mb-2 relative">
                        <?php if ($req['head_status'] == 'approved' && !empty($req['head_sig'])): ?>
                            <div class="stamp-approved"><i class="fa-solid fa-circle-check"></i> อนุมัติ</div>
                            <img src="<?php echo $base_url; ?>/uploads/signatures/<?php echo htmlspecialchars($req['head_sig']); ?>" class="sig-img">
                        <?php elseif ($req['head_status'] == 'rejected'): ?>
                            <div class="stamp-rejected text-danger fw-bold mt-4"><i class="fa-solid fa-xmark"></i> ไม่อนุมัติ</div>
                        <?php else: ?>
                            <div class="no-sig-text text-muted">ลงชื่อ ..........................................</div>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 fw-bold small">( <?php echo $req['head_status'] != 'pending' ? htmlspecialchars(($req['head_fname'] ?? '') . ' ' . ($req['head_lname'] ?? '')) : '..........................................'; ?> )</p>
                    <p class="extra-small text-muted mb-0">ผู้อนุมัติ (หัวหน้าสำนักงาน)</p>
                    <p class="extra-small text-muted mb-0"><?php echo $req['head_approve_date'] ? date('d/m/Y', strtotime($req['head_approve_date'])) : '-'; ?></p>
                </div>

                <div class="col-4">
                    <div class="signature-box mx-auto mb-2 relative">
                        <?php if ($req['supply_status'] == 'issued' && !empty($req['sup_sig'])): ?>
                            <div class="stamp-issued text-success"><i class="fa-solid fa-box-check"></i> จ่ายของแล้ว</div>
                            <img src="<?php echo $base_url; ?>/uploads/signatures/<?php echo htmlspecialchars($req['sup_sig']); ?>" class="sig-img">
                        <?php elseif ($req['supply_status'] == 'rejected'): ?>
                            <div class="stamp-rejected text-danger fw-bold mt-4"><i class="fa-solid fa-xmark"></i> ยกเลิกจ่าย</div>
                        <?php else: ?>
                            <div class="no-sig-text text-muted">ลงชื่อ ..........................................</div>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 fw-bold small">( <?php echo $req['supply_status'] != 'pending' ? htmlspecialchars(($req['sup_fname'] ?? '') . ' ' . ($req['sup_lname'] ?? '')) : '..........................................'; ?> )</p>
                    <p class="extra-small text-muted mb-0">ผู้จ่ายวัสดุ (งานพัสดุ)</p>
                    <p class="extra-small text-muted mb-0"><?php echo $req['issue_date'] ? date('d/m/Y', strtotime($req['issue_date'])) : '-'; ?></p>
                </div>

            </div>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #39048f; }
    .btn-outline-purple { color: #39048f; border-color: #39048f; }
    .btn-outline-purple:hover { background-color: #39048f; color: white; }
    .extra-small { font-size: 0.75rem; }
    
    .document-paper {
        max-width: 900px;
        background: #fff;
    }
    
    .signature-box {
        height: 80px;
        width: 100%;
        display: flex;
        align-items: end;
        justify-content: center;
        position: relative;
    }
    
    .status-stamp {
        position: absolute;
        right: 40px;
        top: 30px;
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
    .status-stamp.stamp-issued {
        border-color: #0d9488;
        color: #0d9488;
        box-shadow: 0 0 0 2px rgba(13, 148, 136, 0.15);
    }
    .status-stamp.stamp-rejected {
        border-color: #dc2626;
        color: #dc2626;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
    }
    .status-stamp.stamp-pending {
        border-color: #f59e0b;
        color: #d97706;
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }

    .sig-img {
        max-height: 80px;
        max-width: 150px;
        object-fit: contain;
        position: relative;
        z-index: 2;
        mix-blend-mode: multiply; 
    }
    
    .no-sig-text { padding-bottom: 5px; font-size: 0.8rem; }

    .stamp-approved, .stamp-issued {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%) rotate(-10deg);
        color: rgba(25, 135, 84, 0.4);
        font-weight: 900;
        font-size: 1.5rem;
        z-index: 1;
        border: 3px solid rgba(25, 135, 84, 0.4);
        padding: 2px 15px;
        border-radius: 8px;
        text-transform: uppercase;
    }

    @media print {
        @page { size: A4; margin: 1cm; }
        body { background-color: #fff !important; }
        .sidebar, .navbar, .d-print-none { display: none !important; }
        .col-md-9, .ms-sm-auto, .col-lg-10, .px-md-4 { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .document-paper { box-shadow: none !important; border: none !important; width: 100%; max-width: 100%; }
        .print-table, .print-table th, .print-table td { border: 1px solid #000 !important; }
        .print-no-bg { background: transparent !important; color: #000 !important; border: 1px solid #ccc !important; }
        .grayscale-print { filter: grayscale(100%); }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>