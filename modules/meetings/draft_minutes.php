<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เข้าได้เฉพาะงานธุรการ / แอดมิน / บุคลากรสายสนับสนุน
if (!hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง'); window.location.href='index.php';</script>";
    exit();
}

$meeting_id = $_GET['id'] ?? '';
if (empty($meeting_id)) {
    header("Location: manage_meeting.php");
    exit();
}

$success_msg = '';

// =========================================================
// 1. บันทึกข้อมูลรายงานการประชุม
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_minutes') {
    $minutes_content = $_POST['minutes_content'];
    
    try {
        $stmt = $pdo->prepare("UPDATE meetings SET minutes_content = ? WHERE meeting_id = ?");
        $stmt->execute([$minutes_content, $meeting_id]);
        $success_msg = "บันทึกร่างรายงานการประชุมเรียบร้อยแล้ว!";
    } catch (PDOException $e) {
        die("Error saving: " . $e->getMessage());
    }
}

// =========================================================
// 2. ดึงข้อมูลเพื่อจัดทำแบบร่าง
// =========================================================
$stmt_meet = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
$stmt_meet->execute([$meeting_id]);
$meeting = $stmt_meet->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    die("ไม่พบข้อมูลการประชุม");
}

// ดึงผู้ใช้งานทั้งหมดสำหรับเลือกลายเซ็น
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name, position, signature_file FROM users WHERE status = 'active' ORDER BY first_name ASC");
$all_users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
$users_json = json_encode($all_users);

// ถ้ายังไม่เคยมีฉบับร่างมาก่อน (minutes_content เป็น NULL) หรือสั่งซิงค์ใหม่ ให้สร้าง Template ขึ้นมาอัตโนมัติ
$html_content = $meeting['minutes_content'];

if (isset($_GET['sync']) && $_GET['sync'] == '1') {
    $html_content = ''; // บังคับสร้างใหม่
}

