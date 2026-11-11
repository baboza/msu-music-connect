<?php
session_start();
// เปิดแสดง Error เพื่อวินิจฉัย
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';

$msg = "";
$msg_type = "";

try {
    $depts = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll();
    
    // แก้ไข: ใช้ GROUP BY role_name เพื่อกำจัดชื่อสิทธิ์ที่ซ้ำกัน (ดึงมาแค่ 1 ตัวต่อ 1 ชื่อ)
    $roles_list = $pdo->query("SELECT MIN(role_id) as role_id, role_name FROM roles GROUP BY role_name ORDER BY role_name ASC")->fetchAll();
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage() . "<br>กรุณาตรวจสอบว่าสร้างตารางและเพิ่มข้อมูลเบื้องต้นหรือยัง");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // --- สำคัญ: สร้างรหัสประจำตัวเป็น Hex ID 8 หลัก แบบสุ่ม (เช่น 41b1312e) ---
        $user_id = substr(md5(uniqid(rand(), true)), 0, 8);
        
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $prefix = trim($_POST['prefix_name']);
        $fname = trim($_POST['first_name']);
        $lname = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $dept_id = $_POST['dept_id'];
        $roles = isset($_POST['roles']) ? $_POST['roles'] : [];

        // แก้ไข: นำ $user_id ที่สุ่มได้ บันทึกลงไปในคำสั่ง INSERT ด้วย
        $stmt = $pdo->prepare("INSERT INTO users (user_id, username, password, prefix_name, first_name, last_name, email, dept_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$user_id, $username, $password, $prefix, $fname, $lname, $email, $dept_id]);
        
        if (!empty($roles)) {
            $role_stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roles as $role_id) {
                $role_stmt->execute([$user_id, $role_id]);
            }
        }

        $pdo->commit();
        $msg = "ลงทะเบียนสำเร็จ! (รหัสประจำตัว: <strong>$user_id</strong>) <br><a href='login.php' class='alert-link'>คลิกที่นี่เพื่อเข้าสู่ระบบ</a>";
        $msg_type = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ลงทะเบียนบุคลากร - Music E-office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@300;400;500;600;700&family=Anuphan:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/google-sans" rel="stylesheet">
    <style>
        body, button, input, select, textarea, h1, h2, h3, h4, h5, h6 { font-family: 'Google Sans', sans-serif !important; }
        body { font-family: 'Google Sans', sans-serif !important; background: #f3e5f5; padding: 40px 20px; }
        .reg-card { max-width: 800px; margin: auto; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(75,0,130,0.1); overflow: hidden; }
        .header { background: #39048f; color: white; padding: 25px; border-bottom: 4px solid #c5a028; }
        .btn-purple { background-color: #39048f; color: white; border-radius: 50px; padding: 10px 40px; }
        .btn-purple:hover { background-color: #4a06b8; color: white; }
        .form-control:focus, .form-select:focus { border-color: #39048f; box-shadow: 0 0 0 0.25rem rgba(57,4,143,0.25); }
        .text-purple { color: #39048f; }
    </style>
</head>
<body>
    <div class="reg-card">
        <div class="header text-center">
            <h4 class="fw-bold mb-1">ลงทะเบียนบุคลากรใหม่</h4>
            <p class="mb-0 text-white-50 small">Music E-office วิทยาลัยดุริยางคศิลป์</p>
        </div>
        <div class="p-4 p-md-5">
            <?php if($msg) echo "<div class='alert alert-$msg_type shadow-sm'>$msg</div>"; ?>
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted">คำนำหน้า</label>
                        <input type="text" name="prefix_name" class="form-control" placeholder="นาย/นาง/ดร." required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">ชื่อ</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">นามสกุล</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">ชื่อผู้ใช้งาน (Username)</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">รหัสผ่าน (Password)</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">อีเมล</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">หน่วยงานต้นสังกัด</label>
                        <select name="dept_id" class="form-select" required>
                            <option value="">-- เลือกหน่วยงาน --</option>
                            <?php foreach($depts as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12 mt-4 pt-3 border-top">
                        <label class="fw-bold text-purple mb-3">กำหนดสิทธิ์การเข้าถึงระบบ (Roles):</label>
                        <div class="row g-2">
                            <?php foreach($roles_list as $r): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input border-secondary" type="checkbox" name="roles[]" value="<?php echo $r['role_id']; ?>" id="role_<?php echo $r['role_id']; ?>"> 
                                        <label class="form-check-label small" for="role_<?php echo $r['role_id']; ?>">
                                            <?php echo htmlspecialchars($r['role_name']); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-12 text-center mt-5">
                        <button type="submit" class="btn btn-purple fw-bold shadow-sm">สร้างบัญชีผู้ใช้งาน</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>