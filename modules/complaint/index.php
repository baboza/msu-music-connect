<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$allowed_roles = ['admin', 'คณบดี', 'รักษาการคณบดี', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายพัฒนานิสิต'];
if (!function_exists('hasRole') || !hasRole($allowed_roles)) {
    echo "<script>alert('ปฏิเสธการเข้าถึง'); window.location.href='../../index.php';</script>";
    exit();
}

$stmt = $pdo->query("
    SELECT c.*, s.prefix_name as std_prefix, s.first_name as std_fname, s.last_name as std_lname
    FROM complaints c
    LEFT JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    ORDER BY c.created_at DESC
");
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h4 fw-bold text-dark mb-1"><i class="fa-solid fa-bullhorn text-danger me-2"></i>จัดการเรื่องร้องเรียนและข้อเสนอแนะ (สายตรงคณบดี)</h1>
            <p class="text-muted small mb-0">รายการเรื่องร้องเรียนและข้อเสนอแนะทั้งหมดในระบบ</p>
        </div>
        <a href="../../requests_list.php" class="btn btn-light border btn-sm px-3 fw-semibold">
            <i class="fa-solid fa-inbox me-1"></i> กล่องงานรวม
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">รหัส</th>
                            <th>วันที่แจ้ง</th>
                            <th>ผู้แจ้ง</th>
                            <th>หัวข้อเรื่องร้องเรียน</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center pe-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">ยังไม่มีรายการเรื่องร้องเรียนในระบบ</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $c): ?>
                                <?php
                                $st_badge = [
                                    'pending'     => ['bg-warning-subtle text-dark border', 'รอพิจารณา'],
                                    'in_progress' => ['bg-info-subtle text-info border', 'กำลังดำเนินการ'],
                                    'resolved'    => ['bg-success-subtle text-success border', 'ยุติเรื่อง/แก้ไขแล้ว'],
                                    'rejected'    => ['bg-danger-subtle text-danger border', 'ปฏิเสธ']
                                ][$c['status']] ?? ['bg-secondary-subtle text-secondary', $c['status']];
                                $sender_name = !empty($c['is_anonymous']) ? 'ไม่ประสงค์ออกนาม' : (!empty($c['std_fname']) ? ($c['std_prefix'] . $c['std_fname'] . ' ' . $c['std_lname']) : ($c['student_id'] ?: 'ไม่ระบุ'));
                                ?>
                                <tr>
                                    <td class="ps-4 fw-bold font-monospace">#CP-<?php echo str_pad((string)$c['complaint_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td class="small"><?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?></td>
                                    <td class="fw-semibold small"><?php echo htmlspecialchars($sender_name); ?></td>
                                    <td class="small fw-bold text-dark"><?php echo htmlspecialchars($c['subject']); ?></td>
                                    <td class="text-center"><span class="badge <?php echo $st_badge[0]; ?>"><?php echo $st_badge[1]; ?></span></td>
                                    <td class="text-center pe-4">
                                        <a href="view_complaint.php?id=<?php echo (int)$c['complaint_id']; ?>" class="btn btn-primary btn-sm px-3 fw-bold">ตรวจสอบ</a>
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

<?php require_once '../../includes/footer.php'; ?>
