<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_remark') {
    $log_id = $_POST['log_id'] ?? 0;
    $remark = $_POST['remark'] ?? '';
    
    // Verify ownership and lateness (scan_type = 'in' and time > 09:00)
    try {
        $stmt_check = $pdo->prepare("SELECT id FROM attendance_logs WHERE id = ? AND user_id = ? AND scan_type = 'in' AND TIME(scan_time) > '09:00:00'");
        $stmt_check->execute([$log_id, $user_id]);
        if ($stmt_check->fetch()) {
            $stmt_update = $pdo->prepare("UPDATE attendance_logs SET remark = ? WHERE id = ?");
            $stmt_update->execute([$remark, $log_id]);
            $success = "บันทึกหมายเหตุเรียบร้อยแล้ว";
        } else {
            $error = "ไม่สามารถบันทึกหมายเหตุสำหรับรายการนี้ได้";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

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

    $stmt_logs = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND DATE_FORMAT(scan_time, '%Y-%m') = ? ORDER BY scan_time DESC");
    $stmt_logs->execute([$user_id, $filter_month]);
    $logs = $stmt_logs->fetchAll();

    // ดึงบันทึกการลงเวลาของวันนี้
    $stmt_today = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND DATE(scan_time) = CURDATE() ORDER BY scan_time ASC");
    $stmt_today->execute([$user_id]);
    $today_logs = $stmt_today->fetchAll();

    $today_in = null;
    $today_out = null;
    foreach ($today_logs as $tlog) {
        if ($tlog['scan_type'] === 'in' && !$today_in) {
            $today_in = $tlog;
        } elseif ($tlog['scan_type'] === 'out') {
            $today_out = $tlog; // latest out
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1"><i class="fa-solid fa-clock-rotate-left text-purple me-2"></i>บันทึกเวลาของฉัน</h1>
            <p class="text-muted small mb-0">ประวัติการบันทึกเวลาเข้า-ออกงานของคุณ</p>
        </div>
    </div>

    <!-- Quick Attendance Today Status Cards -->
    <div class="row g-3 mb-4">
        <!-- Live Realtime Clock -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 text-white" style="background: linear-gradient(135deg, #4b0082 0%, #6a1b9a 100%);">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <span class="badge bg-white text-purple rounded-pill px-3 py-1 fw-bold extra-small text-uppercase mb-2">เวลาปัจจุบัน</span>
                        <div class="fs-1 fw-bold tracking-tight mb-0" id="liveClockDisplay">--:--:--</div>
                        <div class="opacity-75 small" id="liveDateDisplay">กำลังโหลดวันที่...</div>
                    </div>
                    <div class="mt-3 pt-2 border-top border-white border-opacity-25 extra-small opacity-75">
                        <i class="fa-solid fa-location-dot me-1"></i> IP: <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today Check-in Status -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-success border-4">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="text-muted extra-small fw-bold text-uppercase"><i class="fa-solid fa-arrow-right-to-bracket text-success me-1"></i> เข้างานวันนี้</span>
                            <?php if ($today_in): ?>
                                <h3 class="fw-bold text-success mb-1 mt-2"><?php echo date('H:i:s', strtotime($today_in['scan_time'])); ?> <small class="fs-6 fw-normal text-muted">น.</small></h3>
                                <?php if (date('H:i:s', strtotime($today_in['scan_time'])) > '09:00:00'): ?>
                                    <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1 extra-small">เข้าสาย (> 09:00)</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1 extra-small">ตรงเวลา</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <h4 class="fw-bold text-secondary mb-1 mt-2">ยังไม่บันทึก</h4>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1 extra-small">รอดำเนินการ</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($today_in && $today_in['photo_path']): ?>
                            <a href="javascript:void(0)" onclick="showPhoto('../../uploads/attendance/<?php echo $today_in['photo_path']; ?>')">
                                <img src="../../uploads/attendance/<?php echo $today_in['photo_path']; ?>" class="rounded-3 shadow-sm border" width="60" height="60" style="object-fit: cover;">
                            </a>
                        <?php else: ?>
                            <div class="rounded-3 bg-light text-muted d-flex align-items-center justify-content-center" style="width: 55px; height: 55px;">
                                <i class="fa-solid fa-user-clock fs-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-top extra-small text-muted">
                        <i class="fa-solid fa-desktop me-1"></i> บันทึกเวลาที่จุดลงเวลากลาง
                    </div>
                </div>
            </div>
        </div>

        <!-- Today Check-out Status -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-start border-warning border-4">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="text-muted extra-small fw-bold text-uppercase"><i class="fa-solid fa-arrow-right-from-bracket text-warning me-1"></i> ออกงานวันนี้</span>
                            <?php if ($today_out): ?>
                                <h3 class="fw-bold text-dark mb-1 mt-2"><?php echo date('H:i:s', strtotime($today_out['scan_time'])); ?> <small class="fs-6 fw-normal text-muted">น.</small></h3>
                                <span class="badge bg-warning-subtle text-dark rounded-pill px-2 py-1 extra-small">บันทึกออกงานแล้ว</span>
                            <?php else: ?>
                                <h4 class="fw-bold text-secondary mb-1 mt-2">ยังไม่บันทึก</h4>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1 extra-small">รอดำเนินการ</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($today_out && $today_out['photo_path']): ?>
                            <a href="javascript:void(0)" onclick="showPhoto('../../uploads/attendance/<?php echo $today_out['photo_path']; ?>')">
                                <img src="../../uploads/attendance/<?php echo $today_out['photo_path']; ?>" class="rounded-3 shadow-sm border" width="60" height="60" style="object-fit: cover;">
                            </a>
                        <?php else: ?>
                            <div class="rounded-3 bg-light text-muted d-flex align-items-center justify-content-center" style="width: 55px; height: 55px;">
                                <i class="fa-solid fa-door-open fs-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-top extra-small text-muted">
                        <i class="fa-solid fa-desktop me-1"></i> บันทึกเวลาที่จุดลงเวลากลาง
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert alert-success border-0 rounded-4 shadow-sm animate__animated animate__fadeIn"><i class="fa-solid fa-check-circle me-2"></i><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm animate__animated animate__fadeIn"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row align-items-end g-3">
                <div class="col-md-4">
                    <label class="form-label text-muted fw-bold small">เลือกเดือน</label>
                    <input type="month" name="month" class="form-control form-control-lg bg-light border-0" value="<?php echo htmlspecialchars($filter_month); ?>" onchange="this.form.submit()">
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted extra-small text-uppercase">
                        <tr>
                            <th class="ps-4">วันที่ - เวลา</th>
                            <th class="text-center">ประเภท</th>
                            <th class="text-center">รูปถ่าย</th>
                            <th width="30%">หมายเหตุ (กรณีเข้าสาย)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">ไม่พบประวัติการสแกนในเดือนนี้</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): 
                                $is_late = ($log['scan_type'] === 'in' && date('H:i:s', strtotime($log['scan_time'])) > '09:00:00');
                                $type_badge = $log['scan_type'] === 'in' ? '<span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>เข้างาน</span>' : '<span class="badge bg-secondary rounded-pill px-3 py-2"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i>ออกงาน</span>';
                                if ($is_late) {
                                    $type_badge = '<span class="badge bg-danger rounded-pill px-3 py-2"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>เข้างาน (สาย)</span>';
                                }
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark">
                                    <?php echo date('d/m/Y', strtotime($log['scan_time'])); ?>
                                    <br>
                                    <span class="text-muted small fw-normal"><?php echo date('H:i:s', strtotime($log['scan_time'])); ?> น.</span>
                                </td>
                                <td class="text-center"><?php echo $type_badge; ?></td>
                                <td class="text-center">
                                    <?php if($log['photo_path']): ?>
                                        <a href="javascript:void(0)" onclick="showPhoto('../../uploads/attendance/<?php echo $log['photo_path']; ?>')">
                                            <img src="../../uploads/attendance/<?php echo $log['photo_path']; ?>" class="rounded shadow-sm" width="60" height="45" style="object-fit: cover; border: 2px solid #fff;">
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($is_late): ?>
                                        <?php if(empty($log['remark'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill" onclick="openRemarkModal(<?php echo $log['id']; ?>)">
                                                <i class="fa-solid fa-plus me-1"></i> เพิ่มหมายเหตุ
                                            </button>
                                        <?php else: ?>
                                            <div class="d-flex align-items-start justify-content-between">
                                                <p class="mb-0 small text-danger bg-danger-subtle px-3 py-2 rounded-3 flex-grow-1 me-2"><?php echo htmlspecialchars($log['remark']); ?></p>
                                                <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="openRemarkModal(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars(addslashes($log['remark'])); ?>')">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
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

<!-- Remark Modal -->
<div class="modal fade" id="remarkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-pen me-2 text-danger"></i>ระบุหมายเหตุการเข้างาน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_remark">
                    <input type="hidden" name="log_id" id="modalLogId" value="">
                    
                    <p class="text-muted small mb-3">กรุณาระบุเหตุผลกรณีบันทึกเวลาเข้างานหลัง 09:00 น.</p>
                    <textarea name="remark" id="modalRemark" class="form-control bg-light rounded-3 border-0" rows="3" placeholder="ตัวอย่าง: รถติด, ฝนตกหนัก, หรือ ลืมสแกน..."></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">บันทึกหมายเหตุ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="globalPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 bg-transparent">
            <div class="modal-body text-center p-0 position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" style="z-index: 10;"></button>
                <img src="" id="globalPhotoModalImg" class="img-fluid rounded-4 shadow-lg w-100">
            </div>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .extra-small { font-size: 0.75rem; }
</style>

<script>
    // Live Clock Update
    function updateLiveClock() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('th-TH', { hour12: false });
        const dateStr = now.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        
        const clockEl = document.getElementById('liveClockDisplay');
        const dateEl = document.getElementById('liveDateDisplay');
        
        if (clockEl) clockEl.textContent = timeStr + ' น.';
        if (dateEl) dateEl.textContent = dateStr;
    }
    setInterval(updateLiveClock, 1000);
    updateLiveClock();

    function openRemarkModal(logId, currentRemark = '') {
        document.getElementById('modalLogId').value = logId;
        document.getElementById('modalRemark').value = currentRemark;
        var myModal = new bootstrap.Modal(document.getElementById('remarkModal'));
        myModal.show();
    }
    
    function showPhoto(imgSrc) {
        document.getElementById('globalPhotoModalImg').src = imgSrc;
        var photoModal = new bootstrap.Modal(document.getElementById('globalPhotoModal'));
        photoModal.show();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
