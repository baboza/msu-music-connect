<?php
// เปิด Error สำหรับการพัฒนา
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
// 💡 ให้ผู้บริหารสามารถเข้าดูรายการของ "ทุกคน" ได้เหมือน Admin
$can_view_all = hasRole(['admin', 'หัวหน้าสำนักงาน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี', 'งานการเงิน']);

// =========================================================
// ระบบลบข้อมูล (Delete Logic)
// =========================================================
if (isset($_GET['delete_id']) && hasRole(['admin', 'งานการเงิน'])) {
    $delete_id = intval($_GET['delete_id']);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM finance_status_history WHERE req_id = ?")->execute([$delete_id]);
        $pdo->prepare("DELETE FROM finance_requests WHERE req_id = ?")->execute([$delete_id]);
        $pdo->commit();
        echo "<script>alert('ลบรายการเรียบร้อยแล้ว'); window.location.href='index.php';</script>";
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('เกิดข้อผิดพลาดในการลบ: " . $e->getMessage() . "'); window.location.href='index.php';</script>";
        exit();
    }
}

// 1. จัดการเรื่องปีงบประมาณ
$stmt_all_fy = $pdo->query("SELECT year_name, is_active FROM fiscal_years ORDER BY year_name DESC");
$fiscal_years = $stmt_all_fy->fetchAll(PDO::FETCH_ASSOC);
$default_fy = "";
foreach($fiscal_years as $fy) { if($fy['is_active'] == 1) { $default_fy = $fy['year_name']; break; } }
if(empty($default_fy)) $default_fy = date('Y') + 543;

// 2. รับค่าตัวกรอง
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$fy_filter = isset($_GET['fy']) ? $_GET['fy'] : $default_fy;

// 3. Query ข้อมูล
$sql = "SELECT f.*, u.first_name, u.last_name, u.profile_picture 
        FROM finance_requests f 
        JOIN users u ON f.user_id = u.user_id 
        WHERE f.fiscal_year = :fy";

$params = [':fy' => $fy_filter];

// ถ้าไม่มีสิทธิ์ดูทั้งหมด ให้โชว์เฉพาะรายการของตัวเอง
if (!$can_view_all) {
    $sql .= " AND f.user_id = :uid";
    $params[':uid'] = $user_id;
}

