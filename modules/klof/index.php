<?php
/**
 * ไฟล์: modules/klof/index.php
 * ระบบอนุมัติกิจกรรมและจัดการชั่วโมง กยศ. (งานพัฒนานิสิต)
 */

// เปิด Error ชั่วคราวสำหรับการตรวจสอบ
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์ (ให้งานพัฒนานิสิต หรือ Admin เข้าถึงได้)
$allowed_roles = ['admin', 'งานพัฒนานิสิต'];
if (!hasRole($allowed_roles)) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

/* =========================
   PROCESS ACTION (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_term') {
        $new_term = trim($_POST['academic_term']);
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = 'current_klof_term'");
        $stmt->execute([$new_term]);
        $_SESSION['msg'] = "ตั้งค่าภาคการศึกษาปัจจุบันเป็น $new_term เรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
        header("Location: index.php");
        exit();
    }
    
    $log_id = $_POST['log_id'];
    
    if ($_POST['action'] == 'delete') {
        // ---- ฟังก์ชันลบข้อมูล ----
        $stmt = $pdo->prepare("DELETE FROM student_klof_logs WHERE log_id = ?");
        $stmt->execute([$log_id]);
        $_SESSION['msg'] = "ลบรายการกิจกรรมเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "danger";
    } else {
        // ---- ฟังก์ชันอนุมัติ / ปฏิเสธ ----
        $status = ($_POST['action'] == 'approve') ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE student_klof_logs SET status = ? WHERE log_id = ?");
        $stmt->execute([$status, $log_id]);
        $_SESSION['msg'] = "บันทึกสถานะการตรวจสอบเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
    }
    
    // หมุนหน้าจอกลับมาที่ตัวเอง
    header("Location: index.php?term=" . (isset($_GET['term']) ? urlencode($_GET['term']) : ''));
    exit();
}

/* =========================
   GET DATA LOGS (ดึงข้อมูลทั้งหมดเพื่อใช้กรองและค้นหา)
========================= */
// ดึงภาคการศึกษาปัจจุบัน
$stmt_term = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'current_klof_term'");
$current_term_row = $stmt_term->fetch(PDO::FETCH_ASSOC);
$current_term = $current_term_row ? $current_term_row['setting_value'] : '';

// ดึงรายการภาคการศึกษาที่มีในระบบ
$stmt_all_terms = $pdo->query("SELECT DISTINCT academic_term FROM student_klof_logs WHERE academic_term IS NOT NULL ORDER BY academic_term DESC");
$all_terms = $stmt_all_terms->fetchAll(PDO::FETCH_COLUMN);

$filter_term = isset($_GET['term']) ? $_GET['term'] : $current_term;

$sql = "
    SELECT l.*, s.first_name, s.last_name, s.student_id 
    FROM student_klof_logs l 
    JOIN students s ON l.student_id = s.student_id 
    WHERE 1=1
";
$params = [];
if (!empty($filter_term) && $filter_term !== 'all') {
    if ($filter_term === 'none') {
        $sql .= " AND l.academic_term IS NULL";
    } else {
        $sql .= " AND l.academic_term = ?";
        $params[] = $filter_term;
    }
}
$sql .= " ORDER BY FIELD(l.status, 'pending', 'approved', 'rejected'), l.created_at DESC";

