<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$meeting_id = intval($_GET['id'] ?? 0);
if ($meeting_id <= 0) {
    echo "<script>alert('ไม่พบรหัสการประชุม'); window.location.href='index.php';</script>";
    exit();
}

$can_edit = hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน']);
$user_id = $_SESSION['user_id'];

// =========================================================
// 1. ประมวลผล AJAX & POST Actions
// =========================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $can_edit) {
    // 1.1 บันทึกมติและผู้รับผิดชอบผ่าน AJAX
    if ($_POST['action'] === 'save_resolution_ajax') {
        $agenda_id = intval($_POST['agenda_id'] ?? 0);
        $resolution = trim($_POST['resolution'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE meeting_agendas SET resolution = ?, assigned_to = ? WHERE agenda_id = ?");
            $stmt->execute([$resolution, $assigned_to, $agenda_id]);
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) { 
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        }
        exit(); 
    } 
    // 1.2 เพิ่มวาระด่วน
    elseif ($_POST['action'] === 'add_agenda') {
        try {
            $stmt = $pdo->prepare("INSERT INTO meeting_agendas (meeting_id, agenda_number, agenda_title, agenda_detail, proposal) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$meeting_id, trim($_POST['agenda_number']), trim($_POST['agenda_title']), trim($_POST['agenda_detail']), trim($_POST['proposal'] ?? '')]);
            $_SESSION['msg'] = "เพิ่มวาระการประชุมเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['msg'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: meeting_room.php?id=$meeting_id");
        exit();
    } 
    // 1.3 ปรับลำดับวาระ
    elseif ($_POST['action'] === 'reorder_agendas') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE meeting_agendas SET sort_order = ? WHERE agenda_id = ?");
                foreach ($order as $index => $agenda_id) {
                    $stmt->execute([$index + 1, $agenda_id]);
                }
                $pdo->commit();
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
        }
        exit();
    }
    // 1.4 ปรับเปลี่ยนสถานะการประชุม (เริ่มการประชุม / เสร็จสิ้นการประชุม)
    elseif ($_POST['action'] === 'update_meeting_status') {
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['upcoming', 'in_progress', 'completed'])) {
            try {
                $stmt_st = $pdo->prepare("UPDATE meetings SET status = ? WHERE meeting_id = ?");
                $stmt_st->execute([$new_status, $meeting_id]);
                $st_th = ($new_status === 'in_progress' ? 'กำลังประชุม' : ($new_status === 'completed' ? 'เสร็จสิ้นการประชุม' : 'รอการประชุม'));
                $_SESSION['msg'] = "เปลี่ยนสถานะการประชุมเป็น \"$st_th\" เรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            } catch (PDOException $e) {
                $_SESSION['msg'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $_SESSION['msg_type'] = "danger";
            }
        }
        header("Location: meeting_room.php?id=$meeting_id");
        exit();
    }
}

// =========================================================
// 2. ดึงข้อมูลการประชุม
// =========================================================
$stmt_meet = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
$stmt_meet->execute([$meeting_id]);
$meeting = $stmt_meet->fetch(PDO::FETCH_ASSOC);

if (!$meeting) { 
    echo "<script>alert('ไม่พบข้อมูลการประชุม'); window.location.href='index.php';</script>"; 
    exit(); 
}

