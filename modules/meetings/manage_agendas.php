<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์ธุรการ/แอดมิน/บุคลากรสายสนับสนุน
if (!hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: สำหรับเจ้าหน้าที่และผู้จัดประชุมเท่านั้น'); window.location.href='index.php';</script>";
    exit();
}

$meeting_id = $_GET['id'] ?? '';
if (empty($meeting_id)) { header("Location: manage_meeting.php"); exit(); }

$stmt_meet = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
$stmt_meet->execute([$meeting_id]);
$meeting = $stmt_meet->fetch();

if (!$meeting) {
    echo "<script>alert('ไม่พบข้อมูลการประชุม'); window.location.href='manage_meeting.php';</script>";
    exit();
}

// ---------------------------------------------------------
// ตรวจสอบข้อมูลบุคลากรที่ "ลา" หรือ "ไปราชการ" ในวันที่ประชุม
// ---------------------------------------------------------
$m_date = $meeting['meeting_date'];
$m_year = intval(date('Y', strtotime($m_date)));
$m_date_ce = ($m_year < 2400) ? $m_date : (($m_year - 543) . date('-m-d', strtotime($m_date)));
$m_date_be = ($m_year < 2400) ? (($m_year + 543) . date('-m-d', strtotime($m_date))) : $m_date;

// 1. ตรวจสอบการลา (leave_requests)
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

// 2. ตรวจสอบการไปราชการ / อบรม (training_reports)
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

$total_busy_user_ids = array_unique(array_merge(array_keys($leaves_by_user), array_keys($trainings_by_user)));
$total_busy_count = count($total_busy_user_ids);
$total_leave_count = count($leaves_by_user);
$total_training_count = count($trainings_by_user);

$success_msg = ''; $error_msg = '';
if (isset($_GET['success']) && $_GET['success'] == 'template') {
    $success_msg = "นำเข้าโครงสร้างวาระมาตรฐานสำเร็จ!";
} elseif (isset($_GET['saved'])) {
    if ($_GET['saved'] === 'attendees') {
        $success_msg = "บันทึกและอัปเดตรายชื่อผู้เข้าร่วมประชุมเรียบร้อยแล้ว! (ผู้เข้าร่วมสามารถมองเห็นและตอบรับการประชุมได้แล้ว)";
    } elseif ($_GET['saved'] === 'published') {
        $success_msg = "เผยแพร่การประชุมเรียบร้อยแล้ว ผู้เข้าร่วมสามารถมองเห็นและตอบรับการประชุมได้ทันที";
    }
}

$upload_dir = '../../uploads/meetings/';
$submission_dir = '../../uploads/meetings/submissions/'; 
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
if (!is_dir($submission_dir)) mkdir($submission_dir, 0777, true);

