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
            $username = trim($_POST['username']);
            $prefix_name = trim($_POST['prefix_name']);
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $position = trim($_POST['position']); 
            $email = trim($_POST['email']);
            $dept_id = !empty($_POST['dept_id']) ? $_POST['dept_id'] : NULL;
            $status = $_POST['status'] ?? 'active';
            $roles = $_POST['roles'] ?? []; 
            
            $pdo->beginTransaction();

            if ($action == 'add') {
                // เช็ค Username ซ้ำ
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt_check->execute([$username]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("ชื่อผู้ใช้งาน (Username) นี้มีในระบบแล้ว");
                }

                // สร้าง user_id แบบสุ่ม 8 หลัก
                $new_user_id = substr(md5(uniqid(rand(), true)), 0, 8);
                $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (user_id, username, password, prefix_name, first_name, last_name, position, email, dept_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$new_user_id, $username, $password, $prefix_name, $first_name, $last_name, $position, $email, $dept_id, $status]);
                
                $target_user_id = $new_user_id;
                $success_msg = "เพิ่มผู้ใช้งานใหม่เรียบร้อยแล้ว";

            } elseif ($action == 'edit') {
                $target_user_id = $_POST['user_id'];
                
                // เช็ค Username ซ้ำ (ยกเว้นตัวเอง)
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
                $stmt_check->execute([$username, $target_user_id]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("ชื่อผู้ใช้งาน (Username) นี้มีในระบบแล้ว");
                }

                $stmt = $pdo->prepare("UPDATE users SET username=?, prefix_name=?, first_name=?, last_name=?, position=?, email=?, dept_id=?, status=? WHERE user_id=?");
                $stmt->execute([$username, $prefix_name, $first_name, $last_name, $position, $email, $dept_id, $status, $target_user_id]);
                $success_msg = "อัปเดตข้อมูลผู้ใช้งานเรียบร้อยแล้ว";
            }

            // จัดการสิทธิ์ (Roles)
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$target_user_id]);
            if (!empty($roles)) {
                $stmt_role = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $role_id) {
                    $stmt_role->execute([$target_user_id, $role_id]);
                }
            }

            $pdo->commit();

        } elseif ($action == 'reset_password') {
            $target_user_id = $_POST['user_id'];
            $new_password = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([$new_password, $target_user_id]);
            $success_msg = "รีเซ็ตรหัสผ่านเรียบร้อยแล้ว";
            
        } elseif ($action == 'delete') {
            // ใช้ Soft Delete
            $target_user_id = $_POST['user_id'];
            $pdo->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ?")->execute([$target_user_id]);
            $success_msg = "ระงับการใช้งานเรียบร้อยแล้ว";
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error_msg = $e->getMessage();
    }
}

// ---------------------------------------------------------
// ดึงข้อมูลพื้นฐานมาแสดงผล (พร้อมเช็คสถานะออนไลน์)
// ---------------------------------------------------------
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();
$roles_list = $pdo->query("SELECT * FROM roles ORDER BY role_name")->fetchAll();

$stmt_users = $pdo->query("
    SELECT u.*, d.dept_name,
           IF(u.last_active >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), 1, 0) as is_online
    FROM users u 
    LEFT JOIN departments d ON u.dept_id = d.dept_id 
    ORDER BY u.first_name ASC
");
$users = $stmt_users->fetchAll();