// ค้นหาข้อมูล Zoom จาก zoom_bookings (จับคู่ด้วยชื่อและเวลา ถ้ามี)
$zoom_info = null;
try {
    $stmt_zoom = $pdo->prepare("
        SELECT z.*, a.meeting_url, a.meeting_id as zoom_meeting_id, a.passcode 
        FROM zoom_bookings z 
        JOIN zoom_accounts a ON z.zoom_account_id = a.id 
        WHERE z.title = ? AND z.start_time = ?
    ");
    $start_datetime = $meeting['meeting_date'] . ' ' . $meeting['start_time'];
    $stmt_zoom->execute(["ประชุม: " . $meeting['title'], $start_datetime]);
    $zoom_info = $stmt_zoom->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $zoom_info = null; }

// ดึงระเบียบวาระการประชุม
$stmt_agendas = $pdo->prepare("
    SELECT * FROM meeting_agendas 
    WHERE meeting_id = ? 
    ORDER BY sort_order ASC, LENGTH(agenda_number) ASC, agenda_number ASC
");
$stmt_agendas->execute([$meeting_id]); 
$agendas = $stmt_agendas->fetchAll(PDO::FETCH_ASSOC);

// ฟังก์ชันช่วยเข้ารหัส URL ไฟล์แนบและตรวจสอบนามสกุลไฟล์ให้แม่นยำ (รองรับชื่อไฟล์ภาษาไทย/ช่องว่าง/อักขระพิเศษ)
function buildMeetingFileUrl($relPath) {
    $clean = str_replace('\\', '/', trim((string)$relPath));
    $clean = ltrim($clean, '/');
    $segments = array_map('rawurlencode', explode('/', $clean));
    return '../../uploads/meetings/' . implode('/', $segments);
}

function detectMeetingFileExt($fileName, $filePath) {
    $ext = strtolower(pathinfo((string)$filePath, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    }
    return $ext;
}

// ดึงเอกสารแนบในแต่ละวาระ
$stmt_files = $pdo->prepare("
    SELECT a.* FROM meeting_attachments a 
    JOIN meeting_agendas g ON a.agenda_id = g.agenda_id 
    WHERE g.meeting_id = ?
    ORDER BY a.attachment_id ASC
");
$stmt_files->execute([$meeting_id]); 
$files_raw = $stmt_files->fetchAll(PDO::FETCH_ASSOC);
$attachments = [];
$agenda_files_js = [];
foreach ($files_raw as $f) { 
    $aid = $f['agenda_id'];
    $attachments[$aid][] = $f;
    $agenda_files_js[$aid][] = [
        'id'   => (int)$f['attachment_id'],
        'name' => (string)$f['file_name'],
        'path' => buildMeetingFileUrl($f['file_path']),
        'ext'  => detectMeetingFileExt($f['file_name'], $f['file_path'])
    ];
}

// ดึงรายชื่อผู้เข้าร่วม พร้อมสถานะตอบรับ
$stmt_attendees = $pdo->prepare("
    SELECT u.user_id, u.first_name, u.last_name, u.position, d.dept_name,
           a.attendance_status, a.attend_mode, a.absence_reason
    FROM meeting_attendees a 
    JOIN users u ON a.user_id = u.user_id 
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE a.meeting_id = ?
    ORDER BY a.attendance_status DESC, u.first_name ASC
");
$stmt_attendees->execute([$meeting_id]); 
$attendees = $stmt_attendees->fetchAll(PDO::FETCH_ASSOC);

// นับจำนวนผู้เข้าร่วมแยกตามสถานะ
$count_onsite = 0;
$count_online = 0;
$count_absent = 0;
$count_pending = 0;

foreach ($attendees as $att) {
    if ($att['attendance_status'] === 'attending') {
        if ($att['attend_mode'] === 'online') $count_online++;
        else $count_onsite++;
    } elseif ($att['attendance_status'] === 'absent') {
        $count_absent++;
    } else {
        $count_pending++;
    }
}

// ฟังก์ชันแปลงวันที่ภาษาไทยแบบเต็ม
function thaiDateFull($date) {
    $days = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $timestamp = strtotime($date);
    $w = $days[date("w", $timestamp)];
    $d = date("j", $timestamp);
    $m = $months[intval(date("n", $timestamp))];
    $y = date("Y", $timestamp) + 543;
    return "วัน{$w}ที่ $d $m $y";
}

$msg = $_SESSION['msg'] ?? '';
$msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main id="mainContent" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh; transition: all 0.3s ease;">
    
    <!-- Save Success Toast -->
    <div id="saveToast" class="alert alert-success shadow-lg border-0 border-start border-success border-4 d-none position-fixed top-0 start-50 translate-middle-x mt-4 rounded-3" style="z-index: 3000;">
        <i class="fa-solid fa-check-circle me-2"></i> <strong>บันทึกมติและผู้รับผิดชอบเรียบร้อยแล้ว!</strong>
    </div>

    <!-- Alert Message -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type); ?> shadow-xs border-0 border-start border-<?= htmlspecialchars($msg_type); ?> border-4 alert-dismissible fade show rounded-3 mt-3 mb-2">
            <i class="fa-solid fa-circle-info me-2"></i> <?= htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Dedicated Top Bar for Presentation Mode (แสดงเฉพาะเมื่ออยู่ในโหมดนำเสนอ และไม่ซ้อนทับกับเมนูใดๆ) -->
    <div id="presentationTopBar" class="d-none bg-dark text-white py-2 px-3 px-md-4 shadow position-fixed top-0 start-0 w-100" style="z-index: 1040;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger rounded-pill px-3 py-1 extra-small">
                    <i class="fa-solid fa-display me-1"></i> โหมดนำเสนอ
                </span>
                <span class="fw-bold small text-truncate text-white" style="max-width: 450px;">
                    ครั้งที่ <?= htmlspecialchars($meeting['meeting_no']); ?>: <?= htmlspecialchars($meeting['title']); ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 extra-small" onclick="prevAgenda()">
                    <i class="fa-solid fa-chevron-up me-1"></i> วาระก่อนหน้า
                </button>
                <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 extra-small" onclick="nextAgenda()">
                    วาระถัดไป <i class="fa-solid fa-chevron-down ms-1"></i>
                </button>
                <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 py-1 fw-bold shadow-xs ms-2" onclick="togglePresentationMode()" title="คลิกหรือกด ESC เพื่อปิดโหมดนำเสนอ">
                    <i class="fa-solid fa-xmark me-1"></i> ปิดโหมดนำเสนอ (ESC)
                </button>
            </div>
        </div>
    </div>

    <!-- Room Header -->
    <div id="meetingHeader" class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <div>
            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb" class="mb-1">
                <ol class="breadcrumb extra-small mb-0">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted"><i class="fa-solid fa-arrow-left me-1"></i> ระบบการประชุม</a></li>
                    <li class="breadcrumb-item active text-purple" aria-current="page">ครั้งที่ <?= htmlspecialchars($meeting['meeting_no']); ?></li>
                </ol>
            </nav>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h3 fw-bold text-dark mb-0">
                    <i class="fa-solid fa-users-viewfinder text-purple me-2"></i> <?= htmlspecialchars($meeting['title']); ?>
                </h1>
                
                <!-- Status Badge -->
                <?php if ($meeting['status'] === 'in_progress'): ?>
                    <span class="badge bg-success rounded-pill px-3 py-1 extra-small pulse-animation">
                        <i class="fa-solid fa-circle text-white extra-small me-1"></i> กำลังประชุม
                    </span>
                <?php elseif ($meeting['status'] === 'completed'): ?>
                    <span class="badge bg-secondary rounded-pill px-3 py-1 extra-small">
                        <i class="fa-solid fa-check me-1"></i> ประชุมเสร็จสิ้นแล้ว
                    </span>
                <?php else: ?>
                    <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1 extra-small">
                        <i class="fa-regular fa-clock me-1"></i> รอการประชุม
                    </span>
                <?php endif; ?>

                <!-- Auto Session Heartbeat Badge -->
                <span id="sessionStatusBadge" class="badge bg-light text-success border border-success-subtle rounded-pill px-3 py-1 extra-small shadow-xs" title="ระบบรักษาเซสชันอัตโนมัติ ไม่ต้องกังวลเซสชันหลุดแม้เปิดใช้งานตลอดการประชุม">
                    <span class="spinner-grow spinner-grow-sm text-success me-1" role="status" style="width:7px; height:7px;"></span>
                    <span id="sessionStatusText">เซสชันออนไลน์</span>
                </span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="btn-toolbar mb-2 mb-md-0 d-print-none gap-2 mt-2 mt-md-0 position-relative" style="z-index: 1045;">
            <!-- Links for Admin/Secretary (วางไว้ด้านซ้ายของ Toolbar เพื่อไม่ให้ Dropdown หล่นทับ สารบัญวาระ) -->
            <?php if ($can_edit): ?>
                <div class="dropdown">
                    <button class="btn btn-purple dropdown-toggle rounded-pill px-3 py-2 fw-bold shadow-xs" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-gear me-1"></i> จัดการการประชุม
                    </button>
                    <ul class="dropdown-menu dropdown-menu-start shadow-lg border-0 rounded-3 p-2 mt-1" style="z-index: 1055; min-width: 240px; box-shadow: 0 10px 30px rgba(0,0,0,0.18) !important;">
                        <li>
                            <a class="dropdown-item small rounded-2 py-2" href="manage_agendas.php?id=<?= $meeting_id; ?>">
                                <i class="fa-solid fa-list-check me-2 text-primary"></i> จัดการระเบียบวาระ
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item small rounded-2 py-2" href="manage_agendas.php?id=<?= $meeting_id; ?>&open=attendees">
                                <i class="fa-solid fa-users-gear me-2 text-purple"></i> จัดการผู้เข้าร่วม (สิทธิ์การเข้าถึง)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item small rounded-2 py-2" href="draft_minutes.php?id=<?= $meeting_id; ?>">
                                <i class="fa-solid fa-file-lines me-2 text-info"></i> ร่างรายงานการประชุม
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item small rounded-2 py-2" href="print_minutes.php?id=<?= $meeting_id; ?>" target="_blank">
                                <i class="fa-solid fa-print me-2 text-success"></i> พิมพ์รายงานการประชุม
                            </a>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        
                        <!-- Change Status Controls -->
                        <?php if ($meeting['status'] !== 'in_progress'): ?>
                            <li>
                                <form method="POST" action="" class="m-0">
                                    <input type="hidden" name="action" value="update_meeting_status">
                                    <input type="hidden" name="new_status" value="in_progress">
                                    <button type="submit" class="dropdown-item small rounded-2 py-2 text-success fw-bold">
                                        <i class="fa-solid fa-play me-2"></i> เริ่มดำเนินการประชุม
                                    </button>
                                </form>
                            </li>
                        <?php endif; ?>

                        <?php if ($meeting['status'] !== 'completed'): ?>
                            <li>
                                <form method="POST" action="" class="m-0" onsubmit="return confirm('ยืนยันปิดการประชุมและปรับสถานะเป็นเสร็จสิ้นแล้ว?');">
                                    <input type="hidden" name="action" value="update_meeting_status">
                                    <input type="hidden" name="new_status" value="completed">
                                    <button type="submit" class="dropdown-item small rounded-2 py-2 text-primary fw-bold">
                                        <i class="fa-solid fa-check-double me-2"></i> สิ้นสุดการประชุม
                                    </button>
                                </form>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Presentation Mode -->
            <button id="btnPresentation" class="btn btn-dark shadow-xs rounded-pill px-3 py-2 fw-bold" onclick="togglePresentationMode()">
                <i class="fa-solid fa-expand me-1"></i> โหมดนำเสนอ
            </button>

            <!-- Print -->
            <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-xs">
                <i class="fa-solid fa-print me-1"></i> พิมพ์
            </button>
        </div>
    </div>

    <!-- Main Content Layout -->
    <div class="row g-4">
        <!-- Content Column (Agendas List) -->
        <div id="contentCol" class="col-lg-9 order-2 order-lg-1">
            
            <!-- Meeting Info Summary Card -->
            <div id="meetingSummary" class="card border-0 shadow-xs rounded-4 bg-white mb-4">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <!-- ฝั่งซ้าย: ข้อมูลวัน เวลา สถานที่ Zoom -->
                        <div class="col-md-6 border-end-md">
                            <div class="d-flex align-items-center text-dark small mb-2">
                                <div style="width: 28px;"><i class="fa-regular fa-calendar-check text-purple"></i></div>
                                <div><strong>วันที่:</strong> <?= thaiDateFull($meeting['meeting_date']); ?></div>
                            </div>
                            <div class="d-flex align-items-center text-dark small mb-2">
                                <div style="width: 28px;"><i class="fa-regular fa-clock text-warning"></i></div>
                                <div><strong>เวลา:</strong> <?= date('H:i', strtotime($meeting['start_time'])); ?> น. - <?= $meeting['end_time'] ? date('H:i', strtotime($meeting['end_time'])) . ' น.' : 'จนกว่าจะเสร็จสิ้น'; ?></div>
                            </div>
                            <div class="d-flex align-items-center text-dark small mb-3">
                                <div style="width: 28px;"><i class="fa-solid fa-location-dot text-danger"></i></div>
                                <div><strong>สถานที่:</strong> <?= htmlspecialchars($meeting['location'] ?: 'ไม่ระบุสถานที่'); ?></div>
                            </div>

                            <?php if (!empty($zoom_info)): ?>
                                <div class="p-3 bg-light rounded-3 border border-info border-opacity-25">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-bold text-primary"><i class="fa-solid fa-video me-1"></i> ห้องประชุม Zoom Online</span>
                                        <a href="<?= htmlspecialchars($zoom_info['meeting_url']); ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 extra-small shadow-xs">
                                            <i class="fa-solid fa-link me-1"></i> เข้าร่วม Zoom
                                        </a>
                                    </div>
                                    <div class="extra-small text-muted"><strong>Meeting ID:</strong> <?= htmlspecialchars($zoom_info['zoom_meeting_id']); ?></div>
                                    <div class="extra-small text-muted"><strong>Passcode:</strong> <?= htmlspecialchars($zoom_info['passcode']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ฝั่งขวา: รายชื่อผู้เข้าร่วมประชุมและการตอบรับ -->
                        <div class="col-md-6 ps-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-bold text-dark">ผู้เข้าร่วมประชุม (<?= count($attendees); ?> ท่าน)</span>
                                <div class="extra-small text-muted">
                                    <span class="badge bg-success-subtle text-success border me-1">Onsite: <?= $count_onsite; ?></span>
                                    <span class="badge bg-info-subtle text-info border me-1">Online: <?= $count_online; ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary border">ลา: <?= $count_absent; ?></span>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-1" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($attendees as $att): 
                                    $badge_class = 'bg-light text-muted border text-decoration-line-through';
                                    $icon = '<i class="fa-solid fa-xmark text-secondary me-1"></i>';
                                    
                                    if ($att['attendance_status'] === 'pending') {
                                        $badge_class = 'bg-warning-subtle text-dark border border-warning-subtle';
                                        $icon = '<i class="fa-solid fa-hourglass-half text-warning me-1"></i>';
                                    } elseif ($att['attendance_status'] === 'attending') {
                                        if ($att['attend_mode'] === 'online') {
                                            $badge_class = 'bg-info-subtle text-info border border-info-subtle';
                                            $icon = '<i class="fa-solid fa-video me-1"></i>';
                                        } else {
                                            $badge_class = 'bg-success-subtle text-success border border-success-subtle';
                                            $icon = '<i class="fa-solid fa-street-view me-1"></i>';
                                        }
                                    }
                                ?>
                                    <span class="badge fw-normal px-2 py-1 rounded-pill extra-small <?= $badge_class; ?>" title="<?= htmlspecialchars($att['first_name'] . ' ' . $att['last_name'] . ($att['absence_reason'] ? ' (เหตุผล: ' . $att['absence_reason'] . ')' : '')); ?>">
                                        <?= $icon . htmlspecialchars($att['first_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Agendas List Area -->
            <div class="agenda-scroll-area">
                <?php if (empty($agendas)): ?>
                    <div class="card border-0 shadow-xs rounded-4 bg-white p-5 text-center">
                        <i class="fa-solid fa-folder-open fa-3x text-muted opacity-25 mb-3"></i>
                        <h5 class="fw-bold text-dark mb-1">ยังไม่มีระเบียบวาระการประชุม</h5>
                        <p class="text-muted small mb-3">คุณสามารถเพิ่มวาระการประชุมได้ทันที</p>
                        <?php if ($can_edit): ?>
                            <div>
                                <button class="btn btn-sm btn-purple rounded-pill px-4 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#addAgendaModal">
                                    <i class="fa-solid fa-plus me-1"></i> เพิ่มวาระการประชุม
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($agendas as $a): 
                        $a_id = $a['agenda_id']; 
                        $has_res = !empty(trim($a['resolution'] ?? ''));
                    ?>
                        <div id="agenda_<?= $a_id; ?>" class="card border-0 shadow-xs rounded-4 bg-white mb-4 agenda-card position-relative overflow-hidden" style="scroll-margin-top: 80px;">
                            <!-- Header วาระ -->
                            <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between" style="border-left: 5px solid #6f42c1 !important;">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="badge bg-purple fs-6 px-3 py-2 rounded-3 shadow-xs">
                                        วาระที่ <?= htmlspecialchars($a['agenda_number']); ?>
                                    </span>
                                    <h5 class="fw-bold text-dark mb-0 agenda-title-text" style="line-height: 1.4;">
                                        <?= htmlspecialchars($a['agenda_title']); ?>
                                    </h5>
                                </div>
                                <?php if ($has_res): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 extra-small">
                                        <i class="fa-solid fa-check-circle me-1"></i> มีมติแล้ว
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="card-body p-4 p-md-4">
                                <!-- รายละเอียด / ข้อเท็จจริง -->
                                <?php if (!empty($a['agenda_detail'])): ?>
                                    <div class="mb-4">
                                        <h6 class="fw-bold text-muted extra-small text-uppercase mb-2">รายละเอียด / ข้อเท็จจริง</h6>
                                        <?php if ($a['agenda_detail'] !== strip_tags($a['agenda_detail'])): ?>
                                            <div class="text-dark agenda-detail-text table-responsive custom-rich-content" style="line-height: 1.8; font-size: 1.05rem;">
                                                <?= $a['agenda_detail']; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-dark agenda-detail-text" style="line-height: 1.8; text-align: justify; font-size: 1.05rem;">
                                                <?= nl2br(htmlspecialchars($a['agenda_detail'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- ประเด็นเสนอ -->
                                <?php if (!empty($a['proposal'])): ?>
                                    <div class="p-3 mb-4 rounded-3 border-start border-primary border-4" style="background-color: #f0f9ff;">
                                        <h6 class="fw-bold text-primary extra-small text-uppercase mb-1">ประเด็นเสนอ</h6>
                                        <?php if ($a['proposal'] !== strip_tags($a['proposal'])): ?>
                                            <div class="text-dark fw-bold table-responsive custom-rich-content" style="font-size: 1.05rem; line-height: 1.6;">
                                                <?= $a['proposal']; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-dark fw-bold" style="font-size: 1.05rem; line-height: 1.6;">
                                                <?= nl2br(htmlspecialchars($a['proposal'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- เอกสารแนบในวาระนี้ -->
                                <?php if (!empty($attachments[$a_id])): ?>
                                    <div class="bg-light p-3 rounded-3 mb-4 border d-print-none">
                                        <div class="small fw-bold text-muted mb-2">
                                            <i class="fa-solid fa-paperclip text-purple me-1"></i> เอกสารแนบวาระนี้ (คลิกเพื่อเปิดดูควบคู่กับการประชุม):
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($attachments[$a_id] as $file): 
                                                $f_id = $file['attachment_id'];
                                                $f_url = buildMeetingFileUrl($file['file_path']);
                                                $f_path = htmlspecialchars($f_url, ENT_QUOTES);
                                                $f_name = htmlspecialchars($file['file_name']);
                                                $f_ext_btn = detectMeetingFileExt($file['file_name'], $file['file_path']);
                                            ?>
                                                <div class="btn-group shadow-xs rounded-pill overflow-hidden border bg-white" role="group">
                                                    <!-- คลิกเพื่อเปิดขยายเต็มจอ + กล่องพิมพ์บันทึกมติควบคู่กัน -->
                                                    <button type="button" class="btn btn-white btn-sm fw-bold px-3 py-1 text-purple border-0 extra-small hover-bg" 
                                                            data-agenda-id="<?= $a_id; ?>"
                                                            data-agenda-number="<?= htmlspecialchars($a['agenda_number'], ENT_QUOTES); ?>"
                                                            data-agenda-title="<?= htmlspecialchars($a['agenda_title'], ENT_QUOTES); ?>"
                                                            data-agenda-proposal="<?= htmlspecialchars($a['proposal'] ?? '', ENT_QUOTES); ?>"
                                                            data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES); ?>"
                                                            data-file-path="<?= $f_path; ?>"
                                                            data-file-ext="<?= htmlspecialchars($f_ext_btn, ENT_QUOTES); ?>"
                                                            onclick="openSplitDocFromBtn(this)"
                                                            title="คลิกเพื่อเปิดดูเอกสารแบบขยายเต็มจอ พร้อมกล่องพิมพ์บันทึกมติที่ประชุม">
                                                        <i class="fa-solid fa-expand text-purple me-1"></i> <?= $f_name; ?>
                                                    </button>
                                                    <!-- ปุ่มเปิด/ปิดแบบย่อในหน้านี้ -->
                                                    <button type="button" class="btn btn-light btn-sm px-2 text-muted border-start extra-small"
                                                            data-bs-toggle="collapse" data-bs-target="#collapse_doc_<?= $f_id; ?>" aria-expanded="false"
                                                            title="เปิด/ปิดดูแบบย่อในหน้านี้">
                                                        <i class="fa-solid fa-chevron-down"></i>
                                                    </button>
                                                    <!-- เปิดแท็บใหม่ -->
                                                    <a href="<?= $f_path; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-light btn-sm px-2 text-muted border-start extra-small" title="เปิดแท็บใหม่">
                                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <!-- กรอบ In-Card Preview ของไฟล์แนบ -->
                                        <?php foreach ($attachments[$a_id] as $file): 
                                            $f_id = $file['attachment_id'];
                                            $f_url = buildMeetingFileUrl($file['file_path']);
                                            $f_path = htmlspecialchars($f_url, ENT_QUOTES);
                                            $f_name = htmlspecialchars($file['file_name']);
                                            $f_ext = detectMeetingFileExt($file['file_name'], $file['file_path']);
                                        ?>
                                            <div class="collapse mt-3" id="collapse_doc_<?= $f_id; ?>">
                                                <div class="card card-body p-0 border shadow-xs rounded-3 overflow-hidden">
                                                    <div class="bg-dark text-white px-3 py-2 d-flex justify-content-between align-items-center small">
                                                        <span>
                                                            <?php if ($f_ext === 'pdf'): ?>
                                                                <i class="fa-regular fa-file-pdf text-danger me-1"></i>
                                                            <?php elseif (in_array($f_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])): ?>
                                                                <i class="fa-regular fa-file-image text-info me-1"></i>
                                                            <?php elseif (in_array($f_ext, ['doc', 'docx'])): ?>
                                                                <i class="fa-regular fa-file-word text-primary me-1"></i>
                                                            <?php elseif (in_array($f_ext, ['xls', 'xlsx', 'csv'])): ?>
                                                                <i class="fa-regular fa-file-excel text-success me-1"></i>
                                                            <?php else: ?>
                                                                <i class="fa-regular fa-file text-secondary me-1"></i>
                                                            <?php endif; ?>
                                                            <strong><?= $f_name; ?></strong>
                                                        </span>
                                                        <div class="d-flex gap-2 align-items-center">
                                                            <button type="button" class="btn btn-sm btn-purple py-0 px-2 extra-small rounded-pill fw-bold" 
                                                                    data-agenda-id="<?= $a_id; ?>"
                                                                    data-agenda-number="<?= htmlspecialchars($a['agenda_number'], ENT_QUOTES); ?>"
                                                                    data-agenda-title="<?= htmlspecialchars($a['agenda_title'], ENT_QUOTES); ?>"
                                                                    data-agenda-proposal="<?= htmlspecialchars($a['proposal'] ?? '', ENT_QUOTES); ?>"
                                                                    data-file-name="<?= htmlspecialchars($file['file_name'], ENT_QUOTES); ?>"
                                                                    data-file-path="<?= $f_path; ?>"
                                                                    data-file-ext="<?= htmlspecialchars($f_ext, ENT_QUOTES); ?>"
                                                                    onclick="openSplitDocFromBtn(this)">
                                                                <i class="fa-solid fa-expand me-1"></i> ขยายเต็มจอ + บันทึกมติ
                                                            </button>
                                                            <a href="<?= $f_path; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-light py-0 px-2 extra-small rounded-pill">เปิดแท็บใหม่ <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                                                            <button type="button" class="btn btn-sm btn-link text-white p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#collapse_doc_<?= $f_id; ?>"><i class="fa-solid fa-xmark"></i> ปิด</button>
                                                        </div>
                                                    </div>

                                                    <?php if ($f_ext === 'pdf'): ?>
                                                        <div style="height: 540px; width: 100%; background: #525659;">
                                                            <iframe data-src="<?= $f_path; ?>#toolbar=1&view=FitH" src="about:blank" style="width: 100%; height: 100%; border: none; display: block;" class="lazy-doc-iframe" title="<?= $f_name; ?>"></iframe>
                                                        </div>
                                                    <?php elseif (in_array($f_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])): ?>
                                                        <div class="text-center p-3" style="background: #1a1a1a; max-height: 540px; overflow: auto;">
                                                            <img src="<?= $f_path; ?>" class="img-fluid rounded shadow-sm" alt="<?= $f_name; ?>" style="max-height: 500px; object-fit: contain;">
                                                        </div>
                                                    <?php elseif ($f_ext === 'docx'): ?>
                                                        <!-- ส่วนแสดงไฟล์ Word (.docx) In-Card -->
                                                        <div class="position-relative bg-secondary bg-opacity-25" style="height: 550px; overflow-y: auto;">
                                                            <div class="docx-inline-spinner position-absolute top-50 start-50 translate-middle text-center text-muted">
                                                                <div class="spinner-border text-primary mb-2" role="status"></div>
                                                                <div class="small fw-bold">กำลังเปิดเอกสาร Word (.docx)...</div>
                                                            </div>
                                                            <div class="p-3 docx-render-container docx-inline-container" data-file-path="<?= $f_path; ?>" data-loaded="0"></div>
                                                        </div>
                                                    <?php elseif (in_array($f_ext, ['xlsx', 'xls', 'csv'])): ?>
                                                        <!-- ส่วนแสดงไฟล์ Excel (.xlsx, .xls, .csv) In-Card -->
                                                        <div class="position-relative bg-white d-flex flex-column overflow-hidden" style="height: 550px;">
                                                            <div class="xlsx-inline-spinner position-absolute top-50 start-50 translate-middle text-center text-muted" style="z-index: 10;">
                                                                <div class="spinner-border text-success mb-2" role="status"></div>
                                                                <div class="small fw-bold">กำลังเปิดไฟล์ตาราง Excel...</div>
                                                            </div>
                                                            <div class="xlsx-render-container xlsx-inline-container flex-grow-1 overflow-auto p-2" data-file-path="<?= $f_path; ?>" data-loaded="0"></div>
                                                            <div class="xlsx-inline-tabs bg-light border-top px-3 py-1 d-flex gap-1 overflow-auto flex-shrink-0 align-items-center" style="min-height: 42px;"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="p-4 text-center bg-white">
                                                            <div class="my-3">
                                                                <?php if (in_array($f_ext, ['doc'])): ?>
                                                                    <i class="fa-regular fa-file-word text-primary fa-4x"></i>
                                                                <?php elseif (in_array($f_ext, ['ppt', 'pptx'])): ?>
                                                                    <i class="fa-regular fa-file-powerpoint text-warning fa-4x"></i>
                                                                <?php else: ?>
                                                                    <i class="fa-regular fa-file-lines text-secondary fa-4x"></i>
                                                                <?php endif; ?>
                                                            </div>
                                                            <h6 class="fw-bold text-dark mb-2"><?= $f_name; ?></h6>
                                                            <p class="text-muted small mb-3">ไฟล์เอกสารประเภทนี้ไม่สามารถแสดงตัวอย่างบนหน้าเว็บได้โดยตรง สามารถเปิดดูหรือดาวน์โหลดได้ที่ปุ่มด้านล่าง</p>
                                                            <a href="<?= $f_path; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary rounded-pill px-4 fw-bold shadow-xs">
                                                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิด / ดาวน์โหลดไฟล์
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Live Resolution & Assignee Editor (สำหรับ Admin/ธุรการ) -->
                                <?php if ($can_edit): ?>
                                    <div class="mt-3 p-3 p-md-4 rounded-3 border border-success border-opacity-25 bg-success bg-opacity-10 d-print-none shadow-xs">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="fw-bold text-success small mb-0">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> บันทึกมติที่ประชุม (Live Note)
                                            </label>
                                            <div class="d-flex align-items-center gap-2">
                                                <span id="draft_status_<?= $a_id; ?>" class="extra-small text-muted"></span>
                                                <?php if (!empty($attachments[$a_id])): 
                                                    $first_file = reset($attachments[$a_id]);
                                                    $first_url = buildMeetingFileUrl($first_file['file_path']);
                                                    $first_ext = detectMeetingFileExt($first_file['file_name'], $first_file['file_path']);
                                                ?>
                                                    <button type="button" class="btn btn-purple btn-sm py-0 px-2 extra-small rounded-pill shadow-xs"
                                                            data-agenda-id="<?= $a_id; ?>"
                                                            data-agenda-number="<?= htmlspecialchars($a['agenda_number'], ENT_QUOTES); ?>"
                                                            data-agenda-title="<?= htmlspecialchars($a['agenda_title'], ENT_QUOTES); ?>"
                                                            data-agenda-proposal="<?= htmlspecialchars($a['proposal'] ?? '', ENT_QUOTES); ?>"
                                                            data-file-name="<?= htmlspecialchars($first_file['file_name'], ENT_QUOTES); ?>"
                                                            data-file-path="<?= htmlspecialchars($first_url, ENT_QUOTES); ?>"
                                                            data-file-ext="<?= htmlspecialchars($first_ext, ENT_QUOTES); ?>"
                                                            onclick="openSplitDocFromBtn(this)"
                                                            title="เปิดดูเอกสารพร้อมพิมพ์บันทึกมติแบบเต็มจอ">
                                                        <i class="fa-solid fa-expand me-1"></i> เปิดดูเอกสารคู่กับมติ
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <textarea id="res_<?= $a_id; ?>" class="form-control border-success mb-3" rows="3" placeholder="พิมพ์มติที่ประชุม ณ เวลานี้..." oninput="saveLocalDraft(<?= $a_id; ?>)" style="font-size: 1.05rem;"><?= htmlspecialchars($a['resolution'] ?? ''); ?></textarea>
                                        
                                        <!-- ผู้รับผิดชอบ (Modern Dropdown Checkbox Selector) -->
                                        <?php 
                                            $assigned_arr = array_filter(array_map('trim', explode(',', $a['assigned_to'] ?? '')));
                                        ?>
                                        <label class="fw-bold text-success small mb-1">
                                            <i class="fa-solid fa-user-check me-1"></i> มอบหมายผู้รับผิดชอบ:
                                        </label>
                                        <div class="dropdown w-100 mb-3">
                                            <button class="btn btn-white border border-success dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center bg-white rounded-3 shadow-xs" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                                <span id="assign_label_<?= $a_id; ?>">
                                                    <i class="fa-solid fa-users text-success me-1"></i> 
                                                    <?= count($assigned_arr) > 0 ? 'เลือกแล้ว ' . count($assigned_arr) . ' ท่าน' : 'คลิกเพื่อเลือกผู้รับผิดชอบ...'; ?>
                                                </span>
                                            </button>
                                            <div class="dropdown-menu p-3 shadow-lg border-0 rounded-4 w-100" style="max-height: 280px; overflow-y: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="ค้นหาชื่อ..." onkeyup="filterAssignees(this, <?= $a_id; ?>)">
                                                <div class="assignee-list" id="assign_list_<?= $a_id; ?>">
                                                    <?php foreach ($attendees as $att): 
                                                        $is_checked = in_array((string)$att['user_id'], $assigned_arr);
                                                    ?>
                                                    <div class="form-check py-1">
                                                        <input class="form-check-input assign-check-<?= $a_id; ?>" type="checkbox" value="<?= $att['user_id']; ?>" id="assign_<?= $a_id; ?>_<?= $att['user_id']; ?>" <?= $is_checked ? 'checked' : ''; ?> onchange="updateAssignLabel(<?= $a_id; ?>)">
                                                        <label class="form-check-label small" for="assign_<?= $a_id; ?>_<?= $att['user_id']; ?>">
                                                            <?= htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="button" class="btn btn-success fw-bold px-4 rounded-pill w-100 shadow-xs" onclick="saveResolution(<?= $a_id; ?>)">
                                            <i class="fa-solid fa-save me-1"></i> บันทึกมติและผู้รับผิดชอบ
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <!-- แสดงมติและผู้รับผิดชอบ (สำหรับผู้เข้าร่วมทั่วไป หรือเวลาสั่งพิมพ์) -->
                                <?php if ($has_res || !empty($a['assigned_to'])): ?>
                                    <div class="resolution-box mt-3 p-3 p-md-4 rounded-3 <?= $can_edit ? 'd-none d-print-block' : ''; ?> border-start border-success border-4" style="background-color: #f0fdf4;">
                                        <?php if ($has_res): ?>
                                            <h6 class="fw-bold text-success extra-small text-uppercase mb-2"><i class="fa-solid fa-circle-check me-1"></i> มติที่ประชุม</h6>
                                            <?php if ($a['resolution'] !== strip_tags($a['resolution'])): ?>
                                                <div class="text-dark fw-bold mb-3 table-responsive custom-rich-content" style="font-size: 1.1rem; line-height: 1.6;">
                                                    <?= $a['resolution']; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-dark fw-bold mb-3" style="font-size: 1.1rem; line-height: 1.6;">
                                                    <?= nl2br(htmlspecialchars($a['resolution'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($a['assigned_to'])): ?>
                                            <h6 class="fw-bold text-success extra-small text-uppercase mb-1"><i class="fa-solid fa-user-check me-1"></i> ผู้ได้รับมอบหมาย</h6>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php 
                                                $assigned_arr = explode(',', $a['assigned_to'] ?? '');
                                                foreach ($attendees as $att) {
                                                    if (in_array((string)$att['user_id'], $assigned_arr)) {
                                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill extra-small"><i class="fa-solid fa-user me-1"></i>' . htmlspecialchars($att['first_name'] . ' ' . $att['last_name']) . '</span>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- ปุ่มเพิ่มวาระแทรกด้านล่าง -->
                <?php if ($can_edit): ?>
                    <div class="text-center mt-4 mb-5 d-print-none">
                        <button class="btn btn-outline-purple border-dashed shadow-xs rounded-pill px-5 py-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addAgendaModal">
                            <i class="fa-solid fa-plus-circle fa-lg me-2"></i> สร้างวาระแทรก / เรื่องอื่นๆ
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sticky Sidebar Column (สารบัญวาระ TOC) -->
        <div id="sidebarCol" class="col-lg-3 order-1 order-lg-2 d-print-none">
            <div id="tocSticky" class="card border-0 shadow-xs rounded-4 bg-white sticky-top" style="top: 80px; z-index: 10;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-list-ul text-purple me-2"></i> สารบัญวาระ
                    </h6>
                    <span class="badge bg-purple-subtle text-purple rounded-pill extra-small"><?= count($agendas); ?> วาระ</span>
                </div>
                <div id="agendaTocList" class="list-group list-group-flush p-2" style="max-height: 65vh; overflow-y: auto;">
                    <?php if (empty($agendas)): ?>
                        <div class="p-3 text-center text-muted small">ไม่มีวาระ</div>
                    <?php else: ?>
                        <?php foreach ($agendas as $a): 
                            $has_res_toc = !empty(trim($a['resolution'] ?? ''));
                        ?>
                            <div class="list-group-item list-group-item-action py-2 px-3 small toc-item d-flex align-items-center rounded-3 mb-1 border-0" id="toc_<?= $a['agenda_id']; ?>">
                                <?php if ($can_edit): ?>
                                    <div class="d-flex flex-column me-2">
                                        <button type="button" class="btn btn-sm btn-link p-0 text-muted" onclick="moveAgendaUp(<?= $a['agenda_id']; ?>)" title="เลื่อนขึ้น"><i class="fa-solid fa-chevron-up fa-xs"></i></button>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-muted" onclick="moveAgendaDown(<?= $a['agenda_id']; ?>)" title="เลื่อนลง"><i class="fa-solid fa-chevron-down fa-xs"></i></button>
                                    </div>
                                <?php endif; ?>
                                <a href="#agenda_<?= $a['agenda_id']; ?>" class="text-decoration-none flex-grow-1 text-dark">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-purple extra-small">วาระที่ <?= htmlspecialchars($a['agenda_number']); ?></span>
                                        <?php if ($has_res_toc): ?>
                                            <i class="fa-solid fa-circle-check text-success extra-small" title="มีมติแล้ว"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-truncate text-muted extra-small"><?= htmlspecialchars($a['agenda_title']); ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($can_edit): ?>
                    <div class="card-footer bg-white border-0 pt-0 pb-3 px-3">
                        <button class="btn btn-sm btn-outline-purple w-100 rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#addAgendaModal">
                            <i class="fa-solid fa-plus me-1"></i> เพิ่มวาระเร่งด่วน
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Modal: เพิ่มวาระเร่งด่วน -->
<?php if ($can_edit): ?>
<div class="modal fade" id="addAgendaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>เพิ่มวาระเร่งด่วน</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="add_agenda">
                <div class="row g-3">
                    <div class="col-4">
                        <label class="small fw-bold">เลขวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_number" class="form-control rounded-3" placeholder="เช่น 4.1" required>
                    </div>
                    <div class="col-8">
                        <label class="small fw-bold">หัวข้อวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_title" class="form-control rounded-3" placeholder="ระบุหัวข้อวาระ..." required>
                    </div>
                    <div class="col-12">
                        <label class="small fw-bold">รายละเอียด / ข้อเท็จจริง</label>
                        <textarea name="agenda_detail" class="form-control rounded-3" rows="3" placeholder="ระบุรายละเอียด..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="small fw-bold text-primary">ประเด็นเสนอ</label>
                        <textarea name="proposal" class="form-control rounded-3 border-primary" rows="2" placeholder="เช่น จึงเสนอที่ประชุมเพื่อโปรดพิจารณา"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold">บันทึกวาระ</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Fullscreen Document & Live Resolution Split View Modal (เปิดดูเอกสารเต็มจอควบคู่กับการบันทึกมติที่ประชุม) -->
<div class="modal fade" id="docSplitModal" tabindex="-1" aria-labelledby="docSplitModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content border-0 bg-dark">
            <!-- Modal Header -->
            <div class="modal-header bg-dark text-white border-bottom border-secondary border-opacity-25 py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <span class="badge bg-purple px-2 py-1 small rounded-pill" id="splitModalAgendaBadge">วาระที่ ...</span>
                    <h6 class="modal-title fw-bold text-truncate mb-0 text-white" id="splitModalAgendaTitle" style="max-width: 50vw;">
                        ...
                    </h6>
                    <span class="badge bg-secondary bg-opacity-75 text-white px-2 py-1 extra-small text-truncate rounded-pill d-none d-md-inline-block" id="splitModalFileName" style="max-width: 25vw;">
                        <i class="fa-regular fa-file-pdf text-danger me-1"></i> ...
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="splitModalNewTabBtn" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-light rounded-pill px-3 extra-small">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดแท็บใหม่
                    </a>
                    <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 py-1 fw-bold shadow-xs" data-bs-dismiss="modal" title="ปิดหน้าต่างขยาย (ESC)">
                        <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
                    </button>
                </div>
            </div>

            <!-- Modal Body (Split Screen: เอกสารซ้าย 67% | บันทึกมติขวา 33%) -->
            <div class="modal-body p-0 overflow-hidden bg-light" style="height: calc(100vh - 54px);">
                <div class="row g-0 h-100">
                    <!-- ฝั่งซ้าย: ตัวแสดงไฟล์เอกสาร (Full-Height Document Viewer สำหรับ PDF, Word, Excel, รูปภาพ) -->
                    <div class="col-md-7 col-lg-8 split-col-viewer h-100 d-flex flex-column bg-secondary bg-opacity-25 border-end overflow-hidden">
                        <!-- แถบสลับไฟล์แนบในวาระเดียวกัน (แสดงอัตโนมัติเมื่อวาระมีไฟล์แนบมากกว่า 1 ไฟล์) -->
                        <div id="splitAgendaFilesBar" class="split-files-bar px-3 py-1 border-bottom align-items-center gap-2 overflow-auto flex-shrink-0"></div>

                        <div class="flex-grow-1 position-relative overflow-hidden" id="splitViewerParent" style="min-height: 0;">
                            <!-- Loading Spinner -->
                            <div id="splitLoadingSpinner" class="position-absolute top-50 start-50 translate-middle text-center text-muted bg-white bg-opacity-90 px-4 py-3 rounded-4 shadow-sm" style="z-index: 25; display: none;">
                                <div class="spinner-border text-purple mb-2" role="status"></div>
                                <div class="small fw-bold text-dark" id="splitLoadingText">กำลังโหลดเอกสาร...</div>
                            </div>

                            <!-- หมายเหตุ: แต่ละ pane สลับแสดงด้วยคลาส .is-active เท่านั้น (ห้ามใส่ d-flex/d-block ของ Bootstrap เพราะเป็น !important จะทับการซ่อน) -->

                            <!-- 1. Iframe สำหรับ PDF -->
                            <iframe id="splitDocIframe" class="split-pane" src="about:blank" title="PDF Viewer"></iframe>

                            <!-- 2. Container สำหรับรูปภาพ -->
                            <div id="splitImgContainer" class="split-pane split-pane-flex split-img-pane">
                                <img id="splitImgElement" alt="เอกสารรูปภาพ">
                            </div>

                            <!-- 3. Container สำหรับไฟล์ Word (.docx) -->
                            <div id="splitDocxContainer" class="split-pane docx-render-container"></div>

                            <!-- 4. Container สำหรับไฟล์ Excel (.xlsx, .xls, .csv) -->
                            <div id="splitXlsxWrapper" class="split-pane split-pane-flex split-xlsx-pane bg-white">
                                <div id="splitXlsxContainer" class="flex-grow-1 overflow-auto p-3 xlsx-render-container"></div>
                                <div id="splitXlsxTabs" class="bg-light border-top px-3 py-2 d-flex gap-2 overflow-auto flex-shrink-0 align-items-center" style="min-height: 44px;"></div>
                            </div>

                            <!-- 5. Notice สำหรับไฟล์ที่ไม่รองรับการดูในเบราว์เซอร์ -->
                            <div id="splitNonPreviewNotice" class="split-pane split-pane-flex split-notice-pane"></div>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: แผงข้อมูลวาระและกล่องพิมพ์บันทึกมติที่ประชุม (Agenda & Live Resolution Panel) -->
                    <div class="col-md-5 col-lg-4 split-col-editor h-100 bg-white d-flex flex-column shadow-sm overflow-hidden border-start">
                        <!-- Scrollable Content Area -->
                        <div class="flex-grow-1 p-3 p-md-4 overflow-y-auto custom-scrollbar">
                            
                            <!-- การ์ดสรุปประเด็นวาระ -->
                            <div class="card border-0 bg-light rounded-4 p-3 mb-3 shadow-xs">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="badge bg-purple-subtle text-purple fw-bold extra-small">
                                        <i class="fa-solid fa-file-lines me-1"></i> ข้อมูลวาระ
                                    </span>
                                    <span class="text-muted extra-small">ครั้งที่ <?= htmlspecialchars($meeting['meeting_no']); ?></span>
                                </div>
                                <h6 class="fw-bold text-dark mb-2" id="splitModalSideTitle">...</h6>
                                <div id="splitModalSideProposal" class="small text-muted border-start border-purple border-3 ps-2 py-1 bg-white rounded-end" style="line-height: 1.6;">
                                    ...
                                </div>
                            </div>

                            <?php if ($can_edit): ?>
                                <!-- กล่องพิมพ์บันทึกมติที่ประชุม (Live Note Editor) -->
                                <div class="p-3 rounded-4 border border-success border-opacity-50 bg-success bg-opacity-10 mb-3 shadow-xs">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="fw-bold text-success small mb-0">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> บันทึกมติที่ประชุม (พิมพ์ควบคู่กับเอกสาร)
                                        </label>
                                        <span id="splitModalSaveStatus" class="extra-small"></span>
                                    </div>
                                    <textarea id="splitModalResText" class="form-control border-success mb-3 shadow-xs" rows="8" placeholder="พิมพ์มติที่ประชุม ณ เวลานี้..." style="font-size: 1rem; line-height: 1.6;"></textarea>
                                    
                                    <!-- ผู้รับผิดชอบ (Dropdown Selector ใน Modal) -->
                                    <label class="fw-bold text-success small mb-1">
                                        <i class="fa-solid fa-user-check me-1"></i> มอบหมายผู้รับผิดชอบ:
                                    </label>
                                    <div class="dropdown w-100 mb-3">
                                        <button class="btn btn-white border border-success dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center bg-white rounded-3 shadow-xs" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                            <span id="split_assign_label">
                                                <i class="fa-solid fa-users text-success me-1"></i> คลิกเพื่อเลือกผู้รับผิดชอบ...
                                            </span>
                                        </button>
                                        <div class="dropdown-menu p-3 shadow-lg border-0 rounded-4 w-100" style="max-height: 250px; overflow-y: auto;">
                                            <input type="text" class="form-control form-control-sm mb-2" placeholder="ค้นหาชื่อ..." onkeyup="filterSplitAssignees(this)">
                                            <div id="split_assign_list">
                                                <!-- ผู้รับผิดชอบจะถูกสร้างไดนามิกผ่าน JS -->
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-success fw-bold px-3 rounded-pill flex-grow-1 shadow-sm py-2" id="btnSaveSplitResolution" onclick="saveSplitModalResolution()">
                                            <i class="fa-solid fa-save me-1"></i> บันทึกมติและผู้รับผิดชอบ
                                        </button>
                                        <button type="button" class="btn btn-outline-danger fw-bold px-3 rounded-pill py-2 shadow-xs" data-bs-dismiss="modal" title="ปิดหน้าต่างนี้">
                                            <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- สำหรับผู้เข้าร่วมทั่วไป: แสดงมติที่ประชุมที่ถูกบันทึก -->
                                <div id="splitModalViewOnlyResolution" class="p-3 rounded-4 border border-success border-opacity-25 bg-success bg-opacity-10 mb-3 shadow-xs">
                                    <h6 class="fw-bold text-success extra-small text-uppercase mb-2">
                                        <i class="fa-solid fa-circle-check me-1"></i> มติที่ประชุม
                                    </h6>
                                    <div id="splitModalViewOnlyResText" class="text-dark small fw-bold" style="line-height: 1.6;">
                                        (ยังไม่มีการบันทึกมติ)
                                    </div>
                                    <div class="text-end mt-3 pt-2 border-top">
                                        <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 py-1 fw-bold shadow-xs" data-bs-dismiss="modal">
                                            <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="alert alert-info border-0 shadow-xs rounded-3 small p-2 mb-0">
                                <i class="fa-solid fa-circle-info me-1"></i> <strong>คำแนะนำ:</strong> สามารถเปิดดูเอกสาร เลื่อนหน้า และพิมพ์ข้อความมติลงในกล่องขวามือพร้อมกดบันทึกได้ทันที
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #6f42c1 !important; }
    .bg-purple { background-color: #6f42c1 !important; color: white !important; }
    .bg-purple-subtle { background-color: rgba(111, 66, 193, 0.12) !important; }
    .border-purple-subtle { border-color: rgba(111, 66, 193, 0.25) !important; }
    
    /* สไตล์สำหรับตารางและเนื้อหา Rich Text */
    .custom-rich-content table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-top: 0.5rem;
        margin-bottom: 1rem;
        background-color: #ffffff;
    }
    .custom-rich-content table,
    .custom-rich-content th,
    .custom-rich-content td {
        border: 1px solid #ced4da !important;
    }
    .custom-rich-content th {
        background-color: #f1f3f5 !important;
        color: #212529;
        font-weight: 600;
        padding: 8px 12px;
        text-align: left;
    }
    .custom-rich-content td {
        padding: 8px 12px;
        vertical-align: top;
    }
    .custom-rich-content p:last-child {
        margin-bottom: 0;
    }

    .btn-purple { 
        background-color: #6f42c1; 
        color: white; 
        border: none; 
        transition: all 0.2s ease; 
    }
    .btn-purple:hover { 
        background-color: #59359a; 
        color: white; 
        transform: translateY(-1px); 
        box-shadow: 0 4px 12px rgba(111, 66, 193, 0.25); 
    }

    .btn-outline-purple { 
        color: #6f42c1; 
        border-color: #6f42c1; 
        transition: all 0.2s ease; 
    }
    .btn-outline-purple:hover { 
        background-color: #6f42c1; 
        color: white; 
    }

    .extra-small { font-size: 0.75rem; }
    .shadow-xs { box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); }
    
    .toc-item { border-left: 3px solid transparent; transition: all 0.2s; }
    .toc-item:hover { background-color: #f3e8ff; }
    .toc-item.active { background-color: #f3e8ff !important; border-left-color: #6f42c1 !important; font-weight: bold; }
    
    .border-dashed { border-style: dashed !important; border-width: 2px !important; }
    html { scroll-behavior: smooth; }

    .pulse-animation { animation: pulseGreen 2s infinite; }
    @keyframes pulseGreen {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
        70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }

    /* CSS สำหรับ Presentation Mode (โหมดนำเสนอสำหรับโปรเจกเตอร์/หน้าจอใหญ่) */
    body.presentation header.navbar,
    body.presentation .sidebar,
    body.presentation #sidebarMenu { 
        display: none !important; 
    }
    body.presentation #presentationTopBar { 
        display: block !important; 
    }
    /* ซ่อน TopBar ของโหมดนำเสนอทันทีเมื่อมีการเปิด Modal เพื่อไม่ให้ทับหัว Modal และปุ่มปิด */
    body.modal-open #presentationTopBar,
    body.presentation.modal-open #presentationTopBar { 
        display: none !important; 
    }

    /* รับประกันว่า docSplitModal และ Backdrop จะอยู่ชั้นบนสุดเสมอ */
    #docSplitModal {
        z-index: 2200 !important;
    }
    .modal-backdrop {
        z-index: 2190 !important;
    }

    body.presentation #meetingHeader { 
        display: none !important; /* ซ่อน header ปกติเพื่อไม่ให้ซ้อนทับกับเมนูหรือปุ่มใดๆ */
    }
    body.presentation #mainContent { 
        width: 100% !important; 
        margin: 0 !important; 
        max-width: 100% !important; 
        padding: 75px 35px 50px 35px !important; 
        background: #ffffff !important; 
    }
    body.presentation .agenda-title-text { 
        font-size: 1.55rem !important; 
    }
    body.presentation .agenda-detail-text { 
        font-size: 1.25rem !important; 
        line-height: 2 !important; 
    }
    
    body.presentation #sidebarCol { 
        position: fixed; 
        right: 25px; 
        top: 75px;
        width: 300px; 
        z-index: 1030; 
    }
    body.presentation #tocSticky { 
        top: 0 !important; 
        max-height: 82vh; 
    }
    body.presentation #contentCol { 
        width: calc(100% - 330px) !important; 
        flex: 0 0 calc(100% - 330px); 
        max-width: calc(100% - 330px); 
    }

    @media print {
        .d-print-none, .sidebar, .navbar, #meetingHeader, #sidebarCol, #presentationTopBar { display: none !important; }
        main { width: 100% !important; margin: 0 !important; padding: 0 !important; background: white; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
    }

    /* ===== Split Viewer Panes (สลับการแสดงผลด้วย .is-active) ===== */
    .split-files-bar {
        display: none;
        background: #2b3035;
        min-height: 42px;
    }
    .split-files-bar.is-active {
        display: flex;
    }
    .split-file-pill {
        border: 1px solid rgba(255,255,255,0.2);
        background: rgba(255,255,255,0.08);
        color: #e9ecef;
        border-radius: 20px;
        padding: 3px 12px;
        font-size: 0.78rem;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.15s ease;
        max-width: 240px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .split-file-pill:hover {
        background: rgba(255,255,255,0.2);
        color: #ffffff;
    }
    .split-file-pill.active {
        background: #6f42c1;
        border-color: #8c68cd;
        color: #ffffff;
        font-weight: 600;
    }
    @media (max-width: 767.98px) {
        #docSplitModal .split-col-viewer { height: 58% !important; }
        #docSplitModal .split-col-editor { height: 42% !important; }
    }
    #splitViewerParent .split-pane {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        width: 100%;
        height: 100%;
        border: 0;
        display: none;
        overflow: auto;
    }
    #splitViewerParent .split-pane.is-active { display: block; }
    #splitViewerParent .split-pane-flex.is-active { display: flex; }
    #splitViewerParent iframe.split-pane { overflow: hidden; background: #525659; }
    #splitViewerParent .split-img-pane {
        align-items: center;
        justify-content: center;
        background: #1a1a1a;
        padding: 16px;
    }
    #splitViewerParent .split-img-pane img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        border-radius: 6px;
        box-shadow: 0 6px 24px rgba(0,0,0,0.5);
    }
    #splitViewerParent .split-xlsx-pane { flex-direction: column; overflow: hidden; }
    #splitViewerParent .split-notice-pane {
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 24px;
    }

    /* CSS สำหรับตัวแสดงเอกสาร Word (.docx) */
    .docx-render-container {
        background-color: #525659;
        min-height: 100%;
        padding: 20px 10px;
        overflow-y: auto;
    }
    .docx-render-container .docx-wrapper {
        background: transparent !important;
        padding: 0 !important;
    }
    .docx-render-container .docx {
        box-shadow: 0 4px 15px rgba(0,0,0,0.3) !important;
        margin: 0 auto 25px auto !important;
        background: #ffffff !important;
        border-radius: 2px;
        max-width: 100% !important;
    }
    
    /* CSS สำหรับตัวแสดงตาราง Excel (.xlsx, .xls, .csv) */
    .xlsx-render-container {
        background-color: #ffffff;
        min-height: 0;
        overflow: auto;
    }
    .xlsx-render-container table {
        border-collapse: collapse !important;
        font-size: 0.92rem;
        width: max-content;
        min-width: 100%;
    }
    .xlsx-render-container th, 
    .xlsx-render-container td {
        border: 1px solid #dcdcdc !important;
        padding: 6px 12px !important;
        white-space: nowrap;
    }
    .xlsx-render-container tr:first-child td,
    .xlsx-render-container tr:first-child th {
        background-color: #e9ecef !important;
        font-weight: bold;
        position: sticky;
        top: 0;
        z-index: 5;
    }
    .xlsx-tab-btn {
        border: 1px solid #ced4da;
        background: #ffffff;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #495057;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .xlsx-tab-btn:hover {
        background: #e8f5e9;
        color: #198754;
        border-color: #198754;
    }
    .xlsx-tab-btn.active {
        background: #198754 !important;
        color: #ffffff !important;
        border-color: #198754 !important;
        font-weight: bold;
        box-shadow: 0 2px 5px rgba(25, 135, 84, 0.25);
    }
</style>

<!-- Document Preview Libraries (Word & Excel Client Engines) -->
<script src="../../assets/js/jszip.min.js"></script>
<script src="../../assets/js/docx-preview.min.js"></script>
<script src="../../assets/js/xlsx.full.min.js"></script>

<script>
const meetingId = <?= json_encode($meeting_id); ?>;
const agendaFilesMap = <?= json_encode($agenda_files_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function escapeHtmlText(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function getFileIconHtml(ext) {
    if (ext === 'pdf') return '<i class="fa-regular fa-file-pdf text-danger me-1"></i>';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) return '<i class="fa-regular fa-file-image text-info me-1"></i>';
    if (['docx', 'doc'].includes(ext)) return '<i class="fa-regular fa-file-word text-primary me-1"></i>';
    if (['xlsx', 'xls', 'csv'].includes(ext)) return '<i class="fa-regular fa-file-excel text-success me-1"></i>';
    return '<i class="fa-regular fa-file text-secondary me-1"></i>';
}

// === Document Preview Helper Functions (Word & Excel) ===
function renderDocxToContainer(filePath, containerEl, spinnerEl, isAliveFn) {
    if (!containerEl) return;
    if (spinnerEl) spinnerEl.style.display = 'block';
    
    fetch(filePath, { credentials: 'same-origin' })
    .then(res => {
        if (!res.ok) throw new Error('ไม่สามารถดาวน์โหลดไฟล์ Word ได้ (HTTP ' + res.status + ')');
        return res.blob();
    })
    .then(blob => {
        if (isAliveFn && !isAliveFn()) return;
        containerEl.innerHTML = '';
        if (typeof docx === 'undefined') {
            throw new Error('ไลบรารี docx-preview ไม่พร้อมใช้งาน');
        }
        return docx.renderAsync(blob, containerEl, null, {
            className: "docx",
            inWrapper: true,
            ignoreWidth: false,
            ignoreHeight: false,
            breakPages: true
        });
    })
    .then(() => {
        if (isAliveFn && !isAliveFn()) return;
        if (spinnerEl) spinnerEl.style.display = 'none';
    })
    .catch(err => {
        if (isAliveFn && !isAliveFn()) return;
        if (spinnerEl) spinnerEl.style.display = 'none';
        containerEl.innerHTML = `
            <div class="alert alert-warning m-4 shadow-sm border-0 rounded-4 text-center p-4">
                <i class="fa-solid fa-triangle-exclamation text-warning fa-3x mb-3"></i>
                <h6 class="fw-bold">ไม่สามารถแสดงตัวอย่างเอกสาร Word ได้โดยตรง</h6>
                <p class="small text-muted mb-3">${escapeHtmlText(err.message)}</p>
                <a href="${escapeHtmlText(filePath)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">
                    <i class="fa-solid fa-download me-1"></i> ดาวน์โหลดเพื่อเปิดในเครื่อง
                </a>
            </div>
        `;
    });
}

function renderXlsxToContainer(filePath, containerEl, tabsEl, spinnerEl, isAliveFn) {
    if (!containerEl) return;
    if (spinnerEl) spinnerEl.style.display = 'block';

    fetch(filePath, { credentials: 'same-origin' })
    .then(res => {
        if (!res.ok) throw new Error('ไม่สามารถดาวน์โหลดไฟล์ Excel ได้ (HTTP ' + res.status + ')');
        return res.arrayBuffer();
    })
    .then(arrayBuffer => {
        if (isAliveFn && !isAliveFn()) return;
        if (typeof XLSX === 'undefined') {
            throw new Error('ไลบรารี SheetJS (XLSX) ไม่พร้อมใช้งาน');
        }
        const workbook = XLSX.read(arrayBuffer, { type: 'array' });
        const sheetNames = workbook.SheetNames;
        if (!sheetNames || sheetNames.length === 0) {
            throw new Error('ไม่พบข้อมูลชีตในไฟล์ตารางนี้');
        }

        function showSheet(name) {
            const sheet = workbook.Sheets[name];
            const html = XLSX.utils.sheet_to_html(sheet, { editable: false, header: '', footer: '' });
            containerEl.innerHTML = html;
            
            // ตกแต่งตาราง
            const tbl = containerEl.querySelector('table');
            if (tbl) {
                tbl.className = 'table table-bordered table-sm table-hover bg-white mb-0';
                tbl.style.minWidth = '100%';
                const firstTr = tbl.querySelector('tr');
                if (firstTr) firstTr.classList.add('table-light', 'fw-bold');
            }

            // อัปเดตสถานะ Active Tab
            if (tabsEl) {
                tabsEl.querySelectorAll('.xlsx-tab-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.sheet === name);
                });
            }
        }

        // สร้างแท็บชีต
        if (tabsEl) {
            tabsEl.innerHTML = '';
            sheetNames.forEach((sName, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'xlsx-tab-btn' + (idx === 0 ? ' active' : '');
                btn.dataset.sheet = sName;
                btn.innerHTML = `<i class="fa-solid fa-table me-1"></i> ${escapeHtmlText(sName)}`;
                btn.onclick = () => showSheet(sName);
                tabsEl.appendChild(btn);
            });
        }

        // แสดงชีตแรก
        showSheet(sheetNames[0]);
        if (spinnerEl) spinnerEl.style.display = 'none';
    })
    .catch(err => {
        if (isAliveFn && !isAliveFn()) return;
        if (spinnerEl) spinnerEl.style.display = 'none';
        containerEl.innerHTML = `
            <div class="alert alert-warning m-4 shadow-sm border-0 rounded-4 text-center p-4">
                <i class="fa-solid fa-triangle-exclamation text-warning fa-3x mb-3"></i>
                <h6 class="fw-bold">ไม่สามารถแสดงตัวอย่างไฟล์ตาราง Excel ได้โดยตรง</h6>
                <p class="small text-muted mb-3">${escapeHtmlText(err.message)}</p>
                <a href="${escapeHtmlText(filePath)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success rounded-pill px-4 fw-bold">
                    <i class="fa-solid fa-download me-1"></i> ดาวน์โหลดเพื่อเปิดในเครื่อง
                </a>
            </div>
        `;
    });
}

// === Fullscreen Document & Live Resolution Split View System ===
let currentSplitAgendaId = null;
let splitViewerReqId = 0;

function resetSplitViewerPanes(skipPdfBlank) {
    const iframe = document.getElementById('splitDocIframe');
    const imgElement = document.getElementById('splitImgElement');
    const docxBox = document.getElementById('splitDocxContainer');
    const xlsxBox = document.getElementById('splitXlsxContainer');
    const xlsxTabs = document.getElementById('splitXlsxTabs');
    const noticeBox = document.getElementById('splitNonPreviewNotice');
    const spinner = document.getElementById('splitLoadingSpinner');

    // ล้าง Event Handler ก่อนเสมอ เพื่อป้องกัน onerror ย้อนหลังไปทับหน้าจอไฟล์ใหม่
    if (iframe) {
        iframe.onload = null;
        if (!skipPdfBlank && iframe.getAttribute('src') !== 'about:blank') {
            iframe.src = 'about:blank';
        }
    }
    if (imgElement) {
        imgElement.onload = null;
        imgElement.onerror = null;
        imgElement.removeAttribute('src');
    }
    if (docxBox) docxBox.innerHTML = '';
    if (xlsxBox) xlsxBox.innerHTML = '';
    if (xlsxTabs) xlsxTabs.innerHTML = '';
    if (noticeBox) noticeBox.innerHTML = '';
    if (spinner) spinner.style.display = 'none';

    document.querySelectorAll('#splitViewerParent .split-pane').forEach(p => p.classList.remove('is-active'));
}

function openSplitDocFromBtn(btn) {
    const d = btn.dataset;
    openSplitDocModal(d.agendaId, d.agendaNumber, d.agendaTitle, d.agendaProposal, d.fileName, d.filePath, d.fileExt);
}

function renderSplitAgendaFilesBar(agendaId, agendaNumber, agendaTitle, agendaProposal, activePath) {
    const barEl = document.getElementById('splitAgendaFilesBar');
    if (!barEl) return;
    const files = agendaFilesMap[agendaId] || [];
    barEl.innerHTML = '';
    if (files.length <= 1) {
        barEl.classList.remove('is-active');
        return;
    }
    barEl.classList.add('is-active');
    const label = document.createElement('span');
    label.className = 'text-white-50 extra-small flex-shrink-0 me-1';
    label.innerHTML = '<i class="fa-solid fa-paperclip me-1"></i>ไฟล์ในวาระนี้:';
    barEl.appendChild(label);

    const cleanActive = String(activePath || '').split('#')[0].split('?')[0];
    files.forEach(f => {
        const pill = document.createElement('button');
        pill.type = 'button';
        const isCurrent = String(f.path || '').split('#')[0].split('?')[0] === cleanActive;
        pill.className = 'split-file-pill' + (isCurrent ? ' active' : '');
        pill.title = f.name;
        pill.innerHTML = getFileIconHtml(f.ext) + escapeHtmlText(f.name);
        pill.onclick = () => {
            loadSplitFileIntoModal(agendaId, agendaNumber, agendaTitle, agendaProposal, f.name, f.path, f.ext);
        };
        barEl.appendChild(pill);
    });
}

function loadSplitFileIntoModal(agendaId, agendaNumber, agendaTitle, agendaProposal, fileName, filePath, explicitExt) {
    const myReqId = ++splitViewerReqId;
    const isAlive = () => myReqId === splitViewerReqId;

    const cleanPath = String(filePath || '').split('#')[0].split('?')[0];
    const ext = (explicitExt || cleanPath.split('.').pop() || String(fileName || '').split('.').pop() || '').toLowerCase();

    // อัปเดตชื่อไฟล์และปุ่มเปิดแท็บใหม่บนหัว Modal
    const fileEl = document.getElementById('splitModalFileName');
    if (fileEl) {
        fileEl.innerHTML = getFileIconHtml(ext) + ' ' + escapeHtmlText(fileName || 'เอกสารแนบ');
    }
    const newTabBtn = document.getElementById('splitModalNewTabBtn');
    if (newTabBtn) newTabBtn.href = cleanPath;

    // อัปเดตแถบสลับไฟล์ในวาระเดียวกัน
    renderSplitAgendaFilesBar(agendaId, agendaNumber, agendaTitle, agendaProposal, cleanPath);

    // เคลียร์ pane เก่าก่อน (ถ้าไฟล์ใหม่เป็น PDF ไม่ต้องสั่ง about:blank ซ้อนใน tick เดียวกัน)
    resetSplitViewerPanes(ext === 'pdf');

    const spinner = document.getElementById('splitLoadingSpinner');
    const spinnerText = document.getElementById('splitLoadingText');
    const iframe = document.getElementById('splitDocIframe');
    const imgContainer = document.getElementById('splitImgContainer');
    const imgElement = document.getElementById('splitImgElement');
    const docxBox = document.getElementById('splitDocxContainer');
    const xlsxWrapper = document.getElementById('splitXlsxWrapper');
    const xlsxBox = document.getElementById('splitXlsxContainer');
    const xlsxTabs = document.getElementById('splitXlsxTabs');
    const noticeBox = document.getElementById('splitNonPreviewNotice');

    const showPane = (el) => { if (el) el.classList.add('is-active'); };
    const showSpinner = (text) => {
        if (!spinner) return;
        if (spinnerText) spinnerText.innerText = text;
        spinner.style.display = 'block';
    };
    const hideSpinner = () => {
        if (isAlive() && spinner) spinner.style.display = 'none';
    };

    // แสดงสถานะกำลังโหลดทันที
    if (ext === 'pdf') showSpinner('กำลังเปิดเอกสาร PDF...');
    else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) showSpinner('กำลังเปิดรูปภาพ...');
    else if (ext === 'docx') showSpinner('กำลังเปิดเอกสาร Word (.docx)...');
    else if (['xlsx', 'xls', 'csv'].includes(ext)) showSpinner('กำลังเปิดตาราง Excel...');

    // ฟังก์ชันโหลดเนื้อหาจริงเมื่อ Modal แสดงผลและมีขนาดพื้นที่พร้อมแล้ว (ป้องกัน PDF/Word คำนวณขนาดเป็น 0x0)
    let didRun = false;
    const executeLoad = () => {
        if (didRun || !isAlive()) return;
        didRun = true;

        if (ext === 'pdf') {
            showPane(iframe);
            const targetPdfUrl = cleanPath + '#toolbar=1&view=FitH';
            iframe.onload = hideSpinner;
            setTimeout(hideSpinner, 900);
            if (iframe.getAttribute('src') !== targetPdfUrl) {
                iframe.src = targetPdfUrl;
            } else {
                hideSpinner();
            }
        } else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) {
            showPane(imgContainer);
            imgElement.onload = hideSpinner;
            imgElement.onerror = () => {
                if (!isAlive()) return;
                hideSpinner();
                imgContainer.classList.remove('is-active');
                showPane(noticeBox);
                noticeBox.innerHTML = `<div class="bg-white rounded-4 shadow-sm border p-4"><i class="fa-solid fa-triangle-exclamation fa-3x mb-3 text-warning"></i><h6 class="fw-bold">ไม่สามารถโหลดรูปภาพได้</h6><a href="${escapeHtmlText(cleanPath)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-purple rounded-pill px-4">เปิดแท็บใหม่</a></div>`;
            };
            imgElement.src = cleanPath;
        } else if (ext === 'docx') {
            showPane(docxBox);
            renderDocxToContainer(cleanPath, docxBox, spinner, isAlive);
        } else if (['xlsx', 'xls', 'csv'].includes(ext)) {
            showPane(xlsxWrapper);
            renderXlsxToContainer(cleanPath, xlsxBox, xlsxTabs, spinner, isAlive);
        } else if (noticeBox) {
            hideSpinner();
            showPane(noticeBox);
            noticeBox.innerHTML = `
              <div class="bg-white rounded-4 shadow-sm border p-4" style="max-width: 480px;">
                <div class="mb-3"><i class="fa-regular fa-file-lines text-secondary fa-4x"></i></div>
                <h6 class="fw-bold text-dark">${escapeHtmlText(fileName || 'เอกสารประกอบวาระ')}</h6>
                <p class="text-muted small mb-3">ไฟล์เอกสารประเภทนี้ไม่สามารถแสดงตัวอย่างบนหน้าเว็บได้โดยตรง สามารถเปิดดูหรือดาวน์โหลดได้ที่ปุ่มด้านล่าง</p>
                <a href="${escapeHtmlText(cleanPath)}" target="_blank" rel="noopener noreferrer" class="btn btn-purple rounded-pill px-4 fw-bold shadow-xs">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดดู / ดาวน์โหลดไฟล์
                </a>
              </div>
            `;
        }
    };

    const modalEl = document.getElementById('docSplitModal');
    if (modalEl && modalEl.classList.contains('show')) {
        executeLoad();
    } else if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', executeLoad, { once: true });
        setTimeout(executeLoad, 220);
    } else {
        executeLoad();
    }
}

function openSplitDocModal(agendaId, agendaNumber, agendaTitle, agendaProposal, fileName, filePath, explicitExt) {
    const isSameAgenda = String(currentSplitAgendaId) === String(agendaId);
    currentSplitAgendaId = agendaId;
    
    // ตั้งค่าส่วนหัว Header และข้อมูลวาระฝั่งขวา
    const badgeEl = document.getElementById('splitModalAgendaBadge');
    if (badgeEl) badgeEl.innerText = 'วาระที่ ' + (agendaNumber || '');
    
    const titleEl = document.getElementById('splitModalAgendaTitle');
    if (titleEl) titleEl.innerText = agendaTitle || '';
    
    const sideTitleEl = document.getElementById('splitModalSideTitle');
    if (sideTitleEl) sideTitleEl.innerText = 'วาระที่ ' + (agendaNumber || '') + ': ' + (agendaTitle || '');
    
    const sideProposalEl = document.getElementById('splitModalSideProposal');
    if (sideProposalEl) {
        sideProposalEl.innerText = agendaProposal && agendaProposal.trim() !== '' ? ('ประเด็นเสนอ: ' + agendaProposal) : 'ไม่มีประเด็นเสนอเพิ่มเติม';
    }

    // เตรียมช่องพิมพ์มติเมื่อเปิดวาระใหม่
    const resTextarea = document.getElementById('splitModalResText');
    if (resTextarea) {
        if (!isSameAgenda || !document.getElementById('docSplitModal')?.classList.contains('show')) {
            const mainRes = document.getElementById('res_' + agendaId);
            resTextarea.value = mainRes ? mainRes.value : '';
            resTextarea.oninput = function() {
                if (mainRes) {
                    mainRes.value = resTextarea.value;
                    saveLocalDraft(agendaId);
                }
            };
            populateSplitAssignees(agendaId);
        }
    } else {
        const mainRes = document.getElementById('res_' + agendaId);
        const viewText = document.getElementById('splitModalViewOnlyResText');
        if (viewText) {
            viewText.innerText = (mainRes && mainRes.value.trim()) ? mainRes.value : '(ยังไม่มีการบันทึกมติ)';
        }
    }

    // เปิด Modal ก่อน เพื่อให้ Container มีขนาดความกว้าง-สูงจริงก่อนเรนเดอร์ไฟล์
    const modalEl = document.getElementById('docSplitModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        bsModal.show();
    }

    loadSplitFileIntoModal(agendaId, agendaNumber, agendaTitle, agendaProposal, fileName, filePath, explicitExt);
}

function populateSplitAssignees(agendaId) {
    const listContainer = document.getElementById('split_assign_list');
    if (!listContainer) return;
    listContainer.innerHTML = '';
    
    const mainChecks = document.querySelectorAll('.assign-check-' + agendaId);
    let selectedCount = 0;
    
    mainChecks.forEach(mc => {
        const uid = mc.value;
        const labelEl = document.querySelector('label[for="assign_' + agendaId + '_' + uid + '"]');
        const nameText = labelEl ? labelEl.innerText.trim() : uid;
        if (mc.checked) selectedCount++;
        
        const div = document.createElement('div');
        div.className = 'form-check py-1 split-assign-item';
        div.innerHTML = `
            <input class="form-check-input split-assign-check" type="checkbox" value="${uid}" id="split_chk_${uid}" ${mc.checked ? 'checked' : ''}>
            <label class="form-check-label small cursor-pointer" for="split_chk_${uid}">${nameText}</label>
        `;
        listContainer.appendChild(div);
    });
    
    updateSplitAssignLabel(selectedCount);

    document.querySelectorAll('.split-assign-check').forEach(sc => {
        sc.addEventListener('change', function() {
            const mainCheckbox = document.getElementById('assign_' + agendaId + '_' + this.value);
            if (mainCheckbox) mainCheckbox.checked = this.checked;
            updateAssignLabel(agendaId);
            updateSplitAssignLabel(document.querySelectorAll('.split-assign-check:checked').length);
        });
    });
}

function updateSplitAssignLabel(count) {
    const label = document.getElementById('split_assign_label');
    if (label) {
        label.innerHTML = `<i class="fa-solid fa-users text-success me-1"></i> ${count > 0 ? ('เลือกแล้ว ' + count + ' ท่าน') : 'คลิกเพื่อเลือกผู้รับผิดชอบ...'}`;
    }
}

function filterSplitAssignees(input) {
    const q = input.value.toLowerCase().trim();
    document.querySelectorAll('.split-assign-item').forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}

function saveSplitModalResolution() {
    if (!currentSplitAgendaId) return;
    const btn = document.getElementById('btnSaveSplitResolution');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังบันทึกมติ...';
    }

    const splitText = document.getElementById('splitModalResText') ? document.getElementById('splitModalResText').value : '';
    const mainText = document.getElementById('res_' + currentSplitAgendaId);
    if (mainText) mainText.value = splitText;

    let checks = document.querySelectorAll('.split-assign-check:checked');
    let assigned_to = Array.from(checks).map(c => c.value).join(',');

    let fd = new FormData();
    fd.append('action', 'save_resolution_ajax');
    fd.append('agenda_id', currentSplitAgendaId);
    fd.append('resolution', splitText);
    fd.append('assigned_to', assigned_to);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        if (d.status === 'success') {
            try { localStorage.removeItem('meet_' + meetingId + '_res_' + currentSplitAgendaId); } catch(e) {}
            const statusEl = document.getElementById('splitModalSaveStatus');
            if (statusEl) {
                statusEl.innerHTML = '<span class="text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i>บันทึกมติสำเร็จ!</span>';
                setTimeout(() => { statusEl.innerHTML = ''; }, 3500);
            }
            // แสดงสถานะที่กล่องหลักด้วย
            const mainDraftStatus = document.getElementById('draft_status_' + currentSplitAgendaId);
            if (mainDraftStatus) {
                mainDraftStatus.innerHTML = '<span class="text-success extra-small fw-bold"><i class="fa-solid fa-check me-1"></i> บันทึกล่าสุด ' + new Date().toLocaleTimeString('th-TH') + '</span>';
            }
        } else {
            alert('เกิดข้อผิดพลาดในการบันทึก: ' + (d.message || ''));
        }
    }).catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ชั่วคราว ข้อความได้ถูกสำรองไว้ในเครื่องแล้ว');
    });
}

