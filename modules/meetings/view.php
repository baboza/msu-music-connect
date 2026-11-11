<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ไม่พบรหัสการประชุม'); window.location.href='index.php';</script>";
    exit();
}

$meeting_id = $_GET['id'];
$current_user_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. จัดการการส่งฟอร์มตอบรับ (RSVP Submit)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'rsvp') {
    $attendance_status = $_POST['attendance_status'];
    $attend_mode = ($attendance_status == 'attending') ? $_POST['attend_mode'] : null;
    $absence_reason = ($attendance_status == 'absent') ? trim($_POST['absence_reason']) : null;

    try {
        $stmt_rsvp = $pdo->prepare("
            UPDATE meeting_attendees 
            SET attendance_status = ?, attend_mode = ?, absence_reason = ? 
            WHERE meeting_id = ? AND user_id = ?
        ");
        $stmt_rsvp->execute([$attendance_status, $attend_mode, $absence_reason, $meeting_id, $current_user_id]);
        
        $_SESSION['msg'] = "บันทึกการตอบรับเข้าร่วมประชุมเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
        header("Location: view.php?id=" . $meeting_id);
        exit();
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// 2. ดึงข้อมูลรายละเอียดการประชุม และวาระการประชุม
// ---------------------------------------------------------
try {
    // ข้อมูลหลักของการประชุม
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        echo "<script>alert('ไม่พบข้อมูลการประชุมนี้'); window.location.href='index.php';</script>";
        exit();
    }

    // ดึงวาระการประชุมจากตาราง meeting_agendas
    $stmt_agenda = $pdo->prepare("SELECT * FROM meeting_agendas WHERE meeting_id = ? ORDER BY sort_order ASC, LENGTH(agenda_number) ASC, agenda_number ASC");
    $stmt_agenda->execute([$meeting_id]);
    $agendas = $stmt_agenda->fetchAll(PDO::FETCH_ASSOC);

    // ดึงเอกสารแนบในแต่ละวาระ
    $stmt_files = $pdo->prepare("
        SELECT a.* FROM meeting_attachments a 
        JOIN meeting_agendas g ON a.agenda_id = g.agenda_id 
        WHERE g.meeting_id = ?
    ");
    $stmt_files->execute([$meeting_id]);
    $attachments = [];
    foreach ($stmt_files->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $attachments[$f['agenda_id']][] = $f;
    }

    // ดึงรายชื่อผู้เข้าร่วม (ใช้ตาราง meeting_attendees ตาม DB จริง)
    $stmt_parts = $pdo->prepare("
        SELECT ma.*, u.first_name, u.last_name, u.position, d.dept_name 
        FROM meeting_attendees ma
        JOIN users u ON ma.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE ma.meeting_id = ?
        ORDER BY ma.attendance_status DESC, u.first_name ASC
    ");
    $stmt_parts->execute([$meeting_id]);
    $participants = $stmt_parts->fetchAll(PDO::FETCH_ASSOC);

    $is_invited = false;
    $my_rsvp = null;
    
    // สถิติการตอบรับ
    $stat_total = count($participants);
    $stat_attending = 0; $stat_onsite = 0; $stat_online = 0;
    $stat_absent = 0;
    $stat_pending = 0;

    foreach ($participants as $p) {
        // เช็คว่า User ที่กำลังเปิดหน้านี้ ถูกเชิญหรือไม่
        if ($p['user_id'] == $current_user_id) {
            $is_invited = true;
            $my_rsvp = $p;
        }
        
        // นับสถิติ
        if ($p['attendance_status'] == 'attending') {
            $stat_attending++;
            if ($p['attend_mode'] == 'onsite') $stat_onsite++;
            if ($p['attend_mode'] == 'online') $stat_online++;
        } elseif ($p['attendance_status'] == 'absent') {
            $stat_absent++;
        } else {
            $stat_pending++;
        }
    }

    $is_admin = hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน']);

    // ตรวจสอบข้อมูลการลาและไปราชการในวันที่ประชุม
    $m_date = $meeting['meeting_date'];
    $m_year = intval(date('Y', strtotime($m_date)));
    $m_date_ce = ($m_year < 2400) ? $m_date : (($m_year - 543) . date('-m-d', strtotime($m_date)));
    $m_date_be = ($m_year < 2400) ? (($m_year + 543) . date('-m-d', strtotime($m_date))) : $m_date;

    $leaves_by_user = [];
    try {
        $stmt_leaves = $pdo->prepare("
            SELECT lr.user_id, lt.type_name, lr.status, lr.start_date, lr.end_date, lr.reason
            FROM leave_requests lr
            LEFT JOIN leave_types lt ON lr.type_id = lt.type_id
            WHERE lr.status IN ('approved', 'pending')
              AND ((? BETWEEN lr.start_date AND lr.end_date) OR (? BETWEEN lr.start_date AND lr.end_date))
        ");
        $stmt_leaves->execute([$m_date_ce, $m_date_be]);
        while ($row = $stmt_leaves->fetch(PDO::FETCH_ASSOC)) {
            $leaves_by_user[$row['user_id']][] = $row;
        }
    } catch (PDOException $e) {}

    $trainings_by_user = [];
    try {
        $stmt_trainings = $pdo->prepare("
            SELECT tr.user_id, tr.project_name, tr.location, tr.status, tr.report_type, tr.start_date, tr.end_date
            FROM training_reports tr
            WHERE tr.status IN ('approved', 'reported', 'pending')
              AND ((? BETWEEN tr.start_date AND tr.end_date) OR (? BETWEEN tr.start_date AND tr.end_date))
        ");
        $stmt_trainings->execute([$m_date_ce, $m_date_be]);
        while ($row = $stmt_trainings->fetch(PDO::FETCH_ASSOC)) {
            $trainings_by_user[$row['user_id']][] = $row;
        }
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-users-viewfinder me-2"></i>รายละเอียดการประชุม</h1>
            <p class="text-muted small mb-0">ครั้งที่ <?php echo htmlspecialchars($meeting['meeting_no']); ?></p>
        </div>
        <div class="btn-toolbar mt-3 mt-md-0">
            <a href="index.php" class="btn btn-light border rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-arrow-left me-2"></i> กลับหน้ารายการ
            </a>
        </div>
    </div>

    <?php if(isset($_SESSION['msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> shadow-sm border-0 border-start border-<?php echo $_SESSION['msg_type']; ?> border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo $_SESSION['msg']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
    <?php endif; ?>

    <div class="row g-4">
        <?php if ($is_invited && $meeting['status'] != 'completed'): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden <?php echo ($my_rsvp['attendance_status'] == 'pending') ? 'border-top border-warning border-4' : 'border-top border-success border-4'; ?>">
                    <div class="card-body p-4 p-md-5">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-circle me-3 <?php echo ($my_rsvp['attendance_status'] == 'pending') ? 'bg-warning text-dark' : 'bg-success text-white'; ?>" style="width: 50px; height: 50px; font-size: 1.5rem;">
                                <i class="fa-solid <?php echo ($my_rsvp['attendance_status'] == 'pending') ? 'fa-bell fa-shake' : 'fa-check-double'; ?>"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">ยืนยันการเข้าร่วมประชุม (RSVP)</h5>
                                <p class="text-muted small mb-0">กรุณาแจ้งสถานะการเข้าร่วม เพื่อให้ผู้จัดเตรียมความพร้อม</p>
                            </div>
                        </div>

                        <form action="" method="POST" id="rsvpForm" class="bg-light p-4 rounded-3 border">
                            <input type="hidden" name="action" value="rsvp">
                            
                            <div class="row g-4 align-items-start">
                                <div class="col-md-5 border-end">
                                    <h6 class="fw-bold text-dark mb-3">ท่านสามารถเข้าร่วมประชุมได้หรือไม่?</h6>
                                    
                                    <div class="form-check mb-2 custom-radio">
                                        <input class="form-check-input" type="radio" name="attendance_status" id="rsvpYes" value="attending" required <?php echo ($my_rsvp['attendance_status'] == 'attending') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold text-success" for="rsvpYes">
                                            <i class="fa-solid fa-circle-check me-1"></i> เข้าร่วมได้
                                        </label>
                                    </div>
                                    <div class="form-check custom-radio">
                                        <input class="form-check-input" type="radio" name="attendance_status" id="rsvpNo" value="absent" <?php echo ($my_rsvp['attendance_status'] == 'absent') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold text-danger" for="rsvpNo">
                                            <i class="fa-solid fa-circle-xmark me-1"></i> ไม่สามารถเข้าร่วมได้ (ลา)
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-7 ps-md-4">
                                    
                                    <div id="attendModeBox" class="d-none">
                                        <h6 class="fw-bold text-dark mb-3">รูปแบบการเข้าร่วม:</h6>
                                        <div class="d-flex gap-3">
                                            <input type="radio" class="btn-check" name="attend_mode" id="modeOnsite" value="onsite" <?php echo ($my_rsvp['attend_mode'] == 'onsite') ? 'checked' : ''; ?>>
                                            <label class="btn btn-outline-primary rounded-pill px-4" for="modeOnsite"><i class="fa-solid fa-users me-1"></i> Onsite (ที่ห้อง)</label>

                                            <input type="radio" class="btn-check" name="attend_mode" id="modeOnline" value="online" <?php echo ($my_rsvp['attend_mode'] == 'online') ? 'checked' : ''; ?>>
                                            <label class="btn btn-outline-info rounded-pill px-4" for="modeOnline"><i class="fa-solid fa-video me-1"></i> Online</label>
                                        </div>
                                    </div>

                                    <div id="absenceReasonBox" class="d-none">
                                        <h6 class="fw-bold text-danger mb-2">โปรดระบุเหตุผล:</h6>
                                        <textarea class="form-control border-danger-subtle" name="absence_reason" rows="2" placeholder="เช่น ติดราชการอื่น, ลาป่วย, ลากิจ..."><?php echo htmlspecialchars($my_rsvp['absence_reason'] ?? ''); ?></textarea>
                                    </div>

                                    <div id="placeholderBox" class="text-center py-3 text-muted">
                                        <i class="fa-regular fa-hand-pointer fs-3 mb-2 opacity-50"></i>
                                        <p class="small mb-0">กรุณาเลือกสถานะทางซ้ายมือ</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-end mt-4 pt-3 border-top">
                                <button type="submit" class="btn btn-purple rounded-pill px-5 fw-bold shadow-sm">
                                    <i class="fa-solid fa-paper-plane me-2"></i> บันทึกการตอบรับ
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-circle-info me-2"></i>ข้อมูลการประชุม</h5>
                </div>
                <div class="card-body p-4">
                    <h4 class="fw-bold text-dark mb-4"><?php echo htmlspecialchars($meeting['title']); ?></h4>
                    
                    <div class="row mb-3">
                        <div class="col-sm-4 text-muted small fw-bold"><i class="fa-regular fa-calendar-days me-1"></i> วันที่:</div>
                        <div class="col-sm-8 fw-bold text-dark"><?php echo date('d/m/Y', strtotime($meeting['meeting_date'])); ?></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 text-muted small fw-bold"><i class="fa-regular fa-clock me-1"></i> เวลา:</div>
                        <div class="col-sm-8 text-dark">
                            <?php 
                                echo date('H:i', strtotime($meeting['start_time'])) . ' น.';
                                if(!empty($meeting['end_time'])) echo ' - ' . date('H:i', strtotime($meeting['end_time'])) . ' น.'; 
                            ?>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-4 text-muted small fw-bold"><i class="fa-solid fa-location-dot me-1"></i> สถานที่ (Onsite):</div>
                        <div class="col-sm-8 text-dark"><?php echo htmlspecialchars($meeting['location'] ?: '-'); ?></div>
                    </div>

                    <hr class="text-muted opacity-25 mb-4">
                    
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-ol text-purple me-2"></i>ระเบียบวาระการประชุม</h6>
                    <?php if (empty($agendas)): ?>
                        <div class="text-muted small p-3 bg-light rounded text-center">ไม่มีการระบุวาระการประชุม</div>
                    <?php else: ?>
                        <div class="accordion shadow-sm" id="agendaAccordion">
                            <?php foreach($agendas as $index => $agenda): 
                                $a_id = $agenda['agenda_id'];
                                $has_files = !empty($attachments[$a_id]);
                            ?>
                                <div class="accordion-item border-0 border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button <?php echo $index == 0 ? '' : 'collapsed'; ?> fw-bold text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $a_id; ?>">
                                            <span class="me-2 text-purple">วาระที่ <?php echo htmlspecialchars($agenda['agenda_number']); ?>:</span> <?php echo htmlspecialchars($agenda['agenda_title']); ?>
                                            <?php if ($has_files): ?>
                                                <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-2 extra-small" title="เอกสารประกอบวาระ">
                                                    <i class="fa-solid fa-paperclip me-1"></i><?php echo count($attachments[$a_id]); ?>
                                                </span>
                                            <?php endif; ?>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo $a_id; ?>" class="accordion-collapse collapse <?php echo $index == 0 ? 'show' : ''; ?>" data-bs-parent="#agendaAccordion">
                                        <div class="accordion-body bg-light text-dark small">
                                            <!-- รายละเอียด / ข้อเท็จจริง -->
                                            <?php if (!empty($agenda['agenda_detail'])): ?>
                                                <div class="mb-3">
                                                    <div class="fw-bold text-secondary extra-small text-uppercase mb-1"><i class="fa-solid fa-align-left me-1"></i> รายละเอียด / ข้อเท็จจริง:</div>
                                                    <?php if ($agenda['agenda_detail'] !== strip_tags($agenda['agenda_detail'])): ?>
                                                        <div class="p-3 bg-white rounded-3 border-start border-3 border-purple shadow-xs table-responsive custom-rich-content">
                                                            <?php echo $agenda['agenda_detail']; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="p-3 bg-white rounded-3 border-start border-3 border-purple shadow-xs" style="white-space: pre-line; line-height: 1.6;">
                                                            <?php echo htmlspecialchars($agenda['agenda_detail']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- ประเด็นเสนอ -->
                                            <?php if (!empty($agenda['proposal'])): ?>
                                                <div class="mb-3">
                                                    <div class="fw-bold text-secondary extra-small text-uppercase mb-1"><i class="fa-solid fa-lightbulb text-warning me-1"></i> ประเด็นเสนอ:</div>
                                                    <?php if ($agenda['proposal'] !== strip_tags($agenda['proposal'])): ?>
                                                        <div class="p-3 bg-white rounded-3 border-start border-3 border-warning shadow-xs table-responsive custom-rich-content">
                                                            <?php echo $agenda['proposal']; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="p-3 bg-white rounded-3 border-start border-3 border-warning shadow-xs" style="white-space: pre-line; line-height: 1.6;">
                                                            <?php echo htmlspecialchars($agenda['proposal']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (empty($agenda['agenda_detail']) && empty($agenda['proposal'])): ?>
                                                <div class="text-muted fst-italic mb-3">- ไม่มีรายละเอียดระบุไว้ -</div>
                                            <?php endif; ?>

                                            <!-- เอกสารแนบประจำวาระ -->
                                            <?php if ($has_files): ?>
                                                <div class="mt-3 pt-2 border-top">
                                                    <div class="fw-bold text-secondary extra-small text-uppercase mb-2"><i class="fa-solid fa-paperclip text-purple me-1"></i> เอกสารประกอบวาระ:</div>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach ($attachments[$a_id] as $file): 
                                                            $f_path = '../../uploads/meetings/' . htmlspecialchars($file['file_path']);
                                                            $f_ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                                                            $icon = 'fa-file-lines text-secondary';
                                                            if ($f_ext === 'pdf') $icon = 'fa-file-pdf text-danger';
                                                            elseif (in_array($f_ext, ['doc', 'docx'])) $icon = 'fa-file-word text-primary';
                                                            elseif (in_array($f_ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel text-success';
                                                            elseif (in_array($f_ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint text-warning';
                                                            elseif (in_array($f_ext, ['jpg', 'jpeg', 'png'])) $icon = 'fa-file-image text-info';
                                                        ?>
                                                            <a href="<?php echo $f_path; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-white bg-white border rounded-pill px-3 py-1 shadow-xs text-dark hover-lift d-inline-flex align-items-center gap-2 text-decoration-none">
                                                                <i class="fa-regular <?php echo $icon; ?>"></i>
                                                                <span class="text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($file['file_name']); ?></span>
                                                                <i class="fa-solid fa-arrow-up-right-from-square extra-small text-muted ms-1"></i>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100 flex-column d-flex">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-users me-2"></i>องค์ประชุม</h5>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($is_admin): ?>
                            <a href="manage_agendas.php?id=<?= $meeting_id; ?>&open=attendees" class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold shadow-xs">
                                <i class="fa-solid fa-users-gear me-1"></i> จัดการผู้เข้าร่วม
                            </a>
                        <?php endif; ?>
                        <span class="badge bg-purple rounded-pill fs-6"><?php echo $stat_total; ?> คน</span>
                    </div>
                </div>
                
                <div class="p-3 bg-light border-bottom">
                    <div class="row text-center g-2">
                        <div class="col-4">
                            <div class="p-2 bg-success bg-opacity-10 text-success rounded border border-success border-opacity-25">
                                <div class="fs-4 fw-bold"><?php echo $stat_attending; ?></div>
                                <div class="extra-small">เข้าร่วม (<?php echo $stat_onsite; ?>/<?php echo $stat_online; ?>)</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-danger bg-opacity-10 text-danger rounded border border-danger border-opacity-25">
                                <div class="fs-4 fw-bold"><?php echo $stat_absent; ?></div>
                                <div class="extra-small">ลา / ไม่เข้า</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-warning bg-opacity-10 text-dark rounded border border-warning border-opacity-25">
                                <div class="fs-4 fw-bold"><?php echo $stat_pending; ?></div>
                                <div class="extra-small">รอยืนยัน</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0 flex-grow-1" style="max-height: 400px; overflow-y: auto;">
                    <ul class="list-group list-group-flush">
                        <?php foreach($participants as $p): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                <div>
                                    <div class="fw-bold small text-dark"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($p['position'] ?? '-'); ?></div>
                                    
                                    <?php 
                                    $p_uid = $p['user_id'];
                                    $p_leaves = $leaves_by_user[$p_uid] ?? [];
                                    $p_trainings = $trainings_by_user[$p_uid] ?? [];
                                    ?>
                                    <?php if (!empty($p_leaves)): ?>
                                        <?php foreach ($p_leaves as $pl): ?>
                                            <div class="extra-small mt-1 text-danger bg-danger-subtle px-2 py-1 rounded d-inline-block border border-danger-subtle">
                                                <i class="fa-solid fa-umbrella-beach me-1"></i>ลา: <?= htmlspecialchars($pl['type_name']); ?> (<?= ($pl['status'] === 'approved') ? 'อนุมัติแล้ว' : 'รออนุมัติ'; ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($p_trainings)): ?>
                                        <?php foreach ($p_trainings as $pt): ?>
                                            <div class="extra-small mt-1 text-warning-emphasis bg-warning-subtle px-2 py-1 rounded d-inline-block border border-warning-subtle text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($pt['project_name']); ?>">
                                                <i class="fa-solid fa-plane-departure me-1"></i><?= htmlspecialchars($pt['report_type'] ?: 'ไปราชการ'); ?>: <?= htmlspecialchars($pt['project_name']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ($p['attendance_status'] == 'absent' && !empty($p['absence_reason']) && $is_admin): ?>
                                        <div class="extra-small mt-1 text-danger bg-danger-subtle p-1 rounded d-inline-block">
                                            <i class="fa-solid fa-comment-dots me-1"></i> เหตุผล: <?php echo htmlspecialchars($p['absence_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="text-end">
                                    <?php if ($p['attendance_status'] == 'attending'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success"><i class="fa-solid fa-check me-1"></i>เข้าร่วม</span>
                                        <?php if ($p['attend_mode'] == 'onsite'): ?>
                                            <div class="extra-small text-primary mt-1 fw-bold"><i class="fa-solid fa-street-view me-1"></i>Onsite</div>
                                        <?php elseif ($p['attend_mode'] == 'online'): ?>
                                            <div class="extra-small text-info mt-1 fw-bold"><i class="fa-solid fa-video me-1"></i>Online</div>
                                        <?php endif; ?>
                                    <?php elseif ($p['attendance_status'] == 'absent'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger"><i class="fa-solid fa-xmark me-1"></i>ลา</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning"><i class="fa-solid fa-hourglass-half me-1"></i>รอตอบรับ</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</main>

<style>
    .text-purple { color: #39048f; }
    .bg-purple { background-color: #39048f; }
    .btn-purple { background-color: #39048f; color: white; transition: 0.3s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(57, 4, 143,0.2); }
    .extra-small { font-size: 0.75rem; }
    .icon-circle { display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    
    .custom-radio .form-check-input { width: 1.5em; height: 1.5em; cursor: pointer; }
    .custom-radio label { cursor: pointer; padding-top: 3px; font-size: 1.1rem; }
    
    .accordion-button:not(.collapsed) { background-color: #f8f9fa; color: #39048f; box-shadow: none; }
    .accordion-button:focus { box-shadow: none; border-color: rgba(0,0,0,.125); }

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
</style>

<script>
// สคริปต์ควบคุมการซ่อน/โชว์ กล่องเลือก Onsite/Online และกล่องเหตุผล
document.addEventListener('DOMContentLoaded', function() {
    const radioYes = document.getElementById('rsvpYes');
    const radioNo = document.getElementById('rsvpNo');
    
    const modeBox = document.getElementById('attendModeBox');
    const reasonBox = document.getElementById('absenceReasonBox');
    const placeholderBox = document.getElementById('placeholderBox');

    function updateFormUI() {
        if (radioYes && radioYes.checked) {
            placeholderBox.classList.add('d-none');
            reasonBox.classList.add('d-none');
            modeBox.classList.remove('d-none');
            document.querySelector('[name="absence_reason"]').removeAttribute('required');
            document.querySelector('[name="attend_mode"]').setAttribute('required', 'required');
        } else if (radioNo && radioNo.checked) {
            placeholderBox.classList.add('d-none');
            modeBox.classList.add('d-none');
            reasonBox.classList.remove('d-none');
            document.querySelector('[name="attend_mode"]').removeAttribute('required');
            document.querySelector('[name="absence_reason"]').setAttribute('required', 'required');
        }
    }

    if (radioYes && radioNo) {
        radioYes.addEventListener('change', updateFormUI);
        radioNo.addEventListener('change', updateFormUI);
        updateFormUI(); // ให้ทำงานตอนโหลดหน้าเว็บครั้งแรกด้วย
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>