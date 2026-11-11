<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$current_user_id = $_SESSION['user_id'];
$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $media_type = $_POST['media_type'] ?? 'image';
    $duration_seconds = max(5, min(120, (int)($_POST['duration_seconds'] ?? 10)));
    $enable_sound = isset($_POST['enable_sound']) && $_POST['enable_sound'] === '1' ? 1 : 0;
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $end_date = $_POST['end_date'] ?? date('Y-m-d', strtotime('+14 days'));
    $target_screen = $_POST['target_screen'] ?? 'screen1_only';
    $media_url = '';

    if (empty($title)) {
        $error_msg = 'กรุณาระบุชื่อเรื่อง/หัวข้อประชาสัมพันธ์';
    } elseif ($start_date > $end_date) {
        $error_msg = 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด';
    } else {
        // จัดการตามประเภทสื่อ
        if ($media_type === 'image') {
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['image_file']['tmp_name'];
                $fileName = $_FILES['image_file']['name'];
                $fileSize = $_FILES['image_file']['size'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowedExts)) {
                    $error_msg = 'รองรับเฉพาะไฟล์ภาพนามสกุล .jpg, .jpeg, .png, .webp เท่านั้น';
                } elseif ($fileSize > 15 * 1024 * 1024) {
                    $error_msg = 'ขนาดไฟล์รูปภาพต้องไม่เกิน 15 MB';
                } else {
                    $newFileName = 'pr_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                    $uploadDir = __DIR__ . '/../../uploads/pr/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $destination = $uploadDir . $newFileName;
                    if (move_uploaded_file($fileTmp, $destination)) {
                        $media_url = 'uploads/pr/' . $newFileName;
                    } else {
                        $error_msg = 'เกิดข้อผิดพลาดในการบันทึกไฟล์ภาพขึ้นเซิร์ฟเวอร์';
                    }
                }
            } else {
                $error_msg = 'กรุณาเลือกไฟล์ภาพสัดส่วน 16:9 ที่ต้องการแสดงผล';
            }
        } elseif ($media_type === 'canva') {
            $canva_link = trim($_POST['canva_link'] ?? '');
            if (empty($canva_link) || strpos($canva_link, 'canva.com') === false) {
                $error_msg = 'กรุณาระบุลิงก์งานออกแบบจาก Canva ที่ถูกต้อง (Public View/Embed Link)';
            } else {
                $media_url = $canva_link;
            }
        } elseif ($media_type === 'youtube') {
            $youtube_link = trim($_POST['youtube_link'] ?? '');
            if (empty($youtube_link) || (strpos($youtube_link, 'youtube.com') === false && strpos($youtube_link, 'youtu.be') === false)) {
                $error_msg = 'กรุณาระบุลิงก์วิดีโอจาก YouTube ที่ถูกต้อง';
            } else {
                $media_url = $youtube_link;
            }
        }

        // หากไม่มี Error ให้บันทึกลงฐานข้อมูล
        if (empty($error_msg) && !empty($media_url)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO pr_signage_items 
                    (user_id, title, media_type, media_url, duration_seconds, enable_sound, start_date, end_date, status, target_screen, sort_order)
                    VALUES (:uid, :title, :type, :url, :dur, :sound, :start, :end, 'pending', :target, 99)
                ");
                $stmt->execute([
                    ':uid' => $current_user_id,
                    ':title' => $title,
                    ':type' => $media_type,
                    ':url' => $media_url,
                    ':dur' => $duration_seconds,
                    ':sound' => $enable_sound,
                    ':start' => $start_date,
                    ':end' => $end_date,
                    ':target' => $target_screen
                ]);

                header("Location: index.php?msg=submitted");
                exit();
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดของฐานข้อมูล: ' . $e->getMessage();
            }
        }
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <!-- Card Form (Modern White & Purple) -->
            <div class="card card-modern overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width:42px; height:42px;">
                            <i class="fa-solid fa-file-circle-plus text-purple fs-5"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark">แบบฟอร์มส่งป้ายประชาสัมพันธ์</h4>
                            <p class="text-muted small mb-0">ส่งคำขอเพื่อให้นายทะเบียน/ฝ่ายโสตฯ/คณบดี พิจารณาขึ้นจอ LED และจอสัมผัส</p>
                        </div>
                    </div>
                    <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                        <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
                    </a>
                </div>

                <div class="card-body p-4">

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger rounded-4 d-flex align-items-center mb-4">
                            <i class="fa-solid fa-circle-exclamation fs-5 me-2"></i>
                            <div><?= htmlspecialchars($error_msg) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" id="prForm">

                        <!-- 1. Title -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">ชื่อเรื่อง / หัวข้อประชาสัมพันธ์ <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-lg rounded-3" placeholder="เช่น ขอเชิญชมคอนเสิร์ตวงดุริยางค์ซิมโฟนี ประจำปี 2570" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                        </div>

                        <!-- 2. Media Type Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">ประเภทสื่อประชาสัมพันธ์ <span class="text-danger">*</span></label>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer media-type-card active" for="type_img">
                                        <input class="form-check-input d-none" type="radio" name="media_type" id="type_img" value="image" checked onchange="switchMediaType('image')">
                                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mx-auto mb-2 icon-box" style="width:50px; height:50px;">
                                            <i class="fa-solid fa-image text-purple fs-4"></i>
                                        </div>
                                        <div class="fw-bold text-dark">รูปภาพ (16:9)</div>
                                        <div class="text-muted extra-small">JPG, PNG, WebP (1920×1080)</div>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer media-type-card" for="type_canva">
                                        <input class="form-check-input d-none" type="radio" name="media_type" id="type_canva" value="canva" onchange="switchMediaType('canva')">
                                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mx-auto mb-2 icon-box" style="width:50px; height:50px;">
                                            <i class="fa-solid fa-palette text-purple fs-4"></i>
                                        </div>
                                        <div class="fw-bold text-dark">ลิงก์ Canva</div>
                                        <div class="text-muted extra-small">Canva Presentation Link</div>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer media-type-card" for="type_yt">
                                        <input class="form-check-input d-none" type="radio" name="media_type" id="type_yt" value="youtube" onchange="switchMediaType('youtube')">
                                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mx-auto mb-2 icon-box" style="width:50px; height:50px;">
                                            <i class="fa-brands fa-youtube text-danger fs-4"></i>
                                        </div>
                                        <div class="fw-bold text-dark">วิดีโอ YouTube</div>
                                        <div class="text-muted extra-small">YouTube Video URL</div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Media Input Sections -->

                        <!-- A. Image File Upload -->
                        <div id="section_image" class="mb-4 p-4 bg-light rounded-4 border">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-cloud-arrow-up text-purple me-1"></i> แนบไฟล์รูปภาพสัดส่วน 16:9 (แนวนอน) <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="image_file" id="imageInput" class="form-control rounded-3 mb-2 bg-white" accept="image/jpeg,image/png,image/webp" onchange="previewImage(this)">
                            <div class="small text-muted mb-3">
                                <i class="fa-solid fa-circle-info me-1"></i> แนะนำขนาดภาพ <strong>1920 x 1080 พิกเซล (16:9)</strong> เพื่อให้แสดงผลเต็มขอบจอ LED ได้อย่างคมชัดสูงสุด (ขนาดไฟล์ไม่เกิน 15MB)
                            </div>

                            <!-- Image Preview Box -->
                            <div id="imagePreviewContainer" class="d-none text-center p-3 bg-dark rounded-4">
                                <img id="imagePreview" src="" alt="Preview" class="img-fluid rounded-3" style="max-height: 320px; box-shadow: 0 4px 20px rgba(0,0,0,0.5);">
                                <div id="ratioFeedback" class="mt-2 small fw-bold"></div>
                            </div>
                        </div>

                        <!-- B. Canva Link Input -->
                        <div id="section_canva" class="mb-4 p-4 bg-light rounded-4 border d-none">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-link text-purple me-1"></i> วางลิงก์งานออกแบบจาก Canva <span class="text-danger">*</span>
                            </label>
                            <input type="url" name="canva_link" id="canvaInput" class="form-control rounded-3 mb-2 bg-white" placeholder="https://www.canva.com/design/..." oninput="previewCanva(this.value)">
                            <div class="alert bg-purple-subtle py-2 px-3 small rounded-3 mb-0">
                                <strong>💡 วิธีนำลิงก์จาก Canva:</strong> ในหน้า Canva กดปุ่ม <em>Share (แชร์)</em> > เลือก <em>Public View Link (ลิงก์สำหรับดูแบบสาธารณะ)</em> หรือ <em>Embed</em> แล้วนำลิงก์มาวางในช่องนี้
                            </div>
                        </div>

                        <!-- C. YouTube Link Input -->
                        <div id="section_youtube" class="mb-4 p-4 bg-light rounded-4 border d-none">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-brands fa-youtube text-danger me-1"></i> วางลิงก์วิดีโอ YouTube <span class="text-danger">*</span>
                            </label>
                            <input type="url" name="youtube_link" id="youtubeInput" class="form-control rounded-3 mb-2 bg-white" placeholder="https://www.youtube.com/watch?v=..." oninput="previewYouTube(this.value)">
                            
                            <!-- Sound Setting Option -->
                            <div class="mt-3 p-3 bg-white rounded-3 border">
                                <label class="form-label fw-bold text-dark d-flex align-items-center justify-content-between mb-2">
                                    <span><i class="fa-solid fa-volume-high text-purple me-1"></i> การเปิดเสียงวิดีโอบนจอประชาสัมพันธ์</span>
                                    <span class="badge bg-purple-subtle text-purple border">ตั้งค่าเสียง</span>
                                </label>
                                <div class="d-flex flex-wrap gap-4 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="enable_sound" id="sound_mute" value="0" checked onchange="previewYouTube(document.getElementById('youtubeInput').value)">
                                        <label class="form-check-label fw-semibold text-secondary" for="sound_mute">
                                            <i class="fa-solid fa-volume-xmark text-muted me-1"></i> ปิดเสียง (Mute) <span class="badge bg-light text-muted border ms-1">แนะนำสำหรับจอสาธารณะ</span>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="enable_sound" id="sound_on" value="1" onchange="previewYouTube(document.getElementById('youtubeInput').value)">
                                        <label class="form-check-label fw-bold text-success" for="sound_on">
                                            <i class="fa-solid fa-volume-high text-success me-1"></i> เปิดเสียงวิดีโอ (Enable Sound)
                                        </label>
                                    </div>
                                </div>
                                <div class="form-text text-muted extra-small mt-2">
                                    <i class="fa-solid fa-circle-info me-1"></i> หากเลือก <strong>เปิดเสียง</strong> วิดีโอจะส่งเสียงออกลำโพงเมื่อถึงคิวสไลด์ของท่านบนจอ LED (หากเลือกปิดเสียง วิดีโอจะเล่นเฉพาะภาพเงียบๆ)
                                </div>
                            </div>

                            <div id="ytPreviewBox" class="d-none ratio ratio-16x9 rounded-4 overflow-hidden bg-black shadow-sm mt-3" style="max-height: 320px;">
                                <iframe id="ytIframe" src="" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                            </div>
                        </div>

                        <!-- 3. Duration & Target Screen -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">ระยะเวลาแสดงผลต่อรอบ (วินาที)</label>
                                <div class="input-group">
                                    <input type="number" name="duration_seconds" class="form-control rounded-start-3" min="5" max="120" value="10" required>
                                    <span class="input-group-text rounded-end-3 bg-white text-muted">วินาที</span>
                                </div>
                                <div class="form-text text-muted small">แนะนำ 10 - 15 วินาที สำหรับภาพนิ่ง</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">เป้าหมายจอแสดงผล</label>
                                <select name="target_screen" class="form-select rounded-3">
                                    <option value="screen1_only" selected>📺 จอที่ 1: เล่นวนสไลด์เท่านั้น (Signage Auto-Loop)</option>
                                    <option value="both">📺📱 ทั้ง 2 จอ (จอเล่นวน + จอสัมผัส Interactive)</option>
                                </select>
                            </div>
                        </div>

                        <!-- 4. Schedule Dates -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">วันที่เริ่มต้นแสดงผล <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark">วันสิ้นสุดการแสดงผล <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" class="form-control rounded-3" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required>
                            </div>
                        </div>

                        <hr class="my-4 border-light">

                        <!-- Submit Buttons -->
                        <div class="d-flex justify-content-end gap-3">
                            <a href="index.php" class="btn btn-light rounded-pill px-4 text-secondary">ยกเลิก</a>
                            <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 shadow-sm">
                                <i class="fa-solid fa-paper-plane me-1"></i> ยืนยันส่งคำขอขึ้นป้าย
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</main>

