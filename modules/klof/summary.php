<?php
/**
 * ไฟล์: modules/klof/summary.php
 * Dashboard สรุปชั่วโมง กยศ.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "../../config/db.php";
require_once "../../auth/session_check.php";

// ตรวจสอบสิทธิ์การเข้าใช้งาน
if (!hasRole([
    'admin',
    'งานพัฒนานิสิต',
    'หัวหน้าสำนักงาน',
    'รองคณบดีฝ่ายพัฒนานิสิต',
    'คณบดี'
])) {
    $_SESSION['error'] = "คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้";
    header("Location: ../../index.php");
    exit;
}

/* =========================
   SUMMARY STATS (ปรับใช้ SQL ชุดใหม่ตามที่ระบุ)
========================= */
$sql_stats = "
    SELECT 
        COUNT(DISTINCT s.student_id) as total_students,
        SUM(CASE WHEN l.status = 'approved' THEN l.hours_count ELSE 0 END) as total_approved_hours,
        COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_count
    FROM students s
    LEFT JOIN student_klof_logs l ON s.student_id = l.student_id
    WHERE s.is_klof_student = 'yes'
";

$stats = $pdo->query($sql_stats)->fetch(PDO::FETCH_ASSOC);
// Fallback ป้องกันกรณีไม่มีข้อมูลในระบบ
$stats['total_students'] = $stats['total_students'] ?? 0;
$stats['pending_count'] = $stats['pending_count'] ?? 0;

/* =========================
   STUDENT TABLE (ปรับเงื่อนไขให้ล้อตามชุดตัวแปรสถิติด้านบน)
========================= */
$sql_students = "
SELECT 
    s.student_id,
    s.first_name,
    s.last_name,
    s.major,
    s.year_level,
    COALESCE(SUM(CASE WHEN l.status='approved' THEN l.hours_count ELSE 0 END), 0) as current_hours
FROM students s
LEFT JOIN student_klof_logs l ON s.student_id = l.student_id
WHERE s.is_klof_student = 'yes'
GROUP BY s.student_id, s.first_name, s.last_name, s.major, s.year_level
ORDER BY current_hours DESC, s.student_id ASC
";

$stmt = $pdo->prepare($sql_students);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   CALCULATE METRICS
========================= */
$passed_students = array_filter($students, fn($s) => $s['current_hours'] >= 36);
$incomplete_students = array_filter($students, fn($s) => $s['current_hours'] < 36);

