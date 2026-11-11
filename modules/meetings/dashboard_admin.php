<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เข้าได้เฉพาะผู้บริหารและแอดมิน (หัวหน้าสำนักงาน, รองบริหาร, คณบดี, admin)
$exec_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($exec_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: สำหรับผู้บริหารและแอดมินเท่านั้น (หัวหน้าสำนักงาน, รองบริหาร, คณบดี, admin)'); window.location.href='index.php';</script>";
    exit();
}

// ---------------------------------------------------------
// ดึงข้อมูลสถิติภาพรวม
// ---------------------------------------------------------
$current_year = date('Y');
$stats = [
    'total_this_year' => 0,
    'completed' => 0,
    'upcoming' => 0,
    'total_all' => 0
];

try {
    // จำนวนทั้งหมดในปีนี้
    $stmt_yr = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE YEAR(meeting_date) = ?");
    $stmt_yr->execute([$current_year]);
    $stats['total_this_year'] = $stmt_yr->fetchColumn();

    // จำนวนที่เสร็จสิ้นแล้วทั้งหมด
    $stats['completed'] = $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'completed'")->fetchColumn();
    
    // จำนวนที่จะถึง (กำลังประชุม หรือ รอการประชุม)
    $stats['upcoming'] = $pdo->query("SELECT COUNT(*) FROM meetings WHERE status IN ('upcoming', 'in_progress')")->fetchColumn();
    
    // จำนวนทั้งหมดในระบบ
    $stats['total_all'] = $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn();
    
} catch (PDOException $e) {}

