<?php
session_start();
require_once '../../config/db.php';
require_once '../../auth/session_check.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}
$id = $_GET['id'];

$stmt = $pdo->prepare("
    SELECT r.*, u.first_name, u.last_name 
    FROM research_grants r 
    LEFT JOIN users u ON r.researcher_id = u.user_id 
    WHERE r.id = ?
");
$stmt->execute([$id]);
$grant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grant) {
    die("ไม่พบข้อมูลทุนวิจัย");
}

$research_admin_roles = ['admin', 'งานวิจัย', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี'];
$is_admin = hasRole($research_admin_roles);
$can_edit = $is_admin || $grant['researcher_id'] == $_SESSION['user_id'];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="pt-4 pb-2 mb-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">รายละเอียดโครงการ</h1>
            <p class="text-muted mb-0">ข้อมูลทุนวิจัย: <?= htmlspecialchars($grant['project_name']) ?></p>
        </div>
        <div>
            <?php if($can_edit): ?>
            <a href="edit.php?id=<?= $grant['id'] ?>" class="btn btn-warning rounded-pill px-3 shadow-sm text-dark fw-bold me-2">
                <i class="fa-solid fa-pen me-1"></i> แก้ไขข้อมูล
            </a>
            <?php endif; ?>
            <a href="javascript:history.back()" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-info-circle me-2"></i>ข้อมูลทั่วไป</h5>
                </div>
                <div class="card-body p-4">
                    <table class="table table-borderless">
                        <tbody>
                            <tr>
                                <th width="200" class="text-muted">ชื่อโครงการ</th>
                                <td class="fw-bold fs-5 text-dark"><?= htmlspecialchars($grant['project_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ปีที่ขอทุน</th>
                                <td><?= htmlspecialchars($grant['funding_year'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">เลขที่สัญญา</th>
                                <td><?= htmlspecialchars($grant['contract_no'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">แหล่งงานวิจัย</th>
                                <td><?= htmlspecialchars($grant['funding_source'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">หัวหน้าโครงการ</th>
                                <td><?= htmlspecialchars($grant['first_name'] . ' ' . $grant['last_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ผู้ร่วมวิจัย</th>
                                <td><?= htmlspecialchars($grant['co_researchers'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">สถานะ</th>
                                <td>
                                    <?php 
                                        $status = $grant['status'];
                                        $badge = 'bg-secondary';
                                        if ($status == 'อนุมัติแล้ว' || $status == 'เสร็จสิ้น') $badge = 'bg-success';
                                        if ($status == 'กำลังดำเนินการ') $badge = 'bg-primary';
                                        if ($status == 'รอประเมิน' || $status == 'รออนุมัติ') $badge = 'bg-warning text-dark';
                                        if ($status == 'ยกเลิก' || $status == 'ปฏิเสธ') $badge = 'bg-danger';
                                    ?>
                                    <span class="badge <?= $badge ?> rounded-pill px-3 py-1 fs-6"><?= htmlspecialchars($status) ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-success mb-0"><i class="fa-solid fa-wallet me-2"></i>งบประมาณ</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <small class="text-muted fw-bold d-block mb-1">งบประมาณรวม</small>
                        <h3 class="text-success fw-bold mb-0"><?= number_format($grant['budget'], 2) ?> <small class="fs-6 text-muted fw-normal">บาท</small></h3>
                    </div>
                    <div class="mb-4">
                        <small class="text-muted fw-bold d-block mb-1">ผลการเบิกจ่าย (งวดที่เบิก)</small>
                        <h5 class="text-dark fw-bold mb-0"><?= htmlspecialchars($grant['disbursement_result'] ?: '-') ?></h5>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">งวดที่ 1</span>
                        <span class="fw-bold"><?= number_format($grant['installment_1'], 2) ?> บาท</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">งวดที่ 2</span>
                        <span class="fw-bold"><?= number_format($grant['installment_2'], 2) ?> บาท</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">งวดที่ 3</span>
                        <span class="fw-bold"><?= number_format($grant['installment_3'], 2) ?> บาท</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-warning mb-0"><i class="fa-solid fa-clock me-2"></i>ระยะเวลา</h5>
                </div>
                <div class="card-body p-4">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <th width="200" class="text-muted">ระยะเวลาดำเนินโครงการ</th>
                                <td class="fw-bold"><?= htmlspecialchars($grant['extension_period'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ระยะเวลาขยาย (รอบ 1)</th>
                                <td><?= htmlspecialchars($grant['extension_1'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ระยะเวลาขยาย (รอบ 2)</th>
                                <td><?= htmlspecialchars($grant['extension_2'] ?? '-') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-info mb-0"><i class="fa-solid fa-book-open me-2"></i>ผลงานตีพิมพ์ / เผยแพร่</h5>
                </div>
                <div class="card-body p-4">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <th width="150" class="text-muted">ชื่อบทความ</th>
                                <td class="fw-bold text-wrap"><?= htmlspecialchars($grant['publication'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Scopus</th>
                                <td><?= htmlspecialchars($grant['scopus'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">TCI</th>
                                <td><?= htmlspecialchars($grant['tci'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ERIC</th>
                                <td><?= htmlspecialchars($grant['eric'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">DOI</th>
                                <td><?= htmlspecialchars($grant['doi'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Fulltext Report</th>
                                <td>
                                    <?php if(!empty($grant['fulltext_report'])): ?>
                                        <a href="<?= htmlspecialchars($grant['fulltext_report']) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="fa-solid fa-link me-1"></i> เปิดดูเอกสาร</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>
