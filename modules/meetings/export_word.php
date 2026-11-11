<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// เข้าได้เฉพาะงานธุรการ / แอดมิน / บุคลากรสายสนับสนุน
if (!hasRole(['admin', 'งานธุรการ', 'หัวหน้าสำนักงาน', 'บุคลากรสายสนับสนุน'])) {
    die("ปฏิเสธการเข้าถึง");
}

$meeting_id = $_GET['id'] ?? '';
if (empty($meeting_id)) {
    die("ไม่พบข้อมูลการประชุม");
}

$stmt_meet = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ?");
$stmt_meet->execute([$meeting_id]);
$meeting = $stmt_meet->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    die("ไม่พบข้อมูลการประชุม");
}

$html_content = $meeting['minutes_content'];

if (empty($html_content)) {
    die("ยังไม่มีร่างรายงานการประชุม กรุณาบันทึกร่างรายงานก่อนทำการส่งออก");
}

$filename = "Minutes_Meeting_" . $meeting['meeting_no'] . "_" . date("Ymd", strtotime($meeting['meeting_date'])) . ".doc";

header("Content-Type: application/vnd.ms-word");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Content-Disposition: attachment; filename=\"$filename\"");

// สร้างโครงสร้าง HTML ที่ MS Word เข้าใจว่าต้องใช้ฟอนต์อะไร
echo "<html>";
echo "<head>";
echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">";
echo "<style>
    @page {
        size: 21cm 29.7cm;  /* A4 */
        margin: 2.54cm;
    }
    body {
        font-family: 'TH SarabunPSK', 'TH Sarabun New', sans-serif;
        font-size: 16pt;
        line-height: 1.5;
    }
    p, div, span, td, th {
        font-family: 'TH SarabunPSK', 'TH Sarabun New', sans-serif;
        font-size: 16pt;
    }
    h1, h2, h3, h4, h5, h6 {
        font-family: 'TH SarabunPSK', 'TH Sarabun New', sans-serif;
        font-weight: bold;
    }
</style>";
echo "</head>";
echo "<body>";
echo $html_content;
echo "</body>";
echo "</html>";
exit();
?>
