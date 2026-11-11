<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$success_msg = ''; $error_msg = '';

$upload_dir = '../../uploads/meetings/submissions/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

// ---------------------------------------------------------
// 1. จัดการการอัปโหลดเอกสาร
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'submit_doc') {
        $meeting_id = $_POST['meeting_id'];
        $note = trim($_POST['note']);

        if (!empty($_FILES['doc_file']['name'])) {
            $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'zip', 'rar'];
            if (!in_array($ext, $allowed)) {
                $error_msg = "ไม่อนุญาตให้อัปโหลดไฟล์นามสกุล .$ext (รองรับเฉพาะ PDF, Word, Excel, PowerPoint, รูปภาพ และ ZIP)";
            } else {
                $original_name = basename($_FILES['doc_file']['name']);
                $new_file_name = "sub_{$user_id}_" . time() . "." . $ext;
                
                if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $upload_dir . $new_file_name)) {
                    $stmt = $pdo->prepare("INSERT INTO meeting_submissions (meeting_id, user_id, original_file_name, file_path, note) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$meeting_id, $user_id, $original_name, $new_file_name, $note]);
                    $success_msg = "ส่งเอกสารให้ธุรการเรียบร้อยแล้ว!";
                } else {
                    $error_msg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์";
                }
            }
        }
    } 
    // 💡 ฟีเจอร์ใหม่: จัดการการลบเอกสาร (ถ้ายังไม่ถูกรับเรื่อง)
    elseif ($_POST['action'] == 'delete_doc') {
        $submission_id = $_POST['submission_id'];
        
        // เช็คก่อนว่าเป็นไฟล์ของตัวเองและสถานะยัง pending อยู่หรือไม่
        $stmt_check = $pdo->prepare("SELECT file_path FROM meeting_submissions WHERE submission_id = ? AND user_id = ? AND status = 'pending'");
        $stmt_check->execute([$submission_id, $user_id]);
        $doc = $stmt_check->fetch();

        if ($doc) {
            // ลบไฟล์จริงออกจากโฟลเดอร์
            if (file_exists($upload_dir . $doc['file_path'])) {
                unlink($upload_dir . $doc['file_path']);
            }
            // ลบข้อมูลออกจากฐานข้อมูล
            $pdo->prepare("DELETE FROM meeting_submissions WHERE submission_id = ?")->execute([$submission_id]);
            $success_msg = "ยกเลิกการส่งและลบเอกสารเรียบร้อยแล้ว";
        } else {
            $error_msg = "ไม่สามารถลบเอกสารได้ (เอกสารอาจถูกงานธุรการรับเข้าระบบไปแล้ว)";
        }
    }
}

// ---------------------------------------------------------
// 2. ดึงข้อมูลสำหรับแสดงผล
// ---------------------------------------------------------
// ดึงการประชุมที่กำลังจะมาถึงให้เลือก (ดึงเฉพาะที่เผยแพร่แล้ว ไม่รวม draft)
$upcoming_meetings = $pdo->query("SELECT * FROM meetings WHERE status IN ('upcoming', 'in_progress') ORDER BY meeting_date ASC")->fetchAll();

