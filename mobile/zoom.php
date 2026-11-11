<?php
require_once 'auth_check.php';

$msg = '';
$msg_type = '';

// ดึงรายชื่อ Zoom Accounts
try {
    $stmt_acc = $pdo->query("SELECT * FROM zoom_accounts ORDER BY id ASC");
    $accounts = $stmt_acc->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

// จัดการจองห้อง Zoom
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book') {
    $account_id = intval($_POST['zoom_account_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $date = $_POST['booking_date'] ?? '';
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    $start_datetime = $date . ' ' . $start;
    $end_datetime = $date . ' ' . $end;

    if (empty($title) || empty($date) || empty($start) || empty($end) || $account_id <= 0) {
        $msg = "กรุณากรอกข้อมูลให้ครบถ้วน";
        $msg_type = "danger";
    } elseif (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $msg = "เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น";
        $msg_type = "danger";
    } elseif (strtotime($start_datetime) < time()) {
        $msg = "ไม่สามารถจองเวลาย้อนหลังได้";
        $msg_type = "danger";
    } else {
        try {
            // Overlap check
            $stmt_check = $pdo->prepare("
                SELECT COUNT(*) FROM zoom_bookings 
                WHERE zoom_account_id = ? 
                AND start_time < ? 
                AND end_time > ?
            ");
            $stmt_check->execute([$account_id, $end_datetime, $start_datetime]);
            $overlap_count = $stmt_check->fetchColumn();

            if ($overlap_count > 0) {
                $msg = "ช่วงเวลาดังกล่าวมีผู้ใช้งานห้องนี้แล้ว กรุณาเลือกเวลาอื่น";
                $msg_type = "danger";
            } else {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO zoom_bookings (zoom_account_id, user_id, title, start_time, end_time) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt_insert->execute([$account_id, $user_id, $title, $start_datetime, $end_datetime]);
                $msg = "จองห้อง Zoom เรียบร้อยแล้ว";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// จัดการยกเลิกการจอง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $is_admin = hasRole(['admin']);

    try {
        $stmt_del = $pdo->prepare("DELETE FROM zoom_bookings WHERE id = ? AND (user_id = ? OR ?)");
        $stmt_del->execute([$booking_id, $user_id, $is_admin ? 1 : 0]);
        $msg = "ยกเลิกการจองห้อง Zoom เรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงรายการจอง Zoom ที่กำลังจะมาถึง
try {
    $stmt_bookings = $pdo->prepare("
        SELECT b.*, a.account_name, a.meeting_id, a.passcode, a.meeting_url,
               u.first_name, u.last_name
        FROM zoom_bookings b
        JOIN zoom_accounts a ON b.zoom_account_id = a.id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.end_time >= NOW()
        ORDER BY b.start_time ASC
    ");
    $stmt_bookings->execute();
    $upcoming_bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcoming_bookings = [];
}

require_once 'includes/header.php';
?>

<div class="page-container pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3 pt-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">จองห้องประชุม Zoom</h5>
                <small class="text-muted">ระบบจองห้องประชุมออนไลน์ของคณะ</small>
            </div>
        </div>
        <button class="btn btn-sm btn-primary rounded-pill px-3 fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#newZoomBooking">
            <i class="fa-solid fa-plus me-1"></i> จอง Zoom
        </button>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid <?= $msg_type==='success'?'fa-circle-check':'fa-triangle-exclamation' ?> me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Collapsible New Booking Form -->
    <div class="collapse mb-3" id="newZoomBooking">
        <div class="bento-box p-3 border-purple">
            <h6 class="fw-bold text-purple mb-3">
                <i class="fa-solid fa-video me-1"></i> ฟอร์มจองห้อง Zoom
            </h6>
            <form method="POST" action="zoom.php">
                <input type="hidden" name="action" value="book">
                
                <div class="mb-2">
                    <label class="form-label extra-small fw-bold text-muted mb-1">เลือกบัญชี Zoom <span class="text-danger">*</span></label>
                    <select name="zoom_account_id" class="form-select form-select-sm rounded-3" required>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (<?= htmlspecialchars($acc['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label extra-small fw-bold text-muted mb-1">หัวข้อการประชุม / รายวิชา <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control form-control-sm rounded-3" placeholder="เช่น การประชุมกรรมการบริหาร..." required>
                </div>

                <div class="mb-2">
                    <label class="form-label extra-small fw-bold text-muted mb-1">วันที่ <span class="text-danger">*</span></label>
                    <input type="date" name="booking_date" class="form-control form-control-sm rounded-3" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label extra-small fw-bold text-muted mb-1">เวลาเริ่ม <span class="text-danger">*</span></label>
                        <input type="time" name="start_time" class="form-control form-control-sm rounded-3" value="09:00" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label extra-small fw-bold text-muted mb-1">เวลาสิ้นสุด <span class="text-danger">*</span></label>
                        <input type="time" name="end_time" class="form-control form-control-sm rounded-3" value="12:00" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100 rounded-pill fw-bold py-2">
                    <i class="fa-solid fa-check me-1"></i> ยืนยันการจองห้อง
                </button>
            </form>
        </div>
    </div>

    <!-- Available Zoom Accounts Info -->
    <div class="row g-2 mb-3">
        <?php foreach ($accounts as $acc): ?>
            <div class="col-6">
                <div class="bento-box p-3 h-100 position-relative overflow-hidden">
                    <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small mb-1">
                        <?= htmlspecialchars($acc['account_name']) ?>
                    </span>
                    <div class="fw-bold small text-dark text-truncate mb-1"><?= htmlspecialchars($acc['email']) ?></div>
                    <div class="extra-small text-muted mb-2">
                        Meeting ID: <?= htmlspecialchars($acc['meeting_id'] ?: '-') ?><br>
                        Passcode: <?= htmlspecialchars($acc['passcode'] ?: '-') ?>
                    </div>
                    <?php if (!empty($acc['meeting_url'])): ?>
                        <a href="<?= htmlspecialchars($acc['meeting_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill extra-small w-100 py-1">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> ลิงก์ห้อง
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Upcoming Zoom Bookings List -->
    <h6 class="fw-bold text-dark mb-2 px-1 d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-calendar-check text-purple me-2"></i>คิวการจองห้อง Zoom เร็วๆ นี้</span>
        <span class="badge bg-purple-subtle text-purple"><?= count($upcoming_bookings) ?> รายการ</span>
    </h6>

    <?php if (empty($upcoming_bookings)): ?>
        <div class="bento-box text-center py-5">
            <div class="avatar-circle mx-auto mb-3" style="width: 60px; height: 60px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-solid fa-video-slash fa-2x"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">ไม่มีคิวจอง Zoom ในขณะนี้</h6>
            <p class="text-muted small mb-0">คุณสามารถกดปุ่ม "จอง Zoom" ด้านบนเพื่อเปิดห้องประชุมออนไลน์ได้ทันที</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($upcoming_bookings as $b): 
                $start_ts = strtotime($b['start_time']);
                $end_ts = strtotime($b['end_time']);
                $is_mine = ($b['user_id'] === $user_id || hasRole(['admin']));
            ?>
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small">
                            <i class="fa-solid fa-video me-1"></i> <?= htmlspecialchars($b['account_name']) ?>
                        </span>
                        <span class="extra-small text-muted"><?= date('d/m/Y', $start_ts) ?></span>
                    </div>

                    <h6 class="fw-bold text-dark mb-1 line-clamp-2">
                        <?= htmlspecialchars($b['title']) ?>
                    </h6>

                    <div class="extra-small text-muted mb-2">
                        <i class="fa-regular fa-clock text-purple me-1"></i> <?= date('H:i', $start_ts) ?> - <?= date('H:i', $end_ts) ?> น. 
                        | ผู้จอง: <?= htmlspecialchars(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'ผู้ใช้งาน' ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <?php if (!empty($b['meeting_url'])): ?>
                            <a href="<?= htmlspecialchars($b['meeting_url']) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill extra-small px-3 py-1 fw-bold">
                                <i class="fa-solid fa-right-to-bracket me-1"></i> เข้าห้องประชุม
                            </a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>

                        <?php if ($is_mine): ?>
                            <form method="POST" action="zoom.php" class="d-inline" onsubmit="return confirm('ยืนยันการยกเลิกการจองห้อง Zoom นี้?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill extra-small px-3 py-1 fw-bold">
                                    <i class="fa-solid fa-trash me-1"></i> ยกเลิก
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
