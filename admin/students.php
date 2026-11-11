<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../auth/session_check.php';
require_once '../config/db.php';

// ---------------------------------------------------------
// เช็คสิทธิ์: หน้านี้เข้าได้เฉพาะ 'admin' เท่านั้น
// ---------------------------------------------------------
if (!hasRole(['admin'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์เข้าใช้งานระบบจัดการผู้ใช้งาน'); window.location.href='../index.php';</script>";
    exit();
}

$success_msg = "";
$error_msg = "";

// ---------------------------------------------------------
// จัดการข้อมูลเมื่อมีการ Submit Form (เพิ่ม / แก้ไข / ลบ / รีเซ็ตรหัสผ่าน)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action == 'add' || $action == 'edit') {
            $student_id = trim($_POST['student_id']); // This is the ID/Username
            $prefix_name = trim($_POST['prefix_name']);
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $major = trim($_POST['major']); 
            $year_level = !empty($_POST['year_level']) ? intval($_POST['year_level']) : NULL;
            $email = trim($_POST['email']);
            $phone_number = trim($_POST['phone_number']);
            $status = $_POST['status'] ?? 'active';
            
            if ($action == 'add') {
                // เช็ค รหัสนิสิต ซ้ำ
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = ?");
                $stmt_check->execute([$student_id]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("รหัสนิสิตนี้มีในระบบแล้ว");
                }

                $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO students (student_id, password, prefix_name, first_name, last_name, major, year_level, email, phone_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$student_id, $password, $prefix_name, $first_name, $last_name, $major, $year_level, $email, $phone_number, $status]);
                
                $success_msg = "เพิ่มนิสิตใหม่เรียบร้อยแล้ว";

            } elseif ($action == 'edit') {
                $target_id = $_POST['target_id'];
                
                $stmt = $pdo->prepare("UPDATE students SET prefix_name=?, first_name=?, last_name=?, major=?, year_level=?, email=?, phone_number=?, status=? WHERE student_id=?");
                $stmt->execute([$prefix_name, $first_name, $last_name, $major, $year_level, $email, $phone_number, $status, $target_id]);
                $success_msg = "อัปเดตข้อมูลนิสิตเรียบร้อยแล้ว";
            }

        } elseif ($action == 'reset_password') {
            $target_id = $_POST['target_id'];
            $new_password = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE students SET password = ? WHERE student_id = ?")->execute([$new_password, $target_id]);
            $success_msg = "รีเซ็ตรหัสผ่านเรียบร้อยแล้ว";
            
        } elseif ($action == 'delete') {
            // ใช้ Soft Delete
            $target_id = $_POST['target_id'];
            $pdo->prepare("UPDATE students SET status = 'inactive' WHERE student_id = ?")->execute([$target_id]);
            $success_msg = "ระงับการใช้งานเรียบร้อยแล้ว";
        }
        
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// ---------------------------------------------------------
// การดึงข้อมูลจะถูกจัดการผ่าน AJAX (ajax_students.php)
// ---------------------------------------------------------

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-user-graduate me-2"></i>จัดการนิสิต (Students)</h1>
            <p class="text-muted small">เพิ่ม ลบ แก้ไข และจัดการข้อมูลนิสิต</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-purple shadow-sm rounded-pill px-4 d-flex align-items-center fw-bold" data-bs-toggle="modal" data-bs-target="#studentModal" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-2"></i> เพิ่มนิสิตใหม่
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm border-start border-success border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ระบบค้นหาและตัวกรอง -->
    <div class="card shadow-sm border-0 mb-4 bg-white rounded-4 animate__animated animate__fadeIn">
        <div class="card-body p-3">
            <form id="filterForm" onsubmit="event.preventDefault(); loadPage(1);" class="row g-2 align-items-center">
                <div class="col-lg-4 col-md-12">
                    <div class="input-group">
                        <span class="input-group-text bg-white text-muted border-end-0 rounded-start-pill"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" id="searchInput" class="form-control border-start-0 ps-0 rounded-end-pill shadow-none" placeholder="ค้นหารหัสนิสิต, ชื่อ, อีเมล..." onkeyup="debounceSearch()">
                    </div>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <select id="majorFilter" class="form-select text-muted rounded-pill shadow-none border" onchange="loadPage(1)">
                        <option value="">- ทุกสาขา -</option>
                        <option value="ภาควิชาดุริยางคศาสตร์">ภาควิชาดุริยางคศาสตร์</option>
                        <option value="ภาควิชาดนตรีศึกษา">ภาควิชาดนตรีศึกษา</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <select id="yearFilter" class="form-select text-muted rounded-pill shadow-none border" onchange="loadPage(1)">
                        <option value="">- ทุกชั้นปี -</option>
                        <option value="1">ปี 1</option>
                        <option value="2">ปี 2</option>
                        <option value="3">ปี 3</option>
                        <option value="4">ปี 4</option>
                        <option value="5">ปี 5</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <select id="statusFilter" class="form-select text-muted rounded-pill shadow-none border" onchange="loadPage(1)">
                        <option value="">- ทุกสถานะ -</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="graduated">Graduated</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6 d-flex gap-2">
                    <button type="submit" class="btn btn-purple w-100 fw-bold shadow-sm rounded-pill"><i class="fa-solid fa-filter me-1"></i> กรอง</button>
                    <button type="button" onclick="clearFilters()" class="btn btn-light border text-muted w-100 fw-bold shadow-sm rounded-pill"><i class="fa-solid fa-xmark me-1"></i> ล้าง</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 border-top border-purple border-4 bg-white animate__animated animate__fadeInUp">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4" width="25%">รหัสนิสิต / ชื่อ - นามสกุล</th>
                            <th width="15%">สาขาวิชา</th>
                            <th width="10%">ชั้นปี</th>
                            <th width="20%">ข้อมูลติดต่อ</th>
                            <th class="text-center" width="10%">สถานะ</th>
                            <th class="text-center pe-4" width="15%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="studentTableBody">
                        <!-- AJAX content will be loaded here -->
                        <tr><td colspan="6" class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลดข้อมูล...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" class="p-3 bg-light text-center rounded-bottom-4 border-top"></div>
        </div>
    </div>