if (empty($html_content)) {
    
    // ฟังก์ชันแปลงวันที่
    function thaiDateLong($date) {
        $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
        return date("j", strtotime($date)) . " " . $months[date("n", strtotime($date))] . " " . (date("Y", strtotime($date)) + 543);
    }

    // ดึงผู้เข้าร่วมประชุม (RSVP)
    $stmt_att = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.position, a.attendance_status, a.absence_reason FROM meeting_attendees a JOIN users u ON a.user_id = u.user_id WHERE a.meeting_id = ? ORDER BY u.first_name ASC");
    $stmt_att->execute([$meeting_id]);
    $attendees = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    
    $list_attending = ""; $count_att = 1;
    $list_absent = ""; $count_abs = 1;
    $attendee_map = []; // Map user_id to name
    
    foreach ($attendees as $att) {
        $name = htmlspecialchars($att['first_name'] . " " . $att['last_name'] . " - " . ($att['position'] ?? 'บุคลากร'));
        $short_name = htmlspecialchars($att['first_name'] . " " . $att['last_name']);
        $attendee_map[$att['user_id']] = $short_name;
        
        if ($att['attendance_status'] == 'attending' || $att['attendance_status'] == 'pending') {
            // นับคนที่ยังไม่ตอบ (pending) ว่ามาไว้ก่อน (ธุรการค่อยลบออกเองได้)
            $list_attending .= "<div>{$count_att}. {$name}</div>";
            $count_att++;
        } else if ($att['attendance_status'] == 'absent') {
            $reason = !empty($att['absence_reason']) ? " (เนื่องจาก: " . htmlspecialchars($att['absence_reason']) . ")" : " (ติดภารกิจ)";
            $list_absent .= "<div>{$count_abs}. {$name}{$reason}</div>";
            $count_abs++;
        }
    }
    if (empty($list_absent)) $list_absent = "- ไม่มี -";

    // ดึงวาระการประชุม
    $stmt_agendas = $pdo->prepare("SELECT * FROM meeting_agendas WHERE meeting_id = ? ORDER BY sort_order ASC, LENGTH(agenda_number) ASC, agenda_number ASC");
    $stmt_agendas->execute([$meeting_id]);
    $agendas = $stmt_agendas->fetchAll(PDO::FETCH_ASSOC);

    $agenda_html = "";
    foreach ($agendas as $a) {
        $agenda_html .= "<h4 style='margin-top:20px;'><strong>วาระที่ " . htmlspecialchars($a['agenda_number'] . " " . $a['agenda_title']) . "</strong></h4>";
        if (!empty($a['agenda_detail'])) {
            $detail_rendered = ($a['agenda_detail'] !== strip_tags($a['agenda_detail'])) ? $a['agenda_detail'] : nl2br(htmlspecialchars($a['agenda_detail']));
            $agenda_html .= "<p><strong>รายละเอียด/ข้อเท็จจริง:</strong></p>" . $detail_rendered;
        }
        if (!empty($a['proposal'])) {
            $proposal_rendered = ($a['proposal'] !== strip_tags($a['proposal'])) ? $a['proposal'] : nl2br(htmlspecialchars($a['proposal']));
            $agenda_html .= "<p><strong>ประเด็นเสนอ:</strong></p>" . $proposal_rendered;
        }
        $resolution_rendered = !empty($a['resolution']) ? (($a['resolution'] !== strip_tags($a['resolution'])) ? $a['resolution'] : nl2br(htmlspecialchars($a['resolution']))) : "-";
        
        $assigned_text = "";
        if (!empty($a['assigned_to'])) {
            $assigned_arr = explode(',', $a['assigned_to']);
            $names = [];
            foreach ($assigned_arr as $uid) {
                if (isset($attendee_map[$uid])) {
                    $names[] = $attendee_map[$uid];
                }
            }
            if (!empty($names)) {
                $assigned_text = "<br><strong>ผู้รับผิดชอบ/มอบหมายงาน:</strong> " . implode(', ', $names);
            }
        }
        
        $agenda_html .= "<p><strong>มติที่ประชุม:</strong></p>" . $resolution_rendered . $assigned_text . "<hr>";
    }

    // ประกอบร่าง HTML Template ให้เหมือนหนังสือราชการ
    $m_date = thaiDateLong($meeting['meeting_date']);
    $m_start = date('H.i', strtotime($meeting['start_time']));
    $m_end = !empty($meeting['end_time']) ? date('H.i', strtotime($meeting['end_time'])) : "........";
    $m_loc = htmlspecialchars($meeting['location'] ?: '-');

    $html_content = "
        <h3 style='text-align: center;'><strong>รายงานการประชุม</strong></h3>
        <h4 style='text-align: center;'><strong>วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</strong></h4>
        <h4 style='text-align: center;'><strong>ครั้งที่ {$meeting['meeting_no']} วันที่ {$m_date} เวลา {$m_start} น.</strong></h4>
        <h4 style='text-align: center;'><strong>ณ {$m_loc}</strong></h4>
        <hr>
        <p><strong>ผู้เข้าร่วมประชุม</strong></p>
        {$list_attending}
        <br>
        <p><strong>ผู้ไม่เข้าร่วมประชุม</strong></p>
        {$list_absent}
        <br>
        <p><strong>เริ่มประชุมเวลา {$m_start} น.</strong></p>
        {$agenda_html}
        <br>
        <p><strong>ปิดประชุมเวลา {$m_end} น.</strong></p>
        <br><br>
        <table id='signature-block' style='width: 100%; text-align: center; margin-top: 50px;' border='0'>
            <tr>
                <td style='width: 33%; vertical-align: bottom;'>
                    <p style='margin: 0; white-space: nowrap;'>ลงชื่อ...........................ผู้บันทึก</p>
                    <p style='margin: 0; white-space: nowrap;'>(.......................................................)</p>
                    <p style='margin: 0;'>ตำแหน่ง...................................................</p>
                </td>
                <td style='width: 33%; vertical-align: bottom;'>
                    <p style='margin: 0; white-space: nowrap;'>ลงชื่อ...........................ผู้ตรวจสอบ</p>
                    <p style='margin: 0; white-space: nowrap;'>(.......................................................)</p>
                    <p style='margin: 0;'>ตำแหน่ง...................................................</p>
                </td>
                <td style='width: 33%; vertical-align: bottom;'>
                    <p style='margin: 0; white-space: nowrap;'>ลงชื่อ...........................ประธานกรรมการ</p>
                    <p style='margin: 0; white-space: nowrap;'>(.......................................................)</p>
                    <p style='margin: 0;'>ตำแหน่ง...................................................</p>
                </td>
            </tr>
        </table>
    ";
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js"></script>
<script>
  tinymce.init({
    selector: '#minutesEditor',
    height: 800,
    menubar: false,
    plugins: 'lists link image table code wordcount autolink preview fullscreen',
    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | preview fullscreen',
    content_style: "@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap'); body { font-family: 'Sarabun', sans-serif; font-size: 16pt; line-height: 1.6; padding: 20mm; } table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; } table, th, td { border: 1px solid #333; } th { background-color: #f8f9fa; font-weight: 600; padding: 6px 10px; } td { padding: 6px 10px; }",
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
</script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-file-signature me-2"></i>จัดทำรายงานการประชุม</h1>
            <p class="text-muted small mb-0">ปรับแต่ง ขยายความ และตรวจสอบความถูกต้องก่อนพิมพ์</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 gap-2">
            <a href="manage_meeting.php" class="btn btn-light border rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการ
            </a>
            <a href="draft_minutes.php?id=<?php echo $meeting_id; ?>&sync=1" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm" onclick="return confirm('⚠️ คำเตือน: การซิงค์ข้อมูลใหม่ ระบบจะดึงมติและวาระล่าสุดมาสร้างรายงานใหม่ทั้งหมด\n\n**ข้อความที่คุณเคยพิมพ์แก้ไขหรือตกแต่งไว้ในกล่องข้อความจะหายไป**\n\nต้องการดำเนินการต่อหรือไม่?');">
                <i class="fa-solid fa-rotate me-1"></i> ซิงค์ข้อมูลล่าสุดใหม่
            </a>
            <a href="print_minutes.php?id=<?php echo $meeting_id; ?>&export=doc" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-file-word me-1"></i> บันทึกเป็น .doc
            </a>
            <a href="print_minutes.php?id=<?php echo $meeting_id; ?>" target="_blank" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> ดูตัวอย่างก่อนพิมพ์
            </a>
        </div>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success shadow-sm border-start border-success border-4 alert-dismissible fade show">
            <i class="fa-solid fa-check-circle me-2"></i> <?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-info border-0 shadow-sm small">
        <i class="fa-solid fa-circle-info me-1"></i> <strong>คำแนะนำ:</strong> ระบบได้ดึงข้อมูลวาระ, Live Note และรายชื่อผู้เข้าร่วม (รวมถึงเหตุผลการลา) มาจัดเรียงให้เบื้องต้นแล้ว ท่านสามารถพิมพ์ขยายความหรือลบแก้ไขข้อความได้เหมือนการใช้ MS Word 
    </div>

    <!-- UI สำหรับเลือกลายเซ็น -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-purple text-white py-3 fw-bold rounded-top-4">
            <i class="fa-solid fa-signature me-2"></i> แทรกรายชื่อและลายเซ็นท้ายรายงาน
        </div>
        <div class="card-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="fw-bold small mb-2 text-muted">ผู้บันทึกการประชุม</label>
                    <select id="sel_recorder" class="form-select border-primary shadow-sm">
                        <option value="">-- เลือกผู้บันทึก --</option>
                        <?php foreach($all_users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['user_id']); ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="fw-bold small mb-2 text-muted">ผู้ตรวจสอบรายงาน</label>
                    <select id="sel_reviewer" class="form-select border-primary shadow-sm">
                        <option value="">-- เลือกผู้ตรวจสอบ --</option>
                        <?php foreach($all_users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['user_id']); ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="fw-bold small mb-2 text-muted">ประธานกรรมการ</label>
                    <select id="sel_chairman" class="form-select border-primary shadow-sm">
                        <option value="">-- เลือกประธานกรรมการ --</option>
                        <?php foreach($all_users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['user_id']); ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 text-end mt-3">
                    <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="insertSignatures()">
                        <i class="fa-solid fa-plus-circle me-1"></i> แทรกลายเซ็นลงในรายงาน
                    </button>
                    <div class="form-text small mt-2 text-danger">* เมื่อกดแทรก ระบบจะวางบล็อกลายเซ็นไว้ท้ายสุดของเอกสาร (ลบบล็อกเก่าออกให้ด้วยถ้ามี)</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
        <div class="card-body p-0">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_minutes">
                
                <textarea id="minutesEditor" name="minutes_content"><?php echo $html_content; ?></textarea>
                
                <div class="bg-light p-3 text-end border-top">
                    <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 fw-bold shadow-sm">
                        <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูลร่างรายงาน
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
const usersData = <?php echo $users_json; ?>;

function getUserData(userId) {
    if (!userId) return null;
    return usersData.find(u => u.user_id === userId);
}

function buildSigBox(user, roleText) {
    if (!user) {
        return `
        <td style='width: 33%; vertical-align: bottom; padding: 10px;'>
            <p style='margin: 0; white-space: nowrap;'>ลงชื่อ...........................${roleText}</p>
            <p style='margin: 0; white-space: nowrap;'>(.......................................................)</p>
            <p style='margin: 0;'>ตำแหน่ง...................................................</p>
        </td>`;
    }
    
    let sigImg = '';
    if (user.signature_file) {
        sigImg = `<div><img src="../../uploads/signatures/${user.signature_file}" width="100" height="45" style="width: 75pt; height: 35pt;"></div>`;
    } else {
        sigImg = `<div style="height: 35pt;"></div>`;
    }
    
    let position = (user.position && user.position.trim() !== '') ? user.position : '.......................................';
    
    return `
        <td style='width: 33%; vertical-align: bottom; padding: 10px;'>
            ${sigImg}
            <p style='margin: 0; white-space: nowrap;'>ลงชื่อ...........................${roleText}</p>
            <p style='margin: 0; white-space: nowrap;'>(${user.first_name} ${user.last_name})</p>
            <p style='margin: 0;'>ตำแหน่ง ${position}</p>
        </td>`;
}

function insertSignatures() {
    const recorderId = document.getElementById('sel_recorder').value;
    const reviewerId = document.getElementById('sel_reviewer').value;
    const chairmanId = document.getElementById('sel_chairman').value;
    
    const recorder = getUserData(recorderId);
    const reviewer = getUserData(reviewerId);
    const chairman = getUserData(chairmanId);
    
    const tableHtml = `
        <table id='signature-block' style='width: 100%; text-align: center; margin-top: 50px;' border='0'>
            <tr>
                ${buildSigBox(recorder, 'ผู้บันทึก')}
                ${buildSigBox(reviewer, 'ผู้ตรวจสอบ')}
                ${buildSigBox(chairman, 'ประธานกรรมการ')}
            </tr>
        </table>
    `;
    
    let editor = tinymce.get('minutesEditor');
    if (editor) {
        let content = editor.getContent();
        
        // Remove existing signature-block if it exists
        // Regex to find table with id signature-block
        let parser = new DOMParser();
        let doc = parser.parseFromString(content, 'text/html');
        let existingSig = doc.querySelector('#signature-block');
        if (existingSig) {
            existingSig.remove();
            content = doc.body.innerHTML;
        }
        
        // Append new signature block at the end
        editor.setContent(content + "<br><br>" + tableHtml);
        alert('แทรกลายเซ็นเรียบร้อยแล้ว กรุณาเลื่อนลงไปดูที่ท้ายรายงาน');
    }
}
</script>

<style>
    .text-purple { color: #39048f; }
    .btn-purple { background-color: #39048f; color: white; transition: 0.3s; }
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(57, 4, 143,0.2); }
</style>

<?php require_once '../../includes/footer.php'; ?>