// =========================================================
// ประมวลผล POST
// =========================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'reorder_agendas') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (is_array($order) && !empty($order)) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE meeting_agendas SET sort_order = ? WHERE agenda_id = ? AND meeting_id = ?");
                foreach ($order as $index => $agenda_id) {
                    $stmt->execute([$index + 1, intval($agenda_id), $meeting_id]);
                }
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'บันทึกลำดับวาระเรียบร้อยแล้ว']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'ข้อมูลลำดับไม่ถูกต้อง']);
            }
            exit();

        } elseif ($action == 'add_agenda') {
            $stmt_max = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM meeting_agendas WHERE meeting_id = ?");
            $stmt_max->execute([$meeting_id]);
            $next_sort = intval($stmt_max->fetchColumn()) + 1;

            $stmt = $pdo->prepare("INSERT INTO meeting_agendas (meeting_id, agenda_number, agenda_title, agenda_detail, proposal, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$meeting_id, trim($_POST['agenda_number']), trim($_POST['agenda_title']), trim($_POST['agenda_detail']), trim($_POST['proposal']), $next_sort]);
            $success_msg = "เพิ่มวาระการประชุมเรียบร้อยแล้ว";

        } elseif ($action == 'edit_agenda') {
            $stmt = $pdo->prepare("UPDATE meeting_agendas SET agenda_number=?, agenda_title=?, agenda_detail=?, proposal=?, resolution=? WHERE agenda_id=?");
            $stmt->execute([trim($_POST['agenda_number']), trim($_POST['agenda_title']), trim($_POST['agenda_detail']), trim($_POST['proposal']), trim($_POST['resolution']), $_POST['agenda_id']]);
            $success_msg = "บันทึกข้อมูลวาระสำเร็จ";

        } elseif ($action == 'delete_agenda') {
            $agenda_id = $_POST['agenda_id'];
            $stmt_files = $pdo->prepare("SELECT file_path FROM meeting_attachments WHERE agenda_id = ?");
            $stmt_files->execute([$agenda_id]);
            foreach ($stmt_files->fetchAll() as $f) { if(file_exists($upload_dir . $f['file_path'])) unlink($upload_dir . $f['file_path']); }
            $pdo->prepare("DELETE FROM meeting_agendas WHERE agenda_id = ?")->execute([$agenda_id]);
            $success_msg = "ลบวาระเรียบร้อยแล้ว";

        } elseif ($action == 'upload_file') {
            if (!empty($_FILES['attachment']['name'])) {
                $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'zip', 'rar'];
                if (!in_array($ext, $allowed)) {
                    $error_msg = "ไม่อนุญาตให้อัปโหลดไฟล์นามสกุล .$ext (รองรับเฉพาะ PDF, Word, Excel, PowerPoint, รูปภาพ และ ZIP)";
                } else {
                    $new_file_name = "meet_{$meeting_id}_agenda_{$_POST['agenda_id']}_" . time() . "." . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $new_file_name)) {
                        $pdo->prepare("INSERT INTO meeting_attachments (agenda_id, file_name, file_path) VALUES (?, ?, ?)")->execute([$_POST['agenda_id'], basename($_FILES['attachment']['name']), $new_file_name]);
                        $success_msg = "อัปโหลดไฟล์สำเร็จ";
                    } else {
                        $error_msg = "เกิดข้อผิดพลาดในการบันทึกไฟล์";
                    }
                }
            }
            
        } elseif ($action == 'delete_file') {
            if(file_exists($upload_dir . $_POST['file_path'])) unlink($upload_dir . $_POST['file_path']);
            $pdo->prepare("DELETE FROM meeting_attachments WHERE attachment_id = ?")->execute([$_POST['attachment_id']]);
            $success_msg = "ลบไฟล์แนบเรียบร้อยแล้ว";
            
        } elseif ($action == 'assign_submission') {
            $sub_id = $_POST['submission_id'];
            $agenda_id = $_POST['agenda_id'];
            $custom_name = trim($_POST['custom_file_name']); 
            
            $stmt_sub = $pdo->prepare("SELECT file_path, original_file_name FROM meeting_submissions WHERE submission_id = ?");
            $stmt_sub->execute([$sub_id]);
            $sub = $stmt_sub->fetch();
            
            if ($sub && file_exists($submission_dir . $sub['file_path'])) {
                $ext = pathinfo($sub['original_file_name'], PATHINFO_EXTENSION);
                if (!preg_match("/\.$ext$/i", $custom_name)) { $custom_name .= "." . $ext; }

                $final_internal_name = "assigned_meet_{$meeting_id}_" . time() . "." . $ext;
                copy($submission_dir . $sub['file_path'], $upload_dir . $final_internal_name);
                
                $stmt_insert = $pdo->prepare("INSERT INTO meeting_attachments (agenda_id, file_name, file_path) VALUES (?, ?, ?)");
                $stmt_insert->execute([$agenda_id, $custom_name, $final_internal_name]);
                
                $pdo->prepare("UPDATE meeting_submissions SET status = 'assigned' WHERE submission_id = ?")->execute([$sub_id]);
                $success_msg = "นำเข้าและเปลี่ยนชื่อไฟล์สำเร็จ!";
            } else {
                $error_msg = "ไม่พบไฟล์ต้นฉบับในระบบ อาจถูกลบไปแล้ว";
            }
            
        // 💡 [อัปเกรด] ประมวลผล: บันทึกผู้เข้าร่วมประชุมแบบรักษาข้อมูล RSVP
        } elseif ($action == 'update_attendees') {
            $attendees = $_POST['attendees'] ?? [];
            
            // ดึงรายชื่อคนที่มีอยู่แล้วในฐานข้อมูล
            $stmt_curr = $pdo->prepare("SELECT user_id FROM meeting_attendees WHERE meeting_id = ?");
            $stmt_curr->execute([$meeting_id]);
            $curr_users = $stmt_curr->fetchAll(PDO::FETCH_COLUMN);
            
            // 1. ลบคนที่ถูกติ๊กออก (มีใน DB แต่ไม่มีในฟอร์มที่ส่งมา)
            $to_delete = array_diff($curr_users, $attendees);
            if (!empty($to_delete)) {
                $qMarks = str_repeat('?,', count($to_delete) - 1) . '?';
                $params = array_merge([$meeting_id], $to_delete);
                $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id = ? AND user_id IN ($qMarks)")->execute($params);
            }
            
            // 2. เพิ่มคนที่ถูกติ๊กใหม่ (มีในฟอร์มที่ส่งมา แต่ไม่มีใน DB)
            $to_add = array_diff($attendees, $curr_users);
            if (!empty($to_add)) {
                $stmt_in = $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, user_id) VALUES (?, ?)");
                foreach ($to_add as $u) {
                    $stmt_in->execute([$meeting_id, $u]);
                }
            }
            // คนที่อยู่ในทั้ง 2 ฝั่ง (ถูกติ๊กไว้ตั้งแต่แรก) ระบบจะไม่ทำอะไร เพื่อรักษาสถานะ RSVP เดิมเอาไว้
            
            // เผยแพร่การประชุมอัตโนมัติหากเลือก publish_now หรือหากการประชุมยังเป็น draft อยู่
            $should_publish = isset($_POST['publish_now']) && $_POST['publish_now'] == '1';
            if ($should_publish || $meeting['status'] === 'draft') {
                $pdo->prepare("UPDATE meetings SET status = 'upcoming' WHERE meeting_id = ? AND status = 'draft'")->execute([$meeting_id]);
            }

            header("Location: manage_agendas.php?id=" . $meeting_id . "&saved=attendees");
            exit();
        } elseif ($action == 'publish_meeting') {
            $pdo->prepare("UPDATE meetings SET status = 'upcoming' WHERE meeting_id = ?")->execute([$meeting_id]);
            header("Location: manage_agendas.php?id=" . $meeting_id . "&saved=published");
            exit();
        } elseif ($action == 'import_template') {
            $templates = [
                ['number' => '1', 'title' => 'เรื่องที่ประธานแจ้งให้ที่ประชุมทราบ', 'sort' => 1],
                ['number' => '2', 'title' => 'เรื่องรับรองรายงานการประชุม', 'sort' => 2],
                ['number' => '3', 'title' => 'เรื่องเสนอให้ที่ประชุมทราบ', 'sort' => 3],
                ['number' => '4', 'title' => 'เรื่องเสนอให้ที่ประชุมพิจารณา', 'sort' => 4],
                ['number' => '5', 'title' => 'เรื่องอื่นๆ (ถ้ามี)', 'sort' => 5],
            ];
            $stmt = $pdo->prepare("INSERT INTO meeting_agendas (meeting_id, agenda_number, agenda_title, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($templates as $t) {
                $stmt->execute([$meeting_id, $t['number'], $t['title'], $t['sort']]);
            }
            $success_msg = "นำเข้าโครงสร้างวาระมาตรฐานสำเร็จ";
            // ดึงข้อมูลแสดงผลใหม่
            header("Location: manage_agendas.php?id=$meeting_id&success=template");
            exit();
        }
    } catch (PDOException $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// =========================================================
// ดึงข้อมูลแสดงผล
// =========================================================
$agendas = $pdo->prepare("SELECT * FROM meeting_agendas WHERE meeting_id = ? ORDER BY sort_order ASC, LENGTH(agenda_number) ASC, agenda_number ASC");
$agendas->execute([$meeting_id]); $agendas = $agendas->fetchAll(PDO::FETCH_ASSOC);

$files_raw = $pdo->prepare("SELECT a.* FROM meeting_attachments a JOIN meeting_agendas g ON a.agenda_id = g.agenda_id WHERE g.meeting_id = ?");
$files_raw->execute([$meeting_id]);
$attachments = [];
foreach ($files_raw->fetchAll(PDO::FETCH_ASSOC) as $f) { $attachments[$f['agenda_id']][] = $f; }

// ดึงไฟล์ใน Inbox 
try {
    $inbox = $pdo->prepare("SELECT s.*, u.first_name, u.last_name FROM meeting_submissions s JOIN users u ON s.user_id = u.user_id WHERE s.meeting_id = ? AND s.status = 'pending'");
    $inbox->execute([$meeting_id]);
    $inbox_files = $inbox->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $inbox_files = []; }

// ดึงรายชื่อบุคลากรทั้งหมดเพื่อแสดงใน Modal เลือกผู้เข้าร่วม
$users_list = [];
try {
    $users_list = $pdo->query("SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, d.dept_name 
                               FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id 
                               ORDER BY d.dept_name ASC, u.first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ดึงรายชื่อคนที่ถูกเลือกให้เข้าประชุมนี้อยู่แล้ว 
$current_attendees = [];
try {
    $stmt_att = $pdo->prepare("SELECT user_id FROM meeting_attendees WHERE meeting_id = ?");
    $stmt_att->execute([$meeting_id]);
    $current_attendees = $stmt_att->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}


require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

function thaiDateFull($date) {
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $timestamp = strtotime($date);
    return date("j", $timestamp) . " " . $months[date("n", $timestamp)] . " " . (date("Y", $timestamp) + 543);
}

function renderAgendaContent($content, $default = '') {
    if ($content === null || trim((string)$content) === '') {
        return $default;
    }
    if ($content !== strip_tags($content)) {
        return '<div class="custom-rich-content table-responsive">' . $content . '</div>';
    }
    return '<div class="custom-rich-content">' . nl2br(htmlspecialchars($content)) . '</div>';
}

function getFileIconData($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return ['icon' => 'fa-file-pdf', 'color' => 'text-muted', 'badge' => 'PDF', 'bg' => 'bg-light border text-secondary'];
        case 'doc':
        case 'docx': return ['icon' => 'fa-file-word', 'color' => 'text-muted', 'badge' => 'DOC', 'bg' => 'bg-light border text-secondary'];
        case 'xls':
        case 'xlsx': return ['icon' => 'fa-file-excel', 'color' => 'text-muted', 'badge' => 'XLS', 'bg' => 'bg-light border text-secondary'];
        case 'ppt':
        case 'pptx': return ['icon' => 'fa-file-powerpoint', 'color' => 'text-muted', 'badge' => 'PPT', 'bg' => 'bg-light border text-secondary'];
        case 'jpg':
        case 'jpeg':
        case 'png': return ['icon' => 'fa-file-image', 'color' => 'text-muted', 'badge' => 'IMG', 'bg' => 'bg-light border text-secondary'];
        case 'zip':
        case 'rar': return ['icon' => 'fa-file-zipper', 'color' => 'text-muted', 'badge' => 'ZIP', 'bg' => 'bg-light border text-secondary'];
        default: return ['icon' => 'fa-file-lines', 'color' => 'text-muted', 'badge' => strtoupper($ext ?: 'FILE'), 'bg' => 'bg-light border text-secondary'];
    }
}

// คำนวณสรุปสถิติต่างๆ สำหรับหน้าแดชบอร์ดวาระ
$total_agendas = count($agendas);
$total_attachments = 0;
foreach ($attachments as $files) {
    $total_attachments += count($files);
}
$total_resolved = 0;
foreach ($agendas as $a) {
    if (!empty(trim((string)($a['resolution'] ?? '')))) {
        $total_resolved++;
    }
}
$inbox_count = count($inbox_files);
$attendee_count = count($current_attendees);
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js"></script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <!-- Reorder Notification Toast -->
    <div id="reorderToast" class="alert alert-success shadow-lg border-0 border-start border-success border-4 d-none position-fixed top-0 start-50 translate-middle-x mt-4 rounded-3" style="z-index: 3000;">
        <span id="reorderToastText"><i class="fa-solid fa-check-circle me-2"></i><strong>บันทึกลำดับวาระเรียบร้อยแล้ว</strong></span>
    </div>

    <!-- 1. Header Navigation Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap pt-3 pb-2 mb-3 border-bottom gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="manage_meeting.php" class="text-decoration-none text-muted extra-small hover-purple">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้ารายการการประชุม
                </a>
                <span class="text-muted extra-small">/</span>
                <span class="extra-small text-purple fw-bold">จัดระเบียบวาระ</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h3 text-purple fw-bold mb-0">
                    <i class="fa-solid fa-list-check me-2"></i>จัดระเบียบวาระการประชุม
                </h1>
                
                <!-- สถานะการประชุม -->
                <?php if ($meeting['status'] === 'draft'): ?>
                    <span class="badge bg-warning-subtle text-dark border border-warning rounded-pill px-3 py-1 extra-small fw-semibold">
                        <i class="fa-solid fa-file-pen text-warning me-1"></i> แบบร่าง (Draft)
                    </span>
                <?php elseif ($meeting['status'] === 'in_progress'): ?>
                    <span class="badge bg-success rounded-pill px-3 py-1 extra-small pulse-animation">
                        <i class="fa-solid fa-circle text-white me-1" style="font-size: 7px;"></i> กำลังประชุม
                    </span>
                <?php elseif ($meeting['status'] === 'completed'): ?>
                    <span class="badge bg-secondary rounded-pill px-3 py-1 extra-small">
                        <i class="fa-solid fa-check me-1"></i> ประชุมเสร็จสิ้น
                    </span>
                <?php else: ?>
                    <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1 extra-small fw-semibold">
                        <i class="fa-regular fa-clock me-1"></i> รอการประชุม
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ปุ่มทางลัดหลักด้านบน -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($meeting['status'] === 'draft'): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('ยืนยันเผยแพร่การประชุมนี้? ผู้เข้าร่วมประชุมจะสามารถมองเห็นและเริ่มตอบรับได้ทันที');">
                    <input type="hidden" name="action" value="publish_meeting">
                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-xs">
                        <i class="fa-solid fa-bullhorn me-1"></i> เผยแพร่ทันที
                    </button>
                </form>
            <?php endif; ?>
            <a href="manage_meeting.php" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary fw-semibold shadow-xs hover-bg">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการ
            </a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm border-0 border-start border-success border-4 alert-dismissible fade show"><i class="fa-solid fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- 2. Modern Executive Meeting Hub Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden bg-white">
        <div class="card-body p-4">
            <div class="row g-4 align-items-stretch">
                <!-- ข้อมูลสรุปการประชุม -->
                <div class="col-lg-6 d-flex flex-column justify-content-between border-end pe-lg-4">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-purple rounded-pill px-3 py-1 extra-small fw-bold">
                                ครั้งที่ <?php echo htmlspecialchars($meeting['meeting_no']); ?>
                            </span>
                            <span class="text-muted extra-small">รหัสการประชุม #<?php echo $meeting_id; ?></span>
                        </div>
                        <h4 class="fw-bold text-dark mb-2 lh-base">
                            <?php echo htmlspecialchars($meeting['title']); ?>
                        </h4>
                        
                        <div class="d-flex flex-wrap gap-y-1 gap-x-3 text-muted small mt-2">
                            <div class="me-3 mb-1"><i class="fa-regular fa-calendar text-muted me-1"></i> <?php echo thaiDateFull($meeting['meeting_date']); ?></div>
                            <div class="me-3 mb-1"><i class="fa-regular fa-clock text-muted me-1"></i> <?php echo date('H:i', strtotime($meeting['start_time'])); ?> น. <?php echo !empty($meeting['end_time']) ? ' - '.date('H:i', strtotime($meeting['end_time'])).' น.' : ''; ?></div>
                            <div class="mb-1"><i class="fa-solid fa-location-dot text-muted me-1"></i> <?php echo htmlspecialchars($meeting['location'] ?: 'ไม่ระบุสถานที่'); ?></div>
                        </div>
                    </div>

                    <!-- ปุ่มทางลัดไปยังระบบที่เกี่ยวข้อง -->
                    <div class="mt-4 pt-3 border-top d-flex flex-wrap gap-2">
                        <a href="meeting_room.php?id=<?php echo $meeting_id; ?>" class="btn btn-sm btn-purple rounded-pill px-3 fw-bold shadow-xs" target="_blank" title="เข้าสู่ห้องประชุมแบบโต้ตอบออนไลน์/ออนไซต์">
                            <i class="fa-solid fa-door-open me-1"></i> เข้าสู่ห้องประชุม
                        </a>
                        <a href="view.php?id=<?php echo $meeting_id; ?>" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold text-secondary hover-bg shadow-xs" target="_blank" title="ดูรายละเอียดและสถิติการตอบรับเข้าร่วม">
                            <i class="fa-solid fa-chart-pie me-1"></i> สถิติ RSVP
                        </a>
                        <a href="draft_minutes.php?id=<?php echo $meeting_id; ?>" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold text-secondary hover-bg shadow-xs" target="_blank" title="จัดทำและพิมพ์ร่างรายงานการประชุม">
                            <i class="fa-solid fa-file-signature me-1"></i> ร่างรายงานการประชุม
                        </a>
                    </div>
                </div>

                <!-- 4 Interactive KPI Stat Cards (2x2 Grid) -->
                <div class="col-lg-6 ps-lg-3">
                    <div class="row g-2 h-100">
                        <!-- Stat 1: วาระทั้งหมด -->
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border h-100 d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="extra-small fw-bold text-muted text-uppercase">วาระทั้งหมด</span>
                                    <i class="fa-solid fa-layer-group text-muted extra-small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-dark"><?php echo $total_agendas; ?> <span class="fs-6 fw-normal text-muted">วาระ</span></div>
                                <div class="extra-small text-muted mt-1">
                                    มีมติแล้ว: <strong class="text-success"><?php echo $total_resolved; ?></strong>/<?php echo $total_agendas; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Stat 2: ผู้เข้าร่วมประชุม (คลิกเพื่อเปิดจัดการ) -->
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border h-100 d-flex flex-column justify-content-between kpi-card" data-bs-toggle="modal" data-bs-target="#attendeeModal" title="คลิกเพื่อจัดการรายชื่อและสิทธิ์การเข้าถึงการประชุม">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="extra-small fw-bold text-muted text-uppercase">ผู้เข้าร่วม</span>
                                    <i class="fa-solid fa-users text-muted extra-small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-dark"><?php echo $attendee_count; ?> <span class="fs-6 fw-normal text-muted">คน</span></div>
                                <div class="extra-small text-muted mt-1">
                                    <i class="fa-solid fa-gear me-1"></i>จัดการผู้เข้าร่วม
                                </div>
                            </div>
                        </div>

                        <!-- Stat 3: เอกสารแนบทั้งหมด -->
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border h-100 d-flex flex-column justify-content-between">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="extra-small fw-bold text-muted text-uppercase">เอกสารแนบรวม</span>
                                    <i class="fa-solid fa-paperclip text-muted extra-small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-dark"><?php echo $total_attachments; ?> <span class="fs-6 fw-normal text-muted">ไฟล์</span></div>
                                <div class="extra-small text-muted mt-1">
                                    เฉลี่ย <?php echo ($total_agendas > 0) ? round($total_attachments / $total_agendas, 1) : 0; ?> ไฟล์/วาระ
                                </div>
                            </div>
                        </div>

                        <!-- Stat 4: กล่องรับเอกสาร (คลิกเพื่อเปิด) -->
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border h-100 d-flex flex-column justify-content-between kpi-card" data-bs-toggle="modal" data-bs-target="#inboxModal" onclick="clearInboxAgendaSelection()" title="คลิกเพื่อเปิดดูไฟล์ที่บุคลากรส่งมา">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="extra-small fw-bold text-muted text-uppercase">กล่องรับเอกสาร</span>
                                    <i class="fa-solid fa-inbox text-muted extra-small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-dark">
                                    <?php echo $inbox_count; ?> <span class="fs-6 fw-normal text-muted">รายการ</span>
                                    <?php if ($inbox_count > 0): ?>
                                        <span class="badge bg-danger rounded-pill px-2 py-0 extra-small align-middle ms-1">รอ</span>
                                    <?php endif; ?>
                                </div>
                                <div class="extra-small text-muted mt-1">
                                    <i class="fa-solid fa-arrow-right-to-bracket me-1"></i><?php echo ($inbox_count > 0) ? 'เปิดกล่องรับเอกสาร' : 'ไม่มีไฟล์ค้างส่ง'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Agendas Workspace Toolbar -->
    <div class="bg-white p-3 rounded-4 border shadow-xs mb-3">
        <div class="row g-2 align-items-center justify-content-between">
            <!-- Left: Title & Quick Search -->
            <div class="col-md-5 d-flex align-items-center gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-book-open text-purple me-1"></i> วาระการประชุม
                    </h5>
                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1 extra-small"><?php echo $total_agendas; ?></span>
                </div>
                
                <?php if (!empty($agendas)): ?>
                    <div class="input-group input-group-sm flex-grow-1" style="min-width: 190px; max-width: 240px;">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" id="agendaSearchInput" class="form-control bg-light border-start-0" placeholder="พิมพ์ค้นหาวาระ..." onkeyup="searchAgendas()">
                        <button class="btn btn-light border border-start-0 text-muted d-none" type="button" id="btnClearAgendaSearch" onclick="clearAgendaSearch()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Action Buttons Group -->
            <div class="col-md-7 d-flex justify-content-md-end align-items-center gap-2 flex-wrap">
                <?php if (!empty($agendas)): ?>
                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold text-secondary shadow-xs hover-bg" id="btnToggleExpandAll" onclick="toggleExpandAllAgendas()" title="เปิดหรือปิดเนื้อหาทุกวาระพร้อมกัน">
                        <i class="fa-solid fa-expand me-1"></i> ขยายทั้งหมด
                    </button>
                <?php endif; ?>

                <form method="POST" class="d-inline m-0" onsubmit="return confirm('คุณต้องการนำเข้าโครงสร้างวาระมาตรฐาน (5 วาระ) ใช่หรือไม่?\n\n*ระบบจะเพิ่มวาระมาตรฐาน 5 ข้อต่อท้ายรายการเดิม');">
                    <input type="hidden" name="action" value="import_template">
                    <button type="submit" class="btn btn-sm btn-light border text-secondary rounded-pill px-3 fw-semibold shadow-xs hover-bg">
                        <i class="fa-solid fa-file-import me-1"></i> นำเข้า 5 วาระมาตรฐาน
                    </button>
                </form>

                <button type="button" class="btn btn-sm btn-purple rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addAgendaModal">
                    <i class="fa-solid fa-plus me-1"></i> เพิ่มวาระใหม่
                </button>
            </div>
        </div>

        <?php if (!empty($agendas)): ?>
            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top extra-small text-muted">
                <span>
                    <i class="fa-solid fa-arrows-up-down text-muted me-1"></i> สามารถคลิกปุ่ม <i class="fa-solid fa-chevron-up fa-2xs"></i><i class="fa-solid fa-chevron-down fa-2xs"></i> หรือลากสลับลำดับวาระได้อิสระ
                </span>
                <span id="agendaSearchResultsCount" class="fw-semibold text-dark d-none"></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- แจ้งเตือนเมื่อค้นหาไม่พบ -->
    <div id="noAgendaSearchResult" class="alert alert-light border text-center py-4 rounded-4 shadow-xs d-none my-3">
        <i class="fa-solid fa-magnifying-glass text-muted fs-3 mb-2 d-block opacity-50"></i>
        <div class="fw-bold text-dark">ไม่พบวาระที่ตรงกับคำค้นหา</div>
        <div class="extra-small text-muted mt-1">ลองเปลี่ยนคำค้นหา หรือคลิก <a href="javascript:void(0)" onclick="clearAgendaSearch()" class="text-purple fw-bold">ล้างการค้นหา</a></div>
    </div>
    
    <!-- 4. Agenda Accordion List OR Empty State -->
    <?php if (empty($agendas)): ?>
        <div class="card border-0 shadow-sm rounded-4 bg-white p-5 text-center my-4">
            <div class="mb-3">
                <div class="bg-purple-subtle text-purple rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 76px; height: 76px;">
                    <i class="fa-solid fa-folder-plus fs-2"></i>
                </div>
            </div>
            <h4 class="fw-bold text-dark mb-2">ยังไม่มีวาระการประชุม</h4>
            <p class="text-muted small mb-4" style="max-width: 520px; margin: 0 auto;">
                เริ่มต้นจัดเตรียมการประชุมได้อย่างรวดเร็ว โดยเลือกนำเข้าโครงสร้างวาระมาตรฐานราชการ หรือสร้างวาระแรกด้วยตนเอง
            </p>
            <div class="d-flex justify-content-center flex-wrap gap-3">
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="import_template">
                    <button type="submit" class="btn btn-purple rounded-pill px-4 py-2 fw-bold shadow-sm">
                        <i class="fa-solid fa-file-import me-2"></i>นำเข้าโครงสร้าง 5 วาระมาตรฐาน
                    </button>
                </form>
                <button type="button" class="btn btn-outline-purple rounded-pill px-4 py-2 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#addAgendaModal">
                    <i class="fa-solid fa-plus me-2"></i>สร้างวาระแรกด้วยตนเอง
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="accordion shadow-sm" id="agendaAccordion" style="border-radius: 1rem; overflow: hidden;">
            <?php foreach ($agendas as $index => $a): 
                $a_id = $a['agenda_id']; 
                $has_resolution = !empty(trim((string)($a['resolution'] ?? ''))); 
                $has_detail = !empty(trim((string)($a['agenda_detail'] ?? ''))); 
                $has_proposal = !empty(trim((string)($a['proposal'] ?? ''))); 
                $item_attachments = $attachments[$a_id] ?? [];
                $attach_count = count($item_attachments);
            ?>
            <div class="accordion-item border-0 border-bottom agenda-item" id="agenda_item_<?php echo $a_id; ?>" data-id="<?php echo $a_id; ?>">
                <!-- แถบหัวข้อวาระหลัก สัดส่วนสมบูรณ์แบบ แถวเดียว สมดุล สวยงาม -->
                <div class="d-flex align-items-center bg-white px-3 py-2.5 agenda-header-row gap-2 gap-md-3">
                    <!-- ปุ่มและจุดจับปรับลำดับวาระ (ขนาดกะทัดรัด สมส่วน) -->
                    <div class="d-flex align-items-center gap-1 text-muted reorder-controls flex-shrink-0" title="ลากเพื่อสลับลำดับ หรือคลิกปุ่มลูกศร">
                        <span class="px-1 text-secondary opacity-50" style="cursor: grab;" title="ลากสลับลำดับ">
                            <i class="fa-solid fa-grip-vertical"></i>
                        </span>
                        <div class="d-flex flex-column gap-1">
                            <button type="button" class="btn btn-xs btn-white border shadow-2xs p-0 text-secondary d-flex align-items-center justify-content-center rounded" onclick="moveAgendaUp(event, <?php echo $a_id; ?>)" title="เลื่อนขึ้น" style="width: 20px; height: 16px;">
                                <i class="fa-solid fa-chevron-up" style="font-size: 8px;"></i>
                            </button>
                            <button type="button" class="btn btn-xs btn-white border shadow-2xs p-0 text-secondary d-flex align-items-center justify-content-center rounded" onclick="moveAgendaDown(event, <?php echo $a_id; ?>)" title="เลื่อนลง" style="width: 20px; height: 16px;">
                                <i class="fa-solid fa-chevron-down" style="font-size: 8px;"></i>
                            </button>
                        </div>
                    </div>

                    <!-- ป้ายเลขวาระ (สีม่วงเอกลักษณ์ สวยงาม ขนาดสมส่วน) -->
                    <span class="badge bg-purple text-white px-3 py-2 rounded-pill fw-bold shadow-2xs flex-shrink-0" style="font-size: 0.85rem; letter-spacing: 0.3px;">
                        วาระที่ <?php echo htmlspecialchars($a['agenda_number']); ?>
                    </span>

                    <!-- ชื่อวาระ (คลิกเพื่อคลี่/พับเนื้อหา) -->
                    <div class="flex-grow-1 d-flex align-items-center text-truncate pe-2 agenda-toggle-area cursor-pointer" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $a_id; ?>" title="คลิกเพื่อเปิด/ปิดเนื้อหาวาระ" style="cursor: pointer; min-width: 0;">
                        <span class="agenda-title-text fw-bold text-dark text-truncate" style="font-size: 1rem; color: #1e293b;">
                            <?php echo htmlspecialchars($a['agenda_title']); ?>
                        </span>
                    </div>

                    <!-- Badges สรุปสถานะประจำวาระ -->
                    <div class="d-flex align-items-center gap-2 flex-shrink-0 agenda-toggle-area cursor-pointer" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $a_id; ?>" style="cursor: pointer;">
                        <?php if ($attach_count > 0): ?>
                            <span class="badge bg-light text-secondary border rounded-pill px-2.5 py-1 extra-small" title="มีเอกสารแนบ <?php echo $attach_count; ?> ไฟล์">
                                <i class="fa-solid fa-paperclip text-muted me-1"></i><?php echo $attach_count; ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($has_resolution): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 extra-small fw-semibold">
                                <i class="fa-solid fa-check me-1"></i>มีมติแล้ว
                            </span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border rounded-pill px-2.5 py-1 extra-small">
                                <i class="fa-regular fa-clock me-1"></i>รอมติ
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- ปุ่มแก้ไขวาระ (สีเหลืองทองโดดเด่น สัดส่วนพอดี อยู่ระดับเดียวกันในแถว) -->
                    <div class="flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 py-1 shadow-2xs hover-scale" onclick="openEditAgenda(<?php echo $a_id; ?>)" title="แก้ไขวาระ / บันทึกมติ">
                            <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไข
                        </button>
                    </div>

                    <!-- ปุ่มลูกศรคลี่/พับ (Chevron Toggle Button) -->
                    <div class="flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-light border text-secondary rounded-circle d-flex align-items-center justify-content-center p-0 chevron-toggle-btn" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $a_id; ?>" style="width: 32px; height: 32px;" title="เปิด/ปิดเนื้อหาวาระ">
                            <i class="fa-solid fa-chevron-down extra-small transition-transform" style="<?php echo $index == 0 ? 'transform: rotate(180deg);' : ''; ?>"></i>
                        </button>
                    </div>
                </div>

                <div id="collapse_<?php echo $a_id; ?>" class="accordion-collapse collapse <?php echo $index == 0 ? 'show' : ''; ?>" data-bs-parent="#agendaAccordion">
                    <div class="accordion-body bg-light p-4">
                        
                        <!-- Section 1: รายละเอียด / ข้อเท็จจริง -->
                        <div class="card border bg-white rounded-3 p-3 mb-3 shadow-2xs">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="extra-small fw-bold text-muted text-uppercase">
                                    <i class="fa-regular fa-file-lines me-1"></i> รายละเอียด / ข้อเท็จจริง
                                </div>
                                <?php if (!$has_detail): ?>
                                    <span class="text-muted extra-small fst-italic">ไม่มีรายละเอียดระบุไว้</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-dark">
                                <?php echo renderAgendaContent($a['agenda_detail'], '<div class="text-muted extra-small fst-italic p-2 bg-light rounded text-center">ไม่มีรายละเอียดเพิ่มเติมในวาระนี้</div>'); ?>
                            </div>
                        </div>

                        <!-- Section 2: ประเด็นเสนอ -->
                        <?php if ($has_proposal): ?>
                            <div class="card border bg-white rounded-3 p-3 mb-3 shadow-2xs">
                                <div class="extra-small fw-bold text-muted text-uppercase mb-2">
                                    <i class="fa-regular fa-lightbulb me-1"></i> ประเด็นเสนอ
                                </div>
                                <div class="text-dark">
                                    <?php echo renderAgendaContent($a['proposal']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Section 3: เอกสารแนบประจำวาระ -->
                        <div class="card border bg-white rounded-3 p-3 mb-3 shadow-2xs">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <div class="extra-small fw-bold text-muted text-uppercase">
                                        <i class="fa-solid fa-paperclip me-1"></i> เอกสารแนบประจำวาระ
                                    </div>
                                    <div class="extra-small text-muted">รวม <?php echo $attach_count; ?> ไฟล์</div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-light border text-dark rounded-pill px-3 fw-semibold shadow-2xs hover-bg" onclick="openInboxForAgenda('<?php echo $a_id; ?>', '<?php echo htmlspecialchars(addslashes($a['agenda_number'] . ' ' . $a['agenda_title'])); ?>')">
                                        <i class="fa-solid fa-inbox me-1 text-muted"></i> เพิ่มเอกสารจากกล่องรับเอกสาร
                                        <?php if (!empty($inbox_files)): ?>
                                            <span class="badge bg-secondary text-white rounded-pill ms-1"><?php echo count($inbox_files); ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border text-dark rounded-pill px-3 fw-semibold shadow-2xs hover-bg" onclick="openUploadModal('<?php echo $a_id; ?>', '<?php echo htmlspecialchars(addslashes($a['agenda_number'] . ' ' . $a['agenda_title'])); ?>')">
                                        <i class="fa-solid fa-cloud-arrow-up me-1 text-muted"></i> อัปโหลดไฟล์ด้วยตนเอง
                                    </button>
                                </div>
                            </div>

                            <?php if (!empty($item_attachments)): ?>
                                <div class="row g-2">
                                    <?php foreach ($item_attachments as $file): 
                                        $f_info = getFileIconData($file['file_name']);
                                    ?>
                                        <div class="col-12">
                                            <div class="d-flex justify-content-between align-items-center bg-light p-2 px-3 rounded-3 border hover-bg">
                                                <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 80%;">
                                                    <span class="badge bg-light text-muted border px-2 py-1 extra-small font-monospace">
                                                        <?php echo $f_info['badge']; ?>
                                                    </span>
                                                    <a href="../../uploads/meetings/<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="text-dark small text-decoration-none hover-purple text-truncate">
                                                        <?php echo htmlspecialchars($file['file_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <a href="../../uploads/meetings/<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="btn btn-xs btn-light border text-secondary rounded-pill px-2 py-1" title="เปิดดูเอกสาร">
                                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> ดูไฟล์
                                                    </a>
                                                    <form method="POST" class="m-0" onsubmit="return confirm('ยืนยันการลบไฟล์แนบนี้?');">
                                                        <input type="hidden" name="action" value="delete_file">
                                                        <input type="hidden" name="attachment_id" value="<?php echo $file['attachment_id']; ?>">
                                                        <input type="hidden" name="file_path" value="<?php echo $file['file_path']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-link text-danger text-decoration-none px-2" title="ลบไฟล์แนบ">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-3 bg-light border border-dashed rounded-3 text-center text-muted extra-small">
                                    <i class="fa-regular fa-folder-open text-muted opacity-50 me-1"></i> ยังไม่มีเอกสารแนบในวาระนี้
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Section 4: มติที่ประชุม -->
                        <div class="card border rounded-3 p-3 mb-3 shadow-2xs <?php echo $has_resolution ? 'bg-success-subtle bg-opacity-25 border-success border-opacity-25' : 'bg-white'; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="extra-small fw-bold <?php echo $has_resolution ? 'text-success' : 'text-muted'; ?> text-uppercase">
                                    <i class="fa-solid fa-gavel me-1"></i> มติที่ประชุม
                                </div>
                                <?php if ($has_resolution): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle extra-small"><i class="fa-solid fa-check me-1"></i>บันทึกเรียบร้อย</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($has_resolution): ?>
                                <div class="p-3 bg-white border rounded-3 text-dark">
                                    <?php echo renderAgendaContent($a['resolution']); ?>
                                </div>
                            <?php else: ?>
                                <div class="p-3 bg-light border border-dashed rounded-3 text-center text-muted small d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span><i class="fa-regular fa-clock text-muted me-1"></i> ยังไม่ได้บันทึกมติที่ประชุม</span>
                                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold" onclick="openEditAgenda(<?php echo $a_id; ?>)">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> บันทึกมติ
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ซ่อนข้อมูลดิบสำหรับเปิด Edit Modal ได้อย่างปลอดภัยโดยไม่มีปัญหา quote/tag/table -->
                        <div id="raw_agenda_<?php echo $a_id; ?>" class="d-none">
                            <div class="raw-number"><?php echo htmlspecialchars($a['agenda_number'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="raw-title"><?php echo htmlspecialchars($a['agenda_title'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="raw-detail"><?php echo htmlspecialchars($a['agenda_detail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="raw-proposal"><?php echo htmlspecialchars($a['proposal'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="raw-resolution"><?php echo htmlspecialchars($a['resolution'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        
                        <!-- Action Footer ของวาระ -->
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                            <div class="extra-small text-muted">
                                <i class="fa-solid fa-hashtag me-1"></i> รหัสวาระ: #<?php echo $a_id; ?>
                            </div>
                            <div>
                                <form method="POST" class="d-inline m-0" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบวาระนี้? ข้อมูลและเอกสารแนบทั้งหมดในวาระนี้จะถูกลบ');">
                                    <input type="hidden" name="action" value="delete_agenda">
                                    <input type="hidden" name="agenda_id" value="<?php echo $a['agenda_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none px-2 extra-small">
                                        <i class="fa-solid fa-trash me-1"></i> ลบวาระ
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div class="modal fade" id="attendeeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <div class="d-flex align-items-center">
                    <div class="bg-white bg-opacity-20 rounded-circle p-2 me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-users-gear text-white fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">จัดการผู้เข้าร่วมประชุม (สิทธิ์การเข้าถึง)</h5>
                        <div class="extra-small text-white text-opacity-75">
                            นัดหมายวันที่: <?= thaiDateFull($meeting['meeting_date']); ?> | เวลา <?= date('H:i', strtotime($meeting['start_time'])); ?> น.
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="update_attendees">
                
                <!-- Status Callout / Summary -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-body p-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div>
                                <div class="fw-bold text-dark small mb-1">
                                    <i class="fa-solid fa-shield-halved text-purple me-1"></i> ควบคุมสิทธิ์การมองเห็น
                                </div>
                                <div class="extra-small text-muted">
                                    บุคลากรที่ถูก <strong class="text-purple">ติ๊กเลือก</strong> เท่านั้นที่จะมองเห็นการประชุมนี้ในระบบและสามารถเข้าตอบรับ RSVP ได้
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-2 small fw-semibold">
                                    <i class="fa-solid fa-user-check me-1"></i>พร้อมเข้าร่วม <?= count($users_list) - $total_busy_count; ?> คน
                                </span>
                                <?php if ($total_leave_count > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-2 small fw-semibold">
                                        <i class="fa-solid fa-umbrella-beach me-1"></i>ลางาน <?= $total_leave_count; ?> คน
                                    </span>
                                <?php endif; ?>
                                <?php if ($total_training_count > 0): ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-2 small fw-semibold">
                                        <i class="fa-solid fa-plane-departure me-1"></i>ไปราชการ/อบรม <?= $total_training_count; ?> คน
                                    </span>
                                <?php endif; ?>
                                <?php if ($total_busy_count === 0): ?>
                                    <span class="badge bg-light text-muted border px-2 py-2 small">
                                        <i class="fa-solid fa-circle-check text-success me-1"></i>ไม่มีผู้ติดภารกิจในวันนี้
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Controls & Filters Bar -->
                <div class="bg-white p-3 rounded-3 border mb-3 shadow-xs">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="attendeeSearchInput" class="form-control border-start-0 bg-light" placeholder="พิมพ์ชื่อ, นามสกุล หรือสังกัดเพื่อค้นหา..." onkeyup="filterAttendeeList()">
                            </div>
                        </div>
                        <div class="col-md-7 d-flex justify-content-md-end flex-wrap gap-2">
                            <!-- Filter Type -->
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-purple active" id="filterBtnAll" onclick="setAttendeeFilter('all')">
                                    ทั้งหมด (<?= count($users_list); ?>)
                                </button>
                                <button type="button" class="btn btn-outline-success" id="filterBtnAvail" onclick="setAttendeeFilter('available')">
                                    <i class="fa-solid fa-check me-1"></i>คนว่าง
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="filterBtnBusy" onclick="setAttendeeFilter('busy')">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>ติดภารกิจ (<?= $total_busy_count; ?>)
                                </button>
                            </div>

                            <!-- Quick Select Actions -->
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-light border text-dark fw-semibold" onclick="selectAllAttendees(true)" title="เลือกทุกคนที่กำลังแสดง">
                                    <i class="fa-solid fa-check-double text-purple me-1"></i>เลือกทั้งหมด
                                </button>
                                <button type="button" class="btn btn-light border text-success fw-semibold" onclick="selectAvailableAttendees()" title="เลือกเฉพาะคนที่ไม่มีประวัติลาหรือไปราชการ">
                                    <i class="fa-solid fa-user-check me-1"></i>เลือกเฉพาะคนว่าง
                                </button>
                                <button type="button" class="btn btn-light border text-danger fw-semibold" onclick="selectAllAttendees(false)" title="ยกเลิกการเลือกทั้งหมด">
                                    <i class="fa-solid fa-xmark me-1"></i>ล้างทั้งหมด
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendees Grid -->
                <div class="bg-white border rounded-3 p-3 custom-scrollbar" style="max-height: 480px; overflow-y: auto;">
                    <div class="row g-2" id="attendeeContainer">
                        <?php 
                        $current_dept = '';
                        foreach($users_list as $u): 
                            $uid = $u['user_id'];
                            $u_leaves = $leaves_by_user[$uid] ?? [];
                            $u_trainings = $trainings_by_user[$uid] ?? [];
                            $has_leave = !empty($u_leaves);
                            $has_train = !empty($u_trainings);
                            $is_busy = ($has_leave || $has_train);
                            $is_checked = in_array($uid, $current_attendees);

                            // จัดกลุ่มตามสังกัด
                            if($current_dept != $u['dept_name']) {
                                $current_dept = $u['dept_name'];
                                echo "<div class='col-12 mt-3 mb-1 fw-bold text-purple border-bottom pb-1 dept-header'><i class='fa-regular fa-building me-1'></i> " . htmlspecialchars($current_dept ?: 'ไม่ระบุสังกัด') . "</div>";
                            }
                        ?>
                        <div class="col-md-6 attendee-item" 
                             data-name="<?= htmlspecialchars(strtolower($u['first_name'].' '.$u['last_name'].' '.($u['dept_name'] ?? ''))); ?>" 
                             data-busy="<?= $is_busy ? '1' : '0'; ?>">
                            <div class="p-2 border rounded hover-bg h-100 d-flex flex-column justify-content-between transition-all <?= $is_busy ? ($has_leave ? 'border-danger border-opacity-50 bg-danger-subtle bg-opacity-10' : 'border-warning border-opacity-50 bg-warning-subtle bg-opacity-10') : 'bg-white'; ?>">
                                <div class="d-flex align-items-start">
                                    <div class="form-check form-switch m-0 pt-1 me-2">
                                        <input class="form-check-input attendee-checkbox cursor-pointer" 
                                               type="checkbox" 
                                               name="attendees[]" 
                                               value="<?= htmlspecialchars($uid); ?>" 
                                               id="user_<?= htmlspecialchars($uid); ?>" 
                                               <?= $is_checked ? 'checked' : ''; ?> 
                                               data-busy="<?= $is_busy ? '1' : '0'; ?>" 
                                               style="transform: scale(1.15);">
                                    </div>
                                    <label class="form-check-label w-100 cursor-pointer" for="user_<?= htmlspecialchars($uid); ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold small text-dark">
                                                <?= htmlspecialchars($u['prefix_name'].$u['first_name'].' '.$u['last_name']); ?>
                                            </span>
                                            <?php if (!$is_busy): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle extra-small ms-auto">
                                                    <i class="fa-solid fa-circle-check me-1"></i>ว่าง
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="extra-small text-muted mt-0 text-truncate" style="max-width: 250px;">
                                            <?= htmlspecialchars($u['dept_name'] ?: 'ไม่ระบุสังกัด'); ?>
                                        </div>
                                    </label>
                                </div>

                                <!-- ข้อมูลการลา (ถ้ามี) -->
                                <?php if ($has_leave): ?>
                                    <div class="mt-2 pt-1 border-top border-danger border-opacity-25 ps-4 text-start">
                                        <?php foreach ($u_leaves as $lv): 
                                            $st_text = ($lv['status'] === 'approved') ? 'อนุมัติแล้ว' : 'รออนุมัติ';
                                            $st_badge = ($lv['status'] === 'approved') ? 'bg-danger text-white' : 'bg-danger-subtle text-danger border border-danger-subtle';
                                        ?>
                                            <div class="d-flex align-items-center gap-1 my-1">
                                                <span class="badge <?= $st_badge; ?> extra-small" title="<?= htmlspecialchars($lv['reason'] ?? ''); ?>">
                                                    <i class="fa-solid fa-umbrella-beach me-1"></i>ลา: <?= htmlspecialchars($lv['type_name'] ?? 'ไม่ระบุ'); ?> (<?= $st_text; ?>)
                                                </span>
                                                <span class="extra-small text-muted"><?= date('d/m', strtotime($lv['start_date'])); ?><?= ($lv['start_date'] != $lv['end_date']) ? ' - '.date('d/m', strtotime($lv['end_date'])) : ''; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- ข้อมูลการไปราชการ/อบรม (ถ้ามี) -->
                                <?php if ($has_train): ?>
                                    <div class="mt-2 pt-1 border-top border-warning border-opacity-25 ps-4 text-start">
                                        <?php foreach ($u_trainings as $tr): 
                                            $st_t_text = ($tr['status'] === 'approved' || $tr['status'] === 'reported') ? 'อนุมัติแล้ว' : 'รออนุมัติ';
                                            $st_t_badge = ($tr['status'] === 'approved' || $tr['status'] === 'reported') ? 'bg-warning text-dark' : 'bg-warning-subtle text-dark border border-warning';
                                        ?>
                                            <div class="d-flex align-items-center gap-1 my-1">
                                                <span class="badge <?= $st_t_badge; ?> extra-small text-truncate d-inline-block" style="max-width: 260px;" title="<?= htmlspecialchars($tr['project_name'].' @ '.$tr['location']); ?>">
                                                    <i class="fa-solid fa-plane-departure me-1"></i><?= htmlspecialchars($tr['report_type'] ?: 'ไปราชการ'); ?>: <?= htmlspecialchars($tr['project_name']); ?> (<?= $st_t_text; ?>)
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-xs rounded-3 p-3 mt-3 bg-white border-start border-success border-4">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="publish_now" id="publishNowCheck" value="1" <?php echo ($meeting['status'] === 'draft' || $meeting['status'] === 'upcoming') ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold small text-dark cursor-pointer" for="publishNowCheck">
                            <i class="fa-solid fa-bullhorn text-success me-1"></i> เผยแพร่การประชุมให้ผู้เข้าร่วมมองเห็นและตอบรับทันที
                        </label>
                        <div class="extra-small text-muted mt-1 ps-4">
                            * เมื่อบันทึก การประชุมจะอยู่ในสถานะ "รอการประชุม" ทำให้ผู้เข้าร่วมที่ถูกเลือกทุกคนสามารถมองเห็นในหน้าแดชบอร์ดและปฏิทินได้ทันที
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer border-0 bg-white py-3 justify-content-between">
                <div class="text-muted small">
                    <i class="fa-solid fa-users me-1 text-purple"></i> เลือกแล้ว <span id="selectedCountDisplay" class="fw-bold text-purple"><?= count($current_attendees); ?></span> คน จากทั้งหมด <?= count($users_list); ?> คน
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-save me-1"></i> บันทึกผู้เข้าร่วม
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="inboxModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; background-color: #f8f9fa;">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-inbox me-2"></i>กล่องรับเอกสาร (รอการนำเข้า)</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- แถบแจ้งเตือนเมื่อเปิดเจาะจงวาระ -->
            <div id="inboxTargetAgendaBanner" class="alert alert-light border py-2 px-3 m-3 mb-0 rounded-3 d-none">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa-solid fa-bullseye text-purple me-2"></i>
                        <span class="small">กำลังนำเข้าเอกสารสำหรับ: <strong class="text-dark" id="inboxTargetAgendaTitleText"></strong></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none extra-small" onclick="clearInboxAgendaSelection()">
                        <i class="fa-solid fa-xmark me-1"></i> แสดงแบบเลือกวาระเอง
                    </button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase"><tr><th class="ps-4">ผู้ส่ง / โน้ต</th><th>ชื่อไฟล์เดิม</th><th class="text-center" width="30%">จัดการนำเข้าวาระ</th></tr></thead>
                        <tbody>
                            <?php if(empty($inbox_files)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-box-open fs-1 text-muted opacity-50 mb-3 d-block"></i>
                                        <h6 class="fw-bold text-dark mb-1">ไม่มีเอกสารรอดำเนินการในกล่องรับเอกสาร</h6>
                                        <div class="extra-small text-muted">เมื่อผู้เข้าร่วมประชุมส่งเอกสารเสนอวาระเข้ามา ข้อมูลจะแสดงที่นี่เพื่อให้ท่านเลือกนำเข้าสู่วาระได้ทันที</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($inbox_files as $in): ?>
                                <tr class="bg-white">
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark small"><i class="fa-solid fa-user text-muted me-1"></i> <?php echo htmlspecialchars($in['first_name'] . ' ' . $in['last_name']); ?></div>
                                        <div class="extra-small text-muted bg-light p-2 rounded mt-2 border"><strong>โน้ต:</strong> <?php echo htmlspecialchars($in['note'] ?: 'ไม่ได้ระบุข้อความ'); ?></div>
                                    </td>
                                    <td>
                                        <a href="../../uploads/meetings/submissions/<?php echo $in['file_path']; ?>" target="_blank" class="text-primary fw-bold small text-decoration-none"><i class="fa-regular fa-file me-1"></i><?php echo htmlspecialchars($in['original_file_name']); ?></a>
                                        <div class="extra-small text-muted mt-1">ส่งเมื่อ: <?php echo date('H:i d/m/y', strtotime($in['submitted_at'])); ?></div>
                                    </td>
                                    <td class="text-center bg-light p-3">
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="assign_submission"><input type="hidden" name="submission_id" value="<?php echo $in['submission_id']; ?>">
                                            <div class="input-group input-group-sm mb-2 shadow-sm"><span class="input-group-text bg-white"><i class="fa-solid fa-pen text-muted"></i></span><input type="text" name="custom_file_name" class="form-control fw-bold text-purple" value="<?php echo htmlspecialchars($in['original_file_name']); ?>" required></div>
                                            <select name="agenda_id" class="form-select form-select-sm border-primary shadow-sm mb-2 fw-bold inbox-agenda-select" required>
                                                <option value="">-- เลือกวาระที่ต้องการนำเข้า --</option>
                                                <?php foreach($agendas as $ag): ?>
                                                    <option value="<?php echo $ag['agenda_id']; ?>">วาระที่ <?php echo htmlspecialchars($ag['agenda_number'] . " " . $ag['agenda_title']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold shadow-sm rounded-pill"><i class="fa-solid fa-file-import me-1"></i> นำเข้าไฟล์นี้</button>
                                        </form>
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
</div>

<div class="modal fade" id="addAgendaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1rem;" onsubmit="if (typeof tinymce !== 'undefined') tinymce.triggerSave();">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>เพิ่มวาระการประชุม</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="add_agenda">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">เลขวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_number" id="add_agenda_number" class="form-control fw-bold" placeholder="เช่น 1, 3.1" required>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small fw-bold text-muted">หัวข้อวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_title" id="add_agenda_title" class="form-control" placeholder="เช่น เรื่องรับรองรายงานการประชุม" required>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-muted mb-0">รายละเอียด / ข้อเท็จจริง</label>
                            <span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-table me-1"></i> รองรับการพิมพ์/วางตารางจาก Word หรือ Excel</span>
                        </div>
                        <textarea name="agenda_detail" id="add_agenda_detail" class="form-control" rows="5" placeholder="ระบุเนื้อหา หรือวางตารางข้อความ"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-muted mb-0">ประเด็นเสนอ</label>
                            <span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-table me-1"></i> รองรับการพิมพ์/วางตารางและข้อความจัดรูปแบบ</span>
                        </div>
                        <textarea name="proposal" id="add_proposal" class="form-control" rows="4" placeholder="เช่น เสนอที่ประชุมเพื่อโปรดพิจารณาอนุมัติ..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกวาระ</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editAgendaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1rem;" onsubmit="if (typeof tinymce !== 'undefined') tinymce.triggerSave();">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขวาระ / บันทึกมติที่ประชุม</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="edit_agenda">
                <input type="hidden" name="agenda_id" id="edit_agenda_id">
                
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted">เลขวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_number" id="edit_agenda_number" class="form-control fw-bold" required>
                    </div>
                    <div class="col-md-10">
                        <label class="form-label small fw-bold text-muted">หัวข้อวาระ <span class="text-danger">*</span></label>
                        <input type="text" name="agenda_title" id="edit_agenda_title" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-muted mb-0">รายละเอียด / ข้อเท็จจริง</label>
                            <span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-table me-1"></i> รองรับการพิมพ์/วางตารางจาก Word หรือ Excel</span>
                        </div>
                        <textarea name="agenda_detail" id="edit_agenda_detail" class="form-control" rows="5"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-muted mb-0">ประเด็นเสนอ</label>
                            <span class="badge bg-light text-muted border extra-small"><i class="fa-solid fa-table me-1"></i> รองรับการพิมพ์/วางตารางและข้อความจัดรูปแบบ</span>
                        </div>
                        <textarea name="proposal" id="edit_proposal" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="col-12 mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-success mb-0"><i class="fa-solid fa-gavel me-1"></i> มติที่ประชุม (พิมพ์หลังประชุมเสร็จ)</label>
                            <span class="badge bg-success-subtle text-success border border-success-subtle extra-small"><i class="fa-solid fa-table me-1"></i> รองรับการพิมพ์/วางตารางมติที่ประชุม</span>
                        </div>
                        <textarea name="resolution" id="edit_resolution" class="form-control border-success bg-white" rows="6" placeholder="เช่น ที่ประชุมมีมติเห็นชอบตามเสนอ..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-warning text-dark rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="uploadFileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i>อัปโหลดเอกสารแนบ</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-center">
                <input type="hidden" name="action" value="upload_file">
                <input type="hidden" name="agenda_id" id="upload_agenda_id">
                
                <div class="p-3 bg-white rounded-3 border mb-3 text-start">
                    <div class="extra-small text-muted fw-bold text-uppercase mb-1">เป้าหมายวาระ:</div>
                    <h6 class="fw-bold text-dark mb-0" id="upload_agenda_title_display">วาระ: </h6>
                </div>

                <div class="p-4 bg-white rounded-3 border border-2 border-dashed text-center mb-3">
                    <i class="fa-solid fa-file-arrow-up fs-1 text-muted opacity-50 mb-3 d-block"></i>
                    <label class="form-label fw-bold text-dark mb-2">เลือกไฟล์เอกสารจากเครื่องคอมพิวเตอร์</label>
                    <input type="file" name="attachment" class="form-control shadow-xs" required>
                    <div class="extra-small text-muted mt-2">
                        <i class="fa-solid fa-circle-info me-1"></i> รองรับไฟล์ PDF, Word, Excel, PowerPoint, รูปภาพ และ ZIP
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white py-3 justify-content-between">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-upload me-1"></i> เริ่มอัปโหลดไฟล์
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// =========================================================
// เริ่มต้นใช้งาน TinyMCE สำหรับวาระการประชุม (เหมือนหน้า draft_minutes)
// =========================================================
tinymce.init({
    selector: '#add_agenda_detail, #add_proposal, #edit_agenda_detail, #edit_proposal, #edit_resolution',
    height: 260,
    menubar: false,
    plugins: 'lists link image table code wordcount autolink preview fullscreen',
    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | removeformat code fullscreen',
    content_style: "@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Anuphan:wght@300;400;600&display=swap'); body { font-family: 'Sarabun', 'Anuphan', sans-serif; font-size: 15px; line-height: 1.6; } table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; } table, th, td { border: 1px solid #ced4da; } th { background-color: #f8f9fa; font-weight: 600; padding: 8px 10px; } td { padding: 8px 10px; }",
    table_default_attributes: {
        border: '1'
    },
    table_default_styles: {
        'border-collapse': 'collapse',
        'width': '100%'
    },
    table_responsive_width: true,
    branding: false,
    promotion: false
});

// ป้องกัน Bootstrap modal แย่ง focus จาก Popup / Dialog ของ TinyMCE (เช่น หน้าต่างแทรกตาราง, เลือกลิงก์)
document.addEventListener('focusin', function (e) {
    if (e.target.closest('.tox-tinymce-aux, .moxman-window, .tam-package-upgrade') !== null) {
        e.stopImmediatePropagation();
    }
});

// บังคับซิงค์ค่า TinyMCE ลง textarea ก่อน submit ฟอร์มทั้งหมด
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form').forEach(function(f) {
        f.addEventListener('submit', function() {
            if (typeof tinymce !== 'undefined') {
                tinymce.triggerSave();
            }
        });
    });

    const addModalEl = document.getElementById('addAgendaModal');
    if (addModalEl) {
        addModalEl.addEventListener('show.bs.modal', function () {
            document.getElementById('add_agenda_number').value = '';
            document.getElementById('add_agenda_title').value = '';
            if (tinymce.get('add_agenda_detail')) tinymce.get('add_agenda_detail').setContent('');
            if (tinymce.get('add_proposal')) tinymce.get('add_proposal').setContent('');
        });
    }
});

function openEditAgenda(id) { 
    const rawContainer = document.getElementById('raw_agenda_' + id);
    if (!rawContainer) return;

    const no = rawContainer.querySelector('.raw-number')?.textContent || '';
    const title = rawContainer.querySelector('.raw-title')?.textContent || '';
    const detail = rawContainer.querySelector('.raw-detail')?.textContent || '';
    const proposal = rawContainer.querySelector('.raw-proposal')?.textContent || '';
    const resolution = rawContainer.querySelector('.raw-resolution')?.textContent || '';

    document.getElementById('edit_agenda_id').value = id; 
    document.getElementById('edit_agenda_number').value = no; 
    document.getElementById('edit_agenda_title').value = title; 

    if (tinymce.get('edit_agenda_detail')) {
        tinymce.get('edit_agenda_detail').setContent(detail);
    } else {
        document.getElementById('edit_agenda_detail').value = detail;
    }

    if (tinymce.get('edit_proposal')) {
        tinymce.get('edit_proposal').setContent(proposal);
    } else {
        document.getElementById('edit_proposal').value = proposal;
    }

    if (tinymce.get('edit_resolution')) {
        tinymce.get('edit_resolution').setContent(resolution);
    } else {
        document.getElementById('edit_resolution').value = resolution;
    }

    new bootstrap.Modal(document.getElementById('editAgendaModal')).show(); 
}

function openUploadModal(id, titleText) { 
    document.getElementById('upload_agenda_id').value = id; 
    document.getElementById('upload_agenda_title_display').innerText = "วาระที่ " + titleText; 
    new bootstrap.Modal(document.getElementById('uploadFileModal')).show(); 
}

function openInboxForAgenda(agendaId, agendaTitle) {
    const selects = document.querySelectorAll('.inbox-agenda-select');
    selects.forEach(function(sel) {
        sel.value = agendaId;
    });

    const banner = document.getElementById('inboxTargetAgendaBanner');
    const titleText = document.getElementById('inboxTargetAgendaTitleText');
    if (banner && titleText) {
        titleText.textContent = 'วาระที่ ' + agendaTitle;
        banner.classList.remove('d-none');
    }

    new bootstrap.Modal(document.getElementById('inboxModal')).show();
}

function clearInboxAgendaSelection() {
    const banner = document.getElementById('inboxTargetAgendaBanner');
    if (banner) {
        banner.classList.add('d-none');
    }
}

// ------------------------------------------------------------------
// จัดการระบบเลือกผู้เข้าร่วมประชุม (Attendee Management JS)
// ------------------------------------------------------------------
let currentFilterType = 'all';

function updateSelectedCount() {
    const checked = document.querySelectorAll('.attendee-checkbox:checked').length;
    const countDisplay = document.getElementById('selectedCountDisplay');
    if (countDisplay) {
        countDisplay.innerText = checked;
    }
}

document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('attendee-checkbox')) {
        updateSelectedCount();
    }
});

function selectAllAttendees(checked) {
    document.querySelectorAll('.attendee-item').forEach(item => {
        if (item.style.display !== 'none') {
            const cb = item.querySelector('.attendee-checkbox');
            if (cb) cb.checked = checked;
        }
    });
    updateSelectedCount();
}

function selectAvailableAttendees() {
    document.querySelectorAll('.attendee-item').forEach(item => {
        const cb = item.querySelector('.attendee-checkbox');
        if (cb) {
            if (item.getAttribute('data-busy') === '0') {
                cb.checked = true;
            } else {
                cb.checked = false;
            }
        }
    });
    updateSelectedCount();
}

function setAttendeeFilter(type) {
    currentFilterType = type;
    
    // Toggle active button states
    document.getElementById('filterBtnAll').classList.remove('active', 'btn-purple');
    document.getElementById('filterBtnAll').classList.add('btn-outline-purple');
    document.getElementById('filterBtnAvail').classList.remove('active');
    document.getElementById('filterBtnBusy').classList.remove('active');

    if (type === 'all') {
        document.getElementById('filterBtnAll').classList.add('active', 'btn-purple');
        document.getElementById('filterBtnAll').classList.remove('btn-outline-purple');
    } else if (type === 'available') {
        document.getElementById('filterBtnAvail').classList.add('active');
    } else if (type === 'busy') {
        document.getElementById('filterBtnBusy').classList.add('active');
    }

    applyAttendeeFilters();
}

function filterAttendeeList() {
    applyAttendeeFilters();
}

function applyAttendeeFilters() {
    const q = (document.getElementById('attendeeSearchInput').value || '').toLowerCase().trim();
    
    document.querySelectorAll('.attendee-item').forEach(item => {
        const name = (item.getAttribute('data-name') || '').toLowerCase();
        const isBusy = item.getAttribute('data-busy') === '1';

        let matchesSearch = (q === '' || name.includes(q));
        let matchesFilter = true;

        if (currentFilterType === 'available') {
            matchesFilter = !isBusy;
        } else if (currentFilterType === 'busy') {
            matchesFilter = isBusy;
        }

        if (matchesSearch && matchesFilter) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });

    // ซ่อน header สังกัดถ้าทุกรายการในสังกัดนั้นถูกซ่อน
    document.querySelectorAll('.dept-header').forEach(header => {
        let sibling = header.nextElementSibling;
        let hasVisible = false;
        while (sibling && !sibling.classList.contains('dept-header')) {
            if (sibling.classList.contains('attendee-item') && sibling.style.display !== 'none') {
                hasVisible = true;
                break;
            }
            sibling = sibling.nextElementSibling;
        }
        header.style.display = hasVisible ? '' : 'none';
    });
}

// เช็ค URL Parameter หากส่ง ?open=attendees ให้เปิด Modal จัดการผู้เข้าร่วมอัตโนมัติ
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open') === 'attendees') {
        const modalEl = document.getElementById('attendeeModal');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    }
    updateSelectedCount();
});

// =========================================================
// ฟังก์ชันควบคุม UI วาระการประชุม (Expand / Search)
// =========================================================
let allAgendasExpanded = false;
function toggleExpandAllAgendas() {
    const collapses = document.querySelectorAll('#agendaAccordion .accordion-collapse');
    const btn = document.getElementById('btnToggleExpandAll');
    allAgendasExpanded = !allAgendasExpanded;
    
    collapses.forEach(function(el) {
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
        if (allAgendasExpanded) {
            bsCollapse.show();
        } else {
            bsCollapse.hide();
        }
    });
    
    if (btn) {
        if (allAgendasExpanded) {
            btn.innerHTML = '<i class="fa-solid fa-compress me-1"></i> ยุบทั้งหมด';
        } else {
            btn.innerHTML = '<i class="fa-solid fa-expand me-1"></i> ขยายทั้งหมด';
        }
    }
}

function searchAgendas() {
    const input = document.getElementById('agendaSearchInput');
    const query = input ? input.value.toLowerCase().trim() : '';
    const items = document.querySelectorAll('#agendaAccordion .agenda-item');
    const clearBtn = document.getElementById('btnClearAgendaSearch');
    const resultsCountEl = document.getElementById('agendaSearchResultsCount');
    let visibleCount = 0;
    
    if (clearBtn) {
        if (query.length > 0) clearBtn.classList.remove('d-none');
        else clearBtn.classList.add('d-none');
    }
    
    items.forEach(function(item) {
        const text = item.textContent.toLowerCase();
        if (!query || text.includes(query)) {
            item.classList.remove('d-none');
            visibleCount++;
        } else {
            item.classList.add('d-none');
        }
    });
    
    const noResults = document.getElementById('noAgendaSearchResult');
    if (noResults) {
        if (visibleCount === 0 && query !== '') {
            noResults.classList.remove('d-none');
        } else {
            noResults.classList.add('d-none');
        }
    }

    if (resultsCountEl) {
        if (query !== '') {
            resultsCountEl.classList.remove('d-none');
            resultsCountEl.textContent = 'พบ ' + visibleCount + ' จาก ' + items.length + ' วาระ';
        } else {
            resultsCountEl.classList.add('d-none');
        }
    }
}

function clearAgendaSearch() {
    const input = document.getElementById('agendaSearchInput');
    if (input) {
        input.value = '';
        searchAgendas();
        input.focus();
    }
}

// =========================================================
// ฟังก์ชันจัดการปรับลำดับวาระ (Reorder Agendas)
// =========================================================
function showReorderToast(text, type = 'success') {
    let toast = document.getElementById('reorderToast');
    let toastText = document.getElementById('reorderToastText');
    if (!toast || !toastText) return;
    
    toast.className = 'alert alert-' + type + ' shadow-lg border-0 border-start border-' + type + ' border-4 position-fixed top-0 start-50 translate-middle-x mt-4 rounded-3';
    let icon = type === 'success' ? '<i class="fa-solid fa-check-circle me-2"></i>' : (type === 'info' ? '<i class="fa-solid fa-spinner fa-spin me-2"></i>' : '<i class="fa-solid fa-circle-exclamation me-2"></i>');
    toastText.innerHTML = icon + '<strong>' + text + '</strong>';
    toast.classList.remove('d-none');
    
    clearTimeout(window.reorderToastTimeout);
    if (type !== 'info') {
        window.reorderToastTimeout = setTimeout(() => {
            toast.classList.add('d-none');
        }, 2200);
    }
}

function syncAgendaOrder() {
    let items = document.querySelectorAll('#agendaAccordion .agenda-item');
    let newOrder = [];
    items.forEach(function(item) {
        let id = item.getAttribute('data-id');
        if (id) newOrder.push(id);
    });
    
    if (newOrder.length === 0) return;

    showReorderToast('กำลังบันทึกลำดับวาระ...', 'info');

    let fd = new FormData();
    fd.append('action', 'reorder_agendas');
    fd.append('order', JSON.stringify(newOrder));
    
    fetch(window.location.href, {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            showReorderToast('บันทึกลำดับวาระเรียบร้อยแล้ว', 'success');
        } else {
            showReorderToast(d.message || 'เกิดข้อผิดพลาดในการบันทึก', 'danger');
        }
    })
    .catch(err => {
        showReorderToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'danger');
    });
}

function moveAgendaUp(e, id) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    let item = document.getElementById('agenda_item_' + id);
    if (item && item.previousElementSibling && item.previousElementSibling.classList.contains('agenda-item')) {
        item.parentNode.insertBefore(item, item.previousElementSibling);
        syncAgendaOrder();
    }
}

function moveAgendaDown(e, id) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    let item = document.getElementById('agenda_item_' + id);
    if (item && item.nextElementSibling && item.nextElementSibling.classList.contains('agenda-item')) {
        item.parentNode.insertBefore(item.nextElementSibling, item);
        syncAgendaOrder();
    }
}

