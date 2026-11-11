<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// -------------------------------------------------------------
// 1. วันที่ภาษาไทย (แสดงจุดเดียวที่ Header)
// -------------------------------------------------------------
$thai_days = [
    'Sunday' => 'อาทิตย์', 'Monday' => 'จันทร์', 'Tuesday' => 'อังคาร', 
    'Wednesday' => 'พุธ', 'Thursday' => 'พฤหัสบดี', 'Friday' => 'ศุกร์', 'Saturday' => 'เสาร์'
];
$thai_months = [
    1=>'ม.ค.', 2=>'ก.พ.', 3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.', 6=>'มิ.ย.', 
    7=>'ก.ค.', 8=>'ส.ค.', 9=>'ก.ย.', 10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.'
];
$thai_full_months = [
    1=>'มกราคม', 2=>'กุมภาพันธ์', 3=>'มีนาคม', 4=>'เมษายน', 5=>'พฤษภาคม', 6=>'มิถุนายน', 
    7=>'กรกฎาคม', 8=>'สิงหาคม', 9=>'กันยายน', 10=>'ตุลาคม', 11=>'พฤศจิกายน', 12=>'ธันวาคม'
];

$cur_day_en = date('l');
$cur_day_num = date('j');
$cur_month_num = (int)date('n');
$cur_year_th = (int)date('Y') + 543;

$date_thai_header = "วัน" . ($thai_days[$cur_day_en] ?? '') . "ที่ " . $cur_day_num . " " . ($thai_months[$cur_month_num] ?? '') . " " . $cur_year_th;

// -------------------------------------------------------------
// 2. ดึงสถานะการลงเวลาปฏิบัติงานวันนี้ (Attendance Pulse)
// -------------------------------------------------------------
$today_scan_in = null;
try {
    $stmt_att = $pdo->prepare("SELECT scan_time FROM attendance_logs WHERE user_id = ? AND scan_type = 'in' AND DATE(scan_time) = CURDATE() ORDER BY scan_time ASC LIMIT 1");
    $stmt_att->execute([$user_id]);
    $today_scan_in = $stmt_att->fetchColumn();
} catch(Exception $e) {}

// -------------------------------------------------------------
// 3. ดึงยอดงานรออนุมัติทุกส่วนงาน (Universal Approvals)
// -------------------------------------------------------------
$leave_pending = 0;
$room_pending = 0;
$repair_pending = 0;
$supply_pending = 0;
$course_pending = 0;

try {
    $stmt_l = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
    $leave_pending = (int)$stmt_l->fetchColumn();

    $stmt_r = $pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'");
    $room_pending = (int)$stmt_r->fetchColumn();

    $stmt_rp = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'pending'");
    $repair_pending = (int)$stmt_rp->fetchColumn();

    $stmt_s = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE head_status = 'pending' OR supply_status = 'pending'");
    $supply_pending = (int)$stmt_s->fetchColumn();

    $stmt_c = $pdo->query("SELECT COUNT(*) FROM course_requests WHERE status LIKE 'pending%'");
    $course_pending = (int)$stmt_c->fetchColumn();
} catch(Exception $e) {}

$total_pending = $leave_pending + $room_pending + $repair_pending + $supply_pending + $course_pending;

// -------------------------------------------------------------
// 4. ดึงสิทธิ์และสถานะการลาล่าสุดของผู้ใช้งาน
// -------------------------------------------------------------
$my_recent_leave = null;
$my_leave_count = 0;
try {
    $stmt_ml = $pdo->prepare("SELECT l.*, t.type_name FROM leave_requests l JOIN leave_types t ON l.type_id = t.type_id WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 1");
    $stmt_ml->execute([$user_id]);
    $my_recent_leave = $stmt_ml->fetch(PDO::FETCH_ASSOC);

    $stmt_mc = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND YEAR(start_date) = YEAR(CURDATE())");
    $stmt_mc->execute([$user_id]);
    $my_leave_count = (int)$stmt_mc->fetchColumn();
} catch(Exception $e) {}

