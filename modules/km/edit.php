<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($post_id === 0) {
    echo "<script>alert('ไม่พบรหัสบทความ'); window.location.href='index.php';</script>";
    exit();
}

// 1. ดึงข้อมูลโพสต์เดิม
$stmt = $pdo->prepare("SELECT * FROM km_posts WHERE post_id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    echo "<script>alert('ไม่พบบทความ หรือบทความถูกลบไปแล้ว'); window.location.href='index.php';</script>";
    exit();
}

// 2. เช็คสิทธิ์การแก้ไข (ต้องเป็นเจ้าของโพสต์ หรือ Admin เท่านั้น)
$is_admin = hasRole(['admin']);
if ($post['user_id'] !== $user_id && !$is_admin) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์แก้ไขบทความของผู้อื่น'); window.location.href='view.php?id=$post_id';</script>";
    exit();
}

// =========================================================
// 3. ระบบลบไฟล์แนบเดิม (Delete specific attachment)
// =========================================================
if (isset($_GET['delete_file'])) {
    $file_id = intval($_GET['delete_file']);
    $stmt_file = $pdo->prepare("SELECT file_path FROM km_attachments WHERE attachment_id = ? AND post_id = ?");
    $stmt_file->execute([$file_id, $post_id]);
    $file_to_delete = $stmt_file->fetchColumn();

    if ($file_to_delete) {
        $full_path = '../../uploads/km_attachments/' . $file_to_delete;
        if (file_exists($full_path)) {
            unlink($full_path); // ลบไฟล์จริงออกจากเครื่อง
        }
        $pdo->prepare("DELETE FROM km_attachments WHERE attachment_id = ?")->execute([$file_id]);
        header("Location: edit.php?id=$post_id");
        exit();
    }
}

// เช็คสิทธิ์ว่าใครสามารถจัดการ Best Practice ได้บ้าง
$can_approve_best_practice = hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี']);

