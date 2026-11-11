<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์ผู้ใช้งาน
// ---------------------------------------------------------
$is_admin = hasRole(['admin']);
$can_manage_meeting = hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน']);
$is_executive = hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองบริหาร', 'คณบดี', 'รักษาการคณบดี']);
$user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 2. จัดการ Action: ลบการประชุม
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_meeting' && $can_manage_meeting) {
    $meeting_id = intval($_POST['meeting_id']);
    try {
        // 1. ดึงข้อมูลการประชุมเพื่อลบการจอง Zoom
        $stmt_m = $pdo->prepare("SELECT title, meeting_date, start_time FROM meetings WHERE meeting_id = ?");
        $stmt_m->execute([$meeting_id]);
        $meet_info = $stmt_m->fetch(PDO::FETCH_ASSOC);
        if ($meet_info) {
            $zoom_title = "ประชุม: " . $meet_info['title'];
            $zoom_start = $meet_info['meeting_date'] . ' ' . $meet_info['start_time'];
            $pdo->prepare("DELETE FROM zoom_bookings WHERE title = ? AND start_time = ?")->execute([$zoom_title, $zoom_start]);
        }

        // 2. ลบไฟล์เอกสารแนบประจำวาระออกจากดิสก์
        $upload_dir = __DIR__ . '/../../uploads/meetings/';
        $stmt_att_files = $pdo->prepare("SELECT a.file_path FROM meeting_attachments a JOIN meeting_agendas g ON a.agenda_id = g.agenda_id WHERE g.meeting_id = ?");
        $stmt_att_files->execute([$meeting_id]);
        while ($f = $stmt_att_files->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($f['file_path']) && file_exists($upload_dir . $f['file_path'])) {
                @unlink($upload_dir . $f['file_path']);
            }
        }

        // 3. ลบไฟล์เอกสารที่ส่งเข้ามา (submissions) ออกจากดิสก์
        $sub_dir = __DIR__ . '/../../uploads/meetings/submissions/';
        $stmt_sub_files = $pdo->prepare("SELECT file_path FROM meeting_submissions WHERE meeting_id = ?");
        $stmt_sub_files->execute([$meeting_id]);
        while ($f = $stmt_sub_files->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($f['file_path']) && file_exists($sub_dir . $f['file_path'])) {
                @unlink($sub_dir . $f['file_path']);
            }
        }

        // 4. ลบการประชุมออกจากฐานข้อมูล (MySQL Cascade จะลบ agendas, attendees, attachments, submissions ใน DB)
        $stmt = $pdo->prepare("DELETE FROM meetings WHERE meeting_id = ?");
        $stmt->execute([$meeting_id]);
        $_SESSION['msg'] = "ลบการประชุมและไฟล์ที่เกี่ยวข้องเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
    } catch (PDOException $e) {
        $_SESSION['msg'] = "ไม่สามารถลบการประชุมได้: " . $e->getMessage();
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: index.php");
    exit;
}

