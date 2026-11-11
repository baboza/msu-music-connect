<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (เข้าได้เฉพาะ admin และ งานพัสดุ)
if (!hasRole(['admin', 'งานพัสดุ'])) {
    echo "<script>alert('ปฏิเสธการเข้าถึง: เฉพาะเจ้าหน้าที่พัสดุเท่านั้นที่สามารถอัปเดตข้อมูลได้'); window.location.href='index.php';</script>";
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$project_id = $_GET['id'];
$msg = ""; 
$msg_type = "";

// 2. ดึงข้อมูลโครงการพัสดุ (ใส่ COLLATE เพื่อความชัวร์)
$stmt = $pdo->prepare("
    SELECT p.*, u.first_name, u.last_name, u.position 
    FROM procurement_tracking p
    LEFT JOIN users u ON p.responsible_user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    echo "<script>alert('ไม่พบข้อมูลรายการนี้'); window.location.href='index.php';</script>";
    exit();
}

// ดึงรายชื่อบุคลากรทั้งหมดสำหรับใช้ใน Dropdown คณะกรรมการ
$stmt_users = $pdo->query("SELECT user_id, first_name, last_name, position FROM users WHERE status = 'active' ORDER BY first_name ASC");
$users_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// 3. จัดการเมื่อมีการกดปุ่มอัปเดต (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // รับค่า Checklist 10 ขั้นตอน
        $steps_val = [];
        for($i=1; $i<=10; $i++) {
            $steps_val["s$i"] = isset($_POST["step{$i}_request"]) || isset($_POST["step{$i}_tor"]) || isset($_POST["step{$i}_approve_buy"]) || isset($_POST["step{$i}_announce_winner"]) || isset($_POST["step{$i}_system_approve"]) || isset($_POST["step{$i}_contract"]) || isset($_POST["step{$i}_delivery"]) || isset($_POST["step{$i}_inspection"]) || isset($_POST["step{$i}_disbursement"]) || isset($_POST["step{$i}_completed"]) ? 1 : 0;
        }
        
        // รับค่ารายชื่อกรรมการ
        $tor = [!empty($_POST['tor_cm_1']) ? $_POST['tor_cm_1'] : NULL, !empty($_POST['tor_cm_2']) ? $_POST['tor_cm_2'] : NULL, !empty($_POST['tor_cm_3']) ? $_POST['tor_cm_3'] : NULL];
        $con = [!empty($_POST['con_cm_1']) ? $_POST['con_cm_1'] : NULL, !empty($_POST['con_cm_2']) ? $_POST['con_cm_2'] : NULL, !empty($_POST['con_cm_3']) ? $_POST['con_cm_3'] : NULL];
        $ins = [!empty($_POST['ins_cm_1']) ? $_POST['ins_cm_1'] : NULL, !empty($_POST['ins_cm_2']) ? $_POST['ins_cm_2'] : NULL, !empty($_POST['ins_cm_3']) ? $_POST['ins_cm_3'] : NULL];

        // [Validation] ตรวจสอบเงื่อนไข "กรรมการพิจารณา ห้ามเป็น กรรมการตรวจรับ"
        $overlap = array_intersect(array_filter($con), array_filter($ins));
        if (!empty($overlap)) {
            throw new Exception("ผิดเงื่อนไข: มีรายชื่อบุคคลที่เป็นทั้ง 'กรรมการพิจารณา' และ 'กรรมการตรวจรับ' ซ้อนทับกัน");
        }

        // รับค่าข้อมูลการเงิน (💡 เพิ่ม actual_price)
        $budget = floatval($_POST['budget']);
        $actual_price = floatval($_POST['actual_price']);
        $responsible_user_id = trim($_POST['responsible_user_id']);
        $procurement_method = trim($_POST['procurement_method']);
        $procurement_result = trim($_POST['procurement_result']);
        $remark = trim($_POST['remark']);
        $penalty_days = (int)$_POST['penalty_days'];
        $penalty_fee = floatval($_POST['penalty_fee']);

        // บันทึกข้อมูล
        $sql = "UPDATE procurement_tracking SET 
                responsible_user_id=?, procurement_method=?, budget=?, actual_price=?,
                tor_cm_1=?, tor_cm_2=?, tor_cm_3=?,
                con_cm_1=?, con_cm_2=?, con_cm_3=?,
                ins_cm_1=?, ins_cm_2=?, ins_cm_3=?,
                step1_request=?, step2_tor=?, step3_approve_buy=?, step4_announce_winner=?, step5_system_approve=?, 
                step6_contract=?, step7_delivery=?, step8_inspection=?, step9_disbursement=?, step10_completed=?,
                procurement_result=?, remark=?, penalty_days=?, penalty_fee=?, updated_at=NOW()
                WHERE id=?";
        
        $update_stmt = $pdo->prepare($sql);
        $update_stmt->execute([
            $responsible_user_id, $procurement_method, $budget, $actual_price,
            $tor[0], $tor[1], $tor[2],
            $con[0], $con[1], $con[2],
            $ins[0], $ins[1], $ins[2],
            isset($_POST['step1_request'])?1:0, isset($_POST['step2_tor'])?1:0, isset($_POST['step3_approve_buy'])?1:0, 
            isset($_POST['step4_announce_winner'])?1:0, isset($_POST['step5_system_approve'])?1:0, isset($_POST['step6_contract'])?1:0, 
            isset($_POST['step7_delivery'])?1:0, isset($_POST['step8_inspection'])?1:0, isset($_POST['step9_disbursement'])?1:0, 
            isset($_POST['step10_completed'])?1:0,
            $procurement_result, $remark, $penalty_days, $penalty_fee, $project_id
        ]);

        $msg = "อัปเดตข้อมูลและความคืบหน้าเรียบร้อยแล้ว!";
        $msg_type = "success";

        // ดึงข้อมูลใหม่มาแสดง
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msg_type = "danger";
    }
}