// ล้างสถานะ Viewer เมื่อปิดหน้าต่างขยาย และควบคุม Lazy Load ของกรอบแสดงเอกสารย่อ (In-Card Preview)
document.addEventListener("DOMContentLoaded", function() {
    const splitModalEl = document.getElementById('docSplitModal');
    if (splitModalEl) {
        // ย้าย Modal ไปอยู่ใต้ body โดยตรงเพื่อป้องกันปัญหา z-index / overflow จากคอนเทนเนอร์แม่
        if (splitModalEl.parentElement !== document.body) {
            document.body.appendChild(splitModalEl);
        }
        splitModalEl.addEventListener('hidden.bs.modal', function() {
            splitViewerReqId++;
            resetSplitViewerPanes(false);
            const barEl = document.getElementById('splitAgendaFilesBar');
            if (barEl) {
                barEl.innerHTML = '';
                barEl.classList.remove('is-active');
            }
        });
    }

    // โหลดเอกสารในกรอบย่อ (PDF, Word, Excel) หลัง Collapse กางออกเสร็จสมบูรณ์เพื่อให้มีขนาดความกว้าง-สูงพร้อมเรนเดอร์
    document.querySelectorAll('[id^="collapse_doc_"]').forEach(function(collapseEl) {
        collapseEl.addEventListener('shown.bs.collapse', function() {
            // 1. PDF Iframe
            const iframe = collapseEl.querySelector('iframe.lazy-doc-iframe');
            if (iframe && iframe.dataset.src && iframe.getAttribute('src') !== iframe.dataset.src) {
                iframe.src = iframe.dataset.src;
            }

            // 2. Word (.docx)
            const docxContainer = collapseEl.querySelector('.docx-inline-container[data-loaded="0"]');
            if (docxContainer) {
                docxContainer.setAttribute('data-loaded', '1');
                const fp = docxContainer.getAttribute('data-file-path');
                const spinner = collapseEl.querySelector('.docx-inline-spinner');
                renderDocxToContainer(fp, docxContainer, spinner);
            }

            // 3. Excel (.xlsx, .xls, .csv)
            const xlsxContainer = collapseEl.querySelector('.xlsx-inline-container[data-loaded="0"]');
            if (xlsxContainer) {
                xlsxContainer.setAttribute('data-loaded', '1');
                const fp = xlsxContainer.getAttribute('data-file-path');
                const tabsEl = collapseEl.querySelector('.xlsx-inline-tabs');
                const spinner = collapseEl.querySelector('.xlsx-inline-spinner');
                renderXlsxToContainer(fp, xlsxContainer, tabsEl, spinner);
            }
        });

        collapseEl.addEventListener('hidden.bs.collapse', function() {
            const iframe = collapseEl.querySelector('iframe.lazy-doc-iframe');
            if (iframe) {
                iframe.src = 'about:blank';
            }
        });
    });
});

