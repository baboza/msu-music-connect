<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'auth/session_check.php';
require_once 'config/db.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$user_id = $_SESSION['user_id'];
$msg = "";
$msg_type = "";

// ดึงข้อมูลผู้ใช้งาน 
$stmt = $pdo->prepare("SELECT u.*, d.dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // ==========================================
    // 1. อัปเดตข้อมูลส่วนตัว (Profile Info)
    // ==========================================
    if ($action == 'update_info') {
        $prefix = trim($_POST['prefix_name']);
        $fname = trim($_POST['first_name']);
        $lname = trim($_POST['last_name']);
        
        $prefix_en = trim($_POST['prefix_name_en'] ?? ''); 
        $fname_en = trim($_POST['first_name_en']); 
        $lname_en = trim($_POST['last_name_en']); 
        
        $position = trim($_POST['position'] ?? ''); 
        $position_en = trim($_POST['position_en'] ?? ''); 
        
        $phone = trim($_POST['phone'] ?? ''); // 💡 รับค่าเบอร์โทรศัพท์

        // 💡 เพิ่ม phone ลงในคำสั่ง SQL
        $stmt_update = $pdo->prepare("UPDATE users SET prefix_name = ?, first_name = ?, last_name = ?, prefix_name_en = ?, first_name_en = ?, last_name_en = ?, position = ?, position_en = ?, phone = ? WHERE user_id = ?");
        
        if ($stmt_update->execute([$prefix, $fname, $lname, $prefix_en, $fname_en, $lname_en, $position, $position_en, $phone, $user_id])) {
            $_SESSION['full_name'] = $fname . ' ' . $lname;
            $user['prefix_name'] = $prefix;
            $user['first_name'] = $fname;
            $user['last_name'] = $lname;
            $user['prefix_name_en'] = $prefix_en; 
            $user['first_name_en'] = $fname_en; 
            $user['last_name_en'] = $lname_en; 
            $user['position'] = $position;
            $user['position_en'] = $position_en; 
            $user['phone'] = $phone; // 💡 อัปเดตค่าตัวแปรในหน้าเว็บ
            
            $msg = "อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            $msg_type = "danger";
        }
    }

    // ==========================================
    // 2. ระบบอัปโหลดรูปโปรไฟล์ (Avatar)
    // ==========================================
    elseif ($action == 'upload_avatar' && isset($_FILES['avatar'])) {
        $file = $_FILES['avatar'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']; 
        $upload_dir = 'uploads/avatars/';

        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        if ($file['error'] === 0) {
            if (in_array($file['type'], $allowed_types)) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = "avatar_" . $user_id . "_" . time() . "." . $ext;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    if (!empty($user['profile_picture']) && file_exists($upload_dir . $user['profile_picture'])) {
                        unlink($upload_dir . $user['profile_picture']);
                    }
                    $stmt_update = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                    $stmt_update->execute([$new_filename, $user_id]);
                    $user['profile_picture'] = $new_filename; 
                    $msg = "อัปเดตรูปโปรไฟล์เรียบร้อยแล้ว!";
                    $msg_type = "success";
                }
            }
        }
    }

    // ==========================================
    // 3. ระบบอัปโหลดลายเซ็น (Signature)
    // ==========================================
    elseif ($action == 'upload_signature' && isset($_FILES['signature'])) {
        $file = $_FILES['signature'];
        $allowed_types = ['image/png']; 
        $upload_dir = 'uploads/signatures/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        if ($file['error'] === 0 && in_array($file['type'], $allowed_types)) {
            $new_filename = "sig_" . $user_id . "_" . time() . ".png";
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                if (!empty($user['signature_file']) && file_exists($upload_dir . $user['signature_file'])) {
                    unlink($upload_dir . $user['signature_file']);
                }
                $stmt_update = $pdo->prepare("UPDATE users SET signature_file = ? WHERE user_id = ?");
                $stmt_update->execute([$new_filename, $user_id]);
                $user['signature_file'] = $new_filename;
                $msg = "อัปเดตลายเซ็นดิจิทัลเรียบร้อยแล้ว!";
                $msg_type = "success";
            }
        }
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-id-card-personal me-2"></i>ข้อมูลส่วนตัว (Profile)</h1>
        <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill shadow-sm">
            <i class="fa-solid fa-shield-check me-1"></i> ยืนยันตัวตนผ่าน Google แล้ว
        </span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm border-0 rounded-4">
            <i class="fa-solid <?php echo ($msg_type == 'success' ? 'fa-check-circle text-success' : 'fa-triangle-exclamation text-danger'); ?> me-2"></i> 
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center p-4">
                    <div class="position-relative d-inline-block mb-4 mt-2">
                        <div class="user-avatar shadow-sm mx-auto overflow-hidden">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img src="uploads/avatars/<?php echo htmlspecialchars($user['profile_picture']); ?>" class="w-100 h-100" style="object-fit: cover;">
                            <?php else: ?>
                                <i class="fa-solid fa-user-tie text-purple"></i>
                            <?php endif; ?>
                        </div>
                        <form action="" method="POST" enctype="multipart/form-data" id="avatarForm">
                            <input type="hidden" name="action" value="upload_avatar">
                            <input type="file" name="avatar" id="avatarInput" class="d-none" onchange="document.getElementById('avatarForm').submit();">
                            <label for="avatarInput" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                        </form>
                    </div>

                    <h5 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars(($user['prefix_name'] ?? '').($user['first_name'] ?? '')." ".($user['last_name'] ?? '')); ?></h5>
                    <p class="text-muted small mb-4"><?php echo htmlspecialchars($user['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์'); ?></p>
                    
                    <div class="text-start bg-light p-4 rounded-4">
                        <div class="mb-3 border-bottom border-light pb-3">
                            <small class="text-muted d-block fw-bold text-uppercase mb-1">อีเมลมหาวิทยาลัย</small>
                            <span class="text-dark small fw-bold"><i class="fa-solid fa-envelope me-2 text-purple"></i><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                        </div>
                        <div>
                            <small class="text-muted d-block fw-bold text-uppercase mb-2">สิทธิ์การใช้งาน</small>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach($_SESSION['roles'] as $role): ?>
                                    <span class="badge bg-purple-subtle text-purple px-2 py-1 fw-normal"><?php echo htmlspecialchars($role); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white pt-4 px-4 border-bottom-0">
                    <ul class="nav nav-pills card-header-pills custom-tabs" id="profileTabs">
                        <li class="nav-item">
                            <button class="nav-link active fw-bold" id="info-tab" data-bs-toggle="tab" data-bs-target="#info"><i class="fa-solid fa-user-pen me-2"></i>ข้อมูลทั่วไป</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link fw-bold" id="signature-tab" data-bs-toggle="tab" data-bs-target="#signature"><i class="fa-solid fa-signature me-2"></i>ลายเซ็นดิจิทัล</button>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body p-4 tab-content border-top border-light mt-2">
                    <div class="tab-pane fade show active" id="info">
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="update_info">
                            
                            <h6 class="fw-bold text-purple mb-3"><i class="fa-solid fa-language me-2"></i>ข้อมูลภาษาไทย</h6>
                            <div class="row g-3 mb-4 p-3 bg-light rounded-4">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-dark">คำนำหน้า</label>
                                    <input type="text" name="prefix_name" class="form-control" value="<?php echo htmlspecialchars($user['prefix_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-dark">ชื่อจริง</label>
                                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold text-dark">นามสกุล</label>
                                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="col-md-7 mt-3">
                                    <label class="form-label small fw-bold text-dark">ตำแหน่งงาน / ความเชี่ยวชาญ</label>
                                    <input type="text" name="position" class="form-control" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>" placeholder="เช่น อาจารย์ประจำภาควิชา...">
                                </div>
                                <div class="col-md-5 mt-3">
                                    <label class="form-label small fw-bold text-dark">เบอร์โทรศัพท์ติดต่อ</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="เช่น 081-234-5678">
                                </div>
                            </div>

                            <h6 class="fw-bold text-primary mb-3 mt-4"><i class="fa-solid fa-earth-americas me-2"></i>ข้อมูลภาษาอังกฤษ (English)</h6>
                            <div class="row g-3 mb-4 p-3 bg-primary-subtle rounded-4 bg-opacity-25">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-dark">Prefix</label>
                                    <input type="text" name="prefix_name_en" class="form-control" value="<?php echo htmlspecialchars($user['prefix_name_en'] ?? ''); ?>" placeholder="Mr. / Dr.">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-dark">First Name</label>
                                    <input type="text" name="first_name_en" class="form-control" value="<?php echo htmlspecialchars($user['first_name_en'] ?? ''); ?>" placeholder="Jirawat">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold text-dark">Last Name</label>
                                    <input type="text" name="last_name_en" class="form-control" value="<?php echo htmlspecialchars($user['last_name_en'] ?? ''); ?>" placeholder="Surasa">
                                </div>

                                <div class="col-12 mt-3">
                                    <label class="form-label small fw-bold text-dark">Position</label>
                                    <input type="text" name="position_en" class="form-control" value="<?php echo htmlspecialchars($user['position_en'] ?? ''); ?>" placeholder="Lecturer, Computer Academic Officer">
                                </div>
                                
                                <div class="col-12 mt-1">
                                    <small class="text-primary extra-small fw-bold"><i class="fa-solid fa-circle-info me-1"></i> ใช้จัดหน้าในระบบสร้างประวัติ (CV) อัตโนมัติ</small>
                                </div>
                            </div>

                            <div class="text-end mt-4 pt-3 border-top border-light">
                                <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                    <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูล
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade text-center py-4" id="signature">
                        <div class="signature-preview mx-auto mb-4 p-4 rounded-4 border border-2 border-light d-flex align-items-center justify-content-center bg-light" style="min-height: 200px; max-width: 400px; border-style: dashed !important;">
                            <?php if (!empty($user['signature_file'])): ?>
                                <img src="uploads/signatures/<?php echo htmlspecialchars($user['signature_file']); ?>" style="max-height: 120px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">
                            <?php else: ?>
                                <div class="text-muted small"><i class="fa-solid fa-file-signature fs-1 d-block mb-3 opacity-25"></i> ยังไม่มีลายเซ็นในระบบ</div>
                            <?php endif; ?>
                        </div>
                        <form action="" method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded-4 shadow-sm border border-light mx-auto" style="max-width: 400px;">
                            <input type="hidden" name="action" value="upload_signature">
                            <div class="mb-4 text-start">
                                <label class="form-label small fw-bold text-dark mb-2">อัปโหลดไฟล์ลายเซ็นใหม่</label>
                                <input type="file" name="signature" class="form-control" accept="image/png" required>
                                <div class="extra-small text-danger mt-2 fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i> รองรับเฉพาะไฟล์ .png พื้นหลังโปร่งใส</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 shadow-sm"><i class="fa-solid fa-cloud-arrow-up me-2"></i>อัปเดตลายเซ็น</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    /* Custom Styles เฉพาะหน้า Profile (นอกเหนือจาก Master CSS) */
    .user-avatar { 
        width: 140px; 
        height: 140px; 
        background: #f8fafc; 
        border-radius: 50%; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 4rem; 
        border: 4px solid #fff;
    }
    
    /* ปรับแต่ง Tabs ให้นุ่มนวลขึ้น */
    .custom-tabs {
        gap: 10px;
    }
    .custom-tabs .nav-link { 
        color: var(--text-muted); 
        border-radius: 12px; 
        padding: 10px 20px; 
        background-color: transparent;
        transition: all 0.2s ease;
    }
    .custom-tabs .nav-link:hover {
        background-color: #f8fafc;
        color: var(--primary-purple);
    }
    .custom-tabs .nav-link.active { 
        background-color: var(--primary-purple); 
        color: #fff !important; 
        box-shadow: 0 4px 12px rgba(75, 0, 130, 0.2);
    }
    
    /* Utilities */
    .border-dashed { border-style: dashed !important; }
</style>

<?php require_once 'includes/footer.php'; ?>