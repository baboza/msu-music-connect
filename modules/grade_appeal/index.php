<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$is_admin = in_array('admin', $_SESSION['roles'] ?? []);

// Fetch grade appeals for this instructor (or all if admin)
$sql = "SELECT ga.*, s.prefix_name, s.first_name, s.last_name 
        FROM grade_appeals ga
        LEFT JOIN students s ON ga.student_id = s.student_id
        WHERE ga.instructor_id = ? OR ? = 1
        ORDER BY ga.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $is_admin ? 1 : 0]);
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-scale-balanced me-2"></i>รายการอุทธรณ์คะแนน</h1>
            <p class="text-muted small mb-0">ประวัติและรายการคำร้องอุทธรณ์คะแนนทั้งหมดที่คุณรับผิดชอบ</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted small">
                        <tr>
                            <th>วันที่ยื่นเรื่อง</th>
                            <th>รหัสนิสิต / ชื่อ-สกุล</th>
                            <th>รหัสวิชา / ชื่อวิชา</th>
                            <th>ปีการศึกษา</th>
                            <th>สถานะ</th>
                            <th class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($appeals) > 0): ?>
                            <?php foreach ($appeals as $row): ?>
                                <tr>
                                    <td>
                                        <div class="text-dark fw-medium"><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></div>
                                        <div class="text-muted small"><?php echo date('H:i', strtotime($row['created_at'])); ?> น.</div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></div>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars(($row['prefix_name'] ?? '').($row['first_name'] ?? '').' '.($row['last_name'] ?? '')); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['course_code'] ?? ''); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($row['course_name'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(($row['academic_year'] ?? '') . '/' . ($row['semester'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] == 'pending'): ?>
                                            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="fa-regular fa-clock me-1"></i> รอพิจารณา</span>
                                        <?php elseif ($row['status'] == 'in_progress'): ?>
                                            <span class="badge bg-info text-white px-3 py-2 rounded-pill"><i class="fa-solid fa-spinner me-1"></i> กำลังดำเนินการ</span>
                                        <?php elseif ($row['status'] == 'approved'): ?>
                                            <span class="badge bg-success px-3 py-2 rounded-pill"><i class="fa-solid fa-check me-1"></i> ดำเนินการเรียบร้อย</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger px-3 py-2 rounded-pill"><i class="fa-solid fa-xmark me-1"></i> ปฏิเสธ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="view_appeal.php?id=<?php echo $row['appeal_id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                            <i class="fa-solid fa-eye me-1"></i> รายละเอียด
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-folder-open fa-3x mb-3 text-light"></i><br>
                                    ไม่มีรายการอุทธรณ์คะแนนในขณะนี้
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple-subtle { background-color: #f3e5f5; }
</style>

<?php require_once '../../includes/footer.php'; ?>