// 1. ระบบรักษาเซสชัน Heartbeat Ping ทุก 90 วินาที
function keepSessionAlive() {
    fetch('ajax_heartbeat.php?meeting_id=' + meetingId, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
        const text = document.getElementById('sessionStatusText');
        if (d.status === 'alive' && text) {
            text.textContent = 'เซสชันออนไลน์ (' + d.server_time + ' น.)';
        }
    })
    .catch(err => {});
}
setInterval(keepSessionAlive, 90000);
keepSessionAlive();

// 2. สำรองข้อความร่างลง LocalStorage อัตโนมัติทุกครั้งที่พิมพ์
function saveLocalDraft(id) {
    let res = document.getElementById('res_' + id).value;
    try {
        localStorage.setItem('meet_' + meetingId + '_res_' + id, res);
        let statusEl = document.getElementById('draft_status_' + id);
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-warning extra-small"><i class="fa-solid fa-pen-nib me-1"></i> กำลังพิมพ์ (บันทึกร่างในเครื่องแล้ว)</span>';
        }
    } catch(e) {}
}

// 3. กรองค้นหาชื่อผู้รับผิดชอบใน Dropdown
function filterAssignees(input, agendaId) {
    let term = input.value.toLowerCase();
    let container = document.getElementById('assign_list_' + agendaId);
    let items = container.querySelectorAll('.form-check');
    items.forEach(function(item) {
        let text = item.innerText.toLowerCase();
        item.style.display = text.includes(term) ? '' : 'none';
    });
}

