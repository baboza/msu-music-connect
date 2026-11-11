<?php
require_once '../auth/session_check.php';
require_once '../config/db.php';

// 1. ตรวจสอบสิทธิ์ Admin (ต้องเป็น 'admin' เท่านั้นถึงจะแก้คนอื่นได้)
checkAccess(['admin']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // รับค่าจากฟอร์ม
    $user_id = $_POST['user_id'];
    $prefix_name = $_POST['prefix_name'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $dept_id = $_POST['dept_id'];
    $status = $_POST['status'];
    $new_password = $_POST['new_password'];
    $selected_roles = isset($_POST['roles']) ? $_POST['roles'] : []; // รับค่า Checkbox เป็น Array

    try {
        // เริ่มต้น Transaction (ทำงานหลายคำสั่งต้องใช้ตัวนี้กันพลาด)
        $pdo->beginTransaction();

        // 2. จัดการอัปเดตตาราง users
        if (!empty($new_password)) {
            // กรณีมีการเปลี่ยนรหัสผ่านใหม่
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_user = "UPDATE users SET prefix_name = ?, first_name = ?, last_name = ?, email = ?, dept_id = ?, status = ?, password = ? WHERE user_id = ?";
            $stmt_user = $pdo->prepare($sql_user);
            $stmt_user->execute([$prefix_name, $first_name, $last_name, $email, $dept_id, $status, $hashed_password, $user_id]);
        } else {
            // กรณีไม่เปลี่ยนรหัสผ่าน
            $sql_user = "UPDATE users SET prefix_name = ?, first_name = ?, last_name = ?, email = ?, dept_id = ?, status = ? WHERE user_id = ?";
            $stmt_user = $pdo->prepare($sql_user);
            $stmt_user->execute([$prefix_name, $first_name, $last_name, $email, $dept_id, $status, $user_id]);
        }

        // 3. จัดการอัปเดตตาราง user_roles (สิทธิ์การใช้งาน)
        // ขั้นแรก: ลบสิทธิ์เดิมทั้งหมดของ User คนนี้ออกก่อน
        $stmt_del_roles = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt_del_roles->execute([$user_id]);

        // ขั้นที่สอง: เพิ่มสิทธิ์ใหม่ตามที่ติ๊กเลือก (ถ้ามีการเลือกสิทธิ์)
        if (!empty($selected_roles)) {
            $sql_ins_role = "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)";
            $stmt_ins_role = $pdo->prepare($sql_ins_role);
            foreach ($selected_roles as $role_id) {
                $stmt_ins_role->execute([$user_id, $role_id]);
            }
        }

        // ถ้าทุกอย่างโอเค ยืนยันการแก้ไขข้อมูลทั้งหมด
        $pdo->commit();

        echo "<script>
                alert('อัปเดตข้อมูลบุคลากรเรียบร้อยแล้ว');
                window.location.href = 'users.php';
              </script>";

    } catch (Exception $e) {
        // หากเกิดข้อผิดพลาด ให้ยกเลิกคำสั่งทั้งหมด (Rollback)
        $pdo->rollBack();
        echo "<script>
                alert('เกิดข้อผิดพลาด: " . $e->getMessage() . "');
                window.history.back();
              </script>";
    }

} else {
    // หากพยายามเข้าหน้านี้โดยไม่ได้ส่งค่า POST มา
    header("Location: users.php");
    exit();
}
?>