// -------------------------------------------------------------
// 5. ดึงสถานะห้อง การประชุม และสิ่งอำนวยความสะดวก
// -------------------------------------------------------------
$active_practice_rooms = 8;
$total_rooms = 4;
$upcoming_meetings = 0;
$today_meeting = null;

try {
    $stmt_pr = $pdo->query("SELECT COUNT(*) FROM practice_rooms WHERE is_active = 1");
    $active_practice_rooms = (int)$stmt_pr->fetchColumn();

    $stmt_rm = $pdo->query("SELECT COUNT(*) FROM rooms");
    $total_rooms = (int)$stmt_rm->fetchColumn();

    $stmt_m = $pdo->query("SELECT COUNT(*) FROM meetings WHERE (meeting_date >= CURDATE() AND status NOT IN ('completed', 'cancelled')) OR status = 'in_progress'");
    $upcoming_meetings = (int)$stmt_m->fetchColumn();

    // ดึงการประชุมสำคัญรอบถัดไป
    $stmt_tm = $pdo->query("SELECT title, meeting_no, meeting_date, start_time, end_time, location FROM meetings WHERE ((meeting_date >= CURDATE() AND status NOT IN ('completed', 'cancelled')) OR status = 'in_progress') ORDER BY meeting_date ASC, start_time ASC LIMIT 1");
    $today_meeting = $stmt_tm->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$is_executive_user = hasRole(['admin', 'executive', 'head', 'คณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'หัวหน้าสำนักงาน']);
$role_display = $_SESSION['roles'][0] ?? 'บุคลากร';
?>

<style>
body {
    padding-top: 0 !important;
}

/* Unified Hero Header (Merged with Topbar) */
.unified-hero-header {
    background: linear-gradient(140deg, #24005e 0%, #39048f 55%, #5e18d6 100%);
    padding: calc(16px + env(safe-area-inset-top)) 18px 22px 18px;
    border-bottom-left-radius: 28px;
    border-bottom-right-radius: 28px;
    box-shadow: 0 12px 32px -4px rgba(57, 4, 143, 0.35);
    position: relative;
    overflow: hidden;
    color: #ffffff;
}
.unified-hero-header::before {
    content: '';
    position: absolute;
    top: -60px;
    right: -50px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.16) 0%, transparent 70%);
    pointer-events: none;
}
.unified-hero-header::after {
    content: '';
    position: absolute;
    bottom: -50px;
    left: -40px;
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(180, 138, 30, 0.2) 0%, transparent 70%);
    pointer-events: none;
}

/* Hero Typography & Comfortable Spacing */
.hero-greeting-sub {
    font-size: 0.8rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.82);
    margin-bottom: 8px;
    line-height: 1.5;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.hero-role-pill {
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.22);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 99px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.hero-user-name {
    font-size: 1.32rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.45;
    letter-spacing: -0.01em;
    margin-bottom: 0;
}
.hero-date-badge {
    background: rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.24);
    color: #ffffff;
    font-size: 0.74rem;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 99px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    line-height: 1.4;
}
.hero-attendance-box {
    background: rgba(0, 0, 0, 0.22);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 18px;
    padding: 13px 16px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.hero-attendance-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.25rem;
}
.hero-attendance-label {
    font-size: 0.72rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.76);
    margin-bottom: 5px;
    line-height: 1.4;
}
.hero-attendance-value {
    font-size: 0.98rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.4;
    letter-spacing: 0.01em;
}
.hero-action-btn-white {
    flex: 1;
    background: #ffffff;
    color: var(--primary, #39048f) !important;
    font-weight: 700;
    font-size: 0.82rem;
    border-radius: 14px;
    padding: 11px 14px;
    text-align: center;
    text-decoration: none !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
    line-height: 1.4;
    transition: transform 0.15s ease, background 0.15s ease;
}
.hero-action-btn-white:active {
    transform: scale(0.97);
    background: #f8fafc;
}
.hero-action-btn-glass {
    flex: 1;
    background: rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(10px);
    color: #ffffff !important;
    font-weight: 600;
    font-size: 0.82rem;
    border-radius: 14px;
    padding: 11px 14px;
    text-align: center;
    text-decoration: none !important;
    border: 1px solid rgba(255, 255, 255, 0.25);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    white-space: nowrap;
    line-height: 1.4;
    transition: transform 0.15s ease, background 0.15s ease;
}
.hero-action-btn-glass:active {
    transform: scale(0.97);
    background: rgba(255, 255, 255, 0.25);
}

/* 3-Column Modern App Grid Styling */
.app-grid-v3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px 10px;
    margin-bottom: 16px;
}
.service-item-compact {
    text-decoration: none !important;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    padding: 6px 4px;
    border-radius: 16px;
    transition: transform 0.15s ease;
}
.service-item-compact:active {
    transform: scale(0.93);
}
.service-icon-box-v3 {
    width: 58px;
    height: 58px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.42rem;
    margin-bottom: 8px;
    background: #ffffff;
    color: var(--msu-primary, #39048f);
    border: 1px solid rgba(0, 0, 0, 0.06);
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05);
    position: relative;
    transition: all 0.2s ease;
}
[data-bs-theme="dark"] .service-icon-box-v3 {
    background: #1e293b;
    border-color: rgba(255, 255, 255, 0.08);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    color: #c084fc;
}
.service-item-compact:hover .service-icon-box-v3 {
    border-color: rgba(57, 4, 143, 0.25);
    box-shadow: 0 4px 14px rgba(57, 4, 143, 0.12);
}
.service-title-v3 {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--slate-800, #1e293b);
    line-height: 1.35;
    max-width: 96px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
[data-bs-theme="dark"] .service-title-v3 {
    color: #cbd5e1;
}
.service-badge-v3 {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ef4444;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    min-width: 19px;
    height: 19px;
    border-radius: 99px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.35);
}
[data-bs-theme="dark"] .service-badge-v3 {
    border-color: #1e293b;
}

