<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$stmt_fy = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy = $stmt_fy->fetchColumn() ?: (date('Y') + 543);
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $training_hours = !empty($_POST['training_hours']) ? intval($_POST['training_hours']) : null;
        if (!$training_hours && !empty($_POST['start_date']) && !empty($_POST['end_date'])) {
            $s = new DateTime($_POST['start_date']);
            $e = new DateTime($_POST['end_date']);
            $days = $s->diff($e)->days + 1;
            $training_hours = max(6, $days * 6);
        }

        // จัดการอัปโหลดไฟล์แนบ
        $up_dir = __DIR__ . '/../../uploads/trainings/';
        if (!is_dir($up_dir)) {
            mkdir($up_dir, 0777, true);
        }
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'];

        $invitation_file = null;
        if (isset($_FILES['invitation_file']) && $_FILES['invitation_file']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['invitation_file']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $invitation_file = 'invite_' . $user_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['invitation_file']['tmp_name'], $up_dir . $invitation_file);
            }
        }

        $highway_map_file = null;
        if (isset($_FILES['highway_map_file']) && $_FILES['highway_map_file']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['highway_map_file']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $highway_map_file = 'map_' . $user_id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['highway_map_file']['tmp_name'], $up_dir . $highway_map_file);
            }
        }

        $travel_start_date = !empty($_POST['travel_start_date']) ? $_POST['travel_start_date'] : $_POST['start_date'];
        $travel_end_date = !empty($_POST['travel_end_date']) ? $_POST['travel_end_date'] : $_POST['end_date'];

        $stmt = $pdo->prepare("
            INSERT INTO training_reports (
                user_id, project_name, organizer, location, report_type,
                start_date, end_date, travel_start_date, travel_end_date,
                invitation_file, highway_map_file,
                training_hours, fiscal_year, status, current_step,
                est_budget_travel, est_budget_accom, est_budget_allowance, est_budget_regis, est_budget_other
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $_POST['project_name'],
            $_POST['organizer'],
            $_POST['location'],
            $_POST['report_type'] ?? 'ไปราชการ',
            $_POST['start_date'],
            $_POST['end_date'],
            $travel_start_date,
            $travel_end_date,
            $invitation_file,
            $highway_map_file,
            $training_hours,
            $active_fy,
            $_POST['est_budget_travel'] ?: 0,
            $_POST['est_budget_accom'] ?: 0,
            $_POST['est_budget_allowance'] ?: 0,
            $_POST['est_budget_regis'] ?: 0,
            $_POST['est_budget_other'] ?: 0
        ]);
        
        $_SESSION['msg'] = "ส่งคำขออนุมัติไปราชการ/อบรมเรียบร้อยแล้ว";
        $_SESSION['msg_type'] = "success";
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class='pt-4 pb-3 mb-4 border-bottom'>
        <h1 class='h2 text-purple fw-bold'><i class='fa-solid fa-plane-departure me-2'></i>แบบฟอร์มขออนุมัติเดินทางไปราชการ / อบรม</h1>
        <p class='text-muted mb-0'>กรอกข้อมูลเพื่อขออนุมัติจากผู้บังคับบัญชาตามลำดับขั้น</p>
    </div>

    <?php if(isset($error)): ?>
        <div class='alert alert-danger shadow-sm rounded-4'><i class='fa-solid fa-triangle-exclamation me-2'></i><?php echo $error; ?></div>
    <?php endif; ?>

    <form action='' method='POST' enctype='multipart/form-data' class='needs-validation' novalidate>
        
        <!-- ส่วนที่ 1: ข้อมูลโครงการและการเดินทาง -->
        <div class='card border-0 shadow-sm rounded-4 mb-4'>
            <div class='card-header bg-white border-bottom pt-4 px-4 pb-3'>
                <h5 class='fw-bold text-dark mb-0'><i class='fa-solid fa-circle-info text-primary me-2'></i>ส่วนที่ 1: ข้อมูลโครงการและการเดินทาง</h5>
            </div>
            <div class='card-body p-4'>
                <div class='row g-4'>
                    <div class='col-md-3'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ประเภท <span class='text-danger'>*</span></label>
                        <select name='report_type' class='form-select form-control-lg border-secondary border-opacity-25 bg-light' required>
                            <option value='สัมมนา'>สัมมนา</option>
                            <option value='อบรม'>อบรม</option>
                            <option value='ดูงาน'>ดูงาน</option>
                            <option value='ไปราชการ' selected>ไปราชการ</option>
                            <option value='อื่นๆ'>อื่นๆ</option>
                        </select>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ชื่อหลักสูตร / โครงการ <span class='text-danger'>*</span></label>
                        <input type='text' name='project_name' class='form-control form-control-lg border-secondary border-opacity-25 bg-light' required placeholder='ระบุชื่อหลักสูตร หรือชื่อโครงการที่ไปปฏิบัติงาน'>
                    </div>
                    <div class='col-md-3'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ประมาณการชั่วโมง (ชม.)</label>
                        <input type='number' name='training_hours' class='form-control form-control-lg border-secondary border-opacity-25 bg-light fw-bold text-purple' placeholder='อัตโนมัติ (6 ชม./วัน)'>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>หน่วยงานที่จัด <span class='text-danger'>*</span></label>
                        <input type='text' name='organizer' class='form-control border-secondary border-opacity-25 bg-light' required placeholder='ระบุชื่อหน่วยงานผู้จัดงาน / ผู้เชิญ'>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>สถานที่จัด <span class='text-danger'>*</span></label>
                        <input type='text' name='location' class='form-control border-secondary border-opacity-25 bg-light' required placeholder='ระบุสถานที่ โรงแรม จังหวัด'>
                    </div>

                    <!-- วันจัดโครงการ -->
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'><i class='fa-solid fa-calendar-check text-primary me-1'></i>วันจัดโครงการ (เริ่มต้น) <span class='text-danger'>*</span></label>
                        <input type='date' name='start_date' id='start_date' class='form-control border-secondary border-opacity-25 bg-light' required onchange='autoFillTravelDates()'>
                        <small class='text-muted' style='font-size: 0.75rem;'>วันเริ่มจัดงาน/ประชุม/อบรม</small>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'><i class='fa-solid fa-calendar-xmark text-primary me-1'></i>วันจัดโครงการ (สิ้นสุด) <span class='text-danger'>*</span></label>
                        <input type='date' name='end_date' id='end_date' class='form-control border-secondary border-opacity-25 bg-light' required onchange='autoFillTravelDates()'>
                        <small class='text-muted' style='font-size: 0.75rem;'>วันสิ้นสุดจัดงาน/ประชุม/อบรม</small>
                    </div>

                    <!-- กำหนดวันเดินทาง -->
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-purple small text-uppercase'><i class='fa-solid fa-car-side text-purple me-1'></i>กำหนดวันเดินทาง (วันออกเดินทาง) <span class='text-danger'>*</span></label>
                        <input type='date' name='travel_start_date' id='travel_start_date' class='form-control border-purple border-opacity-25 bg-light' required>
                        <small class='text-muted' style='font-size: 0.75rem;'>วันเริ่มต้นออกเดินทางไปปฏิบัติงาน</small>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-purple small text-uppercase'><i class='fa-solid fa-house-chimney text-purple me-1'></i>กำหนดวันเดินทาง (วันเดินทางกลับ) <span class='text-danger'>*</span></label>
                        <input type='date' name='travel_end_date' id='travel_end_date' class='form-control border-purple border-opacity-25 bg-light' required>
                        <small class='text-muted' style='font-size: 0.75rem;'>วันเดินทางกลับถึงหน่วยงาน/ที่พัก</small>
                    </div>

                    <!-- ช่องแนบไฟล์ -->
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'><i class='fa-solid fa-envelope-open-text text-danger me-1'></i>แนบไฟล์หนังสือเชิญ / กำหนดการ <span class='text-danger'>*</span></label>
                        <input type='file' name='invitation_file' class='form-control border-secondary border-opacity-25 bg-light' accept='.pdf,.jpg,.jpeg,.png,.doc,.docx' required>
                        <small class='text-muted' style='font-size: 0.75rem;'>รองรับไฟล์ PDF, JPG, PNG, DOCX</small>
                    </div>
                    <div class='col-md-6'>
                        <label class='form-label fw-bold text-dark small text-uppercase'><i class='fa-solid fa-map-location-dot text-success me-1'></i>แนบไฟล์แผนที่กรมทางหลวง (ระยะทาง)</label>
                        <input type='file' name='highway_map_file' class='form-control border-secondary border-opacity-25 bg-light' accept='.pdf,.jpg,.jpeg,.png,.doc,.docx'>
                        <small class='text-muted' style='font-size: 0.75rem;'>เอกสารแสดงระยะทางจากกรมทางหลวงเพื่อคำนวณเบิกค่าเดินทาง</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ส่วนที่ 2: ประมาณการค่าใช้จ่าย -->
        <div class='card border-0 shadow-sm rounded-4 mb-4'>
            <div class='card-header bg-white border-bottom pt-4 px-4 pb-3'>
                <h5 class='fw-bold text-dark mb-0'><i class='fa-solid fa-sack-dollar text-warning me-2'></i>ส่วนที่ 2: ประมาณการค่าใช้จ่าย (บาท)</h5>
            </div>
            <div class='card-body p-4'>
                <div class='row g-4'>
                    <div class='col-md-4'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ค่าเดินทาง</label>
                        <input type='number' step='0.01' name='est_budget_travel' class='form-control text-end border-secondary border-opacity-25 bg-light' value='0'>
                    </div>
                    <div class='col-md-4'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ค่าที่พัก</label>
                        <input type='number' step='0.01' name='est_budget_accom' class='form-control text-end border-secondary border-opacity-25 bg-light' value='0'>
                    </div>
                    <div class='col-md-4'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ค่าเบี้ยเลี้ยง</label>
                        <input type='number' step='0.01' name='est_budget_allowance' class='form-control text-end border-secondary border-opacity-25 bg-light' value='0'>
                    </div>
                    <div class='col-md-4'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ค่าลงทะเบียน</label>
                        <input type='number' step='0.01' name='est_budget_regis' class='form-control text-end border-secondary border-opacity-25 bg-light' value='0'>
                    </div>
                    <div class='col-md-4'>
                        <label class='form-label fw-bold text-dark small text-uppercase'>ค่าใช้จ่ายอื่นๆ</label>
                        <input type='number' step='0.01' name='est_budget_other' class='form-control text-end border-secondary border-opacity-25 bg-light' value='0'>
                    </div>
                </div>
            </div>
        </div>

        <div class='text-end mb-5'>
            <a href='index.php' class='btn btn-light btn-lg px-4 me-2 border'>ยกเลิก</a>
            <button type='submit' class='btn btn-purple btn-lg px-5 shadow-sm fw-bold'><i class='fa-solid fa-paper-plane me-2'></i>ส่งคำขออนุมัติ</button>
        </div>
    </form>
</main>

<script>
function autoFillTravelDates() {
    const sDate = document.getElementById('start_date').value;
    const eDate = document.getElementById('end_date').value;
    const tsDate = document.getElementById('travel_start_date');
    const teDate = document.getElementById('travel_end_date');
    
    if (sDate && !tsDate.value) {
        tsDate.value = sDate;
    }
    if (eDate && !teDate.value) {
        teDate.value = eDate;
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
