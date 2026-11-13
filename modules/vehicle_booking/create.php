<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$current_user_id = $_SESSION['user_id'] ?? '';
$user_roles = $_SESSION['roles'] ?? [];

// ดึงข้อมูลผู้ยื่นคำขอ
$stmt_u = $pdo->prepare("SELECT prefix_name, first_name, last_name, position, email, phone FROM users WHERE user_id = ?");
$stmt_u->execute([$current_user_id]);
$current_user = $stmt_u->fetch(PDO::FETCH_ASSOC);

// ดึงข้อมูลรถทั้ง 2 คัน
$stmt_v = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id ASC");
$vehicles = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายชื่อพนักงานขับรถในระบบ
$stmt_drivers = $pdo->query("
    SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, u.phone 
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    WHERE r.role_name = 'พนักงานขับรถ' OR r.role_id = 34
    ORDER BY u.first_name ASC
");
$drivers = $stmt_drivers->fetchAll(PDO::FETCH_ASSOC);

$preselect_vehicle = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 1;
$error = '';
$conflict_warn = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $route_detail = trim($_POST['route_detail'] ?? '');
    $start_datetime = trim($_POST['start_datetime'] ?? '');
    $end_datetime = trim($_POST['end_datetime'] ?? '');
    $passenger_count = max(1, (int)($_POST['passenger_count'] ?? 1));
    $passenger_names = trim($_POST['passenger_names'] ?? '');
    $instruments_detail = trim($_POST['instruments_detail'] ?? '');
    $driver_type = $_POST['driver_type'] ?? 'driver_assigned';
    $driver_id = $_POST['driver_id'] ?? null;
    $driver_name = trim($_POST['driver_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validation
    if (empty($vehicle_id) || empty($title) || empty($destination) || empty($start_datetime) || empty($end_datetime)) {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (รถ, วัตถุประสงค์, สถานที่ปลายทาง และวันเวลาเดินทาง)';
    } elseif (strtotime($start_datetime) >= strtotime($end_datetime)) {
        $error = 'วันเวลาสิ้นสุดต้องอยู่หลังจากวันเวลาเริ่มต้นการเดินทาง';
    } else {
        // ตรวจสอบคิวรถชนกัน (Conflict Check)
        $conflicts = checkVehicleConflict($pdo, $vehicle_id, $start_datetime, $end_datetime);
        if (!empty($conflicts)) {
            $c_items = [];
            foreach ($conflicts as $cf) {
                $c_items[] = "{$cf['booking_code']} ({$cf['title']}) ช่วงเวลา: " . formatThaiDT($cf['start_datetime']) . " ถึง " . formatThaiDT($cf['end_datetime']);
            }
            $error = "รถคันที่ท่านเลือกมีภารกิจอื่นถูกจองไว้แล้วในช่วงเวลาเดียวกัน:<br>• " . implode("<br>• ", $c_items);
        } else {
            // ดึงชื่อคนขับถ้าเลือกจากรายการ
            if ($driver_type === 'driver_assigned' && !empty($driver_id)) {
                $stmt_dname = $pdo->prepare("SELECT prefix_name, first_name, last_name FROM users WHERE user_id = ?");
                $stmt_dname->execute([$driver_id]);
                if ($d_row = $stmt_dname->fetch()) {
                    $driver_name = trim(($d_row['prefix_name'] ?? '') . $d_row['first_name'] . ' ' . $d_row['last_name']);
                }
            } elseif ($driver_type === 'self_drive') {
                $driver_name = trim(($current_user['prefix_name'] ?? '') . $current_user['first_name'] . ' ' . $current_user['last_name']) . " (ผู้ขอขับเอง)";
                $driver_id = $current_user_id;
            }

            try {
                $booking_code = generateBookingCode($pdo);

                $stmt_ins = $pdo->prepare("
                    INSERT INTO vehicle_bookings 
                    (booking_code, user_id, vehicle_id, title, destination, route_detail, 
                     start_datetime, end_datetime, passenger_count, passenger_names, instruments_detail, 
                     driver_type, driver_id, driver_name, status, notes, created_at)
                    VALUES 
                    (:code, :uid, :veh_id, :title, :dest, :route, 
                     :start, :end, :pcount, :pnames, :inst, 
                     :dtype, :did, :dname, 'pending_head', :notes, NOW())
                ");

                $stmt_ins->execute([
                    ':code' => $booking_code,
                    ':uid' => $current_user_id,
                    ':veh_id' => $vehicle_id,
                    ':title' => $title,
                    ':dest' => $destination,
                    ':route' => $route_detail,
                    ':start' => $start_datetime,
                    ':end' => $end_datetime,
                    ':pcount' => $passenger_count,
                    ':pnames' => $passenger_names,
                    ':inst' => $instruments_detail,
                    ':dtype' => $driver_type,
                    ':did' => $driver_id,
                    ':dname' => $driver_name,
                    ':notes' => $notes
                ]);

                $new_id = $pdo->lastInsertId();

                $_SESSION['msg'] = "ยื่นคำขอใช้รถยนต์ราชการรหัส {$booking_code} เรียบร้อยแล้ว ขณะนี้คำขอเข้าสู่ขั้นตอนการพิจารณาของหัวหน้าสำนักงาน";
                $_SESSION['msg_type'] = "success";

                header("Location: view.php?id=" . $new_id);
                exit();
            } catch (PDOException $e) {
                $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
            }
        }
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Breadcrumb & Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="text-decoration-none text-muted small">
                    <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับไประบบจองรถ
                </a>
                <span class="text-muted">/</span>
                <span class="badge bg-purple-subtle text-purple px-2 py-1 rounded-pill small">แบบฟอร์มขอใช้รถ</span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-file-pen text-purple me-2"></i>ยื่นคำขออนุญาตใช้รถยนต์ราชการ
            </h3>
            <p class="text-muted small mb-0">
                เลือกรถวิทยาลัย ระบุวันเวลา วัตถุประสงค์ สถานที่ปลายทาง และรายการสัมภาระเครื่องดนตรี
            </p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fa-solid fa-table-list me-1"></i> หน้ารวมคำขอ
            </a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
            <div class="d-flex align-items-start gap-2">
                <i class="fa-solid fa-triangle-exclamation fs-5 mt-1"></i>
                <div>
                    <strong>ไม่สามารถส่งคำขอได้:</strong><br>
                    <?= $error ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" id="bookingForm" class="row g-4">

        <!-- Left Column: Form Details -->
        <div class="col-lg-8">

            <!-- 1. เลือกยานพาหนะ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                    <span class="badge bg-purple text-white rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">1</span>
                    เลือกรถวิทยาลัยที่ต้องการใช้งาน
                </h5>

                <div class="row g-3">
                    <?php foreach ($vehicles as $v): 
                        $is_cargo = ($v['vehicle_type'] === 'cargo_instruments');
                        $checked = ($preselect_vehicle == $v['vehicle_id']) ? 'checked' : '';
                        $border_color = $is_cargo ? 'border-danger' : 'border-purple';
                        $icon = $is_cargo ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple';
                    ?>
                        <div class="col-md-6">
                            <label class="d-block h-100 p-3 rounded-4 border position-relative cursor-pointer vehicle-select-card" style="transition: all 0.2s; cursor: pointer;">
                                <input type="radio" name="vehicle_id" value="<?= $v['vehicle_id'] ?>" class="form-check-input position-absolute top-0 end-0 m-3" <?= $checked ?> onchange="onVehicleChange(<?= $v['vehicle_id'] ?>, '<?= $v['vehicle_type'] ?>')">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fa-solid <?= $icon ?> fs-4"></i>
                                    <span class="badge <?= $is_cargo ? 'bg-danger text-white' : 'bg-purple text-white' ?> rounded-pill extra-small">
                                        <?= $is_cargo ? 'คันที่ 2: บรรทุกเครื่องดนตรี' : 'คันที่ 1: รับส่งบุคคล' ?>
                                    </span>
                                </div>
                                <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($v['model']) ?></h6>
                                <div class="text-muted extra-small mb-2">
                                    <strong>ทะเบียน:</strong> <span class="badge bg-light text-dark border"><?= htmlspecialchars($v['plate_number']) ?></span>
                                    • ปี <?= $v['year_made'] ?>
                                </div>
                                <p class="extra-small text-muted mb-0">
                                    <?= htmlspecialchars($v['notes'] ?? '') ?>
                                </p>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. ข้อมูลการเดินทาง & วัตถุประสงค์ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                    <span class="badge bg-purple text-white rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">2</span>
                    ข้อมูลการเดินทางและวัตถุประสงค์
                </h5>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">วัตถุประสงค์ / โครงการ / ภารกิจราชการ <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control rounded-3" placeholder="เช่น นำวงดุริยางค์ซิมโฟนีไปจัดแสดงดนตรี ณ หอประชุมกองทัพเรือ กรุงเทพฯ" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">วันและเวลาเริ่มออกเดินทาง <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="start_datetime" id="start_datetime" class="form-control rounded-3" required value="<?= htmlspecialchars($_POST['start_datetime'] ?? date('Y-m-d\TH:00', strtotime('+1 day 08:00'))) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">วันและเวลาเดินทางกลับถึง <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="end_datetime" id="end_datetime" class="form-control rounded-3" required value="<?= htmlspecialchars($_POST['end_datetime'] ?? date('Y-m-d\TH:00', strtotime('+1 day 17:00'))) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">สถานที่ปลายทาง <span class="text-danger">*</span></label>
                    <input type="text" name="destination" class="form-control rounded-3" placeholder="เช่น หอประชุมใหญ่ ศูนย์วัฒนธรรมแห่งประเทศไทย กรุงเทพมหานคร" required value="<?= htmlspecialchars($_POST['destination'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-dark">เส้นทางการเดินทางและจุดแวะพัก</label>
                    <textarea name="route_detail" rows="2" class="form-control rounded-3" placeholder="ระบุเส้นทาง เช่น มหาสารคาม - ขอนแก่น - นครราชสีมา - กรุงเทพฯ"><?= htmlspecialchars($_POST['route_detail'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- 3. ผู้โดยสาร และ รายการเครื่องดนตรี / สัมภาระ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                    <span class="badge bg-purple text-white rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">3</span>
                    ผู้โดยสารและรายการสัมภาระ/เครื่องดนตรี
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-dark">จำนวนผู้โดยสาร (คน) <span class="text-danger">*</span></label>
                        <input type="number" name="passenger_count" class="form-control rounded-3" min="1" max="15" value="<?= htmlspecialchars($_POST['passenger_count'] ?? '1') ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold small text-dark">รายชื่อผู้ร่วมเดินทาง</label>
                        <input type="text" name="passenger_names" class="form-control rounded-3" placeholder="เช่น ผศ.ดร.ยสพรรณ, อาจารย์วรากร และนิสิต 6 คน" value="<?= htmlspecialchars($_POST['passenger_names'] ?? '') ?>">
                    </div>
                </div>

                <!-- กล่องรายการเครื่องดนตรี (เน้นเป็นพิเศษเมื่อเลือกคันที่ 2) -->
                <div class="p-3 rounded-4 bg-warning-subtle border border-warning-subtle mb-3" id="instrumentBox">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="form-label fw-bold text-dark mb-0">
                            <i class="fa-solid fa-guitar text-danger me-1"></i> รายการเครื่องดนตรีและอุปกรณ์ที่บรรทุก
                        </label>
                        <span class="badge bg-warning text-dark extra-small">จำเป็นสำหรับรถบรรทุกเครื่องดนตรี</span>
                    </div>
                    <textarea name="instruments_detail" id="instruments_detail" rows="3" class="form-control bg-white rounded-3" placeholder="ระบุรายการเครื่องดนตรี เช่น กลองทิมปานี 4 ใบ, ดับเบิลเบส 2 ตัว, เชลโล 4 ตัว, ตู้แอมป์เบส, ชุดเครื่องเคาะจังหวะ"><?= htmlspecialchars($_POST['instruments_detail'] ?? '') ?></textarea>
                    
                    <!-- Quick Presets -->
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <span class="extra-small text-muted me-1">ตัวเลือกด่วน:</span>
                        <button type="button" class="btn btn-xs btn-outline-dark rounded-pill py-0 px-2 extra-small" onclick="addInstrumentPreset('กลองทิมปานี 4 ใบ')">+ กลองทิมปานี</button>
                        <button type="button" class="btn btn-xs btn-outline-dark rounded-pill py-0 px-2 extra-small" onclick="addInstrumentPreset('ดับเบิลเบส 2 ตัว พร้อมกล่องแข็ง')">+ ดับเบิลเบส</button>
                        <button type="button" class="btn btn-xs btn-outline-dark rounded-pill py-0 px-2 extra-small" onclick="addInstrumentPreset('ชุดเครื่องสาย (ไวโอลิน, วิโอลา, เชลโล รวม 12 ชิ้น)')">+ ชุดเครื่องสาย</button>
                        <button type="button" class="btn btn-xs btn-outline-dark rounded-pill py-0 px-2 extra-small" onclick="addInstrumentPreset('เครื่องดนตรีไทย (ระนาด, ฆ้องวง, กลองทัด)')">+ เครื่องดนตรีไทย</button>
                        <button type="button" class="btn btn-xs btn-outline-dark rounded-pill py-0 px-2 extra-small" onclick="addInstrumentPreset('ตู้แอมป์กีตาร์/เบส และระบบลำโพงมอนิเตอร์')">+ ตู้แอมป์ & PA</button>
                    </div>
                </div>
            </div>

            <!-- 4. พนักงานขับรถ -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                    <span class="badge bg-purple text-white rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">4</span>
                    การกำหนดพนักงานขับรถ
                </h5>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark">ประเภทการขับขี่</label>
                        <select name="driver_type" id="driver_type" class="form-select rounded-3" onchange="onDriverTypeChange()">
                            <option value="driver_assigned">ให้พนักงานขับรถประจำวิทยาลัยขับให้</option>
                            <option value="self_drive">ผู้ขอขับเอง (มีใบขับขี่ถูกต้อง)</option>
                            <option value="other">อื่นๆ (ระบุพนักงานขับรถภายนอก/ชั่วคราว)</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="driverSelectContainer">
                        <label class="form-label fw-bold small text-dark">เลือกพนักงานขับรถ</label>
                        <select name="driver_id" id="driver_id" class="form-select rounded-3">
                            <option value="">-- เลือกพนักงานขับรถประจำ --</option>
                            <?php foreach ($drivers as $dr): ?>
                                <option value="<?= $dr['user_id'] ?>">
                                    <?= htmlspecialchars($dr['prefix_name'] . $dr['first_name'] . ' ' . $dr['last_name']) ?>
                                    <?= !empty($dr['phone']) ? '(' . htmlspecialchars($dr['phone']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12" id="driverCustomContainer" style="display: none;">
                        <label class="form-label fw-bold small text-dark">ชื่อ-นามสกุล และเบอร์โทรพนักงานขับรถ</label>
                        <input type="text" name="driver_name" id="driver_name" class="form-control rounded-3" placeholder="ระบุชื่อพนักงานขับรถ และเบอร์ติดต่อ">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-bold small text-dark">หมายเหตุหรือข้อกำหนดเพิ่มเติม</label>
                    <textarea name="notes" rows="2" class="form-control rounded-3" placeholder="เช่น ต้องการให้ออกเดินทางตั้งแต่เช้ามืด, ต้องแวะรับเครื่องดนตรีเพิ่มที่อาคารแสดง"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="d-flex justify-content-end gap-3 mb-4">
                <a href="index.php" class="btn btn-light rounded-pill px-4">ยกเลิก</a>
                <button type="submit" class="btn btn-purple fw-bold rounded-pill px-5 shadow-sm">
                    <i class="fa-solid fa-paper-plane me-1"></i> ส่งคำขออนุมัติใช้รถ
                </button>
            </div>

        </div>

        <!-- Right Column: Requester Info & Workflow Guide -->
        <div class="col-lg-4">

            <!-- Requester Card -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-id-card text-purple me-2"></i>ข้อมูลผู้ยื่นคำขอ</h6>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-circle bg-purple-subtle text-purple d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-user-tie fa-lg"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars(($current_user['prefix_name'] ?? '') . $current_user['first_name'] . ' ' . $current_user['last_name']) ?></div>
                        <div class="extra-small text-muted"><?= htmlspecialchars($current_user['position'] ?? 'อาจารย์ / บุคลากร') ?></div>
                    </div>
                </div>
                <div class="extra-small text-muted border-top pt-2">
                    <div><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($current_user['email'] ?? '-') ?></div>
                    <div><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($current_user['phone'] ?? '-') ?></div>
                </div>
            </div>

            <!-- Approval Workflow Timeline Preview -->
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4 mb-4">
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-timeline text-purple me-2"></i>ลำดับการพิจารณาอนุมัติ (Workflow)</h6>
                <div class="position-relative ps-4" style="border-left: 2px dashed #9333ea; margin-left: 10px;">
                    <!-- Step 1 -->
                    <div class="mb-4 position-relative">
                        <div class="position-absolute rounded-circle bg-purple text-white d-flex align-items-center justify-content-center" style="width: 22px; height: 22px; left: -26px; top: 0; font-size: 0.7rem;">1</div>
                        <div class="fw-bold small text-dark">ผู้ขอใช้รถส่งคำขอ</div>
                        <div class="extra-small text-muted">ยื่นแบบฟอร์มขอใช้รถเข้าสู่ระบบ</div>
                    </div>
                    <!-- Step 2 -->
                    <div class="mb-4 position-relative">
                        <div class="position-absolute rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 22px; height: 22px; left: -26px; top: 0; font-size: 0.7rem;">2</div>
                        <div class="fw-bold small text-dark">หัวหน้าสำนักงานเลขานุการ</div>
                        <div class="extra-small text-muted">นางแจ่มจันทร์ หลูปรีชาเศรษฐ ตรวจสอบความพร้อมและพิจารณาเห็นชอบ</div>
                    </div>
                    <!-- Step 3 -->
                    <div class="position-relative">
                        <div class="position-absolute rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 22px; height: 22px; left: -26px; top: 0; font-size: 0.7rem;">3</div>
                        <div class="fw-bold small text-dark">รองคณบดีฝ่ายบริหาร</div>
                        <div class="extra-small text-muted">ดร.วัชระ หอมหวล พิจารณาอนุมัติการใช้รถยนต์ราชการสมบูรณ์</div>
                    </div>
                </div>
            </div>

            <!-- Quick Guidelines -->
            <div class="card border-0 shadow-xs rounded-4 bg-purple-subtle p-4 border border-purple-subtle">
                <h6 class="fw-bold text-purple mb-2"><i class="fa-solid fa-circle-info me-2"></i>ข้อปฏิบัติการใช้รถ</h6>
                <ul class="extra-small text-dark ps-3 mb-0" style="line-height: 1.8;">
                    <li>ควรยื่นคำขอใช้รถล่วงหน้าอย่างน้อย 3 วันทำการ</li>
                    <li>เมื่อเดินทางกลับแล้ว ขอความร่วมมือผู้ใช้รถ/พนักงานขับรถบันทึก <strong>เลขไมล์และค่าใช้จ่าย</strong> ในเมนู <em>"บันทึกทริป & อัตราสิ้นเปลือง"</em></li>
                    <li>กรุณาตรวจเช็คสภาพรถทั้งก่อนออกเดินทางและหลังเสร็จสิ้นภารกิจ</li>
                </ul>
            </div>

        </div>

    </form>

</main>

<script>
function onVehicleChange(vehId, vehType) {
    const instBox = document.getElementById('instrumentBox');
    if (vehType === 'cargo_instruments' || vehId == 2) {
        instBox.classList.remove('bg-light');
        instBox.classList.add('bg-warning-subtle');
        document.getElementById('instruments_detail').focus();
    }
}

function addInstrumentPreset(text) {
    const textarea = document.getElementById('instruments_detail');
    if (textarea.value.trim() === '') {
        textarea.value = text;
    } else {
        textarea.value += ', ' + text;
    }
}

function onDriverTypeChange() {
    const dtype = document.getElementById('driver_type').value;
    const selectBox = document.getElementById('driverSelectContainer');
    const customBox = document.getElementById('driverCustomContainer');

    if (dtype === 'driver_assigned') {
        selectBox.style.display = 'block';
        customBox.style.display = 'none';
    } else if (dtype === 'self_drive') {
        selectBox.style.display = 'none';
        customBox.style.display = 'none';
    } else {
        selectBox.style.display = 'none';
        customBox.style.display = 'block';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