$stmt_logs = $pdo->prepare($sql);
$stmt_logs->execute($params);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    
    <div class="pt-4 pb-2 mb-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">
                    <i class="fa-solid fa-check-double me-2 text-primary"></i> 
                    จัดการและอนุมัติกิจกรรม กยศ.
                </h1>
                <p class="text-muted mb-0">ตรวจสอบ ตรวจทาน ค้นหาข้อมูล และจัดการชั่วโมงกิจกรรมของนิสิตผู้กู้ยืมรายบุคคล</p>
            </div>
            <div class="mt-2 mt-md-0 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary rounded-pill px-3 btn-sm" data-bs-toggle="modal" data-bs-target="#termSettingsModal">
                    <i class="fa-solid fa-gear me-1"></i> ตั้งค่าภาคการศึกษา
                </button>
                <button id="reloadBtn" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">
                    <i class="fa-solid fa-rotate me-1"></i> รีเฟรช
                </button>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['msg'])): 
        $alert_type = isset($_SESSION['msg_type']) && $_SESSION['msg_type'] == 'danger' ? 'danger' : 'success';
    ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <i class="fa-solid <?= $alert_type == 'success' ? 'fa-circle-check' : 'fa-trash-can' ?> me-2"></i>
            <strong><?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-4">
            <div class="row g-3 align-items-center">
                <div class="col-lg-3 col-md-4">
                    <label class="form-label small text-muted fw-bold mb-1">เลือกภาคการศึกษา</label>
                    <select class="form-select rounded-pill bg-light" onchange="window.location.href='index.php?term='+this.value">
                        <option value="all" <?= $filter_term === 'all' ? 'selected' : '' ?>>แสดงทุกเทอมทั้งหมด</option>
                        <option value="none" <?= $filter_term === 'none' ? 'selected' : '' ?>>ไม่ระบุเทอม (ข้อมูลเก่า)</option>
                        <?php foreach($all_terms as $term): ?>
                            <option value="<?= htmlspecialchars($term) ?>" <?= $filter_term === $term ? 'selected' : '' ?>>
                                เทอม <?= htmlspecialchars($term) ?> <?= $current_term === $term ? '(ปัจจุบัน)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if(!in_array($current_term, $all_terms) && !empty($current_term)): ?>
                            <option value="<?= htmlspecialchars($current_term) ?>" <?= $filter_term === $current_term ? 'selected' : '' ?>>
                                เทอม <?= htmlspecialchars($current_term) ?> (ปัจจุบัน)
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-lg-4 col-md-5">
                    <label class="form-label small text-muted fw-bold mb-1">ค้นหาด่วน</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" id="customSearch" class="form-control bg-light border-start-0 rounded-end-pill" placeholder="พิมพ์ รหัสนิสิต, ชื่อ-นามสกุล, ชื่อกิจกรรม...">
                    </div>
                </div>
                <div class="col-lg-3 col-md-4">
                    <label class="form-label small text-muted fw-bold mb-1">กรองสเตตัส/สถานะ</label>
                    <select id="statusFilter" class="form-select rounded-pill bg-light">
                        <option value="">แสดงทุกสถานะ</option>
                        <option value="รออนุมัติ">⏳ รออนุมัติ (Pending)</option>
                        <option value="อนุมัติแล้ว">✅ อนุมัติแล้ว (Approved)</option>
                        <option value="ปฏิเสธรายการ">❌ ปฏิเสธรายการ (Rejected)</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 text-md-end align-self-end">
                    <span class="text-muted small">พบ: <strong class="text-primary"><?= count($logs) ?></strong> รายการ</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-body p-4 pt-3">
            <div class="table-responsive">
                <table id="approveTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th width="120">รหัสนิสิต</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>รายละเอียดกิจกรรม</th>
                            <th width="100">จำนวนชั่วโมง</th>
                            <th width="140">เอกสารหลักฐาน</th>
                            <th width="100" class="text-center">สถานะ</th>
                            <th width="150" class="text-center">เครื่องมือจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($log['student_id']); ?></td>
                            
                            <td><?= htmlspecialchars($log['first_name'].' '.$log['last_name']); ?></td>
                            
                            <td>
                                <div class="fw-bold text-wrap-custom"><?= htmlspecialchars($log['activity_name']); ?></div>
                                <div class="small text-muted mt-1">
                                    <i class="fa-regular fa-calendar-days me-1"></i>
                                    <?= date('d/m/Y', strtotime($log['activity_date'])); ?>
                                </div>
                            </td>
                            
                            <td>
                                <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1 fw-bold">
                                    <?= (float)$log['hours_count']; ?> ชม.
                                </span>
                            </td>
                            
                            <td>
                                <?php if(!empty($log['link_evidence'])): 
                                    $ev_link = $log['link_evidence'];
                                    if (!preg_match('/^https?:\/\//i', $ev_link)) {
                                        $ev_link = '../../../e-service/' . $ev_link;
                                    }
                                ?>
                                    <a href="<?= htmlspecialchars($ev_link); ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill px-3">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> เปิดดูหลักฐาน
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fa-solid fa-ban me-1"></i>ไม่มีหลักฐาน</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if($log['status'] == 'pending'): ?>
                                    <span class="badge bg-warning-subtle text-warning rounded-pill px-2 py-1">รออนุมัติ</span>
                                <?php elseif($log['status'] == 'approved'): ?>
                                    <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">อนุมัติแล้ว</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1">ปฏิเสธรายการ</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <div class="d-inline-flex gap-1">
                                    <form action="" method="POST" class="d-inline m-0">
                                        <input type="hidden" name="log_id" value="<?= $log['log_id']; ?>">
                                        
                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success rounded-circle icon-btn" title="อนุมัติรายการ" <?= $log['status'] == 'approved' ? 'disabled style="opacity:0.3;"' : '' ?>>
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                        
                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-warning rounded-circle icon-btn" title="ปฏิเสธ/ตีกลับ" <?= $log['status'] == 'rejected' ? 'disabled style="opacity:0.3;"' : '' ?>>
                                            <i class="fa-solid fa-xmark text-white"></i>
                                        </button>
                                        
                                        <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger rounded-circle icon-btn delete-btn" title="ลบออกจากระบบ">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Modal ตั้งค่าภาคการศึกษา -->
