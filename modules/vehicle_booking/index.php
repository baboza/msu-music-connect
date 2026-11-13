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
$is_supply = in_array('งานพัสดุ', $user_roles);
$is_head_office = in_array('หัวหน้าสำนักงาน', $user_roles) || $current_user_id === '34ee8171';
$is_deputy_admin = in_array('รองคณบดีฝ่ายบริหาร', $user_roles) || $current_user_id === '86580da6';
$can_manage = $is_admin || $is_supply || $is_head_office || $is_deputy_admin;

// จัดการบันทึกแก้ไขข้อมูลและสถานะรถ (เฉพาะ Admin และ งานพัสดุ)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_vehicle') {
    if ($is_admin || $is_supply) {
        $veh_id = (int)($_POST['vehicle_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $odo = (int)($_POST['current_odometer'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $seating = max(1, (int)($_POST['seating_capacity'] ?? 1));
        $fuel = trim($_POST['fuel_type'] ?? '');

        $stmt_up_veh = $pdo->prepare("UPDATE vehicles SET status = ?, current_odometer = ?, notes = ?, seating_capacity = ?, fuel_type = ? WHERE vehicle_id = ?");
        $stmt_up_veh->execute([$status, $odo, $notes, $seating, $fuel, $veh_id]);
        $_SESSION['msg'] = 'อัปเดตข้อมูลและสถานะรถเรียบร้อยแล้ว';
        $_SESSION['msg_type'] = 'success';
        header("Location: index.php");
        exit();
    }
}

// ฟิลเตอร์
$filter_vehicle = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$filter_status = $_GET['status'] ?? 'all';
$filter_month = $_GET['month'] ?? date('Y-m');

// ดึงข้อมูลรถทั้ง 2 คัน
$stmt_vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id ASC");
$vehicles = $stmt_vehicles->fetchAll(PDO::FETCH_ASSOC);

// สถิติภาพรวม
$total_bookings = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings")->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status IN ('pending_head', 'pending_deputy')")->fetchColumn();
$approved_count = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'approved'")->fetchColumn();
$today_count = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'approved' AND DATE(start_datetime) <= CURDATE() AND DATE(end_datetime) >= CURDATE()")->fetchColumn();

// ดึงรายการคำขอใช้รถตามฟิลเตอร์
$sql_list = "
    SELECT b.*, v.plate_number, v.model, v.brand, v.vehicle_type,
           u.prefix_name, u.first_name, u.last_name, u.position
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE 1=1
";
$params_list = [];

if ($filter_vehicle > 0) {
    $sql_list .= " AND b.vehicle_id = ?";
    $params_list[] = $filter_vehicle;
}
if ($filter_status !== 'all') {
    $sql_list .= " AND b.status = ?";
    $params_list[] = $filter_status;
}
$sql_list .= " ORDER BY b.start_datetime DESC LIMIT 50";

