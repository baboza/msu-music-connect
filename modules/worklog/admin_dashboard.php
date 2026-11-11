<?php
// เปิด Error ชั่วคราว (ลบออกได้เมื่อระบบนิ่งแล้ว)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์การเข้าถึง (เฉพาะผู้บริหารและแอดมิน)
$allowed_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($allowed_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์ดูรายงานสรุปภาพรวม'); window.location.href='index.php';</script>";
    exit();
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

date_default_timezone_set('Asia/Bangkok');

// ---------------------------------------------------------
// 1. รับค่าจากตัวกรอง (Filters)
// ---------------------------------------------------------
$filter_type = $_GET['filter_type'] ?? 'day';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_year = $_GET['year'] ?? date('Y');
$filter_user = $_GET['user_id'] ?? 'all';
$filter_mood = $_GET['mood'] ?? 'all'; 
$filter_issue = $_GET['issue'] ?? 'all';

$start_date = '';
$end_date = '';
$display_period = '';

if ($filter_type == 'day') {
    $start_date = $filter_date;
    $end_date = $filter_date;
    $display_period = "ประจำวันที่ " . date('d / m / Y', strtotime($filter_date));
} elseif ($filter_type == 'week') {
    $start_date = date('Y-m-d', strtotime('monday this week', strtotime($filter_date)));
    $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($filter_date)));
    $display_period = "สัปดาห์ที่ " . date('d/m/Y', strtotime($start_date)) . " - " . date('d/m/Y', strtotime($end_date));
} elseif ($filter_type == 'month') {
    $start_date = $filter_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    $display_period = "ประจำเดือน " . date('m / Y', strtotime($start_date));
} elseif ($filter_type == 'year') {
    $start_date = $filter_year . '-01-01';
    $end_date = $filter_year . '-12-31';
    $display_period = "ประจำปี ค.ศ. " . $filter_year;
}

$where_clause = "l.log_date BETWEEN :start_date AND :end_date";
$params = [':start_date' => $start_date, ':end_date' => $end_date];

if ($filter_user !== 'all') {
    $where_clause .= " AND l.user_id = :user_id";
    $params[':user_id'] = $filter_user;
}

if ($filter_mood !== 'all') {
    $where_clause .= " AND l.harmony_score = :mood";
    $params[':mood'] = $filter_mood;
}

if ($filter_issue === '1') {
    $where_clause .= " AND l.has_issue = 1";
}

// ---------------------------------------------------------
// 2. ดึงรายชื่อบุคลากร
// ---------------------------------------------------------
$stmt_users = $pdo->query("SELECT user_id, prefix_name, first_name, last_name FROM users WHERE status = 'active' ORDER BY first_name ASC");
$users_list = $stmt_users->fetchAll();

// ---------------------------------------------------------
// 3. ดึงข้อมูลสถิติภาพรวม (Overview Stats)
// ---------------------------------------------------------
$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(log_id) as total_logs,
        COUNT(DISTINCT l.user_id) as total_users,
        SUM(hours_spent) as total_minutes, 
        SUM(CASE WHEN has_issue = 1 THEN 1 ELSE 0 END) as total_issues,
        AVG(harmony_score) as avg_harmony,
        SUM(CASE WHEN submission_status = 'ล่าช้า' THEN 1 ELSE 0 END) as total_late
    FROM work_logs l
    WHERE $where_clause
");
$stmt_stats->execute($params);
$stats = $stmt_stats->fetch();

$total_logs = $stats['total_logs'] ?: 0;
$total_users_logged = $stats['total_users'] ?: 0;
$total_work_minutes = floatval($stats['total_minutes'] ?: 0); 
$total_issues = $stats['total_issues'] ?: 0;
$avg_harmony = round($stats['avg_harmony'] ?: 0, 1);
$total_late = $stats['total_late'] ?: 0;

