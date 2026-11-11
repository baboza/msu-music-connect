<?php
require_once '../auth/session_check.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

// ตรวจสอบสิทธิ์ Admin (ยึดตาม 'admin' ที่เราตั้งค่าไว้)
checkAccess(['admin']);

// รับ ID ผู้ใช้งาน
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. ดึงข้อมูลพื้นฐานของผู้ใช้งาน
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "<script>alert('ไม่พบข้อมูลบุคลากร'); window.location.href='users.php';</script>";
    exit();
}

// 2. ดึงข้อมูลหน่วยงานทั้งหมด
$depts = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll();

// 3. ดึงสิทธิ์ (Roles) ทั้งหมดแบบ "ไม่ซ้ำชื่อ" (ใช้ GROUP BY)
$all_roles = $pdo->query("SELECT * FROM roles GROUP BY role_name ORDER BY role_name ASC")->fetchAll();

// 4. ดึงสิทธิ์ปัจจุบันที่ผู้ใช้คนนี้มี (ดึงเป็น ID มาเทียบ)
$stmt_current_roles = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$stmt_current_roles->execute([$user_id]);
$current_role_ids = $stmt_current_roles->fetchAll(PDO::FETCH_COLUMN);
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2 text-purple"><i class="fa-solid fa-user-pen me-2"></i>แก้ไขข้อมูลบุคลากร</h1>
        <a href="users.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-11">
            <form action="process_edit.php" method="POST" class="card shadow-sm border-0 mb-5">
                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">

                <div class="card-body p-4 p-md-5">
                    <div class="row g-4">
                        <div class="col-12 border-bottom pb-2">
                            <h5 class="text-purple mb-0">ข้อมูลพื้นฐาน</h5>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">คำนำหน้า</label>
                            <input type="text" name="prefix_name" class="form-control" value="<?php echo htmlspecialchars($user['prefix_name']); ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold small">ชื่อจริง</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold small">นามสกุล</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">ชื่อผู้ใช้งาน (Username)</label>
                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">อีเมลติดต่อ</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">สังกัด / ภาควิชา</label>
                            <select name="dept_id" class="form-select" required>
                                <?php foreach($depts as $d): ?>
                                    <option value="<?php echo $d['dept_id']; ?>" <?php echo ($d['dept_id'] == $user['dept_id']) ? 'selected' : ''; ?>>
                                        <?php echo $d['dept_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-danger">เปลี่ยนรหัสผ่านใหม่ (หากไม่เปลี่ยนให้เว้นว่าง)</label>
                            <input type="password" name="new_password" class="form-control border-danger-subtle" placeholder="Password">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">สถานะสมาชิก</label>
                            <div class="pt-2">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="status" value="active" id="active" <?php echo ($user['status'] == 'active') ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-success fw-bold" for="active">ใช้งานปกติ</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="status" value="inactive" id="inactive" <?php echo ($user['status'] == 'inactive') ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-danger fw-bold" for="inactive">ระงับการใช้งาน</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-5 border-bottom pb-2">
                            <h5 class="text-purple mb-0">จัดการสิทธิ์การเข้าถึง (Roles)</h5>
                        </div>
                        <div class="col-12 pt-2">
                            <div class="row g-2 px-2">
                                <?php foreach($all_roles as $r): ?>
                                    <div class="col-md-4 col-lg-3">
                                        <div class="form-check mb-2 p-2 border rounded shadow-xs bg-light-hover">
                                            <input class="form-check-input ms-0 me-2" type="checkbox" name="roles[]" 
                                                   value="<?php echo $r['role_id']; ?>" 
                                                   id="role_<?php echo $r['role_id']; ?>"
                                                   <?php echo in_array($r['role_id'], $current_role_ids) ? 'checked' : ''; ?>>
                                            <label class="form-check-label extra-small fw-normal" for="role_<?php echo $r['role_id']; ?>">
                                                <?php echo $r['role_name']; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-light p-4 text-center">
                    <button type="submit" class="btn btn-purple px-5 py-2">
                        <i class="fa-solid fa-save me-2"></i> อัปเดตข้อมูลบุคลากร
                    </button>
                    <a href="users.php" class="btn btn-light px-4 py-2 ms-2 border">ยกเลิก</a>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #39048f; color: white; }
    .form-control:focus, .form-select:focus { border-color: #4b0082; box-shadow: 0 0 0 0.25rem rgba(75, 0, 130, 0.1); }
    .extra-small { font-size: 0.8rem; }
    .bg-light-hover:hover { background-color: #f8f9fa; cursor: pointer; }
    .shadow-xs { box-shadow: 0 .125rem .25rem rgba(0,0,0,.030); }
</style>

<?php require_once '../includes/footer.php'; ?>