// 4. อัปเดตป้ายชื่อเมื่อเลือกผู้รับผิดชอบ
function updateAssignLabel(agendaId) {
    let checked = document.querySelectorAll('.assign-check-' + agendaId + ':checked');
    let labelEl = document.getElementById('assign_label_' + agendaId);
    if (labelEl) {
        if (checked.length === 0) {
            labelEl.innerHTML = '<i class="fa-solid fa-users text-success me-1"></i> คลิกเพื่อเลือกผู้รับผิดชอบ...';
        } else {
            labelEl.innerHTML = '<i class="fa-solid fa-users text-success me-1"></i> เลือกแล้ว ' + checked.length + ' ท่าน';
        }
    }
}

// 5. บันทึกมติแบบ AJAX
function saveResolution(id) {
    let res = document.getElementById('res_' + id).value;
    
    let checks = document.querySelectorAll('.assign-check-' + id + ':checked');
    let selected = Array.from(checks).map(c => c.value);
    let assigned_to = selected.join(',');

    let fd = new FormData();
    fd.append('action', 'save_resolution_ajax'); 
    fd.append('agenda_id', id); 
    fd.append('resolution', res);
    fd.append('assigned_to', assigned_to);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
        if (d.status === 'success') {
            try { localStorage.removeItem('meet_' + meetingId + '_res_' + id); } catch(e) {}
            let t = document.getElementById('saveToast'); 
            t.classList.remove('d-none');
            setTimeout(() => {
                t.classList.add('d-none');
                window.location.reload();
            }, 800);
        } else {
            alert('เกิดข้อผิดพลาดในการบันทึก: ' + (d.message || ''));
        }
    }).catch(e => {
        alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ชั่วคราว ข้อความของท่านถูกบันทึกสำรองไว้ในเครื่องแล้ว ไม่สูญหาย กรุณาลองกดใหม่อีกครั้ง');
    });
}

