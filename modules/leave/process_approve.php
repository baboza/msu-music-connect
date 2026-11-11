<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. รับค่าตัวแปรจากฟอร์ม
    $leave_id = $_POST['leave_id'] ?? 0;
    $current_step = (int)($_POST['current_step'] ?? 0);
    $status = $_POST['status'] ?? ''; // ค่าจะเป็น 'approved' หรือ 'rejected'
    $comment = trim($_POST['comment'] ?? '');
    
    // ตัวนี้สำคัญมาก: ดักค่าว่าง ถ้าผู้พิจารณาขั้นที่ 2 ไม่ได้เลือกคณบดี ให้กำหนดค่าเป็น NULL
    $final_signer_id = !empty($_POST['final_signer_id']) ? $_POST['final_signer_id'] : NULL;
    
    $approver_id = $_SESSION['user_id'];

    // เช็คค่าว่างเบื้องต้น
    if (!$leave_id || !$status || !$current_step) {
        echo "<script>alert('ข้อมูลไม่ครบถ้วน กรุณาลองใหม่อีกครั้ง'); window.history.back();</script>";
        exit();
    }

    // ------------------------------------------------------------------
    // [SECURITY CHECK] ตรวจสอบความถูกต้องและสิทธิ์ก่อนบันทึก (สำคัญมาก)
    // ------------------------------------------------------------------
    // 💡 แก้ไข Error ตรงนี้: JOIN ตาราง users เพื่อดึง dept_id
    $stmt_leave = $pdo->prepare("
        SELECT l.user_id, l.current_step, l.status, l.final_signer_id, u.dept_id 
        FROM leave_requests l 
        JOIN users u ON l.user_id = u.user_id 
        WHERE l.leave_id = ?
    ");
    $stmt_leave->execute([$leave_id]);
    $leave = $stmt_leave->fetch();

    if (!$leave || $leave['status'] !== 'pending' || $leave['current_step'] != $current_step) {
        echo "<script>alert('สถานะใบลาไม่ถูกต้อง หรือมีการอนุมัติไปแล้ว'); window.location.href='view.php?id=$leave_id';</script>";
        exit();
    }

    // เช็คว่าผู้ขอลาเป็น "สายวิชาการ" หรือไม่
    $stmt_check_role = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = 1");
    $stmt_check_role->execute([$leave['user_id']]);
    $is_academic = ($stmt_check_role->fetchColumn() > 0) ? true : false;

    $can_approve = false;
    
    // ตรวจสอบสิทธิ์ว่าผู้กดอนุมัติ (Session) มีสิทธิ์ใน Step นั้นๆ จริงหรือไม่
    if ($current_step == 1) {
        $step1_roles = ['งานบุคคล', 'นักวิชาการพัสดุ', 'นักวิชาการศึกษา', 'บุคลากร', 'นักวิชาการเงินและบัญชี', 'เจ้าหน้าที่บริหารงานทั่วไป', 'นักวิเคราะห์นโยบายและแผน', 'นักวิชาการโสตทัศนศึกษา'];
        if (hasRole($step1_roles)) $can_approve = true;
    } elseif ($current_step == 2) {
        // 💡 แยกสายงานตรงนี้ เหมือนในหน้า View.php
        $approver_dept_id = 0;
        try {
            $stmt_ad = $pdo->prepare("SELECT dept_id FROM users WHERE user_id = ?");
            $stmt_ad->execute([$approver_id]);
            $approver_dept_id = (int)$stmt_ad->fetchColumn();
        } catch(Exception $e){}

        $my_head_depts = [];
        if (hasRole(['หัวหน้าภาควิชาดนตรีศึกษา'])) $my_head_depts[] = 12;
        if (hasRole(['หัวหน้าภาควิชาดุริยางคศาสตร์'])) $my_head_depts[] = 13;
        foreach ($_SESSION['roles'] ?? [] as $r) {
            if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
                if ($approver_dept_id > 0 && !in_array($approver_dept_id, $my_head_depts)) {
                    $my_head_depts[] = $approver_dept_id;
                }
            }
        }

        if (in_array($leave['dept_id'], $my_head_depts)) {
            $can_approve = true;
        } elseif (hasRole(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ']) && ($leave['dept_id'] == 11 || !$is_academic)) {
            $can_approve = true;
        }
    } elseif ($current_step == 3) {
        if (hasRole(['รองคณบดีฝ่ายบริหาร'])) $can_approve = true;
    } elseif ($current_step == 4) {
        if (!empty($leave['final_signer_id']) && $leave['final_signer_id'] == $approver_id) {
            $can_approve = true;
        } elseif (empty($leave['final_signer_id']) && hasRole(['คณบดี', 'รักษาการคณบดี'])) {
            $can_approve = true;
        }
    }

    // ถ้าแอบยิง Request มาโดยไม่มีสิทธิ์ ให้เด้งกลับทันที
    if (!$can_approve) {
        echo "<script>alert('คุณไม่มีสิทธิ์อนุมัติเอกสารในขั้นตอนนี้'); window.location.href='view.php?id=$leave_id';</script>";
        exit();
    }

    // ------------------------------------------------------------------
    // ดำเนินการบันทึกฐานข้อมูล
    // ------------------------------------------------------------------
    try {
        // เริ่มต้น Transaction
        $pdo->beginTransaction();

        // STEP 1: บันทึกประวัติการอนุมัติ
        $stmt_app = $pdo->prepare("
            INSERT INTO leave_approvals (leave_id, approver_id, step_order, approve_status, comment) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt_app->execute([$leave_id, $approver_id, $current_step, $status, $comment]);

        // STEP 2: อัปเดตสถานะหลักและเลื่อนขั้นในตาราง leave_requests
        if ($status === 'rejected') {
            
            // กรณี "ไม่อนุมัติ" -> เอกสารตีตก
            $stmt_update = $pdo->prepare("UPDATE leave_requests SET status = 'rejected' WHERE leave_id = ?");
            $stmt_update->execute([$leave_id]);
            
        } else {
            
            // กรณี "อนุมัติ / เห็นชอบ" -> เลื่อนขั้นตอนต่อไป
            $next_step = $current_step + 1;
            $new_status = 'pending';

            // ถ้ามาถึงสเต็ปที่ 4 (คณบดี) แล้วกดอนุมัติ -> ถือว่าใบลาอนุมัติสมบูรณ์ 100%
            if ($current_step == 4) {
                $next_step = 4;
                $new_status = 'approved';
            }

            // หากเป็น Step 2 (หัวหน้าสำนักงาน / หัวหน้าภาค) ให้อัปเดต final_signer_id 
            if ($current_step == 2) {
                $stmt_update = $pdo->prepare("UPDATE leave_requests SET current_step = ?, status = ?, final_signer_id = ? WHERE leave_id = ?");
                $stmt_update->execute([$next_step, $new_status, $final_signer_id, $leave_id]);
            } else {
                $stmt_update = $pdo->prepare("UPDATE leave_requests SET current_step = ?, status = ? WHERE leave_id = ?");
                $stmt_update->execute([$next_step, $new_status, $leave_id]);
            }
        }

        // ยืนยันการบันทึกข้อมูล
        $pdo->commit();

        echo "<script>
                alert('บันทึกผลการพิจารณาเรียบร้อยแล้ว!');
                window.location.href = 'view.php?id=" . htmlspecialchars($leave_id) . "';
              </script>";
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>
                alert('เกิดข้อผิดพลาดจากฐานข้อมูล: " . addslashes($e->getMessage()) . "');
                window.history.back();
              </script>";
        exit();
    }
} else {
    // หากเข้าหน้านี้โดยไม่ได้กดผ่านฟอร์ม ให้เด้งกลับ
    header("Location: approve_list.php");
    exit();
}
?>