$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params_list);
$bookings = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลคิวการใช้รถสำหรับปฏิทิน/ไทม์ไลน์ในเดือนนี้
$stmt_cal = $pdo->prepare("
    SELECT b.booking_id, b.booking_code, b.title, b.destination, b.start_datetime, b.end_datetime, b.status,
           v.vehicle_id, v.plate_number, v.model, v.vehicle_type,
           u.first_name, u.last_name
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE b.status IN ('pending_head', 'pending_deputy', 'approved', 'completed')
      AND (DATE_FORMAT(b.start_datetime, '%Y-%m') = ? OR DATE_FORMAT(b.end_datetime, '%Y-%m') = ?)
    ORDER BY b.start_datetime ASC
");
$stmt_cal->execute([$filter_month, $filter_month]);
$cal_events = $stmt_cal->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
.vehicle-card {
    transition: all 0.25s ease;
    border-radius: 1.25rem;
    overflow: hidden;
}
.vehicle-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px -10px rgba(57, 4, 143, 0.15) !important;
}
.badge-pill-modern {
    padding: 0.4rem 0.85rem;
    font-weight: 600;
    font-size: 0.78rem;
    border-radius: 2rem;
}
.table-hover tbody tr:hover {
    background-color: rgba(57, 4, 143, 0.02);
}
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Top Navigation Bar & Module Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-purple-subtle text-purple px-2 py-1 rounded-pill small">
                    <i class="fa-solid fa-van-shuttle me-1"></i> ยานพาหนะส่วนกลาง
                </span>
                <span class="text-muted small">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-car-side text-purple me-2"></i>ระบบจองและบริหารจัดการรถวิทยาลัย
            </h3>
            <p class="text-muted small mb-0">
                ขอใช้รถราชการ ติดตามสถานะการอนุมัติ 3 ขั้นตอน บันทึกการเดินทาง ค่าใช้จ่าย อัตราสิ้นเปลือง และตรวจสภาพรถ
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="create.php" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> ขอใช้รถคันใหม่
            </a>
            <a href="my_bookings.php" class="btn btn-outline-purple rounded-pill px-3 fw-semibold">
                <i class="fa-solid fa-list-check me-1"></i> รายการของฉัน
            </a>
            <?php if ($can_manage): ?>
                <a href="approve_list.php" class="btn btn-outline-warning rounded-pill px-3 fw-semibold text-dark">
                    <i class="fa-solid fa-stamp me-1 text-warning"></i> ศูนย์พิจารณาอนุมัติ
                    <?php if ($pending_count > 0): ?>
                        <span class="badge rounded-pill bg-danger ms-1"><?= $pending_count ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <a href="trip_logs.php" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold">
                <i class="fa-solid fa-gas-pump me-1"></i> ค่าใช้จ่าย & อัตราสิ้นเปลือง
            </a>
            <a href="inspection.php" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold">
                <i class="fa-solid fa-clipboard-check me-1"></i> ตรวจเช็คสภาพรถ
            </a>
        </div>
    </div>

    <!-- Bento Overview Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">รถประจำวิทยาลัย</span>
                    <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-truck-ramp-box text-purple"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0">2 <span class="fs-6 text-muted fw-normal">คัน</span></div>
                <div class="extra-small text-success mt-1"><i class="fa-solid fa-circle-check me-1"></i>พร้อมให้บริการทั้ง 2 คัน</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">รอการพิจารณา</span>
                    <div class="rounded-circle bg-warning-subtle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-hourglass-half text-warning"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($pending_count) ?> <span class="fs-6 text-muted fw-normal">รายการ</span></div>
                <div class="extra-small text-muted mt-1">หัวหน้าสำนักงาน > รองบริหาร</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">อนุมัติแล้ว</span>
                    <div class="rounded-circle bg-success-subtle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-circle-check text-success"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($approved_count) ?> <span class="fs-6 text-muted fw-normal">รายการ</span></div>
                <div class="extra-small text-muted mt-1">พร้อมออกปฏิบัติภารกิจ</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold">เดินทางวันนี้</span>
                    <div class="rounded-circle bg-info-subtle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-route text-info"></i>
                    </div>
                </div>
                <div class="h3 fw-bold text-dark mb-0"><?= number_format($today_count) ?> <span class="fs-6 text-muted fw-normal">ภารกิจ</span></div>
                <div class="extra-small text-muted mt-1"><?= formatThaiDateOnly(date('Y-m-d')) ?></div>
            </div>
        </div>
    </div>

    <!-- Vehicles Showcase (2 คันของวิทยาลัย) -->
    <div class="row g-4 mb-4">
        <?php foreach ($vehicles as $veh): 
            $is_cargo = ($veh['vehicle_type'] === 'cargo_instruments');
            $card_badge_color = $is_cargo ? 'bg-danger text-white' : 'bg-purple text-white';
            $veh_icon = $is_cargo ? 'fa-solid fa-truck-front' : 'fa-solid fa-van-shuttle';
        ?>
            <div class="col-lg-6">
                <div class="card border-0 shadow-xs vehicle-card bg-white h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <span class="badge <?= $card_badge_color ?> px-3 py-1 rounded-pill small mb-2">
                                    <i class="<?= $veh_icon ?> me-1"></i> <?= $is_cargo ? 'คันที่ 2: บรรทุกเครื่องดนตรี' : 'คันที่ 1: รับส่งบุคคล / ผู้บริหาร' ?>
                                </span>
                                <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($veh['model']) ?></h4>
                                <div class="text-muted small">
                                    <span class="fw-semibold text-dark"><i class="fa-solid fa-id-card me-1 text-purple"></i>ทะเบียน:</span> 
                                    <span class="badge bg-light text-dark border px-2 py-1 fw-bold"><?= htmlspecialchars($veh['plate_number']) ?></span>
                                    <span class="ms-2"><i class="fa-solid fa-calendar me-1 text-purple"></i>ปี <?= htmlspecialchars($veh['year_made']) ?></span>
                                </div>
                            </div>
                            <?php
                            $v_status = $veh['status'] ?? 'available';
                            if ($v_status === 'maintenance'): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-3 py-2 rounded-pill small">
                                    <i class="fa-solid fa-wrench me-1"></i> เข้าศูนย์ / ซ่อมบำรุง
                                </span>
                            <?php elseif ($v_status === 'unavailable'): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill small">
                                    <i class="fa-solid fa-ban me-1"></i> งดใช้งานชั่วคราว
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill small">
                                    <i class="fa-solid fa-circle-dot me-1"></i> พร้อมใช้งาน
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="text-muted small mb-3" style="line-height: 1.6;">
                            <?= htmlspecialchars($veh['notes'] ?? 'ไม่มีหมายเหตุเพิ่มเติม') ?>
                        </p>

                        <div class="row g-2 p-3 bg-light rounded-3 mb-3 text-center">
                            <div class="col-4 border-end">
                                <div class="extra-small text-muted mb-1"><i class="fa-solid fa-users me-1 text-purple"></i>ความจุผู้โดยสาร</div>
                                <div class="fw-bold text-dark small"><?= $veh['seating_capacity'] ?> ที่นั่ง</div>
                            </div>
                            <div class="col-4 border-end">
                                <div class="extra-small text-muted mb-1"><i class="fa-solid fa-gas-pump me-1 text-purple"></i>เชื้อเพลิง</div>
                                <div class="fw-bold text-dark small"><?= htmlspecialchars($veh['fuel_type']) ?></div>
                            </div>
                            <div class="col-4">
                                <div class="extra-small text-muted mb-1"><i class="fa-solid fa-gauge-high me-1 text-purple"></i>เลขไมล์สะสม</div>
                                <div class="fw-bold text-dark small"><?= number_format($veh['current_odometer']) ?> กม.</div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <a href="create.php?vehicle_id=<?= $veh['vehicle_id'] ?>" class="btn btn-purple rounded-pill px-4 fw-semibold shadow-2xs">
                                <i class="fa-solid fa-calendar-plus me-1"></i> จองคันนี้
                            </a>
                            <div class="d-flex gap-2">
                                <?php if ($is_admin || $is_supply): ?>
                                    <button type="button" class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-semibold" onclick='openEditVehicleModal(<?= htmlspecialchars(json_encode($veh), ENT_QUOTES, 'UTF-8') ?>)' title="จัดการข้อมูลและสถานะรถ (งานพัสดุ)">
                                        <i class="fa-solid fa-screwdriver-wrench me-1"></i> จัดการรถ
                                    </button>
                                <?php endif; ?>
                                <a href="inspection.php?vehicle_id=<?= $veh['vehicle_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="ตรวจเช็คสภาพรถ">
                                    <i class="fa-solid fa-clipboard-check me-1"></i> ตรวจสภาพ
                                </a>
                                <a href="trip_logs.php?vehicle_id=<?= $veh['vehicle_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="ประวัติการเดินทางและอัตราสิ้นเปลือง">
                                    <i class="fa-solid fa-chart-line me-1"></i> บันทึกทริป
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Month Schedule Calendar / Conflict Timeline -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4 overflow-hidden">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-calendar-days text-purple me-2"></i>ตารางการใช้รถประจำเดือน (Schedule Timeline)
            </h5>
            <form method="GET" class="d-flex align-items-center gap-2">
                <label class="small text-muted">เลือกเดือน:</label>
                <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>" class="form-control form-control-sm rounded-pill" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-4">
            <?php if (empty($cal_events)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-regular fa-calendar-xmark fa-2x mb-2 text-secondary opacity-50 d-block"></i>
                    ยังไม่มีการจองรถในเดือนนี้ รถทั้ง 2 คันว่างพร้อมให้บริการ
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($cal_events as $ev): 
                        $badge = getVehicleBookingStatusBadge($ev['status']);
                        $is_c2 = ($ev['vehicle_type'] === 'cargo_instruments');
                        $card_border = $is_c2 ? 'border-danger' : 'border-purple';
                        $icon_c = $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple';
                    ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="p-3 rounded-4 border <?= $card_border ?> bg-light shadow-2xs h-100 position-relative">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-white text-dark border extra-small">
                                        <i class="fa-solid <?= $icon_c ?> me-1"></i> <?= htmlspecialchars($ev['plate_number']) ?>
                                    </span>
                                    <span class="badge <?= $badge['class'] ?> extra-small rounded-pill">
                                        <?= $badge['label'] ?>
                                    </span>
                                </div>
                                <h6 class="fw-bold text-dark mb-1 text-truncate" title="<?= htmlspecialchars($ev['title']) ?>">
                                    <?= htmlspecialchars($ev['title']) ?>
                                </h6>
                                <div class="text-muted extra-small mb-2">
                                    <i class="fa-solid fa-location-dot me-1 text-danger"></i><?= htmlspecialchars($ev['destination']) ?>
                                </div>
                                <div class="p-2 bg-white rounded-3 border extra-small mb-2">
                                    <div><strong>เริ่ม:</strong> <?= formatThaiDT($ev['start_datetime']) ?></div>
                                    <div><strong>ถึง:</strong> <?= formatThaiDT($ev['end_datetime']) ?></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center extra-small text-muted">
                                    <span><i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($ev['first_name'] . ' ' . $ev['last_name']) ?></span>
                                    <a href="view.php?id=<?= $ev['booking_id'] ?>" class="text-purple text-decoration-none fw-semibold">
                                        ดูรายละเอียด <i class="fa-solid fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking Requests Table -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-clock-rotate-left text-purple me-2"></i>รายการคำขอใช้รถล่าสุด
                </h5>
                <span class="text-muted extra-small">แสดงคำขอใช้รถและสถานะการอนุมัติ 3 ขั้นตอน</span>
            </div>
            <!-- Filters -->
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <select name="vehicle_id" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                    <option value="0">-- รถทั้งหมด (2 คัน) --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['vehicle_id'] ?>" <?= $filter_vehicle == $v['vehicle_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>-- ทุกสถานะ --</option>
                    <option value="pending_head" <?= $filter_status === 'pending_head' ? 'selected' : '' ?>>รอหัวหน้าสำนักงาน</option>
                    <option value="pending_deputy" <?= $filter_status === 'pending_deputy' ? 'selected' : '' ?>>รอรองบริหาร</option>
                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                    <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>เดินทางแล้ว</option>
                    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>ไม่อนุมัติ</option>
                </select>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (empty($bookings)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-car-tunnel text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ยังไม่มีรายการคำขอใช้รถ</h6>
                    <p class="text-muted small mb-3">คุณสามารถสร้างคำขอใช้รถคันแรกของวิทยาลัยได้ทันที</p>
                    <a href="create.php" class="btn btn-purple rounded-pill px-4 btn-sm fw-semibold">
                        <i class="fa-solid fa-plus me-1"></i> ขอใช้รถตอนนี้
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted extra-small">
                                <th class="ps-4" style="width: 140px;">รหัสคำขอ</th>
                                <th>รถที่ขอใช้</th>
                                <th>ภารกิจ / ปลายทาง</th>
                                <th style="width: 190px;">วันเวลาเดินทาง</th>
                                <th>ผู้ขอใช้รถ</th>
                                <th style="width: 160px;" class="text-center">สถานะคำขอ</th>
                                <th style="width: 130px;" class="text-end pe-4">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): 
                                $badge = getVehicleBookingStatusBadge($b['status']);
                                $user_fullname = trim(($b['prefix_name'] ?? '') . $b['first_name'] . ' ' . $b['last_name']);
                                $is_c2 = ($b['vehicle_type'] === 'cargo_instruments');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <a href="view.php?id=<?= $b['booking_id'] ?>" class="fw-bold text-purple text-decoration-none">
                                            <?= htmlspecialchars($b['booking_code']) ?>
                                        </a>
                                        <div class="extra-small text-muted"><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark small">
                                            <i class="fa-solid <?= $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple' ?> me-1"></i>
                                            <?= htmlspecialchars($b['model']) ?>
                                        </div>
                                        <div class="badge bg-light text-dark border extra-small mt-1">
                                            <?= htmlspecialchars($b['plate_number']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($b['title']) ?></div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($b['destination']) ?>
                                            • <?= $b['passenger_count'] ?> ที่นั่ง
                                            <?php if (!empty($b['instruments_detail'])): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle ms-1">
                                                    <i class="fa-solid fa-guitar me-1"></i>มีเครื่องดนตรี
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= formatThaiDT($b['start_datetime']) ?></div>
                                        <div class="extra-small text-muted">ถึง <?= formatThaiDT($b['end_datetime']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= htmlspecialchars($user_fullname) ?></div>
                                        <div class="extra-small text-muted"><?= htmlspecialchars($b['position'] ?? 'บุคลากร') ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badge['class'] ?> badge-pill-modern">
                                            <i class="<?= $badge['icon'] ?> me-1"></i><?= $badge['label'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group shadow-2xs rounded-pill">
                                            <a href="view.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-light border" title="ดูรายละเอียด / พิจารณา">
                                                <i class="fa-regular fa-eye text-purple"></i>
                                            </a>
                                            <a href="print.php?id=<?= $b['booking_id'] ?>" target="_blank" class="btn btn-sm btn-light border" title="พิมพ์ใบขอใช้รถ">
                                                <i class="fa-solid fa-print text-secondary"></i>
                                            </a>
                                        </div>
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

<?php if ($is_admin || $is_supply): ?>
<!-- Modal จัดการข้อมูลและสถานะรถ (งานพัสดุ & ผู้ดูแล) -->
<div class="modal fade" id="manageVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="update_vehicle">
            <input type="hidden" name="vehicle_id" id="edit_veh_id">

            <div class="modal-header border-0 bg-purple bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-purple">
                    <i class="fa-solid fa-screwdriver-wrench me-2"></i>จัดการข้อมูลและสถานะรถ (งานพัสดุ)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">รุ่นรถ / ทะเบียน</label>
                    <input type="text" id="edit_veh_model_display" class="form-control rounded-3 bg-light fw-semibold" readonly>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">สถานะความพร้อม <span class="text-danger">*</span></label>
                        <select name="status" id="edit_veh_status" class="form-select rounded-3" required>
                            <option value="available">✓ พร้อมใช้งาน (Available)</option>
                            <option value="maintenance">⚠ ซ่อมบำรุง / เข้าศูนย์ (Maintenance)</option>
                            <option value="unavailable">✗ งดใช้งานชั่วคราว (Unavailable)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">เลขไมล์สะสมล่าสุด (กม.) <span class="text-danger">*</span></label>
                        <input type="number" name="current_odometer" id="edit_veh_odometer" class="form-control rounded-3" required min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ความจุผู้โดยสาร (ที่นั่ง)</label>
                        <input type="number" name="seating_capacity" id="edit_veh_seating" class="form-control rounded-3" min="1" max="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ชนิดเชื้อเพลิง</label>
                        <input type="text" name="fuel_type" id="edit_veh_fuel" class="form-control rounded-3" placeholder="เช่น ดีเซล B7">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">หมายเหตุ / ข้อมูลการบำรุงรักษา</label>
                    <textarea name="notes" id="edit_veh_notes" class="form-control rounded-3" rows="3" placeholder="ระบุบันทึกรายละเอียด เช่น เข้าเช็คระยะ 30,000 กม. เปลี่ยนถ่ายน้ำมันเครื่องแล้ว"></textarea>
                </div>
            </div>

            <div class="modal-footer border-0 bg-light py-3 px-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold">
                    <i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditVehicleModal(veh) {
    document.getElementById('edit_veh_id').value = veh.vehicle_id;
    document.getElementById('edit_veh_model_display').value = veh.model + ' (ทะเบียน: ' + veh.plate_number + ')';
    document.getElementById('edit_veh_status').value = veh.status || 'available';
    document.getElementById('edit_veh_odometer').value = veh.current_odometer || 0;
    document.getElementById('edit_veh_seating').value = veh.seating_capacity || 1;
    document.getElementById('edit_veh_fuel').value = veh.fuel_type || '';
    document.getElementById('edit_veh_notes').value = veh.notes || '';

    new bootstrap.Modal(document.getElementById('manageVehicleModal')).show();
}
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
