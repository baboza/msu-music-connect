<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์การเข้าถึง
$practice_admin_roles = ['admin', 'หัวหน้าสำนักงาน', 'งานอาคารสถานที่', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
if (!hasRole($practice_admin_roles)) {
    header("Location: ../../index.php");
    exit();
}

$manage_room_roles = ['admin', 'งานอาคารสถานที่'];
$can_manage = hasRole($manage_room_roles);

$page_title = "จัดการและสถิติห้องซ้อมดนตรี";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ===================================================
// Pagination สำหรับรายชื่อห้องซ้อม (10 ต่อหน้า)
// ===================================================
$rooms_per_page = 10;
$rooms_page = isset($_GET['rooms_page']) ? max(1, (int)$_GET['rooms_page']) : 1;
$rooms_offset = ($rooms_page - 1) * $rooms_per_page;

// ===================================================
// Filter/Search สำหรับรายการจอง
// ===================================================
$bookings_per_page = 15;
$bookings_page = isset($_GET['bookings_page']) ? max(1, (int)$_GET['bookings_page']) : 1;
$bookings_offset = ($bookings_page - 1) * $bookings_per_page;

$filter_search   = isset($_GET['search'])        ? trim($_GET['search'])        : '';
$filter_room     = isset($_GET['filter_room'])   ? trim($_GET['filter_room'])   : '';
$filter_status   = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_date_from= isset($_GET['date_from'])     ? trim($_GET['date_from'])     : '';
$filter_date_to  = isset($_GET['date_to'])       ? trim($_GET['date_to'])       : '';

// ดึงข้อมูลสถิติ
$current_month = date('Y-m');
$today = date('Y-m-d');

// อัปเดตรายการที่ approved แต่ไม่มา Check-in ภายใน 15 นาทีหลังจากเวลาเริ่ม ให้เป็น 'no_show'
$pdo->exec("
    UPDATE practice_bookings 
    SET status = 'no_show', 
        cancel_reason = 'ระบบยกเลิกอัตโนมัติเนื่องจากไม่ Check-in ตามเวลา (เกิน 15 นาที)' 
    WHERE status = 'approved' 
    AND CONCAT(booking_date, ' ', start_time) <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");

// อัปเดตสถานะ 'active' (ที่ Check-in แล้ว) เป็น 'completed' อัตโนมัติหากเลยเวลาไปแล้ว
$pdo->exec("
    UPDATE practice_bookings 
    SET status = 'completed' 
    WHERE status = 'active' 
    AND CONCAT(booking_date, ' ', end_time) < NOW()
");

// 1. จำนวนการจองทั้งหมดในเดือนนี้
$stmt_bookings = $pdo->prepare("SELECT COUNT(*) FROM practice_bookings WHERE DATE_FORMAT(booking_date, '%Y-%m') = ?");
$stmt_bookings->execute([$current_month]);
$total_bookings_month = $stmt_bookings->fetchColumn() ?: 0;

// 2. จำนวนการจองวันนี้
$stmt_today = $pdo->prepare("SELECT COUNT(*) FROM practice_bookings WHERE booking_date = ?");
$stmt_today->execute([$today]);
$total_bookings_today = $stmt_today->fetchColumn() ?: 0;

// 3. ห้องที่ฮิตที่สุดในเดือนนี้
$stmt_top_room = $pdo->prepare("
    SELECT room_id, COUNT(*) as count 
    FROM practice_bookings 
    WHERE DATE_FORMAT(booking_date, '%Y-%m') = ? AND status != 'cancelled'
    GROUP BY room_id 
    ORDER BY count DESC 
    LIMIT 3
");
$stmt_top_room->execute([$current_month]);
$top_rooms = $stmt_top_room->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายชื่อห้องทั้งหมดสำหรับ Dropdown
$all_rooms_list = $pdo->query("SELECT room_id, room_type FROM practice_rooms ORDER BY room_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// สถิติสำหรับกราฟ (Charts)
// ==========================================

// 1. Top 10 Users
$stmt_top_users = $pdo->query("
    SELECT s.first_name, s.last_name, COUNT(pb.booking_id) as booking_count 
    FROM practice_bookings pb
    JOIN students s ON pb.student_id = s.student_id
    WHERE pb.status != 'cancelled'
    GROUP BY pb.student_id
    ORDER BY booking_count DESC
    LIMIT 10
");
$chart_top_users = $stmt_top_users->fetchAll(PDO::FETCH_ASSOC);

// 2. Top 10 Rooms
$stmt_top_rooms_all = $pdo->query("
    SELECT room_id, COUNT(*) as booking_count 
    FROM practice_bookings 
    WHERE status != 'cancelled'
    GROUP BY room_id
    ORDER BY booking_count DESC
    LIMIT 10
");
$chart_top_rooms = $stmt_top_rooms_all->fetchAll(PDO::FETCH_ASSOC);

// 3. 6-Month Trend
$stmt_trend = $pdo->query("
    SELECT DATE_FORMAT(booking_date, '%Y-%m') as month_year, COUNT(*) as booking_count
    FROM practice_bookings
    WHERE status != 'cancelled' 
    AND booking_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
    GROUP BY month_year
    ORDER BY month_year ASC
");
$chart_trend = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format month (e.g. 2026-07 -> ก.ค. 26)
$thai_months_abbr = [
    '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.', '05' => 'พ.ค.', '06' => 'มิ.ย.',
    '07' => 'ก.ค.', '08' => 'ส.ค.', '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
];
foreach ($chart_trend as &$t) {
    $parts = explode('-', $t['month_year']);
    if (count($parts) == 2) {
        $year_th = (intval($parts[0]) + 543) % 100;
        $m_th = $thai_months_abbr[$parts[1]] ?? $parts[1];
        $t['label'] = $m_th . ' ' . $year_th;
    }
}
unset($t);

// 4. ดึงรายชื่อห้องซ้อม (Paginated)
$stmt_rooms_count = $pdo->query("SELECT COUNT(*) FROM practice_rooms");
$total_rooms = $stmt_rooms_count->fetchColumn();
$total_rooms_pages = ceil($total_rooms / $rooms_per_page);

$stmt_rooms = $pdo->prepare("SELECT * FROM practice_rooms ORDER BY room_id ASC LIMIT :limit OFFSET :offset");
$stmt_rooms->bindValue(':limit', $rooms_per_page, PDO::PARAM_INT);
$stmt_rooms->bindValue(':offset', $rooms_offset, PDO::PARAM_INT);
$stmt_rooms->execute();
$rooms = $stmt_rooms->fetchAll(PDO::FETCH_ASSOC);

// 5. Build WHERE clause สำหรับรายการจอง
$bk_conditions = [];
$bk_params = [];

if ($filter_search !== '') {
    $bk_conditions[] = "(pb.student_id LIKE :search1 OR s.first_name LIKE :search2 OR s.last_name LIKE :search3 OR pb.booking_code LIKE :search4)";
    $val = '%' . $filter_search . '%';
    $bk_params[':search1'] = $val;
    $bk_params[':search2'] = $val;
    $bk_params[':search3'] = $val;
    $bk_params[':search4'] = $val;
}
if ($filter_room !== '') {
    $bk_conditions[] = "pb.room_id = :room";
    $bk_params[':room'] = $filter_room;
}
if ($filter_status !== '') {
    $bk_conditions[] = "pb.status = :status";
    $bk_params[':status'] = $filter_status;
}
if ($filter_date_from !== '') {
    $bk_conditions[] = "pb.booking_date >= :date_from";
    $bk_params[':date_from'] = $filter_date_from;
}
if ($filter_date_to !== '') {
    $bk_conditions[] = "pb.booking_date <= :date_to";
    $bk_params[':date_to'] = $filter_date_to;
}

$bk_where = count($bk_conditions) > 0 ? 'WHERE ' . implode(' AND ', $bk_conditions) : '';

$bk_join = "FROM practice_bookings pb LEFT JOIN students s ON pb.student_id = s.student_id LEFT JOIN practice_rooms pr ON pb.room_id = pr.room_id";

// นับยอดรวมตาม filter
$stmt_bk_count = $pdo->prepare("SELECT COUNT(*) $bk_join $bk_where");
$stmt_bk_count->execute($bk_params);
$total_bookings = $stmt_bk_count->fetchColumn();
$total_bookings_pages = max(1, ceil($total_bookings / $bookings_per_page));
if ($bookings_page > $total_bookings_pages) { $bookings_page = 1; $bookings_offset = 0; }

$bk_params_paged = array_merge($bk_params, [':limit' => $bookings_per_page, ':offset' => $bookings_offset]);
$stmt_bookings_list = $pdo->prepare("
    SELECT 
        pb.*,
        s.prefix_name, s.first_name, s.last_name, s.major, s.instrument, s.year_level, s.phone_number, s.profile_picture,
        pr.room_type
    $bk_join $bk_where
    ORDER BY pb.booking_date DESC, pb.start_time DESC
    LIMIT :limit OFFSET :offset
");
foreach ($bk_params as $k => $v) { $stmt_bookings_list->bindValue($k, $v); }
$stmt_bookings_list->bindValue(':limit', $bookings_per_page, PDO::PARAM_INT);
$stmt_bookings_list->bindValue(':offset', $bookings_offset, PDO::PARAM_INT);
$stmt_bookings_list->execute();
$bookings_list = $stmt_bookings_list->fetchAll(PDO::FETCH_ASSOC);

?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-dark fw-bold"><i class="fa-solid fa-music me-2" style="color: #ec4899;"></i>จัดการและสถิติห้องซ้อมดนตรี</h1>
            <p class="text-muted">ระบบจัดการข้อมูลห้องซ้อมและดูสถิติภาพรวมการจอง</p>
        </div>
    </div>

    <!-- Dashboard Statistics -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="background: linear-gradient(135deg, #ec4899, #8b5cf6); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="fw-bold mb-1 opacity-75">การจองเดือนนี้</h6>
                            <h2 class="fw-bold mb-0"><?php echo number_format($total_bookings_month); ?> <span class="fs-6 fw-normal">ครั้ง</span></h2>
                        </div>
                        <div class="bg-white rounded-circle d-flex justify-content-center align-items-center shadow-sm" style="width: 50px; height: 50px; color: #ec4899; flex-shrink:0;">
                            <i class="fa-solid fa-calendar-days fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="fw-bold text-muted mb-1">การจองวันนี้</h6>
                            <h2 class="fw-bold text-dark mb-0"><?php echo number_format($total_bookings_today); ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></h2>
                        </div>
                        <div class="bg-primary-subtle text-primary rounded-circle d-flex justify-content-center align-items-center shadow-sm" style="width: 50px; height: 50px; flex-shrink:0;">
                            <i class="fa-solid fa-clock fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body">
                    <h6 class="fw-bold text-muted mb-2"><i class="fa-solid fa-fire text-danger me-1"></i> ห้องฮิตเดือนนี้</h6>
                    <?php if (empty($top_rooms)): ?>
                        <p class="text-muted small">ยังไม่มีข้อมูลการจองในเดือนนี้</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach($top_rooms as $idx => $tr): ?>
                                <li class="mb-1 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-dark"><?php echo ($idx+1).". ".$tr['room_id']; ?></span>
                                    <span class="badge bg-light text-dark border"><?php echo $tr['count']; ?> ครั้ง</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Charts -->
    <div class="row g-4 mb-4">
        <!-- Chart 1: Top 10 Users -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users text-primary me-2"></i>10 อันดับบุคคลจองเยอะสุด (All-time)</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <canvas id="topUsersChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <!-- Chart 2: Top 10 Rooms -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-door-open text-info me-2"></i>10 อันดับห้องจองเยอะสุด (All-time)</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <canvas id="topRoomsChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <!-- Chart 3: 6-Month Trend -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-bar text-success me-2"></i>สถิติการจองย้อนหลัง 6 เดือน</h6>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <canvas id="trendChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>


    <!-- ===== รายการจองของนิสิต ===== -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-calendar-check me-2" style="color:#ec4899;"></i>รายการจองของนิสิต
                <span class="badge rounded-pill ms-2 fs-6" style="background: linear-gradient(135deg,#ec4899,#8b5cf6); color:white;"><?php echo $total_bookings; ?></span>
            </h5>
            <?php if($filter_search || $filter_room || $filter_status || $filter_date_from || $filter_date_to): ?>
            <a href="?rooms_page=<?php echo $rooms_page; ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                <i class="fa-solid fa-xmark me-1"></i>ล้างตัวกรอง
            </a>
            <?php endif; ?>
        </div>

        <!-- Filter Bar -->
        <div class="px-4 pt-3 pb-0">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="rooms_page" value="<?php echo $rooms_page; ?>">
                <input type="hidden" name="bookings_page" value="1">

                <div class="col-md-3">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา
                    </label>
                    <input type="text" name="search" class="form-control border-0 bg-light rounded-pill"
                           placeholder="ชื่อ, รหัสนิสิต, เลขการจอง..."
                           value="<?php echo htmlspecialchars($filter_search); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1">
                        <i class="fa-solid fa-door-open me-1"></i>ห้องซ้อม
                    </label>
                    <select name="filter_room" class="form-select border-0 bg-light rounded-pill">
                        <option value="">ทุกห้อง</option>
                        <?php foreach ($all_rooms_list as $r): ?>
                        <option value="<?php echo htmlspecialchars($r['room_id']); ?>"
                            <?php echo $filter_room === $r['room_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['room_id']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1">
                        <i class="fa-solid fa-circle-check me-1"></i>สถานะ
                    </label>
                    <select name="filter_status" class="form-select border-0 bg-light rounded-pill">
                        <option value="">ทุกสถานะ</option>
                        <option value="approved"  <?php echo $filter_status === 'approved'  ? 'selected' : ''; ?>>อนุมัติ</option>
                        <option value="active"    <?php echo $filter_status === 'active'    ? 'selected' : ''; ?>>กำลังใช้งาน</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                        <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>ยกเลิก</option>
                        <option value="no_show"   <?php echo $filter_status === 'no_show'   ? 'selected' : ''; ?>>ไม่มา Check-in ตามเวลา</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1">
                        <i class="fa-solid fa-calendar-day me-1"></i>ตั้งแต่วันที่
                    </label>
                    <input type="date" name="date_from" class="form-control border-0 bg-light rounded-pill"
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1">
                        <i class="fa-solid fa-calendar-day me-1"></i>ถึงวันที่
                    </label>
                    <input type="date" name="date_to" class="form-control border-0 bg-light rounded-pill"
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>

                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100 fw-bold text-white">
                        <i class="fa-solid fa-search"></i>
                    </button>
                </div>
            </form>

            <?php if($filter_search || $filter_room || $filter_status || $filter_date_from || $filter_date_to): ?>
            <div class="d-flex flex-wrap gap-2 mt-2 mb-2">
                <?php if($filter_search): ?>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1"><i class="fa-solid fa-magnifying-glass me-1 text-primary"></i>ค้นหา: <?php echo htmlspecialchars($filter_search); ?></span>
                <?php endif; ?>
                <?php if($filter_room): ?>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1"><i class="fa-solid fa-door-open me-1 text-primary"></i>ห้อง: <?php echo htmlspecialchars($filter_room); ?></span>
                <?php endif; ?>
                <?php if($filter_status): ?>
                <?php $sl = ['approved'=>'อนุมัติ','active'=>'กำลังใช้งาน','completed'=>'เสร็จสิ้น','cancelled'=>'ยกเลิก']; ?>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1"><i class="fa-solid fa-circle-check me-1 text-primary"></i>สถานะ: <?php echo $sl[$filter_status] ?? $filter_status; ?></span>
                <?php endif; ?>
                <?php if($filter_date_from): ?>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1"><i class="fa-solid fa-calendar me-1 text-primary"></i>ตั้งแต่: <?php echo date('d/m/Y', strtotime($filter_date_from)); ?></span>
                <?php endif; ?>
                <?php if($filter_date_to): ?>
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1"><i class="fa-solid fa-calendar me-1 text-primary"></i>ถึง: <?php echo date('d/m/Y', strtotime($filter_date_to)); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="8%">#</th>
                            <th width="22%">นิสิต</th>
                            <th width="12%">ห้องซ้อม</th>
                            <th width="13%">วันที่จอง</th>
                            <th width="13%">เวลา</th>
                            <th width="10%" class="text-center">สถานะ</th>
                            <th width="10%" class="text-center">รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings_list)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีรายการจอง</td></tr>
                        <?php else: ?>
                            <?php foreach ($bookings_list as $bk): ?>
                            <tr>
                                <td class="text-muted small"><?php echo htmlspecialchars($bk['booking_code']); ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($bk['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($bk['profile_picture']); ?>" class="rounded-circle shadow-sm" style="width:34px;height:34px;object-fit:cover;flex-shrink:0;" alt="Profile">
                                        <?php else: ?>
                                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:34px;height:34px;font-size:0.8rem;flex-shrink:0;background:linear-gradient(135deg,#ec4899,#8b5cf6);">
                                                <?php echo mb_substr($bk['first_name'] ?? 'N', 0, 1, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size:0.9rem;">
                                                <?php echo htmlspecialchars(($bk['prefix_name'] ?? '').' '.($bk['first_name'] ?? '').' '.($bk['last_name'] ?? '')); ?>
                                            </div>
                                            <div class="text-muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($bk['student_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($bk['room_id']); ?></span>
                                    <?php if (!empty($bk['room_type'])): ?>
                                    <div class="text-muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($bk['room_type']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($bk['booking_date'])); ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border" style="font-size:0.82rem;">
                                        <?php echo date('H:i', strtotime($bk['start_time'])); ?> – <?php echo date('H:i', strtotime($bk['end_time'])); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $status_map = [
                                        'approved'  => ['label' => 'อนุมัติ',    'class' => 'bg-success-subtle text-success border-success'],
                                        'active'    => ['label' => 'กำลังใช้งาน','class' => 'bg-primary-subtle text-primary border-primary'],
                                        'completed' => ['label' => 'เสร็จสิ้น',  'class' => 'bg-secondary-subtle text-secondary border-secondary'],
                                        'cancelled' => ['label' => 'ยกเลิก',                   'class' => 'bg-danger-subtle text-danger border-danger'],
                                        'no_show'   => ['label' => 'ไม่มา Check-in ตามเวลา', 'class' => 'bg-danger-subtle text-danger border-danger'],
                                    ];
                                    $st = $bk['status'] ?? 'approved';
                                    $s = $status_map[$st] ?? ['label' => $st, 'class' => 'bg-light text-dark border'];
                                    ?>
                                    <span class="badge border border-opacity-25 px-2 py-1 <?php echo $s['class']; ?>"><?php echo $s['label']; ?></span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-light border rounded-pill shadow-sm px-3"
                                        onclick="showBookingDetail(<?php echo htmlspecialchars(json_encode($bk)); ?>)"
                                        title="ดูรายละเอียด">
                                        <i class="fa-solid fa-eye me-1 text-primary"></i>ดู
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination รายการจอง -->
            <?php if ($total_bookings_pages > 1): ?>
            <nav class="mt-3 d-flex justify-content-between align-items-center">
                <small class="text-muted">แสดง <?php echo $bookings_offset + 1; ?>–<?php echo min($bookings_offset + $bookings_per_page, $total_bookings); ?> จาก <?php echo $total_bookings; ?> รายการ</small>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $bookings_page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link rounded-pill me-1" href="?rooms_page=<?php echo $rooms_page; ?>&bookings_page=<?php echo $bookings_page - 1; ?>&search=<?php echo urlencode($filter_search); ?>&filter_room=<?php echo urlencode($filter_room); ?>&filter_status=<?php echo urlencode($filter_status); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>">‹</a>
                    </li>
                    <?php
                    $start_page = max(1, $bookings_page - 2);
                    $end_page = min($total_bookings_pages, $bookings_page + 2);
                    $filter_qs = '&search='.urlencode($filter_search).'&filter_room='.urlencode($filter_room).'&filter_status='.urlencode($filter_status).'&date_from='.urlencode($filter_date_from).'&date_to='.urlencode($filter_date_to);
                    if ($start_page > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $bookings_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?rooms_page=<?php echo $rooms_page; ?>&bookings_page=<?php echo $i; ?><?php echo $filter_qs; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor;
                    if ($end_page < $total_bookings_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    ?>
                    <li class="page-item <?php echo $bookings_page >= $total_bookings_pages ? 'disabled' : ''; ?>">
                        <a class="page-link rounded-pill ms-1" href="?rooms_page=<?php echo $rooms_page; ?>&bookings_page=<?php echo $bookings_page + 1; ?><?php echo $filter_qs; ?>">›</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== รายชื่อห้องซ้อมดนตรี ===== -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-door-open me-2" style="color:#8b5cf6;"></i>รายชื่อห้องซ้อมดนตรี
                <span class="badge bg-secondary rounded-pill ms-2 fs-6"><?php echo $total_rooms; ?></span>
            </h5>
            <?php if ($can_manage): ?>
            <a href="create.php" class="btn btn-primary rounded-pill shadow-sm fw-bold px-3"><i class="fa-solid fa-plus me-1"></i> เพิ่มห้องซ้อม</a>
            <?php endif; ?>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="15%">รหัสห้องซ้อม</th>
                            <th width="35%">ประเภทห้อง</th>
                            <th width="15%" class="text-center">ความจุ (คน)</th>
                            <th width="15%" class="text-center">สถานะ</th>
                            <?php if ($can_manage): ?>
                            <th width="20%" class="text-center">จัดการ</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rooms)): ?>
                            <tr><td colspan="<?php echo $can_manage ? '5' : '4'; ?>" class="text-center py-4 text-muted">ไม่พบข้อมูลห้องซ้อมดนตรี</td></tr>
                        <?php else: ?>
                            <?php foreach ($rooms as $room): ?>
                            <tr>
                                <td class="fw-bold text-dark"><?php echo htmlspecialchars($room['room_id']); ?></td>
                                <td><?php echo htmlspecialchars($room['room_type']); ?></td>
                                <td class="text-center"><?php echo $room['capacity']; ?></td>
                                <td class="text-center">
                                    <?php if (isset($room['is_active']) && $room['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">เปิดใช้งาน</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1">ปิดใช้งาน</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($can_manage): ?>
                                <td class="text-center">
                                    <a href="edit.php?id=<?php echo urlencode($room['room_id']); ?>" class="btn btn-sm btn-light border text-primary shadow-sm rounded-circle me-1" title="แก้ไข" style="width:32px; height:32px;"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-light border text-danger shadow-sm rounded-circle" title="ลบ" style="width:32px; height:32px;" onclick="deleteRoom('<?php echo htmlspecialchars($room['room_id']); ?>')"><i class="fa-solid fa-trash"></i></button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination ห้องซ้อม -->
            <?php if ($total_rooms_pages > 1): ?>
            <nav class="mt-3 d-flex justify-content-between align-items-center">
                <small class="text-muted">แสดง <?php echo $rooms_offset + 1; ?>–<?php echo min($rooms_offset + $rooms_per_page, $total_rooms); ?> จาก <?php echo $total_rooms; ?> ห้อง</small>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $rooms_page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link rounded-pill me-1" href="?rooms_page=<?php echo $rooms_page - 1; ?>&bookings_page=<?php echo $bookings_page; ?>">‹</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_rooms_pages; $i++): ?>
                    <li class="page-item <?php echo $i == $rooms_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?rooms_page=<?php echo $i; ?>&bookings_page=<?php echo $bookings_page; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $rooms_page >= $total_rooms_pages ? 'disabled' : ''; ?>">
                        <a class="page-link rounded-pill ms-1" href="?rooms_page=<?php echo $rooms_page + 1; ?>&bookings_page=<?php echo $bookings_page; ?>">›</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</main>


<!-- ===== Modal รายละเอียดการจอง ===== -->
<div class="modal fade" id="bookingDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-3 px-4" style="background: #0f172a;">
                <div class="py-1">
                    <h5 class="modal-title text-white fw-bold mb-0"><i class="fa-solid fa-calendar-check me-2"></i>รายละเอียดการจอง</h5>
                    <p class="text-white opacity-75 mb-0 small" id="modal-booking-code"></p>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- นิสิต -->
                <div class="d-flex align-items-center gap-3 mb-4 p-3 rounded-3 bg-light">
                    <div id="modal-avatar-container" style="width:50px;height:50px;flex-shrink:0;">
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-6" id="modal-student-name"></div>
                        <div class="text-muted small" id="modal-student-id"></div>
                        <div class="text-muted small" id="modal-student-info"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded-3 h-100" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-door-open me-1 text-primary"></i>ห้องซ้อม</div>
                            <div class="fw-bold text-dark" id="modal-room-id"></div>
                            <div class="text-muted small" id="modal-room-type"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 h-100" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-calendar me-1 text-success"></i>วันที่จอง</div>
                            <div class="fw-bold text-dark" id="modal-date"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 h-100" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-clock me-1 text-warning"></i>เวลา</div>
                            <div class="fw-bold text-dark" id="modal-time"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 h-100" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-circle-check me-1 text-info"></i>สถานะ</div>
                            <div id="modal-status"></div>
                        </div>
                    </div>
                    <div class="col-12" id="modal-cancel-reason-wrap" style="display:none;">
                        <div class="p-3 rounded-3" style="background:#fff1f2; border: 1px solid #fecdd3;">
                            <div class="text-danger small fw-bold mb-1"><i class="fa-solid fa-circle-exclamation me-1"></i>เหตุผลการยกเลิก</div>
                            <div class="text-danger-emphasis small" id="modal-cancel-reason" style="white-space: pre-line;"></div>
                        </div>
                    </div>
                    <div class="col-12" id="modal-checkin-wrap" style="display:none;">
                        <div class="p-3 rounded-3" style="background:#f0fdf4; border: 1px solid #bbf7d0;">
                            <div class="text-success small fw-bold mb-1"><i class="fa-solid fa-clipboard-check me-1"></i>ประวัติการ Check-in / Check-out</div>
                            <div class="text-success-emphasis small" id="modal-checkin-info"></div>
                        </div>
                    </div>
                    <div class="col-12" id="modal-purpose-wrap" style="display:none;">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-align-left me-1 text-secondary"></i>วัตถุประสงค์</div>
                            <div class="text-dark" id="modal-purpose"></div>
                        </div>
                    </div>
                    <div class="col-12" id="modal-phone-wrap" style="display:none;">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-phone me-1 text-success"></i>เบอร์โทรศัพท์</div>
                            <div class="fw-bold text-dark" id="modal-phone"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Script -->
<?php if ($can_manage): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function deleteRoom(roomId) {
    Swal.fire({
        title: 'ยืนยันการลบห้องซ้อม',
        text: "คุณแน่ใจหรือไม่ที่จะลบห้องซ้อม " + roomId + " ? (ไม่สามารถลบได้หากมีประวัติการจองอยู่)",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ยืนยันการลบ',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'delete.php?id=' + encodeURIComponent(roomId);
        }
    });
}
</script>
<?php endif; ?>

<script>
const statusLabels = {
    'approved':  '<span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">อนุมัติ</span>',
    'active':    '<span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25 px-2 py-1">กำลังใช้งาน</span>',
    'completed': '<span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2 py-1">เสร็จสิ้น</span>',
    'cancelled': '<span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1">ยกเลิก</span>',
    'no_show':   '<span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1"><i class="fa-solid fa-user-xmark me-1"></i>ไม่มา Check-in ตามเวลา</span>',
};

function showBookingDetail(bk) {
    // Avatar
    if (bk.profile_picture) {
        document.getElementById('modal-avatar-container').innerHTML = `<img src="${bk.profile_picture}" class="rounded-circle shadow-sm" style="width:50px;height:50px;object-fit:cover;" alt="Profile">`;
    } else {
        const firstChar = (bk.first_name || 'N').charAt(0);
        document.getElementById('modal-avatar-container').innerHTML = `<div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width:100%;height:100%;font-size:1.2rem;background:linear-gradient(135deg,#ec4899,#8b5cf6);">${firstChar}</div>`;
    }
    
    document.getElementById('modal-booking-code').textContent = 'รหัสการจอง: ' + (bk.booking_code || '-');

    // ชื่อนิสิต
    const fullName = ((bk.prefix_name || '') + ' ' + (bk.first_name || '') + ' ' + (bk.last_name || '')).trim();
    document.getElementById('modal-student-name').textContent = fullName || '-';
    document.getElementById('modal-student-id').textContent = 'รหัสนิสิต: ' + (bk.student_id || '-');

    let studentInfo = [];
    if (bk.major) studentInfo.push('สาขา: ' + bk.major);
    if (bk.instrument) studentInfo.push('เครื่องดนตรี: ' + bk.instrument);
    if (bk.year_level) studentInfo.push('ชั้นปี: ' + bk.year_level);
    document.getElementById('modal-student-info').textContent = studentInfo.join(' | ');

    // ห้อง
    document.getElementById('modal-room-id').textContent = bk.room_id || '-';
    document.getElementById('modal-room-type').textContent = bk.room_type || '';

    // วันที่และเวลา
    const dateObj = new Date(bk.booking_date);
    const dateStr = dateObj.toLocaleDateString('th-TH', {day:'2-digit', month:'2-digit', year:'numeric'});
    document.getElementById('modal-date').textContent = dateStr;

    const timeStr = (bk.start_time || '').substring(0,5) + ' – ' + (bk.end_time || '').substring(0,5) + ' น.';
    document.getElementById('modal-time').textContent = timeStr;

    // สถานะ
    document.getElementById('modal-status').innerHTML = statusLabels[bk.status] || bk.status;

    // เหตุผลการยกเลิก / ไม่มา Check-in
    const cancelReasonWrap = document.getElementById('modal-cancel-reason-wrap');
    const cancelReasonEl = document.getElementById('modal-cancel-reason');
    if (bk.cancel_reason && bk.cancel_reason.trim() !== '') {
        cancelReasonEl.textContent = bk.cancel_reason;
        cancelReasonWrap.style.display = 'block';
    } else if (bk.status === 'cancelled') {
        cancelReasonEl.textContent = 'ผู้จองยกเลิกการจอง';
        cancelReasonWrap.style.display = 'block';
    } else if (bk.status === 'no_show') {
        cancelReasonEl.textContent = 'ระบบยกเลิกอัตโนมัติเนื่องจากไม่ Check-in ตามเวลา (เกิน 15 นาที)';
        cancelReasonWrap.style.display = 'block';
    } else {
        cancelReasonWrap.style.display = 'none';
    }

    // ประวัติการ Check-in / Check-out
    const checkinWrap = document.getElementById('modal-checkin-wrap');
    const checkinEl = document.getElementById('modal-checkin-info');
    if (bk.check_in_at) {
        let ciText = 'เวลา Check-in: ' + bk.check_in_at;
        if (bk.check_out_at) {
            ciText += ' | เวลา Check-out: ' + bk.check_out_at;
        }
        checkinEl.textContent = ciText;
        checkinWrap.style.display = 'block';
    } else {
        checkinWrap.style.display = 'none';
    }

    // วัตถุประสงค์
    if (bk.purpose) {
        document.getElementById('modal-purpose').textContent = bk.purpose;
        document.getElementById('modal-purpose-wrap').style.display = 'block';
    } else {
        document.getElementById('modal-purpose-wrap').style.display = 'none';
    }

    // เบอร์โทร
    if (bk.phone_number) {
        document.getElementById('modal-phone').textContent = bk.phone_number;
        document.getElementById('modal-phone-wrap').style.display = 'block';
    } else {
        document.getElementById('modal-phone-wrap').style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('bookingDetailModal')).show();
}
</script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const topUsersData = <?php echo json_encode($chart_top_users); ?>;
    const topRoomsData = <?php echo json_encode($chart_top_rooms); ?>;
    const trendData = <?php echo json_encode($chart_trend); ?>;

    // 1. Top Users Chart
    if(document.getElementById('topUsersChart')) {
        const ctxUsers = document.getElementById('topUsersChart').getContext('2d');
        new Chart(ctxUsers, {
            type: 'bar',
            data: {
                labels: topUsersData.map(d => d.first_name + ' ' + (d.last_name ? d.last_name.substring(0, 1) + '.' : '')),
                datasets: [{
                    label: 'จำนวนครั้งที่จอง',
                    data: topUsersData.map(d => d.booking_count),
                    backgroundColor: 'rgba(142, 45, 226, 0.7)',
                    borderColor: 'rgba(142, 45, 226, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // 2. Top Rooms Chart
    if(document.getElementById('topRoomsChart')) {
        const ctxRooms = document.getElementById('topRoomsChart').getContext('2d');
        new Chart(ctxRooms, {
            type: 'bar',
            data: {
                labels: topRoomsData.map(d => d.room_id),
                datasets: [{
                    label: 'จำนวนครั้งที่จอง',
                    data: topRoomsData.map(d => d.booking_count),
                    backgroundColor: 'rgba(236, 72, 153, 0.7)',
                    borderColor: 'rgba(236, 72, 153, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // 3. 6-Month Trend Chart
    if(document.getElementById('trendChart')) {
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: trendData.map(d => d.label || d.month_year),
                datasets: [{
                    label: 'จำนวนการจอง',
                    data: trendData.map(d => d.booking_count),
                    backgroundColor: 'rgba(74, 222, 128, 0.7)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 60
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