// 6. เปิด/ปิด โหมดนำเสนอ (Presentation Mode)
function togglePresentationMode() {
    document.body.classList.toggle('presentation');
    let isPres = document.body.classList.contains('presentation');
    let btn = document.getElementById('btnPresentation');
    if (isPres) {
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-compress me-1"></i> ออกจากโหมดนำเสนอ';
            btn.classList.replace('btn-dark', 'btn-danger');
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-expand me-1"></i> โหมดนำเสนอ';
            btn.classList.replace('btn-danger', 'btn-dark');
        }
    }
}

// กดปุ่ม ESC เพื่อปิด Modal เอกสาร หรือออกจากโหมดนำเสนอ
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const splitModal = document.getElementById('docSplitModal');
        if (splitModal && (splitModal.classList.contains('show') || splitModal.style.display === 'block')) {
            const bsModal = bootstrap.Modal.getInstance(splitModal);
            if (bsModal) {
                bsModal.hide();
            } else {
                new bootstrap.Modal(splitModal).hide();
            }
            e.stopPropagation();
            return;
        }
        if (document.body.classList.contains('presentation')) {
            togglePresentationMode();
        }
    }
});

// ฟังก์ชันเลื่อนไปยังวาระก่อนหน้า / ถัดไป ในโหมดนำเสนอ
function prevAgenda() {
    let cards = Array.from(document.querySelectorAll('.agenda-card'));
    let scrollPos = window.scrollY + 100;
    let target = null;
    for (let i = cards.length - 1; i >= 0; i--) {
        if (cards[i].offsetTop < scrollPos - 30) {
            target = cards[i];
            break;
        }
    }
    if (target) target.scrollIntoView({ behavior: 'smooth' });
    else if (cards[0]) cards[0].scrollIntoView({ behavior: 'smooth' });
}

