<?php
/**
 * ไฟล์: modules/klof/student_details.php
 * แสดงรายละเอียดกิจกรรมของนิสิตแต่ละคน
 */

session_start();

require_once "../../config/db.php";
require_once "../../auth/session_check.php";

// ตรวจสอบสิทธิ์
if (!hasRole([
    'admin',
    'งานพัฒนานิสิต',
    'หัวหน้าสำนักงาน',
    'รองคณบดีฝ่ายพัฒนานิสิต',
    'คณบดี'
])) {
    $_SESSION['error'] = "คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้";
    header("Location: ../../index.php");
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: summary.php");
    exit;
}

$student_id = $_GET['id'];

// ดึงข้อมูลนิสิต
$stmt_stu = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt_stu->execute([$student_id]);
$student = $stmt_stu->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("ไม่พบข้อมูลนิสิต");
}

// ดึงข้อมูลกิจกรรม
$stmt_logs = $pdo->prepare("SELECT * FROM student_klof_logs WHERE student_id = ? ORDER BY created_at DESC");
$stmt_logs->execute([$student_id]);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

function is_image($url) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="pt-4 pb-2 mb-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-user-graduate me-2 text-primary"></i> 
                รายละเอียดกิจกรรม กยศ.
            </h1>
            <p class="text-muted mb-0">รหัสนิสิต: <?= htmlspecialchars($student['student_id']) ?> | ชื่อ-นามสกุล: <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
        </div>
        <div>
            <a href="summary.php" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>

    <div class="row g-4">
        <?php if (empty($logs)): ?>
            <div class="col-12 text-center py-5">
                <h5 class="text-muted">ไม่พบข้อมูลกิจกรรมสำหรับนิสิตรายนี้</h5>
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): 
                $status_color = 'warning';
                $status_text = 'รออนุมัติ';
                if ($log['status'] == 'approved') {
                    $status_color = 'success';
                    $status_text = 'อนุมัติแล้ว';
                } elseif ($log['status'] == 'rejected') {
                    $status_color = 'danger';
                    $status_text = 'ปฏิเสธรายการ';
                }
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-<?= $status_color ?>-subtle text-<?= $status_color ?> rounded-pill px-2 py-1">
                                <?= $status_text ?>
                            </span>
                            <small class="text-muted"><i class="fa-regular fa-calendar-days me-1"></i><?= date('d/m/Y', strtotime($log['activity_date'])) ?></small>
                        </div>
                        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($log['activity_name']) ?></h5>
                        <p class="text-primary fw-bold mb-0"><?= (float)$log['hours_count'] ?> ชั่วโมง</p>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($log['link_evidence'])): 
                            $img_url = $log['link_evidence'];
                            // ปรับแก้ path อัตโนมัติ หากข้อมูลในฐานข้อมูลเป็น uploads/... ให้ดึงจาก /e-service/
                            if (strpos($img_url, 'uploads/') === 0) {
                                $img_url = '/e-service/' . $img_url;
                            }
                        ?>
                            <div class="text-center bg-light rounded-3 p-2 mb-2" style="height: 200px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <?php if (is_image($img_url) || strpos($img_url, 'data:image') === 0): ?>
                                    <a href="<?= htmlspecialchars($img_url) ?>" target="_blank">
                                        <img src="<?= htmlspecialchars($img_url) ?>" class="img-fluid rounded shadow-sm" style="max-height: 100%; object-fit: contain;">
                                    </a>
                                <?php else: ?>
                                    <!-- Fallback case if not ending in typical image extension, try to show as image anyway but handle error -->
                                    <a href="<?= htmlspecialchars($img_url) ?>" target="_blank" style="display:block; width:100%; height:100%;">
                                        <img src="<?= htmlspecialchars($img_url) ?>" class="img-fluid rounded shadow-sm" style="max-height: 100%; object-fit: contain;" onerror="this.outerHTML='<div class=\'h-100 d-flex flex-column align-items-center justify-content-center\'><i class=\'fa-solid fa-file-lines fa-3x text-muted mb-2\'></i><span class=\'btn btn-sm btn-outline-primary rounded-pill\'>คลิกเพื่อเปิดเอกสาร</span></div>'">
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center bg-light rounded-3 p-2 mb-2 text-muted d-flex flex-column align-items-center justify-content-center" style="height: 200px;">
                                <i class="fa-solid fa-image fa-2x mb-2 opacity-50"></i>
                                <small>ไม่มีรูปภาพหลักฐาน</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['academic_term'])): ?>
                    <div class="card-footer bg-transparent border-top-0 pt-0 pb-3">
                        <small class="text-muted">เทอม: <?= htmlspecialchars($log['academic_term']) ?></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>