</main>

<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-purple text-white">
                <h5 class="modal-title fw-bold" id="modalLabel"><i class="fa-solid fa-user-pen me-2"></i>จัดการนิสิต</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST" id="studentForm">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="target_id" id="targetId">

                    <h6 class="fw-bold text-purple border-bottom pb-2 mb-3">1. ข้อมูลส่วนตัว (Profile)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">รหัสนิสิต</label>
                            <input type="text" name="student_id" id="studentId" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">คำนำหน้า</label>
                            <input type="text" name="prefix_name" id="prefixName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">ชื่อจริง</label>
                            <input type="text" name="first_name" id="firstName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">นามสกุล</label>
                            <input type="text" name="last_name" id="lastName" class="form-control shadow-sm" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">สาขาวิชา</label>
                            <select name="major" id="major" class="form-select shadow-sm">
                                <option value="ภาควิชาดุริยางคศาสตร์">ภาควิชาดุริยางคศาสตร์</option>
                                <option value="ภาควิชาดนตรีศึกษา">ภาควิชาดนตรีศึกษา</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ชั้นปี</label>
                            <select name="year_level" id="yearLevel" class="form-select shadow-sm">
                                <option value="1">ปี 1</option>
                                <option value="2">ปี 2</option>
                                <option value="3">ปี 3</option>
                                <option value="4">ปี 4</option>
                                <option value="5">ปี 5</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">อีเมล</label>
                            <input type="email" name="email" id="email" class="form-control shadow-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">เบอร์โทรศัพท์</label>
                            <input type="text" name="phone_number" id="phoneNumber" class="form-control shadow-sm">
                        </div>
                    </div>

                    <h6 class="fw-bold text-purple border-bottom pb-2 mb-3">2. บัญชีเข้าใช้งานระบบ</h6>
                    <div class="row g-3">
                        <div class="col-md-6" id="passwordWrapper">
                            <label class="form-label small fw-bold">รหัสผ่าน (Password)</label>
                            <input type="text" name="password" id="passWord" class="form-control shadow-sm" placeholder="ตั้งรหัสผ่านใหม่...">
                            <div class="form-text extra-small text-danger">* บังคับกรอกกรณีเพิ่มนิสิตใหม่</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">สถานะบัญชี</label>
                            <select name="status" id="status" class="form-select shadow-sm">
                                <option value="active">Active (กำลังศึกษา)</option>
                                <option value="inactive">Inactive (ระงับการใช้งาน)</option>
                                <option value="graduated">Graduated (สำเร็จการศึกษา)</option>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ฟอร์มซ่อนสำหรับลบนิสิต -->