// Drag & Drop สำหรับปรับลำดับวาระ
document.addEventListener('DOMContentLoaded', function() {
    const accordion = document.getElementById('agendaAccordion');
    if (!accordion) return;

    let draggedItem = null;

    accordion.querySelectorAll('.agenda-item').forEach(item => {
        const handle = item.querySelector('.reorder-controls');
        if (handle) {
            handle.setAttribute('draggable', 'true');

            handle.addEventListener('dragstart', function(e) {
                draggedItem = item;
                item.classList.add('opacity-50', 'bg-purple-subtle');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.getAttribute('data-id'));
            });

            handle.addEventListener('dragend', function() {
                if (draggedItem) {
                    draggedItem.classList.remove('opacity-50', 'bg-purple-subtle');
                    draggedItem = null;
                }
                syncAgendaOrder();
            });
        }

        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!draggedItem || draggedItem === item) return;

            const rect = item.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            if (e.clientY < midpoint) {
                accordion.insertBefore(draggedItem, item);
            } else {
                accordion.insertBefore(draggedItem, item.nextElementSibling);
            }
        });
    });

    // ควบคุมการหมุนของลูกศร Chevron เมื่อคลี่หรือพับวาระ
    accordion.querySelectorAll('.accordion-collapse').forEach(function(col) {
        col.addEventListener('show.bs.collapse', function() {
            const item = this.closest('.agenda-item');
            if (item) {
                const icon = item.querySelector('.chevron-toggle-btn i');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        });
        col.addEventListener('hide.bs.collapse', function() {
            const item = this.closest('.agenda-item');
            if (item) {
                const icon = item.querySelector('.chevron-toggle-btn i');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        });
    });
});
</script>