if (!empty($search)) {
    $sql .= " AND (f.title LIKE :search1 OR u.first_name LIKE :search2 OR f.payee_name LIKE :search3)";
    $params[':search1'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}
if ($type_filter !== 'all') {
    $sql .= " AND f.req_type = :type";
    $params[':type'] = $type_filter;
}

$sql .= " ORDER BY f.current_status ASC, f.created_at DESC"; 
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. คำนวณสรุปเฉพาะรายการที่ยังไม่สำเร็จ (Pending)
$total_pending_amount = 0;
$cash_advance_count = 0;
foreach($requests as $r) {
    if($r['current_status'] != 'paid') {
        $total_pending_amount += $r['amount'];
        if($r['req_type'] == 'advance') $cash_advance_count++;
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

function getStatusConfig($status) {
    $configs = [
        'received'  => ['step' => 1, 'label' => 'อยู่คณะ', 'color' => '#6c757d'],
        'verified'  => ['step' => 2, 'label' => 'ตรวจสอบแล้ว', 'color' => '#0d6efd'],
        'forwarded' => ['step' => 3, 'label' => 'ส่งมหาลัย', 'color' => '#fd7e14'],
        'approved'  => ['step' => 4, 'label' => 'มหาลัยอนุมัติ', 'color' => '#20c997'],
        'paid'      => ['step' => 5, 'label' => 'สำเร็จแล้ว', 'color' => '#198754']
    ];
    return $configs[$status] ?? ['step' => 0, 'label' => 'N/A', 'color' => '#eee'];
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-end pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-dark mb-0"><i class="fa-solid fa-money-bill-transfer text-purple me-2"></i>Finance Tracking</h1>
            <p class="text-muted small mb-0">ปีงบประมาณ <?php echo $fy_filter; ?> • ติดตามสถานะเบิกจ่ายและเงินยืม</p>
        </div>
        <div class="d-flex gap-2">
            <?php if(hasRole(['admin', 'งานการเงิน', 'หัวหน้าสำนักงาน'])): ?>
                <a href="report.php" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm">
                    <i class="fa-solid fa-chart-line me-1"></i> รายงานสรุป
                </a>
                <a href="export_excel.php?year=<?php echo $fy_filter; ?>&month=<?php echo date('m'); ?>" class="btn btn-success rounded-pill px-3 fw-bold shadow-sm">
                    <i class="fa-solid fa-file-excel me-1"></i> Export
                </a>
            <?php endif; ?>
            <?php if(hasRole(['admin', 'งานการเงิน'])): ?>
                <a href="create.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm hover-lift">
                    <i class="fa-solid fa-plus me-1"></i> เพิ่มรายการ
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden h-100">
                <div class="card-body p-4 position-relative">
                    <div class="position-absolute end-0 top-0 p-3 opacity-10 text-primary"><i class="fa-solid fa-wallet fs-1"></i></div>
                    <h6 class="text-muted fw-bold small text-uppercase mb-2">ยอดค้างเบิกจ่าย (เฉพาะปี <?php echo $fy_filter; ?>)</h6>
                    <h2 class="fw-bold mb-0 text-primary">฿<?php echo number_format($total_pending_amount, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden border-start border-warning border-4 h-100">
                <div class="card-body p-4 position-relative">
                    <div class="position-absolute end-0 top-0 p-3 opacity-10 text-warning"><i class="fa-solid fa-clock-rotate-left fs-1"></i></div>
                    <h6 class="text-muted fw-bold small text-uppercase mb-2">เงินยืมค้างเคลียร์</h6>
                    <h2 class="fw-bold mb-0 text-warning"><?php echo $cash_advance_count; ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h2>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 text-purple h-100 position-relative overflow-hidden" style="background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);">
                <i class="fa-solid fa-calendar-check position-absolute opacity-25" style="font-size: 8rem; top: -15px; right: -15px; color: #4a148c;"></i>
                
                <div class="card-body p-4 position-relative z-1">
                    <h6 class="fw-bold small text-uppercase mb-2 opacity-75" style="color: #4a148c;">ปีงบประมาณที่แสดงผล</h6>
                    <form action="" method="GET">
                        <select name="fy" class="form-select form-select-lg bg-transparent border-0 p-0 fw-bold fs-3" onchange="this.form.submit()" style="cursor: pointer; color: #39048f; outline: none; box-shadow: none;">
                            <?php foreach($fiscal_years as $fy): ?>
                                <option value="<?php echo $fy['year_name']; ?>" <?php echo $fy_filter == $fy['year_name'] ? 'selected' : ''; ?> class="text-dark">
                                    พ.ศ. <?php echo $fy['year_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white mb-4">
        <div class="card-body p-3">
            <form class="row g-2 align-items-center" method="GET">
                <input type="hidden" name="fy" value="<?php echo $fy_filter; ?>">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-0" placeholder="ชื่อโครงการ หรือบุคลากร..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="type" class="form-select border-0 bg-light" onchange="this.form.submit()">
                        <option value="all">ทุกประเภทรายการ</option>
                        <option value="advance" <?php echo $type_filter == 'advance' ? 'selected' : ''; ?>>ลูกหนี้เงินยืม</option>
                        <option value="clear_advance" <?php echo $type_filter == 'clear_advance' ? 'selected' : ''; ?>>หักล้างเงินยืม</option>
                        <option value="teaching" <?php echo $type_filter == 'teaching' ? 'selected' : ''; ?>>ค่าสอนอาจารย์พิเศษ</option>
                        <option value="reimbursement" <?php echo $type_filter == 'reimbursement' ? 'selected' : ''; ?>>เบิกจ่ายทั่วไป</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold shadow-sm">กรอง</button>
                </div>
            </form>
        </div>
    </div>

    <div class="finance-list-container">
        <?php if(empty($requests)): ?>
            <div class="text-center py-5 bg-white rounded-4 shadow-sm border border-light">
                <i class="fa-solid fa-inbox fs-1 text-muted opacity-25 mb-3"></i>
                <p class="text-muted mb-0 small">ไม่พบข้อมูลรายการในปีงบประมาณนี้</p>
            </div>
        <?php endif; ?>

        <?php foreach($requests as $req): 
            $status_info = getStatusConfig($req['current_status']);
            
            // 💡 ลอจิกใหม่: คำนวณวันเกินกำหนดโดยคำนึงถึงวันล้างหนี้ (cleared_date)
            $is_overdue = false;
            $overdue_days = 0;
            
            // ตั้งค่าเริ่มต้น
            $due_date_text = "ยังไม่มีกำหนดวันเคลียร์เงิน"; 
            $due_date_class = "text-muted"; 

            if ($req['req_type'] == 'advance') {
                if (!empty($req['due_date']) && $req['due_date'] != '0000-00-00') {
                    $due_date = strtotime($req['due_date']);
                    
                    // ตรวจสอบว่ามีการใส่วันล้างหนี้มาหรือยัง
                    $has_cleared_date = (!empty($req['cleared_date']) && $req['cleared_date'] != '0000-00-00');
                    
                    // ถ้าใส่วันล้างหนี้แล้ว ให้ใช้วันล้างหนี้ ถ้ายังไม่ใส่ให้ใช้วันนี้ในการคำนวณ
                    $calc_date = $has_cleared_date ? strtotime($req['cleared_date']) : strtotime(date('Y-m-d'));
                    
                    if ($calc_date > $due_date) {
                        $is_overdue = true;
                        $overdue_days = floor(($calc_date - $due_date) / (60 * 60 * 24));
                        
                        if ($has_cleared_date) {
                            // ส่งช้า (แต่ส่งแล้ว/ล้างหนี้แล้ว)
                            $due_date_text = '<span class="badge bg-danger text-white px-2 py-1 shadow-sm" style="font-size: 0.75rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>ส่งช้า ' . $overdue_days . ' วัน</span>';
                            $due_date_class = ""; 
                        } else {
                            // เลยกำหนด (ยังไม่ส่ง)
                            $due_date_text = '<span class="badge bg-danger text-white px-2 py-1 shadow-sm blink" style="font-size: 0.75rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>เลยกำหนดมาแล้ว ' . $overdue_days . ' วัน</span>';
                            $due_date_class = ""; 
                        }
                    } else {
                        // ไม่เลยกำหนด
                        if ($has_cleared_date || $req['current_status'] == 'paid') {
                            $due_date_text = '<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i> ส่งเอกสารตรงเวลา</span>';
                            $due_date_class = "";
                        } else {
                            $due_date_text = 'กำหนดเคลียร์เงิน: ' . date('d/m/Y', $due_date);
                            $due_date_class = "text-secondary"; 
                        }
                    }
                }
            } else if ($req['current_status'] == 'paid') {
                // สำหรับรายการที่ไม่ใช่เงินยืม (เช่น ค่าสอน เบิกจ่ายทั่วไป) ที่จ่ายเสร็จแล้ว
                $due_date_text = '<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i> ดำเนินการเสร็จสิ้น</span>';
                $due_date_class = "";
            }
        ?>
            <div class="card border-0 shadow-sm rounded-4 mb-3 finance-row-card clickable-row <?php echo ($is_overdue && !$has_cleared_date) ? 'border-start border-danger border-4' : ''; ?>" data-href="view.php?id=<?php echo $req['req_id']; ?>" <?php echo ($is_overdue && !$has_cleared_date) ? 'style="background-color: #fff5f5;"' : ''; ?>>
                <div class="card-body p-3 p-md-4">
                    <div class="row align-items-center">
                        
                        <div class="col-lg-4 col-12 mb-3 mb-lg-0">
                            <div class="d-flex align-items-center">
                                <div class="avatar-circle me-3 bg-white border shadow-sm">
                                    <?php if($req['profile_picture']): ?>
                                        <img src="../../uploads/avatars/<?php echo $req['profile_picture']; ?>" class="rounded-circle" width="48" height="48" style="object-fit: cover;">
                                    <?php else: ?>
                                        <div class="initials rounded-circle bg-purple-subtle text-purple fw-bold"><?php echo mb_substr($req['first_name'],0,1,'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="overflow-hidden">
                                    <h6 class="fw-bold text-dark mb-1 text-truncate" style="max-width: 100%;"><?php echo htmlspecialchars($req['title']); ?></h6>
                                    <div class="extra-small text-muted">
                                        <span class="fw-bold text-purple"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></span>
                                        <span class="mx-1">•</span>
                                        <?php echo date('d M Y', strtotime($req['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-2 col-md-3 col-6 text-start text-lg-center border-start-lg mb-3 mb-md-0">
                            <div class="fw-bold fs-5 <?php echo ($is_overdue && !$has_cleared_date) ? 'text-danger' : 'text-dark'; ?>">฿<?php echo number_format($req['amount'], 2); ?></div>
                            <span class="badge <?php 
                                if($req['req_type'] == 'advance') echo 'bg-warning-subtle text-warning border-warning';
                                elseif($req['req_type'] == 'clear_advance') echo 'bg-success-subtle text-success border-success';
                                elseif($req['req_type'] == 'teaching') echo 'bg-info-subtle text-info border-info';
                                else echo 'bg-secondary-subtle text-secondary border-secondary';
                            ?> border rounded-pill px-3 py-1" style="font-size: 0.65rem;">
                                <?php echo ($req['req_type'] == 'advance') ? 'เงินยืม' : (($req['req_type'] == 'clear_advance') ? 'หักล้างเงินยืม' : (($req['req_type'] == 'teaching') ? 'ค่าสอน' : 'เบิกจ่าย')); ?>
                            </span>
                        </div>

                        <div class="col-lg-4 col-md-5 col-6 border-start-lg">
                            <div class="d-flex justify-content-between mb-2 align-items-center">
                                <span class="extra-small fw-bold <?php echo $due_date_class; ?>">
                                    <?php echo $due_date_text; ?>
                                </span>

                                <span class="extra-small fw-bold px-2 py-1 rounded" style="color: <?php echo $status_info['color']; ?>; background-color: <?php echo $status_info['color']; ?>15;">
                                    <?php echo $status_info['label']; ?>
                                </span>
                            </div>
                            <div class="mini-tracker mt-2">
                                <div class="m-bar">
                                    <div class="m-fill shadow-sm" style="width: <?php echo ($status_info['step']-1)*25; ?>%; background-color: <?php echo $status_info['color']; ?>"></div>
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <div class="m-dot <?php echo $i <= $status_info['step'] ? 'active' : ''; ?>" style="<?php echo $i <= $status_info['step'] ? 'background-color:'.$status_info['color'] : ''; ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-2 col-md-4 mt-3 mt-md-0 text-end" onclick="event.stopPropagation();">
                            <div class="btn-group shadow-sm bg-white rounded-pill">
                                <a href="view.php?id=<?php echo $req['req_id']; ?>" class="btn btn-sm btn-light text-primary border-end px-3" title="ดูรายละเอียด">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <?php if(hasRole(['admin', 'งานการเงิน'])): ?>
                                    <a href="edit_status.php?id=<?php echo $req['req_id']; ?>" class="btn btn-sm btn-light text-warning border-end px-3" title="อัปเดตสถานะ">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="index.php?delete_id=<?php echo $req['req_id']; ?>" onclick="return confirm('ยืนยันการลบรายการนี้? (ไม่สามารถกู้คืนได้)');" class="btn btn-sm btn-light text-danger px-3" title="ลบรายการ">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<style>
    .bg-purple-subtle { background-color: #f3e5f5; }
    .text-purple { color: #4a148c; }
    .extra-small { font-size: 0.75rem; }
    
    /* Tracker Styles */
    .mini-tracker { padding: 5px 0; }
    .m-bar { height: 6px; background: #eef1f4; position: relative; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; }
    .m-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 10px; transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1; }
    .m-dot { width: 10px; height: 10px; background: #dee2e6; border-radius: 50%; position: relative; z-index: 2; border: 2px solid #fff; }
    .m-dot.active { transform: scale(1.3); }
    
    /* Row Interactions */
    .finance-row-card { transition: all 0.25s ease; cursor: pointer; border: 1px solid rgba(0,0,0,0.02) !important; }
    .finance-row-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.07) !important; border-color: rgba(57, 4, 143, 0.1) !important; }
    .avatar-circle { width: 48px; height: 48px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .initials { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    
    @media (min-width: 992px) { .border-start-lg { border-left: 1px solid #f0f2f5; padding-left: 2rem; } }
    
    .blink { animation: blinker 1.5s linear infinite; }
    @keyframes blinker { 50% { opacity: 0.5; } }

    /* Custom Select for Gradient Card */
    select[name="fy"] option {
        background-color: white;
        color: #333;
    }
</style>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const rows = document.querySelectorAll(".clickable-row");
        rows.forEach(row => {
            row.addEventListener("click", () => {
                window.location.href = row.dataset.href;
            });
        });
    });
</script>

<?php require_once '../../includes/footer.php'; ?>