// ---------------------------------------------------------
// ดึงรายการการประชุมทั้งหมด (เรียงจากใหม่ไปเก่า ให้ผู้บริหารมองเห็นทั้งหมด)
// ---------------------------------------------------------
$meetings = [];
try {
    $stmt_all = $pdo->query("
        SELECT m.*, 
               (SELECT COUNT(*) FROM meeting_agendas WHERE meeting_id = m.meeting_id) as agenda_count,
               (SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = m.meeting_id) as total_attendees,
               (SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = m.meeting_id AND attendance_status = 'attending') as attending_count
        FROM meetings m 
        ORDER BY m.meeting_date DESC, m.start_time DESC
    ");
    $meetings = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}


require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

function thaiDateFull($date) {
    if (empty($date) || $date == '0000-00-00') return '-';
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $timestamp = strtotime($date);
    return date("j", $timestamp) . " " . $months[date("n", $timestamp)] . " " . (date("Y", $timestamp) + 543);
}

function getMeetingStatusBadge($status) {
    if ($status == 'upcoming') return '<span class="badge bg-primary rounded-pill px-3 py-2"><i class="fa-solid fa-calendar-check me-1"></i>กำลังจะมาถึง</span>';
    if ($status == 'in_progress') return '<span class="badge bg-warning text-dark rounded-pill px-3 py-2"><i class="fa-solid fa-spinner fa-spin me-1"></i>กำลังดำเนินการ</span>';
    if ($status == 'completed') return '<span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-check-double me-1"></i>เสร็จสิ้นแล้ว</span>';
    if ($status == 'cancelled') return '<span class="badge bg-danger rounded-pill px-3 py-2"><i class="fa-solid fa-ban me-1"></i>ยกเลิก</span>';
    if ($status == 'draft') return '<span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-2"><i class="fa-solid fa-file-pen me-1"></i>แบบร่าง (Draft)</span>';
    return '<span class="badge bg-secondary rounded-pill px-3 py-2">ไม่ทราบสถานะ</span>';
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap align-items-end pt-4 pb-3 mb-4 border-bottom gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted"><i class="fa-solid fa-handshake me-1"></i>ระบบการประชุม</a></li>
                    <li class="breadcrumb-item active text-purple fw-bold" aria-current="page">แดชบอร์ดผู้บริหาร</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h2 fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-purple me-2"></i>แดชบอร์ดผู้บริหาร (ศูนย์รวมการประชุมทั้งหมด)</h1>
            </div>
            <p class="text-muted small mb-0 mt-1">แสดงภาพรวม สถิติ และรายการการประชุมทั้งหมดในคณะ สำหรับ: หัวหน้าสำนักงาน / รองบริหาร / คณบดี / Admin</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-shield-halved me-1"></i> สิทธิ์ผู้บริหาร & Admin
            </span>
            <a href="index.php" class="btn btn-sm btn-light border rounded-pill px-3 py-2 fw-semibold text-secondary shadow-xs">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าการประชุม
            </a>
        </div>
    </div>

    <!-- Overview Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card h-100 p-4 border-0 shadow-sm rounded-4 hover-lift">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold text-muted small text-uppercase">ประชุมปีนี้ (<?php echo $current_year + 543; ?>)</span>
                    <div class="icon-circle bg-purple bg-opacity-10 text-purple" style="width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-calendar-day fs-5"></i></div>
                </div>
                <h2 class="fw-bold text-dark mb-0"><?php echo $stats['total_this_year']; ?> <span class="fs-6 text-muted fw-normal">ครั้ง</span></h2>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card h-100 p-4 border-0 shadow-sm rounded-4 hover-lift border-bottom border-success border-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold text-muted small text-uppercase">ประชุมที่เสร็จสิ้นแล้ว</span>
                    <div class="icon-circle bg-success bg-opacity-10 text-success" style="width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-check-double fs-5"></i></div>
                </div>
                <h2 class="fw-bold text-success mb-0"><?php echo $stats['completed']; ?> <span class="fs-6 text-muted fw-normal">ครั้ง</span></h2>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card h-100 p-4 border-0 shadow-sm rounded-4 hover-lift">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold text-muted small text-uppercase">กำลังจะมาถึง</span>
                    <div class="icon-circle bg-primary bg-opacity-10 text-primary" style="width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-clock fs-5"></i></div>
                </div>
                <h2 class="fw-bold text-primary mb-0"><?php echo $stats['upcoming']; ?> <span class="fs-6 text-muted fw-normal">ครั้ง</span></h2>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card h-100 p-4 border-0 shadow-sm rounded-4 hover-lift text-white" style="background: linear-gradient(135deg, #4b0082 0%, #2a004d 100%);">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold text-white-50 small text-uppercase">ประวัติทั้งหมด (All Time)</span>
                    <div class="icon-circle bg-white bg-opacity-25 text-white" style="width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-database fs-5"></i></div>
                </div>
                <h2 class="fw-bold text-white mb-0"><?php echo $stats['total_all']; ?> <span class="fs-6 text-white-50 fw-normal">ครั้ง</span></h2>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white border-bottom p-4">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list text-muted me-2"></i>รายการประชุมทั้งหมด (Archive List)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="archiveTable">
                    <thead class="table-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4 py-3">วันที่ / เวลา</th>
                            <th>การประชุม</th>
                            <th class="text-center">ผู้เข้าร่วม</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-end pe-4">ดาวน์โหลด / รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($meetings)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fa-3x opacity-25 mb-3"></i>
                                    <h6>ยังไม่มีประวัติการประชุมในระบบ</h6>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($meetings as $m): ?>
                                <?php 
                                    $attendance_rate = ($m['total_attendees'] > 0) ? round(($m['attending_count'] / $m['total_attendees']) * 100) : 0;
                                    $has_minutes = !empty($m['minutes_content']);
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark"><?php echo thaiDateFull($m['meeting_date']); ?></div>
                                        <div class="extra-small text-muted"><i class="fa-regular fa-clock me-1"></i> <?php echo date('H:i', strtotime($m['start_time'])); ?> - <?php echo date('H:i', strtotime($m['end_time'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-purple mb-1">
                                            <span class="badge bg-purple-subtle text-purple border me-1">ครั้งที่ <?php echo htmlspecialchars($m['meeting_no']); ?></span>
                                            <?php echo htmlspecialchars($m['title']); ?>
                                        </div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-layer-group me-1"></i> มีวาระพิจารณา <?php echo $m['agenda_count']; ?> วาระ
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="fw-bold text-dark small"><?php echo $m['attending_count']; ?> <span class="text-muted fw-normal">/ <?php echo $m['total_attendees']; ?></span></div>
                                        <div class="progress mt-1 mx-auto bg-light" style="height: 5px; width: 60px;">
                                            <div class="progress-bar <?php echo $attendance_rate >= 80 ? 'bg-success' : ($attendance_rate >= 50 ? 'bg-warning' : 'bg-danger'); ?>" style="width: <?php echo $attendance_rate; ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getMeetingStatusBadge($m['status']); ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($m['status'] === 'in_progress'): ?>
                                            <a href="meeting_room.php?id=<?php echo $m['meeting_id']; ?>" target="_blank" class="btn btn-sm btn-success rounded-pill fw-bold shadow-xs px-3 me-1" title="เข้าสู่ห้องประชุมแบบเรียลไทม์">
                                                <i class="fa-solid fa-door-open me-1"></i> ห้องประชุม
                                            </a>
                                        <?php endif; ?>

                                        <?php if($has_minutes): ?>
                                            <a href="export_word.php?id=<?php echo $m['meeting_id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill fw-bold shadow-sm px-3 me-1" title="ดาวน์โหลดรายงานการประชุม">
                                                <i class="fa-solid fa-file-word me-1"></i> โหลด .doc
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light rounded-pill fw-bold text-muted px-3 me-1 disabled" title="ยังไม่มีการจัดทำรายงาน">
                                                <i class="fa-solid fa-file-word opacity-50"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <a href="view.php?id=<?php echo $m['meeting_id']; ?>" class="btn btn-sm btn-purple text-white rounded-pill fw-bold shadow-sm px-3">
                                            รายละเอียด <i class="fa-solid fa-arrow-right ms-1"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082 !important; }
    .bg-purple { background-color: #4b0082 !important; color: white !important; }
    .bg-purple-subtle { background-color: #f3e5f5 !important; }
    .btn-purple { background-color: #4b0082; color: white; border: none; }
    .btn-purple:hover { background-color: #39048f; color: white; }
    .hover-lift { transition: transform 0.2s, box-shadow 0.2s; }
    .hover-lift:hover { transform: translateY(-5px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08)!important; }
    .extra-small { font-size: 0.75rem; }
</style>

<!-- DataTables สำหรับคลังการประชุม -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#archiveTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
            },
            "order": [], // ปิดการเรียงลำดับเริ่มต้น (ใช้ที่คิวรีมาอยู่แล้ว)
            "columnDefs": [
                { "orderable": false, "targets": [4] } // ปิดการเรียงลำดับคอลัมน์ปุ่ม
            ],
            "pageLength": 25
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
