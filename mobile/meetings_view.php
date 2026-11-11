<?php
require_once 'auth_check.php';

$meeting_id = intval($_GET['id'] ?? 0);
if ($meeting_id <= 0) {
    header("Location: meetings.php");
    exit();
}

$msg = '';
$msg_type = '';

// จัดการ RSVP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rsvp') {
    $attendance_status = $_POST['attendance_status'] ?? 'pending';
    $attend_mode = ($attendance_status === 'attending') ? ($_POST['attend_mode'] ?? 'onsite') : null;
    $absence_reason = ($attendance_status === 'absent') ? trim($_POST['absence_reason'] ?? '') : null;

    try {
        // ตรวจสอบว่ามีแถวใน meeting_attendees หรือยัง
        $stmt_chk = $pdo->prepare("SELECT attendee_id FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?");
        $stmt_chk->execute([$meeting_id, $user_id]);
        $existing_id = $stmt_chk->fetchColumn();

        if ($existing_id) {
            $stmt_upd = $pdo->prepare("
                UPDATE meeting_attendees 
                SET attendance_status = ?, attend_mode = ?, absence_reason = ? 
                WHERE meeting_id = ? AND user_id = ?
            ");
            $stmt_upd->execute([$attendance_status, $attend_mode, $absence_reason, $meeting_id, $user_id]);
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO meeting_attendees (meeting_id, user_id, attendance_status, attend_mode, absence_reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([$meeting_id, $user_id, $attendance_status, $attend_mode, $absence_reason]);
        }
        $msg = "บันทึกการตอบรับการประชุมเรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงข้อมูลการประชุม
try {
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        header("Location: meetings.php");
        exit();
    }

    // ดึงวาระ
    $stmt_agendas = $pdo->prepare("
        SELECT * FROM meeting_agendas 
        WHERE meeting_id = ? 
        ORDER BY sort_order ASC, LENGTH(agenda_number) ASC, agenda_number ASC
    ");
    $stmt_agendas->execute([$meeting_id]);
    $agendas = $stmt_agendas->fetchAll(PDO::FETCH_ASSOC);

    // ดึงเอกสารแนบ
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

    // ดึงสถานะ RSVP ของผู้ใช้ปัจจุบัน
    $stmt_my_rsvp = $pdo->prepare("SELECT * FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?");
    $stmt_my_rsvp->execute([$meeting_id, $user_id]);
    $my_rsvp = $stmt_my_rsvp->fetch(PDO::FETCH_ASSOC);

    // ดึงจำนวนผู้ตอบรับ
    $stmt_stats = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN attendance_status = 'attending' THEN 1 ELSE 0 END) as attending_cnt,
            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_cnt,
            SUM(CASE WHEN attendance_status = 'pending' THEN 1 ELSE 0 END) as pending_cnt
        FROM meeting_attendees WHERE meeting_id = ?
    ");
    $stmt_stats->execute([$meeting_id]);
    $attendee_stats = $stmt_stats->fetch(PDO::FETCH_ASSOC) ?: ['attending_cnt'=>0, 'absent_cnt'=>0, 'pending_cnt'=>0];

} catch (PDOException $e) {
    header("Location: meetings.php");
    exit();
}

// ฟังก์ชันแปลงวันที่ภาษาไทยแบบเต็ม
if (!function_exists('formatThaiDateFull')) {
    function formatThaiDateFull($date_str) {
        if (!$date_str) return '-';
        $ts = strtotime($date_str);
        $months = [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        $m = (int)date('n', $ts);
        $d = date('j', $ts);
        $y = (int)date('Y', $ts) + 543;
        return "{$d} " . ($months[$m] ?? '') . " {$y}";
    }
}

// ฟังก์ชัน Render เนื้อหา Rich Text ของวาระการประชุม (รองรับแท็ก HTML จากระบบลงข้อมูล)
if (!function_exists('renderAgendaRichText')) {
    function renderAgendaRichText($content) {
        if ($content === null || trim($content) === '') return '';
        // ตรวจสอบว่ามีแท็ก HTML หรือไม่
        if ($content !== strip_tags($content)) {
            $allowed_tags = '<p><br><b><strong><i><em><u><s><strike><ul><ol><li><a><table><thead><tbody><tfoot><tr><th><td><span><div><h1><h2><h3><h4><h5><h6><blockquote><hr><sub><sup>';
            $cleaned = strip_tags($content, $allowed_tags);
            $cleaned = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(on[a-z]+|javascript:)[^>]*?>/i', '<$1>', $cleaned);
            return '<div class="rich-agenda-content">' . $cleaned . '</div>';
        } else {
            return '<div class="rich-agenda-content" style="white-space: pre-line; line-height: 1.6;">' . nl2br(htmlspecialchars($content)) . '</div>';
        }
    }
}

require_once 'includes/header.php';
?>

<style>
.rich-agenda-content {
    font-size: 0.88rem;
    line-height: 1.65;
    color: #1e293b;
    word-break: break-word;
    overflow-wrap: break-word;
}
[data-bs-theme="dark"] .rich-agenda-content {
    color: #e2e8f0;
}
.rich-agenda-content p {
    margin-bottom: 0.6rem;
}
.rich-agenda-content p:last-child {
    margin-bottom: 0;
}
.rich-agenda-content table {
    width: 100% !important;
    max-width: 100%;
    margin-bottom: 0.75rem;
    border-collapse: collapse;
    display: block;
    overflow-x: auto;
}
.rich-agenda-content table td, 
.rich-agenda-content table th {
    padding: 6px 10px;
    border: 1px solid #e2e8f0;
    font-size: 0.82rem;
}
[data-bs-theme="dark"] .rich-agenda-content table td,
[data-bs-theme="dark"] .rich-agenda-content table th {
    border-color: #334155;
}
.rich-agenda-content ul, .rich-agenda-content ol {
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
</style>

<div class="mt-2 pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="meetings.php" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">รายละเอียดการประชุม</h5>
                <small class="text-muted">ครั้งที่ <?= htmlspecialchars($meeting['meeting_no'] ?: '-') ?></small>
            </div>
        </div>
        <span class="badge bg-purple-subtle text-purple px-2 py-1 rounded-pill">
            <?= count($agendas) ?> วาระ
        </span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Meeting Main Card -->
    <div class="bento-box p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1">
                ครั้งที่ <?= htmlspecialchars($meeting['meeting_no'] ?: '-') ?>
            </span>
            <?php
                $status_class = 'bg-info-subtle text-info';
                $status_name = 'เร็วๆ นี้';
                if ($meeting['status'] === 'in_progress') {
                    $status_class = 'bg-success text-white animate-pulse';
                    $status_name = 'กำลังประชุม';
                } elseif ($meeting['status'] === 'completed') {
                    $status_class = 'bg-secondary text-white';
                    $status_name = 'เสร็จสิ้นแล้ว';
                }
            ?>
            <span class="badge <?= $status_class ?> px-2 py-1"><?= $status_name ?></span>
        </div>

        <h5 class="fw-bold text-dark mb-3" style="line-height: 1.4;">
            <?= htmlspecialchars($meeting['title']) ?>
        </h5>

        <div class="d-flex flex-column gap-2 text-muted small bg-light p-3 rounded-3 mb-3 border">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-regular fa-calendar-check text-purple" style="width: 20px;"></i>
                <span class="fw-semibold text-dark"><?= formatThaiDateFull($meeting['meeting_date']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <i class="fa-regular fa-clock text-purple" style="width: 20px;"></i>
                <span><?= date('H:i', strtotime($meeting['start_time'])) ?> - <?= date('H:i', strtotime($meeting['end_time'])) ?> น.</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-location-dot text-purple" style="width: 20px;"></i>
                <span class="text-dark"><?= htmlspecialchars($meeting['location'] ?: 'ไม่ระบุสถานที่') ?></span>
            </div>
        </div>

        <!-- Attendance RSVP Summary & Action -->
        <div class="p-3 rounded-3" style="background: rgba(57, 4, 143, 0.04); border: 1px dashed rgba(57, 4, 143, 0.2);">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold extra-small text-purple">
                    <i class="fa-solid fa-user-check me-1"></i> สถานะการเข้าร่วมของคุณ
                </span>
                <?php
                    $my_stat = $my_rsvp['attendance_status'] ?? 'pending';
                    if ($my_stat === 'attending') {
                        $mode = ($my_rsvp['attend_mode'] ?? 'onsite') === 'online' ? ' (Online)' : ' (Onsite)';
                        echo '<span class="badge bg-success px-2 py-1">เข้าร่วม' . $mode . '</span>';
                    } elseif ($my_stat === 'absent') {
                        echo '<span class="badge bg-danger px-2 py-1">ขอลาการประชุม</span>';
                    } else {
                        echo '<span class="badge bg-warning text-dark px-2 py-1">ยังไม่ตอบรับ</span>';
                    }
                ?>
            </div>

            <!-- RSVP Quick Buttons -->
            <button class="btn btn-sm btn-outline-primary w-100 rounded-pill fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#rsvpCollapse">
                <i class="fa-solid fa-pen-to-square me-1"></i> เปลี่ยนแปลง / ยืนยันการเข้าร่วม
            </button>

            <div class="collapse mt-3" id="rsvpCollapse">
                <form method="POST" action="meetings_view.php?id=<?= $meeting_id ?>">
                    <input type="hidden" name="action" value="rsvp">
                    <div class="mb-2">
                        <label class="form-label extra-small fw-bold text-muted mb-1">การเข้าร่วม</label>
                        <select name="attendance_status" class="form-select form-select-sm rounded-3" id="rsvpStatus" onchange="toggleRsvpInputs()">
                            <option value="attending" <?= ($my_stat === 'attending' || $my_stat === 'pending') ? 'selected' : '' ?>>ยืนยันเข้าร่วม</option>
                            <option value="absent" <?= ($my_stat === 'absent') ? 'selected' : '' ?>>ขอลาการประชุม</option>
                        </select>
                    </div>

                    <div class="mb-2" id="attendModeBox">
                        <label class="form-label extra-small fw-bold text-muted mb-1">รูปแบบการเข้าร่วม</label>
                        <select name="attend_mode" class="form-select form-select-sm rounded-3">
                            <option value="onsite" <?= (($my_rsvp['attend_mode'] ?? '') === 'onsite') ? 'selected' : '' ?>>เข้าประชุม ณ ห้องประชุม (Onsite)</option>
                            <option value="online" <?= (($my_rsvp['attend_mode'] ?? '') === 'online') ? 'selected' : '' ?>>เข้าประชุมออนไลน์ (Online)</option>
                        </select>
                    </div>

                    <div class="mb-2" id="absentReasonBox" style="display: none;">
                        <label class="form-label extra-small fw-bold text-muted mb-1">เหตุผลที่ขอลา</label>
                        <input type="text" name="absence_reason" class="form-control form-control-sm rounded-3" placeholder="ระบุเหตุผล เช่น ติดภารกิจสอน" value="<?= htmlspecialchars($my_rsvp['absence_reason'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100 rounded-pill fw-bold">
                        <i class="fa-solid fa-check me-1"></i> บันทึกคำตอบ
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Section Agendas -->
    <h6 class="fw-bold text-dark mb-2 px-1 d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-list-check text-purple me-2"></i>วาระการประชุม (<?= count($agendas) ?>)</span>
    </h6>

    <?php if (empty($agendas)): ?>
        <div class="bento-box text-center py-4 text-muted small">
            <i class="fa-solid fa-file-lines fa-2x mb-2 opacity-50"></i>
            <p class="mb-0">ยังไม่มีการบันทึกวาระการประชุม</p>
        </div>
    <?php else: ?>
        <div class="accordion d-flex flex-column gap-2" id="agendasAccordion">
            <?php foreach ($agendas as $idx => $ag): 
                $ag_files = $attachments[$ag['agenda_id']] ?? [];
            ?>
                <div class="bento-box p-0 overflow-hidden">
                    <div class="p-3 d-flex justify-content-between align-items-start cursor-pointer" 
                         data-bs-toggle="collapse" 
                         data-bs-target="#agenda_<?= $ag['agenda_id'] ?>" 
                         aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>">
                        <div class="pe-2">
                            <span class="badge bg-purple-subtle text-purple border border-purple-subtle extra-small mb-1">
                                วาระที่ <?= htmlspecialchars($ag['agenda_number']) ?>
                            </span>
                            <h6 class="fw-bold text-dark mb-1" style="line-height: 1.4;">
                                <?= htmlspecialchars($ag['agenda_title']) ?>
                            </h6>
                            <?php if (!empty($ag_files)): ?>
                                <span class="extra-small text-muted">
                                    <i class="fa-solid fa-paperclip text-purple me-1"></i> <?= count($ag_files) ?> เอกสารแนบ
                                </span>
                            <?php endif; ?>
                        </div>
                        <i class="fa-solid fa-chevron-down text-muted small mt-1"></i>
                    </div>

                    <div id="agenda_<?= $ag['agenda_id'] ?>" class="collapse <?= $idx === 0 ? 'show' : '' ?> px-3 pb-3 border-top pt-3 bg-light bg-opacity-25" data-bs-parent="#agendasAccordion">
                        <?php if (!empty($ag['proposal'])): ?>
                            <div class="mb-3">
                                <label class="extra-small fw-bold text-purple text-uppercase mb-1 d-block">
                                    <i class="fa-solid fa-align-left me-1"></i> เรื่องเสนอเพื่อพิจารณา / ข้อเสนอ
                                </label>
                                <div class="p-2.5 rounded-3 bg-white border text-dark">
                                    <?= renderAgendaRichText($ag['proposal']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($ag['agenda_detail'])): ?>
                            <div class="mb-3">
                                <label class="extra-small fw-bold text-muted text-uppercase mb-1 d-block">
                                    <i class="fa-solid fa-circle-info me-1"></i> รายละเอียดเพิ่มเติม
                                </label>
                                <div class="p-2.5 rounded-3 bg-white border text-secondary">
                                    <?= renderAgendaRichText($ag['agenda_detail']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($ag['resolution'])): ?>
                            <div class="mb-3">
                                <label class="extra-small fw-bold text-success text-uppercase mb-1 d-block">
                                    <i class="fa-solid fa-circle-check me-1"></i> มติที่ประชุม
                                </label>
                                <div class="p-2.5 rounded-3 bg-success bg-opacity-10 border border-success-subtle text-success">
                                    <?= renderAgendaRichText($ag['resolution']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- เอกสารแนบประจำวาระ -->
                        <?php if (!empty($ag_files)): ?>
                            <div>
                                <label class="extra-small fw-bold text-muted text-uppercase mb-1 d-block">
                                    <i class="fa-solid fa-paperclip me-1"></i> เอกสารประกอบวาระ
                                </label>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($ag_files as $f): 
                                        $raw_path = $f['file_path'] ?? '';
                                        if (strpos($raw_path, 'uploads/') === 0) {
                                            $file_url = '../' . $raw_path;
                                        } elseif (strpos($raw_path, '../uploads/') === 0) {
                                            $file_url = $raw_path;
                                        } else {
                                            $file_url = '../uploads/meetings/' . ltrim($raw_path, '/');
                                        }

                                        $ext = strtolower(pathinfo($f['file_name'] ?: $raw_path, PATHINFO_EXTENSION));
                                        $icon = 'fa-file-lines text-secondary';
                                        if (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf text-danger';
                                        elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word text-primary';
                                        elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel text-success';
                                        elseif (in_array($ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint text-warning';
                                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $icon = 'fa-file-image text-info';
                                    ?>
                                        <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" class="d-flex align-items-center justify-content-between p-2.5 rounded-3 bg-white border text-decoration-none text-dark shadow-xs" hx-disable>
                                            <div class="d-flex align-items-center gap-2 overflow-hidden pe-2">
                                                <i class="fa-solid <?= $icon ?>" style="width: 18px; text-align: center;"></i>
                                                <span class="text-truncate small fw-medium"><?= htmlspecialchars($f['file_name']) ?></span>
                                            </div>
                                            <span class="btn btn-sm btn-light border rounded-pill extra-small px-2.5 py-1 text-nowrap fw-semibold">
                                                <i class="fa-solid fa-arrow-up-right-from-square me-1 text-purple"></i> เปิดดู
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<script>
function toggleRsvpInputs() {
    const status = document.getElementById('rsvpStatus').value;
    const modeBox = document.getElementById('attendModeBox');
    const reasonBox = document.getElementById('absentReasonBox');
    if (status === 'attending') {
        modeBox.style.display = 'block';
        reasonBox.style.display = 'none';
    } else {
        modeBox.style.display = 'none';
        reasonBox.style.display = 'block';
    }
}
document.addEventListener('DOMContentLoaded', toggleRsvpInputs);
</script>

<?php require_once 'includes/footer.php'; ?>
