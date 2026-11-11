<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

// 1. ดึงข้อมูลผู้ใช้งานเพื่อตรวจสอบลายเซ็น
$stmt_user = $pdo->prepare("SELECT signature_file FROM users WHERE user_id = ?");
$stmt_user->execute([$user_id]);
$user_data = $stmt_user->fetch();

// 2. ดึงประเภทการลาทั้งหมด
$leave_types = $pdo->query("SELECT * FROM leave_types ORDER BY type_id ASC")->fetchAll();
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2 text-purple"><i class="fa-solid fa-pen-nib me-2"></i>เขียนใบลา</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">ระบบลา</a></li>
                <li class="breadcrumb-item active">เขียนใบลาใหม่</li>
            </ol>
        </nav>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-5">
                <div class="card-header bg-purple text-white py-3">
                    <h5 class="mb-0"><i class="fa-solid fa-file-signature me-2"></i> รายละเอียดการขอลา</h5>
                </div>
                <div class="card-body p-4">
                    
                    <?php if (empty($user_data['signature_file'])): ?>
                        <div class="alert alert-warning border-warning shadow-sm p-4">
                            <div class="d-flex">
                                <i class="fa-solid fa-triangle-exclamation fs-2 me-3"></i>
                                <div>
                                    <h5 class="alert-heading">ยังไม่ได้อัปโหลดลายเซ็น!</h5>
                                    <p>คุณต้องอัปโหลดลายเซ็นดิจิทัลก่อนจึงจะสามารถส่งใบลาได้ เพื่อใช้ในการประทับตราในเอกสารอิเล็กทรอนิกส์</p>
                                    <hr>
                                    <a href="../../profile.php" class="btn btn-warning">ไปที่หน้าตั้งค่าโปรไฟล์</a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>

                        <form action="save_leave.php" method="POST" id="leaveForm" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">ประเภทการลา <span class="text-danger">*</span></label>
                                    <select name="type_id" id="type_id" class="form-select form-select-lg border-purple" required>
                                        <option value="">-- เลือกประเภทการลา --</option>
                                        <?php foreach ($leave_types as $type): ?>
                                            <option value="<?php echo $type['type_id']; ?>">
                                                <?php echo $type['type_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">เริ่มวันที่ <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">ถึงวันที่ <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                                </div>

                                <div class="col-md-12">
                                    <div id="daysCount" class="small fw-bold text-muted px-2"></div>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-bold">เหตุผลการลา / ติดต่อได้ที่</label>
                                    <textarea name="reason" class="form-control" rows="3" placeholder="ระบุเหตุผลและสถานที่ติดต่อระหว่างลา" required></textarea>
                                </div>

                                <div id="attachmentSection" class="col-md-12 d-none">
                                    <div class="p-3 border border-danger border-opacity-25 rounded bg-danger-subtle">
                                        <label class="form-label fw-bold text-danger">
                                            <i class="fa-solid fa-paperclip me-1"></i> ใบรับรองแพทย์ <span class="text-danger">*</span>
                                        </label>
                                        <input type="file" name="attachment" id="attachmentInput" class="form-control border-danger" accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="form-text text-danger small mt-1">
                                            * จำเป็นสำหรับการลาป่วยตั้งแต่ 3 วันขึ้นไป (รองรับ PDF, JPG, PNG)
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-12 mt-4">
                                    <div class="p-3 border rounded bg-light text-center shadow-sm">
                                        <p class="small text-muted mb-2">ลายเซ็นดิจิทัลที่จะประทับลงในใบลา:</p>
                                        <img src="../../uploads/signatures/<?php echo $user_data['signature_file']; ?>" 
                                             alt="Signature" class="img-fluid" style="max-height: 80px;">
                                        <div class="mt-2 small text-purple fw-bold">
                                            <?php echo $_SESSION['full_name']; ?>
                                        </div>
                                    </div>
                                    <div class="form-check mt-3">
                                        <input class="form-check-input border-purple" type="checkbox" id="confirmSign" required>
                                        <label class="form-check-label small" for="confirmSign">
                                            ข้าพเจ้าขอยืนยันว่าข้อมูลข้างต้นเป็นความจริง และยินยอมให้ใช้ลายเซ็นดิจิทัลนี้ประกอบการขอลา
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-12 text-center mt-4">
                                    <hr>
                                    <button type="button" onclick="history.back()" class="btn btn-light px-4 me-2">ยกเลิก</button>
                                    <button type="submit" class="btn btn-purple px-5 shadow-sm">
                                        <i class="fa-solid fa-paper-plane me-2"></i> ส่งใบลาไปยังงานบุคคล
                                    </button>
                                </div>
                            </div>
                        </form>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; }
    .bg-danger-subtle { background-color: #fff5f5; }
    .border-purple { border-color: #4b0082; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #39048f; color: white; }
    .form-select-lg { font-size: 1.1rem; }
    #daysCount { transition: all 0.3s; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const typeSelect = document.getElementById('type_id');
    const attachmentSection = document.getElementById('attachmentSection');
    const attachmentInput = document.getElementById('attachmentInput');
    const daysDisplay = document.getElementById('daysCount');

    function checkConditions() {
        const start = new Date(startDateInput.value);
        const end = new Date(endDateInput.value);
        const typeText = typeSelect.options[typeSelect.selectedIndex].text;

        if (startDateInput.value && endDateInput.value) {
            // คำนวณจำนวนวัน (รวมวันเริ่มและวันสุดท้าย)
            const diffTime = end - start;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

            if (diffDays > 0) {
                daysDisplay.innerHTML = `<i class="fa-solid fa-calendar-day me-1"></i> รวมจำนวนวันลา: <span class="text-purple">${diffDays} วัน</span>`;
                
                // ตรวจสอบเงื่อนไข: เป็นลาป่วย และ จำนวนวัน >= 3
                if (typeText.includes('ลาป่วย') && diffDays >= 3) {
                    attachmentSection.classList.remove('d-none');
                    attachmentInput.setAttribute('required', 'required');
                } else {
                    attachmentSection.classList.add('d-none');
                    attachmentInput.removeAttribute('required');
                }
            } else {
                daysDisplay.innerHTML = `<span class="text-danger small">วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น</span>`;
                attachmentSection.classList.add('d-none');
            }
        }
    }

    startDateInput.addEventListener('change', checkConditions);
    endDateInput.addEventListener('change', checkConditions);
    typeSelect.addEventListener('change', checkConditions);
});
</script>

<?php require_once '../../includes/footer.php'; ?>