// ดึงประวัติที่ผู้ใช้คนนี้เคยส่ง
$stmt_history = $pdo->prepare("
    SELECT s.*, m.title, m.meeting_no 
    FROM meeting_submissions s 
    JOIN meetings m ON s.meeting_id = m.meeting_id 
    WHERE s.user_id = ? 
    ORDER BY s.submitted_at DESC
");
$stmt_history->execute([$user_id]);
$history = $stmt_history->fetchAll();

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i>ศูนย์ส่งเอกสารการประชุม</h1>
            <p class="text-muted small">นำส่งไฟล์นำเสนอหรือเอกสารประกอบวาระให้งานธุรการ</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-light border shadow-sm rounded-pill px-4 fw-bold text-dark">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าการประชุม
            </a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm border-0 border-start border-success border-4 alert-dismissible fade show">
            <i class="fa-solid fa-check-circle me-2"></i><?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 border-top border-purple border-4 h-100">
                <div class="card-body p-4 p-md-5 bg-light">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-paper-plane me-2 text-purple"></i>ฟอร์มนำส่งเอกสาร</h5>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_doc">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">เลือกการประชุม <span class="text-danger">*</span></label>
                            <select name="meeting_id" class="form-select border-light-subtle shadow-sm" required>
                                <option value="">-- เลือกวาระการประชุม --</option>
                                <?php foreach($upcoming_meetings as $m): ?>
                                    <option value="<?php echo $m['meeting_id']; ?>">
                                        <?php echo htmlspecialchars($m['title'] . " (ครั้งที่ " . $m['meeting_no'] . ")"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">เลือกไฟล์เอกสาร <span class="text-danger">*</span></label>
                            <input type="file" name="doc_file" class="form-control border-light-subtle shadow-sm" required>
                            <div class="extra-small text-muted mt-1"><i class="fa-solid fa-circle-info me-1"></i> รองรับไฟล์ PDF, Word, Excel, PowerPoint</div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-dark">โน้ตถึงธุรการ (ระบุวาระ)</label>
                            <textarea name="note" class="form-control border-light-subtle shadow-sm" rows="3" placeholder="เช่น รบกวนแนบเข้าวาระที่ 4.2 เรื่องการเบิกจ่ายงบประมาณ..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-purple w-100 fw-bold rounded-pill shadow-sm py-2 hover-lift">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> ส่งเอกสารให้ธุรการ
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>ประวัติการส่งเอกสารของคุณ</h6>
                    <span class="badge bg-light text-dark border"><?php echo count($history); ?> รายการ</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive custom-scrollbar" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-muted text-uppercase" style="position: sticky; top: 0; z-index: 1;">
                                <tr>
                                    <th class="ps-4" width="40%">ไฟล์ที่ส่ง</th>
                                    <th width="35%">การประชุม</th>
                                    <th class="text-center" width="15%">สถานะ</th>
                                    <th class="text-center pe-3" width="10%">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($history)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5 text-muted">
                                            <i class="fa-regular fa-folder-open fs-1 mb-2 opacity-25"></i>
                                            <p class="mb-0 small">ยังไม่เคยนำส่งเอกสาร</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($history as $h): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <a href="<?php echo $upload_dir . $h['file_path']; ?>" target="_blank" class="fw-bold text-primary small text-truncate d-block text-decoration-none" style="max-width: 250px;" title="<?php echo htmlspecialchars($h['original_file_name']); ?>">
                                                <i class="fa-regular fa-file-lines me-1 text-muted"></i> <?php echo htmlspecialchars($h['original_file_name']); ?>
                                            </a>
                                            <div class="extra-small text-muted mt-1"><i class="fa-regular fa-clock me-1"></i> <?php echo date('d/m/y H:i', strtotime($h['submitted_at'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="extra-small text-purple fw-bold text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($h['title']); ?>">
                                                <?php echo htmlspecialchars($h['title']); ?>
                                            </div>
                                            <?php if(!empty($h['note'])): ?>
                                                <div class="extra-small text-muted text-truncate mt-1" style="max-width: 180px;" title="<?php echo htmlspecialchars($h['note']); ?>">
                                                    <i class="fa-solid fa-comment-dots text-warning"></i> <?php echo htmlspecialchars($h['note']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if($h['status'] == 'assigned'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success fw-normal px-2 py-1"><i class="fa-solid fa-check-double me-1"></i>รับแล้ว</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning fw-normal px-2 py-1"><i class="fa-solid fa-spinner fa-spin me-1"></i>รอตรวจ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center pe-3">
                                            <?php if($h['status'] == 'pending'): ?>
                                                <form action="" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_doc">
                                                    <input type="hidden" name="submission_id" value="<?php echo $h['submission_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-light text-danger rounded-circle border shadow-sm" title="ยกเลิกการส่ง" onclick="return confirm('คุณแน่ใจว่าต้องการยกเลิกและลบเอกสารนี้?');">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-light text-muted rounded-circle border" title="เอกสารถูกรับเข้าระบบแล้ว ไม่สามารถลบได้" disabled>
                                                    <i class="fa-solid fa-lock"></i>
                                                </button>
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
        </div>
    </div>
</main>

<style>
    .text-purple { color: #39048f; } 
    .bg-purple { background-color: #39048f; color: white; }
    .border-purple { border-color: #39048f !important; } 
    .extra-small { font-size: 0.75rem; }
    .btn-purple { background-color: #39048f; color: white; transition: 0.2s; border: none; } 
    .btn-purple:hover { background-color: #4b0082; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(57, 4, 143,0.2) !important;}
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    
    /* Scrollbar สำหรับตารางประวัติ */
    .custom-scrollbar::-webkit-scrollbar { width: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #dee2e6; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #adb5bd; }
</style>

<?php require_once '../../includes/footer.php'; ?>