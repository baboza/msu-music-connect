<?php
session_start();
require_once '../../config/db.php';
require_once '../../auth/session_check.php';

// Fetch users for the dropdown
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name ASC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = $_POST['project_name'];
    $researcher_id = !empty($_POST['researcher_id']) ? $_POST['researcher_id'] : null;
    $contract_no = $_POST['contract_no'] ?? null;
    $co_researchers = $_POST['co_researchers'] ?? null;
    $funding_source = $_POST['funding_source'] ?? null;
    $extension_period = $_POST['extension_period'] ?? null;
    $extension_1 = $_POST['extension_1'] ?? null;
    $extension_2 = $_POST['extension_2'] ?? null;
    $budget = !empty($_POST['budget']) ? (float)$_POST['budget'] : 0;
    $disbursement_result = $_POST['disbursement_result'] ?? null;
    $installment_1 = !empty($_POST['installment_1']) ? (float)$_POST['installment_1'] : 0;
    $installment_2 = !empty($_POST['installment_2']) ? (float)$_POST['installment_2'] : 0;
    $installment_3 = !empty($_POST['installment_3']) ? (float)$_POST['installment_3'] : 0;
    $publication = $_POST['publication'] ?? null;
    $scopus = $_POST['scopus'] ?? null;
    $tci = $_POST['tci'] ?? null;
    $eric = $_POST['eric'] ?? null;
    $funding_year = $_POST['funding_year'] ?? null;
    $fulltext_report = $_POST['fulltext_report'] ?? null;
    $doi = $_POST['doi'] ?? null;
    $status = $_POST['status'] ?? 'รอประเมิน';

    $sql = "INSERT INTO research_grants (
        project_name, researcher_id, contract_no, co_researchers, funding_source,
        extension_period, extension_1, extension_2, budget, disbursement_result,
        installment_1, installment_2, installment_3, publication, scopus, tci, eric,
        funding_year, fulltext_report, doi, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $project_name, $researcher_id, $contract_no, $co_researchers, $funding_source,
            $extension_period, $extension_1, $extension_2, $budget, $disbursement_result,
            $installment_1, $installment_2, $installment_3, $publication, $scopus, $tci, $eric,
            $funding_year, $fulltext_report, $doi, $status
        ]);
        $_SESSION['success'] = "บันทึกข้อมูลทุนวิจัยเรียบร้อยแล้ว";
        
        $research_admin_roles = ['admin', 'งานวิจัย', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
        if (hasRole($research_admin_roles)) {
            header("Location: index.php");
        } else {
            header("Location: my_grants.php");
        }
        exit;
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="pt-4 pb-2 mb-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">เพิ่มข้อมูลทุนวิจัย</h1>
            <p class="text-muted mb-0">กรอกรายละเอียดโครงการและสถานะทุนวิจัย</p>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger rounded-4 shadow-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-file-contract me-2"></i>ข้อมูลโครงการทั่วไป</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label fw-bold">ชื่อโครงการ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="project_name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">ปีที่ขอทุน (พ.ศ.)</label>
                        <input type="text" class="form-control" name="funding_year" placeholder="เช่น 2566">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold">เลขที่สัญญา</label>
                        <input type="text" class="form-control" name="contract_no">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">แหล่งงานวิจัย (ทุนจาก)</label>
                        <input type="text" class="form-control" name="funding_source">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">หัวหน้าโครงการ <span class="text-danger">*</span></label>
                        <select class="form-select" name="researcher_id" required>
                            <option value="">-- เลือกหัวหน้าโครงการ --</option>
                            <?php foreach($users as $u): ?>
                                <option value="<?= htmlspecialchars($u['user_id']) ?>" <?= ($_SESSION['user_id'] == $u['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ผู้ร่วมวิจัย (หากมี)</label>
                        <input type="text" class="form-control" name="co_researchers" placeholder="ระบุชื่อผู้ร่วมวิจัย">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-success mb-0"><i class="fa-solid fa-money-bill-wave me-2"></i>งบประมาณและการเบิกจ่าย</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">งบประมาณรวม (บาท)</label>
                        <input type="number" step="0.01" class="form-control" name="budget" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ผลการเบิกจ่าย (ระบุงวดที่เบิก)</label>
                        <input type="text" class="form-control" name="disbursement_result" placeholder="เช่น งวดที่ 1, งวดที่ 2">
                    </div>
                    <div class="col-md-4">
                        <!-- Empty space or other field -->
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">การจัดสรรเงินวิจัย งวดที่ 1</label>
                        <input type="number" step="0.01" class="form-control bg-light" name="installment_1" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">การจัดสรรเงินวิจัย งวดที่ 2</label>
                        <input type="number" step="0.01" class="form-control bg-light" name="installment_2" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">การจัดสรรเงินวิจัย งวดที่ 3</label>
                        <input type="number" step="0.01" class="form-control bg-light" name="installment_3" value="0">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-warning mb-0"><i class="fa-solid fa-clock me-2"></i>ระยะเวลาและการขยายเวลา</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ระยะเวลาดำเนินโครงการ</label>
                        <input type="text" class="form-control" name="extension_period" placeholder="เช่น 1 ปี หรือ ระบุวันที่">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ระยะเวลาขยาย (รอบที่ 1)</label>
                        <input type="text" class="form-control" name="extension_1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ระยะเวลาขยาย (รอบที่ 2)</label>
                        <input type="text" class="form-control" name="extension_2">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-info mb-0"><i class="fa-solid fa-book-open me-2"></i>ผลงานตีพิมพ์และการเผยแพร่</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">การตีพิมพ์เผยแพร่ (ชื่อบทความ/วารสาร)</label>
                        <textarea class="form-control" name="publication" rows="2"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Scopus (Quartile/ข้อมูล)</label>
                        <input type="text" class="form-control" name="scopus">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">TCI (กลุ่ม)</label>
                        <input type="text" class="form-control" name="tci">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ERIC</label>
                        <input type="text" class="form-control" name="eric">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">รายงาน Fulltext (ลิงก์ URL)</label>
                        <input type="url" class="form-control" name="fulltext_report" placeholder="https://...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">DOI</label>
                        <input type="text" class="form-control" name="doi" placeholder="10.xxxx/xxxxx">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4 text-center">
                <label class="form-label fw-bold fs-5 d-block mb-3">สถานะของโครงการ</label>
                <div class="d-inline-block text-start" style="width: 300px;">
                    <select class="form-select form-select-lg fw-bold" name="status">
                        <option value="รอประเมิน">รอประเมิน</option>
                        <option value="กำลังดำเนินการ">กำลังดำเนินการ</option>
                        <option value="อนุมัติแล้ว">อนุมัติแล้ว</option>
                        <option value="เสร็จสิ้น">เสร็จสิ้น</option>
                        <option value="ยกเลิก">ยกเลิก</option>
                        <option value="ปฏิเสธ">ปฏิเสธ</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="text-end mb-5">
            <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow">
                <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูล
            </button>
        </div>
    </form>
</main>

<?php require_once '../../includes/footer.php'; ?>
