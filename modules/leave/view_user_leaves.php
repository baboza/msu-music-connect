<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ (เฉพาะ Admin และ ผู้บริหาร)
// ---------------------------------------------------------
$management_roles = ['admin', 'งานบุคคล', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($management_roles)) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='../../index.php';</script>";
    exit();
}

$target_user_id = $_GET['user_id'] ?? 'all';
if (empty($target_user_id)) {
    $target_user_id = 'all';
}
$is_single_user = ($target_user_id !== 'all');

$target_user = null;
if ($is_single_user) {
    // ดึงข้อมูลผู้ใช้งานที่เลือก
    $stmt_u = $pdo->prepare("SELECT u.*, d.dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.user_id = ?");
    $stmt_u->execute([$target_user_id]);
    $target_user = $stmt_u->fetch(PDO::FETCH_ASSOC);

    if (!$target_user) {
        $is_single_user = false;
        $target_user_id = 'all';
    }
}

// รายชื่อผู้ใช้ทั้งหมดสำหรับ Dropdown กรอง
$all_users = $pdo->query("SELECT user_id, prefix_name, first_name, last_name FROM users ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// 2. รับค่าตัวกรองปีงบประมาณและสถานะ
// ---------------------------------------------------------
$available_years = [];
try {
    $available_years = $pdo->query("SELECT year_name FROM fiscal_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

try {
    $user_cond = $is_single_user ? ("WHERE user_id = " . $pdo->quote($target_user_id)) : "";
    $db_years = $pdo->query("SELECT DISTINCT CASE WHEN MONTH(created_at) >= 10 THEN YEAR(created_at) + 544 ELSE YEAR(created_at) + 543 END as fy FROM leave_requests $user_cond ORDER BY fy DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($db_years as $dy) {
        if ($dy && !in_array((string)$dy, $available_years)) {
            $available_years[] = (string)$dy;
        }
    }
    rsort($available_years);
} catch (Exception $e) {}

$filter_year = $_GET['filter_year'] ?? ($_GET['year'] ?? 'all');
$filter_status = $_GET['status'] ?? 'all';
$sort_order = strtolower($_GET['sort'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// ---------------------------------------------------------
// 3. เตรียม Query ดึงใบลาทั้งหมด (รายบุคคล หรือ ทุกรายการ)
// ---------------------------------------------------------
$sql_leaves = "SELECT l.*, t.type_name, u.prefix_name, u.first_name, u.last_name, u.position, u.signature_file as requester_sig, u.dept_id, d.dept_name,
               (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = l.user_id AND ur.role_id = 1) as is_academic
               FROM leave_requests l 
               JOIN leave_types t ON l.type_id = t.type_id 
               JOIN users u ON l.user_id = u.user_id
               LEFT JOIN departments d ON u.dept_id = d.dept_id
               WHERE 1=1";
$params_leaves = [];

if ($is_single_user) {
    $sql_leaves .= " AND l.user_id = ?";
    $params_leaves[] = $target_user_id;
}

if ($filter_year !== 'all') {
    $fy_num = (int)$filter_year;
    $fy_en = ($fy_num > 2500) ? ($fy_num - 543) : $fy_num;
    $fy_start = ($fy_en - 1) . "-10-01 00:00:00";
    $fy_end = $fy_en . "-09-30 23:59:59";
    $sql_leaves .= " AND l.created_at BETWEEN ? AND ?";
    $params_leaves[] = $fy_start;
    $params_leaves[] = $fy_end;
}

if ($filter_status !== 'all') {
    $sql_leaves .= " AND l.status = ?";
    $params_leaves[] = $filter_status;
}

$sql_leaves .= " ORDER BY l.created_at $sort_order, l.leave_id $sort_order";
$stmt_leaves = $pdo->prepare($sql_leaves);
$stmt_leaves->execute($params_leaves);
$user_leaves = $stmt_leaves->fetchAll(PDO::FETCH_ASSOC);

// ดึงการอนุมัติทั้งหมดของแต่ละใบลาไว้ล่วงหน้า
$leave_ids = array_column($user_leaves, 'leave_id');
$all_approvals = [];
if (!empty($leave_ids)) {
    $placeholders = implode(',', array_fill(0, count($leave_ids), '?'));
    $sql_appr = "SELECT a.*, u.prefix_name, u.first_name, u.last_name, u.position, u.signature_file 
                 FROM leave_approvals a 
                 JOIN users u ON a.approver_id = u.user_id 
                 WHERE a.leave_id IN ($placeholders) 
                 ORDER BY a.leave_id ASC, a.step_order ASC";
    $stmt_appr = $pdo->prepare($sql_appr);
    $stmt_appr->execute($leave_ids);
    while ($app_row = $stmt_appr->fetch(PDO::FETCH_ASSOC)) {
        $all_approvals[$app_row['leave_id']][] = $app_row;
    }
}

// ฟังก์ชันคำนวณสถิติสะสม ณ วันที่ยื่นใบลาใบนั้นๆ (Running Total)
function getPastStatsForLeave($pdo, $user_id, $leave_id, $created_at) {
    $current_y = (int)date('Y', strtotime($created_at));
    $current_m = (int)date('m', strtotime($created_at));

    if ($current_m >= 10) {
        $fy_start = $current_y . '-10-01';
        $fy_end = ($current_y + 1) . '-09-30';
        $fy_year_th = $current_y + 1 + 543;
    } else {
        $fy_start = ($current_y - 1) . '-10-01';
        $fy_end = $current_y . '-09-30';
        $fy_year_th = $current_y + 543;
    }

    $stmt_stat = $pdo->prepare("
        SELECT 
            CASE 
                WHEN t.type_name = 'ลากิจ' THEN 'ลากิจส่วนตัว' 
                ELSE t.type_name 
            END as display_type, 
            SUM(DATEDIFF(l.end_date, l.start_date) + 1) as total_days
        FROM leave_requests l
        JOIN leave_types t ON l.type_id = t.type_id
        WHERE l.user_id = ? 
          AND l.status != 'rejected' 
          AND (l.created_at < ? OR (l.created_at = ? AND l.leave_id < ?))
          AND l.start_date >= ? 
          AND l.end_date <= ?
        GROUP BY display_type
    ");
    $stmt_stat->execute([$user_id, $created_at, $created_at, $leave_id, $fy_start, $fy_end]);
    $stats = $stmt_stat->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    return [
        'stats' => $stats,
        'fy_year_th' => $fy_year_th
    ];
}

$page_title = $is_single_user 
    ? ('แฟ้มใบลาทั้งหมด: ' . htmlspecialchars($target_user['prefix_name'] . $target_user['first_name'] . ' ' . $target_user['last_name']))
    : 'แฟ้มใบลาทั้งหมด (บุคลากรทุกคน - ทุกรายการ)';

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Top Action Bar (ไม่แสดงตอนสั่งพิมพ์ PDF) -->
    <div class="d-print-none pt-4 pb-3 mb-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-1">
                    <ol class="breadcrumb extra-small mb-0">
                        <li class="breadcrumb-item"><a href="dashboard_admin.php" class="text-decoration-none text-muted"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a></li>
                        <li class="breadcrumb-item active text-purple" aria-current="page"><?php echo $is_single_user ? 'ใบลาของบุคคล' : 'ใบลาทุกรายการ'; ?></li>
                    </ol>
                </nav>
                <h2 class="h3 fw-bold text-dark mb-0">
                    <i class="fa-solid fa-folder-open text-purple me-2"></i><?php echo $page_title; ?>
                </h2>
                <div class="small text-muted mt-1">
                    <?php if ($is_single_user): ?>
                        <span class="badge bg-purple-subtle text-purple me-2"><i class="fa-solid fa-briefcase me-1"></i><?php echo htmlspecialchars($target_user['position'] ?? 'บุคลากร'); ?></span>
                        <span class="text-secondary"><i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($target_user['dept_name'] ?? 'ไม่ระบุสังกัด'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary me-2"><i class="fa-solid fa-users me-1"></i>บุคลากรทุกคน</span>
                        <span class="text-secondary">ระบบรายงานและพิมพ์ใบลาทั้งหมด</span>
                    <?php endif; ?>
                    <span class="ms-2">| มีใบลาตามเงื่อนไข: <strong class="text-purple"><?php echo count($user_leaves); ?></strong> ฉบับ</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="dashboard_admin.php<?php echo $is_single_user ? ('?user_id=' . urlencode($target_user_id) . '&filter_year=' . urlencode($filter_year)) : ('?filter_year=' . urlencode($filter_year)); ?>" class="btn btn-outline-secondary rounded-pill px-3 shadow-xs small">
                    <i class="fa-solid fa-chevron-left me-1"></i> กลับหน้า Dashboard
                </a>
                
                <!-- ปุ่มหลัก Export PDF / พิมพ์ใบลาทั้งหมด -->
                <button type="button" onclick="window.print()" class="btn btn-purple rounded-pill px-4 py-2 shadow fw-bold">
                    <i class="fa-solid fa-file-pdf me-2 fs-5 align-middle"></i> พิมพ์ / Export PDF ทั้งหมด (<?php echo count($user_leaves); ?> ฉบับ)
                </button>
            </div>
        </div>

        <!-- Filter Card Bar -->
        <div class="card border-0 shadow-sm rounded-4 p-3 mt-3 bg-white">
            <form method="GET" class="row g-2 align-items-center">
                
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-regular fa-user me-1"></i> บุคคล:</span>
                        <select name="user_id" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="all" <?php echo (!$is_single_user) ? 'selected' : ''; ?>>-- บุคลากรทุกคน (ทุกรายการ) --</option>
                            <?php foreach($all_users as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>" <?php echo ($target_user_id == $u['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-regular fa-calendar me-1"></i> ปีงบ:</span>
                        <select name="filter_year" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="all" <?php echo ($filter_year === 'all') ? 'selected' : ''; ?>>-- ทุกปีงบประมาณ --</option>
                            <?php foreach($available_years as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>>
                                    ปีงบประมาณ <?php echo $y; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-solid fa-filter me-1"></i> สถานะ:</span>
                        <select name="status" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>ทุกสถานะ</option>
                            <option value="approved" <?php echo ($filter_status === 'approved') ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                            <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>รออนุมัติ</option>
                            <option value="rejected" <?php echo ($filter_status === 'rejected') ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0 fw-bold text-muted"><i class="fa-solid fa-arrow-down-short-wide me-1"></i> เรียง:</span>
                        <select name="sort" class="form-select bg-light border-0" onchange="this.form.submit()">
                            <option value="asc" <?php echo ($sort_order === 'ASC') ? 'selected' : ''; ?>>เก่า -> ใหม่</option>
                            <option value="desc" <?php echo ($sort_order === 'DESC') ? 'selected' : ''; ?>>ล่าสุด -> เก่า</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-2 text-end">
                    <button type="submit" class="btn btn-sm btn-purple rounded-pill px-3 w-100 fw-bold">กรองข้อมูล</button>
                </div>
            </form>
        </div>

        <!-- Quick Summary & Instructions -->
        <div class="alert alert-info border-0 shadow-xs rounded-3 p-3 mt-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="small">
                <i class="fa-solid fa-circle-info me-2 text-info"></i>
                <strong>คำแนะนำการส่งออก PDF:</strong> เมื่อกดปุ่ม <strong>"พิมพ์ / Export PDF ทั้งหมด"</strong> ในหน้าต่างพิมพ์ ให้เลือกปลายทางเป็น <strong>"Save as PDF (บันทึกเป็น PDF)"</strong> ระบบจัดหน้ากระดาษ A4 ให้ **ใบลาแต่ละฉบับพิมพ์แยกหน้ากันโดยอัตโนมัติ 1 ฉบับต่อ 1 หน้า A4**
            </div>
            <div class="d-flex gap-2">
                <span class="badge bg-white text-dark border px-2 py-1"><i class="fa-solid fa-copy me-1 text-muted"></i>รวม <?php echo count($user_leaves); ?> หน้า A4</span>
            </div>
        </div>
    </div>

    <!-- Content: รายการใบลาแบบกระดาษราชการ A4 (.leave-paper) เรียงต่อกัน -->
    <?php if (empty($user_leaves)): ?>
        <div class="card border-0 shadow-sm bg-white p-5 text-center rounded-4 my-5">
            <div class="text-muted mb-3"><i class="fa-solid fa-file-excel fs-1 opacity-25"></i></div>
            <h5 class="fw-bold text-secondary">ไม่พบรายการใบลาตามเงื่อนไขที่เลือก</h5>
            <p class="small text-muted">ลองเลือกเงื่อนไข "ทุกปีงบประมาณ" หรือเลือก "บุคลากรทุกคน"</p>
            <div class="mt-2">
                <a href="view_user_leaves.php?user_id=all&filter_year=all" class="btn btn-sm btn-outline-purple rounded-pill px-4">ดูทุกรายการ</a>
            </div>
        </div>
    <?php else: ?>

        <div class="leaves-container">
            <?php 
            $index = 0;
            foreach ($user_leaves as $leave): 
                $index++;
                $leave_id = $leave['leave_id'];
                $leave_days = (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / (60 * 60 * 24) + 1;
                $stat_info = getPastStatsForLeave($pdo, $leave['user_id'], $leave_id, $leave['created_at']);
                $past_stats = $stat_info['stats'];
                $fy_year_th = $stat_info['fy_year_th'];
                $approvals = $all_approvals[$leave_id] ?? [];
                $leave_user_name = htmlspecialchars($leave['prefix_name'] . $leave['first_name'] . ' ' . $leave['last_name']);
                $leave_is_academic = ((int)($leave['is_academic'] ?? 0) > 0);
            ?>
                
                <!-- แถบระบุฉบับที่ และปุ่มพิมพ์เฉพาะใบนี้ (ไม่แสดงตอนพิมพ์) -->
                <div class="d-print-none d-flex justify-content-between align-items-center mb-2 mt-4 px-2" style="max-width: 800px; margin: 0 auto;">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-purple rounded-pill px-3 py-1">
                            <i class="fa-solid fa-file-lines me-1"></i> ฉบับที่ <?php echo $index; ?> / <?php echo count($user_leaves); ?> (รหัสคำร้อง #<?php echo $leave_id; ?>)
                        </span>
                        <span class="badge bg-light text-dark border extra-small">
                            <i class="fa-solid fa-user me-1 text-muted"></i><?php echo $leave_user_name; ?>
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($leave['status'] == 'approved'): ?>
                            <span class="badge bg-success-subtle text-success border border-success extra-small"><i class="fa-solid fa-check me-1"></i>อนุมัติแล้ว</span>
                        <?php elseif ($leave['status'] == 'rejected'): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger extra-small"><i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-dark border border-warning extra-small"><i class="fa-solid fa-clock me-1"></i>รออนุมัติ</span>
                        <?php endif; ?>
                        <a href="view.php?id=<?php echo $leave_id; ?>" target="_blank" class="btn btn-sm btn-light border text-purple extra-small rounded-pill px-2">
                            <i class="fa-solid fa-up-right-from-square me-1"></i> เปิดดูแยกหน้า
                        </a>
                    </div>
                </div>

                <!-- แผ่นเอกสารใบลา A4 (.leave-paper) -->
                <div class="leave-paper shadow-sm p-4 p-md-5 bg-white border mx-auto position-relative mb-5" id="leave_paper_<?php echo $leave_id; ?>">
                    

                    <!-- หัวเอกสาร -->
                    <div class="text-center mb-4">
                        <h4 class="fw-bold text-uppercase text-dark mb-1">ใบ<?php echo htmlspecialchars($leave['type_name']); ?></h4>
                        <p class="mb-0 text-secondary">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>
                    </div>

                    <div class="text-end mb-4">
                        <p class="mb-0">วันที่ยื่นขอลา: <?php echo date('d/m/Y', strtotime($leave['created_at'])); ?></p>
                        <small class="text-muted extra-small">เลขที่คำร้อง #<?php echo htmlspecialchars($leave_id); ?></small>
                    </div>

                    <!-- เนื้อหาใบลา -->
                    <div class="leave-body mb-4 text-dark" style="line-height: 2.2;">
                        <p><strong>เรียน</strong> คณบดีวิทยาลัยดุริยางคศิลป์</p>
                        <p class="ps-5">
                            ข้าพเจ้า <strong><?php echo $leave_user_name; ?></strong> 
                            ตำแหน่ง <strong><?php echo htmlspecialchars(!empty($leave['position']) ? $leave['position'] : 'ไม่ระบุ'); ?></strong> 
                            สังกัด <strong><?php echo htmlspecialchars($leave['dept_name'] ?? 'ไม่ระบุ'); ?></strong>
                            มีความประสงค์ขอลา <strong><?php echo htmlspecialchars($leave['type_name']); ?></strong> 
                            เนื่องจาก <strong><?php echo nl2br(htmlspecialchars($leave['reason'])); ?></strong>
                        </p>
                        <p class="ps-5">
                            ตั้งแต่วันที่ <strong><?php echo date('d/m/Y', strtotime($leave['start_date'])); ?></strong> 
                            ถึงวันที่ <strong><?php echo date('d/m/Y', strtotime($leave['end_date'])); ?></strong>
                            (รวม <strong><?php echo $leave_days; ?></strong> วันทำการ)
                        </p>
                    </div>

                    <!-- ส่วนลงนามผู้ขอลา -->
                    <div class="row justify-content-end text-center mb-4">
                        <div class="col-6">
                            <p class="small mb-1 text-muted">ขอแสดงความนับถือ</p>
                            <div class="sig-space mb-2">
                                <?php if (!empty($leave['requester_sig'])): ?>
                                    <img src="../../uploads/signatures/<?php echo htmlspecialchars($leave['requester_sig']); ?>" class="sig-img" alt="ลายเซ็นผู้ขอลา">
                                <?php else: ?>
                                    <span class="text-muted small">(ไม่มีข้อมูลลายเซ็น)</span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-0 fw-bold signer-name">( <?php echo $leave_user_name; ?> )</p>
                            <p class="small text-muted">ผู้ขอลา</p>
                        </div>
                    </div>

                    <!-- ตารางสถิติวันลาสะสมในรอบปีงบประมาณ -->
                    <div class="stat-box my-4 pb-3 border-bottom border-top pt-4">
                        <h6 class="fw-bold text-dark text-center mb-3">สถิติการลาในปีงบประมาณนี้ (พ.ศ. <?php echo $fy_year_th; ?>)</h6>
                        <table class="table table-bordered text-center align-middle stat-table mb-2 mx-auto" style="max-width: 600px;">
                            <thead class="bg-light">
                                <tr>
                                    <th width="30%">ประเภทการลา</th>
                                    <th>ลามาแล้ว<br><small class="fw-normal">(วันทำการ)</small></th>
                                    <th>ลาครั้งนี้<br><small class="fw-normal">(วันทำการ)</small></th>
                                    <th>รวมเป็น<br><small class="fw-normal">(วันทำการ)</small></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $stat_types = ['ลาป่วย', 'ลากิจส่วนตัว', 'ลาพักผ่อน'];
                                $current_type_display = ($leave['type_name'] == 'ลากิจ') ? 'ลากิจส่วนตัว' : $leave['type_name'];

                                if (!in_array($current_type_display, $stat_types)) {
                                    array_unshift($stat_types, $current_type_display);
                                }

                                foreach ($stat_types as $type): 
                                    $past = $past_stats[$type] ?? 0;
                                    $current = ($current_type_display == $type) ? $leave_days : 0;
                                    $total = $past + $current;
                                ?>
                                <tr>
                                    <td class="text-start ps-3 fw-bold"><?php echo htmlspecialchars($type); ?></td>
                                    <td><?php echo $past > 0 ? $past : '-'; ?></td>
                                    <td><?php echo $current > 0 ? $current : '-'; ?></td>
                                    <td class="fw-bold"><?php echo $total > 0 ? $total : '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="text-end small text-muted mb-0 pe-4">* ข้อมูลสรุปอัตโนมัติ (นับย้อนจากวันที่ยื่นใบลาใบนี้)</p>
                    </div>

                    <!-- ลายเซ็นผู้ตรวจสอบและอนุมัติ (Step 1-4) -->
                    <?php if (!empty($approvals)): ?>
                    <div class="row mt-4 pt-2 g-4 text-center">
                        <h6 class="text-start fw-bold text-muted mb-2 d-print-none"><i class="fa-solid fa-list-check me-2"></i>ประวัติการพิจารณา</h6>
                        
                        <?php foreach ($approvals as $app): ?>
                            <?php 
                            $pos_name = "ผู้พิจารณา";
                            if ($app['step_order'] == 1) {
                                $pos_name = !empty($app['position']) ? $app['position'] : "ผู้ตรวจสอบ";
                            } elseif ($app['step_order'] == 2) {
                                $pos_name = $leave_is_academic ? "หัวหน้าภาควิชาที่สังกัด" : "หัวหน้าสำนักงาน";
                            } elseif ($app['step_order'] == 3) {
                                $pos_name = "รองคณบดีฝ่ายบริหาร";
                            } elseif ($app['step_order'] == 4) {
                                $stmt_r = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?");
                                $stmt_r->execute([$app['approver_id']]);
                                $signer_roles = $stmt_r->fetchAll(PDO::FETCH_COLUMN);
                                if (in_array('คณบดี', $signer_roles)) {
                                    $pos_name = "คณบดี";
                                } else {
                                    $pos_name = "รักษาการแทนคณบดี";
                                }
                            }
                            ?>
                            
                            <div class="col-md-3 col-sm-6 col-print-3 mb-2">
                                <p class="extra-small fw-bold text-muted mb-1 text-uppercase d-print-none">ขั้นตอนที่ <?php echo $app['step_order']; ?></p>
                                <div class="sig-space-sm mb-1 position-relative">
                                    <?php if (!empty($app['signature_file'])): ?>
                                        <img src="../../uploads/signatures/<?php echo htmlspecialchars($app['signature_file']); ?>" class="sig-img-sm" alt="ลายเซ็น">
                                    <?php endif; ?>
                                </div>
                                <p class="mb-0 fw-bold text-dark signer-name">
                                    ( <?php echo htmlspecialchars($app['prefix_name'].$app['first_name'].' '.$app['last_name']); ?> )
                                </p>
                                <div class="fw-bold text-purple mb-1 signer-pos">
                                    <?php echo htmlspecialchars($pos_name); ?>
                                </div>
                                <div class="mt-1 d-print-none">
                                    <?php if (strtolower($app['approve_status']) === 'approved'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success extra-small"><i class="fa-solid fa-check me-1"></i>อนุมัติ / เห็นชอบ</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger extra-small"><i class="fa-solid fa-xmark me-1"></i>ไม่อนุมัติ</span>
                                    <?php endif; ?>
                                </div>
                                <div class="extra-small text-muted mt-1" style="font-size: 0.65rem;">
                                    <?php echo (!empty($app['updated_at']) && $app['updated_at'] !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($app['updated_at'])) : "-"; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</main>


<style>
    .text-purple { color: #4b0082 !important; }
    .bg-purple { background-color: #4b0082 !important; color: white !important; }
    .bg-purple-subtle { background-color: rgba(75, 0, 130, 0.08) !important; }
    .border-purple-subtle { border-color: rgba(75, 0, 130, 0.2) !important; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #39048f; color: white; }
    .btn-outline-purple { color: #4b0082; border-color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }

    .leave-paper { 
        max-width: 800px; 
        min-height: 950px; 
        font-family: 'Anuphan', sans-serif; 
        border-radius: 4px;
    }
    
    .status-stamp {
        position: absolute; top: 40px; right: 40px; font-size: 1.5rem; font-weight: bold;
        text-transform: uppercase; border: 3px solid; padding: 4px 14px; border-radius: 8px;
        transform: rotate(-12deg); opacity: 0.85; z-index: 10; pointer-events: none;
    }
    .stamp-approved { color: #198754; border-color: #198754; }
    .stamp-rejected { color: #dc3545; border-color: #dc3545; }
    .stamp-pending  { color: #d97706; border-color: #d97706; }

    .sig-space { height: 70px; display: flex; align-items: end; justify-content: center; border-bottom: 1px dotted #ccc; width: 180px; margin: 0 auto; padding-bottom: 5px; }
    .sig-space-sm { height: 50px; display: flex; align-items: end; justify-content: center; border-bottom: 1px dotted #ddd; width: 130px; margin: 0 auto; padding-bottom: 5px;}
    .sig-img { max-height: 65px; object-fit: contain; }
    .sig-img-sm { max-height: 45px; object-fit: contain; }
    .extra-small { font-size: 0.75rem; }
    .stat-table th, .stat-table td { border-color: #333; }
    .stat-table th { font-weight: bold; }
    .signer-name, .signer-pos { white-space: nowrap !important; font-size: 0.75rem !important; letter-spacing: -0.3px !important; width: 100%; display: block; }
    .shadow-xs { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

    /* CSS สำหรับการพิมพ์และ Export เป็น PDF */
    @media print {
        @page { 
            size: A4 portrait; 
            margin: 10mm 10mm; 
        }
        body { 
            background-color: white !important; 
            font-size: 11pt !important; 
        }
        .sidebar, .header, .navbar, .d-print-none, footer, .footer, #footer, .site-footer { 
            display: none !important; 
        }
        .col-md-9, .col-lg-10 { 
            width: 100% !important; 
            margin: 0 !important; 
            padding: 0 !important; 
            max-width: 100% !important; 
            flex: 0 0 100% !important; 
        }
        main { 
            margin-top: 0 !important; 
            padding: 0 !important; 
            background: white !important;
        }
        .leaves-container {
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .leave-paper { 
            border: none !important; 
            box-shadow: none !important; 
            margin: 0 !important; 
            padding: 0 10px !important; 
            min-height: auto !important;
            /* บังคับให้แต่ละใบลาขึ้นหน้าใหม่ */
            page-break-after: always !important; 
            break-after: page !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .leave-paper:last-child {
            page-break-after: auto !important;
            break-after: auto !important;
        }
        .mb-5, .mb-4, .mt-5, .mt-4, .pt-4, .pb-3, .p-5 { 
            padding: 0.4rem !important; 
            margin-bottom: 0.4rem !important; 
            margin-top: 0.4rem !important; 
        }
        .leave-body p { 
            margin-bottom: 0.2rem !important; 
            line-height: 1.4 !important; 
        }
        .stat-table { 
            font-size: 9pt !important; 
            margin-bottom: 0 !important; 
        }
        .stat-table th, .stat-table td { 
            padding: 2px 5px !important; 
        }
        .sig-space { height: 50px !important; }
        .sig-space-sm { height: 35px !important; margin-bottom: 0 !important; }
        .sig-img { max-height: 45px !important; }
        .sig-img-sm { max-height: 30px !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
        .col-print-3 { 
            flex: 0 0 25% !important; 
            max-width: 25% !important; 
            padding: 0 5px !important; 
        }
        .signer-name, .signer-pos { 
            font-size: 8pt !important; 
            margin-bottom: 0 !important; 
        }
        .status-stamp { 
            opacity: 1 !important; 
            transform: rotate(-10deg) !important; 
            top: 10px !important; 
            right: 10px !important; 
            font-size: 1.1rem !important; 
        }
        .stat-box { 
            padding-top: 0.5rem !important; 
            margin-top: 0.5rem !important; 
        }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>
