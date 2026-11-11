<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดักจับ POST จากการกดอนุมัติ กยศ.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'approve_klof' || $_POST['action'] == 'reject_klof') {
        $log_id = $_POST['log_id'];
        $status = ($_POST['action'] == 'approve_klof') ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE student_klof_logs SET status = ? WHERE log_id = ?");
        $stmt->execute([$status, $log_id]);
        
        echo "<script>window.location.href='approvals.php';</script>";
        exit();
    }
}

$pending_leaves = [];
$pending_rooms = [];
$pending_repairs = [];
$pending_supplies = [];
$pending_klof = [];
$pending_course_requests = [];

try {
    // 1. ลางาน (leave_requests)
    $stmt = $pdo->query("SELECT l.*, t.type_name, u.first_name, u.last_name 
                         FROM leave_requests l 
                         JOIN leave_types t ON l.type_id = t.type_id 
                         JOIN users u ON l.user_id = u.user_id 
                         WHERE l.status = 'pending' 
                         ORDER BY l.created_at DESC");
    $pending_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. คำร้องวิชาการนิสิต (course_requests)
    $stmt = $pdo->query("SELECT cr.*, u.first_name, u.last_name, u.prefix_name 
                         FROM course_requests cr 
                         LEFT JOIN users u ON cr.student_id = u.user_id 
                         WHERE cr.status LIKE 'pending%' 
                         ORDER BY cr.created_at DESC");
    $pending_course_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. จองห้อง (room_bookings)
    $stmt = $pdo->query("SELECT b.*, r.room_name, u.first_name, u.last_name 
                         FROM room_bookings b 
                         JOIN rooms r ON b.room_id = r.room_id 
                         JOIN users u ON b.user_id = u.user_id 
                         WHERE b.status = 'pending' 
                         ORDER BY b.created_at DESC");
    $pending_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. แจ้งซ่อม (repair_requests)
    $stmt = $pdo->query("SELECT rr.*, r.room_name, u.first_name, u.last_name 
                         FROM repair_requests rr 
                         LEFT JOIN rooms r ON rr.room_id = r.room_id 
                         LEFT JOIN users u ON rr.reporter_id = u.user_id 
                         WHERE rr.status = 'pending' 
                         ORDER BY rr.created_at DESC");
    $pending_repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. เบิกพัสดุ (supplies_requests)
    $stmt = $pdo->query("SELECT sr.*, u.first_name, u.last_name 
                         FROM supplies_requests sr 
                         JOIN users u ON sr.user_id = u.user_id 
                         WHERE sr.head_status = 'pending' OR sr.supply_status = 'pending' 
                         ORDER BY sr.request_date DESC");
    $pending_supplies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. กยศ./จิตอาสา (student_klof_logs)
    $stmt = $pdo->query("SELECT l.*, s.first_name, s.last_name 
                         FROM student_klof_logs l 
                         JOIN students s ON l.student_id = s.student_id 
                         WHERE l.status = 'pending' 
                         ORDER BY l.created_at DESC");
    $pending_klof = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$total_pending = count($pending_leaves) + count($pending_course_requests) + count($pending_rooms) + count($pending_repairs) + count($pending_supplies) + count($pending_klof);

// Mapping คำร้องวิชาการ
function getCourseStepBadge($status) {
    switch ($status) {
        case 'pending_instructor': return ['badge' => 'bg-warning-subtle text-warning-emphasis', 'label' => 'รอผู้สอน'];
        case 'pending_advisor': return ['badge' => 'bg-info-subtle text-info-emphasis', 'label' => 'รอที่ปรึกษา'];
        case 'pending_head': return ['badge' => 'bg-primary-subtle text-primary-emphasis', 'label' => 'รอหัวหน้าภาค'];
        case 'pending_deputy': return ['badge' => 'bg-purple-subtle text-purple', 'label' => 'รอรองคณบดี'];
        case 'pending_academic': return ['badge' => 'bg-success-subtle text-success-emphasis', 'label' => 'รองานวิชาการ'];
        default: return ['badge' => 'bg-secondary-subtle text-secondary', 'label' => $status];
    }
}
?>

<div class="mt-2">
    <!-- Header with Back Button -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-icon-btn me-2" hx-boost="true">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ศูนย์พิจารณาอนุมัติคำร้อง</h5>
            <small class="text-muted">Universal Approvals Queue (<?= $total_pending ?> รายการ)</small>
        </div>
    </div>

    <!-- Scrollable Segmented Filter Pills -->
    <div class="d-flex align-items-center gap-1 mb-3 pb-1" style="overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: none;">
        <button class="btn btn-sm rounded-pill fw-bold active exec-tab-pill" id="tab-btn-leave" onclick="showApprovalTab('leave')">
            <i class="fa-solid fa-calendar-minus me-1"></i>ใบลา 
            <span class="badge bg-danger rounded-pill ms-1"><?= count($pending_leaves) ?></span>
        </button>
        <button class="btn btn-sm rounded-pill fw-bold text-secondary exec-tab-pill" id="tab-btn-academic" onclick="showApprovalTab('academic')">
            <i class="fa-solid fa-graduation-cap me-1"></i>วิชาการ 
            <span class="badge bg-purple rounded-pill ms-1" style="background-color: var(--primary) !important;"><?= count($pending_course_requests) ?></span>
        </button>
        <button class="btn btn-sm rounded-pill fw-bold text-secondary exec-tab-pill" id="tab-btn-supply" onclick="showApprovalTab('supply')">
            <i class="fa-solid fa-box-open me-1"></i>พัสดุ 
            <span class="badge bg-info text-dark rounded-pill ms-1"><?= count($pending_supplies) ?></span>
        </button>
        <button class="btn btn-sm rounded-pill fw-bold text-secondary exec-tab-pill" id="tab-btn-room" onclick="showApprovalTab('room')">
            <i class="fa-solid fa-door-open me-1"></i>จองห้อง 
            <span class="badge bg-success rounded-pill ms-1"><?= count($pending_rooms) ?></span>
        </button>
        <button class="btn btn-sm rounded-pill fw-bold text-secondary exec-tab-pill" id="tab-btn-repair" onclick="showApprovalTab('repair')">
            <i class="fa-solid fa-screwdriver-wrench me-1"></i>แจ้งซ่อม 
            <span class="badge bg-warning text-dark rounded-pill ms-1"><?= count($pending_repairs) ?></span>
        </button>
        <button class="btn btn-sm rounded-pill fw-bold text-secondary exec-tab-pill" id="tab-btn-klof" onclick="showApprovalTab('klof')">
            <i class="fa-solid fa-hands-holding-child me-1"></i>กยศ. 
            <span class="badge bg-secondary rounded-pill ms-1"><?= count($pending_klof) ?></span>
        </button>
    </div>

    <!-- TAB 1: ใบลา -->
    <div id="pane-leave" class="approval-pane">
        <?php if(empty($pending_leaves)): ?>
            <div class="text-center py-5 bento-card opacity-75">
                <i class="fa-solid fa-calendar-check fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีใบลาที่รออนุมัติ</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_leaves as $l): ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1"><?= htmlspecialchars($l['type_name']) ?></span>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($l['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></h6>
                    <p class="small text-muted mb-2">ลาตั้งแต่วันที่ <?= date('d/m/Y', strtotime($l['start_date'])) ?> ถึง <?= date('d/m/Y', strtotime($l['end_date'])) ?></p>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <a href="leave_view.php?id=<?= $l['leave_id'] ?>" class="btn btn-sm btn-outline-primary flex-fill rounded-pill">
                            <i class="fa-solid fa-eye me-1"></i>พิจารณาใบลา
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 2: คำร้องวิชาการนิสิต -->
    <div id="pane-academic" class="approval-pane d-none">
        <?php if(empty($pending_course_requests)): ?>
            <div class="text-center py-5 bento-card opacity-75">
                <i class="fa-solid fa-graduation-cap fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีคำร้องวิชาการที่รออนุมัติ</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_course_requests as $cr): 
                $step_info = getCourseStepBadge($cr['status']);
                $is_open = ($cr['request_type'] === 'open_course');
                $view_url = "academic_view.php?id=" . $cr['request_id'];
            ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="d-flex align-items-center gap-1">
                            <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1">
                                <?= $is_open ? 'เปิดรายวิชา' : 'สำรองที่นั่ง' ?>
                            </span>
                            <span class="badge bg-light text-dark border px-2 py-1"><?= $step_info['label'] ?></span>
                        </div>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($cr['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">
                        <span class="badge bg-dark bg-opacity-10 text-dark me-1"><?= htmlspecialchars($cr['course_code']) ?></span>
                        <?= htmlspecialchars($cr['course_name']) ?>
                    </h6>
                    <p class="small text-muted mb-1">
                        <i class="fa-solid fa-user-graduate me-1 text-secondary"></i>
                        <?= htmlspecialchars($cr['student_name'] ?? ($cr['first_name'] . ' ' . $cr['last_name'])) ?>
                        <?php if(!empty($cr['student_id'])): ?><span class="text-muted">(<?= htmlspecialchars($cr['student_id']) ?>)</span><?php endif; ?>
                    </p>
                    <?php if(!empty($cr['reason'])): ?>
                        <p class="extra-small text-muted text-truncate mb-2" style="font-size: 0.72rem;"><i class="fa-regular fa-comment me-1"></i><?= htmlspecialchars($cr['reason']) ?></p>
                    <?php endif; ?>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <a href="<?= $view_url ?>" class="btn btn-sm btn-outline-primary flex-fill rounded-pill" hx-disable>
                            <i class="fa-solid fa-file-pen me-1"></i>พิจารณาอนุมัติคำร้อง
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 3: เบิกพัสดุ -->
    <div id="pane-supply" class="approval-pane d-none">
        <?php if(empty($pending_supplies)): ?>
            <div class="text-center py-5 bento-box opacity-75">
                <i class="fa-solid fa-box-open fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีรายการเบิกพัสดุที่รออนุมัติ</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_supplies as $s): ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1">ใบเบิก #<?= str_pad($s['request_id'], 4, '0', STR_PAD_LEFT) ?></span>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($s['request_date'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></h6>
                    <p class="small text-muted mb-2"><?= htmlspecialchars($s['request_reason'] ?? 'ขอเบิกวัสดุใช้งาน') ?></p>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <a href="supplies_view.php?id=<?= $s['request_id'] ?>" class="btn btn-sm btn-outline-primary flex-fill rounded-pill">
                            <i class="fa-solid fa-eye me-1"></i>ตรวจสอบใบเบิก
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 4: จองห้อง -->
    <div id="pane-room" class="approval-pane d-none">
        <?php if(empty($pending_rooms)): ?>
            <div class="text-center py-5 bento-box opacity-75">
                <i class="fa-solid fa-door-open fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีคำขอจองห้อง</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_rooms as $r): ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1"><?= htmlspecialchars($r['room_name']) ?></span>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($r['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></h6>
                    <p class="small text-muted mb-2">จองวันที่ <?= date('d/m/Y', strtotime($r['booking_date'])) ?> เวลา <?= date('H:i', strtotime($r['start_time'])) ?> - <?= date('H:i', strtotime($r['end_time'])) ?></p>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <a href="rooms.php" class="btn btn-sm btn-outline-primary flex-fill rounded-pill">
                            <i class="fa-solid fa-eye me-1"></i>ตรวจสอบ
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 5: แจ้งซ่อม -->
    <div id="pane-repair" class="approval-pane d-none">
        <?php if(empty($pending_repairs)): ?>
            <div class="text-center py-5 bento-box opacity-75">
                <i class="fa-solid fa-screwdriver-wrench fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีรายการแจ้งซ่อม</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_repairs as $r): ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1"><?= htmlspecialchars($r['equipment_name']) ?></span>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($r['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></h6>
                    <p class="small text-muted mb-2">สถานที่: <?= htmlspecialchars($r['room_name'] ?? 'ไม่ระบุ') ?></p>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <a href="repairs.php" class="btn btn-sm btn-outline-primary flex-fill rounded-pill">
                            <i class="fa-solid fa-eye me-1"></i>ตรวจสอบ
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 6: กยศ. / จิตอาสา -->
    <div id="pane-klof" class="approval-pane d-none">
        <?php if(empty($pending_klof)): ?>
            <div class="text-center py-5 bento-box opacity-75">
                <i class="fa-solid fa-hands-holding-child fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted fw-semibold mb-0">ไม่มีคำขออนุมัติชั่วโมง กยศ.</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_klof as $k): ?>
                <div class="bento-box p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 fw-bold"><?= (float)$k['hours_count'] ?> ชม.</span>
                        <small class="text-muted"><?= date('d/m/Y', strtotime($k['created_at'])) ?></small>
                    </div>
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?></h6>
                    <p class="small text-muted mb-2">
                        <i class="fa-solid fa-thumbtack me-1 text-secondary"></i> <?= htmlspecialchars($k['activity_name']) ?><br>
                        <i class="fa-regular fa-calendar-days me-1 text-secondary"></i> <?= date('d/m/Y', strtotime($k['activity_date'])) ?>
                    </p>
                    <div class="d-flex gap-2 mt-2 pt-2 border-top">
                        <?php if(!empty($k['link_evidence'])): ?>
                            <a href="<?= htmlspecialchars($k['link_evidence']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill rounded-pill">
                                <i class="fa-solid fa-image me-1"></i>หลักฐาน
                            </a>
                        <?php endif; ?>
                        <form method="POST" class="flex-fill d-flex gap-2 mb-0">
                            <input type="hidden" name="log_id" value="<?= $k['log_id'] ?>">
                            <button type="submit" name="action" value="approve_klof" class="btn btn-sm btn-primary flex-fill rounded-pill text-white fw-bold shadow-sm">
                                <i class="fa-solid fa-check me-1"></i>อนุมัติ
                            </button>
                            <button type="submit" name="action" value="reject_klof" class="btn btn-sm btn-outline-danger flex-fill rounded-pill" onclick="return confirm('ยืนยันปฏิเสธรายการนี้?');">
                                <i class="fa-solid fa-xmark me-1"></i>ปฏิเสธ
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
function showApprovalTab(tabName) {
    // Hide all panes
    document.querySelectorAll('.approval-pane').forEach(el => el.classList.add('d-none'));
    // Deactivate all pill buttons
    document.querySelectorAll('.exec-tab-pill').forEach(btn => {
        btn.classList.remove('active', 'btn-primary');
        btn.classList.add('text-secondary');
        btn.style.backgroundColor = 'transparent';
        btn.style.color = 'var(--slate-600)';
    });

    // Show target pane
    const targetPane = document.getElementById('pane-' + tabName);
    if (targetPane) targetPane.classList.remove('d-none');

    // Activate target button
    const activeBtn = document.getElementById('tab-btn-' + tabName);
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.classList.remove('text-secondary');
        activeBtn.style.backgroundColor = 'var(--primary)';
        activeBtn.style.color = '#ffffff';
    }
}

// Initial active tab check via hash (e.g. #academic)
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#', '');
    if (hash && ['leave', 'academic', 'supply', 'room', 'repair', 'klof'].includes(hash)) {
        showApprovalTab(hash);
    } else {
        showApprovalTab('leave');
    }
});
</script>

<style>
.exec-tab-pill {
    border: 1px solid var(--border-subtle) !important;
    padding: 6px 14px !important;
    font-size: 0.78rem !important;
    transition: all 0.15s ease;
}
.exec-tab-pill.active {
    background-color: var(--primary) !important;
    color: #ffffff !important;
    border-color: var(--primary) !important;
    box-shadow: 0 2px 8px rgba(57, 4, 143, 0.15);
}
</style>

<?php require_once 'includes/footer.php'; ?>
