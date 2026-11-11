<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เช็คสิทธิ์
if (!hasRole(['admin', 'งานการเงิน', 'หัวหน้าสำนักงาน'])) exit();

$year = $_GET['year'];
$month = $_GET['month'];

// ดึงข้อมูลทั้งหมดในเดือนนั้น
$sql = "SELECT f.*, u.first_name, u.last_name 
        FROM finance_requests f 
        JOIN users u ON f.user_id = u.user_id 
        WHERE f.fiscal_year = ? AND MONTH(f.created_at) = ?
        ORDER BY f.created_at ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$year, $month]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ตั้งค่า Header สำหรับการดาวน์โหลดไฟล์ Excel
$filename = "Finance_Report_{$year}_{$month}.xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// จัดการเรื่องภาษาไทย (UTF-8 with BOM)
echo "\xEF\xBB\xBF"; 

echo "<table border='1'>";
echo "<tr>
        <th style='background-color:#eee;'>ลำดับ</th>
        <th style='background-color:#eee;'>วันที่สร้าง</th>
        <th style='background-color:#eee;'>รายการ</th>
        <th style='background-color:#eee;'>ผู้ขอเบิก</th>
        <th style='background-color:#eee;'>ประเภท</th>
        <th style='background-color:#eee;'>ชื่อผู้รับเงิน</th>
        <th style='background-color:#eee;'>จำนวนเงิน</th>
        <th style='background-color:#eee;'>สถานะล่าสุด</th>
      </tr>";

if(empty($data)) {
    echo "<tr><td colspan='8' align='center'>ไม่พบข้อมูล</td></tr>";
} else {
    $i = 1;
    $total = 0;
    foreach($data as $row) {
        $type = ($row['req_type'] == 'advance') ? 'เงินยืม' : (($row['req_type'] == 'clear_advance') ? 'หักล้างเงินยืม' : (($row['req_type'] == 'teaching') ? 'ค่าสอน' : 'เบิกจ่าย'));
        echo "<tr>";
        echo "<td align='center'>{$i}</td>";
        echo "<td>".date('d/m/Y', strtotime($row['created_at']))."</td>";
        echo "<td>".htmlspecialchars($row['title'])."</td>";
        echo "<td>".htmlspecialchars($row['first_name'] . ' ' . $row['last_name'])."</td>";
        echo "<td>{$type}</td>";
        echo "<td>".htmlspecialchars($row['payee_name'])."</td>";
        echo "<td align='right'>".number_format($row['amount'], 2)."</td>";
        echo "<td align='center'>{$row['current_status']}</td>";
        echo "</tr>";
        $total += $row['amount'];
        $i++;
    }
    echo "<tr>
            <td colspan='6' align='right'><b>รวมทั้งสิ้น</b></td>
            <td align='right'><b>".number_format($total, 2)."</b></td>
            <td></td>
          </tr>";
}
echo "</table>";