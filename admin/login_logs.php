<?php
require_once '../auth/session_check.php';
require_once '../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เฉพาะ Admin เท่านั้น)
if (!function_exists('hasRole') || !hasRole(['admin'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: ท่านไม่มีสิทธิ์ดูประวัติระบบ";
    $_SESSION['msg_type'] = "danger";
    header("Location: ../index.php");
    exit();
}

// 2. รับค่าตัวกรอง User ID
$filter_user_id = $_GET['user_id'] ?? '';

// 3. ดึงรายชื่อ User ทั้งหมดมาทำ Dropdown Filter
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name ASC");
$user_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// 4. Query สถิติเบื้องต้น (สถิติของวันนี้)
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$stmt_today_logs = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE login_time BETWEEN ? AND ?");
$stmt_today_logs->execute([$today_start, $today_end]);
$logins_today = $stmt_today_logs->fetchColumn();

$stmt_unique_users = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM login_logs WHERE login_time BETWEEN ? AND ?");
$stmt_unique_users->execute([$today_start, $today_end]);
$unique_users_today = $stmt_unique_users->fetchColumn();

// 5. Query ดึงประวัติการ Login (รองรับการกรองรายบุคคล)
$sql = "
    SELECT l.log_id, l.login_time, l.ip_address, l.user_agent, 
           u.first_name, u.last_name, u.profile_picture, u.user_id
    FROM login_logs l
    JOIN users u ON l.user_id = u.user_id
";
$params = [];

if (!empty($filter_user_id)) {
    $sql .= " WHERE l.user_id = ? ";
    $params[] = $filter_user_id;
}

$sql .= " ORDER BY l.login_time DESC LIMIT 500"; // จำกัด 500 รายการล่าสุดเพื่อไม่ให้โหลดหนัก
$stmt_logs = $pdo->prepare($sql);
$stmt_logs->execute($params);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// ฟังก์ชันย่อชื่อเบราว์เซอร์ให้ดูง่าย
function getBrowserShortName($agent) {
    if (strpos($agent, 'Edg') !== false) return ['name' => 'Edge', 'icon' => 'fa-edge', 'color' => 'text-info'];
    if (strpos($agent, 'Chrome') !== false) return ['name' => 'Chrome', 'icon' => 'fa-chrome', 'color' => 'text-success'];
    if (strpos($agent, 'Safari') !== false && strpos($agent, 'Chrome') === false) return ['name' => 'Safari', 'icon' => 'fa-safari', 'color' => 'text-primary'];
    if (strpos($agent, 'Firefox') !== false) return ['name' => 'Firefox', 'icon' => 'fa-firefox', 'color' => 'text-warning'];
    return ['name' => 'Unknown', 'icon' => 'fa-globe', 'color' => 'text-secondary'];
}

$base_url = "/music-e-office"; 
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<style>
    .text-purple { color: #4b0082 !important; }
    .bg-purple-subtle { background-color: #f3e5f5 !important; }
    .text-gold { color: #c5a028 !important; }
    .bg-gold-subtle { background-color: #fcf4d9 !important; }
    .avatar-sm { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
    .table>tbody>tr>td { vertical-align: middle; padding: 12px; }
    
    /* ปรับแต่ง DataTables ให้สวยงาม */
    .dataTables_wrapper .dataTables_length select { border-radius: 8px; border: 1px solid #e2e8f0; padding: 4px 8px; }
    .dataTables_wrapper .dataTables_filter input { border-radius: 8px; border: 1px solid #e2e8f0; padding: 6px 12px; margin-left: 8px; }
    .page-item.active .page-link { background-color: #4b0082; border-color: #4b0082; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-shield-halved me-2 text-gold"></i>ประวัติการเข้าใช้งานระบบ</h1>
            <p class="text-muted mb-0">ตรวจสอบและติดตามการล็อกอินของบุคลากร (Login Audit Logs)</p>
        </div>
        
        <div class="d-flex align-items-center mt-2 mt-md-0">
            <form action="" method="GET" class="d-flex align-items-center bg-white border rounded-pill px-3 py-1 shadow-sm">
                <i class="fa-solid fa-filter text-muted me-2"></i>
                <select name="user_id" class="form-select form-select-sm border-0 fw-bold text-dark bg-transparent pe-4" onchange="this.form.submit()" style="box-shadow: none; min-width: 200px;">
                    <option value="">-- ดูผู้ใช้งานทั้งหมด --</option>
                    <?php foreach($user_list as $u): ?>
                        <option value="<?php echo $u['user_id']; ?>" <?php if($filter_user_id == $u['user_id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="bg-purple-subtle text-purple rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fa-solid fa-right-to-bracket"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold mb-1 text-uppercase">ล็อกอินวันนี้รวม</p>
                        <h3 class="mb-0 fw-bold text-dark"><?php echo number_format($logins_today); ?> <small class="fs-6 text-muted fw-normal">ครั้ง</small></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="bg-gold-subtle text-gold rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold mb-1 text-uppercase">จำนวนผู้ใช้ที่ออนไลน์วันนี้</p>
                        <h3 class="mb-0 fw-bold text-dark"><?php echo number_format($unique_users_today); ?> <small class="fs-6 text-muted fw-normal">คน</small></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark"><i class="fa-solid fa-list-ul me-2 text-purple"></i> 500 รายการล่าสุด <?php echo $filter_user_id ? '(กรองเฉพาะบุคคล)' : ''; ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive p-3">
                <table id="loginLogsTable" class="table table-hover align-middle mb-0 border-top border-light" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">วัน / เวลาที่เข้าใช้งาน</th>
                            <th>ผู้ใช้งาน</th>
                            <th>IP Address</th>
                            <th>อุปกรณ์ / เบราว์เซอร์</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): 
                                $browser = getBrowserShortName($log['user_agent']);
                            ?>
                            <tr>
                                <td class="ps-4" data-sort="<?php echo strtotime($log['login_time']); ?>">
                                    <div class="fw-bold text-dark small"><?php echo date('d M Y', strtotime($log['login_time'])); ?></div>
                                    <div class="extra-small text-muted"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i:s', strtotime($log['login_time'])); ?> น.</div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if(!empty($log['profile_picture'])): ?>
                                            <img src="<?php echo $base_url; ?>/uploads/avatars/<?php echo htmlspecialchars($log['profile_picture']); ?>" class="avatar-sm me-2 shadow-sm">
                                        <?php else: ?>
                                            <div class="avatar-sm me-2 bg-light text-secondary d-flex align-items-center justify-content-center shadow-sm"><i class="fa-solid fa-user"></i></div>
                                        <?php endif; ?>
                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 fw-normal font-monospace shadow-sm"><?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center small">
                                        <i class="fa-brands <?php echo $browser['icon']; ?> <?php echo $browser['color']; ?> fs-5 me-2"></i>
                                        <span class="text-secondary text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                            <?php echo htmlspecialchars($log['user_agent'] ?? '-'); ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<?php require_once '../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#loginLogsTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json",
            "search": "ค้นหาทั่วไป:"
        },
        "pageLength": 20, // 💡 ตั้งค่าเริ่มต้นให้แสดง 20 รายการต่อหน้า
        "lengthMenu": [[10, 20, 50, 100, -1], [10, 20, 50, 100, "ทั้งหมด"]], // ให้เลือกจำนวนหน้าได้
        "order": [[ 0, "desc" ]] // เรียงจากวันที่ล่าสุด (คอลัมน์แรก)
    });
});
</script>