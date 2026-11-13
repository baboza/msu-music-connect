<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$current_user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles);

// รับ parameter booking_id สำหรับเปิดฟอร์มบันทึกด่วน
$preselect_booking_id = (int)($_GET['booking_id'] ?? 0);
$filter_vehicle = (int)($_GET['vehicle_id'] ?? 0);

$msg = '';
$msg_type = '';

// Handle POST บันทึกผลการเดินทาง / ค่าใช้จ่าย / อัตราสิ้นเปลือง
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_trip_log') {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $driver_name = trim($_POST['driver_name'] ?? '');
    $start_odometer = (int)($_POST['start_odometer'] ?? 0);
    $end_odometer = (int)($_POST['end_odometer'] ?? 0);
    $fuel_liters = (float)($_POST['fuel_liters'] ?? 0);
    $fuel_cost = (float)($_POST['fuel_cost'] ?? 0);
    $toll_cost = (float)($_POST['toll_cost'] ?? 0);
    $parking_cost = (float)($_POST['parking_cost'] ?? 0);
    $driver_allowance = (float)($_POST['driver_allowance'] ?? 0);
    $misc_cost = (float)($_POST['misc_cost'] ?? 0);
    $trip_notes = trim($_POST['trip_notes'] ?? '');

    // Validation
    if ($start_odometer <= 0 || $end_odometer <= 0) {
        $msg = 'กรุณาระบุเลขไมล์เริ่มต้นและสิ้นสุดให้ถูกต้อง';
        $msg_type = 'danger';
    } elseif ($end_odometer < $start_odometer) {
        $msg = 'เลขไมล์สิ้นสุดต้องมากกว่าหรือเท่ากับเลขไมล์เริ่มต้น';
        $msg_type = 'danger';
    } else {
        $total_distance = $end_odometer - $start_odometer;
        $total_expenses = $fuel_cost + $toll_cost + $parking_cost + $driver_allowance + $misc_cost;
        
        // คำนวณอัตราสิ้นเปลือง
        $fuel_consumption_rate = ($fuel_liters > 0) ? ($total_distance / $fuel_liters) : 0.00;
        $cost_per_km = ($total_distance > 0) ? ($total_expenses / $total_distance) : 0.00;

        // จัดการอัปโหลดใบเสร็จ (ถ้ามี)
        $receipt_filename = null;
        if (!empty($_FILES['receipt_file']['name'])) {
            $upload_dir = __DIR__ . '/../../uploads/receipts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                $receipt_filename = 'receipt_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['receipt_file']['tmp_name'], $upload_dir . $receipt_filename);
            }
        }

        try {
            // ตรวจสอบว่ามีบันทึกของ booking นี้แล้วหรือยัง
            $chk_stmt = $pdo->prepare("SELECT log_id, receipt_file FROM vehicle_trip_logs WHERE booking_id = ?");
            $chk_stmt->execute([$booking_id]);
            $existing_log = $chk_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_log) {
                // Update
                if (!$receipt_filename && !empty($existing_log['receipt_file'])) {
                    $receipt_filename = $existing_log['receipt_file'];
                }

                $stmt_up = $pdo->prepare("
                    UPDATE vehicle_trip_logs 
                    SET vehicle_id = :veh,
                        driver_name = :dname,
                        start_odometer = :s_odo,
                        end_odometer = :e_odo,
                        total_distance = :dist,
                        fuel_liters = :fliters,
                        fuel_cost = :fcost,
                        toll_cost = :tcost,
                        parking_cost = :pcost,
                        driver_allowance = :allowance,
                        misc_cost = :misc,
                        total_expenses = :total_exp,
                        fuel_consumption_rate = :fc_rate,
                        cost_per_km = :cpk,
                        trip_notes = :notes,
                        receipt_file = :rec,
                        recorded_by = :uid,
                        recorded_at = NOW()
                    WHERE log_id = :lid
                ");
                $stmt_up->execute([
                    ':veh' => $vehicle_id,
                    ':dname' => $driver_name,
                    ':s_odo' => $start_odometer,
                    ':e_odo' => $end_odometer,
                    ':dist' => $total_distance,
                    ':fliters' => $fuel_liters,
                    ':fcost' => $fuel_cost,
                    ':tcost' => $toll_cost,
                    ':pcost' => $parking_cost,
                    ':allowance' => $driver_allowance,
                    ':misc' => $misc_cost,
                    ':total_exp' => $total_expenses,
                    ':fc_rate' => $fuel_consumption_rate,
                    ':cpk' => $cost_per_km,
                    ':notes' => $trip_notes,
                    ':rec' => $receipt_filename,
                    ':uid' => $current_user_id,
                    ':lid' => $existing_log['log_id']
                ]);
            } else {
                // Insert
                $stmt_ins = $pdo->prepare("
                    INSERT INTO vehicle_trip_logs 
                    (booking_id, vehicle_id, driver_name, start_odometer, end_odometer, total_distance, 
                     fuel_liters, fuel_cost, toll_cost, parking_cost, driver_allowance, misc_cost, 
                     total_expenses, fuel_consumption_rate, cost_per_km, trip_notes, receipt_file, 
                     recorded_by, recorded_at)
                    VALUES 
                    (:bid, :veh, :dname, :s_odo, :e_odo, :dist, 
                     :fliters, :fcost, :tcost, :pcost, :allowance, :misc, 
                     :total_exp, :fc_rate, :cpk, :notes, :rec, 
                     :uid, NOW())
                ");
                $stmt_ins->execute([
                    ':bid' => $booking_id,
                    ':veh' => $vehicle_id,
                    ':dname' => $driver_name,
                    ':s_odo' => $start_odometer,
                    ':e_odo' => $end_odometer,
                    ':dist' => $total_distance,
                    ':fliters' => $fuel_liters,
                    ':fcost' => $fuel_cost,
                    ':tcost' => $toll_cost,
                    ':pcost' => $parking_cost,
                    ':allowance' => $driver_allowance,
                    ':misc' => $misc_cost,
                    ':total_exp' => $total_expenses,
                    ':fc_rate' => $fuel_consumption_rate,
                    ':cpk' => $cost_per_km,
                    ':notes' => $trip_notes,
                    ':rec' => $receipt_filename,
                    ':uid' => $current_user_id
                ]);
            }

            // อัปเดตเลขไมล์สะสมล่าสุดในตาราง vehicles
            $stmt_odo = $pdo->prepare("UPDATE vehicles SET current_odometer = GREATEST(current_odometer, ?) WHERE vehicle_id = ?");
            $stmt_odo->execute([$end_odometer, $vehicle_id]);

            // อัปเดตสถานะ booking ให้เป็น completed (เดินทางเสร็จสิ้น)
            if ($booking_id > 0) {
                $stmt_bk = $pdo->prepare("UPDATE vehicle_bookings SET status = 'completed' WHERE booking_id = ? AND status = 'approved'");
                $stmt_bk->execute([$booking_id]);
            }

            $msg = 'บันทึกข้อมูลการเดินทาง ค่าใช้จ่าย และอัตราสิ้นเปลืองเรียบร้อยแล้ว';
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

// สถิติภาพรวม
$stat_total_dist = $pdo->query("SELECT SUM(total_distance) FROM vehicle_trip_logs")->fetchColumn() ?: 0;
$stat_total_exp = $pdo->query("SELECT SUM(total_expenses) FROM vehicle_trip_logs")->fetchColumn() ?: 0;
$stat_total_fuel = $pdo->query("SELECT SUM(fuel_cost) FROM vehicle_trip_logs")->fetchColumn() ?: 0;
$stat_avg_fc = $pdo->query("SELECT AVG(fuel_consumption_rate) FROM vehicle_trip_logs WHERE fuel_consumption_rate > 0")->fetchColumn() ?: 0;

// ดึงรายการ Log การเดินทาง
$sql_logs = "
    SELECT l.*, b.booking_code, b.title, b.destination, b.start_datetime, b.end_datetime,
           v.plate_number, v.model, v.vehicle_type,
           u.first_name, u.last_name
    FROM vehicle_trip_logs l
    LEFT JOIN vehicle_bookings b ON l.booking_id = b.booking_id
    LEFT JOIN vehicles v ON l.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON l.recorded_by = u.user_id
    WHERE 1=1
";
$params_logs = [];
if ($filter_vehicle > 0) {
    $sql_logs .= " AND l.vehicle_id = ?";
    $params_logs[] = $filter_vehicle;
}
$sql_logs .= " ORDER BY l.log_id DESC LIMIT 50";
$stmt_logs = $pdo->prepare($sql_logs);
$stmt_logs->execute($params_logs);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายการคำขอที่อนุมัติแล้ว แต่ยังไม่ได้บันทึกผลการเดินทาง (สำหรับตัวเลือกในฟอร์ม)
$stmt_unlogged = $pdo->query("
    SELECT b.booking_id, b.booking_code, b.title, b.destination, b.start_datetime, b.vehicle_id, b.driver_name,
           v.plate_number, v.model, v.current_odometer
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_trip_logs l ON b.booking_id = l.booking_id
    WHERE b.status IN ('approved', 'completed') AND l.log_id IS NULL
    ORDER BY b.start_datetime DESC
");
$unlogged_bookings = $stmt_unlogged->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลคำขอที่ preselect (ถ้ามี)
$selected_booking_info = null;
if ($preselect_booking_id > 0) {
    $stmt_sb = $pdo->prepare("
        SELECT b.*, v.plate_number, v.model, v.current_odometer
        FROM vehicle_bookings b
        LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        WHERE b.booking_id = ?
    ");
    $stmt_sb->execute([$preselect_booking_id]);
    $selected_booking_info = $stmt_sb->fetch(PDO::FETCH_ASSOC);
}

// ดึงข้อมูลรถทั้ง 2 คัน
$vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Top Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้ารวมการจองรถ
                </a>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small">
                    <i class="fa-solid fa-gas-pump me-1"></i> รายงานการเดินทาง & ค่าใช้จ่าย
                </span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-purple me-2"></i>บันทึกผลการเดินทาง ค่าใช้จ่าย และอัตราสิ้นเปลือง
            </h3>
            <p class="text-muted small mb-0">
                สรุปเลขไมล์ ค่าน้ำมัน ค่าทางด่วน ค่าเบี้ยเลี้ยง และคำนวณอัตราสิ้นเปลือง (กม./ลิตร) แต่ละทริป
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#tripLogModal">
                <i class="fa-solid fa-plus me-1"></i> บันทึกข้อมูลทริปใหม่
            </button>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Bento Overview Analytics -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">ระยะทางสะสมรวม</span>
                    <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-gauge-high"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($stat_total_dist, 1) ?> <span class="fs-6 text-muted fw-normal">กม.</span></div>
                <div class="extra-small text-muted mt-1">จากทริปที่บันทึกทั้งหมด</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">ค่าน้ำมันรวม</span>
                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-gas-pump"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($stat_total_fuel, 2) ?> <span class="fs-6 text-muted fw-normal">บาท</span></div>
                <div class="extra-small text-muted mt-1">ค่าน้ำมันเชื้อเพลิง</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">ค่าใช้จ่ายรวมทุกด้าน</span>
                    <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($stat_total_exp, 2) ?> <span class="fs-6 text-muted fw-normal">บาท</span></div>
                <div class="extra-small text-muted mt-1">น้ำมัน + ทางด่วน + ที่พัก + จอดรถ</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">อัตราสิ้นเปลืองเฉลี่ย</span>
                    <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-leaf"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-success mb-0"><?= number_format($stat_avg_fc, 2) ?> <span class="fs-6 text-muted fw-normal">กม./ลิตร</span></div>
                <div class="extra-small text-muted mt-1">ประหยัดพลังงานเฉลี่ย</div>
            </div>
        </div>
    </div>

    <!-- รายการ Log การเดินทางและค่าใช้จ่าย -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list-check text-purple me-2"></i>ประวัติการบันทึกทริปและค่าใช้จ่าย</h5>
                <span class="text-muted extra-small">แสดงรายละเอียดการเดินทาง เลขไมล์ และค่าใช้จ่ายแต่ละครั้ง แต่ละที่</span>
            </div>
            <form method="GET" class="d-flex align-items-center gap-2">
                <select name="vehicle_id" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                    <option value="0">-- รถทั้งหมด (2 คัน) --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['vehicle_id'] ?>" <?= $filter_vehicle == $v['vehicle_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-gas-pump text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ยังไม่มีบันทึกข้อมูลการเดินทาง</h6>
                    <p class="text-muted small mb-3">เมื่อเสร็จสิ้นภารกิจ สามารถบันทึกเลขไมล์และค่าใช้จ่ายได้ที่ปุ่มด้านบน</p>
                    <button type="button" class="btn btn-purple rounded-pill px-4 btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#tripLogModal">
                        <i class="fa-solid fa-plus me-1"></i> บันทึกทริปแรก
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted extra-small">
                                <th class="ps-4" style="width: 140px;">รหัสคำขอ / วันที่</th>
                                <th>รถวิทยาลัย</th>
                                <th>สถานที่ปลายทาง / ภารกิจ</th>
                                <th style="width: 170px;">เลขไมล์ & ระยะทาง</th>
                                <th style="width: 150px;">อัตราสิ้นเปลือง</th>
                                <th style="width: 150px;" class="text-end">ค่าใช้จ่ายรวม</th>
                                <th style="width: 130px;" class="text-end pe-4">รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $lg): 
                                $is_c2 = ($lg['vehicle_type'] === 'cargo_instruments');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <a href="view.php?id=<?= $lg['booking_id'] ?>" class="fw-bold text-purple text-decoration-none">
                                            <?= htmlspecialchars($lg['booking_code'] ?: 'ทริปเฉพาะกิจ') ?>
                                        </a>
                                        <div class="extra-small text-muted"><?= date('d/m/Y', strtotime($lg['recorded_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark small">
                                            <i class="fa-solid <?= $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple' ?> me-1"></i>
                                            <?= htmlspecialchars($lg['model']) ?>
                                        </div>
                                        <span class="badge bg-light text-dark border extra-small mt-1"><?= htmlspecialchars($lg['plate_number']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($lg['title'] ?: '-') ?></div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($lg['destination'] ?: '-') ?>
                                            • คนขับ: <?= htmlspecialchars($lg['driver_name'] ?: 'ไม่ระบุ') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-dark"><?= number_format($lg['total_distance'], 1) ?> กม.</div>
                                        <div class="extra-small text-muted"><?= number_format($lg['start_odometer']) ?> → <?= number_format($lg['end_odometer']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold text-success"><?= number_format($lg['fuel_consumption_rate'], 2) ?> กม./ลิตร</div>
                                        <div class="extra-small text-muted">น้ำมัน: <?= number_format($lg['fuel_liters'], 1) ?> ลิตร</div>
                                    </td>
                                    <td class="text-end">
                                        <div class="fw-bold text-danger fs-6"><?= number_format($lg['total_expenses'], 2) ?> ฿</div>
                                        <div class="extra-small text-muted"><?= number_format($lg['cost_per_km'], 2) ?> ฿/กม.</div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" onclick="viewTripDetail(<?= htmlspecialchars(json_encode($lg)) ?>)">
                                            <i class="fa-regular fa-eye text-purple"></i> บิล
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Modal บันทึกผลการเดินทาง & ค่าใช้จ่าย -->
<div class="modal fade" id="tripLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="save_trip_log">

            <div class="modal-header border-0 bg-purple bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-purple">
                    <i class="fa-solid fa-gas-pump me-2"></i>บันทึกผลการเดินทาง ค่าใช้จ่าย และอัตราสิ้นเปลือง
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                <!-- เลือกรอบการจอง -->
                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">เลือกคำขอใช้รถที่เดินทางเสร็จสิ้น</label>
                    <select name="booking_id" id="modal_booking_select" class="form-select rounded-3" onchange="onBookingSelect(this)">
                        <option value="0">-- เลือกคำขอใช้รถ หรือบันทึกแยกต่างหาก --</option>
                        <?php foreach ($unlogged_bookings as $ub): ?>
                            <option value="<?= $ub['booking_id'] ?>" 
                                    data-veh-id="<?= $ub['vehicle_id'] ?>" 
                                    data-odo="<?= $ub['current_odometer'] ?>"
                                    data-driver="<?= htmlspecialchars($ub['driver_name']) ?>"
                                    <?= ($preselect_booking_id == $ub['booking_id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($ub['booking_code']) ?>] <?= htmlspecialchars($ub['title']) ?> - <?= htmlspecialchars($ub['destination']) ?> (<?= htmlspecialchars($ub['plate_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">รถวิทยาลัยที่ใช้ <span class="text-danger">*</span></label>
                        <select name="vehicle_id" id="modal_veh_id" class="form-select rounded-3" required>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>" data-odo="<?= $v['current_odometer'] ?>">
                                    <?= htmlspecialchars($v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ชื่อพนักงานขับรถ / ผู้ขับ</label>
                        <input type="text" name="driver_name" id="modal_driver_name" class="form-control rounded-3" placeholder="เช่น นายเนตร เนื่องวรรณะ">
                    </div>
                </div>

                <!-- เลขไมล์ -->
                <div class="card bg-light border p-3 rounded-4 mb-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-dark">เลขไมล์เริ่มต้น (กม.) <span class="text-danger">*</span></label>
                            <input type="number" name="start_odometer" id="calc_start_odo" class="form-control rounded-3" required oninput="calcTripMetrics()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-dark">เลขไมล์สิ้นสุด (กม.) <span class="text-danger">*</span></label>
                            <input type="number" name="end_odometer" id="calc_end_odo" class="form-control rounded-3" required oninput="calcTripMetrics()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">ระยะทางรวมที่วิ่ง (กม.)</label>
                            <div class="h4 fw-bold text-purple mb-0 pt-1" id="calc_total_distance">0.0 กม.</div>
                        </div>
                    </div>
                </div>

                <!-- ค่าใช้จ่ายแต่ละรายการ -->
                <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-receipt text-purple me-1"></i> รายการค่าใช้จ่ายในการเดินทาง</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ปริมาณน้ำมันที่เติม (ลิตร)</label>
                        <input type="number" step="0.01" name="fuel_liters" id="calc_fuel_liters" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ค่าน้ำมันเชื้อเพลิง (บาท)</label>
                        <input type="number" step="0.01" name="fuel_cost" id="calc_fuel_cost" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ค่าทางด่วน / ผ่านทาง (บาท)</label>
                        <input type="number" step="0.01" name="toll_cost" id="calc_toll_cost" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ค่าที่จอดรถ (บาท)</label>
                        <input type="number" step="0.01" name="parking_cost" id="calc_parking_cost" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ค่าเบี้ยเลี้ยง / ที่พักคนขับ (บาท)</label>
                        <input type="number" step="0.01" name="driver_allowance" id="calc_allowance" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ค่าใช้จ่ายอื่นๆ (บาท)</label>
                        <input type="number" step="0.01" name="misc_cost" id="calc_misc" class="form-control rounded-3" placeholder="0.00" oninput="calcTripMetrics()">
                    </div>
                </div>

                <!-- ผลการคำนวณอัตโนมัติ -->
                <div class="p-3 bg-purple-subtle rounded-4 border border-purple-subtle mb-3">
                    <div class="row g-2 text-center">
                        <div class="col-4 border-end border-purple-subtle">
                            <div class="extra-small text-muted">ค่าใช้จ่ายรวมทั้งหมด</div>
                            <div class="fw-bold text-danger fs-5" id="calc_total_expenses">0.00 ฿</div>
                        </div>
                        <div class="col-4 border-end border-purple-subtle">
                            <div class="extra-small text-muted">อัตราสิ้นเปลือง</div>
                            <div class="fw-bold text-success fs-5" id="calc_fc_rate">0.00 กม./ลิตร</div>
                        </div>
                        <div class="col-4">
                            <div class="extra-small text-muted">ค่าใช้จ่ายต่อกิโลเมตร</div>
                            <div class="fw-bold text-dark fs-5" id="calc_cost_per_km">0.00 ฿/กม.</div>
                        </div>
                    </div>
                </div>

                <!-- แนบไฟล์ใบเสร็จ และ หมายเหตุ -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark"><i class="fa-solid fa-file-invoice me-1 text-purple"></i>แนบไฟล์ใบเสร็จ / บิลค่าน้ำมัน</label>
                        <input type="file" name="receipt_file" class="form-control rounded-3" accept="image/*,.pdf">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">บันทึกเหตุการณ์ระหว่างเดินทาง</label>
                        <input type="text" name="trip_notes" class="form-control rounded-3" placeholder="เช่น ฝนตกหนัก, มีการเปลี่ยนเส้นทาง">
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple fw-bold rounded-pill px-5 shadow-sm">
                    <i class="fa-solid fa-check me-1"></i> บันทึกข้อมูลทริป
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function onBookingSelect(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt.value != 0) {
        const vehId = opt.getAttribute('data-veh-id');
        const odo = opt.getAttribute('data-odo');
        const driver = opt.getAttribute('data-driver');

        if (vehId) document.getElementById('modal_veh_id').value = vehId;
        if (odo) document.getElementById('calc_start_odo').value = odo;
        if (driver) document.getElementById('modal_driver_name').value = driver;
        calcTripMetrics();
    }
}

function calcTripMetrics() {
    const start = parseFloat(document.getElementById('calc_start_odo').value) || 0;
    const end = parseFloat(document.getElementById('calc_end_odo').value) || 0;
    const distance = Math.max(0, end - start);
    document.getElementById('calc_total_distance').textContent = distance.toFixed(1) + ' กม.';

    const liters = parseFloat(document.getElementById('calc_fuel_liters').value) || 0;
    const fuel = parseFloat(document.getElementById('calc_fuel_cost').value) || 0;
    const toll = parseFloat(document.getElementById('calc_toll_cost').value) || 0;
    const park = parseFloat(document.getElementById('calc_parking_cost').value) || 0;
    const allow = parseFloat(document.getElementById('calc_allowance').value) || 0;
    const misc = parseFloat(document.getElementById('calc_misc').value) || 0;

    const totalExp = fuel + toll + park + allow + misc;
    document.getElementById('calc_total_expenses').textContent = totalExp.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ฿';

    const fcRate = (liters > 0) ? (distance / liters) : 0;
    document.getElementById('calc_fc_rate').textContent = fcRate.toFixed(2) + ' กม./ลิตร';

    const costPerKm = (distance > 0) ? (totalExp / distance) : 0;
    document.getElementById('calc_cost_per_km').textContent = costPerKm.toFixed(2) + ' ฿/กม.';
}

function viewTripDetail(lg) {
    let msg = `รายละเอียดค่าใช้จ่ายทริป [${lg.booking_code || '-'}]\n`;
    msg += `------------------------------------\n`;
    msg += `รถ: ${lg.model} (${lg.plate_number})\n`;
    msg += `ภารกิจ: ${lg.title}\n`;
    msg += `ปลายทาง: ${lg.destination}\n`;
    msg += `ระยะทาง: ${lg.total_distance} กม. (ไมล์: ${lg.start_odometer} - ${lg.end_odometer})\n`;
    msg += `ค่าน้ำมัน: ${lg.fuel_cost} บาท (${lg.fuel_liters} ลิตร)\n`;
    msg += `ค่าทางด่วน: ${lg.toll_cost} บาท\n`;
    msg += `ค่าจอดรถ: ${lg.parking_cost} บาท\n`;
    msg += `ค่าเบี้ยเลี้ยง/ที่พัก: ${lg.driver_allowance} บาท\n`;
    msg += `ค่าใช้จ่ายอื่นๆ: ${lg.misc_cost} บาท\n`;
    msg += `รวมทั้งสิ้น: ${lg.total_expenses} บาท\n`;
    msg += `อัตราสิ้นเปลือง: ${lg.fuel_consumption_rate} กม./ลิตร (${lg.cost_per_km} บาท/กม.)`;
    alert(msg);
}

// Preselect trigger if loaded from url
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('modal_booking_select');
    if (sel && sel.value != 0) {
        onBookingSelect(sel);
        new bootstrap.Modal(document.getElementById('tripLogModal')).show();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
