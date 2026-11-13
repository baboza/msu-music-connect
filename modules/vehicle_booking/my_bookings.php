<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once 'setup_db.php';
require_once 'helpers.php';

$current_user_id = $_SESSION['user_id'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

$sql = "
    SELECT b.*, v.plate_number, v.model, v.brand, v.vehicle_type
    FROM vehicle_bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    WHERE b.user_id = ?
";
$params = [$current_user_id];

if ($status_filter !== 'all') {
    $sql .= " AND b.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY b.booking_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$my_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">

    <!-- Top Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 bg-white p-4 rounded-4 border shadow-xs">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้ารวมการจองรถ
                </a>
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 rounded-pill small">รายการของฉัน</span>
            </div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-list-check text-purple me-2"></i>รายการคำขอใช้รถยนต์ราชการของฉัน
            </h3>
            <p class="text-muted small mb-0">
                ติดตามผลการพิจารณา 3 ลำดับขั้น พิมพ์ใบขอใช้รถ และบันทึกผลการเดินทาง
            </p>
        </div>
        <div>
            <a href="create.php" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-plus me-1"></i> ขอใช้รถใหม่
            </a>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="card border-0 shadow-xs rounded-4 bg-white mb-4 p-2">
        <ul class="nav nav-pills nav-fill gap-2">
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'all' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=all">
                    ทั้งหมด
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'pending_head' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=pending_head">
                    รอหัวหน้าสำนักงาน
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'pending_deputy' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=pending_deputy">
                    รอรองบริหาร
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'approved' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=approved">
                    อนุมัติแล้ว
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'completed' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=completed">
                    เดินทางแล้ว
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill py-2 <?= $status_filter === 'rejected' ? 'active bg-purple text-white fw-bold' : 'text-dark' ?>" href="?status=rejected">
                    ไม่อนุมัติ
                </a>
            </li>
        </ul>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
        <div class="card-body p-0">
            <?php if (empty($my_bookings)): ?>
                <div class="text-center py-5">
                    <div class="rounded-circle bg-purple-subtle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px; height:70px;">
                        <i class="fa-solid fa-car-side text-purple fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark">ไม่พบประวัติการขอใช้รถ</h6>
                    <p class="text-muted small mb-3">ท่านยังไม่มีคำขอใช้รถยนต์ราชการในระบบ</p>
                    <a href="create.php" class="btn btn-purple rounded-pill px-4 btn-sm fw-semibold">
                        <i class="fa-solid fa-plus me-1"></i> ยื่นคำขอใช้รถทันที
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted extra-small">
                                <th class="ps-4" style="width: 140px;">รหัสคำขอ</th>
                                <th>รถที่ขอใช้</th>
                                <th>ภารกิจ & ปลายทาง</th>
                                <th style="width: 190px;">วันเวลาเดินทาง</th>
                                <th style="width: 160px;" class="text-center">สถานะคำขอ</th>
                                <th style="width: 170px;" class="text-end pe-4">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_bookings as $b): 
                                $badge = getVehicleBookingStatusBadge($b['status']);
                                $is_c2 = ($b['vehicle_type'] === 'cargo_instruments');
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <a href="view.php?id=<?= $b['booking_id'] ?>" class="fw-bold text-purple text-decoration-none">
                                            <?= htmlspecialchars($b['booking_code']) ?>
                                        </a>
                                        <div class="extra-small text-muted"><?= date('d/m/Y H:i', strtotime($b['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark small">
                                            <i class="fa-solid <?= $is_c2 ? 'fa-truck-front text-danger' : 'fa-van-shuttle text-purple' ?> me-1"></i>
                                            <?= htmlspecialchars($b['model']) ?>
                                        </div>
                                        <span class="badge bg-light text-dark border extra-small mt-1"><?= htmlspecialchars($b['plate_number']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($b['title']) ?></div>
                                        <div class="extra-small text-muted">
                                            <i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($b['destination']) ?>
                                            • <?= $b['passenger_count'] ?> คน
                                            <?php if (!empty($b['instruments_detail'])): ?>
                                                <span class="badge bg-warning text-dark ms-1 extra-small"><i class="fa-solid fa-guitar me-1"></i>มีเครื่องดนตรี</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= formatThaiDT($b['start_datetime']) ?></div>
                                        <div class="extra-small text-muted">ถึง <?= formatThaiDT($b['end_datetime']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $badge['class'] ?> rounded-pill px-3 py-1 extra-small">
                                            <i class="<?= $badge['icon'] ?> me-1"></i><?= $badge['label'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group shadow-2xs rounded-pill">
                                            <a href="view.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-light border" title="ดูรายละเอียด">
                                                <i class="fa-regular fa-eye text-purple"></i>
                                            </a>
                                            <a href="print.php?id=<?= $b['booking_id'] ?>" target="_blank" class="btn btn-sm btn-light border" title="พิมพ์ใบขอใช้รถ">
                                                <i class="fa-solid fa-print text-secondary"></i>
                                            </a>
                                            <?php if ($b['status'] === 'approved'): ?>
                                                <a href="trip_logs.php?booking_id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-light border" title="บันทึกค่าใช้จ่าย">
                                                    <i class="fa-solid fa-gas-pump text-success"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php require_once '../../includes/footer.php'; ?>
