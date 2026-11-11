<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

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
        
        $phone = trim($_POST['phone'] ?? '');

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
            $user['phone'] = $phone; 
            
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
        $upload_dir = '../uploads/avatars/';

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
                    $_SESSION['profile_picture'] = $new_filename; // Update session
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
        $upload_dir = '../uploads/signatures/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        if ($file['error'] === 0) {
            if (in_array($file['type'], $allowed_types)) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = "sig_" . $user_id . "_" . time() . "." . $ext;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    if (!empty($user['signature_file']) && file_exists($upload_dir . $user['signature_file'])) {
                        unlink($upload_dir . $user['signature_file']);
                    }
                    $stmt_update = $pdo->prepare("UPDATE users SET signature_file = ? WHERE user_id = ?");
                    $stmt_update->execute([$new_filename, $user_id]);
                    $user['signature_file'] = $new_filename; 
                    $msg = "อัปเดตลายเซ็นเรียบร้อยแล้ว!";
                    $msg_type = "success";
                }
            } else {
                $msg = "รองรับเฉพาะไฟล์ PNG เท่านั้น สำหรับลายเซ็น";
                $msg_type = "danger";
            }
        }
    }
}
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="settings.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าตั้งค่า">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">จัดการข้อมูลส่วนตัว</h5>
            <small class="text-muted">แก้ไขข้อมูลโปรไฟล์และลายเซ็นดิจิทัล</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-user-gear"></i>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
            <i class="fa-solid <?= $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?> me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- 1. รูปโปรไฟล์ -->
    <div class="bento-box p-4 mb-3 text-center">
        <h6 class="fw-bold mb-3 text-dark">รูปโปรไฟล์ (Avatar)</h6>
        <div class="position-relative d-inline-block mb-3">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" class="rounded-circle shadow" width="110" height="110" style="border: 3px solid var(--primary-light); object-fit: cover;">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'].' '.$user['last_name']) ?>&background=35005e&color=fff&size=110" alt="Profile" class="rounded-circle shadow" style="border: 3px solid var(--primary-light);">
            <?php endif; ?>
        </div>
        
        <form action="profile.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_avatar">
            <div class="input-group input-group-sm mb-2 px-3">
                <input type="file" class="form-control" name="avatar" accept="image/jpeg, image/png, image/webp" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-cloud-arrow-up me-1"></i> อัปโหลดรูปภาพ
            </button>
        </form>
        <p class="extra-small text-muted mt-2 mb-0">รองรับไฟล์ JPG, PNG, WEBP</p>
    </div>

    <!-- 2. ข้อมูลส่วนตัว -->
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-address-card text-purple me-2"></i>ข้อมูลพื้นฐาน</h6>
        
        <form action="profile.php" method="POST">
            <input type="hidden" name="action" value="update_info">
            
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <label class="form-label small text-muted mb-1">คำนำหน้า</label>
                    <input type="text" class="form-control form-control-sm" name="prefix_name" value="<?= htmlspecialchars($user['prefix_name'] ?? '') ?>" required>
                </div>
                <div class="col-8">
                    <label class="form-label small text-muted mb-1">ชื่อ</label>
                    <input type="text" class="form-control form-control-sm" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label small text-muted mb-1">นามสกุล</label>
                    <input type="text" class="form-control form-control-sm" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <hr class="text-muted opacity-25">

            <div class="row g-2 mb-3">
                <div class="col-4">
                    <label class="form-label small text-muted mb-1">Prefix</label>
                    <input type="text" class="form-control form-control-sm" name="prefix_name_en" value="<?= htmlspecialchars($user['prefix_name_en'] ?? '') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label small text-muted mb-1">First Name (EN)</label>
                    <input type="text" class="form-control form-control-sm" name="first_name_en" value="<?= htmlspecialchars($user['first_name_en'] ?? '') ?>">
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label small text-muted mb-1">Last Name (EN)</label>
                    <input type="text" class="form-control form-control-sm" name="last_name_en" value="<?= htmlspecialchars($user['last_name_en'] ?? '') ?>">
                </div>
            </div>

            <hr class="text-muted opacity-25">
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1">ตำแหน่ง (TH)</label>
                <input type="text" class="form-control form-control-sm" name="position" value="<?= htmlspecialchars($user['position'] ?? '') ?>">
            </div>
            
            <div class="mb-3">
                <label class="form-label small text-muted mb-1">Position (EN)</label>
                <input type="text" class="form-control form-control-sm" name="position_en" value="<?= htmlspecialchars($user['position_en'] ?? '') ?>">
            </div>

            <div class="mb-4">
                <label class="form-label small text-muted mb-1"><i class="fa-solid fa-phone me-1 text-purple"></i> เบอร์โทรศัพท์</label>
                <input type="tel" class="form-control form-control-sm" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary w-100 rounded-pill text-white fw-bold shadow-sm py-2">
                <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกข้อมูลส่วนตัว
            </button>
        </form>
    </div>

    <!-- 3. ลายเซ็นอิเล็กทรอนิกส์ -->
    <div class="bento-box p-4 mb-5">
        <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-signature text-purple me-2"></i>ลายเซ็นดิจิทัล</h6>
        
        <div class="bg-light p-3 rounded-4 mb-3 text-center border">
            <?php if (!empty($user['signature_file'])): ?>
                <img src="../uploads/signatures/<?= htmlspecialchars($user['signature_file']) ?>" alt="Signature" class="img-fluid" style="max-height: 80px;">
            <?php else: ?>
                <div class="text-muted py-3"><i class="fa-solid fa-pen-nib mb-2 fs-3 text-purple opacity-50"></i><br>ยังไม่มีลายเซ็น</div>
            <?php endif; ?>
        </div>
        
        <form action="profile.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_signature">
            <div class="input-group input-group-sm mb-2">
                <input type="file" class="form-control" name="signature" accept="image/png" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 rounded-pill text-white fw-bold shadow-sm py-2">
                <i class="fa-solid fa-signature me-1"></i> อัปโหลดลายเซ็น (PNG)
            </button>
        </form>
        <p class="extra-small text-muted mt-2 mb-0 text-center">* แนะนำให้ใช้ไฟล์ PNG แบบพื้นหลังโปร่งใส</p>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