function nextAgenda() {
    let cards = Array.from(document.querySelectorAll('.agenda-card'));
    let scrollPos = window.scrollY + 90;
    let target = null;
    for (let i = 0; i < cards.length; i++) {
        if (cards[i].offsetTop > scrollPos) {
            target = cards[i];
            break;
        }
    }
    if (target) target.scrollIntoView({ behavior: 'smooth' });
}

// 7. ScrollSpy & กู้คืนข้อความร่างอัตโนมัติ
document.addEventListener("DOMContentLoaded", function() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.getAttribute('id').replace('agenda_', '');
                document.querySelectorAll('.toc-item').forEach(el => el.classList.remove('active'));
                const activeLink = document.getElementById('toc_' + id);
                if (activeLink) activeLink.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -80% 0px' });
    document.querySelectorAll('.agenda-card').forEach(card => observer.observe(card));

    // กู้คืนข้อความร่างอัตโนมัติหากมีค้างในเครื่อง
    document.querySelectorAll("textarea[id^='res_']").forEach(function(ta) {
        let id = ta.id.replace('res_', '');
        let draft = localStorage.getItem('meet_' + meetingId + '_res_' + id);
        if (draft && draft.trim() !== '' && draft.trim() !== ta.value.trim() && draft.length > ta.value.length) {
            ta.value = draft;
            let statusEl = document.getElementById('draft_status_' + id);
            if (statusEl) {
                statusEl.innerHTML = '<span class="text-info extra-small fw-bold"><i class="fa-solid fa-clock-rotate-left me-1"></i> กู้คืนข้อความล่าสุดจากเครื่องให้แล้ว</span>';
            }
        }
    });
});
</script>