// ---------------------------------------------------------
// 3. จัดการ Action: ตอบรับการประชุมด่วน (Quick RSVP)
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] == 'quick_rsvp') {
    $meeting_id = intval($_POST['meeting_id'] ?? 0);
    $attendance_status = $_POST['attendance_status'] ?? 'attending';
    $attend_mode = ($attendance_status === 'attending') ? ($_POST['attend_mode'] ?? 'onsite') : null;
    $absence_reason = ($attendance_status === 'absent') ? trim($_POST['absence_reason'] ?? '') : null;

    if ($meeting_id > 0) {
        try {
            $stmt_rsvp = $pdo->prepare("
                UPDATE meeting_attendees 
                SET attendance_status = ?, attend_mode = ?, absence_reason = ? 
                WHERE meeting_id = ? AND user_id = ?
            ");
            $stmt_rsvp->execute([$attendance_status, $attend_mode, $absence_reason, $meeting_id, $user_id]);
            $_SESSION['msg'] = "บันทึกการตอบรับการประชุมเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['msg'] = "เกิดข้อผิดพลาดในการตอบรับ: " . $e->getMessage();
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: index.php");
    exit;
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ---------------------------------------------------------
// 4. ดึงข้อมูลการประชุม
// ---------------------------------------------------------
// การประชุมเร็วๆ นี้: ให้แสดงเฉพาะที่ตัวเองมีรายชื่อเข้าร่วม ยกเว้น admin ที่มองเห็นทุกการประชุม
$where_upcoming = $is_admin ? "" : "AND a.user_id IS NOT NULL";
$stmt_upcoming = $pdo->prepare("
    SELECT m.*, a.attendance_status, a.attend_mode, a.absence_reason,
           (SELECT COUNT(*) FROM meeting_agendas WHERE meeting_id = m.meeting_id) as agenda_count,
           (SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = m.meeting_id) as total_attendees,
           (SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = m.meeting_id AND attendance_status = 'attending') as attending_count
    FROM meetings m 
    LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id AND a.user_id = :user_id
    WHERE m.status IN ('upcoming', 'in_progress') $where_upcoming
    ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), m.meeting_date ASC, m.start_time ASC
");
$stmt_upcoming->execute(['user_id' => $user_id]);
$upcoming_meetings = $stmt_upcoming->fetchAll(PDO::FETCH_ASSOC);

// ประวัติการประชุมที่เสร็จสิ้น (เฉพาะที่ตัวเองมีรายชื่อเข้าร่วม ยกเว้น admin ที่มองเห็นทุกการประชุม)
$where_past = $is_admin ? "" : "AND a.user_id IS NOT NULL";
$stmt_past = $pdo->prepare("
    SELECT m.*, a.attendance_status,
           (SELECT COUNT(*) FROM meeting_agendas WHERE meeting_id = m.meeting_id) as agenda_count,
           (SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = m.meeting_id) as total_attendees
    FROM meetings m 
    LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id AND a.user_id = :user_id
    WHERE m.status = 'completed' $where_past
    ORDER BY m.meeting_date DESC, m.start_time DESC 
    LIMIT 50
");
$stmt_past->execute(['user_id' => $user_id]);
$past_meetings = $stmt_past->fetchAll(PDO::FETCH_ASSOC);

// นับจำนวนสำหรับ KPI Cards
$cnt_in_progress = 0;
$cnt_pending_rsvp = 0;
$cnt_attending = 0;

foreach ($upcoming_meetings as $um) {
    if ($um['status'] === 'in_progress') $cnt_in_progress++;
    if ($um['attendance_status'] === 'pending') $cnt_pending_rsvp++;
    if ($um['attendance_status'] === 'attending') $cnt_attending++;
}

$cnt_completed = count($past_meetings);

// ดึงข้อมูลสำหรับปฏิทิน FullCalendar (เฉพาะที่ตัวเองมีรายชื่อเข้าร่วม ยกเว้น admin ที่มองเห็นทุกการประชุม)
$where_cal = $is_admin ? "" : "AND a.user_id IS NOT NULL";
$stmt_cal = $pdo->prepare("
    SELECT m.meeting_id, m.title, m.meeting_no, m.meeting_date, m.start_time, m.end_time, m.location, m.status
    FROM meetings m 
    LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id AND a.user_id = :uid
    WHERE m.status != 'draft' $where_cal
    ORDER BY m.meeting_date ASC
");
$stmt_cal->execute(['uid' => $user_id]);
$cal_meetings = $stmt_cal->fetchAll(PDO::FETCH_ASSOC);

$calendar_events = [];
foreach ($cal_meetings as $cm) {
    $c_color = '#6f42c1';
    if ($cm['status'] === 'in_progress') $c_color = '#198754';
    elseif ($cm['status'] === 'completed') $c_color = '#6c757d';
    
    $calendar_events[] = [
        'id' => $cm['meeting_id'],
        'title' => 'ครั้งที่ ' . $cm['meeting_no'] . ': ' . $cm['title'],
        'start' => $cm['meeting_date'] . ($cm['start_time'] ? 'T' . $cm['start_time'] : ''),
        'end' => $cm['meeting_date'] . ($cm['end_time'] ? 'T' . $cm['end_time'] : ''),
        'color' => $c_color,
        'location' => $cm['location'] ?: 'ไม่ระบุสถานที่',
        'status_text' => ($cm['status'] === 'in_progress' ? 'กำลังประชุม' : ($cm['status'] === 'completed' ? 'เสร็จสิ้นแล้ว' : 'รอการประชุม'))
    ];
}

// ฟังก์ชันแปลงวันที่เป็นภาษาไทย
function thaiDate($date, $with_day = false) {
    if (!$date) return '-';
    $days = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];
    $months_short = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $timestamp = strtotime($date);
    $d = date("j", $timestamp);
    $m = $months_short[intval(date("n", $timestamp))];
    $y = date("Y", $timestamp) + 543;
    if ($with_day) {
        $w = $days[date("w", $timestamp)];
        return "วัน{$w}ที่ $d $m $y";
    }
    return "$d $m $y";
}

$msg = $_SESSION['msg'] ?? '';
$msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);
?>

<!-- FullCalendar CSS -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap pt-3 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-handshake text-purple me-2"></i> ระบบการประชุมอิเล็กทรอนิกส์ (E-Meeting)
            </h1>
            <p class="text-muted small mb-0">ศูนย์รวมการนัดหมาย ระเบียบวาระ ห้องประชุม และสืบค้นมติ คณะดุริยางคศิลป์</p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">
            <a href="search_resolutions.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-magnifying-glass text-purple me-1"></i> สืบค้นมติที่ประชุม
            </a>
            <a href="document_center.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-cloud-arrow-up text-primary me-1"></i> ส่งเอกสารวาระ
            </a>
            <?php if ($is_executive): ?>
                <a href="dashboard_admin.php" class="btn btn-outline-purple rounded-pill px-3 py-2 fw-bold shadow-xs">
                    <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดผู้บริหาร
                </a>
            <?php endif; ?>
            <?php if ($can_manage_meeting): ?>
                <a href="manage_meeting.php" class="btn btn-purple rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-plus me-1"></i> จัดการ/สร้างการประชุม
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type); ?> shadow-xs border-0 border-start border-<?= htmlspecialchars($msg_type); ?> border-4 alert-dismissible fade show rounded-3 mb-3">
            <i class="fa-solid fa-circle-info me-2"></i> <?= htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Action Required Callout 1: การประชุมที่กำลังดำเนินการอยู่ (Live Meeting) -->
    <?php if ($cnt_in_progress > 0): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-3 text-white overflow-hidden" style="background: linear-gradient(135deg, #198754 0%, #0f5132 100%);">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center">
                        <span class="spinner-grow spinner-grow-sm text-warning me-3" role="status"></span>
                        <div>
                            <span class="badge bg-warning text-dark fw-bold rounded-pill mb-1">กำลังดำเนินการประชุมอยู่ในขณะนี้</span>
                            <h5 class="fw-bold mb-0">มีการประชุมที่กำลังเริ่มอยู่ กรุณาเข้าสู่ห้องประชุม</h5>
                        </div>
                    </div>
                    <?php 
                        $first_live = array_filter($upcoming_meetings, fn($m) => $m['status'] === 'in_progress');
                        $first_live = reset($first_live);
                    ?>
                    <?php if ($first_live): ?>
                        <a href="meeting_room.php?id=<?= $first_live['meeting_id']; ?>" class="btn btn-light text-success fw-bold rounded-pill px-4 py-2 shadow-sm">
                            <i class="fa-solid fa-door-open me-2"></i> เข้าห้องประชุมทันที
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Required Callout 2: มีการประชุมที่รอตอบรับเข้าร่วม (Pending RSVP) -->
    <?php if ($cnt_pending_rsvp > 0): ?>
        <div class="card border-0 shadow-xs rounded-4 mb-4 bg-danger bg-opacity-10 border-start border-danger border-4">
            <div class="card-body p-3 p-md-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger text-white rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.2rem;">
                        <i class="fa-solid fa-hand-pointer"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-danger">คุณมีนัดหมายการประชุมที่รอการตอบรับเข้าร่วม</h6>
                        <p class="text-muted small mb-0">
                            โปรดยืนยันการเข้าร่วมหรือแจ้งลาประชุมล่วงหน้าจำนวน <strong class="text-danger"><?= $cnt_pending_rsvp; ?> รายการ</strong>
                        </p>
                    </div>
                </div>
                <span class="badge bg-danger rounded-pill px-3 py-2 fw-normal">รอตอบรับ</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Compact KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. การประชุมเร็วๆ นี้ -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-purple-subtle text-purple rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-regular fa-calendar-check"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">การประชุมที่กำลังจะถึง</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= count($upcoming_meetings); ?></h3>
                            <span class="text-muted small">นัดหมาย</span>
                        </div>
                        <small class="text-muted extra-small">
                            กำลังประชุม <span class="text-success fw-bold"><?= $cnt_in_progress; ?></span> / รอเริ่ม <span class="text-muted fw-bold"><?= count($upcoming_meetings) - $cnt_in_progress; ?></span>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. รอการตอบรับ -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon <?= ($cnt_pending_rsvp > 0) ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?> rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-envelope-open-text"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">สถานะการตอบรับ</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold <?= ($cnt_pending_rsvp > 0) ? 'text-danger' : 'text-success'; ?> mb-0"><?= $cnt_pending_rsvp; ?></h3>
                            <span class="text-muted small">รอตอบ</span>
                        </div>
                        <small class="text-muted extra-small">
                            ยืนยันเข้าร่วมแล้ว <span class="text-primary fw-bold"><?= $cnt_attending; ?></span> นัด
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. ประวัติที่เสร็จสิ้น -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info-subtle text-info rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">การประชุมที่เสร็จสิ้น</small>
                        <div class="d-flex align-items-baseline gap-1">
                            <h3 class="fw-bold text-dark mb-0"><?= $cnt_completed; ?></h3>
                            <span class="text-muted small">ครั้ง</span>
                        </div>
                        <small class="text-muted extra-small">
                            มีมติที่ประชุมบันทึกไว้ในระบบ
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. คลังเอกสาร & มติ -->
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-xs rounded-4 bg-white h-100 p-3" style="border-right: 4px solid #6f42c1 !important;">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fa-solid fa-folder-closed"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted fw-bold text-uppercase extra-small d-block mb-1">ศูนย์เอกสาร & มติ</small>
                        <div class="d-flex gap-2 mt-1">
                            <a href="search_resolutions.php" class="btn btn-sm btn-outline-purple rounded-pill extra-small px-2 py-1 fw-bold">
                                <i class="fa-solid fa-search me-1"></i> สืบค้นมติ
                            </a>
                            <a href="document_center.php" class="btn btn-sm btn-light border rounded-pill extra-small px-2 py-1">
                                <i class="fa-solid fa-upload me-1"></i> ส่งวาระ
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Segmented Navigation Tabs -->
    <ul class="nav nav-pills bg-white p-2 rounded-4 shadow-xs mb-4 gap-2 border" id="meetingTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold active" id="tab-upcoming-btn" data-bs-toggle="pill" data-bs-target="#tab-upcoming-pane" type="button" role="tab">
                <i class="fa-solid fa-calendar-days me-2"></i> การประชุมเร็วๆ นี้ <span class="badge bg-purple-subtle text-purple rounded-pill ms-1"><?= count($upcoming_meetings); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-history-btn" data-bs-toggle="pill" data-bs-target="#tab-history-pane" type="button" role="tab">
                <i class="fa-solid fa-clock-rotate-left me-2"></i> ประวัติการประชุม (<?= count($past_meetings); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 fw-bold text-secondary" id="tab-calendar-btn" data-bs-toggle="pill" data-bs-target="#tab-calendar-pane" type="button" role="tab" onclick="initMeetingCalendar()">
                <i class="fa-regular fa-calendar me-2"></i> ปฏิทินนัดหมาย
            </button>
        </li>
        <?php if ($is_executive): ?>
            <li class="nav-item ms-md-auto" role="presentation">
                <a href="dashboard_admin.php" class="nav-link rounded-pill px-3 fw-bold border text-purple bg-purple-subtle" title="แดชบอร์ดภาพรวมการประชุมสำหรับผู้บริหาร">
                    <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดผู้บริหาร <i class="fa-solid fa-arrow-up-right-from-square extra-small ms-1 opacity-75"></i>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <!-- Tab Content Panes -->
    <div class="tab-content" id="meetingTabContent">
        <!-- ========================================== -->
        <!-- TAB 1: การประชุมเร็วๆ นี้ (Upcoming Cards) -->
        <!-- ========================================== -->
        <div class="tab-pane fade show active" id="tab-upcoming-pane" role="tabpanel">
            <?php if (empty($upcoming_meetings)): ?>
                <div class="card border-0 shadow-xs rounded-4 bg-white p-5 text-center">
                    <div class="py-4">
                        <i class="fa-solid fa-calendar-check fa-4x text-muted opacity-25 mb-3"></i>
                        <h5 class="fw-bold text-dark mb-1">ยังไม่มีกำหนดการประชุมในเร็วๆ นี้</h5>
                        <p class="text-muted small mb-4">คุณไม่มีนัดหมายการประชุมที่กำลังจะมาถึงในขณะนี้</p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="search_resolutions.php" class="btn btn-sm btn-outline-purple rounded-pill px-3 py-2 fw-semibold">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> สืบค้นมติย้อนหลัง
                            </a>
                            <?php if ($can_manage_meeting): ?>
                                <a href="manage_meeting.php" class="btn btn-sm btn-purple rounded-pill px-3 py-2 fw-bold">
                                    <i class="fa-solid fa-plus me-1"></i> จัดการ/สร้างการประชุมใหม่
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($upcoming_meetings as $m): 
                        $is_live = ($m['status'] === 'in_progress');
                        $rsvp = $m['attendance_status'] ?? 'pending';
                        
                        $border_color = '#e2e8f0';
                        if ($is_live) $border_color = '#198754';
                        elseif ($rsvp === 'pending') $border_color = '#dc3545';
                        elseif ($rsvp === 'attending') $border_color = '#6f42c1';
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card border-0 shadow-xs rounded-4 bg-white h-100 meeting-card position-relative overflow-hidden" style="border-top: 4px solid <?= $border_color; ?> !important;">
                            <div class="card-body p-4 d-flex flex-column justify-content-between">
                                <div>
                                    <!-- Badge Header -->
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-light text-secondary border rounded-pill extra-small">
                                            ครั้งที่ <?= htmlspecialchars($m['meeting_no']); ?>
                                        </span>

                                        <?php if ($is_live): ?>
                                            <span class="badge bg-success rounded-pill extra-small pulse-animation">
                                                <i class="fa-solid fa-circle text-white extra-small me-1"></i> กำลังประชุม
                                            </span>
                                        <?php elseif ($rsvp === 'pending'): ?>
                                            <span class="badge bg-danger rounded-pill extra-small">
                                                <i class="fa-solid fa-bell me-1"></i> รอคุณตอบรับ
                                            </span>
                                        <?php elseif ($rsvp === 'attending'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill extra-small">
                                                <i class="fa-solid fa-check me-1"></i> ยืนยันเข้าร่วม (<?= $m['attend_mode'] === 'online' ? 'ออนไลน์' : 'ออนไซต์'; ?>)
                                            </span>
                                        <?php elseif ($rsvp === 'absent'): ?>
                                            <span class="badge bg-secondary rounded-pill extra-small">
                                                <i class="fa-solid fa-xmark me-1"></i> ลาประชุม
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border rounded-pill extra-small">
                                                รอเริ่มประชุม
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Title -->
                                    <h5 class="fw-bold mb-3">
                                        <a href="view.php?id=<?= $m['meeting_id']; ?>" class="text-dark text-decoration-none hover-purple d-block text-truncate-2" title="<?= htmlspecialchars($m['title']); ?>">
                                            <?= htmlspecialchars($m['title']); ?>
                                        </a>
                                    </h5>

                                    <!-- Date & Time & Location -->
                                    <div class="bg-light rounded-3 p-3 mb-3">
                                        <div class="d-flex align-items-center text-dark small mb-2">
                                            <div style="width: 26px;"><i class="fa-regular fa-calendar-check text-purple"></i></div>
                                            <div class="fw-bold"><?= thaiDate($m['meeting_date'], true); ?></div>
                                        </div>
                                        <div class="d-flex align-items-center text-muted small mb-2">
                                            <div style="width: 26px;"><i class="fa-regular fa-clock text-warning"></i></div>
                                            <div>
                                                <?= date('H:i', strtotime($m['start_time'])); ?> น. - 
                                                <?= $m['end_time'] ? date('H:i', strtotime($m['end_time'])) . ' น.' : 'จนกว่าจะเสร็จสิ้น'; ?>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center text-muted small">
                                            <div style="width: 26px;"><i class="fa-solid fa-location-dot text-danger"></i></div>
                                            <div class="text-truncate" title="<?= htmlspecialchars($m['location'] ?: 'ไม่ระบุสถานที่'); ?>">
                                                <?= htmlspecialchars($m['location'] ?: 'ไม่ระบุสถานที่'); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Agendas and Attendees Count -->
                                    <div class="d-flex justify-content-between text-muted extra-small mb-3 px-1">
                                        <span><i class="fa-solid fa-list-check text-info me-1"></i> <?= $m['agenda_count']; ?> วาระการประชุม</span>
                                        <span><i class="fa-solid fa-users text-secondary me-1"></i> ตอบรับแล้ว <?= $m['attending_count']; ?>/<?= $m['total_attendees']; ?> คน</span>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <a href="meeting_room.php?id=<?= $m['meeting_id']; ?>" class="btn flex-grow-1 <?= $is_live ? 'btn-success' : 'btn-outline-purple'; ?> rounded-pill fw-bold shadow-xs">
                                            <i class="fa-solid fa-door-open me-1"></i> เข้าห้องประชุม
                                        </a>
                                        <?php if ($rsvp === 'pending'): ?>
                                            <button type="button" class="btn btn-danger rounded-pill fw-bold shadow-xs px-3 text-nowrap" onclick="openQuickRsvpModal(<?= $m['meeting_id']; ?>, '<?= htmlspecialchars(addslashes($m['title'])); ?>')" title="ตอบรับเข้าร่วม/ลา">
                                                <i class="fa-solid fa-hand-pointer me-1"></i> ตอบรับ
                                            </button>
                                        <?php endif; ?>

                                        <a href="view.php?id=<?= $m['meeting_id']; ?>" class="btn btn-light border text-dark rounded-pill px-3 shadow-xs" title="ดูรายละเอียดและระเบียบวาระ">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>

                                        <?php if ($can_manage_meeting): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-light border rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 36px; height: 36px; padding: 0;">
                                                    <i class="fa-solid fa-ellipsis-vertical text-muted"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 p-1">
                                                    <li>
                                                        <a class="dropdown-item small rounded-2" href="manage_meeting.php?id=<?= $m['meeting_id']; ?>">
                                                            <i class="fa-solid fa-pen-to-square me-2 text-warning"></i> แก้ไขการประชุม
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item small rounded-2" href="manage_agendas.php?id=<?= $m['meeting_id']; ?>">
                                                            <i class="fa-solid fa-list-check me-2 text-primary"></i> จัดการระเบียบวาระ
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item small rounded-2" href="manage_agendas.php?id=<?= $m['meeting_id']; ?>&open=attendees">
                                                            <i class="fa-solid fa-users me-2 text-info"></i> จัดการผู้เข้าร่วม (สิทธิ์การเข้าถึง)
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider my-1"></li>
                                                    <li>
                                                        <form method="POST" action="" onsubmit="return confirm('ยืนยันลบการประชุมนี้ใช่หรือไม่?');" class="m-0">
                                                            <input type="hidden" name="action" value="delete_meeting">
                                                            <input type="hidden" name="meeting_id" value="<?= $m['meeting_id']; ?>">
                                                            <button type="submit" class="dropdown-item small rounded-2 text-danger">
                                                                <i class="fa-solid fa-trash me-2"></i> ลบการประชุม
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: ประวัติการประชุม (History Table) -->
        <!-- ========================================== -->
        <div class="tab-pane fade" id="tab-history-pane" role="tabpanel">
            <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
                <div class="card-header bg-white p-3 p-md-4 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-clock-rotate-left text-muted me-2"></i> ประวัติการประชุมที่เสร็จสิ้นแล้ว
                        </h5>
                        <small class="text-muted">รายการการประชุมย้อนหลัง มติที่ประชุม และเอกสารแนบ</small>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 280px;">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-search text-muted"></i></span>
                        <input type="text" id="searchInput" class="form-control border-0 bg-light" placeholder="ค้นหาการประชุมย้อนหลัง..." onkeyup="filterPastTable()">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="meetingTable">
                            <thead class="table-light text-muted small">
                                <tr>
                                    <th class="ps-4" style="width: 15%;">วันที่จัด</th>
                                    <th style="width: 12%;">ครั้งที่</th>
                                    <th style="width: 38%;">หัวข้อการประชุม</th>
                                    <th style="width: 15%;">สถานที่</th>
                                    <th style="width: 8%;">วาระ</th>
                                    <th class="text-end pe-4" style="width: 12%;">รายงาน / มติ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($past_meetings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-folder-open fa-3x opacity-25 mb-2"></i>
                                            <p class="mb-0">ยังไม่มีประวัติการประชุมในระบบ</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($past_meetings as $p): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark small"><?= thaiDate($p['meeting_date']); ?></div>
                                            <div class="extra-small text-muted"><?= date('H:i', strtotime($p['start_time'])); ?> น.</div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border rounded-pill extra-small">
                                                ครั้งที่ <?= htmlspecialchars($p['meeting_no']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?= $p['meeting_id']; ?>" class="fw-bold text-purple text-decoration-none small d-block text-truncate" style="max-width: 360px;" title="<?= htmlspecialchars($p['title']); ?>">
                                                <?= htmlspecialchars($p['title']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="extra-small text-muted text-truncate" style="max-width: 160px;">
                                                <i class="fa-solid fa-location-dot me-1 text-danger"></i><?= htmlspecialchars($p['location'] ?: '-'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-secondary border rounded-pill extra-small">
                                                <?= $p['agenda_count']; ?> วาระ
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex justify-content-end align-items-center gap-1">
                                                <a href="meeting_room.php?id=<?= $p['meeting_id']; ?>" class="btn btn-sm btn-light border rounded-pill px-3 shadow-xs text-dark extra-small fw-bold text-nowrap" title="ดูมติที่ประชุมและสรุปผล">
                                                    <i class="fa-regular fa-file-pdf text-danger me-1"></i> ดูมติ
                                                </a>
                                                <a href="view.php?id=<?= $p['meeting_id']; ?>" class="btn btn-sm btn-light border rounded-circle shadow-xs" style="width: 32px; height: 32px; padding: 0;" title="ดูรายละเอียด">
                                                    <i class="fa-solid fa-eye extra-small text-muted"></i>
                                                </a>
                                                <?php if ($can_manage_meeting): ?>
                                                    <form method="POST" action="" onsubmit="return confirm('ยืนยันลบประวัติการประชุมนี้ใช่หรือไม่?');" class="m-0 d-inline">
                                                        <input type="hidden" name="action" value="delete_meeting">
                                                        <input type="hidden" name="meeting_id" value="<?= $p['meeting_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-light border text-danger rounded-circle shadow-xs" style="width: 32px; height: 32px; padding: 0;" title="ลบการประชุม">
                                                            <i class="fa-solid fa-trash extra-small"></i>
                                                        </button>
                                                    </form>
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
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: ปฏิทินนัดหมาย (FullCalendar) -->
        <!-- ========================================== -->
        <div class="tab-pane fade" id="tab-calendar-pane" role="tabpanel">
            <div class="card border-0 shadow-xs rounded-4 bg-white p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-regular fa-calendar-days text-purple me-2"></i> ปฏิทินนัดหมายการประชุม
                        </h5>
                        <small class="text-muted">คลิกที่รายการประชุมในปฏิทินเพื่อดูรายละเอียดเพิ่มเติม</small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge rounded-pill fw-normal" style="background-color: #198754; color: white;">กำลังประชุม</span>
                        <span class="badge rounded-pill fw-normal" style="background-color: #6f42c1; color: white;">การประชุมเร็วๆ นี้</span>
                        <span class="badge rounded-pill fw-normal" style="background-color: #6c757d; color: white;">เสร็จสิ้นแล้ว</span>
                    </div>
                </div>
                <div id="meetingCalendar" style="min-height: 480px;"></div>
            </div>
        </div>
    </div>
</main>

<!-- Quick RSVP Modal (ตอบรับการประชุมด่วน) -->
<div class="modal fade" id="quickRsvpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST" action="">
                <input type="hidden" name="action" value="quick_rsvp">
                <input type="hidden" name="meeting_id" id="rsvpMeetingId" value="">
                
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-hand-pointer text-purple me-2"></i>ยืนยันการตอบรับเข้าร่วมประชุม</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="bg-light p-3 rounded-3 mb-3 border">
                        <small class="text-muted d-block mb-1">การประชุม:</small>
                        <strong class="text-dark d-block" id="rsvpMeetingTitle">-</strong>
                    </div>

                    <label class="form-label fw-bold small text-dark mb-2">โปรดเลือกการตอบรับ:</label>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <div class="form-check p-3 border rounded-3 bg-white" onclick="document.getElementById('rsvpAttending').checked = true; toggleRsvpFields();" style="cursor: pointer;">
                            <input class="form-check-input ms-0 me-3" type="radio" name="attendance_status" id="rsvpAttending" value="attending" checked onchange="toggleRsvpFields()">
                            <label class="form-check-label fw-bold text-success" for="rsvpAttending">
                                <i class="fa-solid fa-check-circle me-1"></i> ยืนยันเข้าร่วมการประชุม
                            </label>
                        </div>
                        <div class="form-check p-3 border rounded-3 bg-white" onclick="document.getElementById('rsvpAbsent').checked = true; toggleRsvpFields();" style="cursor: pointer;">
                            <input class="form-check-input ms-0 me-3" type="radio" name="attendance_status" id="rsvpAbsent" value="absent" onchange="toggleRsvpFields()">
                            <label class="form-check-label fw-bold text-danger" for="rsvpAbsent">
                                <i class="fa-solid fa-times-circle me-1"></i> ขอลาประชุม (ไม่สามารถเข้าร่วมได้)
                            </label>
                        </div>
                    </div>

                    <!-- ฟิลด์ย่อย: รูปแบบการเข้าร่วม -->
                    <div id="rsvpModeGroup" class="mb-3">
                        <label class="form-label small text-muted fw-bold">รูปแบบการเข้าร่วม:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="attend_mode" id="modeOnsite" value="onsite" checked>
                                <label class="form-check-label small" for="modeOnsite"><i class="fa-solid fa-building me-1"></i> ออนไซต์ (On-site)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="attend_mode" id="modeOnline" value="online">
                                <label class="form-check-label small" for="modeOnline"><i class="fa-solid fa-video me-1"></i> ออนไลน์ (Online)</label>
                            </div>
                        </div>
                    </div>

                    <!-- ฟิลด์ย่อย: เหตุผลการลา -->
                    <div id="rsvpReasonGroup" class="mb-3 d-none">
                        <label class="form-label small text-muted fw-bold">ระบุเหตุผลการลาประชุม:</label>
                        <textarea name="absence_reason" class="form-control rounded-3 small" rows="2" placeholder="เช่น ติดภารกิจสอน, ไปราชการต่างจังหวัด..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold">บันทึกการตอบรับ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #6f42c1 !important; }
    .bg-purple { background-color: #6f42c1 !important; color: #ffffff !important; }
    .bg-purple-subtle { background-color: rgba(111, 66, 193, 0.12) !important; }
    .border-purple-subtle { border-color: rgba(111, 66, 193, 0.25) !important; }
    
    .btn-purple { 
        background-color: #6f42c1; 
        color: #ffffff; 
        border: none;
        transition: all 0.2s ease-in-out; 
    }
    .btn-purple:hover { 
        background-color: #59359a; 
        color: #ffffff; 
        transform: translateY(-1px); 
        box-shadow: 0 4px 12px rgba(111, 66, 193, 0.25); 
    }

    .btn-outline-purple { 
        color: #6f42c1; 
        border-color: #6f42c1; 
        transition: all 0.2s ease-in-out; 
    }
    .btn-outline-purple:hover { 
        background-color: #6f42c1; 
        color: #ffffff; 
    }

    .hover-purple:hover {
        color: #6f42c1 !important;
    }

    .shadow-xs {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .extra-small {
        font-size: 0.75rem;
    }

    .text-truncate-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .meeting-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .meeting-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.08) !important;
    }

    /* Animation สำหรับป้าย "กำลังประชุม" */
    .pulse-animation { 
        animation: pulseLive 2s infinite; 
    }
    @keyframes pulseLive {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
        70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }

    /* Segmented Navigation Pills */
    .nav-pills .nav-link {
        color: #6c757d;
        transition: all 0.2s ease;
    }
    .nav-pills .nav-link.active {
        background-color: #6f42c1 !important;
        color: #ffffff !important;
    }
    .nav-pills .nav-link.active .badge {
        background-color: #ffffff !important;
        color: #6f42c1 !important;
    }

    /* FullCalendar Overrides */
    .fc-theme-standard th { border: none; padding: 8px 0; color: #718096; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;}
    .fc-daygrid-day-number { color: #4a5568; text-decoration: none; padding: 4px !important; font-weight: 500; }
    .fc-event { border: none !important; border-radius: 6px; padding: 3px 6px; font-size: 0.75rem; font-weight: 500; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .fc-button-primary { background-color: #6f42c1 !important; border-color: #6f42c1 !important; }
    .fc-button-primary:hover { background-color: #59359a !important; border-color: #59359a !important; }
</style>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js"></script>

<script>
// ค้นหาในประวัติการประชุม
function filterPastTable() {
    let input = document.getElementById("searchInput").value.toLowerCase();
    let rows = document.querySelectorAll("#meetingTable tbody tr");

    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? "" : "none";
    });
}

// เปิด Quick RSVP Modal
function openQuickRsvpModal(meetingId, meetingTitle) {
    document.getElementById('rsvpMeetingId').value = meetingId;
    document.getElementById('rsvpMeetingTitle').innerText = meetingTitle;
    document.getElementById('rsvpAttending').checked = true;
    toggleRsvpFields();
    
    var modal = new bootstrap.Modal(document.getElementById('quickRsvpModal'));
    modal.show();
}

// สลับฟิลด์ใน Modal ตอบรับ
function toggleRsvpFields() {
    var isAbsent = document.getElementById('rsvpAbsent').checked;
    var modeGroup = document.getElementById('rsvpModeGroup');
    var reasonGroup = document.getElementById('rsvpReasonGroup');
    
    if (isAbsent) {
        modeGroup.classList.add('d-none');
        reasonGroup.classList.remove('d-none');
    } else {
        modeGroup.classList.remove('d-none');
        reasonGroup.classList.add('d-none');
    }
}

// เริ่มต้น FullCalendar เมื่อคลิกแท็บปฏิทิน
var calendarInstance = null;
function initMeetingCalendar() {
    setTimeout(function() {
        var calEl = document.getElementById('meetingCalendar');
        if (!calEl) return;
        
        if (!calendarInstance) {
            var eventsData = <?= json_encode($calendar_events, JSON_UNESCAPED_UNICODE); ?>;
            calendarInstance = new FullCalendar.Calendar(calEl, {
                initialView: 'dayGridMonth',
                locale: 'th',
                height: 'auto',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listMonth'
                },
                events: eventsData,
                eventClick: function(info) {
                    if (info.event.id) {
                        window.location.href = 'view.php?id=' + info.event.id;
                    }
                }
            });
            calendarInstance.render();
        } else {
            calendarInstance.updateSize();
        }
    }, 200);
}
</script>

<?php require_once '../../includes/footer.php'; ?>