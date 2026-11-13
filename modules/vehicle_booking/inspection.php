<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$current_user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];

$filter_vehicle = (int)($_GET['vehicle_id'] ?? 0);
$preselect_booking_id = (int)($_GET['booking_id'] ?? 0);

$msg = '';
$msg_type = '';

// Handle POST บันทึกการตรวจเช็คสภาพรถ
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_inspection') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $booking_id = (int)($_POST['booking_id'] ?? 0) ?: null;
    $inspection_type = $_POST['inspection_type'] ?? 'pre_trip';
    $inspector_name = trim($_POST['inspector_name'] ?? '');
    $odometer_reading = (int)($_POST['odometer_reading'] ?? 0);

    $engine_oil = $_POST['engine_oil_check'] ?? 'good';
    $coolant = $_POST['coolant_check'] ?? 'good';
    $tires = $_POST['tires_check'] ?? 'good';
    $brakes = $_POST['brakes_check'] ?? 'good';
    $lights = $_POST['lights_check'] ?? 'good';
    $battery = $_POST['battery_check'] ?? 'good';
    $cleanliness = $_POST['cleanliness_check'] ?? 'clean';
    $cargo_safety = $_POST['cargo_safety_check'] ?? 'good';

    $overall_status = $_POST['overall_status'] ?? 'pass';
    $defect_notes = trim($_POST['defect_notes'] ?? '');

    try {
        $stmt_ins = $pdo->prepare("
            INSERT INTO vehicle_inspections 
            (booking_id, vehicle_id, inspection_type, inspector_id, inspector_name, inspection_date, 
             odometer_reading, engine_oil_check, coolant_check, tires_check, brakes_check, 
             lights_check, battery_check, cleanliness_check, cargo_safety_check, 
             overall_status, defect_notes, created_at)
            VALUES 
            (:bid, :veh, :itype, :uid, :iname, NOW(), 
             :odo, :oil, :coolant, :tires, :brakes, 
             :lights, :batt, :clean, :cargo, 
             :overall, :defects, NOW())
        ");
        $stmt_ins->execute([
            ':bid' => $booking_id,
            ':veh' => $vehicle_id,
            ':itype' => $inspection_type,
            ':uid' => $current_user_id,
            ':iname' => $inspector_name,
            ':odo' => $odometer_reading,
            ':oil' => $engine_oil,
            ':coolant' => $coolant,
            ':tires' => $tires,
            ':brakes' => $brakes,
            ':lights' => $lights,
            ':batt' => $battery,
            ':clean' => $cleanliness,
            ':cargo' => $cargo_safety,
            ':overall' => $overall_status,
            ':defects' => $defect_notes
        ]);

        // อัปเดตสถานะรถในตาราง vehicles หากพบข้อบกพร่องร้ายแรง
        if ($overall_status === 'fail_grounded') {
            $pdo->prepare("UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = ?")->execute([$vehicle_id]);
        } elseif ($overall_status === 'pass') {
            $pdo->prepare("UPDATE vehicles SET status = 'available' WHERE vehicle_id = ? AND status = 'maintenance'")->execute([$vehicle_id]);
        }

        if ($odometer_reading > 0) {
            $pdo->prepare("UPDATE vehicles SET current_odometer = GREATEST(current_odometer, ?) WHERE vehicle_id = ?")->execute([$odometer_reading, $vehicle_id]);
        }

        $msg = 'บันทึกผลการตรวจเช็คสภาพรถเรียบร้อยแล้ว';
        $msg_type = 'success';
    } catch (PDOException $e) {
        $msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ดึงข้อมูลรถทั้ง 2 คัน
$vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายการตรวจเช็คล่าสุด
$sql_insp = "
    SELECT i.*, v.plate_number, v.model, v.vehicle_type,
           b.booking_code, b.title as booking_title
    FROM vehicle_inspections i
    LEFT JOIN vehicles v ON i.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_bookings b ON i.booking_id = b.booking_id
    WHERE 1=1
";
$params_insp = [];
if ($filter_vehicle > 0) {
    $sql_insp .= " AND i.vehicle_id = ?";
    $params_insp[] = $filter_vehicle;
}
$sql_insp .= " ORDER BY i.inspection_id DESC LIMIT 50";
$stmt_insp = $pdo->prepare($sql_insp);
$stmt_insp->execute($params_insp);
$inspections = $stmt_insp->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลคำขอที่รอตรวจสภาพ (ถ้ามี)
$recent_bookings = $pdo->query("
    SELECT booking_id, booking_code, title, vehicle_id 
    FROM vehicle_bookings 
    WHERE status IN ('approved', 'completed')
    ORDER BY booking_id DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

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
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 rounded-pill small">
                    <i class="fa-solid fa-clipboard-check me-1"></i> บำรุงรักษา & ความปลอดภัย
                </span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-screwdriver-wrench text-purple me-2"></i>ตรวจเช็คสภาพรถยนต์ราชการ (Vehicle Inspection)
            </h3>
            <p class="text-muted small mb-0">
                แบบบันทึกการตรวจความพร้อมก่อน-หลังเดินทาง: ระดับของเหลว ลมยาง เบรก ไฟส่องสว่าง แบตเตอรี่ และตู้บรรทุกเครื่องดนตรี
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#inspectionModal">
                <i class="fa-solid fa-plus me-1"></i> ตรวจสภาพรถตอนนี้
            </button>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Status Cards ของรถทั้ง 2 คัน -->
    <div class="row g-4 mb-4">
        <?php foreach ($vehicles as $v): 
            $is_c2 = ($v['vehicle_type'] === 'cargo_instruments');
            $icon = $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple';
            $badge_status = ($v['status'] === 'maintenance') 
                ? 'bg-danger text-white' 
                : (($v['status'] === 'in_use') ? 'bg-warning text-dark' : 'bg-success text-white');
            $status_label = ($v['status'] === 'maintenance') 
                ? 'กำลังซ่อมบำรุง' 
                : (($v['status'] === 'in_use') ? 'อยู่ระหว่างเดินทาง' : 'พร้อมใช้งาน (ปกติ)');
        ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-xs rounded-4 bg-white p-4 h-100">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-light text-dark border extra-small mb-1">
                                <i class="fa-solid <?= $icon ?> me-1"></i> <?= htmlspecialchars($v['plate_number']) ?>
                            </span>
                            <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($v['model']) ?></h5>
                        </div>
                        <span class="badge <?= $badge_status ?> rounded-pill px-3 py-1 extra-small">
                            <?= $status_label ?>
                        </span>
                    </div>

                    <div class="row g-2 extra-small text-muted border-top border-bottom py-2 my-2">
                        <div class="col-6">เลขไมล์ล่าสุด: <strong><?= number_format($v['current_odometer']) ?> กม.</strong></div>
                        <div class="col-6">ความจุ: <strong><?= $v['seating_capacity'] ?> ที่นั่ง</strong></div>
                        <div class="col-12">ประเภท: <strong><?= $is_c2 ? 'บรรทุกเครื่องดนตรีและอุปกรณ์วงดุริยางค์' : 'รับส่งผู้บริหารและบุคคล' ?></strong></div>
                    </div>

                    <div class="d-flex justify-content-end mt-2">
                        <button type="button" class="btn btn-sm btn-outline-purple rounded-pill px-3" onclick="openInspectionFor(<?= $v['vehicle_id'] ?>, '<?= htmlspecialchars(addslashes($v['model'])) ?>', <?= $v['current_odometer'] ?>)">
                            <i class="fa-solid fa-clipboard-check me-1"></i> บันทึกการตรวจสภาพคันนี้
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ประวัติการตรวจสภาพรถ -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-purple me-2"></i>ประวัติการตรวจเช็คสภาพรถล่าสุด</h5>
                <span class="text-muted extra-small">รายการตรวจเช็คความพร้อมก่อนและหลังการใช้งาน</span>
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
            <?php if (empty($inspections)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-clipboard-check text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ยังไม่มีบันทึกการตรวจเช็คสภาพรถ</h6>
                    <p class="text-muted small mb-0">ท่านสามารถกดปุ่ม "ตรวจสภาพรถตอนนี้" เพื่อเริ่มบันทึกความพร้อมได้ทันที</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted extra-small">
                                <th class="ps-4" style="width: 140px;">วันเวลาตรวจ</th>
                                <th>รถวิทยาลัย</th>
                                <th style="width: 140px;">ประเภทการตรวจ</th>
                                <th>ผู้ตรวจเช็ค</th>
                                <th style="width: 130px;">เลขไมล์ที่ตรวจ</th>
                                <th style="width: 150px;" class="text-center">ผลการตรวจ</th>
                                <th style="width: 100px;" class="text-end pe-4">หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inspections as $insp): 
                                $is_c2 = ($insp['vehicle_type'] === 'cargo_instruments');
                                $type_label = ($insp['inspection_type'] === 'pre_trip') ? 'ก่อนออกเดินทาง' : (($insp['inspection_type'] === 'post_trip') ? 'หลังเสร็จสิ้นภารกิจ' : 'ตรวจตามรอบ');
                                $res_badge = ($insp['overall_status'] === 'pass') ? 'bg-success text-white' : (($insp['overall_status'] === 'pass_with_conditions') ? 'bg-warning text-dark' : 'bg-danger text-white');
                                $res_text = ($insp['overall_status'] === 'pass') ? 'ผ่าน พร้อมใช้งาน' : (($insp['overall_status'] === 'pass_with_conditions') ? 'ผ่าน (มีข้อสังเกต)' : 'ไม่ผ่าน (ต้องซ่อม)');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="small fw-bold text-dark"><?= formatThaiDT($insp['inspection_date']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark small">
                                            <i class="fa-solid <?= $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple' ?> me-1"></i>
                                            <?= htmlspecialchars($insp['model']) ?>
                                        </div>
                                        <span class="badge bg-light text-dark border extra-small mt-1"><?= htmlspecialchars($insp['plate_number']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small">
                                            <?= $type_label ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="small text-dark fw-semibold"><?= htmlspecialchars($insp['inspector_name'] ?: 'เจ้าหน้าที่') ?></div>
                                    </td>
                                    <td>
                                        <div class="small text-dark"><?= number_format($insp['odometer_reading']) ?> กม.</div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $res_badge ?> rounded-pill px-3 py-1 extra-small">
                                            <?= $res_text ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if (!empty($insp['defect_notes'])): ?>
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2" onclick="alert('ข้อสังเกต / อาการผิดปกติ:\n<?= htmlspecialchars(addslashes($insp['defect_notes'])) ?>')">
                                                <i class="fa-solid fa-comment-dots text-warning"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted extra-small">ปกติ</span>
                                        <?php endif; ?>
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

<!-- Modal บันทึกผลการตรวจเช็คสภาพรถ -->
<div class="modal fade" id="inspectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="save_inspection">

            <div class="modal-header border-0 bg-purple bg-opacity-10 py-3">
                <h5 class="modal-title fw-bold text-purple">
                    <i class="fa-solid fa-clipboard-check me-2"></i>บันทึกการตรวจเช็คสภาพรถยนต์ราชการ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">เลือกรถวิทยาลัย <span class="text-danger">*</span></label>
                        <select name="vehicle_id" id="insp_veh_id" class="form-select rounded-3" required onchange="onInspVehChange(this)">
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>" data-odo="<?= $v['current_odometer'] ?>" data-type="<?= $v['vehicle_type'] ?>">
                                    <?= htmlspecialchars($v['model']) ?> (<?= htmlspecialchars($v['plate_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ประเภทการตรวจเช็ค</label>
                        <select name="inspection_type" class="form-select rounded-3">
                            <option value="pre_trip">ตรวจก่อนออกเดินทาง (Pre-Trip)</option>
                            <option value="post_trip">ตรวจหลังเสร็จสิ้นภารกิจ (Post-Trip)</option>
                            <option value="routine">ตรวจเช็คประจำรอบ / สัปดาห์</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ชื่อผู้ตรวจเช็ค</label>
                        <input type="text" name="inspector_name" class="form-control rounded-3" placeholder="เช่น นายเนตร (พนักงานขับรถ) หรือชื่อผู้ขอ" value="นายเนตร เนื่องวรรณะ">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">เลขไมล์ ณ เวลาที่ตรวจ (กม.)</label>
                        <input type="number" name="odometer_reading" id="insp_odometer" class="form-control rounded-3" value="15200">
                    </div>
                </div>

                <!-- 8 เกณฑ์ตรวจสภาพรถ Checklist -->
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check text-purple me-1"></i> รายการตรวจสอบสภาพรถ 8 จุดสำคัญ</h6>
                <div class="card bg-light border p-3 rounded-4 mb-3">
                    <div class="row g-3">
                        <!-- 1. น้ำมันเครื่อง -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">1. ระดับน้ำมันเครื่อง</label>
                            <select name="engine_oil_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ ปกติ (ระดับเต็มขีด F/Full)</option>
                                <option value="low">⚠ ระดับต่ำ ควรเติม</option>
                                <option value="needs_change">✗ ดำ/เสื่อมสภาพ ต้องเปลี่ยนถ่าย</option>
                            </select>
                        </div>
                        <!-- 2. น้ำยาหล่อเย็น -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">2. ระดับน้ำยาหล่อเย็นหม้อน้ำ</label>
                            <select name="coolant_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ ปกติ (ระหว่าง Min - Max)</option>
                                <option value="low">⚠ พร่อง ควรเติมน้ำยาหล่อเย็น</option>
                                <option value="abnormal">✗ ผิดปกติ มีรอยรั่วซึม</option>
                            </select>
                        </div>
                        <!-- 3. ลมยาง & สภาพยาง -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">3. ลมยางและสภาพยาง 4 ล้อ + อะไหล่</label>
                            <select name="tires_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ ปกติ ดอกยางสมบูรณ์</option>
                                <option value="low_pressure">⚠ ลมอ่อน ต้องเติมลมยาง</option>
                                <option value="worn">✗ ดอกยางสึก/แตกลายงา</option>
                            </select>
                        </div>
                        <!-- 4. ระบบเบรก -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">4. ระบบเบรกและน้ำมันเบรก</label>
                            <select name="brakes_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ ปกติ ระยะเหยียบมั่นใจ</option>
                                <option value="worn">⚠ มีเสียงดังขณะเบรก</option>
                                <option value="defect">✗ เบรกลึก/รั่วซึม ห้ามใช้งาน</option>
                            </select>
                        </div>
                        <!-- 5. ระบบไฟส่องสว่าง -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">5. ระบบไฟส่องสว่าง ไฟเลี้ยว ไฟเบรก</label>
                            <select name="lights_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ สว่างครบทุกดวง</option>
                                <option value="bulb_out">⚠ หลอดไฟบางดวงขาด</option>
                                <option value="defect">✗ ระบบไฟขัดข้อง</option>
                            </select>
                        </div>
                        <!-- 6. แบตเตอรี่ -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">6. แบตเตอรี่และระบบสตาร์ท</label>
                            <select name="battery_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ สตาร์ทติดง่าย ไฟแรง</option>
                                <option value="weak">⚠ ไฟอ่อน สตาร์ทหน่วง</option>
                                <option value="defect">✗ แบตเตอรี่เสื่อม</option>
                            </select>
                        </div>
                        <!-- 7. ความสะอาด -->
                        <div class="col-md-6">
                            <label class="form-label extra-small fw-bold text-dark mb-1">7. ความสะอาดห้องโดยสาร</label>
                            <select name="cleanliness_check" class="form-select form-select-sm rounded-3">
                                <option value="clean">✓ สะอาดเรียบร้อย</option>
                                <option value="normal">ปานกลาง พอใช้</option>
                                <option value="dirty">สกปรก ควรนำไปล้างทำความสะอาด</option>
                            </select>
                        </div>
                        <!-- 8. ตู้บรรทุกเครื่องดนตรี & สายรัด -->
                        <div class="col-md-6" id="cargoCheckContainer">
                            <label class="form-label extra-small fw-bold text-danger mb-1">8. ตู้บรรทุกเครื่องดนตรี / แร็คและสายรัด</label>
                            <select name="cargo_safety_check" class="form-select form-select-sm rounded-3">
                                <option value="good">✓ แร็คและสายรัดแน่นหนา ปลอดภัย</option>
                                <option value="strap_damage">⚠ สายรัดชำรุดบางจุด</option>
                                <option value="not_applicable">- ไม่ได้บรรทุกเครื่องดนตรี</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- สรุปผลการตรวจ -->
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ผลการตรวจสภาพโดยรวม <span class="text-danger">*</span></label>
                        <select name="overall_status" class="form-select rounded-3 fw-bold">
                            <option value="pass" class="text-success">✓ ผ่าน - รถพร้อมใช้งานออกปฏิบัติภารกิจ</option>
                            <option value="pass_with_conditions" class="text-warning">⚠ ผ่านแบบมีเงื่อนไข (แก้ไขจุดเล็กน้อยแล้วใช้งานได้)</option>
                            <option value="fail_grounded" class="text-danger">✗ ไม่ผ่าน - ต้องส่งซ่อมบำรุง งดใช้งานชั่วคราว</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ข้อสังเกต / อาการผิดปกติที่ตรวจพบ</label>
                        <input type="text" name="defect_notes" class="form-control rounded-3" placeholder="เช่น แอร์เริ่มไม่ค่อยเย็น, เติมลมล้อหลังซ้ายเพิ่ม 32 PSI">
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple fw-bold rounded-pill px-5 shadow-sm">
                    <i class="fa-solid fa-check me-1"></i> บันทึกผลการตรวจสภาพ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function onInspVehChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const odo = opt.getAttribute('data-odo');
    if (odo) {
        document.getElementById('insp_odometer').value = odo;
    }
}

function openInspectionFor(vehId, model, odo) {
    const sel = document.getElementById('insp_veh_id');
    if (sel) {
        sel.value = vehId;
        onInspVehChange(sel);
    }
    new bootstrap.Modal(document.getElementById('inspectionModal')).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
