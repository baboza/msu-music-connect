<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์ (เฉพาะผู้บริหารและงานการเงิน)
$exec_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองบริหาร', 'คณบดี', 'งานการเงิน'];
if (!hasRole($exec_roles)) {
    echo "<script>alert('สิทธิ์ไม่เพียงพอ'); window.location.href='index.php';</script>";
    exit();
}

// 1. รับค่าปีและเดือน (Default คือเดือนปัจจุบัน)
$selected_year = isset($_GET['year']) ? $_GET['year'] : (date('Y') + 543);
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');

// 2. ดึงข้อมูลปีงบประมาณจากตาราง
$fiscal_years = $pdo->query("SELECT year_name FROM fiscal_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Query สรุปยอดรวมแยกตามประเภท (ในเดือนที่เลือก)
$sql_summary = "SELECT req_type, SUM(amount) as total_amount, COUNT(*) as total_count 
                FROM finance_requests 
                WHERE fiscal_year = ? AND MONTH(created_at) = ? 
                GROUP BY req_type";
$stmt = $pdo->prepare($sql_summary);
$stmt->execute([$selected_year, $selected_month]);
$summary_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Query รายการที่ยังค้างดำเนินการ (ไม่ได้อยู่ในสถานะ paid)
$sql_pending = "SELECT f.*, u.first_name, u.last_name 
                FROM finance_requests f 
                JOIN users u ON f.user_id = u.user_id 
                WHERE f.fiscal_year = ? AND MONTH(f.created_at) = ? AND f.current_status != 'paid'
                ORDER BY f.created_at ASC";
$stmt_pending = $pdo->prepare($sql_pending);
$stmt_pending->execute([$selected_year, $selected_month]);
$pending_list = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$thai_months = [
    '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
    '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
    '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
];
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h3 fw-bold text-purple mb-1"><i class="fa-solid fa-chart-pie me-2"></i>รายงานสรุปยอดงานการเงิน</h1>
            <p class="text-muted small mb-0">ประจำเดือน <?php echo $thai_months[$selected_month]; ?> ปีงบประมาณ <?php echo $selected_year; ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="export_excel.php?year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-file-excel me-1"></i> ส่งออก Excel
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form action="" method="GET" class="row g-2">
                <div class="col-md-3">
                    <select name="year" class="form-select border-0 bg-light">
                        <?php foreach($fiscal_years as $fy): ?>
                            <option value="<?php echo $fy['year_name']; ?>" <?php echo $selected_year == $fy['year_name'] ? 'selected' : ''; ?>>ปีงบประมาณ <?php echo $fy['year_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="month" class="form-select border-0 bg-light">
                        <?php foreach($thai_months as $m_val => $m_name): ?>
                            <option value="<?php echo $m_val; ?>" <?php echo $selected_month == $m_val ? 'selected' : ''; ?>><?php echo $m_name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold">เรียกดูรายงาน</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php 
        $grand_total = 0;
        $type_names = ['advance' => 'เงินยืม', 'clear_advance' => 'หักล้างเงินยืม', 'teaching' => 'ค่าสอน', 'reimbursement' => 'เบิกจ่ายอื่นๆ'];
        $type_colors = ['advance' => 'warning', 'clear_advance' => 'success', 'teaching' => 'info', 'reimbursement' => 'purple'];
        
        // แปลงผล Query เป็น Array ที่ใช้ง่าย
        $report_summary = [];
        foreach($summary_data as $row) {
            $report_summary[$row['req_type']] = $row;
            $grand_total += $row['total_amount'];
        }

        foreach($type_names as $key => $name): 
            $amount = isset($report_summary[$key]) ? $report_summary[$key]['total_amount'] : 0;
            $count = isset($report_summary[$key]) ? $report_summary[$key]['total_count'] : 0;
        ?>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-4 bg-white border-start border-<?php echo $type_colors[$key]; ?> border-4">
                    <div class="card-body p-3">
                        <h6 class="text-muted fw-bold small text-uppercase mb-2"><?php echo $name; ?> (<?php echo $count; ?> รายการ)</h6>
                        <h3 class="fw-bold text-dark mb-0">฿<?php echo number_format($amount, 2); ?></h3>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white border-0 pt-4 px-4">
            <h5 class="fw-bold text-danger mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i>รายการค้างดำเนินการ (Pending)</h5>
            <p class="text-muted small mb-0">รายการที่ยังจ่ายเงินหรือเคลียร์ลูกหนี้ไม่สำเร็จในเดือนนี้</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr class="extra-small fw-bold text-muted text-uppercase">
                            <th class="ps-4">วันที่ขอ</th>
                            <th>รายการ</th>
                            <th>ผู้ขอ</th>
                            <th>ประเภท</th>
                            <th class="text-end">ยอดเงิน</th>
                            <th class="pe-4 text-center">สถานะปัจจุบัน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pending_list)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted small">ไม่มีรายการค้างดำเนินการในเดือนนี้</td></tr>
                        <?php else: ?>
                            <?php foreach($pending_list as $p): ?>
                                <tr>
                                    <td class="ps-4 small"><?php echo date('d/m/y', strtotime($p['created_at'])); ?></td>
                                    <td class="fw-bold text-dark small"><?php echo htmlspecialchars($p['title']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($p['first_name']); ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border small"><?php echo $type_names[$p['req_type']]; ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-primary small">฿<?php echo number_format($p['amount'], 2); ?></td>
                                    <td class="pe-4 text-center">
                                        <span class="badge bg-secondary-subtle text-secondary small px-2"><?php echo $p['current_status']; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td colspan="4" class="ps-4 text-end">รวมยอดค้างจ่ายทั้งสิ้น:</td>
                            <td class="text-end text-danger">฿<?php 
                                $pending_total = array_sum(array_column($pending_list, 'amount'));
                                echo number_format($pending_total, 2); 
                            ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>