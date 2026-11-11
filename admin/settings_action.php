<?php
/**
 * ไฟล์: settings_action.php
 * หน้าที่: ประมวลผลข้อมูลการตั้งค่าระบบ (Logic Only)
 */

session_start();
require_once '../auth/session_check.php';
require_once '../config/db.php';

// 1. ตรวจสอบสิทธิ์ Admin
if (!hasRole(['admin'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์จัดการส่วนนี้";
    $_SESSION['msg_type'] = "danger";
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // --- ส่วนจัดการโมดูลระบบ (แบบเก่า ทีละตัว) ---
        if ($action == 'toggle_module') {
            $module_name = $_POST['module_name'];
            $status = ($_POST['module_status'] == '1') ? '1' : '0';

            $check = $pdo->prepare("SELECT setting_name FROM system_settings WHERE setting_name = ?");
            $check->execute([$module_name]);
            
            if ($check->rowCount() > 0) {
                $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = ?");
                $stmt->execute([$status, $module_name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value, description) VALUES (?, ?, 'Module Toggle')");
                $stmt->execute([$module_name, $status]);
            }
            $_SESSION['msg'] = "ตั้งค่าสถานะระบบเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }
        
        // --- ส่วนจัดการโมดูลระบบ (แบบใหม่ อัปเดตพร้อมกันทั้งหมด) ---
        elseif ($action == 'update_modules') {
            if (isset($_POST['modules']) && is_array($_POST['modules'])) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value, description) 
                                       VALUES (?, ?, 'Module Toggle') 
                                       ON DUPLICATE KEY UPDATE setting_value = ?");
                
                foreach ($_POST['modules'] as $module_name => $status) {
                    $status_val = ($status == '1') ? '1' : '0';
                    $stmt->execute([$module_name, $status_val, $status_val]);
                }
                
                $_SESSION['msg'] = "อัปเดตสถานะการเปิด/ปิดโมดูลเรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            }
        }

        // --- ส่วนปีงบประมาณ (Fiscal Years) ---
        elseif ($action == 'add_fiscal_year') {
            $year_name = trim($_POST['year_name']);
            if (!empty($year_name)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM fiscal_years WHERE year_name = ?");
                $stmt->execute([$year_name]);
                if($stmt->fetchColumn() == 0) {
                    $count = $pdo->query("SELECT COUNT(*) FROM fiscal_years")->fetchColumn();
                    $is_active = ($count == 0) ? 1 : 0; 
                    $stmt = $pdo->prepare("INSERT INTO fiscal_years (year_name, is_active) VALUES (?, ?)");
                    $stmt->execute([$year_name, $is_active]);
                    $_SESSION['msg'] = "เพิ่มปีงบประมาณ '$year_name' สำเร็จ";
                    $_SESSION['msg_type'] = "success";
                } else {
                    $_SESSION['msg'] = "ปีงบประมาณนี้มีอยู่ในระบบแล้ว";
                    $_SESSION['msg_type'] = "warning";
                }
            }
        } elseif ($action == 'delete_fiscal_year') {
            $year_name = $_POST['year_name'];
            $stmt = $pdo->prepare("DELETE FROM fiscal_years WHERE year_name = ?");
            $stmt->execute([$year_name]);
            $_SESSION['msg'] = "ลบปีงบประมาณเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        } elseif ($action == 'set_active_fiscal_year') {
            $year_name = $_POST['year_name'];
            $pdo->query("UPDATE fiscal_years SET is_active = 0"); 
            $stmt = $pdo->prepare("UPDATE fiscal_years SET is_active = 1 WHERE year_name = ?"); 
            $stmt->execute([$year_name]);
            $_SESSION['msg'] = "ตั้งค่า '$year_name' เป็นปีงบประมาณปัจจุบันเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }

        // --- ส่วนประเภทการลา ---
        elseif ($action == 'add_leave_type') {
            $type_name = trim($_POST['type_name']);
            if (!empty($type_name)) {
                $stmt = $pdo->prepare("INSERT INTO leave_types (type_name) VALUES (?)");
                $stmt->execute([$type_name]);
                $_SESSION['msg'] = "เพิ่มประเภทการลา '$type_name' สำเร็จ";
                $_SESSION['msg_type'] = "success";
            }
        } elseif ($action == 'delete_leave_type') {
            $type_id = $_POST['type_id'];
            $stmt = $pdo->prepare("DELETE FROM leave_types WHERE type_id = ?");
            $stmt->execute([$type_id]);
            $_SESSION['msg'] = "ลบประเภทการลาเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }

        // --- ส่วนหน่วยงาน ---
        elseif ($action == 'add_dept') {
            $dept_name = trim($_POST['dept_name']);
            if (!empty($dept_name)) {
                $stmt = $pdo->prepare("INSERT INTO departments (dept_name) VALUES (?)");
                $stmt->execute([$dept_name]);
                $_SESSION['msg'] = "เพิ่มหน่วยงาน '$dept_name' สำเร็จ";
                $_SESSION['msg_type'] = "success";
            }
        } elseif ($action == 'delete_dept') {
            $dept_id = $_POST['dept_id'];
            $stmt = $pdo->prepare("DELETE FROM departments WHERE dept_id = ?");
            $stmt->execute([$dept_id]);
            $_SESSION['msg'] = "ลบหน่วยงานเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }

        // 💡 [อัปเกรดใหม่] ส่วนหัวข้องาน (รองรับการใส่หลายบรรทัดพร้อมกัน)
        elseif ($action == 'add_task') {
            $role_id = $_POST['role_id'];
            $task_names_raw = $_POST['task_name']; // รับค่ากล่องข้อความมาแบบก้อนใหญ่
            
            if (!empty($task_names_raw) && !empty($role_id)) {
                // ใช้คำสั่ง explode เพื่อแยกข้อความออกเป็น Array ตามการขึ้นบรรทัดใหม่ (\n)
                $task_lines = explode("\n", $task_names_raw);
                
                $stmt = $pdo->prepare("INSERT INTO work_tasks (role_id, task_name) VALUES (?, ?)");
                $inserted_count = 0; // ตัวแปรนับว่าเพิ่มไปกี่รายการ

                foreach ($task_lines as $line) {
                    $task_name = trim($line); // ตัดช่องว่างและเว้นวรรคส่วนเกินทิ้ง
                    
                    // เช็คว่าถ้าบรรทัดนั้นมีตัวอักษรอยู่จริง (ไม่ใช่บรรทัดว่าง) ค่อยบันทึก
                    if (!empty($task_name)) {
                        $stmt->execute([$role_id, $task_name]);
                        $inserted_count++;
                    }
                }
                
                if ($inserted_count > 0) {
                    $_SESSION['msg'] = "เพิ่มหัวข้องานใหม่สำเร็จ จำนวน $inserted_count รายการ";
                    $_SESSION['msg_type'] = "success";
                } else {
                    $_SESSION['msg'] = "ไม่พบข้อความหัวข้องานที่ถูกต้อง";
                    $_SESSION['msg_type'] = "warning";
                }
            }
        } 
        // แก้ไขหัวข้องาน (Edit Task)
        elseif ($action == 'edit_task') {
            $task_id = $_POST['task_id'];
            $role_id = $_POST['role_id'];
            $task_name = trim($_POST['task_name']);

            if (!empty($task_id) && !empty($task_name) && !empty($role_id)) {
                $stmt = $pdo->prepare("UPDATE work_tasks SET role_id = ?, task_name = ? WHERE task_id = ?");
                $stmt->execute([$role_id, $task_name, $task_id]);
                
                $_SESSION['msg'] = "แก้ไขหัวข้องานเรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            } else {
                $_SESSION['msg'] = "กรุณากรอกข้อมูลให้ครบถ้วน";
                $_SESSION['msg_type'] = "warning";
            }
        } 
        elseif ($action == 'delete_task') {
            $task_id = $_POST['task_id'];
            $stmt = $pdo->prepare("DELETE FROM work_tasks WHERE task_id = ?");
            $stmt->execute([$task_id]);
            $_SESSION['msg'] = "ลบหัวข้องานเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }

        // --- ส่วนเวลาเข้างาน ---
        elseif ($action == 'update_worklog_settings') {
            $open_time = $_POST['open_time'];
            $late_time = $_POST['late_time'];

            $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES ('worklog_open_time', ?) 
                           ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$open_time, $open_time]);
            
            $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES ('worklog_late_time', ?) 
                           ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$late_time, $late_time]);

            $_SESSION['msg'] = "อัปเดตเวลาการบันทึกงานเรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }

    } catch (PDOException $e) {
        $_SESSION['msg_type'] = "danger";
        if ($e->getCode() == '23000') {
            $_SESSION['msg'] = "ไม่สามารถลบข้อมูลได้ เนื่องจากมีการนำข้อมูลนี้ไปใช้งานในระบบแล้ว";
        } else {
            $_SESSION['msg'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Redirect กลับไปหน้า Settings พร้อมให้เปิดแท็บเดิมที่ค้างอยู่
header("Location: settings.php?tab=tasks");
exit();