<?php if ($can_edit): ?>
<script>
function syncAgendaOrder() {
    let items = document.querySelectorAll('#agendaTocList .toc-item');
    let newOrder = [];
    items.forEach(function(item) {
        newOrder.push(item.id.replace('toc_', ''));
    });
    
    let fd = new FormData();
    fd.append('action', 'reorder_agendas');
    fd.append('order', JSON.stringify(newOrder));
    
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
        if (d.status === 'success') {
            let container = document.querySelector('.agenda-scroll-area');
            newOrder.forEach(id => {
                let card = document.getElementById('agenda_' + id);
                if (card) container.appendChild(card);
            });
            let addBtn = document.querySelector('.text-center.mt-4.mb-5');
            if (addBtn) container.appendChild(addBtn);
        } else {
            alert('เกิดข้อผิดพลาดในการบันทึกลำดับ');
        }
    });
}

function moveAgendaUp(id) {
    let el = document.getElementById('toc_' + id);
    if (el && el.previousElementSibling) {
        el.parentNode.insertBefore(el, el.previousElementSibling);
        syncAgendaOrder();
    }
}

function moveAgendaDown(id) {
    let el = document.getElementById('toc_' + id);
    if (el && el.nextElementSibling) {
        el.parentNode.insertBefore(el.nextElementSibling, el);
        syncAgendaOrder();
    }
}
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>