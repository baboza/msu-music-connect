<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์ (ต้องเป็น ธุรการ, หัวหน้าสำนักงาน, บุคลากรสายสนับสนุน หรือ admin)
$manage_roles = ['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน'];
if (!hasRole($manage_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์จัดการการประชุม'); window.location.href='index.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];
$is_admin = hasRole(['admin']);
$success_msg = '';
$error_msg = '';

// =========================================================
// ประมวลผลการเพิ่ม / แก้ไข / ลบ การประชุม
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action == 'add_meeting') {
            $title = trim($_POST['title']);
            $meeting_no = trim($_POST['meeting_no']);
            $meeting_date = $_POST['meeting_date'];
            $start_time = $_POST['start_time'];
            $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $location = trim($_POST['location']);
            $prev_meeting = !empty($_POST['previous_meeting_id']) ? $_POST['previous_meeting_id'] : null;
            $status = $_POST['status'];
            $zoom_account_id = !empty($_POST['zoom_account_id']) ? $_POST['zoom_account_id'] : null;

            $has_conflict = false;
            
            // ตรวจสอบการซ้ำซ้อนของการจอง Zoom
            if ($zoom_account_id && $end_time) {
                $start_datetime = $meeting_date . ' ' . $start_time;
                $end_datetime = $meeting_date . ' ' . $end_time;
                
                $stmt_check = $pdo->prepare("SELECT * FROM zoom_bookings WHERE zoom_account_id = ? AND ((start_time < ? AND end_time > ?) OR (start_time >= ? AND start_time < ?))");
                $stmt_check->execute([$zoom_account_id, $end_datetime, $start_datetime, $start_datetime, $end_datetime]);
                
                if ($stmt_check->rowCount() > 0) {
                    $has_conflict = true;
                    $error_msg = "ไม่สามารถสร้างการประชุมได้: บัญชี Zoom นี้มีการจองในช่วงเวลาดังกล่าวแล้ว";
                }
            } elseif ($zoom_account_id && !$end_time) {
                $has_conflict = true;
                $error_msg = "ไม่สามารถจอง Zoom ได้: กรุณาระบุเวลาสิ้นสุดการประชุม";
            }

            if (!$has_conflict) {
                $stmt = $pdo->prepare("INSERT INTO meetings (title, meeting_no, meeting_date, start_time, end_time, location, previous_meeting_id, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $meeting_no, $meeting_date, $start_time, $end_time, $location, $prev_meeting, $user_id, $status]);
                $new_meeting_id = $pdo->lastInsertId();

                // บันทึกผู้สร้างเป็นผู้เข้าร่วมอัตโนมัติ (สถานะ: เข้าร่วม)
                $stmt_creator = $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, user_id, attendance_status, attend_mode) VALUES (?, ?, 'attending', 'onsite')");
                $stmt_creator->execute([$new_meeting_id, $user_id]);

                // จอง Zoom
                if ($zoom_account_id) {
                    $start_datetime = $meeting_date . ' ' . $start_time;
                    $end_datetime = $meeting_date . ' ' . $end_time;
                    $stmt_zoom = $pdo->prepare("INSERT INTO zoom_bookings (zoom_account_id, user_id, title, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
                    $stmt_zoom->execute([$zoom_account_id, $user_id, "ประชุม: " . $title, $start_datetime, $end_datetime]);
                }
                
                // นำผู้ใช้ไปยังหน้าจัดการผู้เข้าร่วมประชุม (สิทธิ์การเข้าถึง) ทันที
                header("Location: manage_agendas.php?id=" . $new_meeting_id . "&open=attendees");
                exit();
            }

        } elseif ($action == 'edit_meeting') {
            $meeting_id = $_POST['meeting_id'];
            $title = trim($_POST['title']);
            $meeting_no = trim($_POST['meeting_no']);
            $meeting_date = $_POST['meeting_date'];
            $start_time = $_POST['start_time'];
            $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $location = trim($_POST['location']);
            $prev_meeting = !empty($_POST['previous_meeting_id']) ? $_POST['previous_meeting_id'] : null;
            $status = $_POST['status'];
            $zoom_account_id = !empty($_POST['zoom_account_id']) ? $_POST['zoom_account_id'] : null;

            // ดึงข้อมูลเดิมเพื่อใช้ลบ Zoom Booking อันเก่า
            $stmt_old = $pdo->prepare("SELECT title, meeting_date, start_time FROM meetings WHERE meeting_id = ?");
            $stmt_old->execute([$meeting_id]);
            $old_m = $stmt_old->fetch();
            $old_title = "ประชุม: " . $old_m['title'];
            $old_start = $old_m['meeting_date'] . ' ' . $old_m['start_time'];

            $has_conflict = false;
            
            if ($zoom_account_id && $end_time) {
                $start_datetime = $meeting_date . ' ' . $start_time;
                $end_datetime = $meeting_date . ' ' . $end_time;
                
                // เช็คคิวซ้อน (ยกเว้นคิวเดิมของการประชุมนี้)
                $stmt_check = $pdo->prepare("SELECT * FROM zoom_bookings WHERE zoom_account_id = ? AND ((start_time < ? AND end_time > ?) OR (start_time >= ? AND start_time < ?)) AND NOT (title = ? AND start_time = ?)");
                $stmt_check->execute([$zoom_account_id, $end_datetime, $start_datetime, $start_datetime, $end_datetime, $old_title, $old_start]);
                
                if ($stmt_check->rowCount() > 0) {
                    $has_conflict = true;
                    $error_msg = "ไม่สามารถอัปเดตได้: บัญชี Zoom นี้มีการจองคิวอื่นในช่วงเวลาดังกล่าวแล้ว";
                }
            } elseif ($zoom_account_id && !$end_time) {
                $has_conflict = true;
                $error_msg = "ไม่สามารถแก้ไขได้: กรุณาระบุเวลาสิ้นสุดเพื่อจอง Zoom";
            }

            if (!$has_conflict) {
                $stmt = $pdo->prepare("UPDATE meetings SET title=?, meeting_no=?, meeting_date=?, start_time=?, end_time=?, location=?, previous_meeting_id=?, status=? WHERE meeting_id=?");
                $stmt->execute([$title, $meeting_no, $meeting_date, $start_time, $end_time, $location, $prev_meeting, $status, $meeting_id]);
                
                // ลบคิว Zoom เดิมทิ้ง
                $pdo->prepare("DELETE FROM zoom_bookings WHERE title = ? AND start_time = ?")->execute([$old_title, $old_start]);
                
                // ถ้าเลือก Zoom ใหม่/คิวใหม่ ให้เพิ่มเข้าไป
                if ($zoom_account_id) {
                    $start_datetime = $meeting_date . ' ' . $start_time;
                    $end_datetime = $meeting_date . ' ' . $end_time;
                    $stmt_zoom = $pdo->prepare("INSERT INTO zoom_bookings (zoom_account_id, user_id, title, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
                    $stmt_zoom->execute([$zoom_account_id, $user_id, "ประชุม: " . $title, $start_datetime, $end_datetime]);
                }
                
                $success_msg = "อัปเดตข้อมูลการประชุมสำเร็จ!";
            }

        } elseif ($action == 'delete_meeting') {
            if (!$is_admin) {
                $error_msg = "ปฏิเสธการทำรายการ: เฉพาะผู้ดูแลระบบ (Admin) เท่านั้นที่มีสิทธิ์ลบการประชุม";
            } else {
                $meeting_id = intval($_POST['meeting_id']);
                
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
                $success_msg = "ลบการประชุมและไฟล์ที่เกี่ยวข้องเรียบร้อยแล้ว!";
            }
        } elseif ($action == 'publish_meeting') {
            $meeting_id = intval($_POST['meeting_id'] ?? 0);
            if ($meeting_id > 0) {
                $stmt = $pdo->prepare("UPDATE meetings SET status = 'upcoming' WHERE meeting_id = ?");
                $stmt->execute([$meeting_id]);
                $success_msg = "เผยแพร่การประชุมเรียบร้อยแล้ว (ผู้เข้าร่วมทุกคนสามารถมองเห็นและตอบรับการประชุมได้แล้ว)";
            }
        }
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// =========================================================
// 💡 ดึงข้อมูล Zoom Accounts
// =========================================================
$stmt_zoom = $pdo->query("SELECT * FROM zoom_accounts ORDER BY account_name ASC");
$zoom_accounts = $stmt_zoom->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 💡 ดึงข้อมูลมาแสดงผล (อัปเกรด Query เพื่อนับสถิติ RSVP)
// =========================================================
$stmt_meetings = $pdo->query("
    SELECT 
        m.*,
        COUNT(a.attendee_id) as total_invited,
        SUM(CASE WHEN a.attendance_status = 'attending' THEN 1 ELSE 0 END) as rsvp_attending,
        SUM(CASE WHEN a.attendance_status = 'absent' THEN 1 ELSE 0 END) as rsvp_absent,
        SUM(CASE WHEN a.attendance_status = 'pending' THEN 1 ELSE 0 END) as rsvp_pending,
        (SELECT zoom_account_id FROM zoom_bookings z WHERE z.title COLLATE utf8mb4_unicode_ci = CONCAT('ประชุม: ', m.title) COLLATE utf8mb4_unicode_ci AND z.start_time = CONCAT(m.meeting_date, ' ', m.start_time) LIMIT 1) as current_zoom_account_id
    FROM meetings m
    LEFT JOIN meeting_attendees a ON m.meeting_id = a.meeting_id
    GROUP BY m.meeting_id
    ORDER BY m.meeting_date DESC, m.start_time DESC
");
$all_meetings = $stmt_meetings->fetchAll(PDO::FETCH_ASSOC);

// ฟังก์ชันแปลงวันที่ไทย
function thaiDateShort($date) {
    if(!$date) return '-';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $timestamp = strtotime($date);
    return date("j", $timestamp) . " " . $months[date("n", $timestamp)] . " " . (date("Y", $timestamp) + 543);
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-folder-tree me-2"></i>จัดการการประชุม (Manage Meetings)</h1>
            <p class="text-muted small">สร้างการประชุม, กำหนดวันเวลา และจัดการวาระการประชุม</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 gap-2">
            <a href="index.php" class="btn btn-light border shadow-sm rounded-pill px-4 fw-bold">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าแรก
            </a>
            <button class="btn btn-purple shadow-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addMeetingModal">
                <i class="fa-solid fa-plus me-2"></i>สร้างการประชุมใหม่
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm border-0 border-start border-success border-4 alert-dismissible fade show">
            <i class="fa-solid fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 border-top border-purple border-4 bg-white rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive custom-scrollbar">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4" width="12%">วันที่</th>
                            <th width="30%">หัวข้อการประชุม</th>
                            <th class="text-center" width="15%">การตอบรับ (RSVP)</th>
                            <th class="text-center" width="15%">สถานะ</th>
                            <th class="text-center pe-4" width="28%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_meetings)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-box-open fs-1 mb-3 opacity-25"></i>
                                    <h6 class="fw-bold mb-0">ยังไม่มีการประชุมในระบบ</h6>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_meetings as $m): 
                                // จัดการสีของสถานะ
                                $badge_class = 'bg-secondary';
                                $status_text = 'ไม่ทราบ';
                                if($m['status'] == 'draft') { $badge_class = 'bg-secondary'; $status_text = 'แบบร่าง'; }
                                elseif($m['status'] == 'upcoming') { $badge_class = 'bg-warning text-dark'; $status_text = 'รอประชุม'; }
                                elseif($m['status'] == 'in_progress') { $badge_class = 'bg-success pulse-animation'; $status_text = 'กำลังประชุม'; }
                                elseif($m['status'] == 'completed') { $badge_class = 'bg-purple'; $status_text = 'เสร็จสิ้น'; }
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark small"><?php echo thaiDateShort($m['meeting_date']); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('H:i', strtotime($m['start_time'])); ?> น.</div>
                                </td>
                                <td>
                                    <div class="fw-bold text-purple small text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($m['title']); ?>">
                                        <span class="text-dark me-1">[<?php echo htmlspecialchars($m['meeting_no']); ?>]</span>
                                        <?php echo htmlspecialchars($m['title']); ?>
                                    </div>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 300px;"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars($m['location'] ?: 'ไม่ระบุสถานที่'); ?></div>
                                </td>
                                
                                <td class="text-center">
                                    <?php if ($m['total_invited'] > 0): ?>
                                        <a href="view.php?id=<?php echo $m['meeting_id']; ?>" class="text-decoration-none d-block hover-lift" title="คลิกเพื่อดูรายละเอียดรายบุคคล">
                                            <div class="d-flex justify-content-center gap-1">
                                                <span class="badge bg-success-subtle text-success border border-success fw-normal px-2" title="เข้าร่วม"><?php echo $m['rsvp_attending']; ?></span>
                                                <span class="badge bg-danger-subtle text-danger border border-danger fw-normal px-2" title="ลา/ไม่เข้า"><?php echo $m['rsvp_absent']; ?></span>
                                                <span class="badge bg-warning-subtle text-dark border border-warning fw-normal px-2" title="รอตอบ"><?php echo $m['rsvp_pending']; ?></span>
                                            </div>
                                            <div class="extra-small text-muted mt-1">จากเชิญ <?php echo $m['total_invited']; ?> คน</div>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border fw-normal">ยังไม่ระบุคน</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge <?php echo $badge_class; ?> shadow-sm fw-bold px-3 py-2 rounded-pill"><?php echo $status_text; ?></span>
                                    <?php if ($m['status'] === 'draft'): ?>
                                        <form method="POST" class="d-block mt-1" onsubmit="return confirm('ยืนยันเผยแพร่การประชุมนี้ให้ผู้เข้าร่วมมองเห็น?');">
                                            <input type="hidden" name="action" value="publish_meeting">
                                            <input type="hidden" name="meeting_id" value="<?php echo $m['meeting_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success rounded-pill shadow-sm px-2 py-0 fw-bold extra-small" title="เผยแพร่ให้ผู้เข้าร่วมเห็นและตอบรับทันที">
                                                <i class="fa-solid fa-bullhorn me-1"></i> เผยแพร่
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <a href="manage_agendas.php?id=<?php echo $m['meeting_id']; ?>&open=attendees" class="btn btn-sm btn-outline-info text-dark rounded-pill shadow-sm px-3 fw-bold me-1" title="จัดการผู้เข้าร่วมประชุม (สิทธิ์การเข้าถึง)">
                                        <i class="fa-solid fa-users me-1"></i> ผู้เข้าร่วม
                                    </a>
                                    <a href="manage_agendas.php?id=<?php echo $m['meeting_id']; ?>" class="btn btn-sm btn-outline-purple rounded-pill shadow-sm px-3 fw-bold me-1">
                                        <i class="fa-solid fa-list-check me-1"></i> วาระ
                                    </a>
                                    <a href="draft_minutes.php?id=<?php echo $m['meeting_id']; ?>" class="btn btn-sm btn-outline-success rounded-pill shadow-sm px-3 fw-bold me-1" title="จัดทำ/พิมพ์รายงานการประชุม">
    <i class="fa-solid fa-file-word me-1"></i> รายงาน
</a>
                                    
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle shadow-sm me-1 text-primary"
                                        onclick="openEditModal(
                                            '<?php echo $m['meeting_id']; ?>',
                                            '<?php echo htmlspecialchars(addslashes($m['title'])); ?>',
                                            '<?php echo htmlspecialchars(addslashes($m['meeting_no'])); ?>',
                                            '<?php echo $m['meeting_date']; ?>',
                                            '<?php echo date('H:i', strtotime($m['start_time'])); ?>',
                                            '<?php echo $m['end_time'] ? date('H:i', strtotime($m['end_time'])) : ''; ?>',
                                            '<?php echo htmlspecialchars(addslashes($m['location'])); ?>',
                                            '<?php echo $m['previous_meeting_id']; ?>',
                                            '<?php echo $m['status']; ?>',
                                            '<?php echo $m['current_zoom_account_id']; ?>'
                                        )" title="แก้ไขข้อมูลการประชุม">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <?php if ($is_admin): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบการประชุมนี้? (วาระและไฟล์แนบจะถูกลบทั้งหมด)');">
                                        <input type="hidden" name="action" value="delete_meeting">
                                        <input type="hidden" name="meeting_id" value="<?php echo $m['meeting_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border rounded-circle shadow-sm text-danger" title="ลบการประชุม">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
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

<div class="modal fade" id="addMeetingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header bg-purple text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>สร้างการประชุมใหม่</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="add_meeting">
                
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label small fw-bold text-muted">หัวข้อการประชุม <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control border-purple shadow-sm" placeholder="เช่น การประชุมคณะกรรมการประจำวิทยาลัย..." required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">ครั้งที่ <span class="text-danger">*</span></label>
                        <input type="text" name="meeting_no" class="form-control border-purple shadow-sm text-center" placeholder="เช่น 3/2569" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">วันที่ประชุม <span class="text-danger">*</span></label>
                        <input type="date" name="meeting_date" class="form-control border-purple shadow-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">เวลาเริ่ม <span class="text-danger">*</span></label>
                        <input type="time" name="start_time" class="form-control border-purple shadow-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">เวลาสิ้นสุด (ถ้ามี)</label>
                        <input type="time" name="end_time" class="form-control border-purple shadow-sm">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted">สถานที่ประชุม / ลิงก์ออนไลน์</label>
                        <input type="text" name="location" class="form-control border-purple shadow-sm" placeholder="เช่น ห้องประชุมชั้น 2 หรือ ลิงก์ Google Meet">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted"><i class="fa-solid fa-video text-primary me-1"></i> เลือกลิงก์ Zoom เพื่อจองอัตโนมัติ (ถ้ามี)</label>
                        <select name="zoom_account_id" class="form-select border-primary shadow-sm">
                            <option value="">-- ไม่ใช้ / ไม่จอง Zoom --</option>
                            <?php foreach ($zoom_accounts as $z): ?>
                                <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['account_name'] . ' (' . $z['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">* หากเลือก Zoom ระบบจะทำการตรวจสอบคิวว่าง และจองคิวในโมดูล Zoom ให้โดยอัตโนมัติ (ต้องระบุเวลาสิ้นสุดการประชุมด้วย)</div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label small fw-bold text-muted"><i class="fa-solid fa-link text-primary me-1"></i>อ้างอิงการประชุมครั้งก่อนหน้า (เพื่อรับรองรายงาน)</label>
                        <select name="previous_meeting_id" class="form-select border-purple shadow-sm">
                            <option value="">-- ไม่มีการอ้างอิง --</option>
                            <?php foreach ($all_meetings as $prev): ?>
                                <option value="<?php echo $prev['meeting_id']; ?>">
                                    [<?php echo thaiDateShort($prev['meeting_date']); ?>] <?php echo htmlspecialchars($prev['title'] . ' ครั้งที่ ' . $prev['meeting_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">สถานะเริ่มต้น <span class="text-danger">*</span></label>
                        <select name="status" class="form-select border-purple shadow-sm fw-bold" required>
                            <option value="upcoming" selected>รอการประชุม (เผยแพร่ให้ผู้เข้าร่วมเห็นและตอบรับทันที)</option>
                            <option value="draft">แบบร่าง (ยังไม่เผยแพร่ - ซ่อนจากผู้เข้าร่วม)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 py-3">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-5 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editMeetingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขการประชุม</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="edit_meeting">
                <input type="hidden" name="meeting_id" id="edit_id">
                
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label small fw-bold text-muted">หัวข้อการประชุม</label>
                        <input type="text" name="title" id="edit_title" class="form-control shadow-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">ครั้งที่</label>
                        <input type="text" name="meeting_no" id="edit_no" class="form-control shadow-sm text-center" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">วันที่ประชุม</label>
                        <input type="date" name="meeting_date" id="edit_date" class="form-control shadow-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">เวลาเริ่ม</label>
                        <input type="time" name="start_time" id="edit_start" class="form-control shadow-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">เวลาสิ้นสุด</label>
                        <input type="time" name="end_time" id="edit_end" class="form-control shadow-sm">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted">สถานที่ประชุม / ลิงก์ออนไลน์</label>
                        <input type="text" name="location" id="edit_location" class="form-control shadow-sm">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted"><i class="fa-solid fa-video text-primary me-1"></i> เลือกลิงก์ Zoom เพื่อจองอัตโนมัติ (ถ้ามี)</label>
                        <select name="zoom_account_id" id="edit_zoom" class="form-select border-primary shadow-sm">
                            <option value="">-- ไม่ใช้ / ไม่จอง Zoom --</option>
                            <?php foreach ($zoom_accounts as $z): ?>
                                <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['account_name'] . ' (' . $z['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">* เปลี่ยนการจอง Zoom หรือยกเลิกการจองโดยเลือก "ไม่ใช้"</div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label small fw-bold text-muted">อ้างอิงการประชุมครั้งก่อนหน้า</label>
                        <select name="previous_meeting_id" id="edit_prev" class="form-select shadow-sm">
                            <option value="">-- ไม่มีการอ้างอิง --</option>
                            <?php foreach ($all_meetings as $prev): ?>
                                <option value="<?php echo $prev['meeting_id']; ?>">
                                    [<?php echo thaiDateShort($prev['meeting_date']); ?>] <?php echo htmlspecialchars($prev['title'] . ' ครั้งที่ ' . $prev['meeting_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">ปรับสถานะ</label>
                        <select name="status" id="edit_status" class="form-select shadow-sm fw-bold" required>
                            <option value="draft">แบบร่าง (Draft)</option>
                            <option value="upcoming" class="text-warning">รอการประชุม (Upcoming)</option>
                            <option value="in_progress" class="text-success">กำลังประชุม (Live)</option>
                            <option value="completed" class="text-purple">เสร็จสิ้น (Completed)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 py-3">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">อัปเดตข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<script>
// ฟังก์ชันเติมข้อมูลลง Modal แก้ไข
function openEditModal(id, title, no, date, start, end, loc, prev, status, zoom) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_no').value = no;
    document.getElementById('edit_date').value = date;
    document.getElementById('edit_start').value = start;
    document.getElementById('edit_end').value = end;
    document.getElementById('edit_location').value = loc;
    document.getElementById('edit_prev').value = prev;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_zoom').value = zoom || '';
    
    var modal = new bootstrap.Modal(document.getElementById('editMeetingModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    function setupZoomValidation(zoomSelectId, endTimeInputId) {
        let zoomSelect = document.querySelector(zoomSelectId);
        let endTimeInput = document.querySelector(endTimeInputId);
        
        if (zoomSelect && endTimeInput) {
            zoomSelect.addEventListener('change', function() {
                if (this.value !== "") {
                    endTimeInput.required = true;
                    endTimeInput.parentElement.querySelector('label').innerHTML = 'เวลาสิ้นสุด <span class="text-danger">* (จำเป็นสำหรับ Zoom)</span>';
                } else {
                    endTimeInput.required = false;
                    endTimeInput.parentElement.querySelector('label').innerHTML = 'เวลาสิ้นสุด (ถ้ามี)';
                }
            });
        }
    }
    
    // สำหรับหน้าต่างสร้างการประชุม
    setupZoomValidation('#addMeetingModal select[name="zoom_account_id"]', '#addMeetingModal input[name="end_time"]');
    // สำหรับหน้าต่างแก้ไข
    setupZoomValidation('#edit_zoom', '#edit_end');
});
</script>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082 !important; color: white; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(75,0,130,0.2) !important; }
    .btn-outline-purple { color: #4b0082; border-color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .border-purple { border-color: #4b0082 !important; }
    .extra-small { font-size: 0.75rem; }
    
    /* Animation สำหรับสถานะ Live */
    .pulse-animation { animation: pulse 2s infinite; }
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
        70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }

    .hover-lift { transition: transform 0.2s ease; }
    .hover-lift:hover { transform: translateY(-3px); }
</style>

<?php require_once '../../includes/footer.php'; ?>