$user_roles_data = [];
$stmt_ur = $pdo->query("
    SELECT ur.user_id, r.role_id, r.role_name 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.role_id
");
while ($row = $stmt_ur->fetch()) {
    $user_roles_data[$row['user_id']][] = [
        'id' => $row['role_id'],
        'name' => $row['role_name']
    ];
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-users-gear me-2"></i>จัดการผู้ใช้งาน (Users)</h1>
            <p class="text-muted small">เพิ่ม ลบ แก้ไข และติดตามสถานะการใช้งานระบบ</p>
        </div>
        <div class="d-flex gap-2">
            <a href="login_logs.php" class="btn bg-purple-subtle text-purple border border-purple border-opacity-25 shadow-sm rounded-pill px-4 d-flex align-items-center fw-bold">
                <i class="fa-solid fa-clock-rotate-left me-2"></i> ประวัติการเข้าใช้งาน
            </a>
            <button class="btn btn-purple shadow-sm rounded-pill px-4 d-flex align-items-center fw-bold" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-2"></i> เพิ่มผู้ใช้งานใหม่
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

    <div class="card shadow-sm border-0 border-top border-purple border-4 bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th class="ps-4" width="25%">ชื่อ - นามสกุล / ตำแหน่ง</th>
                            <th width="15%">Username</th>
                            <th width="15%">สังกัด/หน่วยงาน</th>
                            <th width="20%">สิทธิ์ (Roles)</th>
                            <th class="text-center" width="10%">สถานะ</th>
                            <th class="text-center pe-4" width="15%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm me-3 bg-purple-subtle text-purple fw-bold rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 45px; height: 45px;">
                                        <?php 
                                            if (!empty($u['profile_picture'])) {
                                                echo "<img src='../uploads/avatars/".htmlspecialchars($u['profile_picture'])."' class='rounded-circle w-100 h-100' style='object-fit:cover;'>";
                                            } else {
                                                echo mb_substr($u['first_name'], 0, 1, 'UTF-8'); 
                                            }
                                        ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($u['prefix_name'].$u['first_name'].' '.$u['last_name']); ?></div>
                                        <div class="extra-small text-purple mb-1"><?php echo htmlspecialchars($u['position'] ?? 'ไม่ระบุตำแหน่ง'); ?></div>
                                        <div class="extra-small text-muted"><i class="fa-regular fa-envelope me-1"></i><?php echo htmlspecialchars($u['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="fw-bold text-secondary"><?php echo htmlspecialchars($u['username']); ?></span></td>
                            <td><div class="small"><?php echo htmlspecialchars($u['dept_name'] ?? '-'); ?></div></td>
                            <td>
                                <?php 
                                    $roles = $user_roles_data[$u['user_id']] ?? [];
                                    $role_ids = [];
                                    if (empty($roles)) {
                                        echo "<span class='badge bg-light text-secondary border px-2'>ไม่มีสิทธิ์พิเศษ</span>";
                                    } else {
                                        foreach($roles as $r) {
                                            $role_ids[] = $r['id'];
                                            $badge_class = ($r['name'] == 'admin') ? 'bg-danger' : (($r['name'] == 'คณบดี' || $r['name'] == 'รักษาการคณบดี') ? 'bg-gold' : 'bg-purple-subtle text-purple border border-purple');
                                            echo "<span class='badge {$badge_class} me-1 mb-1 fw-normal px-2'>".htmlspecialchars($r['name'])."</span>";
                                        }
                                    }
                                    $role_ids_json = json_encode($role_ids);
                                ?>
                            </td>
                            <td class="text-center">
                                <?php if($u['status'] == 'active'): ?>
                                    <div class="mb-1"><span class="badge bg-success shadow-sm px-2">Active</span></div>
                                    
                                    <?php if ($u['is_online'] == 1): ?>
                                        <span class="badge bg-success-subtle text-success border border-success rounded-pill px-2 extra-small" title="กำลังใช้งาน">
                                            <span class="spinner-grow spinner-grow-sm me-1" style="width: 6px; height: 6px;" role="status"></span> ออนไลน์
                                        </span>
                                    <?php else: ?>
                                        <div class="extra-small text-muted" title="ใช้งานล่าสุด">
                                            ออฟไลน์<br>
                                            <?php echo $u['last_active'] ? date('d/m/y H:i', strtotime($u['last_active'])) : '-'; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                <?php else: ?>
                                    <span class="badge bg-secondary shadow-sm px-2">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4 text-nowrap">
                                
                                <a href="login_logs.php?user_id=<?php echo htmlspecialchars($u['user_id']); ?>" class="btn btn-sm btn-light border text-secondary rounded-circle me-1" title="ดูประวัติการเข้าระบบของคนนี้">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </a>

                                <a href="../modules/cv/public_cv.php?u=<?php echo htmlspecialchars($u['user_id']); ?>" target="_blank" class="btn btn-sm btn-light border text-info rounded-circle me-1" title="ดูแฟ้มประวัติ (Public CV)">
                                    <i class="fa-solid fa-address-card"></i>
                                </a>

                                <button type="button" class="btn btn-sm btn-light border text-primary rounded-circle me-1 edit-user-btn" 
                                    title="แก้ไขข้อมูล"
                                    data-id="<?php echo htmlspecialchars($u['user_id']); ?>"
                                    data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                    data-prefix="<?php echo htmlspecialchars($u['prefix_name']); ?>"
                                    data-fname="<?php echo htmlspecialchars($u['first_name']); ?>"
                                    data-lname="<?php echo htmlspecialchars($u['last_name']); ?>"
                                    data-position="<?php echo htmlspecialchars($u['position'] ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($u['email']); ?>"
                                    data-dept="<?php echo htmlspecialchars($u['dept_id'] ?? ''); ?>"
                                    data-status="<?php echo htmlspecialchars($u['status']); ?>"
                                    data-roles='<?php echo $role_ids_json; ?>'>
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                
                                <button type="button" class="btn btn-sm btn-light border text-warning rounded-circle me-1" title="เปลี่ยนรหัสผ่าน"
                                    onclick="openPasswordModal('<?php echo $u['user_id']; ?>', '<?php echo htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])); ?>')">
                                    <i class="fa-solid fa-key"></i>
                                </button>

                                <?php if($u['user_id'] != $_SESSION['user_id']): ?>
                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการระงับผู้ใช้งานท่านนี้?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-light border text-danger rounded-circle" title="ระงับการใช้งาน">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-purple text-white">
                <h5 class="modal-title fw-bold" id="userModalLabel"><i class="fa-solid fa-user-pen me-2"></i>จัดการผู้ใช้งาน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST" id="userForm">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="user_id" id="userId">

                    <h6 class="fw-bold text-purple border-bottom pb-2 mb-3">1. ข้อมูลส่วนตัว (Profile)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">คำนำหน้า</label>
                            <input type="text" name="prefix_name" id="prefixName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">ชื่อจริง</label>
                            <input type="text" name="first_name" id="firstName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">นามสกุล</label>
                            <input type="text" name="last_name" id="lastName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ตำแหน่ง (Position)</label>
                            <input type="text" name="position" id="position" class="form-control shadow-sm" placeholder="เช่น นักวิชาการคอมพิวเตอร์...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">อีเมล (Email)</label>
                            <input type="email" name="email" id="email" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">สังกัด / หน่วยงาน</label>
                            <select name="dept_id" id="deptId" class="form-select shadow-sm">
                                <option value="">-- ไม่ระบุสังกัด --</option>
                                <?php foreach($departments as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold text-purple border-bottom pb-2 mb-3">2. บัญชีผู้ใช้งาน (Account)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Username</label>
                            <input type="text" name="username" id="userName" class="form-control shadow-sm" required>
                        </div>
                        <div class="col-md-6" id="passwordWrapper">
                            <label class="form-label small fw-bold">รหัสผ่าน (Password)</label>
                            <input type="text" name="password" id="passWord" class="form-control shadow-sm" placeholder="ตั้งรหัสผ่านใหม่...">
                            <div class="form-text extra-small text-danger">* บังคับกรอกกรณีเพิ่มผู้ใช้ใหม่</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">สถานะบัญชี</label>
                            <select name="status" id="status" class="form-select shadow-sm">
                                <option value="active">Active (ใช้งานปกติ)</option>
                                <option value="inactive">Inactive (ระงับการใช้งาน)</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold text-purple border-bottom pb-2 mb-3">3. กำหนดสิทธิ์การใช้งานระบบ (Roles)</h6>
                    <div class="bg-white p-3 rounded border shadow-sm">
                        <div class="row g-2">
                            <?php foreach($roles_list as $role): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input role-checkbox" type="checkbox" name="roles[]" value="<?php echo $role['role_id']; ?>" id="role_<?php echo $role['role_id']; ?>">
                                        <label class="form-check-label small" for="role_<?php echo $role['role_id']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
                    <input type="hidden" name="user_id" id="pwdUserId">
                    
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
    .text-gold { color: #c5a028; }
    .bg-gold { background-color: #c5a028; color: white; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    .extra-small { font-size: 0.75rem; }
    .border-purple { border-color: #4b0082 !important; }
</style>

<script>
// ฟังก์ชันเปิด Modal สำหรับ "เพิ่มผู้ใช้"
function openAddModal() {
    document.getElementById('userModalLabel').innerText = "เพิ่มผู้ใช้งานใหม่";
    document.getElementById('formAction').value = "add";
    document.getElementById('userId').value = "";
    document.getElementById('userForm').reset();
    
    document.getElementById('passWord').required = true;
    document.getElementById('passwordWrapper').style.display = 'block';

    document.querySelectorAll('.role-checkbox').forEach(cb => cb.checked = false);
}

// ฟังก์ชันเปิด Modal สำหรับ "เปลี่ยนรหัสผ่าน"
function openPasswordModal(id, fullname) {
    document.getElementById('pwdUserId').value = id;
    document.getElementById('pwdUserName').innerText = fullname;
    
    var myModal = new bootstrap.Modal(document.getElementById('passwordModal'));
    myModal.show();
}

// -------------------------------------------------------------
// ใช้ Event Listener สำหรับปุ่มแก้ไขแทน onclick เดิม
// -------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelectorAll('.edit-user-btn');
    
    editButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            // ดึงข้อมูลจาก Data Attributes
            const dataset = this.dataset;
            
            document.getElementById('userModalLabel').innerText = "แก้ไขข้อมูลผู้ใช้งาน";
            document.getElementById('formAction').value = "edit";
            document.getElementById('userId').value = dataset.id;
            
            document.getElementById('userName').value = dataset.username;
            document.getElementById('prefixName').value = dataset.prefix;
            document.getElementById('firstName').value = dataset.fname;
            document.getElementById('lastName').value = dataset.lname;
            document.getElementById('position').value = dataset.position; 
            document.getElementById('email').value = dataset.email;
            document.getElementById('deptId').value = dataset.dept;
            document.getElementById('status').value = dataset.status;
            
            // ซ่อนฟิลด์ Password ในโหมดแก้ไข
            document.getElementById('passWord').required = false;
            document.getElementById('passwordWrapper').style.display = 'none';

            // จัดการ Checkbox สิทธิ์
            document.querySelectorAll('.role-checkbox').forEach(cb => cb.checked = false);
            const roleIds = JSON.parse(dataset.roles || '[]');
            roleIds.forEach(role_id => {
                let cb = document.getElementById('role_' + role_id);
                if(cb) cb.checked = true;
            });

            // เปิด Modal อย่างปลอดภัย
            var myModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal'));
            myModal.show();
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>