<style>
    .text-purple { color: #4b0082; } .bg-purple { background-color: #4b0082 !important; color: white; } .bg-purple-subtle { background-color: #f3e5f5; }
    .btn-purple { background-color: #4b0082; color: white; border: none; } .btn-purple:hover { background-color: #39048f; color: white; }
    .btn-outline-purple { color: #4b0082; border-color: #4b0082; } .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .border-purple { border-color: #4b0082 !important; } .btn-gold { background-color: #c5a028; color: white; border: none; } .btn-gold:hover { background-color: #b0914f; color: white; }
    .extra-small { font-size: 0.75rem; } .border-dashed { border-style: dashed !important; } .accordion-button:not(.collapsed) { background-color: #faf5ff; color: #4b0082; }
    
    /* สไตล์แถบหัวข้อวาระแบบ Proportional Single-line */
    .agenda-header-row {
        transition: all 0.15s ease;
        border-left: 3px solid transparent;
    }
    .agenda-header-row:hover {
        background-color: #faf7fc !important;
        border-left-color: #4b0082;
    }
    .chevron-toggle-btn {
        transition: all 0.2s ease;
    }
    .chevron-toggle-btn:hover {
        background-color: #e2e8f0 !important;
        color: #1e293b !important;
    }
    .transition-transform {
        transition: transform 0.25s ease-in-out;
    }
    .hover-scale {
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .hover-scale:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0,0,0,0.12) !important;
    }
    
    /* สไตล์สำหรับกล่องเลือกรายชื่อและ Hover */
    .hover-bg { transition: 0.2s; }
    .hover-bg:hover { background-color: #f3e5f5 !important; border-color: #4b0082 !important; }
    .hover-purple:hover { color: #4b0082 !important; }

    /* สไตล์สำหรับปุ่มปรับลำดับวาระ */
    .reorder-controls { cursor: grab !important; user-select: none; transition: background-color 0.15s ease; }
    .reorder-controls:hover { background-color: #e9ecef !important; }
    .reorder-controls:active { cursor: grabbing !important; }
    .shadow-2xs { box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
    .shadow-xs { box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
    .btn-xs { padding: 0.2rem 0.6rem; font-size: 0.75rem; }

    /* การ์ดสถิติ KPI Hub */
    .kpi-card { transition: all 0.2s ease; cursor: pointer; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); background-color: #faf5ff !important; border-color: #4b0082 !important; }
    
    @media (min-width: 992px) {
        .border-end-lg { border-right: 1px solid #dee2e6 !important; }
    }

    .pulse-animation { animation: pulse 1.8s infinite; }
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.5); }
        70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }

    /* สไตล์สำหรับตารางและเนื้อหา Rich Text */
    .custom-rich-content {
        font-family: 'Sarabun', 'Anuphan', sans-serif;
        line-height: 1.65;
        color: #212529;
    }
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
<?php require_once '../../includes/footer.php'; ?>