// =========================================================
// 4. จัดการเมื่อมีการกดปุ่มอัปเดต (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $title = trim($_POST['title']);
        $category = $_POST['category'];
        $post_type = $_POST['post_type'];
        $tags = trim($_POST['tags']);
        $content = $_POST['content']; 
        $status = $_POST['status']; 

        // ป้องกันพนักงานทั่วไปแอบเปลี่ยนเป็น Best Practice เอง
        if ($post_type == 'best_practice' && !$can_approve_best_practice && $post['post_type'] != 'best_practice') {
            $post_type = 'knowledge_sharing'; 
        }

        // จัดการ Tags
        if (!empty($tags)) {
            $tags_array = array_map('trim', explode(',', $tags));
            $tags_array = array_map(function($t) { return ltrim($t, '#'); }, $tags_array);
            $tags = implode(',', array_filter($tags_array));
        }

        // อัปเดตข้อมูลโพสต์
        $stmt_update = $pdo->prepare("UPDATE km_posts SET title = ?, content = ?, category = ?, post_type = ?, tags = ?, status = ? WHERE post_id = ?");
        $stmt_update->execute([$title, $content, $category, $post_type, $tags, $status, $post_id]);

        // จัดการอัปโหลดไฟล์ใหม่เพิ่มเติม
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = '../../uploads/km_attachments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $file_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                $orig_name = $_FILES['attachments']['name'][$i];
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file_type = $_FILES['attachments']['type'][$i];

                if ($tmp_name) {
                    $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
                    $new_name = uniqid('km_') . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                        $stmt_att = $pdo->prepare("INSERT INTO km_attachments (post_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                        $stmt_att->execute([$post_id, $orig_name, $new_name, $file_type, $file_size]);
                    }
                }
            }
        }

        $pdo->commit();
        echo "<script>alert('อัปเดตข้อมูลและจัดการไฟล์เรียบร้อยแล้ว'); window.location.href='view.php?id=$post_id';</script>";
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงไฟล์แนบที่มีอยู่เดิมมาแสดง
$stmt_files = $pdo->prepare("SELECT * FROM km_attachments WHERE post_id = ?");
$stmt_files->execute([$post_id]);
$attachments = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขความรู้ (Edit KM)</h1>
            <p class="text-muted small mb-0">อัปเดตข้อมูลให้ทันสมัยและจัดการไฟล์แนบให้ถูกต้อง</p>
        </div>
        <div class="d-flex gap-2">
            <a href="view.php?id=<?php echo $post_id; ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> ยกเลิก
            </a>
        </div>
    </div>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo $error_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST" id="kmForm" enctype="multipart/form-data">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-purple">หัวข้อความรู้ (Title) <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-lg border-primary shadow-sm" value="<?php echo htmlspecialchars($post['title']); ?>" required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">หมวดหมู่งาน <span class="text-danger">*</span></label>
                                <select name="category" class="form-select border-secondary shadow-sm" required>
                                    <option value="">-- เลือกหมวดหมู่ --</option>
                                    <option value="วิชาการและบัณฑิตศึกษา" <?php echo ($post['category'] == 'วิชาการและบัณฑิตศึกษา') ? 'selected' : ''; ?>>วิชาการและบัณฑิตศึกษา</option>
                                    <option value="วิจัยและนวัตกรรม" <?php echo ($post['category'] == 'วิจัยและนวัตกรรม') ? 'selected' : ''; ?>>วิจัยและนวัตกรรม</option>
                                    <option value="บริหาร แผน พัสดุ และการเงิน" <?php echo ($post['category'] == 'บริหาร แผน พัสดุ และการเงิน') ? 'selected' : ''; ?>>บริหาร แผน พัสดุ และการเงิน (ธุรการ)</option>
                                    <option value="อาคารสถานที่และโสตทัศนูปกรณ์" <?php echo ($post['category'] == 'อาคารสถานที่และโสตทัศนูปกรณ์') ? 'selected' : ''; ?>>อาคารสถานที่และโสตทัศนูปกรณ์</option>
                                    <option value="กิจการนิสิตและศิลปวัฒนธรรม" <?php echo ($post['category'] == 'กิจการนิสิตและศิลปวัฒนธรรม') ? 'selected' : ''; ?>>กิจการนิสิตและศิลปวัฒนธรรม</option>
                                    <option value="เทคโนโลยีและระบบสารสนเทศ" <?php echo ($post['category'] == 'เทคโนโลยีและระบบสารสนเทศ') ? 'selected' : ''; ?>>เทคโนโลยีและระบบสารสนเทศ (IT)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">ระดับองค์ความรู้ (QA Level) <span class="text-danger">*</span></label>
                                <select name="post_type" class="form-select border-secondary shadow-sm" required>
                                    <option value="knowledge_sharing" <?php echo ($post['post_type'] == 'knowledge_sharing') ? 'selected' : ''; ?>> Knowledge Sharing</option>
                                    <option value="lesson_learned" <?php echo ($post['post_type'] == 'lesson_learned') ? 'selected' : ''; ?>> Lesson Learned</option>
                                    <?php if($can_approve_best_practice || $post['post_type'] == 'best_practice'): ?>
                                        <option value="best_practice" class="fw-bold text-warning" <?php echo ($post['post_type'] == 'best_practice') ? 'selected' : ''; ?>> Best Practice</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">เนื้อหา (Content) <span class="text-danger">*</span></label>
                            <textarea name="content" id="contentEditor"><?php echo htmlspecialchars($post['content']); ?></textarea>
                        </div>

                        <div class="mb-4 p-4 bg-light rounded-4 border border-light">
                            <label class="form-label fw-bold text-dark"><i class="fa-solid fa-paperclip me-2 text-primary"></i>จัดการไฟล์แนบ (Attachments)</label>
                            
                            <?php if (!empty($attachments)): ?>
                                <div class="list-group mb-3 shadow-sm rounded-3">
                                    <?php foreach ($attachments as $file): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center small">
                                            <span class="text-truncate" style="max-width: 80%;">
                                                <i class="fa-solid fa-file-lines me-2 text-muted"></i><?php echo htmlspecialchars($file['file_name']); ?>
                                            </span>
                                            <a href="edit.php?id=<?php echo $post_id; ?>&delete_file=<?php echo $file['attachment_id']; ?>" 
                                               class="btn btn-sm btn-outline-danger border-0" 
                                               onclick="return confirm('ลบไฟล์นี้ออกจากระบบถาวร?');">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <input type="file" name="attachments[]" class="form-control shadow-sm" multiple>
                            <div class="form-text extra-small mt-2">คุณสามารถเลือกอัปโหลดไฟล์ใหม่เพิ่มเข้าไปได้เลย (รองรับหลายไฟล์)</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small"><i class="fa-solid fa-hashtag me-1 text-primary"></i> แท็กค้นหา (Tags)</label>
                            <input type="text" name="tags" class="form-control border-secondary shadow-sm" value="<?php echo htmlspecialchars($post['tags']); ?>" placeholder="เช่น #EdPEx, #งานแผน (คั่นด้วยเครื่องหมายจุลภาค , )">
                        </div>

                        <hr class="my-4 text-muted">

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" name="status" value="draft" class="btn btn-light border fw-bold text-secondary px-4 rounded-pill shadow-sm hover-lift">
                                <i class="fa-solid fa-save me-1"></i> บันทึกฉบับร่าง
                            </button>
                            <button type="submit" name="status" value="published" class="btn btn-primary fw-bold px-5 rounded-pill shadow-sm hover-lift" onclick="return validateContent();">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i> อัปเดตองค์ความรู้
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 bg-white sticky-top" style="top: 20px;">
                <div class="card-header bg-dark text-white border-0 pt-4 pb-3 rounded-top-4">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-book-bookmark me-2 text-warning"></i>QA Guidelines</h6>
                </div>
                <div class="card-body p-4 small">
                    <p class="text-muted mb-4">การปรับปรุง KM ให้ทันสมัยเสมอ ช่วยให้หน่วยงานมีการพัฒนาอย่างต่อเนื่อง (CQI) ตามเกณฑ์ AUN-QA</p>
                    
                    <div class="mb-3">
                        <span class="badge bg-success rounded-pill px-3 py-2 mb-2">Sharing</span>
                        <p class="extra-small text-muted mb-0">เน้นเทคนิคหรือเครื่องมือที่คนอื่นนำไปใช้ต่อได้ทันที</p>
                    </div>
                    <div class="mb-3">
                        <span class="badge bg-info text-white rounded-pill px-3 py-2 mb-2">Lesson Learned</span>
                        <p class="extra-small text-muted mb-0">เน้นการสรุปบทเรียนจากโครงการ หรือแนวทางแก้ปัญหาที่เคยเกิดขึ้น</p>
                    </div>
                    <div>
                        <span class="badge bg-warning text-dark border border-warning rounded-pill px-3 py-2 mb-2">Best Practice</span>
                        <p class="extra-small text-muted mb-0">แนวปฏิบัติที่เป็นมาตรฐานและมีผลลัพธ์โดดเด่น (ต้องผ่านการรับรอง)</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .extra-small { font-size: 0.8rem; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.15) !important; }
    .tox-promotion { display: none !important; } /* ซ่อนโฆษณา TinyMCE */
</style>

<script>
    tinymce.init({
        selector: '#contentEditor',
        height: 500,
        menubar: false,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
        toolbar: 'undo redo | blocks | bold italic underline | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | link table image | removeformat',
        content_style: "@import url('https://fonts.googleapis.com/css2?family=Sarabun&display=swap'); body { font-family: 'Anuphan', sans-serif; }",
        language: 'th_TH'
    });

    function validateContent() {
        var content = tinymce.get('contentEditor').getContent();
        if (content.trim() === '') {
            alert('กรุณากรอกเนื้อหาความรู้ด้วยครับ');
            return false;
        }
        return true;
    }
</script>

<?php require_once '../../includes/footer.php'; ?>