.toggle-apps-btn {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 99px;
    padding: 8px 16px;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--msu-primary, #39048f);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.2s ease;
    cursor: pointer;
}
[data-bs-theme="dark"] .toggle-apps-btn {
    background: #1e293b;
    border-color: #334155;
    color: #c084fc;
}
.toggle-apps-btn:active {
    transform: scale(0.98);
    background: #f8fafc;
}
</style>

<div>
    <!-- 1. UNIFIED TOPBAR & COMMAND HERO MASTER HEADER -->
    <div class="unified-hero-header">
        <div class="position-relative z-1">
            
            <!-- Row 1: Brand & Actions (Logo + MSU Connect + Inbox + Avatar) -->
            <div class="d-flex justify-content-between align-items-center pt-1" style="margin-bottom: 20px;">
                <a class="d-inline-flex align-items-center gap-2 text-decoration-none min-w-0 overflow-hidden" href="index.php">
                    <img src="../uploads/logo.png" alt="Logo" style="width: 32px; height: 32px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.25)); flex-shrink: 0;">
                    <div class="d-flex flex-column text-truncate">
                        <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                            <span class="text-white fw-bold text-truncate" style="font-size: 1.05rem; letter-spacing: -0.01em;">Music MSU Connect</span>
                            <span class="badge rounded-pill flex-shrink-0" style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(4px); font-size: 0.62rem; padding: 2px 7px; font-weight: 700; color: #ffffff;">ERP 2.0</span>
                        </div>
                    </div>
                </a>

                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <!-- Inbox Button with Pending Badge -->
                    <a href="approvals.php" class="rounded-circle d-flex align-items-center justify-content-center text-white position-relative" style="width: 38px; height: 38px; background: rgba(255, 255, 255, 0.16); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.22); text-decoration: none;" title="ศูนย์อนุมัติคำร้อง">
                        <i class="fa-solid fa-inbox" style="font-size: 0.95rem;"></i>
                        <?php if ($total_pending > 0): ?>
                            <span class="badge bg-danger rounded-pill position-absolute" style="top: -4px; right: -4px; font-size: 0.6rem; padding: 2px 5px; border: 2px solid #39048f;">
                                <?= $total_pending > 99 ? '99+' : $total_pending ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- User Profile Avatar & Dropdown -->
                    <div class="dropdown" hx-disable style="position: relative;">
                        <button type="button" class="btn p-0 border-0 bg-transparent shadow-none d-block position-relative" onclick="toggleUserDropdown(event)" id="mobileUserMenuBtn" aria-label="User Menu" style="outline: none; -webkit-tap-highlight-color: transparent;">
                            <?php if (!empty($current_avatar)): ?>
                                <?php $avatar_url = (strpos($current_avatar, 'http') === 0) ? $current_avatar : '../uploads/avatars/' . $current_avatar; ?>
                                <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Profile" class="rounded-circle shadow-sm" width="38" height="38" style="border: 2px solid #ffffff; object-fit: cover;">
                            <?php else: ?>
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($full_name) ?>&background=ffffff&color=39048f" alt="Profile" class="rounded-circle shadow-sm" width="38" height="38" style="border: 2px solid #ffffff;">
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" id="mobileUserDropdownMenu" style="border-radius: 16px; min-width: 245px; border: 1px solid rgba(57, 4, 143, 0.12) !important; z-index: 1060; box-shadow: 0 14px 35px -4px rgba(15, 23, 42, 0.22) !important;">
                            <li class="px-3 py-2 bg-light rounded-3 mb-2 d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: var(--primary);">
                                    <i class="fa-solid fa-user small"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <div class="fw-bold text-dark small text-truncate"><?= htmlspecialchars($full_name) ?></div>
                                    <div class="text-muted extra-small text-truncate"><?= htmlspecialchars($role_display) ?></div>
                                </div>
                            </li>
                            <li>
                                <a class="dropdown-item rounded-2 py-2 small fw-semibold" href="profile.php" hx-boost="true">
                                    <i class="fa-solid fa-id-card me-2 text-purple"></i> ข้อมูลโปรไฟล์
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item rounded-2 py-2 small fw-semibold" href="settings.php" hx-boost="true">
                                    <i class="fa-solid fa-sliders me-2 text-purple"></i> ตั้งค่าการใช้งาน
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item rounded-2 py-2 small fw-semibold text-primary" href="../index.php?pc=1" hx-disable>
                                    <i class="fa-solid fa-desktop me-2 text-primary"></i> สลับไปเวอร์ชัน PC (Desktop)
                                </a>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item rounded-2 py-2 small fw-bold text-danger" href="../auth/logout.php?from=mobile" hx-disable>
                                    <i class="fa-solid fa-power-off me-2 text-danger"></i> ออกจากระบบ (Logout)
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Row 2: Greeting + Name on left, Single Date Badge on right -->
            <div class="d-flex align-items-start justify-content-between gap-3 pt-1" style="margin-bottom: 16px;">
                <div class="overflow-hidden min-w-0 flex-grow-1">
                    <div class="hero-greeting-sub">
                        <span>👋 สวัสดีตอนเช้า</span>
                        <span class="hero-role-pill">
                            <i class="fa-solid fa-shield-halved" style="font-size: 0.6rem;"></i>
                            <span><?= htmlspecialchars($role_display) ?></span>
                        </span>
                    </div>
                    <h5 class="hero-user-name text-truncate">
                        <?= htmlspecialchars($full_name) ?>
                    </h5>
                </div>

                <!-- Single Clean Thai Date Pill -->
                <div class="flex-shrink-0 text-end">
                    <span class="hero-date-badge">
                        <i class="fa-regular fa-calendar-check" style="font-size: 0.76rem;"></i>
                        <span><?= $date_thai_header ?></span>
                    </span>
                </div>
            </div>

            <!-- Row 3: Attendance Time & Location Strip -->
            <div class="hero-attendance-box">
                <div class="d-flex align-items-center gap-3 overflow-hidden">
                    <div class="hero-attendance-icon flex-shrink-0">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                    <div class="overflow-hidden">
                        <div class="hero-attendance-label text-truncate">เวลาปฏิบัติงานวันนี้ (สแกนกลาง)</div>
                        <div class="hero-attendance-value text-truncate">
                            <?php if ($today_scan_in): ?>
                                เข้างาน: <?= date('H:i', strtotime($today_scan_in)) ?> น.
                            <?php else: ?>
                                ยังไม่ได้สแกนเข้างาน
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <span class="hero-status-pill flex-shrink-0" style="padding: 5px 12px; font-size: 0.72rem;">
                    <?php if ($today_scan_in): ?>
                        <i class="fa-solid fa-circle text-success" style="font-size: 0.45rem;"></i> บันทึกแล้ว
                    <?php else: ?>
                        <i class="fa-solid fa-circle text-warning" style="font-size: 0.45rem;"></i> จุดบริการกลาง
                    <?php endif; ?>
                </span>
            </div>

            <!-- Row 4: Quick Action Buttons -->
            <div class="d-flex align-items-center gap-2.5">
                <a href="worklog_form.php" class="hero-action-btn-white" hx-boost="true">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>เขียน Daily Report</span>
                </a>
                <a href="attendance_history.php" class="hero-action-btn-glass" hx-boost="true">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>ดูประวัติเวลา</span>
                </a>
            </div>
        </div>
    </div>

    <!-- MAIN BODY CONTENT (Below Unified Header) -->
    <div class="px-3 pt-3">
        <!-- 2. DUAL BENTO WIDGETS (APPROVALS & LEAVE BALANCE) -->
        <div class="bento-dual-grid mb-3">
        <!-- Widget 1: Universal Approvals -->
        <a href="approvals.php" class="bento-dual-card" hx-boost="true">
            <div>
                <div class="card-tag-top text-purple mb-1">
                    <i class="fa-solid fa-bolt text-warning"></i>
                    <span>งานรออนุมัติ</span>
                </div>
                <div class="card-counter-large">
                    <?= $total_pending ?>
                    <span class="text-muted fw-normal" style="font-size: 0.78rem;">รายการ</span>
                </div>
                <div class="card-mini-breakdown">
                    <?php if($leave_pending > 0): ?>
                        <span class="card-mini-chip">ใบลา <?= $leave_pending ?></span>
                    <?php endif; ?>
                    <?php if($course_pending > 0): ?>
                        <span class="card-mini-chip">วิชาการ <?= $course_pending ?></span>
                    <?php endif; ?>
                    <?php if($supply_pending > 0): ?>
                        <span class="card-mini-chip">พัสดุ <?= $supply_pending ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-link-action text-purple">
                <span>เปิดศูนย์อนุมัติ</span>
                <i class="fa-solid fa-arrow-right"></i>
            </div>
        </a>

        <!-- Widget 2: My Leave Balance -->
        <a href="leave.php" class="bento-dual-card" hx-boost="true">
            <div>
                <div class="card-tag-top text-success mb-1">
                    <i class="fa-solid fa-umbrella-beach"></i>
                    <span>การลาของฉัน</span>
                </div>
                <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.2;">
                    <?php if($my_recent_leave): ?>
                        <?= htmlspecialchars($my_recent_leave['type_name']) ?>
                    <?php else: ?>
                        สิทธิ์ลาพักผ่อน
                    <?php endif; ?>
                </div>
                <div class="bento-progress mt-2">
                    <div class="bento-progress-fill" style="width: 70%; background: var(--primary-gradient);"></div>
                </div>
                <div class="extra-small text-muted mt-1" style="font-size: 0.65rem;">
                    <?php if($my_recent_leave): ?>
                        สถานะ: <?= ($my_recent_leave['status'] == 'approved') ? '<span class="text-success fw-bold">อนุมัติแล้ว 🟢</span>' : '<span class="text-warning fw-bold">รอพิจารณา 🟡</span>' ?>
                    <?php else: ?>
                        คงเหลือ 10 วันทำการ
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-link-action text-success">
                <span>+ ยื่นใบลาใหม่</span>
                <i class="fa-solid fa-arrow-right"></i>
            </div>
        </a>
    </div>

    <!-- 3. PRIORITY AGENDA / UPCOMING MEETING (If any) -->
    <?php if($today_meeting): ?>
    <div class="agenda-card mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="agenda-pill-time">
                <i class="fa-regular fa-clock"></i> <?= date('H:i', strtotime($today_meeting['start_time'])) ?> น.
            </span>
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill extra-small">
                <i class="fa-solid fa-circle-dot me-1 text-success"></i>วาระสำคัญ
            </span>
        </div>
        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;">
            <?= htmlspecialchars($today_meeting['title'] . ($today_meeting['meeting_no'] ? ' (' . $today_meeting['meeting_no'] . ')' : '')) ?>
        </h6>
        <div class="text-muted extra-small mb-2">
            <i class="fa-solid fa-location-dot me-1 text-secondary"></i> <?= htmlspecialchars($today_meeting['location'] ?: 'ห้องประชุมวิทยาลัยฯ') ?>
        </div>
        <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
            <span class="extra-small text-muted"><i class="fa-solid fa-calendar-day me-1"></i><?= date('d/m/Y', strtotime($today_meeting['meeting_date'])) ?></span>
            <a href="meetings.php" class="btn btn-sm btn-outline-primary rounded-pill extra-small fw-bold px-3" hx-boost="true">
                <i class="fa-solid fa-file-lines me-1"></i>ดูการประชุม
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4. CATEGORY FILTER PILL BAR -->
    <div class="category-pill-bar mb-3">
        <button class="cat-pill active" onclick="filterServices('all', this)">
            <i class="fa-solid fa-shapes"></i>
            <span>ทั้งหมด</span>
        </button>
        <button class="cat-pill" onclick="filterServices('staff', this)">
            <i class="fa-solid fa-id-badge"></i>
            <span>บริการบุคลากร</span>
        </button>
        <button class="cat-pill" onclick="filterServices('academic', this)">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>วิชาการ & ประชุม</span>
        </button>
        <?php if($is_executive_user): ?>
        <button class="cat-pill" onclick="filterServices('executive', this)">
            <i class="fa-solid fa-shield-halved"></i>
            <span>ศูนย์ผู้บริหาร</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- 5. SMART 3-COLUMN APPS GRID (Top 9 Default + Expandable) -->
    <div class="app-grid-v3" id="servicesGrid">
        
        <!-- [PRIMARY TOP 8] 1. Daily Report (บันทึกการปฏิบัติงาน) -->
        <a href="worklog_form.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
            <span class="service-title-v3">Daily Report</span>
        </a>

        <!-- [PRIMARY TOP 8] 2. ระบบการลา -->
        <a href="leave.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-calendar-minus"></i>
            </div>
            <span class="service-title-v3">ระบบการลา</span>
        </a>

        <!-- [PRIMARY TOP 8] 3. จองห้องซ้อมดนตรี -->
        <a href="practice_rooms.php" class="service-item-compact is-primary" data-cat="academic" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-music"></i>
                <?php if ($active_practice_rooms > 0): ?>
                    <span class="service-badge-v3" style="background: #10b981;"><?= $active_practice_rooms ?></span>
                <?php endif; ?>
            </div>
            <span class="service-title-v3">จองห้องซ้อม</span>
        </a>

        <!-- [PRIMARY TOP 8] 4. จองห้องประชุม / สถานที่ -->
        <a href="rooms.php" class="service-item-compact is-primary" data-cat="academic" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-door-open"></i>
            </div>
            <span class="service-title-v3">จองห้องประชุม</span>
        </a>

        <!-- [PRIMARY TOP 8] 5. ขอไปราชการ / อบรม -->
        <a href="seminars.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-plane-departure"></i>
            </div>
            <span class="service-title-v3">ไปราชการ</span>
        </a>

        <!-- [PRIMARY TOP 8] 6. เบิกพัสดุสำนักงาน -->
        <a href="supplies.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <span class="service-title-v3">เบิกพัสดุ</span>
        </a>

        <!-- [PRIMARY TOP 8] 7. แจ้งซ่อมบำรุง -->
        <a href="repairs.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>
            <span class="service-title-v3">แจ้งซ่อมบำรุง</span>
        </a>

        <!-- [PRIMARY TOP 9] 8. ระบบการประชุม (Meetings) -->
        <a href="meetings.php" class="service-item-compact is-primary" data-cat="academic" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-handshake"></i>
                <?php if ($upcoming_meetings > 0): ?>
                    <span class="service-badge-v3"><?= $upcoming_meetings ?></span>
                <?php endif; ?>
            </div>
            <span class="service-title-v3">ระบบประชุม</span>
        </a>

        <!-- [PRIMARY TOP 9] 9. สลิปและการเงิน -->
        <a href="finance.php" class="service-item-compact is-primary" data-cat="staff" hx-boost="true">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <span class="service-title-v3">สลิปการเงิน</span>
        </a>

        <!-- [SECONDARY APPS] 10. ประวัติและผลงาน (CV) -->
        <a href="cv.php" class="service-item-compact is-secondary" data-cat="staff" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-file-invoice"></i>
            </div>
            <span class="service-title-v3">ประวัติ/CV</span>
        </a>

        <!-- [SECONDARY APPS] 11. คำร้องวิชาการนิสิต -->
        <a href="approvals.php#academic" class="service-item-compact is-secondary" data-cat="academic" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-graduation-cap"></i>
                <?php if ($course_pending > 0): ?>
                    <span class="service-badge-v3"><?= $course_pending ?></span>
                <?php endif; ?>
            </div>
            <span class="service-title-v3">คำร้องวิชาการ</span>
        </a>

        <!-- [SECONDARY APPS] 12. แหล่งเรียนรู้ (KM) -->
        <a href="km.php" class="service-item-compact is-secondary" data-cat="academic" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-brain"></i>
            </div>
            <span class="service-title-v3">แหล่งเรียนรู้</span>
        </a>

        <!-- [SECONDARY APPS] 13. จองห้อง Zoom ออนไลน์ -->
        <a href="zoom.php" class="service-item-compact is-secondary" data-cat="staff" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-video"></i>
            </div>
            <span class="service-title-v3">จองห้อง Zoom</span>
        </a>

        <!-- [SECONDARY APPS] 14. ประวัติการลงเวลาเข้างาน -->
        <a href="attendance_history.php" class="service-item-compact is-secondary" data-cat="staff" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <span class="service-title-v3">ประวัติเวลา</span>
        </a>

        <!-- [SECONDARY APPS] 15. สายตรงคณบดี / ร้องเรียน เสนอแนะ -->
        <a href="complaint.php" class="service-item-compact is-secondary" data-cat="staff" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <span class="service-title-v3">สายตรงคณบดี</span>
        </a>

        <!-- [SECONDARY APPS] 16. ศูนย์อนุมัติคำร้องรวม (สำหรับผู้บริหาร) -->
        <?php if($is_executive_user): ?>
        <a href="approvals.php" class="service-item-compact is-secondary" data-cat="executive" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3" style="background: rgba(57, 4, 143, 0.1); color: var(--msu-primary, #39048f);">
                <i class="fa-solid fa-stamp"></i>
                <?php if($total_pending > 0): ?>
                    <span class="service-badge-v3"><?= $total_pending ?></span>
                <?php endif; ?>
            </div>
            <span class="service-title-v3">อนุมัติคำร้อง</span>
        </a>

        <!-- [SECONDARY APPS] 17. ส่งแจ้งเตือนนิสิต -->
        <a href="notifications.php" class="service-item-compact is-secondary" data-cat="executive" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
            <span class="service-title-v3">แจ้งเตือนนิสิต</span>
        </a>

        <!-- [SECONDARY APPS] 18. ติดตามโครงการยุทธศาสตร์ -->
        <a href="projects.php" class="service-item-compact is-secondary" data-cat="executive" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-diagram-project"></i>
            </div>
            <span class="service-title-v3">ติดตามโครงการ</span>
        </a>

        <!-- [SECONDARY APPS] 19. ติดตามงานจัดซื้อจัดจ้าง -->
        <a href="procurement.php" class="service-item-compact is-secondary" data-cat="executive" hx-boost="true" style="display: none;">
            <div class="service-icon-box-v3">
                <i class="fa-solid fa-truck-ramp-box"></i>
            </div>
            <span class="service-title-v3">ติดตามพัสดุ</span>
        </a>
        <?php endif; ?>

    </div>

    <!-- Toggle Expand / Collapse Button -->
    <div class="mb-4" id="toggleAppsWrap">
        <button type="button" class="toggle-apps-btn" id="toggleAllAppsBtn" onclick="toggleExpandApps()">
            <span id="toggleAppsText">ดูบริการทั้งหมด (<?= $is_executive_user ? 19 : 15 ?> รายการ)</span>
            <i id="toggleAppsIcon" class="fa-solid fa-chevron-down ms-1" style="font-size: 0.72rem;"></i>
        </button>
    </div>

    <!-- Switch to Desktop PC View Option -->
    <div class="text-center my-3 pb-1">
        <a href="../index.php?pc=1" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 extra-small fw-semibold shadow-none border" style="background: rgba(255,255,255,0.7); color: #475569;" hx-disable>
            <i class="fa-solid fa-desktop me-2 text-primary"></i> สลับไปเวอร์ชัน PC (Desktop)
        </a>
    </div>
    <!-- Floating Action Button (Quick Daily Report) -->
    <a href="worklog_form.php" class="mobile-fab" title="เขียนบันทึกงานใหม่" hx-boost="true">
        <i class="fa-solid fa-plus"></i>
    </a>

    </div> <!-- End px-3 pt-3 -->
</div> <!-- End Outer -->

<script>
let isAppsExpanded = false;
let currentFilterCategory = 'all';

function toggleExpandApps() {
    isAppsExpanded = !isAppsExpanded;
    updateAppVisibility();
}

function filterServices(category, btn) {
    currentFilterCategory = category;
    
    // Update active pill styling
    document.querySelectorAll('.cat-pill').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');

    updateAppVisibility();
}

function updateAppVisibility() {
    const primaryCards = document.querySelectorAll('.service-item-compact.is-primary');
    const secondaryCards = document.querySelectorAll('.service-item-compact.is-secondary');
    const toggleWrap = document.getElementById('toggleAppsWrap');
    const toggleBtnText = document.getElementById('toggleAppsText');
    const toggleBtnIcon = document.getElementById('toggleAppsIcon');
    const totalCount = <?= $is_executive_user ? 19 : 15 ?>;

    if (currentFilterCategory === 'all') {
        // ในหมวด "ทั้งหมด": เคารพสถานะย่อ/ขยาย
        if (toggleWrap) toggleWrap.style.display = 'block';

        primaryCards.forEach(card => card.style.display = 'flex');
        
        if (isAppsExpanded) {
            secondaryCards.forEach(card => card.style.display = 'flex');
            if (toggleBtnText) toggleBtnText.innerText = 'ย่อบริการแนะนำ';
            if (toggleBtnIcon) toggleBtnIcon.className = 'fa-solid fa-chevron-up ms-1';
        } else {
            secondaryCards.forEach(card => card.style.display = 'none');
            if (toggleBtnText) toggleBtnText.innerText = `ดูบริการทั้งหมด (${totalCount} รายการ)`;
            if (toggleBtnIcon) toggleBtnIcon.className = 'fa-solid fa-chevron-down ms-1';
        }
    } else {
        // เมื่อเลือกหมวดเฉพาะ (บุคลากร, วิชาการ, ผู้บริหาร): แสดงทุกแอปในหมวดนั้นทันที
        if (toggleWrap) toggleWrap.style.display = 'none';

        const allCards = document.querySelectorAll('.service-item-compact');
        allCards.forEach(card => {
            const cat = card.getAttribute('data-cat');
            if (cat === currentFilterCategory) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