// คำนวณความคืบหน้า
$steps_count = $project['step1_request'] + $project['step2_tor'] + $project['step3_approve_buy'] + $project['step4_announce_winner'] + $project['step5_system_approve'] + $project['step6_contract'] + $project['step7_delivery'] + $project['step8_inspection'] + $project['step9_disbursement'] + $project['step10_completed'];
$progress = ($steps_count / 10) * 100;

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h3 text-dark fw-bold mb-1">แก้ไขโครงการ: <?php echo htmlspecialchars($project['project_name']); ?></h1>
            <p class="text-muted mb-0 small"><i class="fa-solid fa-calendar-alt me-1"></i> ปีงบประมาณ <?php echo $project['fiscal_year']; ?></p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm fw-bold"><i class="fa-solid fa-arrow-left me-2"></i> ย้อนกลับ</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-start border-4 border-<?php echo $msg_type; ?> alert-dismissible fade show">
            <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> me-2"></i> <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form action="" method="POST">
        <div class="row g-4">
            
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white pt-4 pb-0 border-0">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users-gear me-2 text-warning"></i>คณะกรรมการดำเนินงาน</h5>
                    </div>
                    <div class="card-body p-4">
                        <?php 
                        $groups = [
                            'tor' => ['label' => '1. คณะกรรมการร่าง TOR', 'prefix' => 'tor_cm_'],
                            'con' => ['label' => '2. คณะกรรมการพิจารณาผล/จัดซื้อ', 'prefix' => 'con_cm_'],
                            'ins' => ['label' => '3. คณะกรรมการตรวจรับพัสดุ', 'prefix' => 'ins_cm_']
                        ];
                        foreach($groups as $g_key => $g_val):
                        ?>
                        <h6 class="fw-bold text-secondary mt-3 mb-2 small text-uppercase"><?php echo $g_val['label']; ?></h6>
                        <div class="row g-2 mb-3 bg-light p-2 rounded-3">
                            <?php for($i=1; $i<=3; $i++): $fname = $g_val['prefix'].$i; ?>
                            <div class="col-md-4">
                                <select name="<?php echo $fname; ?>" class="form-select form-select-sm shadow-sm committee-select <?php echo $g_key; ?>-cm">
                                    <option value="">-- เลือกคนที่ <?php echo $i; ?> --</option>
                                    <?php foreach ($users_list as $u): ?>
                                        <option value="<?php echo htmlspecialchars($u['user_id']); ?>" <?php echo ($project[$fname] == $u['user_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4 text-center bg-white rounded-top-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 text-start">
                            <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-list-check me-2"></i>อัปเดตขั้นตอนดำเนินงาน</h5>
                            <span class="badge bg-primary fs-5 rounded-pill px-3"><?php echo $progress; ?>%</span>
                        </div>
                        <div class="progress mb-4" style="height: 12px; border-radius: 10px;">
                            <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </div>
                    <div class="list-group list-group-flush rounded-bottom-4">
                        <?php 
                        $steps = [
                            'step1_request' => '1. ขอจัดหา/ทำบันทึกความต้องการ', 'step2_tor' => '2. จัดทำ TOR / สเปกสินค้า', 'step3_approve_buy' => '3. รายงานขออนุมัติจัดซื้อจัดจ้าง',
                            'step4_announce_winner' => '4. ประกาศผู้ชนะการเสนอราคา', 'step5_system_approve' => '5. อนุมัติสั่งซื้อในระบบ (PO)',
                            'step6_contract' => '6. ทำสัญญา / ออกใบสั่งซื้อสั่งจ้าง', 'step7_delivery' => '7. ผู้ขายส่งมอบพัสดุ/งาน',
                            'step8_inspection' => '8. คณะกรรมการตรวจรับเรียบร้อย', 'step9_disbursement' => '9. รวบรวมเอกสารส่งเบิกจ่ายเงิน', 'step10_completed' => '10. เสร็จสิ้นกระบวนการทั้งหมด'
                        ];
                        foreach($steps as $key => $label): 
                        ?>
                        <label class="list-group-item d-flex gap-3 py-3 border-start-0 border-end-0">
                            <input class="form-check-input flex-shrink-0" type="checkbox" name="<?php echo $key; ?>" value="1" <?php echo $project[$key] ? 'checked' : ''; ?> style="width: 1.5rem; height: 1.5rem;">
                            <span class="pt-1 <?php echo $project[$key] ? 'text-muted text-decoration-line-through' : 'fw-bold'; ?>"><?php echo $label; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 mb-4 bg-primary text-white">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3 border-bottom border-light pb-2"><i class="fa-solid fa-coins me-2"></i> ข้อมูลการเงิน</h5>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold opacity-75">ผู้รับผิดชอบโครงการ</label>
                            <select name="responsible_user_id" class="form-select border-0 shadow-sm" required>
                                <?php foreach ($users_list as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u['user_id']); ?>" <?php echo ($project['responsible_user_id'] == $u['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold opacity-75">วิธีจัดซื้อจัดจ้าง</label>
                            <select name="procurement_method" class="form-select border-0 shadow-sm">
                                <option value="เฉพาะเจาะจง" <?php echo ($project['procurement_method'] == 'เฉพาะเจาะจง') ? 'selected' : ''; ?>>เฉพาะเจาะจง</option>
                                <option value="e-bidding" <?php echo ($project['procurement_method'] == 'e-bidding') ? 'selected' : ''; ?>>e-bidding (ประกวดราคาอิเล็กทรอนิกส์)</option>
                                <option value="คัดเลือก" <?php echo ($project['procurement_method'] == 'คัดเลือก') ? 'selected' : ''; ?>>คัดเลือก</option>
                                <option value="ประกาศเชิญชวนทั่วไป" <?php echo ($project['procurement_method'] == 'ประกาศเชิญชวนทั่วไป') ? 'selected' : ''; ?>>ประกาศเชิญชวนทั่วไป</option>
                                <option value="จ้างที่ปรึกษา" <?php echo ($project['procurement_method'] == 'จ้างที่ปรึกษา') ? 'selected' : ''; ?>>จ้างที่ปรึกษา</option>
                                <option value="อื่นๆ" <?php echo ($project['procurement_method'] == 'อื่นๆ') ? 'selected' : ''; ?>>อื่นๆ</option>
                            </select>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold opacity-75">งบประมาณที่ตั้งไว้ (฿)</label>
                                <input type="number" step="0.01" name="budget" class="form-control border-0 shadow-sm fw-bold" value="<?php echo $project['budget']; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold text-warning">ราคาที่จัดซื้อจริง (฿)</label>
                                <input type="number" step="0.01" name="actual_price" class="form-control border-0 shadow-sm fw-bold text-primary" value="<?php echo $project['actual_price']; ?>" style="background-color: #fffbe6;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 20px;">
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-primary">ผลการจัดซื้อ/จัดจ้าง (บริษัทผู้ชนะ)</label>
                            <input type="text" name="procurement_result" class="form-control shadow-sm border-primary" value="<?php echo htmlspecialchars($project['procurement_result'] ?? ''); ?>" placeholder="เช่น บจก. มหาสารคามไอที">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">หมายเหตุเพิ่มเติม</label>
                            <textarea name="remark" class="form-control shadow-sm" rows="3"><?php echo htmlspecialchars($project['remark'] ?? ''); ?></textarea>
                        </div>

                        <div class="p-3 bg-danger-subtle bg-opacity-10 border border-danger-subtle rounded-3 mb-4">
                            <h6 class="fw-bold text-danger mb-3 small"><i class="fa-solid fa-triangle-exclamation me-1"></i> กรณีส่งมอบงานล่าช้า</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="extra-small fw-bold">จำนวนวันที่ปรับ</label>
                                    <input type="number" name="penalty_days" class="form-control form-control-sm" value="<?php echo $project['penalty_days']; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="extra-small fw-bold">เงินค่าปรับรวม</label>
                                    <input type="number" step="0.01" name="penalty_fee" class="form-control form-control-sm text-danger fw-bold" value="<?php echo $project['penalty_fee']; ?>">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm hover-lift">
                            <i class="fa-solid fa-save me-2"></i> บันทึกการเปลี่ยนแปลง
                        </button>
                    </div>
                </div>
            </div>
            
        </div>
    </form>
</main>

<style>
    .extra-small { font-size: 0.65rem; }
    .hover-lift { transition: transform 0.2s; }
    .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
</style>

<script>
// สคริปต์ป้องกันการเลือกกรรมการซ้ำซ้อน (เหมือนเดิม)
document.addEventListener('DOMContentLoaded', function() {
    const selects = document.querySelectorAll('.committee-select');
    selects.forEach(select => {
        select.addEventListener('change', function() {
            if (!this.value) return;
            let conIds = []; document.querySelectorAll('.con-cm').forEach(el => { if(el.value) conIds.push(el.value); });
            let insIds = []; document.querySelectorAll('.ins-cm').forEach(el => { if(el.value) insIds.push(el.value); });
            let overlap = conIds.filter(v => insIds.includes(v));
            if (overlap.length > 0) {
                alert('🚫 ผิดเงื่อนไข: บุคคลนี้ไม่สามารถเป็นทั้ง "กรรมการพิจารณาผล" และ "กรรมการตรวจรับ" ได้');
                this.value = '';
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>