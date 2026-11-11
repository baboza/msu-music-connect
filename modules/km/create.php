<?php
// เปิด Error ชั่วคราว (เอาออกเมื่อใช้งานจริง)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// เช็คสิทธิ์ว่าใครสามารถดันโพสต์เป็น Best Practice ได้บ้าง (คณะกรรมการ KM / ผู้บริหาร)
$can_approve_best_practice = hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction(); // เริ่ม Transaction ป้องกันข้อมูลค้างกรณีไฟล์พัง

        $title = trim($_POST['title']);
        $category = $_POST['category'];
        $post_type = $_POST['post_type'];
        $tags = trim($_POST['tags']);
        $content = $_POST['content']; 
        $status = $_POST['status']; 

        // ป้องกันสิทธิ์การตั้งค่า Best Practice
        if ($post_type == 'best_practice' && !$can_approve_best_practice) {
            $post_type = 'knowledge_sharing'; 
        }

        // จัดการ Tags
        if (!empty($tags)) {
            $tags_array = array_map('trim', explode(',', $tags));
            $tags_array = array_map(function($t) { return ltrim($t, '#'); }, $tags_array);
            $tags = implode(',', array_filter($tags_array));
        }

        // 1. บันทึกข้อมูลลงตาราง km_posts
        $stmt = $pdo->prepare("INSERT INTO km_posts (user_id, title, content, category, post_type, tags, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $content, $category, $post_type, $tags, $status]);
        
        $post_id = $pdo->lastInsertId();

        // 2. จัดการอัปโหลดไฟล์แนบ (Multiple Uploads)
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = '../../uploads/km_attachments/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                $orig_name = $_FILES['attachments']['name'][$i];
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file_type = $_FILES['attachments']['type'][$i];

                if ($tmp_name) {
                    $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
                    $new_name = uniqid('km_') . '_' . time() . '.' . $ext;
                    $dest_path = $upload_dir . $new_name;

                    if (move_uploaded_file($tmp_name, $dest_path)) {
                        $stmt_att = $pdo->prepare("INSERT INTO km_attachments (post_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                        $stmt_att->execute([$post_id, $orig_name, $new_name, $file_type, $file_size]);
                    }
                }
            }
        }

        $pdo->commit(); 

        $msg = $status == 'published' ? "เผยแพร่องค์ความรู้เรียบร้อยแล้ว!" : "บันทึกฉบับร่างเรียบร้อยแล้ว!";
        echo "<script>alert('$msg'); window.location.href='index.php';</script>";
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-pen-nib me-2"></i>แบ่งปันความรู้ (Create KM)</h1>
            <p class="text-muted small mb-0">สร้างคลังความรู้เพื่อตอบโจทย์เกณฑ์ EdPEx และ AUN-QA</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้า Feed
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
                            <input type="text" name="title" class="form-control form-control-lg border-primary shadow-sm" placeholder="เช่น เทคนิคการใช้โปรแกรม... / สรุปปัญหาจากโครงการ..." required autofocus>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">หมวดหมู่งาน <span class="text-danger">*</span></label>
                                <select name="category" class="form-select border-secondary shadow-sm" required>
                                    <option value="">-- เลือกหมวดหมู่ --</option>
                                    <option value="วิชาการและบัณฑิตศึกษา">วิชาการและบัณฑิตศึกษา</option>
                                    <option value="วิจัยและนวัตกรรม">วิจัยและนวัตกรรม</option>
                                    <option value="บริหาร แผน พัสดุ และการเงิน">บริหาร แผน พัสดุ และการเงิน (ธุรการ)</option>
                                    <option value="อาคารสถานที่และโสตทัศนูปกรณ์">อาคารสถานที่และโสตทัศนูปกรณ์</option>
                                    <option value="กิจการนิสิตและศิลปวัฒนธรรม">กิจการนิสิตและศิลปวัฒนธรรม</option>
                                    <option value="เทคโนโลยีและระบบสารสนเทศ">เทคโนโลยีและระบบสารสนเทศ (IT)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">ระดับองค์ความรู้ (QA Level) <span class="text-danger">*</span></label>
                                <select name="post_type" class="form-select border-secondary shadow-sm" required>
                                    <option value="knowledge_sharing" selected> Knowledge Sharing (แบ่งปันความรู้ทั่วไป)</option>
                                    <option value="lesson_learned"> Lesson Learned (ถอดบทเรียนจากงาน)</option>
                                    <?php if($can_approve_best_practice): ?>
                                        <option value="best_practice" class="fw-bold text-warning"> Best Practice (แนวปฏิบัติที่เป็นเลิศ)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">เนื้อหา (Content) <span class="text-danger">*</span></label>
                            <textarea name="content" id="contentEditor"></textarea>
                        </div>

                        <div class="mb-4 p-4 bg-light rounded-4 border border-light">
                            <label class="form-label fw-bold text-dark"><i class="fa-solid fa-paperclip me-2 text-primary"></i>แนบไฟล์เอกสาร (Attachments)</label>
                            <input type="file" name="attachments[]" class="form-control shadow-sm" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.jpg,.jpeg,.png">
                            <div class="form-text extra-small mt-2 text-muted">
                                <i class="fa-solid fa-circle-info me-1"></i> แนะนำไฟล์ PDF, Word, Excel หรือ ZIP (เลือกได้หลายไฟล์)
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small"><i class="fa-solid fa-hashtag me-1 text-primary"></i> แท็กค้นหา (Tags)</label>
                            <input type="text" name="tags" class="form-control border-secondary shadow-sm" placeholder="เช่น #EdPEx, #งานแผน, #KM (คั่นด้วยจุลภาค , )">
                        </div>

                        <hr class="my-4 text-muted">

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" name="status" value="draft" class="btn btn-light border fw-bold text-secondary px-4 rounded-pill shadow-sm">
                                <i class="fa-solid fa-save me-1"></i> บันทึกฉบับร่าง
                            </button>
                            <button type="submit" name="status" value="published" class="btn btn-purple fw-bold px-5 rounded-pill shadow-sm hover-lift" onclick="return validateContent();">
                                <i class="fa-solid fa-paper-plane me-1"></i> เผยแพร่ความรู้
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 bg-white sticky-top" style="top: 20px; z-index: 1;">
                <div class="card-header bg-dark text-white border-0 pt-4 pb-3 rounded-top-4">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-book-bookmark me-2 text-warning"></i>คู่มือ QA Guide</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <span class="badge bg-success rounded-pill px-3 py-2 mb-2">Sharing</span>
                        <p class="extra-small text-muted mb-0">เทคนิคทั่วไป หรือ How-to ที่ช่วยให้ทำงานสะดวกขึ้น</p>
                    </div>
                    <div class="mb-4">
                        <span class="badge bg-info text-white rounded-pill px-3 py-2 mb-2">Lesson Learned</span>
                        <p class="extra-small text-muted mb-0">การถอดบทเรียนจากปัญหา (AAR) เพื่อไม่ให้ผิดซ้ำในอนาคต</p>
                    </div>
                    <div class="border-top pt-3">
                        <span class="badge bg-warning text-dark border border-warning rounded-pill px-3 py-2 mb-2">Best Practice</span>
                        <p class="extra-small text-muted mb-0">แนวปฏิบัติมาตรฐานที่เป็นเลิศ (ต้องผ่านการอนุมัติจากคณะกรรมการ)</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .bg-purple { background-color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .extra-small { font-size: 0.8rem; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(57, 4, 143,0.3) !important; }
    .tox-promotion { display: none !important; }
</style>

<script>
    tinymce.init({
        selector: '#contentEditor',
        height: 400,
        menubar: false,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | link table image',
        content_style: "@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap'); body { font-family: 'Anuphan', sans-serif; font-size: 16px; }",
        placeholder: 'ระบุรายละเอียดองค์ความรู้ที่นี่...',
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