<form id="deleteStudentForm" action="students.php" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="target_id" id="deleteTargetId" value="">
</form>

<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-key me-2"></i>รีเซ็ตรหัสผ่าน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="target_id" id="pwdTargetId">
                    
                    <p class="mb-3 text-muted small">กำลังเปลี่ยนรหัสผ่านของ: <strong id="pwdUserName" class="text-dark"></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">รหัสผ่านใหม่</label>
                        <input type="text" name="new_password" class="form-control shadow-sm" required minlength="4">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="fa-solid fa-check me-1"></i> ยืนยันการเปลี่ยนรหัส</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    .extra-small { font-size: 0.75rem; }
    .border-purple { border-color: #4b0082 !important; }
</style>

<script>
function openAddModal() {
    document.getElementById('modalLabel').innerText = "เพิ่มนิสิตใหม่";
    document.getElementById('formAction').value = "add";
    document.getElementById('targetId').value = "";
    document.getElementById('studentId').readOnly = false;
    document.getElementById('studentForm').reset();
    
    document.getElementById('passWord').required = true;
    document.getElementById('passwordWrapper').style.display = 'block';
}

function openPasswordModal(id, fullname) {
    document.getElementById('pwdTargetId').value = id;
    document.getElementById('pwdUserName').innerText = fullname;
    var myModal = new bootstrap.Modal(document.getElementById('passwordModal'));
    myModal.show();
}

let searchTimer;
let currentPage = 1;

function loadPage(page) {
    currentPage = page;
    const search = document.getElementById('searchInput').value;
    const major = document.getElementById('majorFilter').value;
    const year = document.getElementById('yearFilter').value;
    const status = document.getElementById('statusFilter').value;

    fetch(`ajax_students.php?search=${encodeURIComponent(search)}&major=${encodeURIComponent(major)}&year_level=${encodeURIComponent(year)}&status=${encodeURIComponent(status)}&page=${page}`)
    .then(res => res.json())
    .then(data => {
        if(data.error) { alert(data.error); return; }
        document.getElementById('studentTableBody').innerHTML = data.html;
        document.getElementById('paginationContainer').innerHTML = data.pagination;
    })
    .catch(err => console.error("Error fetching students:", err));
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadPage(1), 300);
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('majorFilter').value = '';
    document.getElementById('yearFilter').value = '';
    document.getElementById('statusFilter').value = '';
    loadPage(1);
}

document.addEventListener('DOMContentLoaded', function() {
    loadPage(1);

    // Event Delegation สำหรับปุ่มแก้ไขที่โหลดมาผ่าน AJAX
    document.getElementById('studentTableBody').addEventListener('click', function(e) {
        const btn = e.target.closest('.edit-btn');
        if (!btn) return;
        
        const dataset = btn.dataset;
        
        document.getElementById('modalLabel').innerText = "แก้ไขข้อมูลนิสิต";
        document.getElementById('formAction').value = "edit";
        document.getElementById('targetId').value = dataset.id;
        
        document.getElementById('studentId').value = dataset.id;
        document.getElementById('studentId').readOnly = true; 
        
        document.getElementById('prefixName').value = dataset.prefix;
        document.getElementById('firstName').value = dataset.fname;
        document.getElementById('lastName').value = dataset.lname;
        document.getElementById('major').value = dataset.major; 
        document.getElementById('yearLevel').value = dataset.year;
        document.getElementById('email').value = dataset.email;
        document.getElementById('phoneNumber').value = dataset.phone;
        document.getElementById('status').value = dataset.status;
        
        document.getElementById('passWord').required = false;
        document.getElementById('passwordWrapper').style.display = 'none';

        var myModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('studentModal'));
        myModal.show();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