<style>
    .cursor-pointer { cursor: pointer; }
    .media-type-card {
        border: 1.5px solid #e2e8f0;
        background: #ffffff;
        transition: all 0.25s ease;
    }
    .media-type-card:hover {
        border-color: #cbd5e1;
        transform: translateY(-2px);
    }
    .media-type-card.active {
        border-color: #6f42c1 !important;
        background-color: #f6f2fc !important;
        box-shadow: 0 4px 15px rgba(111, 66, 193, 0.15);
    }
    .media-type-card.active .icon-box {
        background-color: #ffffff !important;
        box-shadow: 0 2px 8px rgba(111, 66, 193, 0.15);
    }
</style>

<script>
    function switchMediaType(type) {
        document.querySelectorAll('.media-type-card').forEach(c => c.classList.remove('active'));
        if (type === 'image') document.querySelector('label[for="type_img"]').classList.add('active');
        if (type === 'canva') document.querySelector('label[for="type_canva"]').classList.add('active');
        if (type === 'youtube') document.querySelector('label[for="type_yt"]').classList.add('active');

        document.getElementById('section_image').classList.toggle('d-none', type !== 'image');
        document.getElementById('section_canva').classList.toggle('d-none', type !== 'canva');
        document.getElementById('section_youtube').classList.toggle('d-none', type !== 'youtube');
    }

    // 16:9 Image Preview & Aspect Ratio Checker
    function previewImage(input) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const w = this.width;
                    const h = this.height;
                    const ratio = (w / h).toFixed(2);
                    const is16_9 = Math.abs(ratio - (16/9).toFixed(2)) <= 0.08;

                    const preview = document.getElementById('imagePreview');
                    preview.src = e.target.result;
                    document.getElementById('imagePreviewContainer').classList.remove('d-none');

                    const feedback = document.getElementById('ratioFeedback');
                    if (is16_9) {
                        feedback.innerHTML = `<span class="text-success"><i class="fa-solid fa-circle-check"></i> สัดส่วนภาพ 16:9 สมบูรณ์ (${w}x${h} px) พร้อมแสดงเต็มจอ LED</span>`;
                    } else {
                        feedback.innerHTML = `<span class="text-warning"><i class="fa-solid fa-triangle-exclamation"></i> ภาพขนาด ${w}x${h} px (สัดส่วน ${ratio}:1 ไม่ใช่ 16:9 พอดี ระบบจะทำการแสดงฉากหลังเบลออัตโนมัติ)</span>`;
                    }
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    }

    // YouTube Live Preview
    function previewYouTube(url) {
        if (!url) {
            document.getElementById('ytPreviewBox').classList.add('d-none');
            return;
        }
        let videoId = '';
        const match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i);
        if (match && match[1]) {
            videoId = match[1];
            const isSoundOn = document.getElementById('sound_on') && document.getElementById('sound_on').checked;
            const muteParam = isSoundOn ? 'mute=0' : 'mute=1';
            document.getElementById('ytIframe').src = `https://www.youtube.com/embed/${videoId}?autoplay=0&${muteParam}`;
            document.getElementById('ytPreviewBox').classList.remove('d-none');
        } else {
            document.getElementById('ytPreviewBox').classList.add('d-none');
        }
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
