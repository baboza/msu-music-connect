<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$is_admin = in_array('admin', $_SESSION['roles'] ?? []);

if (!isset($_GET['id'])) {
    header("Location: ../../requests_list.php");
    exit();
}

$appeal_id = $_GET['id'];
$msg = '';
$msg_type = '';

// อัปเดตสถานะเมื่อมีการ Submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $new_status = 'pending';
    if ($action == 'approve') $new_status = 'approved';
    elseif ($action == 'reject') $new_status = 'rejected';
    elseif ($action == 'in_progress') $new_status = 'in_progress';
    
    try {
        $stmt_update = $pdo->prepare("UPDATE grade_appeals SET status = ? WHERE appeal_id = ?");
        $stmt_update->execute([$new_status, $appeal_id]);
        $msg = "อัปเดตสถานะเรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (Exception $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงข้อมูล
$stmt = $pdo->prepare("
    SELECT ga.*, 
           s.prefix_name as std_prefix, s.first_name as std_fname, s.last_name as std_lname, s.major, s.year_level, s.email, s.phone_number
    FROM grade_appeals ga 
    LEFT JOIN students s ON ga.student_id = s.student_id
    WHERE ga.appeal_id = ?
");
$stmt->execute([$appeal_id]);
$appeal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appeal) {
    echo "<script>alert('ไม่พบข้อมูลการอุทธรณ์'); window.location.href='../../requests_list.php';</script>";
    exit();
}

// สิทธิ์การเข้าถึง (Admin หรือเป็นอาจารย์ผู้สอนของวิชานั้น)
if (!$is_admin && $appeal['instructor_id'] !== $user_id) {
    echo "<script>alert('ปฏิเสธการเข้าถึง'); window.location.href='../../requests_list.php';</script>";
    exit();
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-scale-balanced me-2"></i>รายละเอียดการอุทธรณ์คะแนน</h1>
            <p class="text-muted small mb-0">ตรวจสอบและพิจารณาคำร้องขออุทธรณ์คะแนนจากนิสิต</p>
        </div>
        <a href="../../requests_list.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าศูนย์อนุมัติรวม
        </a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0 rounded-3 mb-4 animate__animated animate__fadeIn">
            <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2"></i> <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4 position-relative overflow-hidden">
                <!-- Digital Status Stamp -->
                <?php if ($appeal['status'] === 'approved'): ?>
                    <div class="status-stamp stamp-approved">
                        <i class="fa-solid fa-stamp me-1"></i> อนุมัติแล้ว
                    </div>
                <?php elseif ($appeal['status'] === 'in_progress'): ?>
                    <div class="status-stamp stamp-inprogress">
                        <i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังดำเนินการ
                    </div>
                <?php elseif ($appeal['status'] === 'rejected'): ?>
                    <div class="status-stamp stamp-rejected">
                        <i class="fa-solid fa-ban me-1"></i> ปฏิเสธ
                    </div>
                <?php else: ?>
                    <div class="status-stamp stamp-pending">
                        <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
                    </div>
                <?php endif; ?>

                <div class="card-header bg-white p-4 border-bottom-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-lines text-purple me-2"></i>ข้อมูลคำร้อง</h5>
                    <span class="badge <?php 
                        if($appeal['status'] == 'pending') echo 'bg-warning text-dark';
                        elseif($appeal['status'] == 'in_progress') echo 'bg-info text-white';
                        elseif($appeal['status'] == 'approved') echo 'bg-success';
                        else echo 'bg-danger';
                    ?> px-3 py-2 rounded-pill fs-6">
                        สถานะ: <?php 
                        if($appeal['status'] == 'pending') echo 'รอพิจารณา';
                        elseif($appeal['status'] == 'in_progress') echo 'กำลังดำเนินการ';
                        elseif($appeal['status'] == 'approved') echo 'ดำเนินการเรียบร้อย';
                        else echo 'ปฏิเสธ';
                        ?>
                    </span>
                </div>
                <div class="card-body p-4 pt-0">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <th width="25%" class="text-muted">รหัสวิชา</th>
                                <td class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($appeal['course_code']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ชื่อรายวิชา</th>
                                <td class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($appeal['course_name']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ปีการศึกษา/เทอม</th>
                                <td><?php echo htmlspecialchars($appeal['academic_year'] . ' / เทอม ' . $appeal['semester']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">วันที่ยื่นเรื่อง</th>
                                <td><?php echo date('d/m/Y H:i', strtotime($appeal['created_at'])); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <hr class="my-4 text-muted opacity-25">

                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-comment-dots me-2 text-purple"></i>เหตุผล / สิ่งที่ต้องการอุทธรณ์</h6>
                    <div class="bg-light p-4 rounded-4" style="border-left: 4px solid #6f42c1;">
                        <?php echo nl2br(htmlspecialchars($appeal['appeal_reason'])); ?>
                    </div>

                    <?php if(!empty($appeal['attachment_url'])): ?>
                    <div class="mt-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-paperclip me-2 text-purple"></i>เอกสารอ้างอิง</h6>
                        <a href="<?php echo htmlspecialchars($appeal['attachment_url']); ?>" target="_blank" class="btn btn-outline-primary rounded-pill">
                            <i class="fa-brands fa-google-drive me-2"></i> เปิดดูไฟล์แนบ
                        </a>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white p-4 border-bottom-0">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-graduate text-purple me-2"></i>ข้อมูลนิสิต</h5>
                </div>
                <div class="card-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <div class="avatar-lg mx-auto bg-purple-subtle text-purple d-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 80px; height: 80px; font-size: 2rem; font-weight: bold;">
                            <?php echo mb_substr($appeal['std_fname'] ?? 'N', 0, 1, 'UTF-8'); ?>
                        </div>
                        <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars(($appeal['std_prefix'] ?? '').($appeal['std_fname'] ?? '').' '.($appeal['std_lname'] ?? '')); ?></h5>
                        <p class="text-muted mb-0">รหัส: <?php echo htmlspecialchars($appeal['student_id']); ?></p>
                    </div>

                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">สาขาวิชา</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($appeal['major'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">ชั้นปี</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($appeal['year_level'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">อีเมล</span>
                            <span><?php echo htmlspecialchars($appeal['email'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 border-bottom-0">
                            <span class="text-muted">เบอร์โทรศัพท์</span>
                            <span><?php echo htmlspecialchars($appeal['phone_number'] ?? '-'); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <?php if($appeal['status'] == 'pending' || $appeal['status'] == 'in_progress'): ?>
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 text-center">
                    <h6 class="fw-bold text-dark mb-3">การพิจารณา</h6>
                    <form action="" method="POST" class="d-flex flex-column flex-sm-row flex-wrap gap-2">
                        <?php if($appeal['status'] == 'pending'): ?>
                        <button type="submit" name="action" value="in_progress" class="btn btn-info text-white flex-grow-1 rounded-pill fw-bold shadow-sm" onclick="return confirm('ยืนยันเปลี่ยนสถานะเป็น กำลังดำเนินการ?');">
                            <i class="fa-solid fa-spinner me-1"></i> กำลังดำเนินการ
                        </button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="approve" class="btn btn-success flex-grow-1 rounded-pill fw-bold shadow-sm" onclick="return confirm('ยืนยันว่าดำเนินการเรื่องนี้เรียบร้อยแล้ว?');">
                            <i class="fa-solid fa-check me-1"></i> ดำเนินการแล้ว
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger flex-grow-1 rounded-pill fw-bold shadow-sm" onclick="return confirm('ยืนยันที่จะปฏิเสธคำร้องอุทธรณ์นี้?');">
                            <i class="fa-solid fa-xmark me-1"></i> ปฏิเสธ
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
</style>

<?php require_once '../../includes/footer.php'; ?>