$total_active_students = count($students);
$avg_hours = $total_active_students > 0 
    ? round(array_sum(array_column($students, 'current_hours')) / $total_active_students, 2)
    : 0;

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">

    <div class="pt-4 pb-2 mb-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-chart-pie text-primary me-2"></i>
                    Dashboard ชั่วโมง กยศ.
                </h3>
                <p class="text-muted mb-0">
                    ภาพรวมระบบกิจกรรมและชั่วโมงจิตอาสาของนิสิตผู้กู้ยืม
                </p>
            </div>

            <div class="mt-3 mt-md-0">
                <button id="reloadBtn" class="btn btn-outline-primary rounded-pill px-3">
                    <i class="fa-solid fa-rotate me-1"></i> รีเฟรชข้อมูล
                </button>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold">นิสิตผู้กู้ทั้งหมด</div>
                            <h2 class="fw-bold text-primary mb-0 mt-1">
                                <?= number_format($stats['total_students']) ?> <span class="fs-6 fw-normal text-muted">ราย</span>
                            </h2>
                        </div>
                        <div class="bg-primary-subtle text-primary p-3 rounded-4">
                            <i class="fa-solid fa-users fa-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold">ชั่วโมงครบเกณฑ์ (≥ 36 ชม.)</div>
                            <h2 class="fw-bold text-success mb-0 mt-1">
                                <?= number_format(count($passed_students)) ?> <span class="fs-6 fw-normal text-muted">ราย</span>
                            </h2>
                        </div>
                        <div class="bg-success-subtle text-success p-3 rounded-4">
                            <i class="fa-solid fa-circle-check fa-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold">รายการรออนุมัติ</div>
                            <h2 class="fw-bold text-warning mb-0 mt-1">
                                <?= number_format($stats['pending_count']) ?> <span class="fs-6 fw-normal text-muted">รายการ</span>
                            </h2>
                        </div>
                        <div class="bg-warning-subtle text-warning p-3 rounded-4">
                            <i class="fa-solid fa-clock fa-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold">ชั่วโมงเฉลี่ย / คน</div>
                            <h2 class="fw-bold text-danger mb-0 mt-1">
                                <?= number_format($avg_hours, 2) ?> <span class="fs-6 fw-normal text-muted">ชม.</span>
                            </h2>
                        </div>
                        <div class="bg-danger-subtle text-danger p-3 rounded-4">
                            <i class="fa-solid fa-chart-line fa-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-4 px-4">
            <div class="row g-3 align-items-center">
                <div class="col-lg-3">
                    <h5 class="fw-bold mb-0">
                        <i class="fa-solid fa-table me-2 text-primary"></i>รายชื่อและสถานะรายบุคคล
                    </h5>
                </div>
                <div class="col-lg-3 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" id="customSearch" class="form-control bg-light border-start-0 rounded-end-pill" placeholder="ค้นหารหัส, ชื่อ-นามสกุล, สาขา...">
                    </div>
                </div>
                <div class="col-lg-2 col-md-3">
                    <select id="statusFilter" class="form-select form-select-sm rounded-pill bg-light">
                        <option value="">ทุกสถานะผลลัพธ์</option>
                        <option value="ครบเกณฑ์">ครบเกณฑ์</option>
                        <option value="สะสมเพิ่ม">สะสมเพิ่ม</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <select id="yearFilter" class="form-select form-select-sm rounded-pill bg-light">
                        <option value="">ทุกชั้นปี</option>
                        <option value="1">ปี 1</option>
                        <option value="2">ปี 2</option>
                        <option value="3">ปี 3</option>
                        <option value="4">ปี 4</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-2 text-end">
                    <button id="exportCSV" class="btn btn-sm btn-success rounded-pill w-100 fw-bold">
                        <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                    </button>
                </div>
            </div>
        </div>

        <div class="card-body px-4 pb-4 pt-0">
            <div class="table-responsive">
                <table id="summaryTable" class="table table-hover align-middle mb-0">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th>รหัสนิสิต</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>สาขาวิชา</th>
                            <th>ชั้นปี</th>
                            <th width="240">ความคืบหน้าโครงการ</th>
                            <th class="text-center">ชั่วโมงสะสม</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center">รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $stu): 
                            $hours = (float)$stu['current_hours'];
                            $percent = min(($hours / 36) * 100, 100);
                        ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($stu['student_id']) ?></td>
                            <td><?= htmlspecialchars($stu['first_name'] . ' ' . $stu['last_name']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($stu['major']) ?></td>
                            <td data-order="<?= htmlspecialchars($stu['year_level']) ?>"><span class="badge bg-light text-dark border">ปี <?= htmlspecialchars($stu['year_level']) ?></span></td>
                            <td>
                                <div class="d-flex justify-content-between extra-small mb-1" style="font-size: 0.75rem;">
                                    <span class="fw-bold text-secondary"><?= $hours ?> / 36 ชม.</span>
                                    <span class="text-muted"><?= round($percent) ?>%</span>
                                </div>
                                <div class="progress" style="height: 7px; border-radius: 10px;">
                                    <div class="progress-bar <?= $hours >= 36 ? 'bg-success' : 'bg-warning' ?>" 
                                         role="progressbar" 
                                         style="width: <?= $percent ?>%" 
                                         aria-valuenow="<?= $percent ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            </td>
                            <td class="text-center fw-bold <?= $hours >= 36 ? 'text-success' : 'text-dark' ?>"><?= $hours ?></td>
                            <td class="text-center">
                                <?php if($hours >= 36): ?>
                                    <span class="badge bg-success-subtle text-success rounded-pill px-3 py-1">
                                        <i class="fa-solid fa-circle-check me-1"></i>ครบเกณฑ์
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-3 py-1">
                                        <i class="fa-solid fa-spinner fa-spin-pulse me-1"></i>สะสมเพิ่ม
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="student_details.php?id=<?= urlencode($stu['student_id']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                    <i class="fa-solid fa-eye me-1"></i> ดูรายละเอียด
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<?php 
// โหลดระบบ Footer หลักของเว็บไซต์ (ซึ่งปกติจะมีสคริปต์ jQuery หลักอยู่แล้ว)
require_once '../../includes/footer.php'; 
?>

<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script>
$(document).ready(function () {
    // ใช้ destroy: true บังคับรีเซ็ตตารางเดิมที่ถูกสร้างอัตโนมัติจาก footer เพื่อตั้งค่าปุ่ม Export และภาษาไทยตามเงื่อนไขเรา
    let table = $('#summaryTable').DataTable({
        destroy: true,
        responsive: true,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
        },
        pageLength: 10,
        order: [[5, 'desc']], 
        dom: 'rtip', 
        buttons: [
            {
                extend: 'excelHtml5',
                title: 'รายงานสรุปชั่วโมงกิจกรรม_กยศ_' + new Date().toISOString().slice(0,10),
                text: 'Export Excel',
                exportOptions: {
                    columns: [0, 1, 2, 3, 5, 6] 
                }
            }
        ]
    });

    /* 1. ค้นหาอิสระ (รหัส, ชื่อ-นามสกุล, สาขาวิชา) */
    $('#customSearch').on('keyup', function () {
        table.search(this.value).draw();
    });

    /* 2. กรองตามสถานะผลลัพธ์ (ครบเกณฑ์ / สะสมเพิ่ม) */
    $('#statusFilter').on('change', function () {
        table.column(6).search(this.value).draw();
    });

    /* 3. กรองตามชั้นปีการศึกษา */
    $('#yearFilter').on('change', function () {
        if(this.value === "") {
            table.column(3).search("").draw();
        } else {
            table.column(3).search(this.value).draw();
        }
    });

    /* 4. สั่งกดเรียกฟังก์ชันส่งออก Excel */
    $('#exportCSV').click(function () {
        table.button('.buttons-excel').trigger();
    });

    /* 5. รีเฟรชข้อมูลหน้าเว็บ */
    $('#reloadBtn').click(function () {
        location.reload();
    });
});
</script>