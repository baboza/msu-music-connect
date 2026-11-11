<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// --- 1. ดึงรายการปีงบประมาณจากระบบ ---
$stmt_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years = $stmt_fy->fetchAll();

// หาปีปัจจุบัน (Active) เพื่อใช้เป็นค่าเริ่มต้น
$default_year = '';
foreach ($fiscal_years as $fy) {
    if ($fy['is_active'] == 1) {
        $default_year = $fy['year_name'];
        break;
    }
}
if (empty($default_year) && !empty($fiscal_years)) {
    $default_year = $fiscal_years[0]['year_name'];
}

// รับค่ากรองปีงบประมาณ 
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : $default_year; 

// --- 2. ดึงข้อมูลจากฐานข้อมูล (รองรับการกรองปี) ---
$sql = "SELECT p.*, 
        u1.first_name as resp_fname, u1.last_name as resp_lname,
        u2.first_name as coord_fname, u2.last_name as coord_lname
        FROM projects p 
        LEFT JOIN users u1 ON p.responsible_id = u1.user_id 
        LEFT JOIN users u2 ON p.coordinator_id = u2.user_id 
        WHERE 1=1";

$params = [];
if ($filter_year !== 'all') {
    $sql .= " AND p.fiscal_year = :filter_year";
    $params['filter_year'] = $filter_year;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_projects = $stmt->fetchAll();

$projects = [];
$today = strtotime(date('Y-m-d'));

// --- 3. ประมวลผลข้อมูล (คำนวณสถานะและ % ความคืบหน้า) ---
foreach ($raw_projects as $row) {
    $tasks = [$row['task_approve'], $row['task_borrow'], $row['task_execute'], $row['task_report'], $row['task_clear_money']];
    $completed_tasks = array_sum($tasks);
    $progress = ($completed_tasks / 5) * 100;
    
    $display_status = $row['status'];
    if ($progress < 100 && strtotime($row['deadline']) < $today) {
        $display_status = 'ล่าช้า';
    }

    $row['completed_tasks'] = $completed_tasks;
    $row['progress'] = $progress;
    $row['display_status'] = $display_status;
    $projects[] = $row;
}

// สถิติสรุป
$count_active = count(array_filter($projects, function($p) { return $p['display_status'] == 'กำลังดำเนินการ'; }));
$count_success = count(array_filter($projects, function($p) { return $p['display_status'] == 'ดำเนินการเรียบร้อย'; }));
$count_delayed = count(array_filter($projects, function($p) { return $p['display_status'] == 'ล่าช้า'; }));

// 💡 อัปเดตสไตล์ของ Badge สถานะให้เป็น Soft UI
$status_classes = [
    'ยังไม่เริ่ม' => 'bg-secondary bg-opacity-25 text-dark',
    'กำลังดำเนินการ' => 'bg-warning-subtle text-dark border border-warning',
    'ดำเนินการเรียบร้อย' => 'bg-success-subtle text-success border border-success',
    'ล่าช้า' => 'bg-danger-subtle text-danger border border-danger'
];

// สิทธิ์สำหรับการจัดการโครงการ
$is_admin = hasRole(['admin', 'งานแผน', 'คณบดี', 'รองคณบดีฝ่ายบริหาร']);
// สิทธิ์สำหรับการดูหน้า Dashboard โครงการ
$can_view_dashboard = hasRole(['admin', 'งานแผน', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี']);
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold">
            <i class="fa-solid fa-diagram-project me-2"></i>ระบบติดตามโครงการ
        </h1>
        <div class="d-flex gap-2 align-items-center">
            
            <form action="" method="GET" class="shadow-sm rounded-pill overflow-hidden bg-white border border-light">
                <div class="input-group">
                    <span class="input-group-text bg-white border-0 text-primary ps-3"><i class="fa-solid fa-filter"></i></span>
                    <select name="filter_year" class="form-select border-0 text-primary fw-bold shadow-none" style="width: auto; background-color: #f8fafc; cursor: pointer;" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_year == 'all' ? 'selected' : ''; ?>>ทุกปีงบประมาณ</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?php echo htmlspecialchars($fy['year_name']); ?>" <?php echo $filter_year == $fy['year_name'] ? 'selected' : ''; ?>>
                                ปี <?php echo htmlspecialchars($fy['year_name']); ?> <?php echo $fy['is_active'] == 1 ? '(ปัจจุบัน)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($can_view_dashboard): ?>
            <a href="dashboard.php" class="btn btn-outline-primary bg-white rounded-pill px-3 shadow-sm ms-1 fw-bold">
                <i class="fa-solid fa-chart-pie me-1"></i> ภาพรวม
            </a>
            <?php endif; ?>

            <a href="add_project.php" class="btn btn-primary rounded-pill px-4 shadow-sm ms-1">
                <i class="fa-solid fa-plus-circle me-1"></i> สร้างโครงการ
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm border-start border-primary border-4 bg-white hover-card">
                <div class="card-body">
                    <p class="mb-1 text-muted fw-bold extra-small text-uppercase">โครงการทั้งหมด (ปีที่เลือก)</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-primary fw-bold mb-0"><?php echo count($projects); ?></h3>
                        <div class="stat-icon bg-primary-subtle text-primary"><i class="fa-solid fa-folder-open"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm border-start border-warning border-4 bg-white hover-card">
                <div class="card-body">
                    <p class="mb-1 text-muted fw-bold extra-small text-uppercase">กำลังดำเนินการ</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-warning fw-bold mb-0"><?php echo $count_active; ?></h3>
                        <div class="stat-icon bg-warning-subtle text-warning"><i class="fa-solid fa-person-digging"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm border-start border-success border-4 bg-white hover-card">
                <div class="card-body">
                    <p class="mb-1 text-muted fw-bold extra-small text-uppercase">ดำเนินการเรียบร้อย</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-success fw-bold mb-0"><?php echo $count_success; ?></h3>
                        <div class="stat-icon bg-success-subtle text-success"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm border-start border-danger border-4 bg-white hover-card">
                <div class="card-body">
                    <p class="mb-1 text-muted fw-bold extra-small text-uppercase">ล่าช้ากว่ากำหนด</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="text-danger fw-bold mb-0"><?php echo $count_delayed; ?></h3>
                        <div class="stat-icon bg-danger-subtle text-danger"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-5">
        <div class="card-body p-0">
            <div class="table-responsive p-3">
                <table id="projectsTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th class="ps-4" width="28%">ข้อมูลโครงการ</th>
                            <th width="12%">งบประมาณ</th>
                            <th width="16%">ความคืบหน้า</th>
                            <th width="12%" class="text-center">สถานะ</th>
                            <th width="11%">ผู้รับผิดชอบ</th>
                            <th width="11%">ผู้ประสานโครงการ</th>
                            <th class="text-end pe-4" width="10%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($projects)): ?>
                            <?php foreach ($projects as $row): 
                                $curr_status = $row['display_status'];
                                $status_class = isset($status_classes[$curr_status]) ? $status_classes[$curr_status] : 'bg-light text-dark';
                                $is_delayed = ($curr_status == 'ล่าช้า');
                                
                                // จัดกลุ่มสถานะให้เป็นตัวเลข เพื่อให้เรียงลำดับได้อย่างถูกต้อง
                                $status_order_val = 4; // ค่าเริ่มต้น
                                if ($curr_status == 'กำลังดำเนินการ') $status_order_val = 1;
                                elseif ($curr_status == 'ล่าช้า') $status_order_val = 2;
                                elseif ($curr_status == 'ดำเนินการเรียบร้อย') $status_order_val = 3;
                                elseif ($curr_status == 'ยังไม่เริ่ม') $status_order_val = 4;
                            ?>
                            <tr data-group="<?php echo $curr_status; ?>">
                                
                                <td class="ps-4" data-order="<?php echo strtotime($row['start_date']); ?>">
                                    <div class="fw-bold text-dark mb-0" style="font-size: 0.85rem; line-height: 1.4; max-width: 250px; overflow-wrap: break-word;" title="<?php echo htmlspecialchars($row['project_name']); ?>">
                                        <span class="d-none">ปีงบประมาณ <?php echo htmlspecialchars($row['fiscal_year']); ?></span>
                                        <?php echo htmlspecialchars($row['project_name']); ?>
                                    </div>
                                    <div class="extra-small mt-1">
                                        <span class="text-primary fw-bold"><i class="fa-solid fa-calendar-play me-1"></i>เริ่ม: <?php echo date('d/m/Y', strtotime($row['start_date'])); ?></span>
                                        <span class="text-muted ms-2">| เดดไลน์: <?php echo date('d/m/Y', strtotime($row['deadline'])); ?></span>
                                    </div>
                                </td>
                                
                                <td data-order="<?php echo $row['budget']; ?>">
                                    <span class="fw-bold text-success">฿<?php echo number_format($row['budget'], 2); ?></span>
                                </td>
                                
                                <td data-order="<?php echo $row['progress']; ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1" style="height: 8px; border-radius: 10px; background-color: #f1f5f9;">
                                            <div class="progress-bar <?php echo $row['progress'] == 100 ? 'bg-success' : ($is_delayed ? 'bg-danger' : 'bg-primary'); ?>" style="width: <?php echo $row['progress']; ?>%"></div>
                                        </div>
                                        <span class="ms-2 small fw-bold text-dark"><?php echo number_format($row['progress']); ?>%</span>
                                    </div>
                                </td>
                                
                                <td class="text-center" data-order="<?php echo $status_order_val; ?>" data-search="<?php echo $curr_status; ?>">
                                    <span class="badge <?php echo $status_class; ?> rounded-pill px-3 py-1" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-circle me-1" style="font-size: 0.5rem; vertical-align: middle;"></i> <?php echo $curr_status; ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <?php if (!empty($row['resp_fname'])): ?>
                                        <div class="small fw-bold text-dark text-truncate" style="max-width: 120px;" title="<?php echo htmlspecialchars($row['resp_fname'].' '.$row['resp_lname']); ?>">
                                            <?php echo htmlspecialchars($row['resp_fname']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1 small fw-normal"><i class="fa-regular fa-clock me-1"></i>รอข้อมูล</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if (!empty($row['coord_fname'])): ?>
                                        <div class="small fw-bold text-dark text-truncate" style="max-width: 120px;" title="<?php echo htmlspecialchars(trim($row['coord_fname'].' '.$row['coord_lname'])); ?>">
                                            <?php echo htmlspecialchars($row['coord_fname']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1 small fw-normal"><i class="fa-regular fa-clock me-1"></i>รอข้อมูล</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-end pe-4">
                                    <div class="btn-group shadow-sm rounded-pill bg-white">
                                        <a href="view_project.php?id=<?php echo $row['project_id']; ?>" class="btn btn-sm btn-light text-primary border-end" title="Checklist"><i class="fa-solid fa-list-check"></i></a>
                                        <?php 
                                        $can_manage = ($is_admin || $_SESSION['user_id'] == $row['responsible_id'] || $_SESSION['user_id'] == $row['coordinator_id']);
                                        if ($can_manage): 
                                        ?>
                                        <a href="edit_project.php?id=<?php echo $row['project_id']; ?>" class="btn btn-sm btn-light text-primary border-end" title="แก้ไข"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="delete_project.php?id=<?php echo $row['project_id']; ?>" class="btn btn-sm btn-light text-danger" title="ลบ" onclick="return confirm('ลบโครงการนี้?');"><i class="fa-solid fa-trash-can"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<style>
    /* สไตล์เฉพาะสำหรับ Row Grouping ในหน้าตาราง Project */
    tr.group-header td { 
        background-color: #f8fafc !important; 
        border-bottom: 2px solid #e2e8f0 !important; 
        color: #2d3748 !important; 
    }
    .hover-card { transition: all 0.3s ease; }
    .hover-card:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(20, 20, 40, 0.08) !important; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script>
    if (typeof jQuery == 'undefined') {
        document.write('<script src="https://code.jquery.com/jquery-3.7.0.min.js"><\/script>');
    }
</script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    var table = $('#projectsTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json"
        },
        "pageLength": 100, // แสดงผล 100 แถวต่อหน้า
        "ordering": true,
        // เรียงลำดับ Level 1: ตามสถานะ (คอลัมน์ 3) | Level 2: ตามวันที่เริ่มโครงการ (คอลัมน์ 0)
        "order": [[ 3, "asc" ], [ 0, "desc" ]], 
        "columnDefs": [
            { "orderable": false, "targets": 6 }
        ],
        // สร้างระบบ Row Grouping แบบอัตโนมัติโดยใช้ DrawCallback
        "drawCallback": function (settings) {
            var api = this.api();
            var rows = api.rows({ page: 'current' }).nodes();
            var last = null;

            // ค้นหาจำนวนรวมของแต่ละกลุ่มในหน้าที่แสดงผล
            var groupCounts = {};
            api.rows({search: 'applied'}).every(function() {
                var group = $(this.node()).attr('data-group');
                if(group) {
                    groupCounts[group] = (groupCounts[group] || 0) + 1;
                }
            });

            // สร้างแถบ Header ขั้นระหว่างกลุ่ม
            api.rows({ page: 'current' }).every(function(rowIdx, tableLoop, rowLoop) {
                var rowNode = this.node();
                var group = $(rowNode).attr('data-group');

                if (last !== group) {
                    var count = groupCounts[group] || 0;
                    
                    // ปรับสีกล่องนับจำนวนให้ตรงกับสถานะ
                    var badgeColor = 'bg-primary';
                    if(group === 'ดำเนินการเรียบร้อย') badgeColor = 'bg-success';
                    if(group === 'ล่าช้า') badgeColor = 'bg-danger';
                    if(group === 'กำลังดำเนินการ') badgeColor = 'bg-warning text-dark';
                    if(group === 'ยังไม่เริ่ม') badgeColor = 'bg-secondary';

                    $(rowNode).before(
                        '<tr class="group-header"><td colspan="7" class="py-2 ps-4 fw-bold"><i class="fa-solid fa-layer-group me-2 text-primary"></i> ' + group + ' <span class="badge ' + badgeColor + ' rounded-pill ms-2 fw-normal" style="font-size: 0.7rem;">' + count + ' รายการ</span></td></tr>'
                    );
                    last = group;
                }
            });
        }
    });
});
</script>


