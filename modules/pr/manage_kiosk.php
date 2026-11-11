<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($pr_approver_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง'); window.location.href='index.php';</script>";
    exit();
}

$cat_filter = $_GET['cat'] ?? 'all';
$msg = '';
$err = '';

// 8 หมวดหมู่
$cat_names = [
    'news' => 'ประกาศ / ข่าวสาร',
    'concerts' => 'งานคอนเสิร์ต / การแสดง',
    'about' => 'รู้จักวิทยาลัย',
    'programs' => 'หลักสูตรและการสมัคร',
    'staff' => 'ค้นหาบุคลากร',
    'rooms' => 'อาคารและห้องต่างๆ',
    'services' => 'บริการนิสิต',
    'achievements' => 'ผลงานและความสำเร็จ'
];

// Handle Actions (Add, Edit, Delete, Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['content_id'] ?? 0);
        $pdo->prepare("DELETE FROM pr_kiosk_contents WHERE content_id = ?")->execute([$id]);
        $msg = 'ลบรายการข้อมูลเรียบร้อยแล้ว!';
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['content_id'] ?? 0);
        $pdo->prepare("UPDATE pr_kiosk_contents SET is_active = NOT is_active WHERE content_id = ?")->execute([$id]);
        $msg = 'อัปเดตสถานะการแสดงผลเรียบร้อยแล้ว!';
    } elseif ($action === 'save') {
        $id = (int)($_POST['content_id'] ?? 0);
        $category = $_POST['category_key'] ?? 'news';
        $title_th = trim($_POST['title_th'] ?? '');
        $title_en = trim($_POST['title_en'] ?? '');
        $title_cn = trim($_POST['title_cn'] ?? '');
        $detail_th = trim($_POST['detail_th'] ?? '');
        $detail_en = trim($_POST['detail_en'] ?? '');
        $detail_cn = trim($_POST['detail_cn'] ?? '');
        $qr_link = trim($_POST['qr_link'] ?? '');
        $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
        $location = trim($_POST['location'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (empty($title_th)) {
            $err = 'กรุณาระบุหัวข้อภาษาไทย (Title TH)';
        } else {
            // จัดการรูปภาพ
            $thumb_url = trim($_POST['existing_thumb'] ?? '');
            if (isset($_FILES['thumb_file']) && $_FILES['thumb_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['thumb_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $newName = 'kiosk_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                    $uploadDir = __DIR__ . '/../../uploads/pr/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    if (move_uploaded_file($_FILES['thumb_file']['tmp_name'], $uploadDir . $newName)) {
                        $thumb_url = 'uploads/pr/' . $newName;
                    }
                }
            } elseif (!empty($_POST['thumb_url_input'])) {
                $thumb_url = trim($_POST['thumb_url_input']);
            }

            if ($id > 0) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE pr_kiosk_contents SET 
                        category_key = :cat,
                        title_th = :t_th, title_en = :t_en, title_cn = :t_cn,
                        detail_th = :d_th, detail_en = :d_en, detail_cn = :d_cn,
                        thumbnail_image = :thumb, qr_link = :qr,
                        event_date = :ev_date, location = :loc, sort_order = :sort
                    WHERE content_id = :id
                ");
                $stmt->execute([
                    ':cat' => $category,
                    ':t_th' => $title_th, ':t_en' => $title_en, ':t_cn' => $title_cn,
                    ':d_th' => $detail_th, ':d_en' => $detail_en, ':d_cn' => $detail_cn,
                    ':thumb' => $thumb_url, ':qr' => $qr_link,
                    ':ev_date' => $event_date, ':loc' => $location, ':sort' => $sort_order,
                    ':id' => $id
                ]);
                $msg = 'แก้ไขข้อมูลเรียบร้อยแล้ว!';
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO pr_kiosk_contents 
                    (category_key, title_th, title_en, title_cn, detail_th, detail_en, detail_cn, thumbnail_image, qr_link, event_date, location, sort_order, is_active)
                    VALUES (:cat, :t_th, :t_en, :t_cn, :d_th, :d_en, :d_cn, :thumb, :qr, :ev_date, :loc, :sort, 1)
                ");
                $stmt->execute([
                    ':cat' => $category,
                    ':t_th' => $title_th, ':t_en' => $title_en, ':t_cn' => $title_cn,
                    ':d_th' => $detail_th, ':d_en' => $detail_en, ':d_cn' => $detail_cn,
                    ':thumb' => $thumb_url, ':qr' => $qr_link,
                    ':ev_date' => $event_date, ':loc' => $location, ':sort' => $sort_order
                ]);
                $msg = 'เพิ่มข้อมูลใหม่เข้าจอที่ 2 สำเร็จ!';
            }
        }
    } elseif ($action === 'sync_staff') {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/music-e-office';

        $sqlUsers = "
            SELECT u.user_id, u.prefix_name, u.first_name, u.last_name, 
                   u.prefix_name_en, u.first_name_en, u.last_name_en,
                   u.position, u.position_en, u.email, u.phone, u.profile_picture, u.dept_id,
                   d.dept_name,
                   GROUP_CONCAT(r.role_id) as role_ids,
                   GROUP_CONCAT(r.role_name SEPARATOR ', ') as role_names
            FROM users u
            LEFT JOIN departments d ON u.dept_id = d.dept_id
            LEFT JOIN user_roles ur ON u.user_id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.role_id
            WHERE u.status = 'active'
            GROUP BY u.user_id
            ORDER BY u.dept_id ASC, u.first_name ASC
        ";
        $stmtUsers = $pdo->query($sqlUsers);
        $usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        $dept_map_en = [
            'ภาควิชาดนตรีศึกษา' => 'Department of Music Education',
            'ภาควิชาดุริยางคศาสตร์' => 'Department of Music Performance',
            'สำนักงานเลขานุการ' => 'Dean Office & Secretariat',
            'งานวิชาการ' => 'Academic Affairs Division',
            'งานการเงิน' => 'Finance & Accounting Division',
            'งานพัสดุ' => 'Procurement Division',
            'งานบุคคล' => 'Human Resources Division',
            'งานเทคโนโลยีสารสนเทศ' => 'Information Technology Division',
            'งานโสตทัศนศึกษา' => 'Audio-Visual Technology Division',
            'งานพัฒนานิสิต' => 'Student Development Division',
            'งานนโยบายและแผน' => 'Planning & Policy Division',
            'งานบัณฑิต' => 'Graduate Studies Division'
        ];

        $dept_map_cn = [
            'ภาควิชาดนตรีศึกษา' => '音乐教育系',
            'ภาควิชาดุริยางคศาสตร์' => '管弦与器乐表演系',
            'สำนักงานเลขานุการ' => '学院综合行政办公室',
            'งานวิชาการ' => '教务与学术事务部',
            'งานการเงิน' => '财务与会计部',
            'งานพัสดุ' => '资产采购管理部',
            'งานบุคคล' => '人事管理部',
            'งานเทคโนโลยีสารสนเทศ' => '信息网络技术部',
            'งานโสตทัศนศึกษา' => '电教与音视频技术部',
            'งานพัฒนานิสิต' => '学生事务与发展部',
            'งานนโยบายและแผน' => '发展规划部',
            'งานบัณฑิต' => '研究生教学部'
        ];

        $stmtCheck = $pdo->prepare("SELECT content_id FROM pr_kiosk_contents WHERE category_key = 'staff' AND qr_link LIKE :qr_pattern LIMIT 1");
        $stmtUpdate = $pdo->prepare("
            UPDATE pr_kiosk_contents SET
                title_th = :t_th,
                title_en = :t_en,
                title_cn = :t_cn,
                detail_th = :d_th,
                detail_en = :d_en,
                detail_cn = :d_cn,
                thumbnail_image = :thumb,
                qr_link = :qr,
                location = :loc,
                sort_order = :sort,
                is_active = 1
            WHERE content_id = :id
        ");
        $stmtInsert = $pdo->prepare("
            INSERT INTO pr_kiosk_contents
            (category_key, title_th, title_en, title_cn, detail_th, detail_en, detail_cn, thumbnail_image, qr_link, location, sort_order, is_active)
            VALUES
            ('staff', :t_th, :t_en, :t_cn, :d_th, :d_en, :d_cn, :thumb, :qr, :loc, :sort, 1)
        ");

        $cntImport = 0;
        $cntUpdate = 0;

        foreach ($usersList as $u) {
            $uid = $u['user_id'];
            $role_names = $u['role_names'] ?? '';
            $dept_id = (int)$u['dept_id'];
            $dept_th = $u['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์';
            $dept_en = $dept_map_en[$dept_th] ?? 'College of Music, Mahasarakham University';
            $dept_cn = $dept_map_cn[$dept_th] ?? '玛哈沙拉坎大学音乐学院';

            $is_academic = (strpos($role_names, 'บุคลากรสายวิชาการ') !== false || in_array($dept_id, [12, 13]));
            $staff_type_th = $is_academic ? 'บุคลากรสายวิชาการ' : 'บุคลากรสายสนับสนุน';
            $staff_type_en = $is_academic ? 'Academic Faculty Member' : 'Support Personnel';
            $staff_type_cn = $is_academic ? '教学科研人员（学术岗）' : '行政支持人员（管理岗）';

            $prefix_th = trim($u['prefix_name'] ?? '');
            $name_th = trim($u['first_name'] . ' ' . $u['last_name']);
            $title_th = trim($prefix_th . ' ' . $name_th);

            $prefix_en = trim($u['prefix_name_en'] ?? '');
            $name_en = trim(($u['first_name_en'] ?? '') . ' ' . ($u['last_name_en'] ?? ''));
            $title_en = !empty($name_en) ? trim($prefix_en . ' ' . $name_en) : $title_th;
            $title_cn = $title_en ?: $title_th;

            // Executive Role Detection
            $exec_pos_th = '';
            $exec_pos_en = '';
            if (strpos($role_names, 'คณบดี') !== false && strpos($role_names, 'รองคณบดี') === false && strpos($role_names, 'ผู้ช่วยคณบดี') === false) {
                $exec_pos_th = 'คณบดี';
                $exec_pos_en = 'Dean';
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายบริหาร') !== false) {
                $exec_pos_th = 'รองคณบดีฝ่ายบริหาร';
                $exec_pos_en = 'Associate Dean for Administration';
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายวิชาการ') !== false) {
                $exec_pos_th = 'รองคณบดีฝ่ายวิชาการ';
                $exec_pos_en = 'Associate Dean for Academic Affairs';
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายพัฒนานิสิต') !== false) {
                $exec_pos_th = 'รองคณบดีฝ่ายพัฒนานิสิต';
                $exec_pos_en = 'Associate Dean for Student Development';
            } elseif (strpos($role_names, 'ผู้ช่วยคณบดี') !== false) {
                $exec_pos_th = 'ผู้ช่วยคณบดี';
                $exec_pos_en = 'Assistant Dean';
            } elseif (strpos($role_names, 'หัวหน้าภาควิชา') !== false) {
                $exec_pos_th = 'หัวหน้าภาควิชา' . ($dept_id == 12 ? 'ดนตรีศึกษา' : 'ดุริยางคศาสตร์');
                $exec_pos_en = 'Department Head';
            } elseif (strpos($role_names, 'หัวหน้าสำนักงาน') !== false) {
                $exec_pos_th = 'หัวหน้าสำนักงานเลขานุการ';
                $exec_pos_en = 'Head of Secretariat Office';
            }

            $pos_th = $exec_pos_th ?: (!empty($u['position']) ? $u['position'] : ($is_academic ? 'อาจารย์' : 'เจ้าหน้าที่'));
            $pos_en = $exec_pos_en ?: (!empty($u['position_en']) ? $u['position_en'] : ($is_academic ? 'Lecturer / Faculty' : 'Officer'));

            $detail_th = "ประเภท: " . $staff_type_th . "\n";
            $detail_th .= "ตำแหน่ง: " . $pos_th . "\n";
            $detail_th .= "สังกัด: " . $dept_th;
            if (!empty($u['email'])) $detail_th .= "\nอีเมล: " . $u['email'];
            if (!empty($u['phone'])) $detail_th .= "\nโทรศัพท์: " . $u['phone'];
            $detail_th .= "\n\n👉 แตะหรือสแกน QR Code เพื่อเปิดดูประวัติและผลงาน (CV) ฉบับเต็ม";

            $detail_en = "Type: " . $staff_type_en . "\n";
            $detail_en .= "Position: " . $pos_en . "\n";
            $detail_en .= "Department: " . $dept_en;
            if (!empty($u['email'])) $detail_en .= "\nEmail: " . $u['email'];
            if (!empty($u['phone'])) $detail_en .= "\nTel: " . $u['phone'];
            $detail_en .= "\n\n👉 Tap or scan QR Code to view complete Curriculum Vitae (CV)";

            $detail_cn = "类别: " . $staff_type_cn . "\n";
            $detail_cn .= "职务: " . $pos_en . "\n";
            $detail_cn .= "部门: " . $dept_cn;
            if (!empty($u['email'])) $detail_cn .= "\n邮箱: " . $u['email'];
            if (!empty($u['phone'])) $detail_cn .= "\n电话: " . $u['phone'];
            $detail_cn .= "\n\n👉 轻触或扫描二维码查阅个人完整简历与学术成果 (CV)";

            $thumb = 'assets/img/default-avatar.svg';
            if (!empty($u['profile_picture'])) {
                $avatarPath = 'uploads/avatars/' . $u['profile_picture'];
                if (file_exists(__DIR__ . '/../../' . $avatarPath)) {
                    $thumb = $avatarPath;
                }
            }

            $qr_link = $baseUrl . '/modules/cv/public_cv.php?u=' . urlencode($uid);

            $sort = 100;
            if (strpos($role_names, 'คณบดี') !== false && strpos($role_names, 'รองคณบดี') === false && strpos($role_names, 'ผู้ช่วยคณบดี') === false) {
                $sort = 1;
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายวิชาการ') !== false) {
                $sort = 2;
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายบริหาร') !== false) {
                $sort = 3;
            } elseif (strpos($role_names, 'รองคณบดีฝ่ายพัฒนานิสิต') !== false) {
                $sort = 4;
            } elseif (strpos($role_names, 'ผู้ช่วยคณบดี') !== false) {
                $sort = 5;
            } elseif (strpos($role_names, 'หัวหน้าภาควิชาดนตรีศึกษา') !== false) {
                $sort = 8;
            } elseif (strpos($role_names, 'หัวหน้าภาควิชาดุริยางคศาสตร์') !== false) {
                $sort = 9;
            } elseif ($is_academic) {
                $sort = 20;
            } elseif (strpos($role_names, 'หัวหน้าสำนักงาน') !== false) {
                $sort = 50;
            } else {
                $sort = 60;
            }

            $stmtCheck->execute([':qr_pattern' => '%public_cv.php?u=' . $uid]);
            $existingId = $stmtCheck->fetchColumn();

            $pData = [
                ':t_th' => $title_th,
                ':t_en' => $title_en,
                ':t_cn' => $title_cn,
                ':d_th' => $detail_th,
                ':d_en' => $detail_en,
                ':d_cn' => $detail_cn,
                ':thumb' => $thumb,
                ':qr' => $qr_link,
                ':loc' => $dept_th,
                ':sort' => $sort
            ];

            if ($existingId) {
                $pData[':id'] = $existingId;
                $stmtUpdate->execute($pData);
                $cntUpdate++;
            } else {
                $stmtInsert->execute($pData);
                $cntImport++;
            }
        }

        $pdo->exec("DELETE FROM pr_kiosk_contents WHERE category_key = 'staff' AND title_th = 'ทำเนียบคณาจารย์และผู้เชี่ยวชาญทางดนตรี'");
        $msg = "ซิงค์ดึงข้อมูลบุคลากรเข้าสู่ระบบจอที่ 2 สำเร็จ! (เพิ่มใหม่: {$cntImport} ท่าน, ปรับปรุง: {$cntUpdate} ท่าน)";
    }
}

$type_filter = $_GET['type'] ?? 'all';

// Count staff breakdown
$staff_counts = ['total' => 0, 'academic' => 0, 'support' => 0];
if ($cat_filter === 'staff' || $cat_filter === 'all') {
    $stmtSt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN detail_th LIKE '%บุคลากรสายวิชาการ%' THEN 1 ELSE 0 END) as academic,
            SUM(CASE WHEN detail_th LIKE '%บุคลากรสายสนับสนุน%' THEN 1 ELSE 0 END) as support
        FROM pr_kiosk_contents 
        WHERE category_key = 'staff'
    ");
    $staff_counts = $stmtSt->fetch(PDO::FETCH_ASSOC) ?: $staff_counts;
}

// Fetch Kiosk Contents
$sql = "SELECT * FROM pr_kiosk_contents WHERE 1=1 ";
$params = [];
if ($cat_filter !== 'all') {
    $sql .= " AND category_key = :cat ";
    $params[':cat'] = $cat_filter;
}
if ($cat_filter === 'staff') {
    if ($type_filter === 'executives') {
        $sql .= " AND (sort_order <= 8 OR detail_th LIKE '%คณบดี%' OR detail_th LIKE '%หัวหน้าสำนักงาน%' OR detail_th LIKE '%หัวหน้าภาควิชา%') ";
    } elseif ($type_filter === 'secretariat') {
        $sql .= " AND (location LIKE '%สำนักงานเลขานุการ%' OR detail_th LIKE '%สำนักงานเลขานุการ%') ";
    } elseif ($type_filter === 'music_perf') {
        $sql .= " AND (location LIKE '%ดุริยางคศาสตร์%' OR location LIKE '%ดุริยางคศิลป์%' OR detail_th LIKE '%ดุริยางคศาสตร์%' OR detail_th LIKE '%ดุริยางคศิลป์%') ";
    } elseif ($type_filter === 'music_edu') {
        $sql .= " AND (location LIKE '%ดนตรีศึกษา%' OR detail_th LIKE '%ดนตรีศึกษา%') ";
    } elseif ($type_filter === 'academic') {
        $sql .= " AND detail_th LIKE :type_kw ";
        $params[':type_kw'] = '%บุคลากรสายวิชาการ%';
    } elseif ($type_filter === 'support') {
        $sql .= " AND detail_th LIKE :type_kw ";
        $params[':type_kw'] = '%บุคลากรสายสนับสนุน%';
    }
}
$sql .= " ORDER BY sort_order ASC, content_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Header Section (Modern Clean White) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้าหลัก PR
                </a>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-layer-group text-purple me-2"></i>จัดการเนื้อหาจอ Interactive Kiosk (Screen 2)
            </h3>
            <p class="text-muted small mb-0 ps-1">
                จัดการข้อมูล 8 หมวดหมู่ พร้อมระบบ 3 ภาษา (ไทย, อังกฤษ, จีน) และ QR Code สำหรับสแกนอ่านต่อบนมือถือ
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-purple rounded-pill px-4 shadow-sm" onclick="openAddModal()">
                <i class="fa-solid fa-circle-plus me-1"></i> เพิ่มเนื้อหาใหม่ (3 ภาษา)
            </button>
            <a href="public_screen2.php" target="_blank" class="btn btn-outline-purple rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดดูจอที่ 2
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert badge-emerald-soft alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-check me-2 fs-5 text-success"></i> <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert badge-rose-soft alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fa-solid fa-circle-exclamation me-2 fs-5 text-danger"></i> <?= htmlspecialchars($err) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Category Filters (Modern Pills) -->
    <div class="card card-modern mb-4">
        <div class="card-body p-2 overflow-auto">
            <div class="d-flex gap-2">
                <a href="?cat=all" class="btn btn-sm rounded-pill px-3 <?= $cat_filter === 'all' ? 'btn-purple' : 'btn-light border text-secondary' ?>">
                    ทั้งหมด
                </a>
                <?php foreach ($cat_names as $k => $cname): ?>
                    <a href="?cat=<?= $k ?>" class="btn btn-sm rounded-pill px-3 text-nowrap <?= $cat_filter === $k ? 'btn-purple' : 'btn-light border text-secondary' ?>">
                        <?= htmlspecialchars($cname) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($cat_filter === 'staff'): ?>
        <!-- Staff Management Banner & Sync Control -->
        <div class="card card-modern border-purple-subtle mb-4 shadow-sm" style="background: linear-gradient(135deg, #fdfbff 0%, #f5f0ff 100%);">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge badge-purple-soft rounded-pill px-3 py-1"><i class="fa-solid fa-users me-1"></i> ทำเนียบข้อมูลบุคลากร</span>
                            <span class="badge bg-white border text-dark rounded-pill px-3 py-1 fw-bold">ทั้งหมด <?= (int)$staff_counts['total'] ?> ท่าน</span>
                            <span class="badge badge-emerald-soft rounded-pill px-3 py-1"><i class="fa-solid fa-graduation-cap me-1"></i> สายวิชาการ: <?= (int)$staff_counts['academic'] ?></span>
                            <span class="badge badge-amber-soft rounded-pill px-3 py-1"><i class="fa-solid fa-briefcase me-1"></i> สายสนับสนุน: <?= (int)$staff_counts['support'] ?></span>
                        </div>
                        <h5 class="fw-bold text-dark mb-1">รายชื่อบุคลากรวิทยาลัยดุริยางคศิลป์ (พร้อมเชื่อมโยงหน้าประวัติ CV อัตโนมัติ)</h5>
                        <p class="text-muted small mb-0">
                            ข้อมูลเชื่อมโยงจากระบบฐานข้อมูลผู้ใช้หลัก (Users) และผูกลิงก์ไปยัง Public CV ของแต่ละท่าน พร้อมสร้าง QR Code ให้ผู้ใช้นำมือถือมาสแกนดูประวัติได้ทันที
                        </p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <form method="POST" onsubmit="return confirm('ยืนยันดึงและอัปเดตข้อมูลบุคลากรสายวิชาการและสายสนับสนุนทั้งหมดเข้าสู่จอ Kiosk หรือไม่?')">
                            <input type="hidden" name="action" value="sync_staff">
                            <button type="submit" class="btn btn-purple rounded-pill px-4 shadow-sm">
                                <i class="fa-solid fa-arrows-rotate me-2"></i> ซิงค์ดึงข้อมูลบุคลากรทั้งหมด
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Sub-filter pills for Executive, Departments, and Staff Types -->
                <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                    <span class="text-muted small align-self-center me-1">ส่วนงาน/กลุ่ม:</span>
                    <a href="?cat=staff&type=all" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'all' ? 'btn-purple' : 'btn-light border text-secondary' ?>">ทั้งหมด (<?= (int)$staff_counts['total'] ?>)</a>
                    <a href="?cat=staff&type=executives" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'executives' ? 'btn-purple' : 'btn-light border text-secondary' ?>"><i class="fa-solid fa-star text-warning me-1"></i> ผู้บริหาร</a>
                    <a href="?cat=staff&type=secretariat" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'secretariat' ? 'btn-purple' : 'btn-light border text-secondary' ?>"><i class="fa-solid fa-building me-1"></i> สำนักงานเลขานุการ</a>
                    <a href="?cat=staff&type=music_perf" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'music_perf' ? 'btn-purple' : 'btn-light border text-secondary' ?>"><i class="fa-solid fa-music me-1"></i> ภาควิชาการดุริยางคศิลป์</a>
                    <a href="?cat=staff&type=music_edu" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'music_edu' ? 'btn-purple' : 'btn-light border text-secondary' ?>"><i class="fa-solid fa-graduation-cap me-1"></i> ภาควิชาดนตรีศึกษา</a>
                    <span class="text-muted small align-self-center mx-1">|</span>
                    <a href="?cat=staff&type=academic" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'academic' ? 'btn-purple' : 'btn-light border text-secondary' ?>">สายวิชาการ (<?= (int)$staff_counts['academic'] ?>)</a>
                    <a href="?cat=staff&type=support" class="btn btn-xs rounded-pill px-3 <?= $type_filter === 'support' ? 'btn-purple' : 'btn-light border text-secondary' ?>">สายสนับสนุน (<?= (int)$staff_counts['support'] ?>)</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Content Table (Clean White) -->
    <div class="card card-modern overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr class="text-muted small">
                            <th class="ps-4" style="width: 80px;">รูปภาพ</th>
                            <th style="width: 170px;">หมวดหมู่</th>
                            <th>หัวข้อ (TH • EN • CN)</th>
                            <th style="width: 140px;">QR Link</th>
                            <th style="width: 100px;" class="text-center">ยอดแตะดู</th>
                            <th style="width: 110px;" class="text-center">สถานะ</th>
                            <th style="width: 130px;" class="text-end pe-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:60px; height:60px;">
                                        <i class="fa-solid fa-inbox text-purple fa-xl"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark">ไม่พบข้อมูลในหมวดนี้</h6>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): 
                                $cat_label = $cat_names[$item['category_key']] ?? $item['category_key'];
                                $thumb_img = $item['thumbnail_image'];
                                if (!empty($thumb_img)) {
                                    if (!preg_match('/^(http|https):\/\//i', $thumb_img) && strpos($thumb_img, '../../') !== 0 && strpos($thumb_img, '/') !== 0) {
                                        $thumb_display = '../../' . ltrim($thumb_img, '/');
                                    } else {
                                        $thumb_display = $thumb_img;
                                    }
                                } else {
                                    $thumb_display = 'assets/img/default-avatar.svg';
                                }
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <img src="<?= htmlspecialchars($thumb_display) ?>" alt="Thumb" class="rounded shadow-xs border" style="width: 60px; height: 50px; object-fit: cover;" onerror="this.onerror=null; this.src='assets/img/default-avatar.svg';">
                                    </td>
                                    <td>
                                        <span class="badge badge-purple-soft rounded-pill px-3 py-1">
                                            <?= htmlspecialchars($cat_label) ?>
                                        </span>
                                        <?php if ($item['location']): ?>
                                            <div class="extra-small text-muted mt-1"><i class="fa-solid fa-location-dot me-1 text-purple"></i> <?= htmlspecialchars($item['location']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($item['event_date']): ?>
                                            <div class="extra-small text-muted mt-1"><i class="fa-regular fa-calendar me-1"></i> <?= date('d/m/Y', strtotime($item['event_date'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($item['title_th']) ?></div>
                                        <div class="extra-small text-muted">🇬🇧 <?= htmlspecialchars($item['title_en'] ?: '-') ?></div>
                                        <div class="extra-small text-muted">🇨🇳 <?= htmlspecialchars($item['title_cn'] ?: '-') ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['qr_link'])): ?>
                                            <?php if (strpos($item['qr_link'], 'public_cv.php') !== false): ?>
                                                <div class="d-flex flex-column gap-1">
                                                    <a href="<?= htmlspecialchars($item['qr_link']) ?>" target="_blank" class="btn btn-sm btn-purple rounded-pill px-2 py-1 extra-small fw-bold text-nowrap shadow-xs" title="เปิดดูหน้า CV ของบุคลากรท่านนี้">
                                                        <i class="fa-solid fa-file-invoice me-1"></i> ดูหน้า CV <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size: 0.65rem;"></i>
                                                    </a>
                                                    <span class="text-muted extra-small"><i class="fa-solid fa-qrcode text-purple me-1"></i> มี QR Code</span>
                                                </div>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($item['qr_link']) ?>" target="_blank" class="btn btn-sm btn-outline-purple rounded-pill px-2 py-0 extra-small">
                                                    <i class="fa-solid fa-qrcode me-1"></i> มี QR Code
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted extra-small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border">
                                            <i class="fa-regular fa-eye me-1 text-purple"></i> <?= $item['view_count'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="content_id" value="<?= $item['content_id'] ?>">
                                            <button type="submit" class="badge rounded-pill border-0 <?= $item['is_active'] ? 'badge-emerald-soft' : 'bg-light text-muted' ?> px-3 py-2 cursor-pointer">
                                                <?= $item['is_active'] ? 'เปิดใช้งาน' : 'ปิดชั่วคราว' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-outline-purple rounded-circle me-1" onclick='openEditModal(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="แก้ไขข้อมูล">
                                            <i class="fa-solid fa-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันลบเนื้อหานี้หรือไม่?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="content_id" value="<?= $item['content_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="ลบ">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
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

</main>

<!-- Modal เพิ่ม/แก้ไข ข้อมูล Kiosk 3 ภาษา (Modern Purple-White) -->
<div class="modal fade" id="kioskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="content_id" id="modal_content_id" value="0">
            <input type="hidden" name="existing_thumb" id="modal_existing_thumb" value="">

            <div class="modal-header border-0 bg-purple-subtle py-3">
                <h5 class="modal-title fw-bold text-dark" id="kioskModalTitle">
                    <i class="fa-solid fa-pen-to-square text-purple me-2"></i>เพิ่มข้อมูลจอ Interactive Kiosk
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                <!-- หมวดหมู่ & ลำดับ -->
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-dark">หมวดหมู่ <span class="text-danger">*</span></label>
                        <select name="category_key" id="modal_cat" class="form-select rounded-3" required>
                            <?php foreach ($cat_names as $k => $cname): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark">ลำดับการแสดงผล</label>
                        <input type="number" name="sort_order" id="modal_sort" class="form-control rounded-3" value="0" min="0">
                    </div>
                </div>

                <!-- 3 ภาษา Tabs (TH / EN / CN) -->
                <div class="card border rounded-4 p-3 mb-3 bg-light">
                    <ul class="nav nav-pills nav-pills-purple mb-3 gap-2" id="langTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active fw-bold" id="th-tab" data-bs-toggle="tab" data-bs-target="#tab-th" type="button">
                                🇹🇭 ภาษาไทย (TH) *
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link fw-bold" id="en-tab" data-bs-toggle="tab" data-bs-target="#tab-en" type="button">
                                🇬🇧 English (EN)
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link fw-bold" id="cn-tab" data-bs-toggle="tab" data-bs-target="#tab-cn" type="button">
                                🇨🇳 中文 (CN)
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- TH -->
                        <div class="tab-pane fade show active" id="tab-th">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">หัวข้อ (ภาษาไทย) <span class="text-danger">*</span></label>
                                <input type="text" name="title_th" id="modal_title_th" class="form-control rounded-3 bg-white" required placeholder="เช่น เปิดรับสมัครนิสิตใหม่ 2570">
                            </div>
                            <div>
                                <label class="form-label fw-bold small">รายละเอียด (ภาษาไทย)</label>
                                <textarea name="detail_th" id="modal_detail_th" class="form-control rounded-3 bg-white" rows="4" placeholder="รายละเอียดเนื้อหา..."></textarea>
                            </div>
                        </div>

                        <!-- EN -->
                        <div class="tab-pane fade" id="tab-en">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Title (English)</label>
                                <input type="text" name="title_en" id="modal_title_en" class="form-control rounded-3 bg-white" placeholder="e.g. Admission 2027">
                            </div>
                            <div>
                                <label class="form-label fw-bold small">Description (English)</label>
                                <textarea name="detail_en" id="modal_detail_en" class="form-control rounded-3 bg-white" rows="4" placeholder="English description..."></textarea>
                            </div>
                        </div>

                        <!-- CN -->
                        <div class="tab-pane fade" id="tab-cn">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">标题 (中文)</label>
                                <input type="text" name="title_cn" id="modal_title_cn" class="form-control rounded-3 bg-white" placeholder="例如：2027年招生简章">
                            </div>
                            <div>
                                <label class="form-label fw-bold small">详细内容 (中文)</label>
                                <textarea name="detail_cn" id="modal_detail_cn" class="form-control rounded-3 bg-white" rows="4" placeholder="中文介绍内容..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- รูปภาพประกอบ & QR Link -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark">อัปโหลดรูปภาพปก</label>
                        <input type="file" name="thumb_file" class="form-control rounded-3 mb-1" accept="image/*">
                        <input type="text" name="thumb_url_input" id="modal_thumb_url" class="form-control form-control-sm rounded-3" placeholder="หรือใส่ URL รูปภาพ...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark">ลิงก์สำหรับสร้าง QR Code</label>
                        <input type="url" name="qr_link" id="modal_qr_link" class="form-control rounded-3" placeholder="https://music.msu.ac.th/...">
                        <div class="extra-small text-muted mt-1">นิสิตสามารถใช้มือถือสแกนบนจอเพื่ออ่านต่อได้ทันที</div>
                    </div>
                </div>

                <!-- วันที่จัดงาน & สถานที่ -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark">วันที่จัดงาน (ถ้ามี)</label>
                        <input type="date" name="event_date" id="modal_event_date" class="form-control rounded-3">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark">สถานที่ / พิกัดห้อง (ถ้ามี)</label>
                        <input type="text" name="location" id="modal_location" class="form-control rounded-3" placeholder="เช่น หอแสดงดนตรี ชั้น 1">
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4 text-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-purple rounded-pill px-5">
                    <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกข้อมูล
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('kioskModalTitle').innerHTML = '<i class="fa-solid fa-circle-plus text-purple me-2"></i>เพิ่มข้อมูลจอ Interactive Kiosk';
        document.getElementById('modal_content_id').value = '0';
        document.getElementById('modal_cat').value = 'news';
        document.getElementById('modal_title_th').value = '';
        document.getElementById('modal_title_en').value = '';
        document.getElementById('modal_title_cn').value = '';
        document.getElementById('modal_detail_th').value = '';
        document.getElementById('modal_detail_en').value = '';
        document.getElementById('modal_detail_cn').value = '';
        document.getElementById('modal_existing_thumb').value = '';
        document.getElementById('modal_thumb_url').value = '';
        document.getElementById('modal_qr_link').value = '';
        document.getElementById('modal_event_date').value = '';
        document.getElementById('modal_location').value = '';
        document.getElementById('modal_sort').value = '0';

        new bootstrap.Modal(document.getElementById('kioskModal')).show();
    }

    function openEditModal(item) {
        document.getElementById('kioskModalTitle').innerHTML = '<i class="fa-solid fa-pencil text-purple me-2"></i>แก้ไขข้อมูล #' + item.content_id;
        document.getElementById('modal_content_id').value = item.content_id;
        document.getElementById('modal_cat').value = item.category_key;
        document.getElementById('modal_title_th').value = item.title_th || '';
        document.getElementById('modal_title_en').value = item.title_en || '';
        document.getElementById('modal_title_cn').value = item.title_cn || '';
        document.getElementById('modal_detail_th').value = item.detail_th || '';
        document.getElementById('modal_detail_en').value = item.detail_en || '';
        document.getElementById('modal_detail_cn').value = item.detail_cn || '';
        document.getElementById('modal_existing_thumb').value = item.thumbnail_image || '';
        document.getElementById('modal_thumb_url').value = item.thumbnail_image || '';
        document.getElementById('modal_qr_link').value = item.qr_link || '';
        document.getElementById('modal_event_date').value = item.event_date || '';
        document.getElementById('modal_location').value = item.location || '';
        document.getElementById('modal_sort').value = item.sort_order || '0';

        new bootstrap.Modal(document.getElementById('kioskModal')).show();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
