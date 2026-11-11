<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config/db.php';
require_once '../../auth/session_check.php';

$user_id = $_SESSION['user_id'];
$roles = $_SESSION['roles'] ?? [];

// ดึงปีที่ระบุใน URL หรือค่าเริ่มต้นจากตาราง fiscal_years
$stmt_active = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_year = $stmt_active->fetchColumn();
$selected_year = $_GET['year'] ?? $active_year;

// ดึงปีทั้งหมดที่มีในระบบเพื่อสร้าง Dropdown
$stmt_years = $pdo->query("SELECT DISTINCT funding_year FROM research_grants WHERE funding_year IS NOT NULL AND funding_year != '' ORDER BY funding_year DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($active_year, $available_years) && $active_year) {
    $available_years[] = $active_year;
    rsort($available_years);
}

// สร้าง Query เงื่อนไข
if ($selected_year === 'all') {
    $stmt = $pdo->prepare("SELECT * FROM research_grants WHERE researcher_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM research_grants WHERE researcher_id = ? AND funding_year = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id, $selected_year]);
}
$grants = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-microscope text-primary me-2"></i> ทุนวิจัยของฉัน
            </h1>
            <p class="text-muted mb-0">รายการทุนวิจัย โครงการและงบประมาณที่คุณเป็นหัวหน้าโครงการ</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 d-flex gap-2">
            <form method="GET" action="" class="d-flex me-2">
                <select name="year" class="form-select rounded-pill shadow-sm fw-bold border-secondary" onchange="this.form.submit()">
                    <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>-- ทุกปีงบประมาณ --</option>
                    <?php foreach($available_years as $y): ?>
                        <option value="<?= htmlspecialchars($y) ?>" <?= $selected_year == $y ? 'selected' : '' ?>>
                            ปีงบประมาณ <?= htmlspecialchars($y) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="create.php" class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold">
                <i class="fa-solid fa-plus me-1"></i> เพิ่มรายการทุนวิจัย
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4 pt-3">
            <div class="table-responsive">
                <table id="grantsTable" class="table table-hover align-middle w-100">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th>ปีที่ขอทุน</th>
                            <th>เลขที่สัญญา</th>
                            <th>ชื่อโครงการ</th>
                            <th>แหล่งงานวิจัย</th>
                            <th class="text-end">งบประมาณ</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($grants as $row): 
                            $status = $row['status'];
                            $badge = 'bg-secondary';
                            if ($status == 'อนุมัติแล้ว' || $status == 'เสร็จสิ้น') $badge = 'bg-success';
                            if ($status == 'กำลังดำเนินการ') $badge = 'bg-primary';
                            if ($status == 'รอประเมิน' || $status == 'รออนุมัติ') $badge = 'bg-warning text-dark';
                            if ($status == 'ยกเลิก' || $status == 'ปฏิเสธ') $badge = 'bg-danger';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['funding_year'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['contract_no'] ?? '-') ?></td>
                            <td class="fw-bold text-dark text-wrap" style="max-width: 250px;"><?= htmlspecialchars($row['project_name']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($row['funding_source'] ?? '-') ?></td>
                            <td class="text-end fw-bold text-success"><?= number_format($row['budget'], 2) ?></td>
                            <td class="text-center">
                                <span class="badge <?= $badge ?> rounded-pill px-3 py-1"><?= htmlspecialchars($status) ?></span>
                            </td>
                            <td class="text-center">
                                <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info rounded-circle icon-btn" title="ดูรายละเอียด"><i class="fa-solid fa-eye"></i></a>
                                <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning rounded-circle icon-btn" title="แก้ไข"><i class="fa-solid fa-pen"></i></a>
                                <a href="delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger rounded-circle icon-btn" title="ลบ" onclick="return confirm('ยืนยันการลบรายการนี้?');"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#grantsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json',
        },
        order: [[0, 'desc']]
    });
});
</script>

<style>
    .icon-btn { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
</style>

<?php require_once '../../includes/footer.php'; ?>