<div class="modal fade" id="termSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="fw-bold text-dark"><i class="fa-solid fa-gear text-primary me-2"></i>ตั้งค่าภาคการศึกษาปัจจุบัน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-muted mb-4">เลือกภาคการศึกษาที่ต้องการให้เป็นค่าเริ่มต้นปัจจุบัน สำหรับนิสิตที่เข้าใช้งานระบบ e-service</p>
                
                <div class="list-group mb-4 shadow-sm border-0 rounded-4 overflow-hidden">
                    <?php 
                    $display_terms = $all_terms;
                    if (!in_array($current_term, $display_terms) && !empty($current_term)) {
                        array_unshift($display_terms, $current_term);
                    }
                    if (empty($display_terms)): 
                    ?>
                        <div class="list-group-item text-center text-muted py-4 border-0">
                            ยังไม่มีข้อมูลภาคการศึกษาในระบบ
                        </div>
                    <?php else: ?>
                        <?php foreach($display_terms as $term): ?>
                            <div class="list-group-item border-start-0 border-end-0 d-flex justify-content-between align-items-center py-3 <?= $term === $current_term ? 'bg-primary-subtle border-primary border-bottom' : '' ?>">
                                <div>
                                    <span class="fw-bold <?= $term === $current_term ? 'text-primary' : 'text-dark' ?>">เทอม <?= htmlspecialchars($term) ?></span>
                                    <?php if($term === $current_term): ?>
                                        <span class="badge bg-primary ms-2 rounded-pill"><i class="fa-solid fa-check me-1"></i>กำลังใช้งาน</span>
                                    <?php endif; ?>
                                </div>
                                <?php if($term !== $current_term): ?>
                                <form action="" method="POST" class="m-0 p-0">
                                    <input type="hidden" name="action" value="update_term">
                                    <input type="hidden" name="academic_term" value="<?= htmlspecialchars($term) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm">เปิดใช้งาน</button>
                                </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 disabled shadow-sm">เปิดใช้งานแล้ว</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <hr class="border-secondary opacity-10 my-4">

                <form action="" method="POST" class="bg-light p-3 rounded-4 border">
                    <input type="hidden" name="action" value="update_term">
                    <label class="form-label fw-bold text-dark small mb-2"><i class="fa-solid fa-plus text-primary me-1"></i> เพิ่มภาคการศึกษาใหม่ (หากยังไม่มีในรายการ)</label>
                    <div class="input-group">
                        <input type="text" name="academic_term" class="form-control border-end-0 bg-white" placeholder="ตัวอย่าง 1/2569" required>
                        <button type="submit" class="btn btn-dark px-4 shadow-sm">บันทึก & เปิดใช้</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

<?php require_once '../../includes/footer.php'; ?>

<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function () {
    // ป้องกันการทับซ้อนและเขียนทับด้วยคำสั่ง destroy: true
    let table = $('#approveTable').DataTable({
        destroy: true,
        responsive: true,
        pageLength: 10,
        dom: 'rtip', // ปิดกล่องเสิร์ชเริ่มต้นของระบบ เพราะเราเขียนครอบควบคุมเองภายนอกสวยกว่า
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
        },
        order: [] // ปิดการสลับเรียงลำดับออโต้เริ่มต้น เพื่อยึดลำดับเรียงจากที่เราทำไว้ใน SQL
    });

    /* ฟังก์ชัน 1: ค้นหาด่วนอิสระ (พิมพ์ข้อมูลใดๆ ในตาราง) */
    $('#customSearch').on('keyup', function () {
        table.search(this.value).draw();
    });

    /* ฟังก์ชัน 2: ดรอปดาวน์กรองสเตตัสความคืบหน้า (คอลัมน์ลำดับที่ 5 ในโครงสร้างคือสถานะ) */
    $('#statusFilter').on('change', function () {
        table.column(5).search(this.value).draw();
    });

    /* ฟังก์ชัน 3: ดักสคริปต์ยืนยันการลบข้อมูลเพื่อความปลอดภัย */
    $(document).on('click', '.delete-btn', function(e) {
        let confirmAction = confirm("⚠️ คุณมั่นใจใช่ไหมที่จะลบรายการชั่วโมงกิจกรรมชิ้นนี้ออกจากระบบระบบเป็นการถาวร? (การลบจะไม่สามารถกู้คืนกลับมาได้อีก)");
        if (!confirmAction) {
            e.preventDefault(); // สั่งระงับฟอร์มไม่ให้ส่งค่า POST ไปทำงาน
        }
    });

    /* ฟังก์ชัน 4: รีเฟรชข้อมูล */
    $('#reloadBtn').click(function () {
        location.reload();
    });
});
</script>

<style>
/* ตกแต่งปุ่มขนาดไอคอนเพิ่มให้กดง่ายพอดีนิ้ว */
.icon-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.text-wrap-custom {
    max-width: 300px;
    white-space: normal;
    word-break: break-word;
}
</style>