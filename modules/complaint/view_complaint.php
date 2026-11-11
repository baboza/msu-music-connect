<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_roles_array = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles_array) ? 1 : 0;
$is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $user_roles_array)) > 0) ? 1 : 0;

if (!$is_admin && !$is_dean) {
    echo "<script>alert('ปฏิเสธการเข้าถึง'); window.location.href='../../requests_list.php';</script>";
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: ../../requests_list.php");
    exit();
}

$complaint_id = $_GET['id'];
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $new_status = 'pending';
    if ($action == 'in_progress') $new_status = 'in_progress';
    if ($action == 'resolved') $new_status = 'resolved';
    if ($action == 'reject') $new_status = 'rejected';
    
    try {
        $stmt_update = $pdo->prepare("UPDATE complaints SET status = ? WHERE complaint_id = ?");
        $stmt_update->execute([$new_status, $complaint_id]);
        $msg = "อัปเดตสถานะการร้องเรียนเรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (Exception $e) {
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "danger";
    }
}

$stmt = $pdo->prepare("
    SELECT c.*, 
           s.prefix_name as std_prefix, s.first_name as std_fname, s.last_name as std_lname, s.major, s.year_level, s.email, s.phone_number
    FROM complaints c 
    LEFT JOIN students s ON c.student_id = s.student_id
    WHERE c.complaint_id = ?
");
$stmt->execute([$complaint_id]);
$complaint = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$complaint) {
    echo "<script>alert('ไม่พบข้อมูลเรื่องร้องเรียน'); window.location.href='../../requests_list.php';</script>";
    exit();
}

function getStatusBadge($status) {
    $data = [
        'pending' => ['bg-warning text-dark', 'รอพิจารณา'],
        'in_progress' => ['bg-info text-dark', 'กำลังดำเนินการ'],
        'resolved' => ['bg-success', 'ยุติเรื่อง/แก้ไขแล้ว'],
        'rejected' => ['bg-danger', 'ปฏิเสธ']
    ];
    $res = $data[$status] ?? ['bg-secondary', 'ไม่ทราบสถานะ'];
    return "<span class='badge {$res[0]} px-3 py-2 fs-6 rounded-pill'>{$res[1]}</span>";
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1"><i class="fa-solid fa-bullhorn text-secondary me-2"></i>รายละเอียดเรื่องร้องเรียน</h1>
            <p class="text-muted small mb-0">ตรวจสอบและพิจารณาข้อร้องเรียนหรือข้อเสนอแนะ</p>
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
                <?php if ($complaint['status'] === 'resolved'): ?>
                    <div class="status-stamp stamp-approved">
                        <i class="fa-solid fa-stamp me-1"></i> ยุติเรื่องแล้ว
                    </div>
                <?php elseif ($complaint['status'] === 'in_progress'): ?>
                    <div class="status-stamp stamp-inprogress">
                        <i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังดำเนินการ
                    </div>
                <?php elseif ($complaint['status'] === 'rejected'): ?>
                    <div class="status-stamp stamp-rejected">
                        <i class="fa-solid fa-ban me-1"></i> ปฏิเสธ
                    </div>
                <?php else: ?>
                    <div class="status-stamp stamp-pending">
                        <i class="fa-solid fa-clock me-1"></i> รอพิจารณา
                    </div>
                <?php endif; ?>

                <div class="card-header bg-white p-4 border-bottom-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-lines text-secondary me-2"></i>ข้อมูลเรื่องร้องเรียน</h5>
                    <div>
                        <?php echo getStatusBadge($complaint['status']); ?>
                    </div>
                </div>
                <div class="card-body p-4 pt-0">
                    <div class="mb-3">
                        <span class="text-muted small">หัวข้อเรื่องร้องเรียน</span>
                        <div class="fw-bold text-dark fs-4"><?php echo htmlspecialchars($complaint['subject']); ?></div>
                    </div>
                    <div class="mb-4 text-muted small">
                        <i class="fa-regular fa-clock me-1"></i> วันที่แจ้งเรื่อง: <?php echo date('d/m/Y H:i', strtotime($complaint['created_at'])); ?>
                    </div>

                    <hr class="my-4 text-muted opacity-25">

                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-align-left me-2 text-secondary"></i>รายละเอียดข้อเท็จจริง</h6>
                    <div class="bg-light p-4 rounded-4" style="border-left: 4px solid #6c757d;">
                        <?php echo nl2br(htmlspecialchars($complaint['details'])); ?>
                    </div>

                    <?php if(!empty($complaint['attachment_url'])): ?>
                    <div class="mt-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-paperclip me-2 text-secondary"></i>เอกสารแนบ / หลักฐาน</h6>
                        <a href="<?php echo htmlspecialchars($complaint['attachment_url']); ?>" target="_blank" class="btn btn-outline-primary rounded-pill">
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
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-graduate text-secondary me-2"></i>ข้อมูลผู้แจ้ง (นิสิต)</h5>
                </div>
                <div class="card-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <div class="avatar-lg mx-auto bg-secondary bg-opacity-10 text-secondary d-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 80px; height: 80px; font-size: 2rem; font-weight: bold;">
                            <?php echo mb_substr($complaint['std_fname'] ?? 'N', 0, 1, 'UTF-8'); ?>
                        </div>
                        <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars(($complaint['std_prefix'] ?? '').($complaint['std_fname'] ?? '').' '.($complaint['std_lname'] ?? '')); ?></h5>
                        <p class="text-muted mb-0">รหัส: <?php echo htmlspecialchars($complaint['student_id']); ?></p>
                    </div>

                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">สาขาวิชา</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($complaint['major'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">ชั้นปี</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($complaint['year_level'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">อีเมล</span>
                            <span><?php echo htmlspecialchars($complaint['email'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 border-bottom-0">
                            <span class="text-muted">เบอร์โทรศัพท์</span>
                            <span><?php echo htmlspecialchars($complaint['phone_number'] ?? '-'); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <?php if(in_array($complaint['status'], ['pending', 'in_progress'])): ?>
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-body p-4 text-center">
                    <h6 class="fw-bold text-dark mb-3">เปลี่ยนสถานะการดำเนินการ</h6>
                    <form action="" method="POST" class="d-flex flex-column gap-2">
                        <?php if($complaint['status'] == 'pending'): ?>
                        <button type="submit" name="action" value="in_progress" class="btn btn-info w-100 rounded-pill fw-bold shadow-sm text-dark" onclick="return confirm('ปรับสถานะเป็น กำลังดำเนินการ?');">
                            <i class="fa-solid fa-spinner me-1"></i> กำลังดำเนินการ
                        </button>
                        <?php endif; ?>
                        
                        <button type="submit" name="action" value="resolved" class="btn btn-success w-100 rounded-pill fw-bold shadow-sm" onclick="return confirm('ยืนยันว่ายุติเรื่องและแก้ไขแล้ว?');">
                            <i class="fa-solid fa-check me-1"></i> ยุติเรื่อง / แก้ไขแล้ว
                        </button>
                        
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger w-100 rounded-pill fw-bold shadow-sm mt-2" onclick="return confirm('ยืนยันที่จะปฏิเสธเรื่องร้องเรียนนี้?');">
                            <i class="fa-solid fa-xmark me-1"></i> ปฏิเสธการพิจารณา
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>
