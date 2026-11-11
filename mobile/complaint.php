<?php
require_once 'auth_check.php';

$filter_status = $_GET['status'] ?? 'all';
$is_admin = hasRole(['admin', 'คณบดี', 'รักษาการคณบดี', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายพัฒนานิสิต']);

// บันทึกการอัปเดตสถานะ (เฉพาะผู้บริหาร/แอดมิน)
$msg = '';
$msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status' && $is_admin) {
    $cid = intval($_POST['complaint_id'] ?? 0);
    $nstatus = $_POST['new_status'] ?? 'pending';

    try {
        $stmt_upd = $pdo->prepare("UPDATE complaints SET status = ?, updated_at = NOW() WHERE complaint_id = ?");
        $stmt_upd->execute([$nstatus, $cid]);
        $msg = "อัปเดตสถานะเรื่องร้องเรียนเรียบร้อยแล้ว";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// ดึงรายการร้องเรียน
try {
    $sql = "
        SELECT c.*, s.prefix_name as std_prefix, s.first_name as std_fname, s.last_name as std_lname, s.major as std_major
        FROM complaints c
        LEFT JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
    ";
    
    $params = [];
    if (!$is_admin) {
        // หากเป็นบุคลากร/นิสิตทั่วไป ให้ดูเฉพาะของตนเอง
        $sql .= " WHERE c.student_id = ? ";
        $params[] = $user_id;
        if ($filter_status !== 'all') {
            $sql .= " AND c.status = ? ";
            $params[] = $filter_status;
        }
    } else {
        if ($filter_status !== 'all') {
            $sql .= " WHERE c.status = ? ";
            $params[] = $filter_status;
        }
    }
    
    $sql .= " ORDER BY c.created_at DESC ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงจำนวนตามสถานะ
    $count_sql = "SELECT status, COUNT(*) as cnt FROM complaints " . (!$is_admin ? " WHERE student_id = ? " : "") . " GROUP BY status";
    $stmt_cnt = $pdo->prepare($count_sql);
    $stmt_cnt->execute(!$is_admin ? [$user_id] : []);
    $status_counts = [];
    while ($row = $stmt_cnt->fetch(PDO::FETCH_ASSOC)) {
        $status_counts[$row['status']] = $row['cnt'];
    }

} catch (PDOException $e) {
    $complaints = [];
    $status_counts = [];
}

function getComplaintStatusBadge($st) {
    switch ($st) {
        case 'pending': return ['label' => 'รอดำเนินการ', 'class' => 'bg-warning text-dark'];
        case 'in_progress': return ['label' => 'กำลังดำเนินการ', 'class' => 'bg-info text-white'];
        case 'resolved': return ['label' => 'ยุติเรื่องแล้ว', 'class' => 'bg-success text-white'];
        case 'rejected': return ['label' => 'ไม่รับพิจารณา', 'class' => 'bg-danger text-white'];
        default: return ['label' => 'รอดำเนินการ', 'class' => 'bg-secondary text-white'];
    }
}

require_once 'includes/header.php';
?>

<div class="page-container pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3 pt-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">ข้อเสนอแนะ / ร้องเรียน</h5>
                <small class="text-muted">สายตรงถึงคณบดีและผู้บริหาร</small>
            </div>
        </div>
        <a href="complaint_form.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold shadow-sm" hx-boost="true">
            <i class="fa-solid fa-pen-to-square me-1"></i> แจ้งเรื่อง
        </a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-3">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Segmented Filter Tabs -->
    <div class="segmented-control mb-3">
        <a href="complaint.php?status=all" class="segment-pill <?= $filter_status === 'all' ? 'active' : '' ?>" hx-boost="true">
            ทั้งหมด (<?= array_sum($status_counts) ?>)
        </a>
        <a href="complaint.php?status=pending" class="segment-pill <?= $filter_status === 'pending' ? 'active' : '' ?>" hx-boost="true">
            รอ (<?= $status_counts['pending'] ?? 0 ?>)
        </a>
        <a href="complaint.php?status=in_progress" class="segment-pill <?= $filter_status === 'in_progress' ? 'active' : '' ?>" hx-boost="true">
            ดำเนิน (<?= $status_counts['in_progress'] ?? 0 ?>)
        </a>
        <a href="complaint.php?status=resolved" class="segment-pill <?= $filter_status === 'resolved' ? 'active' : '' ?>" hx-boost="true">
            เสร็จ (<?= $status_counts['resolved'] ?? 0 ?>)
        </a>
    </div>

    <!-- Complaints List -->
    <?php if (empty($complaints)): ?>
        <div class="bento-box text-center py-5">
            <div class="avatar-circle mx-auto mb-3" style="width: 60px; height: 60px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary); display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                <i class="fa-regular fa-comment-dots fa-2x"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">ไม่พบข้อร้องเรียนหรือข้อเสนอแนะ</h6>
            <p class="text-muted small mb-0">ไม่มีรายการในหมวดหมู่ที่เลือกในขณะนี้</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($complaints as $c): 
                $st_info = getComplaintStatusBadge($c['status']);
            ?>
                <div class="bento-box p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge <?= $st_info['class'] ?> extra-small px-2 py-1">
                            <?= $st_info['label'] ?>
                        </span>
                        <span class="extra-small text-muted"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></span>
                    </div>

                    <h6 class="fw-bold text-dark mb-1">
                        <?= htmlspecialchars($c['subject']) ?>
                    </h6>

                    <p class="small text-secondary mb-2" style="white-space: pre-wrap; line-height: 1.5;">
                        <?= htmlspecialchars($c['details']) ?>
                    </p>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top extra-small text-muted">
                        <div>
                            <i class="fa-solid fa-user me-1 text-purple"></i>
                            <?php if (!empty($c['std_fname'])): ?>
                                <?= htmlspecialchars(($c['std_prefix'] ?? '') . $c['std_fname'] . ' ' . $c['std_lname']) ?>
                            <?php else: ?>
                                <?= htmlspecialchars($c['student_id'] ?: 'ไม่ระบุตัวตน') ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_admin): ?>
                            <!-- Admin Status Dropdown -->
                            <form method="POST" action="complaint.php?status=<?= htmlspecialchars($filter_status) ?>" class="d-flex align-items-center gap-1">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="complaint_id" value="<?= $c['complaint_id'] ?>">
                                <select name="new_status" class="form-select form-select-sm rounded-pill extra-small py-0" onchange="this.form.submit()">
                                    <option value="pending" <?= $c['status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                    <option value="in_progress" <?= $c['status'] === 'in_progress' ? 'selected' : '' ?>>กำลังดำเนินการ</option>
                                    <option value="resolved" <?= $c['status'] === 'resolved' ? 'selected' : '' ?>>ยุติเรื่องแล้ว</option>
                                    <option value="rejected" <?= $c['status'] === 'rejected' ? 'selected' : '' ?>>ไม่รับพิจารณา</option>
                                </select>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
