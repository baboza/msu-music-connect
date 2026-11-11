<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$user_id = $_SESSION['user_id'];

// ดึงข้อมูลการลาของผู้ใช้งานรายนี้ (l.* จะดึงคอลัมน์ attachment มาด้วยอัตโนมัติ)
$sql = "SELECT l.*, t.type_name 
        FROM leave_requests l
        JOIN leave_types t ON l.type_id = t.type_id
        WHERE l.user_id = ?
        ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$leaves = $stmt->fetchAll();

// ฟังก์ชันแสดงป้ายสถานะและขั้นตอนปัจจุบันตาม Flow ที่กำหนด
function getStepDetails($step, $status) {
    if ($status == 'rejected') return ['label' => 'ไม่อนุมัติ', 'class' => 'bg-danger text-white', 'icon' => 'fa-circle-xmark'];
    if ($status == 'approved') return ['label' => 'อนุมัติเสร็จสมบูรณ์', 'class' => 'bg-success text-white', 'icon' => 'fa-circle-check'];
    // ดักทั้ง canceled และ cancelled
    if ($status == 'canceled' || $status == 'cancelled') return ['label' => 'ยกเลิกการลา', 'class' => 'bg-secondary text-white', 'icon' => 'fa-ban'];
    
    $steps = [
        1 => ['label' => 'งานบุคคลตรวจสอบ', 'icon' => 'fa-magnifying-glass'],
        2 => ['label' => 'หัวหน้าสำนักงานพิจารณา', 'icon' => 'fa-file-signature'],
        3 => ['label' => 'รองบริหารพิจารณา', 'icon' => 'fa-user-tie'],
        4 => ['label' => 'คณบดี/รักษาการพิจารณา', 'icon' => 'fa-pen-fancy'],
    ];
    
    return [
        'label' => $steps[$step]['label'] ?? 'รอดำเนินการ',
        'class' => 'bg-warning text-dark',
        'icon' => $steps[$step]['icon'] ?? 'fa-spinner'
    ];
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2 text-purple fw-bold"><i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติการลา</h1>
        <a href="create.php" class="btn btn-gold shadow-sm px-4 rounded-pill">
            <i class="fa-solid fa-plus-circle me-1"></i> ยื่นใบลาใหม่
        </a>
    </div>

    <?php if (isset($_SESSION['msg_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4 border-start border-success border-4" role="alert">
            <i class="fa-solid fa-check-circle me-2 text-success"></i> <?php echo $_SESSION['msg_success']; unset($_SESSION['msg_success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
            <div class="avatar-icon bg-purple-subtle text-purple rounded me-2">
                <i class="fa-solid fa-list-ul"></i>
            </div>
            <h6 class="mb-0 fw-bold text-dark">รายการลาทั้งหมดของคุณ</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr class="small text-muted text-uppercase">
                            <th class="ps-4" width="15%">วันที่ยื่น</th>
                            <th width="20%">ประเภทการลา</th>
                            <th width="25%">ช่วงวันที่ลา / เหตุผล</th>
                            <th width="25%">สถานะปัจจุบัน</th>
                            <th class="text-center" width="15%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leaves)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <div class="text-muted mb-3"><i class="fa-solid fa-folder-open fa-3x opacity-25"></i></div>
                                    <h6 class="text-secondary fw-bold">ยังไม่มีประวัติการยื่นใบลา</h6>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leaves as $row): 
                                $stepInfo = getStepDetails($row['current_step'], $row['status']);
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark small"><?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
                                    <small class="text-muted extra-small"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($row['created_at'])); ?> น.</small>
                                </td>
                                <td>
                                    <span class="badge bg-purple-subtle text-purple px-2 py-1 rounded small">
                                        <?php echo htmlspecialchars($row['type_name']); ?>
                                    </span>
                                    <?php if (!empty($row['attachment'])): ?>
                                        <i class="fa-solid fa-paperclip text-info ms-1" title="มีไฟล์แนบ"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-dark fw-bold mb-1">
                                        <?php echo date('d/m/Y', strtotime($row['start_date'])); ?> <i class="fa-solid fa-arrow-right mx-1 text-muted" style="font-size: 0.6rem;"></i> <?php echo date('d/m/Y', strtotime($row['end_date'])); ?>
                                    </div>
                                    <div class="text-muted extra-small text-truncate" style="max-width: 250px;">
                                        <?php echo htmlspecialchars($row['reason']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center mb-1">
                                        <span class="badge <?php echo $stepInfo['class']; ?> px-2 py-1 rounded shadow-sm extra-small">
                                            <i class="fa-solid <?php echo $stepInfo['icon']; ?> me-1"></i>
                                            <?php echo $stepInfo['label']; ?>
                                        </span>
                                    </div>
                                    <?php if ($row['status'] == 'pending'): ?>
                                        <div class="progress" style="height: 4px; width: 140px; background-color: #f0f0f0;">
                                            <div class="progress-bar bg-purple progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo ($row['current_step']/4)*100; ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group shadow-sm rounded">
                                        <a href="view.php?id=<?php echo $row['leave_id']; ?>" class="btn btn-sm btn-white text-primary" title="ดูรายละเอียด">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        
                                        <?php if (!empty($row['attachment'])): ?>
                                            <a href="../../uploads/leaves/<?php echo htmlspecialchars($row['attachment']); ?>" target="_blank" class="btn btn-sm btn-white text-info" title="ดูไฟล์แนบ">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($row['current_step'] == 1 && $row['status'] == 'pending'): ?>
                                            <a href="cancel_leave.php?id=<?php echo $row['leave_id']; ?>" class="btn btn-sm btn-white text-danger" title="ลบใบลา">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
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
    .bg-purple { background-color: #4b0082 !important; }
    .text-purple { color: #4b0082 !important; }
    .bg-purple-subtle { background-color: #f3e5f5 !important; }
    .btn-gold { background-color: #c5a028; color: white; border: none; transition: all 0.3s ease; }
    .btn-gold:hover { background-color: #a88820; color: white; }
    .extra-small { font-size: 0.75rem; }
    .btn-white { background-color: #fff; border: 1px solid #e0e0e0; transition: all 0.2s; }
    .btn-white:hover { background-color: #f8f9fa; color: inherit; }
    .avatar-icon { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
</style>

<?php require_once '../../includes/footer.php'; ?>