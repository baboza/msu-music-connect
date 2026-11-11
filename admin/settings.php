<?php
/**
 * ไฟล์: settings.php
 * หน้าที่: หน้าหลักสำหรับการตั้งค่าระบบ
 */

require_once '../auth/session_check.php';
require_once '../config/db.php';

// 1. ตรวจสอบสิทธิ์ Admin
if (!hasRole(['admin'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์ใช้งานส่วนนี้'); window.location.href='../index.php';</script>";
    exit();
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';

// 2. ดึงข้อความแจ้งเตือน
$msg = $_SESSION['msg'] ?? '';
$msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// 3. เตรียมข้อมูล
try {
    // สร้างตารางปีงบประมาณอัตโนมัติหากยังไม่มี
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_years (
        year_name VARCHAR(4) PRIMARY KEY,
        is_active TINYINT(1) DEFAULT 0
    )");
    
    // ดึงข้อมูลปีงบประมาณ
    $fiscal_years = $pdo->query("SELECT * FROM fiscal_years ORDER BY year_name DESC")->fetchAll();

    $leave_types = $pdo->query("SELECT * FROM leave_types ORDER BY type_id ASC")->fetchAll();
    $departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll();
    $roles_list = $pdo->query("SELECT MIN(role_id) as role_id, role_name FROM roles GROUP BY role_name ORDER BY role_name ASC")->fetchAll();
    $tasks_list = $pdo->query("SELECT t.*, r.role_name FROM work_tasks t JOIN roles r ON t.role_id = r.role_id ORDER BY r.role_name ASC, t.task_id ASC")->fetchAll();

    $settings_query = $pdo->query("SELECT setting_name, setting_value FROM system_settings");
    $settings_data = $settings_query->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $open_time = $settings_data['worklog_open_time'] ?? '08:30';
    $late_time = $settings_data['worklog_late_time'] ?? '11:59';
    
    // โหลดสถานะการตั้งค่าของทุกโมดูล
    $modules = [
        'module_cv'          => $settings_data['module_cv'] ?? '1',
        'module_leave'       => $settings_data['module_leave'] ?? '1',
        'module_worklog'     => $settings_data['module_worklog'] ?? '1',
        'module_projects'    => $settings_data['module_projects'] ?? '1',
        'module_meeting'     => $settings_data['module_meeting'] ?? '1',
        'module_training'    => $settings_data['module_training'] ?? '1',
        'module_supplies'    => $settings_data['module_supplies'] ?? '1',
        'module_procurement' => $settings_data['module_procurement'] ?? '1', 
        'module_finance'     => $settings_data['module_finance'] ?? '1', 
        'module_km'          => $settings_data['module_km'] ?? '1',
        // 💡 [เพิ่มใหม่] โหลดสถานะของระบบ E-Service ฝั่งนิสิต
        'eservice_open_course'  => $settings_data['eservice_open_course'] ?? '1', 
        'eservice_reserve_seat' => $settings_data['eservice_reserve_seat'] ?? '1'
    ];

} catch (PDOException $e) {
    die("เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage());
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-screwdriver-wrench me-2"></i>ตั้งค่าระบบ (System Settings)</h1>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm border-0 border-start border-<?php echo $msg_type; ?> border-4" role="alert">
            <i class="fa-solid <?php echo ($msg_type == 'success') ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?> me-2"></i>
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4 border-purple border-bottom-2" id="settingsTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" data-bs-toggle="tab" data-bs-target="#modules" type="button" role="tab">
                <i class="fa-solid fa-toggle-on me-1"></i> โมดูลระบบ
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#fiscal" type="button" role="tab">
                <i class="fa-solid fa-calendar-days me-1"></i> ปีงบประมาณ
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#tasks" type="button" role="tab">
                <i class="fa-solid fa-list-check me-1"></i> หัวข้องาน
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#worklog" type="button" role="tab">
                <i class="fa-solid fa-clock me-1"></i> เวลาเข้างาน
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#leave" type="button" role="tab">
                <i class="fa-solid fa-file-pen me-1"></i> ประเภทการลา
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#dept" type="button" role="tab">
                <i class="fa-solid fa-building me-1"></i> หน่วยงาน
            </button>
        </li>
    </ul>

    <div class="tab-content" id="settingsTabContent">
        <div class="tab-pane fade show active" id="modules" role="tabpanel">
            <?php include 'tabs/tab_modules.php'; ?>
        </div>

        <div class="tab-pane fade" id="fiscal" role="tabpanel">
            <?php include 'tabs/tab_fiscal_year.php'; ?>
        </div>

        <div class="tab-pane fade" id="tasks" role="tabpanel">
            <?php include 'tabs/tab_tasks.php'; ?>
        </div>
        <div class="tab-pane fade" id="worklog" role="tabpanel">
            <?php include 'tabs/tab_worklog.php'; ?>
        </div>
        <div class="tab-pane fade" id="leave" role="tabpanel">
            <?php include 'tabs/tab_leave.php'; ?>
        </div>
        <div class="tab-pane fade" id="dept" role="tabpanel">
            <?php include 'tabs/tab_dept.php'; ?>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
    .border-purple { border-color: #4b0082 !important; }
    .text-gold { color: #c5a028; }
    .bg-gold-subtle { background-color: #fcf4db; }
    .btn-purple { background-color: #4b0082; color: white; border: none; transition: 0.2s; }
    .btn-purple:hover { background-color: #39048f; color: white; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    .nav-tabs .nav-link { color: #6c757d; border: none; padding: 12px 20px; transition: 0.3s; }
    .nav-tabs .nav-link:hover { color: #4b0082; background: #f8f9fa; border-radius: 8px 8px 0 0; }
    .nav-tabs .nav-link.active { color: #4b0082; background-color: transparent; border-bottom: 3px solid #4b0082; }
</style>

<?php require_once '../includes/footer.php'; ?>