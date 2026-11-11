<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// Check RBAC
$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles);
$is_head = in_array('หัวหน้าสำนักงาน', $user_roles);
$is_deputy = in_array('รองคณบดีฝ่ายบริหาร', $user_roles);
$is_dean = in_array('คณบดี', $user_roles);

if (!$is_admin && !$is_head && !$is_deputy && !$is_dean) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    // อัปเกรดคอลัมน์ user_id ใน attendance_logs ให้เป็น VARCHAR(50) อัตโนมัติหากยังเป็น int เดิม
    if (empty($_SESSION['attendance_uid_migrated'])) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM attendance_logs LIKE 'user_id'");
        $colInfo = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
            $uColStmt = $pdo->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_id' LIMIT 1");
            $uCol = $uColStmt ? $uColStmt->fetch(PDO::FETCH_ASSOC) : null;
            $charsetClause = (!empty($uCol['CHARACTER_SET_NAME']) && !empty($uCol['COLLATION_NAME']))
                ? ("CHARACTER SET " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['CHARACTER_SET_NAME']) . " COLLATE " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['COLLATION_NAME']))
                : "";
            $pdo->exec("ALTER TABLE attendance_logs MODIFY COLUMN user_id VARCHAR(50) {$charsetClause} NOT NULL");
            $pdo->exec("
                UPDATE attendance_logs
                SET user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(photo_path, '_', 2), '_', -1)
                WHERE photo_path LIKE 'scan\\_%\\_%'
                  AND EXISTS (
                      SELECT 1 FROM users u
                      WHERE u.user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(attendance_logs.photo_path, '_', 2), '_', -1)
                  )
            ");
        }
        $_SESSION['attendance_uid_migrated'] = true;
    }

    // Summary
    $stmt_summary = $pdo->prepare("SELECT 
        COUNT(DISTINCT CASE WHEN scan_type = 'in' THEN user_id END) as total_present,
        COUNT(id) as total_scans
        FROM attendance_logs 
        WHERE DATE(scan_time) = ?");
    $stmt_summary->execute([$filter_date]);
    $summary = $stmt_summary->fetch();

    // Details
    $stmt_logs = $pdo->prepare("SELECT a.*, u.first_name, u.last_name, u.prefix_name, u.profile_picture 
        FROM attendance_logs a
        JOIN users u ON a.user_id = u.user_id
        WHERE DATE(a.scan_time) = ?
        ORDER BY a.scan_time DESC");
    $stmt_logs->execute([$filter_date]);
    $logs = $stmt_logs->fetchAll();
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-end pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-clock-rotate-left text-purple me-2"></i>ระบบบันทึกเวลา (Attendance)</h1>
            <p class="text-muted small mb-0">ข้อมูลการสแกนเข้า-ออกงาน สำหรับผู้บริหาร</p>
        </div>
        <div class="mt-2 mt-md-0 d-flex gap-2">
            <a href="public_scan.php" target="_blank" class="btn btn-outline-primary rounded-pill fw-bold"><i class="fa-solid fa-camera me-2"></i>เปิดจุดถ่ายรูปลงเวลา (Kiosk)</a>
            <a href="my_attendance.php" class="btn btn-primary rounded-pill fw-bold"><i class="fa-solid fa-user-clock me-2"></i>บันทึกเวลาของฉัน</a>
        </div>
    </div>

    <!-- Filter & Summary -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body">
                    <form method="GET">
                        <label class="fw-bold text-muted small text-uppercase mb-2">เลือกวันที่</label>
                        <div class="input-group">
                            <input type="date" name="date" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4">
                <div class="card-body">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-users me-1 text-success"></i> ผู้มาปฏิบัติงาน (สแกนเข้า)</div>
                    <h3 class="fw-bold text-dark mb-0"><?php echo number_format($summary['total_present'] ?? 0); ?> <span class="fs-6 text-muted fw-normal">คน</span></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-info border-4">
                <div class="card-body">
                    <div class="text-muted extra-small fw-bold text-uppercase mb-1"><i class="fa-solid fa-fingerprint me-1 text-info"></i> จำนวนการสแกนทั้งหมด</div>
                    <h3 class="fw-bold text-dark mb-0"><?php echo number_format($summary['total_scans'] ?? 0); ?> <span class="fs-6 text-muted fw-normal">ครั้ง</span></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white mb-5">
        <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list me-2 text-secondary"></i>รายการบันทึกเวลา วันที่ <?php echo date('d/m/Y', strtotime($filter_date)); ?></h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="attendanceTable" class="table table-hover align-middle w-100">
                    <thead class="bg-light text-muted extra-small text-uppercase">
                        <tr>
                            <th width="15%">เวลา</th>
                            <th width="10%" class="text-center">ประเภท</th>
                            <th width="30%">บุคลากร</th>
                            <th width="15%" class="text-center">รูปถ่ายตอนสแกน</th>
                            <th width="15%" class="text-end">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): 
                            $type_badge = $log['scan_type'] === 'in' ? '<span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>เข้างาน</span>' : '<span class="badge bg-secondary rounded-pill px-3 py-2"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i>ออกงาน</span>';
                        ?>
                        <tr>
                            <td class="fw-bold text-dark"><?php echo date('H:i:s', strtotime($log['scan_time'])); ?></td>
                            <td class="text-center"><?php echo $type_badge; ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if($log['profile_picture']): ?>
                                        <img src="../../uploads/avatars/<?php echo $log['profile_picture']; ?>" class="rounded-circle border me-2" width="40" height="40" style="object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted me-2" style="width: 40px; height: 40px;"><i class="fa-solid fa-user"></i></div>
                                    <?php endif; ?>
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($log['prefix_name'].$log['first_name'].' '.$log['last_name']); ?>
                                        <?php if(!empty($log['remark'])): ?>
                                            <div class="text-danger small mt-1 bg-danger-subtle px-2 py-1 rounded d-inline-block" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($log['remark']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if($log['photo_path']): ?>
                                    <a href="javascript:void(0)" onclick="showPhoto('../../uploads/attendance/<?php echo $log['photo_path']; ?>', '<?php echo htmlspecialchars($log['first_name']); ?> - <?php echo $log['scan_type'] == 'in' ? 'เข้างาน' : 'ออกงาน'; ?> @ <?php echo date('H:i:s', strtotime($log['scan_time'])); ?>')">
                                        <img src="../../uploads/attendance/<?php echo $log['photo_path']; ?>" class="rounded shadow-sm" width="60" height="45" style="object-fit: cover; border: 2px solid #fff;">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted extra-small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small text-muted"><?php echo !empty($log['ip_address']) ? htmlspecialchars($log['ip_address']) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Single Global Photo Modal -->
<div class="modal fade" id="globalPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 bg-transparent">
            <div class="modal-body text-center p-0 position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="z-index: 10;"></button>
                <img src="" id="globalPhotoModalImg" class="img-fluid rounded-4 shadow-lg w-100">
                <div class="bg-dark bg-opacity-75 text-white p-3 rounded-bottom-4 position-absolute bottom-0 w-100" id="globalPhotoModalCaption">
                    <!-- Caption goes here -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .extra-small { font-size: 0.75rem; }
    
    div.dataTables_wrapper div.dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #dee2e6; outline: none; }
    div.dataTables_wrapper div.dataTables_length select { border-radius: 10px; padding: 4px 30px 4px 10px; border: 1px solid #dee2e6; outline: none; }
    .page-item.active .page-link { background-color: #4b0082; border-color: #4b0082; }
    .page-link { color: #4b0082; border-radius: 50% !important; margin: 0 3px; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; }
</style>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#attendanceTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json" },
            "order": [[ 0, "desc" ]],
            "pageLength": 50
        });
    });

    // Global function to show photo modal
    function showPhoto(imgSrc, captionText) {
        document.getElementById('globalPhotoModalImg').src = imgSrc;
        document.getElementById('globalPhotoModalCaption').innerText = captionText;
        var photoModal = new bootstrap.Modal(document.getElementById('globalPhotoModal'));
        photoModal.show();
    }
</script>