// ---------------------------------------------------------
// 4. ดึงข้อมูลตารางรายชื่อตามตัวกรอง
// ---------------------------------------------------------
$stmt_logs = $pdo->prepare("
    SELECT l.*, u.prefix_name, u.first_name, u.last_name, d.dept_name, t.task_name
    FROM work_logs l
    JOIN users u ON l.user_id = u.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    JOIN work_tasks t ON l.task_id = t.task_id
    WHERE $where_clause
    ORDER BY l.log_date DESC, l.created_at DESC
");
$stmt_logs->execute($params);
$all_logs = $stmt_logs->fetchAll();

// ---------------------------------------------------------
// 5. ดึงข้อมูลสำหรับกราฟความสุข (Harmony Chart)
// ---------------------------------------------------------
$chart_where = "l.log_date BETWEEN :start_date AND :end_date";
$chart_params = [':start_date' => $start_date, ':end_date' => $end_date];

if ($filter_user !== 'all') {
    $chart_where .= " AND l.user_id = :user_id";
    $chart_params[':user_id'] = $filter_user;
}

$stmt_chart = $pdo->prepare("
    SELECT harmony_score, COUNT(*) as count 
    FROM work_logs l
    WHERE $chart_where 
    GROUP BY harmony_score
");
$stmt_chart->execute($chart_params);
$harmony_data = $stmt_chart->fetchAll(PDO::FETCH_KEY_PAIR);

$chart_values = [];
for ($i = 1; $i <= 5; $i++) {
    $chart_values[$i] = $harmony_data[$i] ?? 0;
}

function getAvgHarmonyStatus($score) {
    if ($score >= 4.5) return ['label' => 'ยอดเยี่ยม', 'color' => 'text-success', 'icon' => 'fa-face-laugh-squint'];
    if ($score >= 3.5) return ['label' => 'ดีมาก', 'color' => 'text-info', 'icon' => 'fa-face-smile-beam'];
    if ($score >= 2.5) return ['label' => 'ปกติ', 'color' => 'text-primary', 'icon' => 'fa-face-smile'];
    if ($score >= 1.5) return ['label' => 'ตึงเครียด', 'color' => 'text-warning', 'icon' => 'fa-face-meh'];
    if ($score > 0) return ['label' => 'ต้องดูแล', 'color' => 'text-danger', 'icon' => 'fa-face-frown'];
    return ['label' => 'ไม่มีข้อมูล', 'color' => 'text-muted', 'icon' => 'fa-minus'];
}
$avg_mood = getAvgHarmonyStatus($avg_harmony);

function getHarmonyDisplay($score) {
    switch ($score) {
        case 1: return '<i class="fa-solid fa-face-frown text-danger fs-5" title="เหนื่อยล้า"></i>';
        case 2: return '<i class="fa-solid fa-face-meh text-warning fs-5" title="ตึงเครียด"></i>';
        case 3: return '<i class="fa-solid fa-face-smile text-primary fs-5" title="ปกติ/เรื่อยๆ"></i>';
        case 4: return '<i class="fa-solid fa-face-smile-beam text-info fs-5" title="ดีมาก"></i>';
        case 5: return '<i class="fa-solid fa-face-laugh-squint text-success fs-5" title="ยอดเยี่ยม!"></i>';
        default: return '<i class="fa-solid fa-minus text-muted"></i>';
    }
}

function getUrgencyBadge($level) {
    switch ($level) {
        case 'ด่วน': 
            return '<span class="badge bg-warning-subtle text-dark border border-warning px-2 py-1 fw-normal">ด่วน</span>';
        case 'ด่วนมาก': 
            return '<span class="badge shadow-sm px-2 py-1 fw-normal" style="background-color: #fd7e14; color: white;">ด่วนมาก</span>';
        case 'ด่วนที่สุด': 
            return '<span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1 fw-normal">ด่วนที่สุด</span>';
        default: 
            return '<span class="badge bg-light text-dark border px-2 py-1 fw-normal">ปกติ</span>';
    }
}

// ---------------------------------------------------------
// 6. ดึงข้อมูลสำหรับปฏิทิน (ดึงย้อนหลัง 1 เดือน และเดินหน้า 1 เดือน)
// ---------------------------------------------------------
$stmt_cal = $pdo->prepare("
    SELECT l.log_id, l.log_date, u.first_name, t.task_name, l.harmony_score, l.urgency_level 
    FROM work_logs l 
    JOIN users u ON l.user_id = u.user_id 
    JOIN work_tasks t ON l.task_id = t.task_id
    WHERE l.log_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    " . ($filter_user !== 'all' ? " AND l.user_id = :cal_user_id" : "") . "
");

$cal_params = [];
if ($filter_user !== 'all') {
    $cal_params[':cal_user_id'] = $filter_user;
}
$stmt_cal->execute($cal_params);

$calendar_events = [];
foreach ($stmt_cal->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $color = '#cbd5e1'; 
    if ($row['urgency_level'] == 'ด่วนที่สุด') {
        $color = '#ef4444'; 
    } elseif ($row['urgency_level'] == 'ด่วนมาก') {
        $color = '#fd7e14'; 
    } elseif ($row['urgency_level'] == 'ด่วน') {
        $color = '#f59e0b'; 
    } else {
        $color = '#3b82f6'; 
    }

    $calendar_events[] = [
        'id' => $row['log_id'],
        'title' => $row['first_name'] . ' : ' . $row['task_name'],
        'start' => $row['log_date'],
        'color' => $color,
        'url' => 'show.php?id=' . $row['log_id'] . '&from=admin_dashboard'
    ];
}
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-chart-line me-2"></i>ภาพรวมการปฏิบัติงาน (Overview)</h1>
            <p class="text-muted small">สรุปข้อมูลการทำงานของบุคลากร วิทยาลัยดุริยางคศิลป์</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="small text-muted fw-bold mb-1"><i class="fa-solid fa-calendar-days me-1"></i> รูปแบบเวลา</label>
                    <select name="filter_type" id="filterType" class="form-select bg-light border-0 shadow-sm" onchange="toggleDateInputs()">
                        <option value="day" <?php if($filter_type == 'day') echo 'selected'; ?>>รายวัน (Daily)</option>
                        <option value="week" <?php if($filter_type == 'week') echo 'selected'; ?>>รายสัปดาห์ (Weekly)</option>
                        <option value="month" <?php if($filter_type == 'month') echo 'selected'; ?>>รายเดือน (Monthly)</option>
                        <option value="year" <?php if($filter_type == 'year') echo 'selected'; ?>>รายปี (Yearly)</option>
                    </select>
                </div>

                <div class="col-md-2" id="divDate">
                    <label class="small text-muted fw-bold mb-1">เลือกวันที่อ้างอิง</label>
                    <input type="date" name="date" class="form-control bg-light border-0 shadow-sm" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>

                <div class="col-md-2 d-none" id="divMonth">
                    <label class="small text-muted fw-bold mb-1">เลือกเดือน</label>
                    <input type="month" name="month" class="form-control bg-light border-0 shadow-sm" value="<?php echo htmlspecialchars($filter_month); ?>">
                </div>

                <div class="col-md-2 d-none" id="divYear">
                    <label class="small text-muted fw-bold mb-1">เลือกปี (ค.ศ.)</label>
                    <select name="year" class="form-select bg-light border-0 shadow-sm">
                        <?php 
                        $current_year = date('Y');
                        for($y = $current_year; $y >= $current_year - 3; $y--) {
                            $selected = ($filter_year == $y) ? 'selected' : '';
                            echo "<option value='$y' $selected>$y</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="small text-muted fw-bold mb-1"><i class="fa-solid fa-user-tie me-1"></i> ดูตามบุคคล</label>
                    <select name="user_id" class="form-select bg-light border-0 shadow-sm">
                        <option value="all" class="fw-bold text-primary">-- ภาพรวมทุกคน --</option>
                        <?php foreach($users_list as $u): ?>
                            <option value="<?php echo $u['user_id']; ?>" <?php if($filter_user == $u['user_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="small text-muted fw-bold mb-1"><i class="fa-solid fa-face-smile me-1"></i> ระดับความสุข</label>
                    <select name="mood" class="form-select bg-light border-0 shadow-sm">
                        <option value="all" class="fw-bold">-- ทุกระดับความสุข --</option>
                        <option value="5" <?php if($filter_mood == '5') echo 'selected'; ?>>😆 5 - ยอดเยี่ยม</option>
                        <option value="4" <?php if($filter_mood == '4') echo 'selected'; ?>>😊 4 - ดีมาก</option>
                        <option value="3" <?php if($filter_mood == '3') echo 'selected'; ?>>🙂 3 - ปกติ</option>
                        <option value="2" <?php if($filter_mood == '2') echo 'selected'; ?>>😐 2 - ตึงเครียด</option>
                        <option value="1" <?php if($filter_mood == '1') echo 'selected'; ?>>😞 1 - ต้องดูแล</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <?php if($filter_issue === '1'): ?>
                        <input type="hidden" name="issue" value="1">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100 shadow-sm fw-bold rounded-pill"><i class="fa-solid fa-filter me-1"></i> กรอง</button>
                </div>
            </form>
        </div>
    </div>

    <h5 class="fw-bold text-dark mb-4 ms-2">
        <i class="fa-solid fa-calendar-check text-success me-2"></i><?php echo $display_period; ?>
        <?php if($filter_user != 'all'): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary ms-2 fs-6 fw-normal rounded-pill">เฉพาะบุคคลที่เลือก</span>
        <?php endif; ?>
        <?php if($filter_mood != 'all'): ?>
            <span class="badge bg-warning-subtle text-dark border border-warning ms-1 fs-6 fw-normal rounded-pill">เฉพาะระดับ <?php echo $filter_mood; ?></span>
        <?php endif; ?>
        <?php if($filter_issue == '1'): ?>
            <span class="badge bg-danger-subtle text-danger border border-danger ms-1 fs-6 fw-normal rounded-pill"><i class="fa-solid fa-triangle-exclamation"></i> เฉพาะรายการพบปัญหา</span>
            <a href="?<?php $q=$_GET; unset($q['issue']); echo http_build_query($q); ?>" class="btn btn-sm btn-outline-secondary rounded-pill ms-1 py-0 px-2" title="ยกเลิกตัวกรองนี้"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </h5>

    <div class="row g-4 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary me-3"><i class="fa-solid fa-briefcase"></i></div>
                    <div>
                        <div class="text-muted fw-bold extra-small text-uppercase mb-1">ภาระงานที่สำเร็จ</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $total_logs; ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                    </div>
                </div>
                <?php if ($total_late > 0): ?>
                    <div class="card-footer bg-white border-0 pt-0">
                        <div class="extra-small text-danger fw-bold"><i class="fa-solid fa-clock-rotate-left me-1"></i>ส่งล่าช้า <?php echo $total_late; ?> รายการ</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success me-3"><i class="fa-solid fa-stopwatch"></i></div>
                    <div>
                        <div class="text-muted fw-bold extra-small text-uppercase mb-1">เวลาปฏิบัติงานรวม</div>
                        <h3 class="fw-bold text-dark mb-0">
                            <?php 
                                $h_total = floor($total_work_minutes / 60);
                                $m_total = $total_work_minutes % 60;
                                echo "{$h_total} <small class='fs-6 fw-normal text-muted'>ชม.</small> " . ($m_total > 0 ? "{$m_total} <small class='fs-6 fw-normal text-muted'>น.</small>" : "");
                            ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-sm-6">
            <?php
            $query_params = $_GET;
            if ($filter_issue === '1') {
                unset($query_params['issue']); 
            } else {
                $query_params['issue'] = '1'; 
            }
            $issue_link = '?' . http_build_query($query_params);
            ?>
            <a href="<?php echo $issue_link; ?>" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm bg-white hover-lift" style="<?php echo $filter_issue === '1' ? 'border: 2px solid #ef4444 !important;' : ''; ?>">
                    <div class="card-body p-4 d-flex align-items-center">
                        <div class="stat-icon bg-danger-subtle text-danger me-3"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="text-muted fw-bold extra-small text-uppercase mb-1">ปัญหาอุปสรรคที่พบ</div>
                            <h3 class="fw-bold text-dark mb-0"><?php echo $total_issues; ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-3 col-sm-6">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-<?php echo str_replace('text-', '', $avg_mood['color']); ?>-subtle <?php echo $avg_mood['color']; ?> me-3">
                        <i class="fa-solid <?php echo $avg_mood['icon']; ?>"></i>
                    </div>
                    <div>
                        <div class="text-muted fw-bold extra-small text-uppercase mb-1">ความสุขเฉลี่ย</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $avg_harmony; ?> <small class="fs-6 text-muted fw-normal">/ 5</small></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-lg-5">
            <div class="card shadow-sm border-0 h-100 bg-white">
                <div class="card-header bg-white p-4 fw-bold border-0 text-dark">
                    <i class="fa-solid fa-chart-pie me-2 text-warning"></i>สัดส่วนความสุขรวมทั้งหมด
                </div>
                <div class="card-body d-flex align-items-center justify-content-center p-4 pt-0">
                    <?php if (array_sum($chart_values) == 0): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fa-solid fa-chart-simple fs-1 mb-3 opacity-25"></i>
                            <p class="mb-0 small">ยังไม่มีข้อมูลในช่วงเวลานี้</p>
                        </div>
                    <?php else: ?>
                        <div style="position: relative; height:350px; width:100%">
                            <canvas id="harmonyChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <div class="card shadow-sm border-0 h-100 bg-white">
                <div class="card-header bg-white p-4 d-flex justify-content-between align-items-center border-0 flex-wrap">
                    <span class="fw-bold text-dark mb-2 mb-sm-0"><i class="fa-solid fa-list text-primary me-2"></i>รายละเอียดการทำงาน</span>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="small fw-normal text-muted bg-light px-3 py-1 rounded-pill d-none d-md-flex gap-3 shadow-sm border">
                            <span title="ยอดเยี่ยม"><i class="fa-solid fa-face-laugh-squint text-success fs-6"></i> 5</span>
                            <span title="ดีมาก"><i class="fa-solid fa-face-smile-beam text-info fs-6"></i> 4</span>
                            <span title="ปกติ"><i class="fa-solid fa-face-smile text-primary fs-6"></i> 3</span>
                            <span title="ตึงเครียด"><i class="fa-solid fa-face-meh text-warning fs-6"></i> 2</span>
                            <span title="ต้องดูแล"><i class="fa-solid fa-face-frown text-danger fs-6"></i> 1</span>
                        </div>
                        <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill"><?php echo count($all_logs); ?> รายการ</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-muted text-uppercase sticky-top">
                                <tr>
                                    <th class="ps-4">วันที่ปฏิบัติงาน</th>
                                    <th>บุคลากร</th>
                                    <th>หัวข้องาน / รายละเอียด</th>
                                    <th class="text-center">ความเร่งด่วน</th>
                                    <th class="text-center">เวลา (นาที)</th>
                                    <th class="text-center">ความสุข</th>
                                    <th class="text-center pe-4">ดูข้อมูล</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($all_logs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted small">
                                            <i class="fa-solid fa-folder-open fs-2 mb-2 opacity-25"></i><br>
                                            ไม่มีรายการบันทึกงานตามเงื่อนไขที่เลือก
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($all_logs as $log): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($log['log_date'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['first_name']." ".$log['last_name']); ?></div>
                                            <div class="extra-small text-muted"><?php echo htmlspecialchars($log['dept_name'] ?? '-'); ?></div>
                                        </td>
                                        <td>
                                            <div class="text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($log['task_name']); ?>">
                                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($log['task_name']); ?></span>
                                            </div>
                                            <div class="extra-small text-muted text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($log['description']); ?></div>
                                            <?php if ($log['has_issue'] == 1): ?>
                                                <span class="badge bg-danger-subtle text-danger extra-small px-1 fw-normal"><i class="fa-solid fa-triangle-exclamation"></i> พบปัญหา</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center align-middle">
                                            <?php echo getUrgencyBadge($log['urgency_level'] ?? 'ปกติ'); ?>
                                        </td>
                                        <td class="text-center small">
                                            <div class="fw-bold text-dark">
                                                <?php 
                                                    $val = $log['hours_spent'];
                                                    $lh = floor($val / 60);
                                                    $lm = $val % 60;
                                                    echo ($lh > 0 ? "{$lh} ชม. " : "") . ($lm > 0 ? "{$lm} น." : ($lh == 0 ? "0 น." : ""));
                                                ?>
                                            </div>
                                        </td>
                                        
                                        <td class="text-center">
                                            <?php echo getHarmonyDisplay($log['harmony_score']); ?>
                                        </td>
                                        
                                        <td class="text-center pe-4">
                                            <a href="show.php?id=<?php echo $log['log_id']; ?>&from=admin_dashboard" class="btn btn-sm btn-light text-primary border rounded-pill shadow-sm px-3" title="ดูรายละเอียด">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-5 bg-white">
        <div class="card-header bg-white p-4 d-flex justify-content-between align-items-center border-0 text-dark">
            <span class="fw-bold"><i class="fa-regular fa-calendar-days text-primary me-2"></i>ปฏิทินปฏิบัติงาน</span>
            <div class="small fw-normal text-muted">
                <span class="badge" style="background-color:#3b82f6"><i class="fa-solid fa-circle-check me-1"></i>ปกติ</span>
                <span class="badge" style="background-color:#f59e0b"><i class="fa-solid fa-circle-exclamation me-1"></i>ด่วน</span>
                <span class="badge" style="background-color:#fd7e14"><i class="fa-solid fa-bolt me-1"></i>ด่วนมาก</span>
                <span class="badge" style="background-color:#ef4444"><i class="fa-solid fa-fire-flame-curved me-1"></i>ด่วนที่สุด</span>
            </div>
        </div>
        <div class="card-body p-4 pt-0">
            <div id="worklogCalendar" style="min-height: 400px;"></div>
        </div>
    </div>

</main>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js'></script>

<script>
function toggleDateInputs() {
    const type = document.getElementById('filterType').value;
    const divDate = document.getElementById('divDate');
    const divMonth = document.getElementById('divMonth');
    const divYear = document.getElementById('divYear');

    divDate.classList.add('d-none');
    divMonth.classList.add('d-none');
    divYear.classList.add('d-none');

    if (type === 'day' || type === 'week') {
        divDate.classList.remove('d-none');
    } else if (type === 'month') {
        divMonth.classList.remove('d-none');
    } else if (type === 'year') {
        divYear.classList.remove('d-none');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleDateInputs();

    var calendarEl = document.getElementById('worklogCalendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'th',
            headerToolbar: { 
                left: 'prev,next today', 
                center: 'title', 
                right: 'dayGridMonth,listWeek' 
            },
            events: <?php echo json_encode($calendar_events); ?>,
            themeSystem: 'bootstrap5',
            height: 'auto'
        });
        calendar.render();
    }
});
</script>

<?php if (array_sum($chart_values) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('harmonyChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['ต้องดูแล (1)', 'ตึงเครียด (2)', 'ปกติ (3)', 'ดีมาก (4)', 'ยอดเยี่ยม (5)'],
            datasets: [{
                data: [
                    <?php echo $chart_values[1]; ?>, 
                    <?php echo $chart_values[2]; ?>, 
                    <?php echo $chart_values[3]; ?>, 
                    <?php echo $chart_values[4]; ?>, 
                    <?php echo $chart_values[5]; ?>
                ],
                backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#0ea5e9', '#22c55e'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: { 
            responsive: true, maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {family: 'Sarabun'} } } 
            }, 
            cutout: '65%' 
        }
    });
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>